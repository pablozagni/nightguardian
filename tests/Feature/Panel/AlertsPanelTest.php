<?php

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\AlertChannelsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\AlertRulesRelationManager;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\AlertRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

const NG_UI_TOKEN = '987654321:AAUiTestTokenOnlyForTests_0123456789ab';

function ngChannelsPage(array $team, string $as)
{
    test()->actingAs($team[$as]);

    return livewire(AlertChannelsRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class]);
}

function ngRulesPage(array $team, string $as)
{
    test()->actingAs($team[$as]);

    return livewire(AlertRulesRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class]);
}

// --- channels --------------------------------------------------------------------------------------------

it('lets a project admin add a Telegram channel and stores the token encrypted', function () {
    $team = ngTeam();

    ngChannelsPage($team, 'admin')
        ->callTableAction('create', data: [
            'name' => 'On-call',
            'type' => 'telegram',
            'config' => ['bot_token' => NG_UI_TOKEN, 'chat_id' => '-1001234567890'],
            'min_level' => 'error',
            'notify_new' => true,
            'notify_regressed' => true,
            'enabled' => true,
        ])
        ->assertHasNoTableActionErrors();

    $channel = AlertChannel::firstOrFail();
    $raw = DB::table('alert_channels')->where('id', $channel->id)->value('config');

    expect($channel->project_id)->toBe($team['project']->id)
        ->and($channel->type)->toBe('telegram')
        ->and($channel->config)->toBe(['bot_token' => NG_UI_TOKEN, 'chat_id' => '-1001234567890'])
        ->and($raw)->not->toContain(NG_UI_TOKEN);
});

it('rejects channels with invalid settings', function () {
    $team = ngTeam();

    ngChannelsPage($team, 'admin')->callTableAction('create', data: [
        'name' => 'Bad', 'type' => 'telegram', 'config' => ['bot_token' => 'nope', 'chat_id' => 'me'], 'min_level' => 'error', 'enabled' => true,
    ]);

    expect(AlertChannel::count())->toBe(0);
});

it('adds an e-mail channel', function () {
    $team = ngTeam();

    ngChannelsPage($team, 'admin')->callTableAction('create', data: [
        'name' => 'Team', 'type' => 'mail', 'config' => ['recipients' => ['dev@example.com', 'ops@example.com']], 'min_level' => 'warning', 'notify_new' => true, 'enabled' => true,
    ]);

    expect(AlertChannel::firstOrFail()->config)->toBe(['recipients' => ['dev@example.com', 'ops@example.com']]);
});

it('never sends the saved secret back to the browser and keeps it when the field is left blank', function () {
    $team = ngTeam();
    $channel = AlertChannel::factory()->for($team['project'])->create(['type' => 'telegram', 'config' => ['bot_token' => NG_UI_TOKEN, 'chat_id' => '12345']]);

    $page = ngChannelsPage($team, 'admin')->mountTableAction('edit', $channel);

    expect(json_encode($page->instance()->mountedActions))->not->toContain(NG_UI_TOKEN);
    expect($page->html())->not->toContain(NG_UI_TOKEN);

    ngChannelsPage($team, 'admin')->callTableAction('edit', $channel, data: ['name' => 'Renamed', 'config' => ['bot_token' => '', 'chat_id' => '67890']]);

    $fresh = $channel->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->config)->toBe(['bot_token' => NG_UI_TOKEN, 'chat_id' => '67890']);

    ngChannelsPage($team, 'admin')->callTableAction('edit', $channel, data: ['config' => ['bot_token' => '111111111:AAReplacementTokenForTests_0123456789ab', 'chat_id' => '67890']]);
    expect($channel->fresh()->config['bot_token'])->toBe('111111111:AAReplacementTokenForTests_0123456789ab');
});

it('sends a test through the channel and shows the result', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $team = ngTeam();
    $channel = AlertChannel::factory()->for($team['project'])->create(['type' => 'telegram', 'config' => ['bot_token' => NG_UI_TOKEN, 'chat_id' => '12345'], 'last_error' => 'earlier failure']);

    ngChannelsPage($team, 'admin')->callTableAction('test', $channel)->assertNotified(__('alerts.test_sent'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/bot'.NG_UI_TOKEN.'/sendMessage') && str_contains($request['text'], 'NightGuardian test'));
    expect($channel->fresh()->last_error)->toBeNull()
        ->and($channel->fresh()->last_sent_at)->not->toBeNull()
        ->and(AlertLog::first()->only(['kind', 'status']))->toBe(['kind' => 'test', 'status' => 'sent']);
});

it('shows why a test failed, without the token, and remembers the error', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
    $team = ngTeam();
    $channel = AlertChannel::factory()->for($team['project'])->create(['type' => 'telegram', 'config' => ['bot_token' => NG_UI_TOKEN, 'chat_id' => '12345']]);

    $page = ngChannelsPage($team, 'admin')->callTableAction('test', $channel)->assertNotified(__('alerts.test_failed'));

    expect($channel->fresh()->last_error)->toBe('Telegram: Bad Request: chat not found')
        ->and(AlertLog::first()->status)->toBe('failed');

    $page->assertSee('Telegram: Bad Request: chat not found')->assertDontSee(NG_UI_TOKEN);
});

