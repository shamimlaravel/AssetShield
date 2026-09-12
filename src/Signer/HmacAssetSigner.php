<?php

namespace Vendor\AssetShield\Signer;

/**
 * HMAC-SHA256 signature engine for protected asset URLs.
 *
 *   signature = hex( HMAC-SHA256( secret, "asset-shield-sign:" . assetId . ":" . (expires ?? 0) ) )
 *
 * Verification is constant-time (hash_equals) and rejects expired timestamps
 * regardless of signature validity.
 */
class HmacAssetSigner implements AssetSigner
{
    private const CONTEXT = 'asset-shield-sign:';

    public function __construct(
        private readonly string $secret,
        private readonly int $defaultExpires = 300,
        private readonly int $leeway = 5,
    ) {
    }

    public function sign(string $assetId, ?int $expires = null): string
    {
        return $this->signature($assetId, $expires ?? 0);
    }

    public function verify(string $assetId, ?int $expires, string $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        if ($expires !== null && ($expires <= 0 || $expires > 4102444800)) {
            return false;
        }

        $expected = $this->signature($assetId, $expires ?? 0);

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        if ($expires !== null && $expires < time() - $this->leeway) {
            return false;
        }

        return true;
    }

    public function defaultExpires(): int
    {
        return $this->defaultExpires;
    }

    private function signature(string $assetId, int $expires): string
    {
        if ($secret = trim($this->secret)) {
            return hash_hmac('sha256', self::CONTEXT.':'.$assetId.':'.$expires, $secret);
        }

        // Never sign with an empty/placeholder secret; fail closed.
        throw new \RuntimeException('AssetShield requires a real APP_KEY to sign asset URLs.');
    }
}