<?php

use App\Access\InvitationService;
use App\Enums\ProjectRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function ngInvite(array $team, ?ProjectRole $role = ProjectRole::Member, ?string $email = 'new@example.com', bool $instance = false): array
{
    return app(InvitationService::class)->invite($team['admin'], $instance ? null : $team['project'], $role, $email, $instance && $role === null);
}

function ngAcceptData(array $override = []): array
{
    return $override + ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'];
}

it('shows the invitation page with the project and the role', function () {
    $team = ngTeam();
    $invite = ngInvite($team, ProjectRole::Viewer);

    $this->get($invite['url'])->assertOk()->assertSee('Shop')->assertSee('Viewer')->assertSee('new@example.com');
});

it('creates the account, joins the project with the invited role and signs in', function () {
    $team = ngTeam();
    $invite = ngInvite($team, ProjectRole::Member);

    $this->post($invite['url'], ngAcceptData())->assertRedirect('/');

    $user = User::where('email', 'new@example.com')->firstOrFail();
    expect($user->roleIn($team['project'])?->value)->toBe('member')
        ->and($user->is_super_admin)->toBeFalse()
        ->and(Hash::check('a-long-password-1', $user->password))->toBeTrue()
        ->and(Invitation::first()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('fixes the e-mail of an invitation that has one', function () {
    $team = ngTeam();
    $invite = ngInvite($team);

    $this->post($invite['url'], ngAcceptData(['email' => 'someone-else@example.com']))->assertRedirect('/');

    expect(User::where('email', 'new@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();
});

it('asks for the e-mail when the invitation has none', function () {
    $team = ngTeam();
    $invite = ngInvite($team, ProjectRole::Viewer, null);

    $this->post($invite['url'], ngAcceptData(['email' => '']))->assertSessionHasErrors('email');
    $this->post($invite['url'], ngAcceptData(['email' => 'chosen@example.com']))->assertRedirect('/');
    expect(User::where('email', 'chosen@example.com')->exists())->toBeTrue();
});

it('can only be used once', function () {
    $team = ngTeam();
    $invite = ngInvite($team);

    $this->post($invite['url'], ngAcceptData())->assertRedirect('/');
    auth()->logout();

    $this->get($invite['url'])->assertOk()->assertSee('no longer valid');
    $this->post($invite['url'], ngAcceptData(['name' => 'Second']))->assertOk()->assertSee('no longer valid');
    expect(User::where('name', 'Second')->exists())->toBeFalse();
});

it('rejects tampered, unsigned and expired links', function () {
    $team = ngTeam();
    $invite = ngInvite($team);
    $plain = basename(parse_url($invite['url'], PHP_URL_PATH));

    $this->get("/invitations/{$plain}")->assertForbidden();
    $this->get($invite['url'].'x')->assertForbidden();
    $this->post("/invitations/{$plain}", ngAcceptData())->assertForbidden();

    $this->travel(8)->days();
    $this->get($invite['url'])->assertForbidden();

    // Signed but for a token nobody was invited with.
    $fake = URL::temporarySignedRoute('invitations.show', now()->addDay(), ['token' => str_repeat('a', 40)]);
    $this->get($fake)->assertOk()->assertSee('no longer valid');
});

it('rejects expired invitations even with a valid signature', function () {
    $team = ngTeam();
    $invite = ngInvite($team);
    Invitation::query()->update(['expires_at' => now()->subMinute()]);

    $this->get($invite['url'])->assertOk()->assertSee('no longer valid');
});

it('validates the password', function (string $password) {
    $team = ngTeam();
    $invite = ngInvite($team);

    $this->post($invite['url'], ngAcceptData(['password' => $password, 'password_confirmation' => $password]))->assertSessionHasErrors('password');
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
})->with(['short' => 'short', 'eleven chars' => 'abcdefghijk']);

it('requires matching password confirmation', function () {
    $team = ngTeam();
    $invite = ngInvite($team);

    $this->post($invite['url'], ngAcceptData(['password_confirmation' => 'different-password-1']))->assertSessionHasErrors('password');
});

it('does not take over an existing account', function () {
    $team = ngTeam();
    $existing = User::factory()->create(['email' => 'new@example.com', 'name' => 'Original']);
    $invite = ngInvite($team);

    $this->post($invite['url'], ngAcceptData())->assertSessionHasErrors('email');

    expect($existing->refresh()->name)->toBe('Original')
        ->and($existing->roleIn($team['project']))->toBeNull()
        ->and(Invitation::first()->accepted_at)->toBeNull();
});

it('creates a super admin only from an instance-level invitation made by a super admin', function () {
    $team = ngTeam();
    $invite = app(InvitationService::class)->invite($team['super'], null, null, 'boss@example.com', true);

    $this->post($invite['url'], ngAcceptData(['email' => 'boss@example.com']))->assertRedirect('/');

    expect(User::where('email', 'boss@example.com')->firstOrFail()->is_super_admin)->toBeTrue();
});

it('does not give super admin through a project invitation', function () {
    $team = ngTeam();
    $invite = app(InvitationService::class)->invite($team['admin'], $team['project'], ProjectRole::Admin, 'x@example.com', true);

    $this->post($invite['url'], ngAcceptData(['email' => 'x@example.com']))->assertRedirect('/');

    expect(User::where('email', 'x@example.com')->firstOrFail()->is_super_admin)->toBeFalse();
});

it('speaks the language of the browser', function () {
    $team = ngTeam();
    $invite = ngInvite($team);

    $this->withHeaders(['Accept-Language' => 'es-AR,es;q=0.9'])->get($invite['url'])->assertSee('Unite a NightGuardian')->assertSee('Crear mi cuenta');
    $this->withHeaders(['Accept-Language' => 'en-US'])->get($invite['url'])->assertSee('Join NightGuardian');
});

it('lets super admins invite users from the users list, and nobody else', function () {
    $team = ngTeam();

    $this->actingAs($team['super']);
    $page = livewire(ListUsers::class)->callAction('invite', ['email' => 'staff@example.com', 'super_admin' => false]);
    expect(Invitation::first()->project_id)->toBeNull()->and(Invitation::first()->email)->toBe('staff@example.com');

    $this->actingAs($team['admin'])->get('/users')->assertForbidden();
});

it('edits users as a super admin, including the super admin flag', function () {
    $team = ngTeam();
    $this->actingAs($team['super']);

    livewire(EditUser::class, ['record' => $team['member']->getKey()])
        ->fillForm(['is_super_admin' => true, 'name' => 'Promoted'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($team['member']->refresh()->is_super_admin)->toBeTrue()->and($team['member']->name)->toBe('Promoted');
});
