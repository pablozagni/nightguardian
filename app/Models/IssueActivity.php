<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of an issue's history: who changed its status and how.
 *
 * @property string $action
 * @property array<string, mixed>|null $meta
 */
class IssueActivity extends Model
{
    public const UPDATED_AT = null;

    public const RESOLVED = 'resolved';

    public const RESOLVED_IN_RELEASE = 'resolved_in_release';

    public const IGNORED = 'ignored';

    public const REOPENED = 'reopened';

    public const REGRESSED = 'regressed';

    public const MERGED = 'merged';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
