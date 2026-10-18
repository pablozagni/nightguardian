<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $project_id
 * @property ProjectRole|null $role
 * @property bool $is_super_admin
 * @property string|null $email
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'is_super_admin' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function isInstanceLevel(): bool
    {
        return $this->project_id === null;
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
