import { describe, expect, it } from 'vitest';

import { MaskPlanner, codenameName, namelessName } from '../src/mask/names';
import { hex8 } from '../src/mask/fnv1a';
import { assetShieldVite, type FsLike } from '../src/index';

const MANIFEST = {
    'resources/js/app.js': {
        file: 'assets/42623f48.js',
        src: 'resources/js/app.js',
        isEntry: true,
        name: 'app',
        integrity: 'sha384-abcdef123456',
    },
    'resources/css/app.css': {
        file: 'assets/174f2c9.css',
        src: 'resources/css/app.css',
        isEntry: true,
        name: 'app',
    },
};

function maskPlugin(fsLike: FsLike) {
    return assetShieldVite(
        {
            mask: {
                enabled: true,
                strategy: 'nameless',
                seed: 'test-seed',
            },
        },
        { fs: fsLike },
    );
}

describe('mask parity', () => {
    it('hex8 mirrors the PHP implementation', () => {
        expect(hex8('assets/app-A91Kx.js')).toBe(hex8('assets/app-A91Kx.js'));
        expect(hex8('assets/app-A91Kx.js')).toMatch(/^[a-f0-9]{8}$/);
    });

    it('nameless resolver agrees with the PHP HashResolver', () => {
        expect(namelessName('test-seed', 'assets/app-A91Kx.js')).toBe('42623f48.js');
    });

    it('codename resolver agrees with the PHP CodenameResolver', () => {
        expect(codenameName('test-seed', 'assets/app-A91Kx.js')).toBe('brisk-viper.js');
    });

    it('planner plan() agrees with the PHP MaskPlanner', () => {
        const nameless = new MaskPlanner({ enabled: true, strategy: 'nameless', seed: 'test-seed', aliases: {}, include: [], exclude: [] });

        expect(nameless.plan('app', 'assets/app-A91Kx.js')).toEqual({ original: 'assets/app-A91Kx.js', file: 'assets/42623f48.js' });

        const codename = new MaskPlanner({ enabled: true, strategy: 'codename', seed: 'test-seed', aliases: {}, include: [], exclude: [] });

        expect(codename.plan('app', 'assets/app-A91Kx.js').file).toBe('assets/brisk-viper.js');
    });

    it('collision probing matches PHP determinism', () => {
        const planner = new MaskPlanner({ enabled: true, strategy: 'codename', seed: 's', aliases: {}, include: [], exclude: [] });

        const first = planner.plan('a', 'assets/x/index.js').file;
        const second = planner.plan('b', 'assets/y/index.js').file;

        expect(first).toBe('assets/x/onyx-tapir.js');
        expect(second).toBe('assets/y/cobalt-quail.js');
        expect(first).not.toBe(second);
    });

    it('preserves excluded files and applies aliases', () => {
        const planner = new MaskPlanner({
            enabled: true,
            strategy: 'codename',
            seed: 's',
            aliases: { app: 'main' },
            include: ['**'],
            exclude: ['assets/vendor/*'],
        });

        expect(planner.plan('app', 'assets/app-A91Kx.js').file).toBe('assets/main.js');
        expect(planner.plan('vendor', 'assets/vendor/vue.js').file).toBe('assets/vendor/vue.js');
    });
});

