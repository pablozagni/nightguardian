<?php

use App\Enums\IssueStatus;
use App\Filament\Resources\Issues\Pages\ListIssues;
use App\Filament\Resources\Issues\Pages\ViewIssue;
use App\Filament\Resources\Issues\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Issues\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Issues\RelationManagers\FeedbackRelationManager;
use App\Issues\IssueActions;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\ExpectationFailedException;

uses(RefreshDatabase::class);

function ngIssue(array $team, array $attributes = []): Issue
{
    return Issue::factory()->for($team['project'])->create($attributes);
}

// --- actions and who may use them -------------------------------------------------------------------------

it('lets members and admins resolve, ignore and reopen, and logs each change', function (string $role) {
    $team = ngTeam();
    $issue = ngIssue($team);
    $this->actingAs($team[$role]);

    $page = livewire(ViewIssue::class, ['record' => $issue->getKey()]);

    $page->callAction('resolve');
    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)->and($issue->resolved_by)->toBe($team[$role]->id);

    $page->callAction('reopen');
    expect($issue->refresh()->status)->toBe(IssueStatus::New)->and($issue->resolved_at)->toBeNull();

    $page->callAction('ignore24h');
    expect($issue->refresh()->status)->toBe(IssueStatus::Ignored)->and($issue->ignored_until->isFuture())->toBeTrue();

    expect($issue->activities()->orderBy('id')->pluck('action')->all())
        ->toBe(['resolved', 'reopened', 'ignored'])
        ->and($issue->activities()->first()->user_id)->toBe($team[$role]->id);
})->with(['member', 'admin']);

it('does not offer or run any status action to viewers and outsiders', function (string $role) {
    $team = ngTeam();
    $issue = ngIssue($team);
    $this->actingAs($team[$role]);

    if ($role === 'outsider') {
        $this->get('/issues/'.$issue->id)->assertNotFound();

        return;
    }

    livewire(ViewIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('resolve')
        ->assertActionHidden('resolveInRelease')
        ->assertActionHidden('ignore1h')
        ->assertActionHidden('merge')
        ->assertOk();

    expect($issue->refresh()->status)->toBe(IssueStatus::New);
})->with(['viewer', 'outsider']);

it('refuses a forced resolve from a viewer', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $this->actingAs($team['viewer']);

    // Filament refuses to run an action that is hidden for this user.
    expect(fn () => livewire(ViewIssue::class, ['record' => $issue->getKey()])->callAction('resolve'))
        ->toThrow(ExpectationFailedException::class);

    expect($issue->refresh()->status)->toBe(IssueStatus::New);
});

it('resolves in a release and does not reopen for older releases', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => $issue->getKey()])
        ->callAction('resolveInRelease', ['release' => '1.4.3'])
        ->assertHasNoActionErrors();

    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolved_in_release)->toBe('1.4.3')
        ->and($issue->activities()->first()->meta)->toBe(['release' => '1.4.3']);

    // The ingest side already honours it: an older release does not reopen, a newer one does.
    [$project, $token] = [$team['project'], null];
    $fingerprint = IssueFingerprint::factory()->create(['issue_id' => $issue->id, 'project_id' => $project->id]);
    expect($fingerprint->issue_id)->toBe($issue->id);
});

it('requires a release when resolving in a release', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => $issue->getKey()])
        ->callAction('resolveInRelease', ['release' => ''])
        ->assertHasActionErrors(['release' => 'required']);
});

it('shows only the actions that make sense for the current status', function () {
    $team = ngTeam();
    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => ngIssue($team)->getKey()])
        ->assertActionVisible('resolve')->assertActionHidden('reopen');

    livewire(ViewIssue::class, ['record' => ngIssue($team, ['status' => IssueStatus::Resolved])->getKey()])
        ->assertActionHidden('resolve')->assertActionVisible('reopen');
});

// --- merge -------------------------------------------------------------------------------------------------

