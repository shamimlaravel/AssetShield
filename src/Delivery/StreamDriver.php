<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Delivery;

use Shamimstack\AssetShield\AssetIdentity;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetResponse;
use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
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
        private readonly AssetManifest $manifest,
    ) {
    }

    public function deliver(
        AssetIdentity $asset,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response {
        $path = $this->manifest->absolutePath($asset->file());

        if ($path === null || ! is_file($path)) {
            throw new AssetNotFoundException('AssetShield could not locate the compiled asset "'.$asset->file().'".');
        }

        return $this->assetResponse->fromPath(
            $path,
            $asset->contentType() ?? 'application/octet-stream',
            $cacheOverrideSeconds,
            $immutable,
        );
    }

    public function supports(AssetIdentity $asset): bool
    {
        if ($asset->contentType() === null) {
            return false;
        }

        $path = $this->manifest->absolutePath($asset->file());

        return $path !== null && is_file($path);
    }
}