<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Duffel order-change confirmation
    |--------------------------------------------------------------------------
    |
    | App\Services\Flights\DuffelClient::confirmChangeOffer() posts to
    | /air/order_changes with a payload that has never been exercised against
    | Duffel's live or sandbox API from this codebase — it was written to
    | mirror the shape of the order-change-request call, not from a verified
    | round trip. Until someone runs a real change end to end against Duffel
    | and confirms the endpoint/payload/response, that method refuses to run
    | so an unverified request can't reach production silently.
    |
    | Flip this to true (DUFFEL_ORDER_CHANGE_CONFIRMATION_VERIFIED=true) only
    | after that verification has actually happened.
    |
    */

    'duffel' => [
        'order_change_confirmation_verified' => env('DUFFEL_ORDER_CHANGE_CONFIRMATION_VERIFIED', false),
    ],

];
