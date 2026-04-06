<?php

use App\Enums\OrganisationRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public ?int $invitationId = null;

    #[Locked]
    public string $inviteEmail = '';

    #[Locked]
    public string $organisationName = '';

    public function mount(string $token): void
    {
        $invitation = Invitation::findValidByPlainToken($token);

        if ($invitation === null) {
            return;
        }

        $this->invitationId = $invitation->id;
        $this->inviteEmail = $invitation->email;
        $this->organisationName = $invitation->organisation->name;

        if (! Auth::check()) {
            session(['post_sso_invitation_plain' => $token]);
        }
    }

    public function acceptAuthenticated(): void
    {
        if ($this->invitationId === null) {
            abort(404);
        }

        $invitation = Invitation::query()
            ->whereKey($this->invitationId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($invitation === null) {
            abort(404);
        }

        /** @var User $user */
        $user = Auth::user();

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            $this->addError('email', __('You are signed in as :signed. Use an account with :invited, or sign out and sign in with Xero using that email.', [
                'signed' => $user->email,
                'invited' => $invitation->email,
            ]));

            return;
        }

        $role = OrganisationRole::tryFrom($invitation->role) ?? OrganisationRole::Reviewer;
        $invitation->organisation->users()->syncWithoutDetaching([
            $user->id => ['role' => $role->value],
        ]);
        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    @if ($invitationId === null)
        <p class="text-sm text-gray-600">
            {{ __('This invitation link is invalid or has expired. Ask your teammate to send a new invite.') }}
        </p>
        <div class="mt-4">
            <a href="{{ route('login') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-500">{{ __('Sign in') }}</a>
        </div>
    @else
        <p class="text-sm text-gray-600 mb-2">
            {{ __('You have been invited to join :org as :email.', ['org' => $organisationName, 'email' => $inviteEmail]) }}
        </p>

        @guest
            <p class="text-sm text-gray-600 mb-4">
                {{ __('Sign in with Xero using the email address above.') }}
            </p>
            <a
                href="{{ route('xero.sso.redirect') }}"
                class="inline-flex items-center justify-center w-full px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700"
            >
                {{ __('Sign in with Xero') }}
            </a>
        @endguest

        @auth
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
            <div class="mt-4">
                <x-primary-button type="button" wire:click="acceptAuthenticated">
                    {{ __('Accept invitation') }}
                </x-primary-button>
            </div>
        @endauth
    @endif
</div>
