<?php

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\Pages\ViewIssue;
use App\Filament\Resources\Issues\RelationManagers\FeedbackRelationManager;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\User;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ngReferencedEvent(array $team, string $reference = 'NG-TAS9-K3NG', string $project = 'project'): Event
{
    $issue = Issue::factory()->for($team[$project])->create(['title' => 'RuntimeException: Gateway returned an unexpected response']);

    return Event::factory()->for($team[$project])->for($issue)->create(['reference' => $reference, 'message' => 'Gateway returned an unexpected response']);
}

// --- global search by reference code ----------------------------------------------------------------------

it('finds an event by its reference code in the global search and opens it', function () {
    $team = ngTeam();
    $event = ngReferencedEvent($team);
    $this->actingAs($team['viewer']);

    $results = EventResource::getGlobalSearchResults('NG-TAS9-K3NG');

    expect($results)->toHaveCount(1)
        ->and($results->first()->url)->toBe(EventResource::getUrl('view', ['record' => $event]));

    $this->get($results->first()->url)->assertOk()->assertSee('NG-TAS9-K3NG');
});

it('accepts the code in any case, without dashes, and with the letters people mistake for digits', function (string $typed) {
    $team = ngTeam();
    ngReferencedEvent($team, 'NG-1A20-0B11');
    $this->actingAs($team['member']);

    expect(EventResource::getGlobalSearchResults($typed))->toHaveCount(1);
})->with(['ng-1a20-0b11', 'NG1A200B11', 'ng 1a20 0b11', 'NG-IA2O-OBLI', '  NG-1A20-0B11 ']);

it('finds partial codes too', function () {
    $team = ngTeam();
    ngReferencedEvent($team);
    $this->actingAs($team['member']);

    expect(EventResource::getGlobalSearchResults('TAS9'))->toHaveCount(1)
        ->and(EventResource::getGlobalSearchResults('ZZZZ'))->toHaveCount(0);
});

it('never reveals events of projects the user does not belong to', function () {
    $team = ngTeam();
    $event = ngReferencedEvent($team, 'NG-SECR-ET00', 'other');

    $this->actingAs($team['viewer']);
    expect(EventResource::getGlobalSearchResults('NG-SECR-ET00'))->toHaveCount(0);
    $this->get('/events/'.$event->id)->assertNotFound();

    $this->actingAs($team['outsider']);
    expect(EventResource::getGlobalSearchResults('NG-SECR-ET00'))->toHaveCount(1);

    $this->actingAs($team['super']);
    expect(EventResource::getGlobalSearchResults('NG-SECR-ET00'))->toHaveCount(1);
});

it('works from the search box of the panel', function () {
    $team = ngTeam();
    ngReferencedEvent($team);
    $this->actingAs($team['viewer']);

    livewire(GlobalSearch::class)->set('search', 'NG-TAS9-K3NG')->assertSee('NG-TAS9-K3NG')->assertSee('Shop');
});

it('also finds issues by title', function () {
    $team = ngTeam();
    ngReferencedEvent($team);
    $this->actingAs($team['viewer']);

    livewire(GlobalSearch::class)->set('search', 'Gateway returned')->assertSee('Gateway returned an unexpected response');
});

it('normalizes only complete reference codes', function () {
    expect(EventResource::normalizeReference('ng-tas9-k3ng'))->toBe('NG-TAS9-K3NG')
        ->and(EventResource::normalizeReference('hello'))->toBe('hello')
        ->and(EventResource::normalizeReference('tas9'))->toBe('tas9');
});

// --- the feedback of the friendly error page, end to end -------------------------------------------------

it('shows the feedback sent from the error page on the issue, linked through the ingest', function () {
    [$project, $token] = ngProject();
    $project->users()->attach($viewer = User::factory()->create(), ['role' => 'viewer']);

    $event = ngEvent(['reference' => 'NG-TAS9-K3NG']);
    ngPost($token, ['events' => [$event]])->assertStatus(202);
    ngPost($token, ['events' => [[
        'id' => fake()->uuid(), 'type' => 'feedback', 'timestamp' => '2026-10-17T13:46:00.000Z',
        'environment' => 'production', 'platform' => 'php',
        'payload' => ['event_id' => $event['id'], 'reference' => 'NG-TAS9-K3NG', 'comment' => 'I was paying invoice 12 and clicked Confirm twice'],
    ]]])->assertStatus(202)->assertJsonPath('accepted', 1);

    $issue = Issue::query()->firstOrFail();
    $feedback = Feedback::query()->firstOrFail();
    expect($feedback->issue_id)->toBe($issue->id)->and($feedback->event_uuid)->toBe($event['id']);

    $this->actingAs($viewer);
    livewire(FeedbackRelationManager::class, [
        'ownerRecord' => $issue,
        'pageClass' => ViewIssue::class,
    ])->assertCanSeeTableRecords([$feedback])->assertSee('I was paying invoice 12 and clicked Confirm twice');

    expect(EventResource::getGlobalSearchResults('NG-TAS9-K3NG'))->toHaveCount(1);
});

// --- members: adding existing users -----------------------------------------------------------------------

it('adds an existing user by exact e-mail and never lists other users', function () {
    $team = ngTeam();
    $stranger = User::factory()->create(['email' => 'stranger@example.com', 'name' => 'Hidden Person']);
    $this->actingAs($team['admin']);

    $page = livewire(MembersRelationManager::class, ['ownerRecord' => $team['project'], 'pageClass' => EditProject::class])
        ->assertDontSee('Hidden Person')
        ->assertDontSee('stranger@example.com');

    $page->callTableAction('addMember', data: ['email' => 'stranger@example.com', 'role' => 'viewer']);
    expect($stranger->roleIn($team['project'])?->value)->toBe('viewer');

    $page->callTableAction('addMember', data: ['email' => 'nobody@example.com', 'role' => 'viewer']);
    expect(User::where('email', 'nobody@example.com')->exists())->toBeFalse();
});

it('does not let members add users', function () {
    $team = ngTeam();
    $this->actingAs($team['member']);

    expect(MembersRelationManager::canViewForRecord($team['project'], EditProject::class))->toBeFalse();
});
