# Security Documentation

## Overview
This document describes the security measures implemented in the Interclasse system to protect against common vulnerabilities including SQL injection, XSS, CSRF, and unauthorized access.

## SQL Injection Protection

### Prepared Statements
The system uses PDO prepared statements for all database queries that involve user input:

```php
$stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
```

### Static Queries
Queries that don't involve user input use `db()->query()` for static SQL:
```php
$events = db()->query('SELECT id, name FROM events ORDER BY start_date DESC')->fetchAll();+
```

### Security Functions
The `includes/security.php` file provides additional SQL injection detection:
- `detect_sql_injection()` - Checks for common SQL injection patterns in input

## XSS (Cross-Site Scripting) Protection

### Output Escaping
All user-generated content is escaped using the `e()` function before output:
```php
<?= e($user['name']) ?>
```

### Input Sanitization
The `sanitize_input()` function is available for additional input sanitization:
```php
$safeInput = sanitize_input($userInput);
```

## CSRF (Cross-Site Request Forgery) Protection

### CSRF Tokens
All forms include CSRF tokens that are validated on submission:
```php
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
```

### Token Validation
Tokens are validated using:
- `verify_csrf()` - For page requests
- `verify_api_csrf()` - For API requests
- `verify_json_csrf()` - For JSON API requests

## Authentication & Authorization

### Password Security
- Passwords are hashed using `password_hash()` with `PASSWORD_DEFAULT` (bcrypt)
- Legacy plaintext passwords are automatically upgraded to hashed passwords
- Password rehashing is handled automatically when PHP's default algorithm changes
- Session IDs are regenerated on login (`session_regenerate_id(true)`)

### Role-Based Access Control
- Three roles: `admin`, `professor` (displayed as Mesário), `representante`
- Functions to check permissions:
  - `require_login()` - Requires user to be logged in
  - `require_roles(['admin', 'professor'])` - Requires specific roles
  - `has_role(['admin'])` - Checks if user has specific role

### Session Security
- Secure cookies enabled (configurable via `secure_cookies` in config)
- HttpOnly cookies to prevent JavaScript access
- SameSite=Lax to prevent CSRF
- Session timeout handled by PHP default settings

## Rate Limiting

### Login Protection
The login function includes rate limiting:
- Maximum 5 login attempts per IP address
- 5-minute window for rate limiting
- 2-second delay for blocked attempts

### Rate Limiting Function
```php
check_rate_limit($identifier, $maxAttempts, $windowSeconds)
```

## Security Headers

### HTTP Security Headers
The following headers are set on all requests:

- `X-Content-Type-Options: nosniff` - Prevents MIME type sniffing
- `X-Frame-Options: SAMEORIGIN` - Prevents clickjacking
- `X-XSS-Protection: 1; mode=block` - Enables XSS protection
- `Referrer-Policy: strict-origin-when-cross-origin` - Controls referrer information

### Content Security Policy
Basic CSP is implemented to restrict resource loading:
```php
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';
```

## Input Validation

### Validation Functions
The `includes/security.php` provides validation functions:

- `validate_int($value, $min, $max)` - Validates integer input
- `validate_email($email)` - Validates email format
- `validate_length($input, $min, $max)` - Validates string length
- `detect_sql_injection($input)` - Detects SQL injection patterns

### Login Validation
Email and password are validated before authentication:
- Email format validation
- Password length validation (6-128 characters)

## Security Logging

### Event Logging
Security events can be logged using:
```php
log_security_event($event, $context)
```

Logs are stored in `logs/security.log` with:
- Timestamp
- Event type
- Context data
- IP address
- User agent

## Database Security

### Connection Security
- Database credentials stored in config file (not in code)
- UTF-8 encoding for all database connections
- PDO with error handling enabled

### Query Security
- All user input uses prepared statements
- No direct string concatenation in SQL queries
- Type casting for integer parameters

## Additional Security Measures

### Secure Token Generation
```php
generate_secure_token($length = 32)
```
Generates cryptographically secure random tokens using `random_bytes()`.

### Same Origin Check
```php
is_same_origin()
```
Verifies that requests originate from the same domain.

## Best Practices Implemented

1. **Never trust user input** - All input is validated and sanitized
2. **Use prepared statements** - All database queries with user input use PDO prepared statements
3. **Escape output** - All user-generated content is escaped before display
4. **Use HTTPS** - Secure cookies enabled (requires HTTPS in production)
5. **Implement rate limiting** - Prevents brute force attacks
6. **Use strong password hashing** - bcrypt with automatic rehashing
7. **Implement CSRF protection** - All forms use CSRF tokens
8. **Set security headers** - HTTP headers protect against common attacks
9. **Log security events** - Enables audit trail and incident response
10. **Principle of least privilege** - Role-based access control

## Recommendations for Production

1. **Enable HTTPS** - Set `secure_cookies` to `true` and use SSL certificate
2. **Change default passwords** - Update database credentials through environment variables or `includes/config.local.php`
3. **Restrict database access** - Use dedicated database user with limited privileges
4. **Implement file upload validation** - If file uploads are added, validate file types
5. **Regular security updates** - Keep PHP and dependencies updated
6. **Monitor logs** - Regularly review security logs for suspicious activity
7. **Backup strategy** - Implement regular database backups
8. **Environment variables** - Prefer `DB_*` and `APP_*` environment variables for sensitive config
9. **Disable debug mode** - Keep `APP_DEBUG=false` or `debug => false` in production
10. **Implement 2FA** - Consider two-factor authentication for admin accounts
11. **Supabase Data API bloqueada** - As tabelas usam RLS sem policies; o acesso ocorre apenas pelo backend PHP

## Security Checklist

- [x] SQL injection protection via prepared statements
- [x] XSS protection via output escaping
- [x] CSRF protection via tokens
- [x] Password hashing with bcrypt
- [x] Session security (secure cookies, HttpOnly, SameSite)
- [x] Rate limiting for login
- [x] Security headers implemented
- [x] Input validation functions
- [x] Role-based access control
- [x] Security event logging
- [x] Secure token generation
- [ ] HTTPS enabled (requires SSL certificate)
- [ ] File upload validation (if applicable)
