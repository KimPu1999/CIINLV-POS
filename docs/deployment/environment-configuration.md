# Production environment configuration

Use [backend/deploy/.env.production.example](/Users/kimpu/Desktop/CIINLV-POS/backend/deploy/.env.production.example) as a checklist only. Copy values into a secret manager or protected runtime environment; never commit the real file.

## Secret values

Provide privately: `APP_KEY`, database credentials, Redis password, SMTP credentials, backup encryption key, Reverb credentials if enabled, and any future payment-provider secrets.

Do not generate or replace an existing production `APP_KEY` during deployment. A key change invalidates encrypted data and sessions.

## Public browser values

- `frontend/.env.production.example` uses `VITE_API_BASE_URL=https://api.example.com`.
- `marketplace-frontend/.env.production.example` uses the API and CSRF URLs for `api.example.com`.

Only public URLs and non-secret feature flags may use `VITE_` variables. Never place database passwords, API private keys, signing secrets, or provider credentials in Vite variables.

## Required production posture

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_FORCE_HTTPS=true`, secure HTTP-only session cookies, exact CORS origins, `DB_SSLMODE=verify-full` where supported, Redis-backed cache/session/queue, restrictive log level, and secret-managed backup settings. Keep unverified online payment/refund/payout providers disabled.

## Validation

After secrets are injected, run `php artisan config:clear`, inspect effective non-secret configuration, then cache configuration only in the target environment. Do not print `.env`, `APP_KEY`, passwords or tokens in logs or CI output.
