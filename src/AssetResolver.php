<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield;

/**
 * Resolves opaque ids and logical entries to AssetIdentity values.
 *
 * All lookups go exclusively through the registry; never through the
 * filesystem or the request string. Opaque ids are always derived server-side,
 * never read from disk or from the build artifact.
 */
class AssetResolver
{
    public function __construct(
        private readonly AssetRegistry $registry,
    ) {
    }

    /**
     * Identity for an opaque id, or null when unknown (caller maps to 404).
     */
    public function resolveOpaque(string $opaque): ?AssetIdentity
    {
        $entry = $this->registry->entryForOpaque($opaque);

        return $entry === null ? null : $this->identityFromEntry($entry);
    }

    /**
     * @param  array{logical:string,file:string,opaque:string,type:string,integrity:?string,original:?string}  $entry
     */
    private function identityFromEntry(array $entry): AssetIdentity
    {
        return new AssetIdentity(
            $entry['logical'],
            $entry['file'],
            $entry['opaque'],
            $entry['type'],
            $entry['integrity'] ?? null,
            $entry['original'] ?? null,
        );
    }
}