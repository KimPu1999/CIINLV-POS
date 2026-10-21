# Rollback and recovery

## Application rollback

1. Declare the incident and freeze unrelated changes.
2. Preserve logs, health responses, queue state, image tags and database metrics.
3. Drain/restart workers safely to prevent duplicate job side effects.
4. Deploy the previous immutable API and frontend artifacts.
5. Verify health, authentication, tenant isolation, POS reads, marketplace cart reads and queues.
6. Reconcile inventory and payment state before resuming affected writes.

## Database recovery

- Prefer backward-compatible migrations and application rollback.
- Do not automatically run down migrations in production.
- If restoration is needed, preserve the active database and restore to an isolated instance first.
- Obtain explicit approval before any controlled promotion or write freeze.
- Never use `migrate:fresh`, `db:wipe`, `migrate:refresh` or destructive SQL.

## File recovery

Restore private documents, public marketplace media and generated reports from encrypted storage backups into an isolated path first. Verify ownership, permissions and URL visibility before reconnecting the application volume.

## Payment/inventory incidents

Keep unverified payment/refund/payout operations disabled, reconcile webhooks and idempotency keys, pause affected checkout paths if stock drift appears, and do not manually mark payments paid or issue refunds without the approved provider workflow.
