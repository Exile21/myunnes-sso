# Configuration Reference

All configuration values live in `config/sso-client.php`. Publish the file with:

```bash
php artisan vendor:publish --provider="MyUnnes\SSOClient\SSOClientServiceProvider" --tag=sso-client-config
```

Below is an overview of the available options.

## Core Settings

| Key | Description | Default |
| --- | ----------- | ------- |
| `base_url` | Base URL of the MyUnnes SSO server. | `https://sso.myunnes.com` |
| `client_id` | OAuth client identifier issued by the SSO server. | `null` |
| `client_secret` | OAuth client secret (nullable for public clients). | `null` |
| `redirect_uri` | Full callback URL in your application. | `null` |
| `scopes` | Scopes requested during authorization. | `['openid','profile','email']` |

## Endpoints

Endpoints are discovered automatically via the `.well-known/openid-configuration` document. Override them only if you expose the server on custom paths.

| Key | Purpose | Default |
| --- | ------- | ------- |
| `authorization` | Authorization UI endpoint. | `/oauth/authorize` |
| `token` | Token exchange endpoint. | `/oauth/token` |
| `userinfo` | OpenID Connect user info endpoint. | `/oauth/userinfo` |
| `revocation` | Token revocation endpoint. | `/oauth/revoke` |
| `logout` | Browser logout endpoint (for redirect helpers). | `/logout` |
| `launch_token` | API endpoint for resolving launch tokens. | `/api/launch-token` |

## Security

| Key | Description | Default |
| --- | ----------- | ------- |
| `force_pkce` | Enforces PKCE even if server marks it optional. | `true` |
| `code_challenge_method` | `S256` or `plain`. | `S256` |
| `state_length` | Length of random state strings. | `40` |
| `code_verifier_length` | Length of PKCE code verifiers. | `128` |
| `encrypt_tokens` | Encrypt tokens before storing in the session. | `true` |
| `verify_ssl` | Validate HTTPS certificates (never disable in production). | `true` |

## Session

| Key | Description | Default |
| --- | ----------- | ------- |
| `prefix` | Prefix used for state keys in the session store. | `sso_` |
| `tokens_key` | Session key used for storing OAuth tokens. | `sso_tokens` |
| `lifetime` | Minutes before stored state entries expire. | `15` |
| `clear_on_error` | Whether to clear session helpers after OAuth errors. | `true` |

## HTTP Client

| Key | Description | Default |
| --- | ----------- | ------- |
| `timeout` | Request timeout in seconds. | `30` |
| `connect_timeout` | Connection timeout in seconds. | `10` |
| `retry_attempts` | Number of retry attempts for transient failures. | `3` |
| `retry_delay` | Delay between retries (milliseconds). | `1000` |

## Cache

| Key | Description | Default |
| --- | ----------- | ------- |
| `enabled` | Cache OIDC discovery documents. | `true` |
| `store` | Cache store name. | `default` |
| `discovery_ttl` | Lifetime (seconds) for cached discovery data. | `3600` |
| `prefix` | Cache key prefix. | `sso_client_` |

## User Mapping

| Key | Description | Default |
| --- | ----------- | ------- |
| `model` | User model class to sync with. | `App\Models\User` |
| `identifier_field` | Lookup field for local users (usually `email`). | `email` |
| `sso_id_field` | Column used to store the SSO subject (`sub`). | `sso_id` |
| `updateable_fields` | Attributes that may be synced from SSO claims. | `['name','email','email_verified_at']` |
| `auto_create` | Create users automatically when not found. | `true` |
| `auto_update` | Update mapped attributes on login. | `true` |

## Logging

| Key | Description | Default |
| --- | ----------- | ------- |
| `enabled` | Enable package-specific logging. | `true` |
| `channel` | Log channel name. | `default` |
| `level` | Minimum log level. | `info` |
| `log_success` | Log successful authentications. | `true` |
| `log_failures` | Log failures for auditing. | `true` |
| `include_user_data` | Include sanitized user data in logs. | `false` |

---

Need a quick refresher on installing the package? See the [installation guide](installation.md).
