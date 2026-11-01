<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrganisationRole;
use App\Jobs\SyncXeroOrganisationJob;
use App\Models\Organisation;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSyncService;
use App\Support\OrganisationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrganisationContextIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function organisationWithConnection(string $name, ?string $batchId = null, bool $expired = false): Organisation
    {
        $org = Organisation::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'xero_tenant_id' => (string) Str::uuid(),
            'oauth_batch_id' => $batchId,
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'access-'.$org->id,
            'refresh_token' => 'refresh-'.$org->id,
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        return $org;
    }

    public function test_api_client_finds_connection_while_another_organisation_is_in_context(): void
    {
        $orgA = $this->organisationWithConnection('Org A');
        $orgB = $this->organisationWithConnection('Org B');

        OrganisationContext::set($orgA->id);

        Http::fake(['*' => Http::response(['Organisations' => []])]);

        app(XeroApiClient::class)->get($orgB->fresh(), 'Organisation');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('xero-tenant-id', $orgB->xero_tenant_id)
            && $request->hasHeader('Authorization', 'Bearer access-'.$orgB->id));
    }

    public function test_token_refresh_during_scoped_sync_updates_batch_peer_connections(): void
    {
        $batchId = (string) Str::uuid();
        $orgA = $this->organisationWithConnection('Org A', $batchId, expired: true);
        $orgB = $this->organisationWithConnection('Org B', $batchId);

        OrganisationContext::set($orgA->id);

        Http::fake([
            'identity.xero.com/*' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
            'api.xero.com/*' => Http::response(['Organisations' => []]),
        ]);

        app(XeroApiClient::class)->get($orgA->fresh(), 'Organisation');

        $peer = XeroConnection::withoutGlobalScopes()->where('organisation_id', $orgB->id)->firstOrFail();
        $this->assertSame('new-access', $peer->access_token);
        $this->assertSame('new-refresh', $peer->refresh_token);
    }

    public function test_sync_job_clears_organisation_context_when_done(): void
    {
        [$org, $user] = $this->organisationWithAdmin();

        $sync = Mockery::mock(XeroSyncService::class);
        $sync->shouldReceive('sync')->once();

        (new SyncXeroOrganisationJob($org->id, '2026-01-01', '2026-01-31', $user->id))->handle($sync);

        $this->assertNull(OrganisationContext::id());
    }

    public function test_sync_job_clears_organisation_context_when_sync_throws(): void
    {
        [$org, $user] = $this->organisationWithAdmin();

        $sync = Mockery::mock(XeroSyncService::class);
        $sync->shouldReceive('sync')->once()->andThrow(new RuntimeException('Xero down'));

        try {
            (new SyncXeroOrganisationJob($org->id, '2026-01-01', '2026-01-31', $user->id))->handle($sync);
            $this->fail('Expected the sync exception to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull(OrganisationContext::id());
    }

    public function test_queued_jobs_start_without_a_leftover_organisation_context(): void
    {
        OrganisationContext::set(12345);

        dispatch(function (): void {
            Cache::put('context_seen_by_job', OrganisationContext::id() ?? 'none');
        });

        $this->assertSame('none', Cache::get('context_seen_by_job'));
    }

    /**
     * @return array{0: Organisation, 1: User}
     */
    private function organisationWithAdmin(): array
    {
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => OrganisationRole::Admin->value]);

        return [$org, $user];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
