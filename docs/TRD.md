# AssetShield — Technical Requirements & Design Document

| | |
|---|---|
| **Document** | Technical Requirements & Design Document (TRD) |
| **Version** | 0.2.0 |
| **Status** | MVP design |
| **Product** | AssetShield |
| **Requirements source** | `PRD.md` FR/NFR identifiers |

---

## 1. Purpose

This document specifies the technical architecture of AssetShield MVP. It defines the component
map, dependency graph, opaque-ID and signature schemes, request lifecycle, security boundaries,
performance model, Vite plugin design, and the test plan. It is the single source of truth that
implementers and the generated documentation must agree with.

---

## 2. Architecture Overview

AssetShield is a **two-package system**:

1. **Laravel package** (`src/`) — server-side: manifest, registry, URL generation, signing, delivery
   controller, drivers, Blade machinery, Artisan commands.
2. **Vite plugin** (`packages/vite-plugin/`, TypeScript) — build-side: reads production chunks,
   writes the registry used by the Laravel package, optionally obfuscates application JS.

Both halves communicate through one artifact: the **AssetShield registry** (a JSON file written by
the build side, read+cached by the server side). There is no runtime synchronization, no database,
and no per-request filesystem scanning.

```
                        ┌────────────────────────────────────────────┐
                        │                BUILD SIDE                    │
                        │  Laravel Vite (vite build)                   │
                        │  └─ @asset-shield/vite-plugin                    │
                        │       │ reads chunks + maps                  │
                        │       │ writes registry.json                 │
                        │       │ (optional) obfuscates app JS         │
                        └───────────────────┬──────────────────────────┘
                                            │ artifact
                                            ▼
                        ┌────────────────────────────────────────────┐
                        │               SERVER SIDE                   │
                        │  AssetShieldManager                          │
                        │   ├─ AssetManifest   (cached)                │
                        │   ├─ AssetRegistry   (cached)                │
                        │   ├─ AssetUrlGenerator                        │
                        │   ├─ AssetSigner                             │
                        │   └─ AssetController                          │
                        │       └─ Delivery\AssetDeliveryDriver        │
                        └────────────────────────────────────────────┘
```

---

## 3. Package Structure (Target)

```
asset-shield/
│
├── src/
│   ├── AssetShieldServiceProvider.php
│   ├── AssetShieldManager.php
│   ├── AssetManifest.php
│   ├── AssetRegistry.php
│   ├── AssetResolver.php
│   ├── AssetIdentity.php
│   ├── AssetResponse.php
│   ├── AssetUrlGenerator.php
│   ├── Exceptions/
│   │   ├── AssetNotFoundException.php
│   │   ├── InvalidSignatureException.php
│   │   ├── ManifestNotFoundException.php
│   │   └── RegistryInvalidException.php
│   ├── Facades/
│   │   └── AssetShield.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── AssetController.php
│   │   └── Middleware/
│   │       ├── AssetSecurityHeaders.php
│   │       └── VerifyAssetSignature.php
│   ├── Signer/
│   │   ├── AssetSigner.php                 (interface)
│   │   └── HmacAssetSigner.php
│   ├── Delivery/
│   │   ├── AssetDeliveryDriver.php         (interface)
│   │   ├── PublicDriver.php
│   │   └── StreamDriver.php
│   ├── Masking/
│   │   ├── MaskPlanner.php
│   │   ├── MaskResolver.php                (interface)
│   │   ├── HashResolver.php                (nameless)
│   │   ├── CodenameResolver.php
│   │   ├── PreserveResolver.php
│   │   ├── Dictionary.php
│   │   └── Legend.php
│   ├── Security/
│   │   └── Csp.php
│   ├── Support/
│   │   ├── Fnv1a.php
│   │   ├── MimeMapper.php
│   │   └── OpaqueId.php
│   └── Commands/
│       ├── InstallCommand.php
│       ├── BuildCommand.php
│       ├── StatusCommand.php
│       └── DoctorCommand.php
│
├── config/asset-shield.php
├── routes/web.php
├── packages/vite-plugin/
│   ├── src/index.ts
│   ├── src/registry.ts
│   ├── src/chunkRules.ts
│   ├── src/fnv1a.ts
│   ├── src/glob.ts
│   ├── src/mask/{planner,names,dictionary}.ts
│   ├── src/obfuscation/{engine,presets}.ts
│   ├── package.json
│   ├── tsconfig.json
│   └── tests/*.test.ts
├── tests/
│   ├── TestCase.php
│   ├── Unit/
│   └── Feature/
├── composer.json
├── package.json
├── README.md
├── LICENSE
└── CHANGELOG.md
```

