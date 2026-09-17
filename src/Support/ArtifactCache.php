<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * Shared Laravel-cache helper for decoded build artifacts (manifest, registry).
 *
 * Payloads are cached forever and carry the artifact's file mtime, so a rebuilt
 * artifact is re-decoded on the next read without needing a manual purge. The
 * cache is only engaged when the package targets a production environment and
 * the cache switch is on.
 */
final class ArtifactCache
{
    public static function enabled(Application $app): bool
    {
        return (string) $app['config']->get('asset-shield.environment', '') === 'production'
            && (bool) $app['config']->get('asset-shield.cache.enabled', true)
            && $app->bound('cache');
    }

    public static function key(string $namespace, string $path): string
    {
        return 'asset-shield:'.$namespace.':'.hash('crc32b', $path);
    }

    /**
     * Last-modified time of the artifact, or null when it does not exist yet.
     */
    public static function mtime(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime === false ? null : (int) $mtime;
    }

    /**
     * Read a cached payload when its mtime still matches the artifact, else null.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $key, string $path): ?array
    {
        $cached = Cache::get($key);
        $mtime = self::mtime($path);

        if (is_array($cached) && is_array($cached['data'] ?? null) && ($cached['mtime'] ?? null) === $mtime) {
            return $cached['data'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function write(string $key, string $path, array $data): void
    {
        Cache::forever($key, ['mtime' => self::mtime($path), 'data' => $data]);
    }

    public static function forget(string $key): void
    {
        Cache::forget($key);
    }
}