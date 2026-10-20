# CIINLV POS — Backend

Phase 1 backend foundation for the CIINLV POS multi-tenant Point of Sale and inventory platform.

## Stack

- Laravel 13.37 / PHP 8.3+
- PostgreSQL 18+
- Laravel Sanctum 4
- Versioned JSON API under `/api/v1`
- Redis-compatible configuration is included for future cache/queue use

## macOS prerequisites

```bash
brew install php composer postgresql@18 redis
brew services start postgresql@18
```

If using Postgres.app, start it from the application and use its bundled `psql`/`pg_ctl` binaries.

Verify the required tools and PHP driver:

```bash
php -v
composer --version
psql --version
php -m | grep -E 'pdo_pgsql|pgsql'
```

## Database setup

Generate a password locally and create the role/database. Do not commit the password:

```bash
openssl rand -base64 32
psql -h 127.0.0.1 -p 5432 -d postgres
```

Run this SQL in `psql`, replacing the password with the generated value:

```sql
CREATE ROLE ciinlv_user LOGIN PASSWORD 'replace-with-generated-password';
CREATE DATABASE ciinlv_pos OWNER ciinlv_user;
```

Copy `.env.example` to `.env`, set `DB_PASSWORD`, and generate the application key:

```bash
cp .env.example .env
php artisan key:generate
```

The local `.env` is ignored by Git. Production secrets must come from the deployment secret manager.

## Install and run

```bash
composer install
php artisan migrate
php artisan serve
```

The API is available at `http://127.0.0.1:8000`.

## English/Myanmar localization

The React app sends the selected locale as `lang=en|my` on API requests and stores the user preference both per user in local storage and in `users.locale`. Laravel validates the locale and falls back to the canonical source value when a translation is unavailable.

Apply the additive migrations, then start a worker for asynchronous translation jobs:

```bash
php artisan migrate
php artisan queue:work --tries=3 --timeout=120
```

Translation records are tenant-scoped in `translations` and use `pending`, `automatic`, `reviewed`, or `failed` states. Existing values are never replaced. To prepare existing data safely and repeatably:

```bash
php artisan translations:backfill
php artisan translations:backfill --tenant=1 --run-now
php artisan translations:status
```

The default `TRANSLATION_DRIVER=none` intentionally leaves alternate-language values pending. Configure a server-side translation service with `TRANSLATION_DRIVER`, `TRANSLATION_API_URL`, and `TRANSLATION_API_KEY`; the provider receives only translatable text and locale metadata. The API never sends provider credentials to the browser. Authorized business administrators and inventory managers can review translations through `GET /api/v1/translations` and `PATCH /api/v1/translations/{id}`.

Localized product/category/supplier/business resources accept `?lang=my` or `?lang=en`. Stable IDs, SKUs, prices, financial values, emails, phone numbers, legal business names, and historical transaction snapshots remain unchanged.

## API smoke tests

```bash
curl -i http://127.0.0.1:8000/api/v1/health
curl -i -H 'Accept: application/json' http://127.0.0.1:8000/api/v1/me
curl -i -H 'Accept: application/json' http://127.0.0.1:8000/api/v1/not-found
```

`/api/v1/me` requires a valid Sanctum bearer token. The token can be issued in a later authenticated user flow; no insecure placeholder business API was added in Phase 1.

## Tests

Tests use the separate PostgreSQL database `ciinlv_pos_test`. Create it once:

```bash
createdb -h 127.0.0.1 -p 5432 -O ciinlv_user ciinlv_pos_test
php artisan test
```

The test suite covers the health response, database connectivity, Sanctum-protected `/me`, and JSON API 404 behavior.

## Phase 2 authentication

