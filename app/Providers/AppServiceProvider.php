<?php

namespace App\Providers;

use App\Models\User;
use App\Support\OrganisationContext;
use App\Support\QueueJobLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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

        // OrganisationContext is static, so a long-running worker would otherwise carry
        // one job's organisation scope into the next job.
        Queue::before(fn () => OrganisationContext::set(null));
        Queue::after(fn () => OrganisationContext::set(null));
        Queue::failing(fn () => OrganisationContext::set(null));
    }
}
