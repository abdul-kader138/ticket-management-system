<?php

namespace App\Policies;

use App\Models\TravelerProfile;
use App\Models\User;

/**
 * Ownership-only — a traveler profile is private to the account that
 * created it, not a staff-managed resource, so this doesn't check any
 * Spatie permission the way the Filament-facing policies do.
 */
class TravelerProfilePolicy
{
    public function view(User $user, TravelerProfile $travelerProfile): bool
    {
        return $user->id === $travelerProfile->user_id;
    }

    public function update(User $user, TravelerProfile $travelerProfile): bool
    {
        return $user->id === $travelerProfile->user_id;
    }

    public function delete(User $user, TravelerProfile $travelerProfile): bool
    {
        return $user->id === $travelerProfile->user_id;
    }
}
