<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use RuntimeException;

/**
 * OpenID Connect Discovery Service
 *
 * Handles OIDC discovery document retrieval and caching.
 */
class DiscoveryService
{
    protected string $baseUrl;
    protected bool $cacheEnabled;
    protected string $cacheStore;
    protected int $cacheTtl;
    protected string $cachePrefix;
    protected int $timeout;
    protected int $connectTimeout;
    protected bool $verifySsl;
    protected int $retryAttempts;
    protected int $retryDelay;

    public function __construct()
    {
        $this->baseUrl = config('sso-client.base_url');
        $this->cacheEnabled = config('sso-client.cache.enabled', true);
        $this->cacheStore = config('sso-client.cache.store', 'default');
        $this->cacheTtl = config('sso-client.cache.discovery_ttl', 3600);
        $this->cachePrefix = config('sso-client.cache.prefix', 'sso_client_');
        $this->timeout = config('sso-client.http.timeout', 30);
        $this->connectTimeout = config('sso-client.http.connect_timeout', 10);
        $this->verifySsl = config('sso-client.security.verify_ssl', true);
        $this->retryAttempts = (int) config('sso-client.http.retry_attempts', 3);
        $this->retryDelay = (int) config('sso-client.http.retry_delay', 1000);

        if (!$this->baseUrl) {
            throw new InvalidArgumentException('SSO base URL is not configured');
        }
    }

    /**
     * Get the OIDC discovery document.
     *
     * @param bool $forceRefresh Force refresh from server
     * @return array The discovery document
     * @throws RuntimeException
     */
    public function getDiscoveryDocument(bool $forceRefresh = false): array
    {
        $cacheKey = $this->getCacheKey('discovery');

        if (!$forceRefresh && $this->cacheEnabled) {
            $cached = $this->cache()->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $url = $this->baseUrl . config('sso-client.endpoints.discovery', '/.well-known/openid-configuration');

        try {
            $response = $this->request()->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("Failed to fetch discovery document: HTTP {$response->status()}");
            }

            $document = $response->json();

            if (!$this->validateDiscoveryDocument($document)) {
                throw new RuntimeException('Invalid discovery document received');
            }

            // Cache the document
            if ($this->cacheEnabled) {
                $this->cache()->put($cacheKey, $document, $this->cacheTtl);
            }

            return $document;

        } catch (\Exception $e) {
            Log::error('Failed to fetch OIDC discovery document', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Discovery document fetch failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get a specific endpoint from the discovery document.
     *
     * @param string $endpoint The endpoint name (e.g., 'authorization_endpoint')
     * @param bool $forceRefresh Force refresh from server
     * @return string The endpoint URL
     * @throws RuntimeException
     */
    public function getEndpoint(string $endpoint, bool $forceRefresh = false): string
    {
        $document = $this->getDiscoveryDocument($forceRefresh);

        if (!isset($document[$endpoint])) {
            throw new RuntimeException("Endpoint '{$endpoint}' not found in discovery document");
        }

        return $document[$endpoint];
    }

    /**
     * Get supported scopes from discovery document.
     *
     * @param bool $forceRefresh Force refresh from server
     * @return array Supported scopes
     */
    public function getSupportedScopes(bool $forceRefresh = false): array
    {
        $document = $this->getDiscoveryDocument($forceRefresh);
        return $document['scopes_supported'] ?? [];
    }

    /**
     * Get supported response types from discovery document.
     *
     * @param bool $forceRefresh Force refresh from server
     * @return array Supported response types
     */
    public function getSupportedResponseTypes(bool $forceRefresh = false): array
    {
        $document = $this->getDiscoveryDocument($forceRefresh);
        return $document['response_types_supported'] ?? ['code'];
    }

    /**
     * Get supported code challenge methods from discovery document.
     *
     * @param bool $forceRefresh Force refresh from server
     * @return array Supported challenge methods
     */
    public function getSupportedCodeChallengeMethods(bool $forceRefresh = false): array
    {
        $document = $this->getDiscoveryDocument($forceRefresh);
        return $document['code_challenge_methods_supported'] ?? ['S256'];
    }

    /**
     * Check if PKCE is required by the server.
     *
     * @param bool $forceRefresh Force refresh from server
     * @return bool True if PKCE is required
     */
    public function isPKCERequired(bool $forceRefresh = false): bool
    {
        $methods = $this->getSupportedCodeChallengeMethods($forceRefresh);
        return !empty($methods);
    }

    /**
     * Validate the discovery document structure.
     *
     * @param array $document The discovery document
     * @return bool True if valid
     */
    protected function validateDiscoveryDocument(array $document): bool
    {
        $requiredFields = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'jwks_uri',
        ];

        foreach ($requiredFields as $field) {
            if (!isset($document[$field]) || !is_string($document[$field])) {
                Log::warning("Discovery document missing required field: {$field}");
                return false;
            }
        }

        // Validate URLs
        $urlFields = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'jwks_uri',
        ];

        foreach ($urlFields as $field) {
            if (isset($document[$field]) && !filter_var($document[$field], FILTER_VALIDATE_URL)) {
                Log::warning("Discovery document contains invalid URL for field: {$field}");
                return false;
            }
        }

        return true;
    }

    /**
     * Clear cached discovery document.
     *
     * @return bool True if cache was cleared
     */
    public function clearCache(): bool
    {
        if (!$this->cacheEnabled) {
            return true;
        }

        $cacheKey = $this->getCacheKey('discovery');
        return $this->cache()->forget($cacheKey);
    }

    /**
     * Generate cache key.
     *
     * @param string $suffix Cache key suffix
     * @return string Full cache key
     */
    protected function getCacheKey(string $suffix): string
    {
        $baseUrlHash = hash('sha256', $this->baseUrl);
        return $this->cachePrefix . "discovery_{$baseUrlHash}_{$suffix}";
    }

    /**
     * Build a configured HTTP request.
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
     * Resolve cache repository, honoring custom store configuration.
     */
    protected function cache(): \Illuminate\Contracts\Cache\Repository
    {
        if (!$this->cacheEnabled) {
            return Cache::driver();
        }

        if (empty($this->cacheStore) || $this->cacheStore === 'default') {
            return Cache::driver();
        }

        return Cache::store($this->cacheStore);
    }
}
