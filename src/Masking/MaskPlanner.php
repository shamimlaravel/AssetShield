<?php

namespace Shamimstack\AssetShield\Masking;

use Shamimstack\AssetShield\Support\Fnv1a;

/**
 * Orchestrates deterministic file mask for a build.
 *
 * Mirrors the Vite plugin behaviour 1:1 so that PHP-side validation, doctor
 * checks and registry/legend reconstruction agree with what the plugin produced.
 *
 * This class NEVER renames files. Masking is applied at emit time by the
 * Vite plugin; the PHP side only plans (for validation/tests) and consumes the
 * legend written by the plugin.
 */
class MaskPlanner
{
    private readonly MaskResolver $resolver;

    /** @var array{
     *     enabled?: bool,
     *     strategy?: string,
     *     seed?: string,
     *     aliases?: array<string, string>,
     *     include?: array<int, string>,
     *     exclude?: array<int, string>,
     *  }
     */
    private array $config;

    /** @var array<string, true> */
    private array $used = [];

    /** @param  array{
     *     enabled?: bool,
     *     strategy?: string,
     *     seed?: string,
     *     aliases?: array<string, string>,
     *     include?: array<int, string>,
     *     exclude?: array<int, string>,
     *  }  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $enabled = (bool) ($config['enabled'] ?? false);

        if (! $enabled) {
            $this->resolver = new PreserveResolver();

            return;
        }

        $seed = self::resolveSeed($config['seed'] ?? '');
        $this->resolver = match ($config['strategy'] ?? 'nameless') {
            'codename' => new CodenameResolver($seed),
            'preserve' => new PreserveResolver(),
            default => new HashResolver($seed),
        };
    }

    /**
     * Compute the final (masked) file path for one compiled asset.
     *
     * @param  string  $logical       logical entry key
     * @param  string  $originalFile  pre-mask file path relative to public root
     * @return array{original: string, file: string}
     */
    public function plan(string $logical, string $originalFile): array
    {
        $dir = dirname($originalFile);
        $isRoot = $dir === '.' || $dir === '\\' || $dir === '';
        $base = $this->finalBasename($logical, $originalFile);
        $ext = pathinfo($originalFile, PATHINFO_EXTENSION);

        $candidate = $isRoot ? $base : $dir.'/'.$base;

        $k = 0;
        while (isset($this->used[$candidate])) {
            $k++;
            $suffix = substr(Fnv1a::hex8(self::resolveSeed($this->config['seed'] ?? '').':collide:'.$originalFile.':'.$k), 0, 2);

            $name = $ext === ''
                ? $base.'-'.$suffix
                : preg_replace('/\.'.preg_quote($ext, '/').'$/', '-'.$suffix.'.'.$ext, $base);

            $candidate = $isRoot ? $name : $dir.'/'.$name;
        }

        $this->used[$candidate] = true;

        return ['original' => $originalFile, 'file' => $candidate];
    }

    /**
     * Whether a file path should be masked per the include/exclude rules.
     */
    public function shouldMask(string $path): bool
    {
        $include = ($this->config['include'] ?? []) === [] ? ['**'] : (array) $this->config['include'];
        $exclude = (array) ($this->config['exclude'] ?? []);

        return $this->matchesAny($path, $include) && ! $this->matchesAny($path, $exclude);
    }

    private function finalBasename(string $logical, string $originalFile): string
    {
        if (isset($this->config['aliases'][$logical])) {
            $alias = (string) $this->config['aliases'][$logical];
            $ext = pathinfo($originalFile, PATHINFO_EXTENSION);

            return $ext !== '' && ! str_ends_with($alias, '.'.$ext) ? $alias.'.'.$ext : $alias;
        }

        if (($this->config['enabled'] ?? false) === false || ! $this->shouldMask($originalFile)) {
            return basename($originalFile);
        }

        return $this->resolver->resolve($logical, $originalFile);
    }

    /** @param  array<int, string>  $patterns */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Empty/missing seeds fall back to "asset-shield", mirroring the Vite
     * plugin (`seed || 'asset-shield'`) so PHP recomputation always matches.
     */
    private static function resolveSeed(mixed $seed): string
    {
        $seed = (string) ($seed ?? '');

        return $seed === '' ? 'asset-shield' : $seed;
    }
}