<?php

use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\AssetShieldManager;
use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;

test('the registry maps logical, file and opaque identifiers', function () {
    $registry = app(AssetRegistry::class);

    $opaqueJs = $registry->opaqueForLogical('resources/js/app.js');

    expect($opaqueJs)->toMatch('/^as_[a-f0-9]{16}$/')
        ->and($registry->fileForOpaque($opaqueJs))->toBe('build/assets/app-A91Kx.js')
        ->and($registry->entryForOpaque($opaqueJs)['logical'])->toBe('resources/js/app.js');
});

test('an unknown logical entry has no opaque id', function () {
    expect(app(AssetRegistry::class)->opaqueForLogical('resources/js/nope.js'))->toBeNull();
});

test('registry validation is clean for the fixture build', function () {
    $registry = app(AssetRegistry::class);
    $manifest = app(\Shamimstack\AssetShield\AssetManifest::class);

    expect($registry->validate($manifest, checkFiles: true))->toBe([]);
});

test('manager resolve returns full asset metadata', function () {
    $resolve = app(AssetShieldManager::class)->resolve('resources/js/app.js');

    expect($resolve['file'])->toBe('build/assets/app-A91Kx.js')
        ->and($resolve['opaque'])->toMatch('/^as_[a-f0-9]{16}$/')
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
        ['logical' => 'evil', 'file' => '../../.env', 'type' => 'js'],
    ]);
})->throws(InvalidArgumentException::class);

test('registry rejects opaque id collisions and duplicate logical keys', function () {
    app(AssetRegistry::class)->create([
        ['logical' => 'a', 'file' => 'build/assets/app-A91Kx.js', 'type' => 'js'],
        ['logical' => 'b', 'file' => 'build/assets/app-A91Kx.js', 'type' => 'css'],
    ]);
    app(AssetRegistry::class)->create([
        ['logical' => 'a', 'file' => 'build/assets/app-2E5Zc7.css', 'type' => 'css'],
        ['logical' => 'a', 'file' => 'build/assets/app-A91Kx.js', 'type' => 'js'],
    ]);
})->throws(InvalidArgumentException::class);