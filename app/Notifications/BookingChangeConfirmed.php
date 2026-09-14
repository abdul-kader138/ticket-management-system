<?php

namespace App\Notifications;

use App\Filament\Pages\MyBookings;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingChangeConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking, private readonly int $differenceCents) {}

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

        $message = (new MailMessage)
            ->subject("Booking updated — {$booking->pnr}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Your booking **{$booking->pnr}** has been updated with a new itinerary.");

        $message = match (true) {
            $this->differenceCents > 0 => $message->line('An additional '.$booking->currency.' '.number_format($this->differenceCents / 100, 2).' was charged for the fare difference.'),
            $this->differenceCents < 0 => $message->line('The new fare was lower — no additional charge was made.'),
            default => $message,
        };

        return $message
            ->line('New total: '.$booking->currency.' '.number_format($booking->total_price_cents / 100, 2))
            ->action('View your booking', MyBookings::getUrl());
    }
}
