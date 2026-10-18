<?php

use App\Alerts\AlertMessage;
use App\Alerts\ChannelRegistry;
use App\Alerts\Channels\MailChannel;
use App\Alerts\Channels\TelegramChannel;
use App\Jobs\SendAlert;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\AlertRule;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const NG_FAKE_BOT_TOKEN = '123456789:AAFakeTokenForTestsOnly_0123456789abc';

function ngTelegram(array $overrides = []): AlertChannel
{
    return AlertChannel::factory()->for(Project::factory()->create(['name' => 'Acme']))->create($overrides + [
        'type' => 'telegram',
        'name' => 'On-call',
        'config' => ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '-1001234567890'],
    ]);
}

function ngMessage(): AlertMessage
{
    return new AlertMessage('new', '🔴', 'NEW · Acme (production)', 'Error: <b>boom</b> & "more"', ['Controller@update · release 1.0', '3 events · 1 user'], 'https://ng.example.com/issues/1');
}

// --- Telegram ---------------------------------------------------------------------------------------------

it('posts to the Telegram Bot API with HTML parse mode, a 10 second timeout and escaped content', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $channel = ngTelegram();

    (new TelegramChannel)->send(ngMessage(), $channel->config);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.telegram.org/bot'.NG_FAKE_BOT_TOKEN.'/sendMessage'
            && $request->method() === 'POST'
            && $body['chat_id'] === '-1001234567890'
            && $body['parse_mode'] === 'HTML'
            && str_starts_with($body['text'], '<b>🔴 NEW · Acme (production)</b>')
            && str_contains($body['text'], 'Error: &lt;b&gt;boom&lt;/b&gt; &amp; "more"')
            && str_contains($body['text'], '→ https://ng.example.com/issues/1')
            && ! str_contains($body['text'], '<b>boom</b>');
    });
});

it('turns a Telegram error into an exception that never contains the bot token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

    try {
        (new TelegramChannel)->send(ngMessage(), ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1']);
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Telegram: Unauthorized');
    }
});

it('scrubs the token from connection errors too', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot'.NG_FAKE_BOT_TOKEN.'/sendMessage'));

    try {
        (new TelegramChannel)->send(ngMessage(), ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1']);
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain(NG_FAKE_BOT_TOKEN)->toContain('[token]');
    }
});

it('sends the test message', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    (new TelegramChannel)->test(['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1']);

    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'NightGuardian test') && str_contains($r['text'], 'the channel works'));
});

