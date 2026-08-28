<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BookingsByStatusChart;
use App\Filament\Widgets\LatestBookings;
use App\Filament\Widgets\OperationalHealthStats;
use App\Filament\Widgets\PlatformOverviewStats;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\WelcomeHeaderWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * The dashboard is staff-only. Self-registered / customer accounts hold
     * just the base `panel_user` role with no permissions — they land on
     * FlightSearch instead (see LoginResponse and the panel home URL;
     * App\Http\Middleware\RedirectNonAdminsFromDashboard catches a direct
     * hit on "/"). Returning false also hides it from the navigation.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && ($user->hasRole('super_admin') || $user->getAllPermissions()->isNotEmpty());
    }

    /**
     * Ordering comes from each widget's own $sort. Every widget past the
     * welcome header is Shield-gated (see AuthorizesWithShield) and simply
     * doesn't render for a role that lacks its permission — so this list is
     * safe to hand to every panel user.
     */
    public function getWidgets(): array
    {
        return [
            WelcomeHeaderWidget::class,
            PlatformOverviewStats::class,
            OperationalHealthStats::class,
            RevenueChart::class,
            BookingsByStatusChart::class,
            LatestBookings::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
