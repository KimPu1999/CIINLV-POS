# Staging setup

Staging must be isolated from production and must not use production credentials or unsanitized customer data.

## Required isolation

- Separate staging PostgreSQL database and database credentials.
- Separate Redis namespace/instance, storage bucket, mail provider and domain.
- Test-only customer, vendor and Super Admin accounts.
- Sandbox payment configuration only; current online payment execution remains disabled until verified.
- Separate `APP_KEY`, session cookies, CORS origins and Sanctum stateful domains.

## Setup outline

```bash
cd backend
cp deploy/.env.production.example .env
# Fill staging-only values through the approved secret mechanism.
composer install
php artisan migrate --force
php artisan config:cache

cd ../frontend && npm ci && npm run build
cd ../marketplace-frontend && npm ci && npm run build
```

Review migrations before running them, and run them only against the isolated staging database. Never use `migrate:fresh` or `db:wipe`.

## Verification flows

1. Health endpoint and HTTPS redirect.
2. POS/Admin and Marketplace authentication with CSRF.
3. Business/vendor approval and listing publication.
4. Customer catalog, cart, quote and COD checkout.
5. Vendor order visibility, shipment transitions and customer tracking.
6. Super Admin moderation and cross-tenant denial.
7. Inventory reservation/release and reconciliation under concurrent test requests.
8. Queue retry, failed jobs, scheduler, backups and restore rehearsal.
9. Browser matrix at 320, 375, 768, 1024 and 1440 pixels in light/dark modes.

Record evidence and failures before considering production approval.
