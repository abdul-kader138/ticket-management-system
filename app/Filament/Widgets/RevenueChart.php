<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWithShield;
use App\Models\Payment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Daily gross captured revenue (succeeded + since-refunded payments, by
 * row creation date) over a selectable window. Gross, not net — the
 * refund line would flatten the shape that makes a trend chart useful;
 * net is the number on PlatformOverviewStats.
 */
class RevenueChart extends ChartWidget
{
    use AuthorizesWithShield;

    protected static ?string $heading = 'Gross revenue';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);

        $rows = Cache::remember("dashboard:revenue-chart:{$days}", now()->addMinutes(5), function () use ($days) {
            $start = now()->subDays($days - 1)->startOfDay();

            $byDay = Payment::whereIn('status', [
                Payment::STATUS_SUCCEEDED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_PARTIALLY_REFUNDED,
            ])
                ->where('created_at', '>=', $start)
                ->get(['amount_cents', 'created_at'])
                ->groupBy(fn (Payment $p) => $p->created_at->toDateString())
                ->map(fn ($group) => $group->sum('amount_cents'));

            $series = [];

            for ($i = 0; $i < $days; $i++) {
                $date = now()->subDays($days - 1 - $i)->toDateString();
                $series[$date] = ($byDay[$date] ?? 0) / 100;
            }

            return $series;
        });

        return [
            'datasets' => [[
                'label' => 'Revenue',
                'data' => array_values($rows),
                'borderColor' => 'rgba(16, 185, 129, 1)',
                'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(
                fn (string $date) => Carbon::parse($date)->format('M j'),
                array_keys($rows),
            ),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
