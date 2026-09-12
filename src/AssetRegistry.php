<?php

namespace Vendor\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Vendor\AssetShield\Exceptions\AssetNotFoundException;
use Vendor\AssetShield\Exceptions\RegistryInvalidException;
use Vendor\AssetShield\Support\MimeMapper;
use Vendor\AssetShield\Support\OpaqueId;

/**
 * Maps logical assets -> compiled assets -> protected opaque identifiers.
 *
 *   resources/js/app.js
 *           |
 *           v
 *   build/assets/app-A91Kx.js
 *           |
 *           v
 *   7f92a8c1  (HMAC-derived under APP_KEY)
 *
 * The registry is a build artifact. Opaque identifiers are always derived
 * server-side from the application key; neither the Vite plugin nor the client
 * ever sees the real compiled path or the key.
 */
class AssetRegistry
{
    /** @var array<string, array{logical:string,compiled:string,opaque:string,type:string,integrity:?string}> */
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
        $path = (string) $app['config']->get('asset-shield.registry_path', 'storage/asset-shield/registry.json');

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

        if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            throw RegistryInvalidException::invalid($this->registryPath, 'missing "entries" array');
        }

        $entries = [];
        foreach ($decoded['entries'] as $index => $raw) {
            $entry = $this->normalizeRaw((array) $raw, $index);
            $entries[$entry['opaque']] = $entry;
        }

        $this->setEntries($entries);
        $this->loaded = true;
    }

    /**
     * Replace the in-memory registry from a list of entries and materialize the
     * server-side opaque identifiers. Persists unless $persist is false.
     *
     * @param  array<int,array{logical:string,compiled:string,type:?string,integrity:?string}>  $rows
     * @return array<string, array{logical:string,compiled:string,opaque:string,type:string,integrity:?string}>
     */
    public function create(array $rows, bool $persist = true): array
    {
        $entries = [];

        foreach ($rows as $index => $row) {
            $logical = (string) ($row['logical'] ?? '');
            $compiled = (string) ($row['compiled'] ?? '');

            if ($logical === '' || $compiled === '' || ! OpaqueId::isSafe($compiled)) {
                throw new InvalidArgumentException('AssetShield registry entries require a logical key and a safe compiled path (row #'.$index.').');
            }

            $opaque = OpaqueId::from($compiled, $this->appKey);

            if (isset($entries[$opaque])) {
                throw new InvalidArgumentException('AssetShield opaque id collision for "'.$logical.'" and "'.$entries[$opaque]['logical'].'".');
            }

            $entries[$opaque] = [
                'logical' => $logical,
                'compiled' => OpaqueId::canonicalize($compiled),
                'opaque' => $opaque,
                'type' => (string) ($row['type'] ?? MimeMapper::family($compiled)),
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

    public function compiledForOpaque(string $opaque): ?string
    {
        $entry = $this->entryForOpaque($opaque);

        return $entry['compiled'] ?? null;
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
            $problems[] = 'Registry contains no entries. Run `php artisan asset-shield:build`.';
        }

        foreach ($this->entries as $entry) {
            if (! OpaqueId::isSafe($entry['compiled'])) {
                $problems[] = '[compiled "'.$entry['compiled'].'"] contains an unsafe path (traversal or absolute).';
            }

            if (MimeMapper::isForbidden($entry['compiled'])) {
                $problems[] = '[compiled "'.$entry['compiled'].'"] points at a forbidden server-side file.';
            }

            if ($checkFiles && $manifest->absolutePath($entry['compiled']) === null) {
                $problems[] = '[compiled "'.$entry['compiled'].'"] does not exist inside the public build directory.';
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

        $payload = [
            'version' => 1,
            'built_at' => now()->toIso8601String(),
            'entries' => array_values($this->entries),
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

    private function normalizeRaw(array $raw, int $index): array
    {
        $logical = (string) ($raw['logical'] ?? '');
        $compiled = (string) ($raw['compiled'] ?? '');

        if ($logical === '' || $compiled === '') {
            throw RegistryInvalidException::invalid($this->registryPath, 'entry #'.$index.' is missing "logical" or "compiled".');
        }

        if (! OpaqueId::isSafe($compiled)) {
            throw RegistryInvalidException::invalid($this->registryPath, 'entry "'.$logical.'" has an unsafe compiled path "'.$compiled.'".');
        }

        $opaque = (string) ($raw['opaque'] ?? '');
        $expected = OpaqueId::from($compiled, $this->appKey);

        if ($opaque !== '' && $opaque !== $expected) {
            throw RegistryInvalidException::invalid($this->registryPath, 'entry "'.$logical.'" has a stored opaque id that does not match the application key.');
        }

        return [
            'logical' => $logical,
            'compiled' => OpaqueId::canonicalize($compiled),
            'opaque' => $expected,
            'type' => (string) ($raw['type'] ?? MimeMapper::family($compiled)),
            'integrity' => isset($raw['integrity']) ? (string) $raw['integrity'] : null,
        ];
    }
}