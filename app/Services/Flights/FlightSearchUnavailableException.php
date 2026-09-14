<?php

namespace App\Services\Flights;

use RuntimeException;

/**
 * Thrown when every enabled flight provider threw during a search — as
 * opposed to a provider legitimately returning zero offers for the route,
 * which is not an error. See FlightProviderManager::search(): distinguishing
 * the two matters because a total outage should not consume the customer's
 * search quota nor get its empty result cached for other customers.
 */
class FlightSearchUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Flight search is temporarily unavailable. Please try again in a few minutes.');
    }
}
