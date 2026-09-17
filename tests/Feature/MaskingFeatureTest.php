<?php

use Shamimstack\AssetShield\AssetRegistry;
use Shamimstack\AssetShield\Masking\MaskPlanner;
use Shamimstack\AssetShield\Tests\TestCase;

/**
 * End-to-end mask flow: a plugin-style legend alongside a manifest whose
 * file names were masked must produce a registry with the final file and
 * the pre-mask original, validated deterministically by the PHP planner.
 */

function maskFixtureManifest(string $seed): array
{
    $planner = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => $seed]);

    $app = $planner->plan('resources/js/app.js', 'assets/app-original.js');
    $css = $planner->plan('resources/css/app.css', 'assets/app-original.css');

    return [$planner, $app, $css];
}

beforeEach(function () {
    @unlink(TestCase::REGISTRY_FILE);
});

/** @var list<string> paths to the placeholder mask files a given test created */
$createdFixtureFiles = [];

afterEach(function () use (&$createdFixtureFiles) {
    foreach ($createdFixtureFiles as $file) {
        @unlink($file);
    }
    $createdFixtureFiles = [];

    @unlink(storage_path('app/asset-shield/legend.json'));
    @unlink(storage_path('manifest-mask.json'));
});

it('builds a registry with original names and validates the legend', function () use (&$createdFixtureFiles) {
    [$planner, $app, $css] = maskFixtureManifest('seed-1');

    $manifestData = [
        'resources/js/app.js' => [
            'file' => $app['file'],
            'src' => 'resources/js/app.js',
            'isEntry' => true,
            'integrity' => 'sha384-fixture-integrity',
        ],
        'resources/css/app.css' => [
            'file' => $css['file'],
            'src' => 'resources/css/app.css',
            'isEntry' => true,
        ],
    ];

    $legendData = [
        'version' => 1,
        'built_at' => now()->toIso8601String(),
        'seed' => 'seed-1',
        'entries' => [
            $app['original'] => ['original' => $app['original'], 'masked' => $app['file']],
            $css['original'] => ['original' => $css['original'], 'masked' => $css['file']],
        ],
    ];

    // Placehold the masked files on disk so validation can confirm them.
    foreach ([$app, $css] as $row) {
        $createdFixtureFiles[] = public_path('build/'.$row['file']);
        file_put_contents(public_path('build/'.$row['file']), "// mask\n");
    }

    file_put_contents(storage_path('app/asset-shield/legend.json'), json_encode($legendData, JSON_PRETTY_PRINT));
    file_put_contents(storage_path('manifest-mask.json'), json_encode($manifestData, JSON_PRETTY_PRINT));

    config()->set('asset-shield.build.manifest', storage_path('manifest-mask.json'));
    config()->set('asset-shield.mask.enabled', true);
    config()->set('asset-shield.mask.strategy', 'nameless');
    config()->set('asset-shield.mask.seed', 'seed-1');
    config()->set('asset-shield.mask.legend', storage_path('app/asset-shield/legend.json'));
    $this->reloadAssetShield();

    $this->artisan('asset-shield:build')
        ->assertExitCode(0)
        ->expectsOutputToContain('Masking legend validated');

    $registry = app(AssetRegistry::class);
    $registry->refresh();

    $entry = $registry->entryForLogical('resources/js/app.js');

    expect($entry['file'])->toMatch('#^build/assets/[a-f0-9]{8}\.js$#')
        ->and($entry['original'])->toBe('build/assets/app-original.js')
        ->and($entry['integrity'])->toBe('sha384-fixture-integrity');
});

