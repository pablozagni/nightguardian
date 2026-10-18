<?php

namespace App\Filament\Pages\Auth;

use App\Demo\DemoMode;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * The panel's login page. Identical to Filament's unless the public demo mode is on: then the visitor's credentials are
 * prefilled (and shown above the form) and an "Enter the demo" button signs the visitor in with one click.
 */
class DemoLogin extends Login
{
    public function mount(): void
    {
        parent::mount();

        if (DemoMode::enabled()) {
            $this->form->fill(['email' => DemoMode::visitorEmail(), 'password' => DemoMode::visitorPassword()]);
        }
    }

    protected function getFormActions(): array
    {
        $actions = parent::getFormActions();

        if (DemoMode::enabled()) {
            $actions[] = Action::make('enterDemo')
                ->label(__('nightguardian.demo.enter'))
                ->color('gray')
                ->action(fn (): ?LoginResponse => $this->enterDemo());
        }

        return $actions;
    }

    public function enterDemo(): ?LoginResponse
    {
        $this->rateLimit(5);

        $visitor = DemoMode::enabled() ? User::query()->where('email', DemoMode::visitorEmail())->first() : null;

        if ($visitor === null) {
            Notification::make()->danger()->title(__('nightguardian.demo.unavailable'))->send();

            return null;
        }

        Filament::auth()->login($visitor);
        session()->regenerate();

        return app(LoginResponse::class);
    }
}
