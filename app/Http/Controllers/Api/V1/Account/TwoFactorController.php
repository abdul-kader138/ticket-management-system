<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Account\ConfirmTwoFactorRequest;
use App\Models\Setting;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing equivalent of App\Filament\Auth\EditProfile's 2FA
 * actions — same two-step enable (generate, then confirm with a code
 * before it's trusted) since a secret that's never actually been proven to
 * work in the user's authenticator app is worse than no 2FA at all: it
 * would lock them out on their very next login.
 */
class TwoFactorController extends Controller
{
    /**
     * Generates a candidate secret and its QR code — not persisted yet.
     * The client sends the same secret back to confirm(), alongside a code
     * from the authenticator app that actually scanned it.
     */
    public function setup(Request $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $this->ensureTwoFactorIsEnabledPlatformWide();

        if ($request->user()->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 422);
        }

        $secret = $service->generateSecretKey();

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $service->qrCodeSvg($request->user(), $secret),
        ]);
    }

    public function confirm(ConfirmTwoFactorRequest $request, TwoFactorAuthenticationService $service): JsonResponse
    {
        $this->ensureTwoFactorIsEnabledPlatformWide();

        if (! $service->verify($request->string('secret')->toString(), $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor code was invalid.'],
            ]);
        }

        $recoveryCodes = $service->generateRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_secret' => $request->string('secret')->toString(),
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function ensureTwoFactorIsEnabledPlatformWide(): void
    {
        if (! Setting::get('two_factor_enabled', true)) {
            abort(422, 'Two-factor authentication is currently disabled for this application.');
        }
    }
}
