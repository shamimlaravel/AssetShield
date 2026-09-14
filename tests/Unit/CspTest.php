<?php

use Shamimstack\AssetShield\Security\Csp;

it('builds a strict, nonce-bearing policy by default', function () {
    $csp = new Csp();

    $header = $csp->header();

    expect($header)->toContain("default-src 'self'")
        ->and($header)->toContain("object-src 'none'")
        ->and($header)->toContain("base-uri 'self'")
        ->and($header)->toContain("frame-ancestors 'none'")
        ->and($header)->toContain("'nonce-{$csp->nonce()}'");
});

it('returns a single stable nonce per instance', function () {
    $csp = new Csp();

    expect($csp->nonce())->toBe($csp->nonce())
        ->and($csp->nonce())->toMatch('/^_[a-f0-9]{32}$/');
});

it('never reuses the same nonce across instances', function () {
    $a = (new Csp())->nonce();
    $b = (new Csp())->nonce();

    expect($a)->not->toBe($b);
});

it('appends allowlisted sources without weakening the defaults', function () {
    $csp = new Csp([
        'allowlist' => [
            'script' => ['https://cdn.example.com'],
            'style' => ['https://fonts.googleapis.com'],
            'img' => ['https://images.example.com'],
        ],
    ]);

    $header = $csp->header();

    expect($header)->toContain("script-src 'self' 'nonce-")
        ->and($header)->toContain('https://cdn.example.com')
        ->and($header)->toContain("style-src 'self' https://fonts.googleapis.com")
        ->and($header)->toContain("img-src 'self' data: https://images.example.com")
        ->and($header)->not->toContain("'unsafe-inline'");
});

it('emits a strict nonce-free policy for binary asset responses', function () {
    $csp = new Csp();

    $headers = $csp->headersForAsset();

    expect($headers['Content-Security-Policy'])->toContain("default-src 'none'")
        ->and($headers['Content-Security-Policy'])->not->toContain('nonce-')
        ->and($headers['X-Content-Type-Options'])->toBe('nosniff');
});