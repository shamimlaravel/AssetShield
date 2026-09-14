<?php

use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Signer\AssetSigner;

function opaqueFor(string $logical): string
{
    return app(AssetRegistry::class)->opaqueForLogical($logical) ?? '';
}

function signedUrl(string $opaque, ?int $expires = null, ?string $signature = null): string
{
    $expires ??= time() + 300;
    $signature ??= app(AssetSigner::class)->sign($opaque, $expires);

    return '/assets/'.$opaque.'?expires='.$expires.'&signature='.$signature;
}