<?php

use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\Exceptions\AssetNotFoundException;
use Vendor\AssetShield\Exceptions\ManifestNotFoundException;

test('manifest loads the fixture and resolves entries', function () {
    $manifest = app(AssetManifest::class);

    expect($manifest->exists())->toBeTrue();

    $record = $manifest->resolve('resources/js/app.js');

    expect($record['file'])->toBe('assets/app-A91Kx.js')
        ->and($record['isEntry'])->toBeTrue();
});

test('compiledPath prefixes the manifest directory relative to public root', function () {
    $manifest = app(AssetManifest::class);

    expect($manifest->compiledPath('assets/app-A91Kx.js'))
        ->toBe('build/assets/app-A91Kx.js');
});

test('absolutePath resolves inside the public root only', function () {
    $manifest = app(AssetManifest::class);

    $absolute = $manifest->absolutePath('build/assets/app-A91Kx.js');

    expect($absolute)->not->toBeNull()
        ->and(file_exists($absolute))->toBeTrue()
        ->and($manifest->absolutePath('../../.env'))->toBeNull()
        ->and($manifest->absolutePath('build/../outside.txt'))->toBeNull()
        ->and($manifest->absolutePath('build/missing-unknown.js'))->toBeNull();
});

test('resolving an unknown logical entry throws', function () {
    app(AssetManifest::class)->resolve('resources/js/nope.js');
})->throws(AssetNotFoundException::class);

test('a missing manifest throws ManifestNotFoundException', function () {
    $manifest = app(AssetManifest::class);

    $tmp = storage_path('missing-manifest.json');
    @unlink($tmp);

    config()->set('asset-shield.manifest_path', $tmp);
    $this->reloadAssetShield();

    app(AssetManifest::class)->data();
})->throws(ManifestNotFoundException::class);

test('a malformed manifest throws ManifestNotFoundException', function () {
    $tmp = storage_path('broken-manifest.json');
    file_put_contents($tmp, '{"broken": ');

    config()->set('asset-shield.manifest_path', $tmp);
    $this->reloadAssetShield();

    app(AssetManifest::class)->data();

    @unlink($tmp);
})->throws(ManifestNotFoundException::class);