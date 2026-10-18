<?php

use App\Demo\DemoMode;
use App\Demo\DemoReset;
use App\Filament\Pages\Auth\DemoAwareEditProfile;
use App\Filament\Pages\Auth\DemoLogin;
use App\Models\AlertChannel;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

const NG_DEMO_TOKEN = 'ng_Demo1ngestTokenForTests0123456789abcdefg';

function ngDemoOn(array $extra = []): void
{
    config(['nightguardian.demo' => array_replace([
        'enabled' => true,
        'visitor_email' => 'visitor@demo.example.com',
        'visitor_password' => 'visitor-demo',
        'admin_email' => 'boss@example.com',
        'ingest_token' => NG_DEMO_TOKEN,
        'ingest_rate_limit_per_minute' => 120,
        'reset_time' => '04:00',
    ], $extra)]);
}

// --- off by default -----------------------------------------------------------------------------------------------

it('is off by default and changes nothing', function () {
    expect(DemoMode::enabled())->toBeFalse();

    $this->get('/login')->assertOk()->assertDontSee('Live demo')->assertDontSee('Enter the demo')->assertDontSee('visitor@demo.example.com');

    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk()->assertDontSee('data is reset every night');
    expect(DemoMode::isVisitor($user))->toBeFalse();
});

it('is not scheduled and refuses to run when it is off', function () {
    User::factory()->create();

    $this->artisan('nightguardian:demo-reset')->assertFailed();

    expect(User::count())->toBe(1);
});

// --- the login page ------------------------------------------------------------------------------------------------

it('shows the visitor credentials and the enter button on the login page', function () {
    ngDemoOn();

    $this->get('/login')
        ->assertOk()
        ->assertSee('This is a live demo')
        ->assertSee('visitor@demo.example.com')
        ->assertSee('visitor-demo')
        ->assertSee('Enter the demo');
});

it('signs the visitor in with one click and the visitor is a viewer', function () {
    ngDemoOn();
    app(DemoReset::class)->run();

    livewire(DemoLogin::class)->call('enterDemo')->assertHasNoErrors();

    $visitor = Auth::user();
    expect($visitor?->email)->toBe('visitor@demo.example.com')
        ->and($visitor->is_super_admin)->toBeFalse()
        ->and($visitor->projects()->pluck('project_user.role')->unique()->all())->toBe(['viewer']);
});

it('also lets the visitor sign in with the shown credentials', function () {
    ngDemoOn();
    app(DemoReset::class)->run();

    $this->post('/login', ['email' => 'visitor@demo.example.com', 'password' => 'visitor-demo']);

    expect(User::where('email', 'visitor@demo.example.com')->exists())->toBeTrue();
    expect(Hash::check('visitor-demo', User::where('email', 'visitor@demo.example.com')->value('password')))->toBeTrue();
});

it('has no enter button effect when the demo account does not exist', function () {
    ngDemoOn();

    livewire(DemoLogin::class)->call('enterDemo');

    expect(Auth::check())->toBeFalse();
});

it('does not let the enter button sign in when demo mode is off', function () {
    User::factory()->create(['email' => 'visitor@demo.example.com']);

    livewire(DemoLogin::class)->call('enterDemo');

    expect(Auth::check())->toBeFalse();
});

// --- the banner and what the visitor cannot do ------------------------------------------------------------------

it('shows the permanent banner in the panel, in English and Spanish', function () {
    ngDemoOn();
    $visitor = User::factory()->create(['email' => 'visitor@demo.example.com']);

    $this->actingAs($visitor)->get('/')->assertOk()->assertSee('Live demo — the data is reset every night');

    $this->actingAs($visitor)->withHeaders(['Accept-Language' => 'es'])->get('/')->assertSee('Demo en vivo — los datos se reinician todas las noches');
});

it('shows the panel to the visitor in the language of their browser, whatever the account says', function () {
    ngDemoOn();
    $visitor = User::factory()->create(['email' => 'visitor@demo.example.com', 'locale' => 'en']);

    $this->actingAs($visitor)->withHeaders(['Accept-Language' => 'es-AR,es;q=0.9'])->get('/')->assertSee('Demo en vivo — los datos se reinician todas las noches');
    $this->actingAs($visitor)->withHeaders(['Accept-Language' => 'en-US'])->get('/')->assertSee('Live demo — the data is reset every night');
});

it('follows the browser language on the demo login page', function () {
    ngDemoOn();

    $this->withHeaders(['Accept-Language' => 'es-AR,es;q=0.9'])->get('/login')->assertSee('Entrar a la demo');
    $this->withHeaders(['Accept-Language' => 'en-US'])->get('/login')->assertSee('Enter the demo');
});

it('closes the profile page (password, profile, two-factor) to the visitor but not to others', function () {
    ngDemoOn();
    $visitor = User::factory()->create(['email' => 'visitor@demo.example.com']);
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($visitor);
    expect(DemoAwareEditProfile::canAccess())->toBeFalse();
    $this->get('/profile')->assertForbidden();

    $this->actingAs($admin);
    expect(DemoAwareEditProfile::canAccess())->toBeTrue();
    $this->get('/profile')->assertOk();
});

it('leaves the profile open for everybody when demo mode is off', function () {
    $user = User::factory()->create(['email' => 'visitor@demo.example.com']);

    $this->actingAs($user)->get('/profile')->assertOk();
});

it('does not show the profile entry in the user menu of the visitor', function () {
    ngDemoOn();
    $visitor = User::factory()->create(['email' => 'visitor@demo.example.com']);

    $this->actingAs($visitor)->get('/')->assertDontSee('/profile"', false);
});

