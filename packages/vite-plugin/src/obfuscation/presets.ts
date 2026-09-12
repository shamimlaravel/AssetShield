export type ObfuscationPreset = 'light' | 'balanced' | 'aggressive';

export type ObfuscatorOptions = Record<string, unknown>;

const BASE_OPTIONS: ObfuscatorOptions = {
    compact: true,
    selfDefending: false,
    disableConsoleOutput: false,
    renameGlobals: false,
    simplify: true,
    stringArray: true,
    transformObjectKeys: false,
    numbersToExpressions: false,
};

const PRESETS: Record<ObfuscationPreset, ObfuscatorOptions> = {
    light: {
        ...BASE_OPTIONS,
        stringArray: false,
    },
    balanced: {
        ...BASE_OPTIONS,
        stringArrayThreshold: 0.75,
    },
    aggressive: {
        ...BASE_OPTIONS,
        selfDefending: true,
        stringArrayThreshold: 1,
        transformObjectKeys: true,
        numbersToExpressions: true,
    },
};

export function presetOptions(preset: ObfuscationPreset = 'balanced'): ObfuscatorOptions {
    return { ...PRESETS[preset] };
}