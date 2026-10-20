# CIINLV-POS Marketplace Requirements and Audit Findings

## Status

Phase 1 requirements inventory completed on 2026-10-20. This is a design document; no marketplace feature code is implemented yet.

## Scope

The marketplace will let customers discover products from approved businesses, place products from multiple vendors into one cart, complete a secure checkout, and track the resulting master order and vendor sub-orders.

The current POS remains operational and is out of scope for replacement. Existing Business Admins continue managing products, inventory, POS sales, and settings. Existing Super Admins continue managing tenants, security, and platform controls.

## Actors and permissions

| Actor | Current status | Marketplace responsibility | Required isolation |
| --- | --- | --- | --- |
| Customer | Not present in current application | Browse published content, manage own cart/profile/orders, review eligible purchases | Can read only public listings and own records |
| Business Admin | Existing tenant-scoped role | Enable store, publish eligible products, fulfill own vendor orders, view own reports | Must never access another tenant's listings, orders, stock, or payouts |
| Store/Inventory/Cashier roles | Existing operational roles | Optional fulfillment or stock duties only if explicitly granted | Must remain limited by store assignment and existing policies |
| Super Admin | Existing platform_role=super_admin | Approve/suspend vendors, moderate listings, oversee orders/commissions/payouts/reports | Platform-wide access must still be audited and must not weaken tenant boundaries |
| Payment provider/webhook | External actor | Report payment status through verified webhook | Signature, replay, amount, currency, and idempotency checks required |

## Current implementation coverage

| Capability | Current state | Phase 2+ requirement |
| --- | --- | --- |
| Tenant/business lifecycle | Exists | Reuse active/approved/suspended/archived states |
| Product/category/variant catalog | Exists, tenant-scoped | Add opt-in listing/publication layer |
| Inventory and reservations | POS inventory exists; reservation primitives exist | Share canonical variant balances and safe reservations |
| POS checkout | Exists | Keep API and lifecycle unchanged |
| Customer accounts | Missing | Decide and implement customer identity/session model |
| Marketplace stores | Missing | Add approved public store boundary |
| Marketplace cart/orders | Missing | Add server-owned cart and master/vendor order model |
| Online marketplace payment | Missing | Select one provider and implement verified flow |
| Shipping/fulfillment | Missing | Add vendor shipment state and carrier/tracking data |
| Commissions/payouts | Missing | Add auditable ledger and manual reconciliation first |
| Marketplace reviews/wishlist | Missing | Add only after identity and moderation policies are approved |
| Marketplace notifications | Missing | Reuse existing notification conventions only after channel decision |
| Marketplace translations | Partial existing catalog translation service | Reuse en/my; never invent business content |

## Functional requirements

### Public marketplace

- Display only stores and listings that are approved, active, published, and in stock according to server rules.
- Support homepage, shop, categories, product details, vendor store pages, search, filters, and pagination.
- Preserve canonical product names/descriptions where no reviewed translation exists.
- Hide suspended, archived, unpublished, or unapproved business content from new customer discovery.
- Do not expose internal tenant IDs, inventory locations, private media paths, audit metadata, or sensitive business documents.

### Customer identity and profile

- Provide customer registration/login using the approved identity architecture.
- Store customer addresses with ownership and validation.
- Allow a customer to see only their own cart, profile, addresses, orders, payments, reviews, and wishlist.
- Preserve Sanctum web security; do not place session or bearer credentials in local storage.

### Multi-vendor cart and order

- Allow items from multiple approved vendors in one cart.
- Revalidate product publication, current server price, tax, stock, vendor status, shipping, and discount rules at checkout.
- Create a master order plus one vendor sub-order per vendor.
- Snapshot item names, SKU, prices, tax, discount, currency, and vendor identity for historical correctness.
- Support idempotent cart checkout and safe retry after network failures.
- Never trust client totals, stock counts, status transitions, or payment success.

### Vendor operations

- Allow an authorized Business Admin to opt into marketplace participation after any required approval.
- Publish/unpublish eligible existing products without duplicating product or inventory records.
- Manage public listing content, images, availability, and marketplace pricing only under approved business rules.
- View and transition only that tenant's vendor orders.
- Create shipment information, carrier/tracking details, and fulfillment updates.
- Preserve POS operational data and avoid mixing marketplace order state with POS sale state.

### Super Admin operations

- Review and approve/suspend marketplace vendors.
- Moderate listings and public media.
- View master orders, vendor sub-orders, payment/refund state, commissions, payout ledger, disputes, and marketplace audit history.
- Prevent suspended/archived businesses from publishing or receiving new marketplace orders.
- Preserve historical orders and financial records when business status changes.

### Payments and refunds

- Support cash on delivery only if explicitly approved and modeled.
- Support one selected online gateway only after server-side verification and signed webhook handling are implemented.
- Verify amount, currency, merchant/order reference, status, signature, idempotency, and replay protection.
- Keep payment pending until verified; never mark a card/QR payment paid from a browser callback alone.
- Define cancellation/refund rules for master and vendor sub-orders before implementation.

### Fulfillment

- Define allowed state transitions for order, vendor-order, payment, shipment, cancellation, and refund.
- Track shipment carrier, tracking number, optional tracking URL, and status timeline.
- Support self-delivery and external courier only if operational ownership and status rules are agreed.
- Restore/release stock exactly once when cancellation, expiry, or payment failure permits it.

## Non-functional requirements

- PostgreSQL-compatible additive migrations only.
- Composite tenant ownership constraints and explicit policy checks for tenant-owned marketplace rows.
- Server-side pagination, validation, authorization, and rate limits.
- Atomic checkout and stock reservation with row locking or an equivalent safe concurrency design.
- Unique idempotency keys for checkout and payment operations.
- Secure file validation and controlled media delivery.
- English/Myanmar UI and backend locale contract using existing lang=en|my behavior.
- Accessible React UI with keyboard focus, semantic controls, reduced-motion support, responsive layouts, and light/dark themes.
- Automated frontend, backend, authorization, tenancy, inventory-concurrency, payment-idempotency, webhook, and accessibility checks.
- No production deployment or production migration without explicit approval.

## Unresolved decisions

These are blockers for safe Phase 2 schema implementation:

1. Should customer accounts reuse users with a customer role, or use a separate customer table/guard?
2. Should public storefronts map one-to-one to existing stores, one-to-one to tenants, or be a separate approved entity?
3. What is the marketplace default currency and whether can vendors use different currencies?
4. What tax source and tax-inclusive/exclusive rules apply to marketplace orders?
5. Which shipping model and fee ownership apply per vendor?
6. Which payment provider will be supported first?
7. What is the approved order/payment/refund state machine?
8. What commission rates, adjustments, payout cadence, and reconciliation workflow apply?
9. Are reviews, wishlist, promotions, COD, partial cancellation, and guest checkout in the first release?
10. Should public product translations use reviewed records only, with canonical fallback when a Burmese value is absent?
11. Which catalog images may be public, and should signed URLs be required?

## Acceptance gates before implementation

Phase 2 should not start until:

- The customer identity model is approved.
- The store/listing ownership model is approved.
- The provider and payment state model are approved.
- Tenant ownership and cross-tenant test fixtures are defined.
- The inventory reservation/release strategy is approved.
- Commission/payout records are defined as an auditable ledger.
- A rollback plan and migration review checklist are agreed.
