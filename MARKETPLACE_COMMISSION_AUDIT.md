# Marketplace Commission Management Audit

Audit date: 2026-10-26

Repository: `CIINLV-POS`

Scope: read-only architecture and security audit plus minimal safe reporting and configuration enhancements. No live commission rates were changed, no payout was executed, and no business, order, settlement, or payment data was modified.

## Executive summary

The repository has a real marketplace commission foundation rather than a placeholder implementation. Commission rules, effective dates, category/business/promotional scope, immutable order snapshots, seller-earning settlement entries, payout reservations, authorization middleware, tenant context, audit logging, and platform-admin controls are present.

The highest-risk financial capabilities are intentionally incomplete and correctly blocked in the current environment:

- No verified online payment provider is configured.
- No verified payout provider is configured; `MarketplacePayoutService::complete()` refuses to transfer money.
- Approved marketplace refunds are recorded as blocked until a verified provider exists.
- Provider-confirmed partial/full refund commission reversals are not implemented yet.

This audit added safe enhancements around commission configuration clarity and reporting. A legitimate configured 0% rule is now distinguishable from missing commission storage. Commission reports now expose normalized business/order/seller/payout fields, searchable filters, strict rows-per-page options, CSV export, and a compact admin table. Existing payout and tenant safeguards were preserved.

## Existing working architecture

### Commission calculation and rule selection

- `backend/app/Services/MarketplaceCommissionService.php`
  - `effectiveRule()` resolves active rules by effective date and precedence.
  - Advanced precedence is promotional, business, category, then global; tenant-specific and exact promotional matches are preferred, followed by priority, newest effective date, and ID.
  - `preview()` calculates commission and seller net values without persisting an order.
  - `snapshotForVendorOrder()` calculates and persists an immutable order commission snapshot in a transaction.
  - The supported base is `item_subtotal` after item discounts. Tax, shipping, and payment fees are excluded and recorded in snapshot metadata.
  - A configured flag now survives the default-settings fallback, so a persisted 0% rate is not treated as missing configuration.

- `backend/app/Models/MarketplaceCommissionRule.php`
  - Stores rule type, tenant/category/promotion scope, rate, base, effective dates, status, priority, and reason.

- `backend/database/migrations/2026_10_21_120000_create_marketplace_settings_and_commission_tables.php`
  - Creates marketplace settings, commission rules, order commissions, and settlement entries.
  - Database checks constrain rates to 0–100 and enforce valid statuses and monetary values.

- `backend/database/migrations/2026_10_21_130000_add_advanced_marketplace_commission_and_payouts.php`
  - Adds advanced rule scope/priority fields and payout/payout-item storage.

### Snapshot, settlement, and payout integrity

- `MarketplaceCommissionService::snapshotForVendorOrder()` uses a transaction, locks an existing vendor-order snapshot, and uses unique settlement idempotency keys.
- `backend/app/Models/MarketplaceOrderCommission.php` now exposes its settlement entries for report and payout-status resolution.
- `backend/app/Services/MarketplacePayoutService.php` locks available seller entries, reserves whole immutable earning entries, checks the payout minimum, and uses an idempotency key plus unique payout-item relationships.
- `backend/app/Http/Controllers/Api/V1/MarketplaceAdminController.php` protects payout and commission operations inside the authenticated `platform.admin` route group.
- `backend/app/Http/Controllers/Api/V1/MarketplaceVendorController.php` exposes tenant-scoped earnings and now reports whether commission storage/rules are configured.

### Refund and cancellation behavior

- `backend/app/Services/MarketplaceOrderRequestService.php` reverses pending commission/settlement entries for an approved unpaid cancellation.
- Paid-order cancellation is blocked when a verified refund provider is unavailable.
- Return approval validates the requested amount but records the refund as `blocked` until a real-money provider is configured. This prevents a UI approval from pretending that funds were returned.
- Partial/full provider-backed refund reversal remains a required implementation phase.

### Reporting and frontend

- Existing routes are in `backend/routes/api.php`, including commission rules, preview, reports, payouts, and vendor earnings.
- `backend/app/Http/Controllers/Api/V1/MarketplaceAdminController.php` provides Super Admin commission controls, preview, report, CSV export, payout summary, payout request, approval, rejection, and audit events.
- `frontend/src/features/marketplace-admin.tsx` contains the existing finance/settings controls and now includes the report table without replacing the existing controls.
- `frontend/src/components/MarketplaceCommissionReportTable.tsx` provides a compact, responsive, light/dark-compatible report table with search, status filter, loading/error/empty states, pagination, rows-per-page options, and CSV export.

## Requirement status matrix

