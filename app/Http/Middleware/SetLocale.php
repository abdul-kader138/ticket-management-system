<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = auth()->user()?->locale;

        if (! $locale) {
            try {
                $locale = Schema::hasTable('settings')
                    ? Setting::get('default_locale', config('app.locale', 'en'))
                    : config('app.locale', 'en');
            } catch (\Throwable) {
                $locale = config('app.locale', 'en');
            }
        }

        App::setLocale(in_array($locale, User::SUPPORTED_LOCALES, true) ? $locale : 'en');

        return $next($request);
    }
}
