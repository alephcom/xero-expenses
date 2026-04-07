<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">{{ __('Xero organisations') }}</h3>
                            <p class="text-sm text-gray-600 mt-1">{{ __('Connect Xero to add organisations you can review.') }}</p>
                        </div>
                        @if ($canConnectXero ?? true)
                            <a href="{{ route('xero.redirect') }}" class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                                {{ __('Connect Xero') }}
                            </a>
                        @endif
                    </div>

                    @if ($organisations->isEmpty())
                        <p class="text-sm text-gray-500">{{ __('No organisations yet. Use Connect Xero to link your Xero tenant(s).') }}</p>
                    @else
                        <ul class="divide-y divide-gray-100 border border-gray-100 rounded-lg">
                            @foreach ($organisations as $organisation)
                                <li class="flex flex-col gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900">{{ $organisation->name }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ __('Your role') }}: {{ $organisation->pivot->role === 'admin' ? __('Admin') : __('Reviewer') }}
                                        </p>
                                        @if ($organisation->last_synced_at)
                                            <p class="text-xs text-gray-500 mt-1">{{ __('Last synced') }}: {{ $organisation->last_synced_at->timezone(config('app.timezone'))->toDayDateTimeString() }}</p>
                                        @endif
                                        <x-organisation-expense-totals
                                            class="mt-2"
                                            :stats="$organisationExpenseTotals[$organisation->id] ?? []"
                                        />
                                    </div>
                                    <a
                                        href="{{ route('orgs.expenses', $organisation) }}"
                                        wire:navigate
                                        class="inline-flex shrink-0 items-center justify-center px-4 py-2 w-full text-center sm:w-auto bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                    >
                                        {{ __('Review expenses') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <livewire:dashboard.invite-organisation-member />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