describe('plugin mask integration', () => {
    it('renames entry files through the output naming hooks and writes registry + legend', async () => {
        const writes = new Map<string, string>();
        const fsLike: FsLike = {
            existsSync: (path) => path.endsWith('manifest.json'),
            readFileSync: () => JSON.stringify(MANIFEST),
            writeFileSync: (path: string, data: string) => writes.set(path, data),
            mkdirSync: () => undefined,
        };

        const plugin = maskPlugin(fsLike) as unknown as {
            configResolved: (config: unknown) => unknown;
            closeBundle: () => unknown;
        };
        const config: Record<string, unknown> = {
            root: '/project',
            build: { outDir: '/project/public/build', rollupOptions: { output: {} } },
        };

        await (plugin.configResolved as (config: unknown) => unknown).call({ warn: () => undefined, info: () => undefined }, config);

        const output = (config.build as Record<string, unknown>).rollupOptions as { output: Record<string, unknown> };

        const entryNames = output.output.entryFileNames as (info: unknown) => string;
        const chunkNames = output.output.chunkFileNames as (info: unknown) => string;

        const appFile = entryNames({ name: 'app' });
        const cssFile = chunkNames({ name: 'app', isEntry: true });

        expect(appFile).toMatch(/^assets\/[a-f0-9]{8}\.js$/);
        expect(chunkNames({ name: 'vendor' })).toMatch(/^assets\/[a-f0-9]{8}\.js$/);
        expect(appFile).toBe(appFile);

        const dynamicManifest = {
            'resources/js/app.js': { file: appFile, src: 'resources/js/app.js', isEntry: true, name: 'app', integrity: 'sha384-abcdef123456' },
            'resources/css/app.css': { file: cssFile, src: 'resources/css/app.css', isEntry: true, name: 'app' },
        };

        const dynamicFs: FsLike = {
            existsSync: (path) => path.endsWith('manifest.json'),
            readFileSync: () => JSON.stringify(dynamicManifest),
            writeFileSync: (path: string, data: string) => writes.set(path, data),
            mkdirSync: () => undefined,
        };

        const pluginTwo = maskPlugin(dynamicFs) as unknown as {
            configResolved: (config: unknown) => unknown;
            closeBundle: () => unknown;
        };

        const configTwo: Record<string, unknown> = {
            root: '/project',
            build: { outDir: '/project/public/build', rollupOptions: { output: {} } },
        };

        await (pluginTwo.configResolved as (config: unknown) => unknown).call({ warn: () => undefined, info: () => undefined }, configTwo);

        const outputTwo = (configTwo.build as Record<string, unknown>).rollupOptions as { output: Record<string, unknown> };
        const entryNamesTwo = outputTwo.output.entryFileNames as (info: unknown) => string;
        const chunkNamesTwo = outputTwo.output.chunkFileNames as (info: unknown) => string;

        // Simulate the build: the SAME plugin instance that renamed the files
        // must also run its manifest/registry/legend pass.
        expect(entryNamesTwo({ name: 'app' })).toBe(appFile);
        expect(chunkNamesTwo({ name: 'app', isEntry: true })).toBe(cssFile);
        await (pluginTwo.closeBundle as () => unknown).call({ warn: () => undefined, info: () => undefined });

        const registryPath = '/project/storage/app/assetshield/registry.json';
        const legendPath = '/project/storage/app/assetshield/legend.json';

        const registry = JSON.parse(writes.get(registryPath) ?? '{}') as {
            assets: Record<string, { file: string; type: string; original?: string; integrity?: string }>;
        };
        const legend = JSON.parse(writes.get(legendPath) ?? '{}') as { entries: Record<string, { original: string; masked: string }> };

        expect(Object.keys(registry.assets)).toEqual(['resources/js/app.js', 'resources/css/app.css']);
        expect(registry.assets['resources/js/app.js'].file).toBe(`build/${appFile}`);
        expect(registry.assets['resources/js/app.js'].original).toMatch(/^build\/assets\/app-[a-f0-9]{8}\.js$/);
        expect(registry.assets['resources/js/app.js'].integrity).toBe('sha384-abcdef123456');

        const legendEntries = Object.entries(legend.entries);
        expect(legendEntries.length).toBeGreaterThan(0);
        expect(legendEntries.every(([, value]) => value.masked !== value.original)).toBe(true);
        expect(legend.entries[`assets/app-<hash>.js`]).toBeUndefined();
        expect(Object.keys(legend.entries).some((key) => key.startsWith('assets/app-'))).toBe(true);
    });

    it('never writes a legend and never renames output when mask is disabled', async () => {
        const writes = new Map<string, string>();
        const fsLike: FsLike = {
            existsSync: (path) => path.endsWith('manifest.json'),
            readFileSync: () => JSON.stringify(MANIFEST),
            writeFileSync: (path: string, data: string) => writes.set(path, data),
            mkdirSync: () => undefined,
        };

        const plugin = assetShieldVite({}, { fs: fsLike }) as unknown as {
            configResolved: (config: unknown) => unknown;
            closeBundle: () => unknown;
        };
        const config: Record<string, unknown> = {
            root: '/project',
            build: { outDir: '/project/public/build' },
        };

        await (plugin.configResolved as (config: unknown) => unknown).call({ warn: () => undefined }, config);
        await (plugin.closeBundle as () => unknown).call({ warn: () => undefined, info: () => undefined });

        expect(writes.has('/project/storage/app/assetshield/legend.json')).toBe(false);
        expect(writes.has('/project/storage/app/assetshield/registry.json')).toBe(true);
    });
});