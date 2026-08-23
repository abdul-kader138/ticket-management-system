<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Records sign-in activity to the audit log (activity_log table). Registered
 * as an event subscriber in AppServiceProvider::boot() rather than relying on
 * event auto-discovery, since this app has no EventServiceProvider.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'guard' => $event->guard,
                'ip' => request()->ip(),
            ])
            ->log('User logged in');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'guard' => $event->guard,
                'ip' => request()->ip(),
            ])
            ->log('User logged out');
    }

    public function handleFailedLogin(Failed $event): void
    {
        activity('auth')
            ->withProperties([
                'guard' => $event->guard,
                'email' => $event->credentials['email'] ?? null,
                'ip' => request()->ip(),
            ])
            ->log('Failed login attempt');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailedLogin',
        ];
    }
}
