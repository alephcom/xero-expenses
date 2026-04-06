<?php

namespace App\Providers;

use App\Models\User;
use App\Support\QueueJobLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('connect-xero', fn (User $user): bool => $user->canConnectXero());

        QueueJobLog::register();
    }
}
