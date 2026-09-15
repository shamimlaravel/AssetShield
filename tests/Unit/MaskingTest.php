<?php

use Shamimstack\AssetShield\Masking\MaskPlanner;
use Shamimstack\AssetShield\Masking\CodenameResolver;
use Shamimstack\AssetShield\Masking\HashResolver;
use Shamimstack\AssetShield\Masking\PreserveResolver;
use Shamimstack\AssetShield\Support\Fnv1a;

test('fnv1a hex8 is deterministic and 8 hex digits', function () {
    expect(Fnv1a::hex8('assets/app-A91Kx.js'))->toBe(Fnv1a::hex8('assets/app-A91Kx.js'))
        ->and(Fnv1a::hex8('assets/app-A91Kx.js'))->toMatch('/^[a-f0-9]{8}$/')
        ->and(Fnv1a::hex8('a'))->not->toBe(Fnv1a::hex8('b'));
});

test('preserve strategy keeps the original basename', function () {
    $resolver = new PreserveResolver();
    expect($resolver->resolve('resources/js/app.js', 'assets/app-A91Kx.js'))->toBe('app-A91Kx.js');
});

test('nameless strategy produces an 8-hex filename', function () {
    $resolver = new HashResolver('secret-seed');
    $name = $resolver->resolve('resources/js/app.js', 'assets/app-A91Kx.js');

    expect($name)->toMatch('/^[a-f0-9]{8}\.js$/');
});

test('codename strategy produces an adjective-noun filename', function () {
    $resolver = new CodenameResolver('secret-seed');
    $name = $resolver->resolve('resources/js/app.js', 'assets/app-A91Kx.js');

    expect($name)->toMatch('/^[a-z]+-[a-z]+\.js$/');
});

test('planner mirrors deterministically across instances', function () {
    $config = ['enabled' => true, 'strategy' => 'nameless', 'seed' => 'secret-seed'];

    $first = (new MaskPlanner($config))->plan('resources/js/app.js', 'assets/app-A91Kx.js');
    $second = (new MaskPlanner($config))->plan('resources/js/app.js', 'assets/app-A91Kx.js');

    expect($first)->toBe($second)
        ->and($first['file'])->toMatch('#^assets/[a-f0-9]{8}\.js$#')
        ->and($first['original'])->toBe('assets/app-A91Kx.js');
});

test('planner is disabled by default and honours exclude rules', function () {
    $planner = new MaskPlanner(['enabled' => false, 'strategy' => 'nameless', 'seed' => 's']);
    expect($planner->plan('resources/js/app.js', 'assets/app-A91Kx.js')['file'])->toBe('assets/app-A91Kx.js');

    $planner = new MaskPlanner([
        'enabled' => true,
        'strategy' => 'nameless',
        'seed' => 's',
        'exclude' => ['assets/vendor/*'],
    ]);

    expect($planner->shouldMask('assets/app-A91Kx.js'))->toBeTrue()
        ->and($planner->shouldMask('assets/vendor/vue.js'))->toBeFalse()
        ->and($planner->plan('resources/js/vendor.js', 'assets/vendor/vue.js')['file'])->toBe('assets/vendor/vue.js');
});

test('planner applies logical aliases verbatim', function () {
    $planner = new MaskPlanner([
        'enabled' => true,
        'strategy' => 'nameless',
        'seed' => 's',
        'aliases' => ['resources/js/app.js' => 'main'],
    ]);

    expect($planner->plan('resources/js/app.js', 'assets/app-A91Kx.js')['file'])->toBe('assets/main.js');
});

test('planner resolves collisions deterministically', function () {
    $config = ['enabled' => true, 'strategy' => 'nameless', 'seed' => 'same-seed-for-both'];
    $planner = new MaskPlanner($config);

    $one = $planner->plan('resources/js/a/index.js', 'assets/a-index.js');
    $two = $planner->plan('resources/js/b/index.js', 'assets/b-index.js');

    expect($one['file'])->not->toBe($two['file']);
});

test('empty seed falls back to asset-shield, matching the vite plugin', function () {
    $empty = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => '']);
    $explicit = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => 'asset-shield']);

    expect($empty->plan('resources/js/app.js', 'assets/app-A91Kx.js'))
        ->toBe($explicit->plan('resources/js/app.js', 'assets/app-A91Kx.js'));
});

test('empty include list matches everything, like the vite plugin', function () {
    $none = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => 's', 'include' => []]);
    $all = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => 's']);

    expect($none->shouldMask('assets/app-A91Kx.js'))->toBeTrue()
        ->and($none->plan('resources/js/app.js', 'assets/app-A91Kx.js'))
        ->toBe($all->plan('resources/js/app.js', 'assets/app-A91Kx.js'));
});

test('codename and nameless differ for the same input', function () {
    $hash = new HashResolver('same-seed');
    $code = new CodenameResolver('same-seed');

    expect($hash->resolve('resources/js/app.js', 'assets/app-A91Kx.js'))
        ->not->toBe($code->resolve('resources/js/app.js', 'assets/app-A91Kx.js'));
});