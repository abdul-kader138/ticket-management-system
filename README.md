# Flightpath

A self-serve flight booking platform built on Laravel 13 and Filament 3 —
search, book, pay, cancel and change flights, with tiered subscriptions,
per-user search quotas, promotions and referrals. The repository directory
is still named `ticket-management-system` for historical reasons; the
application is a flight retailer.

The full architecture and the phased plan it was built to are in
[`docs/ROADMAP.md`](docs/ROADMAP.md).

## Tech stack

- **Backend:** PHP 8.3+, Laravel 13
- **Admin/ops console:** Filament 3 (`/` — session auth, gated by Spatie
  roles/permissions via Filament Shield)
- **Customer API:** `/api/v1/*` — Laravel Sanctum SPA cookie auth, versioned
- **Frontend:** React 19 + Vite + Tailwind (customer SPA, scaffolded)
- **Queue/cache/session:** Redis, with Laravel Horizon supervising workers
- **Flight sourcing:** Duffel today, behind a `FlightProviderContract` so
  more providers are a `flight_providers` row, not a code change
- **Payments:** Stripe (PaymentIntents + Elements) and PayPal (Orders v2),
  behind a shared `PaymentGatewayContract`; webhook-driven confirmation
- **Auth:** email/password, Google OAuth (Socialite), TOTP 2FA
- **Observability:** Sentry (opt-in via `SENTRY_LARAVEL_DSN`), Spatie
  activity log, dedicated `audit` log channel on money/booking paths

## Key domains

| Area | Where |
|---|---|
| Flight search + provider abstraction | `app/Services/Flights` |
| Search quotas (Redis counters, DB audit trail) | `app/Services/Flights/SearchQuotaService.php` |
| Booking lifecycle (state machine + events) | `app/Services/Bookings`, `app/Models/Booking.php` |
| Payments, refunds, webhooks, reconciliation | `app/Services/Payments`, `app/Jobs` |
| Subscriptions + auto-assigned loyalty tiers | `app/Services/Subscriptions` |
| Promotions, coupons, referral rewards | `app/Services/Promotions`, `app/Observers/BookingObserver.php` |
| Admin resources | `app/Filament/Resources` |

## Getting started

### Prerequisites

- PHP 8.3+, Composer
- Node.js + npm
- Redis (cache, session, queue)
- MySQL 8+ (SQLite is used for the test suite only)

### Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# Set DB_* and REDIS_* in .env, create the database, then:
php artisan migrate

# Seed roles/permissions and the default super admin (see ADMIN_* in .env)
php artisan db:seed

npm run dev      # local
# npm run build  # production
```

### Running locally

```bash
composer dev
```

That runs `php artisan serve`, a queue worker, `pail` log tailing, and Vite
concurrently. Or run the pieces yourself:

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

The admin/ops console is at `/`. The customer API is under `/api/v1`.

## Background processing

Queued work (webhook processing, ticket issuance, tier recalculation) and
scheduled commands (`bookings:expire-holds`, `payments:reconcile`,
`subscriptions:expire`, `data:prune-retention`) both need to be running in
any non-local environment:

- **Production:** the systemd units in `deploy/` — a Horizon supervisor and
  a `schedule:run` timer — installed by `deploy.sh`.
- **Local:** `composer dev` covers the queue; run `php artisan schedule:work`
  in another terminal if you need the scheduled commands.

Horizon's dashboard is at `/horizon` (permission-gated).

## Payments configuration

Stripe and PayPal credentials are set in the admin console under **System
Settings → Payments**, not in `.env`. Each gateway is inert until both its
API keys and its webhook signing secret are present. Point the gateways'
webhooks at:

```
POST /api/v1/webhooks/payments/stripe
POST /api/v1/webhooks/payments/paypal
```

Confirmation is always webhook-driven — a client-side "payment succeeded"
callback never confirms a booking on its own.

## Tests & code style

```bash
php artisan test        # PHPUnit, SQLite in-memory
vendor/bin/pint         # format
vendor/bin/pint --test  # check formatting (CI does this)
```

CI (`.github/workflows/ci.yml`) runs Pint and the test suite on every push
and PR, and flags destructive migrations added in a PR unless the migration
carries a `// migration-safety: reviewed` sign-off.

## License

Proprietary. All rights reserved.
