<?php

namespace App\Alerts;

use App\Filament\Resources\Issues\IssueResource;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Builds alert messages in the project's language (docs/alerts.md → Message content).
 */
class AlertMessageFactory
{
    private const ICONS = ['new' => '🔴', 'regressed' => '🟠', 'threshold' => '📈', 'rule' => '⚡', 'digest' => '📋', 'test' => '✅'];

    public function icon(string $kind): string
    {
        return self::ICONS[$kind] ?? '🔔';
    }

    public function forIssue(string $kind, Project $project, Issue $issue, ?Event $event, int $suppressed = 0, ?int $windowCount = null, ?int $windowMinutes = null): AlertMessage
    {
        $locale = $project->language();
        $environment = $event->environment ?? ($issue->environments[0] ?? null);

        $headline = mb_strtoupper(trans('alerts.kind.'.$kind, [], $locale)).' · '.$project->name
            .($environment !== null ? " ({$environment})" : '');

        $lines = [];

        $where = array_filter([
            $issue->culprit,
            ($event->release ?? $issue->last_release) !== null ? trans('alerts.release', ['release' => $event->release ?? $issue->last_release], $locale) : null,
        ]);

        if ($where !== []) {
            $lines[] = implode(' · ', $where);
        }

        if ($kind === 'threshold' && $windowCount !== null && $windowMinutes !== null) {
            $lines[] = trans('alerts.threshold_line', ['count' => $windowCount, 'minutes' => $windowMinutes], $locale);
        }

        $lines[] = implode(' · ', [
            trans_choice('alerts.events', $issue->events_count, ['count' => $issue->events_count], $locale),
            trans_choice('alerts.users', $issue->users_count, ['count' => $issue->users_count], $locale),
            trans('alerts.first_seen', ['when' => $issue->first_seen_at->copy()->locale($locale)->diffForHumans()], $locale),
        ]);

        if ($suppressed > 0) {
            $lines[] = trans_choice('alerts.suppressed', $suppressed, ['count' => $suppressed], $locale);
        }

        return new AlertMessage($kind, $this->icon($kind), $headline, Str::limit($issue->title, 200), $lines, IssueResource::getUrl('view', ['record' => $issue]));
    }

    public function connectionOk(Project $project, Issue $issue): AlertMessage
    {
        $locale = $project->language();

        return new AlertMessage(
            'test',
            $this->icon('test'),
            trans('alerts.connection_ok.headline', ['project' => $project->name], $locale),
            trans('alerts.connection_ok.body', [], $locale),
            [],
            IssueResource::getUrl('view', ['record' => $issue]),
        );
    }
}
