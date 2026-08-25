<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Same shape as BookingPolicy — ownership for the customer API, or the
 * Shield permission for staff in the read-only Filament PaymentResource.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_payment');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->id === $payment->user_id || $user->can('view_payment');
    }
}
