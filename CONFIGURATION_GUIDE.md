# SSO Client Configuration Guide

## New Configurable Features

### 1. Field Mappings (Flexible Schema Support)

Map SSO claims to your custom database columns using the `field_mappings` configuration.

#### Configuration Location
`config/sso-client.php` → `user.field_mappings`

#### Syntax
```php
'field_mappings' => [
    'your_column_name' => ['source1', 'source2', 'source3'],
]
```

#### Available Sources

**Direct SSO Claims** (use claim name as-is):
- `email` - Email from SSO
- `name` - Full name from SSO
- `given_name` - First name
- `family_name` - Last name
- `preferred_username` - Username
- `sub` - SSO user ID
- Any custom claim from your SSO server

**Derived Values** (use `:` prefix):
- `:identifier` - Direct from 'identifier' claim (no fallbacks)
- `:email` - Email address
- `:full_name` - Composed full name (name → given_name + family_name → email → preferred_username → identifier)
- `:given_name` - First name
- `:family_name` - Last name
- `:preferred_username` - Username
- `:sub` - SSO ID

#### Default Mappings
```php
'field_mappings' => [
    'name' => [':full_name'],
    'email' => [':email'],
]
```

#### Example: Custom User Table Schema
```php
// For a table with columns: username_user, nm_user, email_user, identitas_user
'field_mappings' => [
    'username_user' => [':email', ':identifier'],           // Try email first, fallback to identifier
    'nm_user' => [':full_name', 'name'],                   // Try derived full_name, fallback to name claim
    'email_user' => [':email'],                             // Email address
    'identitas_user' => [':identifier', 'identifier'],      // Identifier from claim
],
```

#### How It Works
The system tries each source in order until it finds a non-empty value:
1. If source starts with `:`, uses derived value
2. Otherwise, looks for that claim in SSO response
3. First non-empty value wins

### 2. Active User Status (Auto-activation on Creation)

Automatically set user as active when created via SSO login.

#### Environment Variables
```env
# Enable auto-activation (default: false)
SSO_SET_USER_ACTIVE=true

# Column name for active status (default: is_active)
SSO_USER_ACTIVE_FIELD=is_active

# Value to set (default: true)
# Options: true, false, 1, 0, 'active', 'Y', etc.
SSO_USER_ACTIVE_VALUE=true
```

#### Configuration
```php
'user' => [
    // Set user as active on creation
    'set_active_on_create' => env('SSO_SET_USER_ACTIVE', false),
    
    // The column name for active status
    'active_field' => env('SSO_USER_ACTIVE_FIELD', 'is_active'),
    
    // The value to set for active status
    'active_value' => env('SSO_USER_ACTIVE_VALUE', true),
],
```

#### Common Scenarios

**Boolean Column (is_active, active, enabled)**
```env
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=is_active
SSO_USER_ACTIVE_VALUE=true
```

**Integer Column (status, aktif)**
```env
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=aktif
SSO_USER_ACTIVE_VALUE=1
```

**String Column (status)**
```env
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=status
SSO_USER_ACTIVE_VALUE=active
```

**Character Column (flag_aktif)**
```env
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=flag_aktif
SSO_USER_ACTIVE_VALUE=Y
```

### 3. Updateable Fields (Schema-aware)

Control which fields are synced from SSO to your database.

#### Environment Variable
```env
SSO_USER_UPDATEABLE_FIELDS=name,email,phone,department
```

#### Default
```env
SSO_USER_UPDATEABLE_FIELDS=name,email
```

#### Examples

**Minimal (email only)**
```env
SSO_USER_UPDATEABLE_FIELDS=email
```

**Standard (name and email)**
```env
SSO_USER_UPDATEABLE_FIELDS=name,email
```

**Extended (with email verification)**
```env
SSO_USER_UPDATEABLE_FIELDS=name,email,email_verified_at
```

**Custom schema**
```env
SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user,email_user,identitas_user
```

## Complete Configuration Examples

### Example 1: Standard Laravel User Table
```env
# User model
SSO_USER_MODEL=App\Models\User

# Identifier field
SSO_USER_IDENTIFIER=email

# SSO ID field
SSO_USER_SSO_ID_FIELD=sso_id

# Fields to update
SSO_USER_UPDATEABLE_FIELDS=name,email,email_verified_at

# Auto-create and update
SSO_AUTO_CREATE_USERS=true
SSO_AUTO_UPDATE_USERS=true

# Set user as active
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=is_active
SSO_USER_ACTIVE_VALUE=true
```

