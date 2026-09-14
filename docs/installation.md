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
- creates the storage path used by the registry and legend (`storage/app/assetshield/`)
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

## 6. Verify

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
    . engine:         not found
  Source Maps:        no
  Driver:             public
```

You can now open the page, view source, and confirm scripts load from
`/assets/{opaque-id}` instead of `/build/assets/app-<hash>.js`.

## What was NOT installed

- No database tables — the registry is a build artifact.
- No full Laravel framework dependency — only `illuminate/*` packages are used.
- No dev-server changes — `vite dev` keeps behaving normally.

## Next steps

- [Configuration reference](configuration.md)
- [Blade + PHP usage](usage.md)
- [Signed URLs](signed-urls.md)