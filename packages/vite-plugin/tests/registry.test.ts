import { describe, expect, it } from 'vitest';

import { assetShieldVite } from '../src/index';
import { buildLegend, buildRegistry, type FsLike, type RegistryFile } from '../src/index';

const MANIFEST = {
    'resources/js/app.js': {
        file: 'assets/app-A91Kx.js',
        src: 'resources/js/app.js',
        isEntry: true,
        name: 'app',
        css: ['assets/app-2E5Zc7.css'],
        integrity: 'sha384-abcdef123456',
    },
    'resources/js/dashboard.js': {
        file: 'assets/dashboard-K3x9v.js',
        src: 'resources/js/dashboard.js',
        isEntry: false,
        name: 'dashboard',
    },
    'resources/css/app.css': {
        file: 'assets/app-2E5Zc7.css',
        src: 'resources/css/app.css',
        isEntry: true,
        name: 'app',
    },
    'resources/images/logo.svg': null,
};

function stubFs(exists: boolean): FsLike & { writes: string[] } {
    const writes: string[] = [];

    return {
        writes,
        existsSync: () => exists,
        readFileSync: () => JSON.stringify(MANIFEST),
        writeFileSync: (_path: string, data: string) => writes.push(data),
        mkdirSync: () => undefined,
    };
}

describe('registry building', () => {
    it('maps every manifest entry to a public-root-relative asset', () => {
        const registry = buildRegistry(MANIFEST, 'build');

        expect(Object.keys(registry.assets)).toEqual([
            'resources/js/app.js',
            'resources/js/dashboard.js',
            'resources/css/app.css',
        ]);
        expect(registry.assets['resources/js/app.js']).toMatchObject({
            file: 'build/assets/app-A91Kx.js',
            type: 'script',
            integrity: 'sha384-abcdef123456',
        });
        expect(registry.assets['resources/js/dashboard.js']).toMatchObject({
            file: 'build/assets/dashboard-K3x9v.js',
            type: 'script',
        });
        expect(registry.assets['resources/css/app.css']).toMatchObject({
            file: 'build/assets/app-2E5Zc7.css',
            type: 'style',
        });
    });

    it('records pre-mask originals when the rename map is provided', () => {
        const renames = new Map<string, string>([
            ['assets/app-A91Kx.js', 'assets/a8bc3d21.js'],
        ]);

        const registry = buildRegistry(MANIFEST, 'build', renames);

        expect(registry.assets['resources/js/app.js'].original).toBe('build/assets/a8bc3d21.js');
        expect(registry.assets['resources/js/dashboard.js'].original).toBeUndefined();
    });

    it('writes the registry after a successful build', async () => {
        const fs = stubFs(true);
        const plugin = assetShieldVite({}, { fs } as { fs: FsLike });

        await (plugin.configResolved as (config: unknown) => unknown).call(
            { warn: () => undefined },
            { root: '/project', build: { outDir: '/project/public/build' } },
        );

        const info: string[] = [];
        await (plugin.closeBundle as () => unknown).call({
            warn: () => undefined,
            info: (message: string) => info.push(message),
        });

        expect(fs.writes).toHaveLength(1);

        const written = JSON.parse(fs.writes[0] as string) as RegistryFile;
        expect(written.version).toBe(1);
        expect(Object.keys(written.assets)).toHaveLength(3);
        expect(info.some((message) => message.includes('wrote 3 entries'))).toBe(true);
    });

    it('builds a legend only for renamed files', () => {
        const renames = new Map<string, string>([
            ['assets/app-A91Kx.js', 'assets/a8bc3d21.js'],
        ]);

        const legend = buildLegend(MANIFEST, renames, 'test-seed');

        expect(legend.seed).toBe('test-seed');
        expect(Object.keys(legend.entries)).toEqual(['assets/a8bc3d21.js']);
        expect(legend.entries['assets/a8bc3d21.js']).toEqual({
            original: 'assets/a8bc3d21.js',
            masked: 'assets/app-A91Kx.js',
        });
    });

    it('warns and keeps the build going when the manifest is missing', async () => {
        const fs = stubFs(false);
        const plugin = assetShieldVite({}, { fs } as { fs: FsLike });

        await (plugin.configResolved as (config: unknown) => unknown).call(
            { warn: () => undefined },
            { root: '/project', build: { outDir: '/project/public/build' } },
        );

        const warnings: string[] = [];
        await (plugin.closeBundle as () => unknown).call({
            warn: (message: string) => warnings.push(message),
            info: () => undefined,
        });

        expect(warnings.some((message) => message.includes('not found'))).toBe(true);
        expect(fs.writes).toHaveLength(0);
    });

    it('does nothing when the plugin is disabled', async () => {
        const fs = stubFs(true);
        const plugin = assetShieldVite({ enabled: false }, { fs } as { fs: FsLike });

        await (plugin.configResolved as (config: unknown) => unknown).call(
            { warn: () => undefined },
            { root: '/project', build: { outDir: '/project/public/build' } },
        );

        await (plugin.closeBundle as () => unknown).call({
            warn: () => undefined,
            info: () => undefined,
        });

        expect(fs.writes).toHaveLength(0);
    });
});