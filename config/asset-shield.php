<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When `false`, AssetShield URL generation, tags, routes and runtime
    | delivery are all disabled. Plain Laravel Vite (`@vite()`) is completely
    | unaffected, so this is a one-line rollback.
    |
    */

    'enabled' => env('ASSET_SHIELD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Which environment this build targets. Obfuscation and source-map warnings
    | default to their safest production behaviour, so verify this reflects the
    | environment the package runs in.
    |
    */

    'environment' => env('ASSET_SHIELD_ENV', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Build artifacts
    |--------------------------------------------------------------------------
    |
    | out_dir      Directory (relative to the public root) where Vite writes the
    |              built assets — used to resolve compiled files from the registry.
    | manifest     Vite manifest location (relative to the Laravel base path).
    | registry     AssetShield registry (must stay outside public/). Defaults to
    |              storage/app/asset-shield/registry.json.
    | source_maps  Whether hostable source maps are produced. Defaults to false;
    |              when enabled a prominent warning is logged. Never "hidden".
    |
    */

    'build' => [
        'out_dir' => env('ASSET_SHIELD_BUILD_OUT_DIR', 'build'),
        'manifest' => env('ASSET_SHIELD_MANIFEST_PATH', 'public/build/manifest.json'),
        'registry' => env('ASSET_SHIELD_REGISTRY_PATH', 'app/asset-shield/registry.json'),
        'source_maps' => env('ASSET_SHIELD_SOURCE_MAPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking (deterministic build-time filename renaming)
    |--------------------------------------------------------------------------
    |
    | enabled   When true the Vite plugin renames entry/shared/dynamic/CSS
    |           output files deterministically (codename or nameless hashes).
    | strategy  'codename' (dictionary words), 'nameless' (hashes),
    |           'preserve' (keep Vite names; explicit per-asset aliases still
    |           apply).
    | seed      Deterministic seed for the name generator. Empty falls back to
    |           "asset-shield" (mirroring the Vite plugin's `seed || 'asset-shield'`),
    |           so PHP recomputation and doctor checks stay in sync. Set a stable
    |           custom value to keep names consistent across machines.
    | aliases   Explicit logical -> filename overrides (with collision checks).
    | include/  Globs constraining which files participate in masking.
    | exclude   Files outside them keep their Vite names. An empty include list
    |           masks everything, matching the plugin.
    | legend    Secret legend mapping logical -> { original, masked }.
    |           MUST stay outside public/ (never routed, never served).
    |           Relative paths resolve under storage_path(), so the default
    |           above means storage/app/asset-shield/legend.json — the same
    |           file the Vite plugin writes by default.
    |
    | Masking names are deterministic and presentational; they are NOT
    | encryption and must not be mistaken for a security boundary.
    |
    */

    'mask' => [
        'enabled' => env('ASSET_SHIELD_MASK', false),
        'strategy' => env('ASSET_SHIELD_MASK_STRATEGY', 'preserve'),
        'seed' => env('ASSET_SHIELD_MASK_SEED', ''),
        'aliases' => [],
        'include' => [],
        'exclude' => [],
        'legend' => env('ASSET_SHIELD_LEGEND_PATH', 'app/asset-shield/legend.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional JS obfuscation (configured and applied on the build side)
    |--------------------------------------------------------------------------
    |
    | These keys mirror what the Vite plugin reads from its own options. They
    | are informational for the PHP side (`asset-shield:status` / `:doctor`);
    | the obfuscation itself is native to `@asset-shield/vite-plugin` and no
    | PHP engine exists. Disabled by default. Obfuscation is NOT encryption:
    | it raises the cost of reverse engineering but cannot make client-
    | delivered code un-inspectable.
    |
    */

    'obfuscation' => [
        'enabled' => env('ASSET_SHIELD_OBFUSCATION', false),
        'preset' => env('ASSET_SHIELD_OBFUSCATION_PRESET', 'balanced'),
        'exclude_vendor' => env('ASSET_SHIELD_OBFUSCATION_EXCLUDE_VENDOR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers / CSP
    |--------------------------------------------------------------------------
    |
    | csp            When true, protected asset responses also carry an explicit,
    |               strict Content-Security-Policy (binary content needs none of
    |               the allowances a page does). Off by default.
    | csp_allowlist  Page-level source allowlists consumed by
    |               AssetShield::cspHeader(): keys script/style/img, each a list
    |               of extra hosts (e.g. ['https://fonts.gstatic.com']). They add
    |               sources; they never weaken the strict defaults by themselves.
    |
    */

    'security' => [
        'csp' => env('ASSET_SHIELD_CSP', false),
        'allowlist' => [
            'script' => [],
            'style' => [],
            'img' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime delivery (opt-in)
    |--------------------------------------------------------------------------
    |
    | When `runtime.enabled` is false (default) AssetShield resolves asset URLs
    | straight to the public build directory, letting the web server serve them
    | at full speed. When enabled, assets are served through the protected
    | route as opaque IDs.
    |
    | route_prefix  URL prefix for protected assets, e.g. /assets/as_...
    | signed_urls   HMAC-sign every emitted protected URL and verify it before
    |               serving bytes.
    | expires       Default signed URL lifetime, in seconds.
    |
    */

    'runtime' => [
        'enabled' => env('ASSET_SHIELD_RUNTIME', false),
        'route_prefix' => env('ASSET_SHIELD_ROUTE_PREFIX', 'assets'),
        'signed_urls' => env('ASSET_SHIELD_SIGNED_URLS', true),
        'expires' => env('ASSET_SHIELD_SIGNATURE_EXPIRES', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | driver  'stream'  -> StreamDriver (Symfony BinaryFileResponse, Range
    |                      support, ideal for large media / CDN origination).
    |         'public'  -> PublicDriver (default, keeps PHP safe from path or
    |                      traversal issues and stays web-server friendly).
    |
    */

    'delivery' => [
        'driver' => env('ASSET_SHIELD_DRIVER', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP caching for immutable production assets
    |--------------------------------------------------------------------------
    |
    | enabled: emit Cache-Control headers. When `asset-shield.environment` is
    |          "production" the same switch also serves the decoded manifest and
    |          registry payloads through the Laravel cache (Cache facade), so cold
    |          requests never hit the filesystem. Set ASSET_SHIELD_CACHE=false to
    |          disable both. Entries are keyed by artifact path and validated
    |          against the artifact's mtime, so a rebuild never serves a stale
    |          decode — even when the explicit purge was missed.
    | max_age: seconds for unsigned immutable assets (1 year by default).
    | For signed/expiring assets the max-age is clamped to the remaining
    | lifetime so a browser never caches beyond the signature expiry.
    |
    */

    'cache' => [
        'enabled' => env('ASSET_SHIELD_CACHE', true),
        'max_age' => env('ASSET_SHIELD_CACHE_MAX_AGE', 31536000),
    ],

];