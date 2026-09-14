# Usage — Blade & PHP API

AssetShield gives you two equivalent ways to emit protected assets: Blade directives and the PHP
facade. Both resolve the same way under the hood:

```
logical entry          compiled file          opaque identifier
resources/js/app.js  → build/assets/app-A91Kx.js → 7f92a8c1
resources/css/app.css → build/assets/app.css-8e3f2a.css → c21b7e66
```

## Blade directives

### `@assetShield('resources/js/app.js')`

Resolves the entry through the registry and renders the appropriate tag:

```blade
@assetShield('resources/js/app.js')
{{-- <script src="/assets/7f92a8c1?expires=...&signature=..."></script> --}}

@assetShield('resources/css/app.css')
{{-- <link rel="stylesheet" href="/assets/c21b7e66?..."> --}}
```

The tag type (script vs. stylesheet) is inferred from the file type in the registry.

### Typed helpers

```blade
@assetShieldCss('resources/css/app.css')
{{-- <link rel="stylesheet" href="/assets/c21b7e66?..."> --}}

@assetShieldJs('resources/js/app.js')
{{-- <script src="/assets/7f92a8c1?..."></script> --}}
```

## PHP facade

```php
use Shamimstack\AssetShield\Facades\AssetShield;
```

### `AssetShield::url(string $entry, ?string $extra = '') : string`

Returns the protected URL (optionally signed):

```php
AssetShield::url('resources/js/app.js');
// /assets/7f92a8c1?expires=1790000000&signature=ac42b8…

AssetShield::url('resources/js/app.js', ['flavor' => 'dark']);
// client-side usable signatures are NOT supported — see signed URLs for the safe API
```

### `AssetShield::script(string $entry) : string`

```php
{!! AssetShield::script('resources/js/app.js') !!}
// <script src="/assets/7f92a8c1?expires=…&signature=…"></script>
```

### `AssetShield::style(string $entry) : string`

```php
{!! AssetShield::style('resources/css/app.css') !!}
// <link rel="stylesheet" href="/assets/c21b7e66?expires=…&signature=…">
```

### `AssetShield::resolve(string $entry)`

Returns the compiled metadata (relative path, opaque ID, mime type) without rendering HTML — useful
for custom markup:

```php
AssetShield::resolve('resources/js/app.js');
// ['compiled' => 'build/assets/app-A91Kx.js', 'opaque' => '7f92a8c1', 'type' => 'js']
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
- Unsigned URLs are produced when signatures are disabled or when you call
  `AssetShield::url($entry, signed: false)`.
- The browser receives **only** the opaque ID; the real compiled filename never appears in HTML.

## Learn more

- [Signed URLs](signed-urls.md)
- [Vite integration / obfuscation](vite.md)
- [Configuration](configuration.md)