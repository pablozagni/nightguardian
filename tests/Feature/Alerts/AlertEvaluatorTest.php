<?php

use App\Alerts\AlertEvaluator;
use App\Alerts\ThresholdChecker;
use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Filament\Resources\Issues\IssueResource;
use App\Jobs\EvaluateAlerts;
use App\Jobs\SendAlert;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\AlertRule;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

/**
 * A project with one issue (and its latest event) and the given channels.
 *
 * @param  array<string, mixed>  $channel  Overrides for the default channel (Telegram, min level error).
 * @return array{project: Project, issue: Issue, event: Event, channel: AlertChannel}
 */
function ngAlertSetup(array $channel = [], array $issue = [], array $event = [], array $project = []): array
{
    $project = Project::factory()->create(['name' => 'Acme Billing'] + $project);
    $channel = AlertChannel::factory()->for($project)->create($channel + [
        'type' => 'telegram',
        'name' => 'On-call',
        'config' => ['bot_token' => '123456:'.str_repeat('a', 30), 'chat_id' => '12345'],
        'min_level' => Level::Error,
        'notify_new' => true,
        'notify_regressed' => true,
    ]);
    $issue = Issue::factory()->for($project)->create($issue + ['title' => 'QueryException: cannot be null', 'level' => Level::Error, 'events_count' => 3, 'users_count' => 1, 'first_seen_at' => now()->subMinutes(2)]);
    $event = Event::factory()->for($project)->for($issue)->create($event + ['level' => Level::Error, 'environment' => 'production', 'release' => 'a1b2c3d']);

    return ['project' => $project, 'issue' => $issue->load('project'), 'event' => $event, 'channel' => $channel];
}

function ngEvaluate(array $s, string $trigger = 'new', ?int $count = null): string
{
    return app(AlertEvaluator::class)->evaluate($s['issue']->fresh()->load('project'), $trigger, $s['event'], $count);
}

function ngSent(): array
{
    $sent = [];
    Queue::assertPushed(SendAlert::class, function (SendAlert $job) use (&$sent) {
        $sent[] = $job;

        return true;
    });

    return $sent;
}

// --- the message ------------------------------------------------------------------------------------------

it('sends a short, actionable message for a new issue', function () {
    $s = ngAlertSetup(['name' => 'On-call'], ['culprit' => 'InvoiceController@update']);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::SENT);

    $jobs = ngSent();
    $message = $jobs[0]->message;

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->channelId)->toBe($s['channel']->id)
        ->and($jobs[0]->kind)->toBe('new')
        ->and($message['icon'])->toBe('🔴')
        ->and($message['headline'])->toBe('NEW · Acme Billing (production)')
        ->and($message['title'])->toBe('QueryException: cannot be null')
        ->and($message['lines'][0])->toBe('InvoiceController@update · release a1b2c3d')
        ->and($message['lines'][1])->toContain('3 events')->toContain('1 user')->toContain('first seen 2 minutes ago')
        ->and($message['url'])->toBe(IssueResource::getUrl('view', ['record' => $s['issue']]));
});

it('writes the message in the language of the project', function () {
    $s = ngAlertSetup(project: ['settings' => ['language' => 'es']]);

    ngEvaluate($s, 'regressed');

    $message = ngSent()[0]->message;
    expect($message['headline'])->toBe('REGRESIÓN · Acme Billing (production)')
        ->and($message['icon'])->toBe('🟠')
        ->and($message['lines'][1])->toContain('3 eventos')->toContain('visto por primera vez hace 2 minutos');
});

// --- filters, in the order of docs/alerts.md -------------------------------------------------------------

it('never alerts about an ignored issue', function () {
    $s = ngAlertSetup(issue: ['status' => IssueStatus::Ignored]);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::IGNORED);
    Queue::assertNothingPushed();
});

it('never alerts when a never_notify rule matches, even if an always_notify rule matches too', function () {
    $s = ngAlertSetup(issue: ['exception_class' => 'NotFoundHttpException']);
    AlertRule::factory()->for($s['project'])->create(['field' => 'exception_class', 'operator' => 'equals', 'value' => 'NotFoundHttpException', 'action' => 'never_notify']);
    AlertRule::factory()->for($s['project'])->create(['field' => 'exception_class', 'operator' => 'contains', 'value' => 'NotFound', 'action' => 'always_notify']);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::NEVER);
    Queue::assertNothingPushed();
});

