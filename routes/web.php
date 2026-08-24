<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\FlightSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // The panel-facing "Search Flights" page (App\Filament\Pages\FlightSearch)
    // owns the pretty /flights URL and embeds this route in an iframe so the
    // form/results keep the admin sidebar around them.
    Route::get('/flights/embed', [FlightSearchController::class, 'index'])->name('flights.embed');
    Route::post('/flights/search', [FlightSearchController::class, 'search'])->name('flights.search.submit');
    Route::get('/flights/airports', [FlightSearchController::class, 'airports'])->name('flights.airports');
});

Route::prefix('admin/auth/google')->name('auth.google.')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');
});
