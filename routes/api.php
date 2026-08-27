<?php

use App\Http\Controllers\Api\V1\Account\TwoFactorController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\BookingChangeController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TravelerProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Named literally "verification.verify" at the top level, outside every
// prefix/name group below — Illuminate\Auth\Notifications\VerifyEmail::
// verificationUrl() looks up that exact global route name to build the
// signed link it emails out (see App\Notifications\Auth\VerifyEmail) and
// has no awareness of this app's "api.v1.*" route naming. The URI is still
// placed under /api/v1/auth to match the rest of this file.
//
// Deliberately NOT behind auth:sanctum: an unverified account can no
// longer establish a session at all (login is refused until the email is
// verified — see AuthenticatedSessionController::store()), so requiring a
// session here would be a deadlock. The 'signed' middleware is what
// authenticates the caller instead — the {id}/{hash} pair is validated in
// EmailVerificationController and the whole URL carries a signature that
// can't be forged or replayed past its expiry.
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Public — Stripe/PayPal call this directly, with no session and no
// Sanctum token. Signature verification (see PaymentWebhookController) is
// what authenticates the caller instead. {gateway} is 'stripe' or 'paypal'.
Route::post('/v1/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:payment-webhook')
    ->name('webhooks.payments');

// Versioned from day one — the customer-facing SPA (and any future mobile
// app) consumes /api/v1/*, while /admin/* (Filament) stays session-based
// and unversioned. See docs/ROADMAP.md, Phase 0/1.
Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public — a pricing page doesn't require a login.
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans'])->name('subscription-plans.index');

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/register', [RegisteredUserController::class, 'store'])->name('register');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login')
            ->name('login');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:login')
            ->name('password.email');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

        // Second step of the challenge started by POST /auth/login when
        // the account has 2FA enabled — see
        // AuthenticatedSessionController::store() and
        // TwoFactorChallengeController.
        Route::post('/login/challenge', [TwoFactorChallengeController::class, 'store'])
            ->middleware('throttle:2fa-challenge')
            ->name('login.challenge');

        // auth:sanctum only, NOT 'verified' — these have to stay reachable
        // for an account that is authenticated but not yet verified (the
        // OAuth path can create one, and a session can outlive a later
        // email change). Everything else below requires a verified email.
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');
        });
    });

    // 'verified' gates the entire customer surface: a logged-in account
    // whose email isn't verified gets a 403 ("Your email address is not
    // verified.") from every endpoint in this group. Login itself already
    // refuses an unverified account (see AuthenticatedSessionController),
    // so this is the defence-in-depth layer behind that.
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        })->name('user');

        Route::get('/account', [AccountController::class, 'show'])->name('account.show');
        Route::put('/account', [AccountController::class, 'update'])->name('account.update');
        Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
        Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
        Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

        Route::post('/account/two-factor/setup', [TwoFactorController::class, 'setup'])->name('account.two-factor.setup');
        Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('account.two-factor.confirm');
        Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy'])->name('account.two-factor.destroy');

        Route::apiResource('traveler-profiles', TravelerProfileController::class)
            ->parameters(['traveler-profiles' => 'travelerProfile'])
            ->except(['show']);

        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

        Route::post('/bookings/{booking}/payments', [PaymentController::class, 'store'])->name('bookings.payments.store');
        Route::post('/payments/{payment}/capture-paypal', [PaymentController::class, 'capturePaypal'])->name('payments.capture-paypal');

        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('/bookings/{booking}/change/search', [BookingChangeController::class, 'search'])->name('bookings.change.search');
        Route::post('/bookings/{booking}/change', [BookingChangeController::class, 'store'])->name('bookings.change.store');

        Route::post('/subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::get('/account/subscription', [SubscriptionController::class, 'current'])->name('account.subscription');

        Route::post('/bookings/{booking}/promotions', [PromotionController::class, 'applyToBooking'])->name('bookings.promotions.apply');
        Route::post('/promotions/redeem', [PromotionController::class, 'redeem'])->name('promotions.redeem');
    });
});
