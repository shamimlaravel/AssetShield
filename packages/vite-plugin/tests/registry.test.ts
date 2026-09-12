import { describe, expect, it, vi } from 'vitest';

import { assetShieldVite } from '../src/index';
import { buildRegistry, type FsLike, type RegistryFile } from '../src/index';

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
    it('includes every manifest entry with a file, including dynamically imported chunks', () => {
        const entries = buildRegistry(MANIFEST, 'build');

        expect(entries.map((entry) => entry.logical)).toEqual([
            'resources/js/app.js',
            'resources/js/dashboard.js',
            'resources/css/app.css',
        ]);
        expect(entries[0]).toMatchObject({
            compiled: 'build/assets/app-A91Kx.js',
            type: 'script',
            integrity: 'sha384-abcdef123456',
        });
        expect(entries[1]).toMatchObject({
            compiled: 'build/assets/dashboard-K3x9v.js',
            type: 'script',
        });
        expect(entries[2]).toMatchObject({
            compiled: 'build/assets/app-2E5Zc7.css',
            type: 'style',
        });
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
        expect(written.entries).toHaveLength(3);
        expect(info.some((message) => message.includes('wrote 3 entries'))).toBe(true);
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