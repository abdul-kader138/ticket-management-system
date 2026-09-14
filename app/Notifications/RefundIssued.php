<?php

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Refund $refund) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $refund = $this->refund;

        return (new MailMessage)
            ->subject('Refund issued')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('A refund of '.$refund->currency.' '.number_format($refund->amount_cents / 100, 2).' has been issued to your original payment method.')
            ->line('It can take several business days to appear on your statement, depending on your bank or card issuer.');
    }
}
