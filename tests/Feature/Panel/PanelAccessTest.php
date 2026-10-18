<?php

use App\Filament\Resources\Deploys\DeployResource;
use App\Filament\Resources\Deploys\Pages\ListDeploys;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Filament\Resources\Issues\Pages\ListIssues;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Issue;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

/**
 * [page => [super admin, project admin, member, viewer, outsider]] expected HTTP status.
 */
dataset('pages', [
    'dashboard' => ['/', [200, 200, 200, 200, 200]],
    'triage' => ['/issues', [200, 200, 200, 200, 200]],
    'deploys' => ['/deploys', [200, 200, 200, 200, 200]],
    'projects list' => ['/projects', [200, 200, 200, 200, 200]],
    'project create (super admin only)' => ['/projects/create', [200, 403, 403, 403, 403]],
    'project settings (admins only)' => ['/projects/{project}/edit', [200, 200, 403, 403, 404]],
    'users (super admin only)' => ['/users', [200, 403, 403, 403, 403]],
    'issue of the project' => ['/issues/{issue}', [200, 200, 200, 200, 404]],
    'event of the project' => ['/events/{event}', [200, 200, 200, 200, 404]],
]);

it('enforces the role matrix on every page', function (string $path, array $expected) {
    $team = ngTeam();
    $issue = Issue::factory()->for($team['project'])->create();
    $event = Event::factory()->for($team['project'])->for($issue)->create();
    $path = str_replace(['{project}', '{issue}', '{event}'], [$team['project']->id, $issue->id, $event->id], $path);

    foreach (['super', 'admin', 'member', 'viewer', 'outsider'] as $index => $role) {
        $this->actingAs($team[$role])->get($path)->assertStatus($expected[$index], "{$role} on {$path}");
    }
})->with('pages');

it('sends guests to the login page', function () {
    $this->get('/issues')->assertRedirect('/login');
    $this->get('/projects')->assertRedirect('/login');
});

it('shows each user only the issues of their projects', function () {
    $team = ngTeam();
    $mine = Issue::factory()->for($team['project'])->create(['title' => 'Mine']);
    $theirs = Issue::factory()->for($team['other'])->create(['title' => 'Theirs']);

    foreach (['admin', 'member', 'viewer'] as $role) {
        $this->actingAs($team[$role]);
        livewire(ListIssues::class)
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    $this->actingAs($team['super']);
    livewire(ListIssues::class)
        ->removeTableFilter('status')
        ->assertCanSeeTableRecords([$mine, $theirs]);
});

it('shows each user only the projects and deploys they belong to', function () {
    $team = ngTeam();
    $deploy = Deploy::factory()->for($team['project'])->create();
    $foreign = Deploy::factory()->for($team['other'])->create();

    $this->actingAs($team['viewer']);
    livewire(ListProjects::class)
        ->assertCanSeeTableRecords([$team['project']])
        ->assertCanNotSeeTableRecords([$team['other']]);
    livewire(ListDeploys::class)
        ->assertCanSeeTableRecords([$deploy])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('only lets super admins create and delete projects and manage users', function () {
    $team = ngTeam();

    foreach (['admin', 'member', 'viewer', 'outsider'] as $role) {
        $this->actingAs($team[$role]);
        expect(ProjectResource::canCreate())->toBeFalse("{$role} create project")
            ->and(UserResource::canViewAny())->toBeFalse("{$role} users")
            ->and(auth()->user()->can('delete', $team['project']))->toBeFalse("{$role} delete project");
    }

    $this->actingAs($team['super']);
    expect(ProjectResource::canCreate())->toBeTrue()->and(UserResource::canViewAny())->toBeTrue();
});

it('does not let a super admin delete their own account', function () {
    $super = User::factory()->superAdmin()->create();

    expect((new UserPolicy)->delete($super, $super))->toBeFalse()
        ->and((new UserPolicy)->delete($super, User::factory()->create()))->toBeTrue();
});

it('lets users who cannot edit a project still see it listed without edit rights', function () {
    $team = ngTeam();
    $this->actingAs($team['viewer']);

    expect(ProjectResource::canEdit($team['project']))->toBeFalse()
        ->and(IssueResource::canView(Issue::factory()->for($team['project'])->create()))->toBeTrue()
        ->and(DeployResource::canCreate())->toBeFalse()
        ->and(EventResource::canCreate())->toBeFalse();
});

it('keeps the navigation and labels translated in both languages', function (string $locale, string $triage) {
    $team = ngTeam();
    $team['viewer']->update(['locale' => $locale]);

    $this->actingAs($team['viewer'])->get('/issues')->assertOk()->assertSee($triage);
})->with([['en', 'Triage'], ['es', 'Triage']]);

it('has exactly the same translation keys in English and Spanish', function () {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($array as $key => $value) {
            is_array($value) ? $keys = [...$keys, ...$flatten($value, "{$prefix}{$key}.")] : $keys[] = "{$prefix}{$key}";
        }

        return $keys;
    };

    $en = $flatten(require lang_path('en/nightguardian.php'));
    $es = $flatten(require lang_path('es/nightguardian.php'));

    expect(array_diff($en, $es))->toBe([])->and(array_diff($es, $en))->toBe([]);
});

it('does not leave untranslated keys behind in the panel code', function () {
    $keys = [];
    $files = [...glob(app_path('Filament/**/*.php')) ?: [], ...glob(app_path('Filament/*/*/*.php')) ?: [], ...glob(app_path('Filament/*/*/*/*.php')) ?: [], ...glob(resource_path('views/**/*.blade.php')) ?: [], ...glob(resource_path('views/*/*/*.blade.php')) ?: []];

    foreach ($files as $file) {
        preg_match_all("/__\('nightguardian\.([a-z0-9_.]+)'/", (string) file_get_contents($file), $m);
        $keys = [...$keys, ...$m[1]];
    }

    $keys = array_filter($keys, fn (string $key) => ! str_ends_with($key, '.')); // dynamic keys: checked by the parity test

    $missing = array_values(array_filter(array_unique($keys), fn (string $key) => ! Arr::has(require lang_path('en/nightguardian.php'), $key)));

    expect($missing)->toBe([]);
});
