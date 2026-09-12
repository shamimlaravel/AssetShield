# AssetShield Documentation

AssetShield is a Laravel-native asset protection layer for Vite-powered applications. This
documentation explains what it does, how to install and configure it, and how to deploy it
honestly and securely.

> **AssetShield does not make browser-delivered code impossible to inspect. It reduces exposure,
> protects asset access, and increases the cost of reverse engineering.**

## Documentation map

| Section | Type | What you'll find |
|---|---|---|
| [Installation](installation.md) | How-to | Composer + npm install, provider auto-discovery, first build |
| [Configuration](configuration.md) | Reference | Every config key, env var, and default |
| [Usage](usage.md) | How-to | Blade directives, PHP API, `@shieldVite`, resolving and emitting assets |
| [Vite integration](vite.md) | How-to | The `@vendor/asset-shield/vite-plugin`, obfuscation presets, vendor rules |
| [Signed URLs](signed-urls.md) | How-to | HMAC signing, expiration, verification, direct `AssetSigner` use |
| [Security model](security.md) | Explanation | Threat model, what is and isn't protected, honest limits |
| [Production deployment](deployment.md) | How-to | `asset-shield:doctor`, builds, Nginx, CDN, cache behavior |
| [Testing](testing.md) | How-to | Running the PHP and Node test suites |
| [Troubleshooting](troubleshooting.md) | How-to | Common errors, command output, and fixes |

## Quick orientation

```
 composer                          npm
 (Laravel package)                 (vite plugin)
      │                                │
      │  public/build/manifest.json    │  writes
      ▼                                ▼
 AssetManifest ────────► AssetRegistry ◄────── registry.json
      │                       │
      └──► AssetUrlGenerator  │   AssetController (GET /assets/{opaque})
             (opaque URLs)    │          │
             Signer (HMAC)    │          ▼
                          regional delivery drivers (Public / Stream)
```

1. The **Vite plugin** produces the build and writes the registry.
2. The **Laravel package** reads the manifest + registry, resolves logical assets to opaque IDs,
   signs URLs when enabled, and serves bytes through a delivery driver.
3. Unregistered, unsigned, or unknown identifiers are rejected — never arbitrary file paths.

## Core guarantees

- Source trees (`resources/`, `app/`, `routes/`, `vendor/`, `node_modules/`, `.env`) stay outside
  the client-visible surface.
- Protected URLs look like `/assets/7f92a8c1`, never `/build/assets/app-A91Kx.js`.
- Signed URLs can expire; invalid or expired signatures return **403**.
- Standard Laravel Vite behavior (`@vite()`) is untouched.
- Obfuscation and source maps are **disabled by default**, vendor chunks are **never obfuscated by
  default**, and `.map` files are never served for protected assets.

## Requirements

- PHP ^8.3
- Laravel 13 (Illuminate-compatible packages, no full framework needed)
- Node.js >= 18
- Vite >= 5
- Pest or PHPUnit + Orchestra Testbench (package development)

## License & status

- Version: 0.1.0 (MVP)
- [CHANGELOG](../CHANGELOG.md)
- [PRD](../PRD.md) · [TRD](../TRD.md)

## Related reading

- [PRD — Product Requirements](../PRD.md)
- [TRD — Technical Requirements & Design](../TRD.md)