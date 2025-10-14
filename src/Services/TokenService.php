<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Client\PendingRequest;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use InvalidArgumentException;
use RuntimeException;

/**
 * Token Service for OAuth 2.0/OIDC
 *
 * Handles token exchange, validation, and JWT processing.
 */
class TokenService
{
    protected DiscoveryService $discoveryService;
    protected int $timeout;
    protected int $connectTimeout;
    protected bool $verifySsl;
    protected bool $encryptTokens;
    protected int $retryAttempts;
    protected int $retryDelay;
    protected string $sessionKey;

    public function __construct(DiscoveryService $discoveryService)
    {
        $this->discoveryService = $discoveryService;
        $this->timeout = config('sso-client.http.timeout', 30);
        $this->connectTimeout = config('sso-client.http.connect_timeout', 10);
        $this->verifySsl = config('sso-client.security.verify_ssl', true);
        $this->encryptTokens = config('sso-client.security.encrypt_tokens', true);
        $this->retryAttempts = (int) config('sso-client.http.retry_attempts', 3);
        $this->retryDelay = (int) config('sso-client.http.retry_delay', 1000);
        $this->sessionKey = config('sso-client.session.tokens_key', 'sso_tokens');
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code
     * @param string $redirectUri Redirect URI used in authorization request
     * @param string $codeVerifier PKCE code verifier
     * @param string $clientId OAuth client ID
     * @param string|null $clientSecret OAuth client secret (for confidential clients)
     * @return array Token response
     * @throws RuntimeException
     */
    public function exchangeCodeForTokens(
        string $code,
        string $redirectUri,
        string $codeVerifier,
        string $clientId,
        ?string $clientSecret = null
    ): array {
        $tokenEndpoint = $this->discoveryService->getEndpoint('token_endpoint');

        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        // Add client secret for confidential clients
        if ($clientSecret) {
            $payload['client_secret'] = $clientSecret;
        }

        try {
            $response = $this->request()
                ->asForm()
                ->post($tokenEndpoint, $payload);

            if (!$response->successful()) {
                $error = $response->json('error', 'unknown_error');
                $description = $response->json('error_description', 'Token exchange failed');

                Log::warning('Token exchange failed', [
                    'status' => $response->status(),
                    'error' => $error,
                    'description' => $description,
                ]);

                throw new RuntimeException("Token exchange failed: {$description}");
            }

            $tokens = $response->json();

            // Validate token response
            if (!$this->validateTokenResponse($tokens)) {
                throw new RuntimeException('Invalid token response received');
            }

            return $tokens;

        } catch (\Exception $e) {
            Log::error('Token exchange error', [
                'endpoint' => $tokenEndpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Token exchange failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Refresh access token using refresh token.
     *
     * @param string $refreshToken Refresh token
     * @param string $clientId OAuth client ID
     * @param string|null $clientSecret OAuth client secret
     * @param array $scopes Optional scopes to request
     * @return array Token response
     * @throws RuntimeException
     */
    public function refreshAccessToken(
        string $refreshToken,
        string $clientId,
        ?string $clientSecret = null,
        array $scopes = []
    ): array {
        $tokenEndpoint = $this->discoveryService->getEndpoint('token_endpoint');

        $payload = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ];

        if ($clientSecret) {
            $payload['client_secret'] = $clientSecret;
        }

        if (!empty($scopes)) {
            $payload['scope'] = implode(' ', $scopes);
        }

        try {
            $response = $this->request()
                ->asForm()
                ->post($tokenEndpoint, $payload);

            if (!$response->successful()) {
                $error = $response->json('error', 'unknown_error');
                $description = $response->json('error_description', 'Token refresh failed');

                Log::warning('Token refresh failed', [
                    'status' => $response->status(),
                    'error' => $error,
                    'description' => $description,
                ]);

                throw new RuntimeException("Token refresh failed: {$description}");
            }

            $tokens = $response->json();

            if (!$this->validateTokenResponse($tokens)) {
                throw new RuntimeException('Invalid token refresh response received');
            }

            return $tokens;

        } catch (\Exception $e) {
            Log::error('Token refresh error', [
                'endpoint' => $tokenEndpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Token refresh failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate JWT token and extract claims.
     *
     * @param string $token JWT token
     * @param bool $verifySignature Whether to verify token signature
     * @return array Token claims
     * @throws RuntimeException
     */
    public function validateJWT(string $token, bool $verifySignature = true): array
    {
        try {
            if (!$verifySignature) {
                // Decode without verification (for testing/debugging only)
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                    throw new InvalidArgumentException('Invalid JWT format');
                }

                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!$payload) {
                    throw new InvalidArgumentException('Invalid JWT payload');
                }

                return $payload;
            }

            // Get JWKS for signature verification
            $jwksUri = $this->discoveryService->getEndpoint('jwks_uri');
            $jwks = $this->getJWKS($jwksUri);

            // Decode and verify JWT
            $decoded = JWT::decode($token, JWK::parseKeySet($jwks));
            return (array) $decoded;

        } catch (\Exception $e) {
            Log::warning('JWT validation failed', [
                'error' => $e->getMessage(),
                'token_length' => strlen($token),
            ]);

            throw new RuntimeException("JWT validation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Revoke a token via the OAuth revocation endpoint.
     *
     * @param string $token Token value to revoke
     * @param string $clientId OAuth client ID
     * @param string|null $clientSecret OAuth client secret (optional)
     * @param string $tokenTypeHint Token type hint (access_token or refresh_token)
     * @return bool True if revocation succeeded or token already invalid
     */
    public function revokeToken(
        string $token,
        string $clientId,
        ?string $clientSecret = null,
        string $tokenTypeHint = 'access_token'
    ): bool {
        if ($token === '') {
            return true;
        }

        try {
            $revocationEndpoint = $this->discoveryService->getEndpoint('revocation_endpoint');
        } catch (\Exception $e) {
            Log::notice('Revocation endpoint not available', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }

        $payload = [
            'token' => $token,
            'token_type_hint' => $tokenTypeHint,
            'client_id' => $clientId,
        ];

        if ($clientSecret) {
            $payload['client_secret'] = $clientSecret;
        }

        try {
            $response = $this->request()
                ->asForm()
                ->post($revocationEndpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            if ($response->status() === 400 && $response->json('error') === 'invalid_token') {
                // Already revoked or expired; treat as success
                return true;
            }

            Log::warning('Token revocation returned unexpected response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'token_type_hint' => $tokenTypeHint,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Token revocation error', [
                'endpoint' => $revocationEndpoint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Revoke stored access and refresh tokens.
     *
     * @param array $tokens Token data
     * @param string $clientId OAuth client ID
     * @param string|null $clientSecret OAuth client secret
     * @return bool True if all revocations succeeded
     */
    public function revokeTokens(array $tokens, string $clientId, ?string $clientSecret = null): bool
    {
        $results = [];

        if (!empty($tokens['access_token'])) {
            $results[] = $this->revokeToken($tokens['access_token'], $clientId, $clientSecret, 'access_token');
        }

        if (!empty($tokens['refresh_token'])) {
            $results[] = $this->revokeToken($tokens['refresh_token'], $clientId, $clientSecret, 'refresh_token');
        }

        return !in_array(false, $results, true);
    }

    /**
     * Get user info using access token.
     *
     * @param string $accessToken Access token
     * @return array User information
     * @throws RuntimeException
     */
    public function getUserInfo(string $accessToken): array
    {
        $userInfoEndpoint = $this->discoveryService->getEndpoint('userinfo_endpoint');

        try {
            $response = $this->request()
                ->withToken($accessToken)
                ->get($userInfoEndpoint);

            if (!$response->successful()) {
                $status = $response->status();
                Log::warning('UserInfo request failed', [
                    'status' => $status,
                    'response' => $response->body(),
                ]);

                throw new RuntimeException("UserInfo request failed: HTTP {$status}");
            }

            $userInfo = $response->json();

            return $userInfo;

        } catch (\Exception $e) {
            Log::error('UserInfo request error', [
                'endpoint' => $userInfoEndpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("UserInfo request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Securely store tokens in session.
     *
     * @param array $tokens Token data
     * @param string $sessionKey Session key
     * @param array $previousTokens Previously stored tokens
     * @return void
     */
    public function storeTokens(array $tokens, ?string $sessionKey = null, array $previousTokens = []): void
    {
        $sessionKey = $sessionKey ?? $this->sessionKey;

        $refreshToken = $tokens['refresh_token'] ?? ($previousTokens['refresh_token'] ?? null);

        $tokenData = [
            'access_token' => $tokens['access_token'],
            'token_type' => $tokens['token_type'] ?? 'Bearer',
            'expires_in' => $tokens['expires_in'] ?? 3600,
            'expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600)->timestamp,
            'refresh_token' => $refreshToken,
            'id_token' => $tokens['id_token'] ?? null,
            'scope' => $tokens['scope'] ?? null,
            'stored_at' => now()->timestamp,
        ];

        if ($this->encryptTokens) {
            $tokenData = Crypt::encrypt($tokenData);
        }

        session([$sessionKey => $tokenData]);
    }

    /**
     * Retrieve tokens from session.
     *
     * @param string $sessionKey Session key
     * @return array|null Token data or null if not found
     */
    public function retrieveTokens(?string $sessionKey = null): ?array
    {
        $sessionKey = $sessionKey ?? $this->sessionKey;
        $tokenData = session($sessionKey);

        if (!$tokenData) {
            return null;
        }

        try {
            if ($this->encryptTokens) {
                $tokenData = Crypt::decrypt($tokenData);
            }

            return is_array($tokenData) ? $tokenData : null;

        } catch (\Exception) {
            // Decryption failed, remove invalid data
            session()->forget($sessionKey);
            return null;
        }
    }

    /**
     * Check if stored access token is expired.
     *
     * @param string $sessionKey Session key
     * @return bool True if expired or not found
     */
    public function isAccessTokenExpired(?string $sessionKey = null): bool
    {
        $sessionKey = $sessionKey ?? $this->sessionKey;
        $tokens = $this->retrieveTokens($sessionKey);

        if (!$tokens || !isset($tokens['expires_at'])) {
            return true;
        }

        return $tokens['expires_at'] <= now()->timestamp;
    }

    /**
     * Clear stored tokens.
     *
     * @param string $sessionKey Session key
     * @return void
     */
    public function clearTokens(?string $sessionKey = null): void
    {
        $sessionKey = $sessionKey ?? $this->sessionKey;
        session()->forget($sessionKey);
    }

    /**
     * Get current stored tokens (if any).
     *
     * @return array|null
     */
    public function getTokens(): ?array
    {
        return $this->retrieveTokens();
    }

    /**
     * Get the current access token if available.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        $tokens = $this->retrieveTokens();
        return $tokens['access_token'] ?? null;
    }

    /**
     * Get the current refresh token if available.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        $tokens = $this->retrieveTokens();
        return $tokens['refresh_token'] ?? null;
    }

    /**
     * Get the current ID token if available.
     *
     * @return string|null
     */
    public function getIdToken(): ?string
    {
        $tokens = $this->retrieveTokens();
        return $tokens['id_token'] ?? null;
    }

    /**
     * Build a pending request with configured options.
     *
     * @return PendingRequest
     */
    protected function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['verify' => $this->verifySsl]);

        if ($this->retryAttempts > 0) {
            $request = $request->retry($this->retryAttempts, $this->retryDelay);
        }

        return $request;
    }

    /**
     * Validate token response structure.
     *
     * @param array $tokens Token response
     * @return bool True if valid
     */
    protected function validateTokenResponse(array $tokens): bool
    {
        // Must have access_token
        if (!isset($tokens['access_token']) || !is_string($tokens['access_token'])) {
            return false;
        }

        // Token type should be Bearer
        if (isset($tokens['token_type']) && strtolower($tokens['token_type']) !== 'bearer') {
            Log::warning('Unexpected token type', ['token_type' => $tokens['token_type']]);
        }

        // Expires_in should be numeric
        if (isset($tokens['expires_in']) && !is_numeric($tokens['expires_in'])) {
            return false;
        }

        return true;
    }

    /**
     * Fetch JWKS from the server.
     *
     * @param string $jwksUri JWKS endpoint URL
     * @return array JWKS data
     * @throws RuntimeException
     */
    protected function getJWKS(string $jwksUri): array
    {
        try {
            $response = $this->request()->get($jwksUri);

            if (!$response->successful()) {
                throw new RuntimeException("JWKS fetch failed: HTTP {$response->status()}");
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('JWKS fetch error', [
                'uri' => $jwksUri,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("JWKS fetch failed: {$e->getMessage()}", 0, $e);
        }
    }
}
