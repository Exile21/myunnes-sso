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

Route::prefix('auth/sso')->name('sso.')->middleware(['web'])->group(function () {
    // Uncomment and customize these routes in your application
    // Route::get('login', [App\Http\Controllers\Auth\SSOController::class, 'redirectToProvider'])->name('login');
    // Route::get('callback', [App\Http\Controllers\Auth\SSOController::class, 'handleProviderCallback'])->name('callback');
    // Route::post('logout', [App\Http\Controllers\Auth\SSOController::class, 'logout'])->name('logout');
});
