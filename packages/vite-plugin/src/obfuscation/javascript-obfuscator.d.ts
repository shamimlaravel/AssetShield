/**
 * Minimal ambient declaration for the optional `javascript-obfuscator`
 * dependency. The package is an optional peer dependency; this module is only
 * loaded at build time when obfuscation is enabled.
 */
declare module 'javascript-obfuscator' {
    interface IObfuscatedCode {
        getObfuscatedCode(): string;
        getSourceMap(): string;
    }

    namespace JavaScriptObfuscator {
        function obfuscate(source: string, options?: Record<string, unknown>): IObfuscatedCode;
    }

    function JavaScriptObfuscator(source: string, options?: Record<string, unknown>): string;

    export = JavaScriptObfuscator;
}