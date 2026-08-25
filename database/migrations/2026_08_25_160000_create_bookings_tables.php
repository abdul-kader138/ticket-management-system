<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_provider_id')->constrained()->restrictOnDelete();
            $table->string('provider_offer_id');
            // Filled once Phase 5 (payment) actually books it with the
            // provider — null for the entire life of a 'held' booking.
            $table->string('provider_order_id')->nullable();
            $table->string('pnr')->nullable();
            // held -> pending_payment -> confirmed -> changed/cancelled/refunded,
            // or held -> expired. See App\Models\Booking::ALLOWED_TRANSITIONS —
            // this column is never written to outside Booking::transitionTo().
            $table->string('status', 20)->default('held');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_price_cents');
            $table->string('cabin_class', 20)->nullable();
            // A price hold is only good until the provider's offer expires —
            // see App\Console\Commands\ExpireStaleBookingHolds.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('booking_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('carrier_iata', 3)->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('flight_number', 10)->nullable();
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->dateTime('departs_at')->nullable();
            $table->dateTime('arrives_at')->nullable();
            $table->string('cabin_class', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('traveler_profile_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            // Snapshotted from the traveler profile at booking time — a
            // later profile edit (e.g. a corrected passport number) must
            // not silently rewrite the passenger details of a ticket
            // that's already been issued.
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('ticket_number')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 30);
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('booking_segments');
        Schema::dropIfExists('bookings');
    }
};
