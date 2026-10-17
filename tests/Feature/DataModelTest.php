<?php

use App\Enums\EventType;
use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Enums\ProjectRole;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\AlertRule;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\IssueFingerprint;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('links a project to its related records', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $event = Event::factory()->for($project)->for($issue)->create();
    $fingerprint = IssueFingerprint::factory()->for($issue)->create();
    $feedback = Feedback::factory()->for($project)->for($issue)->create();
    $deploy = Deploy::factory()->for($project)->create();
    $token = ProjectToken::factory()->for($project)->create();
    $channel = AlertChannel::factory()->for($project)->create();
    $rule = AlertRule::factory()->for($project)->create();
    $log = AlertLog::factory()->for($channel, 'channel')->for($issue)->create();
    $invitation = Invitation::factory()->for($project)->create();

    expect($project->issues->modelKeys())->toBe([$issue->id])
        ->and($project->events->modelKeys())->toBe([$event->id])
        ->and($project->feedback->modelKeys())->toBe([$feedback->id])
        ->and($project->deploys->modelKeys())->toBe([$deploy->id])
        ->and($project->tokens->modelKeys())->toBe([$token->id])
        ->and($project->alertChannels->modelKeys())->toBe([$channel->id])
        ->and($project->alertRules->modelKeys())->toBe([$rule->id])
        ->and($project->invitations->modelKeys())->toBe([$invitation->id])
        ->and($issue->events->modelKeys())->toBe([$event->id])
        ->and($issue->fingerprints->modelKeys())->toBe([$fingerprint->id])
        ->and($issue->feedback->modelKeys())->toBe([$feedback->id])
        ->and($issue->alertLogs->modelKeys())->toBe([$log->id])
        ->and($channel->logs->modelKeys())->toBe([$log->id])
        ->and($event->issue->is($issue))->toBeTrue();
});

it('uses 26 character ULIDs for projects, issues and events', function () {
    $event = Event::factory()->for(Issue::factory()->create(), 'issue')->create(['project_id' => Project::factory()]);

    expect(strlen($event->id))->toBe(26)
        ->and(strlen($event->issue_id))->toBe(26)
        ->and(strlen($event->project_id))->toBe(26);
});

it('casts enums, dates and json columns', function () {
    $issue = Issue::factory()->create();
    $event = Event::factory()->create();
    $channel = AlertChannel::factory()->create(['config' => ['bot_token' => 'secret', 'chat_id' => '1']]);

    expect($issue->status)->toBe(IssueStatus::New)
        ->and($issue->level)->toBe(Level::Error)
        ->and($issue->environments)->toBe(['production'])
        ->and($event->type)->toBe(EventType::Exception)
        ->and($event->payload)->toBeArray()
        ->and($channel->min_level)->toBe(Level::Error)
        ->and($channel->fresh()->config)->toBe(['bot_token' => 'secret', 'chat_id' => '1']);
});

it('stores alert channel config encrypted', function () {
    $channel = AlertChannel::factory()->create(['config' => ['bot_token' => 'secret']]);

    $raw = DB::table('alert_channels')->where('id', $channel->id)->value('config');

    expect($raw)->not->toContain('secret');
});

it('orders levels by severity', function () {
    expect(Level::Critical->atLeast(Level::Error))->toBeTrue()
        ->and(Level::Warning->atLeast(Level::Error))->toBeFalse()
        ->and(ProjectRole::Admin->atLeast(ProjectRole::Member))->toBeTrue()
        ->and(ProjectRole::Viewer->atLeast(ProjectRole::Member))->toBeFalse();
});

it('keeps one membership per user and project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $project->users()->attach($user, ['role' => 'member']);

    expect(fn () => $project->users()->attach($user, ['role' => 'admin']))->toThrow(Exception::class);
    expect($user->roleIn($project))->toBe(ProjectRole::Member)
        ->and($user->projects->modelKeys())->toBe([$project->id]);
});

it('enforces a unique fingerprint per project', function () {
    $issue = Issue::factory()->create();
    IssueFingerprint::factory()->for($issue)->create(['fingerprint' => str_repeat('a', 40)]);

    expect(fn () => IssueFingerprint::factory()->for($issue)->create(['fingerprint' => str_repeat('a', 40)]))
        ->toThrow(Exception::class);
});

it('generates ng_ tokens and hashes them with sha256', function () {
    $plain = ProjectToken::generatePlainToken();

    expect($plain)->toStartWith('ng_')->and(strlen($plain))->toBe(43)
        ->and(ProjectToken::hash($plain))->toBe(hash('sha256', $plain));
});

it('supports instance-level invitations', function () {
    $invitation = Invitation::factory()->instanceLevel()->create(['is_super_admin' => true]);

    expect($invitation->isInstanceLevel())->toBeTrue()
        ->and($invitation->role)->toBeNull();
});

it('seeds the demo data', function () {
    $this->seed(DemoSeeder::class);

    expect(Project::count())->toBe(2)
        ->and(User::count())->toBe(3)
        ->and(Issue::count())->toBe(15)
        ->and(Issue::distinct()->pluck('status')->count())->toBe(4)
        ->and(Event::count())->toBeGreaterThanOrEqual(30)
        ->and(Feedback::count())->toBeGreaterThan(0)
        ->and(Deploy::count())->toBe(6);
});
