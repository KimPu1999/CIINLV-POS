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

Invitation email delivery is queued after commit. Configure a real mail transport before production use; local development may use `MAIL_MAILER=log`, while the activation token is never returned by the business-creation API or written to PostgreSQL.

Phase 3 added `business_settings`, `business_invitations`, and `business_idempotency_keys`, plus tenant contact/business-code fields, membership inviter tracking, and platform audit history. Existing Phase 1/2 routes remain available for compatibility.

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

## Multi-tenant foundation

The initial schema contains:

- `users`: platform identities, including future Super Admin accounts.
- `tenants`: independent businesses owned/managed by business admins.
- `tenant_memberships`: user-to-business membership and role assignment.
- `roles`, `permissions`, `permission_role`: global permission definitions with tenant-scoped roles.
- `stores`: tenant-owned stores with tenant-scoped unique codes.

Every business-owned table starts with a `tenant_id` foreign key and indexes supporting tenant filtering. PostgreSQL Row-Level Security is intentionally deferred until request-scoped tenant resolution, connection role strategy, and policy tests are defined together in a later phase.

## Structure

```text
app/
  Http/Controllers/Api/V1/   # versioned API controllers
  Models/                    # Eloquent domain models
bootstrap/                   # application and exception bootstrap
config/                      # database, CORS, Sanctum, cache, queue
database/migrations/         # framework + tenant foundation schema
routes/api.php               # /api/v1 routes
tests/Feature/               # API and database integration tests
```
