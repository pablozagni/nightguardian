<?php

namespace App\Ingest;

use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Ingest\Types\EventTypeHandler;
use App\Models\Event;
use App\Models\Issue;
use App\Models\IssueFingerprint;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds or creates the issue for a fingerprint and applies the status transitions of docs/grouping.md.
 * Must run inside a database transaction.
 */
class IssueUpdater
{
    private const MAX_USERS_COUNT = 100_000;

    /**
     * @param  array<string, mixed>  $event  Scrubbed protocol event.
     */
    public function record(
        Project $project,
        array $event,
        EventTypeHandler $handler,
        string $fingerprint,
        Carbon $occurredAt,
    ): IssueUpdate {
        $issue = $this->find($project, $fingerprint);

        if ($issue === null) {
            $issue = $this->create($project, $event, $handler, $fingerprint, $occurredAt);

            if ($issue->wasRecentlyCreated) {
                return new IssueUpdate($issue, IssueUpdate::NEW);
            }
        }

        return new IssueUpdate($issue, $this->update($issue, $event, $occurredAt));
    }

    private function find(Project $project, string $fingerprint): ?Issue
    {
        $issueId = IssueFingerprint::query()
            ->where('project_id', $project->getKey())
            ->where('fingerprint', $fingerprint)
            ->value('issue_id');

        if (! is_string($issueId)) {
            return null;
        }

        $issue = Issue::query()->lockForUpdate()->find($issueId);

        // Merged issues keep their fingerprints re-pointed to the target; follow the link defensively.
        while ($issue !== null && $issue->merged_into_id !== null) {
            $issue = Issue::query()->lockForUpdate()->find($issue->merged_into_id);
        }

        return $issue;
    }

    /**
     * Create the issue and its fingerprint. If a concurrent request created the same fingerprint first,
     * the savepoint is rolled back and the existing issue is returned instead.
     *
     * @param  array<string, mixed>  $event
     */
    private function create(
        Project $project,
        array $event,
        EventTypeHandler $handler,
        string $fingerprint,
        Carbon $occurredAt,
    ): Issue {
        $environment = is_string($event['environment'] ?? null) ? $event['environment'] : null;
        $release = is_string($event['release'] ?? null) ? $event['release'] : null;

        try {
            return DB::transaction(function () use ($project, $event, $handler, $fingerprint, $occurredAt, $environment, $release) {
                $issue = Issue::create([
                    'project_id' => $project->getKey(),
                    'type' => $event['type'],
                    'title' => $handler->title($event),
                    'culprit' => $handler->culprit($event),
                    'exception_class' => $handler->exceptionClass($event),
                    'level' => $this->level($event) ?? Level::Error,
                    'status' => IssueStatus::New,
                    'events_count' => 1,
                    'users_count' => $this->userIdentifier($event) === null ? 0 : 1,
                    'environments' => $environment === null ? [] : [$environment],
                    'first_seen_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'first_release' => $release,
                    'last_release' => $release,
                ]);

                IssueFingerprint::create([
                    'project_id' => $project->getKey(),
                    'fingerprint' => $fingerprint,
                    'issue_id' => $issue->getKey(),
                ]);

                return $issue;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->find($project, $fingerprint);

            if ($existing === null) {
                throw new \RuntimeException('Fingerprint conflict without an existing issue.');
            }

            return $existing;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return IssueUpdate::NEW|IssueUpdate::REGRESSED|null
     */
    private function update(Issue $issue, array $event, Carbon $occurredAt): ?string
    {
        $release = is_string($event['release'] ?? null) ? $event['release'] : null;
        $environment = is_string($event['environment'] ?? null) ? $event['environment'] : null;
        $level = $this->level($event);
        $userId = $this->userIdentifier($event);

        $issue->events_count++;

        if ($level !== null && $level->atLeast($issue->level)) {
            $issue->level = $level;
        }

        $environments = $issue->environments;
        if ($environment !== null && ! in_array($environment, $environments, true)) {
            $environments[] = $environment;
            $issue->environments = $environments;
        }

        if ($occurredAt->greaterThanOrEqualTo($issue->last_seen_at)) {
            $issue->last_seen_at = $occurredAt;
            $issue->last_release = $release ?? $issue->last_release;
        }

        if ($occurredAt->lessThan($issue->first_seen_at)) {
            $issue->first_seen_at = $occurredAt;
            $issue->first_release = $release ?? $issue->first_release;
        }

        if ($userId !== null && $issue->users_count < self::MAX_USERS_COUNT
            && ! Event::query()->where('issue_id', $issue->getKey())->where('user_identifier', $userId)->exists()) {
            $issue->users_count++;
        }

        $transition = $this->transition($issue, $release);
        $issue->save();

        return $transition;
    }

    /**
     * @return IssueUpdate::REGRESSED|null
     */
    private function transition(Issue $issue, ?string $release): ?string
    {
        switch ($issue->status) {
            case IssueStatus::Resolved:
                if ($this->isOlderThanFix($release, $issue->resolved_in_release)) {
                    return null;
                }

                $this->reopen($issue);

                return IssueUpdate::REGRESSED;

            case IssueStatus::Ignored:
                if ($issue->ignored_until !== null && $issue->ignored_until->isPast()) {
                    $this->reopen($issue);

                    return IssueUpdate::REGRESSED;
                }

                return null;

            default:
                return null;
        }
    }

    private function reopen(Issue $issue): void
    {
        $issue->status = IssueStatus::Regressed;
        $issue->resolved_at = null;
        $issue->resolved_by = null;
        $issue->resolved_in_release = null;
        $issue->ignored_until = null;
    }

    /**
     * True when the event comes from a release older than the one the issue was resolved in.
     * Only comparable when both look like version numbers.
     */
    private function isOlderThanFix(?string $eventRelease, ?string $resolvedIn): bool
    {
        if ($eventRelease === null || $resolvedIn === null) {
            return false;
        }

        $pattern = '/^v?\d+(\.\d+)*([.-][0-9A-Za-z.-]+)?$/';

        if (preg_match($pattern, $eventRelease) !== 1 || preg_match($pattern, $resolvedIn) !== 1) {
            return false;
        }

        return version_compare(ltrim($eventRelease, 'vV'), ltrim($resolvedIn, 'vV'), '<');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function level(array $event): ?Level
    {
        return is_string($event['level'] ?? null) ? Level::tryFrom($event['level']) : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function userIdentifier(array $event): ?string
    {
        $user = $event['user'] ?? null;
        $id = is_array($user) ? ($user['id'] ?? null) : null;

        return is_scalar($id) && (string) $id !== '' ? mb_substr((string) $id, 0, 191) : null;
    }
}
