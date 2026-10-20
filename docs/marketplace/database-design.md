# CIINLV-POS Marketplace Database Design

## Status

Phase 2 additive foundation implemented on 2026-10-20. The migration has not been executed against the local PostgreSQL database because the configured server at 127.0.0.1:5432 is unavailable.

## Existing schema sources of truth

The migration history under backend/database/migrations currently defines:

- users, Sanctum personal access tokens, sessions/cache/jobs.
- tenants, tenant lifecycle/approval/profile fields, business settings.
- roles, permissions, tenant_memberships, store memberships, invitations, idempotency keys.
- stores, store settings, dashboard configuration.
- categories, products, product_variants, product_images.
- inventory_locations, inventory_balances, stock_movements, purchase orders, receipts, transfers, adjustments.
- POS registers, cashier shifts, cash movements, sales, sale_items, sale adjustments, payments, returns, refunds, and receipts.
- reporting exports, stock alerts, audit logs, translations, and user/business profile media fields.

Relevant canonical relationships:

    tenants
      ├── stores
      ├── tenant_memberships ── users / roles
      ├── categories ── products ── product_variants ── product_images
      ├── inventory_locations ── inventory_balances ── stock_movements
      └── sales ── sale_items / payments / refunds / receipts

The products, product_variants, and inventory_balances tables must remain the canonical catalog and inventory sources for both POS and marketplace. A marketplace must not copy or independently decrement stock.

## Implemented additive entities

Migration: `backend/database/migrations/2026_10_20_180000_create_marketplace_foundation_tables.php`.

Implemented tables are `marketplace_stores`, `marketplace_listings`, `marketplace_listing_images`, `customer_profiles`, `customer_addresses`, and `marketplace_audit_logs`. All tenant-owned marketplace rows carry `tenant_id`; listings use composite tenant/store and tenant/product foreign keys; public store/listing state is protected by PostgreSQL check constraints and indexes. A tenant has one marketplace store, and no existing POS record is backfilled or copied.

The remaining sections document future logical candidates. The Phase 2 tables above are the approved implementation for the current scope; cart/order/payment/fulfillment tables remain unimplemented.

### Public store and listings

#### marketplace_stores

Implemented purpose: an approved public storefront owned by one tenant. It is intentionally separate from existing operational `stores` so a branch is not made public automatically.

Implemented fields:

- id, uuid, tenant_id, name, slug, description, public logo/banner references.
- `approval_status` (`pending`, `approved`, `rejected`), `is_active`, approval actor/timestamp, timestamps.

Required invariants:

- tenant ownership must be explicit.
- one approved mapping rule must be enforced by a unique constraint.
- inactive, suspended, or archived tenants cannot be publicly active.

#### marketplace_listings

Implemented purpose: publish an existing tenant product (including its active variants) to a public store without duplicating product or inventory data.

Implemented fields:

- id, uuid, tenant_id, marketplace_store_id, product_id, unique slug.
- `status` (`draft`, `published`, `archived`) and `published_at`.
- prices and availability are resolved from existing active product variants and inventory balances.

Required invariants:

- tenant_id must match the referenced product, variant, and store tenant.
- uniqueness must prevent duplicate active listings for the selected granularity.
- listing publication must not change POS product status or stock.

#### marketplace_listing_images

Candidate purpose: public listing media with explicit ownership and ordering.

Images must use validated MIME types, safe generated names, size limits, tenant ownership, and a deliberate public/signed delivery policy. Private verification and identity documents must never be reused as listing images.

### Customer data

`customer_profiles` is a one-to-one extension of the existing `users` table. Customer users have no tenant membership and no platform role; the `customer.account` middleware requires the profile and active customer status. This keeps admin/business access semantics unchanged while retaining the existing Sanctum web guard.

#### customer_profiles

The customer identity decision controls whether this is a profile extension of users or a separate account table. Do not create both without a clear ownership model.

Required properties regardless of model:

- unique identity ownership;
- status and deletion/anonymization policy;
- locale and contact preferences;
- no access to tenant administration records;
- auditability without storing unnecessary secrets.

#### customer_addresses

Candidate fields include customer owner, label, recipient/contact snapshot, address lines, city/state/postal/country, default flag, validation status, and timestamps. Add owner indexes and enforce that an address can only be used by its owner.

### Cart and orders

#### marketplace_carts and marketplace_cart_items

Candidate fields include customer/session owner, status, currency, expiry, idempotency metadata, listing/variant references, quantity, and timestamps.

The server must revalidate every cart item at checkout. A cart is not a stock reservation unless the approved inventory policy explicitly makes it one.

#### marketplace_orders

Candidate purpose: customer-facing master order for one checkout containing multiple vendors.

Candidate fields:

