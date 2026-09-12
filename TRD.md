# AssetShield — Technical Requirements & Design Document

| | |
|---|---|
| **Document** | Technical Requirements & Design Document (TRD) |
| **Version** | 0.1.0 |
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
                        │  └─ @vendor/asset-shield/vite-plugin         │
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
│   ├── AssetUrlGenerator.php
│   ├── AssetResponse.php
│   ├── Exceptions/
│   │   ├── AssetNotFoundException.php
│   │   ├── InvalidSignatureException.php
│   │   └── ManifestNotFoundException.php
│   ├── Facades/
│   │   └── AssetShield.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── AssetController.php
│   │   ├── Middleware/
│   │   │   └── AssetSecurityHeaders.php
│   │   └── KernelRouteResolver.php          (internal route registration)
│   ├── Signer/
│   │   ├── AssetSigner.php                 (interface)
│   │   └── HmacAssetSigner.php
│   ├── Delivery/
│   │   ├── AssetDeliveryDriver.php         (interface)
│   │   ├── PublicFileDriver.php
│   │   ├── StreamDriver.php
│   │   └── Contracts/AugmentedResponse.php
│   ├── Support/
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
├── resources/views/                      (optional blade partials)
├── packages/vite-plugin/
│   ├── src/index.ts
│   ├── src/obfuscation/engine.ts
│   ├── src/obfuscation/javascript-obfuscator.ts
│   ├── src/registry.ts
│   ├── src/chunkRules.ts
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
      ├───────────────► AssetRegistry ──────────────► storage/asset-shield/registry.json (cached)
      │
      ├───────────────► AssetUrlGenerator ──────────► Signer\HmacAssetSigner
      │                    │                          Support\OpaqueId
      │                    └► AssetRegistry (map lookup)
      │
      ├───────────────► Signer\AssetSigner (interface)
      │                    ▲
      │                    │
      │                    └─ Signer\HmacAssetSigner ─► config::signature
      │
      ├───────────────► Http\Controllers\AssetController ─► AssetRegistry
      │                    │                                  Signer\AssetSigner
      │                    │                                  Delivery\AssetDeliveryDriver
      │                    │                                  Support\MimeMapper
      │                    └► Http\Middleware\AssetSecurityHeaders
      │
      ├───────────────► Delivery\AssetDeliveryDriver (interface)
      │                    ▲
      │                    ├── Delivery\PublicFileDriver
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
| `AssetManifest` | load + cache `manifest.json`; resolve entries → final files; metadata | `file(string $path)`, `resolve(string $entry)`, `cached()`, `lastModified()` |
| `AssetRegistry` | logical → compiled → opaque ID map; persistence; lookup; validation | `add()`, `opaqueIdFor()`, `compiledPathFor()`, `idToCompiled()`, `save()`, `load()`, `isValid()` |
| `AssetUrlGenerator` | produce `/assets/{opaqueId}` and optional signed variant | `generate(Iterableable)`, `generateSigned()`, `urlForRegistryId()` |
| `AssetSigner` (interface) | contract for sign/verify | `sign(assetId, ?expires)`, `verify(assetId, ?expires, signature)` |
| `HmacAssetSigner` | HMAC-SHA256 under app key; constant-time verify; expiry check | — |
| `AssetController` | request → resolve → verify → deliver; 403 on failure; never accepts paths | `__invoke(Request, string $asset)` |
| `AssetResponse` | response builder for JS/CSS/SVG/JSON/fonts/images w/ cache + security headers | `make()` |
| `AssetDeliveryDriver` | abstraction over file transportation | `deliver()`, `supports()` |
| `PublicFileDriver` | serve via `public/build` resolved file path (safest for PHP middlewares whitelists) | — |
| `StreamDriver` | stream via ReadfileStream / passthrough with MIME | — |
| `MimeMapper` | extension → Content-Type table (JS/CSS/SVG/JSON/woff2/ttf/png/jpeg/webp/gif/ico) | `forPath()` |
| `OpaqueId` | deterministic HMAC-derived ID from compiled path | `from(FilesystemPath)` |
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
// storage/asset-shield/registry.json  (written by vite plugin or asset-shield:build)
{
  "version": 1,
  "built_at": "2026-09-13T12:00:00Z",
  "entries": [
    {
      "logical": "resources/js/app.js",
      "compiled": "build/assets/app-A91Kx.js",   // server-side only; never in HTML/JS
      "opaque": "7f92a8c1",
      "type": "js",
      "integrity": "sha384-..."
    }
  ]
}
```

Client-facing payloads expose only `opaque`, and only when needed.

---

## 6. Signed URL Scheme

### 6.1 Algorithm

```
signature = hex( HMAC-SHA256( app_key,
                 "asset-shield:" . opaqueId . ":" . (expires ?? 0) ) )
