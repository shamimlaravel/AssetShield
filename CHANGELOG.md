# Changelog

All notable changes to AssetShield are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-09-18

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
  `node_modules` when the binary is not on PATH), obfuscation configuration state (config mirror);
  new `info` display state.
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
- **Plugin hardening** — Windows `isAbsolute` fix, output-naming-hook mask wrapping with a
  deterministic pattern hash (with loud caching warning), legend write + "never serve" guards.
- **E2E smoke** (`npm run test:e2e`) — real Vite build with mask + manifest bridge asserting
  registry v2, legend round-trip, deterministic name parity, and zero source maps.
- **Production payload caching** — decoded `manifest.json` / `registry.json` are served through the
  Laravel `Cache` facade (single stable key per artifact path) whenever
  `asset-shield.environment=production` — the package's own environment switch, independent of the
  framework `APP_ENV`. Each entry stores the artifact mtime alongside the payload and is re-read
  whenever the file changes, so a rebuild never serves a stale decoded payload even if a purge is
  missed; `asset-shield:build` still purges the keys. `ASSET_SHIELD_CACHE` (`cache.enabled`) is the
  single kill-switch for both the payload cache and the HTTP cache headers.
- **Registry alignment** — TS and PHP classify assets identically: `image` (svg/png/jpg/jpeg/gif/
  webp/avif/ico), `font` (woff/woff2/ttf/otf/eot), `style` (css), `script` (js/mjs/cjs + default).
- **PHP hardening** — `declare(strict_types=1)` on every `src/` file; fully-typed Symfony response in
  `AssetResponse::decorate()`; PHPStan level 6 clean across `src/` (`composer test:static`).

### Changed

- **`expires` accepts `int|\DateTimeInterface|null`** — `AssetShield::url()` / `sign()`,
  `AssetUrlGenerator` and `HmacAssetSigner::sign()` now take Carbon/`DateTimeInterface` values
  (e.g. `now()->addMinutes(10)`) in addition to Unix timestamps.
- **Signed-URL enforcement** — when `runtime.signed_urls=true` an explicit `signed: false` no longer
  opts out per call; the generator emits a signed URL because the delivery middleware verifies it.
  `runtime.signed_urls=false` remains the global escape hatch.
- **Plugin obfuscation engine loader** — `javascript-obfuscator` is required **in-process** once per
  build (module lookup cached) with no temp files on the happy path; the subprocess loader survives
  only as a fallback for exotic environments. Options are unchanged (`enabled` / `preset` /
  `obfuscateChunks` / `include` / `exclude`) — no `engine` / `node_binary` / `package_path` / `timeout`
  to configure.

### Fixed

- **Registry default path** — the shipped default is now `app/asset-shield/registry.json` on both
  sides, resolved under `storage_path()` exactly once. Previously the config default already included
  the `storage/` prefix while `AssetRegistry::fromConfig()` prefixed it again, silently writing and
  reading `storage/storage/app/asset-shield/registry.json`.
- **Cache environment gate** — payload caching keys off `asset-shield.environment` (falling back to the
  documented default) instead of the framework `app.env`, so a package-configured environment is
  authoritative and `APP_ENV` drift cannot silently disable or enable the cache.
- **Stale cache after rebuild** — cache entries now carry the artifact mtime and are validated on
  read, closing the window where a rebuild that did not purge (e.g. an out-of-band deploy) served the
  previous decoded payload.
- **ETag parity across drivers** — `PublicDriver` and `StreamDriver` now emit the same metadata-derived
  ETag (`md5(size-mtime)`) for a given compiled file; `fromContents()` still falls back to a
  content-derived ETag when no source path is available.
- **Fail-closed signing config** — `HmacAssetSigner` rejects an empty or literal `"null"` secret
  (case-insensitive) with a `RuntimeException` instead of signing with a predictable key, matching
  `OpaqueId`'s guard.
- **Poisoned registry diagnostics** — a registry with a non-string `original`/`integrity`/`type` field,
  missing `assets` map, unsafe path, or a stored opaque id that no longer matches the app key now
  raises `RegistryInvalidException` rather than loading a partial map; `asset-shield:doctor` reports it
  as `Asset registry invalid` and exits non-zero.
- **Build source-map warning** — `asset-shield:build` warns when `build.source_maps=true` or the
  manifest still contains `.map` records, mirroring the doctor check.

### Changed

- **Hot-path performance** — `MimeMapper` classifies a path in a single pass shared by `family()`,
  `forPath()` and `isForbidden()`; `AssetIdentity::contentType()` and `AssetManifest::absolutePath()`
  are memoized per instance (`refresh()` clears the manifest memo); `AssetShieldManager` threads the
  resolved record through `script()`/`style()`/`render()`/`renderVite()` instead of re-resolving, and
  its `url()` branches were collapsed.
- **Shared support helpers** — new `Support\Path::isAbsolute()` (POSIX, drive-letter and UNC aware) is
  used by the manifest, registry and legend config loaders; new `Support\ArtifactCache` centralizes the
  production payload-cache gate, key derivation, mtime validation and purge used by `AssetManifest`
  and `AssetRegistry`.
- **`MaskPlanner::fromConfig()`** — build and doctor commands construct the planner from the published
  `asset-shield.mask` config through one factory, removing duplicated config plumbing.

### Removed

- **Dead APIs** — `AssetResolver::resolveLogical()`, `AssetRegistry::originalForLogical()`,
  `HmacAssetSigner::defaultExpires()`, `AssetIdentity::isStyle()`/`isScript()`,
  `Legend::entry()`, `AssetShieldManager`'s private `integrityFor()`, and the `HmacAssetSigner`
  constructor's unused `$defaultExpires` argument. The manager's `signer()`/`registry()`/`manifest()`
  accessors are kept and directly tested.

- **PHP-side obfuscation engine** — `Obfuscation\ObfuscationEngine`, `Obfuscation\JavascriptObfuscator`,
  `Exceptions\ObfuscationException`, the container binding, the `asset-shield:status` engine row and
  the `asset-shield:doctor` engine probe. Obfuscation stays native to `@asset-shield/vite-plugin`
  (its optional-peer `javascript-obfuscator`); no PHP engine is exposed.
- **Dead config keys** — `mask.dictionary` / `ASSET_SHIELD_MASK_DICTIONARY` and
  `obfuscation.engine|node_binary|package_path|timeout` (+ their env vars) removed.
- **Docs accuracy refresh** — opaque URL examples now use the real `as_`-prefixed IDs, the HMAC
  signature context is documented as `asset-shield-sign::`, the route constraint and registry payload
  match the implementation, and the 6 HTML pages follow the corrected markdown.

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