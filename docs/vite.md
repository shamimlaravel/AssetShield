# Vite Integration

AssetShield ships a TypeScript Vite plugin (`@asset-shield/vite-plugin`) that turns a normal Vite build into a
protected, registry-fed build. It **never** replaces Vite's bundling, hashing, chunking, or `@vite()` behavior.

## Install

```bash
npm install --save-dev @asset-shield/vite-plugin
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
import { assetShieldVite } from '@asset-shield/vite-plugin';

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
            registryFile: 'storage/app/assetshield/registry.json',
            legendFile: 'storage/app/assetshield/legend.json',
            sourceMaps: false,
            mask: { enabled: false },
            obfuscation: { enabled: false },
        }),
    ],
});
```

> Convention: registry and legend MUST live outside `public/`. The `buildDir` (`build` by default) is
> the public-relative directory the manifest points at, exactly as the server expects it.

## Behaviour matrix

| Situation | What happens |
|---|---|
| `vite dev` | **Nothing.** `apply: 'build'` gates the plugin; the dev server is untouched. |
| `vite build` (masking + obfuscation off) | Assets built normally; registry written; chunking and dynamic imports preserved. |
| `vite build` + masking | Entry/chunk file names renamed through Vite's output-naming hooks (see below); legend written. |
| `vite build` + obfuscation | Application JS chunks obfuscated; `node_modules` chunks skipped; CSS never obfuscated; registry written. |

## Options

```ts
export type AssetShieldViteOptions = {
    enabled?: boolean;                          // default true
    registryFile?: string;                      // default 'storage/app/assetshield/registry.json'
    legendFile?: string;                        // default 'storage/app/assetshield/legend.json'
    buildDir?: string;                          // default 'build' (public-relative compiled dir)
    sourceMaps?: boolean;                       // default false
    logLevel?: 'info' | 'warn' | 'error' | 'silent';  // default 'info'
    failOnError?: boolean;                      // throw instead of warning on write errors
    mask?: {
        enabled?: boolean;                      // default false
        strategy?: 'preserve' | 'nameless' | 'codename';  // default 'preserve'
        seed?: string;                          // stable; MUST match PHP asset-shield.mask.seed
        aliases?: Record<string, string>;       // logical -> explicit filename override
        include?: string[];                     // globs; default ['**']
        exclude?: string[];                     // globs (wins over include)
    };
    obfuscation?: {
        enabled?: boolean;                      // default false
        preset?: 'light' | 'balanced' | 'aggressive';   // default 'balanced'
        engine?: 'javascript-obfuscator';       // default
        options?: Record<string, unknown>;      // deep-merged over the preset
        obfuscateChunks?: 'application' | 'entries' | 'all';   // default 'application'
        include?: string[];                     // glob of chunk names to include
        exclude?: string[];                     // glob of chunk names to exclude (wins)
    };
};
```

## Masking

Masking renames build outputs through Vite's **output naming hooks** (`entryFileNames`,
`chunkFileNames`, `assetFileNames`) — names never change after the fact. Three deterministic
strategies:

| Strategy | Result | Example (`assets/app-A91Kx.js`) |
|---|---|---|
| `preserve` (default) | Original Vite name | `assets/app-A91Kx.js` |
| `nameless` | 8-hex digest of `seed:path` | `assets/42623f48.js` |
| `codename` | dictionary adjective-noun | `assets/brisk-viper.js` |

- Names are **deterministic** per `seed` + file path, so identical inputs rebuild identically.
- Because content hashing can no longer drive the filename, Vite's `[hash]` is replaced by a
  deterministic pattern hash when masking is on. The plugin logs a one-time warning — the build is
  still reproducible, just no longer content-addressed.
- The plugin writes `legendFile` mapping every `{ pre-mask path → masked path }`. The server reads
  it (`asset-shield.mask.legend`) to rebuild the registry with both names; `asset-shield:build`
  and `asset-shield:doctor` cross-check the seed so a mismatched `mask.seed` fails fast.

```js
assetShieldVite({
    mask: {
        enabled: true,
        strategy: 'codename',
        seed: 'your-secret-plant-word',
    },
})
```

> Masking names are **presentational, not cryptographic** — anyone downloading the asset sees the
> filename. It defeats casual prying (and "I-know-the-app" URL guessing), not inspection. Use the
> protected runtime route for real access control.
> The legend is a secret artifact: keep it out of `public/` and out of your repo if your threat model
> demands it. AssetShield treats a missing legend as "no masking happened" and falls back to the
> manifest names.

## Obfuscation

Obfuscation is **disabled by default**. Enable it explicitly:

```js
assetShieldVite({
    obfuscation: {
        enabled: true,
        preset: 'balanced',
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
  ids contain `node_modules` are **skipped** (the PHP-side `obfuscation.exclude_vendor` mirror is
  informational).
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
rules, and the registry is written from the emitted manifest after the build output lands on disk.
Dynamic `import()` boundaries and Rollup code-splitting are preserved exactly as Vite produced them.

### CSS is never obfuscated

CSS chunks are excluded from the JS obfuscator unconditionally. Minifying CSS remains
Vite/PostCSS territory.

## Source maps

- Default: `sourceMaps: false`. No `.map` files are produced for protected builds.
- Enabling (`sourceMaps: true`) triggers a **prominent warning** in the build output and a log
  warning server-side. Hosted source maps are never "hidden" — treat `true` as acceptable only for
  non-public contexts.
- AssetShield's asset controller rejects `.map` requests for protected assets regardless.

> With obfuscation enabled the `.map` (if any) points at the *un*-obfuscated chunk — source maps
> and obfuscation are mutually incompatible, and the plugin says so loudly.

## What the plugin writes

Two JSON artifacts, both **outside `public/`** and never served by AssetShield:

```jsonc
// storage/app/assetshield/registry.json  (v2)
{
  "version": 1,
  "built_at": "2026-09-13T12:00:00Z",
  "assets": {
    "resources/js/app.js": {
      "file": "build/assets/42623f48.js",
      "type": "script",
      "integrity": "sha384-…"
    }
  }
}

// storage/app/assetshield/legend.json   (only when masking writes names)
{
  "version": 1,
  "built_at": "2026-09-13T12:00:00Z",
  "seed": "your-secret-plant-word",
  "entries": {
    "assets/app-A91Kx.js": { "original": "assets/app-A91Kx.js", "masked": "assets/42623f48.js" }
  }
}
```

The registry's `opaque` field is intentionally absent — the server derives it from the compiled path
under `APP_KEY` when it loads the registry, so the plugin never needs the key. Rows whose `file` came
from the legend also carry `original` (the pre-mask path) so `asset-shield:build` can keep both
names in sync.

## Two ways to produce the registry

1. **Plugin only** — run `npm run build`; the plugin writes `registry.json` (+ `legend.json` when
   masked). `php artisan asset-shield:build` re-validates/refreshes it.
2. **PHP orchestration** — `php artisan asset-shield:build --run` runs `npm run build` first
   (via a subprocess), then consumes the manifest + legend itself.

## Verifying a build

```bash
php artisan asset-shield:build --run     # npm build, then build registry from manifest+legend
php artisan asset-shield:doctor          # full health check (includes legend/seed consistency)
```

Continue to [Signed URLs](signed-urls.md) or [Deployment](deployment.md).