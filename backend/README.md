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
