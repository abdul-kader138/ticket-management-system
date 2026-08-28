<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FlightSearch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel lives at "/", which is the Filament dashboard route. Staff
 * see the dashboard; customer / self-registered accounts must not (see
 * Dashboard::canAccess()). Without this they'd get a bare 403 when they open
 * the site root — instead, bounce them to the flight search page, which is
 * their real home.
 *
 * Registered in App\Providers\Filament\AdminPanelProvider->authMiddleware(),
 * so auth()->user() is always resolved by the time this runs.
 */
class RedirectNonAdminsFromDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('filament.admin.pages.dashboard') && ! Dashboard::canAccess()) {
            return redirect()->to(FlightSearch::getUrl());
        }

        return $next($request);
    }
}
