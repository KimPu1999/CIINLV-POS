# CIINLV-POS Marketplace Implementation Roadmap

## Status

Phase 1 is complete. Phase 2 foundation is implemented but its migration is intentionally waiting for a live PostgreSQL review/approval before execution.

## Phase 1 — audit and architecture

### Completed

- Inspected repository layout and confirmed separate backend/ and frontend/ applications.
- Inspected Laravel version/dependencies, API routes, middleware, authentication, policies, services, models, resources, migrations, storage, configuration, and tests.
- Inspected React routing, auth context, API client, i18n, dashboards, and package scripts.
- Confirmed and retained the separate marketplace-frontend/ storefront application.
- Confirmed existing products/variants/inventory/POS are tenant-scoped sources of truth.
- Confirmed no online payment provider SDK or verified marketplace webhook integration is present.
- Documented existing PostgreSQL RLS gap instead of claiming RLS protection.
- Added the Phase 1 design documents in docs/marketplace/.

### Not run during Phase 1

- No database connection, query, migration, schema mutation, seed, or reset was run.
- Phase 1 itself made no application changes; Phase 2 changes are listed below.
- Backend tests were not executed against the unavailable local PostgreSQL service. The project has previously reported connection refusal on 127.0.0.1:5432; live schema verification must happen after PostgreSQL is available.
- No browser or end-to-end marketplace verification is possible because the marketplace does not yet exist.

## Phase 2 — database and marketplace foundation

### Implemented

- Added the reversible marketplace foundation migration and Eloquent models/relations.
- Added public marketplace catalog/store/category/search APIs with approved, active, published, available filtering.
- Added customer session registration/login/logout/profile/address APIs.
- Added vendor store/listing APIs with tenant context and cross-business ownership checks.
- Added Super Admin store approval/listing APIs and dedicated marketplace audit records.
- Connected marketplace-frontend catalog requests to `/api/v1/marketplace/products`, `/stores`, and `/categories`; locale changes refetch catalog data with `lang=en|my`.
- Added backend feature coverage in `tests/Feature/MarketplaceFoundationTest.php`.

### Migration gate

The migration has not been executed. The configured PostgreSQL server at `127.0.0.1:5432` currently refuses connections. After PostgreSQL is running and a backup/approval is available, review with `php artisan migrate:status`, then apply only the additive migration with `php artisan migrate --path=database/migrations/2026_10_20_180000_create_marketplace_foundation_tables.php`.

The remaining work after the Phase 2 foundation is:

1. Review `php artisan migrate:status` against the target PostgreSQL database without changing it.
2. Apply the additive migration only after PostgreSQL is running and backup/approval is confirmed.
3. Add any reviewed product/listing image upload flow without reusing private verification media.
4. Do not auto-publish or auto-translate existing products. Create marketplace records only through explicit vendor opt-in.

### Phase 2 exit gate

- Migrations pass on a PostgreSQL test database.
- Existing POS, auth, tenant, inventory, reporting, and security tests remain green.
- Cross-tenant reads/writes are denied.
- Suspended/archived businesses cannot publish or receive new marketplace orders.
- Public listing resources expose no private fields.

## Phase 3 — customer marketplace frontend

The marketplace-frontend storefront foundation already exists and is connected to the Phase 2 read APIs. Future frontend work includes:

- React/TypeScript/Vite/Tailwind/React Router.
- Typed API client with credentialed Sanctum behavior compatible with the approved customer identity model.
- Shared CIINLV brand tokens, en/my resources, theme, accessibility, loading, empty, and error states.
- Homepage, shop, category/store/listing detail, search/filter, login/register/profile, cart, checkout, success, history, tracking, wishlist, contact/support.
- No mock production inventory or fake payment success.

### Phase 3 exit gate

- Public pages show only API-backed approved records.
- Language changes update immediately and persist according to the approved mechanism.
- Responsive/accessibility checks pass at mobile, tablet, and desktop breakpoints.
- Customer cannot access another customer's records.

## Phase 4 — Business Admin marketplace management

- Add marketplace navigation and screens to the existing Business Admin application.
- Add store profile/banner/logo and participation settings.
- Add listing publication, image management, availability, and moderation status.
- Add vendor order, shipment, fulfillment, and marketplace reports.
- Use the existing tenant context and role policies.

### Phase 4 exit gate

- A business admin can manage only the selected tenant.
- Existing POS catalog/inventory values remain intact.
- Public publication is blocked for inactive/unapproved businesses.

## Phase 5 — multi-vendor checkout and payment

- Add cart validation and server-side total calculation.
- Create master/vendor orders atomically.
- Add inventory reservations using row locks and the canonical inventory service.
- Add checkout idempotency and retry semantics.
- Integrate one approved payment provider, including signed webhooks and reconciliation.
- Add COD only if approved.

### Phase 5 exit gate

- Simultaneous POS and marketplace purchase tests cannot oversell.
- Duplicate checkout/payment/webhook events do not duplicate orders or stock mutations.
- Payment state cannot be marked successful from a browser-only callback.
- Receipt/order totals equal authoritative server calculations.

## Phase 6 — fulfillment and tracking

- Add vendor-order state transitions, shipment records, carrier/tracking data, cancellation, partial cancellation if approved, refund, and stock restoration.
- Add customer-facing timeline and vendor fulfillment screens.
- Add notifications only after channel/retention decisions.

## Phase 7 — Super Admin marketplace oversight

- Add vendor approval/suspension, listing moderation, order/payment/refund monitoring.
- Add commission configuration and immutable ledger.
- Add payout reconciliation; keep automatic payouts disabled until the provider/settlement process is approved.
- Add disputes/support and marketplace audit views.

## Phase 8 — production readiness

- Frontend unit/component/accessibility tests and production build.
- Laravel feature/unit tests, policy tests, cross-tenant tests, inventory concurrency tests, payment idempotency tests, webhook signature/replay tests.
- Secure upload and media access tests.
- Rate limiting, structured logging, monitoring, and alerting.
- Backup/restore and rollback documentation.
- Environment and deployment documentation.
- No automatic deployment.

## Local macOS workflow

The existing application can be run without applying marketplace changes:

    cd /Users/kimpu/Desktop/CIINLV-POS/backend
    composer install
    php artisan serve --host=127.0.0.1 --port=8000

In another terminal:

    cd /Users/kimpu/Desktop/CIINLV-POS/frontend
    npm install
    npm run dev -- --host=127.0.0.1

For a real database-backed test run, start the locally configured PostgreSQL service first, verify the database and test database exist, then run only the approved additive migrations and tests. Do not run migrate:fresh, migrate:refresh, db:wipe, destructive seeders, or production migration commands without explicit approval.

The marketplace-frontend install/dev/build commands are documented in that directory and use the approved API base URL and locale query contract.

## Review gate

Phase 2 code is ready for PostgreSQL migration review:

- docs/marketplace/architecture.md
- docs/marketplace/requirements.md
- docs/marketplace/database-design.md
- docs/marketplace/api-contract.md
- docs/marketplace/implementation-roadmap.md

Please confirm PostgreSQL availability, backup state, and migration approval before applying the additive migration. No production migration was run by this implementation.
