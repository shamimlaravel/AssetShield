<?php

namespace Vendor\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use Vendor\AssetShield\Exceptions\AssetNotFoundException;
use Vendor\AssetShield\Exceptions\ManifestNotFoundException;
use Vendor\AssetShield\Support\OpaqueId;

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
    private ?array $data = null;
    private ?string $publicRoot = null;

    public function __construct(
        private readonly string $path,
        private readonly Application $app,
    ) {
    }

    public static function fromConfig(Application $app): self
    {
        $path = (string) $app['config']->get('asset-shield.manifest_path', 'public/build/manifest.json');

        if (! self::isAbsolute($path)) {
            $path = $app->basePath($path);
        }

        return new self($path, $app);
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
     * Raw decoded manifest data (memoized per worker).
     *
     * @throws ManifestNotFoundException
     */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (! $this->exists()) {
            throw ManifestNotFoundException::missing($this->path());
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($decoded)) {
            throw ManifestNotFoundException::invalid($this->path(), json_last_error_msg() ?: 'invalid JSON');
        }

        return $this->data = $decoded;
    }

    /**
     * Resolve a logical entry (e.g. "resources/js/app.js") to its manifest
     * record (src, file, isEntry, css, assets, integrity, ...).
     *
     * @throws AssetNotFoundException
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
     */
    public function compiledPath(string $manifestFile): string
    {
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
     * Drop a manifest from the worker cache forcing a re-read.
     */
    public function refresh(): void
    {
        $this->data = null;
    }

    private static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return true;
        }

        return false;
    }
}