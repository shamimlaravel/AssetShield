<?php

namespace Shamimstack\AssetShield;

use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
use Shamimstack\AssetShield\Signer\AssetSigner;

/**
 * Produces protected asset URLs.
 *
 *   AssetShield::url('resources/js/app.js')  =>  /assets/7f92a8c1?expires=...&signature=...
 *
 * The real compiled filename never appears in the URL.
 */
class AssetUrlGenerator
{
    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetSigner $signer,
        private readonly string $routePrefix,
        private readonly bool $signatureEnabled,
        private readonly int $defaultExpires,
    ) {
    }

    /**
     * Protected URL for a logical entry.
     *
     * @param  bool|null  $signed   null = follow config, true = force signed,
     *                              false = force unsigned.
     * @param  int|null   $expires  absolute Unix timestamp; null uses the
     *                              configured default lifetime.
     */
    public function url(string $logical, ?bool $signed = null, ?int $expires = null): string
    {
        $entry = $this->registry->entryForLogical($logical);

        if ($entry === null) {
            throw AssetNotFoundException::fromRegistryEntry($logical);
        }

        return $this->urlForOpaque($entry['opaque'], $signed, $expires);
    }

    /**
     * Protected URL for an opaque identifier (covers manual/signed flows).
     */
    public function urlForOpaque(string $opaque, ?bool $signed = null, ?int $expires = null): string
    {
        $base = '/'.trim($this->routePrefix, '/').'/'.$opaque;

        $needsSignature = $signed ?? $this->signatureEnabled;

        if (! $needsSignature) {
            return $base;
        }

        $expiresAt = $expires ?? (time() + $this->defaultExpires);

        return $base.'?expires='.$expiresAt.'&signature='.$this->signer->sign($opaque, $expiresAt);
    }
}