### Example 2: Custom MyUnnes Schema (sys_user table)
```env
# User model
SSO_USER_MODEL=MyUnnes\Base\Models\SysUser

# Identifier field
SSO_USER_IDENTIFIER=email_user

# SSO ID field
SSO_USER_SSO_ID_FIELD=sso_id

# Fields to update
SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user,email_user,identitas_user

# Auto-create and update
SSO_AUTO_CREATE_USERS=true
SSO_AUTO_UPDATE_USERS=true

# Set user as active
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=aktif
SSO_USER_ACTIVE_VALUE=1
```

**With Field Mappings (in published config):**
```php
'field_mappings' => [
    'username_user' => [':email', ':identifier'],
    'nm_user' => [':full_name', 'name'],
    'email_user' => [':email'],
    'identitas_user' => [':identifier'],
],
```

### Example 3: Legacy System with Custom Columns
```env
SSO_USER_MODEL=App\Models\LegacyUser
SSO_USER_IDENTIFIER=user_email
SSO_USER_SSO_ID_FIELD=external_id
SSO_USER_UPDATEABLE_FIELDS=user_name,user_email,user_login,user_status
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=user_status
SSO_USER_ACTIVE_VALUE=active
```

**Field Mappings:**
```php
'field_mappings' => [
    'user_name' => [':full_name'],
    'user_email' => [':email'],
    'user_login' => [':preferred_username', ':email'],
],
```

## Migration Guide

### From Hardcoded to Configurable

**Before:** Package assumed standard Laravel columns
```
❌ Failed if your table had different column names
❌ Failed if email_verified_at didn't exist
❌ No control over which fields to sync
```

**After:** Fully configurable
```
✅ Map any SSO claim to any column
✅ Only sync fields that exist in your table
✅ Control activation status
✅ Works with any user table schema
```

### Step-by-Step Migration

1. **Identify your user table columns**
   ```sql
   DESCRIBE sys_user;
   ```

2. **Map required fields to env**
   ```env
   SSO_USER_IDENTIFIER=email_user
   SSO_USER_SSO_ID_FIELD=sso_id
   SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user,email_user,identitas_user
   ```

3. **Publish and customize config (optional)**
   ```bash
   php artisan vendor:publish --tag=sso-client-config
   ```
   
   Edit `config/sso-client.php`:
   ```php
   'field_mappings' => [
       'username_user' => [':email'],
       'nm_user' => [':full_name'],
       'email_user' => [':email'],
       'identitas_user' => [':identifier'],
   ],
   ```

4. **Configure activation if needed**
   ```env
   SSO_SET_USER_ACTIVE=true
   SSO_USER_ACTIVE_FIELD=aktif
   SSO_USER_ACTIVE_VALUE=1
   ```

5. **Clear config cache**
   ```bash
   php artisan config:clear
   ```

6. **Test SSO login**

## Troubleshooting

### Column not found error
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'xyz' in 'field list'
```

**Solution:** Remove that column from `SSO_USER_UPDATEABLE_FIELDS`

### Field not being mapped
**Check:**
1. Is the field in `SSO_USER_UPDATEABLE_FIELDS`?
2. Is there a mapping in `field_mappings` for it?
3. Does the SSO response include that claim?

**Debug:**
```php
// In UserService, add logging:
Log::info('SSO User Data', $ssoUserData);
Log::info('Mapped User Data', $userData);
```

### User not activated
**Check:**
1. `SSO_SET_USER_ACTIVE=true`
2. Column name matches `SSO_USER_ACTIVE_FIELD`
3. Value type matches (boolean vs integer vs string)

## Best Practices

1. **Use environment variables** for deployment flexibility
2. **Publish config** for complex field mappings
3. **Test mappings** with sample SSO responses
4. **Document your schema** for team reference
5. **Use minimal updateable_fields** for security
6. **Clear config cache** after changes

## Security Notes

- Only include fields that should be synced from SSO
- Don't include sensitive fields like `password` in updateable_fields
- Use `auto_update=false` if you want users to control their data
- Validate SSO claims before trusting them

## Support

For issues or questions:
1. Check error logs
2. Review SSO response structure
3. Verify configuration matches your schema
4. Test with minimal configuration first