it('does not let members, viewers or other projects touch channels', function () {
    $team = ngTeam();
    $channel = AlertChannel::factory()->for($team['project'])->create(['type' => 'telegram', 'config' => ['bot_token' => NG_UI_TOKEN, 'chat_id' => '12345']]);
    Http::fake();

    foreach (['member', 'viewer'] as $role) {
        $this->actingAs($team[$role])->get('/projects/'.$team['project']->id.'/edit')->assertForbidden();

        $page = ngChannelsPage($team, $role);
        $page->assertTableActionHidden('create')->assertTableActionHidden('test', $channel)->assertTableActionHidden('edit', $channel)->assertTableActionHidden('delete', $channel);
        expect(fn () => $page->callTableAction('test', $channel))->toThrow(ExpectationFailedException::class);
    }

    Http::assertNothingSent();

    $this->actingAs($team['outsider'])->get('/projects/'.$team['project']->id.'/edit')->assertNotFound();
});

it('lets super admins and admins delete a channel', function () {
    $team = ngTeam();
    $channel = AlertChannel::factory()->for($team['project'])->create();

    ngChannelsPage($team, 'super')->callTableAction('delete', $channel);

    expect(AlertChannel::count())->toBe(0);
});

it('shows the last error of a failing channel in the list', function () {
    $team = ngTeam();
    AlertChannel::factory()->for($team['project'])->create(['name' => 'Broken', 'last_error' => 'Telegram: Unauthorized']);

    ngChannelsPage($team, 'admin')->assertSee('Broken')->assertSee('Telegram: Unauthorized');
});

// --- rules ---------------------------------------------------------------------------------------------------

it('creates, edits and deletes rules, including tag rules', function () {
    $team = ngTeam();

    $page = ngRulesPage($team, 'admin')->callTableAction('create', data: [
        'name' => 'Ignore 404s', 'field' => 'exception_class', 'operator' => 'equals', 'value' => 'NotFoundHttpException', 'action' => 'never_notify', 'position' => 1, 'enabled' => true,
    ]);
    $page->callTableAction('create', data: [
        'name' => 'VIP tenant', 'field' => 'tag', 'tag_key' => 'tenant', 'operator' => 'equals', 'value' => 'acme', 'action' => 'always_notify', 'position' => 2, 'enabled' => true,
    ]);

    $tag = AlertRule::where('name', 'VIP tenant')->firstOrFail();
    expect(AlertRule::count())->toBe(2)->and($tag->field)->toBe('tag:tenant')->and($tag->project_id)->toBe($team['project']->id);

    $page->callTableAction('edit', $tag, data: ['value' => 'globex']);
    expect($tag->fresh()->value)->toBe('globex')->and($tag->fresh()->field)->toBe('tag:tenant');

    $page->callTableAction('delete', $tag);
    expect(AlertRule::count())->toBe(1);
});

it('does not let members or viewers manage rules', function () {
    $team = ngTeam();
    $rule = AlertRule::factory()->for($team['project'])->create();

    foreach (['member', 'viewer'] as $role) {
        ngRulesPage($team, $role)->assertTableActionHidden('create')->assertTableActionHidden('edit', $rule)->assertTableActionHidden('delete', $rule);
    }
});

// --- project alert settings -----------------------------------------------------------------------------------

it('saves the alert settings of the project', function () {
    $team = ngTeam();
    $this->actingAs($team['admin']);

    livewire(EditProject::class, ['record' => $team['project']->getKey()])
        ->fillForm(['settings' => ['language' => 'es', 'cooldown_minutes' => 15, 'max_alerts_per_hour' => 5, 'threshold_count' => 10, 'threshold_minutes' => 30, 'digest_skip_empty' => false]])
        ->call('save')
        ->assertHasNoFormErrors();

    $project = $team['project']->fresh();
    expect($project->language())->toBe('es')
        ->and($project->setting('cooldown_minutes'))->toBe(15)
        ->and($project->setting('max_alerts_per_hour'))->toBe(5)
        ->and($project->setting('threshold_count'))->toBe(10)
        ->and($project->setting('digest_skip_empty'))->toBeFalse();
});

it('renders the alerts tabs in Spanish', function () {
    $team = ngTeam();
    $team['admin']->update(['locale' => 'es']);

    $this->actingAs($team['admin'])->get('/projects/'.$team['project']->id.'/edit')->assertOk()->assertSee('Canales de aviso')->assertSee('Reglas de aviso');
});
