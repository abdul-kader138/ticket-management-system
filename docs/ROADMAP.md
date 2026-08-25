# Flightpath Roadmap — Implementation & Feature Plan

From admin panel to airline retailer. A phased plan for taking the current Laravel/Filament admin shell to a full self-serve flight booking platform — registration, search, booking, cancel/change, Stripe + PayPal payment, tiered subscriptions with search quotas, multi-provider flight sourcing, and admin-managed roles, promotions and settings — sized for **~50,000 users**.

- Codebase today: Laravel 13 · Filament 3 · Spatie Permission/Shield/Activitylog · Duffel (single provider)
- Assessed: 2026-08-25
- Also published as an artifact: https://claude.ai/code/artifact/bcbc8249-d995-4214-9d43-760733f98ccf

---

## 1. Where the code stands today

The repository is presently an **internal admin shell**, not a booking platform. It's a solid foundation to extend rather than replace.

### What already exists

- Filament 3 admin panel with 2FA, Google OAuth login, avatar uploads
- Spatie `permission` + `filament-shield` — roles/permissions CRUD is already generated for every Filament resource
- Spatie `activitylog` wired into `User` and `Setting`
- A typed, cached key/value `Setting` store (`app/Models/Setting.php`) driving a System Settings page (branding, mail, 2FA toggle, flight API credentials)
- One flight provider — `App\Services\Flights\DuffelClient` — hardcoded to Duffel's REST API, search-only (offer request), no booking/order/payment calls
- A single flight search screen embedded in the Filament panel via iframe (`/flights/embed`, `App\Http\Controllers\FlightSearchController`)
- `react` + `react-dom` already added to `package.json` (unused so far) — signals intent for a richer customer-facing UI

### What's missing entirely

- No customer-facing registration/login — `User::canAccessPanel()` gates the only auth surface to staff with roles/permissions
- No booking, order, ticket, or payment tables — search is fire-and-forget, nothing is persisted
- No cancel/change/refund flow of any kind
- No payment gateway integration (Stripe/PayPal)
- No subscription, plan, or benefit model
- No per-user search quota/rate limiting — flight search is a paid API call with no throttle today
- No abstraction over flight providers — Duffel specifics leak into the controller/view layer
- No promotions/coupons
- Queue driver is `database` and cache/session are `database`-backed — fine at current scale, a bottleneck at 50k users

---

## 2. Architecture decisions

Three calls shape every phase below. Made explicit here so the roadmap can be reviewed against them rather than re-litigated per feature.

### Two apps, one codebase

Keep **Filament as the admin/ops console** (staff, roles, settings, providers, plans, promotions, moderation) and add a **separate customer-facing app** for registration, search, booking and account management. Do not bolt customer flows onto Filament — its auth gate, theming and resource conventions are built for internal tools, not a public retail funnel.

**Recommendation:** customer app = **React SPA** (Vite, already scaffolded) talking to a **Laravel API** secured with **Sanctum** (SPA cookie auth, not personal access tokens, to keep CSRF protection and httpOnly sessions). This matches the `react`/`react-dom` dependencies already sitting in `package.json` and gives a real API boundary for a future mobile app. A Blade + Livewire alternative is viable and cheaper to staff, but discard the unused React dependency if that path is chosen instead.

### Separate identity from authorization

`User` currently conflates "person" with "has a Filament role." Introduce a clear split: any registered person is a `User`; **staff** are users with a Spatie role/permission (unchanged, already works); **customers** are users with none, plus a new `customer_profile` and a `subscription`. `canAccessPanel()` stays exactly as-is — it already correctly locks Filament to permissioned users.

### Everything money- or provider-facing goes through a service + queue boundary

Booking, payment capture, ticket issuance and provider search must never happen inline in a request/response cycle beyond the initial synchronous call the user is waiting on. Webhooks, retries, and reconciliation are queue jobs from day one — retrofitting this after go-live on financial data is far more expensive than building it in.

