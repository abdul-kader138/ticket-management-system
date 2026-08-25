<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SVAR File Manager's RestDataProvider issues plain fetch() calls with no
        // way to attach a CSRF header (see app/Http/Controllers/Panel/DocumentController.php).
        // Auth + per-request ownership checks carry security instead of the token.
        $middleware->validateCsrfTokens(except: [
            'panel-api/documents/*',
        ]);

        // The panel is the site's only login now — see App\Filament\Auth\LoginResponse.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Lets the future customer SPA (see docs/ROADMAP.md) authenticate
        // against /api/v1/* with the same session cookie as the web guard,
        // instead of issuing bearer tokens for a first-party frontend.
        $middleware->statefulApi();

        // Applies the 'api' rate limiter (defined in AppServiceProvider) to
        // every /api/* route by default; individual routes can still opt
        // into a stricter named limiter (e.g. 'login', 'search').
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // No-op until SENTRY_LARAVEL_DSN is set (see config/sentry.php) —
        // see docs/ROADMAP.md, Phase 9.
        Integration::handles($exceptions);
    })->create();
