# Configuration Reference

The package is configured entirely through `config/asset-shield.php`. Every sensitive value comes
from environment variables via `env()`; nothing is hard-coded. Publish the file with
`php artisan asset-shield:install` or `vendor:publish`.

## Complete reference

```php
<?php

return [
    /*
    | Master switch. When false, URL generation, tags, routes and runtime delivery
    | are all disabled. Plain Laravel Vite (`@vite()`) is completely unaffected,
    | so this is a one-line rollback.
    */
    'enabled' => env('ASSET_SHIELD_ENABLED', true),

    /*
    | What environment this build targets (defaults to APP_ENV). Warnings
    | (obfuscation/source maps) default to their safest production behaviour.
    */
    'environment' => env('ASSET_SHIELD_ENV', env('APP_ENV', 'production')),

    /*
    | Build artifacts.
    |   out_dir     public-relative Vite output dir, e.g. "build".
    |   manifest    Vite manifest location (base-relative).
    |   registry    AssetShield registry (must stay outside public/).
    |   source_maps hostable source maps are never "hidden"; default false.
    */
    'build' => [
        'out_dir'      => env('ASSET_SHIELD_BUILD_OUT_DIR', 'build'),
        'manifest'     => env('ASSET_SHIELD_MANIFEST_PATH', 'public/build/manifest.json'),
        'registry'     => env('ASSET_SHIELD_REGISTRY_PATH', 'app/asset-shield/registry.json'),
        'source_maps'  => env('ASSET_SHIELD_SOURCE_MAPS', false),
    ],

    /*
    | Masking: deterministic build-time filename renaming applied by the
    | Vite plugin through output-naming hooks. Presentational, NOT encryption.
    |   seed        stable seed; must match the plugin's mask.seed or
    |               asset-shield:build / :doctor fail fast on mismatch. Empty
    |               falls back to "asset-shield" on both sides.
    |   aliases     logical -> filename overrides (with collision checks).
    |   include     globs; an empty/include-missing list masks everything.
    |   exclude     files kept at their Vite names.
    |   legend      artifact path (base-relative), e.g. storage/app/... . It is
    |               resolved via storagePath() and must stay outside public/.
    */
    'mask' => [
        'enabled'    => env('ASSET_SHIELD_MASK', false),
        'strategy'   => env('ASSET_SHIELD_MASK_STRATEGY', 'preserve'),
        'seed'       => env('ASSET_SHIELD_MASK_SEED', ''),
        'aliases'    => [],
        'include'    => [],
        'exclude'    => [],
        'legend'     => env('ASSET_SHIELD_LEGEND_PATH', 'app/asset-shield/legend.json'),
    ],

    /*
    | Obfuscation (mirrored from the plugin; informational on the PHP side).
    | OPT-OUT-SAFE: disabled by default. Obfuscation is NOT encryption. The
    | engine itself is native to @asset-shield/vite-plugin — no PHP-side
    | engine is exposed.
    |   exclude_vendor  node_modules chunks skipped on the plugin side.
    */
    'obfuscation' => [
        'enabled'         => env('ASSET_SHIELD_OBFUSCATION', false),
        'preset'          => env('ASSET_SHIELD_OBFUSCATION_PRESET', 'balanced'),
        'exclude_vendor'  => env('ASSET_SHIELD_OBFUSCATION_EXCLUDE_VENDOR', true),
    ],

    /*
    | Optional runtime delivery through protected routes (opt-in).
    |   route_prefix  URL prefix for protected assets, e.g. /assets/as_...
    |   signed_urls   HMAC-sign every emitted protected URL and verify it.
    |   expires       default signed URL lifetime, seconds.
    */
    'runtime' => [
        'enabled'       => env('ASSET_SHIELD_RUNTIME', false),
        'route_prefix'  => env('ASSET_SHIELD_ROUTE_PREFIX', 'assets'),
        'signed_urls'   => env('ASSET_SHIELD_SIGNED_URLS', true),
        'expires'       => env('ASSET_SHIELD_SIGNATURE_EXPIRES', 300),
    ],

    /*
    | Delivery driver: 'stream' (Range-friendly BinaryFileResponse) or
    | 'public' (default — web server serves the file; PHP stays out of the way).
    */
    'delivery' => [
        'driver' => env('ASSET_SHIELD_DRIVER', 'public'),
    ],

    /*
    | HTTP caching for immutable production assets. In production the same
    | switch warms the decoded manifest/registry payloads through the Laravel
    | cache. For signed assets the max-age is clamped to the signature lifetime.
    */
    'cache' => [
        'enabled'  => env('ASSET_SHIELD_CACHE', true),
        'max_age'  => env('ASSET_SHIELD_CACHE_MAX_AGE', 31536000),
    ],

    /*
    | Security headers / CSP.
    |   csp          strict Content-Security-Policy on protected asset responses
    |                (default off).
    |   allowlist    page-level source allowlists used by AssetShield::cspHeader();
    |                keys script/style/img, each a list of extra hosts.
    */
    'security' => [
        'csp' => env('ASSET_SHIELD_CSP', false),
        'allowlist' => [
            'script' => [],
            'style'  => [],
            'img'    => [],
        ],
    ],
];
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `ASSET_SHIELD_ENABLED` | `true` | Master switch (one-line rollback) |
| `ASSET_SHIELD_ENV` | `APP_ENV` | Build target environment |
| `ASSET_SHIELD_BUILD_OUT_DIR` | `build` | Public-relative Vite output dir |
| `ASSET_SHIELD_MANIFEST_PATH` | `public/build/manifest.json` | Vite manifest |
| `ASSET_SHIELD_REGISTRY_PATH` | `app/asset-shield/registry.json` | Registry (server-owned; resolved under storage) |
| `ASSET_SHIELD_SOURCE_MAPS` | `false` | Whether source maps are built (never hidden) |
| `ASSET_SHIELD_MASK` | `false` | Enable masking |
| `ASSET_SHIELD_MASK_STRATEGY` | `preserve` | `preserve` \| `nameless` \| `codename` |
| `ASSET_SHIELD_MASK_SEED` | `''` | Name seed — must match the plugin |
| `ASSET_SHIELD_LEGEND_PATH` | `app/asset-shield/legend.json` | Secret masking legend (storagePath-relative) |
| `ASSET_SHIELD_OBFUSCATION` | `false` | Enable obfuscation (mirrored in plugin) |
| `ASSET_SHIELD_OBFUSCATION_PRESET` | `balanced` | `light` \| `balanced` \| `aggressive` |
| `ASSET_SHIELD_OBFUSCATION_EXCLUDE_VENDOR` | `true` | Skip `node_modules` chunks |
| `ASSET_SHIELD_RUNTIME` | `false` | Protected runtime delivery (opt-in) |
| `ASSET_SHIELD_ROUTE_PREFIX` | `assets` | Protected route prefix |
| `ASSET_SHIELD_SIGNED_URLS` | `true` | HMAC-sign protected URLs |
| `ASSET_SHIELD_SIGNATURE_EXPIRES` | `300` | Default signature lifetime (seconds) |
| `ASSET_SHIELD_DRIVER` | `public` | `public` \| `stream` |
| `ASSET_SHIELD_CACHE` | `true` | Cache-Control headers + production payload cache |
| `ASSET_SHIELD_CACHE_MAX_AGE` | `31536000` | Cache header max-age |
| `ASSET_SHIELD_CSP` | `false` | Strict CSP on asset responses |

All secrets live in the environment (Laravel's `APP_KEY` drives HMAC identifiers and signatures).
AssetShield itself introduces **no** new secret material into your repository.

## Behaviour notes

- **`enabled=false`**: AssetShield routes/URL rewriting/commands stop, but the Vite plugin can still
  be configured inertly. This makes rollout/rollback trivial.
- **Masking `seed`** must match the Vite plugin's `mask.seed`. `asset-shield:build` emits a
  per-file mismatch error and `asset-shield:doctor` fails categorically when they disagree. Empty
  seeds fall back consistently on both sides so a fresh install "just works", but pin it explicitly
  for reproducible names.
- **Legend path** is resolved via `storagePath()`; keep it outside `public/`. A missing legend simply
  means "no masking happened" — the manifest names are used as-is.
- **`runtime.enabled=true`** routes delivery through opaque `as_` IDs (see
  [signed URLs](signed-urls.md)); every URL is HMAC-signed by default. Leave it off to let the web
  server serve the files directly.
- **`source_maps=true`**: only set this in non-public contexts. Even then asset delivery rejects
  `.map` for protected assets and your logs will contain a loud warning.
- **`cache.enabled`** has two jobs. It sets `Cache-Control` headers on asset responses and — when
  `asset-shield.environment=production` (the package's own switch, independent of the framework
  `APP_ENV`) — serves the decoded manifest and registry payloads through the Laravel cache. Each cache
  entry is keyed by artifact path and stores the artifact mtime alongside the payload, re-reading the
  file whenever it changes — so even a rebuild that misses the explicit purge can never serve a stale
  decoded payload. `ASSET_SHIELD_CACHE=false` disables both, switching back to reading the filesystem
  on every cold request. `asset-shield:build` purges the payload keys.
- **`security.csp=true`** adds an explicit `Content-Security-Policy` to protected asset responses
  (binary content needs none of the allowances a page does). For *pages*, use
  `AssetShield::cspHeader()` / `AssetShield::cspNonce()` — see [Security model](security.md).

See [Security model](security.md), [Production deployment](deployment.md), and
[Troubleshooting](troubleshooting.md) for deeper guidance.