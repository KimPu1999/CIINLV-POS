# CIINLV-POS deployment architecture

This is a deployment preparation document. The domains are placeholders and no infrastructure or DNS changes are authorized by this repository change.

## Logical topology

```text
shop.example.com  ─┐
admin.example.com ─┼─ HTTPS ─> Laravel API: api.example.com ─> private PostgreSQL
                   │                                      └─> private Redis
                   └─ static React assets                 └─> persistent private/public media
                                                              └─> queue workers + scheduler
```

- `frontend/` builds the existing POS/Super Admin application for `admin.example.com`.
- `marketplace-frontend/` builds the customer storefront for `shop.example.com`.
- `backend/` serves Laravel API routes, Sanctum sessions/CSRF, tenant authorization, private media and operational commands.
- PostgreSQL and Redis must be private-network services; neither should be exposed to the public Internet.
- Queue workers and the scheduler run as separate processes using the same immutable backend artifact and secret-managed environment.

## Current repository evidence

- Docker Compose already defines app, Nginx, queue, scheduler, Redis, PostgreSQL and optional Reverb services.
- Laravel has HTTPS, security-header, CORS, rate-limit, Sanctum and generic production error handling middleware.
- Marketplace foundation, checkout and fulfillment schemas exist.
- Online payment, real-money refund, commission and payout execution remain unavailable until verified providers and ledger workflows are approved.

## Trust boundaries

1. Browser clients may receive only public API values and public marketplace media URLs.
2. Laravel is authoritative for sessions, pricing, stock, tenant ownership, payment state and status transitions.
3. Identity documents, private reports and backups remain on private storage.
4. The runtime PostgreSQL role must not be a superuser, `BYPASSRLS` role or schema owner.

## Recommended deployment shape

For a small initial launch, use one private application host (or container cluster) with separate web, worker and scheduler processes, a managed/private PostgreSQL instance, private Redis, object/block storage for uploads, and an external TLS/load-balancing layer. Scale API/web and workers independently when traffic and queue latency justify it.

Do not select a provider, size a production database, or expose a service until traffic, retention, availability and budget requirements are approved.
