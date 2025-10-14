<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PKCE (Proof Key for Code Exchange) Service
 *
 * Implements RFC 7636 for enhanced OAuth security.
 * Generates and validates code verifiers and challenges.
 */
class PKCEService
{
    /**
     * Generate a code verifier for PKCE.
     *
     * @param int $length Length of the code verifier (43-128 characters)
     * @return string Base64URL-encoded code verifier
     * @throws InvalidArgumentException
     */
    public function generateCodeVerifier(int $length = 128): string
    {
        if ($length < 43 || $length > 128) {
            throw new InvalidArgumentException('Code verifier length must be between 43 and 128 characters');
        }

        // Generate random bytes and encode as base64url
        $randomBytes = random_bytes((int) ceil($length * 3 / 4));
        $codeVerifier = $this->base64UrlEncode($randomBytes);

        // Trim to exact length
        return substr($codeVerifier, 0, $length);
    }

    /**
     * Generate a code challenge from a code verifier.
     *
     * @param string $codeVerifier The code verifier
     * @param string $method The challenge method ('S256' or 'plain')
     * @return string The code challenge
     * @throws InvalidArgumentException
     */
    public function generateCodeChallenge(string $codeVerifier, string $method = 'S256'): string
    {
        switch ($method) {
            case 'S256':
                return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

            case 'plain':
                return $codeVerifier;

            default:
                throw new InvalidArgumentException("Unsupported code challenge method: {$method}");
        }
    }

    /**
     * Verify a code verifier against a code challenge.
     *
     * @param string $codeVerifier The code verifier to verify
     * @param string $codeChallenge The expected code challenge
     * @param string $method The challenge method used
     * @return bool True if verification succeeds
     */
    public function verifyCodeChallenge(string $codeVerifier, string $codeChallenge, string $method = 'S256'): bool
    {
        try {
            $expectedChallenge = $this->generateCodeChallenge($codeVerifier, $method);
            return hash_equals($expectedChallenge, $codeChallenge);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Validate a code verifier format.
     *
     * @param string $codeVerifier The code verifier to validate
     * @return bool True if valid
     */
    public function validateCodeVerifier(string $codeVerifier): bool
    {
        $length = strlen($codeVerifier);

        // Check length
        if ($length < 43 || $length > 128) {
            return false;
        }

        // Check format: unreserved characters only
        return preg_match('/^[A-Za-z0-9\-._~]+$/', $codeVerifier) === 1;
    }

    /**
     * Base64URL encode a string.
     *
     * @param string $data The data to encode
     * @return string Base64URL-encoded string
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode a string.
     *
     * @param string $data The data to decode
     * @return string|false Decoded string or false on failure
     */
    protected function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
