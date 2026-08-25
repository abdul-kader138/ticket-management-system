// k6 load test for the burstiest surface in the app: payment webhooks.
// See docs/ROADMAP.md, Phase 9. Stripe/PayPal deliver retries in bursts
// (e.g. after an outage on either side), so this simulates a spike of
// deliveries rather than steady traffic. Requires a valid webhook secret
// for the target environment to produce a signature PaymentWebhookController
// will actually accept — see App\Services\Payments\StripeGateway::
// verifyWebhookSignature(). Without a real secret this only measures the
// cost of the immediate signature-rejection path (still useful: that
// rejection must stay cheap, since it's what an attacker/fuzzer hits too).
//
// Usage:
//   k6 run -e BASE_URL=https://staging.example.com loadtest/webhooks.js
//
// What to watch: this endpoint must return fast (verify + dedupe + dispatch
// only — see docs/ROADMAP.md, Phase 5's "webhook endpoint is dumb on
// purpose") regardless of how backed up the queue processing the actual
// business logic gets. A slow response here risks the gateway giving up
// and retrying even a delivery that arrived fine.

import http from 'k6/http';
import { check } from 'k6';

export const options = {
    scenarios: {
        burst: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '10s', target: 100 }, // sudden spike
                { duration: '30s', target: 100 },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<500'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';

export default function () {
    const eventId = `evt_loadtest_${__VU}_${__ITER}`;
    const payload = JSON.stringify({
        id: eventId,
        type: 'payment_intent.succeeded',
        data: { object: { id: `pi_loadtest_${__VU}_${__ITER}`, amount_received: 1000 } },
    });

    const res = http.post(`${BASE_URL}/api/v1/webhooks/payments/stripe`, payload, {
        headers: { 'Content-Type': 'application/json' },
    });

    // Expect 400 (bad/missing signature) in a run with no real secret —
    // the check here is that it fails FAST, not that it succeeds.
    check(res, {
        'responded': (r) => r.status !== 0,
    });
}
