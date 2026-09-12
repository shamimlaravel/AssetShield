<?php

use Vendor\AssetShield\Support\OpaqueId;

test('opaque ids are deterministic 16-hex-char hashes', function () {
    $a = OpaqueId::from('build/assets/app-A91Kx.js', 'test-secret');
    $b = OpaqueId::from('build/assets/app-A91Kx.js', 'test-secret');

    expect($a)->toBe($b)
        ->and($a)->toMatch('/^[a-f0-9]{16}$/');
});

test('opaque ids differ between application keys', function () {
    $a = OpaqueId::from('build/assets/app-A91Kx.js', 'secret-one');
    $b = OpaqueId::from('build/assets/app-A91Kx.js', 'secret-two');

    expect($a)->not->toBe($b);
});

test('opaque ids differ between assets', function () {
    $a = OpaqueId::from('build/assets/app-A91Kx.js', 'secret');
    $b = OpaqueId::from('build/assets/app-2E5Zc7.css', 'secret');

    expect($a)->not->toBe($b);
});

test('canonicalize normalizes and rejects traversal', function () {
    expect(OpaqueId::canonicalize('build\\assets\\app.js'))->toBe('build/assets/app.js')
        ->and(OpaqueId::isSafe('build/assets/app.js'))->toBeTrue()
        ->and(OpaqueId::isSafe('../.env'))->toBeFalse()
        ->and(OpaqueId::isSafe('/etc/passwd'))->toBeFalse()
        ->and(OpaqueId::isSafe('build/../../.env'))->toBeFalse()
        ->and(OpaqueId::isSafe('C:\\Windows\\win.ini'))->toBeFalse()
        ->and(OpaqueId::isSafe(''))->toBeFalse();
});