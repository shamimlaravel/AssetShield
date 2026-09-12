<?php

use Illuminate\Support\Facades\Blade;

it('renders a script tag for js entries with @assetShieldJs', function () {
    $html = Blade::render('@assetShieldJs("resources/js/app.js")');

    expect($html)->toContain('<script src="/assets/')
        ->and($html)->toContain('expires=')
        ->and($html)->not->toContain('app-A91Kx.js');
});

it('renders a stylesheet link for css entries with @assetShieldCss', function () {
    $html = Blade::render('@assetShieldCss("resources/css/app.css")');

    expect($html)->toContain('<link rel="stylesheet" href="/assets/');
});

it('auto-detects the tag type with @assetShield', function () {
    $js = Blade::render('@assetShield("resources/js/app.js")');
    $css = Blade::render('@assetShield("resources/css/app.css")');

    expect($js)->toContain('<script src=')
        ->and($css)->toContain('<link rel="stylesheet"');
});

it('@shieldVite renders every registered entry', function () {
    $html = Blade::render('@shieldVite(["resources/css/app.css", "resources/js/app.js"])');

    expect($html)->toContain('<link rel="stylesheet" href="/assets/')
        ->and($html)->toContain('<script src="/assets/');
});

it('@shieldVite skips unregistered entries', function () {
    $html = Blade::render('@shieldVite(["resources/js/nope.js", "resources/js/app.js"])');

    expect($html)->not->toContain('nope.js')
        ->and($html)->toContain('<script src="/assets/');
});

it('escapes attribute values in rendered tags', function () {
    config()->set('asset-shield.signature.enabled', false);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $html = Blade::render('@assetShieldJs("resources/js/app.js")');

    expect($html)->not->toContain('"><script>');
});