<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Services\Account\AccountDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    public function update(Request $request): CustomerResource
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
        ]);

        $user = $request->user();
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        $user->fill($data);

        // Changing address invalidates the old verification — re-confirm
        // the new one belongs to the account owner before it's trusted
        // for booking confirmations/receipts.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return new CustomerResource($user);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * GDPR data export (see docs/ROADMAP.md, Phase 10) — everything the
     * account holds, including traveler profiles' passport data.
     */
    public function export(Request $request, AccountDataService $accountData): JsonResponse
    {
        return response()->json($accountData->export($request->user()));
    }

    /**
     * GDPR erasure request. Requires the current password as confirmation —
     * this is destructive-in-effect (every identifying field is
     * overwritten) even though the row itself is anonymized rather than
     * dropped; see AccountDataService::anonymize() for why.
     */
    public function destroy(Request $request, AccountDataService $accountData): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $accountData->anonymize($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Your account has been deleted.']);
    }
}
