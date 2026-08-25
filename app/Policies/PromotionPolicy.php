<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PromotionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_promotion');
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $user->can('view_promotion');
    }

    public function create(User $user): bool
    {
        return $user->can('create_promotion');
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $user->can('update_promotion');
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $user->can('delete_promotion');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_promotion');
    }
}
