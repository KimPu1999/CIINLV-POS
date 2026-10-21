# Maintenance Plan

## Daily

- Review failed jobs and checkout/payment anomalies.
- Review inventory reconciliation output; do not auto-correct quantities.
- Review security audit events and repeated unauthorized requests.
- Confirm application and database logs are being retained safely.

## Hourly / frequent jobs

- Backup health command runs hourly.
- Expired marketplace reservation release runs every minute.
- Queue workers require an external process supervisor and heartbeat monitoring before production enablement.

## Weekly

- Review dependency advisories and update proposals.
- Review slow-query and API timing samples from a non-production or approved observability environment.
- Review disk, object storage, and log growth.
- Review failed backup artifacts and storage permissions.

## Monthly

- Test PostgreSQL restore in a separate non-production database.
- Test uploaded media recovery.
- Review role and tenant-isolation tests.
- Review certificate expiry, CORS origins, cookie settings, and security headers.
- Reassess unresolved production blockers.

## Automation boundary

Automated cleanup, inventory correction, refunds, payouts, dependency major upgrades, migrations, and production deployment remain disabled until separately reviewed and tested.
