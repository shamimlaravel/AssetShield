<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Http\Controllers;

use Illuminate\Http\Request;
use Shamimstack\AssetShield\AssetIdentity;
use Shamimstack\AssetShield\AssetResolver;
use Shamimstack\AssetShield\Delivery\AssetDeliveryDriver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves protected assets.
 *
 *   GET /assets/{opaque-id}[?expires=&signature=]
 *
 * Signature / 404 gating is performed by VerifyAssetSignature middleware
 * (opaque regex -> registry -> signature). This controller finishes the job:
 * MIME/denylist safety gate -> cache hints -> delivery driver. Never accepts a
 * filesystem path.
 */
class AssetController
{
    private static bool $sourceMapWarned = false;

    public function __construct(
        private readonly AssetResolver $resolver,
        private readonly AssetDeliveryDriver $driver,
        private readonly bool $signedUrls,
        private readonly bool $sourceMapsEnabled,
    ) {
    }

    public function __invoke(Request $request, string $asset): Response
    {
        $identity = $request->attributes->get('asset-shield.identity');

        if (! $identity instanceof AssetIdentity) {
            $identity = $this->resolver->resolveOpaque($asset);
        }

        if ($identity === null) {
            abort(404, 'Asset not found.');
        }

        // Deny server-side file types outright (.map, .php, .env, lockfiles...).
        if ($identity->contentType() === null) {
            abort(403, 'Forbidden.');
        }

        if ($this->sourceMapsEnabled && ! self::$sourceMapWarned) {
            self::$sourceMapWarned = true;
            logger()->warning('AssetShield: source maps are enabled (config asset-shield.build.source_maps). Hosted source maps are never hidden; keep them out of production.');
        }

        if (! $this->driver->supports($identity)) {
            abort(404, 'Asset not found.');
        }

        [$cacheSeconds, $immutable] = $this->cacheHints($request);

        return $this->driver->deliver($identity, $cacheSeconds, $immutable);
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

        if ($this->signedUrls && is_numeric($rawExpires) && (int) $rawExpires > 0) {
            $remaining = (int) $rawExpires - time();

            return [$remaining < 0 ? 0 : $remaining, false];
        }

        return [null, true];
    }
}