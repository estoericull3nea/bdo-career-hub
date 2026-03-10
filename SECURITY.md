# Security Implementation Documentation

## Job Posting Manager Plugin - Comprehensive Security Guide

This document provides a detailed overview of all security measures implemented in the Job Posting Manager plugin. The plugin follows WordPress security best practices, OWASP guidelines, and implements multi-layered security defenses.

---

## Table of Contents

1. [Security Architecture](#security-architecture)
2. [Rate Limiting](#rate-limiting)
3. [Input Validation & Sanitization](#input-validation--sanitization)
4. [Output Escaping & XSS Prevention](#output-escaping--xss-prevention)
5. [SQL Injection Prevention](#sql-injection-prevention)
6. [CSRF Protection](#csrf-protection)
7. [File Upload Security](#file-upload-security)
8. [Authentication & Authorization](#authentication--authorization)
9. [Security Logging & Monitoring](#security-logging--monitoring)
10. [Data Protection](#data-protection)
11. [Error Handling](#error-handling)
12. [Direct Access Prevention](#direct-access-prevention)
13. [Security Utility Class](#security-utility-class)
14. [Implementation Examples](#implementation-examples)
15. [Security Best Practices](#security-best-practices)

---

## Security Architecture

The plugin implements a **defense-in-depth** security strategy with multiple layers of protection:

- **Layer 1:** Input validation and sanitization at entry points
- **Layer 2:** Rate limiting to prevent abuse
- **Layer 3:** Nonce verification for CSRF protection
- **Layer 4:** Capability checks for authorization
- **Layer 5:** Prepared statements for SQL injection prevention
- **Layer 6:** Output escaping for XSS prevention
- **Layer 7:** Security logging for monitoring and forensics

### Security Class

All security functions are centralized in the `JPM_Security` class located at:
```
includes/core/class-jpm-security.php
```

This class provides:
- Rate limiting functionality
- Input validation helpers
- File upload validation
- Nonce verification with logging
- Security event logging
- Token generation
- Client IP detection

---

## Rate Limiting

Rate limiting prevents brute force attacks, abuse, and DoS attempts by limiting the number of requests per time window.

### Configuration

Rate limits are configured in `JPM_Security::$rate_limits`:

```php
private static $rate_limits = [
    'login' => ['limit' => 5, 'window' => 900],           // 5 attempts per 15 minutes
    'otp_send' => ['limit' => 5, 'window' => 300],      // 5 attempts per 5 minutes
    'otp_verify' => ['limit' => 10, 'window' => 300],    // 10 attempts per 5 minutes
    'application' => ['limit' => 10, 'window' => 3600],  // 10 applications per hour
    'password_reset' => ['limit' => 3, 'window' => 3600], // 3 attempts per hour
    'api_general' => ['limit' => 100, 'window' => 60],    // 100 requests per minute
];
```

**Note:** Registration is intentionally not rate-limited to avoid blocking legitimate sign-ups. Use external tools (WAF, CAPTCHA, or firewall rules) if registration throttling is needed.

### Implementation Details

#### How It Works

1. **Identifier Detection:** Uses client IP address (with proxy support) or user ID
2. **Cache Key Generation:** Creates unique transient key: `jpm_rate_limit_{action}_{md5(identifier)}`
3. **Attempt Tracking:** Stores attempt count in WordPress transients with expiration
4. **Limit Enforcement:** Blocks requests when limit exceeded
5. **Reset Time:** Returns reset time for user-friendly error messages

#### IP Address Detection

The system intelligently detects client IP addresses, handling various proxy configurations:

```php
public static function get_client_ip()
{
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_REAL_IP',         // Nginx proxy
        'HTTP_X_FORWARDED_FOR',   // Standard proxy header
        'REMOTE_ADDR'             // Direct connection
    ];
    
    // Validates IP and handles comma-separated IPs
    // Filters out private/reserved ranges
}
```

#### Usage Example

```php
// Check rate limit before processing
$rate_limit = JPM_Security::check_rate_limit('login');

if (!$rate_limit['allowed']) {
    $reset_time = date('i:s', $rate_limit['reset_time'] - time());
    wp_send_json_error([
        'message' => sprintf(
            __('Too many login attempts. Please try again in %s.', 'job-posting-manager'),
            $reset_time
        )
    ]);
    return;
}
```

### Rate Limits by Action

| Action | Limit | Window | Purpose |
|--------|-------|--------|---------|
| Login | 5 | 15 minutes | Prevent brute force attacks |
| OTP Send | 5 | 5 minutes | Prevent email spam |
| OTP Verify | 10 | 5 minutes | Allow reasonable verification attempts |
| Application | 10 | 1 hour | Prevent application spam |
| Password Reset | 3 | 1 hour | Prevent reset abuse |
| API General | 100 | 1 minute | General API throttling |

### Transient Storage

Rate limit data is stored in WordPress transients:
- **Key Format:** `jpm_rate_limit_{action}_{md5(identifier)}`
- **Value:** Integer count of attempts
- **Expiration:** Matches the time window
- **Storage:** WordPress options table (or object cache if available)

---

## Input Validation & Sanitization

All user input is validated and sanitized before processing or storage. The plugin uses WordPress's built-in sanitization functions combined with custom validation logic.

### Validation Methods

#### 1. Email Validation

```php
public static function validate_email($email)
{
    // 1. Check if empty
    if (empty($email)) return false;
    
    // 2. Sanitize email
    $email = sanitize_email($email);
    
    // 3. Validate format
    if (!is_email($email)) return false;
    
    // 4. Check length (RFC 5321 limit)
    if (strlen($email) > 254) return false;
    
    return $email;
}
```

**Features:**
- Uses WordPress `sanitize_email()` function
- Validates format with `is_email()`
- Enforces RFC 5321 maximum length (254 characters)
- Returns `false` for invalid emails

**Usage:**
```php
$email = JPM_Security::validate_email($_POST['email'] ?? '');
if (!$email) {
    wp_send_json_error(['message' => __('Invalid email address.', 'job-posting-manager')]);
}
```

#### 2. Text Input Validation

```php
public static function validate_text($input, $max_length = 255, $allow_html = false)
{
    if (empty($input)) return '';
    
    // Sanitize based on HTML allowance
    if ($allow_html) {
        $input = wp_kses_post($input);  // Allows safe HTML
    } else {
        $input = sanitize_text_field($input);  // Strips all HTML
    }
    
    // Enforce maximum length
    if (strlen($input) > $max_length) {
        $input = substr($input, 0, $max_length);
    }
    
    return $input;
}
```

**Features:**
- Configurable maximum length
- Optional HTML allowance (with `wp_kses_post()` sanitization)
- Automatic truncation if exceeds limit
- Uses `sanitize_text_field()` for plain text

**Usage:**
```php
// Plain text (default)
$name = JPM_Security::validate_text($_POST['name'] ?? '', 50);

// HTML allowed
$description = JPM_Security::validate_text($_POST['description'] ?? '', 500, true);
```

#### 3. Textarea Validation

```php
public static function validate_textarea($input, $max_length = 10000)
{
    if (empty($input)) return '';
    
    // Preserves line breaks
    $input = sanitize_textarea_field($input);
    
    // Enforce maximum length
    if (strlen($input) > $max_length) {
        $input = substr($input, 0, $max_length);
    }
    
    return $input;
}
```

**Features:**
- Preserves line breaks (unlike `sanitize_text_field()`)
- Configurable maximum length
- Default limit: 10,000 characters

**Usage:**
```php
$cover_letter = JPM_Security::validate_textarea($_POST['cover_letter'] ?? '', 5000);
```

#### 4. Integer Validation

```php
public static function validate_int($input, $min = null, $max = null)
{
    // Use PHP filter_var for validation
    $input = filter_var($input, FILTER_VALIDATE_INT);
    
    if ($input === false) return false;
    
    // Check minimum value
    if ($min !== null && $input < $min) return false;
    
    // Check maximum value
    if ($max !== null && $input > $max) return false;
    
    return $input;
}
```

**Features:**
- Uses PHP `FILTER_VALIDATE_INT`
- Optional min/max bounds
- Returns `false` for invalid values

**Usage:**
```php
// Validate job ID (must be positive)
$job_id = JPM_Security::validate_int($_POST['job_id'] ?? 0, 1);
if (!$job_id) {
    wp_send_json_error(['message' => __('Invalid job ID.', 'job-posting-manager')]);
}

// Validate boolean-like integer
$remember = JPM_Security::validate_int($_POST['remember'], 0, 1) === 1;
```

#### 5. URL Validation

```php
public static function validate_url($url)
{
    if (empty($url)) return false;
    
    // Sanitize URL
    $url = esc_url_raw($url);
    
    // Validate format
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    
    // Check allowed protocols
    $allowed_protocols = ['http', 'https', 'mailto'];
    $parsed = parse_url($url);
    if (!isset($parsed['scheme']) || 
        !in_array(strtolower($parsed['scheme']), $allowed_protocols)) {
        return false;
    }
    
    return $url;
}
```

**Features:**
- Validates URL format
- Whitelist of allowed protocols (http, https, mailto)
- Prevents dangerous protocols (javascript:, data:, etc.)

**Usage:**
```php
$redirect_url = JPM_Security::validate_url($_POST['redirect_url'] ?? '');
```

#### 6. JSON Validation

```php
public static function validate_json($json, $max_depth = 10)
{
    if (empty($json)) return false;
    
    // Prevent DoS attacks with large JSON
    if (strlen($json) > 1000000) return false;  // 1MB limit
    
    // Decode with depth limit
    $decoded = json_decode($json, true, $max_depth);
    
    if (json_last_error() !== JSON_ERROR_NONE) return false;
    
    return is_array($decoded) ? $decoded : false;
}
```

**Features:**
- Size limit to prevent DoS (1MB)
- Depth limit to prevent stack overflow
- Validates JSON structure
- Returns array or `false`

**Usage:**
```php
$form_fields = JPM_Security::validate_json($_POST['form_fields_json'] ?? '');
if (!$form_fields) {
    wp_send_json_error(['message' => __('Invalid form data.', 'job-posting-manager')]);
}
```

### Array Sanitization

For complex form data, use the `sanitize_array()` method with a schema:

```php
$schema = [
    'first_name' => ['type' => 'text', 'max_length' => 50],
    'email' => ['type' => 'email'],
    'age' => ['type' => 'int', 'min' => 18, 'max' => 100],
    'bio' => ['type' => 'textarea', 'max_length' => 1000],
    'website' => ['type' => 'url'],
    'tags' => ['type' => 'array'],
];

$sanitized = JPM_Security::sanitize_array($_POST, $schema);
```

### WordPress Sanitization Functions Used

| Function | Purpose | Used For |
|----------|---------|----------|
| `sanitize_text_field()` | Removes HTML, normalizes whitespace | Text inputs |
| `sanitize_textarea_field()` | Preserves line breaks | Textarea inputs |
| `sanitize_email()` | Validates and sanitizes email | Email addresses |
| `sanitize_file_name()` | Removes dangerous characters | File names |
| `wp_kses_post()` | Allows safe HTML tags | Rich text content |
| `esc_url_raw()` | Sanitizes URL for database | URLs |
| `intval()` | Converts to integer | Numeric IDs |
| `absint()` | Converts to positive integer | Positive IDs |

---

## Output Escaping & XSS Prevention

All output is escaped using appropriate WordPress escaping functions to prevent Cross-Site Scripting (XSS) attacks.

### Escaping Functions

#### 1. HTML Content Escaping

```php
// Escapes HTML entities
echo esc_html($company_name);

// Example output:
// Input:  <script>alert('XSS')</script>
// Output: &lt;script&gt;alert(&#039;XSS&#039;)&lt;/script&gt;
```

**When to use:**
- Displaying user-generated text content
- Outputting data in HTML context
- Preventing script injection

#### 2. HTML Attribute Escaping

```php
// Escapes HTML attributes
<div data-job-id="<?php echo esc_attr($job->ID); ?>">
<input value="<?php echo esc_attr($user_input); ?>">
```

**When to use:**
- HTML attribute values
- Data attributes
- Form input values

#### 3. URL Escaping

```php
// Validates and escapes URLs
<a href="<?php echo esc_url($job_link); ?>">View Job</a>
<img src="<?php echo esc_url($image_url); ?>" alt="">

// For database storage
$url = esc_url_raw($user_url);
```

**When to use:**
- Links (`<a href="">`)
- Image sources (`<img src="">`)
- Form actions
- Redirect URLs

**Difference:**
- `esc_url()` - For display (adds `http://` if missing)
- `esc_url_raw()` - For database storage (preserves original)

#### 4. JavaScript Escaping

```php
// Escapes strings for JavaScript
<script>
var message = <?php echo esc_js($user_message); ?>;
</script>
```

**When to use:**
- JavaScript variables
- Inline JavaScript
- JSON data in JavaScript

#### 5. Textarea Escaping

```php
// Escapes textarea content
<textarea><?php echo esc_textarea($content); ?></textarea>
```

**When to use:**
- Textarea content
- Pre-formatted text display

#### 6. Allowed HTML Filtering

```php
// Allows safe HTML tags only
echo wp_kses_post($rich_content);

// Custom allowed tags
$allowed = [
    'p' => [],
    'strong' => [],
    'em' => [],
    'a' => ['href' => [], 'title' => []],
];
echo wp_kses($content, $allowed);
```

**When to use:**
- Rich text content
- User-generated HTML
- Preserving formatting while removing scripts

### Context-Specific Escaping

The plugin uses context-appropriate escaping throughout:

```php
// HTML context
<h3><?php echo esc_html(get_the_title()); ?></h3>

// Attribute context
<div class="job-card" data-id="<?php echo esc_attr($job_id); ?>">

// URL context
<a href="<?php echo esc_url(get_permalink($job_id)); ?>">

// JavaScript context
<script>
var jobId = <?php echo esc_js($job_id); ?>;
</script>
```

### XSS Prevention Strategy

1. **Input Sanitization:** All input sanitized on receipt
2. **Output Escaping:** All output escaped before display
3. **Content Security:** HTML filtered through `wp_kses_post()`
4. **JavaScript Safety:** Proper escaping for JavaScript variables
5. **Attribute Safety:** Proper escaping for HTML attributes

---

## SQL Injection Prevention

All database queries use prepared statements via WordPress's `$wpdb->prepare()` method to prevent SQL injection attacks.

### Prepared Statements

#### Basic Prepared Statement

```php
global $wpdb;
$table = $wpdb->prefix . 'job_applications';

// Using $wpdb->prepare()
$application = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table WHERE id = %d AND user_id = %d",
    $application_id,
    $user_id
));
```

#### Type Specifiers

| Specifier | Type | Example |
|-----------|------|---------|
| `%d` | Integer | `$wpdb->prepare("WHERE id = %d", $id)` |
| `%s` | String | `$wpdb->prepare("WHERE name = %s", $name)` |
| `%f` | Float | `$wpdb->prepare("WHERE price = %f", $price)` |

#### Multiple Parameters

```php
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table 
     WHERE status = %s 
     AND application_date >= %s 
     AND job_id = %d
     ORDER BY application_date DESC",
    $status,
    $start_date,
    $job_id
));
```

### Direct Database Methods

WordPress's direct methods automatically escape values:

```php
// Insert - automatically escapes
$wpdb->insert(
    $table,
    [
        'user_id' => $user_id,           // Automatically escaped
        'job_id' => $job_id,             // Automatically escaped
        'status' => $status,             // Automatically escaped
        'notes' => sanitize_textarea_field($notes),  // Pre-sanitized
    ],
    ['%d', '%d', '%s', '%s']  // Format specifiers
);

// Update - automatically escapes
$wpdb->update(
    $table,
    ['status' => $new_status],  // Data
    ['id' => $application_id],  // Where clause
    ['%s'],                      // Format for data
    ['%d']                       // Format for where
);

// Delete - automatically escapes
$wpdb->delete(
    $table,
    ['id' => $application_id],
    ['%d']
);
```

### LIKE Queries

For LIKE queries, use `$wpdb->esc_like()`:

```php
$search_term = '%' . $wpdb->esc_like($search) . '%';
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE notes LIKE %s",
    $search_term
));
```

### IN Clauses

For IN clauses with dynamic arrays:

```php
$job_ids = [1, 2, 3, 4, 5];
$placeholders = implode(',', array_fill(0, count($job_ids), '%d'));

$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE job_id IN ($placeholders)",
    ...$job_ids  // Spread operator
));
```

### Implementation Examples

#### Example 1: Application Retrieval

```php
public static function get_application($id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'job_applications';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id = %d",
        $id
    ));
}
```

#### Example 2: Filtered Applications

```php
public static function get_applications($filters = [])
{
    global $wpdb;
    $table = $wpdb->prefix . 'job_applications';
    $where = ['1=1'];
    $where_values = [];

    if (!empty($filters['status'])) {
        $where[] = "status = %s";
        $where_values[] = $filters['status'];
    }
    
    if (!empty($filters['job_id'])) {
        $where[] = "job_id = %d";
        $where_values[] = $filters['job_id'];
    }

    $where_clause = implode(' AND ', $where);
    
    if (!empty($where_values)) {
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY application_date DESC",
            $where_values
        );
    } else {
        $query = "SELECT * FROM $table WHERE $where_clause ORDER BY application_date DESC";
    }

    return $wpdb->get_results($query);
}
```

### Security Best Practices

1. **Always use prepared statements** for user input
2. **Never concatenate user input** into SQL queries
3. **Use proper type specifiers** (%d, %s, %f)
4. **Prefer direct methods** (`insert`, `update`, `delete`) when possible
5. **Use `esc_like()`** for LIKE queries
6. **Validate input** before using in queries

---

## CSRF Protection

Cross-Site Request Forgery (CSRF) protection is implemented using WordPress nonces (number used once).

### Nonce Implementation

#### Form Nonces

```php
// Generate nonce field in form
wp_nonce_field('jpm_form_builder', 'jpm_form_builder_nonce');

// Output:
// <input type="hidden" name="jpm_form_builder_nonce" value="abc123..." />
```

#### AJAX Nonces

```php
// Generate nonce for AJAX
wp_localize_script('jpm-script', 'jpm_ajax', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('jpm_nonce'),
]);

// Verify in handler
if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'jpm_nonce', 'ajax')) {
    wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
    return;
}
```

### Enhanced Nonce Verification

The plugin uses an enhanced nonce verification method with logging:

```php
public static function verify_nonce($nonce, $action, $context = 'ajax')
{
    if (empty($nonce)) {
        return false;
    }

    // Verify nonce
    $valid = wp_verify_nonce($nonce, $action);
    
    if (!$valid) {
        // Log failed attempts
        error_log(sprintf(
            'JPM Security: Failed nonce verification - Action: %s, Context: %s, IP: %s',
            $action,
            $context,
            self::get_client_ip()
        ));
        return false;
    }

    return true;
}
```

### Nonce Actions

Different nonce actions for different operations:

| Action | Context | Used In |
|--------|---------|---------|
| `jpm_nonce` | AJAX | General AJAX requests |
| `jpm_register` | AJAX | Registration, OTP |
| `jpm_login` | AJAX | Login |
| `jpm_logout` | AJAX | Logout |
| `jpm_form_builder` | Form | Form builder |
| `jpm_application_form` | Form | Application forms |
| `jpm_update_status` | AJAX | Status updates |
| `jpm_medical_details` | AJAX | Medical details |
| `jpm_chart_nonce` | AJAX | Chart data |

### Implementation Examples

#### Example 1: AJAX Handler

```php
public function handle_application()
{
    // Verify nonce
    if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'jpm_nonce', 'ajax')) {
        wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        return;
    }
    
    // Process request...
}
```

#### Example 2: Form Submission

```php
public function save_job_meta($post_id)
{
    // Verify nonce
    if (!isset($_POST['jpm_job_nonce']) || 
        !wp_verify_nonce($_POST['jpm_job_nonce'], 'jpm_job_meta')) {
        return;
    }
    
    // Process form...
}
```

### Nonce Lifetime

- **Default:** 24 hours
- **Configurable:** Via `wp_nonce_life` filter
- **Regeneration:** On each page load for logged-in users

---

## File Upload Security

File uploads are validated and secured using multiple layers of protection.

### Validation Process

#### 1. Upload Error Checking

```php
if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $error_messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'PHP extension stopped the file upload.',
    ];
    return ['valid' => false, 'error' => $error_messages[$file['error']]];
}
```

#### 2. File Size Validation

```php
// Default: 5MB for resumes, 10MB for other files
if ($file['size'] > $max_size) {
    $max_size_mb = round($max_size / 1048576, 2);
    return [
        'valid' => false,
        'error' => sprintf('File size exceeds maximum allowed size of %s MB.', $max_size_mb)
    ];
}
```

#### 3. MIME Type Validation

```php
// Default allowed types for resumes
$allowed_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain'
];

// Double-check MIME type
if (function_exists('mime_content_type')) {
    $detected_mime = mime_content_type($file['tmp_name']);
    if ($detected_mime && $detected_mime !== $mime_type) {
        $mime_type = $detected_mime;  // Use detected type
    }
}

// Validate against allowed types
foreach ($allowed_types as $allowed_type) {
    if ($mime_type === $allowed_type || strpos($mime_type, $allowed_type) !== false) {
        $allowed = true;
        break;
    }
}
```

#### 4. File Extension Validation

```php
$file_type = wp_check_filetype($file['name']);
$allowed_extensions = ['pdf', 'doc', 'docx', 'txt'];

if (!empty($file_type['ext']) && 
    in_array(strtolower($file_type['ext']), $allowed_extensions)) {
    $allowed = true;
}
```

#### 5. Filename Sanitization

```php
// Sanitize filename
$filename = sanitize_file_name($file['name']);

// Ensure unique filename
$filename = wp_unique_filename(wp_upload_dir()['path'], $filename);
```

### Complete Validation Method

```php
public static function validate_file_upload($file, $allowed_types = [], $max_size = 5242880)
{
    // 1. Check upload errors
    // 2. Validate file size
    // 3. Validate MIME type
    // 4. Validate file extension
    // 5. Sanitize filename
    
    return [
        'valid' => true,
        'error' => null,
        'file' => [
            'name' => $filename,
            'type' => $mime_type,
            'tmp_name' => $file['tmp_name'],
            'size' => $file['size']
        ]
    ];
}
```

### Usage Example

```php
// Validate file upload
$file_validation = JPM_Security::validate_file_upload($_FILES['resume'], [], 5242880);

if (!$file_validation['valid']) {
    wp_send_json_error(['message' => $file_validation['error']]);
    return;
}

// Use validated file
$upload = wp_handle_upload($file_validation['file'], ['test_form' => false]);
```

### Security Features

1. **Error Handling:** Checks all PHP upload error codes
2. **Size Limits:** Configurable maximum file size
3. **MIME Validation:** Validates actual file content type
4. **Extension Check:** Validates file extension
5. **Filename Sanitization:** Removes dangerous characters
6. **Unique Filenames:** Prevents overwriting existing files
7. **Secure Storage:** Files stored in WordPress uploads directory

### Allowed File Types

| Type | MIME Type | Extension | Max Size |
|------|-----------|-----------|----------|
| PDF | `application/pdf` | `.pdf` | 5-10 MB |
| Word Doc | `application/msword` | `.doc` | 5-10 MB |
| Word Docx | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | `.docx` | 5-10 MB |
| Text | `text/plain` | `.txt` | 5-10 MB |

---

## Authentication & Authorization

The plugin implements proper authentication and authorization checks.

### Capability Checks

#### Admin Operations

```php
// Check if user can manage options
if (!JPM_Security::check_capability('manage_options')) {
    wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
    return;
}
```

#### Post-Specific Capabilities

```php
// Check if user can edit specific post
if (!JPM_Security::check_capability('edit_post', $post_id)) {
    return;
}
```

### Implementation

```php
public static function check_capability($capability, $object_id = null)
{
    if ($object_id !== null) {
        return current_user_can($capability, $object_id);
    }
    return current_user_can($capability);
}
```

### Required Capabilities

| Operation | Capability | Context |
|-----------|------------|---------|
| Update Application Status | `manage_options` | Admin only |
| Save Medical Details | `manage_options` | Admin only |
| Edit Job Posting | `edit_post` | Post-specific |
| View Applications | `read` | User-specific |
| Submit Application | None (public) | Public action |

### User Authentication

#### Login Security

```php
public function handle_login()
{
    // 1. Verify nonce
    // 2. Check rate limit
    // 3. Validate inputs
    // 4. Find user
    // 5. Verify password
    // 6. Log failed attempts
    // 7. Set auth cookie
}
```

#### Password Verification

```php
if (!wp_check_password($password, $user->user_pass, $user->ID)) {
    // Log failed attempt
    JPM_Security::log_security_event('failed_login', 'Failed login attempt', [
        'login' => $login,
        'user_id' => $user->ID
    ]);
    wp_send_json_error(['message' => __('Invalid credentials.', 'job-posting-manager')]);
    return;
}
```

### Session Management

- Uses WordPress's secure session management
- Secure cookies via WordPress settings
- Session regeneration on login
- Proper logout handling

---

## Security Logging & Monitoring

Security events are logged for monitoring and forensic analysis.

### Logging Method

```php
public static function log_security_event($event, $message, $context = [])
{
    $log_data = [
        'timestamp' => current_time('mysql'),
        'event' => sanitize_text_field($event),
        'message' => sanitize_text_field($message),
        'ip' => self::get_client_ip(),
        'user_id' => get_current_user_id(),
        'context' => $context
    ];

    error_log('JPM Security Event: ' . json_encode($log_data));
}
```

### Logged Events

| Event Type | Trigger | Information Logged |
|------------|---------|-------------------|
| `failed_login` | Failed login attempt | IP, username, user ID |
| `failed_nonce` | Failed nonce verification | IP, action, context |
| `file_upload_error` | File upload failure | IP, error message, field |
| `rate_limit_exceeded` | Rate limit hit | IP, action, reset time |

### Log Format

```json
{
    "timestamp": "2024-01-15 10:30:45",
    "event": "failed_login",
    "message": "Failed login attempt",
    "ip": "192.168.1.100",
    "user_id": 0,
    "context": {
        "login": "user@example.com",
        "user_id": 123
    }
}
```

### Usage Examples

```php
// Log failed login
JPM_Security::log_security_event('failed_login', 'Failed login attempt', [
    'login' => $login,
    'user_id' => $user->ID
]);

// Log file upload error
JPM_Security::log_security_event('file_upload_error', 'Failed to upload file', [
    'error' => $upload['error'],
    'field' => $field_name
]);
```

### Log Storage

- **Location:** WordPress debug log (if `WP_DEBUG_LOG` enabled)
- **Format:** JSON encoded strings
- **Retention:** Managed by WordPress/server configuration
- **Privacy:** Contains IP addresses and user data (GDPR considerations)

---

## Data Protection

Sensitive data is protected through multiple mechanisms.

### Personal Information Protection

#### Email Addresses

```php
// Normalize and validate
$email = strtolower(trim($email));
$email = JPM_Security::validate_email($email);

// Store sanitized
update_user_meta($user_id, 'email', $email);
```

#### Form Data

```php
// Sanitize all form fields
foreach ($form_fields as $field) {
    $field_name = sanitize_text_field($field['name']);
    $field_value = sanitize_text_field($field['value']);
    $form_data[$field_name] = $field_value;
}

// Store as JSON
$notes = json_encode($form_data);
```

### Database Storage

- All data sanitized before storage
- Uses WordPress database methods (automatic escaping)
- JSON data validated before encoding
- Unique constraints prevent duplicates

### Transient Security

```php
// Unique, time-limited keys
$transient_key = 'jpm_otp_' . md5($email);
set_transient($transient_key, $otp, 10 * MINUTE_IN_SECONDS);

// Email verification
$verified_key = 'jpm_otp_verified_' . md5($email);
set_transient($verified_key, $email, 10 * MINUTE_IN_SECONDS);
```

### Password Security

- Never stored in plain text
- Uses WordPress password hashing (`wp_hash_password()`)
- Password verification via `wp_check_password()`
- Minimum length enforced (8 characters)
- Maximum length enforced (128 characters)

### GDPR Considerations

- Data export capability
- Data deletion capability
- User consent handling
- Privacy policy compliance

---

## Error Handling

Errors are handled securely without exposing sensitive information.

### Secure Error Messages

```php
// Generic user-facing error
wp_send_json_error(['message' => __('Invalid credentials.', 'job-posting-manager')]);

// Detailed error logged server-side
error_log('JPM: Failed login - User: ' . $login . ', IP: ' . $ip);
```

### Error Handling Strategy

1. **User-Facing:** Generic, non-revealing messages
2. **Server-Side:** Detailed logging for debugging
3. **No Stack Traces:** Never exposed to users
4. **Graceful Failures:** Operations fail safely

### Implementation Examples

```php
try {
    // Operation
    $result = JPM_Emails::send_notification($data);
} catch (Exception $e) {
    // Log error
    error_log('JPM Email Error: ' . $e->getMessage());
    
    // User-friendly message
    wp_send_json_error([
        'message' => __('Failed to send notification. Please try again.', 'job-posting-manager')
    ]);
}
```

---

## Direct Access Prevention

All plugin files prevent direct access.

### ABSPATH Check

```php
<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
```

### File Protection

- All PHP files include ABSPATH check
- Prevents direct file access
- Forces execution through WordPress

---

## Security Utility Class

The `JPM_Security` class provides centralized security functions.

### Available Methods

| Method | Purpose | Returns |
|--------|---------|---------|
| `check_rate_limit()` | Check rate limit for action | Array with allowed status |
| `get_client_ip()` | Get client IP address | String IP |
| `validate_email()` | Validate and sanitize email | String or false |
| `validate_text()` | Validate and sanitize text | String |
| `validate_textarea()` | Validate and sanitize textarea | String |
| `validate_int()` | Validate integer | Integer or false |
| `validate_url()` | Validate URL | String or false |
| `validate_file_upload()` | Validate file upload | Array with validation result |
| `verify_nonce()` | Verify nonce with logging | Boolean |
| `sanitize_array()` | Sanitize array with schema | Array |
| `check_capability()` | Check user capability | Boolean |
| `log_security_event()` | Log security event | Void |
| `generate_token()` | Generate secure token | String |
| `validate_json()` | Validate JSON input | Array or false |

### Usage Pattern

```php
// 1. Verify nonce
if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'action', 'ajax')) {
    wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
    return;
}

// 2. Check rate limit
$rate_limit = JPM_Security::check_rate_limit('action');
if (!$rate_limit['allowed']) {
    wp_send_json_error(['message' => __('Too many requests.', 'job-posting-manager')]);
    return;
}

// 3. Validate inputs
$email = JPM_Security::validate_email($_POST['email'] ?? '');
if (!$email) {
    wp_send_json_error(['message' => __('Invalid email.', 'job-posting-manager')]);
    return;
}

// 4. Check capability (if needed)
if (!JPM_Security::check_capability('manage_options')) {
    wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
    return;
}

// 5. Process request
// ...
```

---

## Implementation Examples

### Example 1: Secure AJAX Handler

```php
public function handle_application()
{
    // 1. Verify nonce
    if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'jpm_nonce', 'ajax')) {
        wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        return;
    }

    // 2. Check rate limit
    $rate_limit = JPM_Security::check_rate_limit('application');
    if (!$rate_limit['allowed']) {
        $reset_time = date('i:s', $rate_limit['reset_time'] - time());
        wp_send_json_error([
            'message' => sprintf(__('Too many applications. Please try again in %s.', 'job-posting-manager'), $reset_time)
        ]);
        return;
    }

    // 3. Check authentication
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Please log in.', 'job-posting-manager')]);
        return;
    }

    // 4. Validate inputs
    $job_id = JPM_Security::validate_int($_POST['job_id'] ?? 0, 1);
    if (!$job_id) {
        wp_send_json_error(['message' => __('Invalid job posting.', 'job-posting-manager')]);
        return;
    }

    // 5. Verify job exists
    $job = get_post($job_id);
    if (!$job || $job->post_type !== 'job_posting' || $job->post_status !== 'publish') {
        wp_send_json_error(['message' => __('Job posting not found.', 'job-posting-manager')]);
        return;
    }

    // 6. Validate file upload
    $file_validation = JPM_Security::validate_file_upload($_FILES['resume'], [], 5242880);
    if (!$file_validation['valid']) {
        wp_send_json_error(['message' => $file_validation['error']]);
        return;
    }

    // 7. Process upload
    $upload = wp_handle_upload($file_validation['file'], ['test_form' => false]);
    if (isset($upload['error'])) {
        JPM_Security::log_security_event('file_upload_error', 'Failed to upload resume', [
            'error' => $upload['error']
        ]);
        wp_send_json_error(['message' => __('Failed to upload file.', 'job-posting-manager')]);
        return;
    }

    // 8. Insert application
    $result = JPM_Database::insert_application(
        get_current_user_id(),
        $job_id,
        $upload['file'],
        JPM_Security::validate_textarea($_POST['cover_letter'] ?? '', 5000)
    );

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
        return;
    }

    // 9. Success response
    wp_send_json_success([
        'message' => __('Application submitted successfully!', 'job-posting-manager'),
        'application_id' => $result
    ]);
}
```

### Example 2: Secure Login Handler

```php
public function handle_login()
{
    // 1. Verify nonce
    if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'jpm_login', 'ajax')) {
        wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        return;
    }

    // 2. Check rate limit
    $rate_limit = JPM_Security::check_rate_limit('login');
    if (!$rate_limit['allowed']) {
        $reset_time = date('i:s', $rate_limit['reset_time'] - time());
        wp_send_json_error([
            'message' => sprintf(__('Too many login attempts. Please try again in %s.', 'job-posting-manager'), $reset_time)
        ]);
        return;
    }

    // 3. Validate inputs
    $login = JPM_Security::validate_text($_POST['email'] ?? '', 100);
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        wp_send_json_error(['message' => __('Please enter your credentials.', 'job-posting-manager')]);
        return;
    }

    // 4. Prevent password length attacks
    if (strlen($password) > 128) {
        wp_send_json_error(['message' => __('Invalid credentials.', 'job-posting-manager')]);
        return;
    }

    // 5. Find user
    $user = null;
    if (is_email($login)) {
        $user = get_user_by('email', $login);
    }
    if (!$user) {
        $user = get_user_by('login', $login);
    }

    if (!$user) {
        wp_send_json_error(['message' => __('Invalid credentials.', 'job-posting-manager')]);
        return;
    }

    // 6. Verify password
    if (!wp_check_password($password, $user->user_pass, $user->ID)) {
        JPM_Security::log_security_event('failed_login', 'Failed login attempt', [
            'login' => $login,
            'user_id' => $user->ID
        ]);
        wp_send_json_error(['message' => __('Invalid credentials.', 'job-posting-manager')]);
        return;
    }

    // 7. Set authentication
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, $remember);

    // 8. Success response
    wp_send_json_success([
        'message' => __('Login successful!', 'job-posting-manager'),
        'redirect_url' => $redirect_url
    ]);
}
```

### Example 3: Secure Admin Handler

```php
public function update_application_status()
{
    // 1. Verify nonce
    if (!JPM_Security::verify_nonce($_POST['nonce'] ?? '', 'jpm_update_status', 'ajax')) {
        wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        return;
    }

    // 2. Check capability
    if (!JPM_Security::check_capability('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
        return;
    }

    // 3. Validate inputs
    $application_id = JPM_Security::validate_int($_POST['application_id'] ?? 0, 1);
    $status = JPM_Security::validate_text($_POST['status'] ?? '', 50);

    if (!$application_id || !$status) {
        wp_send_json_error(['message' => __('Invalid data.', 'job-posting-manager')]);
        return;
    }

    // 4. Verify application exists
    $application = JPM_Database::get_application($application_id);
    if (!$application) {
        wp_send_json_error(['message' => __('Application not found.', 'job-posting-manager')]);
        return;
    }

    // 5. Update status
    $result = JPM_Database::update_status($application_id, $status);

    if ($result !== false) {
        wp_send_json_success(['message' => __('Status updated successfully.', 'job-posting-manager')]);
    } else {
        wp_send_json_error(['message' => __('Failed to update status.', 'job-posting-manager')]);
    }
}
```

---

## Security Best Practices

### 1. Never Trust User Input

- ✅ Always sanitize input on receipt
- ✅ Validate input format and type
- ✅ Enforce length limits
- ❌ Never use raw `$_POST`, `$_GET`, or `$_REQUEST`

### 2. Always Escape Output

- ✅ Use context-appropriate escaping functions
- ✅ Escape HTML content with `esc_html()`
- ✅ Escape attributes with `esc_attr()`
- ✅ Escape URLs with `esc_url()`
- ❌ Never output raw user data

### 3. Use Prepared Statements

- ✅ Always use `$wpdb->prepare()` for queries
- ✅ Use proper type specifiers (%d, %s, %f)
- ✅ Prefer direct methods (insert, update, delete)
- ❌ Never concatenate user input into SQL

### 4. Verify Nonces

- ✅ Verify nonces on all forms and AJAX requests
- ✅ Use unique nonce actions for different operations
- ✅ Log failed nonce attempts
- ❌ Never skip nonce verification

### 5. Check Capabilities

- ✅ Verify user capabilities before admin operations
- ✅ Use post-specific capabilities when applicable
- ✅ Check capabilities, not user roles
- ❌ Never assume user permissions

### 6. Validate File Uploads

- ✅ Check upload errors
- ✅ Validate file size
- ✅ Validate MIME type and extension
- ✅ Sanitize filenames
- ❌ Never trust file metadata

### 7. Implement Rate Limiting

- ✅ Rate limit sensitive operations
- ✅ Use IP-based tracking
- ✅ Provide user-friendly error messages
- ❌ Don't rate limit too aggressively

### 8. Handle Errors Securely

- ✅ Use generic error messages for users
- ✅ Log detailed errors server-side
- ✅ Never expose stack traces
- ❌ Never reveal system information

### 9. Follow WordPress Standards

- ✅ Use WordPress security functions
- ✅ Follow WordPress coding standards
- ✅ Use WordPress hooks and filters
- ❌ Don't reinvent security functions

### 10. Keep Security Updated

- ✅ Stay updated with WordPress security updates
- ✅ Monitor security logs regularly
- ✅ Review and update security measures
- ❌ Don't ignore security warnings

---

## Security Checklist

Use this checklist when implementing new features:

- [ ] Input validation and sanitization implemented
- [ ] Output escaping applied
- [ ] Nonce verification added
- [ ] Capability checks included (if needed)
- [ ] Rate limiting considered
- [ ] File uploads validated (if applicable)
- [ ] SQL queries use prepared statements
- [ ] Error handling implemented securely
- [ ] Security logging added (if applicable)
- [ ] Direct access prevention included

---

## Conclusion

The Job Posting Manager plugin implements comprehensive security measures following WordPress best practices and OWASP guidelines. All security functions are centralized in the `JPM_Security` class, ensuring consistent security implementation across the plugin.

For questions or security concerns, please contact the development team or report issues through the appropriate channels.

---

**Last Updated:** 2024-01-15  
**Version:** 1.0.0  
**Security Class:** `includes/core/class-jpm-security.php`
