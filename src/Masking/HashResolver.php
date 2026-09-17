<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Masking;

use Shamimstack\AssetShield\Support\Fnv1a;

/**
 * "Nameless" strategy: replaces the file name with a deterministic 8-hex id,
 * e.g. assets/8f4a1c7d.js.
 *
 * Deterministic under a fixed seed so builds, registry and legend stay in sync.
 * Collision-avoidance suffixes are applied by MaskPlanner, not here.
 */
class HashResolver implements MaskResolver
{
    public function __construct(private readonly string $seed)
    {
    }

    public function resolve(string $logical, string $originalFile): string
    {
        return Fnv1a::hex8($this->seed.':'.$originalFile).$this->extension($originalFile);
    }

    private function extension(string $originalFile): string
    {
        $ext = pathinfo($originalFile, PATHINFO_EXTENSION);

        return $ext === '' ? '' : '.'.$ext;
    }
}