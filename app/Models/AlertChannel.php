<?php

namespace App\Models;

use App\Enums\Level;
use Database\Factories\AlertChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property Level $min_level
 * @property bool $notify_new
 * @property bool $notify_regressed
 * @property bool $notify_threshold
 * @property bool $include_digest
 * @property bool $enabled
 * @property string|null $last_error
 * @property Carbon|null $last_sent_at
 */
class AlertChannel extends Model
{
    /** @use HasFactory<AlertChannelFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['config'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'min_level' => Level::class,
            'notify_new' => 'boolean',
            'notify_regressed' => 'boolean',
            'notify_threshold' => 'boolean',
            'include_digest' => 'boolean',
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
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
     * @return HasMany<AlertLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }
}