---

## 4. Dependency Graph (Application)

Pure dependencies (no cycles). Arrows mean "depends on / uses".

```
 AssetShieldManager
      │
      ├───────────────► AssetManifest ──────────────► public/build/manifest.json (cached)
      │
      ├───────────────► AssetRegistry ──────────────► storage/app/asset-shield/registry.json (cached)
      │
      ├───────────────► AssetUrlGenerator ──────────► Signer\HmacAssetSigner
      │                    │                          Support\OpaqueId
      │                    └► AssetRegistry (map lookup)
      │
      ├───────────────► Signer\AssetSigner (interface)
      │                    ▲
      │                    │
      │                    └─ Signer\HmacAssetSigner ─► config::runtime.expires + app.key
      │
      ├───────────────► Http\Controllers\AssetController ─► AssetRegistry
      │                    │                                  Signer\AssetSigner
      │                    │                                  Delivery\AssetDeliveryDriver
      │                    │                                  Support\MimeMapper
      │                    └► Http\Middleware\AssetSecurityHeaders
      │
      ├───────────────► Delivery\AssetDeliveryDriver (interface)
      │                    ▲
      │                    ├── Delivery\PublicDriver
      │                    └── Delivery\StreamDriver
      │
      ├───────────────► Facades\AssetShield ─────────► App alias → AssetShieldManager
      │
      └───────────────► Blade directives / @shieldVite ─► AssetShieldManager::script|style|url
```

### Classes & Responsibilities

| Class | Responsibility | Key methods |
|---|---|---|
| `AssetShieldServiceProvider` | merge config, bind manager/signer/driver, Blade directives, routes, commands, publishables, (de)activation | `register()`, `boot()` |
| `AssetShieldManager` | public facade surface; composition root on top of services | `resolve()`, `url()`, `script()`, `style()`, `sign()`, `isEnabled()`, `status()` |
| `AssetManifest` | load + cache `manifest.json`; resolve entries → final files; metadata | `resolve()`, `compiledPath()`, `absolutePath()`, `exists()` |
| `AssetRegistry` | logical → compiled → opaque ID map; persistence; lookup; validation | `create()`, `entryForLogical()`, `compiledForOpaque()`, `fromConfig()`, `fileExists()`, `path()`, `validate()` |
| `AssetUrlGenerator` | produce `/assets/{opaqueId}` and optional signed variant | `url()`, `urlForOpaque()` |
| `AssetSigner` (interface) | contract for sign/verify | `sign(assetId, ?expires)`, `verify(assetId, ?expires, signature)` |
| `HmacAssetSigner` | HMAC-SHA256 under app key; constant-time verify; expiry check | — |
| `AssetController` | request → resolve → verify → deliver; 403 on failure; never accepts paths | `__invoke(Request, string $asset)` |
| `AssetResponse` | response builder for JS/CSS/SVG/JSON/fonts/images w/ cache + security headers | `make()` |
| `AssetDeliveryDriver` | abstraction over file transportation | `deliver()`, `supports()` |
| `PublicDriver` | serve via `public/build` resolved file path (safest for PHP middlewares whitelists) | — |
| `StreamDriver` | stream via `readfile`-based passthrough with MIME | — |
| `MimeMapper` | extension → Content-Type table (JS/CSS/SVG/JSON/woff2/ttf/png/jpeg/webp/gif/ico) + family + denylist | `forPath()`, `family()`, `isForbidden()` |
| `OpaqueId` | deterministic HMAC-derived ID from compiled path; path sanitization | `from()`, `isSafe()`, `canonicalize()` |
| Commands | install/build/status/doctor | handle() |

