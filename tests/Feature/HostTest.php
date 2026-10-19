<?php

use App\Alerts\AlertMessageFactory;
use App\Enums\Level;
use App\Filament\Resources\Issues\Pages\ListIssues;
use App\Filament\Resources\Issues\Pages\ViewIssue;
use App\Filament\Resources\Issues\RelationManagers\EventsRelationManager;
use App\Ingest\HostBackfill;
use App\Ingest\HostResolver;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use App\Support\IssueHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

/**
 * One event of an issue, as the ingest would have stored it.
 */
function ngHostEvent(Issue $issue, ?string $host, array $attributes = []): Event
{
    return Event::factory()->for($issue->project)->for($issue)->create(['host' => $host] + $attributes);
}

// --- where the host comes from ------------------------------------------------------------------------------

it('takes the host from the host tag, then from the request URL', function (array $event, ?string $host) {
    expect(HostResolver::resolve($event))->toBe($host);
})->with([
    'tag' => [['tags' => ['host' => 'yapai.head-office.com.ar'], 'request' => ['url' => 'https://other.test/x']], 'yapai.head-office.com.ar'],
    'tag is trimmed, not lowercased' => [['tags' => ['host' => '  Customer A ']], 'Customer A'],
    'request url' => [['request' => ['url' => 'https://Shop.Example.COM:8443/invoices?page=2']], 'shop.example.com'],
    'blank tag falls back to the url' => [['tags' => ['host' => ' '], 'request' => ['url' => 'https://app.test/']], 'app.test'],
    'non-string tag falls back to the url' => [['tags' => ['host' => ['x']], 'request' => ['url' => 'https://app.test/']], 'app.test'],
    'neither' => [['tags' => ['team' => 'x']], null],
    'unparseable url' => [['request' => ['url' => 'not a url']], null],
    'nothing at all' => [[], null],
]);

it('limits the host to 255 characters', function () {
    expect(mb_strlen((string) HostResolver::resolve(['tags' => ['host' => str_repeat('a', 400)]])))->toBe(255);
});

it('stores the host of every event the ingest accepts', function () {
    [$project, $plain] = ngProject();

    ngPost($plain, ['sent_at' => '2026-10-17T13:45:12.345Z', 'sdk' => ['name' => 'nightguardian-laravel', 'version' => '2.1.0'], 'events' => [
        ngEvent(['id' => fake()->uuid(), 'tags' => ['host' => 'yapai.head-office.com.ar']]),
        ngEvent(['id' => fake()->uuid()]),
        ngEvent(['id' => fake()->uuid(), 'request' => null, 'user' => null]),
    ]])->assertStatus(202);

    expect(Event::query()->orderBy('received_at')->orderBy('id')->pluck('host')->sort()->values()->all())
        ->toBe([null, 'app.test', 'yapai.head-office.com.ar']);
});

it('indexes the host column', function () {
    $indexes = collect(Schema::getIndexes('events'))->map(fn (array $index) => implode(',', $index['columns']));

    expect($indexes)->toContain('host')->toContain('issue_id,host');
});

// --- backfill ---------------------------------------------------------------------------------------------------

it('backfills the host of existing events in chunks, from their tags or request URL', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    $withTag = ngHostEvent($issue, null, ['payload' => ['tags' => ['host' => 'a.example.com'], 'request' => ['url' => 'https://ignored.test/']], 'request_url' => null]);
    $withUrl = ngHostEvent($issue, null, ['payload' => ['message' => 'x'], 'request_url' => 'https://B.example.com/path']);
    $withPayloadUrl = ngHostEvent($issue, null, ['payload' => ['request' => ['url' => 'https://c.example.com/']], 'request_url' => null]);
    $nothing = ngHostEvent($issue, null, ['payload' => ['message' => 'cli'], 'request_url' => null]);
    $alreadySet = ngHostEvent($issue, 'keep.example.com', ['payload' => ['tags' => ['host' => 'other.example.com']]]);

    expect(HostBackfill::run(chunk: 2))->toBe(3);

    expect($withTag->fresh()->host)->toBe('a.example.com')
        ->and($withUrl->fresh()->host)->toBe('b.example.com')
        ->and($withPayloadUrl->fresh()->host)->toBe('c.example.com')
        ->and($nothing->fresh()->host)->toBeNull()
        ->and($alreadySet->fresh()->host)->toBe('keep.example.com');

    expect(HostBackfill::run())->toBe(0);
});

it('survives events with an unreadable payload', function () {
    $issue = Issue::factory()->for(Project::factory()->create())->create();
    $event = ngHostEvent($issue, null, ['request_url' => 'https://d.example.com/']);
    DB::table('events')->where('id', $event->id)->update(['payload' => '"scalar"']);

    expect(HostBackfill::run())->toBe(1)->and($event->fresh()->host)->toBe('d.example.com');
});

// --- the issue detail ---------------------------------------------------------------------------------------

