<?php

namespace App\Console\Commands;

use App\Models\PaymentWebhookEvent;
use App\Models\SearchQuotaUsage;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * See docs/ROADMAP.md, Phase 10: high-volume, low-value-per-row tables
 * that don't need to be kept forever. Deliberately does not touch
 * bookings/payments/refunds — those are financial records, not the kind of
 * operational exhaust this command is for.
 */
class PruneRetentionData extends Command
{
    protected $signature = 'data:prune-retention';

    protected $description = 'Delete old search-quota audit rows and processed payment webhook events past the retention window';

    public function handle(): int
    {
        $days = (int) Setting::get('data_retention_days', 180);
        $cutoff = now()->subDays($days);

        $quotaRows = SearchQuotaUsage::where('created_at', '<', $cutoff)->delete();

        // Only ever prunes events that were actually processed — an
        // unprocessed one past the retention window is a stuck webhook
        // worth investigating, not garbage to clean up silently.
        $webhookRows = PaymentWebhookEvent::whereNotNull('processed_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$quotaRows} search quota row(s) and {$webhookRows} processed webhook event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