```

Constructed URL:

```
/assets/{opaqueId}?expires=1790000000&signature=<hex>
```

- `expires` is a Unix timestamp. Omitted → non-expiring (still signed/verifiable).
- `sign()` performs constant-time comparison via `hash_equals()`.
- `verify()` returns `false` for: malformed signature, expired timestamps (`now > expires`),
  signature mismatch. The controller translates `false` → **403 Forbidden**.

### 6.2 Interface Contract

```php
interface AssetSigner
{
    public function sign(string $assetId, ?int $expires = null): string;
    public function verify(string $assetId, ?int $expires, string $signature): bool;
}
```

### 6.3 Security Properties

- Timing-safe via `hash_equals` (NFR-S3).
- Secrets never logged, emitted, or embedded in client artifacts (NFR-S4).
- Expiration validation rejects past timestamps regardless of signature validity (pre-empts replay).
- Default expiration from `config('asset-shield.signature.expires', 300)`.

---

## 7. Request Lifecycle — `GET /assets/{asset}`

```
 Browser ── GET /assets/7f92a8c1[?expires&signature]
   │
   ▼
 Route: web.php  →  group('assets-shield', prefix /{route_prefix}) prefix/assets/{asset}
   │
   ▼
 AssetSecurityHeaders middleware (adds nosniff + headers; cache controls deferred to AssetResponse)
   │
   ▼
 AssetController::__invoke
   │  1. id = route param {asset}            (never a filesystem path — regex: [a-f0-9]{8,32})
   │  2. if signature.enabled and query present → AssetSigner::verify(id, expires, signature)
   │        false → 403 InvalidSignatureException
   │  3. resolve id → compiled path → AssetRegistry (registry-only; throws 404 if unknown)
   │  4. validate file exists (stream/public driver)
   │  5. AssetResponse::make() → 200, correct MIME (MimeMapper), cache/security headers
   │  6. driver delivers bytes (never executed server-side)
   ▼
 Browser receives asset bytes
