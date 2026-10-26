<?php

namespace Tests\Unit\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroApiClientTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_token_is_refreshed_and_shared_with_batch_peers(): void
    {
        $batchId = (string) Str::uuid();
        $org = $this->organisationWithConnection($batchId, 'old-access', 'old-refresh', now()->subMinute());
        $peer = $this->organisationWithConnection($batchId, 'old-access', 'old-refresh', now()->subMinute());

        Http::fake([
            'identity.xero.com/*' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
            'api.xero.com/*' => Http::response(['Accounts' => []]),
        ]);

        app(XeroApiClient::class)->get($org, 'Accounts');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'api.xero.com')
            && $r->hasHeader('Authorization', 'Bearer new-access'));
        $this->assertSame('new-refresh', $peer->xeroConnection()->first()->refresh_token);
    }

    public function test_refresh_is_skipped_when_another_process_already_rotated_the_token(): void
    {
        $org = $this->organisationWithConnection(null, 'old-access', 'old-refresh', now()->subMinute());

        // Simulate a concurrent worker rotating the token after this request loaded the connection.
        $org->load('xeroConnection');
        XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->first()->forceFill([
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_at' => now()->addMinutes(30),
        ])->save();

        Http::fake([
            'identity.xero.com/*' => Http::response(['error' => 'invalid_grant'], 400),
            'api.xero.com/*' => Http::response(['Accounts' => []]),
        ]);

        app(XeroApiClient::class)->get($org, 'Accounts');

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'identity.xero.com'));
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer rotated-access'));
    }

    private function organisationWithConnection(?string $batchId, string $access, string $refresh, \DateTimeInterface $expiresAt): Organisation
    {
        $org = Organisation::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(6)),
            'xero_tenant_id' => (string) Str::uuid(),
            'oauth_batch_id' => $batchId,
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => $expiresAt,
            'token_type' => 'Bearer',
        ]);

        return $org;
    }
}
