<?php

namespace App\Alerts;

use App\Jobs\SendAlert;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Sends the daily digest of every project whose `digest_time` (in the project's time zone) has passed today
 * and that has not had one yet today. Run every minute by `nightguardian:digest`.
 */
class DigestScheduler
{
    public function __construct(private readonly DigestBuilder $builder) {}

    /**
     * @return int number of projects whose digest was handled (sent to channels or skipped for lack of activity)
     */
    public function dispatchDue(?Carbon $now = null): int
    {
        $now ??= now();
        $handled = 0;

        foreach (Project::query()->whereNull('archived_at')->whereNotNull('digest_time')->get() as $project) {
            $local = $now->copy()->timezone($project->timezone);
            $today = $local->toDateString();

            if ($project->setting('last_digest_on') === $today || $local->format('H:i') < substr((string) $project->digest_time, 0, 5)) {
                continue;
            }

            $project->forceFill(['settings' => [...$project->settings, 'last_digest_on' => $today]])->save();
            $handled++;

            $message = $this->builder->build($project, $now);

            if ($message === null) {
                continue;
            }

            foreach ($project->alertChannels()->where('enabled', true)->where('include_digest', true)->get() as $channel) {
                SendAlert::dispatch($channel->id, 'digest', null, $message->toArray());
            }
        }

        return $handled;
    }
}
