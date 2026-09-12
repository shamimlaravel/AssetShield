<?php

/**
 * Recursive `php -l` linter for the package source, config, routes and tests.
 *
 * Usage: composer lint   (runs on any platform via @php)
 */

$roots = [
    __DIR__.'/../src',
    __DIR__.'/../config',
    __DIR__.'/../routes',
    __DIR__.'/../tests',
];

$errors = [];

foreach ($roots as $root) {
    if (! is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $code);

        if ($code !== 0) {
            $errors[] = $output ? implode("\n", $output) : 'syntax error in '.$file->getPathname();
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

echo "PHP lint: all clean\n";
exit(0);