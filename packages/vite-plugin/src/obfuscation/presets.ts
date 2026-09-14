export type ObfuscationPreset = 'light' | 'balanced' | 'aggressive';

export type ObfuscatorOptions = Record<string, unknown>;

const COMMON_TARGET: ObfuscatorOptions = {
    compact: true,
    simplify: true,
    target: 'browser',
    renameGlobals: false,
    debugProtection: false,
};

const COMMON_STRINGS: ObfuscatorOptions = {
    stringArray: true,
    stringArrayThreshold: 0,
    stringArrayRotate: true,
    stringArrayShuffle: true,
};

export const PRESET_FEATURES: Record<ObfuscationPreset, string[]> = {
    light: [
        'hexadecimal identifier renaming',
        'base64 string array',
        '0.5 string-array threshold',
        'split strings (chunk length 12)',
        'simplification and control-flow cleanup',
        'no self-defending or anti-debug runtime',
    ],
    balanced: [
        'hexadecimal identifier renaming',
        'rc4 string array (encoded, rotated, shuffled)',
        '0.75 string-array threshold',
        'split strings (chunk length 8)',
        'numbers-as-expressions',
        'control-flow flattening (0.5)',
        'self-defending disabled',
    ],
    aggressive: [
        'hexadecimal / mangled identifier renaming',
        'rc4 + base64 string array (1.0 threshold)',
        'split strings (chunk length 4)',
        'numbers-as-expressions',
        'control-flow flattening (1.0)',
        'dead-code injection',
        'object-key transformation',
        'self-defending + debug protection + console output disabled',
        'maximum obfuscation with a measurable runtime and bundle-size cost',
    ],
};

const PRESETS: Record<ObfuscationPreset, ObfuscatorOptions> = {
    light: {
        ...COMMON_TARGET,
        ...COMMON_STRINGS,
        stringArrayThreshold: 0.5,
        stringArrayEncoding: ['base64'],
        identifierNamesGenerator: 'hexadecimal',
        splitStrings: true,
        splitStringsChunkLength: 12,
        transformObjectKeys: false,
        controlFlowFlattening: false,
        deadCodeInjection: false,
        numbersToExpressions: false,
        selfDefending: false,
        disableConsoleOutput: false,
    },
    balanced: {
        ...COMMON_TARGET,
        ...COMMON_STRINGS,
        stringArrayThreshold: 0.75,
        stringArrayEncoding: ['rc4'],
        identifierNamesGenerator: 'hexadecimal',
        splitStrings: true,
        splitStringsChunkLength: 8,
        transformObjectKeys: false,
        controlFlowFlattening: true,
        controlFlowFlatteningThreshold: 0.5,
        deadCodeInjection: false,
        numbersToExpressions: true,
        selfDefending: false,
        disableConsoleOutput: false,
    },
    aggressive: {
        ...COMMON_TARGET,
        ...COMMON_STRINGS,
        stringArrayThreshold: 1,
        stringArrayEncoding: ['rc4', 'base64'],
        identifierNamesGenerator: 'mangled-shuffled',
        splitStrings: true,
        splitStringsChunkLength: 4,
        transformObjectKeys: true,
        controlFlowFlattening: true,
        controlFlowFlatteningThreshold: 1,
        deadCodeInjection: true,
        numbersToExpressions: true,
        selfDefending: true,
        debugProtection: true,
        disableConsoleOutput: true,
    },
};

/**
 * Options for a preset, cloned so callers can never mutate the shared table.
 */
export function presetOptions(preset: ObfuscationPreset = 'balanced'): ObfuscatorOptions {
    return deepClone(PRESETS[preset] ?? PRESETS.balanced);
}

/**
 * Human-readable feature list for the build report / status output.
 */
export function describePreset(preset: ObfuscationPreset): string[] {
    return PRESET_FEATURES[preset] ?? PRESET_FEATURES.balanced;
}

/**
 * Deep-merge a caller override on top of a preset. Arrays (such as
 * `stringArrayEncoding`) and scalars are replaced wholesale; plain objects are
 * merged recursively. `undefined` values are ignored so partial overrides never
 * clear a preset key by accident.
 */
export function mergeOptions(preset: ObfuscationPreset, override?: ObfuscatorOptions): ObfuscatorOptions {
    const merged: ObfuscatorOptions = presetOptions(preset);

    if (override === undefined || override === null) {
        return merged;
    }

    applyOverrides(merged, override);

    return merged;
}

function applyOverrides(target: ObfuscatorOptions, overrides: ObfuscatorOptions): void {
    for (const [key, value] of Object.entries(overrides)) {
        if (value === undefined) {
            continue;
        }

        if (isRecord(value) && isRecord(target[key])) {
            applyOverrides(target[key] as ObfuscatorOptions, value as ObfuscatorOptions);
            continue;
        }

        target[key] = value;
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function deepClone(value: unknown): ObfuscatorOptions {
    return value === null || typeof value !== 'object'
        ? {}
        : JSON.parse(JSON.stringify(value)) as ObfuscatorOptions;
}