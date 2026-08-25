<?php

namespace App\Services\Flights\DTO;

/**
 * Result of FlightProviderContract::createOrder() — not persisted anywhere
 * yet (see docs/ROADMAP.md, Phase 4: the `bookings` table is what will
 * store providerOrderId/pnr against a local booking once that phase lands).
 */
final class ProviderOrder
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $bookingReference,
        public readonly string $status,
        public readonly array $raw,
    ) {}
}
