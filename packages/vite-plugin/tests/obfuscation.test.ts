import { describe, expect, it, vi } from 'vitest';

import { assetShieldVite, ObfuscationEngine } from '../src/index';

describe('obfuscation engine', () => {
    it('obfuscates application chunks when enabled', async () => {
        const fakeObfuscator = vi.fn((source: string) => `OBFUSCATED:${source}`);
        const engine = new ObfuscationEngine(fakeObfuscator as (source: string, options?: Record<string, unknown>) => string);

        const plugin = assetShieldVite(
            { obfuscation: { enabled: true, preset: 'light' } },
            { engine } as { engine: ObfuscationEngine },
        );

        const warnings: string[] = [];
        const result = await (plugin.renderChunk as (
            code: string,
            chunk: { fileName: string; isEntry: boolean; moduleIds: string[] },
        ) => unknown).call(
            { warn: (message: string) => warnings.push(message) },
            'export const value = 1;',
            { fileName: 'assets/app.js', isEntry: true, moduleIds: ['/project/resources/js/app.js'] },
        );

        expect(fakeObfuscator).toHaveBeenCalledOnce();
        expect(result).toEqual({ code: 'OBFUSCATED:export const value = 1;', map: null });
        expect(warnings).toHaveLength(0);
    });

    it('refrains from obfuscating when disabled', async () => {
        const fakeObfuscator = vi.fn((source: string) => `OBFUSCATED:${source}`);
        const engine = new ObfuscationEngine(fakeObfuscator as (source: string, options?: Record<string, unknown>) => string);

        const plugin = assetShieldVite({}, { engine } as { engine: ObfuscationEngine });

        const result = await (plugin.renderChunk as (
            code: string,
            chunk: { fileName: string; isEntry: boolean; moduleIds: string[] },
        ) => unknown).call(
            { warn: () => undefined },
            'export const value = 1;',
            { fileName: 'assets/app.js', isEntry: true, moduleIds: ['/project/resources/js/app.js'] },
        );

        expect(fakeObfuscator).not.toHaveBeenCalled();
        expect(result).toBeNull();
    });

    it('skips empty chunks', async () => {
        const fakeObfuscator = vi.fn((source: string) => `OBFUSCATED:${source}`);
        const engine = new ObfuscationEngine(fakeObfuscator as (source: string, options?: Record<string, unknown>) => string);
        const result = await engine.transformChunk('assets/empty.js', '   \n  ', 'balanced');

        expect(result.obfuscated).toBe(false);
        expect(result.code).toBe('   \n  ');
        expect(fakeObfuscator).not.toHaveBeenCalled();
    });

    it('warns once but keeps the chunk when the obfuscator is unavailable (failOnError false)', async () => {
        const engine = new ObfuscationEngine(() => undefined);
        const plugin = assetShieldVite(
            { obfuscation: { enabled: true }, failOnError: false },
            { engine } as { engine: ObfuscationEngine },
        );

        const warnings: string[] = [];
        const result = await (plugin.renderChunk as (
            code: string,
            chunk: { fileName: string; isEntry: boolean; moduleIds: string[] },
        ) => unknown).call(
            { warn: (message: string) => warnings.push(message) },
            'export const value = 1;',
            { fileName: 'assets/app.js', isEntry: true, moduleIds: ['/project/resources/js/app.js'] },
        );

        expect(result).toBeNull();
        expect(warnings.some((message) => message.includes('no javascript-obfuscator'))).toBe(true);
    });
});