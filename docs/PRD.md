# AssetShield — Product Requirements Document

| | |
|---|---|
| **Document** | Product Requirements Document (PRD) |
| **Version** | 0.2.0 |
| **Status** | MVP |
| **Product** | AssetShield |
| **Stack** | Laravel 13 · PHP 8.3+ · Vite ≥ 5 · Node ≥ 18 |
| **Composer package** | `shamimstack/asset-shield` |
| **NPM package** | `@asset-shield/vite-plugin` |

---

## 1. Executive Summary

AssetShield is a **Laravel-native asset protection layer** for Vite-powered applications.

It moves the discussion about "hiding JavaScript" from impossible client-side secrecy to what is
actually achievable server-side: keeping source files out of the public root, abstracting compiled
asset URLs, gating asset access with signed links, and raising the cost of reverse engineering
through optional obfuscation.

The product is guided by one non-negotiable principle:

> **AssetShield does not make browser-delivered code impossible to inspect. It reduces
> exposure, protects asset access, and increases the cost of reverse engineering.**

Nothing delivered to a browser can ever be made truly unreadable. AssetShield never claims otherwise.

---

## 2. Problem Statement

Modern Laravel + Vite applications ship compiled assets that suffer from structural problems:

1. **Source exposure** — source directories (`resources/`, `app/`, `routes/`, `vendor/`,
   `node_modules/`, `.env`) live inside the project that is deployed to the web root. Misconfigured
   servers, directory listing, or `.env` leaks become catastrophic.
2. **Predictable URLs** — Vite emits content-hashed but still **predictable** URLs such as
   `/build/assets/app-A91Kx.js`. Published filenames reveal build structure, framework fingerprints,
   and module boundaries.
3. **No access control** — anyone with a URL can fetch an asset forever. There is no mechanism for
   expiring, signing, or gating asset access.
4. **Uncontrolled reverse engineering** — source maps are frequently shipped to production,
   handing over near-original source; third-party dependency boundaries are trivially visible.
5. **Unclear policy** — teams conflate "obfuscation" with "encryption" and believe browser code can
   be hidden. This leads to impossible requirements and wasted engineering effort.

AssetShield exists to give Laravel teams a **serious, honest, production-ready** answer to these
problems.

---

## 3. Product Positioning

- **Laravel-native.** Composer package, Service Provider, Blade directives, Artisan commands,
  config-driven — it feels like Laravel, not an external service bolted on.
- **Vite-first.** Wraps the official Vite build pipeline instead of replacing it. Existing Laravel
  Vite behavior (`@vite()`, `public/build/manifest.json`) keeps working.
- **Delivery-layer aware.** Designed so Nginx `X-Accel-Redirect`, `X-Sendfile`, S3, R2, and CDN
  acceleration can be added without re-architecting (implemented in future releases, not MVP).
- **Honest security posture.** Documents what it cannot do. Bans security-through-obscurity claims.
- **Drop-in protection.** Applies on top of the standard build pipeline with minimal configuration.

---

## 4. Target Audience & Personas

| Persona | Goals |
|---|---|
| **Laravel application developer** | Protect assets with clean DX; keep `@vite()` working; ship JS/CSS behind protected URLs. |
| **DevOps / platform engineer** | Enforce "source outside web root", `.env` safety, cache policy, Nginx/CDN delivery; run `asset-shield:doctor`. |
| **Security-conscious product owner** | Raise reverse-engineering cost; want documented threat model and no overclaims. |
| **Build / frontend engineer** | Configure Vite plugin; selective JS obfuscation; preserve chunking and dynamic imports. |

---

## 5. Design Principles

1. **Honesty over theater.** Browser code can always be inspected. We say so, clearly, in docs and UI.
2. **Server-side secrets belong serverside.** Keys never enter JavaScript. Client receives opaque
   identifiers and signed URLs, never source paths.
3. **Least exposure.** Source trees stay outside the web root; only resolved, registered assets are
   ever served; arbitrary paths are never accepted.
4. **Composability with existing tools.** Vite hashing, Rollup chunking, Laravel manifest handling,
   `javascript-obfuscator` are used as provided — nothing important is reinvented.
5. **Performance by architecture.** Immutable URLs, cached registry/manifest, long-lived HTTP
   caching, and a delivery layer that can be offloaded to Nginx/CDN.
