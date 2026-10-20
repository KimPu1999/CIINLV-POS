# CIINLV Marketplace

Customer-facing storefront foundation for CIINLV-POS.

## Run locally

    cd marketplace-frontend
    npm install
    npm run dev

The Vite server is configured for http://localhost:5174.

## Backend boundary

Phase 2 exposes public marketplace stores, products, categories, search, customer session auth, customer profiles/addresses, vendor store/listing management, and Super Admin store approval. The storefront reads only approved, active, published, in-stock records from the public API and never fabricates production products.

Configure the public base URL when the frontend is served from a separate origin:

    VITE_MARKETPLACE_API_BASE=/api/v1/marketplace

The typed adapter in src/lib/marketplace.ts uses /products, /stores, and /categories, appending `lang=en|my` and sending credentialed requests for the existing Sanctum-compatible session boundary.

## Included storefront routes

- /
- /shop
- /product/:id
- /categories
- /stores
- /stores/:id
- /wishlist
- /cart
- /checkout
- /login
- /register
- /account
- /orders
- /tracking

Cart, wishlist, language, and theme preferences persist in local storage. Cart/checkout/order/payment APIs remain intentionally out of scope for Phase 2 and are not presented as functional production flows.

## Checks

    npm run build
    npm run lint
