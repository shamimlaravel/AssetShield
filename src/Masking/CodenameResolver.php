<?php

namespace Shamimstack\AssetShield\Masking;

use Shamimstack\AssetShield\Support\Fnv1a;

/**
 * "Codename" strategy: maps a file to a deterministic adjective-noun pair,
 * e.g. assets/swift-tiger.js.
 *
 * Deterministic under a fixed seed so builds, registry and legend stay in sync.
 * Collision-avoidance suffixes are applied by MaskPlanner, not here.
 */
class CodenameResolver implements MaskResolver
{
    public function __construct(
        private readonly string $seed,
        /** @var array<int, string> */
        private array $adjectives = [],
        /** @var array<int, string> */
        private array $nouns = [],
    ) {
        $this->adjectives = $adjectives ?: Dictionary::adjectives();
        $this->nouns = $nouns ?: Dictionary::nouns();
    }

    public function resolve(string $logical, string $originalFile): string
    {
        $adjective = $this->adjectives[Fnv1a::hash32($this->seed.':a:'.$originalFile) % count($this->adjectives)];
        $noun = $this->nouns[Fnv1a::hash32($this->seed.':n:'.$originalFile) % count($this->nouns)];

        return $adjective.'-'.$noun.$this->extension($originalFile);
    }

    private function extension(string $originalFile): string
    {
        $ext = pathinfo($originalFile, PATHINFO_EXTENSION);

        return $ext === '' ? '' : '.'.$ext;
    }
}