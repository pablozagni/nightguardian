<?php

use App\Alerts\DigestBuilder;
use App\Alerts\DigestScheduler;
use App\Jobs\SendAlert;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Project;
use App\Models\User;
use App\Retention\Pruner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function ngDigestProject(array $attributes = []): Project
{
    return Project::factory()->create($attributes + ['name' => 'Acme Billing', 'timezone' => 'America/Argentina/Buenos_Aires', 'digest_time' => '08:00:00']);
}

// --- content ---------------------------------------------------------------------------------------------

it('summarizes the last 24 hours: events against the previous day, new, regressed, resolved, top 5 and deploys', function () {
    $this->travelTo(now()->setTime(12, 0));
    $project = ngDigestProject();
    $user = User::factory()->create();

    $new = Issue::factory()->for($project)->create(['title' => 'Brand new problem', 'first_seen_at' => now()->subHours(3)]);
    $old = Issue::factory()->for($project)->create(['title' => 'Old problem', 'first_seen_at' => now()->subDays(10)]);
    Event::factory()->for($project)->for($new)->count(4)->create(['occurred_at' => now()->subHours(2)]);
    Event::factory()->for($project)->for($old)->count(2)->create(['occurred_at' => now()->subHours(5)]);
    Event::factory()->for($project)->for($old)->count(3)->create(['occurred_at' => now()->subHours(30)]);
    Event::factory()->for($project)->count(7)->create(['issue_id' => null, 'occurred_at' => now()->subHour()]);
    IssueActivity::create(['issue_id' => $old->id, 'action' => IssueActivity::REGRESSED, 'created_at' => now()->subHours(4)]);
    IssueActivity::create(['issue_id' => $old->id, 'user_id' => $user->id, 'action' => IssueActivity::RESOLVED, 'created_at' => now()->subHours(6)]);
    IssueActivity::create(['issue_id' => $old->id, 'user_id' => $user->id, 'action' => IssueActivity::RESOLVED, 'created_at' => now()->subDays(3)]);
    Deploy::factory()->for($project)->create(['release' => '1.4.2', 'environment' => 'production', 'deployed_at' => now()->subHours(2)]);

    $message = app(DigestBuilder::class)->build($project);
    $text = $message->toPlain();

    expect($message->kind)->toBe('digest')
        ->and($message->icon)->toBe('📋')
        ->and($message->headline)->toBe('DAILY DIGEST · Acme Billing')
        ->and($text)->toContain('Events: 6 (previous day: 3)')
        ->and($text)->toContain('New issues: 1: Brand new problem')
        ->and($text)->toContain('Regressions: 1')
        ->and($text)->toContain('Resolved: 1')
        ->and($text)->toContain('• Brand new problem — 4')
        ->and($text)->toContain('• Old problem — 2')
        ->and($text)->toContain('• 1.4.2 (production)')
        ->and($text)->not->toContain('Events: 13');
});

it('lists at most 5 issues in the top', function () {
    $project = ngDigestProject();

    foreach (range(1, 7) as $i) {
        $issue = Issue::factory()->for($project)->create(['title' => "Issue {$i}"]);
        Event::factory()->for($project)->for($issue)->count($i)->create(['occurred_at' => now()->subHour()]);
    }

    $text = app(DigestBuilder::class)->build($project)->toPlain();

    expect(substr_count($text, '• Issue'))->toBe(5)->and($text)->toContain('• Issue 7 — 7')->not->toContain('• Issue 1 —');
});

it('is written in the language of the project', function () {
    $project = ngDigestProject(['settings' => ['language' => 'es']]);
    $issue = Issue::factory()->for($project)->create();
    Event::factory()->for($project)->for($issue)->create(['occurred_at' => now()->subHour()]);

    $text = app(DigestBuilder::class)->build($project)->toPlain();

    expect($text)->toContain('RESUMEN DIARIO · Acme Billing')->toContain('Eventos: 1 (día anterior: 0)')->toContain('Issues con más eventos:');
});

it('is not sent when there was no activity, unless the project asks for it', function () {
    $project = ngDigestProject();

    expect(app(DigestBuilder::class)->build($project))->toBeNull();

    $project->update(['settings' => ['digest_skip_empty' => false]]);
    expect(app(DigestBuilder::class)->build($project->fresh())->toPlain())->toContain('Events: 0 (previous day: 0)');
});

// --- scheduling ----------------------------------------------------------------------------------------------

function ngDigestSetup(): array
{
    Queue::fake();
    test()->travelTo(Carbon::parse('2026-10-18 10:00:00', 'UTC'));
    $project = ngDigestProject();
    $issue = Issue::factory()->for($project)->create();
    Event::factory()->for($project)->for($issue)->create(['occurred_at' => now()->subHour()]);
    $with = AlertChannel::factory()->for($project)->create(['include_digest' => true]);
    $without = AlertChannel::factory()->for($project)->create(['include_digest' => false]);
    $disabled = AlertChannel::factory()->for($project)->create(['include_digest' => true, 'enabled' => false]);

    return compact('project', 'with', 'without', 'disabled');
}

