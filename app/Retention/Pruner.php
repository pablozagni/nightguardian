<?php

namespace App\Retention;

use App\Models\AlertLog;
use App\Models\Event;
use App\Models\Project;

/**
 * Retention: events older than the project's `retention_days` are deleted in chunks.
 * Issues, their counters and feedback stay. Alert logs are kept 90 days.
 */
class Pruner
{
    public const ALERT_LOG_DAYS = 90;

    /**
     * @return array{events: int, alert_logs: int}
     */
    public function run(?int $chunk = null): array
    {
        $chunk ??= (int) config('nightguardian.retention.chunk', 1000);
        $events = 0;

        foreach (Project::query()->get(['id', 'retention_days']) as $project) {
            $cutoff = now()->subDays(max(1, $project->retention_days));

            do {
                $ids = Event::query()->where('project_id', $project->id)->where('occurred_at', '<', $cutoff)->limit($chunk)->pluck('id');
                $deleted = $ids->isEmpty() ? 0 : Event::query()->whereIn('id', $ids)->delete();
                $events += $deleted;
            } while ($deleted > 0 && $ids->count() >= $chunk);
        }

        $logs = 0;
        do {
            $ids = AlertLog::query()->where('created_at', '<', now()->subDays(self::ALERT_LOG_DAYS))->limit($chunk)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : AlertLog::query()->whereIn('id', $ids)->delete();
            $logs += $deleted;
        } while ($deleted > 0 && $ids->count() >= $chunk);

        return ['events' => $events, 'alert_logs' => $logs];
    }
}
