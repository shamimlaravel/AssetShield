<?php

namespace Shamimstack\AssetShield\Obfuscation;

use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Process\Process;
use Shamimstack\AssetShield\Exceptions\ObfuscationException;

/**
 * Drives the `javascript-obfuscator` npm package through Node.
 *
 * The package runs as a one-shot Node process: input source goes to a temp
 * file, the obfuscator option map is passed via JSO_OPTIONS (JSON, kept out
 * of the command line to avoid quoting issues on Windows), and the result is
 * read back from a second temp file.
 *
 * real-world note: obfuscation is NOT encryption and is off by default. Only
 * the Vite plugin's render stage should emit obfuscated bytes; this adapter
 * exists for build tooling (status/doctor and explicit bake commands).
 */
final class JavascriptObfuscator implements ObfuscationEngine
{
    private const PRESETS = [
        'none' => ['compact' => false],
        'performance' => ['compact' => true, 'selfDefending' => false, 'stringArray' => false, 'simplify' => true],
        'balanced' => ['compact' => true, 'selfDefending' => true, 'stringArray' => true, 'stringArrayEncoding' => ['base64']],
        'high' => ['compact' => true, 'selfDefending' => true, 'stringArray' => true, 'stringArrayEncoding' => ['base64', 'rc4'], 'disableConsoleOutput' => true, 'deadCodeInjection' => true],
    ];

    /**
     * @param string $nodeBinary   node executable ('node' resolves via PATH)
     * @param string $packagePath  absolute path to the javascript-obfuscator entry file
     * @param float  $timeout      seconds allowed for a single run
     */
    public function __construct(
        private readonly string $nodeBinary,
        private readonly string $packagePath,
        private readonly float $timeout = 120.0,
    ) {
    }

    public static function fromConfig(Application $app): self
    {
        $root = $app->basePath('node_modules');
        $packageDir = $root.DIRECTORY_SEPARATOR.'javascript-obfuscator';

        $packagePath = (string) $app['config']->get('asset-shield.obfuscation.package_path', '');
        if ($packagePath === '') {
            $packagePath = ($main = self::resolvePackageMain($packageDir)) !== null
                ? $main
                : $packageDir.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'index.js';
        }

        return new self(
            (string) $app['config']->get('asset-shield.obfuscation.node_binary', 'node'),
            $packagePath,
            (float) $app['config']->get('asset-shield.obfuscation.timeout', 120.0),
        );
    }

    public function isAvailable(): bool
    {
        return is_file($this->packagePath);
    }

    public function presets(): array
    {
        return array_keys(self::PRESETS);
    }

    /** @param array<string, mixed> $options */
    public function obfuscate(string $source, array $options = []): string
    {
        if (! $this->isAvailable()) {
            throw new ObfuscationException(
                'javascript-obfuscator package not found at "'.$this->packagePath.'". '
                .'Run `npm install --save-dev javascript-obfuscator` or point asset-shield.obfuscation.package_path '
                .'at the package entry file (use a path that works cross-platform).'
            );
        }

        $script = <<<'JS'
            const fs = require('fs');
            const [,pkg,src,dst] = process.argv;
            try {
                let options = {};
                try { options = JSON.parse(process.env.JSO_OPTIONS || '{}'); } catch (e) {}
                const jso = require(pkg);
                const out = jso.obfuscate(fs.readFileSync(src, 'utf8'), options);
                fs.writeFileSync(dst, out);
            } catch (e) {
                process.stderr.write((e && e.stack) ? e.stack : String(e));
                process.exit(1);
            }
            JS;

        $srcFile = tempnam(sys_get_temp_dir(), 'asset-shield-src-');
        if ($srcFile === false) {
            throw new ObfuscationException('Could not create a temp source file.');
        }
        $dstFile = tempnam(sys_get_temp_dir(), 'asset-shield-out-');
        if ($dstFile === false) {
            @unlink($srcFile);
            throw new ObfuscationException('Could not create a temp output file.');
        }

        file_put_contents($srcFile, $source);

        $process = new Process([
            $this->nodeBinary,
            '-e',
            $script,
            $this->packagePath,
            $srcFile,
            $dstFile,
        ]);

        $env = $this->safeEnv();
        $env['JSO_OPTIONS'] = json_encode($options);
        $process->setEnv($env);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } finally {
            @unlink($srcFile);
        }

        if (! $process->isSuccessful()) {
            @unlink($dstFile);

            throw new ObfuscationException('javascript-obfuscator failed: '.trim($process->getErrorOutput()));
        }

        $out = is_file($dstFile) ? (string) file_get_contents($dstFile) : '';
        @unlink($dstFile);

        if ($out === '') {
            throw new ObfuscationException('javascript-obfuscator produced no output.');
        }

        return $out;
    }

    /**
     * Locate the package entry file honouring its package.json "main" field.
     */
    private static function resolvePackageMain(string $packageDir): ?string
    {
        $packageJson = $packageDir.DIRECTORY_SEPARATOR.'package.json';

        if (! is_file($packageJson)) {
            return null;
        }

        $package = json_decode((string) file_get_contents($packageJson), true);

        if (! is_array($package) || ! isset($package['main']) || ! is_string($package['main'])) {
            return null;
        }

        $entry = $packageDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $package['main']);

        return is_file($entry) ? $entry : null;
    }

    /**
     * @return array<string, string> current env restricted to scalar values
     */
    private function safeEnv(): array
    {
        $env = [];
        foreach ((array) getenv() as $key => $value) {
            if (is_string($value)) {
                $env[(string) $key] = $value;
            }
        }

        return $env;
    }
}