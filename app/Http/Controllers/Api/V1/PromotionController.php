<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Promotions\ApplyPromotionToBookingRequest;
use App\Http\Requests\Api\Promotions\RedeemPromotionRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\Promotions\PromotionException;
use App\Services\Promotions\PromotionService;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function applyToBooking(ApplyPromotionToBookingRequest $request, Booking $booking, PromotionService $promotions): JsonResponse
    {
        try {
            $promotions->redeemForBooking($booking, $request->string('code')->toString(), $request->user());
        } catch (PromotionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BookingResource($booking->fresh(['segments', 'passengers'])))->response();
    }

    public function redeem(RedeemPromotionRequest $request, PromotionService $promotions): JsonResponse
    {
        try {
            $promotions->redeemStandalone($request->user(), $request->string('code')->toString());
        } catch (PromotionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Code redeemed.']);
    }
}
