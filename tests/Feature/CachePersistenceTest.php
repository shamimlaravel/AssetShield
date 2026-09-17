<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Tests\TestCase;

it('serves the manifest and registry from the Laravel cache in production', function () {
    Cache::flush();

    $manifest = app(AssetManifest::class);
    $registry = app(AssetRegistry::class);

    // Drops the payloads setUp cached while bootstrapping the registry so the
    // next access actually re-reads disk through the cache.
    $manifest->refresh();
    $registry->refresh();

    $manifestKey = AssetManifest::cacheKey($manifest->path());
    $registryKey = AssetRegistry::cacheKey($registry->path());

    expect(Cache::has($manifestKey))->toBeFalse();
    expect(Cache::has($registryKey))->toBeFalse();

    $manifest->data();
    $registry->load();

    expect(Cache::has($manifestKey))->toBeTrue();
    expect(Cache::has($registryKey))->toBeTrue();

    $cached = Cache::get($registryKey);
    expect(is_array($cached))
        ->and(is_array($cached['data'] ?? null))
        ->and(isset($cached['data']['assets']) && is_array($cached['data']['assets']))->toBeTrue()
        ->and($cached['mtime'] ?? null)->toBeInt();
});

it('invalidates the cache when an artifact is rebuilt (mtime changes)', function () {
    Cache::flush();

    $manifest = app(AssetManifest::class);
    $registry = app(AssetRegistry::class);

    $manifest->refresh();
    $registry->refresh();

    $manifest->data();
    $registry->load();

    $manifestKey = AssetManifest::cacheKey($manifest->path());
    $registryKey = AssetRegistry::cacheKey($registry->path());

    $cachedMtime = Cache::get($manifestKey)['mtime'];

    // Simulate a rebuild: touch the artifact with a newer timestamp.
    touch($manifest->path(), $cachedMtime + 5);
    clearstatcache(true, $manifest->path());

    $this->reloadAssetShield();

    $reloaded = app(AssetManifest::class);
    $reloaded->data();

    $newMtime = Cache::get($manifestKey)['mtime'];

    expect($newMtime)->not->toBe($cachedMtime)
        ->and($newMtime)->toBe((int) filemtime($manifest->path()));
});

it('refresh() drops the production cache entries', function () {
    $manifest = app(AssetManifest::class);
    $registry = app(AssetRegistry::class);

    $manifest->data();
    $registry->load();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeTrue();

    $manifest->refresh();
    $registry->refresh();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeFalse();
    expect(Cache::has(AssetRegistry::cacheKey($registry->path())))->toBeFalse();
});

it('gates the cache on asset-shield.environment, not the framework app env', function () {
    Cache::flush();

    config()->set('app.env', 'local');
    config()->set('asset-shield.environment', 'production');
    $this->reloadAssetShield();

    $manifest = app(AssetManifest::class);
    $manifest->refresh();
    $manifest->data();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeTrue();

    Cache::flush();
    config()->set('app.env', 'production');
    config()->set('asset-shield.environment', 'local');
    $this->reloadAssetShield();

    $manifest = app(AssetManifest::class);
    $manifest->data();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeFalse();
});

it('does not touch the cache when asset-shield.cache.enabled is false', function () {
    Cache::flush();

    config()->set('asset-shield.cache.enabled', false);
    $this->reloadAssetShield();

    $manifest = app(AssetManifest::class);
    $registry = app(AssetRegistry::class);

    $manifest->data();
    $registry->load();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeFalse();
    expect(Cache::has(AssetRegistry::cacheKey($registry->path())))->toBeFalse();
});

it('asset-shield:build purges stale caches before regenerating', function () {
    $this->artisan('asset-shield:build')
        ->assertExitCode(0);

    $manifest = app(AssetManifest::class);
    $registry = app(AssetRegistry::class);

    // The build command left the in-memory singletons loaded; force a reload
    // so the next access exercises (and re-primes) the cache.
    $registry->refresh();

    // First run caches the current artifacts…
    $manifest->data();
    $registry->load();

    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeTrue();
    expect(Cache::has(AssetRegistry::cacheKey($registry->path())))->toBeTrue();

    // …a subsequent build refreshes the manifest and regenerates the registry
    // so no stale payload survives.
    $this->artisan('asset-shield:build')
        ->assertExitCode(0);

    expect(Cache::has(AssetRegistry::cacheKey($registry->path())))->toBeFalse();
    expect(Cache::has(AssetManifest::cacheKey($manifest->path())))->toBeTrue();
});