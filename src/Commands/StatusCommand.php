<?php

namespace Vendor\AssetShield\Commands;

use Illuminate\Console\Command;
use Vendor\AssetShield\AssetShieldManager;

class StatusCommand extends Command
{
    protected $signature = 'asset-shield:status {--json : Output the status as JSON}';

    protected $description = 'Print the AssetShield configuration snapshot';

    public function handle(AssetShieldManager $manager): int
    {
        $status = $manager->status();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('AssetShield');
        $this->line('-----------');
        $this->line(sprintf('  %-18s %s', 'Enabled:', $this->yesNo($status['enabled'])));
        $this->line(sprintf('  %-18s %s', 'Mode:', $status['mode']));
        $this->line(sprintf('  %-18s %s', 'Route prefix:', $status['route_prefix']));
        $this->line(sprintf('  %-18s %s', 'Manifest:', $status['manifest_found'] ? 'found' : 'not found'));
        $this->line(sprintf('  %-18s %s', 'Registry:', $this->registryState($status)));
        $this->line(sprintf('  %-18s %s', 'Signed URLs:', $status['signed_urls'] ? 'enabled' : 'disabled'));
        $this->line(sprintf('  %-18s %ss', 'Expiration:', $status['expires']));
        $this->line(sprintf('  %-18s %s', 'Obfuscation:', $status['obfuscation_enabled'] ? $status['obfuscation_preset'] : 'disabled'));
        $this->line(sprintf('  %-18s %s', 'Source Maps:', $status['source_maps'] ? 'enabled' : 'disabled'));
        $this->line(sprintf('  %-18s %s', 'Driver:', $status['driver']));

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function registryState(array $status): string
    {
        if (! $status['registry_found']) {
            return 'missing';
        }

        return $status['registry_valid'] ? 'valid' : 'invalid';
    }
}