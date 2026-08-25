<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Subscriptions\SubscribeRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Services\Flights\SearchQuotaService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    /**
     * Public — a pricing page doesn't require a login.
     */
    public function plans(): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection(
            SubscriptionPlan::query()->active()->orderBy('price_cents')->get()
        );
    }

    /**
     * Orchestrates SubscriptionService (create the pending record) and
     * PaymentService (charge for it) directly here rather than adding a
     * third service purely to combine two calls — see
     * App\Services\Subscriptions\SubscriptionService's own docblock on why
     * it doesn't depend on PaymentService itself.
     */
    public function store(SubscribeRequest $request, SubscriptionService $subscriptions, PaymentService $payments): JsonResponse
    {
        $plan = SubscriptionPlan::query()->active()->findOrFail($request->integer('plan_id'));
        $subscription = $subscriptions->createPendingSubscription($request->user(), $plan);

        try {
            $result = $payments->chargeForPayable(
                $subscription,
                $plan->price_cents,
                $plan->currency,
                $request->string('gateway')->toString(),
                'subscription_purchase',
            );
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'subscription_id' => $subscription->id,
            'payment_id' => $result['payment']->id,
            ...$result['client_data'],
        ], 201);
    }

    public function current(Request $request, SubscriptionService $subscriptions, SearchQuotaService $quota): JsonResponse
    {
        $user = $request->user();
        $plan = $subscriptions->activePlan($user);
        $tier = $subscriptions->matchedTierRule($user);

        return response()->json([
            'plan' => $plan ? new SubscriptionPlanResource($plan) : null,
            'tier' => $tier?->name,
            'total_spend' => number_format($user->total_spend_cents / 100, 2),
            'search_quota' => $quota->remaining($user),
        ]);
    }
}
