<?php

namespace Vendor\AssetShield\Exceptions;

use RuntimeException;

/**
 * Thrown when a logical asset entry cannot be resolved against the Vite
 * manifest or the protected registry.
 */
class AssetNotFoundException extends RuntimeException
{
    public static function fromManifest(string $entry, string $manifestPath): self
    {
        return new static(
            sprintf('AssetShield could not resolve "%s" in the Vite manifest at "%s".', $entry, $manifestPath)
        );
    }

    public static function fromRegistry(string $opaque): self
    {
        return new static(
            sprintf('AssetShield could not resolve the protected asset id "%s" in the registry.', $opaque)
        );
    }

    public static function fromRegistryEntry(string $logical): self
    {
        return new static(
            sprintf('AssetShield could not resolve "%s" in the protected asset registry. Run `php artisan asset-shield:build`.', $logical)
        );
    }
}