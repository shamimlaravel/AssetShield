<?php

namespace Vendor\AssetShield\Commands;

use Illuminate\Console\Command;
use Vendor\AssetShield\AssetManifest;
use Vendor\AssetShield\AssetRegistry;
use Vendor\AssetShield\Support\MimeMapper;
use Vendor\AssetShield\Support\OpaqueId;

class BuildCommand extends Command
{
    protected $signature = 'asset-shield:build {--fresh : Regenerate the registry from the manifest, dropping any existing entries}';

    protected $description = 'Verify the Vite manifest and regenerate/validate the AssetShield registry';

    public function handle(AssetManifest $manifest, AssetRegistry $registry): int
    {
        if (! $manifest->exists()) {
            $this->components->error('Vite manifest not found at "'.$manifest->path().'". Run `npm run build` first.');

            return self::FAILURE;
        }

        $registry->refresh();

        $data = $manifest->data();
        $rows = $this->buildRows($manifest, $data);

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

        $count = $registry->save();
        $this->components->info(sprintf('Registered %d %s in "%s".', $count, $count === 1 ? 'asset' : 'assets', $registry->path()));

        return self::SUCCESS;
    }

    /**
     * Derive registry rows from the manifest. Every key with a generated `file`
     * becomes an entry; the opaque id is always derived server-side.
     *
     * @return array<int,array{logical:string,compiled:string,type:string,integrity:?string}>
     */
    private function buildRows(AssetManifest $manifest, array $data): array
    {
        $rows = [];
        $seen = [];

        foreach ($data as $logical => $record) {
            if (! is_array($record) || ! isset($record['file']) || ! is_string($record['file'])) {
                continue;
            }

            $compiled = $manifest->compiledPath($record['file']);

            if (! OpaqueId::isSafe($compiled) || MimeMapper::forPath($compiled) === null) {
                continue;
            }

            if (isset($seen[$logical])) {
                continue;
            }

            $seen[$logical] = true;

            $rows[] = [
                'logical' => (string) $logical,
                'compiled' => $compiled,
                'type' => MimeMapper::family($compiled),
                'integrity' => isset($record['integrity']) ? (string) $record['integrity'] : null,
            ];
        }

        return $rows;
    }
}