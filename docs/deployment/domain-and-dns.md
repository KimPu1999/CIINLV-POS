# Domain, DNS and certificate plan

The names below are placeholders only. Do not create records or request certificates without ownership and change approval.

## Records

| Hostname | Record | Target |
|---|---|---|
| `admin.example.com` | A/AAAA or CNAME | POS/Admin static hosting or TLS edge |
| `shop.example.com` | A/AAAA or CNAME | Marketplace static hosting or TLS edge |
| `api.example.com` | A/AAAA or CNAME | Laravel API TLS edge/load balancer |

Use provider-specific health checks and keep DNS TTL low during the initial cutover. Do not point production names at local development services.

## TLS

- Issue certificates for all three hosts through the approved certificate manager.
- Redirect HTTP to HTTPS.
- Configure Laravel `APP_URL`, `APP_FORCE_HTTPS=true`, trusted proxies, Sanctum stateful domains, CORS origins and secure cookies using the final hosts.
- Verify certificate chain, renewal, hostname coverage and HSTS only after all production subdomains are HTTPS-ready.

## CORS and cookies

Allowed origins must be exact HTTPS origins for `admin.example.com` and `shop.example.com`; credentials must not be used with wildcard origins. Validate the final `SESSION_DOMAIN`, `SameSite`, `Secure`, CSRF and Sanctum behavior in staging before DNS cutover.