6. **Safety by default.** Source maps off, obfuscation off by default, immutable cache headers,
   `nosniff`, no wildcard CORS, timing-safe signature checks.

---

## 6. Scope

### 6.1 MVP In-Scope

1. Laravel Service Provider (`AssetShieldServiceProvider`) with config, manager, Blade directives,
   routes, commands, publishables, boot lifecycle integration.
2. Config file `config/asset-shield.php` with the specified key surface.
3. `AssetManifest` — reads the Vite `public/build/manifest.json`, resolves entries and final files,
   caches in production, throws actionable exceptions.
4. `AssetRegistry` — maps logical asset → compiled asset → opaque protected ID.
5. `AssetUrlGenerator` — emits `/assets/{opaque-id}`, deterministic per build, HMAC/crypto-derived,
   never sequential.
6. `AssetSigner` — HMAC signed URLs with expiration and timing-safe validation (403 on failure).
7. `AssetController` at `GET /assets/{asset}` — signatures → registry resolution → correct MIME →
   secure response via delivery drivers.
8. Delivery driver architecture — `AssetDeliveryDriver` interface with MVP drivers
   `PublicDriver` and `StreamDriver`.
9. Blade directives `@assetShield`, `@assetShieldCss`, `@assetShieldJs`, `@shieldVite`, and PHP API
   `AssetShield::url|script|style`.
10. Vite plugin (`packages/vite-plugin`, TypeScript) — production-only, registry generation,
    optional JS obfuscation, vendor-chunk rules, preserves chunking/dynamic imports.
11. Obfuscation native to the Vite plugin — `light` / `balanced` / `aggressive` presets, vendor-safe
    by default, **disabled by default**; no PHP-side engine is exposed.
12. Source maps disabled by default; never exposed for protected assets; intentional enabling is
    warned about in logs.
13. Security and cache headers on responses.
14. Artisan commands: `asset-shield:install`, `asset-shield:build`, `asset-shield:status`,
    `asset-shield:doctor`.
15. Test suites: Pest/PHPUnit (Laravel Orchestra Testbench) + Vitest for the plugin.

### 6.2 MVP Out-of-Scope (Future Versions)

The following are explicitly **not** part of MVP and must not be half-implemented:

- DRM, anti-debugging, DevTools detection, disabling F12 / right-click
- Browser fingerprinting
- Encrypted JavaScript execution / custom JavaScript VM
- License enforcement
- Automatic Cloudflare / S3 / R2 integration (delivery architecture supports them, code does not ship)
- Analytics / dashboard
- Database-backed asset registry
- Automatic Livewire core replacement
- Custom CSS encryption

---

## 7. Functional Requirements

Requirements are numbered and referenced from the TRD (`FR-xx`) and test plan.

### 7.1 Service Provider & Lifecycle

- **FR-01** Package registers `AssetShieldServiceProvider` via Composer auto-discovery.
- **FR-02** Provider merges `config/asset-shield.php`, registers the manager, Blade directives,
  routes, commands, and `asset-shield:install` publishable.
- **FR-03** When `asset-shield.enabled` is `false`, ALL routing and asset delivery is disabled;
  existing `@vite()` behavior is unaffected.

### 7.2 Configuration Surface

- **FR-04** Default config matches the specification exactly (`enabled`, `environment`, `build.*`,
  `mask.*`, `obfuscation.*`, `runtime.*`, `delivery.*`, `cache.*`, `security.*`).
- **FR-05** No secrets are hard-coded; keys come from environment variables.

### 7.3 Manifest & Registry

- **FR-06** `AssetManifest` loads `public/build/manifest.json`, resolves entries, resolves final
  generated files, exposes metadata, caches in production.
- **FR-07** Unresolvable assets throw a useful exception naming the entry and the manifest path.
- **FR-08** `AssetRegistry` maps logical asset → compiled asset → opaque ID
  (`resources/js/app.js` → `assets/app-A91Kx.js` → `as_2ae34e8b0c462491`).
- **FR-09** The registry is persisted (generated/updated by `asset-shield:build` or the Vite
  plugin) and contains no public filesystem paths in what is transmitted to the client.

### 7.4 Protected URLs & Signing

