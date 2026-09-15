<?php

namespace Shamimstack\AssetShield\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shamimstack\AssetShield\Delivery\AssetDeliveryDriver;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\AssetResolver;
use Shamimstack\AssetShield\AssetResponse;
use Shamimstack\AssetShield\AssetShieldManager;
use Shamimstack\AssetShield\AssetShieldServiceProvider;
use Shamimstack\AssetShield\AssetUrlGenerator;
use Shamimstack\AssetShield\Masking\Legend;
use Shamimstack\AssetShield\Delivery\PublicDriver;
use Shamimstack\AssetShield\Delivery\StreamDriver;
use Shamimstack\AssetShield\Http\Controllers\AssetController;
use Shamimstack\AssetShield\Http\Middleware\VerifyAssetSignature;
use Shamimstack\AssetShield\Obfuscation\ObfuscationEngine;
use Shamimstack\AssetShield\Security\Csp;
use Shamimstack\AssetShield\Signer\AssetSigner;

abstract class TestCase extends Orchestra
{
    public const APP_KEY = 'base64:6fMu0G7YZ7pGFqfh50PMIkg4nV5n1kL4nV5n1kL4nV5n1kLZwA6s=';
    public const FIXTURES = __DIR__.'/Fixtures';

    public const REGISTRY_FILE = self::FIXTURES.'/storage/app/asset-shield/registry.json';

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
        $app['config']->set('asset-shield.environment', 'production');
        $app['config']->set('asset-shield.build.manifest', self::FIXTURES.'/public/build/manifest.json');
        $app['config']->set('asset-shield.build.registry', self::REGISTRY_FILE);
        $app['config']->set('asset-shield.build.out_dir', 'build');
        $app['config']->set('asset-shield.build.source_maps', false);
        $app['config']->set('asset-shield.runtime.enabled', true);
        $app['config']->set('asset-shield.runtime.route_prefix', 'assets');
        $app['config']->set('asset-shield.runtime.signed_urls', true);
        $app['config']->set('asset-shield.runtime.expires', 300);
        $app['config']->set('asset-shield.delivery.driver', 'public');
        $app['config']->set('asset-shield.cache.max_age', 31536000);
        $app['config']->set('asset-shield.cache.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapRegistry();
    }

    protected function tearDown(): void
    {
        @unlink(self::REGISTRY_FILE);

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
                'file' => $manifest->compiledPath($record['file']),
                'type' => \Shamimstack\AssetShield\Support\MimeMapper::family($manifest->compiledPath($record['file'])),
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
            Legend::class,
            Csp::class,
            ObfuscationEngine::class,
            AssetResolver::class,
            AssetResponse::class,
            AssetSigner::class,
            AssetUrlGenerator::class,
            PublicDriver::class,
            StreamDriver::class,
            AssetDeliveryDriver::class,
            AssetController::class,
            VerifyAssetSignature::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}