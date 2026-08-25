<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'day' rows key on 'Y-m-d', 'month' rows key on 'Y-m' — see
            // App\Services\Flights\SearchQuotaService. Two rows per user
            // per search (one of each granularity) rather than one row with
            // two counters, so either window can be queried/reported on
            // without the other's period boundary getting in the way.
            $table->string('period_type', 5);
            // 'Y-m-d' (10 chars) for day rows, 'Y-m' for month rows.
            $table->string('period_key', 10);
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('limit_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_quota_usage');
    }
};
