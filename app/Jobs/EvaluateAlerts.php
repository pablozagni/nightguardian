<?php

namespace App\Jobs;

use App\Alerts\AlertEvaluator;
use App\Models\Event;
use App\Models\Issue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Decides whether an issue change must notify someone and queues one SendAlert per channel
 * (rules in docs/alerts.md, implemented by AlertEvaluator).
 */
class EvaluateAlerts implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $trigger  `new`, `regressed`, `rule`, `threshold` or `test`
     * @param  int|null  $windowCount  Threshold only: events counted in the window.
     */
    public function __construct(
        public readonly string $issueId,
        public readonly string $trigger,
        public readonly string $eventUuid,
        public readonly ?int $windowCount = null,
    ) {}

    public function handle(AlertEvaluator $evaluator): void
    {
        $issue = Issue::query()->with('project')->find($this->issueId);

        if ($issue === null) {
            return;
        }

        $event = Event::query()->where('uuid', $this->eventUuid)->first();

        $evaluator->evaluate($issue, $this->trigger, $event, $this->windowCount);
    }
}
