<?php

namespace App\Ingest;

use App\Models\Issue;

/**
 * Outcome of recording an event on an issue.
 */
final class IssueUpdate
{
    public const NEW = 'new';

    public const REGRESSED = 'regressed';

    /**
     * @param  self::NEW|self::REGRESSED|null  $transition  Set when the issue became new or regressed (alert trigger).
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly ?string $transition,
    ) {}
}
