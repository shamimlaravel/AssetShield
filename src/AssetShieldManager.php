<?php

declare(strict_types=1);

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
    /**
     * @param  array<string, mixed>  $config
     */
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
     *
     * @return array{file: string, original: string|null, opaque: string, type: string, integrity: string|null, mime: string|null, url: string}
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
     *
     * @param  bool|null  $signed   null = follow config, true = force signed,
     *                              false = attempt unsigned (config wins when
     *                              signing is enforced).
     * @param  int|\DateTimeInterface|null  $expires  absolute Unix timestamp
     *                              or a DateTimeInterface (Carbon is fine).
     */
    public function url(string $entry, ?bool $signed = null, int|\DateTimeInterface|null $expires = null): string
    {
        if (! $this->isEnabled() || ! $this->runtimeEnabled()) {
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

        $record = $this->recordFor($entry);

        return $this->scriptTag($record);
    }

    public function style(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $record = $this->recordFor($entry);

        return $this->styleTag($record);
    }

    /**
     * Render the tag implied by the asset type (script vs stylesheet).
     */
    public function render(string $entry): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $record = $this->recordFor($entry);

        return $record['type'] === 'style' ? $this->styleTag($record) : $this->scriptTag($record);
    }

    /**
     * Convenience wrapper for Vite entry lists. Pass the same entry points you
     * would to @vite() — a single entry or an iterable of entries, e.g.
     * shieldVite(["resources/css/app.css", "resources/js/app.js"]). Unregistered
     * entries are skipped (with a log) instead of forced through AssetShield,
     * keeping @vite() compatibility intact.
     *
     * @param  iterable<array-key, string>  $entries
     */
    public function renderVite(iterable $entries): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $html = '';

        foreach ($entries as $entry) {
            $record = $this->registry->entryForLogical((string) $entry);

            if ($record === null) {
                logger()->warning('AssetShield skipped unregistered entry "'.(string) $entry.'" in @shieldVite().');

                continue;
            }

            $html .= $record['type'] === 'style' ? $this->styleTag($record) : $this->scriptTag($record);
        }

        return $html;
    }

    /**
     * @param  int|\DateTimeInterface|null  $expires  absolute Unix timestamp
     *                              or a DateTimeInterface (Carbon is fine).
     */
    public function sign(string $assetId, int|\DateTimeInterface|null $expires = null): string
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

    /**
     * @return array{logical: string, file: string, original: string|null, opaque: string, type: string, integrity: string|null}
     */
    private function recordFor(string $entry): array
    {
        $record = $this->registry->entryForLogical($entry);

        if ($record === null) {
            throw AssetNotFoundException::fromRegistryEntry($entry);
        }

        return $record;
    }

    /**
     * @param  array{logical: string, file: string, original: string|null, opaque: string, type: string, integrity: string|null}  $record
     */
    private function scriptTag(array $record): string
    {
        $url = $this->urlForTag($record);
        $integrity = $record['integrity'] ?? null;

        $attributes = 'src="'.e($url).'"';

        if ($integrity !== null) {
            $attributes .= ' integrity="'.e($integrity).'" crossorigin="anonymous"';
        }

        return '<script '.$attributes.'></script>';
    }

    /**
     * @param  array{logical: string, file: string, original: string|null, opaque: string, type: string, integrity: string|null}  $record
     */
    private function styleTag(array $record): string
    {
        $url = $this->urlForTag($record);
        $integrity = $record['integrity'] ?? null;

        $attributes = 'rel="stylesheet" href="'.e($url).'"';

        if ($integrity !== null) {
            $attributes .= ' integrity="'.e($integrity).'" crossorigin="anonymous"';
        }

        return '<link '.$attributes.'>';
    }

    /**
     * @param  array{logical: string, file: string, original: string|null, opaque: string, type: string, integrity: string|null}  $record
     */
    private function urlForTag(array $record): string
    {
        if ($this->runtimeEnabled()) {
            return $this->urlGenerator->urlForOpaque($record['opaque']);
        }

        return '/'.$record['file'];
    }

    private function registryValid(): bool
    {
        if (! $this->registry->fileExists()) {
            return false;
        }

        return count($this->registry->validate($this->manifest)) === 0;
    }
}