| Area | Status | Evidence and conclusion |
|---|---|---|
| Commission rules, defaults, precedence, effective dates | Implemented and verified by tests | `MarketplaceCommissionService::effectiveRule()` filters active/effective rules and applies promotional > business > category > global precedence. Tests cover overlapping business/category/global rules and expired promotional rules. |
| True 0% commission | Implemented and verified by tests | `configured` is separate from the numeric rate in `MarketplaceCommissionService` and vendor payloads. `MarketplaceSettingsCommissionTest::test_zero_percent_commission_is_configured_and_distinct_from_missing_storage()` verifies 0.00 commission and full seller net. |
| Global, business, category, promotional controls | Implemented and verified by tests | Super Admin routes/controllers and `frontend/src/features/marketplace-admin.tsx` expose the existing controls. Request validation and database checks constrain rates to 0–100. |
| Commission preview | Implemented and verified by tests | `/api/v1/admin/marketplace/commission-preview` calls `preview()`, validates tenant/category ownership, and now returns `configured` plus an explicit base policy. |
| Immutable order commission snapshots | Implemented and verified by tests | Snapshot creation is transaction-wrapped, locked by vendor order, and protected by unique relationships/idempotency keys. |
| Calculation base policy | Implemented and verified by tests | Snapshot metadata records item subtotal minus item discounts, excluding tax, shipping, and payment fees. Only `item_subtotal` is currently supported, which is safer than silently accepting unsupported bases. |
| Seller earnings and settlement balances | Implemented but needs testing | `MarketplaceCommissionService::earnings()` and vendor earnings endpoints aggregate tenant/branch-scoped settlement entries. Branch allocation and unusual multi-location cases need production-like fixtures and deeper feature coverage. |
| Payout duplicate prevention | Implemented and verified by tests | `MarketplacePayoutService::request()` uses idempotency, transaction locking, whole-entry reservations, and unique payout-item constraints. Repeated request coverage passes. |
| Payout execution | Missing / safely blocked | `MarketplacePayoutService::complete()` always refuses transfer until a verified provider is configured. No real payout was attempted. |
| Cancellations and pending commission reversal | Implemented and verified by tests | Approved unpaid cancellations call `reversePending()`. Paid cancellation is blocked without a verified refund provider. |
| Partial/full refunds and commission reversals | Partially implemented | Refund request validation and provider-blocking are present, but provider-confirmed partial/full refund events do not yet create proportional negative commission/settlement entries or immutable reversal links. |
| Multi-vendor order snapshots | Implemented but needs testing | Commission snapshots are per `MarketplaceVendorOrder`; the data model supports one snapshot per vendor order. Add a dedicated multi-vendor amount/refund integration fixture before production. |
| Commission report fields | Implemented and verified by tests | Reports now normalize Commission ID, order/vendor order, business/seller, gross amount, commission base, rate, commission amount, seller net, payout status, status, currency, and date. |
| Search, filters, pagination, rows 10/20/50/100 | Implemented and verified by tests | API validation accepts the specified page sizes; server-side report query supports search, tenant/status/date filters, sort, direction, and pagination. Frontend uses all four sizes. |
| CSV export | Implemented and verified by tests | Platform-admin export is authorized by route middleware, uses the same scoped query, streams in chunks, and records `marketplace.commission_report_exported`. |
| Business Admin commission visibility | Implemented but needs testing | Vendor earnings are tenant-context scoped and branch-aware. A dedicated Business Admin commission-report/export screen is not currently exposed; platform-admin reporting must not be reused for tenant users without a separate policy decision. |
| Audit logs | Partially implemented | Rule/settings changes, payout request/approval/rejection, report export, and order request decisions are audited. Settlement reversal and provider callback audit events need to be added with immutable event IDs when provider integrations are implemented. |
| Migration dependencies | Implemented but deployment-dependent | Commission storage requires `2026_10_21_120000_create_marketplace_settings_and_commission_tables.php`; advanced rule and payout features require `2026_10_21_130000_add_advanced_marketplace_commission_and_payouts.php`. Runtime storage checks provide safe unavailable responses, but migrations must be applied before enabling these routes. |

## Security and correctness findings

### P0 — block production financial activation until provider integrations exist

1. Payout transfer is not configured. Keep `complete()` blocked until a verified provider, signed callback verification, provider reference, idempotency mapping, retry policy, and reconciliation job are implemented.
2. Online payments and real-money refunds are not configured. Do not mark an approved return as paid or reverse stock/commission as if money moved without a provider-confirmed event.
3. Add an explicit provider transaction reference and immutable state-transition record for every successful payout/refund.

### P1 — commission/refund ledger integrity

1. Implement proportional refund/reversal settlement entries for partial returns and full refunds. Link each reversal to the original commission snapshot and provider refund reference.
2. Define whether a refund reverses commission using the original rule snapshot or the currently active rule. The safe default is the original snapshot rule and original base policy.
3. Add idempotency keys for refund webhooks and provider retries. Lock the original order commission and settlement rows before creating a reversal.
4. Add dedicated integration tests for multi-vendor orders, partial quantities, mixed-rate line items, shipping/tax/discount combinations, and repeated provider callbacks.

### P1 — authorization and tenant/branch scope

