<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $settings
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'settings' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * A value of the `settings` json (cooldown_minutes, max_alerts_per_hour, threshold_count, language, …).
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** Language of alert messages and digests. */
    public function language(): string
    {
        $language = $this->setting('language', 'en');

        return in_array($language, User::LOCALES, true) ? $language : 'en';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<ProjectToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(ProjectToken::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * @return HasMany<Deploy, $this>
     */
    public function deploys(): HasMany
    {
        return $this->hasMany(Deploy::class);
    }

    /**
     * @return HasMany<AlertChannel, $this>
     */
    public function alertChannels(): HasMany
    {
        return $this->hasMany(AlertChannel::class);
    }

    /**
     * @return HasMany<AlertRule, $this>
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
