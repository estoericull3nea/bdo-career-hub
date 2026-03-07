<?php
/**
 * Core Database Operations
 * 
 * Handles all database operations for job applications
 */
class JPM_Database
{
    /**
     * Create database tables
     */
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

    /**
     * Insert a new application
     * 
     * @param int $user_id User ID
     * @param int $job_id Job ID
     * @param string $resume_path Resume file path
     * @param string $notes Application notes/form data
     * @return int|WP_Error Application ID on success, WP_Error on failure
     */
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
        
        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'job_id' => $job_id,
            'resume_file_path' => $resume_path,
            'notes' => sanitize_textarea_field($notes),
            'status' => 'pending',
        ]);
        
        if ($result === false) {
            return new WP_Error('db_error', __('Failed to insert application.', 'job-posting-manager'));
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Update application status
     * 
     * @param int $id Application ID
     * @param string $status New status
     * @return bool|int Number of rows updated, or false on error
     */
    public static function update_status($id, $status)
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'job_applications',
            ['status' => sanitize_text_field($status)],
            ['id' => $id]
        );
    }

    /**
     * Get applications with filters
     * 
     * @param array $filters Filter options (status, job_id, user_id, search)
     * @return array Array of application objects
     */
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
        
        if (!empty($filters['user_id'])) {
            $where[] = "user_id = %d";
            $where_values[] = $filters['user_id'];
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

        $applications = $wpdb->get_results($query);

        // If search term is provided, filter by searching in form data
        if (!empty($filters['search'])) {
            $applications = self::filter_applications_by_search($applications, $filters['search']);
        }

        return $applications;
    }

    /**
     * Filter applications by search term
     * 
     * @param array $applications Applications to filter
     * @param string $search_term Search term
     * @return array Filtered applications
     */
    private static function filter_applications_by_search($applications, $search_term)
    {
        $search_term = strtolower(trim($search_term));
        $filtered_applications = [];

        $search_fields = [
            'first_name', 'firstname', 'fname', 'first-name',
            'given_name', 'givenname', 'given-name', 'given name',
            'middle_name', 'middlename', 'mname', 'middle-name', 'middle name',
            'last_name', 'lastname', 'lname', 'last-name',
            'surname', 'family_name', 'familyname', 'family-name', 'family name',
            'email', 'email_address', 'e-mail', 'email-address',
            'application_number'
        ];

        // Optimize: Batch fetch all user data to avoid N+1 queries
        $user_ids = [];
        foreach ($applications as $application) {
            if ($application->user_id > 0) {
                $user_ids[] = $application->user_id;
            }
        }
        $user_ids = array_unique($user_ids);
        $users_data = [];
        if (!empty($user_ids)) {
            $users = get_users(['include' => $user_ids]);
            foreach ($users as $user) {
                $users_data[$user->ID] = [
                    'email' => strtolower($user->user_email),
                    'first_name' => strtolower($user->first_name),
                    'last_name' => strtolower($user->last_name),
                    'display_name' => strtolower($user->display_name)
                ];
            }
        }

        foreach ($applications as $application) {
            $form_data = json_decode($application->notes, true);
            if (!is_array($form_data)) {
                $form_data = [];
            }

            $match = false;

            // Check each search field
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

                    if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given', 'middlename', 'mname', 'middle', 'lastname', 'lname', 'surname', 'familyname', 'family', 'email', 'applicationnumber'])) {
                        if (strpos($field_value_lower, $search_term) !== false) {
                            $match = true;
                            break;
                        }
                    }
                }
            }

            // Also check user email if user_id exists (using pre-fetched data)
            if (!$match && $application->user_id > 0 && isset($users_data[$application->user_id])) {
                $user_data = $users_data[$application->user_id];
                if (
                    strpos($user_data['email'], $search_term) !== false ||
                    strpos($user_data['first_name'], $search_term) !== false ||
                    strpos($user_data['last_name'], $search_term) !== false ||
                    strpos($user_data['display_name'], $search_term) !== false
                ) {
                    $match = true;
                }
            }

            if ($match) {
                $filtered_applications[] = $application;
            }
        }

        return $filtered_applications;
    }

    /**
     * Get a single application by ID
     * 
     * @param int $id Application ID
     * @return object|null Application object or null if not found
     */
    public static function get_application($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    /**
     * Update application notes/form data
     * 
     * @param int $id Application ID
     * @param string $notes Notes/form data (JSON encoded)
     * @return bool|int Number of rows updated, or false on error
     */
    public static function update_application_notes($id, $notes)
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'job_applications',
            ['notes' => sanitize_textarea_field($notes)],
            ['id' => $id]
        );
    }

    /**
     * Delete an application
     * 
     * @param int $id Application ID
     * @return bool|int Number of rows deleted, or false on error
     */
    public static function delete_application($id)
    {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'job_applications',
            ['id' => $id],
            ['%d']
        );
    }
}
