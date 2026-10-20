# CIINLV Marketplace

Customer-facing storefront foundation for CIINLV-POS.

## Run locally

    cd marketplace-frontend
    npm install
    npm run dev

The Vite server is configured for http://localhost:5174.

## Current backend boundary

The existing Laravel backend currently exposes tenant-authenticated POS/catalog APIs, but it does not yet expose public marketplace listings, stores, categories, customer accounts, checkout, or payment routes. This frontend therefore does not fabricate product data or call private tenant APIs as if they were public.

When the approved marketplace API is implemented, configure its public base URL without changing the UI:

    VITE_MARKETPLACE_API_BASE=/api/v1/marketplace

The typed adapter in src/lib/marketplace.ts expects collection endpoints for /listings, /stores, and /categories. Until that variable is configured, the app renders an intentional “catalog not connected” state.

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

Cart, wishlist, language, and theme preferences persist in local storage. Checkout and customer authentication remain clearly disabled until their Laravel APIs are approved and available.

## Checks

    npm run build
    npm run lint