it('lists the affected installations of an issue with their events and last seen', function () {
    $team = ngTeam();
    $issue = Issue::factory()->for($team['project'])->create();

    foreach (['a.example.com', 'a.example.com', 'a.example.com', 'b.example.com'] as $i => $host) {
        ngHostEvent($issue, $host, ['occurred_at' => now()->subHours(10 - $i)]);
    }
    ngHostEvent($issue, null);
    ngHostEvent(Issue::factory()->for($team['project'])->create(), 'elsewhere.example.com');

    $rows = IssueHosts::breakdown($issue);
    expect($rows->pluck('host')->all())->toBe(['a.example.com', 'b.example.com'])
        ->and($rows->pluck('events')->map(fn ($n) => (int) $n)->all())->toBe([3, 1])
        ->and(IssueHosts::count($issue))->toBe(2);

    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => $issue->getKey()])
        ->assertSee(__('nightguardian.issues.hosts_title'))
        ->assertSee('a.example.com')
        ->assertSee('b.example.com')
        ->assertDontSee('elsewhere.example.com');
});

it('hides the installations block when no event reported a host', function () {
    $team = ngTeam();
    $issue = Issue::factory()->for($team['project'])->create();
    ngHostEvent($issue, null);

    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => $issue->getKey()])->assertDontSee(__('nightguardian.issues.hosts_title'));
});

it('shows the host in the events of an issue', function () {
    $team = ngTeam();
    $issue = Issue::factory()->for($team['project'])->create();
    ngHostEvent($issue, 'client-7.saas.test');

    $this->actingAs($team['member']);

    livewire(EventsRelationManager::class, ['ownerRecord' => $issue, 'pageClass' => ViewIssue::class])
        ->assertTableColumnExists('host')
        ->assertSee('client-7.saas.test');
});

// --- triage filter --------------------------------------------------------------------------------------------

it('filters the triage list by host and only offers the hosts of the projects the user can see', function () {
    $team = ngTeam();
    $onA = Issue::factory()->for($team['project'])->create(['title' => 'Boom on A']);
    $onB = Issue::factory()->for($team['project'])->create(['title' => 'Boom on B']);
    $onBoth = Issue::factory()->for($team['project'])->create(['title' => 'Boom on both']);
    ngHostEvent($onA, 'a.example.com');
    ngHostEvent($onB, 'b.example.com');
    ngHostEvent($onBoth, 'a.example.com');
    ngHostEvent($onBoth, 'b.example.com');
    ngHostEvent(Issue::factory()->for($team['other'])->create(), 'secret.other-project.test');

    $this->actingAs($team['member']);

    $list = livewire(ListIssues::class)
        ->removeTableFilter('status')
        ->filterTable('host', 'a.example.com');

    $list->assertCanSeeTableRecords([$onA, $onBoth])->assertCanNotSeeTableRecords([$onB]);

    $options = collect($list->instance()->getTable()->getFilter('host')->getOptions());
    expect($options->keys()->all())->toBe(['a.example.com', 'b.example.com']);
});

// --- alerts ------------------------------------------------------------------------------------------------------

it('puts the host of the event and the number of installations in the alert', function () {
    $project = Project::factory()->create(['name' => 'Acme']);
    $issue = Issue::factory()->for($project)->create(['events_count' => 3, 'users_count' => 1, 'first_seen_at' => now()->subMinutes(2), 'level' => Level::Error]);
    $event = ngHostEvent($issue, 'a.example.com');
    ngHostEvent($issue, 'b.example.com');
    ngHostEvent($issue, 'c.example.com');

    $lines = app(AlertMessageFactory::class)->forIssue('new', $project, $issue->load('project'), $event)->lines;

    expect($lines)->toContain('host a.example.com · in 3 installations');
});

it('writes the installations line in the language of the project', function () {
    $project = Project::factory()->create(['settings' => ['language' => 'es']]);
    $issue = Issue::factory()->for($project)->create();
    $event = ngHostEvent($issue, 'a.example.com');
    ngHostEvent($issue, 'b.example.com');

    $lines = app(AlertMessageFactory::class)->forIssue('new', $project, $issue->load('project'), $event)->lines;

    expect($lines)->toContain('host a.example.com · en 2 instalaciones');
});

it('mentions only the host while the issue is seen in a single installation', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $event = ngHostEvent($issue, 'a.example.com');

    $lines = app(AlertMessageFactory::class)->forIssue('new', $project, $issue->load('project'), $event)->lines;

    expect($lines)->toContain('host a.example.com')->and(implode(' ', $lines))->not->toContain('installation');
});

it('adds no host line to the alert of an issue without hosts', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $event = ngHostEvent($issue, null);

    $lines = app(AlertMessageFactory::class)->forIssue('new', $project, $issue->load('project'), $event)->lines;

    expect(implode(' ', $lines))->not->toContain('host ')->not->toContain('installation');
});

it('mentions the installations even when the alert has no event', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    ngHostEvent($issue, 'a.example.com');
    ngHostEvent($issue, 'b.example.com');

    $lines = app(AlertMessageFactory::class)->forIssue('threshold', $project, $issue->load('project'), null, 0, 50, 60)->lines;

    expect($lines)->toContain('in 2 installations');
});
