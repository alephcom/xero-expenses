<?php

namespace Tests\Feature\Auth;

use App\Jobs\SyncXeroReviewerAccessJob;
use App\Livewire\Dashboard\ManageOrganisationTeam;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMemberRemoval;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSsoOrganisationAccessService;
use App\Services\Xero\XeroSsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class RemovedMemberStaysRemovedTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $admin;

    private User $reviewer;

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
        $this->reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
        $this->organisation->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->organisation->users()->attach($this->reviewer->id, ['role' => 'reviewer']);
    }

    private function removeReviewer(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $this->organisation->id)
            ->call('removeMember', $this->reviewer->id)
            ->assertHasNoErrors();
    }

    public function test_removing_a_member_records_the_removal(): void
    {
        $this->removeReviewer();

        $this->assertDatabaseHas('organisation_member_removals', [
            'organisation_id' => $this->organisation->id,
            'user_id' => $this->reviewer->id,
            'removed_by' => $this->admin->id,
        ]);
    }

    public function test_xero_reviewer_sync_does_not_re_add_a_removed_member(): void
    {
        $this->removeReviewer();

        $client = Mockery::mock(XeroApiClient::class);
        $client->shouldNotReceive('get');
        $this->app->instance(XeroApiClient::class, $client);

        app(XeroSsoOrganisationAccessService::class)->syncReviewerAccessFromXero($this->reviewer->fresh());

        $this->assertFalse($this->reviewer->fresh()->isMemberOf($this->organisation));
    }

    public function test_accepting_a_new_invitation_clears_the_removal(): void
    {
        $this->removeReviewer();

        $created = Invitation::createWithPlainToken([
            'email' => 'reviewer@example.com',
            'organisation_id' => $this->organisation->id,
            'role' => 'reviewer',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDay(),
        ]);

        Volt::actingAs($this->reviewer)
            ->test('pages.auth.accept-invitation', ['token' => $created['plain_token']])
            ->call('acceptAuthenticated')
            ->assertHasNoErrors();

        $this->assertTrue($this->reviewer->fresh()->isMemberOf($this->organisation));
        $this->assertSame(0, OrganisationMemberRemoval::query()->count());
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
            'sub' => 'xero-sub-reviewer',
            'email' => 'reviewer@example.com',
            'name' => 'Reviewer',
        ]);
        $this->app->instance(XeroSsoService::class, $sso);

        $this->withSession(['xero_sso_state' => 'state'])
            ->get(route('xero.callback', ['code' => 'code', 'state' => 'state']))
            ->assertRedirect(route('dashboard', absolute: false));

        Queue::assertPushed(SyncXeroReviewerAccessJob::class, fn (SyncXeroReviewerAccessJob $job): bool => $job->userId === $this->reviewer->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
