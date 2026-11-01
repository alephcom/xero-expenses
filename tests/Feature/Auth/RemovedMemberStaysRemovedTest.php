<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganisationMembershipSource;
use App\Jobs\SyncXeroReviewerAccessJob;
use App\Livewire\Dashboard\ManageOrganisationTeam;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSsoOrganisationAccessService;
use App\Services\Xero\XeroSsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Admin removal and invitation acceptance must win over a Xero SSO reviewer sync that read
 * membership before its (slow) Xero Users call.
 */
class RemovedMemberStaysRemovedTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        XeroConnection::query()->create([
            'organisation_id' => $this->organisation->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create(['email' => 'member@example.com']);
        $this->organisation->users()->attach($this->admin->id, ['role' => 'admin']);
    }

    /**
     * @param  list<array<string, string>>  $xeroUsers
     */
    private function mockXeroUsersCall(array $xeroUsers, callable $duringCall): void
    {
        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturnUsing(function () use ($xeroUsers, $duringCall): array {
                $duringCall();

                return ['Users' => $xeroUsers];
            });
        $this->app->instance(XeroApiClient::class, $client);
    }

    public function test_removal_made_while_reviewer_sync_is_calling_xero_still_wins(): void
    {
        // Not a member when the sync starts, so it will consider attaching.
        $this->mockXeroUsersCall(
            [['EmailAddress' => 'member@example.com', 'OrganisationRole' => 'STANDARD']],
            function (): void {
                // The admin removes them (records the exclusion) while the sync is waiting on Xero.
                $this->organisation->users()->attach($this->member->id, ['role' => 'reviewer']);
                Livewire::actingAs($this->admin)
                    ->test(ManageOrganisationTeam::class)
                    ->set('organisationId', (string) $this->organisation->id)
                    ->call('removeMember', $this->member->id)
                    ->assertHasNoErrors();
            },
        );

        app(XeroSsoOrganisationAccessService::class)->syncReviewerAccessFromXero($this->member->fresh());

        $this->assertFalse($this->member->fresh()->isMemberOf($this->organisation));
    }

    public function test_invitation_accepted_while_reviewer_sync_is_calling_xero_is_not_revoked(): void
    {
        $this->organisation->users()->attach($this->member->id, [
            'role' => 'reviewer',
            'source' => OrganisationMembershipSource::XeroSso->value,
        ]);

        $created = Invitation::createWithPlainToken([
            'email' => 'member@example.com',
            'organisation_id' => $this->organisation->id,
            'role' => 'reviewer',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDay(),
        ]);

        // Xero no longer lists them, but they accept an invitation while the sync is waiting on Xero.
        $this->mockXeroUsersCall([], function () use ($created): void {
            $created['invitation']->fresh()->acceptFor($this->member);
        });

        app(XeroSsoOrganisationAccessService::class)->syncReviewerAccessFromXero($this->member->fresh());

        $membership = $this->organisation->users()->where('users.id', $this->member->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame(OrganisationMembershipSource::Invitation->value, $membership->pivot->source);
    }

    public function test_sso_sign_in_queues_reviewer_access_sync_instead_of_running_it_inline(): void
    {
        Queue::fake();

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldNotReceive('get');
        $this->app->instance(XeroApiClient::class, $client);

        $sso = Mockery::mock(XeroSsoService::class);
        $sso->shouldReceive('exchangeCode')->once()->andReturn(['id_token' => 'jwt']);
        $sso->shouldReceive('verifyIdToken')->once()->andReturn([
            'sub' => 'xero-sub-member',
            'email' => 'member@example.com',
            'name' => 'Member',
        ]);
        $this->app->instance(XeroSsoService::class, $sso);

        $this->withSession(['xero_sso_state' => 'state'])
            ->get(route('xero.callback', ['code' => 'code', 'state' => 'state']))
            ->assertRedirect(route('dashboard', absolute: false));

        Queue::assertPushed(SyncXeroReviewerAccessJob::class, fn (SyncXeroReviewerAccessJob $job): bool => $job->userId === $this->member->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
