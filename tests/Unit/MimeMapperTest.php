<?php

use Shamimstack\AssetShield\Support\MimeMapper;

test('MimeMapper classifies families', function () {
    expect(MimeMapper::family('build/assets/app-A91Kx.js'))->toBe('script')
        ->and(MimeMapper::family('build/assets/app-2E5Zc7.css'))->toBe('style')
        ->and(MimeMapper::family('build/assets/logo-D3E4.svg'))->toBe('image')
        ->and(MimeMapper::family('build/assets/din-1A2B.woff2'))->toBe('font')
        ->and(MimeMapper::family('build/assets/pic.png'))->toBe('image');
});

test('MimeMapper resolves content types from extensions', function () {
    expect(MimeMapper::forPath('build/assets/app-A91Kx.js'))->toBe('text/javascript')
        ->and(MimeMapper::forPath('build/assets/app-2E5Zc7.css'))->toBe('text/css')
        ->and(MimeMapper::forPath('build/assets/logo-D3E4.svg'))->toBe('image/svg+xml')
        ->and(MimeMapper::forPath('build/assets/din-1A2B.woff2'))->toBe('font/woff2');
});

test('MimeMapper rejects unknown extensions', function () {
    expect(MimeMapper::forPath('build/assets/archive.xyz'))->toBeNull()
        ->and(MimeMapper::forPath('build/assets/data'))->toBeNull();
});

test('MimeMapper flags forbidden server-side files', function () {
    foreach (['.env', 'config.php', 'package-lock.json', 'composer.json', 'app.js.map', 'example.cs'] as $name) {
        expect(MimeMapper::isForbidden('build/'.$name))->toBeTrue();
    }

    expect(MimeMapper::isForbidden('build/assets/app-A91Kx.js'))->toBeFalse();
});