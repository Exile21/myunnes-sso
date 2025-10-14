<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Main SSO Authentication Service
 *
 * Orchestrates the OAuth 2.0/OIDC authentication flow.
 */
class SSOAuthService
{
    protected PKCEService $pkceService;
    protected StateService $stateService;
    protected TokenService $tokenService;
    protected DiscoveryService $discoveryService;

    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected array $scopes;
    protected bool $forcePKCE;
    protected string $codeChallengeMethod;
    protected int $httpTimeout;
    protected int $httpConnectTimeout;
    protected int $httpRetryAttempts;
    protected int $httpRetryDelay;
    protected bool $verifySsl;

    public function __construct(
        PKCEService $pkceService,
        StateService $stateService,
        TokenService $tokenService,
        DiscoveryService $discoveryService
    ) {
        $this->pkceService = $pkceService;
        $this->stateService = $stateService;
        $this->tokenService = $tokenService;
        $this->discoveryService = $discoveryService;

        // Load configuration
        $this->baseUrl = config('sso-client.base_url');
        $this->clientId = config('sso-client.client_id');
        $this->clientSecret = config('sso-client.client_secret');
        $this->redirectUri = config('sso-client.redirect_uri');
        $this->scopes = config('sso-client.scopes', ['openid', 'profile', 'email']);
        $this->forcePKCE = config('sso-client.security.force_pkce', true);
        $this->codeChallengeMethod = config('sso-client.security.code_challenge_method', 'S256');
        $this->httpTimeout = config('sso-client.http.timeout', 30);
        $this->httpConnectTimeout = config('sso-client.http.connect_timeout', 10);
        $this->httpRetryAttempts = (int) config('sso-client.http.retry_attempts', 3);
        $this->httpRetryDelay = (int) config('sso-client.http.retry_delay', 1000);
        $this->verifySsl = config('sso-client.security.verify_ssl', true);

        // Validate configuration
        $this->validateConfiguration();
    }

