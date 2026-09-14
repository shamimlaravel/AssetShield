<?php

namespace Shamimstack\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Shamimstack\AssetShield\Masking\Legend;
use Shamimstack\AssetShield\Commands\BuildCommand;
use Shamimstack\AssetShield\Commands\DoctorCommand;
use Shamimstack\AssetShield\Commands\InstallCommand;
use Shamimstack\AssetShield\Commands\StatusCommand;
use Shamimstack\AssetShield\Delivery\AssetDeliveryDriver;
use Shamimstack\AssetShield\Delivery\PublicDriver;
use Shamimstack\AssetShield\Delivery\StreamDriver;
use Shamimstack\AssetShield\Http\Controllers\AssetController;
use Shamimstack\AssetShield\Http\Middleware\VerifyAssetSignature;
use Shamimstack\AssetShield\Obfuscation\JavascriptObfuscator;
use Shamimstack\AssetShield\Obfuscation\ObfuscationEngine;
use Shamimstack\AssetShield\Security\Csp;
use Shamimstack\AssetShield\Signer\AssetSigner;
use Shamimstack\AssetShield\Signer\HmacAssetSigner;

class AssetShieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/asset-shield.php', 'asset-shield');

        $this->registerServices();

        $this->app->alias(AssetShieldManager::class, 'asset-shield');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/asset-shield.php' => config_path('asset-shield.php'),
        ], 'asset-shield-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                BuildCommand::class,
                StatusCommand::class,
                DoctorCommand::class,
            ]);
        }

        $this->registerBladeDirectives();

        if ($this->app['config']->get('asset-shield.enabled', true)
            && $this->app['config']->get('asset-shield.runtime.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    private function registerServices(): void
    {
        $this->app->singleton(AssetManifest::class, fn (Application $app) => AssetManifest::fromConfig($app));

        $this->app->singleton(AssetRegistry::class, fn (Application $app) => AssetRegistry::fromConfig($app));

        $this->app->singleton(Legend::class, fn (Application $app) => Legend::fromConfig($app));

        $this->app->singleton(Csp::class, fn (Application $app) => new Csp(
            (array) $app['config']->get('asset-shield.security', []),
        ));

        $this->app->bind(ObfuscationEngine::class, fn (Application $app) => JavascriptObfuscator::fromConfig($app));

        $this->app->singleton(AssetResolver::class, fn (Application $app) => new AssetResolver(
            $app->make(AssetRegistry::class),
        ));

        $this->app->singleton(AssetResponse::class, fn (Application $app) => new AssetResponse(
            (bool) $app['config']->get('asset-shield.cache.enabled', true),
            (int) $app['config']->get('asset-shield.cache.max_age', 31536000),
        ));

        $this->app->singleton(PublicDriver::class, fn (Application $app) => new PublicDriver(
            $app->make(AssetResponse::class),
            $app->make(AssetManifest::class),
        ));
        $this->app->singleton(StreamDriver::class, fn (Application $app) => new StreamDriver(
            $app->make(AssetResponse::class),
            $app->make(AssetManifest::class),
        ));

        $this->app->singleton(AssetDeliveryDriver::class, function (Application $app) {
            $driver = $app['config']->get('asset-shield.delivery.driver', 'public');

            return $driver === 'stream'
                ? $app->make(StreamDriver::class)
                : $app->make(PublicDriver::class);
        });

        $this->app->singleton(AssetSigner::class, fn (Application $app) => new HmacAssetSigner(
            (string) $app['config']->get('app.key'),
            (int) $app['config']->get('asset-shield.runtime.expires', 300),
        ));

        $this->app->singleton(AssetUrlGenerator::class, fn (Application $app) => new AssetUrlGenerator(
            $app->make(AssetRegistry::class),
            $app->make(AssetSigner::class),
            (string) $app['config']->get('asset-shield.runtime.route_prefix', 'assets'),
            (bool) $app['config']->get('asset-shield.runtime.signed_urls', true),
            (int) $app['config']->get('asset-shield.runtime.expires', 300),
        ));

        $this->app->singleton(AssetShieldManager::class, fn (Application $app) => new AssetShieldManager(
            $app->make(AssetRegistry::class),
            $app->make(AssetManifest::class),
            $app->make(AssetUrlGenerator::class),
            $app->make(AssetSigner::class),
            (array) $app['config']->get('asset-shield'),
        ));

        $this->app->bind(VerifyAssetSignature::class, fn (Application $app) => new VerifyAssetSignature(
            $app->make(AssetResolver::class),
            $app->make(AssetSigner::class),
            (bool) $app['config']->get('asset-shield.runtime.signed_urls', true),
        ));

        $this->app->bind(AssetController::class, fn (Application $app) => new AssetController(
            $app->make(AssetResolver::class),
            $app->make(AssetDeliveryDriver::class),
            (bool) $app['config']->get('asset-shield.runtime.signed_urls', true),
            (bool) $app['config']->get('asset-shield.build.source_maps', false),
        ));
    }

    private function registerBladeDirectives(): void
    {
        Blade::directive('assetShield', function (string $expression) {
            return "<?php echo app('asset-shield')->render({$expression}); ?>";
        });

        Blade::directive('assetShieldJs', function (string $expression) {
            return "<?php echo app('asset-shield')->script({$expression}); ?>";
        });

        Blade::directive('assetShieldCss', function (string $expression) {
            return "<?php echo app('asset-shield')->style({$expression}); ?>";
        });

        Blade::directive('shieldVite', function (string $expression) {
            return "<?php echo app('asset-shield')->renderVite({$expression}); ?>";
        });
    }
}