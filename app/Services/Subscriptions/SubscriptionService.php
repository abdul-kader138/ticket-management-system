<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTierRule;
use App\Models\User;
use App\Models\UserSubscription;

/**
 * See docs/ROADMAP.md, Phase 7. Deliberately has no dependency on
 * PaymentService — that dependency runs the other way (PaymentService
 * calls activate()/markFailed() once a subscription payment resolves), so
 * keeping this one-directional avoids a circular dependency between the
 * two services (the same reasoning documented on CancellationService).
 */
class SubscriptionService
{
    private const PLAN_COLUMNS = ['day' => 'daily_search_limit', 'month' => 'monthly_search_limit'];

    public function createPendingSubscription(User $user, SubscriptionPlan $plan): UserSubscription
    {
        return UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'source' => 'purchased',
            'status' => UserSubscription::STATUS_PENDING_PAYMENT,
            'starts_at' => now(),
            'auto_renew' => false,
        ]);
    }

    /**
     * Called only once the subscription's payment has actually succeeded
     * (see PaymentService::markSucceeded()).
     */
    public function activate(UserSubscription $subscription): void
    {
        $plan = $subscription->subscriptionPlan;

        $subscription->update([
            'status' => UserSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => $plan->billing_interval === 'year' ? now()->addYear() : now()->addMonth(),
        ]);
    }

    public function markFailed(UserSubscription $subscription): void
    {
        $subscription->update(['status' => UserSubscription::STATUS_FAILED]);
    }

    public function cancel(UserSubscription $subscription): void
    {
        $subscription->update(['status' => UserSubscription::STATUS_CANCELLED, 'ends_at' => now()]);
    }

    /**
     * Sweeps active subscriptions past their end date — see
     * App\Console\Commands\ExpireSubscriptions, scheduled daily. Purely
     * for admin-visible bookkeeping: activePlan() below already ignores a
     * subscription past ends_at regardless of its stored status.
     */
    public function expireLapsed(): int
    {
        $count = UserSubscription::query()
            ->where('status', UserSubscription::STATUS_ACTIVE)
            ->where('ends_at', '<', now())
            ->update(['status' => UserSubscription::STATUS_EXPIRED]);

        return $count;
    }

    public function activePlan(User $user): ?SubscriptionPlan
    {
        return UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', UserSubscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at')
            ->first()
            ?->subscriptionPlan;
    }

    public function matchedTierRule(User $user): ?SubscriptionTierRule
    {
        $accountAgeDays = $user->created_at->diffInDays(now());

        return SubscriptionTierRule::query()
            ->active()
            ->where('min_total_spend_cents', '<=', $user->total_spend_cents)
            ->where('min_account_age_days', '<=', $accountAgeDays)
            ->orderByDesc('priority')
            ->first();
    }

    /**
     * Highest wins per-benefit, not whole-plan override — a user on a
     * "Plus" purchased plan who has also earned a spend-based "Gold" tier
     * gets whichever of the two gives more search allowance, not
     * necessarily all of either plan wholesale (see docs/ROADMAP.md, Phase
     * 7's precedence rule).
     *
     * @return int|null null means "this user has no plan/tier override —
     *                  fall back to the Setting-driven default" (see
     *                  SearchQuotaService::limitFor()). SubscriptionPlan::UNLIMITED
     *                  (-1) always wins over any finite number.
     */
    public function searchLimit(User $user, string $period): ?int
    {
        $column = self::PLAN_COLUMNS[$period];

        $candidates = array_filter([
            $this->activePlan($user)?->{$column},
            $this->matchedTierRule($user)?->subscriptionPlan?->{$column},
        ], fn ($value) => $value !== null);

        if ($candidates === []) {
            return null;
        }

        if (in_array(SubscriptionPlan::UNLIMITED, $candidates, true)) {
            return SubscriptionPlan::UNLIMITED;
        }

        return max($candidates);
    }

    public function hasBenefit(User $user, string $key): bool
    {
        return (bool) $this->activePlan($user)?->hasBenefit($key)
            || (bool) $this->matchedTierRule($user)?->subscriptionPlan?->hasBenefit($key);
    }
}
