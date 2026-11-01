<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\ManageOrganisationTeam;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OrganisationTeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_cannot_resend_invitation(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer']);

        $created = Invitation::createWithPlainToken([
            'email' => 'pending@example.com',
            'organisation_id' => $org->id,
            'role' => 'reviewer',
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        Livewire::actingAs($reviewer)
            ->test(ManageOrganisationTeam::class)
            ->call('resendInvitation', $created['invitation']->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_admin_can_resend_pending_invitation(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);

        $created = Invitation::createWithPlainToken([
            'email' => 'pending@example.com',
            'organisation_id' => $org->id,
            'role' => 'reviewer',
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $oldHash = $created['invitation']->token_hash;

        Livewire::actingAs($admin)
            ->test(ManageOrganisationTeam::class)
            ->call('resendInvitation', $created['invitation']->id)
            ->assertHasNoErrors();

        $this->assertNotSame($oldHash, $created['invitation']->fresh()->token_hash);

        Notification::assertSentOnDemand(
            InvitationNotification::class,
            function (InvitationNotification $notification, array $channels, object $notifiable): bool {
                return in_array('mail', $channels, true)
                    && $notifiable->routes['mail'] === 'pending@example.com'
                    && strlen($notification->plainToken) > 0;
            }
        );
    }

    public function test_admin_can_revoke_pending_invitation(): void
    {
        $admin = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);

        $created = Invitation::createWithPlainToken([
            'email' => 'gone@example.com',
            'organisation_id' => $org->id,
            'role' => 'reviewer',
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $id = $created['invitation']->id;

        Livewire::actingAs($admin)
            ->test(ManageOrganisationTeam::class)
            ->call('revokeInvitation', $id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('invitations', ['id' => $id]);
    }

    public function test_admin_cannot_demote_last_admin(): void
    {
        $soleAdmin = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($soleAdmin->id, ['role' => 'admin']);

        Livewire::actingAs($soleAdmin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('updateMemberRole', $soleAdmin->id, 'reviewer')
            ->assertHasErrors('team');
    }

    public function test_admin_can_demote_peer_admin_when_two_admins(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($adminA->id, ['role' => 'admin']);
        $org->users()->attach($adminB->id, ['role' => 'admin']);

        Livewire::actingAs($adminA)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('updateMemberRole', $adminB->id, 'reviewer')
            ->assertHasNoErrors();

        $this->assertSame('reviewer', $org->users()->where('users.id', $adminB->id)->first()->pivot->role);
    }

    public function test_admin_can_promote_reviewer_to_admin(): void
    {
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer']);

        Livewire::actingAs($admin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('updateMemberRole', $reviewer->id, 'admin')
            ->assertHasNoErrors();

        $this->assertSame('admin', $org->users()->where('users.id', $reviewer->id)->first()->pivot->role);
    }

    public function test_admin_cannot_remove_last_admin(): void
    {
        $soleAdmin = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($soleAdmin->id, ['role' => 'admin']);

        Livewire::actingAs($soleAdmin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('removeMember', $soleAdmin->id)
            ->assertHasErrors('team');

        $this->assertTrue($org->users()->where('users.id', $soleAdmin->id)->exists());
    }

    public function test_admin_can_remove_reviewer(): void
    {
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer']);

        Livewire::actingAs($admin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('removeMember', $reviewer->id)
            ->assertHasNoErrors();

        $this->assertFalse($org->users()->where('users.id', $reviewer->id)->exists());
        $this->assertDatabaseHas('organisation_access_exclusions', [
            'organisation_id' => $org->id,
            'user_id' => $reviewer->id,
            'excluded_by' => $admin->id,
        ]);
    }

    public function test_role_change_takes_member_out_of_xero_sso_management(): void
    {
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer', 'source' => 'xero_sso']);

        Livewire::actingAs($admin)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('updateMemberRole', $reviewer->id, 'admin')
            ->assertHasNoErrors();

        $this->assertSame('manual', $org->users()->where('users.id', $reviewer->id)->first()->pivot->source);
    }

    public function test_reviewer_cannot_change_member_role(): void
    {
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer']);

        Livewire::actingAs($reviewer)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->call('updateMemberRole', $reviewer->id, 'admin')
            ->assertForbidden();
    }
}
