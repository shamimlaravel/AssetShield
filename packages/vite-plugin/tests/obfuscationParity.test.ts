import { describe, expect, it, vi } from 'vitest';

import { assetShieldVite, describePreset, mergeOptions, ObfuscationEngine, presetOptions } from '../src/index';

function engineFor(obfuscator: (source: string, options?: Record<string, unknown>) => unknown): ObfuscationEngine {
    return new ObfuscationEngine(obfuscator as (source: string, options?: Record<string, unknown>) => string);
}

describe('preset parity', () => {
    it('exposes meaningful Tyro-style option matrices per preset', () => {
        const light = presetOptions('light');
        const balanced = presetOptions('balanced');
        const aggressive = presetOptions('aggressive');

        expect(light.stringArray).toBe(true);
        expect(light.stringArrayEncoding).toEqual(['base64']);
        expect(light.stringArrayThreshold).toBe(0.5);
        expect(light.selfDefending).toBe(false);

        expect(balanced.stringArrayEncoding).toEqual(['rc4']);
        expect(balanced.stringArrayThreshold).toBe(0.75);
        expect(balanced.controlFlowFlattening).toBe(true);
        expect(balanced.controlFlowFlatteningThreshold).toBe(0.5);
        expect(balanced.numbersToExpressions).toBe(true);
        expect(balanced.selfDefending).toBe(false);

        expect(aggressive.selfDefending).toBe(true);
        expect(aggressive.controlFlowFlatteningThreshold).toBe(1);
        expect(aggressive.deadCodeInjection).toBe(true);
        expect(aggressive.transformObjectKeys).toBe(true);
        expect(aggressive.splitStringsChunkLength).toBe(4);
        expect(aggressive.identifierNamesGenerator).toBe('mangled-shuffled');
    });

    it('returns a human-readable feature description', () => {
        const features = describePreset('aggressive');

        expect(features.length).toBeGreaterThan(0);
        expect(features).toContain('dead-code injection');
    });

    it('deep-merges overrides without clobbering preset arrays or sibling keys', () => {
        const merged = mergeOptions('balanced', {
            stringArrayThreshold: 0.9,
            splitStrings: false,
        });

        expect(merged.stringArrayThreshold).toBe(0.9);
        expect(merged.splitStrings).toBe(false);
        expect(merged.stringArrayEncoding).toEqual(['rc4']);
        expect(merged.controlFlowFlattening).toBe(true);
    });

    it('replaces arrays wholesale and ignores undefined override values', () => {
        const merged = mergeOptions('light', {
            stringArrayEncoding: ['rc4', 'base64'],
            numbersToExpressions: undefined,
        });

        expect(merged.stringArrayEncoding).toEqual(['rc4', 'base64']);
        expect(merged.numbersToExpressions).toBe(false);
    });

    it('returns a fresh copy each time so the shared table is immutable', () => {
        const first = presetOptions('aggressive');
        const second = presetOptions('aggressive');

        first.stringArrayThreshold = 0;
        expect(second.stringArrayThreshold).toBe(1);
    });
});

describe('engine override and source maps', () => {
    it('passes the merged options (preset + override) to the obfuscator', async () => {
        const obfuscator = vi.fn((source: string) => `O:${source}`);
        const engine = engineFor(obfuscator);

        await engine.transformChunk('assets/app.js', 'code;', 'balanced', { stringArrayThreshold: 0.9 });

        expect(obfuscator).toHaveBeenCalledOnce();
        const options = obfuscator.mock.calls[0][1] as Record<string, unknown>;

        expect(options.stringArrayThreshold).toBe(0.9);
        expect(options.stringArrayEncoding).toEqual(['rc4']);
    });

    it('returns an aligned source map when a chunk map is provided and source maps are wanted', async () => {
        const engine = engineFor((source: string) => `O:${source}`);
        const rollupMap = JSON.stringify({
            version: 3,
            file: 'assets/app.js',
            sources: ['resources/js/app.js'],
            sourcesContent: ['export {}'],
            names: [],
            mappings: 'AAAA',
        });

        const result = await engine.transformChunk('assets/app.js', 'code;', 'balanced', undefined, rollupMap);

        expect(result.obfuscated).toBe(true);
        expect(result.map).not.toBeNull();

        const map = JSON.parse(result.map as string) as Record<string, unknown>;

        expect(map.version).toBe(3);
        expect(map.sources).toEqual(['resources/js/app.js']);
        expect(map.file).toBe('assets/app.js');
    });

    it('returns no map when no source map was supplied', async () => {
        const engine = engineFor((source: string) => `O:${source}`);

        const result = await engine.transformChunk('assets/app.js', 'code;');

        expect(result.obfuscated).toBe(true);
        expect(result.map).toBeNull();
    });

    it('treats an unparseable incoming map as no map rather than failing', async () => {
        const engine = engineFor((source: string) => `O:${source}`);

        const result = await engine.transformChunk('assets/app.js', 'code;', 'balanced', undefined, 'not-json');

        expect(result.obfuscated).toBe(true);
        expect(result.map).toBeNull();
    });

    it('accepts an object result with its own map from a real engine', async () => {
        const engine = engineFor((source: string) => ({ code: `O:${source}`, map: '{"version":3}' }));
        const result = await engine.transformChunk('assets/app.js', 'code;', 'balanced', undefined, '{"version":3,"sources":["a.js"]}');

        expect(result.code).toBe('O:code;');
        expect(result.map).toBe('{"version":3}');
    });
});

