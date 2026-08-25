<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionTierRule extends Model
{
    protected $fillable = [
        'name', 'subscription_plan_id', 'min_total_spend_cents', 'min_account_age_days', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_total_spend_cents' => 'integer',
            'min_account_age_days' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
