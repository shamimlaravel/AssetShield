<?php

namespace Vendor\AssetShield\Exceptions;

use RuntimeException;

/**
 * Thrown when the Laravel/Vite production manifest cannot be found or parsed.
 */
class ManifestNotFoundException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new static(
            sprintf('AssetShield could not find the Vite manifest at "%s". Run `npm run build` first.', $path)
        );
    }

    public static function invalid(string $path, string $reason): self
    {
        return new static(
            sprintf('AssetShield could not parse the Vite manifest at "%s": %s', $path, $reason)
        );
    }
}