---

## 3. Domain & data model

New tables grouped by domain. Existing tables (`users`, `settings`, `roles`, `permissions`, `activity_log`) are extended, not replaced.

### Identity & access (extends existing)

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Add customer fields | `phone`, `date_of_birth`, `passport_number` (encrypted), `nationality`, `marketing_opt_in`, `total_spend_cents` (denormalized, maintained by observer) |
| `roles` / `permissions` | Unchanged — Shield already gives admins a UI to create roles and assign granular permissions per Filament resource/page | — |
| `traveler_profiles` | Saved passengers on an account (self + frequent companions) for faster checkout | `user_id`, `first_name`, `last_name`, `dob`, `passport_number` (encrypted), `passport_expiry`, `nationality` |

### Flight sourcing

| Table | Purpose | Key columns |
|---|---|---|
| `flight_providers` | Registry of pluggable search/booking APIs (replaces hardcoded Duffel Settings) | `code`, `driver_class`, `credentials` (encrypted json), `is_enabled`, `priority`, `environment` |
| `search_requests` | Every search a user runs — powers quota accounting and analytics | `user_id`, `provider_id`, `origin`, `destination`, `dates`, `cabin_class`, `pax`, `counted_against_quota`, `response_ms` |
| `search_cache_entries` | Short-TTL cache of provider responses keyed by search signature (Redis-backed, table only for slow-changing fallback) | `signature_hash`, `provider_id`, `payload`, `expires_at` |

### Booking & fulfillment

| Table | Purpose | Key columns |
|---|---|---|
| `bookings` | One purchased itinerary (maps to a provider order/PNR) | `user_id`, `provider_id`, `provider_order_id`, `pnr`, `status`, `currency`, `total_price_cents`, `expires_at` (hold) |
| `booking_segments` | Flight legs within a booking | `booking_id`, `carrier`, `flight_number`, `origin`, `destination`, `depart_at`, `arrive_at`, `cabin_class` |
| `booking_passengers` | Passengers tied to a booking, linked to traveler_profiles | `booking_id`, `traveler_profile_id`, `ticket_number`, `type` |
| `booking_events` | Audit trail of status transitions (booked → changed → cancelled → refunded) | `booking_id`, `event_type`, `actor_type`, `payload`, `created_at` |

### Payments

| Table | Purpose | Key columns |
|---|---|---|
| `payments` | One payment attempt against a booking or subscription | `payable_type`/`id`, `gateway` (stripe/paypal), `gateway_reference`, `amount_cents`, `currency`, `status`, `idempotency_key` |
| `refunds` | Full/partial refunds against a payment | `payment_id`, `amount_cents`, `reason`, `status`, `gateway_reference` |
| `payment_webhook_events` | Raw inbound webhook log, deduped by gateway event id, processed async | `gateway`, `event_id` (unique), `payload`, `processed_at` |

### Subscriptions & quotas

| Table | Purpose | Key columns |
|---|---|---|
| `subscription_plans` | Admin-defined tiers (Free, Plus, Pro, ...) | `name`, `price_cents`, `billing_interval`, `daily_search_limit`, `monthly_search_limit`, `benefits` (json), `is_active` |
| `subscription_tier_rules` | Automatic tier assignment conditions (spend/tenure based) | `plan_id`, `min_total_spend_cents`, `min_account_age_days`, `priority` |
| `user_subscriptions` | A user's current + historical plan membership | `user_id`, `plan_id`, `source` (manual/auto/purchased), `starts_at`, `ends_at`, `auto_renew` |
| `search_quota_usage` | Rolling counters per user per period (Redis primary, DB for reporting) | `user_id`, `period_type` (day/month), `period_key`, `used_count`, `limit_snapshot` |

### Promotions

