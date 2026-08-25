<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A payment that's been 'pending' for a while almost always means its
 * webhook was lost or never sent — this is the safety net, not the primary
 * confirmation path (see docs/ROADMAP.md, Phase 5 and
 * PaymentService::applyWebhookOutcome()).
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Poll gateways directly for payments stuck pending with no webhook delivered';

    public function handle(PaymentService $payments): int
    {
        $stale = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('gateway_reference')
            ->where('created_at', '<', now()->subHour())
            ->get();

        foreach ($stale as $payment) {
            try {
                $payments->reconcile($payment);
            } catch (Throwable $e) {
                Log::warning('Payment reconciliation failed for one payment', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled {$stale->count()} stale pending payment(s).");

        return self::SUCCESS;
    }
}
