<?php

namespace App\Services\Flights\DTO;

final class CancellationResult
{
    public function __construct(
        public readonly bool $confirmed,
        public readonly ?string $refundAmount,
        public readonly ?string $refundCurrency,
        public readonly array $raw,
    ) {}
}
