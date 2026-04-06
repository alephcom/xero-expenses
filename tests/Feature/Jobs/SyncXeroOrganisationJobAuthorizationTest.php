<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncXeroOrganisationJob;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Xero\XeroSyncService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SyncXeroOrganisationJobAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_sync_when_initiating_user_is_not_org_member(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $sync = Mockery::mock(XeroSyncService::class);
        $sync->shouldNotReceive('sync');

        $job = new SyncXeroOrganisationJob(
            $org->id,
            '2026-01-01',
            '2026-01-31',
            $user->id,
        );

        $this->expectException(AuthorizationException::class);
        $job->handle($sync);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
