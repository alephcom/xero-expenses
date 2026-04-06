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

class InviteOrganisationMember extends Component
{
    use AuthorizesRequests;

    public string $organisationId = '';

    public string $email = '';

    public string $role = 'reviewer';

    public function render(): View
    {
        return view('livewire.dashboard.invite-organisation-member', [
            'adminOrganisations' => $this->adminOrganisations(),
        ]);
    }

    /**
     * @return Collection<int, Organisation>
     */
    private function adminOrganisations()
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->organisations()
            ->wherePivot('role', OrganisationRole::Admin->value)
            ->orderBy('name')
            ->get();
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
        session()->flash('dashboard_invitation_sent', __('Invitation sent to :email.', ['email' => $email]));
    }
}
