# API Reference

The package exposes a fluent API through the `MyUnnes\SSOClient\SSOClient` class and the `SSOClient` facade. Below you can find the primary entry points.

## Facade Methods

| Method | Description |
| --- | --- |
| `SSOClient::redirect(array $options = [])` | Builds the authorization URL and returns a redirect response. |
| `SSOClient::handleCallback(Request $request)` | Handles the OAuth callback, exchanges the authorization code, syncs the user, and returns the authenticated user model. |
| `SSOClient::getUserInfo()` | Fetches the latest user info from the SSO server using the stored access token. |
| `SSOClient::refreshToken()` | Exchanges the stored refresh token for a new set of tokens (automatically called when needed). |
| `SSOClient::isAuthenticated()` | Indicates whether valid tokens are available. |
| `SSOClient::logout()` | Revokes stored tokens (if possible) and clears local session state. |
| `SSOClient::getTokens()` | Returns the stored token payload (access, refresh, ID token, expiry). |
| `SSOClient::getAccessToken()` | Retrieves the current access token or `null` if unavailable. |
| `SSOClient::getRefreshToken()` | Retrieves the current refresh token or `null`. |
| `SSOClient::getIdToken()` | Retrieves the current ID token or `null`. |
| `SSOClient::validateIdToken(?string $idToken = null, bool $verifySignature = true)` | Validates an ID token and returns its claims array. Uses the package's JWKS cache. |
| `SSOClient::revokeTokens()` | Explicitly revokes access and refresh tokens with the SSO revocation endpoint. |
| `SSOClient::getLogoutUrl(?string $redirectUrl = null)` | Generates a browser logout URL for the SSO server. |
| `SSOClient::findUserBySSOId(string $id)` | Finds a local user by stored SSO subject. |
| `SSOClient::findUserByIdentifier(string $identifier)` | Finds a local user by identifier (email/username). |

## Middleware

| Alias | Purpose |
| --- | --- |
| `sso.auth` | Redirects unauthenticated users to the SSO login screen and rehydrates Laravel authentication from SSO tokens when possible. |
| `sso.guest` | Redirects authenticated users away from guest-only pages (e.g., login screen). |

Register them in your route groups just like any Laravel middleware.

## Built-in Routes

When `sso-client.routes.enabled` is true (default), the service provider registers:

| Route | Action | Description |
| --- | --- | --- |
| `GET {prefix}/login` | `SSOController@redirect` | Begins the OAuth flow and sends the user to the SSO server. |
| `GET {prefix}/callback` | `SSOController@callback` | Handles the callback, logs the user in, and redirects them. |
| `POST {prefix}/logout` | `SSOController@logout` | Logs out locally and forwards to the SSO logout endpoint. |

Configure `{prefix}` and post-auth redirects through `sso-client.routes.*`.

## Launch Token Handling

Direct app launches from the SSO server may pass a `launch_token` inside the `state` parameter. The package automatically resolves the token via the `/api/launch-token/{token}` endpoint before exchanging the authorization code. No additional configuration is required.

## Token Management Helpers

Tokens are encrypted (when enabled) and stored under the configurable session key (`sso-client.session.tokens_key`). You can directly access them via:

```php
$tokens = SSOClient::getTokens();

$accessToken = SSOClient::getAccessToken();
$refreshToken = SSOClient::getRefreshToken();
$idToken = SSOClient::getIdToken();
$claims = SSOClient::validateIdToken(); // returns an array of OIDC claims
```

To revoke tokens manually (useful during user-initiated logout):

```php
SSOClient::revokeTokens();
SSOClient::logout();
```

## Service Container Bindings

The service provider registers the following singletons:

- `MyUnnes\SSOClient\SSOClient`
- `MyUnnes\SSOClient\Services\SSOAuthService`
- `MyUnnes\SSOClient\Services\TokenService`
- `MyUnnes\SSOClient\Services\StateService`
- `MyUnnes\SSOClient\Services\PKCEService`
- `MyUnnes\SSOClient\Services\DiscoveryService`
- `MyUnnes\SSOClient\Services\UserService`

Resolve any of them through dependency injection when you need lower-level access.

---

Looking for setup instructions? Head back to the [installation guide](installation.md).