| Table | Purpose | Key columns |
|---|---|---|
| `promotions` | Coupon/campaign definitions | `code`, `type` (percent/fixed/free_search_bonus), `value`, `starts_at`, `ends_at`, `usage_limit`, `per_user_limit` |
| `promotion_redemptions` | Who used what, and against which booking/payment | `promotion_id`, `user_id`, `booking_id`, `discount_cents` |

---

## 4. Phased roadmap

Eleven phases (0–10), each shippable and independently valuable. Later phases assume earlier ones are in place; within a phase, backend and admin UI ship together so staff can operate the feature from day one.

### Phase 0 — Platform hardening *(Foundation)*

Nothing below is safe to build on the current infra defaults. Do this first, even though it ships no visible feature.

- Move cache, session and queue off the `database` driver onto **Redis**; add a dedicated queue worker process (already have a systemd unit in `deploy/` — extend it)
- Introduce API layer: install Sanctum, scaffold `routes/api.php`, versioned under `/api/v1`
- Add Laravel Horizon for queue visibility before any payment/webhook jobs exist
- Baseline rate limiting middleware (per-IP and per-user) ready for the search-quota work in Phase 3
- CI: PHPUnit + Pint already present — add a staging environment and migration-safety checks (no destructive migrations without a review flag)

*Unblocks everything else.*

### Phase 1 — Customer identity *(Core)*

A member of the public can create an account, verify email, log in, reset a password, and sign in with Google — independent of the Filament admin login.

- Public registration/login/password-reset API endpoints + React screens; reuse the existing `MustVerifyEmail` contract and 2FA service where it already lives on `User`
- Reuse `GoogleAuthController` pattern for a customer-side OAuth callback (separate redirect route from the admin one)
- Account settings page: profile, saved travelers, password/2FA, notification preferences
- Rate-limit auth endpoints (login attempts, registration) to blunt credential stuffing at 50k-user scale

### Phase 2 — Provider abstraction *(Core)*

Replace the hardcoded `DuffelClient` with a `FlightProvider` interface so a second/third API can be added without touching the search flow.

- `App\Services\Flights\Contracts\FlightProviderContract` defining `search()`, `getOffer()`, `createOrder()`, `cancelOrder()`, `changeOrder()`
- `DuffelClient` refactored to implement it; credentials move from the singleton `Setting` keys to rows in `flight_providers` (supports multiple accounts/environments)
- A provider manager that fans a search out to every enabled provider, merges/dedupes/sorts offers, and tags each offer with its source for booking-time routing
- Admin Filament resource for `flight_providers` (enable/disable, credentials, priority) — Filament Shield already gives this CRUD/permission scaffolding for free
- Redis-cached search results (short TTL, e.g. 3–5 min) keyed by route+date+cabin+pax signature — cuts paid-API calls and is a prerequisite for quota enforcement in Phase 3

### Phase 3 — Search quotas *(Core)*

Every search that reaches a paid provider is metered, budgeted per user, and configurable by an admin — before booking exists, because it's the thing protecting the API bill.

- `search_quota_usage` + Redis counters (atomic `INCR` with day/month TTL) — DB is the audit trail, Redis is the hot path
- Default free-tier limits as admin-editable `Setting` values (e.g. `default_daily_search_limit`), overridden per subscription plan once Phase 7 lands
- Quota middleware in front of the search endpoint: cached results (Phase 2) never consume quota; only genuine provider calls do
- Friendly "X searches left today" UI state, upsell nudge when exhausted

### Phase 4 — Booking *(Core)*

A logged-in user can take a search result to a held itinerary with passenger details attached — no payment yet.

- `bookings`, `booking_segments`, `booking_passengers` + state machine (`held → pending_payment → confirmed → cancelled/changed/refunded`) enforced via a dedicated status enum and guarded transitions, not free-text
- Short-lived price hold using the provider's offer expiry; background job expires stale holds
- Traveler profile picker + passport/DOB capture with field-level encryption
- `booking_events` audit trail feeding the existing Activity Log conventions