- **FR-10** `AssetShield::url('resources/js/app.js')` returns `/assets/{opaque-id}` — never the real
  build filename.
- **FR-11** Opaque IDs are deterministic per build and derived with a cryptographically secure
  method (HMAC-SHA256 of the manifest-resolved path under an application key), never sequential.
- **FR-12** `AssetSigner` produces HMAC signatures with optional expiration; verification uses
  timing-safe comparison and rejects expired/invalid signatures with HTTP 403.

### 7.5 Delivery

- **FR-13** `GET /assets/{asset}` resolves the ID via the registry only, validates the signature,
  and streams the asset with a correct Content-Type.
- **FR-14** Supported types: JS, CSS, SVG, JSON, fonts, common images (PNG/JPEG/WebP/GIF/ICO).
- **FR-15** Files are never executed server-side.
- **FR-16** Delivery flows through an `AssetDeliveryDriver` (MVP: `PublicDriver`, `StreamDriver`).
- **FR-17** No arbitrary filesystem path is ever accepted from any request input.

### 7.6 Blade & PHP API

- **FR-18** `@assetShield('resources/js/app.js')` strips to nothing/clearly documents degradation
  when disabled; produces the resolved script/link when enabled.
- **FR-19** `@assetShieldCss` / `@assetShieldJs` work as typed helpers.
- **FR-20** `@shieldVite([...entries])` resolves the given entries through AssetShield and remains
  compatible with plain `@vite()` for unregistered entries.
- **FR-21** PHP API `AssetShield::url|script|style` mirrors the Blade output exactly.
- **FR-22** Scripts render `<script src="/assets/{id}?..."></script>`; CSS renders
  `<link rel="stylesheet" href="/assets/{id}?...">`.

### 7.7 Vite Plugin

- **FR-23** The plugin activates only in production builds (`build` mode); the dev server is
  completely untouched.
- **FR-24** It reads generated chunks, writes/updates the AssetShield registry, preserves
  chunking and dynamic imports, and does no custom bundling.
- **FR-25** CSS chunks are never passed to the JS obfuscator.

### 7.8 Obfuscation

- **FR-26** JS obfuscation runs natively inside the Vite plugin via `javascript-obfuscator`
  (optional peer); there is no PHP-side engine. The plugin requires the package in-process (a single
  lookup per build, cached), with a subprocess fallback only when that require fails.
- **FR-27** Presets `light|balanced|aggressive`, default `balanced`; **disabled by default**.
- **FR-28** Default chunk rule `application` (application chunks obfuscated, `node_modules` vendor
  chunks skipped); overridable via `obfuscateChunks: 'application' | 'entries' | 'all'` plus
  include/exclude globs.

### 7.9 Source Maps

- **FR-29** Production default: source maps OFF; protected assets never expose `*.map`.
- **FR-30** Intentional enabling emits a prominent warning in logs and never implies "hidden"
  source maps are secure.

### 7.10 Commands

- **FR-31** `asset-shield:install` publishes config, creates directories/files, shows required Vite
  configuration, and never overwrites user files without confirmation.
- **FR-32** `asset-shield:build` verifies Vite, verifies manifest, generates/updates the registry,
  validates all registered assets, and reports failures clearly (no silent errors).
- **FR-33** `asset-shield:status` prints the status report (enabled, environment, runtime delivery,
  route prefix, manifest, registry, signed URLs, expiration, masking, obfuscation, source maps,
  driver).
- **FR-34** `asset-shield:doctor` inspects `APP_ENV`, `APP_DEBUG`, manifest, source maps,
  `node_modules` accessibility, `.env` location, Debugbar detection, and registry validity, with
  actionable output.

---

## 8. Non-Functional Requirements

### 8.1 Security (NFR-S)

- **NFR-S1** `.env`, PHP source, Composer files, `package.json`, `node_modules`, `routes/`,
  `app/`, `storage/`, and `vendor/` must never be retrievable through AssetShield.
- **NFR-S2** Path traversal, arbitrary file reads, and query-parameter-as-path attacks are
  structurally impossible (registry-only resolution + canonicalization).
- **NFR-S3** Signature comparison is timing-safe (constant-time).
- **NFR-S4** Secrets never appear in JavaScript, HTML, URLs beyond the non-secret opaque ID, logs,
  or errors.
