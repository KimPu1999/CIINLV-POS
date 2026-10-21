# Monitoring Guide

## Health endpoints

- `GET /api/v1/health`: public liveness only. It does not expose diagnostics.
- `GET /api/v1/health/ready`: readiness check for database and configured Redis. It returns a generic failure message and HTTP 503 when unavailable.
- `GET /api/v1/platform/monitoring`: authenticated Super Admin-only operational overview.

The detailed endpoint is read-only and bounded. It reports API/database readiness, failed-job summaries, private storage configuration, backup artifact presence, inventory reconciliation findings, stored marketplace payment states, and security posture flags.

## Dashboard

Open `/platform/monitoring` in the existing Super Admin application. Use Refresh to request a new snapshot. Values are API-backed; `Unknown`, `Unavailable`, and `Not configured` are intentional states when the current schema or provider configuration cannot prove health.

## Operational interpretation

- Queue `unknown` means no worker heartbeat is persisted. A failed-job count is not proof that a worker is alive.
- Inventory findings are report-only. Use the existing authorized inventory services for corrections after review.
- Backup `ok` means an artifact exists, not that a restore has been verified.
- Payment `not_configured` means live online payment monitoring is not enabled.
- Security indicators are read-only and do not change HTTPS, debug, session, or database policy settings.

## Suggested staging checks

1. Authenticate as a Super Admin and verify the page loads in English and Burmese.
2. Verify Business Admin and Customer accounts receive HTTP 403 for the detailed endpoint.
3. Create an isolated failed-job fixture and confirm only queue, reference, and timestamp are shown.
4. Run inventory reconciliation against a staging database and confirm no stock rows change.
5. Place a sandbox COD order and verify the payment state is reported without being changed.
6. Verify the public health endpoint contains no database diagnostics.

## Not enabled automatically

No production alert destination, scheduled cleanup, stock correction, refund, payout, deployment, or infrastructure change is enabled by this work.