### Phase 5 — Payments *(Core)*

Stripe and PayPal both work as checkout options; a confirmed payment is what flips a booking to `confirmed` and triggers ticket issuance with the provider.

- Stripe: PaymentIntents + Elements (never touch raw card numbers), webhook-driven confirmation
- PayPal: Orders v2 API (create → approve client-side → capture server-side), webhook-driven confirmation
- Shared `PaymentGateway` interface so booking/subscription code doesn't branch on gateway; `payments`/`refunds` tables are gateway-agnostic
- Idempotency keys on every capture/charge call; webhook events deduped via `payment_webhook_events.event_id` unique constraint and processed by a queued listener, not inline in the webhook controller
- Reconciliation job comparing local payment status against gateway state nightly

> **PCI scope:** both integrations are chosen specifically so raw card data never touches this app's servers — Stripe Elements/PayPal's hosted flows keep the project at SAQ-A, not full PCI-DSS.

### Phase 6 — Cancel & change *(Core)*

Post-purchase servicing — the other half of "buy a ticket" that most MVPs skip and then bolt on badly.

- Cancel flow: calls provider `cancelOrder()`, computes refund per fare rules, issues via the Phase 5 `PaymentGateway::refund()`
- Change flow: re-search same route with new dates via the provider's change API where supported (Duffel: order change offers), fare-difference charged as a new `payments` row against the same booking
- Admin override tools: manual refund, manual booking status correction, with mandatory reason + activity log entry
- Customer-facing self-service cancel/change screens with fare-rule-aware messaging (refundable vs. non-refundable, change fees)

### Phase 7 — Subscriptions & tiers *(Growth)*

Admin defines plans and the rules that assign users to them automatically; plans grant real benefits enforced by Phase 3's quota engine.

- `subscription_plans` Filament resource: name, price, billing interval, search limits, benefit flags (priority support, fee-free changes, fare-drop alerts, etc.)
- Purchasable plans billed via Stripe/PayPal subscriptions (recurring), reusing the Phase 5 gateway abstraction
- `subscription_tier_rules`: a scheduled job evaluates each user's `total_spend_cents` (maintained by a booking-confirmed observer) and account age against admin-defined thresholds, auto-upgrading loyalty tiers independent of paid plans
- Precedence rule when both a purchased plan and an earned tier apply: highest benefit wins per-benefit, not whole-plan override
- Account page shows current tier, progress to next tier, and remaining quota

### Phase 8 — Promotions *(Growth)*

Marketing gets a self-serve tool instead of one-off DB edits.

- `promotions` Filament resource: percentage/fixed discounts, bonus search-quota grants, date windows, usage caps (total and per-user)
- Coupon code entry at checkout, validated server-side against `promotion_redemptions` to prevent replay/limit abuse
- Referral variant: unique per-user code, reward on referred user's first confirmed booking

### Phase 9 — Scale-out & observability *(Hardening)*

Prove the 50k-user target before it's tested in production by real load.

- Read replica for reporting/admin list views; write traffic stays on primary
- Full audit of N+1 queries across Filament resources and API endpoints (Laravel Debugbar / Telescope in staging)
- APM + error tracking (Sentry) and structured logging on payment/booking/webhook paths specifically
- Load test the search endpoint (highest QPS surface) and the webhook endpoints (burstiest) separately
- Evaluate Laravel Octane for the API app once profiling shows request bootstrap overhead matters

### Phase 10 — Compliance close-out *(Hardening)*

The regulatory and trust items that don't block launch but block staying open.

- GDPR/data-subject request tooling (export/delete) covering traveler passport data specifically
- Data retention policy for `search_requests` and `payment_webhook_events` (high-volume, low-value-per-row tables)
- Formal backup/restore drill for the booking and payments tables
- Terms/refund-policy versioning tied to bookings (which policy applied at time of purchase)

---

