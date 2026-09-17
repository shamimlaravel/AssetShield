# Installation

This guide installs AssetShield into a Laravel 13 application and produces your first protected
assets. It assumes Laravel's Vite setup is already working (`npm run build` produces
`public/build/manifest.json`).

## 1. Install the Composer package

```bash
composer require shamimstack/asset-shield
```

Laravel package discovery registers `AssetShieldServiceProvider` automatically. For package
development inside this repository, use the dev tooling instead:

```bash
composer install   # installs dev tooling (testbench, pest) alongside runtime deps
```

## 2. Publish and configure

```bash
php artisan asset-shield:install
```

This command:

- publishes `config/asset-shield.php`
- creates the storage path used by the registry and legend (`storage/app/asset-shield/`)
- prints the required Vite integration step
- never overwrites an existing config file without confirmation

If you prefer to publish manually:

```bash
php artisan vendor:publish --provider="Shamimstack\AssetShield\AssetShieldServiceProvider"
```

## 3. Install the Vite plugin

```bash
npm install --save-dev @asset-shield/vite-plugin
```

Add the plugin to your `vite.config.js` (or `.ts`):

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { assetShieldVite } from '@asset-shield/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        assetShieldVite(),
    ],
});
```

> The plugin is inert during `vite dev` and only activates on `vite build`.

## 4. Build once

```bash
npm run build
php artisan asset-shield:build
```

The Vite build emits your real, freshly versioned assets **and** writes the AssetShield registry.
`asset-shield:build` re-validates the manifest and rebuilds the registry server-side, verifying
every registered asset.

## 5. Use it in your views

```blade
{!! AssetShield::script('resources/js/app.js') !!}
{!! AssetShield::style('resources/css/app.css') !!}
```

or the Blade directives:

```blade
@assetShield('resources/js/app.js')
@assetShieldCss('resources/css/app.css')
@assetShieldJs('resources/js/app.js')
```

## 6. Opt in to masking & obfuscation (optional)

Masking, obfuscation, and the opaque runtime are all off by default so the first integration stays
predictable and diffable. Turn them on per environment:

```php
// config/asset-shield.php
'mask' => [
        'enabled'  => true,
        'strategy' => 'codename',  // preserve | nameless | codename
        'seed'     => env('ASSET_SHIELD_MASK_SEED', 'a-long-secret-phrase'),
    ],
'obfuscation' => [
    'enabled' => true,
    'preset'  => 'balanced',   // light | balanced | aggressive
],
```

Masked and obfuscated output is derived deterministically from your `ASSET_SHIELD_MASK_SEED` (and
`APP_KEY`): keep them stable or every deploy renames every file (and breaks client caches).
Obfuscation runs natively through the Vite plugin during `vite build` and stays inert while
`vite dev` serves readable source. Only the server ever reads the legend that maps names back.

See [configuration.md](configuration.md) for aliases, include/exclude lists, the Vite-plugin options,
and the caching rules. There is no automatic environment gating — masking and obfuscation follow
their config flags, which are off by default.

## 7. Verify

```bash
php artisan asset-shield:status
```

Expected (slightly abbreviated):

```
AssetShield
-----------
  Enabled:            yes
  Environment:        production
  Runtime delivery:   no
  Route prefix:       assets
  Manifest:           found
  Registry:           valid
  Signed URLs:        yes
  Expiration:         300s
  Masking:            disabled
  Obfuscation:        disabled
  Source Maps:        no
  Driver:             public
```

You can now open the page, view source, and confirm scripts load from
`/assets/{opaque-id}` instead of `/build/assets/app-<hash>.js`.

Once you complete step 6, the status output replaces those `disabled` lines with the live values —
`Masking:  codename` and `Obfuscation:  balanced`.

## What was NOT installed

- No database tables — the registry is a build artifact.
- No full Laravel framework dependency — only `illuminate/*` packages are used.
- No dev-server changes — `vite dev` keeps behaving normally.

## Uninstall

Removal is a Composer/npm-level operation — there is **no** artisan uninstall command.

```bash
composer remove shamimstack/asset-shield     # unregisters the provider (auto-discovery)
npm uninstall --save-dev @asset-shield/vite-plugin
```

Composer and npm only remove the packages. Everything installation published or created stays on
disk and must be cleaned up manually:

1. Delete the published config — `config/asset-shield.php` (or revert it from version control).
2. Remove `assetShieldVite()` from `vite.config.js` / `vite.config.ts`.
3. Revert `@shieldVite([...])` to `@vite([...])` in Blade and delete any `@assetShield`,
   `@assetShieldJs`, `@assetShieldCss` directives.
4. Delete the build artifacts under `storage/app/asset-shield/` (`registry.json`, `legend.json`).
   They are regenerated on the next build and are not needed once the package is gone.

Nothing else is touched: no database tables, no runtime-mutated `public/` files beyond the normal
`build` output.

## Next steps

- [Configuration reference](configuration.md)
- [Blade + PHP usage](usage.md)
- [Signed URLs](signed-urls.md)