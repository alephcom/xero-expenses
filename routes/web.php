<?php

use App\Http\Controllers\Xero\XeroCallbackController;
use App\Http\Controllers\XeroOAuthController;
use App\Livewire\Org\ExpenseLineReview;
use App\Queries\ExpenseReview\OrganisationExpenseTotalsQuery;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    $user = auth()->user();
    $organisations = $user->organisations()->orderBy('name')->get();
    $canConnectXero = $user->canConnectXero();
    $organisationExpenseTotals = app(OrganisationExpenseTotalsQuery::class)
        ->forOrganisationIds($organisations->pluck('id')->all());

    return view('dashboard', [
        'organisations' => $organisations,
        'canConnectXero' => $canConnectXero,
        'organisationExpenseTotals' => $organisationExpenseTotals,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/xero/redirect', [XeroOAuthController::class, 'redirect'])
        ->middleware('throttle:60,1')
        ->name('xero.redirect');
    Route::get('/orgs/{organisation}/expenses', ExpenseLineReview::class)
        ->middleware('org.member')
        ->name('orgs.expenses');
});

Route::get('/xero/callback', XeroCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('xero.callback');

require __DIR__.'/auth.php';
