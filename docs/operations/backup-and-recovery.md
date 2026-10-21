# Backup and Recovery

## Current evidence

The Laravel application has a configured backup directory inspection service and scheduled backup-health command. Artifact presence is not equivalent to a valid or restorable backup. Restore testing was not verified in this Phase 10 pass.

Status: **Needs Verification**.

## Required backup policy

- Use encrypted PostgreSQL backups stored outside the application host.
- Keep a documented retention schedule and access list.
- Back up uploaded media separately from the database.
- Keep backup credentials outside Git and `.env` examples.
- Monitor age, size, checksum, and failure status.

## Restore test procedure

1. Provision a separate non-production PostgreSQL database.
2. Restore a recent backup there; never overwrite the active database.
3. Verify migrations, users, tenant relationships, orders, inventory snapshots, and audit logs.
4. Restore media to an isolated storage location and verify references.
5. Run the application test suite and tenant-isolation checks.
6. Record restore time, data recency, failures, and corrective actions.

No restore, production backup rotation, or data overwrite was executed by this work.
