<?php
class JPM_DB
{
    public static function create_tables()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'job_applications';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            job_id bigint(20) NOT NULL,
            application_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(50) DEFAULT 'pending',
            resume_file_path varchar(255),
            notes text,
            PRIMARY KEY (id),
            UNIQUE KEY unique_application (user_id, job_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function insert_application($user_id, $job_id, $resume_path, $notes = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        // Check for duplicate (only for logged-in users)
        if ($user_id > 0) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND job_id = %d", $user_id, $job_id));
            if ($existing) {
                return new WP_Error('duplicate', __('You have already applied for this job.', 'job-posting-manager'));
            }
        }
        return $wpdb->insert($table, [
            'user_id' => $user_id,
            'job_id' => $job_id,
            'resume_file_path' => $resume_path,
            'notes' => sanitize_textarea_field($notes),
            'status' => 'pending', // Explicitly set status to pending
        ]);
    }

    public static function update_status($id, $status)
    {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'job_applications', ['status' => sanitize_text_field($status)], ['id' => $id]);
    }

    public static function get_applications($filters = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $where = '1=1';
        if (!empty($filters['status']))
            $where .= $wpdb->prepare(" AND status = %s", $filters['status']);
        if (!empty($filters['job_id']))
            $where .= $wpdb->prepare(" AND job_id = %d", $filters['job_id']);

        // Get all applications matching status and job filters
        $applications = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY application_date DESC");

        // If search term is provided, filter by searching in form data
        if (!empty($filters['search'])) {
            $search_term = strtolower(trim($filters['search']));
            $filtered_applications = [];

            foreach ($applications as $application) {
                $form_data = json_decode($application->notes, true);
                if (!is_array($form_data)) {
                    $form_data = [];
                }

                $match = false;

                // Search in various fields
                $search_fields = [
                    // First name variations
                    'first_name',
                    'firstname',
                    'fname',
                    'first-name',
                    'given_name',
                    'givenname',
                    'given-name',
                    'given name',
                    // Middle name variations
                    'middle_name',
                    'middlename',
                    'mname',
                    'middle-name',
                    'middle name',
                    // Last name variations
                    'last_name',
                    'lastname',
                    'lname',
                    'last-name',
                    'surname',
                    'family_name',
                    'familyname',
                    'family-name',
                    'family name',
                    // Email variations
                    'email',
                    'email_address',
                    'e-mail',
                    'email-address',
                    // Application number
                    'application_number'
                ];

                // Check each field
                foreach ($search_fields as $field_name) {
                    if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                        $field_value = strtolower(strval($form_data[$field_name]));
                        if (strpos($field_value, $search_term) !== false) {
                            $match = true;
                            break;
                        }
                    }
                }

                // Also try case-insensitive partial matching on all form data
                if (!$match) {
                    foreach ($form_data as $field_name => $field_value) {
                        $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                        $field_value_lower = strtolower(strval($field_value));

                        // Check if field name matches search terms
                        if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given', 'middlename', 'mname', 'middle', 'lastname', 'lname', 'surname', 'familyname', 'family', 'email', 'applicationnumber'])) {
                            if (strpos($field_value_lower, $search_term) !== false) {
                                $match = true;
                                break;
                            }
                        }
                    }
                }

                // Also check user email if user_id exists
                if (!$match && $application->user_id > 0) {
                    $user = get_userdata($application->user_id);
                    if ($user) {
                        $user_email = strtolower($user->user_email);
                        $user_first_name = strtolower($user->first_name);
                        $user_last_name = strtolower($user->last_name);
                        $user_display_name = strtolower($user->display_name);

                        if (
                            strpos($user_email, $search_term) !== false ||
                            strpos($user_first_name, $search_term) !== false ||
                            strpos($user_last_name, $search_term) !== false ||
                            strpos($user_display_name, $search_term) !== false
                        ) {
                            $match = true;
                        }
                    }
                }

                if ($match) {
                    $filtered_applications[] = $application;
                }
            }

            return $filtered_applications;
        }

        return $applications;
    }
}