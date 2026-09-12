<?php

namespace Vendor\AssetShield;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Vendor\AssetShield\Commands\BuildCommand;
use Vendor\AssetShield\Commands\DoctorCommand;
use Vendor\AssetShield\Commands\InstallCommand;
use Vendor\AssetShield\Commands\StatusCommand;
use Vendor\AssetShield\Delivery\AssetDeliveryDriver;
use Vendor\AssetShield\Delivery\PublicFileDriver;
use Vendor\AssetShield\Delivery\StreamDriver;
use Vendor\AssetShield\Http\Controllers\AssetController;
use Vendor\AssetShield\Signer\AssetSigner;
use Vendor\AssetShield\Signer\HmacAssetSigner;

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
            && $this->app['config']->get('asset-shield.mode', 'protected') === 'protected') {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    private function registerServices(): void
    {
        $this->app->singleton(AssetManifest::class, fn (Application $app) => AssetManifest::fromConfig($app));

        $this->app->singleton(AssetRegistry::class, fn (Application $app) => AssetRegistry::fromConfig($app));

        $this->app->singleton(AssetResponse::class, fn (Application $app) => new AssetResponse(
            (bool) $app['config']->get('asset-shield.cache.enabled', true),
            (int) $app['config']->get('asset-shield.cache.max_age', 31536000),
        ));

        $this->app->singleton(PublicFileDriver::class, fn (Application $app) => new PublicFileDriver($app->make(AssetResponse::class)));
        $this->app->singleton(StreamDriver::class, fn (Application $app) => new StreamDriver($app->make(AssetResponse::class)));

        $this->app->singleton(AssetDeliveryDriver::class, function (Application $app) {
            $driver = $app['config']->get('asset-shield.driver', 'public');

            return $driver === 'stream'
                ? $app->make(StreamDriver::class)
                : $app->make(PublicFileDriver::class);
        });

        $this->app->singleton(AssetSigner::class, fn (Application $app) => new HmacAssetSigner(
            (string) $app['config']->get('app.key'),
            (int) $app['config']->get('asset-shield.signature.expires', 300),
        ));

        $this->app->singleton(AssetUrlGenerator::class, fn (Application $app) => new AssetUrlGenerator(
            $app->make(AssetRegistry::class),
            $app->make(AssetSigner::class),
            (string) $app['config']->get('asset-shield.route_prefix', 'assets'),
            (bool) $app['config']->get('asset-shield.signature.enabled', true),
            (int) $app['config']->get('asset-shield.signature.expires', 300),
        ));

        $this->app->singleton(AssetShieldManager::class, fn (Application $app) => new AssetShieldManager(
            $app->make(AssetRegistry::class),
            $app->make(AssetManifest::class),
            $app->make(AssetUrlGenerator::class),
            $app->make(AssetSigner::class),
            (array) $app['config']->get('asset-shield'),
        ));

        $this->app->bind(AssetController::class, fn (Application $app) => new AssetController(
            $app->make(AssetRegistry::class),
            $app->make(AssetSigner::class),
            $app->make(AssetManifest::class),
            $app->make(AssetDeliveryDriver::class),
            (bool) $app['config']->get('asset-shield.signature.enabled', true),
            (bool) $app['config']->get('asset-shield.source_maps', false),
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