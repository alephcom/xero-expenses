<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OrganisationRole;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Xero\XeroSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class XeroSsoController extends Controller
{
    public function redirect(Request $request, XeroSsoService $sso): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('xero_sso_state', $state);

        return redirect()->away($sso->authorizationUrl($state));
    }

    public function callback(Request $request, XeroSsoService $sso): RedirectResponse
    {
        $expected = $request->session()->pull('xero_sso_state');
        if ($expected === null || ! hash_equals((string) $expected, $request->string('state')->toString())) {
            abort(403, 'Invalid OAuth state.');
        }

        try {
            $tokens = $sso->exchangeCode($request->string('code')->toString());
        } catch (Throwable) {
            return redirect()->route('login')->withErrors(['xero' => __('Could not complete sign-in with Xero. Please try again.')]);
        }

        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            return redirect()->route('login')->withErrors(['xero' => __('Xero did not return an identity token. Check SSO scopes include openid.')]);
        }

        try {
            $claims = $sso->verifyIdToken($idToken);
        } catch (Throwable) {
            return redirect()->route('login')->withErrors(['xero' => __('Could not validate sign-in with Xero. Please try again.')]);
        }

        $user = User::query()->where('xero_sub', $claims['sub'])->first();

        if ($user === null) {
            $byEmail = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($claims['email'])])->first();
            if ($byEmail !== null) {
                if ($byEmail->xero_sub !== null && $byEmail->xero_sub !== $claims['sub']) {
                    return redirect()->route('login')->withErrors([
                        'xero' => __('This email is already linked to a different Xero account.'),
                    ]);
                }
                $user = $byEmail;
            }
        }

        if ($user === null) {
            if (! $this->canProvisionNewUserViaSso($request)) {
                return redirect()->route('login')->withErrors([
                    'xero' => __('New sign-ups are disabled for this application. Contact an administrator if you need access.'),
                ]);
            }

            $user = User::query()->create([
                'name' => $claims['name'],
                'email' => $claims['email'],
                'xero_sub' => $claims['sub'],
                'password' => null,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->forceFill([
                'name' => $claims['name'],
                'email' => $claims['email'],
                'xero_sub' => $claims['sub'],
                'email_verified_at' => now(),
            ])->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $invitationRedirect = $this->tryCompleteInvitationAfterSso($request, $user);
        if ($invitationRedirect !== null) {
            return $invitationRedirect;
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function canProvisionNewUserViaSso(Request $request): bool
    {
        if (config('services.xero.allow_open_signup')) {
            return true;
        }

        if (User::query()->count() === 0) {
            return true;
        }

        $plain = $request->session()->get('post_sso_invitation_plain');
        if (! is_string($plain) || $plain === '') {
            return false;
        }

        return Invitation::findValidByPlainToken($plain) !== null;
    }

    private function tryCompleteInvitationAfterSso(Request $request, User $user): ?RedirectResponse
    {
        $plain = $request->session()->pull('post_sso_invitation_plain');
        if (! is_string($plain) || $plain === '') {
            return null;
        }

        $invitation = Invitation::findValidByPlainToken($plain);
        if ($invitation === null) {
            return redirect()->route('dashboard')->with('status', __('That invitation is no longer valid.'));
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'xero' => __('Sign in with the Xero account that uses :email.', ['email' => $invitation->email]),
            ]);
        }

        $role = OrganisationRole::tryFrom($invitation->role) ?? OrganisationRole::Reviewer;
        $invitation->organisation->users()->syncWithoutDetaching([
            $user->id => ['role' => $role->value],
        ]);
        $invitation->forceFill(['accepted_at' => now()])->save();

        return redirect()
            ->route('dashboard')
            ->with('status', __('You joined :org.', ['org' => $invitation->organisation->name]));
    }
}
