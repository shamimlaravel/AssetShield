<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Masking\MaskPlanner;
use Shamimstack\AssetShield\Masking\Legend;

/**
 * @phpstan-type DoctorRow = array{state: 'ok'|'info'|'warn'|'fail', label: string, detail: string}
 */
class DoctorCommand extends Command
{
    protected $signature = 'asset-shield:doctor {--json : Output the checks as JSON}';

    protected $description = 'Inspect the deployment for AssetShield security and configuration problems';

    public function handle(AssetManifest $manifest, AssetRegistry $registry, Legend $legend): int
    {
        $checks = $this->checks($manifest, $registry, $legend);
        $failures = 0;
        $warnings = 0;

        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $check) {
                match ($check['state']) {
                    'ok' => $this->line('  <fg=green;options=bold>✓</> '.$check['label'].' <fg=gray>'.$check['detail'].'</>'),
                    'info' => $this->line('  <fg=cyan>·</> '.$check['label'].' <fg=gray>'.$check['detail'].'</>'),
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
     * @return array<int, DoctorRow>
     */
    private function checks(AssetManifest $manifest, AssetRegistry $registry, Legend $legend): array
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
        $build = (array) ($config['build'] ?? []);
        if ((bool) ($build['source_maps'] ?? false)) {
            $checks[] = $this->row('fail', 'Source maps enabled in config', 'asset-shield.build.source_maps=true. Hosted source maps are never hidden — disable in production.');
        } else {
            $checks[] = $this->row('ok', 'Source maps disabled', 'asset-shield.build.source_maps=false');
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

        // 9. Masking legend consistency
        $mask = (array) ($config['mask'] ?? []);
        if ((bool) ($mask['enabled'] ?? false)) {
            if (! $legend->exists()) {
                $checks[] = $this->row('warn', 'Masking enabled but legend missing', $legend->path().' — run "npm run build" with the @asset-shield/vite-plugin first.');
            } else {
                $planner = MaskPlanner::fromConfig($mask);

                $mismatches = [];

                if ($manifest->exists()) {
                    foreach ($manifest->data() as $logical => $record) {
                        if (! is_array($record) || ! isset($record['file']) || ! is_string($record['file'])) {
                            continue;
                        }

                        $entry = $legend->entryForMasked($record['file']);

                        if ($entry === null) {
                            continue;
                        }

                        $expected = $planner->plan((string) $logical, $entry['original'])['file'];

                        if ($expected !== $entry['masked']) {
                            $mismatches[] = (string) $logical;
                        }
                    }
                }

                if ($mismatches === []) {
                    $checks[] = $this->row('ok', 'Masking legend valid', sprintf('%d asset mappings in %s', count($legend->entries()), $legend->path()));
                } else {
                    $checks[] = $this->row('fail', 'Masking legend mismatch', implode(', ', array_slice($mismatches, 0, 5)).' — the PHP mask.seed must match the plugin seed.');
                }
            }
        } else {
            $checks[] = $this->row('info', 'Masking disabled', '');
        }

        // 10. Duplicate output filenames in the manifest
        if ($manifest->exists()) {
            $basenames = [];

            foreach ($manifest->data() as $record) {
                if (is_array($record) && isset($record['file']) && is_string($record['file'])) {
                    $basenames[basename($record['file'])][] = basename($record['file']);
                }
            }

            $duplicates = [];
            foreach ($basenames as $basename => $occurrences) {
                if (count($occurrences) > 1) {
                    $duplicates[] = $basename;
                }
            }

            if ($duplicates !== []) {
                $checks[] = $this->row('warn', 'Duplicate output filenames', implode(', ', array_slice($duplicates, 0, 5)).' — multiple manifest entries resolve to the same basename.');
            } else {
                $checks[] = $this->row('ok', 'No duplicate output filenames', '');
            }
        }

        // 11. Broken manifest references
        if ($manifest->exists()) {
            $missing = [];

            foreach ($manifest->data() as $record) {
                if (is_array($record) && isset($record['file']) && is_string($record['file'])) {
                    $file = $manifest->compiledPath($record['file']);

                    if ($manifest->absolutePath($file) === null) {
                        $missing[] = $record['file'];
                    }
                }
            }

            if ($missing !== []) {
                $checks[] = $this->row('warn', 'Manifest references missing files on disk', implode(', ', array_slice($missing, 0, 5)).' — rebuild ("npm run build" then "asset-shield:build").');
            } else {
                $checks[] = $this->row('ok', 'All manifest files exist on disk', '');
            }
        }

        // 12. Toolchain versions (informational)
        foreach ([
            'PHP' => ['php', '-v'],
            'Node' => ['node', '-v'],
            'NPM' => ['npm', '-v'],
        ] as $label => [$bin, $arg]) {
            $checks[] = $this->versionCheck($label, $bin, $arg);
        }
        $checks[] = $this->row('info', 'Laravel', app()->version());
        $checks[] = $this->viteVersionCheck();

        // 13. Obfuscation configuration (config mirror — the engine itself
        //     stays native to the @asset-shield/vite-plugin build side)
        $obfuscation = (array) ($config['obfuscation'] ?? []);

        if ((bool) ($obfuscation['enabled'] ?? false)) {
            $checks[] = $this->row('info', 'Obfuscation enabled', 'preset: '.($obfuscation['preset'] ?? 'balanced').' (applied by the Vite plugin at build time).');
        } else {
            $checks[] = $this->row('info', 'Obfuscation disabled', '');
        }

        return $checks;
    }

    /**
     * @return DoctorRow
     */
    private function versionCheck(string $label, string $bin, string $arg): array
    {
        $command = str_contains(strtolower(PHP_OS_FAMILY), 'win')
            ? ($bin === 'npm' ? 'npm.cmd' : [$bin, $arg])
            : [$bin, $arg];

        if (is_string($command)) {
            try {
                $process = new Process([$command, $arg], null, null, null, 3);
            } catch (\Throwable $e) {
                return $this->row('warn', $label.' unavailable', $e->getMessage());
            }
        } else {
            $process = new Process($command, null, null, null, 3);
        }

        try {
            $process->run();
            $output = trim($process->getOutput() ?: $process->getErrorOutput());
        } catch (\Throwable $e) {
            return $this->row('warn', $label.' unavailable', $e->getMessage());
        }

        if (! $process->isSuccessful() || $output === '') {
            return $this->row('warn', $label.' unavailable', 'Could not detect '.$label.' — the Node toolchain is expected for frontend builds.');
        }

        return $this->row('info', $label, strtok($output, "\n"));
    }

    /**
     * Vite lives in node_modules, not on PATH, so resolve it from the project
     * root. Falls back to the bundled manifest version when the binary is not
     * executable but the package is present.
     *
     * @return DoctorRow
     */
    private function viteVersionCheck(): array
    {
        $root = base_path();
        $bin = $root.'/node_modules/.bin/vite'.(defined('PHP_WINDOWS_VERSION_BUILD') ? '.cmd' : '');

        try {
            if (is_file($bin)) {
                $process = new Process([$bin, '--version'], $root, null, null, 3);
                $process->run();
                $output = trim($process->getOutput() ?: $process->getErrorOutput());

                if ($process->isSuccessful() && $output !== '') {
                    return $this->row('info', 'Vite', strtok($output, "\n"));
                }
            }

            $manifest = $root.'/node_modules/vite/package.json';

            if (is_file($manifest)) {
                $version = (string) (json_decode((string) file_get_contents($manifest), true)['version'] ?? '');

                if ($version !== '') {
                    return $this->row('info', 'Vite', 'v'.$version.' (package.json)');
                }
            }
        } catch (\Throwable $e) {
            return $this->row('info', 'Vite unavailable', $e->getMessage());
        }

        return $this->row('info', 'Vite unavailable', 'Could not locate vite in node_modules — frontend builds are expected to use Vite.');
    }

    /**
     * @return DoctorRow
     */
    private function envLocationCheck(string $path, string $label): array
    {
        if (is_file($path) || is_dir($path)) {
            return $this->row('fail', $label, $path.' — sensitive files must never live inside public/.');
        }

        return $this->row('ok', $label.' not present', '');
    }

    /**
     * @return DoctorRow
     */
    private function vendorCheck(): array
    {
        $publicVendor = public_path('vendor');

        if (is_file($publicVendor) || is_dir($publicVendor)) {
            return $this->row('fail', 'vendor inside public root', $publicVendor.' — remove it.');
        }

        return $this->row('ok', 'vendor is outside public root', '');
    }

    /**
     * @return list<string>
     */
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

    /**
     * @param  'ok'|'info'|'warn'|'fail'  $state
     * @return DoctorRow
     */
    private function row(string $state, string $label, string $detail): array
    {
        return ['state' => $state, 'label' => $label, 'detail' => $detail];
    }
}