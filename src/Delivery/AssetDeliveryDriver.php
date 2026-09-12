<?php

namespace Vendor\AssetShield\Delivery;

use Vendor\AssetShield\AssetManifest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abstraction over asset byte transportation. Implementations MUST NOT execute
 * the file and MUST NOT accept arbitrary input paths — they only receive a
 * manifest, a registry-validated relative compiled path, and response hints.
 *
 * MVP drivers: PublicFileDriver, StreamDriver.
 * Future drivers (reserved, not implemented): Nginx X-Accel-Redirect,
 * Apache X-Sendfile, S3, Cloudflare R2, generic CDN.
 */
interface AssetDeliveryDriver
{
    /**
     * Deliver the bytes of a validated, registered asset.
     *
     * @param  string  $contentType   MIME type chosen by the controller.
     * @param  int|null  $cacheOverrideSeconds  remaining signature lifetime.
     * @param  bool  $immutable      whether this is an unsigned immutable asset.
     */
    public function deliver(
        AssetManifest $manifest,
        string $compiledRelativePath,
        string $contentType,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response;

    /**
     * Whether this driver can serve the given compiled relative path.
     */
    public function supports(AssetManifest $manifest, string $compiledRelativePath): bool;
}