<?php

namespace Shamimstack\AssetShield\Delivery;

use Shamimstack\AssetShield\AssetIdentity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abstraction over asset byte transportation. Implementations MUST NOT execute
 * the file and MUST NOT accept arbitrary input paths — they only ever receive
 * a registry-validated AssetIdentity plus cache hints.
 *
 * MVP drivers: PublicDriver, StreamDriver.
 * Future drivers (reserved, not implemented): Nginx X-Accel-Redirect,
 * Apache X-Sendfile, S3, Cloudflare R2, generic CDN.
 */
interface AssetDeliveryDriver
{
    /**
     * Deliver the bytes of a validated, registered asset.
     *
     * @param  int|null  $cacheOverrideSeconds  remaining signature lifetime.
     * @param  bool  $immutable  whether this is an unsigned immutable asset.
     */
    public function deliver(
        AssetIdentity $asset,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response;

    /**
     * Whether this driver can serve the given validated asset.
     */
    public function supports(AssetIdentity $asset): bool;
}