<?php

namespace Tests\Unit\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function organisationWithConnection(string $batchId, bool $expired): Organisation
    {
        $org = Organisation::create([
            'name' => 'Token Org',
            'slug' => 'token-org',
            'xero_tenant_id' => (string) Str::uuid(),
            'oauth_batch_id' => $batchId,
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        return $org;
    }

    private function fakeTokenEndpoint(): void
    {
        Http::fake([
            'identity.xero.com/*' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
            'api.xero.com/*' => Http::response(['Organisations' => []]),
        ]);
    }

    private function tokenRequestCount(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'identity.xero.com'))->count();
    }

    public function test_skips_refresh_when_another_process_already_rotated_the_token(): void
    {
        $org = $this->organisationWithConnection('batch-1', expired: true);
        $staleConnection = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->firstOrFail();

        // Another worker refreshed while this one waited for the lock.
        XeroConnection::withoutGlobalScopes()->whereKey($staleConnection->id)->firstOrFail()->forceFill([
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_at' => now()->addMinutes(30),
        ])->save();

        $this->fakeTokenEndpoint();

        app(XeroOAuthService::class)->refreshConnectionTokens($staleConnection, 'old-access');

        $this->assertSame(0, $this->tokenRequestCount());
        $this->assertSame('rotated-refresh', $staleConnection->fresh()->refresh_token);
    }

    public function test_refreshes_when_stored_token_is_still_the_stale_one(): void
    {
        $org = $this->organisationWithConnection('batch-1', expired: true);
        $connection = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->firstOrFail();

        $this->fakeTokenEndpoint();

        app(XeroOAuthService::class)->refreshConnectionTokens($connection, 'old-access');

        $this->assertSame(1, $this->tokenRequestCount());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'identity.xero.com')
            && $request['refresh_token'] === 'old-refresh');
        $this->assertSame('new-refresh', $connection->fresh()->refresh_token);
    }

    public function test_unauthorised_response_retries_with_token_rotated_by_another_process(): void
    {
        $org = $this->organisationWithConnection('batch-1', expired: false);
        $connectionId = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->value('id');

        Http::fake([
            'identity.xero.com/*' => Http::response(['access_token' => 'unexpected', 'refresh_token' => 'unexpected', 'expires_in' => 1800]),
            'api.xero.com/*' => function (Request $request) use ($connectionId) {
                if ($request->hasHeader('Authorization', 'Bearer old-access')) {
                    // Simulate a concurrent worker rotating the token while this request was in flight.
                    XeroConnection::withoutGlobalScopes()->whereKey($connectionId)->firstOrFail()->forceFill([
                        'access_token' => 'rotated-access',
                        'refresh_token' => 'rotated-refresh',
                        'expires_at' => now()->addMinutes(30),
                    ])->save();

                    return Http::response(['Title' => 'Unauthorized'], 401);
                }

                return Http::response(['Organisations' => []]);
            },
        ]);

        app(XeroApiClient::class)->get($org->fresh(), 'Organisation');

        $this->assertSame(0, $this->tokenRequestCount());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer rotated-access'));
        $this->assertSame('rotated-refresh', XeroConnection::withoutGlobalScopes()->findOrFail($connectionId)->refresh_token);
    }

    public function test_refresh_holds_a_lock_shared_by_the_oauth_batch(): void
    {
        $org = $this->organisationWithConnection('batch-1', expired: true);
        $connection = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->firstOrFail();

        $lockWasHeld = false;
        Http::fake([
            'identity.xero.com/*' => function () use (&$lockWasHeld) {
                $probe = Cache::lock('xero_token_refresh:batch-1', 5);
                $lockWasHeld = ! $probe->get();
                if (! $lockWasHeld) {
                    $probe->release();
                }

                return Http::response([
                    'access_token' => 'new-access',
                    'refresh_token' => 'new-refresh',
                    'expires_in' => 1800,
                ]);
            },
        ]);

        app(XeroOAuthService::class)->refreshConnectionTokens($connection, 'old-access');

        $this->assertTrue($lockWasHeld);
        $this->assertTrue(Cache::lock('xero_token_refresh:batch-1', 5)->get(), 'Lock should be released after refresh.');
    }
}
