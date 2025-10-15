# SSO Client - Quick Setup Guide

## For sys_user Table (MyUnnes Schema)

### 1. Environment Configuration (.env)

```env
# SSO Server
SSO_BASE_URL=https://sso.myunnes.com
SSO_CLIENT_ID=your-client-id
SSO_CLIENT_SECRET=your-client-secret
SSO_REDIRECT_URI=https://your-app.com/auth/sso/callback

# User Model
SSO_USER_MODEL=MyUnnes\Base\Models\SysUser
SSO_USER_IDENTIFIER=email_user
SSO_USER_SSO_ID_FIELD=sso_id

# Fields to Sync (remove email_verified_at if column doesn't exist)
SSO_USER_UPDATEABLE_FIELDS=username_user,nm_user,email_user,identitas_user

# Auto-create Users
SSO_AUTO_CREATE_USERS=true
SSO_AUTO_UPDATE_USERS=true

# Activate Users on Creation (if you have aktif column)
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=aktif
SSO_USER_ACTIVE_VALUE=1
```

### 2. Publish Config (Optional for Field Mappings)

```bash
php artisan vendor:publish --tag=sso-client-config
```

Edit `config/sso-client.php`:

```php
'field_mappings' => [
    'username_user' => [':email', ':identifier'],
    'nm_user' => [':full_name'],
    'email_user' => [':email'],
    'identitas_user' => [':identifier'],
],
```

### 3. Clear Cache

```bash
php artisan config:clear
```

## For Standard Laravel User Table

### Environment Configuration (.env)

```env
# SSO Server
SSO_BASE_URL=https://sso.myunnes.com
SSO_CLIENT_ID=your-client-id
SSO_CLIENT_SECRET=your-client-secret
SSO_REDIRECT_URI=https://your-app.com/auth/sso/callback

# User Model (default)
SSO_USER_MODEL=App\Models\User
SSO_USER_IDENTIFIER=email
SSO_USER_SSO_ID_FIELD=sso_id

# Fields to Sync
SSO_USER_UPDATEABLE_FIELDS=name,email,email_verified_at

# Auto-create Users
SSO_AUTO_CREATE_USERS=true
SSO_AUTO_UPDATE_USERS=true

# Activate Users on Creation (if you have is_active column)
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=is_active
SSO_USER_ACTIVE_VALUE=true
```

## Common Issues & Solutions

### ❌ Column 'email_verified_at' not found
**Solution:** Remove from `SSO_USER_UPDATEABLE_FIELDS`
```env
SSO_USER_UPDATEABLE_FIELDS=name,email
```

### ❌ Column 'xyz' not found
**Solution:** Only include columns that exist in your table
```env
# Check your table structure first
DESCRIBE sys_user;

# Then update env
SSO_USER_UPDATEABLE_FIELDS=existing_col1,existing_col2
```

### ❌ User not activated after login
**Solution:** Enable activation
```env
SSO_SET_USER_ACTIVE=true
SSO_USER_ACTIVE_FIELD=aktif  # or is_active, status, etc.
SSO_USER_ACTIVE_VALUE=1      # or true, 'active', 'Y', etc.
```

### ❌ Field mapping not working
**Solution:** Publish config and add custom mappings
```bash
php artisan vendor:publish --tag=sso-client-config
```

## Field Mapping Cheat Sheet

| Your Column | Recommended Mapping |
|-------------|-------------------|
| `name` | `[':full_name']` |
| `email` | `[':email']` |
| `username` | `[':preferred_username', ':email']` |
| `nm_user` | `[':full_name']` |
| `username_user` | `[':email', ':identifier']` |
| `email_user` | `[':email']` |
| `identitas_user` | `[':identifier']` |

## Derived Values Reference

| Derived Value | Description | Fallback Chain |
|--------------|-------------|----------------|
| `:identifier` | Direct identifier | identifier claim only |
| `:email` | Email address | email claim |
| `:full_name` | Full name | name → given_name + family_name → email → preferred_username → identifier |
| `:given_name` | First name | given_name claim |
| `:family_name` | Last name | family_name claim |
| `:preferred_username` | Username | preferred_username claim |
| `:sub` | SSO ID | sub claim |

## Testing Checklist

- [ ] Config cache cleared
- [ ] User table has required columns
- [ ] SSO credentials correct
- [ ] Redirect URI matches
- [ ] Field mappings align with table
- [ ] Activation field exists (if enabled)
- [ ] SSO server accessible
- [ ] Test login successful
- [ ] User created in database
- [ ] Fields populated correctly

## Need Help?

1. Check error logs: `storage/logs/laravel.log`
2. Review SSO response in logs
3. Verify configuration: `php artisan config:show sso-client`
4. Check documentation: `CONFIGURATION_GUIDE.md`
