<?php

declare(strict_types=1);

namespace Shamimstack\AssetShield\Security;

/**
 * Content-Security-Policy builder.
 *
 * Emits a strict-by-default policy with a per-request nonce so the app can
 * inline script/style tags safely without a CSP reporting dependency. Source
 * allowlists (script/style/img) are opt-in extras — they do not relax the
 * defaults on their own.
 *
 * A CSP nonce is NOT a secret: it is delivered to clients in every response,
 * which is why it must be unique per request and never reused across them.
 */
class Csp
{
    private string $nonce = '';

    /** @param array{allowlist?: array{script?: list<string>, style?: list<string>, img?: list<string>}} $config */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Stable, per-instance nonce (one request = one instance).
     */
    public function nonce(): string
    {
        return $this->nonce !== '' ? $this->nonce : ($this->nonce = '_'.bin2hex(random_bytes(16)));
    }

    /**
     * @return string the Content-Security-Policy header value.
     */
    public function header(): string
    {
        $allowlist = (array) ($this->config['allowlist'] ?? []);

        $sources = static function (array $hosts): string {
            $hosts = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $hosts)), fn (string $h): bool => $h !== '')));

            return implode(' ', $hosts);
        };

        $script = trim(implode(' ', array_filter([
            "'self'",
            "'nonce-{$this->nonce()}'",
            $sources((array) ($allowlist['script'] ?? [])),
        ], fn (string $part): bool => $part !== '')));

        $style = trim(implode(' ', array_filter([
            "'self'",
            $sources((array) ($allowlist['style'] ?? [])),
        ], fn (string $part): bool => $part !== '')));

        $img = trim(implode(' ', array_filter([
            "'self'",
            'data:',
            $sources((array) ($allowlist['img'] ?? [])),
        ], fn (string $part): bool => $part !== '')));

        $directives = [
            'default-src' => "'self'",
            'script-src' => $script !== '' ? $script : "'self'",
            'style-src' => $style !== '' ? $style : "'self'",
            'img-src' => $img !== '' ? $img : "'self' data:",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'frame-ancestors' => "'none'",
            'form-action' => "'self'",
        ];

        return implode('; ', array_map(
            static fn (string $directive, string $value): string => "{$directive} {$value}",
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * Strict, nonce-free policy for binary asset responses: by the time a
     * client executes bytes it has already passed every check, so the asset
     * itself never needs inline script allowances.
     *
     * @return array<string, string>
     */
    public function headersForAsset(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}