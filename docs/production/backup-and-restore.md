# CIINLV-POS backup and restore procedure

This procedure is documentation only. Do not restore over the current database without explicit approval.

## Backup scope

- PostgreSQL logical backups for application data.
- PostgreSQL WAL archiving/PITR if the selected host supports it.
- Private uploaded media and generated files that must be retained.
- Secret-manager recovery material, stored separately from database backups.

The repository includes `backend/scripts/backup-postgres.sh`, `verify-postgres-backup.sh`, `restore-postgres-backup.sh`, `verify-restored-database.sh`, `rotate-backups.sh` and `pitr-postgresql.conf.example`. Review scripts and permissions before use.

## Required controls

- Encrypt backup artifacts before leaving the database host.
- Use a separate backup identity with minimum required permissions.
- Keep daily, weekly and monthly retention according to the approved policy.
- Store copies in a separate failure domain.
- Monitor age, size, checksum and completion status.
- Never put backup keys, passwords or `.env` files in Git or CI logs.

## Restore rehearsal

1. Provision an isolated PostgreSQL instance with no production network route.
2. Retrieve a backup through the approved access process.
3. Decrypt it using the recovered key under dual control.
4. Restore into a new database; never overwrite the source.
5. Run `verify-restored-database.sh` and application health checks.
6. Run representative read-only authorization, tenant, checkout quote and report tests.
7. Compare row counts/checksums for critical tables and record duration.
8. Destroy the isolated rehearsal instance according to the retention policy.

No restore rehearsal was executed during this audit, so backup readiness remains **Blocked** until evidence is recorded.

## Suggested objectives

Set approved service-specific RPO/RTO values before launch. `pg_dump` alone does not provide point-in-time recovery; use WAL archiving when the business requires recovery between snapshots.
