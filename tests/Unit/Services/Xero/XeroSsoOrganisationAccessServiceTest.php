<?php

namespace Tests\Unit\Services\Xero;

use App\Models\Organisation;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSsoOrganisationAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class XeroSsoOrganisationAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_attaches_reviewer_when_email_matches_and_role_is_standard(): void
    {
        $org = $this->makeOrgWithConnection();
        $user = User::factory()->create(['email' => 'match@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with(Mockery::on(fn ($o) => $o->is($org)), 'Users')
            ->andReturn([
                'Users' => [
                    ['EmailAddress' => 'match@example.com', 'OrganisationRole' => 'STANDARD'],
                ],
            ]);

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertTrue($user->organisations()->whereKey($org->id)->exists());
        $this->assertSame('reviewer', $user->organisations()->whereKey($org->id)->first()->pivot->role);
    }

    public function test_does_not_attach_for_readonly_role(): void
    {
        $org = $this->makeOrgWithConnection();
        $user = User::factory()->create(['email' => 'u@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'Users' => [
                    ['EmailAddress' => 'u@example.com', 'OrganisationRole' => 'READONLY'],
                ],
            ]);

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertFalse($user->organisations()->whereKey($org->id)->exists());
    }

    public function test_does_not_attach_for_invoiceonly_role(): void
    {
        $org = $this->makeOrgWithConnection();
        $user = User::factory()->create(['email' => 'u@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'Users' => [
                    ['EmailAddress' => 'u@example.com', 'OrganisationRole' => 'INVOICEONLY'],
                ],
            ]);

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertFalse($user->organisations()->whereKey($org->id)->exists());
    }

    public function test_does_not_change_existing_member_role(): void
    {
        $org = $this->makeOrgWithConnection();
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldNotReceive('get');

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertSame('admin', $user->organisations()->whereKey($org->id)->first()->pivot->role);
    }

    public function test_attaches_to_multiple_organisations_when_both_match(): void
    {
        $orgA = $this->makeOrgWithConnection('tenant-a');
        $orgB = $this->makeOrgWithConnection('tenant-b');
        $user = User::factory()->create(['email' => 'multi@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->twice()
            ->andReturnUsing(function ($organisation) {
                return [
                    'Users' => [
                        ['EmailAddress' => 'multi@example.com', 'OrganisationRole' => 'FINANCIALADVISER'],
                    ],
                ];
            });

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertTrue($user->organisations()->whereKey($orgA->id)->exists());
        $this->assertTrue($user->organisations()->whereKey($orgB->id)->exists());
    }

    public function test_continues_when_one_org_request_fails(): void
    {
        $orgFail = $this->makeOrgWithConnection('fail-tenant');
        $orgOk = $this->makeOrgWithConnection('ok-tenant');
        $user = User::factory()->create(['email' => 'resilient@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->twice()
            ->andReturnUsing(function ($organisation) use ($orgFail, $user) {
                if ($organisation->is($orgFail)) {
                    throw new RuntimeException('upstream error');
                }

                return [
                    'Users' => [
                        ['EmailAddress' => $user->email, 'OrganisationRole' => 'MANAGEDCLIENT'],
                    ],
                ];
            });

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertFalse($user->organisations()->whereKey($orgFail->id)->exists());
        $this->assertTrue($user->organisations()->whereKey($orgOk->id)->exists());
    }

    public function test_no_op_when_config_disabled(): void
    {
        config(['services.xero.sso_auto_reviewer_access' => false]);

        $this->makeOrgWithConnection();
        $user = User::factory()->create(['email' => 'u@example.com']);

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldNotReceive('get');

        $this->app->instance(XeroApiClient::class, $client);

        $this->makeService()->syncReviewerAccessFromXero($user);

        $this->assertSame(0, $user->organisations()->count());
    }

    private function makeService(): XeroSsoOrganisationAccessService
    {
        return new XeroSsoOrganisationAccessService($this->app->make(XeroApiClient::class));
    }

    private function makeOrgWithConnection(string $tenantSuffix = ''): Organisation
    {
        $tenant = $tenantSuffix !== '' ? $tenantSuffix : (string) Str::uuid();

        $org = Organisation::query()->create([
            'name' => 'Org '.$tenant,
            'slug' => Str::slug('org-'.$tenant).'-'.Str::lower(Str::random(4)),
            'xero_tenant_id' => $tenant,
        ]);

        XeroConnection::query()->create([
            'organisation_id' => $org->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
            'scopes' => 'offline_access',
        ]);

        return $org->fresh();
    }
}
