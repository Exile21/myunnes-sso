# SSO Field Mapping Reference

## Available Data Sources

### 🔹 Standard OpenID Connect Claims

These are the standard claims defined by the OpenID Connect specification that your SSO server may provide:

| Claim | Type | Description | Example |
|-------|------|-------------|---------|
| `sub` | string | Subject - Unique user ID (required) | `"0198215e-1951-715f-9ac6-8485a39e89ea"` |
| `email` | string | Email address | `"user@example.com"` |
| `email_verified` | boolean | Email verification status | `true` |
| `name` | string | Full name | `"John Doe"` |
| `given_name` | string | First name | `"John"` |
| `family_name` | string | Last name / surname | `"Doe"` |
| `middle_name` | string | Middle name | `"Michael"` |
| `nickname` | string | Casual name | `"Johnny"` |
| `preferred_username` | string | Username | `"johndoe"` |
| `profile` | string | Profile page URL | `"https://example.com/users/johndoe"` |
| `picture` | string | Profile picture URL | `"https://example.com/avatar.jpg"` |
| `website` | string | User's website | `"https://johndoe.com"` |
| `gender` | string | Gender | `"male"` or `"female"` |
| `birthdate` | string | Date of birth (YYYY-MM-DD) | `"1990-01-15"` |
| `zoneinfo` | string | Time zone | `"Asia/Jakarta"` |
| `locale` | string | Locale/language | `"id-ID"` or `"en-US"` |
| `phone_number` | string | Phone number | `"+628123456789"` |
| `phone_number_verified` | boolean | Phone verification status | `true` |
| `address` | object | Address information | `{"country": "ID", ...}` |
| `updated_at` | number | Last update timestamp | `1634567890` |

### 🔹 Derived Values (Computed by Package)

These are smart values computed from the SSO claims with intelligent fallbacks:

| Derived Value | Computation Logic | Use Case |
|--------------|-------------------|----------|
| `:identifier` | Direct from `identifier` claim (no fallbacks) | Exact identifier value from SSO |
| `:email` | `email` claim | Email address |
| `:full_name` | `name → (given_name + family_name) → email → preferred_username → identifier` | User's full name |
| `:given_name` | `given_name` claim | First name |
| `:family_name` | `family_name` claim | Last name |
| `:preferred_username` | `preferred_username` claim | Username |
| `:sub` | `sub` claim | SSO user ID |

**Note:** Use `:` prefix for derived values, no prefix for direct claims.

## Mapping Examples

### Standard Laravel User Table

```php
'field_mappings' => [
    'name' => [':full_name'],
    'email' => [':email'],
    'avatar' => ['picture'],
],
```

### Custom MyUnnes Schema (sys_user)

```php
'field_mappings' => [
    'username_user' => [':email', ':preferred_username'],
    'nm_user' => [':full_name', 'name'],
    'email_user' => [':email'],
    'identitas_user' => [':identifier'],
    'telp_user' => ['phone_number'],
    'foto_user' => ['picture'],
],
```

### Extended User Profile

```php
'field_mappings' => [
    'username' => [':preferred_username', ':email'],
    'email' => [':email'],
    'first_name' => [':given_name', 'given_name'],
    'last_name' => [':family_name', 'family_name'],
    'full_name' => [':full_name'],
    'phone' => ['phone_number'],
    'avatar_url' => ['picture'],
    'website' => ['website'],
    'bio' => ['profile'],
    'locale' => ['locale', 'zoneinfo'],
    'gender' => ['gender'],
    'birth_date' => ['birthdate'],
],
```

### Legacy System

```php
'field_mappings' => [
    'user_login' => [':preferred_username', ':email'],
    'user_name' => [':full_name'],
    'user_email' => [':email'],
    'user_firstname' => [':given_name'],
    'user_lastname' => [':family_name'],
    'user_phone' => ['phone_number'],
    'user_avatar' => ['picture'],
    'user_locale' => ['locale'],
],
```

## Custom SSO Claims

If your SSO server provides custom claims, you can map them directly:

```php
'field_mappings' => [
    // Standard mappings
    'email' => [':email'],
    'name' => [':full_name'],
    
    // Custom claims from your SSO
    'employee_id' => ['employee_number'],        // Custom claim
    'department' => ['department'],              // Custom claim
    'job_title' => ['title', 'job_title'],      // Multiple custom claims
    'organization' => ['org_name', 'company'],   // With fallback
],
```

## Fallback Chain Strategy

The package tries each source in order until it finds a non-empty value:

```php
'username_user' => [':email', ':preferred_username', 'sub']
```

