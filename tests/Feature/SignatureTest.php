<?php

use Vendor\AssetShield\Signer\AssetSigner;

it('serves an asset with a valid signature', function () {
    $opaque = opaqueFor('resources/js/app.js');

    $this->get(signedUrl($opaque))->assertOk();
});

it('rejects requests without signature and expiry', function () {
    $this->get('/assets/'.opaqueFor('resources/js/app.js'))->assertForbidden();
});

it('rejects an invalid signature', function () {
    $opaque = opaqueFor('resources/js/app.js');
    $expires = time() + 300;

    $this->get('/assets/'.$opaque.'?expires='.$expires.'&signature=deadbeef')->assertForbidden();
});

it('rejects an expired signature', function () {
    $opaque = opaqueFor('resources/js/app.js');
    $expires = time() - 60;
    $signature = app(AssetSigner::class)->sign($opaque, $expires);

    $this->get('/assets/'.$opaque.'?expires='.$expires.'&signature='.$signature)->assertForbidden();
});

it('rejects a signature that does not match the requested expiry', function () {
    $opaque = opaqueFor('resources/js/app.js');
    $signedFor = time() + 300;
    $signature = app(AssetSigner::class)->sign($opaque, $signedFor);

    $this->get('/assets/'.$opaque.'?expires='.($signedFor + 1).'&signature='.$signature)->assertForbidden();
});

it('rejects a signature minted for a different asset', function () {
    $opaque = opaqueFor('resources/js/app.js');
    $other = opaqueFor('resources/css/app.css');
    $expires = time() + 300;
    $signature = app(AssetSigner::class)->sign($other, $expires);

    $this->get('/assets/'.$opaque.'?expires='.$expires.'&signature='.$signature)->assertForbidden();
});

it('swallows the invalid signature with a generic 403 and nosniff', function () {
    $this->get('/assets/'.opaqueFor('resources/js/app.js'))
        ->assertForbidden()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});