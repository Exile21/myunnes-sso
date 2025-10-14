# MyUnnes SSO Client Package

A secure, production-ready OAuth 2.0/OpenID Connect client package for Laravel applications that provides seamless integration with MyUnnes SSO Server.

## Features

- 🔐 **OIDC Authorization Code + PKCE** for secure browser-based sign-in flows
- 🛰️ **Automatic Discovery & JWKS caching** with resilient HTTP retries
- 🔄 **Complete token lifecycle**: refresh, revocation, encryption, and expiry tracking
- 🎯 **Launch token & deep-link support** straight from the SSO server
- 🧭 **Laravel-native DX**: auto-discovered service provider, facade, and ready-to-use middleware
- 🧾 **Typed helpers** to access access/refresh/ID tokens and validate ID token claims
- 🧰 **Extensive configuration & logging** for different deployment environments

## Installation

```bash
composer require dsiunnes/myunnes-sso
```

## Quick Start

1. **Publish config + migration**
    ```bash
    php artisan vendor:publish --provider="MyUnnes\SSOClient\SSOClientServiceProvider" --tag=sso-client-config
    php artisan vendor:publish --provider="MyUnnes\SSOClient\SSOClientServiceProvider" --tag=sso-client-migrations
    ```
2. **Set environment variables**
    ```env
    SSO_BASE_URL=https://sso.myunnes.com
    SSO_CLIENT_ID=your_client_id
    SSO_CLIENT_SECRET=your_client_secret
    SSO_REDIRECT_URI=${APP_URL}/auth/sso/callback
    ```
3. **Run the migration**
    ```bash
    php artisan migrate
    ```
4. **Use the ready-made routes**
    - Visit `/auth/sso/login` (configurable) to start the flow.
    - The package ships with a controller + routes for login, callback, and logout.
    - Override `sso-client.routes.*` if you need a different prefix, middleware, or post-login redirect.
5. **Protect routes with middleware (optional)**
    ```php
    Route::middleware(['web', 'sso.auth'])->group(function () {
        Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
    });
    ```
    The SSO middleware layers nicely with Laravel's defaults (`web`, `auth`, etc.), so you can keep using existing guard logic alongside the SSO session.

Need tokens for downstream APIs? Use the facade helpers:

```php
$accessToken = SSOClient::getAccessToken();
$claims = SSOClient::validateIdToken(); // array of verified OIDC claims
```

## Security Features

- **PKCE Implementation**: Proof Key for Code Exchange for enhanced security
- **State Validation**: CSRF protection with cryptographically secure state parameters
- **Token Encryption**: Secure token storage with Laravel's encryption
- **Launch Token Security**: Secure handling of direct app launches
- **Input Validation**: Comprehensive validation of all OAuth parameters
- **Error Handling**: Secure error responses without information leakage
- **Rate Limiting**: Built-in protection against abuse

## Documentation

- [Installation Guide](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Security Best Practices](docs/security.md)
- [API Reference](docs/api.md)
- [Troubleshooting](docs/troubleshooting.md)

## License

MIT License. See [LICENSE](LICENSE) for details.
