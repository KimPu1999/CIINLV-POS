# Incident Response

## Initial triage

1. Record the time, affected service, request ID if available, tenant/business scope, and user-visible symptom.
2. Check public liveness and readiness without exposing their responses publicly.
3. Review `/platform/monitoring` as an authorized Super Admin.
4. Inspect Laravel logs and failed jobs without copying credentials, tokens, payment data, or private customer information into tickets.
5. Determine whether the incident is availability, authorization, inventory, payment, data integrity, or deployment related.

## Severity guidance

- **Critical:** database outage, cross-tenant exposure, unauthorized payment state, overselling, or broad checkout failure.
- **High:** queue backlog, failed backup, inventory discrepancy, webhook verification failure, or admin authorization failure.
- **Medium:** isolated API errors, degraded search/reporting, or missing non-critical notifications.
- **Low:** UI defects with a safe workaround.

## Containment

- Preserve logs and evidence.
- Do not reset PostgreSQL, delete orders, rewrite ledger entries, or manually change inventory to hide an alert.
- Disable an affected payment method or marketplace publication path only through an approved, audited backend control.
- Keep Sanctum, tenant middleware, and authorization policies enabled.
- Do not issue refunds or payouts until provider evidence and authorization are confirmed.

## Recovery and closure

Validate database connectivity, queue processing, reservation state, payment reconciliation, tenant isolation, and affected user workflows in staging or an isolated recovery environment before reopening traffic. Document root cause, timeline, data impact, corrective action, and follow-up owner.