- **NFR-S5** Responses include `X-Content-Type-Options: nosniff` and correct Content-Type.
- **NFR-S6** No `Access-Control-Allow-Origin: *` unless explicitly configured.
- **NFR-S7** Immutable production assets receive `Cache-Control: public, max-age=31536000, immutable`
  by default.

### 8.2 Performance (NFR-P)

- **NFR-P1** No database lookup and no filesystem scanning per asset request.
- **NFR-P2** Manifest and registry are cached; not rebuilt per request.
- **NFR-P3** Immutable IDs and long-lived HTTP caching allow CDN/Nginx offload.
- **NFR-P4** PHP code is OPcache-compatible.
- **NFR-P5** Default path must not route every asset through full Laravel middleware stack when
  Nginx/CDN acceleration is configured.

### 8.3 Compatibility (NFR-C)

- **NFR-C1** Standard Laravel Vite behavior (`@vite()`, manifest layout) must keep working.
- **NFR-C2** PHP 8.3+, Laravel 13-compatible `illuminate/*` packages; full framework not required.
- **NFR-C3** Node ≥ 18, Vite ≥ 5, TypeScript for the plugin; minimal frontend deps.
- **NFR-C4** `.map` files never delivered for protected production assets.

### 8.4 Quality & DX (NFR-Q)

- **NFR-Q1** All PHP classes are small, testable, and follow Laravel conventions.
- **NFR-Q2** Commands give actionable output; build failures are never silently swallowed.
- **NFR-Q3** Install never clobbers user files without confirmation.

---

## 9. Key User Stories

| ID | Story |
|---|---|
| US-01 | As a Laravel dev, I install the package and run `asset-shield:install`, then `{!! AssetShield::script('resources/js/app.js') !!}` serves my JS behind a protected URL. |
| US-02 | As a dev, I sign an asset URL so it expires after 5 minutes; expired links return 403. |
| US-03 | As a platform engineer, I run `asset-shield:doctor` before deploy and get actionable checks (`.env` location, source maps, node_modules exposure). |
| US-04 | As a frontend engineer, I enable obfuscation for application chunks only and confirm vendor bundles stay untouched. |
| US-05 | As a security engineer, I confirm that `/assets?file=../../.env` and `/assets/{id}?expires=X&signature=forged` are both rejected. |
| US-06 | As a DevOps engineer, I verify immutable cache headers allow my CDN to serve assets without hitting PHP. |

---

## 10. Example Configuration (Reference Contract)

```php
// config/asset-shield.php
return [
    'enabled'        => env('ASSET_SHIELD_ENABLED', true),
    'environment'    => env('ASSET_SHIELD_ENV', env('APP_ENV', 'production')),

    'build' => [
        'out_dir'     => env('ASSET_SHIELD_BUILD_OUT_DIR', 'build'),
        'manifest'    => env('ASSET_SHIELD_MANIFEST_PATH', 'public/build/manifest.json'),
        'registry'    => env('ASSET_SHIELD_REGISTRY_PATH', 'app/asset-shield/registry.json'),
        'source_maps' => env('ASSET_SHIELD_SOURCE_MAPS', false),
    ],

    'mask' => [
        'enabled'  => env('ASSET_SHIELD_MASK', false),
        'strategy' => env('ASSET_SHIELD_MASK_STRATEGY', 'preserve'),
        'seed'     => env('ASSET_SHIELD_MASK_SEED', ''),
        'aliases'  => [],
        'include'  => [],
        'exclude'  => [],
        'legend'   => env('ASSET_SHIELD_LEGEND_PATH', 'app/asset-shield/legend.json'),
    ],

    'obfuscation' => [
        'enabled'        => env('ASSET_SHIELD_OBFUSCATION', false),
        'preset'         => env('ASSET_SHIELD_OBFUSCATION_PRESET', 'balanced'),
        'exclude_vendor' => env('ASSET_SHIELD_OBFUSCATION_EXCLUDE_VENDOR', true),
    ],

    'security' => [
        'csp'       => env('ASSET_SHIELD_CSP', false),
        'allowlist' => [
            'script' => [],
            'style'  => [],
            'img'    => [],
        ],
    ],

    'runtime' => [
        'enabled'      => env('ASSET_SHIELD_RUNTIME', false),
        'route_prefix' => env('ASSET_SHIELD_ROUTE_PREFIX', 'assets'),
        'signed_urls'  => env('ASSET_SHIELD_SIGNED_URLS', true),
        'expires'      => env('ASSET_SHIELD_SIGNATURE_EXPIRES', 300),
    ],

    'delivery' => [
        'driver' => env('ASSET_SHIELD_DRIVER', 'public'),
    ],

    'cache' => [
        'enabled' => env('ASSET_SHIELD_CACHE', true),
        'max_age' => env('ASSET_SHIELD_CACHE_MAX_AGE', 31536000),
    ],
];
```

