<?php

namespace Shamimstack\AssetShield\Masking;

/**
 * Reads the legend artifact written by the Vite plugin.
 *
 * The legend records the { pre-mask -> masked } filename mapping for
 * every renamed build output, keyed by the pre-mask (outDir-relative)
 * file path — exactly the value the manifest would have contained without
 * mask:
 *
 *   { "version": 1, "built_at": "...", "seed": "...",
 *     "entries": {
 *       "assets/a8bc3d21.js": { "original": "assets/a8bc3d21.js",
 *                                "masked": "assets/42623f48.js" }
 *     } }
 *
 * It MUST live outside public/ (default: storage/app/asset-shield/legend.json),
 * is never routed and never served.
 */
class Legend
{
    /** @var array<string, array{original:string, masked:string}> */
    private array $entries = [];

    /** @var array<string, string> masked -> key (inverted lookup) */
    private array $byMasked = [];

    private bool $loaded = false;

    private bool $exists = false;

    public function __construct(private readonly string $path)
    {
    }

    public static function fromConfig(\Illuminate\Contracts\Foundation\Application $app): self
    {
        $path = (string) $app['config']->get('asset-shield.mask.legend', 'app/asset-shield/legend.json');

        if ($path === '' || preg_match('/^[A-Za-z]:[\\\\\/]|^\//', $path) !== 1) {
            $path = $app->storagePath($path);
        }

        return new self((string) $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array{original:string, masked:string}|null
     */
    public function entry(string $key): ?array
    {
        $this->ensureLoaded();

        return $this->entries[$key] ?? null;
    }

    /**
     * Legend row whose masked path matches the given manifest file, or null.
     *
     * @return array{original:string, masked:string}|null
     */
    public function entryForMasked(string $masked): ?array
    {
        $this->ensureLoaded();

        $key = $this->byMasked[(string) $masked] ?? null;

        return $key === null ? null : ($this->entries[$key] ?? null);
    }

    /**
     * Pre-mask (outDir-relative) file for a masked manifest file, or null.
     */
    public function originalForMasked(string $masked): ?string
    {
        return $this->entryForMasked($masked)['original'] ?? null;
    }

    /** @return array<string, array{original:string, masked:string}> */
    public function entries(): array
    {
        $this->ensureLoaded();

        return $this->entries;
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->exists = is_file($this->path);

        if (! $this->exists) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
            foreach ($decoded['entries'] as $key => $row) {
                if (! is_array($row) || ! isset($row['original']) || ! isset($row['masked'])) {
                    continue;
                }

                $this->entries[(string) $key] = [
                    'original' => (string) $row['original'],
                    'masked' => (string) $row['masked'],
                ];
                $this->byMasked[(string) $row['masked']] = (string) $key;
            }
        }
    }
}