---

## 5. Opaque Identifier Scheme

### 5.1 Requirements (FR-10, FR-11, NFR-S1)

- Deterministic per build, so registries and URLs are stable between deploys with identical content.
- Derived cryptographically; no sequential integers.
- Contains **no** information about the real filesystem path.

### 5.2 Derivation

```
opaqueId = substr( hex( HMAC-SHA256( app_key, "asset-shield:" . canonicalCompiledRelativePath ) ), 0, 16 )
```

- `app_key` is the Laravel application key (`config('app.key')`), retrieved from `APP_KEY`.
- The canonical compiled relative path is e.g. `build/assets/app-A91Kx.js` (manifest-relative),
  normalized (forward slashes, no `..`, no leading slash) before hashing.
- Output is 8 bytes hex (16 chars) — collision-resistant for realistic asset counts, deterministic,
  key-bound. Registry generation still validates uniqueness and re-rolls on collision (practically
  impossible; guarded defensively).

### 5.3 Registry Payload

```jsonc
// storage/app/asset-shield/registry.json  (written by vite plugin or asset-shield:build)
{
  "version": 1,
  "built_at": "2026-09-13T12:00:00Z",
  "assets": {
    "resources/js/app.js": {
      "file": "build/assets/app-A91Kx.js",   // server-side only; never in HTML/JS
      "type": "script",                      // script | style | image | font
      "original": "build/assets/app-A91Kx.js", // pre-mask path; present when the legend had one
      "integrity": "sha384-..."
    }
  }
}
```

The on-disk registry has **no** `opaque` field: the server re-derives it from the compiled path +
app key on load, so the Vite plugin never needs the key.

Client-facing payloads expose only `opaque`, and only when needed.

---

## 6. Signed URL Scheme

### 6.1 Algorithm

```
signature = hex( HMAC-SHA256( app_key,
                 "asset-shield-sign:" . ":" . opaqueId . ":" . (expires ?? 0) ) )
```

(The `asset-shield-sign:` prefix already ends in a colon, producing the double colon before the
opaque ID.)

Constructed URL:

```
/assets/{opaqueId}?expires=1790000000&signature=<hex>
```

- `expires` is a Unix timestamp. Omitted → non-expiring (still signed/verifiable).
- `verify()` performs the comparison in constant time via `hash_equals()`.
- `verify()` returns `false` for: malformed signature, expired timestamps (`now > expires`),
  signature mismatch. The controller translates `false` → **403 Forbidden**.

### 6.2 Interface Contract

```php
interface AssetSigner
{
    public function sign(string $assetId, int|\DateTimeInterface|null $expires = null): string;
    public function verify(string $assetId, ?int $expires, string $signature): bool;
}
```

### 6.3 Security Properties

- Timing-safe via `hash_equals` (NFR-S3).
- Secrets never logged, emitted, or embedded in client artifacts (NFR-S4).
- Expiration validation rejects past timestamps regardless of signature validity (pre-empts replay).
- Default expiration from `config('asset-shield.runtime.expires', 300)`.

---

## 7. Request Lifecycle — `GET /assets/{asset}`

```
 Browser ── GET /assets/as_2ae34e8b0c462491[?expires&signature]
   │
   ▼
 Route: web.php  →  middleware [AssetSecurityHeaders, VerifyAssetSignature]
   │              prefix(config route_prefix) assets, where {asset} = [a-z0-9_]{2,64}
   ▼
 VerifyAssetSignature middleware
   │  1. signing enforced (runtime.signed_urls=true) → signature+expires REQUIRED;
   │     missing/invalid/expired → 403 (generic; never reveals existence)
   │  2. signing disabled → skip (still structural: registry-only resolution below)
   ▼
 AssetController::__invoke
   │  1. id = route param {asset}            (never a filesystem path — regex: [a-z0-9_]{2,64})
   │  2. resolve id → registry entry → compiled path (throws 404 if unknown)
   │  3. content type from MimeMapper; unknown/forbidden (e.g. .map/.php/.env) → 403/404
   │  4. validate file exists (stream/public driver)
   │  5. AssetResponse → 200, correct MIME (MimeMapper), cache/security headers
   │  6. driver delivers bytes (never executed server-side)
   ▼
 Browser receives asset bytes
```

