<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Support;

/**
 * Deterministic, key-bound opaque identifiers.
 *
 * Derivation:
 *   opaqueId = "as_" . substr( hex( HMAC-SHA256( APP_KEY, "asset-shield:" . canonicalPath ) ), 0, 16 )
 *
 * Deterministic for the same compiled file + application key, so URLs remain
 * stable between identical deploys yet never reveal the real filesystem path,
 * and never use sequential integers.
 *
 * The `as_` prefix distinguishes AssetShield opaque IDs from arbitrary route
 * segments and from FNV-based mask names, which are presentational only.
 */
final class OpaqueId
{
    public const LENGTH = 8; // bytes -> 16 hex characters

    public const PREFIX = 'as_';

    /**
     * @return string "as_" + 16-character lowercase hex string.
     */
    public static function from(string $compiledRelativePath, string $appKey): string
    {
        $canonical = self::canonicalize($compiledRelativePath);

        if ($appKey === '' || strtolower($appKey) === 'null' || $canonical === '') {
            throw new \InvalidArgumentException('AssetShield requires a valid application key and a compilable path to derive an opaque id.');
        }

        $digest = hash_hmac('sha256', 'asset-shield:'.$canonical, $appKey);

        return self::PREFIX.substr($digest, 0, self::LENGTH * 2);
    }

    /**
     * Normalize a compiled relative path: forward slashes, no leading slash,
     * no `.` / `..` segments (canonicalized), no double slashes.
     */
    public static function canonicalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        $parts = explode('/', $path);
        $out = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($out);
                continue;
            }

            $out[] = $part;
        }

        return implode('/', $out);
    }

    /**
     * True when the path is safe for registry storage: relative, no traversal,
     * no absolute segments, no drive letters.
     */
    public static function isSafe(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($normalized, '/') || (bool) preg_match('/^[A-Za-z]:/', $normalized)) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '') {
                return false;
            }
        }

        return true;
    }
}