it('keeps the visitor read-only: cannot create projects, manage users or tokens', function () {
    ngDemoOn();
    app(DemoReset::class)->run();
    $visitor = User::where('email', 'visitor@demo.example.com')->first();

    $this->actingAs($visitor);
    $this->get('/users')->assertForbidden();
    $this->get('/projects/create')->assertForbidden();

    $project = Project::where('slug', 'demo-app')->first();
    $this->get('/projects/'.$project->id.'/edit')->assertForbidden();
});

// --- the reset ------------------------------------------------------------------------------------------------------

it('wipes everything but the administrator and rebuilds realistic data', function () {
    ngDemoOn();
    $admin = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    $stranger = User::factory()->superAdmin()->create(['email' => 'stranger@example.com']);
    $old = Project::factory()->create(['name' => 'Left over']);
    Issue::factory()->for($old)->create();
    Event::factory()->for($old)->create();

    $this->artisan('nightguardian:demo-reset')->assertSuccessful();

    expect(User::pluck('email')->sort()->values()->all())->toBe(['boss@example.com', 'visitor@demo.example.com'])
        ->and(User::where('email', 'boss@example.com')->value('id'))->toBe($admin->id)
        ->and(User::where('email', 'stranger@example.com')->exists())->toBeFalse()
        ->and(Project::where('name', 'Left over')->exists())->toBeFalse()
        ->and(Project::pluck('slug')->sort()->values()->all())->toBe(['demo-api', 'demo-app', 'demo-shop'])
        ->and(Issue::count())->toBe(15)
        ->and(Event::count())->toBeGreaterThan(30);
});

it('does not keep the well-known local demo accounts', function () {
    ngDemoOn();

    app(DemoReset::class)->run();

    expect(User::whereIn('email', ['owner@example.com', 'member@example.com', 'viewer@example.com'])->exists())->toBeFalse();
});

it('creates no alert channels, so the demo never sends e-mail', function () {
    ngDemoOn();

    app(DemoReset::class)->run();

    expect(AlertChannel::count())->toBe(0);
});

it('gives the Demo app project the token from the environment, again after every reset', function () {
    ngDemoOn();

    app(DemoReset::class)->run();
    ngPost(NG_DEMO_TOKEN, ['events' => [ngEvent()]])->assertStatus(202);
    expect(Event::where('project_id', Project::where('slug', 'demo-app')->value('id'))->count())->toBe(1);

    app(DemoReset::class)->run();
    expect(Event::where('project_id', Project::where('slug', 'demo-app')->value('id'))->count())->toBe(0);
    ngPost(NG_DEMO_TOKEN, ['events' => [ngEvent()]])->assertStatus(202);

    expect(ProjectToken::where('token_hash', ProjectToken::hash(NG_DEMO_TOKEN))->count())->toBe(1);
});

it('warns and generates a random token when no demo ingest token is configured', function () {
    ngDemoOn(['ingest_token' => null]);

    $this->artisan('nightguardian:demo-reset')->expectsOutputToContain('NIGHTGUARDIAN_DEMO_INGEST_TOKEN is not set')->assertSuccessful();

    expect(ProjectToken::count())->toBeGreaterThan(0);
});

it('keeps existing super admins when no administrator e-mail is configured', function () {
    ngDemoOn(['admin_email' => null]);
    User::factory()->superAdmin()->create(['email' => 'first@example.com']);
    User::factory()->create(['email' => 'regular@example.com']);

    app(DemoReset::class)->run();

    expect(User::pluck('email')->sort()->values()->all())->toBe(['first@example.com', 'visitor@demo.example.com']);
});

it('schedules the reset every day at 04:00, only in demo mode', function () {
    $commands = fn () => collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' @ '.$e->expression)->implode("\n");

    expect($commands())->not->toContain('demo-reset');

    // routes/console.php decides at boot: check it with the flag on.
    config(['nightguardian.demo.enabled' => true]);
    Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
    app()->forgetInstance(Schedule::class);
    require base_path('routes/console.php');

    expect($commands())->toContain('nightguardian:demo-reset')->toContain('0 4 * * *');
});

// --- the lower ingest limit --------------------------------------------------------------------------------------

it('caps the ingest of every project to the demo limit (120 events per minute by default)', function () {
    ngDemoOn();
    Cache::flush();
    [$project, $token] = ngProject(['rate_limit_per_minute' => 600]);

    $batch = fn (int $n) => ['events' => array_map(fn () => ngEvent(['id' => fake()->uuid()]), range(1, $n))];

    ngPost($token, $batch(100))->assertStatus(202);
    ngPost($token, $batch(20))->assertStatus(202);
    ngPost($token, $batch(1))->assertStatus(429)->assertHeader('Retry-After');
});

it('does not cap anything below the project limit when the demo is off', function () {
    Cache::flush();
    [$project, $token] = ngProject(['rate_limit_per_minute' => 600]);

    ngPost($token, ['events' => array_map(fn () => ngEvent(['id' => fake()->uuid()]), range(1, 100))])->assertStatus(202);
    ngPost($token, ['events' => array_map(fn () => ngEvent(['id' => fake()->uuid()]), range(1, 100))])->assertStatus(202);
});

it('honours a lower demo limit from the configuration', function () {
    ngDemoOn(['ingest_rate_limit_per_minute' => 5]);
    Cache::flush();
    [, $token] = ngProject(['rate_limit_per_minute' => 600]);

    ngPost($token, ['events' => array_map(fn () => ngEvent(['id' => fake()->uuid()]), range(1, 5))])->assertStatus(202);
    ngPost($token, ['events' => [ngEvent()]])->assertStatus(429);
});
