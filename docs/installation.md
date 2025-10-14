# Installation Guide

Follow these steps to wire the MyUnnes SSO Client package into a Laravel application.

## Requirements

- Laravel 10.x, 11.x, or 12.x
- PHP 8.1 or higher
- Access to a configured MyUnnes SSO server (client ID, client secret, redirect URI)

## 1. Install the package

```bash
composer require dsiunnes/myunnes-sso
```

The service provider and facade are auto-discovered by Laravel.

## 2. Publish configuration and migration

```bash
php artisan vendor:publish --provider="MyUnnes\SSOClient\SSOClientServiceProvider" --tag=sso-client-config
php artisan vendor:publish --provider="MyUnnes\SSOClient\SSOClientServiceProvider" --tag=sso-client-migrations
```

## 3. Configure environment variables

Update your `.env` file with credentials from the SSO server:

```env
SSO_BASE_URL=https://sso.myunnes.com
SSO_CLIENT_ID=your_client_id
SSO_CLIENT_SECRET=your_client_secret
SSO_REDIRECT_URI=${APP_URL}/auth/sso/callback
```

See the [configuration reference](configuration.md) for all available options.

## 4. Run database migration

```bash
php artisan migrate
```

This adds the `sso_id` column to the `users` table so accounts can be linked to SSO identities.

## 5. Add controller and routes

Create a simple controller to bridge the redirect and callback:

```bash
php artisan make:controller Auth/SSOController
```

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MyUnnes\SSOClient\Facades\SSOClient;

class SSOController extends Controller
{
    public function redirect()
    {
        return SSOClient::redirect();
    }

    public function callback(Request $request)
    {
        $user = SSOClient::handleCallback($request);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request)
    {
        SSOClient::logout();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
```

Register the routes (e.g., in `routes/web.php`):

```php
Route::middleware('web')->group(function () {
    Route::get('/login/sso', [SSOController::class, 'redirect'])->name('sso.login');
    Route::get('/auth/sso/callback', [SSOController::class, 'callback'])->name('sso.callback');
    Route::post('/logout', [SSOController::class, 'logout'])->name('logout');
});
```

## 6. Protect pages with middleware

The package ships with two middleware aliases:

- `sso.auth` – forces SSO authentication
- `sso.guest` – redirects authenticated users away from guest-only pages

```php
Route::middleware(['sso.auth'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});
```

## 7. (Optional) Use launch token support

If your application receives deep links from the SSO server that include a `launch_token`, just continue using `SSOClient::handleCallback($request)`—the package automatically resolves the launch token and exchanges it for OAuth credentials.

---

Need more? Check the [API reference](api.md) and [troubleshooting guide](troubleshooting.md).
