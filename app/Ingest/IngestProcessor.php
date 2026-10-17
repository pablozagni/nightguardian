<?php

namespace App\Ingest;

use App\Grouping\DefaultFingerprinter;
use App\Jobs\EvaluateAlerts;
use App\Models\Project;
use App\Privacy\Scrubber;

/**
 * Validates, scrubs and stores every event of a batch, producing the per-event results of protocol v1 section 5.
 */
class IngestProcessor
{
    public function __construct(
        private readonly BatchValidator $validator,
        private readonly EventTypeRegistry $types,
        private readonly EventStore $store,
        private readonly DefaultFingerprinter $fingerprinter,
    ) {}

    /**
     * @param  list<mixed>  $events
     * @param  array<string, mixed>  $body
     * @return array{accepted: int, rejected: int, results: list<array<string, mixed>>}
     */
    public function process(Project $project, array $body, array $events, bool $dryRun): array
    {
        $scrubber = Scrubber::fromConfig();
        $sdk = $this->batchSdk($body);
        $results = [];
        $accepted = 0;
        $rejected = 0;

        foreach ($events as $raw) {
            $id = is_array($raw) && is_string($raw['id'] ?? null) ? $raw['id'] : null;
            $errors = $this->validator->validate($raw);

            if ($errors !== []) {
                $rejected++;
                $results[] = ['id' => $id, 'status' => 'rejected', 'errors' => $errors];

                continue;
            }

            /** @var array<string, mixed> $event */
            $event = $scrubber->scrub($raw);
            $handler = $this->types->find((string) $event['type']);
            \assert($handler !== null);

            $accepted++;

            if ($dryRun) {
                $results[] = array_filter([
                    'id' => $id,
                    'status' => 'accepted',
                    'fingerprint' => $handler->groups() ? $this->fingerprinter->fingerprint($event) : null,
                ], fn ($value) => $value !== null);

                continue;
            }

            $stored = $this->store->store($project, $event, $handler, $sdk);

            if ($stored->duplicate) {
                $results[] = ['id' => $id, 'status' => 'duplicate'];

                continue;
            }

            $result = ['id' => $id, 'status' => 'accepted'];

            if ($stored->update !== null) {
                $result['issue'] = $stored->update->issue->getKey();
                $this->dispatchAlerts($stored->update, (string) $event['id'], (string) $event['type']);
            }

            $results[] = $result;
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'results' => $results];
    }

    private function dispatchAlerts(IssueUpdate $update, string $eventUuid, string $type): void
    {
        $trigger = $type === 'test' ? 'test' : $update->transition;

        if ($trigger !== null) {
            EvaluateAlerts::dispatch((string) $update->issue->getKey(), $trigger, $eventUuid);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{name: string, version: string}|null
     */
    private function batchSdk(array $body): ?array
    {
        $sdk = $body['sdk'] ?? null;

        if (is_array($sdk) && is_string($sdk['name'] ?? null) && is_string($sdk['version'] ?? null)) {
            return ['name' => mb_substr($sdk['name'], 0, 64), 'version' => mb_substr($sdk['version'], 0, 32)];
        }

        return null;
    }
}