Web clients use the `web` session guard through Sanctum. A React SPA should first request `/sanctum/csrf-cookie`, then send credentialed requests with the CSRF header; do not store session or bearer credentials in `localStorage`. Configure `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, and `CORS_SUPPORTS_CREDENTIALS=true` for the deployed SPA. Production must use HTTPS and `SESSION_SECURE_COOKIE=true`.

Mobile clients send `client_type=mobile` and a validated `device_name` to `/api/v1/auth/login`. The response contains a device-specific Sanctum token with minimal `auth:mobile` ability and a 30-day expiry by default. Store it only in Flutter secure platform storage. `/api/v1/auth/logout` revokes the current token.

Authentication endpoints:

```text
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/auth/me
POST /api/v1/auth/change-password
POST /api/v1/auth/forgot-password
POST /api/v1/auth/reset-password
```

Provision the first platform administrator interactively; no default credentials are created:

```bash
php artisan ciinlv:create-super-admin
```

Platform management requires a Sanctum-authenticated user with the database-backed `users.platform_role=super_admin`. Business APIs require an active `tenant_memberships` row with a tenant-scoped `business_admin` role. The server validates `X-Tenant-Id` against that membership; changing the header cannot cross tenant boundaries.

Platform dashboard and tenant management APIs:

```text
GET    /api/v1/platform/dashboard
GET    /api/v1/platform/tenants
POST   /api/v1/platform/tenants
GET    /api/v1/platform/tenants/{uuid}
PATCH  /api/v1/platform/tenants/{uuid}
POST   /api/v1/platform/tenants/{uuid}/suspend
POST   /api/v1/platform/tenants/{uuid}/activate
POST   /api/v1/platform/tenants/{uuid}/admins
GET    /api/v1/platform/tenants/{uuid}/admins
PATCH  /api/v1/platform/tenants/{uuid}/admins/{userId}
DELETE /api/v1/platform/tenants/{uuid}/admins/{userId}
```

Tenant and store primary keys remain the Phase 1 integer keys for compatibility. New unique UUID columns are used as public API identifiers and route keys. PostgreSQL RLS remains a planned defense-in-depth layer; application tenant context and policy checks are active now, with RLS deferred until the connection-role and transaction-local context design is finalized.

## Phase 3 business management

Super Admin business APIs use public tenant UUIDs:

```text
GET    /api/v1/platform/businesses
POST   /api/v1/platform/businesses
GET    /api/v1/platform/businesses/{uuid}
PATCH  /api/v1/platform/businesses/{uuid}
POST   /api/v1/platform/businesses/{uuid}/activate
POST   /api/v1/platform/businesses/{uuid}/suspend
GET    /api/v1/platform/businesses/{uuid}/admins
POST   /api/v1/platform/businesses/{uuid}/admins
PATCH  /api/v1/platform/businesses/{uuid}/admins/{userId}
DELETE /api/v1/platform/businesses/{uuid}/admins/{userId}
POST   /api/v1/platform/businesses/{uuid}/admins/{userId}/resend-invitation
POST   /api/v1/platform/businesses/{uuid}/admins/{userId}/revoke-invitation
GET    /api/v1/platform/businesses/{uuid}/audit-logs
```

Business administrators use the validated business context header:

```text
GET   /api/v1/business/profile
PATCH /api/v1/business/profile
GET   /api/v1/business/settings
PATCH /api/v1/business/settings
GET   /api/v1/business/members
GET   /api/v1/business/dashboard
```

For users with multiple memberships, send `X-Tenant-Id: <business-uuid>`. The header is only a context selector; every request is checked against the authenticated user’s active membership, role, and tenant status. Suspended tenants and revoked memberships are rejected even when a session or Sanctum token remains valid.

Business creation is atomic and creates the tenant, default settings, normalized business-admin role, membership, invitation record, and audit event in one transaction. Send an `Idempotency-Key` header to make retries return the original persisted business instead of creating another one. Business codes are unique and are also enforced at the database layer.

Invitation tokens are stored only as SHA-256 hashes. Activation is handled by:

```text
POST /api/v1/auth/invitations/accept
```

Invitation email delivery is queued after commit. Configure a real mail transport before production use. For local development, use `MAIL_MAILER=log`, `BUSINESS_INVITATION_EXPIRY_HOURS=24`, and `QUEUE_CONNECTION=sync` (or run a queue worker when using Redis/database queues). The complete activation link is written to `storage/logs/laravel.log` by Laravel's log mailer; it is never returned by the API and only a SHA-256 token digest is stored in PostgreSQL. Do not use the log mailer in production.

### Gmail SMTP and the first real invitation

The committed configuration intentionally remains on the local `log` mailer. To send a real message, edit the uncommitted `backend/.env` privately after enabling 2-Step Verification on the Gmail account and creating a Google 16-character App Password. Do not use the normal Gmail password, paste either credential into source control, or send it in chat:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_USERNAME=your-gmail-address@gmail.com
MAIL_PASSWORD=your-16-character-google-app-password
MAIL_FROM_ADDRESS=your-gmail-address@gmail.com
MAIL_FROM_NAME="CIINLV POS"
FRONTEND_URL=https://your-public-frontend.example
```

For an immediate local test, keep `QUEUE_CONNECTION=sync`. For durable delivery, use the database or Redis queue configured for the environment and run a worker:

```bash
php artisan config:clear
php artisan queue:work --tries=3 --timeout=120
php artisan queue:failed
php artisan queue:retry all
```

With `MAIL_MAILER=log`, the link is written only to `storage/logs/laravel.log` for local development. With SMTP, a successful invitation is marked `sent`; transport failures are recorded as `failed` and rethrown so the queued job is visible through `queue:failed`. The API never returns the raw token or its database hash. After a successful mail test, remove or rotate the local App Password if it was created only for development.

