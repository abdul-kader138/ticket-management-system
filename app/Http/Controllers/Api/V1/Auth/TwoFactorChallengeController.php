<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The second step of AuthenticatedSessionController::store()'s challenge —
 * this is the only place a challenge token actually establishes a session.
 */
class TwoFactorChallengeController extends Controller
{
    public function store(TwoFactorChallengeRequest $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $cacheKey = "2fa-challenge:{$request->string('challenge_token')->toString()}";
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This challenge has expired. Please log in again.'],
            ]);
        }

        $user = User::find($payload['user_id']);
        $code = trim($request->string('code')->toString());

        $verified = $user && $service->verify($user->two_factor_secret, $code);

        if (! $verified && $user && $service->verifyRecoveryCode($user, $code)) {
            $service->consumeRecoveryCode($user, $code);
            $verified = true;
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor code was invalid.'],
            ]);
        }

        // Single-use regardless of outcome shape below — a code that just
        // worked shouldn't also be replayable against the same challenge.
        Cache::forget($cacheKey);

        Auth::guard('web')->login($user, $payload['remember']);
        $request->session()->regenerate();

        return (new CustomerResource($user))->response();
    }
}
