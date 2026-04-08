<?php

namespace Tests\Feature\Auth;

use App\Livewire\Dashboard\ManageOrganisationTeam;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_org_invitation_from_dashboard(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        Livewire::actingAs($user)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->set('email', 'invitee@example.com')
            ->set('role', 'reviewer')
            ->call('sendInvitation')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(
            InvitationNotification::class,
            function (InvitationNotification $notification, array $channels, object $notifiable): bool {
                return in_array('mail', $channels, true)
                    && $notifiable->routes['mail'] === 'invitee@example.com'
                    && strlen($notification->plainToken) > 0;
            }
        );

        $this->assertDatabaseHas('invitations', [
            'email' => 'invitee@example.com',
            'organisation_id' => $org->id,
            'role' => 'reviewer',
            'invited_by' => $user->id,
        ]);
    }

    public function test_reviewer_cannot_send_org_invitation_from_dashboard(): void
    {
        Notification::fake();

        $reviewer = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($reviewer->id, ['role' => 'reviewer']);

        Livewire::actingAs($reviewer)
            ->test(ManageOrganisationTeam::class)
            ->set('organisationId', (string) $org->id)
            ->set('email', 'invitee@example.com')
            ->set('role', 'reviewer')
            ->call('sendInvitation')
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_invitee_can_accept_invitation_when_authenticated_with_matching_email(): void
    {
        $inviter = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($inviter->id, ['role' => 'admin']);

        $created = Invitation::createWithPlainToken([
            'email' => 'invitee@example.com',
            'organisation_id' => $org->id,
            'role' => 'reviewer',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDay(),
        ]);

        $token = $created['plain_token'];

        $invitee = User::factory()->create([
            'email' => 'invitee@example.com',
            'name' => 'Invited User',
        ]);

        $this->get(route('invitations.accept', ['token' => $token]))
            ->assertOk()
            ->assertSeeVolt('pages.auth.accept-invitation');

        Volt::actingAs($invitee)->test('pages.auth.accept-invitation', ['token' => $token])
            ->call('acceptAuthenticated')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue($invitee->fresh()->organisations()->where('organisations.id', $org->id)->exists());
        $this->assertSame('reviewer', $invitee->fresh()->organisations()->where('organisations.id', $org->id)->first()->pivot->role);
        $this->assertNotNull($created['invitation']->fresh()->accepted_at);
    }
}
