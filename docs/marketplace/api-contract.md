# CIINLV-POS Marketplace API Contract

## Status

Phase 1 contract proposal based on the existing /api/v1 conventions. No marketplace route or controller has been added.

## Existing API conventions

The current Laravel API is defined in backend/routes/api.php under /api/v1.

- JSON responses use success, data, optional message, and paginated meta.
- Web clients use Sanctum session authentication with /sanctum/csrf-cookie, credentialed requests, and CSRF headers.
- Existing tenant requests select a business with X-Tenant-Id: business-uuid; the server validates the authenticated user's active membership and tenant status.
- lang=en|my is sent on requests and SetApiLocale sets Laravel's locale.
- Existing public identifiers generally use UUID route keys.
- Validation, policies, services, audit logging, transactions, and idempotency are backend responsibilities.
- Existing POS and administrator endpoint contracts must remain unchanged.

## Existing relevant endpoint families

These routes are present today and must not be repurposed.

### Authentication and media

    POST /api/v1/auth/login
    POST /api/v1/auth/logout
    GET  /api/v1/auth/me
    GET  /api/v1/me/profile-image
    GET  /api/v1/me/business-logo/{tenant}
    PATCH /api/v1/me

### Platform

    GET /api/v1/platform/dashboard
    GET /api/v1/platform/businesses
    GET /api/v1/platform/businesses/{business}
    POST/PATCH /api/v1/platform/businesses...
    GET /api/v1/platform/security/...
    GET /api/v1/platform/businesses/{business}/audit-logs

### Business and catalog

    GET/PATCH /api/v1/business/profile
    GET/PATCH /api/v1/business/settings
    GET/POST/PATCH /api/v1/business/stores...
    GET/POST/PATCH /api/v1/categories...
    GET/POST/PATCH /api/v1/products...
    POST /api/v1/products/{product}/images
    GET/POST/PATCH /api/v1/translations...

### Inventory and POS

    GET /api/v1/inventory...
    GET /api/v1/dashboard...
    GET /api/v1/reports...
    GET/POST /api/v1/pos/...
    GET/POST /api/v1/sales...
    GET/POST /api/v1/payments...
    GET/POST /api/v1/receipts...
    GET/POST /api/v1/returns...
    GET/POST /api/v1/refunds...

The precise POS route tail should be taken from backend/routes/api.php during implementation; marketplace code must not call a guessed endpoint in place of an existing POS contract.

## Proposed marketplace namespaces

These are design-level paths and are not implemented.

### Public/customer read API

    GET /api/v1/marketplace
    GET /api/v1/marketplace/stores
    GET /api/v1/marketplace/stores/{store}
    GET /api/v1/marketplace/categories
    GET /api/v1/marketplace/listings
    GET /api/v1/marketplace/listings/{listing}
    GET /api/v1/marketplace/search

Only approved/public records should be returned. Responses must not expose tenant-private fields, internal inventory locations, verification documents, or unpublished moderation data.

### Customer account and cart

The exact authentication prefix depends on the approved customer identity model. Candidate endpoints:

    POST /api/v1/marketplace/auth/register
    POST /api/v1/marketplace/auth/login
    POST /api/v1/marketplace/auth/logout
    GET  /api/v1/marketplace/me
    GET/POST/PATCH/DELETE /api/v1/marketplace/me/addresses...
    GET/POST/PATCH /api/v1/marketplace/cart...

If customers reuse the existing users/Sanctum flow, route and guard behavior must be explicitly separated from platform/business role checks. Do not silently broaden auth:sanctum access to admin endpoints.

### Customer order and payment

    POST /api/v1/marketplace/checkout
    GET  /api/v1/marketplace/orders
    GET  /api/v1/marketplace/orders/{order}
    POST /api/v1/marketplace/orders/{order}/cancel
    POST /api/v1/marketplace/orders/{order}/payment
    GET  /api/v1/marketplace/orders/{order}/tracking
    POST /api/v1/marketplace/payments/{payment}/retry

