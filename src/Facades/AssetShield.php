<?php

namespace Vendor\AssetShield\Facades;

use Illuminate\Support\Facades\Facade;

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
 *
 * @see \Vendor\AssetShield\AssetShieldManager
 */
class AssetShield extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'asset-shield';
    }
}