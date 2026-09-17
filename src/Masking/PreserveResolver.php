<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Masking;

/**
 * Keeps the original compiled file name untouched.
 *
 * The default strategy: used whenever mask is disabled or a path matches
 * the include/exclude rules in favour of preservation.
 */
class PreserveResolver implements MaskResolver
{
    public function resolve(string $logical, string $originalFile): string
    {
        return basename($originalFile);
    }
}