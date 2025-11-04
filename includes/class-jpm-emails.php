<?php
class JPM_Emails
{
    public static function send_confirmation($app_id)
    {
        $settings = get_option('jpm_settings');
        $subject = str_replace('[Application ID]', $app_id, $settings['confirmation_subject']);
        $body = str_replace(['[Applicant Name]', '[Job Title]'], [wp_get_current_user()->display_name, get_the_title($_POST['job_id'])], $settings['confirmation_body']);
        wp_mail(wp_get_current_user()->user_email, $subject, $body);
    }

    public static function send_status_update($app_id)
    {
        // Similar to above, fetch details and send
    }

    /**
     * Send admin notification email when a new application is submitted
     * 
     * @param int $application_id Application ID
     * @param int $job_id Job posting ID
     * @param array $form_data Form submission data
     * @param string $admin_email Admin email address
     */
    public static function send_admin_notification($application_id, $job_id, $form_data, $admin_email = 'palisocericson87@gmail.com')
    {
        // Get job details
        $job_title = get_the_title($job_id);
        $job_link = admin_url('post.php?post=' . $job_id . '&action=edit');

        // Get applicant details
        $user = wp_get_current_user();
        $applicant_name = $user->display_name;
        $applicant_email = $user->user_email;

        // Build email subject
        $subject = sprintf(__('New Job Application: %s', 'job-posting-manager'), $job_title);

        // Build email body
        $body = '<html><body>';
        $body .= '<h2>' . __('New Job Application Received', 'job-posting-manager') . '</h2>';
        $body .= '<p><strong>' . __('Job Title:', 'job-posting-manager') . '</strong> ' . esc_html($job_title) . '</p>';
        $body .= '<p><strong>' . __('Job Link:', 'job-posting-manager') . '</strong> <a href="' . esc_url($job_link) . '">' . esc_html($job_title) . '</a></p>';
        $body .= '<p><strong>' . __('Application ID:', 'job-posting-manager') . '</strong> #' . esc_html($application_id) . '</p>';
        $body .= '<hr>';
        $body .= '<h3>' . __('Applicant Information', 'job-posting-manager') . '</h3>';
        $body .= '<p><strong>' . __('Name:', 'job-posting-manager') . '</strong> ' . esc_html($applicant_name) . '</p>';
        $body .= '<p><strong>' . __('Email:', 'job-posting-manager') . '</strong> ' . esc_html($applicant_email) . '</p>';
        $body .= '<hr>';
        $body .= '<h3>' . __('Application Details', 'job-posting-manager') . '</h3>';
        $body .= '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        $body .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left; padding: 8px;">' . __('Field', 'job-posting-manager') . '</th><th style="text-align: left; padding: 8px;">' . __('Value', 'job-posting-manager') . '</th></tr>';

        // Display form data
        foreach ($form_data as $field_name => $field_value) {
            $body .= '<tr>';
            $body .= '<td style="padding: 8px; font-weight: bold;">' . esc_html(ucwords(str_replace('_', ' ', $field_name))) . '</td>';

            // Handle different field types
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }

            // Check if it's a file URL
            if (filter_var($field_value, FILTER_VALIDATE_URL) && (strpos($field_value, '.pdf') !== false || strpos($field_value, '.doc') !== false || strpos($field_value, '.docx') !== false)) {
                $body .= '<td style="padding: 8px;"><a href="' . esc_url($field_value) . '">' . __('Download File', 'job-posting-manager') . '</a></td>';
            } else {
                $body .= '<td style="padding: 8px;">' . nl2br(esc_html($field_value)) . '</td>';
            }

            $body .= '</tr>';
        }

        $body .= '</table>';
        $body .= '<hr>';
        $body .= '<p style="color: #666; font-size: 12px;">' . __('This is an automated notification from Job Posting Manager.', 'job-posting-manager') . '</p>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        wp_mail($admin_email, $subject, $body, $headers);
    }
}