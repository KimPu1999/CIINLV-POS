# HTTPS and web-server configuration

Reviewed templates are in:

- [api.example.conf](/Users/kimpu/Desktop/CIINLV-POS/backend/deploy/nginx/api.example.conf)
- [static.example.conf](/Users/kimpu/Desktop/CIINLV-POS/backend/deploy/nginx/static.example.conf)
- Existing container baseline: [default.conf](/Users/kimpu/Desktop/CIINLV-POS/backend/docker/nginx/default.conf)

## API server

The API template redirects HTTP to HTTPS, routes Laravel through `public/index.php`, sets upload limits and security headers, rejects hidden files and passes `HTTPS=on` to PHP-FPM. Replace certificate paths and PHP-FPM addresses for the selected host.

## Static SPAs

The static template serves `frontend/dist` and `marketplace-frontend/dist`, uses `try_files ... /index.html` for client-side routes, applies immutable caching only to fingerprinted assets, and does not proxy browser traffic to an arbitrary development server.

## Review before activation

- Verify TLS protocol/cipher policy with the approved edge provider.
- Verify `server_name`, certificate paths, upstreams, body size and timeout values.
- Add a CSP compatible with the camera scanner, API and optional realtime provider after testing.
- Ensure uploaded files cannot execute and private storage is outside the public document root.
- Validate health, CSRF, authentication, static routes, 404 behavior and access logs in staging.

No Nginx configuration has been installed or reloaded by this change.
