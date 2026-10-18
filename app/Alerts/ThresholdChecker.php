<?php

namespace App\Alerts;

use App\Jobs\EvaluateAlerts;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Threshold alerts: an issue with at least `threshold_count` events in the last `threshold_minutes`
 * (project settings, default 50 in 60) is evaluated once per window.
 */
class ThresholdChecker
{
    /**
     * @return int number of issues handed to EvaluateAlerts
     */
    public function run(): int
    {
        $dispatched = 0;

        $projects = Project::query()
            ->whereNull('archived_at')
            ->whereHas('alertChannels', fn ($query) => $query->where('enabled', true)->where('notify_threshold', true))
            ->get();

        foreach ($projects as $project) {
            $count = max(1, (int) $project->setting('threshold_count', 50));
            $minutes = max(1, (int) $project->setting('threshold_minutes', 60));

            $hot = Event::query()
                ->where('project_id', $project->id)
                ->whereNotNull('issue_id')
                ->where('occurred_at', '>=', now()->subMinutes($minutes))
                ->selectRaw('issue_id, count(*) as total')
                ->groupBy('issue_id')
                ->havingRaw('count(*) >= ?', [$count])
                ->pluck('total', 'issue_id');

            foreach ($hot as $issueId => $total) {
                if (! Cache::add('ng:threshold:'.$issueId, true, $minutes * 60)) {
                    continue;
                }

                $uuid = Event::query()->where('issue_id', $issueId)->latest('occurred_at')->value('uuid');

                EvaluateAlerts::dispatch((string) $issueId, 'threshold', (string) $uuid, (int) $total);
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
