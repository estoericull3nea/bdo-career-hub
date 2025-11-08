<?php
/**
 * SMTP Configuration Class
 * Configures WordPress PHPMailer to use SMTP
 * Only activates if no other SMTP plugin is installed
 */
class JPM_SMTP
{
    /**
     * Check if another SMTP plugin is active
     * 
     * @return bool True if another SMTP plugin is active
     */
    public static function has_existing_smtp_plugin()
    {
        // Check for WP Mail SMTP
        if (defined('WPMS_ON') || function_exists('wp_mail_smtp')) {
            return true;
        }

        // Check for Easy WP SMTP
        if (defined('EASY_WP_SMTP_VERSION') || class_exists('EasyWPSMTP')) {
            return true;
        }

        // Check for Post SMTP
        if (defined('POST_SMTP_VERSION') || class_exists('Post_SMTP')) {
            return true;
        }

        // Check for SMTP Mailer
        if (defined('SMTP_MAILER_VERSION') || class_exists('SMTP_Mailer')) {
            return true;
        }

        // Check for FluentSMTP
        if (defined('FLUENTMAIL') || class_exists('FluentMail\App\Hooks\Handler')) {
            return true;
        }

        // Check for Gmail SMTP
        if (defined('GMAIL_SMTP_VERSION') || class_exists('Gmail_SMTP')) {
            return true;
        }

        // Check for SendGrid
        if (defined('SENDGRID_VERSION') || class_exists('Sendgrid_Tools')) {
            return true;
        }

        // Check for Mailgun
        if (defined('MAILGUN_VERSION') || class_exists('Mailgun')) {
            return true;
        }

        // Check for Amazon SES
        if (defined('AWS_SES_WP_MAIL_VERSION') || class_exists('Amazon_SES_Mail')) {
            return true;
        }

        // Check if phpmailer_init is already hooked by another plugin
        global $wp_filter;
        if (isset($wp_filter['phpmailer_init'])) {
            $callbacks = $wp_filter['phpmailer_init']->callbacks;
            foreach ($callbacks as $priority => $hooks) {
                foreach ($hooks as $hook) {
                    // Skip our own hook
                    if (
                        is_array($hook['function']) &&
                        is_object($hook['function'][0]) &&
                        $hook['function'][0] instanceof JPM_SMTP
                    ) {
                        continue;
                    }
                    // If another plugin has hooked phpmailer_init, assume SMTP is handled
                    if (is_array($hook['function']) || is_string($hook['function'])) {
                        $function_name = is_array($hook['function'])
                            ? (is_object($hook['function'][0]) ? get_class($hook['function'][0]) : $hook['function'][1])
                            : $hook['function'];
                        // Skip WordPress core and our own class
                        if (
                            strpos($function_name, 'JPM_SMTP') === false &&
                            strpos($function_name, 'wp_mail') === false
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function __construct()
    {
        // Only add our SMTP configuration if no other SMTP plugin is active
        if (!self::has_existing_smtp_plugin()) {
            add_action('phpmailer_init', [$this, 'configure_smtp'], 999);
        }
    }

    /**
     * Configure PHPMailer to use SMTP
     */
    public function configure_smtp($phpmailer)
    {
        // Double check if another plugin has already configured SMTP
        if (self::has_existing_smtp_plugin()) {
            return;
        }

        // Get SMTP settings (default to hardcoded values if not set)
        $smtp_settings = get_option('jpm_smtp_settings', [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => 'noreply050623@gmail.com',
            'password' => 'xlqhaxnjiowsxuyr',
            'from_email' => 'noreply050623@gmail.com',
            'from_name' => get_bloginfo('name')
        ]);

        // Configure PHPMailer
        $phpmailer->isSMTP();
        $phpmailer->Host = $smtp_settings['host'];
        $phpmailer->SMTPAuth = $smtp_settings['auth'];
        $phpmailer->Port = $smtp_settings['port'];
        $phpmailer->Username = $smtp_settings['username'];
        $phpmailer->Password = $smtp_settings['password'];

        // Set encryption
        if (strtolower($smtp_settings['encryption']) === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        } elseif (strtolower($smtp_settings['encryption']) === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        }

        // Set from email and name
        $phpmailer->From = $smtp_settings['from_email'];
        $phpmailer->FromName = $smtp_settings['from_name'];

        // Enable debug if WP_DEBUG is enabled (optional)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 0; // Set to 2 for verbose debug output
        }
    }

    /**
     * Initialize default SMTP settings
     * Only initializes if no other SMTP plugin is active
     * Note: We don't initialize default settings anymore - user must configure SMTP plugin
     */
    public static function init_default_settings()
    {
        // Don't initialize if another SMTP plugin is active
        if (self::has_existing_smtp_plugin()) {
            return;
        }

        // Don't initialize default settings - user must configure an SMTP plugin
        // This ensures emails won't work unless an SMTP plugin is properly configured
        return;
    }

    /**
     * Test SMTP connection
     */
    public static function test_smtp_connection()
    {
        // Check if another SMTP plugin is active
        if (self::has_existing_smtp_plugin()) {
            return new WP_Error('external_smtp', __('Another SMTP plugin is active. Please use that plugin to test SMTP settings.', 'job-posting-manager'));
        }

        $smtp_settings = get_option('jpm_smtp_settings', []);

        if (empty($smtp_settings)) {
            return new WP_Error('no_settings', __('SMTP settings not configured.', 'job-posting-manager'));
        }

        $test_email = get_option('admin_email');
        $subject = __('SMTP Test Email', 'job-posting-manager');
        $message = __('This is a test email to verify SMTP configuration.', 'job-posting-manager');

        $result = wp_mail($test_email, $subject, $message);

        if ($result) {
            return true;
        } else {
            return new WP_Error('smtp_failed', __('SMTP test failed. Please check your settings.', 'job-posting-manager'));
        }
    }
}

