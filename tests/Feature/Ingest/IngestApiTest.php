<?php

use App\Enums\IssueStatus;
use App\Jobs\EvaluateAlerts;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\IssueFingerprint;
use App\Models\ProjectToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function batch(array ...$events): array
{
    return ['sent_at' => '2026-10-17T13:45:12.345Z', 'sdk' => ['name' => 'nightguardian-laravel', 'version' => '2.0.0'], 'events' => $events];
}

function example(string $name): array
{
    return json_decode((string) file_get_contents(base_path("docs/protocol/examples/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(fn () => Queue::fake());

// -- Authentication ---------------------------------------------------------------------------------------------

it('rejects requests without a token with 401', function () {
    $this->postJson('/api/v1/ingest', batch(ngEvent()))->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
});

it('rejects unknown tokens with 401', function () {
    ngPost('ng_'.str_repeat('x', 40), batch(ngEvent()))->assertUnauthorized();
});

it('rejects revoked tokens with 401', function () {
    [$project, $plain] = ngProject();
    ProjectToken::query()->update(['revoked_at' => now()]);

    ngPost($plain, batch(ngEvent()))->assertUnauthorized();
});

it('rejects archived projects with 403', function () {
    [, $plain] = ngProject(['archived_at' => now()]);

    ngPost($plain, batch(ngEvent()))->assertForbidden()->assertJsonPath('error', 'project_archived');
    expect(Event::count())->toBe(0);
});

it('rate limits repeated invalid tokens per IP', function () {
    for ($i = 0; $i < 20; $i++) {
        ngPost('ng_bad', batch(ngEvent()))->assertUnauthorized();
    }

    ngPost('ng_bad', batch(ngEvent()))->assertStatus(429)->assertHeader('Retry-After');
});

it('updates last_used_at at most once per minute', function () {
    [$project, $plain] = ngProject();

    ngPost($plain, batch(ngEvent()))->assertStatus(202);
    $first = ProjectToken::firstOrFail()->last_used_at;
    expect($first)->not->toBeNull();

    $this->travel(30)->seconds();
    ngPost($plain, batch(ngEvent()))->assertStatus(202);
    expect(ProjectToken::firstOrFail()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(40)->seconds();
    ngPost($plain, batch(ngEvent()))->assertStatus(202);
    expect(ProjectToken::firstOrFail()->last_used_at->greaterThan($first))->toBeTrue();
});

it('is stateless: no cookies or session are set', function () {
    [, $plain] = ngProject();

    $response = ngPost($plain, batch(ngEvent()))->assertStatus(202);

    expect($response->headers->getCookies())->toBe([]);
});

// -- Body handling and limits -----------------------------------------------------------------------------------

it('returns 400 for a body that is not JSON', function () {
    [, $plain] = ngProject();

    ngPost($plain, 'not json')->assertStatus(400)->assertJsonPath('error', 'malformed');
});

it('returns 400 when events is missing or empty', function () {
    [, $plain] = ngProject();

    ngPost($plain, ['sdk' => []])->assertStatus(400);
    ngPost($plain, ['events' => []])->assertStatus(400);
});

it('returns 400 for invalid gzip data and unknown encodings', function () {
    [, $plain] = ngProject();

    ngPost($plain, 'definitely not gzip', ['Content-Encoding' => 'gzip'])->assertStatus(400);
    ngPost($plain, '{}', ['Content-Encoding' => 'br'])->assertStatus(400);
});

it('returns 400 for JSON nested deeper than the limit', function () {
    [, $plain] = ngProject();
    $deep = str_repeat('[', 40).str_repeat(']', 40);

    ngPost($plain, '{"events":[{"id":'.$deep.'}]}')->assertStatus(400);
});

it('accepts gzip-compressed bodies', function () {
    [, $plain] = ngProject();

    ngPost($plain, batch(ngEvent()), gzip: true)->assertStatus(202)->assertJsonPath('accepted', 1);
    expect(Event::count())->toBe(1);
});

it('returns 413 when the compressed body exceeds the limit', function () {
    [, $plain] = ngProject();
    config(['nightguardian.ingest.max_compressed_bytes' => 200]);

    $body = batch(ngEvent(['context' => ['noise' => bin2hex(random_bytes(500))]]));

    ngPost($plain, $body, gzip: true)->assertStatus(413);
});

it('returns 413 for a gzip bomb without inflating it fully', function () {
    [, $plain] = ngProject();
    config(['nightguardian.ingest.max_uncompressed_bytes' => 1024 * 1024]);

    ngPost($plain, str_repeat('a', 5 * 1024 * 1024), gzip: true)->assertStatus(413);
});

it('returns 413 when the identity body exceeds the uncompressed limit', function () {
    [, $plain] = ngProject();
    config(['nightguardian.ingest.max_uncompressed_bytes' => 1000]);

    ngPost($plain, str_repeat('a', 2000))->assertStatus(413);
});

it('returns 413 for more than 100 events', function () {
    [, $plain] = ngProject();
    $events = array_map(fn () => ngEvent(), range(1, 101));

    ngPost($plain, batch(...$events))->assertStatus(413);
    expect(Event::count())->toBe(0);
});

it('rejects an oversized event without affecting the rest of the batch', function () {
    [, $plain] = ngProject();
    config(['nightguardian.ingest.max_event_bytes' => 2000]);
    $big = ngEvent(['context' => ['noise' => str_repeat('x', 3000)]]);

    ngPost($plain, batch($big, ngEvent()))
        ->assertStatus(202)
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('rejected', 1)
        ->assertJsonPath('results.0.status', 'rejected');
});

it('returns 429 with Retry-After when the project rate limit is exceeded', function () {
    [, $plain] = ngProject(['rate_limit_per_minute' => 3]);

    ngPost($plain, batch(ngEvent(), ngEvent()))->assertStatus(202);
    $response = ngPost($plain, batch(ngEvent(), ngEvent()))->assertStatus(429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect(Event::count())->toBe(2);
});

// -- Validation -------------------------------------------------------------------------------------------------

it('rejects invalid events individually and accepts the rest', function () {
    [, $plain] = ngProject();
    $invalid = ngEvent(['payload' => []]);
    unset($invalid['environment']);

    $response = ngPost($plain, batch(ngEvent(), $invalid, ngEvent(['type' => 'martian'])))->assertStatus(202);

    $response->assertJsonPath('accepted', 1)->assertJsonPath('rejected', 2)
        ->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'rejected')
        ->assertJsonPath('results.2.status', 'rejected');

    expect($response->json('results.1.errors'))->toContain('environment: required string (max 64)', 'payload.exceptions: required')
        ->and($response->json('results.2.errors'))->toContain('type: unknown_type');
    expect(Event::count())->toBe(1);
});

it('validates the envelope fields', function (string $field, mixed $value, string $expected) {
    [, $plain] = ngProject();

    $response = ngPost($plain, batch(ngEvent([$field => $value])))->assertStatus(202);

    expect($response->json('results.0.errors'))->toContain($expected);
})->with([
    'bad id' => ['id', 'not-a-uuid', 'id: required UUID'],
    'bad timestamp' => ['timestamp', 'yesterday', 'timestamp: required RFC 3339 date-time'],
    'bad level' => ['level', 'loud', 'level: must be a PSR-3 level'],
    'bad reference' => ['reference', 'NG-1234-ILOU', 'reference: must match NG-XXXX-XXXX'],
    'long environment' => ['environment', str_repeat('e', 65), 'environment: required string (max 64)'],
    'tags not object' => ['tags', ['a', 'b'], 'tags: must be an object'],
    'non-string tag' => ['tags', ['a' => 1], 'tags: keys max 64 characters, values must be strings of max 200 characters'],
    'fingerprint not strings' => ['fingerprint', [1, 2], 'fingerprint: must be an array of at most 10 strings'],
    'context not object' => ['context', 'text', 'context: must be an object'],
]);

it('requires a level except for deploy and feedback events', function () {
    [, $plain] = ngProject();
    $log = ngEvent(['type' => 'log', 'payload' => ['message' => 'hi']]);
    unset($log['level']);
    $deploy = ngEvent(['type' => 'deploy', 'payload' => ['release' => '1.2.3']]);
    unset($deploy['level']);

    ngPost($plain, batch($log, $deploy))
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.1.status', 'accepted');
});

it('validates type specific payloads', function (string $type, array $payload, string $expected) {
    [, $plain] = ngProject();

    $response = ngPost($plain, batch(ngEvent(['type' => $type, 'payload' => $payload])));

    expect($response->json('results.0.errors'))->toContain($expected);
})->with([
    'exception without exceptions' => ['exception', [], 'payload.exceptions: required'],
    'exception without class' => ['exception', ['exceptions' => [['message' => 'x']]], 'payload.exceptions.0.class: required string (max 255)'],
    'log without message' => ['log', [], 'payload.message: required'],
    'job without job' => ['job_failed', ['exceptions' => []], 'payload.job: required'],
    'schedule without command' => ['schedule_failed', [], 'payload.command: required'],
    'slow request without duration' => ['slow_request', [], 'payload.duration_ms: required number'],
    'slow query without sql' => ['slow_query', ['duration_ms' => 5], 'payload.sql: required'],
    'deploy without release' => ['deploy', [], 'payload.release: required string (max 128)'],
    'feedback without comment' => ['feedback', [], 'payload.comment: required'],
    'feedback too long' => ['feedback', ['comment' => str_repeat('a', 2001)], 'payload.comment: at most 2000 characters'],
]);

// -- Protocol examples and event types --------------------------------------------------------------------------

it('accepts the exception example from the protocol docs', function () {
    [$project, $plain] = ngProject();

    $response = ngPost($plain, example('exception'), gzip: true)->assertStatus(202)
        ->assertJsonPath('accepted', 1)->assertJsonPath('rejected', 0);

    $issue = Issue::firstOrFail();
    expect($response->json('results.0.issue'))->toBe($issue->id)
        ->and($issue->project_id)->toBe($project->id)
        ->and($issue->status)->toBe(IssueStatus::New)
        ->and($issue->title)->toStartWith('QueryException: SQLSTATE[23000]')
        ->and($issue->culprit)->toBe('App\\Http\\Controllers\\InvoiceController@update')
        ->and($issue->exception_class)->toBe('Illuminate\\Database\\QueryException')
        ->and($issue->events_count)->toBe(1)
        ->and($issue->users_count)->toBe(1)
        ->and($issue->environments)->toBe(['production'])
        ->and($issue->first_release)->toBe('a1b2c3d');

    $event = Event::firstOrFail();
    expect($event->reference)->toBe('NG-7F3K-92QD')
        ->and($event->request_route)->toBe('invoices.update')
        ->and($event->status_code)->toBe(500)
        ->and($event->user_identifier)->toBe('42')
        ->and($event->sdk_name)->toBe('nightguardian-laravel')
        ->and($event->occurred_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-17 13:45:11.902')
        ->and($event->payload['payload']['exceptions'][0]['class'])->toBe('Illuminate\\Database\\QueryException');
    Queue::assertPushed(EvaluateAlerts::class, fn ($job) => $job->trigger === 'new' && $job->issueId === $issue->id);
});

it('stores feedback from the protocol example and attaches it to the original event', function () {
    [, $plain] = ngProject();

    ngPost($plain, example('exception'))->assertStatus(202);
    ngPost($plain, example('feedback'))->assertStatus(202)->assertJsonPath('accepted', 1);

    $feedback = Feedback::firstOrFail();
    expect($feedback->issue_id)->toBe(Issue::firstOrFail()->id)
        ->and($feedback->reference)->toBe('NG-7F3K-92QD')
        ->and($feedback->comment)->toContain('factura B');
});

it('keeps feedback that arrives before its event and attaches it later', function () {
    [, $plain] = ngProject();

    ngPost($plain, example('feedback'))->assertStatus(202);
    expect(Feedback::firstOrFail()->issue_id)->toBeNull();

    ngPost($plain, example('exception'))->assertStatus(202);
    expect(Feedback::firstOrFail()->issue_id)->toBe(Issue::firstOrFail()->id);
});

it('stores every event type', function (string $type, array $payload, array $extra) {
    [$project, $plain] = ngProject();

    $response = ngPost($plain, batch(ngEvent(['type' => $type, 'payload' => $payload] + $extra)))->assertStatus(202);

    $response->assertJsonPath('accepted', 1)->assertJsonPath('rejected', 0);
    expect(Event::where('type', $type)->count())->toBe(1);
})->with([
    'exception' => ['exception', ['exceptions' => [['class' => 'RuntimeException', 'message' => 'x']]], []],
    'log' => ['log', ['message' => 'Payment gateway timeout for order {order}', 'channel' => 'stack', 'context' => ['order' => 42], 'exception' => null], []],
    'job_failed' => ['job_failed', ['job' => 'App\\Jobs\\SendInvoice', 'queue' => 'default', 'attempts' => 3, 'exceptions' => [['class' => 'RuntimeException']]], []],
    'schedule_failed' => ['schedule_failed', ['command' => 'php artisan invoices:sync', 'exit_code' => 1, 'exceptions' => []], []],
    'slow_request' => ['slow_request', ['duration_ms' => 4810, 'threshold_ms' => 2000], ['level' => 'warning']],
    'slow_query' => ['slow_query', ['sql' => 'select * from invoices where id = ?', 'duration_ms' => 2350, 'connection' => 'mysql'], ['level' => 'warning']],
    'test' => ['test', ['exceptions' => [['class' => 'RuntimeException', 'message' => 'NightGuardian test']]], []],
]);

it('stores deploys without creating an issue', function () {
    [$project, $plain] = ngProject();
    $deploy = ngEvent(['type' => 'deploy', 'payload' => ['release' => 'a1b2c3d', 'commit' => 'a1b2c3d4e5', 'branch' => 'main', 'deployer' => 'pull.sh']]);
    unset($deploy['level']);

    ngPost($plain, batch($deploy))->assertStatus(202)->assertJsonPath('accepted', 1)->assertJsonMissingPath('results.0.issue');

    expect(Issue::count())->toBe(0)
        ->and(Deploy::firstOrFail()->only(['release', 'commit', 'branch', 'deployer', 'environment']))
        ->toBe(['release' => 'a1b2c3d', 'commit' => 'a1b2c3d4e5', 'branch' => 'main', 'deployer' => 'pull.sh', 'environment' => 'production'])
        ->and(Event::firstOrFail()->issue_id)->toBeNull();
    Queue::assertNothingPushed();
});

it('never alerts for test events except the stub trigger', function () {
    [, $plain] = ngProject();

    ngPost($plain, batch(ngEvent(['type' => 'test'])))->assertStatus(202);

    Queue::assertPushed(EvaluateAlerts::class, fn ($job) => $job->trigger === 'test');
    Queue::assertNotPushed(EvaluateAlerts::class, fn ($job) => $job->trigger === 'new');
});

// -- Idempotency and grouping -----------------------------------------------------------------------------------

it('is idempotent: re-sending an event reports duplicate and changes nothing', function () {
    [, $plain] = ngProject();
    $event = ngEvent();

    ngPost($plain, batch($event))->assertJsonPath('results.0.status', 'accepted');
    $second = ngPost($plain, batch($event))->assertStatus(202);

    $second->assertJsonPath('results.0.status', 'duplicate')->assertJsonPath('accepted', 1);
    expect(Event::count())->toBe(1)->and(Issue::firstOrFail()->events_count)->toBe(1);
    Queue::assertPushed(EvaluateAlerts::class, 1);
});

it('groups repeated occurrences into one issue and updates its counters', function () {
    [, $plain] = ngProject();

    ngPost($plain, batch(ngEvent(['timestamp' => '2026-10-17T10:00:00.000Z', 'release' => '1.0.0', 'user' => ['id' => 'a']])));
    ngPost($plain, batch(ngEvent([
        'timestamp' => '2026-10-17T12:00:00.000Z', 'release' => '1.1.0', 'environment' => 'staging', 'level' => 'critical', 'user' => ['id' => 'b'],
    ])));
    ngPost($plain, batch(ngEvent(['timestamp' => '2026-10-17T11:00:00.000Z', 'release' => '1.0.5', 'user' => ['id' => 'a']])));

    $issue = Issue::sole();
    expect($issue->events_count)->toBe(3)
        ->and($issue->users_count)->toBe(2)
        ->and($issue->environments)->toBe(['production', 'staging'])
        ->and($issue->level->value)->toBe('critical')
        ->and($issue->first_seen_at->toIso8601String())->toBe('2026-10-17T10:00:00+00:00')
        ->and($issue->last_seen_at->toIso8601String())->toBe('2026-10-17T12:00:00+00:00')
        ->and($issue->first_release)->toBe('1.0.0')
        ->and($issue->last_release)->toBe('1.1.0')
        ->and(IssueFingerprint::count())->toBe(1);
    Queue::assertPushed(EvaluateAlerts::class, 1);
});

it('keeps issues of different projects apart', function () {
    [, $plainA] = ngProject();
    [, $plainB] = ngProject();

    ngPost($plainA, batch(ngEvent()));
    ngPost($plainB, batch(ngEvent()));

    expect(Issue::count())->toBe(2);
});

it('groups with an explicit fingerprint', function () {
    [, $plain] = ngProject();
    $other = ngEvent(['payload' => ['exceptions' => [['class' => 'LogicException', 'message' => 'different']]]]);

    ngPost($plain, batch(ngEvent(['fingerprint' => ['custom']]), $other + ['fingerprint' => ['custom']]));

    expect(Issue::count())->toBe(1)->and(Issue::sole()->events_count)->toBe(2);
});

// -- Status transitions -----------------------------------------------------------------------------------------

it('turns a resolved issue into regressed and alerts', function () {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Resolved, 'resolved_at' => now(), 'resolved_in_release' => '1.0.0']);
    Queue::fake();

    ngPost($plain, batch(ngEvent(['release' => '1.0.0'])));

    $issue = Issue::sole();
    expect($issue->status)->toBe(IssueStatus::Regressed)->and($issue->resolved_at)->toBeNull();
    Queue::assertPushed(EvaluateAlerts::class, fn ($job) => $job->trigger === 'regressed');
});

it('keeps a resolved issue resolved when the event comes from an older release', function () {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Resolved, 'resolved_at' => now(), 'resolved_in_release' => '1.2.0']);
    Queue::fake();

    ngPost($plain, batch(ngEvent(['release' => '1.1.0'])));

    expect(Issue::sole()->status)->toBe(IssueStatus::Resolved)->and(Issue::sole()->events_count)->toBe(2);
    Queue::assertNothingPushed();
});

it('regresses when the release is newer or not comparable', function (string $resolvedIn, string $eventRelease) {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Resolved, 'resolved_in_release' => $resolvedIn]);

    ngPost($plain, batch(ngEvent(['release' => $eventRelease])));

    expect(Issue::sole()->status)->toBe(IssueStatus::Regressed);
})->with([
    'newer version' => ['1.2.0', '1.3.0'],
    'commit shas' => ['a1b2c3d', 'b2c3d4e'],
]);

