<?php
/**
 * SMTP Configuration Class
 * Configures WordPress PHPMailer to use SMTP
 */
class JPM_SMTP
{

    public function __construct()
    {
        add_action('phpmailer_init', [$this, 'configure_smtp']);
    }

    /**
     * Configure PHPMailer to use SMTP
     */
    public function configure_smtp($phpmailer)
    {
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
     */
    public static function init_default_settings()
    {
        $default_settings = [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => 'noreply050623@gmail.com',
            'password' => 'xlqhaxnjiowsxuyr',
            'from_email' => 'noreply050623@gmail.com',
            'from_name' => get_bloginfo('name')
        ];

        // Only set defaults if settings don't exist
        if (get_option('jpm_smtp_settings') === false) {
            update_option('jpm_smtp_settings', $default_settings);
        }
    }

    /**
     * Test SMTP connection
     */
    public static function test_smtp_connection()
    {
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

