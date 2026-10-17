<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\Level;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $uuid
 * @property string|null $issue_id
 * @property EventType $type
 * @property string|null $reference
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    // occurred_at and received_at are timestamp(3) columns.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'level' => Level::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'status_code' => 'integer',
            'payload' => 'array',
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
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
