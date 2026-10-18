<?php

use App\Enums\IssueStatus;
use App\Filament\Widgets\ProjectCards;
use App\Models\Event;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Project;
use App\Models\User;
use App\Support\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a card per visible project with open issues, new today, events in 24 h and a 7 day chart', function () {
    $this->travelTo(now()->setTime(15, 0));
    $team = ngTeam();
    $project = $team['project'];

    $open = Issue::factory()->for($project)->create(['status' => IssueStatus::New, 'first_seen_at' => now()->subHour()]);
    Issue::factory()->for($project)->create(['status' => IssueStatus::Regressed, 'first_seen_at' => now()->subDays(3)]);
    Issue::factory()->for($project)->create(['status' => IssueStatus::Resolved, 'first_seen_at' => now()->subDays(3)]);
    Issue::factory()->for($project)->create(['status' => IssueStatus::New, 'merged_into_id' => $open->id, 'first_seen_at' => now()]);

    Event::factory()->for($project)->for($open)->count(3)->create(['occurred_at' => now()->subHours(2)]);
    Event::factory()->for($project)->for($open)->count(2)->create(['occurred_at' => now()->subDays(3)]);
    Event::factory()->for($project)->for($open)->create(['occurred_at' => now()->subDays(20)]);
    Event::factory()->for($project)->create(['issue_id' => null, 'occurred_at' => now()->subHour()]); // deploy / feedback

    $this->actingAs($team['viewer']);
    $cards = (new ProjectCards)->getCards();

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['name'])->toBe('Shop')
        ->and($cards[0]['open'])->toBe(2)
        ->and($cards[0]['new_today'])->toBe(1)
        ->and($cards[0]['events_24h'])->toBe(3)
        ->and($cards[0]['days'])->toHaveCount(7)
        ->and(array_sum(array_column($cards[0]['days'], 'count')))->toBe(5)
        ->and(end($cards[0]['days'])['count'])->toBe(3);
});

it('only shows the projects of the user, and everything to super admins', function () {
    $team = ngTeam();

    $this->actingAs($team['outsider']);
    expect(array_column((new ProjectCards)->getCards(), 'name'))->toBe([$team['other']->name]);

    $this->actingAs($team['super']);
    expect(array_column((new ProjectCards)->getCards(), 'name'))->toContain('Shop', 'Other');
});

it('renders the dashboard cards and an empty state', function () {
    $team = ngTeam();

    $this->actingAs($team['viewer'])->get('/')->assertOk()->assertSee('Shop')->assertSee('Open issues');

    $lonely = User::factory()->create();
    $this->actingAs($lonely)->get('/')->assertOk()->assertSee('No projects yet');
});

it('shows a regression in the activity log when an old issue comes back through the ingest', function () {
    [$project, $token] = ngProject();

    ngPost($token, ['events' => [ngEvent()]])->assertStatus(202);
    $issue = Issue::firstOrFail();
    $issue->update(['status' => IssueStatus::Resolved, 'resolved_at' => now()]);

    ngPost($token, ['events' => [ngEvent(['release' => '2.0.0'])]])->assertStatus(202);

    expect($issue->refresh()->status)->toBe(IssueStatus::Regressed)
        ->and(IssueActivity::where('issue_id', $issue->id)->where('action', 'regressed')->first()?->meta)->toBe(['release' => '2.0.0']);
});

// --- the presenter ----------------------------------------------------------------------------------------

it('groups consecutive vendor frames and keeps the protocol order', function () {
    $event = new Event(['payload' => ngEvent(['payload' => ['exceptions' => [[
        'class' => 'A', 'message' => 'm', 'frames' => [
            ['file' => 'app/a.php', 'line' => 10, 'in_app' => true, 'function' => 'f', 'class' => 'C', 'context' => ['pre' => ['a', 'b'], 'line' => 'c', 'post' => ['d']]],
            ['file' => 'vendor/x.php', 'line' => 1, 'in_app' => false],
            ['file' => 'vendor/y.php', 'line' => 2, 'in_app' => false],
            ['file' => 'app/b.php', 'line' => 20, 'in_app' => true],
        ],
    ]]]])]);

    $exceptions = (new EventPresenter)->exceptions($event);
    $runs = $exceptions[0]['runs'];

    expect($runs)->toHaveCount(3)
        ->and(array_column($runs, 'in_app'))->toBe([true, false, true])
        ->and($runs[1]['frames'])->toHaveCount(2)
        ->and($runs[0]['frames'][0]['call'])->toBe('C::f()')
        ->and(array_column($runs[0]['frames'][0]['code'], 'number'))->toBe([8, 9, 10, 11])
        ->and(array_column($runs[0]['frames'][0]['code'], 'current'))->toBe([false, false, true, false]);
});

it('copes with events that have no usable stack trace', function () {
    $presenter = new EventPresenter;

    foreach ([['payload' => []], ['payload' => ['payload' => ['exceptions' => 'nope']]], ['payload' => ['payload' => ['exceptions' => [5, ['frames' => 'x']]]]]] as $attributes) {
        expect($presenter->exceptions(new Event($attributes)))->toBeArray();
    }

    expect($presenter->request(new Event(['payload' => []])))->toBe([]);
});

it('keeps projects without events or issues working', function () {
    Project::factory()->create();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/')->assertOk();
});
