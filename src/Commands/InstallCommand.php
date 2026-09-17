<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'asset-shield:install {--force : Overwrite the existing config without confirmation}';

    protected $description = 'Publish the AssetShield config, create required paths, and print the Vite integration';

    private const STORAGE_DIR = 'app/asset-shield';

    public function handle(): int
    {
        $this->publishConfig();

        $this->createStorageDirectory();

        $this->printViteSetup();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $target = config_path('asset-shield.php');

        if (file_exists($target) && ! $this->option('force')) {
            if (! $this->components->confirm('config/asset-shield.php already exists. Overwrite existing file?', false)) {
                $this->components->info('Keeping your existing config/asset-shield.php.');

                return;
            }
        }

        $this->callSilently('vendor:publish', [
            '--provider' => 'Shamimstack\AssetShield\AssetShieldServiceProvider',
            '--tag' => 'asset-shield-config',
            '--force' => true,
        ]);

        $this->components->info('Published config/asset-shield.php'.(file_exists($target) ? ' (overwritten)' : '.'));
    }

    private function createStorageDirectory(): void
    {
        $dir = $this->laravel->storagePath(self::STORAGE_DIR);

        if (is_dir($dir)) {
            $this->components->info('Found existing storage/app/asset-shield directory.');

            return;
        }

        if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->components->error('Could not create '.$dir.' — check filesystem permissions.');

            return;
        }

        $this->components->info('Created storage/app/asset-shield (registry and legend live here, outside public/).');
    }

    private function printViteSetup(): void
    {
        $this->newLine();
        $this->components->info('Next: wire the optional Vite plugin into vite.config.js:');

        $this->line("import { defineConfig } from 'vite';\n"
            ."import laravel from 'laravel-vite-plugin';\n"
            ."import { assetShieldVite } from '@asset-shield/vite-plugin';\n"
            ."\n"
            ."export default defineConfig({\n"
            ."    plugins: [\n"
            ."        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),\n"
            ."        assetShieldVite(),\n"
            ."    ],\n"
            ."});");

        $this->newLine();
        $this->components->info("Done. Build with `npm run build`, then validate with `php artisan asset-shield:build`.");
    }
}