<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubscriptionPlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_subscription::plan');
    }

    public function view(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->can('view_subscription::plan');
    }

    public function create(User $user): bool
    {
        return $user->can('create_subscription::plan');
    }

    public function update(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->can('update_subscription::plan');
    }

    public function delete(User $user, SubscriptionPlan $subscriptionPlan): bool
    {
        return $user->can('delete_subscription::plan');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_subscription::plan');
    }
}