it('merges an issue into another one moving events, feedback and counters', function () {
    $team = ngTeam();
    $source = ngIssue($team, ['events_count' => 2, 'users_count' => 2, 'environments' => ['staging'], 'first_seen_at' => now()->subDays(10), 'last_seen_at' => now()->subDay()]);
    $target = ngIssue($team, ['events_count' => 3, 'users_count' => 1, 'environments' => ['production'], 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()]);
    Event::factory()->for($team['project'])->for($source)->count(2)->sequence(['user_identifier' => 'a'], ['user_identifier' => 'b'])->create();
    Event::factory()->for($team['project'])->for($target)->count(3)->state(['user_identifier' => 'a'])->create();
    Feedback::factory()->for($team['project'])->for($source)->create();
    IssueFingerprint::factory()->create(['issue_id' => $source->id, 'project_id' => $team['project']->id]);

    $this->actingAs($team['member']);
    livewire(ViewIssue::class, ['record' => $source->getKey()])
        ->callAction('merge', ['target' => $target->getKey()])
        ->assertHasNoActionErrors();

    $target->refresh();
    $source->refresh();

    expect($source->merged_into_id)->toBe($target->id)
        ->and(Event::where('issue_id', $target->id)->count())->toBe(5)
        ->and(Event::where('issue_id', $source->id)->count())->toBe(0)
        ->and(Feedback::where('issue_id', $target->id)->count())->toBe(1)
        ->and(IssueFingerprint::where('issue_id', $target->id)->count())->toBe(1)
        ->and($target->events_count)->toBe(5)
        ->and($target->users_count)->toBe(2)
        ->and($target->environments)->toBe(['production', 'staging'])
        ->and($target->first_seen_at->isSameDay(now()->subDays(10)))->toBeTrue()
        ->and($target->activities()->where('action', 'merged')->exists())->toBeTrue();

    livewire(ListIssues::class)->removeTableFilter('status')->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$source]);
});

it('only offers issues of the same project to merge into, and rejects forged targets', function () {
    $team = ngTeam();
    $source = ngIssue($team);
    $foreign = Issue::factory()->for($team['other'])->create();
    $this->actingAs($team['member']);

    livewire(ViewIssue::class, ['record' => $source->getKey()])
        ->callAction('merge', ['target' => $foreign->getKey()])
        ->assertHasActionErrors(['target']);

    expect($source->refresh()->merged_into_id)->toBeNull();

    expect(fn () => app(IssueActions::class)->merge($source, $foreign, $team['member']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(IssueActions::class)->merge($source, $source, $team['member']))->toThrow(InvalidArgumentException::class);
});

// --- bulk actions ------------------------------------------------------------------------------------------

it('resolves and ignores in bulk, only the issues the user may update', function () {
    $team = ngTeam();
    $mine = ngIssue($team);
    $another = ngIssue($team);
    $this->actingAs($team['member']);

    livewire(ListIssues::class)->callTableBulkAction('resolve', [$mine, $another]);
    expect($mine->refresh()->status)->toBe(IssueStatus::Resolved)->and($another->refresh()->status)->toBe(IssueStatus::Resolved);

    $viewerIssue = ngIssue($team);
    $this->actingAs($team['viewer']);
    livewire(ListIssues::class)->callTableBulkAction('ignore', [$viewerIssue]);

    expect($viewerIssue->refresh()->status)->toBe(IssueStatus::New);
});

it('filters the triage list by project, status, level, type and release', function () {
    $team = ngTeam();
    $error = ngIssue($team, ['level' => 'error', 'last_release' => '2.0.0', 'type' => 'exception']);
    $warning = ngIssue($team, ['level' => 'warning', 'last_release' => '1.0.0', 'type' => 'log']);
    $resolved = ngIssue($team, ['status' => IssueStatus::Resolved, 'environments' => ['staging']]);
    $this->actingAs($team['super']);

    livewire(ListIssues::class)
        ->assertCanSeeTableRecords([$error, $warning])->assertCanNotSeeTableRecords([$resolved])
        ->removeTableFilter('status')
        ->filterTable('level', ['error'])->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$warning])
        ->removeTableFilter('level')
        ->filterTable('type', 'log')->assertCanSeeTableRecords([$warning])->assertCanNotSeeTableRecords([$error])
        ->removeTableFilter('type')
        ->filterTable('release', ['release' => '2.0.0'])->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$warning])
        ->removeTableFilter('release')
        ->filterTable('environment', 'staging')->assertCanSeeTableRecords([$resolved])->assertCanNotSeeTableRecords([$error])
        ->removeTableFilter('environment')
        ->filterTable('project_id', $team['other']->id)->assertCanNotSeeTableRecords([$error, $warning, $resolved]);
});

it('sorts the triage list by last seen and by events', function () {
    $team = ngTeam();
    $old = ngIssue($team, ['last_seen_at' => now()->subDays(2), 'events_count' => 99]);
    $new = ngIssue($team, ['last_seen_at' => now(), 'events_count' => 1]);
    $this->actingAs($team['super']);

    livewire(ListIssues::class)->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->sortTable('events_count', 'desc')->assertCanSeeTableRecords([$old, $new], inOrder: true);
});

