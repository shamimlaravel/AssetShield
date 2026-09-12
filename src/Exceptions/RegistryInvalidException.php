<?php

namespace Vendor\AssetShield\Exceptions;

use RuntimeException;

/**
 * Thrown when the AssetShield registry artifact is missing or structurally
 * invalid (absolute paths, traversal, missing fields).
 */
class RegistryInvalidException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new static(
            sprintf('AssetShield could not find its registry at "%s". Run `php artisan asset-shield:build`.', $path)
        );
    }

    public static function invalid(string $path, string $reason): self
    {
        return new static(
            sprintf('AssetShield detected an invalid registry at "%s": %s', $path, $reason)
        );
    }
}