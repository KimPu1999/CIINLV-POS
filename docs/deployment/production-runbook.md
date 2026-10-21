# Production deployment runbook

This runbook requires explicit release approval. It is not an automatic deployment workflow.

## Preflight

1. Confirm the readiness audit has no unaccepted critical/high findings.
2. Confirm the final domains, TLS, secret manager, database, Redis, storage and monitoring owners.
3. Verify an encrypted backup and an isolated restore result.
4. Build and scan the exact frontend artifacts and backend image in CI.
5. Review migrations for backward compatibility; obtain explicit approval before production migration.
6. Confirm online payments, refunds and payouts remain disabled unless independently approved and verified.

## Artifact preparation

```bash
cd frontend && npm ci && npm run build
cd ../marketplace-frontend && npm ci && npm run build
cd ../backend && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
docker compose build --pull
```

Run these commands in CI or a controlled release environment, never with production secrets printed to logs.

## Controlled release sequence

```bash
# Approval required before each production-affecting step.
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force --no-interaction
docker compose run --rm app php artisan config:cache
docker compose up -d app nginx queue scheduler
curl --fail https://api.example.com/api/v1/health/ready
docker compose exec app php artisan queue:restart
```

The migration and deployment commands above are prepared examples only and were not executed. Review the actual host, image tags, secrets, volumes and migration plan before use.

## Post-release

Check login/logout, CSRF, health, tenant isolation, catalog, COD checkout, queue processing, scheduled tasks, media access, logs, database connections, disk space and alert delivery. Keep the previous immutable artifact available for rollback.
