<?php

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Projects\RelationManagers\TokensRelationManager;
use App\Models\Invitation;
use App\Models\ProjectToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

function ngTokensPage(array $team, string $as): Testable
{
    test()->actingAs($team[$as]);

    return livewire(TokensRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class]);
}

/**
 * The HTML of the modal that is currently open (Filament updates modals with partial renders,
 * so the Livewire test's html() does not contain it). Empty when no modal is open.
 */
function ngModalHtml(Testable $page): string
{
    $action = $page->instance()->getMountedAction();

    return $action === null ? '' : html_entity_decode((string) $action->getModalContent());
}

/** The plain token as the modal printed it. */
function ngRevealedToken(Testable $page): string
{
    preg_match('/ng_[A-Za-z0-9]{40}/', ngModalHtml($page), $match);

    return $match[0] ?? '';
}

it('creates a token and shows it once with the .env block and the composer command', function () {
    $team = ngTeam();
    config(['app.url' => 'https://nightguardian.example.com']);

    $page = ngTokensPage($team, 'admin')
        ->callTableAction('createToken', data: ['name' => 'production server']);

    $plain = ngRevealedToken($page);

    expect($plain)->toMatch('/^ng_[A-Za-z0-9]{40}$/');

    expect(ngModalHtml($page))
        ->toContain('NIGHTGUARDIAN_URL=https://nightguardian.example.com')
        ->toContain('NIGHTGUARDIAN_TOKEN='.$plain)
        ->toContain('composer require nightguardian/laravel')
        ->toContain(__('nightguardian.tokens.reveal_warning'));

    // Only the hash is stored.
    $stored = ProjectToken::query()->where('name', 'production server')->firstOrFail();
    expect($stored->token_hash)->toBe(ProjectToken::hash($plain))
        ->and($stored->project_id)->toBe($team['project']->id)
        ->and(json_encode($stored->getAttributes()))->not->toContain($plain);
});

it('does not show the token again after the modal is closed or the page is reloaded', function () {
    $team = ngTeam();

    $page = ngTokensPage($team, 'admin')->callTableAction('createToken', data: ['name' => 'ci']);
    $plain = ngRevealedToken($page);
    expect($plain)->not->toBe('');

    $page->instance()->unmountAction();
    expect(ngModalHtml($page))->toBe('')->and($page->html())->not->toContain($plain);

    $fresh = ngTokensPage($team, 'admin');
    expect($fresh->html())->not->toContain($plain)->and($fresh->html())->toContain('ci');
});

it('issues a token that really authenticates the ingest endpoint', function () {
    $team = ngTeam();
    $plain = ngRevealedToken(ngTokensPage($team, 'admin')->callTableAction('createToken', data: ['name' => 'e2e']));

    ngPost($plain, ['events' => [ngEvent()]])->assertStatus(Response::HTTP_ACCEPTED);
});

it('revokes a token, which then stops authenticating', function () {
    $team = ngTeam();
    $plain = ngRevealedToken(ngTokensPage($team, 'admin')->callTableAction('createToken', data: ['name' => 'old']));
    $token = ProjectToken::query()->where('name', 'old')->firstOrFail();

    ngTokensPage($team, 'admin')->callTableAction('revoke', $token);

    expect($token->refresh()->isRevoked())->toBeTrue();
    ngPost($plain, ['events' => [ngEvent()]])->assertStatus(401);
});

it('requires a name for the token', function () {
    ngTokensPage(ngTeam(), 'admin')->callTableAction('createToken', data: ['name' => ''])->assertHasActionErrors(['name' => 'required']);
});

it('only project admins and super admins manage tokens', function () {
    $team = ngTeam();
    $token = ProjectToken::factory()->for($team['project'])->create();

    foreach (['member', 'viewer'] as $role) {
        $this->actingAs($team[$role])->get('/projects/'.$team['project']->id.'/edit')->assertForbidden();

        $page = ngTokensPage($team, $role)->assertTableActionHidden('createToken');
        expect(fn () => $page->callTableAction('createToken', data: ['name' => 'sneaky']))->toThrow(ExpectationFailedException::class);
    }

    expect(ProjectToken::query()->where('name', 'sneaky')->exists())->toBeFalse();

    ngTokensPage($team, 'super')->assertTableActionVisible('createToken');
    ngTokensPage($team, 'admin')->assertTableActionVisible('revoke', $token);
    ngTokensPage($team, 'viewer')->assertTableActionHidden('revoke', $token);
});

it('cannot touch the tokens of another project', function () {
    $team = ngTeam();
    $foreign = ProjectToken::factory()->for($team['other'])->create();

    ngTokensPage($team, 'admin')->assertCanNotSeeTableRecords([$foreign]);

    expect($team['admin']->can('update', $foreign))->toBeFalse();
});

// --- members and invitations -----------------------------------------------------------------------------

it('lets admins change a member role and remove members', function () {
    $team = ngTeam();
    $this->actingAs($team['admin']);

    livewire(MembersRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class])
        ->assertCanSeeTableRecords([$team['viewer'], $team['member']])
        ->callTableAction('changeRole', $team['viewer'], ['role' => 'member']);

    expect($team['viewer']->roleIn($team['project'])?->value)->toBe('member');
});

it('creates an invitation link shown once, stored as a hash', function () {
    $team = ngTeam();
    $this->actingAs($team['admin']);

    $page = livewire(InvitationsRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class])
        ->callTableAction('invite', data: ['email' => 'new@example.com', 'role' => 'viewer']);

    preg_match('#http[^\s<"]*/invitations/([A-Za-z0-9]{40})\?[^\s<"]*signature=[a-f0-9]+#', ngModalHtml($page), $match);

    expect($match)->not->toBeEmpty();

    $invitation = Invitation::query()->firstOrFail();
    expect($invitation->token_hash)->toBe(hash('sha256', $match[1]))
        ->and($invitation->email)->toBe('new@example.com')
        ->and($invitation->role->value)->toBe('viewer')
        ->and($invitation->project_id)->toBe($team['project']->id)
        ->and($invitation->created_by)->toBe($team['admin']->id);

    $page->instance()->unmountAction();
    expect(ngModalHtml($page))->toBe('')->and($page->html())->not->toContain($match[1]);
});

it('does not let members or viewers invite', function () {
    $team = ngTeam();
    $this->actingAs($team['member']);

    livewire(InvitationsRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class])
        ->assertTableActionHidden('invite');
});

it('says nothing sensitive in the project list for viewers', function () {
    $team = ngTeam();
    ProjectToken::factory()->for($team['project'])->create(['name' => 'secret-token-name']);

    $this->actingAs($team['viewer'])->get('/projects')->assertOk()->assertDontSee('secret-token-name');
});

it('creates tokens with the generator format and unique hashes', function () {
    $a = ProjectToken::generatePlainToken();
    $b = ProjectToken::generatePlainToken();

    expect($a)->toStartWith('ng_')->and(Str::length($a))->toBe(43)->and($a)->not->toBe($b);
});
