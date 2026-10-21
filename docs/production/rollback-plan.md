# CIINLV-POS rollback plan

## Application rollback

1. Declare an incident and freeze unrelated releases.
2. Capture logs, queue state, health responses and the current image/version.
3. Stop or drain workers only as required to avoid duplicate jobs.
4. Deploy the previous immutable application image and matching frontend artifacts.
5. Restart PHP-FPM/queue processes using the host runbook.
6. Verify health, login/logout, tenant isolation, POS read paths, marketplace cart reads and queue processing.
7. Re-enable traffic gradually and monitor errors and reconciliation.

## Database safety

- Prefer backward-compatible migrations so the previous application remains runnable.
- Do not automatically run migration rollbacks in production; down migrations can destroy data.
- If recovery is needed, preserve the current database, restore to an isolated instance, validate it, and obtain explicit approval before any controlled promotion.
- Never use `migrate:fresh`, `db:wipe`, `migrate:refresh` or destructive SQL.

## Payment and inventory incidents

- Disable unverified online payment methods through configuration/feature controls.
- Do not mark orders paid based on browser redirects.
- Reconcile provider webhooks and payment records before replaying jobs.
- Pause affected checkout workflows if inventory reconciliation detects drift.
- Use the existing idempotency keys and audit records to investigate duplicate attempts.
- Do not issue refunds or payouts during an incident without the approved provider and authorization workflow.

## Exit criteria

Rollback is complete only after health checks pass, queue failures are understood, tenant isolation is verified, inventory/payment reconciliation is complete, and the incident owner approves resuming writes.
