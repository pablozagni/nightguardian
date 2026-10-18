<?php

namespace App\Filament\Pages\Auth;

use App\Demo\DemoMode;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Support\Facades\Auth;

/**
 * Filament's profile page (name, e-mail, password, two-factor authentication), closed to the shared demo visitor.
 */
class DemoAwareEditProfile extends EditProfile
{
    public static function canAccess(): bool
    {
        return ! DemoMode::isVisitor(Auth::user());
    }
}
