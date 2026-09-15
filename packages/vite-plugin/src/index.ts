import type { Plugin, ResolvedConfig } from 'vite';

import { MaskPlanner, type MaskConfig, type MaskStrategy } from './mask/names';
import { hex8 } from './mask/fnv1a';
import { makeChunkPredicate, type ObfuscateChunks } from './chunkRules';
import { ObfuscationEngine } from './obfuscation/engine';
import { describePreset, type ObfuscationPreset, type ObfuscatorOptions } from './obfuscation/presets';
import { buildLegend, buildRegistry, type FsLike, writeLegend, writeRegistry } from './registry';

export type { ObfuscateChunks } from './chunkRules';
export { ObfuscationEngine } from './obfuscation/engine';
export type { ObfuscatorOutput, ObfuscationResult } from './obfuscation/engine';
export { describePreset, mergeOptions, presetOptions, PRESET_FEATURES } from './obfuscation/presets';
export type { ObfuscationPreset, ObfuscatorOptions } from './obfuscation/presets';
export { buildRegistry, buildLegend, writeRegistry, writeLegend } from './registry';
export type { FsLike, RegistryAsset, RegistryFile, LegendEntry, LegendFile } from './registry';
export { MaskPlanner, codenameName, namelessName } from './mask/names';
export type { MaskConfig, MaskStrategy } from './mask/names';
export { matchesAny } from './glob';

export type LogLevel = 'info' | 'warn' | 'error' | 'silent';

export interface MaskViteOptions {
    enabled?: boolean;
    strategy?: MaskStrategy;
    seed?: string;
    aliases?: Record<string, string>;
    include?: string[];
    exclude?: string[];
}

