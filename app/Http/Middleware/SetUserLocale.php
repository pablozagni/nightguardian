<?php

namespace App\Http\Middleware;

use App\Demo\DemoMode;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && in_array($user->locale, User::LOCALES, true)) {
            App::setLocale($user->locale);
        } elseif (DemoMode::enabled()) {
            // Public demo: visitors have no account language yet, follow the browser.
            App::setLocale($request->getPreferredLanguage(User::LOCALES) ?? config('app.locale'));
        }

        return $next($request);
    }
}