## 5. Subscriptions & search quotas, in detail

This is the most bespoke piece of the system — worth spelling out because "how much does this user get to search" depends on three independent inputs.

1. **Base allowance** — a `Setting`-driven default (e.g. 10 searches/day) applied to every account with no active plan. Admin-editable without a deploy.
2. **Purchased plan** — a `subscription_plans.daily_search_limit`/`monthly_search_limit` that replaces the base allowance while the plan is active and paid.
3. **Earned tier** — `subscription_tier_rules` matched against lifetime spend / account age, applied automatically and combinable with a purchased plan on a per-benefit basis.

### Resolution order at request time

```
effective_limit = max(
    base_setting_limit,
    active_purchased_plan?.daily_search_limit,
    matched_tier_rule?.daily_search_limit
)

# Redis: INCR search:quota:{user_id}:{YYYY-MM-DD}, EXPIRE at UTC midnight
# only incremented when a request actually reaches a live provider —
# cache hits from Phase 2 are free and don't touch this counter
```

The `user_subscriptions` table keeps history (so "why did this user's limit change" is answerable), while Redis holds the hot counter that actually gates requests — a DB round-trip per search would not survive 50k concurrent users.

---

## 6. Multi-provider flight sourcing

Airlines' own GDS/NDC feeds vary in speed, coverage and cost — retail platforms at scale always source from more than one aggregator. The interface below is the seam that makes adding a second one a config change, not a rewrite.

```php
interface FlightProviderContract {
    public function search(SearchCriteria $criteria): OfferCollection;
    public function getOffer(string $offerId): Offer;
    public function createOrder(Offer $offer, PassengerSet $pax): ProviderOrder;
    public function cancelOrder(string $providerOrderId): CancellationResult;
    public function changeOrder(string $providerOrderId, SearchCriteria $newCriteria): ChangeOfferCollection;
}

// DuffelClient implements FlightProviderContract   (exists today, search-only — extend it)
// AmadeusClient implements FlightProviderContract   (candidate #2 — broader GDS coverage)
// SabreClient implements FlightProviderContract     (candidate #3)
```

- **Credentials move to data, not code** — each row in `flight_providers` holds its own encrypted API key/secret and environment, so sandbox and live credentials for the same provider (or multiple accounts of the same provider) coexist.
- **Fan-out search** — the provider manager calls every `is_enabled` provider in turn, merges offers by a normalized fare/segment shape, and tags each with `provider_id` so booking routes back to the right API. Sequential for now, since `FlightProviderContract::search()` is a single synchronous call and only one provider (Duffel) is actually implemented — there's nothing to run *concurrently* with yet. Making the fan-out concurrent (Laravel's `Http::pool()`) needs splitting the contract into a request-build step and a response-parse step per driver, which is real surgery across every implementation; worth doing once a second provider makes sequential latency an actual cost, not before.
- **Graceful degradation** — one provider timing out or erroring must not fail the whole search; log and return partial results.
- **Booking stays provider-bound** — once a user picks an offer, all subsequent calls (order, change, cancel) go to that same provider by `provider_id` stored on the `booking`.

---

## 7. Payments: Stripe & PayPal

Both gateways sit behind one interface so booking and subscription billing code is written once.

| Concern | Stripe | PayPal |
|---|---|---|
| One-time booking payment | PaymentIntents + Stripe Elements (card entry never touches our servers) | Orders v2: create → client-side approve → server-side capture |
| Recurring subscription billing | Stripe Billing/Subscriptions | PayPal Subscriptions API |
| Refunds | Refunds API, partial or full | Refund API against the capture id |
| Async confirmation | Webhook: `payment_intent.succeeded`, `charge.refunded`, etc. | Webhook: `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED` |
| Signature verification | Stripe-Signature header, verified before queueing | PayPal webhook signature verification API call |

