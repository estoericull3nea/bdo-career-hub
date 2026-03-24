<?php
/**
 * Security Utilities Class
 * 
 * Provides rate limiting, input validation, sanitization, and other security features
 */
class JPM_Security
{
    /**
     * Rate limit configuration
     *
     * Note: Registration is intentionally not rate-limited at this layer
     * to avoid blocking legitimate sign-ups. Use external tools (e.g. WAF,
     * CAPTCHA, or firewall rules) if you want to throttle registrations.
     */
    private static $rate_limits = [
        'login' => ['limit' => 5, 'window' => 900], // 5 attempts per 15 minutes
        // 'register' => ['limit' => 3, 'window' => 3600], // Removed registration rate limit
        'otp_send' => ['limit' => 5, 'window' => 300], // 5 attempts per 5 minutes
        'otp_verify' => ['limit' => 10, 'window' => 300], // 10 attempts per 5 minutes
        'application' => ['limit' => 10, 'window' => 3600], // 10 applications per hour
        'password_reset' => ['limit' => 3, 'window' => 3600], // 3 attempts per hour
        'api_general' => ['limit' => 100, 'window' => 60], // 100 requests per minute
    ];

    /**
     * Check rate limit for an action
     * 
     * @param string $action Action name (e.g., 'login', 'register')
     * @param string $identifier User identifier (IP address or user ID)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_time' => int]
     */
    public static function check_rate_limit($action, $identifier = null)
    {
        if (!isset(self::$rate_limits[$action])) {
            return ['allowed' => true, 'remaining' => 999, 'reset_time' => 0];
        }

        $limit_config = self::$rate_limits[$action];
        $limit = $limit_config['limit'];
        $window = $limit_config['window'];

        // Use IP address if identifier not provided
        if ($identifier === null) {
            $identifier = self::get_client_ip();
        }

        // Create unique key for this action and identifier
        $cache_key = 'jpm_rate_limit_' . $action . '_' . md5($identifier);
        
        // Get current attempts
        $attempts = get_transient($cache_key);
        if ($attempts === false) {
            $attempts = 0;
        }

        // Check if limit exceeded
        if ($attempts >= $limit) {
            $reset_time = get_option('_transient_timeout_' . $cache_key);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_time' => $reset_time ?: (time() + $window)
            ];
        }

        // Increment attempts
        $attempts++;
        set_transient($cache_key, $attempts, $window);

