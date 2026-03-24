<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base Email Class
 * 
 * Provides common functionality for all email operations
 */
abstract class JPM_Email_Base
{
    /**
     * Check if SMTP is available
     * 
     * @return bool True if SMTP is available
     */
    protected static function is_smtp_available()
    {
        // Check if external SMTP plugin is active
        if (class_exists('JPM_SMTP') && JPM_SMTP::has_existing_smtp_plugin()) {
            return true;
        }

        // Check if our own SMTP is configured
        $smtp_settings = get_option('jpm_smtp_settings', []);
        if (!empty($smtp_settings) && !empty($smtp_settings['host'])) {
            return true;
        }

        return false;
    }

    /**
     * Replace placeholders in template strings
     * 
     * @param string $text Text with placeholders
     * @param array $replacements Array of placeholder => replacement pairs
     * @return string Text with placeholders replaced
     */
    protected static function replace_placeholders($text, $replacements)
    {
        foreach ($replacements as $placeholder => $replacement) {
            $text = str_replace($placeholder, $replacement, $text);
        }
        return $text;
    }

    /**
     * Add CC and BCC headers from settings
     * 
     * @param array $headers Existing email headers
     * @return array Headers with CC and BCC added
     */
    protected static function add_email_recipients($headers)
    {
        $email_settings = get_option('jpm_email_settings', []);

        // Add CC headers
        if (!empty($email_settings['cc_emails'])) {
            $cc_emails = array_map('trim', explode(',', $email_settings['cc_emails']));
            $cc_emails = array_filter($cc_emails, 'is_email');
            if (!empty($cc_emails)) {
                $headers[] = 'Cc: ' . implode(', ', $cc_emails);
            }
        }

        // Add BCC headers
        if (!empty($email_settings['bcc_emails'])) {
            $bcc_emails = array_map('trim', explode(',', $email_settings['bcc_emails']));
            $bcc_emails = array_filter($bcc_emails, 'is_email');
            if (!empty($bcc_emails)) {
                $headers[] = 'Bcc: ' . implode(', ', $bcc_emails);
            }
        }

        return $headers;
    }

    /**
     * Get default email headers
     * 
     * @return array Default email headers
     */
    protected static function get_default_headers()
    {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
    }

    /**
     * Send email with logging
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body
     * @param array $headers Email headers
     * @return bool True if sent successfully
     */
    protected static function send_email($to, $subject, $body, $headers = [])
    {
        if (empty($headers)) {
            $headers = self::get_default_headers();
        }

        // Add CC and BCC from settings
        $headers = self::add_email_recipients($headers);

        // Send email
        $result = wp_mail($to, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send email to ' . $to);
        }

        return $result;
    }

    /**
     * Get default medical address
     * 
     * @return string Default medical address
     */
    protected static function get_default_medical_address()
    {
        return '2250 Singalong St., Malate Manila';
    }
}
