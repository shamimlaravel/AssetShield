<?php

namespace Shamimstack\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Shamimstack\AssetShield\Exceptions\RegistryInvalidException;
use Shamimstack\AssetShield\Support\MimeMapper;
use Shamimstack\AssetShield\Support\OpaqueId;

/**
 * Maps logical assets -> compiled files -> protected opaque identifiers.
 *
 *   resources/js/app.js
 *           |
 *           v
 *   build/assets/app-A91Kx.js          (file; may be a masked name)
 *           |
 *           v
 *   as_7f92a8c1d2e3...                 (HMAC-derived under APP_KEY, never stored)
 *
 * The registry is a build artifact written as:
 *
 *   { "version": 1, "built_at": "...", "assets": {
 *       "app.js": { "type": "script", "file": "8f4a1c7d.js",
 *                   "original": "app-A91Kx.js", "integrity": "sha384-..." }
 *   } }
 *
 * Opaque identifiers are always derived server-side from the application key;
 * neither the Vite plugin nor the client ever sees the real compiled path or
 * the key. FNV-based mask names are presentational only and are never
 * used as security.
 */
class AssetRegistry
{
    /** @var array<string, array{logical:string,file:string,original:?string,opaque:string,type:string,integrity:?string}> */
    private array $entries = [];

    /** @var array<string,string> opaque -> logical */
    private array $opaqueIndex = [];

    /** @var array<string,string> logical -> opaque */
    private array $logicalIndex = [];

    private bool $loaded = false;

    public function __construct(
        private readonly string $registryPath,
        private readonly string $appKey,
    ) {
    }

    public static function fromConfig(Application $app): self
    {
        $path = (string) $app['config']->get('asset-shield.build.registry', 'storage/app/asset-shield/registry.json');

        if ($path === '' || preg_match('/^[A-Za-z]:[\\\\\/]|^\//', $path) !== 1) {
            $path = $app->storagePath($path);
        }

        return new self($path, (string) $app['config']->get('app.key'));
    }

    public function path(): string
    {
        return $this->registryPath;
    }

    public function fileExists(): bool
    {
        return is_file($this->registryPath);
    }

    /**
     * Load the registry artifact (lazily, once per worker).
     *
     * @throws RegistryInvalidException
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if (! $this->fileExists()) {
            throw RegistryInvalidException::missing($this->registryPath);
        }

        $decoded = json_decode((string) file_get_contents($this->registryPath), true);

        if (! is_array($decoded) || ! isset($decoded['assets']) || ! is_array($decoded['assets'])) {
            throw RegistryInvalidException::invalid($this->registryPath, 'missing "assets" map');
        }

        $entries = [];
        foreach ($decoded['assets'] as $logical => $raw) {
            $entry = $this->normalizeRaw((string) $logical, (array) $raw);
            $entries[$entry['opaque']] = $entry;
        }

        $this->setEntries($entries);
        $this->loaded = true;
    }

    /**
     * Replace the in-memory registry from a list of rows and materialize the
     * server-side opaque identifiers. Persists unless $persist is false.
     *
     * @param  array<int,array{logical:string,file:string,type:?string,integrity:?string,original:?string}>  $rows
     * @return array<string, array{logical:string,file:string,original:?string,opaque:string,type:string,integrity:?string}>
     */
    public function create(array $rows, bool $persist = true): array
    {
        $entries = [];
        $seenLogicals = [];

        foreach ($rows as $index => $row) {
            $logical = (string) ($row['logical'] ?? '');
            $file = (string) ($row['file'] ?? '');

            if ($logical === '' || $file === '' || ! OpaqueId::isSafe($file)) {
                throw new InvalidArgumentException('AssetShield registry entries require a logical key and a safe file path (row #'.$index.').');
            }

            if (isset($seenLogicals[$logical])) {
                throw new InvalidArgumentException('AssetShield registry logical key collision for "'.$logical.'".');
            }
            $seenLogicals[$logical] = true;

            $opaque = OpaqueId::from($file, $this->appKey);

            if (isset($entries[$opaque])) {
                throw new InvalidArgumentException('AssetShield opaque id collision for "'.$logical.'" and "'.$entries[$opaque]['logical'].'".');
            }

            $entries[$opaque] = [
                'logical' => $logical,
                'file' => OpaqueId::canonicalize($file),
                'original' => isset($row['original']) && (string) $row['original'] !== '' ? (string) $row['original'] : null,
                'opaque' => $opaque,
                'type' => (string) ($row['type'] ?? MimeMapper::family($file)),
                'integrity' => isset($row['integrity']) ? (string) $row['integrity'] : null,
            ];
        }

        if ($persist) {
            $this->save();
        }

        $this->setEntries($entries);
        $this->loaded = true;

        return $entries;
    }

    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
        $this->opaqueIndex = [];
        $this->logicalIndex = [];

