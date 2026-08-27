<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    // How long a "code sent, waiting for the 6-digit entry" window stays
    // valid — matches the 5-minute window Filament's own admin login
    // challenge effectively gives via the session (see App\Filament\Auth\Login).
    private const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Session-cookie login for the Sanctum SPA — same 'web' guard the
     * Filament panel uses, so a customer session and an admin session are
     * indistinguishable at the auth layer; only role/permission (checked by
     * canAccessPanel()) decides what either one can reach.
     *
     * Credentials are validated WITHOUT logging in whenever a 2FA challenge
     * is needed (same two-step shape as App\Filament\Auth\Login) — the
     * session is only established once the code verifies, via
     * TwoFactorChallengeController::store().
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($request->only('email', 'password'));

        if (! $user || ! $provider->validateCredentials($user, $request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // An unverified account cannot sign in. Credentials were correct,
        // so quietly send a fresh verification link (the one from
        // registration may have expired) and tell the client to route the
        // user to a "check your email" screen. No session is established
        // and 2FA is not even attempted.
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => "Your email address isn't verified yet. We've sent you a new verification link — check your inbox.",
                'code' => 'email_unverified',
            ], 403);
        }

        if ($this->requiresTwoFactor($user)) {
            $token = Str::random(40);

            Cache::put("2fa-challenge:{$token}", [
                'user_id' => $user->id,
                'remember' => (bool) $request->boolean('remember'),
            ], now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $token,
            ]);
        }

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return (new CustomerResource($user))->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    private function requiresTwoFactor(User $user): bool
    {
        return (bool) Setting::get('two_factor_enabled', true) && $user->hasEnabledTwoFactorAuthentication();
    }
}