it('keeps an actively ignored issue ignored, counting events without alerting', function () {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Ignored, 'ignored_until' => now()->addHour()]);
    Queue::fake();

    ngPost($plain, batch(ngEvent()));

    expect(Issue::sole()->status)->toBe(IssueStatus::Ignored)->and(Issue::sole()->events_count)->toBe(2);
    Queue::assertNothingPushed();
});

it('keeps an issue ignored forever ignored', function () {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Ignored, 'ignored_until' => null]);

    ngPost($plain, batch(ngEvent()));

    expect(Issue::sole()->status)->toBe(IssueStatus::Ignored);
});

it('regresses an issue whose ignore period expired', function () {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => IssueStatus::Ignored, 'ignored_until' => now()->subMinute()]);
    Queue::fake();

    ngPost($plain, batch(ngEvent()));

    expect(Issue::sole()->status)->toBe(IssueStatus::Regressed)->and(Issue::sole()->ignored_until)->toBeNull();
    Queue::assertPushed(EvaluateAlerts::class, fn ($job) => $job->trigger === 'regressed');
});

it('leaves new and regressed issues in their status', function (IssueStatus $status) {
    [, $plain] = ngProject();
    ngPost($plain, batch(ngEvent()));
    Issue::query()->update(['status' => $status]);
    Queue::fake();

    ngPost($plain, batch(ngEvent()));

    expect(Issue::sole()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with([IssueStatus::New, IssueStatus::Regressed]);

// -- Scrubbing --------------------------------------------------------------------------------------------------

it('scrubs sensitive data again on the collector before storing', function () {
    [, $plain] = ngProject();
    $event = ngEvent([
        'context' => ['password' => 'hunter2', 'note' => 'card 4111 1111 1111 1111 declined', 'nested' => ['api_token' => 'abc']],
        'request' => ['method' => 'POST', 'url' => 'https://a.test/x', 'headers' => ['authorization' => 'Bearer abc123', 'cookie' => 'a=b', 'accept' => 'text/html']],
        'payload' => ['exceptions' => [['class' => 'RuntimeException', 'message' => 'Request failed with Bearer abc.def.ghi']]],
    ]);

    ngPost($plain, batch($event))->assertStatus(202);

    $stored = Event::sole();
    $json = json_encode($stored->payload);
    expect($json)->not->toContain('hunter2')->not->toContain('4111')->not->toContain('abc123')->not->toContain('abc.def.ghi')
        ->and($stored->payload['context']['password'])->toBe('[Filtered]')
        ->and($stored->payload['request']['headers']['accept'])->toBe('text/html')
        ->and($stored->message)->not->toContain('abc.def.ghi')
        ->and(Issue::sole()->title)->not->toContain('abc.def.ghi');
});

// -- Dry run ----------------------------------------------------------------------------------------------------

it('validates without storing anything in dry-run mode', function () {
    [, $plain] = ngProject();
    $invalid = ngEvent(['payload' => []]);

    $response = ngPost($plain, batch(ngEvent(), $invalid), ['X-NightGuardian-Dry-Run' => '1'])->assertStatus(202);

    $response->assertJsonPath('dry_run', true)->assertJsonPath('accepted', 1)->assertJsonPath('rejected', 1)
        ->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'rejected');
    expect($response->json('results.0.fingerprint'))->toMatch('/^[0-9a-f]{40}$/');
    expect(Event::count())->toBe(0)->and(Issue::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('still requires authentication in dry-run mode', function () {
    ngPost('ng_nope', batch(ngEvent()), ['X-NightGuardian-Dry-Run' => '1'])->assertUnauthorized();
});
