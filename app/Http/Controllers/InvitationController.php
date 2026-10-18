<?php

namespace App\Http\Controllers;

use App\Access\InvitationService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;

/**
 * Invitation acceptance page. Reached through a signed, expiring URL; the form posts back to the same URL.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $token): View
    {
        $this->useBrowserLocale($request);

        $invitation = $this->invitations->find($token);

        return view('invitations.accept', [
            'invitation' => $invitation,
            'action' => $request->fullUrl(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse|View
    {
        $this->useBrowserLocale($request);

        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            return view('invitations.accept', ['invitation' => null, 'action' => $request->fullUrl()]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [$invitation->email === null ? 'required' : 'nullable', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        try {
            $user = $this->invitations->accept(
                $invitation,
                $data['name'],
                (string) ($data['email'] ?? ''),
                $data['password'],
                App::getLocale(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => __('nightguardian.invitation.'.($e->getMessage() === 'email_taken' ? 'email_taken' : 'unavailable'))]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    private function useBrowserLocale(Request $request): void
    {
        App::setLocale($request->getPreferredLanguage(User::LOCALES) ?? config('app.locale'));
    }
}
