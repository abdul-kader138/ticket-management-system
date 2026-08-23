<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\WelcomeHeaderWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            WelcomeHeaderWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 3;
    }
}
