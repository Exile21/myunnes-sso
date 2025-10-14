<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient;

use MyUnnes\SSOClient\Services\SSOAuthService;
use MyUnnes\SSOClient\Services\UserService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Main SSO Client Class
 *
 * Provides a high-level interface for SSO authentication operations.
 */
class SSOClient
{
    protected SSOAuthService $authService;
    protected UserService $userService;
    protected LoggerInterface $logger;

    public function __construct(
        SSOAuthService $authService,
        UserService $userService,
        LoggerInterface $logger
    ) {
        $this->authService = $authService;
        $this->userService = $userService;
        $this->logger = $logger;
    }

    /**
     * Redirect user to SSO server for authentication.
     *
     * @param array $options Additional options for authorization request
 * @return RedirectResponse
 */
    public function redirect(array $options = []): RedirectResponse
    {
        try {
            $authUrl = $this->authService->getAuthorizationUrl($options);

            $this->logger->info('Redirecting to SSO for authentication', [
                'destination' => parse_url($authUrl, PHP_URL_HOST),
                'options' => array_keys($options),
            ]);

            return redirect($authUrl);

        } catch (\Exception $e) {
            $this->logger->error('Failed to redirect to SSO', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("SSO redirect failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Handle OAuth callback and return user.
     *
     * @param Request $request OAuth callback request
     * @return Authenticatable User model instance
     * @throws RuntimeException
     */
    public function handleCallback(Request $request): Authenticatable
    {
        try {
            // Extract callback parameters
            $code = $request->input('code');
            $state = $request->input('state');
            $error = $request->input('error');
            $errorDescription = $request->input('error_description');

            if (!$code || !$state) {
                throw new RuntimeException('Missing required callback parameters');
            }

            // Handle OAuth callback
            $tokens = $this->authService->handleCallback($code, $state, $error, $errorDescription);

            // Get user information
            $userInfo = $this->authService->getUserInfo();

            // Find or create user
            $user = $this->userService->findOrCreateUser($userInfo);

            if (!$user) {
                throw new RuntimeException('Failed to create or find user');
            }

            $this->logger->info('SSO authentication successful', [
                'user_id' => $user->getAuthIdentifier(),
                'sso_id' => $userInfo['sub'] ?? 'unknown',
                'has_refresh_token' => isset($tokens['refresh_token']),
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->logger->error('SSO callback handling failed', [
                'error' => $e->getMessage(),
                'request_params' => $request->only(['code', 'state', 'error', 'error_description']),
            ]);

            throw new RuntimeException("SSO callback handling failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get current user information from SSO.
     *
     * @return array User information
     * @throws RuntimeException
     */
    public function getUserInfo(): array
    {
        try {
            return $this->authService->getUserInfo();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user info', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Failed to get user info: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Refresh access token.
     *
     * @return array New token data
     * @throws RuntimeException
     */
    public function refreshToken(): array
    {
        try {
            $tokens = $this->authService->refreshAccessToken();

            $this->logger->info('Access token refreshed successfully');

            return $tokens;

        } catch (\Exception $e) {
            $this->logger->error('Token refresh failed', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Token refresh failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Check if user is authenticated via SSO.
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->authService->isAuthenticated();
    }

    /**
     * Logout user from SSO.
     *
     * @return void
     */
    public function logout(): void
    {
        try {
            $this->authService->logout();

            $this->logger->info('SSO logout successful');

        } catch (\Exception $e) {
            $this->logger->error('SSO logout failed', [
                'error' => $e->getMessage(),
            ]);
            // Don't throw exception for logout failures
        }
    }

    /**
     * Get logout URL for SSO server.
     *
     * @param string|null $redirectUrl Post-logout redirect URL
     * @return string Logout URL
     */
    public function getLogoutUrl(?string $redirectUrl = null): string
    {
        $baseUrl = config('sso-client.base_url');
        $logoutEndpoint = config('sso-client.endpoints.logout', '/logout');

        $url = $baseUrl . $logoutEndpoint;

        if ($redirectUrl) {
            $url .= '?' . http_build_query(['redirect_uri' => $redirectUrl]);
        }

        return $url;
    }

    /**
     * Find user by SSO ID.
     *
     * @param string $ssoId SSO user ID
     * @return Authenticatable|null
     */
    public function findUserBySSOId(string $ssoId): ?Authenticatable
    {
        return $this->userService->findUserBySSOId($ssoId);
    }

    /**
     * Find user by identifier (email, username, etc.).
     *
     * @param string $identifier User identifier
     * @return Authenticatable|null
     */
    public function findUserByIdentifier(string $identifier): ?Authenticatable
    {
        return $this->userService->findUserByIdentifier($identifier);
    }

    /**
     * Get current token payload if available.
     *
     * @return array|null
     */
    public function getTokens(): ?array
    {
        return $this->authService->getTokens();
    }

    /**
     * Get current access token if available.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->authService->getAccessToken();
    }

    /**
     * Get current refresh token if available.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        return $this->authService->getRefreshToken();
    }

    /**
     * Get current ID token if available.
     *
     * @return string|null
     */
    public function getIdToken(): ?string
    {
        return $this->authService->getIdToken();
    }

    /**
     * Validate the stored or provided ID token and return its claims.
     *
     * @param string|null $idToken
     * @param bool $verifySignature
     * @return array|null
     */
    public function validateIdToken(?string $idToken = null, bool $verifySignature = true): ?array
    {
        return $this->authService->validateIdToken($idToken, $verifySignature);
    }

    /**
     * Revoke tokens currently held by the client.
     *
     * @return bool
     */
    public function revokeTokens(): bool
    {
        return $this->authService->revokeTokens();
    }

    /**
     * Get SSO authentication service instance.
     *
     * @return SSOAuthService
     */
    public function getAuthService(): SSOAuthService
    {
        return $this->authService;
    }

    /**
     * Get user service instance.
     *
     * @return UserService
     */
    public function getUserService(): UserService
    {
        return $this->userService;
    }
}
