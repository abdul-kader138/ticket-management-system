<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bookings\CancelBookingRequest;
use App\Http\Requests\Api\Bookings\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\CancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return BookingResource::collection(
            $request->user()->bookings()->latest()->get()
        );
    }

    public function show(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        return new BookingResource($booking->load(['segments', 'passengers']));
    }

    public function store(StoreBookingRequest $request, BookingService $bookings): JsonResponse
    {
        try {
            $booking = $bookings->createHold(
                $request->user(),
                $request->string('provider_code')->toString(),
                $request->string('offer_id')->toString(),
                $request->input('passengers'),
            );
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    public function cancel(CancelBookingRequest $request, Booking $booking, CancellationService $cancellations): JsonResponse
    {
        try {
            $cancellations->cancel($booking, 'user', $request->user()->id, (string) $request->input('reason', ''));
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BookingResource($booking->fresh(['segments', 'passengers'])))->response();
    }
}
