# CIINLV-POS deployment guide

This is a preparation guide. It does not deploy, migrate or alter live infrastructure. Obtain explicit approval before executing production commands.

## Logical services

1. Existing POS/Admin website (`frontend/` production build).
2. Customer marketplace website (`marketplace-frontend/` production build).
3. Laravel API (`backend/`, PHP-FPM behind TLS termination).
4. PostgreSQL database, private network only.
5. Redis, queue workers and scheduler.
6. Private uploaded media and generated reports.
7. Monitoring, logging and encrypted backups.

## Build artifacts

```bash
cd frontend && npm ci && npm run build
cd ../marketplace-frontend && npm ci && npm run build
cd ../backend && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Publish only the generated frontend `dist/` directories and the immutable backend image/artifact. Never publish `.env`, private storage, database dumps or provider secrets.

## Required production environment values

Set these in a secret manager or protected runtime environment, not Git:

`APP_ENV`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `APP_FORCE_HTTPS=true`, `TRUSTED_PROXIES`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=verify-full`, `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE`, `SANCTUM_STATEFUL_DOMAINS`, restrictive `CORS_ALLOWED_ORIGINS`, `MAIL_*`, `BACKUP_*`, and verified Reverb values if realtime is enabled.

Online payment, refund and payout credentials must remain unset until their provider adapter, signature verification and reconciliation tests are approved.

## Suggested release sequence

1. Build and scan the immutable application image in CI.
2. Verify a recent encrypted backup and an isolated restore test.
3. Review every migration for backward compatibility; obtain explicit approval.
4. Deploy the application artifact to staging.
5. Run configuration, health, queue, authorization, checkout and browser smoke tests in staging.
6. Drain or pause workers only as required by the release procedure.
7. Promote the same artifact to production behind the selected TLS reverse proxy.
8. Run `php artisan config:cache` and restart workers according to the host runbook.
9. Verify `/api/v1/health/ready`, authenticated login, tenant isolation, queue processing and frontend smoke routes.
10. Monitor errors, latency, queue failures, database connections and payment reconciliation before declaring success.

The repository's `backend/docker-compose.yml`, `backend/docker/Dockerfile`, `backend/docker/nginx/default.conf` and `backend/deploy/production-runbook.md` provide a starting point. The compose file is not a substitute for production TLS, secret management, backup storage, alerting or a tested restore process.

## Safety constraints

Never run `migrate:fresh`, `db:wipe`, `migrate:refresh`, destructive SQL, force push, automatic deployment, real-money payment tests, real refunds or payouts. Use a separate staging database for migration and concurrency rehearsal.
