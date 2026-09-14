<?php

it('serves a protected asset with the correct content and headers', function () {
    $opaque = opaqueFor('resources/js/app.js');
    $url = signedUrl($opaque);

    $this->get($url)
        ->assertOk()
        ->assertHeaderContains('Content-Type', 'text/javascript')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control')
        ->assertSee('asset-shield fixture', false);
});

it('serves css, svg and woff2 assets with correct MIME types', function () {
    $this->get(signedUrl(opaqueFor('resources/css/app.css')))->assertHeaderContains('Content-Type', 'text/css');
    $this->get(signedUrl(opaqueFor('resources/images/logo.svg')))->assertHeaderContains('Content-Type', 'image/svg+xml');
    $this->get(signedUrl(opaqueFor('resources/fonts/din.woff2')))->assertHeaderContains('Content-Type', 'font/woff2');
});

it('emits an explicit strict CSP for asset responses when security.csp is enabled', function () {
    config()->set('asset-shield.security.csp', true);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $this->get(signedUrl(opaqueFor('resources/js/app.js')))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderContains('Content-Security-Policy', "default-src 'none'")
        ->assertHeaderContains('Content-Security-Policy', "frame-ancestors 'none'")
        ->assertHeader('Content-Security-Policy')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('returns 404 for unknown or malformed opaque ids', function () {
    $this->get('/assets/'.str_repeat('a', 16))->assertNotFound();
    $this->get('/assets/xyz')->assertNotFound();
    $this->get('/assets/a'.str_repeat('f', 15))->assertNotFound();
});

it('never reveals asset existence through the signature gate', function () {
    $expires = time() + 300;
    $signature = app(\Shamimstack\AssetShield\Signer\AssetSigner::class)->sign(opaqueFor('resources/js/app.js'), $expires);

    // A valid signature for a DIFFERENT (unknown) opaque id must not leak 403.
    $this->get('/assets/as_'.str_repeat('a', 16).'?expires='.$expires.'&signature='.$signature)->assertNotFound();
});

it('returns 404 for path traversal attempts', function () {
    $this->get('/assets/../../.env')->assertNotFound();
    $this->get('/assets/%2e%2e/.env')->assertNotFound();
    $this->get('/assets?file=../../.env')->assertNotFound();
});

it('never serves the .env even when registered', function () {
    $registry = app(\Shamimstack\AssetShield\AssetRegistry::class);
    $rows = [
        ['logical' => 'secret', 'file' => 'build/.env', 'type' => 'unknown'],
    ];
    $entries = $registry->create($rows);
    $opaque = array_key_first($entries);

    $this->get(signedUrl($opaque))->assertForbidden();
});

it('never serves server-side files like php or source maps', function () {
    $registry = app(\Shamimstack\AssetShield\AssetRegistry::class);
    $rows = [
        ['logical' => 'php', 'file' => 'build/shell.php', 'type' => 'php'],
        ['logical' => 'map', 'file' => 'build/assets/app.js.map', 'type' => 'map'],
    ];
    $entries = $registry->create($rows);

    foreach (array_keys($entries) as $opaque) {
        $this->get(signedUrl($opaque))->assertForbidden();
    }
});

it('returns 404 when the compiled file is missing', function () {
    $registry = app(\Shamimstack\AssetShield\AssetRegistry::class);
    $rows = [
        ['logical' => 'ghost', 'file' => 'build/assets/ghost-missing.js', 'type' => 'script'],
    ];
    $entries = $registry->create($rows);
    $opaque = array_key_first($entries);

    $this->get(signedUrl($opaque))->assertNotFound();
});

it('returns 404 in disabled mode or with runtime delivery off', function () {
    config()->set('asset-shield.enabled', false);
    config()->set('asset-shield.runtime.signed_urls', false);
    $this->reloadAssetShield();

    $this->get('/assets/'.str_repeat('a', 16))->assertNotFound();
});

it('caches unsigned assets as immutable for one year', function () {
    config()->set('asset-shield.runtime.signed_urls', false);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $opaque = app(\Shamimstack\AssetShield\AssetRegistry::class)->opaqueForLogical('resources/js/app.js');

    $this->get('/assets/'.$opaque)
        ->assertHeader('Cache-Control')
        ->assertHeaderContains('Cache-Control', 'max-age=31536000')
        ->assertHeaderContains('Cache-Control', 'immutable');
});

it('never marks signed assets immutable', function () {
    $cacheControl = $this->get(signedUrl(opaqueFor('resources/js/app.js')))
        ->headers
        ->get('Cache-Control');

    expect($cacheControl)->toContain('max-age=')
        ->and($cacheControl)->not->toContain('immutable')
        ->and($cacheControl)->not->toContain('no-cache');
});

it('limits the cache lifetime of a signed asset to its remaining seconds', function () {
    $expires = time() + 60;
    $response = $this->get(signedUrl(opaqueFor('resources/js/app.js'), $expires));

    expect((int) preg_replace('~.*max-age=(\d+).*~', '$1', $response->headers->get('Cache-Control')))
        ->toBeLessThanOrEqual(60);
});