<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            // percent (value = 0-100), fixed (value = cents off), or
            // free_search_bonus (value = extra searches granted once,
            // redeemed standalone rather than at checkout) — see
            // App\Models\Promotion and App\Services\Promotions\PromotionService.
            $table->string('type', 20);
            $table->unsignedInteger('value');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null for a standalone free_search_bonus redemption — only a
            // checkout (percent/fixed) redemption is tied to a booking.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->timestamps();

            $table->index(['promotion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotions');
    }
};
