# CIINLV-POS launch checklist

## Release gates

- [ ] All critical and high findings in `readiness-audit.md` are closed or explicitly accepted.
- [ ] Full Laravel suite passes with zero unexpected failures.
- [ ] POS/Admin and Marketplace frontend tests, builds and type checks pass.
- [ ] Strict i18n scope is complete or an approved exception lists remaining hardcoded strings.
- [ ] Composer and npm dependency advisories are reviewed from a networked CI run.
- [ ] Staging migration rehearsal and rollback compatibility are verified.
- [ ] Isolated PostgreSQL backup restore test is successful.
- [ ] TLS, CORS, cookies, proxy trust and security headers are verified on final domains.
- [ ] Role, tenant and archived/suspended access matrix passes.
- [ ] POS/marketplace inventory concurrency and reconciliation tests pass.
- [ ] Browser QA passes at 320, 375, 768, 1024 and 1440 pixels in light/dark modes, including Myanmar text.

## Feature gates

- [ ] COD workflow is verified end-to-end in staging.
- [ ] Online payment remains disabled unless provider credentials, signed webhooks and reconciliation are approved.
- [ ] Real-money refunds remain disabled unless provider refund verification is approved.
- [ ] Vendor commission/payout features remain disabled until immutable ledger and settlement controls exist.
- [ ] Shipment state transitions and cancellation/refund policies are verified.

## Operations

- [ ] Secrets are in the secret manager; no `.env` or credentials are in artifacts.
- [ ] Queue workers, scheduler, storage, log retention and alerting are active.
- [ ] Database/Redis are private and least-privilege users are used.
- [ ] Backup, restore and rollback owners are named.
- [ ] Incident contacts and maintenance window are recorded.
- [ ] Deployment approval is recorded before any production change.

## Post-launch observation

For the agreed observation window, monitor API error rate, latency, queue failures, database connections, disk, checkout failures, stock reconciliation, authentication failures and payment reconciliation. Keep the previous artifact available for rollback.
