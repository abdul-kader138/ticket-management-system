<?php

namespace App\Policies;

use App\Models\FlightProvider;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Hand-written to match the shape php artisan shield:generate produces for
 * every other resource (see App\Policies\UserPolicy) — holds API
 * credentials, so it's deliberately not left to super_admin's blanket
 * Gate::before alone; other roles need an explicit 'flight::provider'
 * permission (see docs/ROADMAP.md, Phase 2).
 */
class FlightProviderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_flight::provider');
    }

    public function view(User $user, FlightProvider $flightProvider): bool
    {
        return $user->can('view_flight::provider');
    }

    public function create(User $user): bool
    {
        return $user->can('create_flight::provider');
    }

    public function update(User $user, FlightProvider $flightProvider): bool
    {
        return $user->can('update_flight::provider');
    }

    public function delete(User $user, FlightProvider $flightProvider): bool
    {
        return $user->can('delete_flight::provider');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_flight::provider');
    }
}
