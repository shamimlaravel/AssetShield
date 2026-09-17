<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Support;

/**
 * Deterministic FNV-1a hashing for MASK NAMING ONLY.
 *
 * FNV-1a is fast and deterministic — perfect for mapping files to stable
 * presentational names. It is a public, non-cryptographic algorithm: it MUST
 * never be used as a security mechanism. Opaque runtime IDs are always derived
 * with HMAC-SHA256 under the application key (see OpaqueId).
 */
final class Fnv1a
{
    private const OFFSET = 0x811c9dc5;
    private const PRIME = 0x01000193;

    /**
     * 32-bit FNV-1a hash as an unsigned integer (0..2^32-1).
     */
    public static function hash32(string $data): int
    {
        $hash = self::OFFSET;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $hash ^= ord($data[$i]);
            $hash = ($hash * self::PRIME) & 0xFFFFFFFF;
        }

        return $hash & 0xFFFFFFFF;
    }

    /**
     * 8-digit lowercase hex representation of the hash.
     */
    public static function hex8(string $data): string
    {
        return sprintf('%08x', self::hash32($data));
    }
}