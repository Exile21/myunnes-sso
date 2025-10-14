<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * State Management Service for OAuth 2.0
 *
 * Handles secure generation, storage, and validation of OAuth state parameters
 * to prevent CSRF attacks.
 */
class StateService
{
    protected string $sessionPrefix;
    protected bool $encryptState;

    public function __construct()
    {
        $this->sessionPrefix = config('sso-client.session.prefix', 'sso_');
        $this->encryptState = config('sso-client.security.encrypt_tokens', true);
    }

    /**
     * Generate a secure state parameter.
     *
     * @param int $length Length of the state parameter
     * @return string The generated state
     * @throws InvalidArgumentException
     */
    public function generateState(int $length = null): string
    {
        $length = $length ?? config('sso-client.security.state_length', 40);

        if ($length < 32) {
            throw new InvalidArgumentException('State length must be at least 32 characters');
        }

        return Str::random($length);
    }

    /**
     * Store state in session with optional encryption.
     *
     * @param string $state The state to store
     * @param array $additionalData Additional data to store with state
     * @return void
     */
    public function storeState(string $state, array $additionalData = []): void
    {
        $data = array_merge([
            'state' => $state,
            'created_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(config('sso-client.session.lifetime', 15))->timestamp,
        ], $additionalData);

        if ($this->encryptState) {
            $data = Crypt::encrypt($data);
        }

        Session::put($this->getStateKey($state), $data);
    }

    /**
     * Retrieve and validate state from session.
     *
     * @param string $state The state to retrieve
     * @param bool $removeAfterRetrieval Remove from session after retrieval
     * @return array|null The state data or null if invalid/expired
     */
    public function retrieveState(string $state, bool $removeAfterRetrieval = true): ?array
    {
        $key = $this->getStateKey($state);
        $data = Session::get($key);

        if (!$data) {
            return null;
        }

        try {
            if ($this->encryptState) {
                $data = Crypt::decrypt($data);
            }

            // Check if state matches
            if (!isset($data['state']) || !hash_equals($data['state'], $state)) {
                if ($removeAfterRetrieval) {
                    Session::forget($key);
                }
                return null;
            }

            // Check expiration
            if (isset($data['expires_at']) && $data['expires_at'] < now()->timestamp) {
                if ($removeAfterRetrieval) {
                    Session::forget($key);
                }
                return null;
            }

            if ($removeAfterRetrieval) {
                Session::forget($key);
            }

            return $data;

        } catch (\Exception) {
            // Decryption failed or other error
            if ($removeAfterRetrieval) {
                Session::forget($key);
            }
            return null;
        }
    }

    /**
     * Validate a state parameter.
     *
     * @param string $receivedState The state received from OAuth server
     * @param bool $removeAfterValidation Remove from session after validation
     * @return bool True if state is valid
     */
    public function validateState(string $receivedState, bool $removeAfterValidation = true): bool
    {
        $data = $this->retrieveState($receivedState, $removeAfterValidation);
        return $data !== null;
    }

    /**
     * Store PKCE data with state.
     *
     * @param string $state The state parameter
     * @param string $codeVerifier The PKCE code verifier
     * @param string $codeChallenge The PKCE code challenge
     * @param string $challengeMethod The challenge method used
     * @return void
     */
    public function storePKCEData(string $state, string $codeVerifier, string $codeChallenge, string $challengeMethod = 'S256'): void
    {
        $this->storeState($state, [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
            'challenge_method' => $challengeMethod,
        ]);
    }

    /**
     * Retrieve PKCE data for a state.
     *
     * @param string $state The state parameter
     * @return array|null PKCE data or null if not found
     */
    public function retrievePKCEData(string $state): ?array
    {
        $data = $this->retrieveState($state, false);

        if (!$data || !isset($data['code_verifier'])) {
            return null;
        }

        return [
            'code_verifier' => $data['code_verifier'],
            'code_challenge' => $data['code_challenge'] ?? null,
            'challenge_method' => $data['challenge_method'] ?? 'S256',
        ];
    }

    /**
     * Clean up expired state entries.
     *
     * @return int Number of cleaned entries
     */
    public function cleanupExpiredStates(): int
    {
        $sessionData = Session::all();
        $prefix = $this->sessionPrefix . 'state_';
        $cleaned = 0;
        $now = now()->timestamp;

        foreach ($sessionData as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                try {
                    $data = $this->encryptState ? Crypt::decrypt($value) : $value;

                    if (isset($data['expires_at']) && $data['expires_at'] < $now) {
                        Session::forget($key);
                        $cleaned++;
                    }
                } catch (\Exception) {
                    // Invalid data, remove it
                    Session::forget($key);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Clear all SSO state data from session.
     *
     * @return void
     */
    public function clearAllStates(): void
    {
        $sessionData = Session::all();
        $prefix = $this->sessionPrefix;

        foreach (array_keys($sessionData) as $key) {
            if (strpos($key, $prefix) === 0) {
                Session::forget($key);
            }
        }
    }

    /**
     * Handle launch token state (from SSO server direct launches).
     *
     * @param string $encodedState Base64-encoded state containing launch token info
     * @return array|null Decoded launch token data
     */
    public function handleLaunchTokenState(string $encodedState): ?array
    {
        try {
            $decoded = json_decode(base64_decode($encodedState), true);

            if (!is_array($decoded) || !isset($decoded['launch_token'])) {
                return null;
            }

            return $decoded;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Generate session key for state storage.
     *
     * @param string $state The state parameter
     * @return string The session key
     */
    protected function getStateKey(string $state): string
    {
        return $this->sessionPrefix . 'state_' . hash('sha256', $state);
    }
}