Before sending the first real invitation from an existing installation, inspect the migration status and apply the pending additive migration only after approving the schema change:

```bash
php artisan migrate:status
php artisan migrate --force
php artisan config:clear
```

Then sign in as a Super Admin in the React app, create a business with the real administrator email, and check the invitation status in the response or Business Admin list. For a local database queue, start `php artisan queue:work --tries=3 --timeout=120` before creating the business. The recipient should open the configured `FRONTEND_URL/accept-invitation/{token}` link, set a password of at least 12 characters, and sign in at `/login`; acceptance activates only the invited membership.

The invitation lifecycle is:

- `pending`: an unexpired, unrevoked invitation;
- `sent`: the notification transport completed successfully;
- `failed`: delivery threw an exception; resend creates a fresh token;
- `accepted`: the one-time token has activated the membership;
- `expired`: the configured expiry time has passed;
- `revoked`: a platform administrator cancelled the invitation.

Use `GET /api/v1/auth/invitations/{token}` from the activation page to preview the invited business and email. Use `POST /api/v1/auth/invitations/accept` to activate a new account. If the email already belongs to an active account, that user must sign in first; the acceptance request then activates only the pending membership and does not change their password.

Platform invitation management is restricted to the existing Super Admin middleware:

```text
POST /api/v1/platform/businesses/{business}/admins/{user}/resend-invitation
POST /api/v1/platform/businesses/{business}/admins/{user}/revoke-invitation
```

The React activation route is `/accept-invitation/{token}`. The token is passed in the URL only from the mail link, not returned from business-creation, resend, preview, or acceptance JSON responses.

Phase 3 added `business_settings`, `business_invitations`, and `business_idempotency_keys`, plus tenant contact/business-code/country fields, membership inviter tracking, and platform audit history. Existing Phase 1/2 routes remain available for compatibility.

Useful commands:

```bash
php artisan migrate
php artisan route:list --path=api/v1/platform/businesses
php artisan test
php artisan queue:work
```

Example platform request after obtaining a mobile Sanctum token:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/platform/businesses \
  -H "Authorization: Bearer $CIINLV_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: business-a-2026-unique-key' \
  -d '{
    "business_name": "Business A",
    "business_code": "BUS-A",
    "admin_name": "Admin A",
    "admin_email": "admin-a@example.com",
    "currency_code": "MMK",
    "timezone": "Asia/Yangon"
  }'
```

Example tenant-scoped request:

```bash
curl http://127.0.0.1:8000/api/v1/business/profile \
  -H "Authorization: Bearer $CIINLV_ADMIN_TOKEN" \
  -H 'X-Tenant-Id: <business-uuid>' \
  -H 'Accept: application/json'
```

## Phase 4 branches and dashboards

Branch APIs use the same `X-Tenant-Id` context selector. The server resolves that header against the authenticated user’s active business membership; clients cannot choose a tenant by sending an arbitrary ID. Business admins can manage all branches in their selected business. Store managers, inventory managers, and cashiers must also have an active direct store assignment.

```text
GET    /api/v1/business/stores
POST   /api/v1/business/stores
GET    /api/v1/business/stores/{storeUuid}
PATCH  /api/v1/business/stores/{storeUuid}
POST   /api/v1/business/stores/{storeUuid}/activate
POST   /api/v1/business/stores/{storeUuid}/suspend
GET    /api/v1/business/stores/{storeUuid}/settings
PATCH  /api/v1/business/stores/{storeUuid}/settings
GET    /api/v1/business/stores/{storeUuid}/members
POST   /api/v1/business/stores/{storeUuid}/members
PATCH  /api/v1/business/stores/{storeUuid}/members/{membershipId}
DELETE /api/v1/business/stores/{storeUuid}/members/{membershipId}
GET    /api/v1/business/stores/{storeUuid}/dashboard
GET    /api/v1/business/stores/{storeUuid}/dashboard/layout
PUT    /api/v1/business/stores/{storeUuid}/dashboard/layout
GET    /api/v1/business/stores/{storeUuid}/dashboard/widgets
PUT    /api/v1/business/stores/{storeUuid}/dashboard/widgets
GET    /api/v1/me/stores
GET    /api/v1/me/permissions
```

Create a branch with actual persisted values:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/business/stores \
  -H "Authorization: Bearer $CIINLV_ADMIN_TOKEN" \
  -H "X-Tenant-Id: <business-uuid>" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Main Store","store_code":"MAIN-001","currency_code":"MMK","timezone":"Asia/Yangon"}'
```

