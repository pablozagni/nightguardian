<?php

namespace App\Models;

use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Models\Concerns\VisibleToUser;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $title
 * @property string $type
 * @property string|null $exception_class
 * @property Carbon|null $last_alerted_at
 * @property string|null $culprit
 * @property Level $level
 * @property IssueStatus $status
 * @property int $events_count
 * @property int $users_count
 * @property array<int, string> $environments
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $last_release
 * @property string|null $first_release
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolved_in_release
 * @property Carbon|null $ignored_until
 * @property string|null $merged_into_id
 */
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, HasUlids, VisibleToUser;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'environments' => '[]',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'status' => IssueStatus::class,
            'events_count' => 'integer',
            'users_count' => 'integer',
            'environments' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_until' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<IssueFingerprint, $this>
     */
    public function fingerprints(): HasMany
    {
        return $this->hasMany(IssueFingerprint::class);
    }

    /**
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * @return HasMany<IssueActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class);
    }

    /**
     * @return HasMany<AlertLog, $this>
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }
}
