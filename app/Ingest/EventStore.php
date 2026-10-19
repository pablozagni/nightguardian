<?php

namespace App\Ingest;

use App\Enums\EventType;
use App\Enums\Level;
use App\Grouping\DefaultFingerprinter;
use App\Ingest\Types\EventTypeHandler;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists one validated, scrubbed event. Idempotent on the protocol event id (events.uuid).
 */
class EventStore
{
    public function __construct(
        private readonly DefaultFingerprinter $fingerprinter,
        private readonly IssueUpdater $issues,
    ) {}

    /**
     * @param  array<string, mixed>  $event  Scrubbed protocol event.
     * @param  array{name: string, version: string}|null  $sdk  Batch-level SDK info.
     */
    public function store(Project $project, array $event, EventTypeHandler $handler, ?array $sdk): StoreResult
    {
        $uuid = (string) $event['id'];

        if (Event::query()->where('uuid', $uuid)->exists()) {
            return StoreResult::duplicate();
        }

        try {
            return DB::transaction(fn () => $this->persist($project, $event, $handler, $sdk));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request stored the same event id first.
            return StoreResult::duplicate();
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{name: string, version: string}|null  $sdk
     */
    private function persist(Project $project, array $event, EventTypeHandler $handler, ?array $sdk): StoreResult
    {
        $occurredAt = Carbon::parse((string) $event['timestamp'])->utc();
        $update = null;

        if ($handler->groups()) {
            $update = $this->issues->record(
                $project,
                $event,
                $handler,
                $this->fingerprinter->fingerprint($event),
                $occurredAt,
            );
        }

        $sdk = is_array($event['sdk'] ?? null) ? $event['sdk'] : $sdk;
        $request = is_array($event['request'] ?? null) ? $event['request'] : [];
        $user = is_array($event['user'] ?? null) ? $event['user'] : [];
        $userId = $user['id'] ?? null;

        $stored = Event::create([
            'uuid' => $event['id'],
            'project_id' => $project->getKey(),
            'issue_id' => $update?->issue->getKey(),
            'type' => $event['type'],
            'level' => is_string($event['level'] ?? null) ? Level::tryFrom($event['level']) : null,
            'reference' => $event['reference'] ?? null,
            'occurred_at' => $occurredAt,
            'received_at' => now()->utc(),
            'environment' => $event['environment'] ?? null,
            'release' => $event['release'] ?? null,
            'server' => $event['server'] ?? null,
            'host' => HostResolver::resolve($event),
            'sdk_name' => $sdk['name'] ?? null,
            'sdk_version' => $sdk['version'] ?? null,
            'platform' => $event['platform'] ?? null,
            'message' => $handler->message($event),
            'request_method' => $this->limit($request['method'] ?? null, 10),
            'request_route' => $this->limit($request['route'] ?? null, 255),
            'request_url' => $this->limit($request['url'] ?? null, 1024),
            'status_code' => is_int($request['status_code'] ?? null) ? $request['status_code'] : null,
            'user_identifier' => is_scalar($userId) ? $this->limit((string) $userId, 191) : null,
            'payload' => $event,
        ]);

        match ($stored->type) {
            EventType::Deploy => $this->storeDeploy($project, $event, $occurredAt),
            EventType::Feedback => $this->storeFeedback($project, $event),
            default => null,
        };

        if ($update !== null) {
            $this->attachPendingFeedback($project, $stored);
        }

        return StoreResult::accepted($update);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function storeDeploy(Project $project, array $event, Carbon $deployedAt): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        Deploy::create([
            'project_id' => $project->getKey(),
            'environment' => $event['environment'],
            'release' => $payload['release'],
            'commit' => $this->limit($payload['commit'] ?? null, 255),
            'branch' => $this->limit($payload['branch'] ?? null, 255),
            'deployer' => $this->limit($payload['deployer'] ?? null, 255),
            'url' => $this->limit($payload['url'] ?? null, 1024),
            'deployed_at' => $deployedAt,
        ]);
    }

    /**
     * Feedback is attached to the issue of the original event (by id or reference); when that event has not
     * arrived yet it is kept without an issue and attached later by attachPendingFeedback().
     *
     * @param  array<string, mixed>  $event
     */
    private function storeFeedback(Project $project, array $event): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];
        $eventUuid = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
        $reference = is_string($payload['reference'] ?? null)
            ? $payload['reference']
            : (is_string($event['reference'] ?? null) ? $event['reference'] : null);

        $issueId = Event::query()
            ->where('project_id', $project->getKey())
            ->whereNotNull('issue_id')
            ->where(function ($query) use ($eventUuid, $reference) {
                $query->whereRaw('1 = 0');
                if ($eventUuid !== null) {
                    $query->orWhere('uuid', $eventUuid);
                }
                if ($reference !== null) {
                    $query->orWhere('reference', $reference);
                }
            })
            ->value('issue_id');

        Feedback::create([
            'project_id' => $project->getKey(),
            'issue_id' => is_string($issueId) ? $issueId : null,
            'event_uuid' => $eventUuid,
            'reference' => $reference,
            'comment' => $payload['comment'],
            'email' => is_string($payload['email'] ?? null) ? mb_substr($payload['email'], 0, 255) : null,
        ]);
    }

    private function attachPendingFeedback(Project $project, Event $event): void
    {
        Feedback::query()
            ->where('project_id', $project->getKey())
            ->whereNull('issue_id')
            ->where(function ($query) use ($event) {
                $query->where('event_uuid', $event->uuid);

                if ($event->reference !== null) {
                    $query->orWhere('reference', $event->reference);
                }
            })
            ->update(['issue_id' => $event->issue_id]);
    }

    private function limit(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
