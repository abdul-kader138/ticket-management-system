<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Widgets\Concerns\AuthorizesWithShield;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The "does anything need a human right now" row. Every number here should
 * normally be zero — a non-zero value is coloured danger/warning and links
 * straight to the rows that need attention.
 *
 * "Stuck bookings" is the failure mode App\Jobs\IssueProviderOrderJob was
 * built around: money captured, provider order never issued, booking left
 * in pending_payment.
 */
class OperationalHealthStats extends BaseWidget
{
    use AuthorizesWithShield;

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $stuckBookings = Booking::where('status', Booking::STATUS_PENDING_PAYMENT)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->count();

        $expiringHolds = Booking::where('status', Booking::STATUS_HELD)
            ->whereBetween('expires_at', [now(), now()->addHour()])
            ->count();

        $failedPayments = Payment::where('status', Payment::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $unprocessedWebhooks = PaymentWebhookEvent::whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(5))
            ->count();

        return [
            Stat::make('Stuck bookings', number_format($stuckBookings))
                ->description($stuckBookings > 0 ? 'Paid, awaiting provider order — reconcile' : 'None — all paid bookings issued')
                ->descriptionIcon($stuckBookings > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($stuckBookings > 0 ? 'danger' : 'success')
                ->url($stuckBookings > 0 ? BookingResource::getUrl('index', ['tableFilters' => ['status' => ['value' => Booking::STATUS_PENDING_PAYMENT]]]) : null),

            Stat::make('Holds expiring < 1h', number_format($expiringHolds))
                ->description('Held bookings the customer must pay for soon')
                ->descriptionIcon('heroicon-m-clock')
                ->color($expiringHolds > 0 ? 'warning' : 'gray'),

            Stat::make('Failed payments (24h)', number_format($failedPayments))
                ->description($failedPayments > 0 ? 'Declines and gateway errors' : 'None in the last 24 hours')
                ->descriptionIcon($failedPayments > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color($failedPayments > 0 ? 'warning' : 'success')
                ->url($failedPayments > 0 ? PaymentResource::getUrl('index', ['tableFilters' => ['status' => ['value' => Payment::STATUS_FAILED]]]) : null),

            Stat::make('Unprocessed webhooks', number_format($unprocessedWebhooks))
                ->description($unprocessedWebhooks > 0 ? 'Gateway events not yet applied — check Horizon' : 'Queue is keeping up')
                ->descriptionIcon($unprocessedWebhooks > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($unprocessedWebhooks > 0 ? 'danger' : 'success'),
        ];
    }
}
