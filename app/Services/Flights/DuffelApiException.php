<?php

namespace App\Services\Flights;

use Illuminate\Http\Client\Response;
use RuntimeException;

class DuffelApiException extends RuntimeException
{
    public static function fromResponse(?Response $response): self
    {
        if (! $response) {
            return new self('Could not reach the flight search provider.');
        }

        $message = $response->json('errors.0.message')
            ?? $response->json('errors.0.title')
            ?? 'The flight search provider returned an error.';

        return new self("{$message} (HTTP {$response->status()})");
    }
}
