<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Signer;

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
        private readonly int $leeway = 5,
    ) {
    }

    public function sign(string $assetId, int|\DateTimeInterface|null $expires = null): string
    {
        return $this->signature($assetId, $this->resolveExpires($expires));
    }

    public function verify(string $assetId, ?int $expires, string $signature): bool
    {
        if ($signature === '') {
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

    /**
     * @param  int|\DateTimeInterface|null  $expires
     */
    private function resolveExpires(int|\DateTimeInterface|null $expires): int
    {
        return match (true) {
            $expires instanceof \DateTimeInterface => $expires->getTimestamp(),
            $expires === null => 0,
            default => $expires,
        };
    }

    private function signature(string $assetId, int $expires): string
    {
        $secret = trim($this->secret);

        // Never sign with an empty/placeholder secret; fail closed. The literal
        // "null" (unset APP_KEY serialized) is rejected too, matching the opaque
        // id derivation, so a misconfigured key can never be used to mint URLs.
        if ($secret === '' || strtolower($secret) === 'null') {
            throw new \RuntimeException('AssetShield requires a real APP_KEY to sign asset URLs.');
        }

        return hash_hmac('sha256', self::CONTEXT.':'.$assetId.':'.$expires, $secret);
    }
}