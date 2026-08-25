<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Polymorphic so a subscription (Phase 7) can reuse the same
            // gateway/refund plumbing as a booking, without a second set of
            // payment tables.
            $table->morphs('payable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            // Stripe PaymentIntent id / PayPal order id — null until the
            // gateway call that creates it actually succeeds.
            $table->string('gateway_reference')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            // One booking can retry payment more than once (card declined,
            // etc.); each attempt gets its own idempotency key so a network
            // retry of the SAME attempt can't double-charge, while a
            // genuinely new attempt after a decline is free to try again.
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('gateway_reference');
            $table->index(['user_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('gateway_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            // The gateway's own event id — deduping on this (not just
            // storing every delivery) is what makes replay-safe processing
            // possible, since both Stripe and PayPal retry undelivered
            // webhooks and can send the same event more than once.
            $table->string('event_id');
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
