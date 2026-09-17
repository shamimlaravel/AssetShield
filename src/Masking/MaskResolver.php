<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Masking;

/**
 * Produces the deterministic, presentational file name for a compiled asset.
 *
 * Masking is naming-only: it hides the semantic asset name publicly while
 * keeping the mapping fully deterministic for validation, legend and registry.
 * It is not an access-control or anti-inspection mechanism.
 */
interface MaskResolver
{
    /**
     * @param  string  $logical       the logical entry key (e.g. resources/js/app.js)
     * @param  string  $originalFile  the pre-mask file path relative to public root
     * @return string  the masked basename (no directory prefix)
     */
    public function resolve(string $logical, string $originalFile): string;
}