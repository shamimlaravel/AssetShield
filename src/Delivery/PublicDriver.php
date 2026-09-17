<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Delivery;

use Shamimstack\AssetShield\AssetIdentity;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetResponse;
use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the resolved file inside the public build directory and streams the
 * contents in a plain HTTP response. Simplest, PHP-whitelist-friendly, and the
 * default when runtime delivery is enabled. When runtime delivery is disabled
 * the web server itself serves these files at full speed.
 */
class PublicDriver implements AssetDeliveryDriver
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

        return $this->assetResponse->fromContents(
            (string) file_get_contents($path),
            $asset->contentType() ?? 'application/octet-stream',
            $cacheOverrideSeconds,
            $immutable,
            $path,
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