import { describe, expect, it, vi } from 'vitest';

import { type PluginDeps, assetShieldVite } from '../src/index';
import { ObfuscationEngine } from '../src/index';

describe('plugin activation', () => {
    it('activates only on build', () => {
        const plugin = assetShieldVite();

        expect(plugin.apply).toBe('build');
    });

    it('leaves the dev server untouched', () => {
        const plugin = assetShieldVite() as Record<string, unknown>;

        expect(plugin.configureServer).toBeUndefined();
        expect(plugin.configurePreviewServer).toBeUndefined();
        expect(plugin.transformIndexHtml).toBeUndefined();
    });
});

describe('source maps', () => {
    it('forces source maps off unless the option is enabled', async () => {
        const plugin = assetShieldVite();
        const warnings: string[] = [];
        const config = {
            root: '/project',
            build: { outDir: '/project/public/build', sourcemap: true },
        };

        await (plugin.configResolved as (config: unknown, options: unknown) => unknown).call(
            { warn: (message: string) => warnings.push(message) },
            config,
        );

        expect((config.build as { sourcemap: unknown }).sourcemap).toBe(false);
        expect(warnings.some((message) => message.includes('source maps disabled'))).toBe(true);
    });

    it('warns when source maps are explicitly enabled and leaves the setting alone', async () => {
        const plugin = assetShieldVite({ sourceMaps: true });
        const warnings: string[] = [];
        const config = {
            root: '/project',
            build: { outDir: '/project/public/build', sourcemap: false },
        };

        await (plugin.configResolved as (config: unknown, options: unknown) => unknown).call(
            { warn: (message: string) => warnings.push(message) },
            config,
        );

        expect((config.build as { sourcemap: unknown }).sourcemap).toBe(false);
        expect(warnings.some((message) => message.includes('never hidden'))).toBe(true);
    });
});