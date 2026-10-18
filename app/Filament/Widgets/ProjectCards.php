<?php

namespace App\Filament\Widgets;

use App\Enums\IssueStatus;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard: one card per project the user can see, with open issues, new issues today, events in the last
 * 24 hours and a 7 day bar chart.
 */
class ProjectCards extends Widget
{
    protected string $view = 'filament.widgets.project-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    /**
     * @return list<array{id: string, name: string, url: string, open: int, new_today: int, events_24h: int, days: list<array{label: string, count: int, height: int}>}>
     */
    public function getCards(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $visible = $user->visibleProjectIds();
        $projects = Project::query()
            ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible ?? []))
            ->orderBy('name')
            ->get();
        $ids = $projects->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $now = now();
        $today = $now->copy()->startOfDay();

        $open = Issue::query()->whereIn('project_id', $ids)->whereNull('merged_into_id')
            ->whereIn('status', [IssueStatus::New->value, IssueStatus::Regressed->value])
            ->selectRaw('project_id, count(*) as total')->groupBy('project_id')->pluck('total', 'project_id');

        $newToday = Issue::query()->whereIn('project_id', $ids)->whereNull('merged_into_id')
            ->where('first_seen_at', '>=', $today)
            ->selectRaw('project_id, count(*) as total')->groupBy('project_id')->pluck('total', 'project_id');

        $events24h = Event::query()->whereIn('project_id', $ids)->whereNotNull('issue_id')
            ->where('occurred_at', '>=', $now->copy()->subDay())
            ->selectRaw('project_id, count(*) as total')->groupBy('project_id')->pluck('total', 'project_id');

        $perDay = Event::query()->whereIn('project_id', $ids)->whereNotNull('issue_id')
            ->where('occurred_at', '>=', $today->copy()->subDays(6))
            ->selectRaw('project_id, date(occurred_at) as day, count(*) as total')->groupBy('project_id', 'day')->get()
            ->groupBy('project_id');

        return $projects->map(function (Project $project) use ($open, $newToday, $events24h, $perDay, $today): array {
            $counts = $perDay->get($project->id, collect())->pluck('total', 'day');
            $days = [];

            for ($i = 6; $i >= 0; $i--) {
                $day = $today->copy()->subDays($i);
                $days[] = ['label' => $day->isoFormat('dd D'), 'count' => (int) ($counts[$day->toDateString()] ?? 0), 'height' => 0];
            }

            $max = max(1, ...array_column($days, 'count'));
            foreach ($days as &$day) {
                $day['height'] = $day['count'] === 0 ? 2 : max(8, (int) round($day['count'] / $max * 100));
            }
            unset($day);

            return [
                'id' => $project->id,
                'name' => $project->name,
                'url' => IssueResource::getUrl('index', ['filters' => ['project_id' => ['value' => $project->id]]]),
                'open' => (int) ($open[$project->id] ?? 0),
                'new_today' => (int) ($newToday[$project->id] ?? 0),
                'events_24h' => (int) ($events24h[$project->id] ?? 0),
                'days' => $days,
            ];
        })->all();
    }
}
