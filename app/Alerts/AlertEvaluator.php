<?php

namespace App\Alerts;

use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Jobs\SendAlert;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\AlertRule;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Decides who gets notified about an issue, following docs/alerts.md:
 * ignored → never rules → channel level and toggles → per-issue cooldown → hourly cap per project.
 */
class AlertEvaluator
{
    public const SENT = 'sent';

    public const IGNORED = 'ignored';

    public const NEVER = 'never';

    public const NO_RULE = 'no_rule';

    public const NO_CHANNELS = 'no_channels';

    public const COOLDOWN = 'cooldown';

    public const CAPPED = 'capped';

    public const ARCHIVED = 'archived';

    public function __construct(private readonly RuleMatcher $rules, private readonly AlertMessageFactory $messages) {}

    /**
     * @param  string  $trigger  new, regressed, rule, threshold or test
     * @return string one of the class constants, what happened
     */
    public function evaluate(Issue $issue, string $trigger, ?Event $event, ?int $windowCount = null): string
    {
        $project = $issue->project;

        if ($project->isArchived()) {
            return self::ARCHIVED;
        }

        if ($trigger === 'test') {
            return $this->connectionOk($project, $issue);
        }

        if ($issue->status === IssueStatus::Ignored) {
            return self::IGNORED;
        }

        $matched = $this->rules->matching($project->alertRules()->where('enabled', true)->orderBy('position')->orderBy('id')->get(), $issue, $event);
        $actions = array_map(fn (AlertRule $rule): string => $rule->action, $matched);

        if (in_array('never_notify', $actions, true)) {
            return self::NEVER;
        }

        $always = in_array('always_notify', $actions, true);

        if ($trigger === 'rule' && ! $always) {
            return self::NO_RULE;
        }

        $level = in_array('set_level_critical', $actions, true) ? Level::Critical : ($event->level ?? $issue->level);
        $channels = $this->channels($project, $trigger, $level, $always);

        if ($channels === []) {
            return self::NO_CHANNELS;
        }

        if ($trigger !== 'threshold' && $this->coolingDown($project, $issue, $always)) {
            return self::COOLDOWN;
        }

        if (! $this->withinHourlyCap($project)) {
            $this->suppress($project, $issue, $trigger, $channels);

            return self::CAPPED;
        }

        $kind = $always && ! in_array($trigger, ['new', 'regressed', 'threshold'], true) ? 'rule' : $trigger;
        $message = $this->messages->forIssue($kind, $project, $issue, $event, $this->takeSuppressed($project), $windowCount, (int) $project->setting('threshold_minutes', 60));

        foreach ($channels as $channel) {
            SendAlert::dispatch($channel->id, $kind, $issue->id, $message->toArray());
        }

        $issue->forceFill(['last_alerted_at' => now()])->save();

        return self::SENT;
    }

    /**
     * The "connection OK" notice for the first test event of an issue (the SDK's `nightguardian:test`).
     */
    private function connectionOk(Project $project, Issue $issue): string
    {
        $message = $this->messages->connectionOk($project, $issue);
        $sent = false;

        foreach ($project->alertChannels()->where('enabled', true)->get() as $channel) {
            $already = AlertLog::query()->where('alert_channel_id', $channel->id)->where('issue_id', $issue->id)->where('kind', 'test')->exists();

            if (! $already) {
                SendAlert::dispatch($channel->id, 'test', $issue->id, $message->toArray());
                $sent = true;
            }
        }

        return $sent ? self::SENT : self::NO_CHANNELS;
    }

    /**
     * @return list<AlertChannel>
     */
    private function channels(Project $project, string $trigger, Level $level, bool $always): array
    {
        $toggle = match ($trigger) {
            'new' => 'notify_new',
            'regressed' => 'notify_regressed',
            'threshold' => 'notify_threshold',
            default => null,
        };

        return $project->alertChannels()->where('enabled', true)->get()
            ->filter(fn (AlertChannel $channel): bool => $level->atLeast($channel->min_level))
            ->filter(fn (AlertChannel $channel): bool => $always || $toggle === null || $channel->{$toggle})
            ->values()
            ->all();
    }

    private function coolingDown(Project $project, Issue $issue, bool $always): bool
    {
        $minutes = $always
            ? (int) config('alerts.always_notify_cooldown_minutes', 5)
            : (int) $project->setting('cooldown_minutes', config('alerts.cooldown_minutes', 60));

        return $issue->last_alerted_at !== null && $issue->last_alerted_at->greaterThan(now()->subMinutes($minutes));
    }

    /**
     * Counts this alert against the project's hourly budget. False when the budget is used up.
     */
    private function withinHourlyCap(Project $project): bool
    {
        $max = (int) $project->setting('max_alerts_per_hour', config('alerts.max_alerts_per_hour', 20));
        $key = 'ng:alerts:'.$project->id.':'.now()->format('YmdH');

        Cache::add($key, 0, 3700);
        $count = Cache::increment($key);

        return is_int($count) && $count <= $max;
    }

    /**
     * @param  list<AlertChannel>  $channels
     */
    private function suppress(Project $project, Issue $issue, string $trigger, array $channels): void
    {
        $key = 'ng:alerts-suppressed:'.$project->id;
        Cache::add($key, 0, 3600);
        Cache::increment($key);

        foreach ($channels as $channel) {
            AlertLog::create(['alert_channel_id' => $channel->id, 'issue_id' => $issue->id, 'kind' => $this->logKind($trigger), 'status' => 'suppressed']);
        }
    }

    /** How many alerts were held back since the last one that went out; reading resets it. */
    private function takeSuppressed(Project $project): int
    {
        return (int) Cache::pull('ng:alerts-suppressed:'.$project->id, 0);
    }

    private function logKind(string $trigger): string
    {
        return in_array($trigger, ['new', 'regressed', 'threshold', 'rule'], true) ? $trigger : 'rule';
    }
}
