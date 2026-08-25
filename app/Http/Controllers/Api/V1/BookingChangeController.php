<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bookings\ApplyChangeRequest;
use App\Http\Requests\Api\Bookings\SearchChangeOffersRequest;
use App\Models\Booking;
use App\Services\Bookings\BookingChangeService;
use App\Services\Bookings\BookingException;
use App\Services\Flights\DTO\SearchCriteria;
use Illuminate\Http\JsonResponse;

class BookingChangeController extends Controller
{
    public function search(SearchChangeOffersRequest $request, Booking $booking, BookingChangeService $changes): JsonResponse
    {
        $slices = [];

        foreach ($request->input('legs') as $index => $leg) {
            $origin = SearchCriteria::extractIataCode($leg['from']);
            $destination = SearchCriteria::extractIataCode($leg['to']);

            if (! $origin || ! $destination) {
                return response()->json([
                    'message' => "Leg {$index}: please pick an airport from the suggestions for both departure and arrival.",
                ], 422);
            }

            $slices[] = ['origin' => $origin, 'destination' => $destination, 'departure_date' => $leg['date']];
        }

        $criteria = new SearchCriteria(slices: $slices, adults: (int) $request->input('adults'));

        try {
            $offers = $changes->searchOffers($booking, $criteria);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $offers->toArray()]);
    }

    public function store(ApplyChangeRequest $request, Booking $booking, BookingChangeService $changes): JsonResponse
    {
        try {
            $result = $changes->applyChange(
                $booking,
                $request->string('change_offer_id')->toString(),
                $request->user(),
                $request->string('gateway')->toString(),
            );
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment_id' => $result['payment']?->id,
            ...$result['client_data'],
        ], 201);
    }
}
