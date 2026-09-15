<?php

use Symfony\Component\Process\Process;
use Shamimstack\AssetShield\Exceptions\ObfuscationException;
use Shamimstack\AssetShield\Obfuscation\JavascriptObfuscator;

function nodeAvailable(): bool
{
    $process = new Process(['node', '--version']);
    $process->run();

    return $process->isSuccessful();
}

function fakeObfuscatorModule(string $dir): string
{
    $file = $dir.'/javascript-obfuscator-entry.js';
    file_put_contents($file, <<<'JS'
        module.exports = {
            obfuscate: (code, options) => 'obfuscated[' + code.length + '][' + JSON.stringify(options).length + ']'
        };
        JS);

    return $file;
}

it('reports available only when the package entry exists', function (string $dir) {
    $engine = new JavascriptObfuscator('node', $dir.'/does-not-exist.js');

    expect($engine->isAvailable())->toBeFalse();
})->with(function () {
    $dir = sys_get_temp_dir().'/asset-shield-jso-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    return ['dir' => $dir];
});

it('exports the documented presets', function () {
    $engine = new JavascriptObfuscator('node', __FILE__);

    expect($engine->presets())->toContain('none', 'performance', 'balanced', 'high');
});

it('obfuscates source through a real node process when the package is a fake module', function () {
    if (! nodeAvailable()) {
        $this->markTestSkipped('node is not on PATH.');
    }

    $dir = sys_get_temp_dir().'/asset-shield-jso-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $engine = new JavascriptObfuscator('node', fakeObfuscatorModule($dir));

    $out = $engine->obfuscate('const x = 1;', ['compact' => true]);

    expect($out)->toMatch('/^obfuscated\[\d+\]\[\d+\]$/');

    @unlink($dir.'/javascript-obfuscator-entry.js');
    @rmdir($dir);
}, ['timeout' => 30]);

it('throws a helpful exception when the package is missing', function () {
    $dir = sys_get_temp_dir().'/asset-shield-jso-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $engine = new JavascriptObfuscator('node', $dir.'/missing.js');

    $engine->obfuscate('x');

    @rmdir($dir);
})->throws(ObfuscationException::class, 'javascript-obfuscator package not found');