```

Guard rails that make traversal structurally impossible:

- Route param constrained to hex opaque IDs; registry maps ID → relative compiled path.
- The relative compiled path is appended against the **config-resolved public build directory**
  and canonicalized (`realpath`) inside that root; any escape → 404.
- No user-supplied query value is ever interpreted as a file path.
- `.map`, `.env`, PHP files are excluded by extension in the MIME/delivery whitelist.

---

## 8. Security Boundaries

| Boundary | Rule |
|---|---|
| Input | Only the opaque ID + optional `expires`/`signature`. Everything else ignored. |
| Registry | Exclusive channel between ID and compiled path. No `..`, no absolute paths accepted when loading registry. |
| Filesystem | Compiled paths resolved relative to a locked `public/build` root; canonicalized; escape → 404. |
| Delivery | Safer default `PublicFileDriver`; `StreamDriver` for flexibility; both never execute files. |
| Headers | `X-Content-Type-Options: nosniff`; correct Content-Type; never `*,` wildcard CORS unless explicitly enabled. |
| Cache | Immutable: `public, max-age=31536000, immutable` for unsigned immutable assets; configurable for signed/expiring. |
| Secrets | Only in server config/env. Never in HTML, JS, regressions, logs. |
| Source maps | Not produced by default; `.map` requests rejected for protected assets; enabling logs a prominent warning. |

**Honesty clause (repeated everywhere):** browser-delivered code can always be inspected.
AssetShield reduces exposure, gates access, and raises reverse-engineering cost — nothing more.

---

## 9. Performance Model (NFR-P)

- `manifest.json` and `registry.json` loaded once per process worker and cached (Laravel cache in
  production, static memoization otherwise).
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
- Uses Rollup hooks: `renderChunk` (obfuscation, when enabled) and `generateBundle`
  (registry write). No custom bundling.
- CSS chunks skipped for obfuscation (FR-25). Dynamic import splits preserved (Rollup output shape
  untouched).

### 10.2 Plugin options

```ts
export interface AssetShieldViteOptions {
  enabled?: boolean;                    // default true; production-only anyway
  registryFile?: string;                // default 'storage/asset-shield/registry.json'
  obfuscation?: {
    enabled?: boolean;                  // default false
    preset?: 'light' | 'balanced' | 'aggressive'; // default 'balanced'
    engine?: 'javascript-obfuscator';   // default
    obfuscateChunks?: 'application' | 'entries' | 'all'; // default 'application'
    include?: string[];                 // globs (applied on chunk names)
    exclude?: string[];                 // globs (take precedence)
  };
  sourceMaps?: boolean;                 // default false; warning if true
}
```

### 10.3 Chunk classification

- Default `'application'`: module ids containing `node_modules` → vendor (skip); all else obfuscated.
- `'entries'`: only entry chunks (`isEntry`) obfuscated.
- `'all'`: everything (with include/exclude glob overrides).
- `exclude` wins over `include`; both are matched against chunk names.

### 10.4 Obfuscation adapter

```ts
export interface ObfuscatorResult { code: string; sourceMap?: object | null; }

export interface ObfuscationEngine {
  obfuscate(code: string, options: object): Promise<ObfuscatorResult>;
}
```

`JavascriptObfuscatorEngine` wraps `javascript-obfuscator` with preset option maps.

---

## 11. Delivery Driver Contract

```php
interface AssetDeliveryDriver
{
    /**
     * Deliver resolved asset bytes for a validated, registered relative path.
     * Implementations MUST NOT execute the file or accept arbitrary input paths.
     */
    public function deliver(AssetManifest $manifest, string $relativePath, AssetResponse $response): Response;
    public function supports(AssetManifest $manifest, string $relativePath): bool;
}
```

| Driver | Behavior | When to use |
|---|---|---|
| `PublicFileDriver` | Reads the resolved file within `public/build`, streams with headers | Default; simplest, PHP whitelist friendly |
| `StreamDriver` | `fpassthru`-style streaming with lazy open, supports range/conditional | Larger media or future CDN origination |
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
| 8 | dynamic imports preserved after obfuscation | FR-24 |
| 9 | build failures surface loudly | — |

---

## 14. Version Matrix

| Component | Version |
|---|---|
| PHP | ^8.3 |
| `illuminate/contracts`, `illuminate/support`, `illuminate/http`, `illuminate/routing`, `illuminate/console` (Laravel 13–compatible) | ^13.0 |
| Testbench (dev) | ^10 (Laravel 13–compatible) |
| Pest (dev) | ^3 |
| Node | >= 18 |
| Vite (peer) | >= 5 |
| TypeScript | ^5 |
| `javascript-obfuscator` (optional peer) | ^4 |
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

## 16. Open Questions (Implementation Notes)

- Exact persistence path for the registry — `storage/asset-shield/registry.json` (default) with
  config override; must be outside `public/`.
- Whether `PublicFileDriver` or `StreamDriver` is default — recommend `PublicFileDriver` for
  whitelist-friendly production; configurable via `asset-shield.driver`.
- Signature default: sign all `/assets` URLs when `signature.enabled=true` (recommended) versus
  allowing inline `unsigned` assets — documentation covers both; default signs.