<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark active subscriptions past their end date as expired';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireLapsed();

        if ($count > 0) {
            $this->info("Expired {$count} subscription(s).");
        }

        return self::SUCCESS;
    }
}
