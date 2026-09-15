# AssetShield

A **Laravel-native asset protection layer** for Vite-powered applications. Keeps your source outside
the web root, serves compiled assets behind opaque, signed, expiring URLs, and raises the cost of
reverse engineering.

> **AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering.**

---

## What it does

| Capability | Result |
|---|---|
| Source outside web root | `resources/`, `app/`, `routes/`, `vendor/`, `node_modules/`, `.env` never public |
| Opaque asset URLs | `/assets/7f92a8c1` instead of `/build/assets/app-A91Kx.js` |
| Signed, expiring URLs | HMAC-SHA256, constant-time verify, `expires`, forged/expired → **403** |
| Registry-only resolution | Arbitrary file paths are structurally impossible to request |
| Cache + security headers | `immutable` caching, correct MIME, `nosniff`, no wildcard CORS |
| Optional JS obfuscation | `light` / `balanced` / `aggressive`, **off by default**, vendor chunks skipped |
| Vite compatibility | Uses your existing `@vite()` manifest and pipeline — nothing replaced |
| Delivery drivers | `PublicDriver` / `StreamDriver` now; Nginx/S3/R2/CDN ready later |
| Diagnostics | `asset-shield:doctor`, `:status`, `:build`, `:install` |

**What it cannot do** (and never claims to): hide browser-delivered code, encrypt JavaScript,
block DevTools, or act as DRM. See the [honest security model](docs/security.md).

---

## Requirements

- PHP **^8.3**, Laravel 13–compatible `illuminate/*` (no full framework dependency)
- Node.js **>= 18**, Vite **>= 5**

## Installation

```bash
composer require shamimstack/asset-shield
npm install --save-dev @asset-shield/vite-plugin
```

```bash
php artisan asset-shield:install      # publish config, create paths, show Vite setup
```

Add the plugin to `vite.config.js`:

```js
import { assetShieldVite } from '@asset-shield/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        assetShieldVite(),
    ],
});
```

Build and verify:

```bash
npm run build
php artisan asset-shield:build        # validate manifest, write registry
php artisan asset-shield:status
```

## Usage

```blade
{{-- Blade directives --}}
@assetShieldCss('resources/css/app.css')
@assetShieldJs('resources/js/app.js')
@assetShield('resources/js/app.js')

{{-- Or the PHP API --}}
{!! AssetShield::script('resources/js/app.js') !!}   {{-- <script src="/assets/7f92a8c1?..."></script> --}}
{!! AssetShield::style('resources/css/app.css') !!}  {{-- <link rel="stylesheet" href="/assets/..."> --}}
AssetShield::url('resources/js/app.js', signed: true, expires: now()->addMinutes(10));

{{-- Optional convenience wrapper, compatible with @vite() --}}
@shieldVite(['resources/css/app.css', 'resources/js/app.js'])
```

## Configuration

See [docs/configuration.md](docs/configuration.md) for the full reference. Highlights:

```php
'enabled'    => env('ASSET_SHIELD_ENABLED', true),
'environment'=> env('ASSET_SHIELD_ENV', env('APP_ENV', 'production')),
'build'      => [
    'out_dir'     => 'build',
    'manifest'    => 'public/build/manifest.json',
    'registry'    => 'storage/app/asset-shield/registry.json',
    'source_maps' => false,
],
'mask' => ['enabled' => false, 'strategy' => 'preserve', 'seed' => '', 'legend' => 'app/asset-shield/legend.json'],
'obfuscation'=> ['enabled' => false, 'preset' => 'balanced', 'engine' => 'javascript-obfuscator', 'exclude_vendor' => true],
'runtime'    => ['enabled' => false, 'route_prefix' => 'assets', 'signed_urls' => true, 'expires' => 300],
'delivery'   => ['driver' => 'public'],
'cache'      => ['enabled' => true, 'max_age' => 31536000],
'security'   => ['csp' => false, 'allowlist' => ['script' => [], 'style' => [], 'img' => []]],
```

## Documentation

- [Installation](docs/installation.md)
- [Configuration reference](docs/configuration.md)
- [Usage (Blade + PHP API)](docs/usage.md)
- [Vite integration & obfuscation](docs/vite.md)
- [Signed URLs](docs/signed-urls.md)
- [Security model](docs/security.md)
- [Production deployment](docs/deployment.md)
- [Testing](docs/testing.md)
- [Troubleshooting](docs/troubleshooting.md)

Companion specs: [PRD](docs/PRD.md) · [TRD](docs/TRD.md)

## Testing

```bash
composer test     # Pest/PHPUnit + Orchestra Testbench
npm test          # Vitest for the Vite plugin
```

## Commands

| Command | Purpose |
|---|---|
| `asset-shield:install` | Publish config, create directories, show Vite setup |
| `asset-shield:build` | Validate manifest + regenerate/validate the registry (`--run` also runs `npm run build`) |
| `asset-shield:status` | Print configuration snapshot |
| `asset-shield:doctor` | Environment/security health check (pre-deploy) |

## Production

```bash
php artisan asset-shield:doctor   # required pre-deploy
```

Key production facts: source maps **off** by default and never served for protected assets; `.env`
must stay outside `public/`; `APP_DEBUG=false`; immutable IDs + cache headers mean a CDN can hold
`/assets/*` for a year; Nginx hardening recommendations are in
[docs/deployment.md](docs/deployment.md#3-nginx-recommendations).

**Rotating `APP_KEY` regenerates every opaque ID and signature** — rebuild the registry when you do.

## Security model — the honest summary

1. Server-side source files are kept out of the web root and are never requestable via AssetShield.
2. Protected URLs are opaque and key-derived — no filenames, no structure leakage; optional
   build-time masking strips framework fingerprints from served filenames too.
3. Signed URLs expire; forging/expiry → **403** via constant-time HMAC verification.
4. Files are resolved only through the AssetRegistry — path traversal, `.env`, storage/vendor
   reads are structurally impossible.
5. Obfuscation is optional, off by default, vendor-safe, and explicitly **not** encryption.

## License & status

- **Version:** 0.1.0 (MVP) · [CHANGELOG](CHANGELOG.md)
- **License:** MIT (see [LICENSE](LICENSE))
- Composer package: `shamimstack/asset-shield` · Vite plugin: `@asset-shield/vite-plugin`