<?php

it('builds the registry from the manifest', function () {
    $registryFile = \Vendor\AssetShield\Tests\TestCase::FIXTURES.'/storage/asset-shield/registry.json';
    @unlink($registryFile);

    $this->artisan('asset-shield:build')
        ->assertExitCode(0);

    expect(file_exists($registryFile))->toBeTrue();

    $registry = app(\Vendor\AssetShield\AssetRegistry::class);
    $registry->refresh();
    $opaque = $registry->opaqueForLogical('resources/js/app.js');

    expect($opaque)->toMatch('/^[a-f0-9]{16}$/')
        ->and($registry->compiledForOpaque($opaque))->toBe('build/assets/app-A91Kx.js');
});

it('fails the build when the manifest is missing', function () {
    $tmp = storage_path('no-manifest-here.json');
    @unlink($tmp);

    config()->set('asset-shield.manifest_path', $tmp);
    $this->reloadAssetShield();

    $this->artisan('asset-shield:build')
        ->assertExitCode(1);
});

it('prints a status report', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:status');

    expect($exitCode)->toBe(0)
        ->and($kernel->output())->toContain('AssetShield', 'Enabled:', 'yes', 'protected', 'valid');
});

it('prints status as json', function () {
    $this->artisan('asset-shield:status', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled": true');
});

it('doctor passes for a healthy environment', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);

    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:doctor');

    expect($exitCode)->toBe(0)
        ->and($kernel->output())->toContain('healthy');
});

it('doctor fails when the registry is missing', function () {
    @unlink(\Vendor\AssetShield\Tests\TestCase::FIXTURES.'/storage/asset-shield/registry.json');

    $this->artisan('asset-shield:doctor')
        ->assertExitCode(1);
});

it('doctor fails when source maps are enabled', function () {
    config()->set('asset-shield.source_maps', true);
    $this->reloadAssetShield();

    $this->artisan('asset-shield:doctor')
        ->assertExitCode(1);
});

it('install publishes config and creates storage', function () {
    $target = config_path('asset-shield.php');

    $this->artisan('asset-shield:install', ['--force' => true])
        ->assertExitCode(0);

    expect(is_dir(storage_path('asset-shield')))->toBeTrue();

    if (file_exists($target)) {
        @unlink($target);
    }
});