<?php

namespace Tests\Feature\Compliance;

use App\Models\PaymentWebhookEvent;
use App\Models\SearchQuotaUsage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneRetentionDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_rows_older_than_the_retention_window(): void
    {
        Setting::set('data_retention_days', 30);
        $user = User::factory()->create();

        $old = SearchQuotaUsage::create([
            'user_id' => $user->id, 'period_type' => 'day', 'period_key' => '2020-01-01', 'used_count' => 1,
        ]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        $recent = SearchQuotaUsage::create([
            'user_id' => $user->id, 'period_type' => 'day', 'period_key' => now()->format('Y-m-d'), 'used_count' => 1,
        ]);

        $this->artisan('data:prune-retention')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_does_not_prune_an_unprocessed_webhook_event_even_if_old(): void
    {
        Setting::set('data_retention_days', 30);

        $unprocessed = PaymentWebhookEvent::create([
            'gateway' => 'stripe', 'event_id' => 'evt_1', 'event_type' => 'x', 'payload' => [],
        ]);
        $unprocessed->forceFill(['created_at' => now()->subDays(90)])->save();

        $processed = PaymentWebhookEvent::create([
            'gateway' => 'stripe', 'event_id' => 'evt_2', 'event_type' => 'x', 'payload' => [], 'processed_at' => now(),
        ]);
        $processed->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->artisan('data:prune-retention')->assertSuccessful();

        $this->assertModelExists($unprocessed);
        $this->assertModelMissing($processed);
    }
}