Dashboard APIs return the configured widgets plus branch-scoped metrics computed from completed sales, verified payments, refunds, inventory balances, staff assignments, and suppliers. If there are no records, the API returns zero or empty values; it does not generate demo data. Supported widget keys are configured in `config/business.php`, disabled widgets are omitted, and role-aware responses exclude widgets not authorized for the current store assignment.

Trial branch limits default to four. Set `BUSINESS_ACTIVE_BRANCH_LIMIT` or `BUSINESS_DEFAULT_BRANCH_LIMIT` when a deployment uses a different subscription policy. The tenant row is locked during provisioning so concurrent requests for one tenant serialize limit checks; the database’s tenant/code unique constraint remains the final duplicate-code guard.

Phase 4 adds `store_settings`, direct `store_memberships` assignment fields, `dashboard_widgets`, and `dashboard_layouts`. The original Phase 2 store pivot columns remain for compatibility and its legacy `tenant_membership_id` is now nullable for direct assignments. Internal tenant/store integer primary keys are preserved; public UUID columns continue to be used in API routes. Store suspension retains data and immediately blocks non-admin branch access. All branch mutations and assignment/dashboard changes create audit records without credentials or tokens.

Phase 4 verification commands:

```bash
php artisan migrate --force
APP_ENV=testing php artisan migrate --force
php artisan route:list --path=api/v1/business/stores
php artisan test
vendor/bin/pint --test
```

## Phase 8 production readiness

Phase 8 adds read-only security and inventory audits, readiness checks, security headers, explicit CORS allowlists, configurable API/export throttles, encrypted PostgreSQL backup/restore tooling, Docker/Nginx/Redis/queue/scheduler configuration, a non-deploying GitHub Actions workflow, and a production runbook.

The legacy health contract remains unchanged:

```text
GET /api/v1/health
```

The deployment readiness endpoint checks PostgreSQL and optionally Redis:

```bash
curl -i http://127.0.0.1:8000/api/v1/health/ready
HEALTHCHECK_REDIS=true php artisan config:clear
```

`GET /api/v1/health/ready` returns HTTP 503 if a required dependency is unavailable, without exposing credentials or connection strings.

### Security and data-integrity audits

Run these read-only checks:

```bash
php artisan ciinlv:security-audit
php artisan ciinlv:inventory-reconcile
php artisan ciinlv:backup-health --path=storage/app/private/backups
psql "$DATABASE_URL" -f scripts/audit-postgres-security.sql
```

The current local audit result is important: the application role `ciinlv_user` is not a superuser, does not have `BYPASSRLS`, and cannot create roles. PostgreSQL currently reports zero RLS policies and RLS is disabled on the audited tenant-owned tables. Application policies, tenant context, composite foreign keys, and store authorization are active, but RLS is not yet production-enabled. RLS must be introduced in a staging environment with transaction-local tenant context, `FORCE ROW LEVEL SECURITY` where appropriate, separate owner/runtime roles, background-job context tests, and a tested rollback before production activation.

The inventory reconcile command is report-only. It compares `inventory_balances.on_hand_quantity` with the append-only `stock_movements` ledger and never repairs data automatically; corrections must use the existing audited inventory services.

The dedicated concurrency smoke harness is restricted to a database whose name ends in `_test`:

```bash
set -a; source .env.testing; set +a
CIINLV_CONCURRENCY_DB=ciinlv_pos_test php scripts/concurrency-smoke.php
```

It uses separate PostgreSQL connections and forked workers, then removes only its temporary probe table. It verifies the database locking primitive used by inventory services; full staging load tests still belong in the isolated performance environment.

### Authentication and production configuration

React uses Sanctum’s first-party cookie/session flow and CSRF protection; Flutter uses short-lived, device-specific bearer tokens stored in platform secure storage. Bearer tokens must not be stored in browser local storage. Production must set:

```dotenv
APP_DEBUG=false
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=https://app.example.com
CORS_ALLOWED_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Accept,Authorization,Content-Type,X-CSRF-TOKEN,X-Requested-With,Idempotency-Key,X-Tenant-Id
SANCTUM_TOKEN_EXPIRATION_MINUTES=43200
```

The development `.env` intentionally has `APP_DEBUG=true` and insecure-local cookie defaults. Do not copy those values to production. `deploy/.env.production.example` is a safe placeholder template; it contains no usable secrets.

API exceptions are JSON-formatted. With production debug disabled, unexpected exceptions return a generic message and are logged server-side without stack traces or secrets. Validation, authentication, authorization, rate-limit, and not-found responses retain their intended HTTP statuses.

### Backups and restore verification

