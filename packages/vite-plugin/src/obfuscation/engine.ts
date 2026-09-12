import { type ObfuscationPreset, presetOptions } from './presets';

export type Obfuscator = (source: string, options?: Record<string, unknown>) => string;

export interface ObfuscationResult {
    code: string;
    obfuscated: boolean;
}

type ObfuscatorLoader = () => Promise<Obfuscator | null>;

async function defaultLoader(): Promise<Obfuscator | null> {
    try {
        const loaded: unknown = await import('javascript-obfuscator');
        const module = loaded as { default?: unknown };

        if (typeof loaded === 'function') {
            return loaded as Obfuscator;
        }

        if (module.default !== undefined && typeof module.default === 'function') {
            return module.default as Obfuscator;
        }

        return null;
    } catch {
        return null;
    }
}

export class ObfuscationEngine {
    private readonly loader: ObfuscatorLoader;
    private cached: Promise<Obfuscator | null> | null = null;

    constructor(obfuscator?: Obfuscator) {
        this.loader = obfuscator ? () => Promise.resolve(obfuscator) : defaultLoader;
    }

    async available(): Promise<boolean> {
        return (await this.resolve()) !== null;
    }

    async transformChunk(name: string, code: string, preset: ObfuscationPreset = 'balanced'): Promise<ObfuscationResult> {
        if (code.trim().length === 0) {
            return { code, obfuscated: false };
        }

        const obfuscator = await this.resolve();

        if (obfuscator === null) {
            return { code, obfuscated: false };
        }

        const obfuscated = obfuscator(code, presetOptions(preset));

        if (typeof obfuscated !== 'string' || obfuscated.length === 0) {
            return { code, obfuscated: false };
        }

        return { code: obfuscated, obfuscated: true };
    }

    private resolve(): Promise<Obfuscator | null> {
        if (this.cached === null) {
            this.cached = this.loader().catch(() => null);
        }

        return this.cached;
    }
}