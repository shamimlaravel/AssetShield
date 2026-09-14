<?php

use Illuminate\Support\Facades\Route;
use Shamimstack\AssetShield\Http\Controllers\AssetController;
use Shamimstack\AssetShield\Http\Middleware\AssetSecurityHeaders;
use Shamimstack\AssetShield\Http\Middleware\VerifyAssetSignature;

/*
|--------------------------------------------------------------------------
| Protected asset delivery (runtime delivery, opt-in)
|--------------------------------------------------------------------------
|
| Loaded only when AssetShield is enabled AND runtime delivery is on:
|
|   GET /{route_prefix}/{opaque-id}[?expires=&signature=]
|
| The {asset} segment is constrained to AssetShield opaque ids (as_...hex) so a
| filesystem path can never be matched against this route. Resolution happens
| exclusively through the registry; VerifyAssetSignature gates 404/403.
|
*/

Route::middleware([AssetSecurityHeaders::class, VerifyAssetSignature::class])
    ->prefix((string) config('asset-shield.runtime.route_prefix', 'assets'))
    ->name('asset-shield.')
    ->group(function (): void {
        Route::get('/{asset}', AssetController::class)
            ->where('asset', '[a-z0-9_]{2,64}')
            ->name('show');
    });