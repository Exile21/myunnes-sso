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

- Verify that `SSO_USER_EMAIL_COLUMNS` lists every column name your client schema uses for email addresses (for example `email,email_user`). The package matches existing users across all configured email columns before creating a record.
- Ensure `SSO_USER_IDENTIFIER_COLUMNS` includes the columns that should mirror the SSO identifier (e.g. `identitas_user`). Missing columns will remain `NULL` until added.
- After updating the environment values, clear config cache (`php artisan config:clear`) and retry the login flow. You may safely delete previously duplicated rows once matching is configured correctly.

## Revocation endpoint failures

- The SSO server must expose `/oauth/revoke`. If it is disabled, revocation attempts will log a warning but logout will still clear local tokens.
- Some servers require Basic Auth instead of form parameters—ensure your SSO server follows the MyUnnes implementation.

## Debugging tips

- Enable debug logging: set `SSO_LOGGING_ENABLED=true` and `SSO_LOG_LEVEL=debug`.
- Check Laravel logs for entries tagged with `SSO` or `sso` context.
- Use the `SSOClient::getTokens()` helper to inspect what has been stored in the session.

Still stuck? Review the [installation guide](installation.md) and [configuration reference](configuration.md), then contact your SSO administrator with detailed logs.
