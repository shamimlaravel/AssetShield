# Signed URLs

Signed URLs let you control **when** an asset URL stops working. Each `/assets/{opaque}` URL can
carry an expiry timestamp and an HMAC signature that the controller verifies before serving bytes.
This is useful for:

- limited-time asset grants (e.g. temporary preview links)
- expiring media embeds
- hotlink-style gating per shared link

## How it works

```
URL                  /assets/7f92a8c1?expires=1790000000&signature=ab9c…
│
├─ expires  Unix timestamp (UTC). Absent → treated as non-expiring (signed only).
└─ signature
```

Signature algorithm (server-side, secret = application key):

```
signature = hex( HMAC-SHA256( APP_KEY, "asset-shield:" . opaqueId . ":" . (expires ?? 0) ) )
```

Verification:

1. Malformed signature → `false` → **403**.
2. `expires` in the past → `false` → **403** (replayed/expired links fail even with a valid signature).
3. Signature mismatch → `false` → **403** (`hash_equals`, constant-time).
4. OK → asset served.

## Using it through the facade

```php
use Vendor\AssetShield\Facades\AssetShield;

// signed, non-expiring (signed URL w/o expiry)
AssetShield::url('resources/js/app.js', signed: true);

// signed, expires in 10 minutes
AssetShield::url('resources/js/app.js', signed: true, expires: now()->addMinutes(10));
```

When `config('asset-shield.signature.enabled')` is `true`, `script()` / `style()` / `url()` emit
signed, expiring URLs automatically using the configured default lifetime (`expires` = 300s by
default).

## Direct signer API

Extract the signer from the container when you need a fine-grained API:

```php
use Vendor\AssetShield\Signer\AssetSigner;

$signer = app(AssetSigner::class);   // resolves HmacAssetSigner

$signature = $signer->sign('7f92a8c1', expires: 1790000000);     // returns hex
$valid     = $signer->verify('7f92a8c1', 1790000000, $signature); // true|false
```

### `AssetSigner` interface

```php
interface AssetSigner
{
    public function sign(string $assetId, ?int $expires = null): string;
    public function verify(string $assetId, ?int $expires, string $signature): bool;
}
```

## HTTP behaviour

| Condition | HTTP result |
|---|---|
| Valid signature, no expiry / not expired | `200` with content |
| Invalid signature | `403 Forbidden` |
| Expired signature | `403 Forbidden` |
| Unknown opaque ID | `404 Not Found` |
| Missing signature when required | `403 Forbidden` |

## Cache behaviour for signed assets

Signed/expiring assets are still cacheable, but AssetShield **clamps** the `Cache-Control` max-age
so that the browser never keeps a signed asset beyond its allowed lifetime. Immutable (unsigned,
build-artifact) assets keep `public, max-age=31536000, immutable`.

## Security notes

- Keys never leave the server. The signature math happens entirely server-side.
- Verification is constant-time (`hash_equals`); no early-exit comparison.
- `expires` uses Unix times; beware clock skew between app servers — use a few seconds of slack if
  needed.
- Never place secrets in query strings; the **signature is not a secret to the client**, it is a
  capability token. Anyone with a valid signed URL can use it until it expires.

> AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering. Signed URLs are access
> control for compiled assets — they are not encryption of the code they guard.

## When you DON'T need signed URLs

For plain public assets (CSS, standard JS referenced by your layout), default signed URLs are fine
and slightly stronger. For truly long-lived, cache-friendly assets consider disabling signatures
and relying on immutable caching + CDN — see [Production deployment](deployment.md).