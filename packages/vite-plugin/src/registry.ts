export interface RegistryEntry {
    logical: string;
    compiled: string;
    type: 'script' | 'style';
    integrity?: string;
}

export interface RegistryFile {
    version: 1;
    built_at: string;
    entries: RegistryEntry[];
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

export function buildRegistry(manifest: Record<string, unknown>, buildDir: string): RegistryEntry[] {
    const entries: RegistryEntry[] = [];

    for (const [logical, value] of Object.entries(manifest)) {
        if (value === null || typeof value !== 'object') {
            continue;
        }

        const record = value as ManifestRecord;

        if (typeof record.file !== 'string' || record.file.length === 0) {
            continue;
        }

        const file = normalizeSlashes(record.file).replace(/^\/+/, '');
        const base = normalizeSlashes(buildDir).replace(/^\/+|\/+$/g, '');

        if (file.length === 0) {
            continue;
        }

        const entry: RegistryEntry = {
            logical,
            compiled: base.length > 0 ? `${base}/${file}` : file,
            type: file.toLowerCase().endsWith('.css') ? 'style' : 'script',
        };

        if (typeof record.integrity === 'string' && record.integrity.length > 0) {
            entry.integrity = record.integrity;
        }

        entries.push(entry);
    }

    return entries;
}

export async function writeRegistry(registryFile: string, entries: RegistryEntry[], fsLike?: FsLike): Promise<RegistryFile> {
    const fs: FsLike = fsLike ?? (await import('node:fs'));

    const normalized = normalizeSlashes(registryFile);
    const slash = normalized.lastIndexOf('/');

    if (slash > 0) {
        const directory = normalized.slice(0, slash);

        if (fs.existsSync === undefined || !fs.existsSync(directory)) {
            fs.mkdirSync?.(directory, { recursive: true });
        }
    }

    const file: RegistryFile = {
        version: 1,
        built_at: new Date().toISOString(),
        entries,
    };

    fs.writeFileSync?.(registryFile, JSON.stringify(file, null, 2) + '\n');

    return file;
}