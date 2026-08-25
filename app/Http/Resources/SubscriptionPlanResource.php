<?php

namespace App\Http\Resources;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubscriptionPlan
 */
class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'price' => number_format($this->price_cents / 100, 2),
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval,
            'daily_search_limit' => $this->daily_search_limit,
            'monthly_search_limit' => $this->monthly_search_limit,
            'benefits' => $this->benefits ?? [],
        ];
    }
}
