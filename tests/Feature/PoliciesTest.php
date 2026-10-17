<?php

use App\Models\AlertChannel;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Policy matrix: [ability => [super admin, admin, member, viewer, outsider]].
 */
function actors(): array
{
    $project = Project::factory()->create();
    $make = function (?string $role, bool $super = false) use ($project) {
        $user = $super ? User::factory()->superAdmin()->create() : User::factory()->create();
        if ($role) {
            $project->users()->attach($user, ['role' => $role]);
        }

        return $user;
    };

    return [
        $project,
        [
            'super' => $make(null, true),
            'admin' => $make('admin'),
            'member' => $make('member'),
            'viewer' => $make('viewer'),
            'outsider' => $make(null),
        ],
    ];
}

function matrix(array $actors, callable $check): array
{
    return collect($actors)->map(fn (User $user) => $check($user))->all();
}

it('applies the project policy matrix', function () {
    [$project, $actors] = actors();

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('view', $project)))
        ->toBe(['super' => true, 'admin' => true, 'member' => true, 'viewer' => true, 'outsider' => false]);

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('update', $project)))
        ->toBe(['super' => true, 'admin' => true, 'member' => false, 'viewer' => false, 'outsider' => false]);

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('create', Project::class)))
        ->toBe(['super' => true, 'admin' => false, 'member' => false, 'viewer' => false, 'outsider' => false]);

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('delete', $project)))
        ->toBe(['super' => true, 'admin' => false, 'member' => false, 'viewer' => false, 'outsider' => false]);
});

it('applies the issue policy matrix', function () {
    [$project, $actors] = actors();
    $issue = Issue::factory()->for($project)->create();

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('view', $issue)))
        ->toBe(['super' => true, 'admin' => true, 'member' => true, 'viewer' => true, 'outsider' => false]);

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('update', $issue)))
        ->toBe(['super' => true, 'admin' => true, 'member' => true, 'viewer' => false, 'outsider' => false]);

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('delete', $issue)))
        ->toBe(['super' => true, 'admin' => true, 'member' => false, 'viewer' => false, 'outsider' => false]);
});

it('limits alert channels to project admins', function () {
    [$project, $actors] = actors();
    $channel = AlertChannel::factory()->for($project)->create();
    $adminOnly = ['super' => true, 'admin' => true, 'member' => false, 'viewer' => false, 'outsider' => false];

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows($ability, $channel)))->toBe($adminOnly);
    }

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('create', [AlertChannel::class, $project])))
        ->toBe($adminOnly);
});

it('limits project tokens to project admins', function () {
    [$project, $actors] = actors();
    $token = ProjectToken::factory()->for($project)->create();
    $adminOnly = ['super' => true, 'admin' => true, 'member' => false, 'viewer' => false, 'outsider' => false];

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows($ability, $token)))->toBe($adminOnly);
    }

    expect(matrix($actors, fn ($u) => Gate::forUser($u)->allows('create', [ProjectToken::class, $project])))
        ->toBe($adminOnly);
});

it('does not leak access between projects', function () {
    [$projectA, $actors] = actors();
    $projectB = Project::factory()->create();
    $issueB = Issue::factory()->for($projectB)->create();

    expect(Gate::forUser($actors['admin'])->allows('view', $projectB))->toBeFalse()
        ->and(Gate::forUser($actors['admin'])->allows('update', $issueB))->toBeFalse();
});
