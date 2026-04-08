<?php

namespace App\Livewire\Dashboard;

use App\Enums\OrganisationRole;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ManageOrganisationTeam extends Component
{
    use AuthorizesRequests;

    public string $organisationId = '';

    public string $email = '';

    public string $role = 'reviewer';

    public ?string $successMessage = null;

    public function mount(): void
    {
        $first = $this->adminOrganisations()->first();
        if ($first !== null) {
            $this->organisationId = (string) $first->id;
        }
    }

    public function updatedOrganisationId(): void
    {
        $this->successMessage = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $organisation = $this->resolvedOrganisation();

        return view('livewire.dashboard.manage-organisation-team', [
            'adminOrganisations' => $this->adminOrganisations(),
            'organisation' => $organisation,
            'invitations' => $organisation !== null
                ? $organisation->invitations()->with('invitedBy')->latest()->get()
                : collect(),
            'members' => $organisation !== null
                ? $organisation->users()->orderBy('name')->get()
                : collect(),
        ]);
    }

    /**
     * @return Collection<int, Organisation>
     */
    private function adminOrganisations(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->organisations()
            ->wherePivot('role', OrganisationRole::Admin->value)
            ->orderBy('name')
            ->get();
    }

    private function resolvedOrganisation(): ?Organisation
    {
        if ($this->organisationId === '') {
            return null;
        }

        $org = Organisation::query()->find((int) $this->organisationId);
        if ($org === null) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();
        if (! $user->isOrganisationAdmin($org)) {
            return null;
        }

        return $org;
    }

    private function otherAdminCount(Organisation $organisation, int $excludingUserId): int
    {
        return $organisation->users()
            ->wherePivot('role', OrganisationRole::Admin->value)
            ->where('users.id', '!=', $excludingUserId)
            ->count();
    }

    public function canDemoteMember(?Organisation $organisation, User $member): bool
    {
        if ($organisation === null) {
            return false;
        }
        if ((string) $member->pivot->role !== OrganisationRole::Admin->value) {
            return false;
        }

        return $this->otherAdminCount($organisation, $member->id) >= 1;
    }

    public function canRemoveMember(?Organisation $organisation, User $member): bool
    {
        if ($organisation === null) {
            return false;
        }
        if ((string) $member->pivot->role !== OrganisationRole::Admin->value) {
            return true;
        }

        return $this->otherAdminCount($organisation, $member->id) >= 1;
    }

    public function sendInvitation(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $this->validate([
            'organisationId' => ['required', 'string'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:admin,reviewer'],
        ]);

        $organisation = Organisation::query()->find((int) $validated['organisationId']);
        if ($organisation === null) {
            $this->addError('organisationId', __('Choose an organisation you administer.'));

            return;
        }

        $this->authorize('invite', $organisation);

        $email = $validated['email'];

        if (strtolower($email) === strtolower($user->email)) {
            $this->addError('email', __('You cannot invite your own email address.'));

            return;
        }

        $existingMember = $organisation->users()
            ->whereRaw('LOWER(users.email) = ?', [strtolower($email)])
            ->exists();
        if ($existingMember) {
            $this->addError('email', __('Someone with this email is already a member of this organisation.'));

            return;
        }

        Invitation::query()
            ->where('organisation_id', $organisation->id)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->whereNull('accepted_at')
            ->delete();

        $created = Invitation::createWithPlainToken([
            'email' => $email,
            'organisation_id' => $organisation->id,
            'role' => $validated['role'],
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)
            ->notify(new InvitationNotification($created['plain_token']));

        $this->reset('email');
        $this->role = 'reviewer';
        $this->successMessage = __('Invitation sent to :email.', ['email' => $email]);
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = Invitation::query()->find($invitationId);
        if ($invitation === null) {
            $this->addError('team', __('That invitation was not found.'));

            return;
        }

        $organisation = Organisation::query()->find($invitation->organisation_id);
        if ($organisation === null) {
            return;
        }

        $this->authorize('invite', $organisation);

        if ($invitation->accepted_at !== null) {
            $this->addError('team', __('That invitation cannot be resent.'));

            return;
        }

        /** @var User $user */
        $user = auth()->user();

        $plain = $invitation->regenerateTokenForResend($user);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($plain));

        $this->successMessage = __('Invitation resent to :email.', ['email' => $invitation->email]);
    }

    public function revokeInvitation(int $invitationId): void
    {
        $invitation = Invitation::query()->find($invitationId);
        if ($invitation === null) {
            $this->addError('team', __('That invitation was not found.'));

            return;
        }

        $organisation = Organisation::query()->find($invitation->organisation_id);
        if ($organisation === null) {
            return;
        }

        $this->authorize('invite', $organisation);

        if ($invitation->accepted_at !== null) {
            $this->addError('team', __('That invitation cannot be revoked.'));

            return;
        }

        $invitation->delete();

        $this->successMessage = __('Invitation revoked.');
    }

    public function updateMemberRole(int $userId, string $newRole): void
    {
        if (! in_array($newRole, ['admin', 'reviewer'], true)) {
            return;
        }

        $organisation = Organisation::query()->find((int) $this->organisationId);
        if ($organisation === null) {
            return;
        }

        $this->authorize('invite', $organisation);

        $member = $organisation->users()->where('users.id', $userId)->first();
        if ($member === null) {
            return;
        }

        $currentRole = (string) $member->pivot->role;
        if ($currentRole === $newRole) {
            return;
        }

        if ($currentRole === OrganisationRole::Admin->value && $newRole === OrganisationRole::Reviewer->value) {
            if ($this->otherAdminCount($organisation, $userId) < 1) {
                $this->addError('team', __('The organisation must keep at least one admin.'));

                return;
            }
        }

        $organisation->users()->updateExistingPivot($userId, ['role' => $newRole]);

        $this->successMessage = __('Role updated.');
    }

    public function removeMember(int $userId): void
    {
        $organisation = Organisation::query()->find((int) $this->organisationId);
        if ($organisation === null) {
            return;
        }

        $this->authorize('invite', $organisation);

        $member = $organisation->users()->where('users.id', $userId)->first();
        if ($member === null) {
            return;
        }

        if ((string) $member->pivot->role === OrganisationRole::Admin->value) {
            if ($this->otherAdminCount($organisation, $userId) < 1) {
                $this->addError('team', __('The organisation must keep at least one admin.'));

                return;
            }
        }

        $organisation->users()->detach($userId);

        $this->successMessage = __('Member removed from organisation.');
    }
}
