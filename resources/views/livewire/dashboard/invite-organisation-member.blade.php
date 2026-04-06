<div>
    @if ($adminOrganisations->isEmpty())
        <p class="text-sm text-gray-500">
            {{ __('You need to be an organisation admin to send invitations. Ask an admin to promote you or to link a new Xero organisation from the dashboard.') }}
        </p>
    @else
        <header>
            <h3 class="text-lg font-medium text-gray-900">
                {{ __('Invite someone to an organisation') }}
            </h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ __('They will receive an email link. They must sign in with Xero using the invited email address.') }}
            </p>
        </header>

        @if (session('dashboard_invitation_sent'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800" role="status">
                {{ session('dashboard_invitation_sent') }}
            </div>
        @endif

        <form wire:submit="sendInvitation" class="mt-6 space-y-4">
            <div>
                <x-input-label for="invite_org" :value="__('Organisation')" />
                <select
                    wire:model="organisationId"
                    id="invite_org"
                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    required
                >
                    <option value="">{{ __('Select organisation') }}</option>
                    @foreach ($adminOrganisations as $org)
                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('organisationId')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="invite_email_dash" :value="__('Email')" />
                <x-text-input wire:model="email" id="invite_email_dash" class="block mt-1 w-full" type="email" required autocomplete="off" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="invite_role" :value="__('Access level')" />
                <select
                    wire:model="role"
                    id="invite_role"
                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                >
                    <option value="reviewer">{{ __('Reviewer — review expenses') }}</option>
                    <option value="admin">{{ __('Admin — connect Xero, invite others') }}</option>
                </select>
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <x-primary-button type="submit">
                    {{ __('Send invitation') }}
                </x-primary-button>
            </div>
        </form>
    @endif
</div>