- **Never trust the client-side "success" callback alone** — a booking only moves to `confirmed` when the corresponding webhook is verified and processed, closing the classic "closed the tab after paying" gap.
- **Idempotency** — every charge/capture call carries an idempotency key derived from the booking id + attempt number, so a retried request (network blip, double-click) can't double-charge.
- **Webhook endpoint is dumb on purpose** — verify signature, store the raw event in `payment_webhook_events` (unique on gateway event id), dispatch a queued job, return 200 immediately. All business logic runs in the job, replayable if it fails.

---

## 8. Scaling to 50,000 users

50k registered users doesn't mean 50k concurrent, but it does mean the current single-box, database-driven cache/session/queue setup needs to change before the customer-facing surfaces above go live.

### Infrastructure

- Redis for cache, session, and queue (replacing the `database` driver for all three)
- Horizontal app servers behind a load balancer; sessions in Redis make this stateless-safe immediately
- Horizon-monitored queue workers scaled independently from web workers — booking/payment jobs shouldn't compete with a traffic spike on search
- MySQL/Postgres primary + read replica once the admin/reporting read load is measurable

### Application

- Cache provider search responses (Phase 2) — the paid API is both the cost driver and the latency floor
- Eager-load consistently in Filament resources and API resources; audit with Telescope/Debugbar before launch, not after a slow-query report
- Queue every external call that isn't the one thing the user is actively waiting on: ticket issuance, receipt emails, webhook processing, tier recalculation
- Index `search_requests`, `bookings`, and `search_quota_usage` on the columns the quota and reporting queries actually filter by (user_id + period_key, user_id + status)

> **Sequencing note:** Phase 0's Redis migration is cheap now and expensive to retrofit once bookings/payments depend on session and cache behavior — it's placed first for that reason, not because it's exciting.

---

## 9. Security & compliance checklist

### Already in place

- 2FA (TOTP) via `TwoFactorAuthenticationService`, admin-toggleable firm-wide
- Encrypted-at-rest 2FA secrets/recovery codes via Eloquent casts
- Activity logging on `User` and `Setting` changes (extend the same pattern to bookings/payments/refunds)
- Role/permission separation via Spatie + Shield, already resource-scoped

### To add

- Field-level encryption for passport numbers/DOB on `traveler_profiles` (same `encrypted` cast pattern already used for 2FA secrets)
- PCI scope kept to SAQ-A by never handling raw card data server-side (Stripe Elements / PayPal hosted approval)
- Rate limiting on auth and search endpoints (Phase 0/1/3)
- Webhook signature verification for both gateways, mandatory before any queue dispatch
- GDPR export/delete covering traveler and payment history (Phase 10)

---

## 10. Suggested sequencing

Relative effort, not calendar dates — sized in engineer-weeks for a small team (2–3 backend, 1–2 frontend). Reorder within a track freely; don't start a later phase before its dependencies are checked off.

| Phase | Depends on | Relative effort | Ships |
|---|---|---|---|
| 0 · Platform hardening | — | 1–2 wks | Nothing user-visible |
| 1 · Customer identity | 0 | 2 wks | Public sign-up/login |
| 2 · Provider abstraction | 0 | 2–3 wks | Faster, cached search; second API ready to plug in |
| 3 · Search quotas | 2 | 1–2 wks | API cost protected |
| 4 · Booking | 1, 2 | 3–4 wks | Hold an itinerary end-to-end |
| 5 · Payments | 4 | 3–4 wks | Actually sell a ticket |
| 6 · Cancel & change | 5 | 2–3 wks | Full purchase lifecycle |
| 7 · Subscriptions & tiers | 3, 5 | 2–3 wks | Monetized quota upgrades |
| 8 · Promotions | 5, 7 | 1–2 wks | Marketing self-serve |
| 9 · Scale-out | ongoing | interleaved | Survives real traffic |
| 10 · Compliance close-out | 4, 5 | 1–2 wks | Safe to stay open |
