<?php

namespace App\Jobs;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\Bookings\BookingService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Issues the flight provider order (the actual ticket) for a booking whose
 * payment has already succeeded — see docs/ROADMAP.md, Phase 5.
 *
 * This used to run inline inside PaymentService::markSucceeded(): a single
 * transient Duffel 5xx during the webhook that confirmed payment would
 * capture the customer's money, leave the booking stuck in
 * 'pending_payment', and write one log line that nobody watches. Pulling it
 * into its own job buys the two things that failure mode needs — automatic
 * retries with backoff, and an explicit failed() path that alerts staff
 * instead of failing silent.
 *
 * ShouldBeUnique (keyed by booking id) stops a duplicate webhook delivery,
 * a PayPal client-side capture, and the nightly reconcile job from each
 * dispatching a second issuance for the same booking; handle()'s own status
 * guard is the belt to that suspenders.
 */
class IssueProviderOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The provider call is a slow external HTTP request that can rate-limit
     * or blip; give it a few well-spaced attempts before escalating.
     */
    public int $tries = 5;

    /**
     * The unique lock outlives the retry schedule below so overlapping
     * dispatches collapse to one for the full duration a retry chain runs.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $bookingId) {}

    public function uniqueId(): string
    {
        return (string) $this->bookingId;
    }

    /**
     * @return array<int, int> seconds to wait before attempts 2..5
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(BookingService $bookings): void
    {
        $booking = Booking::find($this->bookingId);

        // Anything other than 'pending_payment' means the order was already
        // issued (a prior attempt that actually succeeded), or the booking
        // was cancelled/refunded in the meantime — nothing to do either way.
        if (! $booking || $booking->status !== Booking::STATUS_PENDING_PAYMENT) {
            return;
        }

        $bookings->confirmWithProvider($booking);
    }

    /**
     * Reached only once every retry in backoff() has been exhausted. The
     * money is captured but the ticket was never issued — this must be
     * loud: a critical audit-log entry, a durable booking_events row (not a
     * status transition — the booking legitimately stays 'pending_payment'
     * until a human sorts it out), and a database notification to every
     * super admin so it surfaces in the Filament notification bell.
     */
    public function failed(Throwable $exception): void
    {
        Log::stack(['stack', 'audit'])->critical(
            'Provider order issuance permanently failed after retries — payment captured, ticket NOT issued, manual reconciliation required',
            ['booking_id' => $this->bookingId, 'error' => $exception->getMessage()],
        );

        $booking = Booking::find($this->bookingId);

        if (! $booking) {
            return;
        }

        $booking->events()->create([
            'event_type' => 'provider_order_failed',
            'actor_type' => 'system',
            'actor_id' => null,
            'payload' => ['error' => $exception->getMessage()],
            'created_at' => now(),
        ]);

        User::query()->role('super_admin')->get()->each(function (User $admin) use ($booking) {
            Notification::make()
                ->title('Ticket issuance failed')
                ->body("Booking #{$booking->id} was paid but the provider order could not be created after several retries. Manual reconciliation is required.")
                ->danger()
                ->actions([
                    Action::make('view')
                        ->label('Open booking')
                        ->url(BookingResource::getUrl('view', ['record' => $booking->id]), shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($admin);
        });
    }
}
