<?php

use Vendor\AssetShield\AssetRegistry;
use Vendor\AssetShield\AssetShieldManager;
use Vendor\AssetShield\Exceptions\AssetNotFoundException;

test('the registry maps logical, compiled and opaque identifiers', function () {
    $registry = app(AssetRegistry::class);

    $opaqueJs = $registry->opaqueForLogical('resources/js/app.js');

    expect($opaqueJs)->toMatch('/^[a-f0-9]{16}$/')
        ->and($registry->compiledForOpaque($opaqueJs))->toBe('build/assets/app-A91Kx.js')
        ->and($registry->entryForOpaque($opaqueJs)['logical'])->toBe('resources/js/app.js');
});

test('an unknown logical entry has no opaque id', function () {
    expect(app(AssetRegistry::class)->opaqueForLogical('resources/js/nope.js'))->toBeNull();
});

test('registry validation is clean for the fixture build', function () {
    $registry = app(AssetRegistry::class);
    $manifest = app(\Vendor\AssetShield\AssetManifest::class);

    expect($registry->validate($manifest, checkFiles: true))->toBe([]);
});

test('manager resolve returns full asset metadata', function () {
    $resolve = app(AssetShieldManager::class)->resolve('resources/js/app.js');

    expect($resolve['compiled'])->toBe('build/assets/app-A91Kx.js')
        ->and($resolve['opaque'])->toMatch('/^[a-f0-9]{16}$/')
        ->and($resolve['type'])->toBe('script')
        ->and($resolve['mime'])->toBe('text/javascript')
        ->and($resolve['url'])->toStartWith('/assets/')
        ->and($resolve['url'])->toContain('expires')
        ->and($resolve['url'])->toContain('signature');
});

test('manager resolve throws for unregistered entries', function () {
    app(AssetShieldManager::class)->resolve('resources/js/nope.js');
})->throws(AssetNotFoundException::class);

test('registry rejects directory traversal at build time', function () {
    app(AssetRegistry::class)->create([
        ['logical' => 'evil', 'compiled' => '../../.env', 'type' => 'js'],
    ]);
})->throws(InvalidArgumentException::class);

test('registry rejects opaque id collisions', function () {
    app(AssetRegistry::class)->create([
        ['logical' => 'a', 'compiled' => 'build/assets/app-A91Kx.js', 'type' => 'js'],
        ['logical' => 'b', 'compiled' => 'build/assets/app-A91Kx.js', 'type' => 'css'],
    ]);
})->throws(InvalidArgumentException::class);