<?php

namespace App\Issues;

use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueFingerprint;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Status changes an operator can make on an issue. Callers are responsible for authorization (IssuePolicy::update).
 * Every change leaves an entry in the issue's activity log.
 */
class IssueActions
{
    public function resolve(Issue $issue, User $by, ?string $inRelease = null): void
    {
        $inRelease = $inRelease === null || trim($inRelease) === '' ? null : trim($inRelease);

        $issue->forceFill([
            'status' => IssueStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => $by->getKey(),
            'resolved_in_release' => $inRelease,
            'ignored_until' => null,
        ])->save();

        $this->log(
            $issue,
            $by,
            $inRelease === null ? IssueActivity::RESOLVED : IssueActivity::RESOLVED_IN_RELEASE,
            $inRelease === null ? null : ['release' => $inRelease],
        );
    }

    /**
     * @param  Carbon|null  $until  null = forever
     */
    public function ignore(Issue $issue, User $by, ?Carbon $until = null): void
    {
        $issue->forceFill([
            'status' => IssueStatus::Ignored,
            'ignored_until' => $until,
            'resolved_at' => null,
            'resolved_by' => null,
            'resolved_in_release' => null,
        ])->save();

        $this->log($issue, $by, IssueActivity::IGNORED, ['until' => $until?->toIso8601String()]);
    }

    public function reopen(Issue $issue, User $by): void
    {
        $issue->forceFill([
            'status' => IssueStatus::New,
            'resolved_at' => null,
            'resolved_by' => null,
            'resolved_in_release' => null,
            'ignored_until' => null,
        ])->save();

        $this->log($issue, $by, IssueActivity::REOPENED);
    }

    /**
     * Moves everything of $source into $target and leaves $source as a tombstone pointing at it.
     * Both must belong to the same project.
     */
    public function merge(Issue $source, Issue $target, User $by): void
    {
        if ($source->is($target) || $source->project_id !== $target->project_id) {
            throw new InvalidArgumentException('Issues can only be merged into another issue of the same project.');
        }

        if ($target->merged_into_id !== null) {
            throw new InvalidArgumentException('The target issue was itself merged into another one.');
        }

        DB::transaction(function () use ($source, $target, $by) {
            Event::query()->where('issue_id', $source->getKey())->update(['issue_id' => $target->getKey()]);
            Feedback::query()->where('issue_id', $source->getKey())->update(['issue_id' => $target->getKey()]);
            IssueFingerprint::query()->where('issue_id', $source->getKey())->update(['issue_id' => $target->getKey()]);
            IssueActivity::query()->where('issue_id', $source->getKey())->update(['issue_id' => $target->getKey()]);
            Issue::query()->where('merged_into_id', $source->getKey())->update(['merged_into_id' => $target->getKey()]);

            $sourceIsOlder = $source->first_seen_at->lessThan($target->first_seen_at);
            $sourceIsNewer = $source->last_seen_at->greaterThan($target->last_seen_at);

            $target->forceFill([
                'events_count' => $target->events_count + $source->events_count,
                'users_count' => (int) Event::query()
                    ->where('issue_id', $target->getKey())
                    ->whereNotNull('user_identifier')
                    ->distinct()
                    ->count('user_identifier'),
                'environments' => array_values(array_unique([...$target->environments, ...$source->environments])),
                'first_seen_at' => $sourceIsOlder ? $source->first_seen_at : $target->first_seen_at,
                'first_release' => $sourceIsOlder ? ($source->first_release ?? $target->first_release) : $target->first_release,
                'last_seen_at' => $sourceIsNewer ? $source->last_seen_at : $target->last_seen_at,
                'last_release' => $sourceIsNewer ? ($source->last_release ?? $target->last_release) : $target->last_release,
                'level' => $source->level->atLeast($target->level) ? $source->level : $target->level,
            ])->save();

            $source->forceFill([
                'merged_into_id' => $target->getKey(),
                'events_count' => 0,
                'users_count' => 0,
                'status' => IssueStatus::Resolved,
                'resolved_at' => now(),
                'resolved_by' => $by->getKey(),
            ])->save();

            $this->log($target, $by, IssueActivity::MERGED, ['from' => $source->getKey(), 'title' => $source->title]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function log(Issue $issue, ?User $by, string $action, ?array $meta = null): void
    {
        IssueActivity::create([
            'issue_id' => $issue->getKey(),
            'user_id' => $by?->getKey(),
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
