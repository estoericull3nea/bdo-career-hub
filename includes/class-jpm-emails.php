<?php
class JPM_Emails
{
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

        // Build email subject
        $subject = !empty($settings['confirmation_subject'])
            ? str_replace(['[Application ID]', '[Job Title]'], [$app_id, $job_title], $settings['confirmation_subject'])
            : sprintf(__('Application Confirmation #%s - %s', 'job-posting-manager'), $app_id, $job_title);

        // Build enhanced HTML email body
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;">';
        $body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px 5px 0 0;">';
        $body .= '<h1 style="color: #2c3e50; margin: 0;">' . __('Application Confirmation', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        $body .= '<div style="background-color: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . sprintf(__('Dear %s,', 'job-posting-manager'), esc_html($full_name)) . '</p>';
        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . __('Thank you for submitting your job application. We have successfully received your application and it is now <strong>pending</strong>.', 'job-posting-manager') . '</p>';

        $body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;">';
        $body .= '<h2 style="color: #2c3e50; margin-top: 0; font-size: 18px;">' . __('Application Details', 'job-posting-manager') . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold; width: 40%;">' . __('Application ID:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">#' . esc_html($app_id) . '</td></tr>';
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

        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . __('Our team will carefully review your application and qualifications. We will contact you via email if we need any additional information or to schedule an interview.', 'job-posting-manager') . '</p>';

        $body .= '<p style="font-size: 16px; margin-bottom: 20px;">' . __('Please keep this confirmation email for your records. If you have any questions, please feel free to contact us.', 'job-posting-manager') . '</p>';

        $body .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">';
        $body .= '<p style="margin: 0; font-size: 14px; color: #666;">' . __('Best regards,', 'job-posting-manager') . '<br>';
        $body .= '<strong>' . esc_html(get_bloginfo('name')) . '</strong></p>';
        $body .= '</div>';

        $body .= '</div>';
        $body .= '<div style="background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #666;">' . __('This is an automated confirmation email. Please do not reply to this message.', 'job-posting-manager') . '</p>';
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
            error_log('JPM: Failed to send confirmation email to ' . $customer_email);
        }

        return $result;
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
     * @param string $customer_email Customer email address
     * @param string $first_name Customer first name
     * @param string $last_name Customer last name
     */
    public static function send_admin_notification($application_id, $job_id, $form_data, $admin_email = 'palisocericson87@gmail.com', $customer_email = '', $first_name = '', $last_name = '')
    {
        // Get job details
        $job_title = get_the_title($job_id);
        $job_link = admin_url('post.php?post=' . $job_id . '&action=edit');
        $application_link = admin_url('post.php?post=' . $job_id . '&action=edit#jpm_job_applications');

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

        // Build email subject
        $subject = sprintf(__('New Job Application: %s - %s', 'job-posting-manager'), $job_title, $full_name);

        // Build enhanced HTML email body
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 700px; margin: 0 auto;">';
        $body .= '<div style="background-color: #dc3545; padding: 20px; border-radius: 5px 5px 0 0; color: #ffffff;">';
        $body .= '<h1 style="color: #ffffff; margin: 0; font-size: 24px;">' . __('New Job Application Received', 'job-posting-manager') . '</h1>';
        $body .= '</div>';

        $body .= '<div style="background-color: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">';

        // Job Information Section
        $body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: #2c3e50; margin-top: 0; font-size: 18px; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">' . __('Job Information', 'job-posting-manager') . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold; width: 35%;">' . __('Job Title:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><a href="' . esc_url($job_link) . '" style="color: #0073aa; text-decoration: none;">' . esc_html($job_title) . '</a></td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Application ID:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><strong>#' . esc_html($application_id) . '</strong></td></tr>';
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
        $body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: #2c3e50; margin-top: 0; font-size: 18px; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">' . __('Applicant Information', 'job-posting-manager') . '</h2>';
        $body .= '<table style="width: 100%; border-collapse: collapse;">';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('First Name:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($first_name) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Last Name:', 'job-posting-manager') . '</td><td style="padding: 8px 0;">' . esc_html($last_name) . '</td></tr>';
        $body .= '<tr><td style="padding: 8px 0; font-weight: bold;">' . __('Email Address:', 'job-posting-manager') . '</td><td style="padding: 8px 0;"><a href="mailto:' . esc_attr($customer_email) . '" style="color: #0073aa; text-decoration: none;">' . esc_html($customer_email) . '</a></td></tr>';
        $body .= '</table>';
        $body .= '</div>';

        // Application Details Section
        $body .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">';
        $body .= '<h2 style="color: #2c3e50; margin-top: 0; font-size: 18px; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">' . __('Application Details', 'job-posting-manager') . '</h2>';
        $body .= '<table border="1" cellpadding="12" cellspacing="0" style="border-collapse: collapse; width: 100%; background-color: #ffffff;">';
        $body .= '<tr style="background-color: #2c3e50; color: #ffffff;"><th style="text-align: left; padding: 10px; font-weight: bold;">' . __('Field', 'job-posting-manager') . '</th><th style="text-align: left; padding: 10px; font-weight: bold;">' . __('Value', 'job-posting-manager') . '</th></tr>';

        // Display form data (exclude internal fields)
        $exclude_fields = ['application_number', 'date_of_registration'];
        foreach ($form_data as $field_name => $field_value) {
            // Skip excluded fields and empty values
            if (in_array($field_name, $exclude_fields) || empty($field_value)) {
                continue;
            }

            $body .= '<tr>';
            $body .= '<td style="padding: 10px; font-weight: bold; background-color: #f8f9fa; border: 1px solid #e0e0e0;">' . esc_html(ucwords(str_replace(['_', '-'], ' ', $field_name))) . '</td>';

            // Handle different field types
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }

            // Check if it's a file URL
            if (filter_var($field_value, FILTER_VALIDATE_URL) && (strpos($field_value, '.pdf') !== false || strpos($field_value, '.doc') !== false || strpos($field_value, '.docx') !== false || strpos($field_value, '.jpg') !== false || strpos($field_value, '.png') !== false)) {
                $body .= '<td style="padding: 10px; border: 1px solid #e0e0e0;"><a href="' . esc_url($field_value) . '" style="color: #0073aa; text-decoration: none; font-weight: bold;">' . __('Download File', 'job-posting-manager') . '</a></td>';
            } else {
                $body .= '<td style="padding: 10px; border: 1px solid #e0e0e0;">' . nl2br(esc_html($field_value)) . '</td>';
            }

            $body .= '</tr>';
        }

