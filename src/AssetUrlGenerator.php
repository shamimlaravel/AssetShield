<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield;

use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
use Shamimstack\AssetShield\Signer\AssetSigner;

/**
 * Produces protected asset URLs.
 *
 *   AssetShield::url('resources/js/app.js')  =>  /assets/as_2ae34e8b0c462491?expires=...&signature=...
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
     *                              false = attempt unsigned. When URL signing is
     *                              enforced by config, an unsigned request is
     *                              overridden and a signed URL is emitted; the
     *                              global `runtime.signed_urls=false` is the
     *                              escape hatch for plain public URLs.
     * @param  int|\DateTimeInterface|null  $expires  absolute Unix timestamp
     *                              or a DateTimeInterface; null uses the
     *                              configured default lifetime.
     */
    public function url(string $logical, ?bool $signed = null, int|\DateTimeInterface|null $expires = null): string
    {
        $entry = $this->registry->entryForLogical($logical);

        if ($entry === null) {
            throw AssetNotFoundException::fromRegistryEntry($logical);
        }

        return $this->urlForOpaque($entry['opaque'], $signed, $expires);
    }

    /**
     * Protected URL for an opaque identifier (covers manual/signed flows).
     *
     * @param  bool|null  $signed   null = follow config, true = force signed,
     *                              false = attempt unsigned (config wins when
     *                              signing is enforced).
     * @param  int|\DateTimeInterface|null  $expires  absolute Unix timestamp
     *                              or a DateTimeInterface; null uses the
     *                              configured default lifetime.
     */
    public function urlForOpaque(string $opaque, ?bool $signed = null, int|\DateTimeInterface|null $expires = null): string
    {
        $base = '/'.trim($this->routePrefix, '/').'/'.$opaque;

        // Config wins: when the deployment requires signed URLs, an explicit
        // `signed: false` cannot void the signature that the delivery
        // middleware verifies — emit a signed URL silently instead.
        $needsSignature = $this->signatureEnabled || ($signed ?? false);

        if (! $needsSignature) {
            return $base;
        }

        $expiresAt = $expires instanceof \DateTimeInterface
            ? $expires->getTimestamp()
            : ($expires ?? (time() + $this->defaultExpires));

        return $base.'?expires='.$expiresAt.'&signature='.$this->signer->sign($opaque, $expiresAt);
    }
}