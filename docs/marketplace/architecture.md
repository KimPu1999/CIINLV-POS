# CIINLV-POS Marketplace Architecture Audit

Phase 1 read-only audit completed on 2026-10-20.

This document describes the current repository and a proposed marketplace boundary. No Laravel application logic, routes, migrations, models, database records, authentication configuration, or frontend behavior was changed during Phase 1.

## Executive summary

CIINLV-POS is currently a multi-tenant Laravel POS and inventory application with a React administrator frontend. The existing system has:

- Laravel 13.17+ / PHP 8.3+ backend code (the backend README identifies Laravel 13.37).
- PostgreSQL as the intended production database.
- Laravel Sanctum session authentication for web clients and optional personal access tokens for mobile clients.
- users, tenants, tenant_memberships, roles, stores, inventory, POS sales, payments, returns, refunds, receipts, reporting, audit logs, settings, and translation records.
- A versioned /api/v1 JSON API.
- A React/Vite/Tailwind frontend with Super Admin, Business Admin, authentication, POS, inventory, reporting, settings, and security screens.

There is no marketplace implementation in the current repository. The requested marketplace must therefore be an additive subsystem that reuses the existing tenant/product/variant/inventory sources of truth and keeps POS routes and contracts unchanged.

## Current system boundary

    React administrator frontend
      ├── /login, invitation, verification, password flows
      ├── /platform/*          Super Admin
      ├── /business/*          Business Admin
      └── /app/*               tenant-scoped operational pages
                 │
                 │ credentialed Axios requests; X-Tenant-Id when required
                 ▼
    Laravel /api/v1
      ├── Sanctum web session + CSRF
      ├── active.account
      ├── SetApiLocale (en/my)
      ├── platform.admin
      ├── tenant.context / tenant.context.any
      ├── policy and service authorization
      ├── inventory and POS transaction services
      └── PostgreSQL persistence

The future marketplace should be a separate marketplace-frontend React application consuming a dedicated /api/v1/marketplace/* namespace. It may reuse visual tokens and typed API conventions, but it must not introduce a second source of truth for products, stock, prices, payments, or tenant ownership.

## Existing backend architecture

### API and middleware

backend/routes/api.php defines the v1 API. Public endpoints include health, login, password reset, invitation preview/acceptance, business registration, and email verification. Authenticated endpoints use:

- auth:sanctum
- active.account
- API throttling
- App\Http\Middleware\SetApiLocale

Platform routes are protected by platform.admin. Tenant routes resolve an active business membership from X-Tenant-Id through TenantContext; a super administrator cannot use a tenant endpoint without a tenant membership. A user with one active business can omit the header; a user with multiple businesses must select a valid business UUID.

tenant.context requires the resolved membership role to be business_admin. tenant.context.any allows tenant-scoped operational roles, with downstream policies/services applying the more specific permissions.

### Authentication

The web frontend first requests /sanctum/csrf-cookie, then uses the Laravel web session with credentialed Axios requests. users.platform_role=super_admin identifies platform administrators. Business access is based on active tenant_memberships and role records, not on a client-provided tenant ID.

The current React AuthProvider restores the authenticated user from /api/v1/auth/me, persists only the selected tenant ID and locale in local storage, and exposes refreshUser() for profile refreshes. A customer marketplace identity flow does not currently exist and is an architectural decision for Phase 2.

### Tenant and business ownership

The tenants table is the business boundary. Existing tenant-owned records carry tenant_id, and important relationships have composite tenant foreign keys or explicit tenant checks. Public API identifiers are generally UUIDs while internal compatibility keys remain integer IDs.

The current application enforces isolation primarily in middleware, policies, controllers, and services. The repository's PostgreSQLRlsTest explicitly documents that PostgreSQL row-level security is not enabled yet; it is a planned defense-in-depth layer pending a connection-role and transaction-local tenant context design.

### Product and inventory sources of truth

Existing canonical catalog and stock entities are:

- products: tenant-owned product identity, name, description, brand, status, inventory tracking, tax exemption.
- product_variants: tenant-owned SKU, barcode, attributes, purchase cost, selling price, reorder level, status.
- product_images: existing product media records.
- categories: tenant-owned category hierarchy.
- inventory_locations: stores and warehouses.
- inventory_balances: on-hand, reserved, average cost, version.
- stock_movements: auditable stock changes.

Marketplace records must reference these entities. A marketplace listing must not copy a product into a separate inventory table or maintain an independent available quantity.

InventoryService uses PostgreSQL transactions, row locks, reserved quantities, idempotency keys, and invariant checks. Marketplace reservations should use this service or a carefully designed extension of it so simultaneous POS and marketplace purchases cannot oversell the same variant.

### POS, money, and payments

The current POS phase has sales, sale_items, payments, refunds, receipts, cashier shifts, registers, returns, and cash movements. PosSaleService is authoritative for prices, tax, discounts, inventory deduction, transactions, and idempotency. PosPaymentService verifies cash immediately and keeps external methods pending until verified.

config/pos.php contains cash, card terminal, QR, and bank transfer method definitions. There is no provider SDK, provider-specific configuration, webhook endpoint, or verified online payment gateway in the current repository. A marketplace online gateway must not be advertised as functional until a provider is selected and its server-side verification and webhook signature flow are implemented and tested.

### Storage and private media

The default local/private disk is storage/app/private. Business logos, profile images, and identity documents are stored privately and served through authenticated controllers. Product images currently use the public disk and are returned through product resources.

Marketplace product/store media needs an explicit public-versus-private policy. Public catalog images may use controlled public or signed delivery; identity documents, customer information, payment evidence, and operational attachments must remain authenticated/private.

### Localization

The backend supports en and my through TranslationService, the translations table, SetApiLocale, and localized resources. Product, category, supplier, tenant, and inventory-location fields are translatable where translation storage exists. Canonical user-entered business values remain the source fallback.

The administrator frontend bundles English/Myanmar JSON resources with i18next, stores ciinlv_language, sends lang on API requests, and falls back to English/static source values. The future marketplace should reuse this locale contract and terminology, not introduce a competing locale system.

## Current repository findings

### Present

- Existing platform and tenant administrator interfaces.
- Tenant lifecycle states including active, suspended, archived, and approval-related states.
- Tenant memberships and role-aware authorization.
- Product, category, image, variant, barcode, stock, warehouse, POS, payment, returns, refund, receipt, audit, report, and translation foundations.
- Additive migration history with PostgreSQL-oriented constraints and indexes.
- Frontend build, lint, tests, and i18n audit scripts.

### Absent

- marketplace-frontend/.
- Customer registration, customer login, customer profile, address book, or customer role/guard.
- Marketplace store/public storefront records.
- Listing publication or moderation workflow.
- Marketplace cart, wishlist, order, vendor-order, checkout, shipment, reservation, review, commission, payout, or marketplace audit entities.
- Marketplace API routes/controllers/resources/policies.
- Customer notification or marketplace order tracking workflow.
- Provider-specific online payment integration or webhook verification.
- Marketplace search/indexing strategy.

### Existing dependencies that need explicit treatment

- POS sales.customer_id is an existing nullable integer reference without a marketplace customer model or foreign-key relationship. Phase 2 must decide whether customer identities are added compatibly or whether marketplace orders use a separate customer profile reference.
- Existing stores represent operational business branches. They should not automatically be treated as public marketplace storefronts without an approval/publication boundary.
- Tenant and product localization is currently translation-record based. User-created product names and business names must not be silently overwritten by generated Burmese text.
- Product media is public while verification media is private. Marketplace media must not accidentally expose private business documents.
- Existing tenant isolation is application-enforced; marketplace code must use the same context and explicit tenant ownership rules until the PostgreSQL RLS design is approved.

## Proposed marketplace boundary

### Customer application

marketplace-frontend/ will be an independent React/TypeScript/Tailwind application with:

- Public browsing and search.
- Public store/product details using only published, approved records.
- Customer authentication and profile flows after the identity strategy is approved.
- Cart, checkout, payment, order success, history, tracking, wishlist, and support.
- A typed API client and shared locale/theme/accessibility conventions.

### Vendor administration

Existing Business Admin routes will gain a marketplace section under a dedicated route/API namespace. Business users can manage only their own store publication, listing, fulfillment, and marketplace reports. Marketplace status must be checked server-side for every write and order transition.

### Platform administration

Existing Super Admin routes will gain marketplace oversight for vendor approval, moderation, orders, commissions, payouts, disputes, and audit. Historical records must remain visible even if a business is suspended or archived; suspended/archived businesses must not publish or receive new orders.

### Order and inventory boundary

A customer checkout creates one master marketplace order plus vendor sub-orders. Prices, discounts, taxes, shipping, and stock are calculated server-side. Inventory is reserved under row-level locking and is committed/released according to a server-enforced order/payment state machine. POS and marketplace must share the same variant balances and mutation service.

## Phase 1 decision gates

Before Phase 2 implementation, approve:

1. Customer identity model: extend users with a customer role, add a separate customer auth/profile model, or support both.
2. Whether an existing operational store can opt into a public marketplace store, or whether a new marketplace store is always required.
3. Marketplace currency, tax, shipping, cancellation, refund, and order-state policies.
4. One supported online payment provider and its webhook/signature requirements.
5. Product listing granularity: product-level listing with variant snapshots versus one listing per sellable variant.
6. Public media delivery strategy.
7. Commission and payout accounting rules.
8. Search strategy and acceptable scale for the first release.