        $body .= '</table>';
        $body .= '</div>';

        $body .= '<div style="margin-top: 20px; padding: 15px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 3px;">';
        $body .= '<p style="margin: 0; font-size: 14px; color: #856404;"><strong>' . __('Action Required:', 'job-posting-manager') . '</strong> ' . __('Please review this application and update its status in the admin panel.', 'job-posting-manager') . '</p>';
        $body .= '</div>';

        $body .= '</div>';
        $body .= '<div style="background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; border: 1px solid #e0e0e0; border-top: none;">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #666;">' . __('This is an automated notification from Job Posting Manager.', 'job-posting-manager') . '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email and return result
        $result = wp_mail($admin_email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            error_log('JPM: Failed to send admin notification email to ' . $admin_email);
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
        $full_name = trim($first_name . ' ' . $last_name);

        // Build email subject
        $subject = sprintf(__('Welcome to %s - Your Account Has Been Created', 'job-posting-manager'), get_bloginfo('name'));

        // Build email body
        $body = '<html><body>';
        $body .= '<h2>' . __('Welcome, ' . esc_html($full_name) . '!', 'job-posting-manager') . '</h2>';
        $body .= '<p>' . __('Your account has been successfully created on our website.', 'job-posting-manager') . '</p>';
        $body .= '<hr>';
        $body .= '<h3>' . __('Your Account Details', 'job-posting-manager') . '</h3>';
        $body .= '<p><strong>' . __('Email:', 'job-posting-manager') . '</strong> ' . esc_html($email) . '</p>';
        $body .= '<p><strong>' . __('Password:', 'job-posting-manager') . '</strong> ' . esc_html($password) . '</p>';
        $body .= '<p><strong>' . __('Login URL:', 'job-posting-manager') . '</strong> <a href="' . esc_url(wp_login_url()) . '">' . esc_html(wp_login_url()) . '</a></p>';
        $body .= '<hr>';
        $body .= '<p><strong>' . __('Important:', 'job-posting-manager') . '</strong> ' . __('Please save this email and change your password after your first login for security purposes.', 'job-posting-manager') . '</p>';
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
            error_log('JPM: Failed to send account creation email to ' . $email);
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
    public static function send_new_customer_notification($user_id, $email, $first_name, $last_name, $admin_email = 'palisocericson87@gmail.com')
    {
        $full_name = trim($first_name . ' ' . $last_name);
        $user_link = admin_url('user-edit.php?user_id=' . $user_id);

        // Build email subject
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

        // Send email
        $result = wp_mail($admin_email, $subject, $body, $headers);

        // Log email sending attempt
        if (!$result) {
            error_log('JPM: Failed to send new customer notification email to ' . $admin_email);
        }

        return $result;
    }
}