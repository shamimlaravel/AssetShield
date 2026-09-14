<?php

namespace Shamimstack\AssetShield\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Shamimstack\AssetShield\AssetManifest;
use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Masking\MaskPlanner;
use Shamimstack\AssetShield\Masking\Legend;
use Shamimstack\AssetShield\Support\MimeMapper;
use Shamimstack\AssetShield\Support\OpaqueId;

class BuildCommand extends Command
{
    protected $signature = 'asset-shield:build
        {--fresh : Regenerate the registry from the manifest, dropping any existing entries}
        {--run : Run "npm run build" first when the manifest is missing}';

    protected $description = 'Verify the Vite manifest, regenerate/validate the AssetShield registry and record the mask legend';

    public function handle(AssetManifest $manifest, AssetRegistry $registry, Legend $legend): int
    {
        if (! $manifest->exists() && $this->option('run')) {
            $this->runFrontendBuild();
        }

        if (! $manifest->exists()) {
            $this->components->error('Vite manifest not found at "'.$manifest->path().'". Run `npm run build` or use `--run` first.');

            return self::FAILURE;
        }

        $registry->refresh();

        $data = $manifest->data();
        $rows = $this->buildRows($manifest, $legend, $data);

        if ($rows === []) {
            $this->components->error('The manifest at "'.$manifest->path().'" contains no assets to register.');

            return self::FAILURE;
        }

        // Materialize in memory first so we can validate before persisting.
        $registry->create($rows, persist: false);

        $problems = $registry->validate($manifest, checkFiles: true);

        if ($problems !== []) {
            $this->components->error('Registry validation failed:');
            foreach ($problems as $problem) {
                $this->components->error('  - '.$problem);
            }

            return self::FAILURE;
        }

        $this->reportMasking($manifest, $legend, $data);

        $count = $registry->save();
        $this->components->info(sprintf('Registered %d %s in "%s".', $count, $count === 1 ? 'asset' : 'assets', $registry->path()));

        return self::SUCCESS;
    }

    /**
     * Derive registry rows from the manifest. Every key with a generated `file`
     * becomes an entry; the opaque id is always derived server-side. When the
     * plugin legend is present its pre-mask names are preserved as
     * `original`.
     *
     * @return array<int,array{logical:string,file:string,type:string,integrity:?string,original:?string}>
     */
    private function buildRows(AssetManifest $manifest, Legend $legend, array $data): array
    {
        $rows = [];
        $seen = [];

        foreach ($data as $logical => $record) {
            if (! is_array($record) || ! isset($record['file']) || ! is_string($record['file'])) {
                continue;
            }

            $file = $manifest->compiledPath($record['file']);

            if (! OpaqueId::isSafe($file) || MimeMapper::forPath($file) === null) {
                continue;
            }

            if (isset($seen[$logical])) {
                continue;
            }

            $seen[$logical] = true;

            $original = $legend->originalForMasked($record['file']);

            $rows[] = [
                'logical' => (string) $logical,
                'file' => $file,
                'type' => MimeMapper::family($file),
                'integrity' => isset($record['integrity']) ? (string) $record['integrity'] : null,
                'original' => $original === null ? null : $manifest->compiledPath($original),
            ];
        }

        return $rows;
    }

    /**
     * Cross-check the plugin legend against the manifest and print a summary of
     * which assets were masked (and a warning when mask is configured but
     * no legend was produced by the plugin).
     */
    private function reportMasking(AssetManifest $manifest, Legend $legend, array $data): void
    {
        $config = (array) config('asset-shield.mask', []);

        if (! (bool) ($config['enabled'] ?? false)) {
            return;
        }

        if (! $legend->exists()) {
            $this->components->warn('Masking is enabled but no legend was found at "'.$legend->path().'". Make sure the @asset-shield/vite-plugin ran during "npm run build".');

            return;
        }

        $planner = new MaskPlanner([
            'enabled' => true,
            'strategy' => (string) ($config['strategy'] ?? 'nameless'),
            'seed' => (string) ($config['seed'] ?? ''),
            'aliases' => (array) ($config['aliases'] ?? []),
        ]);

        $renamed = 0;
        $mismatches = 0;

        foreach ($data as $logical => $record) {
            if (! is_array($record) || ! isset($record['file']) || ! is_string($record['file'])) {
                continue;
            }

            $entry = $legend->entryForMasked($record['file']);

            if ($entry === null) {
                continue;
            }

            $expected = $planner->plan((string) $logical, $entry['original'])['file'];

            if ($expected !== $entry['masked']) {
                $mismatches++;
                $this->components->error('  - Legend mismatch for "'.$logical.'": plugin wrote "'.$entry['masked'].'" but PHP recomputes "'.$expected.'" (check mask.seed matches the plugin seed).');
                continue;
            }

            if (basename($entry['masked']) !== basename($entry['original'])) {
                $renamed++;
            }
        }

        if ($mismatches > 0) {
            return;
        }

        $this->components->info(sprintf('Masking legend validated: %d asset%s renamed (strategy: %s).', $renamed, $renamed === 1 ? ' was' : 's were', (string) ($config['strategy'] ?? 'nameless')));
    }

    private function runFrontendBuild(): void
    {
        $base = base_path();
        $command = defined('PHP_WINDOWS_VERSION_BUILD') ? 'npm.cmd' : 'npm';

        $this->components->info('Manifest missing — running "npm run build" in "'.$base.'".');

        $process = new Process([$command, 'run', 'build'], $base, null, null, 600);
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (! $process->isSuccessful()) {
            $this->components->error('"npm run build" failed with exit code '.$process->getExitCode().'.');

            return;
        }

        $this->components->info('Frontend build completed.');
    }
}