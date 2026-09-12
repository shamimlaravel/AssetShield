# Changelog

All notable changes to AssetShield are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned (post-MVP)

- Delivery drivers: Nginx `X-Accel-Redirect`, Apache `X-Sendfile`, S3, Cloudflare R2, CDN signing.
- Optional database-backed registry; per-path hotlink policies.
- `@shieldVite()` refinement and integration edge cases.
- Expanded doctor checks (OPcache, CDN configuration).
- Non-goals remain non-goals: DRM, VM-based JS encryption, anti-debugging theater.

## [0.1.0] — MVP

### Added

- **Service provider** with config merge, manager registration, Blade directives, route
  registration, Artisan commands, and publishables.
- **AssetShieldManager** — facade surface (`url`, `script`, `style`, `resolve`, `sign`, `status`).
- **AssetManifest** — Vite `public/build/manifest.json` loading, entry/file resolution, production
  caching, actionable exceptions.
- **AssetRegistry** — logical → compiled → opaque map, persisted as a build artifact, validated on
  load (no absolute/`..` paths), cached in production.
- **Opaque IDs** — deterministic HMAC-SHA256 under `APP_KEY`, 8-byte hex, never sequential.
- **AssetSigner / HmacAssetSigner** — signed, expiring URLs; timing-safe verification; expiring and
  forged signatures rejected with 403.
- **AssetController** — registry-only resolution, signature validation, MIME mapping, security and
  cache headers; path traversal / `.env` / vendor reads structurally impossible.
- **Delivery drivers** — `AssetDeliveryDriver` interface with `PublicFileDriver` and `StreamDriver`;
  architecture reserved for Nginx/S3/R2/CDN drivers.
- **Blade directives** — `@assetShield`, `@assetShieldCss`, `@assetShieldJs`, `@shieldVite`.
- **Vite plugin** (TypeScript) — production-only activation, registry generation, optional JS
  obfuscation via `javascript-obfuscator` adapter, vendor-chunk exclusion by default, include/exclude
  globs, no custom bundling, dynamic imports preserved, CSS never obfuscated.
- **Source maps** — off by default; `.map` rejected for protected assets; loud warning when enabled.
- **Commands** — `asset-shield:install`, `asset-shield:build`, `asset-shield:status`,
  `asset-shield:doctor`.
- **Tests** — Pest/PHPUnit (Orchestra Testbench) for the Laravel package; Vitest for the plugin.
- **Documentation** — README, full docs set, PRD, TRD; honest security posture stated throughout.

### Security

- No secrets in client artifacts, HTML, logs, or errors.
- `X-Content-Type-Options: nosniff`, correct Content-Type, no wildcard CORS, immutable caching.
- No security-through-obscurity claims; browser-delivered code documented as inspectable.