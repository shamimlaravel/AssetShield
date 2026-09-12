<?php

namespace Vendor\AssetShield\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vendor\AssetShield\AssetDeliveryDriver;
use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetRegistry;
use Vendor\AssetShield\AssetResponse;
use Vendor\AssetShield\AssetShieldManager;
use Vendor\AssetShield\AssetShieldServiceProvider;
use Vendor\AssetShield\AssetUrlGenerator;
use Vendor\AssetShield\Delivery\PublicFileDriver;
use Vendor\AssetShield\Delivery\StreamDriver;
use Vendor\AssetShield\Http\Controllers\AssetController;
use Vendor\AssetShield\Signer\AssetSigner;

abstract class TestCase extends Orchestra
{
    public const APP_KEY = 'base64:6fMu0G7YZ7pGFqfh50PMIkg4nV5n1kL4nV5n1kL4nV5n1kLZwA6s=';
    public const FIXTURES = __DIR__.'/Fixtures';

    protected function getPackageProviders($app): array
    {
        return [AssetShieldServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->usePublicPath(self::FIXTURES.'/public');

        $app['config']->set('app.key', self::APP_KEY);
        $app['config']->set('app.debug', false);
        $app['config']->set('app.env', 'production');

        $app['config']->set('asset-shield.enabled', true);
        $app['config']->set('asset-shield.mode', 'protected');
        $app['config']->set('asset-shield.route_prefix', 'assets');
        $app['config']->set('asset-shield.driver', 'public');
        $app['config']->set('asset-shield.source_maps', false);
        $app['config']->set('asset-shield.signature.enabled', true);
        $app['config']->set('asset-shield.signature.expires', 300);
        $app['config']->set('asset-shield.cache.max_age', 31536000);
        $app['config']->set('asset-shield.cache.enabled', true);

        $app['config']->set('asset-shield.manifest_path', self::FIXTURES.'/public/build/manifest.json');
        $app['config']->set('asset-shield.registry_path', self::FIXTURES.'/storage/asset-shield/registry.json');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapRegistry();
    }

    protected function tearDown(): void
    {
        @unlink(self::FIXTURES.'/storage/asset-shield/registry.json');

        parent::tearDown();
    }

    /**
     * Populate the registry from the fixture manifest so routes resolve.
     */
    protected function bootstrapRegistry(bool $persist = true): void
    {
        $manifest = $this->app->make(AssetManifest::class);
        $registry = $this->app->make(AssetRegistry::class);

        $rows = [];

        foreach ($manifest->data() as $logical => $record) {
            if (! is_array($record) || ! isset($record['file']) || ! is_string($record['file'])) {
                continue;
            }

            $rows[] = [
                'logical' => (string) $logical,
                'compiled' => $manifest->compiledPath($record['file']),
                'type' => \Vendor\AssetShield\Support\MimeMapper::family($manifest->compiledPath($record['file'])),
                'integrity' => $record['integrity'] ?? null,
            ];
        }

        $registry->create($rows, $persist);
    }

    /**
     * Forget the AssetShield singletons so the next resolution reads the
     * current config. Call this after changing config in a test.
     */
    protected function reloadAssetShield(): void
    {
        foreach ([
            AssetShieldManager::class,
            'asset-shield',
            AssetManifest::class,
            AssetRegistry::class,
            AssetResponse::class,
            AssetSigner::class,
            AssetUrlGenerator::class,
            PublicFileDriver::class,
            StreamDriver::class,
            AssetDeliveryDriver::class,
            AssetController::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}