<?php

use App\Http\Controllers\Auth\XeroSsoController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function (): void {
    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('invite/{token}', 'pages.auth.accept-invitation')
        ->name('invitations.accept');

    Route::get('/auth/xero/redirect', [XeroSsoController::class, 'redirect'])
        ->middleware('throttle:60,1')
        ->name('xero.sso.redirect');
});
