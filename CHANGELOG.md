# Changelog

All notable changes to AssetShield are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Masking** (PHP + plugin, deterministic build-time filename renaming) — `nameless`/`codename`/
  `preserve` strategies, logical aliases, include/exclude globs, cross-side seed parity, and a secret
  **legend** artifact (`storage/app/asset-shield/legend.json`) mapping every pre-mask → masked name.
  Names are presentational, not cryptographic; documented honestly.
- **Registry v2** — plugin & server both write/consume `{version, built_at, assets:{logical}}` with
  optional `original` (pre-mask) and `integrity` fields; `buildLegend`/`writeLegend` helpers.
- **`asset-shield:build --run`** — runs `npm run build` via a subprocess, then regenerates the
  registry from manifest + legend.
- **Doctor checks 9–13** — mask legend consistency (seed mismatch fails fast), duplicate output
  filenames, broken manifest file references, toolchain versions (PHP/Node/NPM/Vite, resolved from
  `node_modules` when the binary is not on PATH), obfuscation engine availability; new `info`
  display state.
- **Plugin watch-mode fixes** — `buildStart` clears the mask planner collision tracker and rename map
  so rebuilds (Vite watch) produce identical deterministic names instead of accumulating suffixes;
  `[format]` output placeholder support; dead `obfuscation.engine` option removed from the type.
- **Mask parity fixes** — PHP `MaskPlanner` now falls back to the plugin's `asset-shield` seed on
  empty input (was `?? 'asset-shield'`, which kept `''`), and an empty `include` list matches every
  path exactly like the plugin; `include`/`exclude` are now passed into the planner from
  `asset-shield:build` and `asset-shield:doctor`; dead `--fresh` option removed.
- **CSP & security headers** — `Security\Csp` policy builder, `AssetShield::cspNonce()` /
  `cspHeader()`, optional strict `Content-Security-Policy` on protected asset responses
  (`security.csp`), page-level allowlists without `'unsafe-inline'`.
- **PHP obfuscation adapter** — `Obfuscation\ObfuscationEngine` + `JavascriptObfuscator` (drives the
  `javascript-obfuscator` package through Node; presets; vendor exclusions honored plugin-side);
  engine availability surfaced by `asset-shield:status`.
- **Plugin hardening** — Windows `isAbsolute` fix, output-naming-hook mask wrapping with a
  deterministic pattern hash (with loud caching warning), legend write + "never serve" guards.
- **E2E smoke** (`npm run test:e2e`) — real Vite build with mask + manifest bridge asserting
  registry v2, legend round-trip, deterministic name parity, and zero source maps.

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
- **Delivery drivers** — `AssetDeliveryDriver` interface with `PublicDriver` and `StreamDriver`;
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