it('ignores disabled rules', function () {
    $s = ngAlertSetup(issue: ['exception_class' => 'NotFoundHttpException']);
    AlertRule::factory()->for($s['project'])->create(['field' => 'exception_class', 'operator' => 'equals', 'value' => 'NotFoundHttpException', 'action' => 'never_notify', 'enabled' => false]);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::SENT);
});

it('applies the minimum level of each channel', function (Level $channelMin, Level $eventLevel, bool $expected) {
    $s = ngAlertSetup(['min_level' => $channelMin], ['level' => $eventLevel], ['level' => $eventLevel]);

    expect(ngEvaluate($s))->toBe($expected ? AlertEvaluator::SENT : AlertEvaluator::NO_CHANNELS);
})->with([
    'warning below error' => [Level::Error, Level::Warning, false],
    'error meets error' => [Level::Error, Level::Error, true],
    'critical above error' => [Level::Error, Level::Critical, true],
    'warning meets warning' => [Level::Warning, Level::Warning, true],
    'error below critical' => [Level::Critical, Level::Error, false],
]);

it('sends only through the channels that accept the level', function () {
    $s = ngAlertSetup(['min_level' => Level::Error]);
    $loud = AlertChannel::factory()->for($s['project'])->create(['type' => 'mail', 'config' => ['recipients' => ['a@example.com']], 'min_level' => Level::Critical]);

    ngEvaluate($s);

    expect(collect(ngSent())->pluck('channelId')->all())->toBe([$s['channel']->id])->not->toContain($loud->id);
});

it('raises the level to critical with a set_level_critical rule', function () {
    $s = ngAlertSetup(['min_level' => Level::Critical], ['level' => Level::Error, 'title' => 'Payment failed'], ['message' => 'Payment failed for order 7']);
    expect(ngEvaluate($s))->toBe(AlertEvaluator::NO_CHANNELS);

    AlertRule::factory()->for($s['project'])->create(['field' => 'message', 'operator' => 'contains', 'value' => 'Payment', 'action' => 'set_level_critical']);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::SENT);
});

it('respects the channel toggles for new, regressed and threshold alerts', function (string $trigger, string $toggle) {
    $s = ngAlertSetup([$toggle => false]);

    expect(ngEvaluate($s, $trigger))->toBe(AlertEvaluator::NO_CHANNELS);
    Queue::assertNothingPushed();

    $s['channel']->update([$toggle => true]);
    expect(ngEvaluate($s, $trigger))->toBe(AlertEvaluator::SENT);
})->with([['new', 'notify_new'], ['regressed', 'notify_regressed'], ['threshold', 'notify_threshold']]);

it('does not use disabled channels or archived projects', function () {
    $s = ngAlertSetup(['enabled' => false]);
    expect(ngEvaluate($s))->toBe(AlertEvaluator::NO_CHANNELS);

    $s['channel']->update(['enabled' => true]);
    $s['project']->update(['archived_at' => now()]);
    expect(ngEvaluate($s))->toBe(AlertEvaluator::ARCHIVED);
    Queue::assertNothingPushed();
});

// --- rules -------------------------------------------------------------------------------------------------

it('matches rules by field and operator', function (string $field, string $operator, string $value, bool $matches) {
    $s = ngAlertSetup(
        ['notify_new' => false, 'notify_regressed' => false],
        ['exception_class' => 'App\\Exceptions\\PaymentException', 'type' => 'exception'],
        ['message' => 'Gateway timeout', 'request_route' => 'invoices.update', 'level' => Level::Error, 'type' => 'exception', 'payload' => ['tags' => ['tenant' => 'acme']]],
    );
    AlertRule::factory()->for($s['project'])->create(['field' => $field, 'operator' => $operator, 'value' => $value, 'action' => 'always_notify']);

    expect(ngEvaluate($s, 'rule'))->toBe($matches ? AlertEvaluator::SENT : AlertEvaluator::NO_RULE);
})->with([
    'class equals' => ['exception_class', 'equals', 'App\\Exceptions\\PaymentException', true],
    'class equals other' => ['exception_class', 'equals', 'PaymentException', false],
    'class contains' => ['exception_class', 'contains', 'Payment', true],
    'class starts with' => ['exception_class', 'starts_with', 'App\\Exceptions', true],
    'class starts with wrong' => ['exception_class', 'starts_with', 'Exceptions', false],
    'message contains' => ['message', 'contains', 'timeout', true],
    'message regex' => ['message', 'regex', '^Gateway \w+$', true],
    'message regex no match' => ['message', 'regex', '^\d+$', false],
    'invalid regex never matches' => ['message', 'regex', '([unclosed', false],
    'route equals' => ['route', 'equals', 'invoices.update', true],
    'type equals' => ['type', 'equals', 'exception', true],
    'level equals' => ['level', 'equals', 'error', true],
    'tag equals' => ['tag:tenant', 'equals', 'acme', true],
    'tag other value' => ['tag:tenant', 'equals', 'globex', false],
    'missing tag' => ['tag:plan', 'equals', 'pro', false],
    'empty contains never matches' => ['message', 'contains', '', false],
]);

