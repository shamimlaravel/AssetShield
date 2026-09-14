import { matchesAny } from './glob';

/**
 * Chunk selection can be one of the named modes or an explicit predicate.
 * A function predicate is authoritative over selection; when one is supplied,
 * the `include`/`exclude` glob lists are ignored (the function is the filter).
 * The CSS guard always applies, since CSS is never obfuscated.
 */
export type ObfuscateChunks = 'application' | 'entries' | 'all' | ChunkPredicate;

export interface ChunkRuleContext {
    fileName: string;
    isEntry: boolean;
    moduleIds: readonly string[];
    isCss?: boolean;
}

export interface ObfuscationRules {
    mode: ObfuscateChunks;
    include?: string[];
    exclude?: string[];
}

export type ChunkPredicate = (context: ChunkRuleContext) => boolean;

export function makeChunkPredicate(rules: ObfuscationRules): ChunkPredicate {
    const { mode } = rules;

    if (typeof mode === 'function') {
        return (context: ChunkRuleContext): boolean => {
            if (context.isCss === true || context.fileName.toLowerCase().endsWith('.css')) {
                return false;
            }

            return mode(context);
        };
    }

    const hasInclude = rules.include !== undefined && rules.include.length > 0;
    const hasExclude = rules.exclude !== undefined && rules.exclude.length > 0;

    return (context: ChunkRuleContext): boolean => {
        if (context.isCss === true || context.fileName.toLowerCase().endsWith('.css')) {
            return false;
        }

        if (hasExclude && matchesAny(rules.exclude, context.fileName)) {
            return false;
        }

        if (hasInclude && !matchesAny(rules.include, context.fileName)) {
            return false;
        }

        switch (mode) {
            case 'all':
                return true;
            case 'entries':
                return context.isEntry;
            case 'application':
            default:
                return !context.moduleIds.some((id) => id.includes('node_modules'));
        }
    };
}