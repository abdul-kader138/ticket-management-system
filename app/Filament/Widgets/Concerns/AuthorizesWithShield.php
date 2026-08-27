<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Gates a dashboard widget the same way App\Filament\Pages\SystemSettings
 * gates itself: the configured super admin role always sees it, everyone
 * else needs the `widget_<ClassName>` permission that
 * `php artisan shield:generate` mints for it (see ShieldSeeder, which runs
 * that command and syncs every permission to super_admin).
 */
trait AuthorizesWithShield
{
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $superAdminName = (string) config('filament-shield.super_admin.name', 'super_admin');

        if ((bool) config('filament-shield.super_admin.enabled', true) && $user->hasRole($superAdminName)) {
            return true;
        }

        return $user->can('widget_'.class_basename(static::class));
    }
}
