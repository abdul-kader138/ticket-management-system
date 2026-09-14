<?php

namespace App\Notifications;

use App\Filament\Pages\MyBookings;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmed extends Notification implements ShouldQueue
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
        $booking = $this->booking;

        return (new MailMessage)
            ->subject("Booking confirmed — {$booking->pnr}")
            ->greeting("You're all set, {$notifiable->first_name}!")
            ->line("Your booking is confirmed. Reference: **{$booking->pnr}**.")
            ->line('Total paid: '.$booking->currency.' '.number_format($booking->total_price_cents / 100, 2))
            ->action('View your booking', MyBookings::getUrl())
            ->line('Thanks for booking with us.');
    }
}
