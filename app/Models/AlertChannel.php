<?php

namespace App\Models;

use App\Enums\Level;
use Database\Factories\AlertChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
