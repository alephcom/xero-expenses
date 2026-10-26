<?php

namespace Tests\Feature\Xero;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class XeroOAuthConnectExistingOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organisation::create([
            'name' => 'Existing Org',
            'slug' => 'existing-org',
            'xero_tenant_id' => (string) Str::uuid(),
            'oauth_batch_id' => 'admin-batch',
        ]);

        $this->admin = User::factory()->create();
        $this->organisation->users()->attach($this->admin->id, ['role' => OrganisationRole::Admin->value]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'access_token' => 'admin-access',
            'refresh_token' => 'admin-refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);
    }

    private function connectAs(User $user): void
    {
        $state = Str::random(40);

        $mock = Mockery::mock(XeroOAuthService::class);
        $mock->shouldReceive('exchangeCode')->once()->andReturn([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 1800,
        ]);
        $mock->shouldReceive('fetchConnections')->once()->andReturn([[
            'tenantId' => $this->organisation->xero_tenant_id,
            'tenantType' => 'ORGANISATION',
            'tenantName' => 'Renamed By Connector',
        ]]);
        $this->app->instance(XeroOAuthService::class, $mock);

        $this->actingAs($user)
            ->withSession(['xero_oauth_state' => $state, 'xero_oauth_user_id' => $user->id])
            ->get(route('xero.callback', ['code' => 'code', 'state' => $state]))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_non_admin_connecting_existing_tenant_leaves_organisation_untouched(): void
    {
        $outsider = User::factory()->create();

        $this->connectAs($outsider);

        $org = $this->organisation->fresh();
        $this->assertSame('admin-batch', $org->oauth_batch_id);
        $this->assertSame('Existing Org', $org->name);
        $this->assertFalse($outsider->isMemberOf($org));

        $connection = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->firstOrFail();
        $this->assertSame('admin-access', $connection->access_token);
        $this->assertSame('admin-refresh', $connection->refresh_token);
    }

    public function test_admin_reconnecting_existing_tenant_moves_it_to_the_new_batch(): void
    {
        $this->connectAs($this->admin);

        $org = $this->organisation->fresh();
        $this->assertNotSame('admin-batch', $org->oauth_batch_id);
        $this->assertSame('Renamed By Connector', $org->name);

        $connection = XeroConnection::withoutGlobalScopes()->where('organisation_id', $org->id)->firstOrFail();
        $this->assertSame('new-refresh', $connection->refresh_token);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
