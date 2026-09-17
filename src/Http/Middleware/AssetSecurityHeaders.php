<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Shamimstack\AssetShield\Security\Csp;

/**
 * Security headers applied to every protected asset response.
 *
 * Correct Content-Type and X-Content-Type-Options: nosniff are already set by
 * AssetResponse for successful deliveries; this middleware guarantees nosniff
 * even for rejection responses (403/404) that pass through the route.
 *
 * When `asset-shield.security.csp` is enabled an explicit, strict policy for
 * binary content accompanies every response, including rejections.
 */
class AssetSecurityHeaders
{
    private readonly bool $cspEnabled;

    public function __construct(private readonly Csp $csp)
    {
        $this->cspEnabled = (bool) config('asset-shield.security.csp', false);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->cspEnabled) {
            foreach ($this->csp->headersForAsset() as $name => $value) {
                $response->headers->set($name, $value);
            }
        } else {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}