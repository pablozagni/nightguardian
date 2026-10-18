<?php

namespace App\Demo;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The public demo mode switch (config/nightguardian.php → demo). Everything it controls is off by default.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('nightguardian.demo.enabled', false);
    }

    public static function visitorEmail(): string
    {
        return (string) config('nightguardian.demo.visitor_email');
    }

    public static function visitorPassword(): string
    {
        return (string) config('nightguardian.demo.visitor_password');
    }

    /**
     * The shared read-only account of the public demo (only while the mode is on).
     */
    public static function isVisitor(?Authenticatable $user): bool
    {
        return self::enabled()
            && $user !== null
            && strcasecmp((string) ($user->email ?? ''), self::visitorEmail()) === 0;
    }
}