The backup script uses PostgreSQL custom format and encrypts database and private-storage archives with OpenSSL AES-256-CBC/PBKDF2. The encryption key must come from a secret manager. Product images, private receipts, and report files under `storage/app/private` are included, while the backup directory itself is excluded to avoid recursive archives.

```bash
set -a; source .env; set +a
export BACKUP_DIR=/secure/backup/path
export BACKUP_ENCRYPTION_KEY='from-secret-manager'
scripts/backup-postgres.sh
scripts/verify-postgres-backup.sh /secure/backup/path/daily/<archive>.dump.enc
scripts/rotate-backups.sh
```

The starting retention policy is 14 daily, 8 weekly, and 12 monthly periods. Configure offsite transfer, encryption-key recovery, and failure alerting outside the application host. `scripts/pitr-postgresql.conf.example` documents WAL archiving and point-in-time recovery; `pg_dump` alone is not PITR.

Restoration is intentionally guarded against production targets:

```bash
export RESTORE_CONFIRMATION=I_UNDERSTAND_ISOLATED_RESTORE
export RESTORE_TARGET_DATABASE=ciinlv_pos_restore
scripts/restore-postgres-backup.sh /secure/backup/path/daily/<archive>.dump.enc
scripts/verify-restored-database.sh
```

The target must end in `_restore`, `_staging`, or `_test`. The Phase 8 verification created a temporary isolated database, restored a real encrypted archive, verified the core tables and reported zero inventory-ledger mismatches, then removed only that temporary database. The existing `ciinlv_pos` database was not modified.

### Docker and operations

`docker-compose.yml` provides local/staging services for Laravel PHP-FPM, Nginx, PostgreSQL, Redis, a queue worker, and a scheduler. PostgreSQL and Redis have no host port published. The `realtime` profile starts the installed Laravel Reverb server after its credentials and allowed origins are configured.

```bash
cp .env.example .env
# Set non-placeholder local values, then:
docker compose up -d --build
curl -i http://127.0.0.1:8080/api/v1/health/ready
docker compose logs --since=10m app queue scheduler nginx
```

Use `deploy/production-runbook.md` for release ordering, migration review, rollback, recovery, and monitoring procedures. The GitHub Actions workflow runs PHP syntax checks, Pint, PostgreSQL migrations/tests, Composer vulnerability audit, and secret scanning. It never deploys automatically.

For isolated staging performance work, run the k6 script with explicit authorized credentials:

```bash
API_URL=https://staging.example.com CIINLV_TOKEN='short-lived-token' K6_VUS=20 K6_DURATION=2m k6 run scripts/load-test.js
psql "$STAGING_DATABASE_URL" -f scripts/explain-reporting.sql
```

No representative staging load test was run in this local Phase 8 execution, so no production latency or throughput claim is made. Load tests must not target production.

### Phase 8 verification status

Verified in this workspace:

- PHP syntax checks completed for the application, configuration, routes, database, and tests.
- Focused Phase 8 suite: **6 tests, 24 assertions passed**.
- PostgreSQL concurrency smoke test: **passed** in the dedicated `_test` database; two forked workers contended for the same locked quantity, one committed 8 units, one was rejected, and final quantity was 2.000.
- Full Phase 1–8 regression suite: **48 tests, 254 assertions passed**.
- Development PostgreSQL migrations: all 29 migrations applied.
- Development PostgreSQL health: PostgreSQL 18.3 reachable; 55 tables present.
- Encrypted `pg_dump` archive creation, checksum verification, and `pg_restore --list`: passed.
- Isolated restore and core table/inventory-ledger verification: passed; temporary restore database removed afterward.
- Runtime role audit: non-superuser, no `BYPASSRLS`, no `CREATEROLE`.

Open production blockers:

- PostgreSQL RLS is not enabled; application-level isolation is active, but RLS staging design/verification remains required.
- Reverb is installed and configured with an origin allowlist, but browser WebSocket delivery still requires an authorized client and a running Redis/realtime environment.
- No managed offsite backup destination, WAL archive, monitoring/alert provider, TLS certificate, or secret manager is configured locally.
- No representative staging load test was run; p95 objectives remain unmeasured.
- The local development `.env` is not production-safe by design (`APP_DEBUG=true`); use the deployment template and secret manager for production.

Phase 8 stops at backend verification and deployment preparation. No production deployment or frontend/mobile work is performed.

PostgreSQL RLS remains prepared but not enabled. Application middleware, policies, tenant/store foreign keys, composite tenant/store integrity, and database constraints are active. Enabling RLS safely requires a non-owner application role plus transaction-local tenant context and pool reset behavior, which must be deployed together.

## Phase 5 inventory, purchasing, and stock management

