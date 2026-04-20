<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core Database Operations
 * 
 * Handles all database operations for job applications
 */
class JPM_Database
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
     * Turn stored form data values into one lowercase string for search (arrays from repeaters, checkboxes, etc.).
     *
     * @param mixed $value
     * @return string
     */
    private static function form_value_to_search_string($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_scalar($value)) {
            return strtolower((string) $value);
        }
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($v) use (&$parts) {
                if (is_scalar($v) && (string) $v !== '') {
                    $parts[] = (string) $v;
                }
            });

            return strtolower(implode(' ', $parts));
        }

        return '';
    }

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
            whitelisted tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_application (user_id, job_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add new columns on existing installs (dbDelta does not always alter).
     */
    public static function maybe_upgrade_schema()
    {
        global $wpdb;
        $table = self::get_validated_applications_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from validated prefix + literal.
        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'whitelisted'", ARRAY_A);
        if (!empty($exists)) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN whitelisted tinyint(1) NOT NULL DEFAULT 0");
    }

    /**
     * Mark an application as whitelisted or not.
     *
     * @param int  $id          Application ID.
     * @param bool $whitelisted Whether to whitelist.
     * @return bool|int|false Rows updated, or false on failure.
     */
    public static function set_whitelisted($id, $whitelisted)
    {
        global $wpdb;
        $table = self::get_validated_applications_table();
        $val = $whitelisted ? 1 : 0;

        return $wpdb->update(
            $table,
            ['whitelisted' => $val],
            ['id' => absint($id)],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Whether stored status means the user may submit a new application for the same job (re-apply).
     *
     * @param string $status_slug Value from job_applications.status
     * @return bool
     */
    private static function is_rejected_application_status($status_slug)
    {
        $status_slug = (string) $status_slug;
        if ($status_slug === '') {
            return false;
        }
        if (class_exists('JPM_Status_Manager') && JPM_Status_Manager::is_rejected_status($status_slug)) {
            return true;
        }

        return strtolower($status_slug) === 'rejected';
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
        $table = self::get_validated_applications_table();
        $user_id = absint($user_id);
        $job_id = absint($job_id);

        // Check for duplicate (only for logged-in users). Rejected applications may re-apply (same DB row updated).
        if ($user_id > 0) {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$table} WHERE user_id = %d AND job_id = %d LIMIT 1",
                    $user_id,
                    $job_id
                )
            );
            if ($existing_row) {
                if (!self::is_rejected_application_status($existing_row->status)) {
                    return new WP_Error('duplicate', __('You have already applied for this job.', 'job-posting-manager'));
                }

                $existing_id = (int) $existing_row->id;
                $updated = $wpdb->update(
                    $table,
                    [
                        'resume_file_path' => $resume_path,
                        'notes' => sanitize_textarea_field($notes),
                        'status' => 'pending',
                        'application_date' => current_time('mysql'),
                    ],
                    ['id' => $existing_id],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );

                if ($updated === false) {
                    return new WP_Error('db_error', __('Failed to update application.', 'job-posting-manager'));
                }

                delete_option('jpm_application_rejection_details_' . $existing_id);

                return $existing_id;
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
        $updated = $wpdb->update(
            $wpdb->prefix . 'job_applications',
            ['status' => sanitize_text_field($status)],
            ['id' => absint($id)]
        );
        return $updated;
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
        $table = self::get_validated_applications_table();
        $filters = is_array($filters) ? $filters : [];
        $normalized_filters = [
            'status' => isset($filters['status']) ? sanitize_text_field((string) $filters['status']) : '',
            'job_id' => isset($filters['job_id']) ? absint($filters['job_id']) : 0,
            'user_id' => isset($filters['user_id']) ? absint($filters['user_id']) : 0,
            'search' => isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '',
            'whitelisted_only' => !empty($filters['whitelisted_only']),
        ];

        $where = [];
        $where_values = [];

        if (!empty($normalized_filters['status'])) {
            $where[] = "status = %s";
            $where_values[] = $normalized_filters['status'];
        }

        if (!empty($normalized_filters['job_id'])) {
            $where[] = "job_id = %d";
            $where_values[] = $normalized_filters['job_id'];
        }

        if (!empty($normalized_filters['user_id'])) {
            $where[] = "user_id = %d";
            $where_values[] = $normalized_filters['user_id'];
        }

        if (!empty($normalized_filters['whitelisted_only'])) {
            $where[] = 'whitelisted = 1';
        }

        if (!empty($where)) {
            $query = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY application_date DESC';
            if (!empty($where_values)) {
                $applications = $wpdb->get_results($wpdb->prepare($query, ...$where_values));
            } else {
                $applications = $wpdb->get_results($query);
            }
        } else {
            $applications = $wpdb->get_results("SELECT * FROM {$table} ORDER BY application_date DESC");
        }

        // If search term is provided, filter by searching in form data
        if (!empty($normalized_filters['search'])) {
            $applications = self::filter_applications_by_search($applications, $normalized_filters['search']);
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
            'first_name',
            'firstname',
            'fname',
            'first-name',
            'given_name',
            'givenname',
            'given-name',
            'given name',
            'middle_name',
            'middlename',
            'mname',
            'middle-name',
            'middle name',
            'last_name',
            'lastname',
            'lname',
            'last-name',
            'surname',
            'family_name',
            'familyname',
            'family-name',
            'family name',
            'email',
            'email_address',
            'e-mail',
            'email-address',
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
                    $field_value = self::form_value_to_search_string($form_data[$field_name]);
                    if ($field_value !== '' && strpos($field_value, $search_term) !== false) {
                        $match = true;
                        break;
                    }
                }
            }

            // Also try case-insensitive partial matching on all form data
            if (!$match) {
                foreach ($form_data as $field_name => $field_value) {
                    $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', (string) $field_name));
                    $field_value_lower = self::form_value_to_search_string($field_value);

                    if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given', 'middlename', 'mname', 'middle', 'lastname', 'lname', 'surname', 'familyname', 'family', 'email', 'applicationnumber'])) {
                        if ($field_value_lower !== '' && strpos($field_value_lower, $search_term) !== false) {
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
        $table = self::get_validated_applications_table();
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }

        $application = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        return $application;
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
        $updated = $wpdb->update(
            $wpdb->prefix . 'job_applications',
            ['notes' => sanitize_textarea_field($notes)],
            ['id' => absint($id)]
        );
        return $updated;
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
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'job_applications',
            ['id' => absint($id)],
            ['%d']
        );
        return $deleted;
    }
}
