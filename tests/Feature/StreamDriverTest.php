<?php

use Shamimstack\AssetShield\AssetIdentity;
use Shamimstack\AssetShield\Delivery\StreamDriver;

it('streams a file via BinaryFileResponse with range support', function () {
    $driver = app(StreamDriver::class);

    $response = $driver->deliver(new AssetIdentity(
        'resources/js/app.js',
        'build/assets/app-A91Kx.js',
        'as_'.str_repeat('a', 16),
        'script',
        'sha384-KwLqDpyFkPYEy2b1fXnfK2J5bN8UYrIlVlGtZg0VYb9ufmZnKIj7xK5h5QjrPYUR',
    ));

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class)
        ->and($response->getFile()->getPathname())->toBe(realpath(public_path('build/assets/app-A91Kx.js')))
        ->and($response->headers->get('Content-Type'))->toContain('text/javascript')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->isOk())->toBeTrue();
});

it('streams the on-disk contents verbatim', function () {
    $driver = app(StreamDriver::class);
    $path = public_path('build/assets/app-A91Kx.js');

    $response = $driver->deliver(new AssetIdentity('resources/js/app.js', 'build/assets/app-A91Kx.js', 'as_x', 'script'));

    $stream = $response->getFile()->openFile('r');
    $contents = (string) $stream->fread($stream->getSize());

    expect($contents)->toContain('asset-shield fixture');
});

it('honours cache overrides and the immutable flag', function () {
    $driver = app(StreamDriver::class);

    $response = $driver->deliver(
        new AssetIdentity('resources/js/app.js', 'build/assets/app-A91Kx.js', 'as_x', 'script'),
        60,
        true,
    );

    $cacheControl = $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('max-age=60')
        ->and($cacheControl)->not->toContain('immutable')
        ->and((int) preg_replace('~.*max-age=(\d+).*~', '$1', $cacheControl))->toBeLessThanOrEqual(60);
});

it('supports only existing protected content with a mime', function () {
    $driver = app(StreamDriver::class);

    expect($driver->supports(new AssetIdentity('resources/js/app.js', 'build/assets/app-A91Kx.js', 'as_x', 'script')))->toBeTrue()
        ->and($driver->supports(new AssetIdentity('ghost', 'build/assets/missing.js', 'as_x', 'script')))->toBeFalse()
        ->and($driver->supports(new AssetIdentity('env', 'build/.env', 'as_x', 'unknown')))->toBeFalse();
});

it('throws when the compiled file is missing', function () {
    app(StreamDriver::class)->deliver(new AssetIdentity('ghost', 'build/assets/missing.js', 'as_x', 'script'));
})->throws(\Shamimstack\AssetShield\Exceptions\AssetNotFoundException::class);