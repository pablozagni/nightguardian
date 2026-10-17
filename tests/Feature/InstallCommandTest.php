<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a super admin from flags', function () {
    $this->artisan('nightguardian:install', [
        '--name' => 'Ada',
        '--email' => 'ada@example.com',
        '--password' => 'correct-horse-battery',
    ])->assertSuccessful();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->locale)->toBe('en');
});

it('rejects a short password', function () {
    $this->artisan('nightguardian:install', [
        '--name' => 'Ada',
        '--email' => 'ada@example.com',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});

it('rejects a duplicated e-mail', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('nightguardian:install', [
        '--name' => 'Ada',
        '--email' => 'ada@example.com',
        '--password' => 'correct-horse-battery',
    ])->assertFailed();
});
