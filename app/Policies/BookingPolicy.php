<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Serves two very different callers against the same model: the customer
 * API (App\Http\Controllers\Api\V1\BookingController), which only ever
 * checks ownership, and the Filament admin resource
 * (App\Filament\Resources\BookingResource), where staff need to see every
 * customer's bookings — hence the "own it OR hold the Shield permission"
 * shape below rather than a plain ownership check.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_booking');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id || $user->can('view_booking');
    }
}
