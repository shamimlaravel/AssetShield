# Production Deployment

This guide covers hardening and accelerating AssetShield in production. It assumes installation
is complete and `npm run build` + `php artisan asset-shield:build` are working.

## 0. Pre-deploy checklist (run this)

```bash
php artisan asset-shield:doctor
```

`doctor` inspects and reports, with actionable hints:

- `APP_ENV` / `APP_DEBUG` (`APP_DEBUG` must be `false`)
- `public/build/manifest.json` presence and validity
- whether source maps are enabled (must be off)
- whether `node_modules` has leaked into the web root
- where `.env` sits (must be outside `public/`)
- Debugbar detection
- registry validity (entries resolve; no absolute/`..` paths)

Example healthy output:

```
✓ Production environment
✓ APP_DEBUG=false
✓ Vite manifest found
✓ Source maps disabled
✓ Asset registry valid
✓ node_modules is outside public root
```

Every `✗` line comes with a concrete fix.

## 1. Build & registry lifecycle

| Step | Command | Purpose |
|---|---|---|
| 1 | `npm ci && npm run build` | Produce versioned, hashed build + registry via the Vite plugin |
| 2 | `php artisan asset-shield:build` | Re-validate manifest, regenerate/refresh the server-side registry, fail loudly on missing entries |

Deploy order in CI: commit or ship the build output, run `asset-shield:build`, cache warm, then
switch traffic.

## 2. Cache headers & CDN offload

For immutable unsigned assets the response is:

```
Cache-Control: public, max-age=31536000, immutable
ETag: "…"
```

Because opaque IDs are key-derived and per-deploy, a **CDN can cache `/assets/{id}` for a full
year** — assets never change until you rotate `APP_KEY` or rebuild (new IDs). This is the
performance model: browsers and CDNs absorb the traffic; PHP serves each unique asset at most once
per cache layer.

For signed/expiring assets, `max-age` is clamped to the remaining lifetime so a browser never
re-validates past the expiry.

Place the CDN in front of the whole site, or at least `/assets/*`. Your CDN must forward query
strings (`?expires&signature`) — or you can configure AssetShield to emit unsigned immutable URLs
when the delivery layer handles authorization outside PHP.

## 3. Nginx recommendations

Blocklist direct source access regardless of AssetShield. Standard Laravel hardening applies; the
AssetShield-specific additions:

```nginx
# Web root locks — keep these under your server block that serves the app
location ~* \.(env|git|log|bak|orig|old|md|sql|ini|lock)$ { deny all; }
location ~ /\. { deny all; }                               # dotfiles

# Never serve source maps through the app
location ~* \.map$ { deny all; }

# Optionally offload immutable assets before PHP (PHP-FPM cache already handles it, this adds Nginx)

# If a future driver emits X-Accel-Redirect headers, add:
# location ^~ /_protected/ { internal; alias /var/www/app/public/build/; }
```

Notes:

- `deny all` on dotfiles/private extensions is defense-in-depth: AssetShield already prevents these
  reads through its controller; Nginx should too.
- Keep `APP_DEBUG=false`; `APP_DEBUG=true` renders stack traces that reveal paths and the composer
  `vendor/` layout.
- Never alias `public/` to the Laravel project root; set your root correctly to `public/`.

## 4. Performance architecture (why it scales)

- Registry + manifest are **cached** in production (no per-request scans, no DB lookups).
- Opaque IDs are immutable strings; resolution is an in-memory array lookup.
- Long-lived HTTP caching (31536000s immutable) shifts work to browsers/CDN.
- Delivery drivers abstract transportation: **PublicDriver** (default) and **StreamDriver** ship
  in MVP; the same controller supports future `X-Accel-Redirect`, `X-Sendfile`, S3, R2, and CDN
  drivers without rework — enabling you to move bytes to the edge.

## 5. Environment matrix

| Env | `runtime.enabled` | `runtime.signed_urls` | `cache` | Notes |
|---|---|---|---|---|
| Local dev | `true` | `true` | default | Use `@vite()` for HMR; AssetShield is inactive in dev server |
| Staging | `true` | `true` | default | Run `doctor` here after each build |
| Production | `true` | `true` (default) | immutable | CDN on `/assets/*` |
| Rollback | `false` | — | — | AssetShield disabled; `@vite()` serves assets |

## 6. Hotlink protection

AssetShield has no built-in `hotlink_protection` switch — correct host gating depends on your
upstream. Enforce it at the reverse proxy / CDN layer: validate the `Host` for `/assets/*` and, if
you want to block cross-site references, gate on `Referer` or use signed URLs (HMAC + expiry) as the
access boundary. Never rely on `Referer` alone — it is trivially spoofable and absent for many
legitimate clients.

## 7. Verifying a release

```bash
php artisan asset-shield:status      # settings snapshot
php artisan asset-shield:doctor      # health
curl -sI https://your.app/assets/<opaque-id> | grep -iE "cache-control|content-type|x-content"
# expect:
# Cache-Control: public, max-age=31536000, immutable
# Content-Type: text/javascript
# X-Content-Type-Options: nosniff
```

A forged signature check:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://your.app/assets/<opaque-id>?expires=9999999999&signature=deadbeef"
# 403
```

## 8. Common failure modes → quick fixes

| Symptom | Fix |
|---|---|
| `script 404` errors after deploy | Run `php artisan asset-shield:build`; clear app cache; confirm registry on disk |
| Everything works locally, breaks in prod | `APP_KEY` different → run `asset-shield:build` in prod environment |
| Obfuscated bundle throws at runtime | Use `preset: 'light'`, add `exclude` globs for the offending chunk, or disable obfuscation |
| CDN serving stale JS after deploy | New deploy = new opaque IDs (key-derived) → CDN keys change; ensure registry regenerated |

Continue to [Testing](testing.md) and [Troubleshooting](troubleshooting.md).