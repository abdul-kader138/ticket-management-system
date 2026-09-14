<?php

namespace App\Notifications;

use App\Filament\Pages\FlightSearch;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a held booking's price hold lapses unpaid — see
 * App\Services\Bookings\BookingService::expireStaleHolds(). Distinct from
 * BookingCancelled: nothing was ever charged, so there's no refund
 * language, and the call to action is to search again rather than to
 * review what happened to an existing purchase.
 */
class BookingExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking hold has expired')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('We held your fare while you completed payment, but the hold expired before checkout finished. Nothing was charged.')
            ->line('Fares change quickly, so you\'ll need to search again to get a current price.')
            ->action('Search flights', FlightSearch::getUrl());
    }
}
