<?php

namespace Vendor\AssetShield\Delivery;

use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the resolved file inside the public build directory and streams the
 * contents in a plain HTTP response. Simplest, PHP-whitelist-friendly driver;
 * the default for MVP.
 */
class PublicFileDriver implements AssetDeliveryDriver
{
    public function __construct(
        private readonly AssetResponse $assetResponse,
    ) {
    }

    public function deliver(
        AssetManifest $manifest,
        string $compiledRelativePath,
        string $contentType,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response {
        $path = $manifest->absolutePath($compiledRelativePath);

        if ($path === null || ! is_file($path)) {
            throw new \Vendor\AssetShield\Exceptions\AssetNotFoundException(
                'AssetShield could not locate the compiled asset "'.$compiledRelativePath.'".'
            );
        }

        return $this->assetResponse->fromContents(
            (string) file_get_contents($path),
            $contentType,
            $cacheOverrideSeconds,
            $immutable,
        );
    }

    public function supports(AssetManifest $manifest, string $compiledRelativePath): bool
    {
        $path = $manifest->absolutePath($compiledRelativePath);

        return $path !== null && is_file($path);
    }
}