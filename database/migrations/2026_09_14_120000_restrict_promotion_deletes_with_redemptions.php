<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * promotion_redemptions.promotion_id previously cascaded on delete, so
 * removing a Promotion from the admin UI silently destroyed the audit
 * trail of every redemption (who redeemed it, when, how much discount was
 * given). Redemptions are financial history and must outlive the
 * promotion record — deleting a promotion with redemptions should fail,
 * not cascade.
 *
 * // migration-safety: reviewed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
        });

        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->foreign('promotion_id')->references('id')->on('promotions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
        });

        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
        });
    }
};
