# CIINLV-POS security checklist

## Application and transport

- [ ] `APP_DEBUG=false` in every production process.
- [ ] TLS terminates before the API and `APP_FORCE_HTTPS=true` is verified behind the trusted proxy configuration.
- [ ] HSTS is enabled only after all production subdomains are HTTPS-ready.
- [x] API error handling redacts unexpected exception details when debug is disabled.
- [x] Security middleware sets `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and a restrictive `Permissions-Policy`.
- [ ] Add/verify a Content-Security-Policy suitable for the selected frontend, API and camera/realtime requirements.
- [ ] Confirm Nginx/TLS server headers, request size limits and access logs do not disclose secrets.

## Authentication and authorization

- [x] Sanctum stateful API and CSRF middleware are configured.
- [x] Login, password reset and verification routes have named rate limiters.
- [x] Admin, vendor and customer route groups use distinct middleware and tenant context.
- [ ] Run the complete cross-tenant and role matrix in staging with two businesses and customer accounts.
- [ ] Verify secure cookie domain, `Secure`, `HttpOnly`, `SameSite`, session lifetime and logout invalidation with final domains.
- [ ] Verify trusted proxy settings; do not trust arbitrary forwarded headers.

## API and data handling

- [x] Request validation is used on sensitive mutations and uploads.
- [x] Private verification documents and business profile media use authenticated delivery paths.
- [x] Marketplace public images are separated from private identity documents.
- [ ] Confirm all production CORS origins are exact HTTPS origins; do not use `*` with credentials.
- [ ] Confirm pagination limits and export rate limits for every high-volume endpoint.
- [ ] Review logs and exception reporting for email addresses, tokens, identity documents and payment data.
- [ ] Run a dependency advisory scan with network access and record the lockfile result.

## Payments, inventory and marketplace

- [x] POS and marketplace pricing are recalculated server-side.
- [x] Idempotency keys and inventory reservation concepts exist in the checkout flows.
- [ ] Complete isolated concurrency tests for POS versus marketplace stock consumption.
- [ ] Enable online payments only after provider sessions, signed webhooks, amount/currency checks and replay protection pass.
- [ ] Keep real-money refunds and payouts disabled until provider verification and reconciliation are approved.
- [ ] Validate archived/suspended business restrictions in staging and audit each moderation action.

## File uploads

- [x] Image uploads have image type and size validation in the Laravel requests.
- [x] Stored filenames are generated server-side and tenant-scoped.
- [ ] Verify web-server rules cannot execute uploaded content and private disks are not publicly mounted.
- [ ] Verify image processing limits and malware scanning requirements for the selected host.

## Operational security

- [ ] Use least-privilege PostgreSQL, Redis and filesystem identities.
- [ ] Store `APP_KEY`, database credentials and backup encryption keys in a secret manager.
- [ ] Restrict database and Redis network access.
- [ ] Enable alerting for failed queues, checkout failures, database health, disk usage, backup failures and reconciliation drift.
- [ ] Test restore and rollback on isolated infrastructure.
