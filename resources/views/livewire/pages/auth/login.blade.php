<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    //
}; ?>

<div>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if ($errors->has('xero'))
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">
            {{ $errors->first('xero') }}
        </div>
    @endif

    <p class="text-sm text-gray-600 mb-6">
        {{ __('Sign in with your Xero account. You will be redirected to Xero to approve access.') }}
    </p>

    <div>
        <a
            href="{{ route('xero.sso.redirect') }}"
            class="inline-flex items-center justify-center w-full px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
        >
            {{ __('Sign in with Xero') }}
        </a>
    </div>
</div>
