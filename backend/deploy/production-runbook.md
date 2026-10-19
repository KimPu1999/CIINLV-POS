# CIINLV POS production runbook

## Before deployment

1. Provision a separate PostgreSQL migration/owner role and a least-privilege runtime role. The runtime role must not be `SUPERUSER`, `BYPASSRLS`, or `CREATEROLE`.
2. Store `APP_KEY`, database credentials, Redis credentials, backup encryption keys, mail credentials, and Reverb credentials in a secret manager. Never put them in the image or GitHub logs.
3. Configure `deploy/.env.production.example` into an environment-specific secret-managed file. Set `APP_DEBUG=false`, `APP_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, restrictive CORS origins, and `DB_SSLMODE=verify-full`.
4. Install and verify TLS termination at the reverse proxy/load balancer. Do not expose PostgreSQL or Redis publicly.
5. Configure Redis, queue workers, scheduler, and the Reverb profile independently. Reverb is installed in the repository; verify its credentials, allowed origins, Redis connectivity, and private-channel authorization before exposing it publicly.
6. Take and verify an encrypted database backup and confirm that the restore key can be recovered under the organization’s key-recovery procedure.

## Release sequence

```bash
docker compose build --pull
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan config:cache
docker compose up -d app nginx queue scheduler
curl --fail https://api.example.com/api/v1/health/ready
docker compose exec app php artisan queue:restart
```

Review each migration before `migrate --force`. Do not run `migrate:fresh`, `db:wipe`, or `migrate:refresh` in a deployment environment.

## Rollback

Application rollback is preferred: deploy the previous immutable image, restart workers, and verify readiness. Database migrations must be backward-compatible with the previous image. Do not automatically run migration rollbacks in production. If data recovery is required, stop writes, preserve the current database, restore to an isolated instance, validate it, and obtain explicit approval before any controlled promotion.

## Recovery objectives

Set and alert on deployment-specific RPO/RTO values. `scripts/pitr-postgresql.conf.example` describes WAL archiving and point-in-time recovery; `pg_dump` backups alone do not provide PITR.

## Operational checks

```bash
php artisan ciinlv:security-audit
php artisan ciinlv:inventory-reconcile
php artisan ciinlv:backup-health
php artisan queue:failed
docker compose ps
docker compose logs --since=10m app queue scheduler nginx
```

Use `scripts/load-test.js` only against isolated staging. It is intentionally not a production deployment or load-test command.
