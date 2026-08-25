<?php

namespace App\Services\Flights\DTO;

/**
 * Deliberately not normalized into a provider-agnostic shape yet — with a
 * single live provider (Duffel) there's no second shape to normalize
 * against, and guessing at one now risks building the wrong abstraction.
 * $raw is Duffel's own offer object; resources/views/flights/results.blade.php
 * already reads it directly via data_get(), so toArray() merges the
 * provider tag into that same shape instead of replacing it — adding a
 * second provider is what should force this to become a real mapper.
 */
final class Offer
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $providerCode,
        public readonly array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw + [
            'provider_code' => $this->providerCode,
            'provider_offer_id' => $this->id,
        ];
    }
}
