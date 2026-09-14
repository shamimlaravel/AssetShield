import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { mkdtemp, writeFile, readFile, rm } from 'node:fs/promises';
import { mergeOptions, type ObfuscationPreset, type ObfuscatorOptions } from './presets';

export type ObfuscatorOutput = string | { code: string; map?: string | null };

export type Obfuscator = (source: string, options?: Record<string, unknown>) => ObfuscatorOutput;

export interface ObfuscationResult {
    code: string;
    map: string | null;
    obfuscated: boolean;
}

type ObfuscatorLoader = () => Promise<Obfuscator | null>;

const subprocessScript = `
const fs = require('fs');
const { createRequire } = require('module');
const entry = process.argv[2];
const srcFile = process.argv[3];
const dstFile = process.argv[4];
const options = JSON.parse(process.env.JSO_OPTIONS || '{}');
const _rq = createRequire(__filename);
const api = _rq(entry);
const code = fs.readFileSync(srcFile, 'utf8');
const result = api.obfuscate(code, options);
fs.writeFileSync(dstFile, result.getObfuscatedCode ? result.getObfuscatedCode() : result);
`;

async function subprocessObfuscator(
    source: string,
    options?: Record<string, unknown>,
): Promise<ObfuscatorOutput> {
    const requirer = createRequire(typeof __filename !== 'undefined' ? __filename : import.meta.url);
    const entry = requirer.resolve('javascript-obfuscator') as string;

    const dir = await mkdtemp(join(tmpdir(), 'asset-shield-obf-'));
    const srcFile = join(dir, 'src.js');
    const dstFile = join(dir, 'dst.js');

    try {
        await writeFile(srcFile, source, 'utf8');

        const harnessFile = join(dir, 'harness.cjs');
        await writeFile(harnessFile, subprocessScript, 'utf8');

        const child = spawn(process.execPath, [harnessFile, entry, srcFile, dstFile], {
            env: { ...process.env, JSO_OPTIONS: JSON.stringify(options ?? {}) },
            stdio: ['ignore', 'ignore', 'pipe'],
        });

        const code = await new Promise<string>((done, fail) => {
            let stderr = '';
            child.stderr?.on('data', (chunk) => (stderr += String(chunk)));
            child.on('error', fail);
            child.on('close', (exit) => {
                if (exit !== 0) {
                    fail(new Error(stderr.trim() || `child exited ${exit}`));
                    return;
                }
                readFile(dstFile, 'utf8').then(done, fail);
            });
        });

        return code;
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
}

function defaultLoader(): Promise<Obfuscator | null> {
    return Promise.resolve(
        subprocessObfuscator as unknown as Obfuscator,
    );
}

export class ObfuscationEngine {
    private readonly loader: ObfuscatorLoader;
    private cached: Obfuscator | null | undefined;

    constructor(obfuscator?: Obfuscator) {
        this.loader = obfuscator ? () => Promise.resolve(obfuscator) : defaultLoader;
    }

    async available(): Promise<boolean> {
        return (await this.resolve()) !== null;
    }

    private async resolve(): Promise<Obfuscator | null> {
        if (this.cached === undefined) {
            try {
                this.cached = (await this.loader()) as Obfuscator | null;
            } catch {
                this.cached = null;
            }
        }

        return this.cached;
    }

    /**
     * Transform a single chunk. `preset` selects a named option bundle;
     * `override` is deep-merged over it. Returns the obfuscated code plus an
     * (optionally aligned) map; `obfuscated:false` means the engine chose not
     * to transform (empty source, or the package is unavailable).
     */
    async transformChunk(
        name: string,
        code: string,
        preset: ObfuscationPreset = 'balanced',
        override?: ObfuscatorOptions,
        sourceMap?: string | null,
    ): Promise<ObfuscationResult> {
        if (code.trim().length === 0) {
            return { code, map: null, obfuscated: false };
        }

        const obfuscator = await this.resolve();

        if (obfuscator === null || obfuscator === undefined) {
            return { code, map: null, obfuscated: false };
        }

        const options = mergeOptions(preset, override);

        if (sourceMap) {
            (options as Record<string, unknown>).sourceMap = true;
            (options as Record<string, unknown>).sourceMapMode = 'separate';
        }

        const output = await obfuscator(code, options);

        if (typeof output === 'string') {
            if (output.length === 0) {
                return { code, map: null, obfuscated: false };
            }

            let aligned: string | null = null;

            if (sourceMap) {
                try {
                    const incoming = JSON.parse(sourceMap) as { version?: number; file?: string; sources?: unknown[] };
                    aligned = JSON.stringify({ ...incoming, version: 3, file: name });
                } catch {
                    aligned = null;
                }
            }

            return { code: output, map: aligned, obfuscated: true };
        }

        if (
            output === null ||
            output === undefined ||
            typeof output.code !== 'string' ||
            output.code.length === 0
        ) {
            return { code, map: null, obfuscated: false };
        }

        return { code: output.code, map: output.map ?? null, obfuscated: true };
    }
}
