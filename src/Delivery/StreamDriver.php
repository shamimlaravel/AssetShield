<?php

namespace Vendor\AssetShield\Delivery;

use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the resolved file via Symfony's BinaryFileResponse, which provides
 * Range and conditional (ETag/Last-Modified) support out of the box. Preferred
 * for larger media files or future CDN origination.
 */
class StreamDriver implements AssetDeliveryDriver
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

        return $this->assetResponse->fromPath(
            $path,
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