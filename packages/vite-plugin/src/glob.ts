const GLOB_SPECIAL_CHARS = /[\\^$.[\]{}()|+]/;

export function globToRegExp(pattern: string): RegExp {
    const normalized = pattern.replace(/\\/g, '/');

    let out = '^';

    for (let i = 0; i < normalized.length; i++) {
        const char = normalized.charAt(i);

        if (char === '*') {
            const next = normalized.charAt(i + 1);

            if (next === '*') {
                i += 1;

                if (normalized.charAt(i + 1) === '/') {
                    i += 1;
                }

                out += '.*';
            } else {
                out += '[^/]*';
            }
        } else if (char === '?') {
            out += '[^/]';
        } else if (GLOB_SPECIAL_CHARS.test(char)) {
            out += '\\' + char;
        } else {
            out += char;
        }
    }

    out += '$';

    return new RegExp(out);
}

export function matchesAny(patterns: readonly string[] | undefined, path: string): boolean {
    if (patterns === undefined || patterns.length === 0) {
        return false;
    }

    const normalized = path.replace(/\\/g, '/');

    return patterns.some((pattern) => globToRegExp(pattern).test(normalized));
}