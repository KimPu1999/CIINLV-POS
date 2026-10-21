# CIINLV POS frontend

React, TypeScript, Vite, Tailwind CSS, React Router, Axios, and Lucide React frontend for the existing Laravel POS backend.

## Run locally

Terminal 1:

```bash
cd /Users/kimpu/Desktop/CIINLV-POS/backend
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2:

```bash
cd /Users/kimpu/Desktop/CIINLV-POS/frontend
npm install
npm run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173/login`. The Vite proxy forwards `/api` and `/sanctum` to Laravel at `http://127.0.0.1:8000` using Sanctum cookie authentication.

## Platform business routes

- `/platform/dashboard`
- `/platform/businesses`
- `/platform/businesses/new`
- `/platform/businesses/:id`
- `/platform/businesses/:id/edit`
- `/platform/businesses/:id/admins`
- `/accept-invitation/:token`

The Laravel API remains authoritative for authentication, Super Admin authorization, tenant isolation, idempotency, and invitation security.

## Checks

```bash
npm run build
npm run lint
```
assdfkdfk