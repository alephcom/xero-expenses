<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationMembershipSource;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class XeroOAuthController extends Controller
{
    public function redirect(Request $request, XeroOAuthService $oauth): RedirectResponse
    {
        $this->authorize('connect-xero');

        $state = Str::random(40);
        $request->session()->put('xero_oauth_state', $state);
        $request->session()->put('xero_oauth_user_id', $request->user()->id);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, XeroOAuthService $oauth): RedirectResponse
    {
        $expected = $request->session()->pull('xero_oauth_state');
        if (! is_string($expected) || ! hash_equals($expected, $request->string('state')->toString())) {
            abort(403, 'Invalid OAuth state.');
        }

        $expectedUserId = $request->session()->pull('xero_oauth_user_id');
        if ($expectedUserId === null || (int) $expectedUserId !== (int) $request->user()->id) {
            abort(403, 'Invalid OAuth state.');
        }

        try {
            $tokens = $oauth->exchangeCode($request->string('code')->toString());
            $connections = $oauth->fetchConnections($tokens['access_token']);
        } catch (Throwable $e) {
            Log::warning('Xero connect token exchange failed', ['message' => $e->getMessage()]);

            return redirect()->route('dashboard')->with('status', __('Could not connect to Xero. Please try again.'));
        }
        $batchId = (string) Str::uuid();

        $linked = false;
        $skippedExisting = false;
        $user = $request->user();

        foreach ($connections as $tenant) {
            if (($tenant['tenantType'] ?? '') !== 'ORGANISATION') {
                continue;
            }

            $org = Organisation::firstOrCreate(
                ['xero_tenant_id' => $tenant['tenantId']],
                [
                    'name' => $tenant['tenantName'] ?? 'Xero organisation',
                    'slug' => $this->uniqueSlug($tenant['tenantName'] ?? 'organisation'),
                    'oauth_batch_id' => $batchId,
                ]
            );

            $wasNew = $org->wasRecentlyCreated;

            if (! $wasNew) {
                $org->update([
                    'name' => $tenant['tenantName'] ?? $org->name,
                    'oauth_batch_id' => $batchId,
                ]);
            }

            if ($wasNew) {
                $linked = true;
                $org->users()->syncWithoutDetaching([
                    $user->id => ['role' => OrganisationRole::Admin->value, 'source' => OrganisationMembershipSource::XeroConnect->value],
                ]);

                XeroConnection::withoutGlobalScopes()->updateOrCreate(
                    ['organisation_id' => $org->id],
                    [
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'],
                        'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 1800)),
                        'token_type' => $tokens['token_type'] ?? 'Bearer',
                        'scopes' => $tokens['scope'] ?? implode(' ', config('services.xero.scopes')),
                    ]
                );

                continue;
            }

            if (Gate::forUser($user)->denies('manageXeroConnection', $org)) {
                $skippedExisting = true;

                continue;
            }

            $linked = true;
            if (! $org->users()->where('users.id', $user->id)->exists()) {
                $org->users()->attach($user->id, [
                    'role' => OrganisationRole::Admin->value,
                    'source' => OrganisationMembershipSource::XeroConnect->value,
                ]);
            } else {
                $org->users()->syncWithoutDetaching([$user->id]);
            }

            XeroConnection::withoutGlobalScopes()->updateOrCreate(
                ['organisation_id' => $org->id],
                [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 1800)),
                    'token_type' => $tokens['token_type'] ?? 'Bearer',
                    'scopes' => $tokens['scope'] ?? implode(' ', config('services.xero.scopes')),
                ]
            );
        }

        if (! $linked && $skippedExisting) {
            return redirect()->route('dashboard')->with('status', __('Only organisation admins can refresh the Xero connection for organisations that are already linked.'));
        }

        if (! $linked) {
            return redirect()->route('dashboard')->with('status', __('No Xero organisations were returned for this account. Check app permissions and try again.'));
        }

        return redirect()->route('dashboard')->with('status', __('Xero organisations connected.'));
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'organisation';
        $candidate = $slug;
        for ($i = 0; $i < 30; $i++) {
            if (! Organisation::where('slug', $candidate)->exists()) {
                return $candidate;
            }
            $candidate = $slug.'-'.Str::lower(Str::random(4));
        }

        return Str::lower(Str::random(12));
    }
}
