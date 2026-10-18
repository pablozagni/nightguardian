<?php

namespace App\Alerts;

use App\Filament\Resources\Issues\IssueResource;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The daily digest of a project: new and regressed issues, top 5 by events, totals against the previous
 * day, resolved count and last deploys (docs/alerts.md → Daily digest).
 */
class DigestBuilder
{
    public function __construct(private readonly AlertMessageFactory $messages) {}

    /**
     * @return AlertMessage|null null when there was no activity and the project skips empty digests
     */
    public function build(Project $project, ?Carbon $until = null): ?AlertMessage
    {
        $until ??= now();
        $since = $until->copy()->subDay();
        $before = $since->copy()->subDay();
        $locale = $project->language();

        $events = $this->events($project, $since, $until);
        $previous = $this->events($project, $before, $since);
        $new = Issue::query()->where('project_id', $project->id)->whereNull('merged_into_id')->whereBetween('first_seen_at', [$since, $until])->orderByDesc('events_count')->get();
        $regressed = $this->activities($project, IssueActivity::REGRESSED, $since, $until);
        $resolved = $this->activities($project, [IssueActivity::RESOLVED, IssueActivity::RESOLVED_IN_RELEASE], $since, $until);

        if ($events === 0 && $new->isEmpty() && $regressed === 0 && $project->setting('digest_skip_empty', true) !== false) {
            return null;
        }

        $t = fn (string $key, array $replace = []): string => trans('alerts.digest.'.$key, $replace, $locale);

        $lines = [
            $t('events', ['count' => $events, 'previous' => $previous]),
            $t('new_issues', ['count' => $new->count()]).($new->isEmpty() ? '' : ': '.$new->take(3)->map(fn (Issue $i): string => Str::limit($i->title, 60))->implode('; ')),
            $t('regressed', ['count' => $regressed]),
            $t('resolved', ['count' => $resolved]),
        ];

        $top = Event::query()
            ->where('project_id', $project->id)->whereNotNull('issue_id')->whereBetween('occurred_at', [$since, $until])
            ->selectRaw('issue_id, count(*) as total')->groupBy('issue_id')->orderByDesc('total')->limit(5)->pluck('total', 'issue_id');

        if ($top->isNotEmpty()) {
            $titles = Issue::query()->whereIn('id', $top->keys())->pluck('title', 'id');
            $lines[] = $t('top');

            foreach ($top as $issueId => $total) {
                $lines[] = '• '.Str::limit((string) ($titles[$issueId] ?? $issueId), 70).' — '.$total;
            }
        }

        $deploys = Deploy::query()->where('project_id', $project->id)->orderByDesc('deployed_at')->limit(3)->get();

        if ($deploys->isNotEmpty()) {
            $lines[] = $t('deploys');

            foreach ($deploys as $deploy) {
                $lines[] = '• '.$deploy->release.' ('.$deploy->environment.') · '.$deploy->deployed_at->copy()->locale($locale)->diffForHumans();
            }
        }

        return new AlertMessage(
            'digest',
            $this->messages->icon('digest'),
            mb_strtoupper($t('headline')).' · '.$project->name,
            $t('title', ['date' => $until->copy()->timezone($project->timezone)->locale($locale)->isoFormat('LL')]),
            $lines,
            IssueResource::getUrl('index', ['filters' => ['project_id' => ['value' => $project->id]]]),
        );
    }

    private function events(Project $project, Carbon $from, Carbon $to): int
    {
        return Event::query()->where('project_id', $project->id)->whereNotNull('issue_id')->whereBetween('occurred_at', [$from, $to])->count();
    }

    /**
     * @param  string|list<string>  $actions
     */
    private function activities(Project $project, string|array $actions, Carbon $from, Carbon $to): int
    {
        return IssueActivity::query()
            ->whereIn('issue_id', Issue::query()->where('project_id', $project->id)->select('id'))
            ->whereIn('action', (array) $actions)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }
}
