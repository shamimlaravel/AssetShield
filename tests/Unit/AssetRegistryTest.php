<?php

use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Exceptions\RegistryInvalidException;

test('published config default for the registry is storage-relative, not double-prefixed', function () {
    $published = require __DIR__.'/../../config/asset-shield.php';

    expect($published['build']['registry'])->toBe('app/asset-shield/registry.json')
        ->and(str_starts_with($published['build']['registry'], 'storage/'))->toBeFalse();
});

test('fromConfig resolves the default registry under the storage root, not storage/storage', function () {
    config()->set('asset-shield.build.registry', 'app/asset-shield/registry.json');

    $registry = AssetRegistry::fromConfig(app());

    $expected = $this->app->storagePath('app/asset-shield/registry.json');

    $normalized = str_replace('\\', '/', $expected);

    expect($registry->path())->toBe($expected)
        ->and(str_contains($normalized, 'storage/storage'))->toBeFalse()
        ->and(str_ends_with($normalized, 'storage/app/asset-shield/registry.json'))->toBeTrue();
});

test('create(persist) writes the new entries to disk, never the previous state', function () {
    $registry = app(AssetRegistry::class);

    $rows = [
        ['logical' => 'resources/js/app.js', 'file' => 'build/assets/app-A91Kx.js', 'type' => 'script', 'integrity' => 'sha384-abc'],
        ['logical' => 'resources/js/new-feature.js', 'file' => 'build/assets/new-ABC123.js', 'type' => 'script'],
    ];

    $registry->create($rows, persist: true);

    $decoded = json_decode((string) file_get_contents($registry->path()), true);

    expect(is_array($decoded) && isset($decoded['assets']) && is_array($decoded['assets']))->toBeTrue();

    $logicals = array_keys($decoded['assets']);

    expect($logicals)->toContain('resources/js/app.js')
        ->toContain('resources/js/new-feature.js')
        ->and(count($logicals))->toBe(2)
        ->and($decoded['assets']['resources/js/new-feature.js']['type'])->toBe('script')
        ->and($decoded['assets']['resources/js/new-feature.js']['integrity'] ?? null)->toBeNull();
});

function poisonedRegistry(string $contents): AssetRegistry
{
    $path = storage_path('poisoned-registry-'.bin2hex(random_bytes(4)).'.json');

    file_put_contents($path, $contents);

    register_shutdown_function(static fn () => @unlink($path));

    return new AssetRegistry($path, 'test-key');
}

test('load() throws when the registry file is missing', function () {
    $registry = new AssetRegistry(storage_path('does-not-exist-'.bin2hex(random_bytes(4)).'.json'), 'test-key');

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class);
});

test('load() rejects a registry without an assets map', function () {
    $registry = poisonedRegistry(json_encode(['version' => 1]));

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class, 'missing "assets" map');
});

test('load() rejects a non-string integrity field', function () {
    $registry = poisonedRegistry(json_encode([
        'assets' => ['app.js' => ['file' => 'build/app-abc.js', 'type' => 'script', 'integrity' => 123]],
    ]));

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class, 'non-string "integrity"');
});

test('load() rejects a non-string type field', function () {
    $registry = poisonedRegistry(json_encode([
        'assets' => ['app.js' => ['file' => 'build/app-abc.js', 'type' => ['script']]],
    ]));

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class, 'non-string "type"');
});

test('load() rejects an unsafe file path', function () {
    $registry = poisonedRegistry(json_encode([
        'assets' => ['app.js' => ['file' => '../../.env', 'type' => 'script']],
    ]));

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class, 'unsafe file path');
});

test('load() rejects a stored opaque id that does not match the app key', function () {
    $registry = poisonedRegistry(json_encode([
        'assets' => ['app.js' => ['file' => 'build/app-abc.js', 'type' => 'script', 'opaque' => 'as_deadbeef']],
    ]));

    expect(fn () => $registry->load())->toThrow(RegistryInvalidException::class, 'does not match the application key');
});