---

## 11. Install / Uninstall Contract

### 11.1 Composer

```
composer require shamimstack/asset-shield
```

- Laravel auto-discovery registers the provider.
- Dev: `composer require --dev shamimstack/asset-shield` picks up testbench/pest (dev-only, package dev).

### 11.2 NPM

```
npm install --save-dev @asset-shield/vite-plugin
```

- Vite peer dependency; `javascript-obfuscator` is an optional peer (only needed when obfuscation is
  enabled).

### 11.3 Uninstall

Removal is a Composer/npm-level operation — there is **no** artisan uninstall command. Artisan
exposes exactly four commands: `asset-shield:install`, `asset-shield:build`, `asset-shield:status`,
`asset-shield:doctor`.

```
composer remove shamimstack/asset-shield     # unregisters the provider (auto-discovery)
npm uninstall --save-dev @asset-shield/vite-plugin
```

Composer and npm only remove the packages themselves. Everything installation published or created
stays on disk and must be cleaned up manually:

1. Delete the published config — `config/asset-shield.php` (or revert it from version control).
2. Remove `assetShieldVite()` from `vite.config.js` / `vite.config.ts`.
3. Revert `@shieldVite([...])` to `@vite([...])` in Blade and delete any `@assetShield`,
   `@assetShieldJs`, `@assetShieldCss` directives.
4. Delete the build artifacts under `storage/app/asset-shield/` (`registry.json`, `legend.json`).
   They are regenerated on the next build and are not needed once the package is gone.

Nothing else is touched: no database tables, no runtime-mutated `public/` files beyond the normal
`build` output.

---

## 12. Success Metrics & Definition of Done

The MVP ships only when (mirrors §31 of the master spec):

- [ ] `composer install` works; package installs cleanly into a fresh Laravel 13 app
- [ ] `npm install` works; Vite build works; normal Laravel Vite continues working
- [ ] AssetShield resolves JS and CSS; protected URLs work
- [ ] Invalid signatures → 403; expired signatures → 403
- [ ] Arbitrary filesystem access is impossible; `.env` cannot be retrieved
- [ ] Source maps not publicly exposed; `node_modules` never required to be public
- [ ] Application JS can optionally be obfuscated; vendor chunks skipped by default
- [ ] Blade helpers + PHP API + `@shieldVite` work
- [ ] `asset-shield:doctor|status|install|build` commands work
- [ ] All PHP and Node/Vitest tests pass
- [ ] README complete and honest; no secrets hard-coded; no security-through-obscurity claims

---

## 13. Honest Security Statement (Must Appear Verbatim)

> **AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering.**

---

## 14. Risk Register

| Risk | Mitigation |
|---|---|
| False sense of security | Mandatory honest-security statements in PRD, README, docs, landing page. |
| Breaking `@vite()` | Separate code paths; `enabled=false` disables only AssetShield; compatibility tests required. |
| Performance regression from PHP delivery | Cached manifest/registry, immutable IDs, delivery driver abstraction for Nginx/CDN offload. |
| Obfuscation breaking app JS | Vendor exclusion by default, obfuscation off by default, plugin tests for dynamic imports/chunking. |
| Secret leakage | Keys via env only; signer/verifier never log or echo secrets; no secrets in generated HTML/JS. |

---

## 15. Roadmap Direction (Post-MVP)

- Delivery drivers: Nginx `X-Accel-Redirect`, Apache `X-Sendfile`, S3, R2, CDN signing.
- Optional database registry; per-path hotlink policies; per-region signed URLs.
- Source map handling policy tooling (never for protected assets).
- Not planned: DRM, VM-based execution encryption, anti-debugging theater.