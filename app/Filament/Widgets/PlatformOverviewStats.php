<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWithShield;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\UserSubscription;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The top-line "how is the business doing" row: confirmed bookings, net
 * revenue, new customers and active subscriptions — each for the current
 * calendar month, with the month-over-month delta and a 12-week trend
 * sparkline.
 *
 * Only the plain computed numbers are cached (a minute), never the Stat
 * objects themselves — those don't survive a round trip through a
 * serializing cache store and would come back as __PHP_Incomplete_Class on
 * the next Livewire render.
 */
class PlatformOverviewStats extends BaseWidget
{
    use AuthorizesWithShield;

    protected static ?int $sort = 1;

    /**
     * Statuses whose payment row represents money that was actually
     * captured. A later refund flips the status to refunded /
     * partially_refunded but the capture still happened — it's the Refund
     * rows that net it back down.
     */
    private const CAPTURED_STATUSES = [
        Payment::STATUS_SUCCEEDED,
        Payment::STATUS_REFUNDED,
        Payment::STATUS_PARTIALLY_REFUNDED,
    ];

    protected function getStats(): array
    {
        $d = Cache::remember('dashboard:platform-overview', now()->addMinute(), fn () => $this->compute());

        return [
            Stat::make('Confirmed bookings', number_format($d['confirmed_this_month']))
                ->description($this->delta($d['confirmed_this_month'], $d['confirmed_last_month']).' vs last month')
                ->descriptionIcon($this->trendIcon($d['confirmed_this_month'], $d['confirmed_last_month']))
                ->color($this->trendColor($d['confirmed_this_month'], $d['confirmed_last_month']))
                ->chart($d['confirmed_series']),

            Stat::make('Net revenue', $d['currency'].' '.number_format($d['revenue_this_month'] / 100, 2))
                ->description($this->delta($d['revenue_this_month'], $d['revenue_last_month'], isMoney: true).' vs last month')
                ->descriptionIcon($this->trendIcon($d['revenue_this_month'], $d['revenue_last_month']))
                ->color($this->trendColor($d['revenue_this_month'], $d['revenue_last_month']))
                ->chart($d['revenue_series']),

            Stat::make('New customers', number_format($d['customers_this_month']))
                ->description($this->delta($d['customers_this_month'], $d['customers_last_month']).' vs last month')
                ->descriptionIcon($this->trendIcon($d['customers_this_month'], $d['customers_last_month']))
                ->color($this->trendColor($d['customers_this_month'], $d['customers_last_month']))
                ->chart($d['customers_series']),

            Stat::make('Active subscriptions', number_format($d['active_subscriptions']))
                ->description('Currently paying members')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),
        ];
    }

    /**
     * @return array<string, mixed> plain scalars/arrays only — safe to cache
     */
    private function compute(): array
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonthNoOverflow();

        [$revenueThisMonth, $currency] = $this->netRevenueBetween($monthStart, $now);
        [$revenueLastMonth] = $this->netRevenueBetween($lastMonthStart, $monthStart);

        return [
            'confirmed_this_month' => $this->confirmedBetween($monthStart, $now),
            'confirmed_last_month' => $this->confirmedBetween($lastMonthStart, $monthStart),
            'confirmed_series' => $this->weeklySeries(fn (Carbon $from, Carbon $to) => $this->confirmedBetween($from, $to)),

            'revenue_this_month' => $revenueThisMonth,
            'revenue_last_month' => $revenueLastMonth,
            'currency' => $currency,
            'revenue_series' => $this->weeklySeries(fn (Carbon $from, Carbon $to) => $this->netRevenueBetween($from, $to)[0]),

            'customers_this_month' => User::whereBetween('created_at', [$monthStart, $now])->count(),
            'customers_last_month' => User::whereBetween('created_at', [$lastMonthStart, $monthStart])->count(),
            'customers_series' => $this->weeklySeries(fn (Carbon $from, Carbon $to) => User::whereBetween('created_at', [$from, $to])->count()),

            'active_subscriptions' => UserSubscription::where('status', UserSubscription::STATUS_ACTIVE)
                ->where('starts_at', '<=', $now)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->count(),
        ];
    }

    private function confirmedBetween(Carbon $from, Carbon $to): int
    {
        return Booking::whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED])
            ->whereBetween('confirmed_at', [$from, $to])
            ->count();
    }

    /**
     * Captured payments minus succeeded refunds in the window, by row
     * creation time. Currency is the single most common one among those
     * payments — this platform can take more than one and summing across
     * them would be meaningless.
     *
     * @return array{0: int, 1: string}
     */
    private function netRevenueBetween(Carbon $from, Carbon $to): array
    {
        $captured = (int) Payment::whereIn('status', self::CAPTURED_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_cents');

        $refunded = (int) Refund::where('status', Refund::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_cents');

        $currency = Payment::whereIn('status', self::CAPTURED_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('currency, count(*) as aggregate')
            ->groupBy('currency')
            ->orderByDesc('aggregate')
            ->value('currency') ?? 'USD';

        return [$captured - $refunded, $currency];
    }

    /**
     * 12 weekly data points ending this week, for a Stat sparkline.
     *
     * @param  callable(Carbon, Carbon): (int|float)  $measure
     * @return array<int, int|float>
     */
    private function weeklySeries(callable $measure): array
    {
        $series = [];

        for ($week = 11; $week >= 0; $week--) {
            $from = now()->subWeeks($week)->startOfWeek();
            $to = now()->subWeeks($week)->endOfWeek();
            $series[] = $measure($from, $to);
        }

        return $series;
    }

    private function delta(int|float $current, int|float $previous, bool $isMoney = false): string
    {
        $diff = $current - $previous;
        $sign = $diff > 0 ? '+' : ($diff < 0 ? '−' : '±');
        $magnitude = abs($isMoney ? $diff / 100 : $diff);

        return $sign.($isMoney ? number_format($magnitude, 2) : number_format($magnitude));
    }

    private function trendIcon(int|float $current, int|float $previous): string
    {
        return $current >= $previous ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function trendColor(int|float $current, int|float $previous): string
    {
        return $current >= $previous ? 'success' : 'danger';
    }
}
