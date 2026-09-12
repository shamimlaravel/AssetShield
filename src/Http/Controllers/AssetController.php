<?php

namespace Vendor\AssetShield\Http\Controllers;

use Illuminate\Http\Request;
use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetRegistry;
use Vendor\AssetShield\Delivery\AssetDeliveryDriver;
use Vendor\AssetShield\Exceptions\InvalidSignatureException;
use Vendor\AssetShield\Signer\AssetSigner;
use Vendor\AssetShield\Support\MimeMapper;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves protected assets.
 *
 *   GET /assets/{opaque-id}[?expires=&signature=]
 *
 * Flow: resolve ID via registry only -> verify signature (when enabled) ->
 * MIME/denylist gate -> delivery driver. Never accepts a filesystem path.
 */
class AssetController
{
    private static bool $sourceMapWarned = false;

    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetSigner $signer,
        private readonly AssetManifest $manifest,
        private readonly AssetDeliveryDriver $driver,
        private readonly bool $signatureEnabled,
        private readonly bool $sourceMapsEnabled,
    ) {
    }

    public function __invoke(Request $request, string $asset): Response
    {
        if (preg_match('/^[a-f0-9]{8,32}$/', $asset) !== 1) {
            abort(404, 'Asset not found.');
        }

        $entry = $this->registry->entryForOpaque($asset);

        if ($entry === null) {
            abort(404, 'Asset not found.');
        }

        if ($this->signatureEnabled) {
            $this->guardSignature($request, $asset);
        }

        $compiled = $entry['compiled'];

        // Deny server-side file types outright (.map, .php, .env, lockfiles...).
        if (MimeMapper::isForbidden($compiled)) {
            abort(403, 'Forbidden.');
        }

        $contentType = MimeMapper::forPath($compiled);

        if ($contentType === null) {
            abort(404, 'Unsupported asset type.');
        }

        if ($this->sourceMapsEnabled && ! self::$sourceMapWarned) {
            self::$sourceMapWarned = true;
            logger()->warning('AssetShield: source maps are enabled (config asset-shield.source_maps). Hosted source maps are never hidden; keep them out of production.');
        }

        if (! $this->driver->supports($this->manifest, $compiled)) {
            abort(404, 'Asset not found.');
        }

        [$cacheSeconds, $immutable] = $this->cacheHints($request);

        return $this->driver->deliver(
            $this->manifest,
            $compiled,
            $contentType,
            $cacheSeconds,
            $immutable,
        );
    }

    private function guardSignature(Request $request, string $asset): void
    {
        $rawExpires = $request->query->get('expires');
        $expires = is_numeric($rawExpires) && (int) $rawExpires > 0 ? (int) $rawExpires : null;
        $signature = (string) $request->query->get('signature', '');

        if (! $this->signer->verify($asset, $expires, $signature)) {
            throw new InvalidSignatureException();
        }
    }

    /**
     * Signed/expiring assets cache for at most their remaining lifetime; never
     * marked immutable. Unsigned or permanently-signed assets are immutable.
     *
     * @return array{0:?int,1:bool}
     */
    private function cacheHints(Request $request): array
    {
        $rawExpires = $request->query->get('expires');

        if (is_numeric($rawExpires) && (int) $rawExpires > 0) {
            $remaining = (int) $rawExpires - time();

            return [$remaining < 0 ? 0 : $remaining, false];
        }

        return [null, true];
    }
}