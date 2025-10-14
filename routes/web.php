<?php

/*
|--------------------------------------------------------------------------
| SSO Client Routes
|--------------------------------------------------------------------------
|
| Optional routes for SSO client package. These routes are only loaded
| if the 'routes.enabled' configuration is set to true.
|
| To use these routes, create your own SSOController in your application:
| php artisan make:controller Auth/SSOController
|
*/

use Illuminate\Support\Facades\Route;
use MyUnnes\SSOClient\Http\Controllers\SSOController;

$routeConfig = config('sso-client.routes');
$prefix = $routeConfig['prefix'] ?? 'auth/sso';
$middleware = $routeConfig['middleware'] ?? ['web'];

Route::middleware($middleware)
    ->prefix($prefix)
    ->as('sso.')
    ->group(function () {
        Route::get('login', [SSOController::class, 'redirect'])->name('login');
        Route::get('callback', [SSOController::class, 'callback'])->name('callback');
        Route::post('logout', [SSOController::class, 'logout'])->name('logout');
    });
