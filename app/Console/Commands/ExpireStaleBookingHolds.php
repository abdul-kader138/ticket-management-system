<?php

namespace App\Console\Commands;

use App\Services\Bookings\BookingService;
use Illuminate\Console\Command;

class ExpireStaleBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire held bookings whose price hold has passed';

    public function handle(BookingService $bookings): int
    {
        $count = $bookings->expireStaleHolds();

        if ($count > 0) {
            $this->info("Expired {$count} booking hold(s).");
        }

        return self::SUCCESS;
    }
}
