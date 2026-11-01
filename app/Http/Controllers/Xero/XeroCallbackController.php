<?php

namespace App\Http\Controllers\Xero;

use App\Http\Controllers\Auth\XeroSsoController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\XeroOAuthController;
use App\Services\Xero\XeroOAuthService;
use App\Services\Xero\XeroSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class XeroCallbackController extends Controller
{
    public function __invoke(Request $request, XeroSsoService $sso, XeroOAuthService $oauth): RedirectResponse
    {
        // Xero redirects back with ?error=access_denied (and no code) when the user cancels consent.
        if ($request->filled('error')) {
            $request->session()->forget(['xero_sso_state', 'xero_oauth_state', 'xero_oauth_user_id']);

            if (Auth::check()) {
                return redirect()->route('dashboard')->with('status', __('Xero connection was cancelled.'));
            }

            return redirect()->route('login')->withErrors(['xero' => __('Sign-in with Xero was cancelled.')]);
        }

        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $state = $request->string('state')->toString();

        $ssoExpected = $request->session()->get('xero_sso_state');
        if (is_string($ssoExpected) && $ssoExpected !== '' && hash_equals($ssoExpected, $state)) {
            return app(XeroSsoController::class)->callback($request, $sso);
        }

        $oauthExpected = $request->session()->get('xero_oauth_state');
        if (is_string($oauthExpected) && $oauthExpected !== '' && hash_equals($oauthExpected, $state)) {
            if (! Auth::check()) {
                abort(403, 'Invalid OAuth state.');
            }

            return app(XeroOAuthController::class)->callback($request, $oauth);
        }

        abort(403, 'Invalid OAuth state.');
    }
}