        foreach ($entries as $entry) {
            if ($entry['opaque'] === '') {
                continue;
            }

            $this->opaqueIndex[$entry['opaque']] = $entry['logical'];
            $this->logicalIndex[$entry['logical']] = $entry['opaque'];
        }
    }

    public function entries(): array
    {
        $this->ensureLoaded();

        return $this->entries;
    }

    public function entryForOpaque(string $opaque): ?array
    {
        $this->ensureLoaded();

        return $this->entries[$opaque] ?? null;
    }

    public function entryForLogical(string $logical): ?array
    {
        $this->ensureLoaded();

        $opaque = $this->logicalIndex[$logical] ?? null;

        return $opaque === null ? null : ($this->entries[$opaque] ?? null);
    }

    public function opaqueForLogical(string $logical): ?string
    {
        $this->ensureLoaded();

        return $this->logicalIndex[$logical] ?? null;
    }

    /**
     * Compiled file (public-root-relative) for an opaque id, or null.
     */
    public function fileForOpaque(string $opaque): ?string
    {
        $entry = $this->entryForOpaque($opaque);

        return $entry['file'] ?? null;
    }

    /**
     * Pre-mask compiled filename for a logical entry, or null when the
     * asset was not renamed.
     */
    public function originalForLogical(string $logical): ?string
    {
        $entry = $this->entryForLogical($logical);

        return $entry['original'] ?? null;
    }

    /**
     * Validate the registry both structurally and against the manifest/files.
     * Returns a list of human-readable problems (empty list = valid).
     */
    public function validate(AssetManifest $manifest, bool $checkFiles = true): array
    {
        $this->ensureLoaded();

        $problems = [];

        if (empty($this->entries)) {
            $problems[] = 'Registry contains no assets. Run `php artisan asset-shield:build`.';
        }

        foreach ($this->entries as $entry) {
            if (! OpaqueId::isSafe($entry['file'])) {
                $problems[] = '[file "'.$entry['file'].'"] contains an unsafe path (traversal or absolute).';
            }

            if (MimeMapper::isForbidden($entry['file'])) {
                $problems[] = '[file "'.$entry['file'].'"] points at a forbidden server-side file.';
            }

            if ($checkFiles && $manifest->absolutePath($entry['file']) === null) {
                $problems[] = '[file "'.$entry['file'].'"] does not exist inside the public build directory.';
            }
        }

        return $problems;
    }

    /**
     * Persist the current entries to the registry artifact. Directory is created
     * as needed. Returns the number of entries written.
     */
    public function save(): int
    {
        $dir = dirname($this->registryPath);

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException('AssetShield could not create the registry directory "'.$dir.'".');
        }

        $assets = [];

        foreach ($this->entries as $entry) {
            $asset = [
                'type' => $entry['type'],
                'file' => $entry['file'],
            ];

            if ($entry['original'] !== null) {
                $asset['original'] = $entry['original'];
            }

            if ($entry['integrity'] !== null) {
                $asset['integrity'] = $entry['integrity'];
            }

            $assets[$entry['logical']] = $asset;
        }

        $payload = [
            'version' => 1,
            'built_at' => now()->toIso8601String(),
            'assets' => $assets,
        ];

        if (@file_put_contents($this->registryPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('AssetShield could not write the registry to "'.$this->registryPath.'".');
        }

        return count($this->entries);
    }

    /**
     * Force a reload from disk on next access.
     */
    public function refresh(): void
    {
        $this->loaded = false;
        $this->entries = [];
        $this->opaqueIndex = [];
        $this->logicalIndex = [];
    }

    public function loaded(): bool
    {
        return $this->loaded;
    }

    private function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->load();
        }
    }

    private function normalizeRaw(string $logical, array $raw): array
    {
        $file = (string) ($raw['file'] ?? '');

        if ($logical === '' || $file === '') {
            throw RegistryInvalidException::invalid($this->registryPath, 'registry asset "'.($logical === '' ? '(empty)' : $logical).'" is missing "file".');
        }

        if (! OpaqueId::isSafe($file)) {
            throw RegistryInvalidException::invalid($this->registryPath, 'registry asset "'.$logical.'" has an unsafe file path "'.$file.'".');
        }

        $opaque = (string) ($raw['opaque'] ?? '');
        $expected = OpaqueId::from($file, $this->appKey);

        if ($opaque !== '' && $opaque !== $expected) {
            throw RegistryInvalidException::invalid($this->registryPath, 'registry asset "'.$logical.'" has a stored opaque id that does not match the application key.');
        }

        return [
            'logical' => $logical,
            'file' => OpaqueId::canonicalize($file),
            'original' => isset($raw['original']) && (string) $raw['original'] !== '' ? (string) $raw['original'] : null,
            'opaque' => $expected,
            'type' => (string) ($raw['type'] ?? MimeMapper::family($file)),
            'integrity' => isset($raw['integrity']) ? (string) $raw['integrity'] : null,
        ];
    }
}