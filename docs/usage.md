# Usage — Blade & PHP API

AssetShield gives you two equivalent ways to emit protected assets: Blade directives and the PHP
facade. Both resolve the same way under the hood:

```
logical entry          compiled file          opaque identifier
resources/js/app.js  → build/assets/app-A91Kx.js → as_2ae34e8b0c462491
resources/css/app.css → build/assets/app.css-8e3f2a.css → as_9f7c1d2e69b8a534
```

## Blade directives

### `@assetShield('resources/js/app.js')`

Resolves the entry through the registry and renders the appropriate tag:

```blade
@assetShield('resources/js/app.js')
{{-- <script src="/assets/as_2ae34e8b0c462491?expires=...&signature=..."></script> --}}

@assetShield('resources/css/app.css')
{{-- <link rel="stylesheet" href="/assets/as_9f7c1d2e69b8a534?..."> --}}
```

The tag type (script vs. stylesheet) is inferred from the file type in the registry.

### Typed helpers

```blade
@assetShieldCss('resources/css/app.css')
{{-- <link rel="stylesheet" href="/assets/as_9f7c1d2e69b8a534?..."> --}}

@assetShieldJs('resources/js/app.js')
{{-- <script src="/assets/as_2ae34e8b0c462491?..."></script> --}}
```

## PHP facade

```php
use Shamimstack\AssetShield\Facades\AssetShield;
```

### `AssetShield::url(string $entry, ?bool $signed = null, int|\DateTimeInterface|null $expires = null) : string`

Returns the protected URL (optionally signed). `$expires` accepts an absolute Unix timestamp or any
`DateTimeInterface` (Carbon is fine):

```php
AssetShield::url('resources/js/app.js');
// /assets/as_2ae34e8b0c462491?expires=1790000000&signature=ac42b8…

AssetShield::url('resources/js/app.js', expires: now()->addMinutes(10));
// signed URL with a ten-minute lifetime
```

### `AssetShield::script(string $entry) : string`

```php
{!! AssetShield::script('resources/js/app.js') !!}
// <script src="/assets/as_2ae34e8b0c462491?expires=…&signature=…"></script>
```

### `AssetShield::style(string $entry) : string`

```php
{!! AssetShield::style('resources/css/app.css') !!}
// <link rel="stylesheet" href="/assets/as_9f7c1d2e69b8a534?expires=…&signature=…">
```

### `AssetShield::resolve(string $entry)`

Returns the compiled metadata (relative file path, original pre-mask path, opaque ID, type, integrity,
mime type and URL) without rendering HTML — useful for custom markup:

```php
AssetShield::resolve('resources/js/app.js');
// ['file' => 'build/assets/app-A91Kx.js', 'original' => 'build/assets/app-A91Kx.js',
//  'opaque' => 'as_2ae34e8b0c462491', 'type' => 'script', 'integrity' => null,
//  'mime' => 'text/javascript', 'url' => '/assets/as_2ae34e8b0c462491?expires=…&signature=…']
```

Unknown entries throw `AssetNotFoundException` and the message includes the entry name and manifest
path so you can fix it fast.

## `@shieldVite` — convenience wrapper

`@shieldVite([...])` resolves the given Vite entry list through AssetShield and renders the
resulting tags in order, while remaining fully compatible with regular `@vite()` elsewhere.

```blade
@shieldVite(['resources/css/app.css', 'resources/js/app.js'])
{{-- renders style for app.css, then script for app.js, via AssetShield --}}
```

Rules:

- Every entry must exist in the registry (i.e. was in the Vite build).
- Entries not managed by AssetShield render as **nothing** (logged once) — they are never forced
  through AssetShield against their will.
- Mixing `@vite()` and `@shieldVite()` in one layout is supported; don't register the same entry in
  both or you'd get duplicate tags.

## Disabled mode

When `asset-shield.enabled=false`, the Blade directives resolve to **empty output** and `url()`
falls back to the plain `@vite()` helper output (documented and logged). `@vite()` keeps working as
it always has, so rollback is a one-line config change.

## Cache & signature notes

- Emitted URLs include `expires` + `signature` when `runtime.signed_urls=true`.
- Unsigned URLs are produced when `runtime.signed_urls=false`; pass `signed: true` for an explicit
  per-call signature. When signing is enforced by config, requesting `signed: false` still yields a
  signed URL — the delivery middleware verifies signatures, so the global config wins.
- The browser receives **only** the opaque ID; the real compiled filename never appears in HTML.

## Learn more

- [Signed URLs](signed-urls.md)
- [Vite integration / obfuscation](vite.md)
- [Configuration](configuration.md)