<?php

declare(strict_types=1);

/**
 * PHPStan declarations for Laravel global helper functions that are not
 * defined when the package is analysed standalone (illuminate/foundation
 * is intentionally not a runtime dependency of AssetShield).
 *
 * This file is never loaded at runtime; it only teaches PHPStan the
 * signatures of the helpers used inside src/.
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;

/**
 * @template T of object
 *
 * @param  class-string<T>|string|null  $abstract
 * @param  array<array-key, mixed>  $parameters
 *
 * @return ($abstract is class-string ? T : Application)
 */
function app(?string $abstract = null, array $parameters = []): mixed
{
    return null;
}

/**
 * @param array<array-key, mixed>|string|null $key
 *
 * @param  mixed  $default
 * @return mixed
 */
function config($key = null, $default = null): mixed
{
    return null;
}

function base_path(string $path = ''): string
{
    return '';
}

function app_path(string $path = ''): string
{
    return '';
}

function public_path(string $path = ''): string
{
    return '';
}

function storage_path(string $path = ''): string
{
    return '';
}

function resource_path(string $path = ''): string
{
    return '';
}

function config_path(string $path = ''): string
{
    return '';
}

function now(): CarbonImmutable
{
    return CarbonImmutable::now();
}