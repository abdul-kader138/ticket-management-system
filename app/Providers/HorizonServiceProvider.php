<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Reuses the same role the rest of the admin panel trusts, rather
        // than a hardcoded email allowlist — Horizon exposes queue payloads
        // (including failed job data), so it stays behind the same bar as
        // the Filament panel itself.
        Gate::define('viewHorizon', fn ($user = null) => (bool) $user?->hasRole(
            config('filament-shield.super_admin.name', 'super_admin')
        ));
    }
}
