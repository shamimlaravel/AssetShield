<?php

namespace Vendor\AssetShield;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Builds HTTP responses for protected assets with correct MIME types,
 * nosniff, and a cache policy:
 *
 *  - unsigned immutable assets: "public, max-age=31536000, immutable"
 *  - signed/expiring assets:    "public, max-age={remaining}" (clamped to the
 *                               configured max-age, never marked immutable)
 */
class AssetResponse
{
    public function __construct(
        private readonly bool $cacheEnabled = true,
        private readonly int $cacheMaxAge = 31536000,
    ) {
    }

    /**
     * In-memory content response (used by PublicFileDriver).
     */
    public function fromContents(
        string $contents,
        string $contentType,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): Response {
        $response = new Response($contents, 200);
        $response->header('ETag', '"'.md5($contents).'"');

        $this->decorate($response, $contentType, $cacheOverrideSeconds, $immutable);

        return $response;
    }

    /**
     * Streamed file response (used by StreamDriver) with Range/conditional
     * support provided by Symfony's BinaryFileResponse.
     */
    public function fromPath(
        string $path,
        string $contentType,
        ?int $cacheOverrideSeconds = null,
        bool $immutable = true,
    ): BinaryFileResponse {
        $response = new BinaryFileResponse($path, 200, headers: [], public: true, contentDisposition: 'inline');

        $size = @filesize($path);
        $mtime = @filemtime($path);
        $response->headers->set('ETag', '"'.md5((string) $size.'-'.(string) $mtime).'"');

        $this->decorate($response, $contentType, $cacheOverrideSeconds, $immutable);

        return $response;
    }

    private function decorate($response, string $contentType, ?int $cacheOverrideSeconds, bool $immutable): void
    {
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($this->cacheEnabled) {
            $maxAge = $cacheOverrideSeconds === null
                ? $this->cacheMaxAge
                : min($this->cacheMaxAge, max(0, (int) $cacheOverrideSeconds));

            $directive = 'public, max-age='.$maxAge;

            // Never mark an expiring asset immutable: the browser must be able
            // to revalidate before the signature lifetime runs out.
            if ($immutable && $cacheOverrideSeconds === null) {
                $directive .= ', immutable';
            }

            $response->headers->set('Cache-Control', $directive);
        } else {
            $response->headers->set('Cache-Control', 'no-cache');
        }
    }
}