<?php

namespace App\Notifications;

use App\Filament\Pages\MyBookings;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Booking $booking, private readonly string $reason = '') {}

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
            ->subject("Booking cancelled — {$booking->pnr}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Your booking **{$booking->pnr}** has been cancelled.");

        if (filled($this->reason)) {
            $message->line("Reason: {$this->reason}");
        }

        return $message
            ->action('View your bookings', MyBookings::getUrl())
            ->line('If a refund is due, it will be processed to your original payment method and you\'ll receive a separate confirmation once it\'s issued.');
    }
}
