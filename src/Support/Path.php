<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Support;

/**
 * Shared filesystem path helpers used across the package so artifact path
 * resolution stays consistent (drive letters, UNC, POSIX root, ...).
 */
final class Path
{
    /**
     * True for POSIX absolute paths ("/var/..."), Windows drive paths
     * ("C:\...", "C:/...") and UNC roots ("\\server\share\...").
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}