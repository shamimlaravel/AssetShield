/**
 * Deterministic FNV-1a for MASK NAMING ONLY.
 *
 * Mirrors `Shamimstack\AssetShield\Support\Fnv1a`. Public, non-cryptographic — MUST
 * never be used where a security boundary is required (opaque ids are
 * HMAC-SHA256 under APP_KEY and live PHP-side).
 */
export function fnv1a32(data: string): number {
    let hash = 0x811c9dc5;

    for (let i = 0; i < data.length; i++) {
        hash ^= data.charCodeAt(i) & 0xff;
        hash = Math.imul(hash, 0x01000193);
    }

    return hash >>> 0;
}

export function hex8(data: string): string {
    return fnv1a32(data).toString(16).padStart(8, '0');
}