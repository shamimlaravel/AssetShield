# Configuration Reference

The package is configured entirely through `config/asset-shield.php`. Every sensitive value comes
from environment variables via `env()`; nothing is hard-coded. Publish the file with
`php artisan asset-shield:install` or `vendor:publish`.

## Complete reference

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false, AssetShield routes, URL rewriting, and delivery are disabled.
    | Plain Laravel Vite (`@vite()`) is completely unaffected.
    */
    'enabled' => env('ASSET_SHIELD_ENABLED', true),

    /*
    | 'protected' — assets served via opaque IDs through protected routes.
    | Future: 'public' passthrough mode.
    */
    'mode' => env('ASSET_SHIELD_MODE', 'protected'),

    /*
    | URL prefix. Default: /assets/{opaque-id}
    */
    'route_prefix' => env('ASSET_SHIELD_ROUTE_PREFIX', 'assets'),

    /*
    | Signed URL behaviour.
    | enabled:  attach/verify HMAC signatures.
    | expires:  default lifetime in seconds (300 = 5 minutes).
    */
    'signature' => [
        'enabled' => true,
        'expires' => env('ASSET_SHIELD_SIGNATURE_EXPIRES', 300),
    ],

    /*
    | Obfuscation is OPT-OUT-SAFE: disabled by default.
    | engine: 'javascript-obfuscator'
    | preset: light | balanced | aggressive
    | Vendor (node_modules) chunks are skipped unless told otherwise — see docs/vite.md.
    */
    'obfuscation' => [
        'enabled' => env('ASSET_SHIELD_OBFUSCATION', false),
        'preset'  => 'balanced',
        'engine'  => 'javascript-obfuscator',
    ],

    /*
    | Production default is FALSE. When intentionally enabled, a prominent
    | warning is written to the logs. Never assume hosted source maps are hidden.
    */
    'source_maps' => env('ASSET_SHIELD_SOURCE_MAPS', false),

    /*
    | Reject requests whose Referer/Host is not configured (advanced).
    | Default off; enable only with a known upstream host list.
    */
    'hotlink_protection' => env('ASSET_SHIELD_HOTLINK_PROTECTION', false),

    /*
    | HTTP caching for immutable production assets.
    | enabled: emit Cache-Control.
    | max_age: seconds; 31536000 = 1 year, "immutable".
    | For signed/expiring assets the max-age is clamped/reflected by the response.
    */
    'cache' => [
        'enabled' => true,
        'max_age' => env('ASSET_SHIELD_CACHE_MAX_AGE', 31536000),
    ],
];
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `ASSET_SHIELD_ENABLED` | `true` | Master switch |
| `ASSET_SHIELD_MODE` | `protected` | Delivery mode |
| `ASSET_SHIELD_ROUTE_PREFIX` | `assets` | URL prefix |
| `ASSET_SHIELD_SIGNATURE_EXPIRES` | `300` | Default signature lifetime (seconds) |
| `ASSET_SHIELD_OBFUSCATION` | `false` | Enable obfuscation flag (mirrored in plugin) |
| `ASSET_SHIELD_SOURCE_MAPS` | `false` | Whether source maps are built |
| `ASSET_SHIELD_HOTLINK_PROTECTION` | `false` | Referer-based gating |
| `ASSET_SHIELD_CACHE_MAX_AGE` | `31536000` | Cache header max-age |

All secrets live in the environment (Laravel's `APP_KEY` drives HMAC identifiers and signatures).
AssetShield itself introduces **no** new secret material into your repository.

## Behaviour notes

- **`enabled=false`**: AssetShield routes/commands-service stop, but the Vite plugin can still be
  configured inertly. This makes rollout/rollback trivial.
- **`signature.enabled=true`** signs every `/assets/{opaque}` URL emitted by
  `AssetShield::url()/script()/style()`. Use [signed URLs](signed-urls.md) for manual/long-lived
  links.
- **`source_maps=true`**: only set this in non-public contexts. Even then asset delivery rejects
  `.map` for protected assets and your logs will contain a loud warning.
- **`hotlink_protection=true`**: requires additional `hotlink` host configuration (implementation
  detail); not enabled by default.

See [Security model](security.md), [Production deployment](deployment.md), and
[Troubleshooting](troubleshooting.md) for deeper guidance.