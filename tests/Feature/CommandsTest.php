<?php

use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Tests\TestCase;

it('builds the registry from the manifest', function () {
    $registryFile = TestCase::REGISTRY_FILE;
    @unlink($registryFile);

    $this->artisan('asset-shield:build')
        ->assertExitCode(0);

    expect(file_exists($registryFile))->toBeTrue();

    $registry = app(AssetRegistry::class);
    $registry->refresh();
    $opaque = $registry->opaqueForLogical('resources/js/app.js');

    expect($opaque)->toMatch('/^as_[a-f0-9]{16}$/')
        ->and($registry->fileForOpaque($opaque))->toBe('build/assets/app-A91Kx.js');
});

it('fails the build when the manifest is missing', function () {
    $tmp = storage_path('no-manifest-here.json');
    @unlink($tmp);

    config()->set('asset-shield.build.manifest', $tmp);
    $this->reloadAssetShield();

    $this->artisan('asset-shield:build')
        ->assertExitCode(1);
});

it('prints a status report', function () {
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:status');

    expect($exitCode)->toBe(0)
        ->and($kernel->output())->toContain('AssetShield', 'Enabled:', 'yes', 'valid');
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

it('doctor reports toolchain versions and obfuscation state', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('asset-shield.obfuscation.enabled', false);

    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:doctor');

    expect($exitCode)->toBe(0)
        ->and($kernel->output())->toContain('Node', 'Obfuscation disabled');
});

it('doctor warns on duplicate output filenames in the manifest', function () {
    $tmp = storage_path('manifest-dupes.json');
    file_put_contents($tmp, json_encode([
        'resources/js/a.js' => ['file' => 'assets/app-AAAAAA.js', 'src' => 'resources/js/a.js', 'isEntry' => true],
        'resources/js/b.js' => ['file' => 'vendor/app-AAAAAA.js', 'src' => 'resources/js/b.js', 'isEntry' => true],
    ], JSON_PRETTY_PRINT));

    // Real files on disk (same basename, different dirs) keep the registry valid
    // so only the duplicate-name warning fires.
    $a = public_path('build/assets/app-AAAAAA.js');
    $b = public_path('build/vendor/app-AAAAAA.js');
    @mkdir(dirname($a), 0777, true);
    @mkdir(dirname($b), 0777, true);
    file_put_contents($a, '// a');
    file_put_contents($b, '// b');

    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('asset-shield.build.manifest', $tmp);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $this->artisan('asset-shield:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('Duplicate output filenames');

    @unlink($tmp);
    @unlink($a);
    @unlink($b);
});

it('doctor fails exit 1 when manifest files are missing from disk', function () {
    $tmp = storage_path('manifest-ghosts.json');
    file_put_contents($tmp, json_encode([
        'resources/js/ghost.js' => ['file' => 'assets/ghost-MMMMMM.js', 'src' => 'resources/js/ghost.js', 'isEntry' => true],
    ], JSON_PRETTY_PRINT));

    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('asset-shield.build.manifest', $tmp);
    $this->reloadAssetShield();
    $this->bootstrapRegistry();

    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:doctor');

    // A registry entry whose compiled file is absent is a hard failure, and the
    // doctor also names the missing file explicitly as a warning.
    expect($exitCode)->toBe(1)
        ->and($kernel->output())->toContain('Manifest references missing files on disk');

    @unlink($tmp);
});

it('doctor fails when the registry is missing', function () {
    @unlink(TestCase::REGISTRY_FILE);

    $this->artisan('asset-shield:doctor')
        ->assertExitCode(1);
});

it('doctor fails when source maps are enabled', function () {
    config()->set('asset-shield.build.source_maps', true);
    $this->reloadAssetShield();

    $this->artisan('asset-shield:doctor')
        ->assertExitCode(1);
});

it('install publishes config and creates storage', function () {
    $target = config_path('asset-shield.php');

    $this->artisan('asset-shield:install', ['--force' => true])
        ->assertExitCode(0);

    expect(is_dir(storage_path('app/asset-shield')))->toBeTrue();

    if (file_exists($target)) {
        @unlink($target);
    }
});