import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { mkdtemp, writeFile, readFile, rm } from 'node:fs/promises';
import { mergeOptions, type ObfuscationPreset, type ObfuscatorOptions } from './presets';

export type ObfuscatorOutput = string | { code: string; map?: string | null };

export type Obfuscator = (
    source: string,
    options?: Record<string, unknown>,
) => ObfuscatorOutput | Promise<ObfuscatorOutput>;

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

interface JavaScriptObfuscatorApi {
    obfuscate(source: string, options?: Record<string, unknown>): {
        code?: string;
        map?: string | null;
        getObfuscatedCode?: () => string | Promise<string>;
        getSourceMap?: () => string | null | { toString(): string } | Promise<string | null | { toString(): string }>;
    } | null;
}

/**
 * Fast path: require `javascript-obfuscator` once, in-process, and transform
 * every chunk directly in the build. This avoids spawning a Node subprocess and
 * writing temp files per chunk. Yields null when the optional peer dependency
 * cannot be loaded, in which case the subprocess loader keeps working as a
 * fallback for exotic environments.
 *
 * The result of a single module lookup is cached for the process lifetime.
 */
let inProcessApi: JavaScriptObfuscatorApi | null | undefined;

async function inProcessObfuscator(): Promise<Obfuscator | null> {
    if (inProcessApi === undefined) {
        try {
            const requirer = createRequire(typeof __filename !== 'undefined' ? __filename : import.meta.url);
            const api = requirer('javascript-obfuscator') as JavaScriptObfuscatorApi;

            inProcessApi = typeof api?.obfuscate === 'function' ? api : null;
        } catch {
            inProcessApi = null;
        }
    }

    if (inProcessApi === null) {
        return null;
    }

    return async (source: string, options?: Record<string, unknown>): Promise<ObfuscatorOutput> => {
        const result = inProcessApi?.obfuscate(source, options ?? {});

        if (result === null || result === undefined || typeof result !== 'object') {
            return String(result);
        }

        const rawCode = typeof result.getObfuscatedCode === 'function'
            ? result.getObfuscatedCode()
            : typeof result.code === 'string'
              ? result.code
              : '';

        const code = (await rawCode) ?? '';

        if (typeof code !== 'string' || code.length === 0) {
            return '';
        }

        let map: string | null = null;

        if (typeof result.getSourceMap === 'function') {
            // `separate` mode: js-obfuscator hands back the raw map. Depending
            // on the version this is a string, a {toString()} wrapper, or a
            // promise of either.
            const rawMap = await result.getSourceMap();

            if (typeof rawMap === 'string') {
                map = rawMap.length > 0 ? rawMap : null;
            } else if (rawMap !== null && typeof rawMap === 'object') {
                const serialized = String(rawMap);
                map = serialized.length > 0 ? serialized : null;
            }
        } else if (typeof result.map === 'string') {
            map = result.map;
        }

        return map === null ? code : { code, map };
    };
}

function defaultLoader(): Promise<Obfuscator | null> {
    return inProcessObfuscator().then((inProcess) => inProcess ?? subprocessObfuscator);
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
