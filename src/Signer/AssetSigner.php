<?php

namespace Vendor\AssetShield\Signer;

/**
 * Contract for HMAC-based signing and verification of protected asset URLs.
 *
 * Signatures are capability tokens, not secrets: anyone in possession of a
 * valid, non-expired signature may use the URL it protects.
 */
interface AssetSigner
{
    /**
     * Produce a signature for an asset identifier.
     *
     * When $expires is null the signature covers a non-expiring URL (the
     * controller still requires a valid signature when signing is enabled).
     */
    public function sign(string $assetId, ?int $expires = null): string;

    /**
     * Verify a signature for an identifier; false when malformed, expired, or
     * mismatched. Always returns false (never throws) for the controller path.
     */
    public function verify(string $assetId, ?int $expires, string $signature): bool;
}