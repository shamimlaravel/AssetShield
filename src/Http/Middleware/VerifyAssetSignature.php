<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Shamimstack\AssetShield\AssetResolver;
use Shamimstack\AssetShield\Exceptions\InvalidSignatureException;
use Shamimstack\AssetShield\Signer\AssetSigner;

/**
 * Gate for the protected asset route.
 *
 *   GET /{route_prefix}/{opaque-id}[?expires=&signature=]
 *
 * Runs BEFORE the controller so the 404/403 decision is made centrally:
 *   1. opaque segment must match the `as_` pattern (else 404) — a filesystem
 *      path can never reach the route.
 *   2. the opaque id must exist in the registry (else 404, avoiding oracles).
 *   3. when signed URLs are enabled, the HMAC signature must verify (else 403).
 *
 * The resolved AssetIdentity is passed to the controller via request attributes
 * so resolution happens exactly once.
 */
class VerifyAssetSignature
{
    private const OPAQUE_PATTERN = '/^as_[a-f0-9]{8,64}$/';

    public function __construct(
        private readonly AssetResolver $resolver,
        private readonly AssetSigner $signer,
        private readonly bool $signedUrls,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $signedOverride = null): Response
    {
        $assetSegment = (string) $request->route('asset');

        if (preg_match(self::OPAQUE_PATTERN, $assetSegment) !== 1) {
            abort(404, 'Asset not found.');
        }

        $identity = $this->resolver->resolveOpaque($assetSegment);

        if ($identity === null) {
            abort(404, 'Asset not found.');
        }

        $signed = $signedOverride !== null ? filter_var($signedOverride, FILTER_VALIDATE_BOOL) : $this->signedUrls;

        if ($signed) {
            $expires = is_numeric($request->query->get('expires')) && (int) $request->query->get('expires') > 0
                ? (int) $request->query->get('expires')
                : null;
            $signature = (string) $request->query->get('signature', '');

            if (! $this->signer->verify($identity->opaque(), $expires, $signature)) {
                throw new InvalidSignatureException();
            }
        }

        $request->attributes->set('asset-shield.identity', $identity);

        return $next($request);
    }
}