describe('plugin failOnError and function predicates', () => {
    it('fails the build by default when obfuscation produces no output', async () => {
        const engine = new ObfuscationEngine(() => undefined);
        const plugin = assetShieldVite({ obfuscation: { enabled: true } }, { engine } as { engine: ObfuscationEngine });

        await expect(
            (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(
                { warn: () => undefined },
                'export const x = 1;',
                { fileName: 'assets/app.js', isEntry: true, moduleIds: [] },
            ),
        ).rejects.toThrow(/obfuscation failed/i);
    });

    it('keeps the chunk and warns when failOnError is false', async () => {
        const engine = new ObfuscationEngine(() => undefined);
        const plugin = assetShieldVite(
            { obfuscation: { enabled: true }, failOnError: false },
            { engine } as { engine: ObfuscationEngine },
        );

        const warnings: string[] = [];
        const render = (): Promise<unknown> =>
            (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(
                { warn: (message: string) => warnings.push(message) },
                'export const x = 1;',
                { fileName: 'assets/app.js', isEntry: true, moduleIds: [] },
            );

        await render();
        await render();

        expect(warnings).toHaveLength(1);
    });

    it('rethrows a wrapped error when the obfuscator throws and failOnError is on', async () => {
        const engine = engineFor(() => {
            throw new Error('boom');
        });
        const plugin = assetShieldVite(
            { obfuscation: { enabled: true }, failOnError: true },
            { engine } as { engine: ObfuscationEngine },
        );

        await expect(
            (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(
                { warn: () => undefined },
                'export const x = 1;',
                { fileName: 'assets/app.js', isEntry: true, moduleIds: [] },
            ),
        ).rejects.toThrow(/boom/i);
    });

    it('honours a function predicate for chunk selection', async () => {
        const obfuscator = vi.fn((source: string) => `O:${source}`);
        const plugin = assetShieldVite(
            {
                obfuscation: {
                    enabled: true,
                    obfuscateChunks: (context) => context.fileName.startsWith('secured/'),
                },
            },
            { engine: engineFor(obfuscator) } as { engine: ObfuscationEngine },
        );

        const render = (fileName: string): Promise<unknown> =>
            (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(
                { warn: () => undefined },
                'code;',
                { fileName, isEntry: false, moduleIds: [] },
            );

        await render('secured/dashboard.js');
        await render('assets/app.js');

        expect(obfuscator).toHaveBeenCalledOnce();
    });
});

describe('logLevel and build report', () => {
    interface Ctx {
        info: (message: string) => void;
    }

    function fullHarness(logLevel: 'info' | 'silent', onInfo: (message: string) => void) {
        const obfuscator = vi.fn((source: string) => `O:${source}`);
        const writes: string[] = [];
        const fakeFs = {
            existsSync: () => true,
            mkdirSync: () => undefined,
            readFileSync: () => JSON.stringify({ 'app.js': { file: 'assets/app.js', isEntry: true } }),
            writeFileSync: (_path: string, data: string) => writes.push(data),
        };
        const plugin = assetShieldVite(
            {
                obfuscation: { enabled: true, preset: 'light' },
                logLevel,
                registryFile: 'storage/app/asset-shield/registry.json',
            },
            { engine: engineFor(obfuscator), fs: fakeFs } as never,
        );
        const ctx: Ctx = { info: onInfo };

        return { plugin, ctx, obfuscator };
    }

    it('prints a per-chunk report at info level', async () => {
        const infos: string[] = [];
        const { plugin, ctx } = fullHarness('info', (message) => infos.push(message));

        await (plugin.configResolved as (config: unknown) => unknown).call(ctx, {
            root: '/project',
            build: { outDir: '/project/public/build' },
        });
        await (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(ctx, 'export const x = 1;', {
            fileName: 'assets/app.js',
            isEntry: true,
            moduleIds: ['/project/resources/js/app.js'],
        });
        await (plugin.closeBundle as () => unknown).call(ctx);

        expect(infos.some((message) => message.includes('AssetShield build report'))).toBe(true);
        expect(infos.some((message) => message.includes('assets/app.js'))).toBe(true);
    });

    it('suppresses the report and registry notices at silent level', async () => {
        const infos: string[] = [];
        const warns: string[] = [];
        const { plugin } = fullHarness('silent', (message) => infos.push(message));
        const ctx = { info: (message: string) => infos.push(message), warn: (message: string) => warns.push(message) };

        await (plugin.configResolved as (config: unknown) => unknown).call(ctx, {
            root: '/project',
            build: { outDir: '/project/public/build', sourcemap: true },
        });
        await (plugin.renderChunk as (code: string, chunk: unknown) => unknown).call(ctx, 'export const x = 1;', {
            fileName: 'assets/app.js',
            isEntry: true,
            moduleIds: ['/project/resources/js/app.js'],
        });
        await (plugin.closeBundle as () => unknown).call(ctx);

        expect(infos).toHaveLength(0);
        expect(warns).toHaveLength(0);
    });

    it('suppresses warnings below the configured level', async () => {
        const warns: string[] = [];
        const plugin = assetShieldVite({ sourceMaps: true, logLevel: 'error' });

        await (plugin.configResolved as (config: unknown) => unknown).call(
            { warn: (message: string) => warns.push(message) },
            { root: '/project', build: { outDir: '/project/public/build', sourcemap: false } },
        );

        expect(warns).toHaveLength(0);
    });
});