Checkout must require an Idempotency-Key. The server returns a master order plus vendor-order summaries and payment state. It must calculate totals from current canonical product/variant data and reject stale, unpublished, unavailable, cross-vendor, or cross-tenant references.

### Business Admin marketplace

    GET/PATCH /api/v1/business/marketplace/store
    POST       /api/v1/business/marketplace/store/submit
    GET/POST/PATCH /api/v1/business/marketplace/listings...
    POST       /api/v1/business/marketplace/listings/{listing}/publish
    POST       /api/v1/business/marketplace/listings/{listing}/unpublish
    GET        /api/v1/business/marketplace/orders
    GET        /api/v1/business/marketplace/orders/{order}
    POST       /api/v1/business/marketplace/orders/{order}/fulfill
    POST       /api/v1/business/marketplace/orders/{order}/shipment
    GET        /api/v1/business/marketplace/reports...

Every route must resolve the existing tenant context and apply explicit tenant ownership checks. A business admin must not receive a resource merely because an attacker guessed its UUID.

### Super Admin marketplace

    GET/POST/PATCH /api/v1/platform/marketplace/vendors...
    GET/POST/PATCH /api/v1/platform/marketplace/listings...
    GET             /api/v1/platform/marketplace/orders...
    GET/PATCH       /api/v1/platform/marketplace/commissions...
    GET/PATCH       /api/v1/platform/marketplace/payouts...
    GET             /api/v1/platform/marketplace/audit-logs...

Platform endpoints should preserve audit logging and should not grant vendor users access through shared resource controllers.

### Payment webhook

    POST /api/v1/marketplace/webhooks/{provider}

The endpoint must be provider-specific at the verification layer, rate-limited, replay-resistant, and independent of browser authentication. It must verify the raw request signature, provider event ID, order/payment reference, amount, currency, merchant account, and current state before changing payment or order records.

## Response shapes

Use the established envelope:

    {
      "success": true,
      "data": {},
      "meta": {}
    }

Errors should retain Laravel's current JSON validation/authorization conventions and include stable machine-readable error codes only if the existing API standard is extended consistently. Human-readable messages must respect lang=en|my; clients must not parse translated message text as a status code.

Public listing resources should include only:

- public listing/store UUID and slug;
- reviewed localized display values with canonical fallback;
- public images;
- variant/SKU/price/availability data permitted for discovery;
- public vendor display data;
- pagination metadata.

Order resources should include immutable snapshots, status, totals, payment state, vendor-order summaries, and tracking information appropriate to the current customer or authorized vendor/platform actor.

## Authorization contract

- Public read: approved and published marketplace content only.
- Customer: own profile, addresses, cart, wishlist, payments, orders, reviews.
- Business Admin: own tenant's marketplace store/listings/vendor-orders only.
- Platform Admin: platform marketplace records and moderation/finance oversight.
- Webhooks: provider signature plus event/order/payment validation, not user session authorization.

Tenant ownership must be checked in queries and policies, not only by frontend route visibility.

## Idempotency and concurrency

POST /marketplace/checkout, payment creation/retry, cancellation, shipment updates, and webhook ingestion need idempotency semantics. A repeated key with the same request hash should return the original result; the same key with a different request must be rejected.

Checkout must run in a transaction, lock relevant variant balances, validate reserved/available quantity, create order/vendor-order/item/payment records, and either reserve/consume or release stock according to the approved state machine. A frontend amount or stock count is informational only.

## Backward compatibility

The marketplace must not change:

- Existing /api/v1/auth/*, /api/v1/business/*, /api/v1/platform/*, POS, inventory, reporting, or translation contracts.
- Sanctum session/CSRF behavior.
- Existing tenant membership and role meanings.
- Existing product, variant, stock, sale, payment, refund, receipt, or audit data.
