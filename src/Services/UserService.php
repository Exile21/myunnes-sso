<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use InvalidArgumentException;

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

    public function __construct()
    {
        $this->userModel = config('sso-client.user.model', '\App\Models\User::class');
        $this->identifierField = config('sso-client.user.identifier_field', 'email');
        $this->ssoIdField = config('sso-client.user.sso_id_field', 'sso_id');
        $this->updateableFields = config('sso-client.user.updateable_fields', ['name', 'email', 'email_verified_at']);
        $this->autoCreate = config('sso-client.user.auto_create', true);
        $this->autoUpdate = config('sso-client.user.auto_update', true);

        // Validate user model
        if (!class_exists($this->userModel)) {
            throw new InvalidArgumentException("User model class does not exist: {$this->userModel}");
        }

        if (!is_subclass_of($this->userModel, Authenticatable::class)) {
            throw new InvalidArgumentException("User model must implement Authenticatable interface: {$this->userModel}");
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
            $identifier = $ssoUserData[$this->identifierField] ?? null;

            if (!$identifier) {
                throw new InvalidArgumentException("Missing identifier field '{$this->identifierField}' in SSO user data");
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
            $user = $this->findUserByIdentifier($identifier);

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
     * Find user by identifier (email, username, etc.).
     *
     * @param string $identifier User identifier
     * @return Authenticatable|null
     */
    public function findUserByIdentifier(string $identifier): ?Authenticatable
    {
        try {
            return $this->userModel::where($this->identifierField, $identifier)->first();
        } catch (\Exception $e) {
            Log::error('Error finding user by identifier', [
                'identifier' => $identifier,
                'field' => $this->identifierField,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

            // Set email as verified if provided by SSO
            if (isset($ssoUserData['email']) && !isset($userData['email_verified_at'])) {
                $userData['email_verified_at'] = now();
            }

            return $this->userModel::create($userData);

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
                    $user->update($updateData);
                } else {
                    // For non-Eloquent models, try to update each field individually
                    foreach ($updateData as $field => $value) {
                        if (property_exists($user, $field)) {
                            $user->{$field} = $value;
                        }
                    }

                    // Check if model has save method before calling
                    if (method_exists($user, 'save')) {
                        /** @var \Illuminate\Database\Eloquent\Model $user */
                        $user->save();
                    }
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
                $user->update([$this->ssoIdField => $ssoId]);
            } else {
                // For non-Eloquent models, set the field directly
                if (property_exists($user, $this->ssoIdField)) {
                    $user->{$this->ssoIdField} = $ssoId;

                    // Try to save if method exists
                    if (method_exists($user, 'save')) {
                        /** @var \Illuminate\Database\Eloquent\Model $user */
                        $user->save();
                    }
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
        $userData = [];

        // Map standard OIDC claims to user fields
        $givenName = $ssoUserData['given_name'] ?? null;
        $familyName = $ssoUserData['family_name'] ?? null;
        $composedName = trim(implode(' ', array_filter([$givenName, $familyName])));

        $fieldMapping = [
            'name' => $ssoUserData['name'] ?? ($composedName !== '' ? $composedName : null),
            'email' => $ssoUserData['email'] ?? null,
            'first_name' => $givenName,
            'last_name' => $familyName,
            'username' => $ssoUserData['preferred_username'] ?? null,
            'avatar' => $ssoUserData['picture'] ?? null,
            'locale' => $ssoUserData['locale'] ?? null,
            'timezone' => $ssoUserData['zoneinfo'] ?? null,
        ];

        // Only include fields that have values and are in updateable fields
        foreach ($fieldMapping as $field => $value) {
            if ($value !== null && (in_array($field, $this->updateableFields) || $field === 'email')) {
                $userData[$field] = $value;
            }
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
        if (!isset($ssoUserData['sub']) || !is_string($ssoUserData['sub'])) {
            return false;
        }

        // Must have identifier field
        if (!isset($ssoUserData[$this->identifierField])) {
            return false;
        }

        return true;
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
}
