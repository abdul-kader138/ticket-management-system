<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Resolves the admin panel's configured theme color (System Settings →
 * Appearance) into hex values the public-facing Blade pages can use, so
 * customer-facing pages (flight search, auth) look like part of the same
 * product instead of a separately-styled demo.
 */
class BrandColor
{
    /** @var array<string, array{600: string, 700: string}> */
    private const PALETTE = [
        'indigo' => ['600' => '#4f46e5', '700' => '#4338ca'],
        'amber' => ['600' => '#d97706', '700' => '#b45309'],
        'emerald' => ['600' => '#059669', '700' => '#047857'],
        'rose' => ['600' => '#e11d48', '700' => '#be123c'],
        'violet' => ['600' => '#7c3aed', '700' => '#6d28d9'],
        'sky' => ['600' => '#0284c7', '700' => '#0369a1'],
        'teal' => ['600' => '#0d9488', '700' => '#0f766e'],
        'orange' => ['600' => '#ea580c', '700' => '#c2410c'],
        'slate' => ['600' => '#475569', '700' => '#334155'],
        'gray' => ['600' => '#4b5563', '700' => '#374151'],
        'blue' => ['600' => '#2563eb', '700' => '#1d4ed8'],
        'cyan' => ['600' => '#0891b2', '700' => '#0e7490'],
        'purple' => ['600' => '#9333ea', '700' => '#7e22ce'],
        'pink' => ['600' => '#db2777', '700' => '#be185d'],
    ];

    public static function base(): string
    {
        return self::shades()['600'];
    }

    public static function dark(): string
    {
        return self::shades()['700'];
    }

    /** @return array{600: string, 700: string} */
    public static function shades(): array
    {
        $theme = Setting::get('admin_theme', 'indigo');

        return self::PALETTE[$theme] ?? self::PALETTE['indigo'];
    }

    public static function panelMode(): string
    {
        return Setting::get('admin_panel_theme_mode', 'dark');
    }

    /**
     * The CSS `color-scheme` value for this mode. Native browser-rendered
     * controls — <select> popups, checkboxes, date pickers, scrollbars —
     * are NOT reachable by our page CSS (they're OS/browser chrome, not DOM
     * we can style), so `background`/`color` rules on the trigger element
     * never touch the open dropdown list. `color-scheme` is the one lever
     * that actually retints them to match the page instead of always
     * rendering as a jarring white-on-black popup.
     */
    public static function colorScheme(): string
    {
        return match (self::panelMode()) {
            'light', 'sepia' => 'light',
            'system' => 'light dark',
            default => 'dark',
        };
    }

    /**
     * Background/foreground palette for the public pages, mirroring the six
     * admin_panel_theme_mode options from System Settings → Appearance (see
     * AdminPanelProvider::resolveAdminPanelModeStyles for the Filament-side
     * equivalents of high_contrast/sepia/midnight).
     *
     * @return array{bg: string, fg: string, card: string, border: string, muted: string, hover: string}
     */
    public static function palette(): array
    {
        return match (self::panelMode()) {
            'light' => self::lightPalette(),
            'high_contrast' => [
                'bg' => '#020617', 'fg' => '#f8fafc', 'card' => '#0f172a', 'border' => 'rgba(148,163,184,.22)',
                'muted' => 'rgba(226,232,240,.65)', 'hover' => 'rgba(148,163,184,.12)',
            ],
            'sepia' => [
                'bg' => '#f7efe4', 'fg' => '#4b3621', 'card' => '#f9f2e8', 'border' => 'rgba(120,86,56,.16)',
                'muted' => '#8a7860', 'hover' => '#efe3d2',
            ],
            'midnight' => [
                'bg' => '#0f172a', 'fg' => '#e5e7eb', 'card' => '#111827', 'border' => 'rgba(96,165,250,.18)',
                'muted' => '#93a5c4', 'hover' => 'rgba(96,165,250,.08)',
            ],
            // "system" ships the light palette by default; the dark
            // counterpart is layered on via a prefers-color-scheme media
            // query (see darkPalette()) rather than resolved here, since
            // that choice belongs to the visitor's OS, not the server.
            'system' => self::lightPalette(),
            default => self::darkPalette(),
        };
    }

    /** @return array{bg: string, fg: string, card: string, border: string, muted: string, hover: string} */
    public static function lightPalette(): array
    {
        return ['bg' => '#f3f4f6', 'fg' => '#111827', 'card' => '#ffffff', 'border' => '#e5e7eb', 'muted' => '#6b7280', 'hover' => '#f9fafb'];
    }

    /** @return array{bg: string, fg: string, card: string, border: string, muted: string, hover: string} */
    public static function darkPalette(): array
    {
        return ['bg' => '#030712', 'fg' => '#f3f4f6', 'card' => '#111827', 'border' => '#1f2937', 'muted' => '#9ca3af', 'hover' => '#1f2937'];
    }
}