1. Keep platform commission reports and payout controls in the `platform.admin` middleware group.
2. Add a dedicated tenant-policy route for Business Admin commission reporting if required; scope every query through tenant context and the authorized branch set, and test a cross-tenant/cross-branch access attempt.
3. Ensure any future category/promotion preview validates both business ownership and the caller's ability to manage that business. The current platform preview validates category-to-tenant ownership; route authorization remains the primary boundary.

### P2 — operational reporting and auditability

1. Add report totals for refunded/reversed commission separately from active payable totals.
2. Add settlement approval/reversal audit actions with actor, reason, source request, original entry, reversal entry, and provider references.
3. Add a payout reconciliation view that compares internal status, provider status, provider reference, and settlement entries.
4. Keep CSV exports bounded and monitored; the current export streams in 250-row chunks, but large exports should eventually become an authorized queued export with a stored artifact and expiry.

### P2 — schema and operational readiness

1. Verify both reviewed marketplace migrations are applied in every environment before enabling advanced rules or payout screens.
2. Add indexes for the production report search/filter workload if query plans show degradation, especially tenant/status/created_at and vendor-order/order joins.
3. Add a database constraint or application invariant ensuring a commission reversal cannot exceed the original commission or seller earning balance.

## Minimal safe enhancements included in this audit

- Distinguishes configured 0% commission from missing settings storage.
- Adds explicit preview base-policy metadata.
- Adds normalized report rows with business, order, gross, base, rate, commission, seller net, payout status, and date fields.
- Adds server-side report search/status/date/sort/direction filters and strict 10/20/50/100 pagination choices.
- Adds chunked CSV export with platform authorization and an audit event.
- Adds a compact admin report table with one outer search border, responsive overflow handling, theme-aware surfaces, loading/error/empty states, and rows-per-page controls.
- Adds regression coverage for 0% rules, rule precedence, payout idempotency, report fields, report page sizes, and blocked partial-return behavior.

## Modified files

- `backend/app/Http/Controllers/Api/V1/MarketplaceAdminController.php`
- `backend/app/Http/Controllers/Api/V1/MarketplaceVendorController.php`
- `backend/app/Models/MarketplaceOrderCommission.php`
- `backend/app/Services/MarketplaceCommissionService.php`
- `backend/tests/Feature/MarketplaceFulfillmentTest.php`
- `backend/tests/Feature/MarketplaceSettingsCommissionTest.php`
- `frontend/src/components/MarketplaceCommissionReportTable.tsx`
- `frontend/src/features/marketplace-admin.tsx`
- `frontend/tests/marketplace-admin.test.mjs`

No migration, payment provider configuration, live commission rate, payout record, order record, settlement record, or database table was changed.

## Verification performed

- PHP syntax checks passed for all modified PHP files.
- Targeted Laravel feature suite passed: **24 tests, 209 assertions** for `MarketplaceSettingsCommissionTest` and `MarketplaceFulfillmentTest`.
- Frontend tests passed: **111 tests**.
- Frontend production build passed: `tsc -b && vite build`.
- Frontend lint exited successfully; existing warning-level React hook/set-state and dependency warnings remain outside this change.
- Build emitted the existing Vite large-chunk warning; it is a performance follow-up, not a commission correctness failure.

The Laravel tests required access to the local PostgreSQL test database. They were run against the test database only; live application data was not touched.

## Priority roadmap

### P0 — before production payouts

- Configure and verify payment/refund/payout providers.
- Implement signed provider callbacks, provider idempotency, reconciliation, and immutable financial event references.
- Keep payout/refund actions blocked until all provider checks pass.

### P1 — before enabling commission automation

- Implement partial/full refund commission reversals.
- Add multi-vendor and cross-branch isolation integration tests.
- Add a Business Admin report route only if the product requires tenant-facing commission reports; enforce tenant and branch policy server-side.
- Add reversal and provider-callback audit events.

### P2 — operational maturity

- Add reconciliation dashboards, reversed/refunded totals, queued large exports, query-plan/index review, and scheduled settlement consistency checks.
- Add production-like concurrency tests for simultaneous payout requests and refund callbacks.

### P3 — usability and optimization

- Add configurable report columns, saved filters, localized currency/date formatting, and background export notifications.
- Split oversized frontend build chunks after measuring route-level loading impact.

## Required tests before deployment

1. Persisted 0% rule versus absent settings row.
2. Promotional, business, category, and global overlap with effective-date boundaries.
3. Mixed category rates inside one vendor order and immutable line-rule snapshots.
4. Tax, shipping, payment fee, item discount, and cart discount base-policy cases.
5. Multi-vendor order commission and seller net totals.
6. Approved unpaid cancellation reversal and repeated cancellation request.
7. Provider-confirmed partial refund, full refund, duplicate webhook, and refund-after-payout behavior.
8. Two concurrent payout requests for the same balance and repeated idempotency keys.
9. Payout approval/rejection/failed/paid transition authorization and provider reference persistence.
10. Super Admin cross-business report access and Business Admin cross-tenant/cross-branch denial.
11. CSV export authorization, filters, large result streaming, and audit record contents.
12. Migration-up and migration-missing behavior in a clean PostgreSQL environment.

