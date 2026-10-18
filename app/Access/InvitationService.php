<?php

namespace App\Access;

use App\Enums\ProjectRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invitation links: a random token (only its sha256 is stored) inside a signed, expiring URL.
 * Project invitations give a role in one project; instance invitations (project_id null) can create a super admin.
 */
class InvitationService
{
    public const VALID_DAYS = 7;

    /**
     * @return array{invitation: Invitation, url: string}
     */
    public function invite(User $by, ?Project $project, ?ProjectRole $role, ?string $email = null, bool $superAdmin = false): array
    {
        $plain = Str::random(40);

        $invitation = Invitation::create([
            'project_id' => $project?->getKey(),
            'role' => $project === null ? null : ($role ?? ProjectRole::Viewer),
            'is_super_admin' => $project === null && $superAdmin,
            'email' => $email === null || trim($email) === '' ? null : trim($email),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(self::VALID_DAYS),
            'created_by' => $by->getKey(),
        ]);

        $url = URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['token' => $plain]);

        return ['invitation' => $invitation, 'url' => $url];
    }

    /**
     * The pending (not accepted, not expired) invitation behind a plain token, if any.
     */
    public function find(string $plain): ?Invitation
    {
        return Invitation::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Creates the user and gives them the invited access. The e-mail of an invitation that has one is fixed.
     *
     * @throws RuntimeException when an account with that e-mail already exists
     */
    public function accept(Invitation $invitation, string $name, string $email, string $password, string $locale): User
    {
        $email = $invitation->email ?? $email;

        return DB::transaction(function () use ($invitation, $name, $email, $password, $locale) {
            $fresh = Invitation::query()->lockForUpdate()->find($invitation->getKey());

            if ($fresh === null || $fresh->accepted_at !== null || $fresh->expires_at->isPast()) {
                throw new RuntimeException('invitation_unavailable');
            }

            if (User::query()->where('email', $email)->exists()) {
                throw new RuntimeException('email_taken');
            }

            $user = new User(['name' => $name, 'email' => $email, 'password' => $password, 'locale' => $locale]);
            $user->forceFill(['is_super_admin' => $fresh->is_super_admin, 'email_verified_at' => now()])->save();

            if ($fresh->project_id !== null && $fresh->role !== null) {
                $user->projects()->attach($fresh->project_id, ['role' => $fresh->role->value]);
            }

            $fresh->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }
}
