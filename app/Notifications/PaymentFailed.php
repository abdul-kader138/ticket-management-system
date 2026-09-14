<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\UserSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent from PaymentService::markFailed() for either payable type it
 * handles — a booking purchase (reverted to 'held', still payable again
 * before the hold expires) or a subscription purchase (left 'failed', dead
 * end — see SubscriptionService::createPendingSubscription() to retry).
 * The copy below is generic enough to cover both without a payable-type
 * branch in the mail body.
 */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Payment $payment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment;
        $payable = $payment->payable;

        $subject = match (true) {
            $payable instanceof Booking => "We couldn't take payment for booking {$payable->pnr}",
            $payable instanceof UserSubscription => "We couldn't take payment for your subscription",
            default => 'Your payment could not be processed',
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Your payment of {$payment->currency} ".number_format($payment->amount_cents / 100, 2).' could not be processed.')
            ->line('This is usually a declined card or an expired card. No booking or subscription has been activated, and you have not been charged.')
            ->line($payable instanceof Booking
                ? 'You can try paying again before your price hold expires.'
                : 'You can try subscribing again whenever you\'re ready.');
    }
}
