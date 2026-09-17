export type RegistryType = 'script' | 'style' | 'image' | 'font';

export interface RegistryAsset {
    type: RegistryType;
    /** Compiled file path relative to the public root, e.g. build/assets/8f4a1c7d.js */
    file: string;
    /** Pre-mask compiled file path relative to the public root (renamed assets only). */
    original?: string;
    integrity?: string;
}

export interface RegistryFile {
    version: 1;
    built_at: string;
    assets: Record<string, RegistryAsset>;
}

export interface LegendFile {
    version: 1;
    built_at: string;
    /** Alias of a LegendEntry whose key is the pre-mask file. */
    seed: string;
    entries: Record<string, LegendEntry>;
}

export interface LegendEntry {
    /** Pre-mask file path (outDir-relative), equals the legend key. */
    original: string;
    /** Final, masked file path (outDir-relative). */
    masked: string;
}

export interface FsLike {
    existsSync?: (path: string) => boolean;
    mkdirSync?: (path: string, options?: { recursive?: boolean }) => void;
    readFileSync?: (path: string, encoding: 'utf8') => string;
    writeFileSync?: (path: string, data: string) => void;
}

interface ManifestRecord {
    file?: unknown;
    integrity?: unknown;
}

function normalizeSlashes(path: string): string {
    return path.replace(/\\/g, '/');
}

/**
 * Mirrors the PHP side (MimeMapper::family): css -> style, js/mjs/cjs ->
 * script, image extensions -> image, font extensions -> font, unknown -> script.
 */
function classify(file: string): RegistryType {
    const extension = file.toLowerCase().slice(file.lastIndexOf('.') + 1);

    if (extension === 'css') {
        return 'style';
    }

    if (['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico'].includes(extension)) {
        return 'image';
    }

    if (['woff', 'woff2', 'ttf', 'otf', 'eot'].includes(extension)) {
        return 'font';
    }

    return 'script';
}

/**
 * Build the registry as a public-root-relative asset map.
 *
 * @param manifest Vite manifest.json (logical -> record with `file`)
 * @param buildDir public-root-relative build directory, e.g. "build"
 * @param originalByFinal map of final (masked) manifest file -> pre-mask file
 */
export function buildRegistry(
    manifest: Record<string, unknown>,
    buildDir: string,
    originalByFinal?: ReadonlyMap<string, string>,
): RegistryFile {
    const base = normalizeSlashes(buildDir).replace(/^\/+|\/+$/g, '');
    const assets: Record<string, RegistryAsset> = {};

    for (const [logical, value] of Object.entries(manifest)) {
        if (value === null || typeof value !== 'object') {
            continue;
        }

        const record = value as ManifestRecord;

        if (typeof record.file !== 'string' || record.file.length === 0) {
            continue;
        }

        const file = normalizeSlashes(record.file).replace(/^\/+/, '');

        if (file.length === 0) {
            continue;
        }

        const compiled = base.length > 0 ? `${base}/${file}` : file;

        const asset: RegistryAsset = {
            type: classify(file),
            file: compiled,
        };

        const original = originalByFinal?.get(file);

        if (original !== undefined && original !== file) {
            asset.original = base.length > 0 ? `${base}/${original}` : original;
        }

        if (typeof record.integrity === 'string' && record.integrity.length > 0) {
            asset.integrity = record.integrity;
        }

        assets[logical] = asset;
    }

    return {
        version: 1,
        built_at: new Date().toISOString(),
        assets,
    };
}

export async function writeRegistry(
    registryFile: string,
    registry: RegistryFile,
    fsLike?: FsLike,
): Promise<RegistryFile> {
    const fs: FsLike = fsLike ?? (await import('node:fs'));

    writeJson(fs, registryFile, registry);

    return registry;
}

/**
 * Build the legend from a manifest whose files were renamed by mask.
 *
 * @param manifest      Vite manifest.json (logical -> record with `file`)
 * @param originalByFinal final (masked) file -> pre-mask file
 * @param seed          mask seed captured for the header
 */
export function buildLegend(
    manifest: Record<string, unknown>,
    originalByFinal?: ReadonlyMap<string, string>,
    seed = '',
): LegendFile {
    const entries: Record<string, LegendEntry> = {};

    for (const [, value] of Object.entries(manifest)) {
        if (value === null || typeof value !== 'object') {
            continue;
        }

        const record = value as ManifestRecord;

        if (typeof record.file !== 'string') {
            continue;
        }

        const finalFile = normalizeSlashes(record.file).replace(/^\/+/, '');
        const original = originalByFinal?.get(finalFile);

        if (original === undefined || original === finalFile) {
            continue;
        }

        entries[original] = {
            original,
            masked: finalFile,
        };
    }

    return {
        version: 1,
        built_at: new Date().toISOString(),
        seed,
        entries,
    };
}

export async function writeLegend(
    legendFile: string,
    legend: LegendFile,
    fsLike?: FsLike,
): Promise<LegendFile> {
    const fs: FsLike = fsLike ?? (await import('node:fs'));

    writeJson(fs, legendFile, legend);

    return legend;
}

function writeJson(fs: FsLike, target: string, payload: unknown): void {
    const normalized = normalizeSlashes(target);
    const slash = normalized.lastIndexOf('/');

    if (slash > 0) {
        const directory = normalized.slice(0, slash);

        if (fs.existsSync === undefined || !fs.existsSync(directory)) {
            fs.mkdirSync?.(directory, { recursive: true });
        }
    }

    fs.writeFileSync?.(target, JSON.stringify(payload, null, 2) + '\n');
}