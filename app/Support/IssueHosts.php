<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Issue;
use Illuminate\Support\Collection;

/**
 * The installations (events.host) an issue has been seen in.
 */
final class IssueHosts
{
    /**
     * @return Collection<int, object{host: string, events: int, last_seen_at: string}>
     */
    public static function breakdown(Issue $issue, int $limit = 50): Collection
    {
        /** @var Collection<int, object{host: string, events: int, last_seen_at: string}> $rows */
        $rows = Event::query()
            ->where('issue_id', $issue->getKey())
            ->whereNotNull('host')
            ->groupBy('host')
            ->selectRaw('host, count(*) as events, max(occurred_at) as last_seen_at')
            ->orderByDesc('events')
            ->orderBy('host')
            ->limit($limit)
            ->toBase()
            ->get();

        return $rows;
    }

    public static function count(Issue $issue): int
    {
        return (int) Event::query()->where('issue_id', $issue->getKey())->whereNotNull('host')->distinct()->count('host');
    }
}
