<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Every panel user (staff and customer-role accounts alike) shares the same
 * `users` table and `web` guard as the flight search feature, so this page
 * just embeds routes/web.php's /flights/embed in an iframe rather than
 * reimplementing the search form as native Filament components — it keeps
 * the admin sidebar/topbar around the existing Alpine-driven search UI
 * without a full rewrite.
 */
class FlightSearch extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Search Flights';

    protected static ?string $title = 'Search Flights';

    protected static ?string $slug = 'flights';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.flight-search';
}
