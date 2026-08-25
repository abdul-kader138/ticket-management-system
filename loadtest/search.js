// k6 load test for the highest-QPS surface in the app: flight search.
// See docs/ROADMAP.md, Phase 9. Not run as part of CI or this repo's own
// test suite — it needs a real staging environment (a sandboxed dev
// container has no representative network/DB/Redis topology to make its
// numbers meaningful) and a logged-in session cookie.
//
// Usage:
//   1. Log into the app in a browser against the target environment,
//      copy the `ticket_management_system_session` cookie value.
//   2. k6 run -e BASE_URL=https://staging.example.com -e SESSION_COOKIE=... loadtest/search.js
//
// What to watch: p95 latency, and specifically how much of it is cache
// hits (FlightProviderManager's 5-minute cache, see docs/ROADMAP.md, Phase
// 2) vs. genuine provider calls — a search endpoint that's fast only
// because every run hits the same cached route/date isn't representative.
// Vary ORIGIN/DESTINATION per iteration in a real run to test real load on
// the provider-call path and the Phase 3 quota counters.

import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        steady: {
            executor: 'constant-arrival-rate',
            rate: 20, // requests per second — adjust to the traffic level being tested
            timeUnit: '1s',
            duration: '2m',
            preAllocatedVUs: 50,
            maxVUs: 200,
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<2000'],
        http_req_failed: ['rate<0.01'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const SESSION_COOKIE = __ENV.SESSION_COOKIE || '';

const ROUTES = [
    ['LHR', 'JFK'], ['CDG', 'LHR'], ['JFK', 'LAX'], ['SIN', 'HND'], ['DXB', 'LHR'],
];

export default function () {
    const [origin, destination] = ROUTES[Math.floor(Math.random() * ROUTES.length)];
    const date = '2027-06-15';

    const payload = {
        trip_type: 'oneway',
        legs: [{ from: origin, to: destination, date }],
        adults: 1,
        cabin_class: 'economy',
    };

    const res = http.post(`${BASE_URL}/flights/search`, payload, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Cookie: `ticket_management_system_session=${SESSION_COOKIE}`,
        },
    });

    check(res, {
        'status is 200 or 302': (r) => r.status === 200 || r.status === 302,
        'not rate limited': (r) => r.status !== 429,
    });

    sleep(1);
}