it('lets an always_notify rule alert on any event, bypassing the new and regressed toggles', function () {
    $s = ngAlertSetup(['notify_new' => false, 'notify_regressed' => false], ['exception_class' => 'PaymentException']);

    expect(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::NO_RULE);

    AlertRule::factory()->for($s['project'])->create(['field' => 'exception_class', 'operator' => 'equals', 'value' => 'PaymentException', 'action' => 'always_notify']);

    expect(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::SENT);
    $job = ngSent()[0];
    expect($job->kind)->toBe('rule')->and($job->message['icon'])->toBe('⚡');
});

it('still applies the channel level to always_notify rules', function () {
    $s = ngAlertSetup(['min_level' => Level::Critical]);
    AlertRule::factory()->for($s['project'])->create(['field' => 'type', 'operator' => 'equals', 'value' => 'exception', 'action' => 'always_notify']);

    expect(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::NO_CHANNELS);
});

// --- cooldown ----------------------------------------------------------------------------------------------

it('does not alert again about the same issue during the cooldown (60 minutes by default)', function () {
    $s = ngAlertSetup();

    expect(ngEvaluate($s, 'new'))->toBe(AlertEvaluator::SENT)
        ->and(ngEvaluate($s, 'regressed'))->toBe(AlertEvaluator::COOLDOWN);

    $this->travel(59)->minutes();
    expect(ngEvaluate($s, 'regressed'))->toBe(AlertEvaluator::COOLDOWN);

    $this->travel(2)->minutes();
    expect(ngEvaluate($s, 'regressed'))->toBe(AlertEvaluator::SENT);
});

it('uses the cooldown of the project settings', function () {
    $s = ngAlertSetup(project: ['settings' => ['cooldown_minutes' => 10]]);

    ngEvaluate($s);
    $this->travel(11)->minutes();

    expect(ngEvaluate($s, 'regressed'))->toBe(AlertEvaluator::SENT);
});

it('uses a short 5 minute cooldown for always_notify rules to prevent floods', function () {
    $s = ngAlertSetup();
    AlertRule::factory()->for($s['project'])->create(['field' => 'type', 'operator' => 'equals', 'value' => 'exception', 'action' => 'always_notify']);

    expect(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::SENT)
        ->and(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::COOLDOWN);

    $this->travel(6)->minutes();
    expect(ngEvaluate($s, 'rule'))->toBe(AlertEvaluator::SENT);
});

it('does not use up the cooldown when no channel was eligible', function () {
    $s = ngAlertSetup(['min_level' => Level::Critical]);

    expect(ngEvaluate($s))->toBe(AlertEvaluator::NO_CHANNELS)
        ->and($s['issue']->fresh()->last_alerted_at)->toBeNull();
});

// --- hourly cap ----------------------------------------------------------------------------------------------

it('suppresses alerts over the hourly cap and tells how many on the next one', function () {
    Cache::flush();
    $s = ngAlertSetup(project: ['settings' => ['max_alerts_per_hour' => 2, 'cooldown_minutes' => 1]]);
    $second = Issue::factory()->for($s['project'])->create(['title' => 'Second']);
    $third = Issue::factory()->for($s['project'])->create(['title' => 'Third']);
    $fourth = Issue::factory()->for($s['project'])->create(['title' => 'Fourth']);

    $this->travelTo(now()->startOfHour()->addMinutes(5));

    $results = [
        app(AlertEvaluator::class)->evaluate($s['issue']->fresh()->load('project'), 'new', $s['event']),
        app(AlertEvaluator::class)->evaluate($second->load('project'), 'new', null),
        app(AlertEvaluator::class)->evaluate($third->load('project'), 'new', null),
        app(AlertEvaluator::class)->evaluate($fourth->load('project'), 'new', null),
    ];

    expect($results)->toBe(['sent', 'sent', 'capped', 'capped'])
        ->and(AlertLog::where('status', 'suppressed')->count())->toBe(2)
        ->and(AlertLog::where('status', 'suppressed')->first()->alert_channel_id)->toBe($s['channel']->id);

    // The next hour has a new budget; its first alert reports what was held back.
    $this->travelTo(now()->startOfHour()->addHour()->addMinutes(1));
    $fifth = Issue::factory()->for($s['project'])->create(['title' => 'Fifth']);
    Queue::fake();

    expect(app(AlertEvaluator::class)->evaluate($fifth->load('project'), 'new', null))->toBe('sent');
    $lines = ngSent()[0]->message['lines'];
    expect(implode(' ', $lines))->toContain('2 alerts suppressed in the last hour');

    // …and only once.
    $sixth = Issue::factory()->for($s['project'])->create(['title' => 'Sixth']);
    Queue::fake();
    app(AlertEvaluator::class)->evaluate($sixth->load('project'), 'new', null);
    expect(implode(' ', ngSent()[0]->message['lines']))->not->toContain('suppressed');
});