export interface AssetShieldViteOptions {
    enabled?: boolean;
    registryFile?: string;
    legendFile?: string;
    buildDir?: string;
    sourceMaps?: boolean;
    logLevel?: LogLevel;
    failOnError?: boolean;
    mask?: MaskViteOptions;
    obfuscation?: {
        enabled?: boolean;
        preset?: ObfuscationPreset;
        options?: ObfuscatorOptions;
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
    legendFile: string;
    buildDir: string;
    sourceMaps: boolean;
    logLevel: LogLevel;
    failOnError: boolean;
    mask: {
        enabled: boolean;
        strategy: MaskStrategy;
        seed: string;
        aliases: Record<string, string>;
        include: string[];
        exclude: string[];
    };
    obfuscation: {
        enabled: boolean;
        preset: ObfuscationPreset;
        options: ObfuscatorOptions | undefined;
        obfuscateChunks: ObfuscateChunks;
        include?: string[];
        exclude?: string[];
    };
}

interface Logger {
    info(message: string): void;
    warn(message: string): void;
    error(message: string): void;
}

type HookContext = {
    info?: (message: string) => void;
    warn?: (message: string) => void;
    error?: (message: string) => void;
};

interface ReportRow {
    fileName: string;
    obfuscated: boolean;
    sizeBefore: number;
    sizeAfter: number;
}

/**
 * Compose a best-effort source map from the chunk's real module list so an
 * obfuscated chunk keeps truthful source identity (module paths + content) for
 * error grouping in Sentry/Bugsnag. Line-level mappings are intentionally left
 * empty: obfuscation destroys precise positions and pretending otherwise would
 * mislead stack traces.
 */
function composeChunkMap(chunk: { fileName: string; modules?: Record<string, { code?: string | null }> }): string | null {
    const modules = chunk.modules ?? {};
    const sources = Object.keys(modules).filter((id) => !id.includes('node_modules'));

    if (sources.length === 0) {
        return null;
    }

    const sourcesContent = sources.map((id) => (typeof modules[id]?.code === 'string' ? (modules[id].code as string) : null));

    return JSON.stringify({
        version: 3,
        file: chunk.fileName,
        sources,
        sourcesContent,
        names: [],
        mappings: '',
    });
}

function makeLogger(ctx: HookContext | undefined, level: LogLevel): Logger {
    const noop = (): void => undefined;
    const info = ctx?.info ?? noop;
    const warn = ctx?.warn ?? noop;
    const error = ctx?.error ?? noop;

    switch (level) {
        case 'silent':
            return { info: noop, warn: noop, error: noop };
        case 'error':
            return { info: noop, warn: noop, error };
        case 'warn':
            return { info: noop, warn, error };
        case 'info':
        default:
            return { info, warn, error };
    }
}

function normalizeOptions(input: AssetShieldViteOptions): NormalizedOptions {
    const obfuscation = input.obfuscation ?? {};
    const mask = input.mask ?? {};

    return {
        enabled: input.enabled ?? true,
        registryFile: input.registryFile ?? 'storage/app/asset-shield/registry.json',
        legendFile: input.legendFile ?? 'storage/app/asset-shield/legend.json',
        buildDir: input.buildDir ?? 'build',
        sourceMaps: input.sourceMaps ?? false,
        logLevel: input.logLevel ?? 'info',
        failOnError: input.failOnError ?? true,
        mask: {
            enabled: mask.enabled ?? false,
            strategy: mask.strategy ?? 'preserve',
            seed: mask.seed ?? '',
            aliases: mask.aliases ?? {},
            include: mask.include ?? [],
            exclude: mask.exclude ?? [],
        },
        obfuscation: {
            enabled: obfuscation.enabled ?? false,
            preset: obfuscation.preset ?? 'balanced',
            options: obfuscation.options,
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
    return /^[a-zA-Z]:[\\/]/.test(path) || path.startsWith('/');
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

function resolveRootPath(root: string, configured: string): string {
    if (isAbsolute(configured)) {
        return configured;
    }

    return `${normalizePath(root)}/${normalizePath(configured)}`;
}

type OutputName = string | ((info: unknown, options: unknown) => string | { fileName: string });

type TemplateKind = 'entry' | 'chunk' | 'asset';

/**
 * Structural stand-in for Rollup's PreRenderedChunk (Vite does not re-export
 * Rollup's types uniformly across versions).
 */
interface PreRenderedChunkLike {
    name?: string;
    [key: string]: unknown;
}

const KIND_BY_PROPERTY: Record<string, TemplateKind> = {
    entryFileNames: 'entry',
    chunkFileNames: 'chunk',
    assetFileNames: 'asset',
};

const DEFAULT_TEMPLATES: Record<TemplateKind, string> = {
    entry: 'assets/[name]-[hash].[ext]',
    chunk: 'assets/[name]-[hash].js',
    asset: 'assets/[name]-[hash][extname]',
};

function assetName(info: unknown, kind: TemplateKind): string {
    if (kind !== 'asset') {
        return (info as PreRenderedChunkLike | undefined)?.name ?? '';
    }

    const assetInfo = (info as { assetInfo?: { name?: string } })?.assetInfo;

    return assetInfo?.name ?? (info as { name?: string } | undefined)?.name ?? '';
}

function assetBase(info: unknown, kind: TemplateKind): string {
    const name = assetName(info, kind);
    const index = name.lastIndexOf('.');

    return index > 0 ? name.slice(0, index) : name;
}

function assetExtname(info: unknown, kind: TemplateKind): string {
    if (kind !== 'asset') {
        return '.js';
    }

    const name = assetName(info, kind);
    const index = name.lastIndexOf('.');

    return index > 0 ? name.slice(index) : '';
}

function renderTemplate(template: string, kind: TemplateKind, info: unknown, patternHash: string, format = 'es'): string {
    return template
        .replace(/\[hash:(\d+)\]/g, (_match, length: string) => patternHash.slice(0, Number(length)))
        .replace(/\[hash\]/g, patternHash)
        .replace(/\[format\]/g, format)
        .replace(/\[extname\]/g, assetExtname(info, kind))
        .replace(/\[ext\]/g, kind === 'asset' ? assetExtname(info, kind).replace(/^\./, '') : 'js')
        .replace(/\[assetInfo\.name\]/g, assetName(info, kind))
        .replace(/\[name\]/g, assetBase(info, kind));
}

/**
 * Wrap an output-name setting so enabled mask renames matching outputs
 * deterministically while untouched files keep their original name.
 */
function wrapOutputName(
    original: OutputName | undefined,
    kind: TemplateKind,
    planner: MaskPlanner,
    finalToOriginal: Map<string, string>,
    patternHashOf: (name: string) => string,
): (info: unknown, options: unknown) => string {
    return (info, options) => {
        const format = typeof options === 'object' && options !== null && 'format' in options
            ? String((options as { format?: string }).format ?? 'es')
            : 'es';
        let defaultPath: string;

        if (typeof original === 'function') {
            const result = original(info, options);

            if (typeof result === 'string') {
                defaultPath = result;
            } else if (result !== null && typeof result === 'object' && 'fileName' in result) {
                defaultPath = (result as { fileName: string }).fileName;
            } else {
                defaultPath = '';
            }
        } else if (typeof original === 'string') {
            defaultPath = renderTemplate(original, kind, info, patternHashOf(assetName(info, kind)), format);
        } else {
            defaultPath = renderTemplate(DEFAULT_TEMPLATES[kind], kind, info, patternHashOf(assetName(info, kind)), format);
        }

        if (defaultPath === '') {
            return defaultPath;
        }

        const normalized = normalizePath(defaultPath);
        const planned = planner.plan(kind === 'asset' ? assetName(info, kind) : assetBase(info, kind), normalized);

        if (planned.file !== normalized) {
            finalToOriginal.set(planned.file, normalized);
        }

        return planned.file;
    };
}

function installMasking(
    resolved: ResolvedConfig,
    mask: NormalizedOptions['mask'],
    planner: MaskPlanner,
    finalToOriginal: Map<string, string>,
    log: Logger,
): void {
    const outputs = resolved.build.rollupOptions.output;
    const list: Array<Record<string, unknown>> =
        outputs === undefined ? [] : Array.isArray(outputs) ? (outputs as Array<Record<string, unknown>>) : [outputs as Record<string, unknown>];

    if (list.length === 0) {
        list.push({});
    }

    const patternHashOf = (name: string): string => hex8((mask.seed || 'asset-shield') + ':hash:' + name);

    for (const output of list) {
        for (const [property, kind] of Object.entries(KIND_BY_PROPERTY)) {
            const current = output[property];

            if (current !== undefined && typeof current !== 'string' && typeof current !== 'function') {
                continue;
            }

            output[property] = wrapOutputName(
                current as OutputName | undefined,
                kind,
                planner,
                finalToOriginal,
                patternHashOf,
            );
        }
    }

    resolved.build.rollupOptions.output = (list.length === 1 ? list[0] : list) as ResolvedConfig['build']['rollupOptions']['output'];
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

    const maskConfig: MaskConfig = {
        enabled: options.mask.enabled,
        strategy: options.mask.strategy,
        seed: options.mask.seed ?? '',
        aliases: options.mask.aliases,
        include: options.mask.include,
        exclude: options.mask.exclude,
    };

    const maskPlanner = new MaskPlanner(maskConfig);
    const finalToOriginal = new Map<string, string>();

    const report: ReportRow[] = [];

    let config: ResolvedConfig | undefined;
    let warnedAboutSourceMaps = false;
    let warnedAboutObfuscator = false;
    let warnedAboutMaskCaching = false;

    return {
        name: 'asset-shield',
        apply: 'build',
        buildStart() {
            // Watch-mode rebuilds reuse this plugin instance; clear the
            // collision tracker and rename map so names stay deterministic
            // across rebuilds instead of accumulating suffixes.
            maskPlanner.reset();
            finalToOriginal.clear();
        },
        configResolved(resolved) {
            config = resolved;
            const log = makeLogger(this as unknown as HookContext, options.logLevel);

            if (options.sourceMaps) {
                if (!warnedAboutSourceMaps) {
                    log.warn('AssetShield: sourceMaps explicitly enabled. Hosted source maps are never hidden by AssetShield; keep them out of public contexts.');
                    warnedAboutSourceMaps = true;
                }
            } else if (resolved.build.sourcemap) {
                resolved.build.sourcemap = false;
                log.warn('AssetShield: source maps disabled because the sourceMaps option is off. Set sourceMaps: true to keep them.');
            }

            if (options.mask.enabled) {
                installMasking(resolved, options.mask, maskPlanner, finalToOriginal, log);

                if (!warnedAboutMaskCaching) {
                    log.warn(
                        'AssetShield: mask enabled — output names become deterministic, replacing Vite content hashing. Since names no longer change with content, prefer runtime delivery with signed URLs (cache clamps to the signature expiry) or use a short/conditional cache for public builds.',
                    );
                    warnedAboutMaskCaching = true;
                }
            }
        },
        async renderChunk(code, chunk, _options) {
            const log = makeLogger(this as unknown as HookContext, options.logLevel);

            if (!options.enabled || !options.obfuscation.enabled) {
                return null;
            }

            if (code.trim().length === 0) {
                return null;
            }

            if (!predicate({ fileName: chunk.fileName, isEntry: chunk.isEntry, moduleIds: chunk.moduleIds })) {
                return null;
            }

            const chunkMap = options.sourceMaps ? composeChunkMap(chunk) : null;

            try {
                const result = await engine.transformChunk(
                    chunk.fileName,
                    code,
                    options.obfuscation.preset,
                    options.obfuscation.options,
                    chunkMap,
                );

                if (!result.obfuscated) {
                    if (options.failOnError) {
                        throw new Error(
                            `AssetShield: obfuscation failed for "${chunk.fileName}" (javascript-obfuscator unavailable or produced no output).`,
                        );
                    }

                    if (!warnedAboutObfuscator) {
                        log.warn('AssetShield: obfuscation enabled but no javascript-obfuscator is available; chunks were left untouched.');
                        warnedAboutObfuscator = true;
                    }

                    return null;
                }

                report.push({
                    fileName: chunk.fileName,
                    obfuscated: true,
                    sizeBefore: code.length,
                    sizeAfter: result.code.length,
                });

                return { code: result.code, map: result.map };
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);

                if (options.failOnError) {
                    throw new Error(`AssetShield: obfuscation of "${chunk.fileName}" failed: ${message}`);
                }

                log.warn(`AssetShield: obfuscation of "${chunk.fileName}" failed: ${message}`);

                return null;
            }
        },
        async closeBundle() {
            const log = makeLogger(this as unknown as HookContext, options.logLevel);

            if (options.obfuscation.enabled && report.length > 0) {
                log.info(`AssetShield build report: ${report.length} obfuscated ${report.length === 1 ? 'chunk' : 'chunks'} (preset "${options.obfuscation.preset}").`);
                log.info(`  Features: ${describePreset(options.obfuscation.preset).join('; ')}`);

                for (const row of report) {
                    const delta = row.sizeAfter - row.sizeBefore;

                    log.info(`  ${row.fileName}  ${row.sizeBefore}B -> ${row.sizeAfter}B (${delta >= 0 ? '+' : ''}${delta}B)`);
                }

                report.length = 0;
            }

            if (!options.enabled || config === undefined) {
                return;
            }

            const fs: FsLike = externalFs ?? (await import('node:fs'));
            const outDir = normalizePath(config.build.outDir);
            const exists = fs.existsSync;
            let manifestPath = `${outDir}/.vite/manifest.json`;

            if (exists === undefined || !exists(manifestPath)) {
                const legacyManifestPath = `${outDir}/manifest.json`;

                if (exists !== undefined && legacyManifestPath !== manifestPath && exists(legacyManifestPath)) {
                    manifestPath = legacyManifestPath;
                } else {
                    log.warn(`AssetShield: ${manifestPath} not found; registry was not written. Make sure the entry output plugin (e.g. laravel-vite-plugin) runs before this one.`);
                    return;
                }
            }

            let manifest: Record<string, unknown>;
            const readFile = fs.readFileSync;

            if (readFile === undefined) {
                log.warn('AssetShield: filesystem is not readable; registry was not written.');
                return;
            }

            try {
                manifest = JSON.parse(readFile(manifestPath, 'utf8')) as Record<string, unknown>;
            } catch {
                log.warn(`AssetShield: ${manifestPath} is not valid JSON; registry was not written.`);
                return;
            }

            const buildDir = resolveBuildDir(outDir, config.root, options.buildDir);
            const registry = buildRegistry(manifest, buildDir, finalToOriginal);
            const registryPath = resolveRootPath(config.root, options.registryFile);
            const count = Object.keys(registry.assets).length;

            await writeRegistry(registryPath, registry, fs);

            log.info(`AssetShield wrote ${count} ${count === 1 ? 'entry' : 'entries'} to ${registryPath}`);

            if (options.mask.enabled) {
                const legend = buildLegend(manifest, finalToOriginal, options.mask.seed ?? '');
                const legendPath = resolveRootPath(config.root, options.legendFile);

                await writeLegend(legendPath, legend, fs);

                const renames = Object.keys(legend.entries).length;

                if (renames > 0) {
                    log.info(`AssetShield wrote ${renames} masked filename mapping${renames === 1 ? '' : 's'} to ${legendPath}`);
                    log.warn('AssetShield: the legend maps original filenames to their masked names. It MUST stay outside public/ and must never be served.');
                } else {
                    log.warn('AssetShield: mask enabled but no outputs were renamed — check mask.include patterns and the output file names.');
                }
            }
        },
    };
}

export default assetShieldVite;