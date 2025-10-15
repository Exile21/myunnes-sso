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
# Optional schema overrides for client databases
# SSO_USER_IDENTIFIER_COLUMNS=identitas_user,another_column
# SSO_USER_EMAIL_COLUMNS=email,email_user
```

See the [configuration reference](configuration.md) for all available options.

## 4. Run database migration

```bash
php artisan migrate
```

The migrations add the `sso_id` column to your primary `users` table and ensure legacy client tables (such as `sys_user`) include `remember_token` so Laravel’s session helpers continue working. Re-run `vendor:publish --tag=sso-client-migrations` followed by `php artisan migrate` whenever you upgrade the package and new migrations are introduced.

## 5. Use the built-in controller & routes

The package now registers a ready-made controller and route set:

- `GET /auth/sso/login` – redirect to the SSO server
- `GET /auth/sso/callback` – handle the OAuth response and log the user in
- `POST /auth/sso/logout` – sign out locally and at the SSO server

Tweak `sso-client.routes.*` in the published config (or matching environment variables) if you need a different prefix, middleware, or post-login/post-logout destinations.

## 6. Protect pages with middleware

The package ships with two middleware aliases:

- `sso.auth` – forces SSO authentication (combine it with Laravel's `web` stack in your routes)
- `sso.guest` – redirects authenticated users away from guest-only pages

```php
Route::middleware(['web', 'sso.auth'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});

Route::middleware(['web', 'sso.guest'])->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
});
```

> The built-in SSO routes use the middleware configured in `sso-client.routes.middleware` (defaults to `['web']`), so they integrate cleanly with Laravel's standard session/auth handling. You can add `auth` or other middleware on top if your application requires additional checks.

## 7. (Optional) Use launch token support

If your application receives deep links from the SSO server that include a `launch_token`, just continue using `SSOClient::handleCallback($request)`—the package automatically resolves the launch token and exchanges it for OAuth credentials.

---

Need more? Check the [API reference](api.md) and [troubleshooting guide](troubleshooting.md).