Guard rails that make traversal structurally impossible:

- Route param constrained to opaque `as_...` IDs; registry maps ID → relative compiled path.
- The relative compiled path is appended against the **config-resolved public build directory**
  and canonicalized (`realpath`) inside that root; any escape → 404.
- No user-supplied query value is ever interpreted as a file path.
- `.map`, `.env`, source files (`.php`, `.cs`), and lockfiles are excluded by extension in the MIME/delivery whitelist.

---

## 8. Security Boundaries

| Boundary | Rule |
|---|---|
| Input | Only the opaque ID + optional `expires`/`signature`. Everything else ignored. |
| Registry | Exclusive channel between ID and compiled path. No `..`, no absolute paths accepted when loading registry. |
| Filesystem | Compiled paths resolved relative to a locked `public/build` root; canonicalized; escape → 404. |
| Delivery | Safer default `PublicDriver`; `StreamDriver` for flexibility; both never execute files. |
| Headers | `X-Content-Type-Options: nosniff`; correct Content-Type; never `*,` wildcard CORS unless explicitly enabled. |
| Cache | Immutable: `public, max-age=31536000, immutable` for unsigned immutable assets; configurable for signed/expiring. |
| Secrets | Only in server config/env. Never in HTML, JS, regressions, logs. |
| Source maps | Not produced by default; `.map` requests rejected for protected assets; enabling logs a prominent warning. |

**Honesty clause (repeated everywhere):** browser-delivered code can always be inspected.
AssetShield reduces exposure, gates access, and raises reverse-engineering cost — nothing more.

---

## 9. Performance Model (NFR-P)

- `manifest.json` and `registry.json` loaded once per process worker and cached (Laravel cache when
  `asset-shield.environment=production`, static memoization otherwise); cache entries are keyed by
  artifact path and mtime-validated so a rebuilt artifact never serves a stale decoded payload.
- Immutable opaque IDs are FFI-free string lookups against the cached in-memory map.
- No database, no `Storage::files`, no `scandir` per request.
- Long-lived HTTP caching (31536000s immutable) shifts load to browser/CDN/Nginx.
- Delivery driver interface allows future `X-Accel-Redirect`/`X-Sendfile`/S3/R2/CDN without
  changing the controller.
- OPcache-compatible (no runtime eval, no dynamic class generation, no `source mapping` trickery).

---

## 10. Vite Plugin Design (`packages/vite-plugin`)

### 10.1 Runtime contract

- `plugin.activateOnBuild()` internal guard: only runs when `config.command === 'build'`.
- `apply: 'build'` restricts the plugin to build; `configureServer()` no-ops → dev server unaffected
  (FR-23).
- Uses Rollup hooks: `renderChunk` (obfuscation, when enabled) and `closeBundle`
  (registry write). No custom bundling.
- CSS chunks skipped for obfuscation (FR-25). Dynamic import splits preserved (Rollup output shape
  untouched).

### 10.2 Plugin options

