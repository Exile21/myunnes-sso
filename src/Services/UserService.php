<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use RuntimeException;

/**
 * User Service for SSO Client
 *
 * Handles user creation, updates, and mapping from SSO user data.
 */
class UserService
{
    protected string $userModel;
    protected string $identifierField;
    protected string $ssoIdField;
    protected array $updateableFields;
    protected bool $autoCreate;
    protected bool $autoUpdate;
    protected array $fallbackIdentifierFields = ['email', 'preferred_username', 'identifier'];
    protected array $fieldMappings;
    protected bool $setActiveOnCreate;
    protected string $activeField;
    protected mixed $activeValue;

    public function __construct()
    {
        $this->userModel = config('sso-client.user.model', \App\Models\User::class);
        $this->identifierField = config('sso-client.user.identifier_field', 'email');
        $this->ssoIdField = config('sso-client.user.sso_id_field', 'sso_id');
        $this->updateableFields = array_values(array_filter(array_unique(
            config('sso-client.user.updateable_fields', ['name', 'email', 'identitas_user'])
        )));
        $this->autoCreate = config('sso-client.user.auto_create', true);
        $this->autoUpdate = config('sso-client.user.auto_update', true);
        $this->setActiveOnCreate = config('sso-client.user.set_active_on_create', false);
        $this->activeField = config('sso-client.user.active_field', 'is_active');
        $this->activeValue = config('sso-client.user.active_value', true);

        // Validate user model
        if (!class_exists($this->userModel)) {
            throw new InvalidArgumentException("User model class does not exist: {$this->userModel}");
        }

        if (!is_subclass_of($this->userModel, Authenticatable::class)) {
            throw new InvalidArgumentException("User model must implement Authenticatable interface: {$this->userModel}");
        }

        if (!in_array($this->identifierField, $this->updateableFields, true)) {
            $this->updateableFields[] = $this->identifierField;
        }

        $this->fieldMappings = $this->prepareFieldMappings(
            config('sso-client.user.field_mappings', [])
        );

        if ($this->identifierField !== 'email') {
            $this->fallbackIdentifierFields = array_values(array_filter(
                $this->fallbackIdentifierFields,
                fn ($field) => $field !== 'email'
            ));
        }
    }

