<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWithShield;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Where the last 30 days of bookings currently sit in the lifecycle —
 * held / pending_payment / confirmed / changed / cancelled / refunded /
 * expired. A quick read on funnel drop-off and refund rate.
 */
class BookingsByStatusChart extends ChartWidget
{
    use AuthorizesWithShield;

    protected static ?string $heading = 'Bookings by status (30 days)';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    private const STATUS_COLOURS = [
        Booking::STATUS_HELD => '#94a3b8',
        Booking::STATUS_PENDING_PAYMENT => '#f59e0b',
        Booking::STATUS_CONFIRMED => '#10b981',
        Booking::STATUS_CHANGED => '#3b82f6',
        Booking::STATUS_CANCELLED => '#ef4444',
        Booking::STATUS_REFUNDED => '#a855f7',
        Booking::STATUS_EXPIRED => '#6b7280',
    ];

    protected function getData(): array
    {
        $counts = Cache::remember('dashboard:bookings-by-status', now()->addMinutes(2), function () {
            $raw = Booking::where('created_at', '>=', now()->subDays(30))
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            return collect(self::STATUS_COLOURS)
                ->keys()
                ->mapWithKeys(fn (string $status) => [$status => (int) ($raw[$status] ?? 0)])
                ->all();
        });

        return [
            'datasets' => [[
                'label' => 'Bookings',
                'data' => array_values($counts),
                'backgroundColor' => array_values(self::STATUS_COLOURS),
            ]],
            'labels' => array_map(
                fn (string $status) => ucwords(str_replace('_', ' ', $status)),
                array_keys($counts),
            ),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