```ts
export interface AssetShieldViteOptions {
  enabled?: boolean;                    // default true; activates only on vite build
  registryFile?: string;                // default 'storage/app/asset-shield/registry.json'
  legendFile?: string;                  // default 'storage/app/asset-shield/legend.json'
  buildDir?: string;                    // default 'build'
  sourceMaps?: boolean;                 // default false; warning if true
  logLevel?: 'info' | 'warn' | 'error' | 'silent';
  failOnError?: boolean;                // throw instead of warning on write errors
  mask?: {
    enabled?: boolean;                  // default false
    strategy?: 'preserve' | 'nameless' | 'codename';
    seed?: string;                      // MUST match PHP asset-shield.mask.seed
    aliases?: Record<string, string>;
    include?: string[];
    exclude?: string[];
  };
  obfuscation?: {
    enabled?: boolean;                  // default false
    preset?: 'light' | 'balanced' | 'aggressive'; // default 'balanced'
    options?: Record<string, unknown>;  // deep-merged over the preset
    obfuscateChunks?: 'application' | 'entries' | 'all'; // default 'application'
    include?: string[];                 // globs (applied on chunk names)
    exclude?: string[];                 // globs (take precedence)
  };
}
```

There is **no `engine` string option** — `javascript-obfuscator` is the native engine, imported
only when obfuscation is enabled.

### 10.3 Chunk classification

- Default `'application'`: module ids containing `node_modules` → vendor (skip); all else obfuscated.
- `'entries'`: only entry chunks (`isEntry`) obfuscated.
- `'all'`: everything (with include/exclude glob overrides).
- `exclude` wins over `include`; both are matched against chunk names.

### 10.4 Obfuscation engine (native to the plugin)

The plugin's `obfuscation/engine.ts` drives the `javascript-obfuscator` package (itself an optional
peer dependency) using preset option maps from `obfuscation/presets.ts`. Obfuscation is entirely
**build-side**: there is no PHP engine, and no `obfuscation.engine` / `node_binary` / `package_path` /
`timeout` configuration anywhere in the Laravel package — the PHP config only mirrors `enabled` /
`preset` / `exclude_vendor` for status and doctor output.

Loading strategy (`ObfuscationEngine`): the package is required **in-process** (once per build process,
module-lookup cached) and transforms every chunk directly. A **subprocess fallback** (temp files only,
removed in a `finally`) is used solely when the in-process require fails — e.g. exotic package-manager
layouts that break `createRequire` resolution. Consumers only ever see the `ObfuscationResult` contract,
so the loader is swappable without touching the plugin's call sites.

---

## 11. Delivery Driver Contract

```php
use Shamimstack\AssetShield\AssetIdentity;
use Symfony\Component\HttpFoundation\Response;

interface AssetDeliveryDriver
{
    /**
     * Deliver the bytes of a validated, registered asset.
     * Implementations MUST NOT execute the file and MUST NOT accept arbitrary
     * input paths — they only ever receive a registry-validated AssetIdentity.
     *
     * @param  int|null  $cacheOverrideSeconds  remaining signature lifetime.
     * @param  bool      $immutable             whether this is unsigned/immutable.
     */
    public function deliver(
        AssetIdentity $asset,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response;

    public function supports(AssetIdentity $asset): bool;
}
```

| Driver | Behavior | When to use |
|---|---|---|
| `PublicDriver` | Reads the resolved file within `public/build`, streams contents (default) | Simplest; PHP-whitelist friendly |
| `StreamDriver` | `BinaryFileResponse` streaming with range/conditional support | Larger media or future CDN origination |
| *(future)* `NginxXAccelDriver` | `X-Accel-Redirect` header; PHP never sends bytes | Defined, not implemented (MVP non-goal) |
| *(future)* `S3Driver` / `R2Driver` / `CdnDriver` | Presigned/CDN redirect | Defined, not implemented (MVP non-goal) |

---

## 12. Medium-Term Data & Cache Keys

| Data | Cache key | Lifetime | Invalidation |
|---|---|---|---|
| Manifest | `asset-shield:manifest` | `config('asset-shield.cache.max_age', 31536000)` | deploy-time build command clears |
| Registry | `asset-shield:registry` | same | `asset-shield:build` regenerates |
| Opaque map (client) | none (derived in HTML per request, cheap) | — | — |

---

## 13. Test Plan

### 13.1 PHP (Pest/PHPUnit + Orchestra Testbench)

