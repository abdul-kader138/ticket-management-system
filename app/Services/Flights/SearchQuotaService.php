<?php

namespace App\Services\Flights;

use App\Models\SearchQuotaUsage;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Meters flight searches that actually reach a paid provider — a cache hit
 * in FlightProviderManager never calls into this class at all. Redis (via
 * the Cache facade, so this stays store-agnostic) is the enforcement path;
 * `search_quota_usage` is only an audit/reporting trail, written after the
 * fact, and is never read to decide whether a search is allowed.
 *
 * Limits resolve base Setting default vs. purchased plan vs. earned tier
 * and take the highest (see docs/ROADMAP.md, Phase 7 and
 * SubscriptionService::searchLimit() for the per-benefit precedence rule).
 * A limit of SubscriptionPlan::UNLIMITED (-1) always wins and disables
 * enforcement entirely for that period.
 */
class SearchQuotaService
{
    private const PERIODS = ['day', 'month'];

    // "day" doesn't pluralize to its Setting key by simple concatenation
    // ("default_{day}ly_..." → "default_dayly_...") — spelled out explicitly
    // instead of relying on string interpolation to get it right.
    private const SETTING_KEYS = [
        'day' => 'default_daily_search_limit',
        'month' => 'default_monthly_search_limit',
    ];

    private const DEFAULTS = ['day' => 10, 'month' => 300];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function limitFor(User $user, string $period): int
    {
        $subscriptionLimit = $this->subscriptions->searchLimit($user, $period);

        if ($subscriptionLimit === SubscriptionPlan::UNLIMITED) {
            return SubscriptionPlan::UNLIMITED;
        }

        $base = (int) Setting::get(self::SETTING_KEYS[$period], self::DEFAULTS[$period]);

        return $subscriptionLimit !== null ? max($base, $subscriptionLimit) : $base;
    }

    public function isUnlimited(User $user, string $period): bool
    {
        return $this->limitFor($user, $period) === SubscriptionPlan::UNLIMITED;
    }

    /**
     * @return array<string, int>
     */
    public function limits(User $user): array
    {
        return collect(self::PERIODS)->mapWithKeys(fn ($period) => [$period => $this->limitFor($user, $period)])->all();
    }

    public function used(User $user, string $period): int
    {
        return (int) Cache::get($this->cacheKey($user, $period), 0);
    }

    /**
     * SubscriptionPlan::UNLIMITED (-1) in the result means unlimited, not a
     * literal negative count remaining — check isUnlimited() before
     * displaying this as a number.
     *
     * @return array<string, int>
     */
    public function remaining(User $user): array
    {
        return collect(self::PERIODS)->mapWithKeys(function ($period) use ($user) {
            $limit = $this->limitFor($user, $period);

            return [$period => $limit === SubscriptionPlan::UNLIMITED ? SubscriptionPlan::UNLIMITED : max(0, $limit - $this->used($user, $period))];
        })->all();
    }

    /**
     * @throws SearchQuotaExceededException
     */
    public function ensureNotExceeded(User $user): void
    {
        foreach (self::PERIODS as $period) {
            $limit = $this->limitFor($user, $period);

            if ($limit === SubscriptionPlan::UNLIMITED) {
                continue;
            }

            if ($this->used($user, $period) >= $limit) {
                throw new SearchQuotaExceededException($period);
            }
        }
    }

    /**
     * Call exactly once per search that actually reaches a live provider.
     */
    public function consume(User $user): void
    {
        foreach (self::PERIODS as $period) {
            $key = $this->cacheKey($user, $period);

            // add() is atomic (SETNX under the hood on Redis) — avoids a
            // race where two concurrent first-requests-of-the-period both
            // see "no key yet" and each reset the counter to 1.
            Cache::add($key, 0, $this->expiresAt($period));
            Cache::increment($key);

            $row = SearchQuotaUsage::query()->firstOrCreate(
                ['user_id' => $user->id, 'period_type' => $period, 'period_key' => $this->periodKey($period)],
                ['used_count' => 0, 'limit_snapshot' => $this->limitFor($user, $period)],
            );

            // increment() issues `used_count = used_count + 1` at the SQL
            // level — atomic regardless of how many requests land here
            // concurrently, unlike read-then-write in PHP.
            $row->increment('used_count', 1, ['limit_snapshot' => $this->limitFor($user, $period)]);
        }
    }

    /**
     * A one-time top-up for the *current* period only — see
     * App\Services\Promotions\PromotionService (free_search_bonus codes)
     * and App\Observers\BookingObserver (referral rewards). Implemented as
     * a decrement of the existing usage counter rather than a separate
     * ledger: it gives the same extra headroom for whatever's left of the
     * current day/month without needing new state, and naturally expires
     * with that counter's own TTL.
     */
    public function grantBonusSearches(User $user, string $period, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $key = $this->cacheKey($user, $period);

        Cache::add($key, 0, $this->expiresAt($period));
        Cache::decrement($key, $amount);
    }

    private function cacheKey(User $user, string $period): string
    {
        return "search-quota:{$user->id}:{$period}:{$this->periodKey($period)}";
    }

    private function periodKey(string $period): string
    {
        return $period === 'day' ? now()->format('Y-m-d') : now()->format('Y-m');
    }

    private function expiresAt(string $period): Carbon
    {
        return $period === 'day' ? now()->endOfDay() : now()->endOfMonth();
    }
}
