# Vite Integration

AssetShield ships a TypeScript Vite plugin that turns a normal Vite build into a protected,
registry-fed build. It **never** replaces Vite's bundling, hashing, chunking, or `@vite()` behavior.

## Install

```bash
npm install --save-dev @vendor/asset-shield
# optional, only when enabling obfuscation:
npm install --save-dev javascript-obfuscator
```

`javascript-obfuscator` is an optional peer dependency; the plugin only imports it when obfuscation
is enabled.

## Wire it up

```js
// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { assetShieldVite } from '@vendor/asset-shield';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        assetShieldVite({
            // defaults shown
            enabled: true,
            registryFile: 'storage/asset-shield/registry.json',
            sourceMaps: false,
        }),
    ],
});
```

## Behaviour matrix

| Situation | What happens |
|---|---|
| `vite dev` | **Nothing.** `apply: 'build'` gates the plugin; the dev server is untouched. |
| `vite build` (no obfuscation) | Assets built normally; registry written; chunking and dynamic imports preserved. |
| `vite build` + obfuscation | Application JS chunks obfuscated; vendor chunks skipped; CSS never obfuscated; registry written. |

## Options

```ts
export type AssetShieldViteOptions = {
    enabled?: boolean;                          // default true
    registryFile?: string;                      // default 'storage/asset-shield/registry.json'
    sourceMaps?: boolean;                       // default false
    obfuscation?: {
        enabled?: boolean;                      // default false
        preset?: 'light' | 'balanced' | 'aggressive';   // default 'balanced'
        engine?: 'javascript-obfuscator';       // default
        obfuscateChunks?: 'application' | 'entries' | 'all'; // default 'application'
        include?: string[];                     // glob of chunk names to include
        exclude?: string[];                     // glob of chunk names to exclude (wins)
    };
};
```

## Obfuscation

Obfuscation is **disabled by default**. Enable it explicitly:

```js
assetShieldVite({
    obfuscation: {
        enabled: true,
        preset: 'balanced',   // light | balanced | aggressive
    },
})
```

### Presets

| Preset | Intensity | Recommended use |
|---|---|---|
| `light` | Identifier mangling, minimal transformation | Speed-sensitive apps |
| `balanced` | Identifier mangling + common transforms | **Default when enabled**; most apps |
| `aggressive` | Maximum transforms, best-effort resistance | Heavy protection needs; test carefully |

> AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering. Obfuscation is **not
> encryption** — an attacker with time and tooling can always reverse it.

### Chunk selection rules

- `obfuscateChunks: 'application'` (default) — obfuscates application chunks; chunks whose module
  ids contain `node_modules` are **skipped**.
- `'entries'` — only entry chunks (`isEntry`) are obfuscated.
- `'all'` — obfuscate everything (including vendor/module chunks). Only for advanced users.
- `include` / `exclude` globs refine the selection; `exclude` always wins.

```js
assetShieldVite({
    obfuscation: {
        enabled: true,
        include: ['dashboard/**'],
        exclude: ['vendor/**', '**/*.min.js'],
    },
})
```

### Dynamic imports & chunks

The plugin never re-bundles: `renderChunk` handles code transform for the chunks that match the
rules, `generateBundle` writes the registry. Dynamic `import()` boundaries and Rollup code-splitting
are preserved exactly as Vite produced them.

### CSS is never obfuscated

CSS chunks are excluded from the JS obfuscator unconditionally (FR-25). Minifying CSS remains
Vite/PostCSS territory.

## Source maps

- Default: `sourceMaps: false`. No `.map` files are produced for protected builds.
- Enabling (`sourceMaps: true`) triggers a **prominent warning** in the build output and a log
  warning server-side. Hosted source maps are never "hidden" — treat `true` as acceptable only for
  non-public contexts.
- AssetShield's asset controller rejects `.map` requests for protected assets regardless.

## What the plugin writes

It writes (or updates) one JSON registry, e.g. `storage/asset-shield/registry.json`:

```jsonc
{
  "version": 1,
  "built_at": "2026-09-13T12:00:00Z",
  "entries": [
    { "logical": "resources/js/app.js",
      "compiled": "build/assets/app-A91Kx.js",   // server-only
      "opaque": "7f92a8c1",
      "type": "js", "integrity": "sha384-…" }
  ]
}
```

The **server-side** Laravel package reads this file (cached in production). If you prefer, generate
the same file with `php artisan asset-shield:build` instead of the plugin — both paths are valid.

## Verifying a build

```bash
npm run build
php artisan asset-shield:build      # re-validate & refresh registry
php artisan asset-shield:doctor     # full health check
```

Continue to [Signed URLs](signed-urls.md) or [Deployment](deployment.md).