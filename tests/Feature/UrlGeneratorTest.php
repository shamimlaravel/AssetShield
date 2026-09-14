<?php

use Shamimstack\AssetShield\AssetUrlGenerator;

it('builds signed URLs by default', function () {
    $url = app(AssetUrlGenerator::class)->url('resources/js/app.js');

    expect($url)->toStartWith('/assets/')
        ->and($url)->toContain('expires=')
        ->and($url)->toContain('signature=')
        ->and($url)->not->toContain('app-A91Kx.js');
});

it('builds unsigned URLs when forced', function () {
    $url = app(AssetUrlGenerator::class)->url('resources/js/app.js', signed: false);

    expect($url)->toBe('/assets/'.opaqueFor('resources/js/app.js'));
});

it('honours a custom expiry timestamp', function () {
    $expires = time() + 600;
    $url = app(AssetUrlGenerator::class)->url('resources/js/app.js', signed: true, expires: $expires);

    expect($url)->toContain('expires='.$expires);
});

it('throws when generating a URL for an unknown logical entry', function () {
    app(AssetUrlGenerator::class)->url('resources/js/nope.js');
})->throws(\Shamimstack\AssetShield\Exceptions\AssetNotFoundException::class);

it('falls back to plain Vite/public URLs when disabled', function () {
    config()->set('asset-shield.enabled', false);
    $this->reloadAssetShield();

    $manager = app(\Shamimstack\AssetShield\AssetShieldManager::class);

    expect($manager->url('resources/js/app.js'))->toBe('/build/assets/app-A91Kx.js')
        ->and($manager->script('resources/js/app.js'))->toBe('')
        ->and($manager->style('resources/css/app.css'))->toBe('')
        ->and($manager->renderVite(['resources/js/app.js']))->toBe('')
        ->and($manager->isEnabled())->toBeFalse();
});

it('returns plain public URLs when runtime delivery is off', function () {
    config()->set('asset-shield.runtime.enabled', false);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $manager = app(\Shamimstack\AssetShield\AssetShieldManager::class);

    expect($manager->url('resources/js/app.js'))->toBe('/build/assets/app-A91Kx.js')
        ->and($manager->script('resources/js/app.js'))->toContain('src="/build/assets/app-A91Kx.js"')
        ->and($manager->isEnabled())->toBeTrue()
        ->and($manager->runtimeEnabled())->toBeFalse();
});

it('reports disabled state in status()', function () {
    config()->set('asset-shield.enabled', false);
    $this->reloadAssetShield();

    $status = app(\Shamimstack\AssetShield\AssetShieldManager::class)->status();

    expect($status['enabled'])->toBeFalse();
});

it('reports the configured snapshot in status()', function () {
    $status = app(\Shamimstack\AssetShield\AssetShieldManager::class)->status();

    expect($status['enabled'])->toBeTrue()
        ->and($status['environment'])->toBe('production')
        ->and($status['runtime_enabled'])->toBeTrue()
        ->and($status['route_prefix'])->toBe('assets')
        ->and($status['manifest_found'])->toBeTrue()
        ->and($status['registry_found'])->toBeTrue()
        ->and($status['registry_valid'])->toBeTrue()
        ->and($status['signed_urls'])->toBeTrue()
        ->and($status['expires'])->toBe(300)
        ->and($status['mask_enabled'])->toBeFalse()
        ->and($status['obfuscation_enabled'])->toBeFalse()
        ->and($status['source_maps'])->toBeFalse()
        ->and($status['driver'])->toBe('public');
});