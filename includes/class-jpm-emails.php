<?php
if (!defined('ABSPATH')) {
    exit;
}

class JPM_Emails
{
    /**
     * Get validated applications table name.
     *
     * @return string
     */
    private static function get_validated_applications_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $expected_pattern = '/^' . preg_quote($wpdb->prefix, '/') . 'job_applications$/';

        if (!preg_match($expected_pattern, $table)) {
            return $wpdb->prefix . 'job_applications';
        }

        return $table;
    }

    /**
     * Fetch and cache a job application row by ID.
     *
     * @param int $app_id Application ID.
     * @return object|null
     */
    private static function get_application_row($app_id)
    {
        $app_id = absint($app_id);
        if ($app_id <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::get_validated_applications_table();
        $application = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));

        return $application;
    }

    /**
     * Detect the slug used for the "For Medical" status.
     */
    private static function get_medical_status_slug()
    {
        return JPM_Status_Manager::get_medical_status_slug();
    }

    /**
     * Default medical address fallback.
     */
    private static function get_default_medical_address()
    {
        return '2250 Singalong St., Malate Manila';
    }

    /**
     * Check if SMTP is available (either external plugin or our own configured)
     * 
     * @return bool True if SMTP is available
     */
    public static function is_smtp_available()
    {
        // Check if external SMTP plugin is active
        if (JPM_SMTP::has_existing_smtp_plugin()) {
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
    private static function replace_placeholders($text, $replacements)
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
    private static function add_email_recipients($headers)
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
     * Send confirmation email to applicant
     * 
     * @param int $app_id Application ID
     * @param int $job_id Job posting ID
     * @param string $customer_email Customer email address
     * @param string $first_name Customer first name
     * @param string $last_name Customer last name
     * @param array $form_data Form submission data
     */
    public static function send_confirmation($app_id, $job_id = 0, $customer_email = '', $first_name = '', $last_name = '', $form_data = [])
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }
        $settings = get_option('jpm_settings', []);

        // Get customer information
        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = __('Valued Applicant', 'job-posting-manager');
        }

        // Get customer email - try from parameter, then form data, then current user
        if (empty($customer_email)) {
            // Try to get from form data
            $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];
            foreach ($email_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $customer_email = sanitize_email($form_data[$field_name]);
                    break;
                }
            }
        }

        // Fallback to current user if still empty
        if (empty($customer_email)) {
            $user = wp_get_current_user();
            $customer_email = $user->user_email;
            if (empty($full_name) || $full_name === __('Valued Applicant', 'job-posting-manager')) {
                $full_name = $user->display_name;
            }
        }

        // Get job title
        $job_title = '';
        if ($job_id > 0) {
            $job_title = get_the_title($job_id);
        }

        // Get application number from form data
        $application_number = '';
        if (isset($form_data['application_number'])) {
            $application_number = $form_data['application_number'];
        }

        // Get application status from database
        $application = self::get_application_row($app_id);

        // Get status information
        $status_slug = 'pending'; // Default status
        if ($application && !empty($application->status)) {
            $status_slug = $application->status;
        }

        // Get status info from JPM_Admin class
        if (class_exists('JPM_Admin')) {
            $status_info = JPM_Admin::get_status_by_slug($status_slug);
        } else {
            $status_info = null;
        }

        if ($status_info) {
            $status_name = $status_info['name'];
            $status_color = $status_info['color'];
            $status_text_color = $status_info['text_color'];
        } else {
            $status_name = ucfirst($status_slug);
            $status_color = '#ffc107';
            $status_text_color = '#000000';
        }

        // Get email template
        $template = JPM_Email_Templates::get_template('confirmation');

        // Replace placeholders in subject
        $subject = self::replace_placeholders($template['subject'], [
            '[Application ID]' => $app_id,
            '[Job Title]' => $job_title,
            '[Full Name]' => $full_name,
            '[Application Number]' => $application_number,
            '[Email]' => $customer_email,
        ]);

        // Build HTML email body from template
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: ' . esc_attr($template['body_text_color']) . '; max-width: 600px; margin: 0 auto;">';

        // Header
        $body .= '<div style="background-color: ' . esc_attr($template['header_color']) . '; padding: 20px; border-radius: 5px 5px 0 0;">';
        $body .= '<h1 style="color: ' . esc_attr($template['header_text_color']) . '; margin: 0;">' . __('Application Confirmation', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        // Body
        $body .= '<div style="background-color: ' . esc_attr($template['body_bg_color']) . '; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">';

        // Greeting
        $greeting = self::replace_placeholders($template['greeting'], [
            '[Full Name]' => esc_html($full_name),
        ]);
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($greeting) . '</p>';

        // Intro message
        $intro_message = self::replace_placeholders($template['intro_message'], [
            '[Application ID]' => $app_id,
            '[Job Title]' => esc_html($job_title),
            '[Full Name]' => esc_html($full_name),
        ]);
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($intro_message) . '</p>';

        // Details section
        $body .= '<div style="background-color: ' . esc_attr($template['details_bg_color']) . '; padding: 20px; border-radius: 5px; margin: 20px 0;">';
        $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px;">' . esc_html($template['details_section_title']) . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Status:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">';
        $body .= '<span style="display: inline-block; background-color: ' . esc_attr($status_color) . '; color: ' . esc_attr($status_text_color) . '; padding: 6px 12px; border-radius: 4px; font-size: 14px; font-weight: bold; text-transform: uppercase;">' . esc_html($status_name) . '</span>';
        $body .= '</td></tr>';
        if (!empty($application_number)) {
            $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Application Number:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($application_number) . '</td></tr>';
        }
        if (!empty($job_title)) {
            $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Job Position:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($job_title) . '</td></tr>';
        }
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Applicant Name:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($full_name) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Email:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($customer_email) . '</td></tr>';
        $body .= '</table>';
        $body .= '</div>';

        // Closing message
        $closing_message = self::replace_placeholders($template['closing_message'], [
            '[Application ID]' => $app_id,
            '[Job Title]' => esc_html($job_title),
            '[Full Name]' => esc_html($full_name),
        ]);
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($closing_message) . '</p>';

        // Tracking and login links
        $login_url = home_url('/sign-in/');
        $track_url = home_url('/track-job-application/');

        // Try to find pages with shortcodes dynamically
        $pages = get_pages();
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'jpm_login')) {
                $login_url = get_permalink($page->ID);
            }
            if (has_shortcode($page->post_content, 'application_tracker')) {
                $track_url = get_permalink($page->ID);
            }
        }

        $body .= '<div style="background-color: #f0f8ff; padding: 20px; border-radius: 5px; margin: 20px 0;">';
        $body .= '<p style="font-size: 15px; margin: 0 0 15px 0; color: #333;">' . __('Please check this link for tracking your application or login to your account to view or track your application in your profile.', 'job-posting-manager') . '</p>';
        $body .= '<p style="margin: 10px 0;">';
        $body .= '<a href="' . esc_url($login_url) . '" style="display: inline-block; background-color: #0073aa; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px; font-weight: bold;">' . __('Login to Your Account', 'job-posting-manager') . '</a>';
        $body .= '<a href="' . esc_url($track_url) . '" style="display: inline-block; background-color: #28a745; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">' . __('Track Your Application', 'job-posting-manager') . '</a>';
        $body .= '</p>';
        $body .= '</div>';

        // Signature
        $body .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">';
        $body .= '<p style="margin: 0; font-size: 14px; color: #666;">' . __('Best regards,', 'job-posting-manager') . '<br>';
        $body .= '<strong>' . esc_html(get_bloginfo('name')) . '</strong></p>';
        $body .= '</div>';

        $body .= '</div>';

        // Footer
        $footer_message = self::replace_placeholders($template['footer_message'], [
            '[Application ID]' => $app_id,
            '[Job Title]' => esc_html($job_title),
        ]);
        $body .= '<div style="background-color: ' . esc_attr($template['footer_bg_color']) . '; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #666;">' . wp_kses_post($footer_message) . '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        $result = wp_mail($customer_email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send confirmation email to ' . $customer_email);
        }

        return $result;
    }

    /**
     * Send status update notification to customer
     * 
     * @param int $app_id Application ID
     */
    public static function send_status_update($app_id)
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }
        // Get application details
        $application = self::get_application_row($app_id);

        if (!$application) {
            do_action('jpm_log_error', 'JPM: Application not found for ID: ' . $app_id);
            return false;
        }

        // Get job details
        $job_id = $application->job_id;
        $job_title = get_the_title($job_id);
        $job_link = get_permalink($job_id);

        // Get status information
        $status_slug = $application->status;
        // Get status info from JPM_Admin class (which is in class-jpm-db.php)
        if (class_exists('JPM_Admin')) {
            $status_info = JPM_Admin::get_status_by_slug($status_slug);
        } else {
            $status_info = null;
        }

        if ($status_info) {
            $status_name = $status_info['name'];
            $status_color = $status_info['color'];
            $status_text_color = $status_info['text_color'];
        } else {
            $status_name = ucfirst($status_slug);
            $status_color = '#ffc107';
            $status_text_color = '#000000';
        }

        // Get customer information
        $form_data = json_decode($application->notes, true);
        $first_name = '';
        $last_name = '';
        $customer_email = '';

        // Extract customer information from form data
        $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
        $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
        $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];

        // Try exact field name matches first
        foreach ($first_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $first_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        foreach ($last_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $last_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // If still not found, try case-insensitive and partial matches
        if (empty($first_name)) {
            foreach ($form_data as $field_name => $field_value) {
                $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given']) && !empty($field_value)) {
                    $first_name = sanitize_text_field($field_value);
                    break;
                }
            }
        }

        if (empty($last_name)) {
            foreach ($form_data as $field_name => $field_value) {
                $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                if (in_array($field_name_lower, ['lastname', 'lname', 'surname', 'familyname', 'family']) && !empty($field_value)) {
                    $last_name = sanitize_text_field($field_value);
                    break;
                }
            }
        }

        foreach ($email_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $customer_email = sanitize_email($form_data[$field_name]);
                break;
            }
        }

        // Fallback to user account if email not found
        if (empty($customer_email) && $application->user_id > 0) {
            $user = get_userdata($application->user_id);
            if ($user) {
                $customer_email = $user->user_email;
                if (empty($first_name)) {
                    $first_name = $user->first_name;
                }
                if (empty($last_name)) {
                    $last_name = $user->last_name;
                }
            }
        }

        // If still no email, can't send notification
        if (empty($customer_email)) {
            do_action('jpm_log_error', 'JPM: No email found for application ID: ' . $app_id);
            return false;
        }

        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = __('Valued Applicant', 'job-posting-manager');
        }

        // Get application number from form data
        $application_number = '';
        if (isset($form_data['application_number'])) {
            $application_number = $form_data['application_number'];
        }

        // Medical details (if status is For Medical)
        $medical_details = [];
        $medical_status_slug = self::get_medical_status_slug();
        $is_medical = $medical_status_slug && $status_slug === $medical_status_slug;

        if ($is_medical) {
            $stored = get_option('jpm_application_medical_details_' . $app_id, []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $medical_details = [
                'requirements' => isset($stored['requirements']) ? wp_kses_post($stored['requirements']) : '',
                'address' => isset($stored['address']) && !empty($stored['address']) ? sanitize_text_field($stored['address']) : self::get_default_medical_address(),
                'date' => isset($stored['date']) ? sanitize_text_field($stored['date']) : '',
                'time' => isset($stored['time']) ? sanitize_text_field($stored['time']) : '',
                'updated_at' => isset($stored['updated_at']) ? sanitize_text_field($stored['updated_at']) : '',
            ];
        }

        // Rejection details (if status is Rejected)
        $rejection_details = [];
        $is_rejected = false;
        $rejected_status_slug = '';
        $all_statuses = JPM_Status_Manager::get_all_statuses_info();
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'rejected' || $name === 'rejected') {
                $rejected_status_slug = $status['slug'];
                break;
            }
        }
        $is_rejected = $rejected_status_slug && $status_slug === $rejected_status_slug;

        if ($is_rejected) {
            $stored = get_option('jpm_application_rejection_details_' . $app_id, []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $problem_area_labels = [
                'personal_information' => __('Personal Information', 'job-posting-manager'),
                'education' => __('Education', 'job-posting-manager'),
                'employment' => __('Employment', 'job-posting-manager'),
            ];

            $rejection_details = [
                'problem_area' => isset($stored['problem_area']) ? sanitize_text_field($stored['problem_area']) : '',
                'problem_area_label' => isset($stored['problem_area']) && isset($problem_area_labels[$stored['problem_area']]) ? $problem_area_labels[$stored['problem_area']] : '',
                'notes' => isset($stored['notes']) ? wp_kses_post($stored['notes']) : '',
                'updated_at' => isset($stored['updated_at']) ? sanitize_text_field($stored['updated_at']) : '',
            ];
        }

        // Interview details (if status is For Interview)
        $interview_details = [];
        $is_interview = false;
        $interview_status_slug = '';
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if (
                $slug === 'for-interview' || $slug === 'for_interview' || $slug === 'forinterview' ||
                $name === 'for interview' || stripos($name, 'for interview') !== false || stripos($name, 'interview') !== false
            ) {
                $interview_status_slug = $status['slug'];
                break;
            }
        }
        $is_interview = $interview_status_slug && $status_slug === $interview_status_slug;

        if ($is_interview) {
            $stored = get_option('jpm_application_interview_details_' . $app_id, []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $interview_details = [
                'requirements' => isset($stored['requirements']) ? wp_kses_post($stored['requirements']) : '',
                'address' => isset($stored['address']) ? sanitize_text_field($stored['address']) : '',
                'date' => isset($stored['date']) ? sanitize_text_field($stored['date']) : '',
                'time' => isset($stored['time']) ? sanitize_text_field($stored['time']) : '',
                'updated_at' => isset($stored['updated_at']) ? sanitize_text_field($stored['updated_at']) : '',
            ];
        }

        // Get email template
        $template = JPM_Email_Templates::get_template('status_update');

        // Replace placeholders in subject
        $subject = self::replace_placeholders($template['subject'], [
            '[Status Name]' => $status_name,
            '[Job Title]' => $job_title,
            '[Application ID]' => $app_id,
            '[Full Name]' => $full_name,
        ]);

        // Build HTML email body from template
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: ' . esc_attr($template['body_text_color']) . '; max-width: 600px; margin: 0 auto;">';

        // Header (use status color if template header color is default, otherwise use template)
        $header_color = ($template['header_color'] === '#ffc107') ? $status_color : $template['header_color'];
        $header_text_color = ($template['header_text_color'] === '#000000') ? $status_text_color : $template['header_text_color'];

        $body .= '<div style="background-color: ' . esc_attr($header_color) . '; padding: 20px; border-radius: 5px 5px 0 0; color: ' . esc_attr($header_text_color) . ';">';
        $body .= '<h1 style="color: ' . esc_attr($header_text_color) . '; margin: 0; font-size: 24px;">' . __('Application Status Update', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        // Body
        $body .= '<div style="background-color: ' . esc_attr($template['body_bg_color']) . '; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">';

        // Greeting
        $greeting = self::replace_placeholders($template['greeting'], [
            '[Full Name]' => esc_html($full_name),
        ]);
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($greeting) . '</p>';

        // Intro message
        $intro_message = self::replace_placeholders($template['intro_message'], [
            '[Status Name]' => esc_html($status_name),
            '[Job Title]' => esc_html($job_title),
        ]);
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($intro_message) . '</p>';

        // Status section
        $body .= '<div style="background-color: ' . esc_attr($template['status_bg_color']) . '; padding: 20px; border-radius: 5px; margin: 20px 0; text-align: center;">';
        $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666; font-weight: bold;">' . esc_html($template['status_section_title']) . '</p>';
        $body .= '<span style="display: inline-block; background-color: ' . esc_attr($status_color) . '; color: ' . esc_attr($status_text_color) . '; padding: 10px 20px; border-radius: 5px; font-size: 18px; font-weight: bold; text-transform: uppercase;">' . esc_html($status_name) . '</span>';
        $body .= '</div>';

        // Details section
        $body .= '<div style="background-color: ' . esc_attr($template['details_bg_color']) . '; padding: 20px; border-radius: 5px; margin: 20px 0;">';
        $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px;">' . esc_html($template['details_section_title']) . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        if (!empty($application_number)) {
            $body .= '<tr><td style="padding: 8px 0; font-weight: bold; width: 40%;">' . __('Application Number:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($application_number) . '</td></tr>';
        }
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Job Position:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($job_title) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Status:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><strong>' . esc_html($status_name) . '</strong></td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Date Updated:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp'))) . '</td></tr>';
        $body .= '</table>';
        $body .= '</div>';

        // Medical details section (only when status is For Medical)
        if ($is_medical) {
            $body .= '<div style="background: linear-gradient(to right, #e8f4f8 0%, #f0f8fb 100%); padding: 25px; border-radius: 8px; margin: 25px 0; box-shadow: 0 2px 6px rgba(0,115,170,0.15);">';
            $body .= '<h2 style="color: #0073aa; margin-top: 0; margin-bottom: 20px; font-size: 21px; font-weight: 700; border-bottom: 2px solid #0073aa; padding-bottom: 12px; letter-spacing: 0.3px;">' . __('Medical Requirements & Schedule', 'job-posting-manager') . '</h2>';
            $body .= '<table style="width: 100%; border-collapse: collapse;">';

            if (!empty($medical_details['requirements'])) {
                $body .= '<tr style="background-color: rgba(255,255,255,0.6);"><td style="padding: 14px 12px; font-weight: 700; width: 35%; vertical-align: top; color: #0073aa; border-radius: 4px 0 0 4px;">' . __('Requirements:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; line-height: 1.8; color: #2c3e50; border-radius: 0 4px 4px 0;">' . nl2br(wp_kses_post($medical_details['requirements'])) . '</td></tr>';
            }

            $medical_address = !empty($medical_details['address']) ? $medical_details['address'] : self::get_default_medical_address();
            $body .= '<tr style="background-color: rgba(255,255,255,0.4); margin-top: 8px;"><td style="padding: 14px 12px; font-weight: 700; vertical-align: top; color: #0073aa; border-top: 1px solid rgba(0,115,170,0.2); border-radius: 4px 0 0 4px;">' . __('Address:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; border-top: 1px solid rgba(0,115,170,0.2); line-height: 1.8; color: #2c3e50; font-size: 15px; border-radius: 0 4px 4px 0;">' . esc_html($medical_address) . '</td></tr>';

            if (!empty($medical_details['date']) || !empty($medical_details['time'])) {
                $schedule = '';
                if (!empty($medical_details['date'])) {
                    // Format date from YYYY-MM-DD to readable format (e.g., January 14, 2026)
                    $date_timestamp = strtotime($medical_details['date']);
                    $formatted_date = $date_timestamp ? date_i18n('F j, Y', $date_timestamp) : esc_html($medical_details['date']);
                    $schedule .= '<div style="margin-bottom: 10px; padding: 8px 0;"><strong style="color: #0073aa; font-size: 14px;">' . __('Date:', 'job-posting-manager') . '</strong> <span style="color: #2c3e50; font-size: 15px; font-weight: 500; margin-left: 8px;">' . $formatted_date . '</span></div>';
                }
                if (!empty($medical_details['time'])) {
                    // Format time to readable format (e.g., 2:30 PM)
                    $time_formatted = date_i18n('g:i A', strtotime($medical_details['time']));
                    if (!$time_formatted) {
                        $time_formatted = esc_html($medical_details['time']);
                    }
                    $schedule .= '<div style="padding: 8px 0;"><strong style="color: #0073aa; font-size: 14px;">' . __('Time:', 'job-posting-manager') . '</strong> <span style="color: #2c3e50; font-size: 15px; font-weight: 500; margin-left: 8px;">' . $time_formatted . '</span></div>';
                }
                $body .= '<tr style="background-color: rgba(255,255,255,0.4);"><td style="padding: 14px 12px; font-weight: 700; vertical-align: top; color: #0073aa; border-top: 1px solid rgba(0,115,170,0.2); border-radius: 4px 0 0 4px;">' . __('Schedule:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; border-top: 1px solid rgba(0,115,170,0.2); line-height: 1.8; border-radius: 0 4px 4px 0;">' . $schedule . '</td></tr>';
            }

            $body .= '</table>';
            $body .= '</div>';
            $body .= '<div style="margin: 12px 0 25px 0; padding: 14px 16px; background-color: #f0f6fc;  border-radius: 0 4px 4px 0;">';
            $body .= '<p style="margin: 0; font-size: 15px; color: #1e1e1e; line-height: 1.6;">' . esc_html__('Please go to our address on your schedule.', 'job-posting-manager') . '</p>';
            $body .= '</div>';
        }

        // Interview details section (only when status is For Interview)
        if ($is_interview && !empty($interview_details['requirements'])) {
            $body .= '<div style="background: linear-gradient(to right, #e8f4f8 0%, #f0f8fb 100%); padding: 25px; border-radius: 8px; margin: 25px 0; box-shadow: 0 2px 6px rgba(0,115,170,0.15);">';
            $body .= '<h2 style="color: #0073aa; margin-top: 0; margin-bottom: 20px; font-size: 21px; font-weight: 700; border-bottom: 2px solid #0073aa; padding-bottom: 12px; letter-spacing: 0.3px;">' . __('Interview Requirements & Schedule', 'job-posting-manager') . '</h2>';
            $body .= '<table style="width: 100%; border-collapse: collapse;">';

            if (!empty($interview_details['requirements'])) {
                $body .= '<tr style="background-color: rgba(255,255,255,0.6);"><td style="padding: 14px 12px; font-weight: 700; width: 35%; vertical-align: top; color: #0073aa; border-radius: 4px 0 0 4px;">' . __('Requirements:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; line-height: 1.8; color: #2c3e50; border-radius: 0 4px 4px 0;">' . nl2br(wp_kses_post($interview_details['requirements'])) . '</td></tr>';
            }

            if (!empty($interview_details['address'])) {
                $body .= '<tr style="background-color: rgba(255,255,255,0.4); margin-top: 8px;"><td style="padding: 14px 12px; font-weight: 700; vertical-align: top; color: #0073aa; border-top: 1px solid rgba(0,115,170,0.2); border-radius: 4px 0 0 4px;">' . __('Address:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; border-top: 1px solid rgba(0,115,170,0.2); line-height: 1.8; color: #2c3e50; font-size: 15px; border-radius: 0 4px 4px 0;">' . esc_html($interview_details['address']) . '</td></tr>';
            }

            if (!empty($interview_details['date']) || !empty($interview_details['time'])) {
                $schedule = '';
                if (!empty($interview_details['date'])) {
                    // Format date from YYYY-MM-DD to readable format (e.g., January 14, 2026)
                    $date_timestamp = strtotime($interview_details['date']);
                    $formatted_date = $date_timestamp ? date_i18n('F j, Y', $date_timestamp) : esc_html($interview_details['date']);
                    $schedule .= '<div style="margin-bottom: 10px; padding: 8px 0;"><strong style="color: #0073aa; font-size: 14px;">' . __('Date:', 'job-posting-manager') . '</strong> <span style="color: #2c3e50; font-size: 15px; font-weight: 500; margin-left: 8px;">' . $formatted_date . '</span></div>';
                }
                if (!empty($interview_details['time'])) {
                    // Format time to readable format (e.g., 2:30 PM)
                    $time_formatted = date_i18n('g:i A', strtotime($interview_details['time']));
                    if (!$time_formatted) {
                        $time_formatted = esc_html($interview_details['time']);
                    }
                    $schedule .= '<div style="padding: 8px 0;"><strong style="color: #0073aa; font-size: 14px;">' . __('Time:', 'job-posting-manager') . '</strong> <span style="color: #2c3e50; font-size: 15px; font-weight: 500; margin-left: 8px;">' . $time_formatted . '</span></div>';
                }
                $body .= '<tr style="background-color: rgba(255,255,255,0.4);"><td style="padding: 14px 12px; font-weight: 700; vertical-align: top; color: #0073aa; border-top: 1px solid rgba(0,115,170,0.2); border-radius: 4px 0 0 4px;">' . __('Schedule:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; border-top: 1px solid rgba(0,115,170,0.2); line-height: 1.8; border-radius: 0 4px 4px 0;">' . $schedule . '</td></tr>';
            }

            $body .= '</table>';
            $body .= '</div>';
            $body .= '<div style="margin: 12px 0 25px 0; padding: 14px 16px; background-color: #f0f6fc;  border-radius: 0 4px 4px 0;">';
            $body .= '<p style="margin: 0; font-size: 15px; color: #1e1e1e; line-height: 1.6;">' . esc_html__('Please go to our address on your schedule.', 'job-posting-manager') . '</p>';
            $body .= '</div>';
        }

        // Rejection details section (only when status is Rejected)
        if ($is_rejected && !empty($rejection_details['notes'])) {
            $body .= '<div style="background: linear-gradient(to right, #fff5f5 0%, #ffe8e8 100%); padding: 25px; border-radius: 8px; margin: 25px 0; box-shadow: 0 2px 6px rgba(220,53,69,0.15);">';
            $body .= '<h2 style="color: #dc3545; margin-top: 0; margin-bottom: 20px; font-size: 21px; font-weight: 700; border-bottom: 2px solid #dc3545; padding-bottom: 12px; letter-spacing: 0.3px;">' . __('Rejection Details', 'job-posting-manager') . '</h2>';
            $body .= '<table style="width: 100%; border-collapse: collapse;">';

            if (!empty($rejection_details['problem_area_label'])) {
                $body .= '<tr style="background-color: rgba(255,255,255,0.6);"><td style="padding: 14px 12px; font-weight: 700; width: 35%; vertical-align: top; color: #dc3545; border-radius: 4px 0 0 4px;">' . __('The problem is in the:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; line-height: 1.8; color: #2c3e50; border-radius: 0 4px 4px 0; font-weight: 600;">' . esc_html($rejection_details['problem_area_label']) . '</td></tr>';
            }

            if (!empty($rejection_details['notes'])) {
                $body .= '<tr style="background-color: rgba(255,255,255,0.4); margin-top: 8px;"><td style="padding: 14px 12px; font-weight: 700; vertical-align: top; color: #dc3545; border-top: 1px solid rgba(220,53,69,0.2); border-radius: 4px 0 0 4px;">' . __('Notes:', 'job-posting-manager') . '</td><td style="padding: 14px 12px; border-top: 1px solid rgba(220,53,69,0.2); line-height: 1.8; color: #2c3e50; font-size: 15px; border-radius: 0 4px 4px 0;">' . nl2br(wp_kses_post($rejection_details['notes'])) . '</td></tr>';
            }

            $body .= '</table>';
            $body .= '</div>';
        }

        // Status-specific message
        // For rejected status, show custom message about re-applying
        if ($is_rejected) {
            $body .= '<div style="background-color: #fff3cd; padding: 15px; border-radius: 3px; margin: 20px 0;">';
            $body .= '<p style="margin: 0; font-size: 15px; color: #856404; font-weight: 500;">' . __('You need to re-apply to this job. Please fix the issues in your application that caused the rejection before submitting a new application.', 'job-posting-manager') . '</p>';
            $body .= '</div>';
        } else {
            // Check if status is "In Progress" or "Pending"
            $status_slug_lower = strtolower($status_slug);
            $status_name_lower = strtolower($status_name);

            $is_in_progress = false;
            if (
                $status_slug_lower === 'in-progress' || $status_slug_lower === 'in_progress' || $status_slug_lower === 'inprogress' ||
                $status_name_lower === 'in progress' || stripos($status_name_lower, 'in progress') !== false
            ) {
                $is_in_progress = true;
            }

            $is_pending = false;
            if (
                $status_slug_lower === 'pending' ||
                $status_name_lower === 'pending'
            ) {
                $is_pending = true;
            }

            $is_reviewed = false;
            if (
                $status_slug_lower === 'reviewed' ||
                $status_name_lower === 'reviewed'
            ) {
                $is_reviewed = true;
            }

            $is_accepted = false;
            if (
                $status_slug_lower === 'accepted' ||
                $status_name_lower === 'accepted'
            ) {
                $is_accepted = true;
            }

            // For "Accepted" status, show congratulations message
            if ($is_accepted) {
                $body .= '<div style="background: linear-gradient(to right, #d4edda 0%, #c3e6cb 100%); padding: 20px; border-radius: 5px; margin: 20px 0;">';
                $body .= '<p style="margin: 0; font-size: 16px; color: #155724; font-weight: 600; line-height: 1.6;">';
                /* translators: %s: Job title wrapped in strong tag. */
                $body .= __('Congratulations!', 'job-posting-manager') . ' ' . sprintf(__('We are pleased to inform you that your application for the position of %s has been accepted.', 'job-posting-manager'), '<strong>' . esc_html($job_title) . '</strong>');
                $body .= '</p>';
                $body .= '<p style="margin: 15px 0 0 0; font-size: 15px; color: #155724; line-height: 1.6;">';
                $body .= __('Our team will contact you with the next steps and additional information.', 'job-posting-manager');
                $body .= '</p>';
                $body .= '</div>';
            } elseif ($is_in_progress || $is_pending || $is_reviewed) {
                // For "In Progress", "Pending", or "Reviewed" status, show the default message
                $body .= '<div style="background-color: #e7f3ff; padding: 15px; border-radius: 3px; margin: 20px 0;">';
                $body .= '<p style="margin: 0; font-size: 15px; color: #004085;">' . __('We will keep you updated on any further changes to your application status.', 'job-posting-manager') . '</p>';
                $body .= '</div>';
            } else {
                // For other statuses, only show if message exists and is not the default one
                $status_specific_message = self::replace_placeholders($template['status_specific_message'], [
                    '[Status Name]' => esc_html($status_name),
                    '[Job Title]' => esc_html($job_title),
                ]);
                // Don't show the default "We will keep you updated..." message
                $default_message = __('We will keep you updated on any further changes to your application status.', 'job-posting-manager');
                if (!empty($status_specific_message) && trim($status_specific_message) !== trim($default_message)) {
                    $body .= '<div style="background-color: #e7f3ff; padding: 15px; border-radius: 3px; margin: 20px 0;">';
                    $body .= '<p style="margin: 0; font-size: 15px; color: #004085;">' . wp_kses_post($status_specific_message) . '</p>';
                    $body .= '</div>';
                }
            }
        }

        // Closing message
        $closing_message = self::replace_placeholders($template['closing_message'], [
            '[Status Name]' => esc_html($status_name),
            '[Job Title]' => esc_html($job_title),
        ]);

        // Get contact page URL - try to find page with /contact/ slug, otherwise use default
        $contact_url = home_url('/contact/');
        $pages = get_pages();
        foreach ($pages as $page) {
            if (strpos(strtolower($page->post_name), 'contact') !== false || strpos(strtolower(get_permalink($page->ID)), '/contact/') !== false) {
                $contact_url = get_permalink($page->ID);
                break;
            }
        }

        // Replace "contact us" with a link (preserve original case)
        $closing_message = preg_replace_callback(
            '/(contact us)/i',
            function ($matches) use ($contact_url) {
                return '<a href="' . esc_url($contact_url) . '" style="color: #0073aa; text-decoration: underline;">' . $matches[1] . '</a>';
            },
            $closing_message
        );

        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . wp_kses_post($closing_message) . '</p>';

        if (!empty($job_link)) {
            $body .= '<div style="margin: 20px 0; text-align: center;">';
            $body .= '<a href="' . esc_url($job_link) . '" style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">' . __('View Job Posting', 'job-posting-manager') . '</a>';
            $body .= '</div>';
        }

        // Signature
        $body .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">';
        $body .= '<p style="margin: 0; font-size: 14px; color: #666;">' . __('Best regards,', 'job-posting-manager') . '<br>';
        $body .= '<strong>' . esc_html(get_bloginfo('name')) . '</strong></p>';
        $body .= '</div>';

        $body .= '</div>';

        // Footer
        $footer_message = self::replace_placeholders($template['footer_message'], [
            '[Status Name]' => esc_html($status_name),
            '[Job Title]' => esc_html($job_title),
        ]);
        $body .= '<div style="background-color: ' . esc_attr($template['footer_bg_color']) . '; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #666;">' . wp_kses_post($footer_message) . '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Add CC and BCC from settings
        $headers = self::add_email_recipients($headers);

        // Send email
        $result = wp_mail($customer_email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send status update email to ' . $customer_email);
        }

        return $result;
    }

    /**
     * Send admin notification email when a new application is submitted
     * 
     * @param int $application_id Application ID
     * @param int $job_id Job posting ID
     * @param array $form_data Form submission data
     * @param string $admin_email Admin email address
     * @param string $customer_email Customer email address
     * @param string $first_name Customer first name
     * @param string $last_name Customer last name
     */
    public static function send_admin_notification($application_id, $job_id, $form_data, $admin_email = '', $customer_email = '', $first_name = '', $last_name = '')
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }

        // Get recipient email from settings if not provided
        if (empty($admin_email)) {
            $email_settings = get_option('jpm_email_settings', []);
            $admin_email = !empty($email_settings['recipient_email']) ? $email_settings['recipient_email'] : get_option('admin_email');
        }

        // Get job details
        $job_title = get_the_title($job_id);
        $job_link = admin_url('post.php?post=' . $job_id . '&action=edit');
        // Link to applications page with view details action (dynamic based on application_id)
        $application_link = admin_url('admin.php?page=jpm-applications&action=print&application_id=' . $application_id);

        // Extract customer information from form data if not provided
        if (empty($first_name) || empty($last_name) || empty($customer_email)) {
            $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
            $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
            $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];

            // Try exact field name matches first
            foreach ($first_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $first_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            foreach ($last_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $last_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // If still not found, try case-insensitive and partial matches
            if (empty($first_name)) {
                foreach ($form_data as $field_name => $field_value) {
                    $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                    if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given']) && !empty($field_value)) {
                        $first_name = sanitize_text_field($field_value);
                        break;
                    }
                }
            }

            if (empty($last_name)) {
                foreach ($form_data as $field_name => $field_value) {
                    $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                    if (in_array($field_name_lower, ['lastname', 'lname', 'surname', 'familyname', 'family']) && !empty($field_value)) {
                        $last_name = sanitize_text_field($field_value);
                        break;
                    }
                }
            }

            foreach ($email_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $customer_email = sanitize_email($form_data[$field_name]);
                    break;
                }
            }
        }

        // Fallback to current user if customer info not found
        if (empty($customer_email)) {
            $user = wp_get_current_user();
            $customer_email = $user->user_email;
            if (empty($first_name) && empty($last_name)) {
                $first_name = $user->first_name;
                $last_name = $user->last_name;
                if (empty($first_name) && empty($last_name)) {
                    $full_name_parts = explode(' ', $user->display_name);
                    $first_name = $full_name_parts[0] ?? '';
                    $last_name = isset($full_name_parts[1]) ? implode(' ', array_slice($full_name_parts, 1)) : '';
                }
            }
        }

        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = __('Unknown Applicant', 'job-posting-manager');
        }

        // Get application number from form data
        $application_number = '';
        if (isset($form_data['application_number'])) {
            $application_number = $form_data['application_number'];
        }

        // Get date of registration from form data
        $date_of_registration = '';
        if (isset($form_data['date_of_registration'])) {
            $date_of_registration = $form_data['date_of_registration'];
        }

        // Get email template
        $template = JPM_Email_Templates::get_template('admin_notification');

        // Replace placeholders in subject
        $subject = self::replace_placeholders($template['subject'], [
            '[Job Title]' => $job_title,
            '[Full Name]' => $full_name,
            '[Application ID]' => $application_id,
        ]);

        // Build HTML email body from template
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: ' . esc_attr($template['body_text_color']) . '; max-width: 700px; margin: 0 auto;">';

        // Header
        $body .= '<div style="background-color: ' . esc_attr($template['header_color']) . '; padding: 20px; border-radius: 5px 5px 0 0; color: ' . esc_attr($template['header_text_color']) . ';">';
        $body .= '<h1 style="color: ' . esc_attr($template['header_text_color']) . '; margin: 0; font-size: 24px;">' . __('New Job Application Received', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        // Body
        $body .= '<div style="background-color: ' . esc_attr($template['body_bg_color']) . '; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">';

        // Greeting (from admin template settings)
        if (!empty($template['greeting'])) {
            $greeting = self::replace_placeholders($template['greeting'], [
                '[Full Name]' => esc_html($full_name),
                '[Job Title]' => esc_html($job_title),
                '[Application ID]' => $application_id,
            ]);
            $body .= '<p style="font-size: 16px; margin-bottom: 15px;">' . wp_kses_post($greeting) . '</p>';
        }

        // Intro message (from admin template settings)
        if (!empty($template['intro_message'])) {
            $intro_message = self::replace_placeholders($template['intro_message'], [
                '[Job Title]' => esc_html($job_title),
                '[Full Name]' => esc_html($full_name),
                '[Application ID]' => $application_id,
            ]);
            $body .= '<p style="font-size: 15px; margin-bottom: 20px;">' . wp_kses_post($intro_message) . '</p>';
        }

        // Job Information Section
        $body .= '<div style="background-color: ' . esc_attr($template['job_section_bg_color']) . '; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px; border-bottom: 2px solid ' . esc_attr($template['header_color']) . '; padding-bottom: 10px;">' . esc_html($template['job_section_title']) . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold; width: 35%;">' . __('Job Title:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><a href="' . esc_url($job_link) . '" style="color: #0073aa; text-decoration: none;">' . esc_html($job_title) . '</a></td></tr>';
        if (!empty($application_number)) {
            $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Application Number:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($application_number) . '</td></tr>';
        }
        if (!empty($date_of_registration)) {
            $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Date of Registration:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($date_of_registration) . '</td></tr>';
        }
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('View Application:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><a href="' . esc_url($application_link) . '" style="background-color: #0073aa; color: #ffffff; padding: 8px 15px; text-decoration: none; border-radius: 3px; display: inline-block;">' . __('View in Admin', 'job-posting-manager') . '</a></td></tr>';
        $body .= '</table>';
        $body .= '</div>';

        // Applicant Information Section
        $body .= '<div style="background-color: ' . esc_attr($template['applicant_section_bg_color']) . '; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px; border-bottom: 2px solid ' . esc_attr($template['header_color']) . '; padding-bottom: 10px;">' . esc_html($template['applicant_section_title']) . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('First Name:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($first_name) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Last Name:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($last_name) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Email Address:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><a href="mailto:' . esc_attr($customer_email) . '" style="color: #0073aa; text-decoration: none;">' . esc_html($customer_email) . '</a></td></tr>';
        $body .= '</table>';
        $body .= '</div>';

        // Education Section
        $has_education = false;
        $education_fields = [
            'edu_primary_school_name',
            'edu_primary_school_address',
            'edu_primary_start_year',
            'edu_primary_end_year',
            'edu_primary_completed',
            'edu_secondary_school_name',
            'edu_secondary_school_address',
            'edu_secondary_school_type',
            'edu_secondary_start_year',
            'edu_secondary_end_year',
            'edu_secondary_completed',
            'edu_tertiary_institution_name',
            'edu_tertiary_school_address',
            'edu_tertiary_program',
            'edu_tertiary_degree_level',
            'edu_tertiary_start_year',
            'edu_tertiary_end_year',
            'edu_tertiary_status'
        ];

        foreach ($education_fields as $edu_field) {
            if (isset($form_data[$edu_field]) && !empty($form_data[$edu_field])) {
                $has_education = true;
                break;
            }
        }

        if ($has_education) {
            $body .= '<div style="background-color: ' . esc_attr($template['details_section_bg_color']) . '; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
            $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px; border-bottom: 2px solid ' . esc_attr($template['header_color']) . '; padding-bottom: 10px;">' . __('Education', 'job-posting-manager') . '</h2>';

            // Primary Education
            if (!empty($form_data['edu_primary_school_name']) || !empty($form_data['edu_primary_school_address'])) {
                $body .= '<h3 style="color: #0073aa; font-size: 16px; margin: 15px 0 10px 0;">' . __('Primary Education', 'job-posting-manager') . '</h3>';
                $body .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">';
                if (!empty($form_data['edu_primary_school_name'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold; width: 35%;">' . __('School Name:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_primary_school_name']) . '</td></tr>';
                }
                if (!empty($form_data['edu_primary_school_address'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('School Address:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_primary_school_address']) . '</td></tr>';
                }
                if (!empty($form_data['edu_primary_start_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Start Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_primary_start_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_primary_end_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('End Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_primary_end_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_primary_completed'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Completed:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_primary_completed']) . '</td></tr>';
                }
                $body .= '</table>';
            }

            // Secondary Education
            if (!empty($form_data['edu_secondary_school_name']) || !empty($form_data['edu_secondary_school_address'])) {
                $body .= '<h3 style="color: #0073aa; font-size: 16px; margin: 15px 0 10px 0;">' . __('Secondary Education', 'job-posting-manager') . '</h3>';
                $body .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">';
                if (!empty($form_data['edu_secondary_school_name'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold; width: 35%;">' . __('School Name:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_school_name']) . '</td></tr>';
                }
                if (!empty($form_data['edu_secondary_school_address'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('School Address:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_school_address']) . '</td></tr>';
                }
                if (!empty($form_data['edu_secondary_school_type'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('School Type:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_school_type']) . '</td></tr>';
                }
                if (!empty($form_data['edu_secondary_start_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Start Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_start_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_secondary_end_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('End Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_end_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_secondary_completed'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Completed:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_secondary_completed']) . '</td></tr>';
                }
                $body .= '</table>';
            }

            // Tertiary Education
            if (!empty($form_data['edu_tertiary_institution_name']) || !empty($form_data['edu_tertiary_school_address'])) {
                $body .= '<h3 style="color: #0073aa; font-size: 16px; margin: 15px 0 10px 0;">' . __('Tertiary Education', 'job-posting-manager') . '</h3>';
                $body .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">';
                if (!empty($form_data['edu_tertiary_institution_name'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold; width: 35%;">' . __('Institution Name:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_institution_name']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_school_address'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('School Address:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_school_address']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_program'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Program / Course:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_program']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_degree_level'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Degree Level:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_degree_level']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_start_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Start Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_start_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_end_year'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('End Year:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_end_year']) . '</td></tr>';
                }
                if (!empty($form_data['edu_tertiary_status'])) {
                    $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Status:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($form_data['edu_tertiary_status']) . '</td></tr>';
                }
                $body .= '</table>';
            }

            $body .= '</div>';
        }

        // Employment Section
        $has_employment = false;
        if (
            (isset($form_data['emp_company_name']) && !empty($form_data['emp_company_name'])) ||
            (isset($form_data['emp_position']) && !empty($form_data['emp_position'])) ||
            (isset($form_data['emp_years']) && !empty($form_data['emp_years'])) ||
            (isset($form_data['employment_entries']) && !empty($form_data['employment_entries']))
        ) {
            $has_employment = true;
        }

        if ($has_employment) {
            $body .= '<div style="background-color: ' . esc_attr($template['details_section_bg_color']) . '; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
            $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px; border-bottom: 2px solid ' . esc_attr($template['header_color']) . '; padding-bottom: 10px;">' . __('Employment History', 'job-posting-manager') . '</h2>';

            // Handle employment entries (array format)
            $employment_entries = [];
            if (isset($form_data['employment_entries']) && is_array($form_data['employment_entries'])) {
                $employment_entries = $form_data['employment_entries'];
            } else {
                // Fallback: reconstruct from arrays
                $company_names = isset($form_data['emp_company_name']) && is_array($form_data['emp_company_name']) ? $form_data['emp_company_name'] : [];
                $positions = isset($form_data['emp_position']) && is_array($form_data['emp_position']) ? $form_data['emp_position'] : [];
                $years = isset($form_data['emp_years']) && is_array($form_data['emp_years']) ? $form_data['emp_years'] : [];

                $max_count = max(count($company_names), count($positions), count($years));
                for ($i = 0; $i < $max_count; $i++) {
                    if (!empty($company_names[$i]) || !empty($positions[$i]) || !empty($years[$i])) {
                        $employment_entries[] = [
                            'company_name' => $company_names[$i] ?? '',
                            'position' => $positions[$i] ?? '',
                            'years' => $years[$i] ?? ''
                        ];
                    }
                }
            }

            if (!empty($employment_entries)) {
                foreach ($employment_entries as $index => $entry) {
                    $entry_num = $index + 1;
                    /* translators: %d: Employment entry number. */
                    $body .= '<h3 style="color: #0073aa; font-size: 16px; margin: 15px 0 10px 0;">' . sprintf(__('Employment #%d', 'job-posting-manager'), $entry_num) . '</h3>';
                    $body .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">';
                    if (!empty($entry['company_name'])) {
                        $body .= '<tr><td style="padding: 6px 0; font-weight: bold; width: 35%;">' . __('Company Name:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($entry['company_name']) . '</td></tr>';
                    }
                    if (!empty($entry['position'])) {
                        $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Position:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($entry['position']) . '</td></tr>';
                    }
                    if (!empty($entry['years'])) {
                        $body .= '<tr><td style="padding: 6px 0; font-weight: bold;">' . __('Years:', 'job-posting-manager') . '</td><td style="padding: 6px 0;">' . esc_html($entry['years']) . '</td></tr>';
                    }
                    $body .= '</table>';
                }
            }

            $body .= '</div>';
        }

        // Application Details Section - OPTIMIZED: Show only key fields to reduce email size
        $body .= '<div style="background-color: ' . esc_attr($template['details_section_bg_color']) . '; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: ' . esc_attr($template['header_text_color']) . '; margin-top: 0; font-size: 18px; border-bottom: 2px solid ' . esc_attr($template['header_color']) . '; padding-bottom: 10px;">' . esc_html($template['details_section_title']) . '</h2>';

        // Only show essential/key fields (limit to 5 most important fields to reduce email size)
        // Exclude education and employment fields as they are shown in dedicated sections above
        $exclude_fields = [
            'application_number',
            'date_of_registration',
            'applicant_number',
            'first_name',
            'last_name',
            'email',
            'email_address',
            'edu_primary_school_name',
            'edu_primary_school_address',
            'edu_primary_start_year',
            'edu_primary_end_year',
            'edu_primary_completed',
            'edu_secondary_school_name',
            'edu_secondary_school_address',
            'edu_secondary_school_type',
            'edu_secondary_start_year',
            'edu_secondary_end_year',
            'edu_secondary_completed',
            'edu_tertiary_institution_name',
            'edu_tertiary_school_address',
            'edu_tertiary_program',
            'edu_tertiary_degree_level',
            'edu_tertiary_start_year',
            'edu_tertiary_end_year',
            'edu_tertiary_status',
            'emp_company_name',
            'emp_position',
            'emp_years',
            'employment_entries'
        ];
        $priority_fields = ['phone', 'phone_number', 'mobile', 'contact_number', 'resume', 'cv', 'cover_letter', 'message', 'experience', 'qualification'];

        $fields_shown = 0;
        $max_fields = 5; // Limit to 5 fields to keep email small

        // First, show priority fields
        foreach ($priority_fields as $priority_field) {
            if ($fields_shown >= $max_fields)
                break;

            // Check variations of field name
            $field_found = false;
            $field_value = '';
            foreach ($form_data as $field_name => $field_val) {
                if (stripos($field_name, $priority_field) !== false && !in_array($field_name, $exclude_fields) && !empty($field_val)) {
                    $field_found = true;
                    $field_name_display = ucwords(str_replace(['_', '-'], ' ', $field_name));
                    if (is_array($field_val)) {
                        $field_value = implode(', ', $field_val);
                    } else {
                        $field_value = $field_val;
                    }
                    // Truncate long values
                    if (strlen($field_value) > 100) {
                        $field_value = substr($field_value, 0, 100) . '...';
                    }
                    break;
                }
            }

            if ($field_found) {
                $body .= '<p style="margin: 8px 0;"><strong>' . esc_html($field_name_display) . ':</strong> ' . esc_html($field_value) . '</p>';
                $fields_shown++;
            }
        }

        // Show count of remaining fields
        $total_fields = 0;
        foreach ($form_data as $key => $value) {
            if (!in_array($key, $exclude_fields) && !empty($value)) {
                $total_fields++;
            }
        }

        if ($total_fields > $fields_shown) {
            $remaining = $total_fields - $fields_shown;
            /* translators: %d: Number of additional form fields not shown in the email. */
            $body .= '<p style="margin: 12px 0; color: #666; font-style: italic;">' . sprintf(__('+ %d more field(s) available in admin panel', 'job-posting-manager'), $remaining) . '</p>';
        }

        $body .= '</div>';

        // Prominent call-to-action to view full details
        $body .= '<div style="margin: 25px 0; text-align: center; padding: 20px; background-color: #f0f8ff; border: 2px solid #0073aa; border-radius: 5px;">';
        $body .= '<p style="margin: 0 0 15px 0; font-size: 16px; font-weight: bold; color: #0073aa;">' . __('View Complete Application Details', 'job-posting-manager') . '</p>';
        $body .= '<a href="' . esc_url($application_link) . '" style="background-color: #0073aa; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; font-size: 16px;">' . __('View Full Application in Admin Panel', 'job-posting-manager') . '</a>';
        $body .= '</div>';

        // Action required message (from admin template settings)
        if (!empty($template['action_required_message'])) {
            $action_required_message = self::replace_placeholders($template['action_required_message'], [
                '[Job Title]' => esc_html($job_title),
                '[Full Name]' => esc_html($full_name),
                '[Application ID]' => $application_id,
            ]);
            $body .= '<div style="margin-top: 20px; padding: 15px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 3px;">';
            $body .= '<p style="margin: 0; font-size: 14px; color: #856404;">' . wp_kses_post($action_required_message) . '</p>';
            $body .= '</div>';
        }

        // Closing message (from admin template settings)
        if (!empty($template['closing_message'])) {
            $closing_message = self::replace_placeholders($template['closing_message'], [
                '[Job Title]' => esc_html($job_title),
                '[Full Name]' => esc_html($full_name),
                '[Application ID]' => $application_id,
            ]);
            $body .= '<p style="font-size: 15px; margin: 20px 0;">' . wp_kses_post($closing_message) . '</p>';
        }

        $body .= '</div>';

        // Footer (from admin template settings)
        $footer_message = self::replace_placeholders($template['footer_message'], [
            '[Job Title]' => esc_html($job_title),
            '[Full Name]' => esc_html($full_name),
            '[Application ID]' => $application_id,
        ]);
        $body .= '<div style="background-color: ' . esc_attr($template['footer_bg_color']) . '; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #666;">' . wp_kses_post($footer_message) . '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers with priority
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'X-Priority: 1', // High priority
            'Importance: High',
            'X-Mailer: Job Posting Manager'
        ];

        // Add CC and BCC from settings
        $headers = self::add_email_recipients($headers);

        // Set PHP execution time limit for email sending (if possible)
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Optional runtime guard for long SMTP requests in shared hosts.
            @set_time_limit(30); // 30 seconds for email sending
        }

        // Log start time for debugging
        $start_time = microtime(true);
        do_action('jpm_log_error', 'JPM: Starting admin notification email send to ' . $admin_email . ' at ' . gmdate('Y-m-d H:i:s'));

        // Send email and return result
        $result = wp_mail($admin_email, $subject, $body, $headers);

        // Log email sending attempt with timing
        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send admin notification email to ' . $admin_email . ' (took ' . $duration . 's)');
        } else {
            do_action('jpm_log_error', 'JPM: Successfully sent admin notification email to ' . $admin_email . ' (took ' . $duration . 's)');
        }

        return $result;
    }

    /**
     * Send OTP email for email verification
     * 
     * @param string $email User email address
     * @param string $otp 6-digit OTP code
     * @return bool True if email sent successfully, false otherwise
     */
    public static function send_otp_email($email, $otp)
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }

        // Build email subject
        /* translators: %s: Site name. */
        $subject = sprintf(__('Your Verification Code for %s', 'job-posting-manager'), get_bloginfo('name'));

        // Build email body with modern styling
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $body .= '<div style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">';
        $body .= '<h1 style="color: #ffffff; margin: 0; font-size: 24px;">' . __('Email Verification', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        $body .= '<div style="background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">';
        $body .= '<p style="font-size: 16px; margin: 0 0 20px 0;">' . __('Hello,', 'job-posting-manager') . '</p>';
        $body .= '<p style="font-size: 16px; margin: 0 0 20px 0;">' . __('Thank you for registering with us. Please use the verification code below to verify your email address:', 'job-posting-manager') . '</p>';

        // OTP display box
        $body .= '<div style="background: #f3f4f6; border: 2px dashed #2563eb; border-radius: 8px; padding: 20px; text-align: center; margin: 30px 0;">';
        $body .= '<div style="font-size: 32px; font-weight: bold; color: #2563eb; letter-spacing: 8px; font-family: monospace;">' . esc_html($otp) . '</div>';
        $body .= '</div>';

        $body .= '<p style="font-size: 14px; color: #6b7280; margin: 20px 0 0 0;">' . __('This code will expire in 10 minutes.', 'job-posting-manager') . '</p>';
        $body .= '<p style="font-size: 14px; color: #6b7280; margin: 10px 0 0 0;">' . __('If you did not request this code, please ignore this email.', 'job-posting-manager') . '</p>';
        $body .= '</div>';

        $body .= '<div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">';
        /* translators: %s: Site name. */
        $body .= '<p style="font-size: 12px; color: #9ca3af; margin: 0;">' . sprintf(__('This is an automated email from %s.', 'job-posting-manager'), get_bloginfo('name')) . '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        $result = wp_mail($email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send OTP email to ' . $email);
        }

        return $result;
    }

    /**
     * Send account creation notification to customer
     * 
     * @param int $user_id User ID
     * @param string $email User email
     * @param string $password User password
     * @param string $first_name First name
     * @param string $last_name Last name
     */
    public static function send_account_creation_notification($user_id, $email, $password, $first_name, $last_name)
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }

        $full_name = trim($first_name . ' ' . $last_name);

        // Build email subject
        /* translators: %s: Site name. */
        $subject = sprintf(__('Welcome to %s - Your Account Has Been Created', 'job-posting-manager'), get_bloginfo('name'));

        // Build email body
        $body = '<html><body>';
        /* translators: %s: Customer full name. */
        $body .= '<h2>' . sprintf(__('Welcome, %s!', 'job-posting-manager'), esc_html($full_name)) . '</h2>';
        $body .= '<p>' . __('Your account has been successfully created on our website.', 'job-posting-manager') . '</p>';
        $body .= '<hr>';
        $body .= '<h3>' . __('Your Account Details', 'job-posting-manager') . '</h3>';
        $body .= '<p><strong>' . __('Email:', 'job-posting-manager') . '</strong> ' . esc_html($email) . '</p>';
        $body .= '<p><strong>' . __('Password:', 'job-posting-manager') . '</strong> ' . esc_html($password) . '</p>';
        $login_url = home_url('/sign-in/');
        $body .= '<p><strong>' . __('Login URL:', 'job-posting-manager') . '</strong> <a href="' . esc_url($login_url) . '">' . esc_html($login_url) . '</a></p>';
        $body .= '<hr>';
        $body .= '<p><strong>' . __('Important:', 'job-posting-manager') . '</strong> ' . __('Please save this email and change your password after your first login for security purposes.', 'job-posting-manager') . '</p>';

        // Get forgot password URL - try to find page with shortcode, otherwise use default
        $forgot_password_url = home_url('/forgot-password/');
        $pages = get_pages();
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'jpm_forgot_password')) {
                $forgot_password_url = get_permalink($page->ID);
                break;
            }
        }

        $body .= '<p>' . __('Forgot your password?', 'job-posting-manager') . ' <a href="' . esc_url($forgot_password_url) . '" style="color: #0073aa; text-decoration: underline;">' . __('Click here to reset it', 'job-posting-manager') . '</a>.</p>';
        $body .= '<p style="color: #666; font-size: 12px;">' . __('This is an automated email from Job Posting Manager.', 'job-posting-manager') . '</p>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        $result = wp_mail($email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send account creation email to ' . $email);
        }

        return $result;
    }

    /**
     * Send new customer notification to admin
     * 
     * @param int $user_id User ID
     * @param string $email User email
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $admin_email Admin email address
     */
    public static function send_new_customer_notification($user_id, $email, $first_name, $last_name, $admin_email = '')
    {
        // Check if SMTP is available
        if (!self::is_smtp_available()) {
            do_action('jpm_log_error', 'Job Posting Manager: Email not sent - No SMTP plugin configured');
            return false;
        }

        // Get recipient email from settings if not provided
        if (empty($admin_email)) {
            $email_settings = get_option('jpm_email_settings', []);
            $admin_email = !empty($email_settings['recipient_email']) ? $email_settings['recipient_email'] : get_option('admin_email');
        }

        $full_name = trim($first_name . ' ' . $last_name);
        $user_link = admin_url('user-edit.php?user_id=' . $user_id);

        // Build email subject
        /* translators: %s: Customer full name. */
        $subject = sprintf(__('New Customer Account Created: %s', 'job-posting-manager'), $full_name);

        // Build email body
        $body = '<html><body>';
        $body .= '<h2>' . __('New Customer Account Created', 'job-posting-manager') . '</h2>';
        $body .= '<p>' . __('A new customer account has been created on your website.', 'job-posting-manager') . '</p>';
        $body .= '<hr>';
        $body .= '<h3>' . __('Customer Information', 'job-posting-manager') . '</h3>';
        $body .= '<p><strong>' . __('Name:', 'job-posting-manager') . '</strong> ' . esc_html($full_name) . '</p>';
        $body .= '<p><strong>' . __('Email:', 'job-posting-manager') . '</strong> ' . esc_html($email) . '</p>';
        $body .= '<p><strong>' . __('User ID:', 'job-posting-manager') . '</strong> ' . esc_html($user_id) . '</p>';
        $body .= '<p><strong>' . __('User Profile:', 'job-posting-manager') . '</strong> <a href="' . esc_url($user_link) . '">' . __('View User Profile', 'job-posting-manager') . '</a></p>';
        $body .= '<hr>';
        $body .= '<p style="color: #666; font-size: 12px;">' . __('This is an automated notification from Job Posting Manager.', 'job-posting-manager') . '</p>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Add CC and BCC from settings
        $headers = self::add_email_recipients($headers);

        // Send email
        $result = wp_mail($admin_email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            do_action('jpm_log_error', 'JPM: Failed to send new customer notification email to ' . $admin_email);
        }

        return $result;
    }
}