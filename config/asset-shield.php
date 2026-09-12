<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When `false`, AssetShield routes, URL rewriting and asset delivery are all
    | disabled. Plain Laravel Vite (`@vite()`) is completely unaffected, so this
    | gives you a one-line rollback.
    |
    */

    'enabled' => env('ASSET_SHIELD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | 'protected' (default) serves assets via opaque IDs through the protected
    | route. Future releases may add a passthrough 'public' mode.
    |
    */

    'mode' => env('ASSET_SHIELD_MODE', 'protected'),

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | Protected URLs have the shape /{route_prefix}/{opaque-id}, e.g.
    | /assets/7f92a8c1.
    |
    */

    'route_prefix' => env('ASSET_SHIELD_ROUTE_PREFIX', 'assets'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL behaviour
    |--------------------------------------------------------------------------
    |
    | enabled: when true, every emitted URL is HMAC-signed and the controller
    |          verifies it before serving bytes.
    | expires: default lifetime for signed URLs, in seconds.
    |
    */

    'signature' => [
        'enabled' => true,
        'expires' => env('ASSET_SHIELD_SIGNATURE_EXPIRES', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional JS obfuscation (configured on the build side)
    |--------------------------------------------------------------------------
    |
    | These keys mirror what the Vite plugin reads from its own options. They
    | are informational for the PHP side (`asset-shield:status` / `:doctor`)
    | and are disabled by default. Obfuscation is NOT encryption.
    |
    */

    'obfuscation' => [
        'enabled' => env('ASSET_SHIELD_OBFUSCATION', false),
        'preset' => 'balanced',
        'engine' => 'javascript-obfuscator',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source maps
    |--------------------------------------------------------------------------
    |
    | Production default is FALSE. When intentionally enabled, a prominent
    | warning is logged. Hosted source maps are never "hidden".
    |
    */

    'source_maps' => env('ASSET_SHIELD_SOURCE_MAPS', false),

    /*
    |--------------------------------------------------------------------------
    | Hotlink protection
    |--------------------------------------------------------------------------
    |
    | Off by default: correct referer/host gating depends on your upstream
    | (proxy, CDN). Enable only with an explicit allow-list of upstream hosts.
    |
    */

    'hotlink_protection' => env('ASSET_SHIELD_HOTLINK_PROTECTION', false),

    'hotlink' => [
        'hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP caching for immutable production assets
    |--------------------------------------------------------------------------
    |
    | enabled: emit Cache-Control headers.
    | max_age: seconds for unsigned immutable assets (1 year by default).
    | For signed/expiring assets the max-age is clamped to the remaining
    | lifetime so a browser never caches beyond the signature expiry.
    |
    */

    'cache' => [
        'enabled' => true,
        'max_age' => env('ASSET_SHIELD_CACHE_MAX_AGE', 31536000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | manifest_path   Vite manifest (relative to the Laravel base path).
    | registry_path   AssetShield registry (must stay outside public/).
    |
    */

    'manifest_path' => env('ASSET_SHIELD_MANIFEST_PATH', 'public/build/manifest.json'),

    'registry_path' => env('ASSET_SHIELD_REGISTRY_PATH', 'storage/asset-shield/registry.json'),

    /*
    |--------------------------------------------------------------------------
    | Delivery driver
    |--------------------------------------------------------------------------
    |
    | 'public'  -> PublicFileDriver (default, PHP whitelist friendly)
    | 'stream'  -> StreamDriver (Symfony BinaryFileResponse, range support)
    |
    */

    'driver' => env('ASSET_SHIELD_DRIVER', 'public'),

];