Phase 5 adds tenant-scoped categories, products, variants, barcodes, suppliers, warehouses, store stockroom locations, inventory balances, an append-only stock movement ledger, purchase receiving, stock transfers, adjustments, and inventory reports. Existing stores receive a corresponding `inventory_locations` stockroom record; newly provisioned stores create one automatically.

Inventory quantities are changed only through transactional backend services. Balances use row locking, non-negative quantity constraints, idempotency keys, and weighted-average costing (`inventory_balances.average_cost`). Product edits never change stock. Stock transfers reserve source stock, deduct it only at dispatch, and add destination stock only at receipt. Transfer receipts are recorded in `stock_transfer_receipts` so retries cannot post the same receipt twice.

Phase 5 APIs include:

```text
GET|POST|PATCH|DELETE /api/v1/categories
GET|POST|PATCH|DELETE /api/v1/products
GET                 /api/v1/products/barcode/{barcode}
POST                /api/v1/products/{product}/variants
POST                /api/v1/products/{product}/images
GET|POST|PATCH|DELETE /api/v1/suppliers
GET|POST|PATCH      /api/v1/warehouses
GET                 /api/v1/inventory
GET                 /api/v1/inventory/locations/{location}
GET                 /api/v1/inventory/movements
GET                 /api/v1/inventory/low-stock
GET                 /api/v1/inventory/out-of-stock
POST                /api/v1/inventory/opening-stock
GET|POST            /api/v1/inventory/adjustments
GET|POST            /api/v1/purchase-orders
POST                /api/v1/purchase-orders/{order}/submit
POST                /api/v1/purchase-orders/{order}/receive
GET|POST            /api/v1/stock-transfers
POST                /api/v1/stock-transfers/{transfer}/submit|approve|dispatch|receive|cancel
GET                 /api/v1/reports/inventory
GET                 /api/v1/reports/stock-movements
GET                 /api/v1/reports/stock-transfers
GET                 /api/v1/reports/stock-valuation
```

Use `X-Tenant-Id` only to select an already-authorized tenant context. Location access is checked against the authenticated membership and, for store-scoped users, their active store assignment. Every receiving, adjustment, and transfer receipt request requires an `Idempotency-Key` header. No credentials, tokens, or secrets are written to audit metadata.

Phase 5 verification commands:

```bash
php artisan migrate --force
APP_ENV=testing php artisan migrate --force
vendor/bin/pint --test
php artisan test
php artisan route:list --path=api/v1/inventory
php artisan serve --host=127.0.0.1 --port=8000
```

The test database must be separate from development and production databases. Never use `migrate:fresh`, `db:wipe`, or destructive reset commands against a real environment. PostgreSQL RLS remains prepared but is not enabled; application tenant/location authorization and database constraints are active until a non-owner RLS role and connection-pool context reset strategy are deployed together.

## Phase 6 POS sales, payments, receipts, and returns

Phase 6 adds tenant/store-scoped POS registers, register cashier assignments, cashier shifts, cash movements, immutable sale snapshots, split payments, receipt records/PDFs, returns, refunds, and POS reports. Internal integer keys remain compatible with earlier phases; public API resources use UUIDs.

Core POS endpoints:

```text
GET|POST|PATCH /api/v1/pos/registers
GET|POST|DELETE /api/v1/pos/registers/{register}/members
POST            /api/v1/pos/shifts/open
GET             /api/v1/pos/shifts/current
GET             /api/v1/pos/shifts
POST            /api/v1/pos/shifts/{shift}/close
POST            /api/v1/pos/shifts/{shift}/cash-movements
POST            /api/v1/pos/checkout/preview
GET|POST        /api/v1/pos/sales
GET             /api/v1/pos/sales/{sale}
POST            /api/v1/pos/sales/{sale}/hold|resume|cancel|complete
GET|POST        /api/v1/pos/sales/{sale}/payments
POST            /api/v1/pos/payments/{payment}/verify
GET             /api/v1/pos/sales/{sale}/receipt
GET             /api/v1/pos/sales/{sale}/receipt/pdf?format=58mm|80mm|a4
GET             /api/v1/pos/returns
POST            /api/v1/pos/sales/{sale}/returns
POST            /api/v1/pos/returns/{return}/approve|refund
GET             /api/v1/pos/reports/daily|payments|cashiers|returns
```

Checkout prices, taxes, stock, totals, payment state, and sale snapshots are calculated server-side. Held sales do not reserve inventory; completion revalidates and locks current stock. Completed sales deduct inventory through `InventoryService` and append `sale` movements exactly once. Cash overpayment is represented as `change_due`; only the retained cash amount enters reconciliation.

