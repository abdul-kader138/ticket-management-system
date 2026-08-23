<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class WelcomeHeaderWidget extends Widget
{
    protected static string $view = 'filament.widgets.welcome-header';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function getUser(): ?User
    {
        return Filament::auth()->user();
    }

    public function getGreeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
