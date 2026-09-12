<?php

namespace Vendor\AssetShield;

use Vendor\AssetShield\Exceptions\AssetNotFoundException;
use Vendor\AssetShield\Signer\AssetSigner;
use Vendor\AssetShield\Support\MimeMapper;

/**
 * Public surface of the package (facade accessor "asset-shield").
 *
 *   AssetShield::url('resources/js/app.js')
 *   AssetShield::script('resources/js/app.js')
 *   AssetShield::style('resources/css/app.css')
 *   AssetShield::resolve('resources/js/app.js')
 */
class AssetShieldManager
{
    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetManifest $manifest,
        private readonly AssetUrlGenerator $urlGenerator,
        private readonly AssetSigner $signer,
        private readonly array $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function mode(): string
    {
        return (string) ($this->config['mode'] ?? 'protected');
    }

    /**
     * Resolved metadata for an entry (compiled, opaque, type, mime, url).
     */
    public function resolve(string $entry): array
    {
        if (! $this->isEnabled()) {
            throw new AssetNotFoundException('AssetShield is disabled.');
        }

        $record = $this->registry->entryForLogical($entry);

        if ($record === null) {
            throw AssetNotFoundException::fromRegistryEntry($entry);
        }

        return [
            'compiled' => $record['compiled'],
            'opaque' => $record['opaque'],
            'type' => $record['type'],
            'integrity' => $record['integrity'],
            'mime' => MimeMapper::forPath($record['compiled']),
            'url' => $this->url($entry),
        ];
    }

    /**
     * Protected URL for a logical entry. When AssetShield is disabled this
     * falls back to the plain Vite/public URL so rollback stays one line.
     */
    public function url(string $entry, ?bool $signed = null, ?int $expires = null): string
    {
        if (! $this->isEnabled()) {
            $record = $this->manifest->resolve($entry);

            return '/'.$this->manifest->compiledPath($record['file']);
        }

        return $this->urlGenerator->url($entry, $signed, $expires);
    }

    public function script(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return '<script src="'.e($this->url($entry)).'"></script>';
    }

    public function style(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return '<link rel="stylesheet" href="'.e($this->url($entry)).'">';
    }

    /**
     * Render the tag implied by the asset type (script vs stylesheet).
     */
    public function render(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $record = $this->registry->entryForLogical($entry);

        if ($record === null) {
            throw AssetNotFoundException::fromRegistryEntry($entry);
        }

        return $record['type'] === 'style' ? $this->style($entry) : $this->script($entry);
    }

    /**
     * Convenience wrapper for Vite entry lists:
     *   @shieldVite(['resources/css/app.css', 'resources/js/app.js'])
     *
     * Unregistered entries are skipped (with a log) instead of forced through
     * AssetShield, keeping @vite() compatibility intact.
     */
    public function renderVite(iterable $entries): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $html = '';

        foreach ($entries as $entry) {
            if ($this->registry->entryForLogical((string) $entry) === null) {
                logger()->warning('AssetShield skipped unregistered entry "'.(string) $entry.'" in @shieldVite().');

                continue;
            }

            $html .= $this->render((string) $entry);
        }

        return $html;
    }

    public function sign(string $assetId, ?int $expires = null): string
    {
        return $this->signer->sign($assetId, $expires);
    }

    /**
     * @return array<string,mixed> snapshot for asset-shield:status.
     */
    public function status(): array
    {
        $signature = (array) ($this->config['signature'] ?? []);
        $obfuscation = (array) ($this->config['obfuscation'] ?? []);

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->mode(),
            'route_prefix' => (string) ($this->config['route_prefix'] ?? 'assets'),
            'manifest_found' => $this->manifest->exists(),
            'manifest_path' => $this->manifest->path(),
            'registry_found' => $this->registry->fileExists(),
            'registry_path' => $this->registry->path(),
            'registry_valid' => $this->registryValid(),
            'signed_urls' => (bool) ($signature['enabled'] ?? false),
            'expires' => (int) ($signature['expires'] ?? 300),
            'obfuscation_enabled' => (bool) ($obfuscation['enabled'] ?? false),
            'obfuscation_preset' => (string) ($obfuscation['preset'] ?? 'balanced'),
            'source_maps' => (bool) ($this->config['source_maps'] ?? false),
            'driver' => (string) ($this->config['driver'] ?? 'public'),
            'cache_max_age' => (int) (($this->config['cache'] ?? [])['max_age'] ?? 31536000),
        ];
    }

    public function signer(): AssetSigner
    {
        return $this->signer;
    }

    public function registry(): AssetRegistry
    {
        return $this->registry;
    }

    public function manifest(): AssetManifest
    {
        return $this->manifest;
    }

    private function registryValid(): bool
    {
        if (! $this->registry->fileExists()) {
            return false;
        }

        return count($this->registry->validate($this->manifest)) === 0;
    }
}