it('validates Telegram settings', function (array $config, int $problems) {
    expect(TelegramChannel::validate($config))->toHaveCount($problems);
})->with([
    'valid user chat' => [['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '123456789'], 0],
    'valid group chat' => [['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '-1001234567890'], 0],
    'valid channel name' => [['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '@my_alerts'], 0],
    'bad token' => [['bot_token' => 'not-a-token', 'chat_id' => '123456789'], 1],
    'bad chat' => [['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => 'me'], 1],
    'nothing' => [[], 2],
]);

// --- mail ---------------------------------------------------------------------------------------------------

it('sends plain text e-mail to every recipient', function () {
    (new MailChannel)->send(ngMessage(), ['recipients' => ['a@example.com', 'b@example.com']]);

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $email = $messages[0]->getOriginalMessage();

    expect($messages)->toHaveCount(1)
        ->and(collect($email->getTo())->map(fn ($address) => $address->getAddress())->all())->toBe(['a@example.com', 'b@example.com'])
        ->and($email->getSubject())->toContain('NEW · Acme (production)')
        ->and($email->getTextBody())->toContain('Controller@update · release 1.0')->toContain('→ https://ng.example.com/issues/1')
        ->and($email->getHtmlBody())->toBeNull();
});

it('validates e-mail recipients', function () {
    expect(MailChannel::validate(['recipients' => ['ok@example.com']]))->toBe([])
        ->and(MailChannel::validate(['recipients' => []]))->toHaveCount(1)
        ->and(MailChannel::validate(['recipients' => ['nope']]))->toHaveCount(1);
});

// --- SendAlert ----------------------------------------------------------------------------------------------

it('delivers, logs the delivery and clears the last error', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $channel = ngTelegram(['last_error' => 'old problem']);
    $issue = Issue::factory()->for($channel->project)->create();

    SendAlert::dispatchSync($channel->id, 'new', $issue->id, ngMessage()->toArray());

    expect($channel->refresh()->last_error)->toBeNull()
        ->and($channel->last_sent_at)->not->toBeNull()
        ->and(AlertLog::first()->only(['alert_channel_id', 'issue_id', 'kind', 'status']))->toBe(['alert_channel_id' => $channel->id, 'issue_id' => $issue->id, 'kind' => 'new', 'status' => 'sent']);
});

it('records the failure in the channel and the log, without leaking the token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $channel = ngTelegram();
    $job = new SendAlert($channel->id, 'new', null, ngMessage()->toArray());

    try {
        $job->handle(app(ChannelRegistry::class));
        $this->fail('expected an exception so the queue retries');
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect($channel->refresh()->last_error)->toBe('Telegram: Forbidden: bot was blocked by the user')
        ->and(AlertLog::first()->status)->toBe('failed')
        ->and(AlertLog::first()->error)->toBe('Telegram: Forbidden: bot was blocked by the user')
        ->and(json_encode(DB::table('alert_logs')->get()))->not->toContain(NG_FAKE_BOT_TOKEN)
        ->and(json_encode(DB::table('alert_channels')->get(['last_error'])))->not->toContain(NG_FAKE_BOT_TOKEN);
});

it('is retried 3 times with a 30, 120 and 600 second backoff', function () {
    $job = new SendAlert(1, 'new', null, []);

    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([30, 120, 600]);
});

it('retries through the queue and succeeds when Telegram recovers', function () {
    Http::fakeSequence('api.telegram.org/*')->push(['ok' => false, 'description' => 'Bad Gateway'], 502)->push(['ok' => true]);
    $channel = ngTelegram();
    $job = new SendAlert($channel->id, 'new', null, ngMessage()->toArray());

    expect(fn () => $job->handle(app(ChannelRegistry::class)))->toThrow(RuntimeException::class);
    expect($channel->refresh()->last_error)->toBe('Telegram: Bad Gateway');

    $job->handle(app(ChannelRegistry::class));

    expect($channel->refresh()->last_error)->toBeNull()->and(AlertLog::pluck('status')->all())->toBe(['sent']);
});

it('does nothing for a disabled or deleted channel', function () {
    Http::fake();
    $channel = ngTelegram(['enabled' => false]);

    SendAlert::dispatchSync($channel->id, 'new', null, ngMessage()->toArray());
    SendAlert::dispatchSync(99999, 'new', null, ngMessage()->toArray());

    Http::assertNothingSent();
    expect(AlertLog::count())->toBe(0);
});

it('stores the channel config encrypted and never exposes it', function () {
    $channel = ngTelegram();

    $raw = DB::table('alert_channels')->where('id', $channel->id)->value('config');

    expect($raw)->not->toContain(NG_FAKE_BOT_TOKEN)->not->toContain('1234567890')
        ->and($channel->fresh()->config['bot_token'])->toBe(NG_FAKE_BOT_TOKEN)
        ->and($channel->fresh()->toArray())->not->toHaveKey('config')
        ->and(json_encode($channel->fresh()))->not->toContain(NG_FAKE_BOT_TOKEN);
});

// --- the whole path from the ingest API to Telegram --------------------------------------------------------

it('sends a Telegram alert when the ingest API receives a new error, and not for the same error again', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    [$project, $token] = ngProject(['name' => 'Acme Billing']);
    AlertChannel::factory()->for($project)->create(['type' => 'telegram', 'config' => ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1234567']]);

    ngPost($token, ['events' => [ngEvent()]])->assertStatus(202);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'NEW · Acme Billing (production)') && str_contains($r['text'], 'QueryException'));

    ngPost($token, ['events' => [ngEvent()]])->assertStatus(202);
    Http::assertSentCount(1);
    expect(Event::count())->toBe(2);
});

it('alerts on a regression once the issue was resolved', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    [$project, $token] = ngProject();
    AlertChannel::factory()->for($project)->create(['type' => 'telegram', 'config' => ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1234567']]);

    ngPost($token, ['events' => [ngEvent()]]);
    $issue = Issue::firstOrFail();
    $issue->update(['status' => 'resolved', 'last_alerted_at' => now()->subHours(2)]);

    ngPost($token, ['events' => [ngEvent()]]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'REGRESSED'));
});

it('sends the "connection OK" notice when the SDK sends a test event', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    [$project, $token] = ngProject(['name' => 'Acme Billing']);
    AlertChannel::factory()->for($project)->create(['type' => 'telegram', 'config' => ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1234567']]);

    ngPost($token, ['events' => [ngEvent(['type' => 'test'])]])->assertStatus(202);

    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Connection OK · Acme Billing'));
});

it('alerts on every matching event when an always_notify rule exists, respecting its 5 minute cooldown', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    [$project, $token] = ngProject();
    AlertChannel::factory()->for($project)->create(['type' => 'telegram', 'config' => ['bot_token' => NG_FAKE_BOT_TOKEN, 'chat_id' => '1234567'], 'notify_new' => false, 'notify_regressed' => false]);
    AlertRule::factory()->for($project)->create(['field' => 'exception_class', 'operator' => 'contains', 'value' => 'QueryException', 'action' => 'always_notify']);

    ngPost($token, ['events' => [ngEvent()]]);
    ngPost($token, ['events' => [ngEvent()]]);
    Http::assertSentCount(1);

    $this->travel(6)->minutes();
    ngPost($token, ['events' => [ngEvent()]]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'NEW ·') || str_contains($r['text'], 'RULE ·'));
});
