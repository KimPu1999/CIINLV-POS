# Security Monitoring

## Monitored signals

- Failed login and authentication audit events.
- Unauthorized platform/API access attempts.
- Super Admin business lifecycle and marketplace moderation actions.
- Stored marketplace payment status anomalies.
- Inventory reconciliation discrepancies.
- Failed queue jobs and backup health.

## Redaction rules

Do not log or display passwords, access tokens, cookies, payment card data, provider secrets, raw job payloads, exception traces in API responses, or unnecessary private customer details. The monitoring endpoint returns hashed failure references and queue metadata only.

## Access controls

Detailed operational data is protected by Sanctum authentication and `platform.admin`. Business Admin and Customer users must not receive platform-wide monitoring data. Public health is intentionally minimal.

## Remaining hardening

- Configure an approved external alert channel.
- Forward `X-Request-Id` and the Laravel `correlation_id` log context into the approved centralized logging system.
- Verify security headers, CORS origins, TLS, session cookies, rate limits, and log retention on the actual staging host.
- Test cross-tenant denial and sensitive-data redaction in staging.
