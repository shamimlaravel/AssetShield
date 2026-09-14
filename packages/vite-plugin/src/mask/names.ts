import { matchesAny } from '../glob';
import { adjectives, nouns } from './dictionary';
import { fnv1a32, hex8 } from './fnv1a';

/**
 * Masking name generation. Mirrors `Shamimstack\AssetShield\Masking\*`
 * exactly, so names recomputed PHP-side agree with what the plugin emits.
 */

export type MaskStrategy = 'preserve' | 'nameless' | 'codename';

export interface MaskConfig {
    enabled: boolean;
    strategy: MaskStrategy;
    seed: string;
    aliases: Record<string, string>;
    include: string[];
    exclude: string[];
}

function normalizeSlashes(path: string): string {
    return path.replace(/\\/g, '/');
}

export function dirOf(path: string): string {
    const normalized = normalizeSlashes(path);
    const index = normalized.lastIndexOf('/');

    return index < 0 ? '.' : (normalized.slice(0, index) || '/');
}

export function baseOf(path: string): string {
    const normalized = normalizeSlashes(path);
    const index = normalized.lastIndexOf('/');

    return index < 0 ? normalized : normalized.slice(index + 1);
}

/** Extension WITHOUT the leading dot, mirroring PHP pathinfo(PATHINFO_EXTENSION). */
function extOf(path: string): string {
    const base = baseOf(path);
    const index = base.lastIndexOf('.');

    return index <= 0 ? '' : base.slice(index + 1);
}

function withExt(base: string, ext: string): string {
    return ext === '' ? base : base + '.' + ext;
}

function isRoot(dir: string): boolean {
    return dir === '.' || dir === '/' || dir === '';
}

export function namelessName(seed: string, canonicalPath: string): string {
    return withExt(hex8(seed + ':' + canonicalPath), extOf(canonicalPath));
}

export function codenameName(seed: string, canonicalPath: string): string {
    const adjective = adjectives[fnv1a32(seed + ':a:' + canonicalPath) % adjectives.length];
    const noun = nouns[fnv1a32(seed + ':n:' + canonicalPath) % nouns.length];

    return withExt(adjective + '-' + noun, extOf(canonicalPath));
}

/**
 * Deterministic planner, mirroring MaskPlanner::plan(). Reusing one
 * instance enforces the same collision-avoidance probing the PHP side applies.
 */
export class MaskPlanner {
    private readonly used = new Set<string>();

    constructor(private readonly config: MaskConfig) {}

    plan(logical: string, original: string): { original: string; file: string } {
        const dir = dirOf(original);
        const base = this.finalBasename(logical, original);
        const ext = extOf(original);

        let candidate = isRoot(dir) ? base : dir + '/' + base;

        let k = 0;
        while (this.used.has(candidate)) {
            k += 1;
            const suffix = hex8(
                (this.config.seed || 'asset-shield') + ':collide:' + original + ':' + k,
            ).slice(0, 2);

            const name =
                ext === ''
                    ? base + '-' + suffix
                    : base.replace(new RegExp('\\.' + ext.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'), '-' + suffix + '.' + ext);

            candidate = isRoot(dir) ? name : dir + '/' + name;
        }

        this.used.add(candidate);

        return { original, file: candidate };
    }

    shouldMask(path: string): boolean {
        const include = this.config.include.length > 0 ? this.config.include : ['**'];
        const exclude = this.config.exclude;
        const included = matchesAny(include, path) || matchesAny(include, baseOf(path));
        const excluded = matchesAny(exclude, path) || matchesAny(exclude, baseOf(path));

        return included && !excluded;
    }

    private finalBasename(logical: string, original: string): string {
        const alias = this.config.aliases[logical];

        if (alias !== undefined) {
            const ext = extOf(original);

            return ext !== '' && !alias.endsWith('.' + ext) ? alias + '.' + ext : alias;
        }

        if (!this.config.enabled || !this.shouldMask(original)) {
            return baseOf(original);
        }

        const seed = this.config.seed || 'asset-shield';

        switch (this.config.strategy) {
            case 'codename':
                return codenameName(seed, original);
            case 'preserve':
                return baseOf(original);
            case 'nameless':
            default:
                return namelessName(seed, original);
        }
    }
}