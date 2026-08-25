<?php

namespace App\Services\Flights\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Offer>
 */
final class OfferCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, Offer>  $offers
     */
    public function __construct(private readonly array $offers = []) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<int, Offer>  ...$collections
     */
    public static function merge(OfferCollection ...$collections): self
    {
        $offers = [];

        foreach ($collections as $collection) {
            array_push($offers, ...$collection->offers);
        }

        return new self($offers);
    }

    public function count(): int
    {
        return count($this->offers);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->offers);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Offer $offer) => $offer->toArray(), $this->offers);
    }
}
