import type { Plugin, ResolvedConfig } from 'vite';

import { makeChunkPredicate, type ObfuscateChunks } from './chunkRules';
import { ObfuscationEngine } from './obfuscation/engine';
import type { ObfuscationPreset } from './obfuscation/presets';
import { buildRegistry, type FsLike, writeRegistry } from './registry';

export type { ObfuscateChunks } from './chunkRules';
export { ObfuscationEngine } from './obfuscation/engine';
export type { ObfuscationPreset } from './obfuscation/presets';
export { buildRegistry, writeRegistry } from './registry';
export type { FsLike, RegistryEntry, RegistryFile } from './registry';
export { matchesAny } from './glob';

export interface AssetShieldViteOptions {
    enabled?: boolean;
    registryFile?: string;
    buildDir?: string;
    sourceMaps?: boolean;
    obfuscation?: {
        enabled?: boolean;
        preset?: ObfuscationPreset;
        engine?: 'javascript-obfuscator';
        obfuscateChunks?: ObfuscateChunks;
        include?: string[];
        exclude?: string[];
    };
}

export interface PluginDeps {
    engine?: ObfuscationEngine;
    fs?: FsLike;
}

interface NormalizedOptions {
    enabled: boolean;
    registryFile: string;
    buildDir: string;
    sourceMaps: boolean;
    obfuscation: {
        enabled: boolean;
        preset: ObfuscationPreset;
        obfuscateChunks: ObfuscateChunks;
        include?: string[];
        exclude?: string[];
    };
}

interface HookContext {
    warn(message: string): void;
    info(message: string): void;
}

function normalizeOptions(input: AssetShieldViteOptions): NormalizedOptions {
    const obfuscation = input.obfuscation ?? {};

    return {
        enabled: input.enabled ?? true,
        registryFile: input.registryFile ?? 'storage/asset-shield/registry.json',
        buildDir: input.buildDir ?? 'build',
        sourceMaps: input.sourceMaps ?? false,
        obfuscation: {
            enabled: obfuscation.enabled ?? false,
            preset: obfuscation.preset ?? 'balanced',
            obfuscateChunks: obfuscation.obfuscateChunks ?? 'application',
            include: obfuscation.include,
            exclude: obfuscation.exclude,
        },
    };
}

function normalizePath(path: string): string {
    return path.replace(/\\/g, '/').replace(/\/+$/, '');
}

function isAbsolute(path: string): boolean {
    return /^[a-zA-Z]:\//.test(path) || path.startsWith('/');
}

function projectRelative(target: string, root: string): string {
    const normalizedTarget = normalizePath(target);
    const normalizedRoot = normalizePath(root);

    if (normalizedTarget === normalizedRoot) {
        return '';
    }

    if (normalizedTarget.startsWith(normalizedRoot + '/')) {
        return normalizedTarget.slice(normalizedRoot.length + 1);
    }

    return normalizedTarget;
}

function resolveBuildDir(outDir: string, root: string, fallback: string): string {
    if (isAbsolute(outDir)) {
        const relative = projectRelative(outDir, root);

        if (relative !== '') {
            const stripped = relative.startsWith('public/')
                ? relative.slice('public/'.length)
                : relative;

            return stripped.length > 0 ? stripped : fallback;
        }

        return fallback;
    }

    const stripped = normalizePath(outDir).startsWith('public/')
        ? normalizePath(outDir).slice('public/'.length)
        : normalizePath(outDir);

    return stripped.length > 0 ? stripped : fallback;
}

function resolveRegistryPath(root: string, registryFile: string): string {
    if (isAbsolute(registryFile)) {
        return registryFile;
    }

    return `${normalizePath(root)}/${normalizePath(registryFile)}`;
}

export function assetShieldVite(input: AssetShieldViteOptions = {}, deps: PluginDeps = {}): Plugin {
    const options = normalizeOptions(input);
    const engine = deps.engine ?? new ObfuscationEngine();
    const externalFs: FsLike | undefined = deps.fs;

    const predicate = makeChunkPredicate({
        mode: options.obfuscation.obfuscateChunks,
        include: options.obfuscation.include,
        exclude: options.obfuscation.exclude,
    });

    let config: ResolvedConfig | undefined;
    let warnedAboutSourceMaps = false;
    let warnedAboutObfuscator = false;

    return {
        name: 'asset-shield',
        apply: 'build',
        configResolved(resolved) {
            config = resolved;
            const ctx = this as unknown as HookContext;

            if (options.sourceMaps) {
                if (!warnedAboutSourceMaps) {
                    ctx.warn('AssetShield: sourceMaps explicitly enabled. Hosted source maps are never hidden by AssetShield; keep them out of public contexts.');
                    warnedAboutSourceMaps = true;
                }
            } else if (resolved.build.sourcemap) {
                resolved.build.sourcemap = false;
                ctx.warn('AssetShield: source maps disabled because the sourceMaps option is off. Set sourceMaps: true to keep them.');
            }
        },
        async renderChunk(code, chunk) {
            const ctx = this as unknown as HookContext;

            if (!options.enabled || !options.obfuscation.enabled) {
                return null;
            }

            if (!predicate({ fileName: chunk.fileName, isEntry: chunk.isEntry, moduleIds: chunk.moduleIds })) {
                return null;
            }

            const result = await engine.transformChunk(chunk.fileName, code, options.obfuscation.preset);

            if (!result.obfuscated) {
                if (!warnedAboutObfuscator) {
                    ctx.warn('AssetShield: obfuscation enabled but no javascript-obfuscator is available; chunks were left untouched.');
                    warnedAboutObfuscator = true;
                }

                return null;
            }

            return { code: result.code, map: null };
        },
        async closeBundle() {
            const ctx = this as unknown as HookContext;

            if (!options.enabled || config === undefined) {
                return;
            }

            const fs: FsLike = externalFs ?? (await import('node:fs'));
            const outDir = normalizePath(config.build.outDir);
            const manifestPath = `${outDir}/manifest.json`;

            if (fs.existsSync === undefined || !fs.existsSync(manifestPath)) {
                ctx.warn(`AssetShield: ${manifestPath} not found; registry was not written. Make sure the entry output plugin (e.g. laravel-vite-plugin) runs before this one.`);
                return;
            }

            let manifest: Record<string, unknown>;
            const readFile = fs.readFileSync;

            if (readFile === undefined) {
                ctx.warn(`AssetShield: filesystem is not readable; registry was not written.`);
                return;
            }

            try {
                manifest = JSON.parse(readFile(manifestPath, 'utf8')) as Record<string, unknown>;
            } catch {
                ctx.warn(`AssetShield: ${manifestPath} is not valid JSON; registry was not written.`);
                return;
            }

            const buildDir = resolveBuildDir(outDir, config.root, options.buildDir);
            const entries = buildRegistry(manifest, buildDir);
            const registryPath = resolveRegistryPath(config.root, options.registryFile);

            await writeRegistry(registryPath, entries, fs);

            ctx.info(`AssetShield wrote ${entries.length} entries to ${registryPath}`);
        },
    };
}

export default assetShieldVite;