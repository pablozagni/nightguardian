<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether an issue change must notify someone. Stub: the real implementation arrives with the
 * alerts stage (docs/alerts.md); for now it only logs what would be evaluated.
 */
class EvaluateAlerts implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $trigger  `new`, `regressed` or `test`
     */
    public function __construct(
        public readonly string $issueId,
        public readonly string $trigger,
        public readonly string $eventUuid,
    ) {}

    public function handle(): void
    {
        Log::info('EvaluateAlerts (stub)', [
            'issue' => $this->issueId,
            'trigger' => $this->trigger,
            'event' => $this->eventUuid,
        ]);
    }
}
