<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
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
// placed under /api/v1/auth to match the rest of this file. Clicked from
// the email itself as a full browser navigation, not an XHR call, so it
// runs behind the same session the SPA is logged into (auth:sanctum) and
// stays 'signed' so the link can't be replayed or reused past its expiry.
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

// Public — Stripe/PayPal call this directly, with no session and no
// Sanctum token. Signature verification (see PaymentWebhookController) is
// what authenticates the caller instead. {gateway} is 'stripe' or 'paypal'.
Route::post('/v1/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
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

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        })->name('user');

        Route::get('/account', [AccountController::class, 'show'])->name('account.show');
        Route::put('/account', [AccountController::class, 'update'])->name('account.update');
        Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
        Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
        Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

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
