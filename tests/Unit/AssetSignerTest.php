<?php

use Shamimstack\AssetShield\Signer\HmacAssetSigner;

function signer(string $secret = 'test-secret'): HmacAssetSigner
{
    return new HmacAssetSigner($secret, leeway: 5);
}

test('signatures verify exactly once and round-trip', function () {
    $signer = signer();
    $expires = time() + 300;
    $sig = $signer->sign('opaque-id', $expires);

    expect($sig)->toBeString()
        ->and($signer->verify('opaque-id', $expires, $sig))->toBeTrue();
});

test('a DateTimeInterface (Carbon) expiry signs the same value', function () {
    $signer = signer();
    $expires = now()->addMinutes(10);

    $sig = $signer->sign('opaque-id', $expires);

    expect($signer->verify('opaque-id', $expires->getTimestamp(), $sig))->toBeTrue();
});

test('an invalid signature is rejected in constant time', function () {
    $signer = signer();
    $expires = time() + 300;

    expect($signer->verify('opaque-id', $expires, 'deadbeef'))->toBeFalse();
});

test('a signature for a different asset is rejected', function () {
    $signer = signer();
    $expires = time() + 300;
    $sig = $signer->sign('opaque-a', $expires);

    expect($signer->verify('opaque-b', $expires, $sig))->toBeFalse();
});

test('expired signatures fail even with a valid tag', function () {
    $signer = signer();
    $expires = time() - 60;
    $sig = $signer->sign('opaque-id', $expires);

    expect($signer->verify('opaque-id', $expires, $sig))->toBeFalse();
});

test('an empty signature string is rejected', function () {
    expect(signer()->verify('opaque-id', time() + 300, ''))->toBeFalse();
});

test('signing with an empty secret fails closed', function () {
    expect(fn () => signer('')->sign('opaque-id', time() + 300))
        ->toThrow(RuntimeException::class);
});

test('signing with the literal "null" secret fails closed', function () {
    expect(fn () => signer('null')->sign('opaque-id', time() + 300))
        ->toThrow(RuntimeException::class)
        ->and(fn () => signer('NULL')->sign('opaque-id', time() + 300))
        ->toThrow(RuntimeException::class);
});

test('a permanent signature (null expiry) round-trips and never looks expired', function () {
    $signer = signer();

    $sig = $signer->sign('opaque-id');

    expect($sig)->toBeString()
        ->and($signer->verify('opaque-id', null, $sig))->toBeTrue();
});

test('future timestamps beyond sanity are rejected', function () {
    $signer = signer();

    expect($signer->verify('opaque-id', 4102444801, 'x'))->toBeFalse()
        ->and($signer->verify('opaque-id', 0, 'x'))->toBeFalse();
});