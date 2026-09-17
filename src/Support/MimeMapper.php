<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Support;

/**
 * Extension -> Content-Type mapping for the supported protected asset types,
 * plus an explicit denylist of server-side extensions that must never be
 * delivered (source maps, PHP source, env files, lockfiles, ...).
 */
final class MimeMapper
{
    private const MAP = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'cjs' => 'text/javascript',
        'css' => 'text/css',
        'svg' => 'image/svg+xml',
        'json' => 'application/json',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
    ];

    private const FORBIDDEN_EXTENSIONS = [
        'map',
        'php',
        'phtml',
        'php3',
        'php4',
        'php5',
        'phar',
        'cs',
        'env',
        'lock',
        'git',
        'ini',
        'sql',
        'log',
        'bak',
        'sh',
    ];

    /**
     * Return the Content-Type for a compiled relative path, or null when the
     * extension is unknown and therefore not a protected asset type.
     */
    public static function forPath(string $relativePath): ?string
    {
        $parts = self::parts($relativePath);

        if (self::isForbiddenParts($parts)) {
            return null;
        }

        return self::MAP[$parts['extension']] ?? null;
    }

    /**
     * True when the compiled relative path resolves to a server-side filename
     * that must never be served (source maps, PHP source, .env, ...).
     *
     * `.env.example` is intentionally NOT in the blanket list below because we
     * match the basename exactly for env-style files.
     */
    public static function isForbidden(string $relativePath): bool
    {
        return self::isForbiddenParts(self::parts($relativePath));
    }

    /**
     * @param  array{normalized:string, basename:string, extension:string}  $parts
     */
    private static function isForbiddenParts(array $parts): bool
    {
        if (in_array($parts['basename'], ['env', '.env', '.gitignore', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'readme.md', 'license'], true)) {
            return true;
        }

        return in_array($parts['extension'], self::FORBIDDEN_EXTENSIONS, true);
    }

    /**
     * Single-pass decomposition: normalized path, lowercase basename and
     * lowercase extension for one shared scan instead of three.
     *
     * @return array{normalized:string, basename:string, extension:string}
     */
    private static function parts(string $path): array
    {
        $normalized = str_replace('\\', '/', $path);

        return [
            'normalized' => $normalized,
            'basename' => strtolower((string) basename($normalized)),
            'extension' => strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * The media family (script, style, image, font) for tag rendering and
     * registry metadata.
     */
    public static function family(string $relativePath): string
    {
        $extension = self::parts($relativePath)['extension'];

        return match (true) {
            in_array($extension, ['css'], true) => 'style',
            in_array($extension, ['js', 'mjs', 'cjs'], true) => 'script',
            in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico'], true) => 'image',
            in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true) => 'font',
            default => 'script',
        };
    }
}