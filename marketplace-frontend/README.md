# CIINLV Marketplace

Customer-facing multi-vendor storefront for CIINLV-POS.

## Run locally

    cd marketplace-frontend
    npm install
    npm run dev

The Vite server is configured for http://localhost:5174.

## Backend boundary

The Laravel boundary exposes public marketplace stores, products, categories, search, customer session auth, customer profiles/addresses, vendor store/listing management, and Super Admin store approval. The storefront reads only approved, active, published, in-stock records from the public API and never fabricates production products.

Configure the public base URL when the frontend is served from a separate origin:

    VITE_MARKETPLACE_API_BASE=/api/v1/marketplace
    VITE_MARKETPLACE_CSRF_URL=/sanctum/csrf-cookie

The typed adapter in src/lib/marketplace.ts uses /products, /stores, /categories, and the customer auth/address routes. It appends `lang=en|my`, sends credentialed requests, obtains the Laravel CSRF cookie before mutations, and keeps admin/POS routes outside the customer boundary.

## Included storefront routes

- /
- /shop
- /product/:slug
- /categories
- /stores
- /stores/:slug
- /wishlist
- /cart
- /checkout
- /login
- /register
- /account
- /orders
- /tracking

Cart, wishlist, language, and theme preferences persist in local storage. Wishlist is local-only because no authorized wishlist API exists yet. Cart is a local draft only; checkout, stock reservations, order creation, payment, and order history remain intentionally disabled until their real APIs are implemented. The checkout page never reports a successful payment or order.

## Checks

    npm run build
    npm run lint
