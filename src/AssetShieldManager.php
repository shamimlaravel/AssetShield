<?php

namespace Shamimstack\AssetShield;

use Shamimstack\AssetShield\Exceptions\AssetNotFoundException;
use Shamimstack\AssetShield\Signer\AssetSigner;

/**
 * Public surface of the package (facade accessor "asset-shield").
 *
 *   AssetShield::url('resources/js/app.js')
 *   AssetShield::script('resources/js/app.js')
 *   AssetShield::style('resources/css/app.css')
 *   AssetShield::resolve('resources/js/app.js')
 *
 * URL behaviour:
 *  - disabled:            plain `@vite()` fallback path (one-line rollback)
 *  - runtime enabled:     protected `/assets/as_...` URLs (signed when on)
 *  - runtime disabled:    plain public path, web server serves the file
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

    public function runtimeEnabled(): bool
    {
        return (bool) (($this->config['runtime'] ?? [])['enabled'] ?? false);
    }

    public function environment(): string
    {
        return (string) ($this->config['environment'] ?? 'production');
    }

    /**
     * Resolved metadata for an entry (file, opaque, type, mime, url).
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
            'file' => $record['file'],
            'original' => $record['original'],
            'opaque' => $record['opaque'],
            'type' => $record['type'],
            'integrity' => $record['integrity'],
            'mime' => \Shamimstack\AssetShield\Support\MimeMapper::forPath($record['file']),
            'url' => $this->url($entry),
        ];
    }

    /**
     * URL for a logical entry. When AssetShield is disabled this falls back to
     * the plain Vite/public URL; when runtime delivery is off the web server
     * serves the same public path directly.
     */
    public function url(string $entry, ?bool $signed = null, ?int $expires = null): string
    {
        if (! $this->isEnabled()) {
            $record = $this->manifest->resolve($entry);

            return '/'.$this->manifest->compiledPath($record['file']);
        }

        if (! $this->runtimeEnabled()) {
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

        $url = $this->url($entry);
        $integrity = $this->integrityFor($entry);

        $attributes = 'src="'.e($url).'"';

        if ($integrity !== null) {
            $attributes .= ' integrity="'.e($integrity).'" crossorigin="anonymous"';
        }

        return '<script '.$attributes.'></script>';
    }

    public function style(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $url = $this->url($entry);
        $integrity = $this->integrityFor($entry);

        $attributes = 'rel="stylesheet" href="'.e($url).'"';

        if ($integrity !== null) {
            $attributes .= ' integrity="'.e($integrity).'" crossorigin="anonymous"';
        }

        return '<link '.$attributes.'>';
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

        return ($record['type'] ?? '') === 'style' ? $this->style($entry) : $this->script($entry);
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
        $runtime = (array) ($this->config['runtime'] ?? []);
        $build = (array) ($this->config['build'] ?? []);
        $mask = (array) ($this->config['mask'] ?? []);
        $obfuscation = (array) ($this->config['obfuscation'] ?? []);
        $delivery = (array) ($this->config['delivery'] ?? []);

        return [
            'enabled' => $this->isEnabled(),
            'environment' => $this->environment(),
            'runtime_enabled' => $this->runtimeEnabled(),
            'route_prefix' => (string) ($runtime['route_prefix'] ?? 'assets'),
            'manifest_found' => $this->manifest->exists(),
            'manifest_path' => $this->manifest->path(),
            'registry_found' => $this->registry->fileExists(),
            'registry_path' => $this->registry->path(),
            'registry_valid' => $this->registryValid(),
            'signed_urls' => (bool) ($runtime['signed_urls'] ?? false),
            'expires' => (int) ($runtime['expires'] ?? 300),
            'mask_enabled' => (bool) ($mask['enabled'] ?? false),
            'mask_strategy' => (string) ($mask['strategy'] ?? 'preserve'),
            'obfuscation_enabled' => (bool) ($obfuscation['enabled'] ?? false),
            'obfuscation_preset' => (string) ($obfuscation['preset'] ?? 'balanced'),
            'obfuscation_exclude_vendor' => (bool) ($obfuscation['exclude_vendor'] ?? true),
            'source_maps' => (bool) ($build['source_maps'] ?? false),
            'driver' => (string) ($delivery['driver'] ?? 'public'),
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

    private function integrityFor(string $entry): ?string
    {
        return $this->registry->entryForLogical($entry)['integrity'] ?? null;
    }

    private function registryValid(): bool
    {
        if (! $this->registry->fileExists()) {
            return false;
        }

        return count($this->registry->validate($this->manifest)) === 0;
    }
}