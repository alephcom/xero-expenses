<div>
    @if ($adminOrganisations->isEmpty())
        <p class="text-sm text-gray-500">
            {{ __('You need to be an organisation admin to manage team members and invitations. Ask an admin to promote you or to link a new Xero organisation from the dashboard.') }}
        </p>
    @else
        <header class="space-y-1">
            <h3 class="text-lg font-medium text-gray-900">
                {{ __('Team and invitations') }}
            </h3>
            <p class="text-sm text-gray-600">
                {{ __('Invite people by email, resend or revoke pending invites, and manage member roles for the selected organisation.') }}
            </p>
        </header>

        <div class="mt-6 space-y-6">
            <div>
                <x-input-label for="team_org" :value="__('Organisation')" />
                <select
                    wire:model.live="organisationId"
                    id="team_org"
                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                >
                    <option value="">{{ __('Select organisation') }}</option>
                    @foreach ($adminOrganisations as $org)
                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('organisationId')" class="mt-2" />
            </div>

            @if ($successMessage)
                <div class="rounded-md bg-green-50 p-3 text-sm text-green-800" role="status">
                    {{ $successMessage }}
                </div>
            @endif

            <x-input-error :messages="$errors->get('team')" class="mt-2" />

            @if ($organisation)
                <div class="border-t border-gray-100 pt-6 space-y-6">
                    <div>
                        <h4 class="text-base font-medium text-gray-900">{{ __('Invite someone') }}</h4>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('They will receive an email link. They must sign in with Xero using the invited email address.') }}
                        </p>

                        <form wire:submit="sendInvitation" class="mt-4 space-y-4">
                            <div>
                                <x-input-label for="team_invite_email" :value="__('Email')" />
                                <x-text-input wire:model="email" id="team_invite_email" class="block mt-1 w-full" type="email" required autocomplete="off" />
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="team_invite_role" :value="__('Access level')" />
                                <select
                                    wire:model="role"
                                    id="team_invite_role"
                                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                >
                                    <option value="reviewer">{{ __('Reviewer — review expenses') }}</option>
                                    <option value="admin">{{ __('Admin — connect Xero, invite others') }}</option>
                                </select>
                                <x-input-error :messages="$errors->get('role')" class="mt-2" />
                            </div>

                            <x-primary-button type="submit">
                                {{ __('Send invitation') }}
                            </x-primary-button>
                        </form>
                    </div>

                    <div>
                        <h4 class="text-base font-medium text-gray-900">{{ __('Invitations') }}</h4>
                        @if ($invitations->isEmpty())
                            <p class="mt-2 text-sm text-gray-500">{{ __('No invitations yet for this organisation.') }}</p>
                        @else
                            <div class="mt-3 overflow-x-auto border border-gray-100 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-100 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <tr>
                                            <th class="px-3 py-2">{{ __('Email') }}</th>
                                            <th class="px-3 py-2">{{ __('Access') }}</th>
                                            <th class="px-3 py-2">{{ __('Status') }}</th>
                                            <th class="px-3 py-2">{{ __('Expires') }}</th>
                                            <th class="px-3 py-2">{{ __('Invited by') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        @foreach ($invitations as $inv)
                                            @php($status = $inv->statusForDisplay())
                                            <tr wire:key="inv-{{ $inv->id }}">
                                                <td class="px-3 py-2 text-gray-900">{{ $inv->email }}</td>
                                                <td class="px-3 py-2 text-gray-600">
                                                    {{ $inv->role === 'admin' ? __('Admin') : __('Reviewer') }}
                                                </td>
                                                <td class="px-3 py-2 text-gray-600">
                                                    @if ($status === 'accepted')
                                                        {{ __('Accepted') }}
                                                    @elseif ($status === 'pending')
                                                        {{ __('Pending') }}
                                                    @else
                                                        {{ __('Expired') }}
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                                                    @if ($inv->accepted_at)
                                                        —
                                                    @else
                                                        {{ $inv->expires_at->timezone(config('app.timezone'))->toDayDateTimeString() }}
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-gray-600">
                                                    {{ $inv->invitedBy?->name ?? '—' }}
                                                </td>
                                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                                    @if ($status !== 'accepted')
                                                        <button
                                                            type="button"
                                                            wire:click="resendInvitation({{ $inv->id }})"
                                                            class="text-indigo-600 hover:text-indigo-800 font-medium text-xs mr-2"
                                                        >
                                                            {{ __('Resend') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="revokeInvitation({{ $inv->id }})"
                                                            wire:confirm="{{ __('Revoke this invitation?') }}"
                                                            class="text-red-600 hover:text-red-800 font-medium text-xs"
                                                        >
                                                            {{ __('Revoke') }}
                                                        </button>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div>
                        <h4 class="text-base font-medium text-gray-900">{{ __('Members') }}</h4>
                        <div class="mt-3 overflow-x-auto border border-gray-100 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-100 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('Name') }}</th>
                                        <th class="px-3 py-2">{{ __('Email') }}</th>
                                        <th class="px-3 py-2">{{ __('Role') }}</th>
                                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach ($members as $member)
                                        <tr wire:key="member-{{ $member->id }}">
                                            <td class="px-3 py-2 text-gray-900">
                                                {{ $member->name }}
                                                @if ($member->id === auth()->id())
                                                    <span class="text-gray-500 font-normal">({{ __('you') }})</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-gray-600">{{ $member->email }}</td>
                                            <td class="px-3 py-2 text-gray-600">
                                                {{ $member->pivot->role === 'admin' ? __('Admin') : __('Reviewer') }}
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-2">
                                                @if ($member->pivot->role === 'reviewer')
                                                    <button
                                                        type="button"
                                                        wire:click="updateMemberRole({{ $member->id }}, 'admin')"
                                                        class="text-indigo-600 hover:text-indigo-800 font-medium text-xs"
                                                    >
                                                        {{ __('Make admin') }}
                                                    </button>
                                                @endif
                                                @if ($this->canDemoteMember($organisation, $member))
                                                    <button
                                                        type="button"
                                                        wire:click="updateMemberRole({{ $member->id }}, 'reviewer')"
                                                        class="text-indigo-600 hover:text-indigo-800 font-medium text-xs"
                                                    >
                                                        {{ __('Make reviewer') }}
                                                    </button>
                                                @endif
                                                @if ($this->canRemoveMember($organisation, $member))
                                                    <button
                                                        type="button"
                                                        wire:click="removeMember({{ $member->id }})"
                                                        wire:confirm="{{ __('Remove this member from the organisation?') }}"
                                                        class="text-red-600 hover:text-red-800 font-medium text-xs"
                                                    >
                                                        {{ __('Remove') }}
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
