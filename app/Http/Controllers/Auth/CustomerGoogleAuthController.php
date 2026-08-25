<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Customer-side "Sign in with Google" — separate route/callback from
 * App\Http\Controllers\Auth\GoogleAuthController (the admin panel's own),
 * since a customer login must never assign the 'panel_user' role or land
 * in Filament. Shares the same Google OAuth client credentials
 * (config('services.google.*')) but registers its own redirect URI via
 * Socialite's redirectUrl(), since a single Google client can be
 * authorized for multiple redirect URIs.
 */
class CustomerGoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('customer.auth.google.callback'))
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(route('customer.auth.google.callback'))
                ->user();
        } catch (InvalidStateException) {
            return $this->failed('expired');
        } catch (\Throwable) {
            return $this->failed('failed');
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            if (! $user->google_id) {
                $user->forceFill(['google_id' => $googleUser->getId()])->save();
            }
        } else {
            $user = User::create([
                'first_name' => $googleUser->user['given_name'] ?? Str::before($googleUser->getName() ?? $googleUser->getEmail(), ' ') ?: 'Google',
                'last_name' => $googleUser->user['family_name'] ?? Str::after($googleUser->getName() ?? '', ' ') ?: 'User',
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'email_verified_at' => now(),
                'password' => null,
            ]);
        }

        Auth::guard('web')->login($user);
        request()->session()->regenerate();

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/auth/callback?status=success');
    }

    private function failed(string $reason): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/login?error={$reason}");
    }
}
