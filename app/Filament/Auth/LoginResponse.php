<?php

namespace App\Filament\Auth;

use App\Filament\Pages\FlightSearch;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * The panel is the site's only login now (see App\Filament\Auth\Login), and
 * most people signing in are flight-search customers rather than staff —
 * land them on the search page instead of the admin dashboard.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->intended(FlightSearch::getUrl());
    }
}
