<?php

use Illuminate\Support\Facades\Route;
use Vendor\AssetShield\Http\Controllers\AssetController;
use Vendor\AssetShield\Http\Middleware\AssetSecurityHeaders;

/*
|--------------------------------------------------------------------------
| Protected asset delivery
|--------------------------------------------------------------------------
|
| The {asset} segment is constrained to hex opaque ids ([a-f0-9]{8,32}) so a
| filesystem path can never be matched against this route. Resolution happens
| exclusively through the AssetRegistry.
|
*/

Route::middleware([AssetSecurityHeaders::class])
    ->prefix((string) config('asset-shield.route_prefix', 'assets'))
    ->name('asset-shield.')
    ->group(function (): void {
        Route::get('/{asset}', AssetController::class)
            ->where('asset', '[a-f0-9]{8,32}')
            ->name('show');
    });