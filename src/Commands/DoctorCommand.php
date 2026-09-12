<?php

namespace Vendor\AssetShield\Commands;

use Illuminate\Console\Command;
use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetRegistry;

class DoctorCommand extends Command
{
    protected $signature = 'asset-shield:doctor {--json : Output the checks as JSON}';

    protected $description = 'Inspect the deployment for AssetShield security and configuration problems';

    public function handle(AssetManifest $manifest, AssetRegistry $registry): int
    {
        $checks = $this->checks($manifest, $registry);
        $failures = 0;
        $warnings = 0;

        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $check) {
                match ($check['state']) {
                    'ok' => $this->line('  <fg=green;options=bold>✓</> '.$check['label'].' <fg=gray>'.$check['detail'].'</>'),
                    'warn' => $this->line('  <fg=yellow;options=bold>!</> '.$check['label'].' <fg=yellow>'.$check['detail'].'</>'),
                    default => $this->line('  <fg=red;options=bold>✗</> '.$check['label'].' <fg=red>'.$check['detail'].'</>'),
                };

                if ($check['state'] === 'fail') {
                    $failures++;
                }

                if ($check['state'] === 'warn') {
                    $warnings++;
                }
            }
        }

        $this->newLine();
        $this->components->info(
            sprintf('%d ok, %d warning%s, %d failure%s.', count($checks) - $failures - $warnings, $warnings, $warnings === 1 ? '' : 's', $failures, $failures === 1 ? '' : 's')
        );

        if ($failures > 0) {
            $this->components->error('AssetShield found problems that should be fixed before deploying.');

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->components->warn('AssetShield found warnings — review before deploying.');

            return self::SUCCESS;
        }

        $this->components->info('AssetShield environment looks healthy.');

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{state:'ok'|'warn'|'fail',label:string,detail:string}>
     */
    private function checks(AssetManifest $manifest, AssetRegistry $registry): array
    {
        $config = config('asset-shield');
        $environment = (string) config('app.env');
        $checks = [];

        // 1. APP_ENV
        if ($environment === 'production') {
            $checks[] = $this->row('ok', 'Production environment', 'APP_ENV='.$environment);
        } else {
            $checks[] = $this->row('warn', 'Not a production environment', 'APP_ENV='.$environment.' — expected "production" in production.');
        }

        // 2. APP_DEBUG
        if (! (bool) config('app.debug')) {
            $checks[] = $this->row('ok', 'APP_DEBUG=false', '');
        } else {
            $checks[] = $this->row('fail', 'APP_DEBUG is enabled', 'Set APP_DEBUG=false in production. Debug mode prints stack traces and paths.');
        }

        // 3. Vite manifest
        if ($manifest->exists()) {
            $checks[] = $this->row('ok', 'Vite manifest found', $manifest->path());
        } else {
            $checks[] = $this->row('fail', 'Vite manifest missing', $manifest->path().' — run `npm run build` first.');
        }

        // 4. Source maps (config + manifest scan)
        if ((bool) ($config['source_maps'] ?? false)) {
            $checks[] = $this->row('fail', 'Source maps enabled in config', 'asset-shield.source_maps=true. Hosted source maps are never hidden — disable in production.');
        } else {
            $checks[] = $this->row('ok', 'Source maps disabled', 'asset-shield.source_maps=false');
        }

        $mapFiles = $this->sourceMapFiles($manifest);
        if ($mapFiles !== []) {
            $checks[] = $this->row('warn', 'Source map files present on disk', implode(', ', array_slice($mapFiles, 0, 5)).' — they are never served by AssetShield, but consider removing them.');
        } else {
            $checks[] = $this->row('ok', 'No source map files in manifest', '');
        }

        // 5. node_modules exposure
        if (is_dir(public_path('node_modules'))) {
            $checks[] = $this->row('fail', 'node_modules is public', public_path('node_modules').' is inside the public root — move or remove it.');
        } else {
            $checks[] = $this->row('ok', 'node_modules is outside public root', '');
        }

        // 6. .env / sensitive files
        $checks[] = $this->envLocationCheck(public_path('.env'), '.env in public root');
        $checks[] = $this->envLocationCheck(public_path('composer.json'), 'composer.json in public root');
        $checks[] = $this->vendorCheck();

        // 7. Debugbar
        if (class_exists(\Barryvdh\Debugbar\ServiceProvider::class)) {
            $checks[] = $this->row('warn', 'Debugbar detected', 'barryvdh/laravel-debugbar is installed — ensure it is never loaded in production.');
        } else {
            $checks[] = $this->row('ok', 'Debugbar not detected', '');
        }

        // 8. Registry validity
        if (! $registry->fileExists()) {
            $checks[] = $this->row('fail', 'Asset registry missing', $registry->path().' — run `php artisan asset-shield:build`.');
        } else {
            try {
                $problems = $registry->validate($manifest, checkFiles: true);
            } catch (\Throwable $e) {
                $problems = [$e->getMessage()];
            }

            if ($problems === []) {
                $checks[] = $this->row('ok', 'Asset registry valid', sprintf('%d assets in %s', count($registry->entries()), $registry->path()));
            } else {
                foreach (array_slice($problems, 0, 3) as $problem) {
                    $checks[] = $this->row('fail', 'Asset registry invalid', $problem);
                }
                if (count($problems) > 3) {
                    $checks[] = $this->row('warn', 'Asset registry', sprintf('%d more problems were suppressed — run `php artisan asset-shield:build`.', count($problems) - 3));
                }
            }
        }

        return $checks;
    }

    private function envLocationCheck(string $path, string $label): array
    {
        if (is_file($path) || is_dir($path)) {
            return $this->row('fail', $label, $path.' — sensitive files must never live inside public/.');
        }

        return $this->row('ok', $label.' not present', '');
    }

    private function vendorCheck(): array
    {
        $publicVendor = public_path('vendor');

        if (is_file($publicVendor) || is_dir($publicVendor)) {
            return $this->row('fail', 'vendor inside public root', $publicVendor.' — remove it.');
        }

        return $this->row('ok', 'vendor is outside public root', '');
    }

    private function sourceMapFiles(AssetManifest $manifest): array
    {
        if (! $manifest->exists()) {
            return [];
        }

        $files = [];

        foreach ($manifest->data() as $record) {
            if (is_array($record) && isset($record['file']) && str_ends_with((string) $record['file'], '.map')) {
                $files[] = $record['file'];
            }
        }

        return $files;
    }

    private function row(string $state, string $label, string $detail): array
    {
        return ['state' => $state, 'label' => $label, 'detail' => $detail];
    }
}