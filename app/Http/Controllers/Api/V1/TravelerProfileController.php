<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TravelerProfiles\StoreTravelerProfileRequest;
use App\Http\Requests\Api\TravelerProfiles\UpdateTravelerProfileRequest;
use App\Http\Resources\TravelerProfileResource;
use App\Models\TravelerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TravelerProfileController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TravelerProfileResource::collection(
            $request->user()->travelerProfiles()->orderBy('first_name')->get()
        );
    }

    public function store(StoreTravelerProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->travelerProfiles()->create($request->validated());

        return (new TravelerProfileResource($profile))->response()->setStatusCode(201);
    }

    public function update(UpdateTravelerProfileRequest $request, TravelerProfile $travelerProfile): TravelerProfileResource
    {
        $travelerProfile->update($request->validated());

        return new TravelerProfileResource($travelerProfile);
    }

    public function destroy(Request $request, TravelerProfile $travelerProfile): JsonResponse
    {
        $this->authorize('delete', $travelerProfile);

        $travelerProfile->delete();

        return response()->json(['message' => 'Traveler removed.']);
    }
}
