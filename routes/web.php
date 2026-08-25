<?php

use App\Http\Controllers\Auth\CustomerGoogleAuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\FlightSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // The panel-facing "Search Flights" page (App\Filament\Pages\FlightSearch)
    // owns the pretty /flights URL and embeds this route in an iframe so the
    // form/results keep the admin sidebar around them.
    Route::get('/flights/embed', [FlightSearchController::class, 'index'])->name('flights.embed');
    // Ceiling only, not the real quota engine — see AppServiceProvider's
    // 'search' limiter and docs/ROADMAP.md, Phase 3.
    Route::post('/flights/search', [FlightSearchController::class, 'search'])
        ->middleware('throttle:search')
        ->name('flights.search.submit');
    Route::get('/flights/airports', [FlightSearchController::class, 'airports'])->name('flights.airports');
});

Route::prefix('admin/auth/google')->name('auth.google.')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');
});

// Customer-facing "Sign in with Google" — a full browser redirect flow
// (OAuth can't be an XHR call), separate from the admin one above so a
// customer login never assigns the admin's 'panel_user' role. See
// App\Http\Controllers\Auth\CustomerGoogleAuthController.
Route::prefix('auth/google')->name('customer.auth.google.')->group(function () {
    Route::get('/redirect', [CustomerGoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [CustomerGoogleAuthController::class, 'callback'])->name('callback');
});