| # | Test | FR/NFR |
|---|---|---|
| 1 | manifest loading (found, missing, malformed) | FR-06/07 |
| 2 | asset resolution via manifest | FR-06 |
| 3 | opaque ID generation deterministic + crypto-shaped | FR-11 |
| 4 | valid signed URL accepted | FR-12 |
| 5 | invalid signature → 403 | FR-12 |
| 6 | expired URL → 403 | FR-12 |
| 7 | unknown asset → 404 | FR-07 |
| 8 | path traversal attempt rejected | NFR-S2 |
| 9 | `.env` access attempt → 404/403 | NFR-S1 |
| 10 | vendor access attempt → 404 | NFR-S1 |
| 11 | correct MIME type per extension | FR-14 |
| 12 | cache headers immutable / configured | NFR-S7 |
| 13 | disabled mode → routes inactive, `@vite` intact | FR-03, NFR-C1 |
| 14 | Blade directives render script/link tags | FR-18–22 |
| 15 | `asset-shield:doctor` exit codes + output | FR-34 |

### 13.2 Node (Vitest)

| # | Test | FR |
|---|---|---|
| 1 | plugin activates only on production build | FR-23 |
| 2 | dev server unaffected (configureServer no-op) | FR-23 |
| 3 | JS chunk obfuscation applied when enabled | FR-24/26 |
| 4 | vendor (`node_modules`) chunk excluded by default | FR-28 |
| 5 | include/exclude glob rules honored | FR-28 |
| 6 | source maps disabled default; warning on enable | FR-29/30 |
| 7 | empty chunks handled without error | — |
| 8 | dynamic imports preserved after obfuscation (registry keeps every chunk) | FR-24 |
| 9 | missing manifest → warning, build continues; filesystem write errors surface loudly | — |

---

## 14. Version Matrix

| Component | Version |
|---|---|
| PHP | ^8.3 |
| `illuminate/collections`, `illuminate/console`, `illuminate/contracts`, `illuminate/filesystem`, `illuminate/http`, `illuminate/routing`, `illuminate/support`, `illuminate/view` (Laravel 13–compatible) | ^13.0 |
| Testbench (dev) | ^11 (Laravel 13–compatible) |
| Pest (dev) | ^3 |
| Node | >= 18 |
| Vite (peer) | >= 5 |
| TypeScript | ^5 |
| `javascript-obfuscator` (optional peer) | >= 5 |
| `tsup`, `vitest` (dev) | latest-stable |

Composer package: **no** full-framework requirement; only `illuminate/*` pieces needed at runtime.

---

## 15. Key Decisions & Trade-offs

| Decision | Rationale |
|---|---|
| Registry as build artifact (not DB) | NFR-P1 (no DB per request); deterministic per build; Vite plugin writes it. |
| HMAC-derived opaque IDs under app key | Deterministic + key-bound; no storage lookup needed for URL emission; avoids sequential IDs. |
| Obfuscation disabled by default | Safety first; opt-in matches FR-27 and avoids unrequested build-time risk. |
| Vendor chunks skipped by default | Prevents breaking third-party code; matches FR-28. |
| Delivery driver indirection in MVP | Enables Nginx/S3/R2/CDN offload later without controller rework (non-goals reserved cleanly). |
| `.map` never served for protected assets | FR-29; honest logging when intentionally enabled. |
| No custom bundling/obfuscator | Reuse Rollup + `javascript-obfuscator`; nothing reinvented (per master spec). |

---

## 16. Resolved Implementation Notes

- Registry persistence path — `storage/app/asset-shield/registry.json` (default), config override supported,
  kept outside `public/` (server-loads via `AssetRegistry::fromConfig`).
- Default driver — `PublicDriver` (whitelist-friendly production); configurable via
  `asset-shield.delivery.driver`; `StreamDriver` for readfile-based passthrough.
- Signature default — all `/assets` URLs are signed when `runtime.signed_urls=true`; requesting
  `signed: false` per call does **not** opt out once signing is enforced — the global
  `runtime.signed_urls=false` is the escape hatch (config wins).