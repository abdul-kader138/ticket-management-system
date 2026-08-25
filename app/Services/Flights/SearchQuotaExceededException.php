<?php

namespace App\Services\Flights;

use RuntimeException;

class SearchQuotaExceededException extends RuntimeException
{
    public function __construct(public readonly string $period)
    {
        parent::__construct(
            $period === 'day'
                ? "You've used all of today's flight searches. Try again tomorrow, or upgrade your plan for more."
                : "You've used all of this month's flight searches. Upgrade your plan for more."
        );
    }
}