- id, uuid, customer owner, currency, address snapshot.
- subtotal, discount, tax, shipping, fees, rounding, grand total, paid/refunded/balance fields as fixed-precision numeric values.
- payment/order/cancellation status.
- idempotency key and request hash with a tenant-safe uniqueness strategy.
- immutable receipt/customer/address snapshots required for history.

#### marketplace_vendor_orders

Candidate purpose: one fulfillment and financial sub-order per vendor.

Candidate fields:

- master order reference, tenant/vendor reference, public UUID.
- vendor-specific subtotal, discount, tax, shipping, fees, totals.
- fulfillment/cancellation/refund status.
- shipment and commission/payout references.

Tenant ownership must prevent a vendor user from querying or mutating another vendor's sub-order even when they know a master order ID.

#### marketplace_order_items

Candidate fields include vendor-order reference, listing/product/variant references, immutable product name/SKU/image/price/tax snapshot, quantity, discount/tax/line total, and cancellation/refund quantities.

Historical snapshots are required so later product edits do not rewrite an existing receipt or order.

### Payments, reservations, and fulfillment

#### marketplace_payments

Candidate fields include order/vendor-order references, provider, method, currency, amount, provider reference, status, verified timestamps, webhook metadata digest, idempotency key, and failure reason.

Unique constraints must prevent duplicate provider references and duplicate successful idempotency operations. Secrets and untrusted provider payloads must not be stored without a retention policy.

#### marketplace_inventory_reservations

Candidate fields include tenant, variant, inventory location/allocation, order/vendor-order/item, quantity, status, expiry, and release/consume timestamps.

Reservation creation, conversion to dispatch, release, and expiry must be atomic and compatible with the existing InventoryService row locking and reserved_quantity.

#### marketplace_shipments

Candidate fields include vendor-order, fulfillment method, carrier, tracking number, tracking URL, status, shipped/delivered timestamps, and tenant ownership. Status changes must be server-enforced.

### Reviews, finance, and audit

#### marketplace_reviews

Candidate fields include customer, order item, listing, rating, content, moderation status, published timestamp, and tenant/listing ownership. A review must be tied to an eligible purchase if verified reviews are required.

#### marketplace_commissions

Candidate purpose: immutable/auditable commission ledger entries by vendor-order or order item.

Store gross amount, commission rule/version, commission amount, adjustments, net vendor amount, currency, and lifecycle state. Never calculate historical payouts only from current configuration.

#### marketplace_payouts

Candidate purpose: manual or provider-backed settlement records.

Store vendor, covered ledger entries, amount, currency, status, reconciliation references, and reviewed/paid timestamps. Automatic payouts are out of scope until a provider and settlement process are approved.

#### marketplace_audit_logs

The existing audit_logs table may be reusable if it can safely represent marketplace actors, tenant ownership, order/listing references, and immutable metadata. A second table should be added only if the existing audit model cannot meet retention or query requirements.

## Constraints and indexing principles

- Use UUIDs for public references and retain integer primary keys only for compatibility where appropriate.
- Every tenant-owned marketplace row should carry tenant_id or be reachable through a constrained tenant-owned parent.
- Use composite foreign keys or application checks to ensure referenced product, variant, store, listing, order, and inventory records share the same tenant.
- Add unique constraints for public slugs, active listings, provider references, idempotency keys, and one-to-one mappings.
- Index tenant/status/publication combinations used by customer discovery and vendor dashboards.
- Index order/customer/vendor/status/time combinations used by history, fulfillment, and platform reporting.
- Use fixed-precision PostgreSQL numeric values for money; do not use floating-point totals.
- Enforce valid status transitions in services and tests; database check constraints can provide a second layer for enumerated values.
- Avoid cascade deletion for historical financial/order records. Prefer restrict, soft deletion, archive states, or nullable references with immutable snapshots.
- Use row locks or optimistic version checks for stock reservation and payment/order finalization.
- Add retention and privacy rules for addresses, payment metadata, reviews, and audit logs.

## Migration and backfill strategy

No migration should run until the data model decisions are approved and php artisan migrate:status has been reviewed against the target environment.

The expected safe sequence is:

1. Add marketplace tables and constraints without changing existing rows.
2. Add models, policies, and read-only resources.
3. Add explicit opt-in store/listing records; do not publish all existing products automatically.
4. Add feature-flagged write endpoints and tests.
5. Backfill only deliberate, reviewed records with an idempotent command.
6. Add marketplace checkout/reservation behavior after concurrency tests pass.

Never use migrate:fresh, migrate:refresh, db:wipe, destructive seeders, or data-reset scripts.

## PostgreSQL and tenant defense

The current repository documents application-level tenant isolation and an RLS gap. Phase 2 should not claim RLS protection unless policies, connection roles, transaction-local tenant context, and tests are implemented. Marketplace code must use the existing TenantContext, membership checks, policy authorization, and composite ownership constraints from the beginning.
