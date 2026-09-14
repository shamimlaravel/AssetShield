# Security Model (Explainer)

This page explains AssetShield's **actual** security properties and its honest limits. Read this
before you deploy anything, and before you place "hiding the code" in a requirement list.

## The one thing to internalize

> **AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering.**

Any JavaScript, CSS, or SVG that reaches a browser can — technically — be read, saved, renamed, and
re-run outside your site. Obfuscation makes that harder and slower; it does not make it impossible.
AssetShield refuses to claim otherwise.

## What AssetShield protects

### 1. Server-side source files

Your real source trees — `resources/`, `app/`, `routes/`, `vendor/`, `node_modules/`, `.env`,
Composer files, `package.json` — are **not** placed behind the protected URL scheme at all. The
delivery controller resolves only through the **AssetRegistry**, which knows only compiled,
manifest-resolved, build artifacts. Arbitrary file paths are structurally impossible to request.

```
BAD      GET /assets?file=../../.env          → rejected (no such parameter exists)
BAD      GET /assets/../../.env               → route does not match
GOOD     GET /assets/7f92a8c1                 → registry lookup → compiled file
```

Path traversal, `.env` retrieval, storage/vendor traversal, and PHP-source reads are prevented at
three layers:

1. Route shape — the `{asset}` segment accepts only opaque IDs (`[a-f0-9]{8,32}`).
2. Registry-only resolution — ID → relative compiled path; no user string is ever treated as a path.
3. Filesystem confinement — compiled paths are canonicalized against the public build root; any
   escape resolves to 404.

### 2. URL abstraction

Users see `/assets/7f92a8c1`, never `/build/assets/app-A91Kx.js`, and with optional *masking* they
no longer even see `app-A91Kx.js` — build-time filename renaming (`nameless` hashes or `codename`
words) removes framework fingerprints and module boundaries from every served filename. Masking is
**presentational, not cryptographic**; it pairs with the opaque-URL scheme, it does not replace it.
Both are keyed to the deployment and both rotate with `APP_KEY`.

### 3. Access control & expiration

Signed URLs (HMAC-SHA256, constant-time verification) let assets expire or be gated. Forged or
expired signatures return **403**.

### 4. Reverse-engineering friction

Optional, off-by-default JS obfuscation (via `javascript-obfuscator`) increases the cost of reading
application logic. Vendor chunks are skipped by default so third-party code keeps working.

### 5. Source-map discipline

Production source maps are off by default. `.map` files are never delivered for protected assets.
Even when they exist on disk, the controller rejects them.

## What AssetShield does NOT protect

| Claim | Reality |
|---|---|
| "Encrypted JavaScript" | Not in MVP. No VM, no runtime decryption. |
| "Hidden source" | Impossible. Browser-delivered code is inspectable. |
| Anti-debugging / DevTools blocking | Explicit non-goal (and mostly defeatable anyway). |
| Perfect DRM | Out of scope; DRM is an ecosystem-level problem, not a per-file property. |
| "Obfuscation = encryption" | Wrong and dangerous framing; we document the difference. |

## Threat modeling on the wire

| Attacker capability | AssetShield response |
|---|---|
| Reads `.env` by URL guessing | Impossible via AssetShield; `.env` not exposed; doctor checks deployment. |
| Replays a leak old signed URL | Expiration → 403 after `expires`. |
| Forges a signature | HMAC under `APP_KEY`; constant-time verify → 403. |
| Reads a cached public asset | Expected; immutable caching trades exposure for performance. Keep truly sensitive data out of public assets. |
| Harvests URLs to map internal structure | Opaque IDs are key-bound and non-sequential. |
| Downloads app JS and de-obfuscates | Expected; costs time, not impossible. Acceptable if protection is your goal, not secrecy. |

## Security headers shipped

| Header | Value | Why |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME-sniffing downgrades |
| `Content-Type` | Correct type per extension | JS/CSS/SVG/JSON/fonts/images |
| `Cache-Control` | `public, max-age=31536000, immutable` (unsigned) | Long-lived for build artifacts |
| `Content-Security-Policy` | strict `default-src 'none'` (assets) / page policy (via CSP helper) | On by default for asset contract; page-level policy is opt-in |
| `Access-Control-Allow-Origin` | **never** `*` unless explicitly configured | No wildcard CORS |

With `asset-shield.security.csp=true`, every protected asset response also carries an explicit,
strict `Content-Security-Policy` (`default-src 'none'` — binary content needs no inline allowances).

## CSP helper for your own pages

For page HTML, use the facade to emit an identical, strict policy plus a fresh per-request nonce:

```php
// App\Http\Middleware\ApplyContentSecurityPolicy
public function handle($request, Closure $next)
{
    $response = $next($request);
    $response->headers->set('Content-Security-Policy', \Shamimstack\AssetShield\Facades\AssetShield::cspHeader());
    return $response;
}
```

```blade
<script nonce="{{ \Shamimstack\AssetShield\Facades\AssetShield::cspNonce() }}">
    window.App ??= {};
</script>
<script src="{{ asset('js/app.js') }}" nonce="{{ \Shamimstack\AssetShield\Facades\AssetShield::cspNonce() }}"></script>
```

- The nonce is stable within one request (one CSP value, one nonce) and recycled every request.
- A CSP nonce is **not a secret** — it is delivered to clients. Only its per-request uniqueness and
  the fact that untrusted content cannot predict it are what make it useful.
- Extend `default-src`/`script-src` with `asset-shield.security.allowlist` entries rather than
  `'unsafe-inline'`; the helper never emits `'unsafe-inline'` on its own.

## The masking legend

The legend (default `storage/app/assetshield/legend.json`) is the one AssetShield artifact that
reconstructs the original (pre-mask) names. Treat it as secret:

- Keep it out of `public/` (AssetShield refuses to serve it) and out of public repository history.
- A leaked legend alone does not expose code — but combined with the served assets it undoes
  filename masking for the curious. It poses **no** similar risk to the protected runtime route,
  which never depends on filename secrecy.
- Missing legend ≠ failure: AssetShield degrades to manifest names and reports it in
  `asset-shield:build` / `:doctor`.

## Production hard rules

- Set `APP_KEY` and `APP_DEBUG=false` in production.
- Keep `public/` free of `.env`, `node_modules`, `app/`, `routes/`, `vendor/`, `storage/`.
- Run `php artisan asset-shield:doctor` before deploy (it checks `.env` location, `node_modules`
  accessibility, source maps, debugbar, registry validity).
- Rotate `APP_KEY` = invalidate every opaque ID and signature (they're key-derived). That's by
  design — know it before rotating.

## A note on rotating the app key

Changing `APP_KEY` changes every opaque ID and signature. If you rotate keys, rebuild your
registry and deploy so old URLs stop being resolved.

## Further reading

- [Signed URLs](signed-urls.md) — mechanics and API
- [Vite integration](vite.md) — obfuscation and source-map policy
- [Production deployment](deployment.md) — Nginx/CDN hardening
- [Troubleshooting](troubleshooting.md) — using `asset-shield:doctor` output