Cash is verified immediately. Card terminal, QR, and bank-transfer payments remain `pending` until trusted provider integration is added or an explicitly enabled, authorized manual reconciliation is used. Set `POS_ALLOW_MANUAL_PAYMENT_VERIFICATION=true` only for a controlled reconciliation workflow; the API never treats a client screenshot or client-side success flag as proof of payment. No raw card number, CVV, or authentication data is stored.

Receipts are generated from completed persisted sale data using Dompdf. `58mm`, `80mm`, and `a4` formats are supported. Return requests reserve refundable quantities while pending/approved, approval is manager-only, cash refunds are transactional and can restock only `sellable` items, and external refunds remain pending until a provider callback is implemented.

Phase 6 verification commands:

```bash
cd /Users/kimpu/Desktop/CIINLV/CIINLV-POS/backend
php artisan migrate --force
APP_ENV=testing php artisan migrate --force
vendor/bin/pint --test
php artisan test
php artisan route:list --path=api/v1/pos
php artisan serve --host=127.0.0.1 --port=8000
```

Example request sequence after authentication and register/cashier assignment:

```bash
export API=http://127.0.0.1:8000/api/v1
export TENANT_UUID=<business-uuid>
export STORE_UUID=<store-uuid>
export REGISTER_UUID=<register-uuid>
export VARIANT_UUID=<variant-uuid>
export TOKEN=<sanctum-token>

curl -X POST "$API/pos/shifts/open" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $TENANT_UUID" -H 'Content-Type: application/json' -d "{\"store_id\":\"$STORE_UUID\",\"register_id\":\"$REGISTER_UUID\",\"opening_amount\":100000}"
curl -X POST "$API/pos/checkout/preview" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $TENANT_UUID" -H 'Content-Type: application/json' -d "{\"store_id\":\"$STORE_UUID\",\"items\":[{\"variant_id\":\"$VARIANT_UUID\",\"quantity\":1}]}"
```

Phase 6 does not include a live card, QR, bank, or refund provider integration because provider credentials and PCI-compliant contracts were not supplied. Those payment states are deliberately pending until a trusted adapter/callback is configured. React and Flutter clients are not included.

## Multi-tenant foundation

The initial schema contains:

- `users`: platform identities, including future Super Admin accounts.
- `tenants`: independent businesses owned/managed by business admins.
- `tenant_memberships`: user-to-business membership and role assignment.
- `roles`, `permissions`, `permission_role`: global permission definitions with tenant-scoped roles.
- `stores`: tenant-owned stores with tenant-scoped unique codes.

Every business-owned table starts with a `tenant_id` foreign key and indexes supporting tenant filtering. PostgreSQL Row-Level Security is intentionally deferred until request-scoped tenant resolution, connection role strategy, and policy tests are defined together in a later phase.

## Phase 7 reports, analytics, alerts, exports, and real-time channels

Phase 7 adds tenant/store-scoped reporting services and APIs backed by the existing Phase 5/6 ledger:

```text
GET /api/v1/dashboard/overview|sales-summary|inventory-summary|low-stock|recent-sales|top-products
GET /api/v1/reports/sales/daily|weekly|monthly|custom
GET /api/v1/reports/sales/by-payment-method|by-cashier
GET /api/v1/reports/inventory/valuation|by-location|movements|low-stock|out-of-stock
GET /api/v1/reports/profit/summary|by-product|by-branch
GET /api/v1/reports/branches|comparison|{storeUuid}
GET /api/v1/alerts|unread-count
POST /api/v1/alerts/{alertUuid}/acknowledge
GET|PATCH /api/v1/alerts/preferences
POST /api/v1/reports/exports
GET /api/v1/reports/exports|{exportUuid}|{exportUuid}/download
```

Reporting conventions are explicit:

- Completed-sale metrics use `sales.completed_at`; verified collections use `payments.verified_at`; successful refunds use `refunds.processed_at`. Pending payments, held sales, and cancelled sales are excluded from collected revenue.
- Date boundaries use the tenant `business_settings.timezone`, then convert to UTC for PostgreSQL. Responses include the basis and timezone.
- Net sales before tax is merchandise subtotal minus discounts. Gross profit is net sales before tax minus `sale_items.quantity * unit_cost_at_sale`; it is not net profit.
- Current valuation uses the Phase 5 weighted-average `inventory_balances.average_cost` and on-hand quantity. Same-tenant transfers do not create revenue. Arbitrary historical as-of valuation returns an explicit unavailable reason because the existing ledger has no valuation layers.
- Low-stock state is on-hand minus reserved quantity against the variant reorder level. `stock_alerts` suppresses duplicate open alerts, records acknowledgements, and reopens a resolved condition when stock falls again.

