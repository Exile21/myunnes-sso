# Security Best Practices

This document outlines security best practices when using the MyUnnes SSO Client package.

## Security Features

### ✅ Built-in Security Measures

1. **PKCE (Proof Key for Code Exchange)**
   - Enforced by default for all OAuth flows
   - Uses SHA256 challenge method
   - 128-character code verifiers for maximum entropy

2. **State Parameter Validation**
   - Cryptographically secure random state generation
   - Automatic CSRF protection
   - Session-based state storage with expiration

3. **Token Security**
   - Encrypted token storage in sessions
   - Automatic token expiration handling
   - Secure token refresh and revocation mechanisms

4. **Input Validation**
   - Comprehensive validation of all OAuth parameters
   - Sanitization of user data
   - Protection against injection attacks

5. **SSL/TLS Security**
   - SSL certificate validation enforced by default
   - Secure HTTP transport for all API calls
   - Protection against man-in-the-middle attacks

## Configuration Security

### Environment Variables

Store sensitive configuration in environment variables:

```env
# Required - Keep these secret
SSO_CLIENT_SECRET=your_secret_here

# Optional security settings
SSO_FORCE_PKCE=true
SSO_ENCRYPT_TOKENS=true
SSO_VERIFY_SSL=true
SSO_CODE_CHALLENGE_METHOD=S256
```

### Laravel Configuration

Ensure secure session configuration:

```php
// config/session.php
'secure' => env('SESSION_SECURE_COOKIE', true),
'http_only' => true,
'same_site' => 'lax',
'encrypt' => true,
```

## Implementation Security

### Controller Security

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MyUnnes\SSOClient\Facades\SSOClient;

class SSOController extends Controller
{
    public function __construct()
    {
        // Apply rate limiting
        $this->middleware('throttle:5,1')->only(['redirectToProvider', 'handleProviderCallback']);
        
        // Apply CSRF protection
        $this->middleware('web');
    }

    public function handleProviderCallback(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'code' => 'required|string|max:255',
            'state' => 'required|string|max:255',
        ]);

        try {
            $user = SSOClient::handleCallback($request);
            
            // Additional security checks
            if (!$user->email_verified_at) {
                throw new \Exception('Email not verified');
            }
            
            Auth::login($user);
            
            // Regenerate session ID after login
            $request->session()->regenerate();
            
            return redirect()->intended('/dashboard');
            
        } catch (\Exception $e) {
            // Log security events
            \Log::warning('SSO authentication failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'error' => $e->getMessage(),
            ]);
            
            return redirect('/login')->withErrors([
                'sso' => 'Authentication failed. Please try again.'
            ]);
        }
    }
}
```

### Middleware Protection

```php
// Apply SSO authentication middleware
Route::middleware(['sso.auth'])->group(function () {
    Route::get('/protected', function () {
        return 'Protected content';
    });
});
```

## Data Protection

### User Data Handling

```php
// Configure which fields can be updated from SSO
'user' => [
    'updateable_fields' => [
        'name',
        'email',
        'email_verified_at',
        // Don't include sensitive fields like 'password'
    ],
    
    // Auto-create users only if needed
    'auto_create' => env('SSO_AUTO_CREATE_USERS', false),
    
    // Be careful with auto-updates
    'auto_update' => env('SSO_AUTO_UPDATE_USERS', true),
],
```

### Logging Configuration

```php
'logging' => [
    'enabled' => true,
    'log_success' => true,
    'log_failures' => true,
    
    // Disable in production to protect user privacy
    'include_user_data' => env('APP_DEBUG', false),
],
```

## Network Security

### Firewall Configuration

Ensure your application can only access the SSO server:

```bash
# Allow outbound HTTPS to SSO server only
iptables -A OUTPUT -d sso.myunnes.com -p tcp --dport 443 -j ACCEPT

# Block other outbound connections
iptables -A OUTPUT -p tcp --dport 443 -j DROP
```

### SSL Certificate Validation

Always validate SSL certificates:

```php
'security' => [
    'verify_ssl' => true, // Never set to false in production
    'timeout' => 30,      // Reasonable timeout
],
```

## Monitoring & Alerting

### Security Events to Monitor

1. **Failed Authentication Attempts**
2. **Unusual Login Patterns**
3. **Token Refresh Failures**
4. **SSL Certificate Errors**
5. **Configuration Changes**

### Example Monitoring

```php
// In your controller or service
\Log::channel('security')->info('SSO login successful', [
    'user_id' => $user->id,
    'ip' => request()->ip(),
    'user_agent' => request()->userAgent(),
    'timestamp' => now(),
]);
```

## Common Security Pitfalls

### ❌ Don't Do This

```php
// DON'T disable SSL verification
'verify_ssl' => false,

// DON'T store secrets in code
'client_secret' => 'hardcoded_secret',

// DON'T log sensitive data
\Log::info('Token received', ['access_token' => $token]);

// DON'T use weak state parameters
$state = '123456';
```

### ✅ Do This Instead

```php
// DO use environment variables
'client_secret' => env('SSO_CLIENT_SECRET'),

// DO validate SSL certificates
'verify_ssl' => env('SSO_VERIFY_SSL', true),

// DO use secure logging
\Log::info('Authentication successful', [
    'user_id' => $user->id,
    'timestamp' => now(),
]);

// DO use cryptographically secure state
$state = Str::random(40);
```

## Security Auditing

### Regular Security Checks

1. **Review Dependencies**: `composer audit`
2. **Check Configuration**: Verify all security settings
3. **Monitor Logs**: Regular review of authentication logs
4. **Update Packages**: Keep dependencies up to date

### Vulnerability Scanning

```bash
# Check for known vulnerabilities
composer audit

# Security scanning with additional tools
./vendor/bin/security-checker security:check

# Laravel-specific security checks
php artisan security:check
```

## Emergency Response

### Security Incident Response

1. **Immediately revoke compromised credentials**
2. **Force logout all users if needed**
3. **Review logs for suspicious activity**
4. **Update credentials and redeploy**
5. **Monitor for continued suspicious activity**

### Emergency Logout

```php
// Force logout all SSO sessions
SSOClient::logout();
Session::flush();
Auth::logout();
```

## Contact

For security issues or questions:
- Email: security@myunnes.com
- Report vulnerabilities responsibly
- Include steps to reproduce issues
