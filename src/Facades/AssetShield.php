<?php

namespace Shamimstack\AssetShield\Facades;

use Illuminate\Support\Facades\Facade;
use Shamimstack\AssetShield\Security\Csp;

/**
 * @method static string url(string $entry, ?bool $signed = null, ?int $expires = null)
 * @method static string script(string $entry)
 * @method static string style(string $entry)
 * @method static string render(string $entry)
 * @method static string renderVite(iterable $entries)
 * @method static array  resolve(string $entry)
 * @method static string sign(string $assetId, ?int $expires = null)
 * @method static array  status()
 * @method static bool   isEnabled()
 * @method static bool   runtimeEnabled()
 * @method static string environment()
 *
 * @see \Shamimstack\AssetShield\AssetShieldManager
 */
class AssetShield extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'asset-shield';
    }

    /**
     * Per-request CSP nonce for inline script/style attributes in your own
     * markup. A nonce is only meaningful when the same nonce is present in the
     * Content-Security-Policy header emitted for the page.
     */
    public static function cspNonce(): string
    {
        return app(Csp::class)->nonce();
    }

    /**
     * The page-level Content-Security-Policy header value (call this from a
     * middleware to attach the header to HTML responses).
     */
    public static function cspHeader(): string
    {
        return app(Csp::class)->header();
    }
}