Exports are queued private local-disk files with a 24-hour expiry. CSV values beginning with `=`, `+`, `-`, or `@` are neutralized against spreadsheet formula injection. PDF exports use the installed Dompdf package; XLSX is intentionally not advertised until a spreadsheet package is selected. Download authorization is rechecked against the requesting user and tenant.

The reporting cache key includes tenant, authorized store scope, metric, date filters, currency, and timezone. Local development defaults to the file cache and synchronous queue. Redis can be enabled with `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, and a running Redis server. Stock alert evaluation is dispatched after successful inventory transactions.

Broadcast events and private channel authorization are included for completed sales, verified payments, refunds, stock changes, low-stock changes, transfer receipts, and cashier shift closure. Channels are authorized against active tenant membership and store assignment in `routes/channels.php`; payloads contain identifiers and summary values only. Laravel Reverb is installed, and the safe local default remains the `null` broadcaster until `BROADCAST_CONNECTION=reverb` is explicitly configured:

```bash
php artisan reverb:start
```

Then set `BROADCAST_CONNECTION=reverb` and the `REVERB_*` values in an uncommitted `.env`.

Phase 7 verification commands:

```bash
cd /Users/kimpu/Desktop/CIINLV/CIINLV-POS/backend
php artisan migrate --force
APP_ENV=testing php artisan migrate --force
vendor/bin/pint --test
php artisan test
php artisan route:list --path=api/v1/dashboard
php artisan route:list --path=api/v1/reports
```

Example report requests:

```bash
curl "$API/dashboard/overview" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $TENANT_UUID" -H 'Accept: application/json'
curl "$API/reports/sales/custom?from=2026-10-01&to=2026-10-18" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $TENANT_UUID" -H 'Accept: application/json'
curl -X POST "$API/reports/exports" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $TENANT_UUID" -H 'Content-Type: application/json' -d '{"report_type":"sales","format":"csv","from":"2026-10-01","to":"2026-10-18"}'
```

## Structure

```text
app/
  Http/Controllers/Api/V1/   # versioned API controllers
  Models/                    # Eloquent domain models
  Services/                  # tenant, business, store, membership, dashboard services
  Policies/                  # platform, tenant, store, and membership authorization
bootstrap/                   # application and exception bootstrap
config/                      # database, CORS, Sanctum, cache, queue
database/migrations/         # framework + tenant foundation schema
routes/api.php               # /api/v1 routes
  tests/Feature/               # API and database integration tests
```
# Business email verification

The public registration workflow is available at the frontend route `/register-business` and the API endpoint `POST /api/v1/auth/business-registration`. It creates a pending tenant, owner, business-admin membership, and sends a signed verification URL. The URL expires after `EMAIL_VERIFICATION_EXPIRY_MINUTES` (default 60). Until verification, tenant middleware rejects dashboard and business operations because the tenant and membership are pending.

## Local setup

1. Copy `.env.example` to `.env`, generate an application key with `php artisan key:generate`, and configure PostgreSQL as usual.
2. Run `php artisan migrate`. This is additive; it does not drop or reset existing tables.
3. Start Laravel with `php artisan serve --host=127.0.0.1 --port=8000` and the frontend with `npm run dev` from `frontend/`.
4. For local mail inspection, leave `MAIL_MAILER=log` and inspect `storage/logs/laravel.log`. For automated tests, the suite uses the array mailer.

## SMTP / production

Set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` (usually `tls`), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` through the deployment secret manager. Set `APP_URL` to the public Laravel URL and `FRONTEND_URL` to the public React URL. Use `QUEUE_CONNECTION=database` or Redis with a worker for reliable mail delivery, and keep `APP_DEBUG=false`.

Set `BUSINESS_REQUIRE_SUPER_ADMIN_APPROVAL=true` when email verification should be followed by manual approval. A Super Admin can approve a verified business with `POST /api/v1/platform/businesses/{business}/approve`. Existing businesses default to approved during migration.

## End-to-end verification

Register from `/register-business`, confirm the pending screen, open the branded link from the log or SMTP inbox, and verify the success page. Sign in after verification; before verification, the owner has no active tenant membership and protected business routes remain inaccessible. The resend endpoint is `POST /api/v1/auth/email/verification-notification`; it always returns the same response for existing and missing addresses and is rate-limited.

Super Admin-created businesses use the same pending activation principle: the tenant is created with `status=pending` and `email_verification_status=pending_email_verification`; the Business Admin invitation acts as the secure owner-verification link. Email delivery is isolated from the provisioning transaction. A delivery failure is recorded on the invitation and can be retried from Business Admin management without rolling back the business or returning a false registration failure.
