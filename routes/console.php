<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A price hold is only good for as long as the provider's own offer says —
// see App\Services\Bookings\BookingService::createHold(). Requires the
// ticket-management-system-scheduler.timer systemd unit (installed by
// deploy.sh) to actually call `schedule:run` and fire this in production.
Schedule::command('bookings:expire-holds')->everyMinute();

// Safety net for a webhook that never arrived — see
// App\Console\Commands\ReconcilePendingPayments.
Schedule::command('payments:reconcile')->daily();

// Bookkeeping only — SubscriptionService::activePlan() already ignores a
// lapsed subscription regardless of stored status; see
// App\Console\Commands\ExpireSubscriptions.
Schedule::command('subscriptions:expire')->daily();

// See docs/ROADMAP.md, Phase 10 — keeps high-volume audit tables from
// growing forever. Weekly, not daily: this is housekeeping, not anything
// time-sensitive.
Schedule::command('data:prune-retention')->weekly();