it('doctor fails when the mask seed does not match the legend', function () use (&$createdFixtureFiles) {
    [$planner, $app, $css] = maskFixtureManifest('seed-1');

    $manifestData = [
        'resources/js/app.js' => ['file' => $app['file'], 'src' => 'resources/js/app.js', 'isEntry' => true],
    ];

    $legendData = [
        'version' => 1,
        'built_at' => now()->toIso8601String(),
        'seed' => 'seed-1',
        'entries' => [$app['original'] => ['original' => $app['original'], 'masked' => $app['file']]],
    ];

    $createdFixtureFiles[] = public_path('build/'.$app['file']);
    file_put_contents(public_path('build/'.$app['file']), "// mask\n");
    file_put_contents(storage_path('app/asset-shield/legend.json'), json_encode($legendData, JSON_PRETTY_PRINT));
    file_put_contents(storage_path('manifest-mask.json'), json_encode($manifestData, JSON_PRETTY_PRINT));

    config()->set('asset-shield.build.manifest', storage_path('manifest-mask.json'));
    config()->set('asset-shield.mask.enabled', true);
    config()->set('asset-shield.mask.strategy', 'nameless');
    config()->set('asset-shield.mask.seed', 'a-different-seed');
    config()->set('asset-shield.mask.legend', storage_path('app/asset-shield/legend.json'));
    $this->reloadAssetShield();

    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $exitCode = $kernel->call('asset-shield:doctor');

    expect($exitCode)->toBe(1)
        ->and($kernel->output())->toContain('Masking legend mismatch');
});

it('is always read fresh from disk (never Laravel-cached)', function () use (&$createdFixtureFiles) {
    $legendFile = storage_path('app/asset-shield/legend.json');

    $writeLegend = function (string $original, string $masked) use ($legendFile) {
        file_put_contents($legendFile, json_encode([
            'version' => 1,
            'seed' => 'seed-1',
            'entries' => [$original => ['original' => $original, 'masked' => $masked]],
        ], JSON_PRETTY_PRINT));
    };

    $createdFixtureFiles[] = public_path('build/assets/first-mask.js');
    $writeLegend('assets/app-original.js', 'assets/first-mask.js');
    file_put_contents(public_path('build/assets/first-mask.js'), "// mask\n");

    $legend = new \Shamimstack\AssetShield\Masking\Legend($legendFile);

    expect($legend->originalForMasked('assets/first-mask.js'))->toBe('assets/app-original.js');

    // A fresh legend instance (i.e. a new worker/process) must see a plugin
    // rebuild immediately — no cache purge required, because the legend is
    // never stored in the Laravel cache.
    $createdFixtureFiles[] = public_path('build/assets/second-mask.js');
    $writeLegend('assets/app-original.js', 'assets/second-mask.js');
    file_put_contents(public_path('build/assets/second-mask.js'), "// mask\n");

    $fresh = new \Shamimstack\AssetShield\Masking\Legend($legendFile);

    expect($fresh->originalForMasked('assets/second-mask.js'))->toBe('assets/app-original.js')
        ->and($fresh->originalForMasked('assets/first-mask.js'))->toBeNull();
});

it('keeps original names when mask is enabled but no legend exists', function () use (&$createdFixtureFiles) {
    $planner = new MaskPlanner(['enabled' => true, 'strategy' => 'nameless', 'seed' => 'seed-1']);
    $app = $planner->plan('resources/js/app.js', 'assets/app-A91Kx.js');

    $manifestData = [
        'resources/js/app.js' => ['file' => $app['file'], 'src' => 'resources/js/app.js', 'isEntry' => true],
    ];

    $createdFixtureFiles[] = public_path('build/'.$app['file']);
    file_put_contents(public_path('build/'.$app['file']), "// mask\n");
    file_put_contents(storage_path('manifest-mask.json'), json_encode($manifestData, JSON_PRETTY_PRINT));

    config()->set('asset-shield.build.manifest', storage_path('manifest-mask.json'));
    config()->set('asset-shield.mask.enabled', true);
    config()->set('asset-shield.mask.strategy', 'nameless');
    config()->set('asset-shield.mask.seed', 'seed-1');
    config()->set('asset-shield.mask.legend', storage_path('app/asset-shield/legend.json'));
    $this->reloadAssetShield();

    // No legend on disk (afterEach cleans it, but ensure absence).
    @unlink(storage_path('app/asset-shield/legend.json'));

    $this->artisan('asset-shield:build')
        ->assertExitCode(0)
        ->expectsOutputToContain('no legend was found');

    $registry = app(AssetRegistry::class);
    $registry->refresh();

    $entry = $registry->entryForLogical('resources/js/app.js');

    expect($entry['file'])->toBe('build/'.$app['file'])
        ->and($entry['original'])->toBeNull();
});