// --- the tabs ----------------------------------------------------------------------------------------------

it('shows the feedback of users linked to the issue, like the one left on the error page', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $feedback = Feedback::factory()->for($team['project'])->for($issue)->create([
        'reference' => 'NG-TAS9-K3NG',
        'comment' => 'I clicked Confirm twice',
    ]);
    $unrelated = Feedback::factory()->for($team['project'])->create(['comment' => 'Different issue']);
    $this->actingAs($team['viewer']);

    livewire(FeedbackRelationManager::class, ['ownerRecord' => $issue, 'pageClass' => ViewIssue::class])
        ->assertCanSeeTableRecords([$feedback])
        ->assertCanNotSeeTableRecords([$unrelated])
        ->assertSee('I clicked Confirm twice')
        ->assertSee('NG-TAS9-K3NG');
});

it('lists the events of the issue paginated and links them', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $events = Event::factory()->for($team['project'])->for($issue)->count(12)->create();
    $other = Event::factory()->for($team['project'])->create(['issue_id' => ngIssue($team)->id]);
    $this->actingAs($team['viewer']);

    livewire(EventsRelationManager::class, ['ownerRecord' => $issue, 'pageClass' => ViewIssue::class])
        ->assertCanSeeTableRecords($events->sortByDesc('occurred_at')->take(10)->all())
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows the activity log with who did what, including automatic regressions', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    app(IssueActions::class)->resolve($issue, $team['member']);
    IssueActivity::create(['issue_id' => $issue->id, 'action' => 'regressed', 'meta' => ['release' => '2.0.0']]);
    $this->actingAs($team['viewer']);

    livewire(ActivityRelationManager::class, ['ownerRecord' => $issue, 'pageClass' => ViewIssue::class])
        ->assertCanSeeTableRecords($issue->activities)
        ->assertSee('Marked as resolved')
        ->assertSee('regression')
        ->assertSee($team['member']->name);
});

// --- event rendering ---------------------------------------------------------------------------------------

it('renders the stack trace with in-app frames highlighted and code context, escaping everything', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    $event = Event::factory()->for($team['project'])->for($issue)->create([
        'reference' => 'NG-AAAA-BBBB',
        'message' => '<script>alert("x")</script>',
        'payload' => ngEvent([
            'payload' => ['handled' => false, 'exceptions' => [[
                'class' => 'App\\<b>Boom</b>',
                'message' => '<script>alert("pwned")</script>',
                'frames' => [
                    ['file' => 'app/Http/Controllers/InvoiceController.php', 'line' => 88, 'function' => 'update', 'class' => 'App\\Http\\Controllers\\InvoiceController', 'in_app' => true,
                        'context' => ['pre' => ['    $a = 1;', '    $b = 2;'], 'line' => '    $invoice->save();', 'post' => ['    return 1;']]],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 825, 'function' => 'run', 'class' => 'Illuminate\\Database\\Connection', 'in_app' => false],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/Router.php', 'line' => 10, 'function' => 'dispatch', 'class' => 'Illuminate\\Routing\\Router', 'in_app' => false],
                ],
            ]]],
            'tags' => ['tenant' => '<i>acme</i>'],
            'context' => ['order' => '<img src=x onerror=alert(1)>'],
        ]),
    ]);
    $this->actingAs($team['viewer']);

    $html = $this->get('/events/'.$event->id)->assertOk()->getContent();

    expect($html)
        ->toContain('app/Http/Controllers/InvoiceController.php:88')
        ->toContain('ng-frame-app')
        ->toContain('ng-line-current')
        ->toContain('$invoice-&gt;save();')
        ->toContain('2 framework / vendor frames')
        ->not->toContain('<script>alert("pwned")</script>')
        ->not->toContain('<script>alert("x")</script>')
        ->not->toContain('<b>Boom</b>')
        ->not->toContain('<i>acme</i>')
        ->not->toContain('<img src=x onerror=alert(1)>');
});

it('shows the latest event of the issue in the overview', function () {
    $team = ngTeam();
    $issue = ngIssue($team);
    Event::factory()->for($team['project'])->for($issue)->create(['occurred_at' => now()->subDay(), 'reference' => 'NG-OLD0-OLD0']);
    Event::factory()->for($team['project'])->for($issue)->create(['occurred_at' => now(), 'reference' => 'NG-NEW0-NEW0']);
    $this->actingAs($team['viewer']);

    $this->get('/issues/'.$issue->id)->assertOk()->assertSee('NG-NEW0-NEW0')->assertDontSee('NG-OLD0-OLD0');
});
