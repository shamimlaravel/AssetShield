<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
use Shamimstack\AssetShield\Exceptions\ManifestNotFoundException;
use Shamimstack\AssetShield\Support\ArtifactCache;
use Shamimstack\AssetShield\Support\OpaqueId;
use Shamimstack\AssetShield\Support\Path;

/**
 * Reads the Laravel/Vite production manifest (public/build/manifest.json),
 * resolves logical entries to their final generated files, and resolves the
 * absolute filesystem path of a compiled asset.
 *
 * Data is loaded lazily and memoized for the worker lifetime so no request
 * re-reads or re-parses the manifest.
 */
class AssetManifest
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;
    private ?string $publicRoot = null;

    /** @var array<string, string|null> memoized absolutePath results */
    private array $absolutePathCache = [];

    public function __construct(
        private readonly string $path,
        private readonly Application $app,
        private readonly ?string $outDir = null,
    ) {
    }

    public static function fromConfig(Application $app): self
    {
        $path = (string) $app['config']->get('asset-shield.build.manifest', 'public/build/manifest.json');

        if (! Path::isAbsolute($path)) {
            $path = $app->basePath($path);
        }

        $outDir = (string) $app['config']->get('asset-shield.build.out_dir', '');

        return new self($path, $app, $outDir === '' ? null : $outDir);
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
     * Absolute path of the directory that contains the manifest.
     */
    public function dir(): string
    {
        return dirname($this->path);
    }

    /**
     * The Laravel public root (normally the application's public/ directory).
     */
    public function publicRoot(): string
    {
        if ($this->publicRoot === null) {
            $this->publicRoot = rtrim((string) $this->app->make('path.public'), '/\\');
        }

        return $this->publicRoot;
    }

    /**
     * Manifest directory expressed relative to the public root, forward slashes.
     * e.g. public/build/manifest.json -> "build".
     */
    public function publicRelDir(): string
    {
        $root = rtrim(str_replace('\\', '/', $this->publicRoot()), '/');
        $dir = rtrim(str_replace('\\', '/', $this->dir()), '/');

        if (str_starts_with($dir, $root.'/')) {
            return substr($dir, strlen($root) + 1);
        }

        return basename($dir);
    }

    /**
     * Raw decoded manifest data (memoized per worker; served from the Laravel
     * cache in production).
     *
     * @return array<string, mixed>
     * @throws ManifestNotFoundException
     */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        return $this->data = $this->readFromCacheOrDisk();
    }

    /**
     * @return array<string, mixed>
     */
    private function readFromCacheOrDisk(): array
    {
        if (! $this->cacheEnabled()) {
            return $this->loadFromDisk();
        }

        $key = static::cacheKey($this->path);
        $cached = ArtifactCache::read($key, $this->path);

        if ($cached !== null) {
            return $cached;
        }

        $fresh = $this->loadFromDisk();

        ArtifactCache::write($key, $this->path, $fresh);

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     * @throws ManifestNotFoundException
     */
    private function loadFromDisk(): array
    {
        if (! $this->exists()) {
            throw ManifestNotFoundException::missing($this->path());
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($decoded)) {
            throw ManifestNotFoundException::invalid($this->path(), json_last_error_msg() ?: 'invalid JSON');
        }

        return $decoded;
    }

    private function cacheEnabled(): bool
    {
        return ArtifactCache::enabled($this->app);
    }

    /**
     * The Laravel-cache key holding the decoded manifest. Keyed by resolved
     * path so different builds/installs never share a payload.
     */
    public static function cacheKey(string $path): string
    {
        return ArtifactCache::key('manifest', $path);
    }

    /**
     * Resolve a logical entry (e.g. "resources/js/app.js") to its manifest
     * record (src, file, isEntry, css, assets, integrity, ...).
     *
     * @throws AssetNotFoundException
     *
     * @return array<string, mixed>
     */
    public function resolve(string $entry): array
    {
        $data = $this->data();

        if (! isset($data[$entry]) || ! is_array($data[$entry])) {
            throw AssetNotFoundException::fromManifest($entry, $this->path());
        }

        return ['logical' => $entry] + $data[$entry];
    }

    /**
     * Convert a manifest-relative file (e.g. "assets/app-A91Kx.js") into the
     * compiled path relative to the public root ("build/assets/app-A91Kx.js").
     * Uses `build.out_dir` when configured, otherwise the manifest directory.
     */
    public function compiledPath(string $manifestFile): string
    {
        if ($this->outDir !== null) {
            $rel = OpaqueId::canonicalize($this->outDir);
            $file = OpaqueId::canonicalize($manifestFile);

            return $rel === '' ? $file : $rel.'/'.$file;
        }

        $rel = $this->publicRelDir();
        $file = OpaqueId::canonicalize($manifestFile);

        return $rel === '' ? $file : $rel.'/'.$file;
    }

    /**
     * Absolute, canonical filesystem path for a compiled path that is relative
     * to the public root. Returns null when the file does not exist or escapes
     * the public root.
     */
    public function absolutePath(string $compiledRelativePath): ?string
    {
        if (array_key_exists($compiledRelativePath, $this->absolutePathCache)) {
            return $this->absolutePathCache[$compiledRelativePath];
        }

        $result = $this->resolveAbsolutePath($compiledRelativePath);

        return $this->absolutePathCache[$compiledRelativePath] = $result;
    }

    private function resolveAbsolutePath(string $compiledRelativePath): ?string
    {
        if (! OpaqueId::isSafe($compiledRelativePath)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $this->publicRoot()), '/');
        $absolute = $root.'/'.str_replace('\\', '/', $compiledRelativePath);
        $real = realpath($absolute);

        if ($real === false) {
            return null;
        }

        $normalizedReal = str_replace('\\', '/', $real);

        // Confine resolution strictly inside the public root.
        if ($normalizedReal !== $root && ! str_starts_with($normalizedReal, $root.'/')) {
            return null;
        }

        return $real;
    }

    /**
     * Drop a manifest from the worker cache (and the production cache, when
     * enabled) forcing a re-read from disk on next access.
     */
    public function refresh(): void
    {
        $this->data = null;
        $this->absolutePathCache = [];

        if ($this->cacheEnabled()) {
            ArtifactCache::forget(static::cacheKey($this->path));
        }
    }
}