# Troubleshooting

Work through issues by **outcome**, then root cause. When in doubt, start every session with:

```bash
php artisan asset-shield:status     # what is configured
php artisan asset-shield:doctor     # what is broken with the environment
```

---

## Scripts return 404 "Asset not found"

**Signs:** `<script src="/assets/...">` returns 404; errors in the browser console; Laravel log
shows `AssetNotFoundException`.

**Causes & fixes**

1. Registry not regenerated after a build → `php artisan asset-shield:build` then `php artisan
   cache:clear`.
2. Entry not in the Vite build (typo, or `laravel-vite-plugin` input list missing it) → confirm the
   entry is present in `npm run build` output and listed under `input:`.
3. Registry file is stale / from another environment → regenerate *in the production environment*;
   remember IDs are derived from `APP_KEY`.
4. `.env`'s `ASSET_SHIELD_ROUTE_PREFIX` mismatch — the request URL prefix must match the config.

**Verify:**

```bash
php artisan asset-shield:build   # fails loudly when entries are unresolvable
php artisan cache:clear
```

---

## 403 on every /assets request

**Signs:** all protected URLs return Forbidden, including freshly generated ones.

**Causes & fixes**

1. `/assets` requires a signature and the emitted query params are being stripped by your proxy/CDN →
   allow `?expires&signature` through, or configure AssetShield to emit unsigned URLs and let the
   edge handle authorization.
2. `APP_KEY` differs between the process that generated the page and the process serving assets
   (e.g., PHP-FPM workers with different env files) → normalize env; verify `.env` single source.
3. Test directly:

```bash
# emit a fresh signed URL (from your layout or tinker), then:
curl -s -o /dev/null -w "%{http_code}\n" "<the-url>"
```

---

## Assets load in dev but obfuscation breaks production JS

**Signs:** `ReferenceError`, `SyntaxError`, or "is not defined" only in `vite build` output with
obfuscation enabled.

**Fixes**

1. Downgrade preset: `light` → fewer transforms.
2. Exclude the offending chunk:

```js
assetShieldVite({
    obfuscation: {
        enabled: true,
        exclude: ['**/*.min.js', 'vendor/**'],
    },
})
```

3. If a third-party library breaks, confirm a `node_modules` chunk wasn't obfuscated — restore
   `obfuscateChunks: 'application'` (default).
4. As a last resort, disable obfuscation; AssetShield still protects URLs and access without it.
   Obfuscation is a knob, not a requirement.

---

## `@vite()` no longer works after installing the package

**Fixes**

1. Confirm you did NOT put the same entry in both `@vite()` and `@shieldVite()` (duplicate tags are
   the symptom of mixing).
2. Confirm `asset-shield.enabled` is `true` in the env you expect.
3. `@vite()` output comes from Laravel's Vite integration, independent of AssetShield — if it broke,
   the cause is usually a plugin config change in `vite.config`, not AssetShield.

---

## Doctor reports problems you can't reproduce

`doctor` checks the **runtime environment**, not the build. When staging passes but production
fails, recheck:

| Check | Production gotcha |
|---|---|
| `APP_DEBUG=false` | `.env` in prod leaking `APP_DEBUG=true` |
| `node_modules is outside public root` | a deploy script that copies `node_modules` into `public/` |
| `.env` location | Nginx root pointing at the project root instead of `public/` |
| Source maps | `ASSET_SHIELD_SOURCE_MAPS=true` leftover in prod env |
| Debugbar detection | `barryvdh/laravel-debugbar` present in production |

Each warning's message states the concrete fix.

---

## Cache behaving unexpectedly (stale or missing)

| Symptom | Cause / fix |
|---|---|
| Browser never re-fetches new JS after deploy | New build → new opaque IDs; if you see 200 on old ID, its CDN key is stale → purge CDN or wait for immutable expiry (by design). |
| Signed links 403 immediately | `expires` in the past (clock skew in the app generating vs. the client clock is irrelevant — server clock rules); too-short `runtime.expires` default; NTP the app servers. |
| `Cache-Control` lacks `immutable` | You're serving a signed asset → clamped to remaining lifetime (expected). Unsigned immutable assets get `immutable`. |

---

## npm / composer install errors

- `@asset-shield/vite-plugin` requires Node ≥ 18 and Vite ≥ 5 — upgrade, or pin a compatible version.
- `javascript-obfuscator` is optional; if `npm install` complains, you don't need it unless
  obfuscation is enabled.
- Composer: the package requires PHP ^8.3 and Laravel 13–compatible `illuminate/*`. On Laravel < 13,
  pin a compatible release or upgrade.

---

## Distinguishing security-test false positives

When testing traversal/leaks, use the **documented surface**, not invented endpoints:

```bash
# These must ALL be denied:
curl -s -o /dev/null -w "%{http_code}\n" "/assets?file=../../.env"
curl -s -o /dev/null -w "%{http_code}\n" "/assets/../../.env"
curl -s -o /dev/null -w "%{http_code}\n" "/assets/<opaque>?expires=9999999999&signature=deadbeef"
# expect: 403/404, never 200 with content
```

If you *expect* 403 semantics and get 404, that's equally safe — the ID simply didn't resolve.
Security is that both are **non-delivery** outcomes.

---

## Still stuck?

1. `php artisan asset-shield:doctor` — read every line.
2. `php artisan asset-shield:status` — confirm the config surface.
3. Enable the during-build logs (`LOG_LEVEL=debug`) and repeat `npm run build`.
4. If the registry is write-protected in prod (read-only deploy), run
   `asset-shield:build` as a deploy step that writes to a writable volume, then cache-warm.

Open a detailed issue (stack, versions, doctor output) for anything this page doesn't resolve.