it('sends the digest once a day, at the digest time in the project time zone', function () {
    $s = ngDigestSetup();
    $scheduler = app(DigestScheduler::class);

    // 08:00 in Buenos Aires (UTC-3) is 11:00 UTC.
    expect($scheduler->dispatchDue(Carbon::parse('2026-10-18 10:59:00', 'UTC')))->toBe(0);
    Queue::assertNothingPushed();

    expect($scheduler->dispatchDue(Carbon::parse('2026-10-18 11:00:00', 'UTC')))->toBe(1);
    Queue::assertPushed(SendAlert::class, 1);

    // Every minute afterwards, the same day: nothing more.
    expect($scheduler->dispatchDue(Carbon::parse('2026-10-18 11:01:00', 'UTC')))->toBe(0)
        ->and($scheduler->dispatchDue(Carbon::parse('2026-10-18 20:00:00', 'UTC')))->toBe(0);
    Queue::assertPushed(SendAlert::class, 1);

    // Next day, due again (with new activity).
    Event::factory()->for($s['project'])->for(Issue::first())->create(['occurred_at' => Carbon::parse('2026-10-19 10:00:00', 'UTC')]);
    expect($scheduler->dispatchDue(Carbon::parse('2026-10-19 11:05:00', 'UTC')))->toBe(1);
    Queue::assertPushed(SendAlert::class, 2);
});

it('sends the digest only through enabled channels that include it', function () {
    $s = ngDigestSetup();

    app(DigestScheduler::class)->dispatchDue(Carbon::parse('2026-10-18 15:00:00', 'UTC'));

    $ids = [];
    Queue::assertPushed(SendAlert::class, function (SendAlert $job) use (&$ids) {
        $ids[] = $job->channelId;

        return $job->kind === 'digest' && $job->issueId === null;
    });

    expect($ids)->toBe([$s['with']->id]);
});

it('skips projects without a digest time or archived, and marks an empty day as handled', function () {
    Queue::fake();
    $none = ngDigestProject(['digest_time' => null]);
    $archived = ngDigestProject(['archived_at' => now()]);
    $quiet = ngDigestProject();
    AlertChannel::factory()->for($quiet)->create(['include_digest' => true]);

    $handled = app(DigestScheduler::class)->dispatchDue(Carbon::parse('2026-10-18 15:00:00', 'UTC'));

    expect($handled)->toBe(1)->and($quiet->fresh()->setting('last_digest_on'))->toBe('2026-10-18');
    Queue::assertNothingPushed();
});

it('runs from the artisan command', function () {
    ngDigestSetup();
    $this->travelTo(Carbon::parse('2026-10-18 15:00:00', 'UTC'));

    $this->artisan('nightguardian:digest')->expectsOutputToContain('Digests handled: 1.')->assertSuccessful();
});

// --- retention -----------------------------------------------------------------------------------------------

it('deletes events older than the retention of each project and keeps issues, counters and feedback', function () {
    $short = Project::factory()->create(['retention_days' => 7]);
    $long = Project::factory()->create(['retention_days' => 90]);
    $issueShort = Issue::factory()->for($short)->create(['events_count' => 5]);
    $issueLong = Issue::factory()->for($long)->create(['events_count' => 5]);

    $oldShort = Event::factory()->for($short)->for($issueShort)->count(3)->create(['occurred_at' => now()->subDays(8)]);
    $newShort = Event::factory()->for($short)->for($issueShort)->count(2)->create(['occurred_at' => now()->subDays(6)]);
    $oldLong = Event::factory()->for($long)->for($issueLong)->count(2)->create(['occurred_at' => now()->subDays(30)]);
    Feedback::factory()->for($short)->for($issueShort)->create(['event_uuid' => $oldShort->first()->uuid]);

    $result = app(Pruner::class)->run();

    expect($result['events'])->toBe(3)
        ->and(Event::whereIn('id', $oldShort->pluck('id'))->count())->toBe(0)
        ->and(Event::whereIn('id', $newShort->pluck('id'))->count())->toBe(2)
        ->and(Event::whereIn('id', $oldLong->pluck('id'))->count())->toBe(2)
        ->and($issueShort->fresh()->events_count)->toBe(5)
        ->and(Issue::count())->toBe(2)
        ->and(Feedback::count())->toBe(1);
});

it('deletes in chunks until nothing old is left', function () {
    $project = Project::factory()->create(['retention_days' => 1]);
    $issue = Issue::factory()->for($project)->create();
    Event::factory()->for($project)->for($issue)->count(11)->create(['occurred_at' => now()->subDays(5)]);
    Event::factory()->for($project)->for($issue)->count(2)->create(['occurred_at' => now()]);

    $result = app(Pruner::class)->run(chunk: 3);

    expect($result['events'])->toBe(11)->and(Event::count())->toBe(2);
});

it('deletes alert logs after 90 days', function () {
    $channel = AlertChannel::factory()->create();
    AlertLog::create(['alert_channel_id' => $channel->id, 'kind' => 'new', 'status' => 'sent', 'created_at' => now()->subDays(91)]);
    AlertLog::create(['alert_channel_id' => $channel->id, 'kind' => 'new', 'status' => 'sent', 'created_at' => now()->subDays(89)]);

    $result = app(Pruner::class)->run();

    expect($result['alert_logs'])->toBe(1)->and(AlertLog::count())->toBe(1);
});

it('runs from the artisan command and is scheduled', function () {
    $project = Project::factory()->create(['retention_days' => 1]);
    Event::factory()->for($project)->create(['occurred_at' => now()->subDays(3)]);

    $this->artisan('nightguardian:prune')->expectsOutputToContain('Deleted 1 events and 0 alert logs.')->assertSuccessful();

    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');
    expect($scheduled)->toContain('nightguardian:digest')->toContain('nightguardian:thresholds')->toContain('nightguardian:prune');
});

it('runs the thresholds command', function () {
    $this->artisan('nightguardian:thresholds')->expectsOutputToContain('Issues over the threshold: 0.')->assertSuccessful();
});