    /**
     * Generate authorization URL for OAuth flow.
     *
     * @param array $options Additional options for authorization request
     * @return string Authorization URL
     * @throws RuntimeException
     */
    public function getAuthorizationUrl(array $options = []): string
    {
        try {
            // Generate state and PKCE parameters
            $state = $this->stateService->generateState();
            $codeVerifier = $this->pkceService->generateCodeVerifier();
            $codeChallenge = $this->pkceService->generateCodeChallenge($codeVerifier, $this->codeChallengeMethod);

            // Store PKCE data with state
            $this->stateService->storePKCEData($state, $codeVerifier, $codeChallenge, $this->codeChallengeMethod);

            // Get authorization endpoint from discovery
            $authorizationEndpoint = $this->discoveryService->getEndpoint('authorization_endpoint');

            // Build authorization parameters
            $params = array_merge([
                'response_type' => 'code',
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'scope' => implode(' ', $this->scopes),
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $this->codeChallengeMethod,
            ], $options);

            $authUrl = $authorizationEndpoint . '?' . http_build_query($params);

            return $authUrl;

        } catch (\Exception $e) {
            Log::error('Failed to generate authorization URL', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Failed to generate authorization URL: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle OAuth callback and exchange code for tokens.
     *
     * @param string $code Authorization code
     * @param string $state State parameter
     * @param string|null $error Error parameter
     * @param string|null $errorDescription Error description parameter
     * @return array Token data
     * @throws RuntimeException
     */
    public function handleCallback(
        string $code,
        string $state,
        ?string $error = null,
        ?string $errorDescription = null
    ): array {
        try {
            // Handle authorization errors
            if ($error) {
                Log::warning('OAuth authorization error', [
                    'error' => $error,
                    'description' => $errorDescription,
                ]);
                throw new RuntimeException("Authorization failed: {$error} - {$errorDescription}");
            }

            // Check for launch token in state
            $launchTokenData = $this->stateService->handleLaunchTokenState($state);

            if ($launchTokenData) {
                return $this->handleLaunchTokenCallback($code, $launchTokenData);
            }

            // Standard OAuth callback handling
            return $this->handleStandardCallback($code, $state);

        } catch (\Exception $e) {
            Log::error('OAuth callback handling failed', [
                'error' => $e->getMessage(),
                'has_code' => !empty($code),
                'has_state' => !empty($state),
                'oauth_error' => $error,
            ]);

            // Clean up state on error
            if ($state) {
                $this->stateService->retrieveState($state, true);
            }

            throw new RuntimeException("Callback handling failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle standard OAuth callback.
     *
     * @param string $code Authorization code
     * @param string $state State parameter
     * @return array Token data
     */
    protected function handleStandardCallback(string $code, string $state): array
    {
        // Retrieve and consume stored state
        $stateData = $this->stateService->retrieveState($state, true);

        if (!$stateData) {
            throw new RuntimeException('Invalid or expired state parameter');
        }

        if (!isset($stateData['code_verifier'])) {
            throw new RuntimeException('PKCE code verifier not found');
        }

        // Exchange code for tokens
        $tokens = $this->tokenService->exchangeCodeForTokens(
            $code,
            $this->redirectUri,
            $stateData['code_verifier'],
            $this->clientId,
            $this->clientSecret
        );

        // Store tokens in session
        $this->tokenService->storeTokens($tokens);

        return $tokens;
    }

    /**
     * Handle launch token callback (direct app launch from SSO server).
     *
     * @param string $code Authorization code
     * @param array $launchTokenData Launch token data
     * @return array Token data
     */
    protected function handleLaunchTokenCallback(string $code, array $launchTokenData): array
    {
        // Retrieve launch token data from SSO server
        $launchTokenResponse = $this->retrieveLaunchTokenData($launchTokenData['launch_token']);

        if (!$launchTokenResponse) {
            throw new RuntimeException('Invalid or expired launch token');
        }

        // Validate state matches
        if (!hash_equals($launchTokenResponse['state'], $launchTokenData['state'])) {
            throw new RuntimeException('Launch token state mismatch');
        }

        // Exchange code for tokens using launch token data
        $tokens = $this->tokenService->exchangeCodeForTokens(
            $code,
            $this->redirectUri,
            $launchTokenResponse['code_verifier'],
            $this->clientId,
            $this->clientSecret
        );

        // Store tokens in session
        $this->tokenService->storeTokens($tokens);

        return $tokens;
    }

    /**
     * Retrieve launch token data from SSO server.
     *
     * @param string $launchToken Launch token
     * @return array|null Launch token data or null if invalid
     */
    protected function retrieveLaunchTokenData(string $launchToken): ?array
    {
        try {
            $endpoint = $this->baseUrl . config('sso-client.endpoints.launch_token', '/api/launch-token');

            $response = $this->request()
                ->get("{$endpoint}/{$launchToken}");

            if (!$response->successful()) {
                Log::warning('Launch token endpoint returned non-success response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::warning('Failed to retrieve launch token data', [
                'error' => $e->getMessage(),
                'launch_token' => substr($launchToken, 0, 10) . '...',
            ]);
            return null;
        }
    }

    /**
     * Get user information using stored access token.
     *
     * @return array User information
     * @throws RuntimeException
     */
    public function getUserInfo(): array
    {
        $tokens = $this->tokenService->retrieveTokens();

        if (!$tokens || !isset($tokens['access_token'])) {
            throw new RuntimeException('No access token available');
        }

        // Check if token is expired and try to refresh
        if ($this->tokenService->isAccessTokenExpired()) {
            if (isset($tokens['refresh_token'])) {
                $tokens = $this->refreshAccessToken();
            } else {
                throw new RuntimeException('Access token expired and no refresh token available');
            }
        }

        return $this->tokenService->getUserInfo($tokens['access_token']);
    }

    /**
     * Refresh access token using refresh token.
     *
     * @return array New token data
     * @throws RuntimeException
     */
    public function refreshAccessToken(): array
    {
        $tokens = $this->tokenService->retrieveTokens();

        if (!$tokens || !isset($tokens['refresh_token'])) {
            throw new RuntimeException('No refresh token available');
        }

        $newTokens = $this->tokenService->refreshAccessToken(
            $tokens['refresh_token'],
            $this->clientId,
            $this->clientSecret,
            $this->scopes
        );

        // Update stored tokens
        $this->tokenService->storeTokens($newTokens, null, $tokens);

        return $newTokens;
    }

    /**
     * Clear all stored authentication data.
     *
     * @return void
     */
    public function logout(): void
    {
        $tokens = $this->tokenService->retrieveTokens();

        if ($tokens) {
            try {
                $this->tokenService->revokeTokens($tokens, $this->clientId, $this->clientSecret);
            } catch (\Exception $e) {
                Log::warning('Token revocation during logout failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->tokenService->clearTokens();
        $this->stateService->clearAllStates();

        Log::info('SSO logout completed');
    }

    /**
     * Check if user is currently authenticated.
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        $tokens = $this->tokenService->retrieveTokens();

        if (!$tokens || !isset($tokens['access_token'])) {
            return false;
        }

        // If token is expired but we have refresh token, consider still authenticated
        if ($this->tokenService->isAccessTokenExpired()) {
            return isset($tokens['refresh_token']);
        }

        return true;
    }

    /**
     * Get the currently stored tokens.
     *
     * @return array|null
     */
    public function getTokens(): ?array
    {
        return $this->tokenService->getTokens();
    }

    /**
     * Get the current access token.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->tokenService->getAccessToken();
    }

    /**
     * Get the current refresh token.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        return $this->tokenService->getRefreshToken();
    }

    /**
     * Get the current ID token.
     *
     * @return string|null
     */
    public function getIdToken(): ?string
    {
        return $this->tokenService->getIdToken();
    }

    /**
     * Validate the provided (or stored) ID token.
     *
     * @param string|null $idToken
     * @param bool $verifySignature
     * @return array|null
     */
    public function validateIdToken(?string $idToken = null, bool $verifySignature = true): ?array
    {
        $token = $idToken ?? $this->tokenService->getIdToken();

        if (!$token) {
            return null;
        }

        return $this->tokenService->validateJWT($token, $verifySignature);
    }

    /**
     * Revoke currently stored tokens.
     *
     * @return bool
     */
    public function revokeTokens(): bool
    {
        $tokens = $this->tokenService->retrieveTokens();

        if (!$tokens) {
            return true;
        }

        return $this->tokenService->revokeTokens($tokens, $this->clientId, $this->clientSecret);
    }

    /**
     * Validate service configuration.
     *
     * @throws InvalidArgumentException
     */
    protected function validateConfiguration(): void
    {
        if (!$this->baseUrl) {
            throw new InvalidArgumentException('SSO base URL is not configured');
        }

        if (!$this->clientId) {
            throw new InvalidArgumentException('SSO client ID is not configured');
        }

        if (!$this->redirectUri) {
            throw new InvalidArgumentException('SSO redirect URI is not configured');
        }

        if (empty($this->scopes)) {
            throw new InvalidArgumentException('SSO scopes must be configured');
        }

        // Validate URLs
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('SSO base URL is not valid');
        }

        if (!filter_var($this->redirectUri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('SSO redirect URI is not valid');
        }
    }

    /**
     * Build a configured HTTP client.
     *
     * @return PendingRequest
     */
    protected function request(): PendingRequest
    {
        $request = Http::timeout($this->httpTimeout)
            ->connectTimeout($this->httpConnectTimeout)
            ->withOptions(['verify' => $this->verifySsl]);

        if ($this->httpRetryAttempts > 0) {
            $request = $request->retry($this->httpRetryAttempts, $this->httpRetryDelay);
        }

        return $request;
    }
}