**Processing order:**
1. Try derived `:email` → if not empty, use it
2. Try derived `:preferred_username` → if not empty, use it  
3. Try direct `sub` claim → if not empty, use it
4. If all empty → field not set

## Best Practices

### ✅ DO

```php
// Use derived values for flexibility
'name' => [':full_name']

// Provide fallbacks
'username' => [':preferred_username', ':email', 'sub']

// Map direct claims when available
'phone' => ['phone_number']

// Use specific derived values
'email' => [':email']
```

### ❌ DON'T

```php
// Don't mix claim and field names incorrectly
'name' => ['username']  // Wrong claim

// Don't forget the colon for derived values
'email' => ['email']    // Should be [':email'] for derived

// Don't map to non-existent columns
'nonexistent_column' => [':email']  // Column must exist in updateable_fields
```

## Debugging Mappings

### View SSO Response

Add temporary logging to see what claims your SSO provides:

```php
// In app/Http/Controllers/Auth/SSOController.php or similar
use Illuminate\Support\Facades\Log;

Log::info('SSO User Info', $userInfo);
```

### Common SSO Response Example

```json
{
    "sub": "0198215e-1951-715f-9ac6-8485a39e89ea",
    "email": "admin@example.com",
    "email_verified": true,
    "name": "Admin User",
    "given_name": "Admin",
    "family_name": "User",
    "preferred_username": "admin",
    "picture": "https://sso.example.com/avatar/admin.jpg",
    "locale": "id-ID",
    "zoneinfo": "Asia/Jakarta"
}
```

### Mapping This Response

```php
'field_mappings' => [
    'username_user' => [':email'],               // → "admin@example.com"
    'nm_user' => [':full_name'],                  // → "Admin User"
    'identitas_user' => [':identifier'],          // → "admin@example.com"
    'foto_user' => ['picture'],                   // → "https://sso.example.com/avatar/admin.jpg"
    'locale_user' => ['locale'],                  // → "id-ID"
    'timezone_user' => ['zoneinfo'],              // → "Asia/Jakarta"
],
```

## Troubleshooting

### Field Not Being Populated

**Check:**
1. Is the field in `updateable_fields`?
   ```env
   SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user,foto_user
   ```

2. Does the SSO response include that claim?
   ```php
   Log::info('SSO Claims', $ssoUserData);
   ```

3. Is the mapping correct?
   ```php
   'foto_user' => ['picture'],  // Direct claim
   // or
   'name' => [':full_name'],    // Derived value (with colon)
   ```

### Wrong Data Being Saved

**Verify:**
1. Check fallback order
   ```php
   // This tries email first, then username
   'identifier' => [':email', ':preferred_username']
   ```

2. Confirm claim names match SSO response
   ```php
   // SSO sends 'display_name', not 'name'
   'name' => ['display_name', ':full_name']
   ```

### Column Not Found Error

```
SQLSTATE[42S22]: Column not found
```

**Solution:** Only map to existing columns in `updateable_fields`

```env
# Remove non-existent columns
SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user  # Remove 'email_verified_at' if it doesn't exist
```

## Reference Implementation

Complete example for typical MyUnnes implementation:

```php
// config/sso-client.php
'user' => [
    'model' => env('SSO_USER_MODEL', 'MyUnnes\Base\Models\SysUser::class'),
    'identifier_field' => env('SSO_USER_IDENTIFIER', 'email_user'),
    'sso_id_field' => env('SSO_USER_SSO_ID_FIELD', 'sso_id'),
    
    'updateable_fields' => array_filter(array_map('trim', explode(',', 
        env('SSO_USER_UPDATEABLE_FIELDS', 'username_user,nm_user,email_user,identitas_user,telp_user,foto_user')
    ))),
    
    'field_mappings' => [
        'username_user' => [':email', ':preferred_username'],
        'nm_user' => [':full_name', 'name'],
        'email_user' => [':email'],
        'identitas_user' => [':identifier'],
        'telp_user' => ['phone_number'],
        'foto_user' => ['picture'],
    ],
    
    'set_active_on_create' => env('SSO_SET_USER_ACTIVE', true),
    'active_field' => env('SSO_USER_ACTIVE_FIELD', 'is_aktif'),
    'active_value' => env('SSO_USER_ACTIVE_VALUE', 1),
],
```

## Need More Claims?

If you need custom claims from your SSO server:

1. **Request them in scopes:**
   ```php
   'scopes' => ['openid', 'profile', 'email', 'custom_scope'],
   ```

2. **Check SSO server configuration** for available claims

3. **Map them directly:**
   ```php
   'custom_field' => ['custom_claim_name'],
   ```
