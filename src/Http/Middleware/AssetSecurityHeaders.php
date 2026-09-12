<?php

namespace Vendor\AssetShield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers applied to every protected asset response.
 *
 * Correct Content-Type and X-Content-Type-Options: nosniff are already set by
 * AssetResponse for successful deliveries; this middleware guarantees nosniff
 * even for rejection responses (403/404) that pass through the route.
 */
class AssetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}