        return [
            'allowed' => true,
            'remaining' => $limit - $attempts,
            'reset_time' => time() + $window
        ];
    }

    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    public static function get_client_ip()
    {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP', // Nginx proxy
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    }

    /**
     * Validate and sanitize email
     * 
     * @param string $email Email address
     * @return string|false Sanitized email or false if invalid
     */
    public static function validate_email($email)
    {
        if (empty($email)) {
            return false;
        }
        
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return false;
        }
        
        // Additional validation: check length
        if (strlen($email) > 254) {
            return false;
        }
        
        return $email;
    }

    /**
     * Validate and sanitize text input
     * 
     * @param string $input Input string
     * @param int $max_length Maximum length
     * @param bool $allow_html Allow HTML (will be sanitized)
     * @return string Sanitized string
     */
    public static function validate_text($input, $max_length = 255, $allow_html = false)
    {
        if (empty($input)) {
            return '';
        }

        if ($allow_html) {
            $input = wp_kses_post($input);
        } else {
            $input = sanitize_text_field($input);
        }

        // Enforce max length
        if (strlen($input) > $max_length) {
            $input = substr($input, 0, $max_length);
        }

        return $input;
    }

    /**
     * Validate and sanitize textarea input
     * 
     * @param string $input Input string
     * @param int $max_length Maximum length
     * @return string Sanitized string
     */
    public static function validate_textarea($input, $max_length = 10000)
    {
        if (empty($input)) {
            return '';
        }

        $input = sanitize_textarea_field($input);

        // Enforce max length
        if (strlen($input) > $max_length) {
            $input = substr($input, 0, $max_length);
        }

        return $input;
    }

    /**
     * Validate integer input
     * 
     * @param mixed $input Input value
     * @param int $min Minimum value
     * @param int $max Maximum value
     * @return int|false Validated integer or false
     */
    public static function validate_int($input, $min = null, $max = null)
    {
        $input = filter_var($input, FILTER_VALIDATE_INT);
        
        if ($input === false) {
            return false;
        }

        if ($min !== null && $input < $min) {
            return false;
        }

        if ($max !== null && $input > $max) {
            return false;
        }

        return $input;
    }

    /**
     * Validate URL
     * 
     * @param string $url URL string
     * @return string|false Validated URL or false
     */
    public static function validate_url($url)
    {
        if (empty($url)) {
            return false;
        }

        $url = esc_url_raw($url);
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Ensure URL uses allowed protocols
        $allowed_protocols = ['http', 'https', 'mailto'];
        $parsed = wp_parse_url($url);
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), $allowed_protocols)) {
            return false;
        }

        return $url;
    }

    /**
     * Validate file upload
     * 
     * @param array $file $_FILES array element
     * @param array $allowed_types Allowed MIME types
     * @param int $max_size Maximum file size in bytes
     * @return array ['valid' => bool, 'error' => string|null, 'file' => array|null]
     */
    public static function validate_file_upload($file, $allowed_types = [], $max_size = 5242880)
    {
        // Default allowed types for resumes
        if (empty($allowed_types)) {
            $allowed_types = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain'
            ];
        }

        // Check for upload errors
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('File exceeds upload_max_filesize directive.', 'job-posting-manager'),
                UPLOAD_ERR_FORM_SIZE => __('File exceeds MAX_FILE_SIZE directive.', 'job-posting-manager'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded.', 'job-posting-manager'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'job-posting-manager'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder.', 'job-posting-manager'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'job-posting-manager'),
                UPLOAD_ERR_EXTENSION => __('PHP extension stopped the file upload.', 'job-posting-manager'),
            ];
            $error = $error_messages[$file['error']] ?? __('Unknown upload error.', 'job-posting-manager');
            return ['valid' => false, 'error' => $error, 'file' => null];
        }

        // Check file size
        if ($file['size'] > $max_size) {
            $max_size_mb = round($max_size / 1048576, 2);
            return [
                'valid' => false,
                /* translators: %s: Maximum allowed upload size in megabytes. */
                'error' => sprintf(__('File size exceeds maximum allowed size of %s MB.', 'job-posting-manager'), $max_size_mb),
                'file' => null
            ];
        }

        // Validate MIME type
        $file_type = wp_check_filetype($file['name']);
        $mime_type = $file['type'];
        
        // Double-check MIME type using WordPress function
        if (function_exists('mime_content_type')) {
            $detected_mime = mime_content_type($file['tmp_name']);
            if ($detected_mime && $detected_mime !== $mime_type) {
                $mime_type = $detected_mime;
            }
        }

        // Check against allowed types
        $allowed = false;
        foreach ($allowed_types as $allowed_type) {
            if ($mime_type === $allowed_type || strpos($mime_type, $allowed_type) !== false) {
                $allowed = true;
                break;
            }
        }

        // Also check file extension
        if (!$allowed && !empty($file_type['ext'])) {
            $allowed_extensions = ['pdf', 'doc', 'docx', 'txt'];
            if (in_array(strtolower($file_type['ext']), $allowed_extensions)) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            return [
                'valid' => false,
                'error' => __('File type not allowed. Please upload PDF, DOC, DOCX, or TXT files only.', 'job-posting-manager'),
                'file' => null
            ];
        }

        // Sanitize filename
        $filename = sanitize_file_name($file['name']);
        $filename = wp_unique_filename(wp_upload_dir()['path'], $filename);

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

    /**
     * Verify nonce with additional security checks
     * 
     * @param string $nonce Nonce value
     * @param string $action Nonce action
     * @param string $context Context (e.g., 'ajax', 'form')
     * @return bool True if valid
     */
    public static function verify_nonce($nonce, $action, $context = 'ajax')
    {
        if (empty($nonce)) {
            return false;
        }

        // Verify nonce
        $valid = wp_verify_nonce($nonce, $action);
        
        if (!$valid) {
            // Log failed nonce attempts
            do_action('jpm_log_error', sprintf(
                'JPM Security: Failed nonce verification - Action: %s, Context: %s, IP: %s',
                $action,
                $context,
                self::get_client_ip()
            ));
            return false;
        }

        return true;
    }

    /**
     * Sanitize array of inputs
     * 
     * @param array $data Input data
     * @param array $schema Schema defining how to sanitize each field
     * @return array Sanitized data
     */
    public static function sanitize_array($data, $schema)
    {
        $sanitized = [];

        foreach ($schema as $field => $config) {
            if (!isset($data[$field])) {
                if (isset($config['default'])) {
                    $sanitized[$field] = $config['default'];
                }
                continue;
            }

            $value = $data[$field];
            $type = $config['type'] ?? 'text';

            switch ($type) {
                case 'email':
                    $sanitized[$field] = self::validate_email($value);
                    break;
                case 'int':
                    $min = $config['min'] ?? null;
                    $max = $config['max'] ?? null;
                    $sanitized[$field] = self::validate_int($value, $min, $max);
                    break;
                case 'textarea':
                    $max_length = $config['max_length'] ?? 10000;
                    $sanitized[$field] = self::validate_textarea($value, $max_length);
                    break;
                case 'url':
                    $sanitized[$field] = self::validate_url($value);
                    break;
                case 'array':
                    if (is_array($value)) {
                        $sanitized[$field] = array_map('sanitize_text_field', $value);
                    } else {
                        $sanitized[$field] = [];
                    }
                    break;
                case 'text':
                default:
                    $max_length = $config['max_length'] ?? 255;
                    $allow_html = $config['allow_html'] ?? false;
                    $sanitized[$field] = self::validate_text($value, $max_length, $allow_html);
                    break;
            }
        }

        return $sanitized;
    }

    /**
     * Check if user has required capability
     * 
     * @param string $capability Required capability
     * @param int $object_id Optional object ID for meta capabilities
     * @return bool True if user has capability
     */
    public static function check_capability($capability, $object_id = null)
    {
        if ($object_id !== null) {
            return current_user_can($capability, $object_id);
        }
        return current_user_can($capability);
    }

    /**
     * Log security event
     * 
     * @param string $event Event type
     * @param string $message Event message
     * @param array $context Additional context
     */
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

        do_action('jpm_log_error', 'JPM Security Event: ' . wp_json_encode($log_data));
    }

    /**
     * Generate secure random token
     * 
     * @param int $length Token length
     * @return string Random token
     */
    public static function generate_token($length = 32)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        } else {
            // Fallback (less secure)
            return wp_generate_password($length, false);
        }
    }

    /**
     * Validate JSON input
     * 
     * @param string $json JSON string
     * @param int $max_depth Maximum depth
     * @return array|false Decoded array or false
     */
    public static function validate_json($json, $max_depth = 10)
    {
        if (empty($json)) {
            return false;
        }

        // Check length (prevent DoS)
        if (strlen($json) > 1000000) { // 1MB limit
            return false;
        }

        $decoded = json_decode($json, true, $max_depth);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return is_array($decoded) ? $decoded : false;
    }
}
