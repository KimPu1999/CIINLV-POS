# Server and capacity requirements

These are starting assumptions for staging and a modest initial production deployment, not a capacity guarantee. Load test only against isolated staging.

## Application runtime

| Component | Requirement |
|---|---|
| OS | Current supported Ubuntu/Debian LTS or managed container runtime |
| PHP | PHP 8.3+; the production Dockerfile targets PHP 8.3-FPM |
| PHP extensions | `bcmath`, `gd`, `mbstring`, `pdo_pgsql`, `zip`, `fileinfo`, `opcache`, Redis client |
| Node | Node 22 for Vite builds; Node is not required at API runtime |
| Web server | Nginx or managed TLS reverse proxy with PHP-FPM |
| Database | PostgreSQL 16+ compatible with the migrations; private networking and TLS preferred |
| Cache/queues | Redis 7+ with authentication/private networking |

## Initial sizing hypothesis

- Staging: 2 vCPU, 4 GB RAM, 30 GB application disk, separate small PostgreSQL/Redis services.
- Small production: 2–4 vCPU, 8 GB RAM for web/API plus separate managed PostgreSQL and Redis; persistent media storage sized from actual upload growth.
- Worker capacity: start with two long-running workers and tune using queue latency, memory and retry rates.
- Database: provision storage for live data, indexes, WAL, backups and growth; do not use local development sizing as production capacity.

## Network and firewall

- Public: TCP 443 (and TCP 80 only for redirect/ACME if needed).
- Private only: PHP-FPM, PostgreSQL 5432, Redis 6379, queue management and internal monitoring ports.
- Restrict administrative SSH/VPN access by source IP and identity provider.
- Do not expose PostgreSQL or Redis publicly.

## Storage

Separate persistent storage for Laravel `storage/`, private identity documents, public marketplace images, generated reports, logs and encrypted backups. Ensure the web process cannot execute uploaded files.
