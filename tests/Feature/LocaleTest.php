<?php

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('applies the locale of the authenticated user', function () {
    $user = User::factory()->create(['locale' => 'es']);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    (new SetUserLocale)->handle($request, fn () => response('ok'));

    expect(app()->getLocale())->toBe('es');
});

it('ignores unsupported locales', function () {
    $user = User::factory()->create(['locale' => 'xx']);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    (new SetUserLocale)->handle($request, fn () => response('ok'));

    expect(app()->getLocale())->toBe('en');
});

it('serves the login page in the app locale', function () {
    app()->setLocale('es');

    $this->get('/login')->assertOk();
});
