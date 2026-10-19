<?php

namespace App\Providers\Filament;

use App\Demo\DemoMode;
use App\Filament\Pages\Auth\DemoAwareEditProfile;
use App\Filament\Pages\Auth\DemoLogin;
use App\Filament\Widgets\ProjectCards;
use App\Http\Middleware\SetUserLocale;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(DemoLogin::class)
            ->profile(DemoAwareEditProfile::class)
            // Public demo mode (off by default): login credentials, a permanent banner, no profile for the visitor.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => DemoMode::enabled() ? view('filament.demo-login')->render() : '')
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => DemoMode::enabled() ? view('filament.demo-banner')->render() : '')
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label(fn (): string => Filament::getUserName(Filament::auth()->user()))
                    ->url(fn (): string => Filament::getProfileUrl())
                    ->icon(Heroicon::UserCircle)
                    ->visible(fn (): bool => ! DemoMode::isVisitor(Filament::auth()->user())),
            ])
            ->brandName('NightGuardian')
            ->sidebarWidth('15rem')
            ->collapsedSidebarWidth('9rem')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->unsavedChangesAlerts()
            ->darkMode()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([ProjectCards::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetUserLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