it('counts the hourly cap per project', function () {
    Cache::flush();
    $a = ngAlertSetup(project: ['settings' => ['max_alerts_per_hour' => 1]]);
    $b = ngAlertSetup(project: ['settings' => ['max_alerts_per_hour' => 1]]);

    expect(ngEvaluate($a))->toBe('sent')->and(ngEvaluate($b))->toBe('sent');
});

// --- threshold -----------------------------------------------------------------------------------------------

it('alerts once per window when an issue gets many events, and only on channels that asked for it', function () {
    Cache::flush();
    $s = ngAlertSetup(['notify_threshold' => true], event: ['occurred_at' => now()->subMinutes(3)], project: ['settings' => ['threshold_count' => 5, 'threshold_minutes' => 30]]);
    $quiet = AlertChannel::factory()->for($s['project'])->create(['type' => 'mail', 'config' => ['recipients' => ['a@example.com']], 'notify_threshold' => false]);
    Event::factory()->for($s['project'])->for($s['issue'])->count(5)->create(['occurred_at' => now()->subMinutes(10)]);
    $other = Issue::factory()->for($s['project'])->create();
    Event::factory()->for($s['project'])->for($other)->count(4)->create(['occurred_at' => now()->subMinutes(10)]);
    Event::factory()->for($s['project'])->for($other)->count(9)->create(['occurred_at' => now()->subMinutes(90)]);

    expect(app(ThresholdChecker::class)->run())->toBe(1);
    Queue::assertPushed(EvaluateAlerts::class, fn (EvaluateAlerts $job) => $job->issueId === $s['issue']->id && $job->trigger === 'threshold' && $job->windowCount === 6);

    // Same window: nothing new.
    expect(app(ThresholdChecker::class)->run())->toBe(0);

    // The evaluator then sends through the right channel with the numbers.
    Queue::fake();
    expect(ngEvaluate($s, 'threshold', 6))->toBe('sent');
    $jobs = ngSent();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->channelId)->toBe($s['channel']->id)
        ->and($jobs[0]->channelId)->not->toBe($quiet->id)
        ->and($jobs[0]->message['icon'])->toBe('📈')
        ->and(implode(' ', $jobs[0]->message['lines']))->toContain('6 events in the last 30 minutes');

    // A new window starts later.
    $this->travel(31)->minutes();
    Event::factory()->for($s['project'])->for($s['issue'])->count(5)->create(['occurred_at' => now()->subMinutes(5)]);
    Queue::fake();
    expect(app(ThresholdChecker::class)->run())->toBe(1);
});

it('does not look for thresholds in projects without a threshold channel', function () {
    Cache::flush();
    $s = ngAlertSetup(['notify_threshold' => false], project: ['settings' => ['threshold_count' => 1]]);
    Event::factory()->for($s['project'])->for($s['issue'])->count(3)->create(['occurred_at' => now()]);

    expect(app(ThresholdChecker::class)->run())->toBe(0);
});

// --- the connection-OK notice ---------------------------------------------------------------------------------

it('sends one "connection OK" notice for the first test event of an issue, and nothing else', function () {
    $s = ngAlertSetup(['min_level' => Level::Critical, 'notify_new' => false]);

    expect(ngEvaluate($s, 'test'))->toBe('sent');
    $job = ngSent()[0];
    expect($job->kind)->toBe('test')->and($job->message['headline'])->toBe('Connection OK · Acme Billing');

    AlertLog::create(['alert_channel_id' => $s['channel']->id, 'issue_id' => $s['issue']->id, 'kind' => 'test', 'status' => 'sent']);
    Queue::fake();
    expect(ngEvaluate($s, 'test'))->toBe(AlertEvaluator::NO_CHANNELS);
    Queue::assertNothingPushed();
});
