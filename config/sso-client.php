<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SSO Client Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for MyUnnes SSO Client package.
    | These settings control how your application connects to the SSO server.
    |
    */

    'base_url' => env('SSO_BASE_URL', 'https://sso.myunnes.com'),
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Endpoints
    |--------------------------------------------------------------------------
    |
    | The OAuth endpoints for the SSO server.
    | These should not need to be changed unless using a custom server.
    |
    */

    'endpoints' => [
        'discovery' => '/.well-known/openid-configuration',
        'authorization' => '/oauth/authorize',
        'token' => '/oauth/token',
        'userinfo' => '/oauth/userinfo',
        'revocation' => '/oauth/revoke',
        'logout' => '/logout',
        'launch_token' => '/api/launch-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | The OAuth scopes to request from the SSO server.
    | Available scopes: openid, profile, email, roles, read, write, admin
    |
    */

    'scopes' => [
        'openid',
        'profile',
        'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security configuration for the OAuth flow.
    |
    */

    'security' => [
        // Enforce PKCE for enhanced security
        'force_pkce' => env('SSO_FORCE_PKCE', true),

        // Code challenge method (S256 recommended)
        'code_challenge_method' => env('SSO_CODE_CHALLENGE_METHOD', 'S256'),

        // State parameter length (minimum 32 characters)
        'state_length' => env('SSO_STATE_LENGTH', 40),

        // Code verifier length (43-128 characters for PKCE)
        'code_verifier_length' => env('SSO_CODE_VERIFIER_LENGTH', 128),

        // Encrypt tokens in session
        'encrypt_tokens' => env('SSO_ENCRYPT_TOKENS', true),

        // Validate SSL certificates
        'verify_ssl' => env('SSO_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for session handling during OAuth flow.
    |
    */

    'session' => [
        // Session key prefix
        'prefix' => 'sso_',

        // Token storage session key
        'tokens_key' => env('SSO_SESSION_TOKENS_KEY', 'sso_tokens'),

        // Session lifetime in minutes
        'lifetime' => env('SSO_SESSION_LIFETIME', 15),

        // Clear session on error
        'clear_on_error' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Toggle and customize the built-in controller and routes that ship
    | with the package.
    |
    */

    'routes' => [
        // Enable automatic route registration
        'enabled' => env('SSO_ROUTES_ENABLED', true),

        // Route prefix, i.e. /auth/sso/*
        'prefix' => env('SSO_ROUTES_PREFIX', 'auth/sso'),

        // Middleware applied to the routes
        'middleware' => ['web'],

        // Destination after a successful login
        'redirect_after_login' => env('SSO_REDIRECT_AFTER_LOGIN', '/dashboard'),

        // Fallback route name used when login fails
        'fallback_login_route' => env('SSO_FALLBACK_LOGIN_ROUTE', 'auth.login.view'),

        // Destination after logout (relative to your app)
        'redirect_after_logout' => env('SSO_REDIRECT_AFTER_LOGOUT', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the HTTP client used to communicate with the SSO server.
    |
    */

    'http' => [
        // Request timeout in seconds
        'timeout' => env('SSO_HTTP_TIMEOUT', 30),

        // Connect timeout in seconds
        'connect_timeout' => env('SSO_HTTP_CONNECT_TIMEOUT', 10),

        // Retry attempts
        'retry_attempts' => env('SSO_HTTP_RETRY_ATTEMPTS', 3),

        // Retry delay in milliseconds
        'retry_delay' => env('SSO_HTTP_RETRY_DELAY', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for user creation and updates.
    |
    */

    'user' => [
        // User model class
        'model' => env('SSO_USER_MODEL', 'MyUnnes\Base\Models\SysUser::class'),

        // User identifier field (usually 'email' or 'username')
        'identifier_field' => env('SSO_USER_IDENTIFIER', 'email'),

        // SSO ID field in user table
        'sso_id_field' => env('SSO_USER_SSO_ID_FIELD', 'sso_id'),

        // Fields to update from SSO
        'updateable_fields' => array_filter(array_map('trim', explode(',', env('SSO_USER_UPDATEABLE_FIELDS', 'name,email,email_verified_at,identitas_user')))),

        // Auto-create users if they don't exist
        'auto_create' => env('SSO_AUTO_CREATE_USERS', true),

        // Auto-update existing users
        'auto_update' => env('SSO_AUTO_UPDATE_USERS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for logging SSO events.
    |
    */

    'logging' => [
        // Enable SSO logging
        'enabled' => env('SSO_LOGGING_ENABLED', true),

        // Log channel
        'channel' => env('SSO_LOG_CHANNEL', 'default'),

        // Log level
        'level' => env('SSO_LOG_LEVEL', 'info'),

        // Log successful authentications
        'log_success' => env('SSO_LOG_SUCCESS', true),

        // Log failed authentications
        'log_failures' => env('SSO_LOG_FAILURES', true),

        // Include user data in logs (set to false for privacy)
        'include_user_data' => env('SSO_LOG_INCLUDE_USER_DATA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching discovery document and other data.
    |
    */

    'cache' => [
        // Enable caching
        'enabled' => env('SSO_CACHE_ENABLED', true),

        // Cache store
        'store' => env('SSO_CACHE_STORE', 'default'),

        // Discovery document cache TTL in seconds
        'discovery_ttl' => env('SSO_CACHE_DISCOVERY_TTL', 3600),

        // Cache key prefix
        'prefix' => env('SSO_CACHE_PREFIX', 'sso_client_'),
    ],
];
