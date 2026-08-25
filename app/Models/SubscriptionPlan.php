<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    // A limit of -1 means unlimited — see App\Services\Flights\SearchQuotaService.
    public const UNLIMITED = -1;

    protected $fillable = [
        'name', 'code', 'price_cents', 'currency', 'billing_interval',
        'daily_search_limit', 'monthly_search_limit', 'benefits', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'daily_search_limit' => 'integer',
            'monthly_search_limit' => 'integer',
            'benefits' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tierRules(): HasMany
    {
        return $this->hasMany(SubscriptionTierRule::class);
    }

    public function hasBenefit(string $key): bool
    {
        return (bool) ($this->benefits[$key] ?? false);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
