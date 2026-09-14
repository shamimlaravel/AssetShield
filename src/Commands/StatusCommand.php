<?php

namespace Shamimstack\AssetShield\Commands;

use Illuminate\Console\Command;
use Shamimstack\AssetShield\AssetShieldManager;
use Shamimstack\AssetShield\Obfuscation\ObfuscationEngine;

class StatusCommand extends Command
{
    protected $signature = 'asset-shield:status {--json : Output the status as JSON}';

    protected $description = 'Print the AssetShield configuration snapshot';

    public function handle(AssetShieldManager $manager, ObfuscationEngine $engine): int
    {
        $status = $manager->status();
        $obfuscationEnabled = (bool) $status['obfuscation_enabled'];

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('AssetShield');
        $this->line('-----------');
        $this->line(sprintf('  %-20s %s', 'Enabled:', $this->yesNo($status['enabled'])));
        $this->line(sprintf('  %-20s %s', 'Environment:', $status['environment']));
        $this->line(sprintf('  %-20s %s', 'Runtime delivery:', $this->yesNo($status['runtime_enabled'])));
        $this->line(sprintf('  %-20s %s', 'Route prefix:', $status['route_prefix']));
        $this->line(sprintf('  %-20s %s', 'Manifest:', $status['manifest_found'] ? 'found' : 'not found'));
        $this->line(sprintf('  %-20s %s', 'Registry:', $this->registryState($status)));
        $this->line(sprintf('  %-20s %s', 'Signed URLs:', $this->yesNo($status['signed_urls'])));
        $this->line(sprintf('  %-20s %s', 'Expiration:', $status['expires'].'s'));
        $this->line(sprintf('  %-20s %s', 'Masking:', $status['mask_enabled'] ? $status['mask_strategy'] : 'disabled'));
        $this->line(sprintf('  %-20s %s', 'Obfuscation:', $obfuscationEnabled ? $status['obfuscation_preset'] : 'disabled'));
        $this->line(sprintf('  %-20s %s', '  . engine:', $engine->isAvailable() ? $this->yesNo(true) : 'not found'));
        $this->line(sprintf('  %-20s %s', 'Source Maps:', $this->yesNo($status['source_maps'])));
        $this->line(sprintf('  %-20s %s', 'Driver:', $status['driver']));

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