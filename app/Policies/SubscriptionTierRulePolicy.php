<?php

namespace App\Policies;

use App\Models\SubscriptionTierRule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubscriptionTierRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_subscription::tier::rule');
    }

    public function view(User $user, SubscriptionTierRule $subscriptionTierRule): bool
    {
        return $user->can('view_subscription::tier::rule');
    }

    public function create(User $user): bool
    {
        return $user->can('create_subscription::tier::rule');
    }

    public function update(User $user, SubscriptionTierRule $subscriptionTierRule): bool
    {
        return $user->can('update_subscription::tier::rule');
    }

    public function delete(User $user, SubscriptionTierRule $subscriptionTierRule): bool
    {
        return $user->can('delete_subscription::tier::rule');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_subscription::tier::rule');
    }
}
