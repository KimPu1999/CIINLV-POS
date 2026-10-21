# Phase 9 launch checklist

## Approval and readiness

- [ ] Phase 8 readiness audit reviewed by the release owner.
- [ ] Remaining POS tax/fee test failure resolved and full Laravel suite passes.
- [ ] Strict i18n audit scope is completed or formally accepted.
- [ ] Composer and npm dependency advisories reviewed in networked CI.
- [ ] Tenant, role, archived/suspended and customer access matrix passes in staging.
- [ ] Inventory concurrency and reconciliation evidence is recorded.
- [ ] Backup restore succeeds on isolated infrastructure.

## Infrastructure

- [ ] Hosting, capacity, private network, PostgreSQL, Redis and storage owners approved.
- [ ] `admin.example.com`, `shop.example.com` and `api.example.com` ownership/DNS approved.
- [ ] TLS certificates issued and renewal tested.
- [ ] Exact CORS origins, Sanctum domains, proxy trust and secure cookie settings verified.
- [ ] Queue workers, scheduler, failed-job monitoring and logs are active.
- [ ] Alerts exist for API errors, checkout failures, queue failures, database availability, disk and backups.

## Application verification

- [ ] Admin and marketplace production builds served with SPA fallback.
- [ ] Customer registration/login, catalog, cart, quote and COD order verified.
- [ ] Vendor order visibility and shipment tracking verified.
- [ ] Super Admin moderation and audit access verified.
- [ ] Cross-tenant access denial verified with separate businesses.
- [ ] Private identity/media documents are not publicly reachable.
- [ ] Online payment, refunds and payouts remain disabled unless separately approved.
- [ ] Browser QA passes required viewport and light/dark/Unicode checks.

## Cutover

- [ ] Encrypted pre-deployment backup completed and verified.
- [ ] Exact release artifact recorded.
- [ ] Production migration approval recorded, if any migration is required.
- [ ] DNS/TLS cutover approved.
- [ ] Post-cutover smoke tests pass.
- [ ] Rollback owner and previous artifact are available.

**Current recommendation:** do not declare launch until the unchecked blockers are closed or explicitly accepted.
