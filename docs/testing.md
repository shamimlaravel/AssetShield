# Testing

AssetShield is tested on both sides of its boundary: the Laravel package (PHP) and the Vite plugin
(TypeScript). This page tells you how to run the suites and what each covers.

## Prerequisites

```bash
composer install   # pulls testbench + pest (or phpunit)
npm install        # pulls vitest, tsup, typescript
```

## PHP tests

Use **Pest** (bundled) or plain PHPUnit — the suite is written to run under either.

```bash
composer test
```

or, explicitly:

```bash
vendor/bin/pest                        # pest suite
vendor/bin/phpunit --testsuite=Unit    # phpunit-style subset
```

### What the PHP suite covers

| Test | What it proves |
|---|---|
| Manifest loading | found / missing / malformed `public/build/manifest.json` handling |
| Asset resolution | logical entry → compiled file via the manifest |
| Opaque ID generation | deterministic, key-derived, crypto-shaped (hex, fixed length) |
| Valid signed URL | `sign()` → `verify()` returns `true` |
| Invalid signature | returns `false`; controller returns **403** |
| Expired URL | returns `false`; controller returns **403** |
| Unknown asset | returns **404** with a useful exception/message |
| Path traversal | `../`, absolute paths, encoded `%2e%2e` → **404/403**, never a read |
| `.env` access | `.env` and sibling-sensitive filenames → **404/403** |
| Vendor access | `vendor/...` paths → **404/403** |
| MIME types | JS/CSS/SVG/JSON/fonts/images return correct Content-Type |
| Cache headers | immutable `Cache-Control` for unsigned; clamped for signed |
| Disabled mode | routing inactive, `@vite()` untouched |
| Blade directives | render correct `<script>` / `<link>` markup |
| `doctor` command | render sensible output, correct exit code for known-good fixture |

Test fixtures live in `tests/Fixtures` (a synthetic `public/build/manifest.json` plus a registry),
so tests never depend on a real Vite run.

## Node / TypeScript tests (Vite plugin)

```bash
npm test
```

### What the Vitest suite covers

| Test | What it proves |
|---|---|
| Activates only on build | `command === 'build'` triggers; `serve` is inert |
| Dev server unaffected | plugin's `configureServer` is a no-op |
| JS chunk obfuscation | `renderChunk` transforms application chunks when enabled |
| Vendor exclusion | chunks whose ids contain `node_modules` are untouched by default |
| include/exclude rules | globs honored; `exclude` wins |
| Source maps | disabled default; warning emitted when enabled |
| Empty chunks | no crash on empty output |
| Dynamic imports | preserved after obfuscation (output shape intact) |
| Build failures | surfaced loudly rather than silently ignored |

Tests run against Rollup/Vite programmatically with small synthetic fixture modules — no real
application needed.

## Adding a test

- **PHP:** drop a `Pest` test in `tests/Feature` or `tests/Unit`, reuse `TestCase` (which boots
  Orchestra Testbench with the provider and a fixture manifest).
- **Node:** add a `*.test.ts` under `packages/vite-plugin/tests`; fixtures are tiny inline modules.

## CI suggestion

```yaml
steps:
  - run: composer install
  - run: composer test
  - run: npm ci
  - run: npm test
  - run: npm run build        # build the plugin package (tsup)
```

## Coverage expectations (MVP bar)

- PHP: every FR-test in the [TRD §13 table](./TRD.md#13-test-plan) green.
- Node: every plugin contract green; no test asserts "impossible secrecy" (there is none).

See [Troubleshooting](troubleshooting.md) when a test fails unexpectedly.