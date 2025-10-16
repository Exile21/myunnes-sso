# Troubleshooting Guide

Having issues integrating the MyUnnes SSO Client package? Start with the scenarios below.

## Authorization errors (`invalid_client`, `unauthorized_client`)

- Double-check `SSO_CLIENT_ID` and `SSO_CLIENT_SECRET`.
- Ensure the redirect URI registered on the SSO server exactly matches `SSO_REDIRECT_URI`.
- Regenerate the client secret if it might have been rotated or revoked.

## `invalid_grant` or `invalid_state`

- The state parameter may have expired. Increase `SSO_SESSION_LIFETIME` if users spend a long time on the SSO consent screen.
- Ensure the app uses a sticky session store (Redis/database) when running behind multiple workers.
- Verify that the Laravel session is not being cleared between the redirect and callback steps.

## Callback errors with `code_verifier` or PKCE mismatch

- Confirm that the callback request includes the `code` and `state` parameters (some proxies strip query parameters on redirects).
- Check that your application does not modify the session or state values between redirect and callback.

## HTTP client timeouts

- Increase `SSO_HTTP_TIMEOUT` or `SSO_HTTP_CONNECT_TIMEOUT` in `.env`.
- Confirm that outbound HTTPS traffic to the SSO server is allowed through firewalls.

## SSL certificate validation failures

- Ensure your container or host trusts the certificate chain used by the SSO server.
- For local testing only, you may temporarily set `SSO_VERIFY_SSL=false`. **Do not disable SSL verification in production.**

## Tokens disappear after login

- Verify that session encryption is working (set `APP_KEY` and ensure session driver supports encryption).
- If using a custom session driver, confirm it supports storing encrypted arrays.
- Ensure the session key configured in `sso-client.session.tokens_key` does not collide with other application keys.

## Users are not created automatically

- Confirm `SSO_AUTO_CREATE_USERS` is set to `true`.
- Make sure the `users` table contains the `sso_id` column (run the published migration).
- Check application logs for validation failures while mapping SSO claims to your user model.

## Duplicate rows appear in client user tables

- Ensure `SSO_USER_IDENTIFIER` references the column that stores email in your client schema (for example set it to `email_user`). The package searches this column before creating a new record.
- Include every column you expect to sync in `SSO_USER_UPDATEABLE_FIELDS` (e.g. `username_user,nm_user,identitas_user`).
- Publish `config/sso-client.php` and add `field_mappings` entries so SSO claims populate the appropriate columns (for example `'username_user' => [':identifier', ':email']`).
- Use the SSO admin UI (OAuth Clients → Edit) to adjust the allowed scopes for a client after updating configuration.
- If the client package is still requesting old scopes, clear cached configuration and set `SSO_SCOPES=` to allow dynamic discovery, or explicitly list the scopes you expect in `SSO_SCOPES`.
- After adjusting configuration, clear the cache (`php artisan config:clear`) and retry the login flow. You can remove any previously duplicated rows once matching is configured correctly.

## Revocation endpoint failures

- The SSO server must expose `/oauth/revoke`. If it is disabled, revocation attempts will log a warning but logout will still clear local tokens.
- Some servers require Basic Auth instead of form parameters—ensure your SSO server follows the MyUnnes implementation.

## Debugging tips

- Enable debug logging: set `SSO_LOGGING_ENABLED=true` and `SSO_LOG_LEVEL=debug`.
- Check Laravel logs for entries tagged with `SSO` or `sso` context.
- Use the `SSOClient::getTokens()` helper to inspect what has been stored in the session.

Still stuck? Review the [installation guide](installation.md) and [configuration reference](configuration.md), then contact your SSO administrator with detailed logs.