    /**
     * Find or create user from SSO user data.
     *
     * @param array $ssoUserData User data from SSO server
     * @return Authenticatable|null The user model instance
     * @throws RuntimeException
     */
    public function findOrCreateUser(array $ssoUserData): ?Authenticatable
    {
        try {
            // Validate required SSO user data
            if (!$this->validateSSOUserData($ssoUserData)) {
                throw new InvalidArgumentException('Invalid SSO user data provided');
            }

            $ssoId = $ssoUserData['sub'];
            $identifierCandidates = $this->resolveIdentifierCandidates($ssoUserData);

            $identifier = $this->resolvePrimaryIdentifier($identifierCandidates);

            if (!$identifier) {
                throw new InvalidArgumentException('Missing identifier in SSO user data');
            }

            // Try to find user by SSO ID first
            $user = $this->findUserBySSOId($ssoId);

            if ($user) {
                // Update existing user if auto-update is enabled
                if ($this->autoUpdate) {
                    $this->updateUser($user, $ssoUserData);
                }

                return $user;
            }

            // Try to find user by identifier (email, username, etc.)
            $user = $this->findUserByIdentifiers($identifierCandidates);

            if ($user) {
                // Link existing user to SSO
                $this->linkUserToSSO($user, $ssoId);

                if ($this->autoUpdate) {
                    $this->updateUser($user, $ssoUserData);
                }

                return $user;
            }

            // Create new user if auto-create is enabled
            if ($this->autoCreate) {
                $user = $this->createUser($ssoUserData);

                Log::info('New SSO user created', [
                    'sso_id' => $ssoId,
                    'identifier' => $identifier,
                ]);

                return $user;
            }

            Log::warning('User not found and auto-create disabled', [
                'sso_id' => $ssoId,
                'identifier' => $identifier,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error in findOrCreateUser', [
                'error' => $e->getMessage(),
                'sso_data' => $this->sanitizeUserDataForLogging($ssoUserData),
            ]);

            throw new RuntimeException("Failed to find or create user: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Find user by SSO ID.
     *
     * @param string $ssoId SSO user ID
     * @return Authenticatable|null
     */
    public function findUserBySSOId(string $ssoId): ?Authenticatable
    {
        try {
            /** @var \Illuminate\Contracts\Auth\Authenticatable|null */
            return $this->userModel::where($this->ssoIdField, $ssoId)->first();
        } catch (\Exception $e) {
            Log::error('Error finding user by SSO ID', [
                'sso_id' => $ssoId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Find user by multiple identifier fields.
     *
     * @param array<string, string> $identifiers Field => value pairs to search
     * @return Authenticatable|null
     */
    public function findUserByIdentifiers(array $identifiers): ?Authenticatable
    {
        foreach ($identifiers as $field => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
                $user = $this->userModel::where($field, $value)->first();
                if ($user) {
                    return $user;
                }
            } catch (\Exception $e) {
                Log::error('Error finding user by identifier', [
                    'identifier' => $value,
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function findUserByIdentifier(string $identifier): ?Authenticatable
    {
        return $this->findUserByIdentifiers([
            $this->identifierField => $identifier,
        ]);
    }

    /**
     * Create new user from SSO data.
     *
     * @param array $ssoUserData SSO user data
     * @return Authenticatable
     * @throws RuntimeException
     */
    protected function createUser(array $ssoUserData): Authenticatable
    {
        try {
            $userData = $this->mapSSODataToUserData($ssoUserData);

            // Add SSO ID
            $userData[$this->ssoIdField] = $ssoUserData['sub'];

            // Set email as verified if provided by SSO and field exists in updateable fields
            if (isset($ssoUserData['email']) &&
                in_array('email_verified_at', $this->updateableFields, true) &&
                !isset($userData['email_verified_at'])) {
                $userData['email_verified_at'] = now();
            }

            // Set user as active if configured
            if ($this->setActiveOnCreate && !isset($userData[$this->activeField])) {
                $userData[$this->activeField] = $this->activeValue;
            }

            /** @var \Illuminate\Database\Eloquent\Model&\Illuminate\Contracts\Auth\Authenticatable $user */
            $user = new $this->userModel();

            if (!$user instanceof Model) {
                throw new RuntimeException('Configured user model must extend Eloquent Model.');
            }

            $user->forceFill($userData)->save();

            return $user;

        } catch (\Exception $e) {
            Log::error('Error creating user', [
                'error' => $e->getMessage(),
                'sso_data' => $this->sanitizeUserDataForLogging($ssoUserData),
            ]);

            throw new RuntimeException("Failed to create user: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Update existing user with SSO data.
     *
     * @param Authenticatable $user User model
     * @param array $ssoUserData SSO user data
     * @return void
     */
    protected function updateUser(Authenticatable $user, array $ssoUserData): void
    {
        try {
            $userData = $this->mapSSODataToUserData($ssoUserData);

            // Only update specified fields
            $updateData = [];
            foreach ($this->updateableFields as $field) {
                if (isset($userData[$field])) {
                    $updateData[$field] = $userData[$field];
                }
            }

            if (!empty($updateData)) {
                if ($user instanceof Model) {
                    $user->forceFill($updateData)->save();
                    return;
                }

                foreach ($updateData as $field => $value) {
                    if (property_exists($user, $field)) {
                        $user->{$field} = $value;
                    }
                }

                if (method_exists($user, 'save')) {
                    /** @var \Illuminate\Database\Eloquent\Model $user */
                    $user->save();
                }
            }

        } catch (\Exception $e) {
            Log::error('Error updating user', [
                'user_id' => $user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
            // Don't throw exception for update failures
        }
    }

    /**
     * Link existing user to SSO.
     *
     * @param Authenticatable $user User model
     * @param string $ssoId SSO user ID
     * @return void
     */
    protected function linkUserToSSO(Authenticatable $user, string $ssoId): void
    {
        try {
            if ($user instanceof Model) {
                $user->forceFill([$this->ssoIdField => $ssoId])->save();
                return;
            }

            if (property_exists($user, $this->ssoIdField)) {
                $user->{$this->ssoIdField} = $ssoId;

                if (method_exists($user, 'save')) {
                    /** @var \Illuminate\Database\Eloquent\Model $user */
                    $user->save();
                }
            }
        } catch (\Exception $e) {
            Log::error('Error linking user to SSO', [
                'user_id' => $user->getAuthIdentifier(),
                'sso_id' => $ssoId,
                'error' => $e->getMessage(),
            ]);
            // Don't throw exception for linking failures
        }
    }

    /**
     * Map SSO user data to local user data.
     *
     * @param array $ssoUserData SSO user data
     * @return array Mapped user data
     */
    protected function mapSSODataToUserData(array $ssoUserData): array
    {
        // Pre-compute derived values once
        $derived = $this->computeDerivedValues($ssoUserData);

        $userData = [];

        // Map fields based on configuration
        foreach ($this->fieldMappings as $field => $sources) {
            // Skip fields not in updateable list
            if (!in_array($field, $this->updateableFields, true) && $field !== $this->identifierField) {
                continue;
            }

            $value = $this->resolveFieldValue($sources, $ssoUserData, $derived);
            if ($value !== null && $value !== '') {
                $userData[$field] = $value;
            }
        }

        // Ensure primary identifier is set
        $primaryIdentifier = $derived['identifier'] ?? $derived['email'];
        if ($primaryIdentifier) {
            $userData[$this->identifierField] = $primaryIdentifier;
        }

        return $userData;
    }

    /**
     * Validate SSO user data structure.
     *
     * @param array $ssoUserData SSO user data
     * @return bool True if valid
     */
    protected function validateSSOUserData(array $ssoUserData): bool
    {
        // Must have 'sub' (subject) claim
        if (!isset($ssoUserData['sub']) || !is_string($ssoUserData['sub']) || trim($ssoUserData['sub']) === '') {
            return false;
        }

        return !empty($this->resolveIdentifierCandidates($ssoUserData));
    }

    /**
     * Sanitize user data for logging (remove sensitive information).
     *
     * @param array $userData User data
     * @return array Sanitized data
     */
    protected function sanitizeUserDataForLogging(array $userData): array
    {
        $sensitive = ['password', 'token', 'secret', 'key'];
        $sanitized = [];

        foreach ($userData as $key => $value) {
            if (is_string($key)) {
                $keyLower = strtolower($key);
                $isSensitive = false;

                foreach ($sensitive as $sensitiveKey) {
                    if (strpos($keyLower, $sensitiveKey) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }

                $sanitized[$key] = $isSensitive ? '[REDACTED]' : $value;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Resolve potential identifier values from SSO payload.
     *
     * @param array $ssoUserData
     * @return array<string, string>
     */
    /**
     * Build a list of possible identifiers derived from the SSO payload.
     *
     * @param array<string, mixed> $ssoUserData
     * @param array<string, mixed>|null $derived
     * @return array<string, string>
     */
    protected function resolveIdentifierCandidates(array $ssoUserData, ?array $derived = null): array
    {
        $derived ??= $this->computeDerivedValues($ssoUserData);

        $candidates = [];

        $primary = $derived['identifier'] ?? null;
        if (is_string($primary) && $primary !== '') {
            $candidates[$this->identifierField] = $primary;
        } elseif (!empty($derived['email'])) {
            $candidates[$this->identifierField] = $derived['email'];
        }

        foreach ($this->fallbackIdentifierFields as $field) {
            if (!isset($ssoUserData[$field])) {
                continue;
            }

            $value = is_string($ssoUserData[$field]) ? trim($ssoUserData[$field]) : null;
            if ($value !== null && $value !== '') {
                $candidates[$field] = $value;
            }
        }

        foreach ($this->fieldMappings as $column => $sources) {
            $value = $this->resolveFieldValue($sources, $ssoUserData, $derived);
            if ($value !== null && $value !== '') {
                $candidates[$column] = $value;
            }
        }

        return array_filter($candidates, fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * Prepare field mappings from configuration, falling back to sensible defaults.
     */
    /**
     * Normalise field mapping configuration.
     *
     * @param array<string, mixed>|string|null $configMappings
     * @return array<string, array<int, string>>
     */
    protected function prepareFieldMappings($configMappings): array
    {
        // Sensible defaults for common scenarios
        $defaultMappings = [
            $this->identifierField => [':identifier', ':email'],
            'name' => [':full_name'],
            'email' => [':email'],
        ];

        // Use provided config mappings, or fall back to defaults
        if (!is_array($configMappings) || empty($configMappings)) {
            $configMappings = $defaultMappings;
        } else {
            // Merge with defaults, config takes precedence
            $configMappings = array_merge($defaultMappings, $configMappings);
        }

        $normalized = [];

        foreach ($configMappings as $column => $sources) {
            if (!is_string($column) || trim($column) === '') {
                continue;
            }

            // Normalize sources to array
            $sources = is_array($sources) ? $sources : [$sources];
            $sources = array_values(array_filter(array_map(
                function ($source) {
                    return is_string($source) ? trim($source) : null;
                },
                $sources
            )));

            if (empty($sources)) {
                continue;
            }

            // Only include fields in updateable list or identifier field
            if (!in_array($column, $this->updateableFields, true) && $column !== $this->identifierField) {
                continue;
            }

            $normalized[$column] = $sources;
        }

        // Ensure identifier field always has a mapping
        if (!isset($normalized[$this->identifierField])) {
            $normalized[$this->identifierField] = [':identifier', ':email'];
        }

        return $normalized;
    }

    /**
     * Pre-compute reusable claim values to simplify mapping logic.
     *
     * @param array<string, mixed> $ssoUserData
     * @return array<string, string|null>
     */
    protected function computeDerivedValues(array $ssoUserData): array
    {
        $sub = $ssoUserData['sub'] ?? null;
        $email = !empty($ssoUserData['email']) ? trim((string) $ssoUserData['email']) : null;
        $given = !empty($ssoUserData['given_name']) ? trim((string) $ssoUserData['given_name']) : null;
        $family = !empty($ssoUserData['family_name']) ? trim((string) $ssoUserData['family_name']) : null;
        $name = !empty($ssoUserData['name']) ? trim((string) $ssoUserData['name']) : null;
        $preferred = !empty($ssoUserData['preferred_username']) ? trim((string) $ssoUserData['preferred_username']) : null;

        // Compose full name from parts if not provided
        if (!$name && ($given || $family)) {
            $name = trim(($given ?? '') . ' ' . ($family ?? '')) ?: null;
        }

        // Determine identifier with fallback chain
        $identifier = !empty($ssoUserData['identifier']) ? trim((string) $ssoUserData['identifier']) : null;
        if (!$identifier) {
            $identifier = $email ?? $preferred ?? $sub;
        }

        // Full name fallback chain
        $fullName = $name ?? $preferred ?? $email ?? $identifier;

        return [
            'sub' => $sub,
            'email' => $email,
            'identifier' => $identifier,
            'full_name' => $fullName,
            'given_name' => $given,
            'family_name' => $family,
            'preferred_username' => $preferred,
        ];
    }

    /**
     * Resolve the first non-empty value for the configured sources.
     *
     * @param array<int, string> $sources
     * @param array<string, mixed> $ssoUserData
     * @param array<string, mixed> $derived
     * @return string|null
     */
    protected function resolveFieldValue(array $sources, array $ssoUserData, array $derived): ?string
    {
        foreach ($sources as $source) {
            if (!is_string($source) || $source === '') {
                continue;
            }

            $value = null;

            if ($source[0] === ':') {
                $key = substr($source, 1);
                $value = $derived[$key] ?? null;
            } else {
                $value = $ssoUserData[$source] ?? null;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determine the primary identifier value to use.
     *
     * @param array<string, string> $identifierCandidates
     * @return string|null
     */
    protected function resolvePrimaryIdentifier(array $identifierCandidates): ?string
    {
        if (empty($identifierCandidates)) {
            return null;
        }

        if (isset($identifierCandidates[$this->identifierField])) {
            return $identifierCandidates[$this->identifierField];
        }

        $firstKey = array_key_first($identifierCandidates);

        return $firstKey ? $identifierCandidates[$firstKey] : null;
    }
}
