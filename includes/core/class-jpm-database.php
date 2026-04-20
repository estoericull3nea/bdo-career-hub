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
        $history_table = $wpdb->prefix . 'jpm_employer_email_history';
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
            employer_first_name varchar(191) NULL,
            employer_last_name varchar(191) NULL,
            employer_phone varchar(100) NULL,
            employer_email varchar(191) NULL,
            employer_recorded_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_application (user_id, job_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $history_sql = "CREATE TABLE $history_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            application_id mediumint(9) NOT NULL,
            employer_email varchar(191) NOT NULL,
            from_email varchar(191) NOT NULL,
            subject varchar(255) NOT NULL,
            content longtext NOT NULL,
            sent_by_user_id bigint(20) NOT NULL DEFAULT 0,
            sent_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY application_id (application_id),
            KEY employer_email (employer_email)
        ) $charset_collate;";
        dbDelta($history_sql);
    }

    /**
     * Add new columns on existing installs (dbDelta does not always alter).
     */
    public static function maybe_upgrade_schema()
    {
        global $wpdb;
        $table = self::get_validated_applications_table();
        $history_table = $wpdb->prefix . 'jpm_employer_email_history';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from validated prefix + literal.
        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'whitelisted'", ARRAY_A);
        if (empty($exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated.
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN whitelisted tinyint(1) NOT NULL DEFAULT 0");
        }

        $employer_columns = [
            'employer_first_name' => 'varchar(191) NULL',
            'employer_last_name' => 'varchar(191) NULL',
            'employer_phone' => 'varchar(100) NULL',
            'employer_email' => 'varchar(191) NULL',
            'employer_recorded_at' => 'datetime NULL',
        ];
        foreach ($employer_columns as $column => $definition) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names validated.
            $col_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '" . esc_sql($column) . "'", ARRAY_A);
            if (empty($col_exists)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name.
        $history_exists = $wpdb->get_var("SHOW TABLES LIKE '{$history_table}'");
        if ($history_exists !== $history_table) {
            $charset_collate = $wpdb->get_charset_collate();
            $history_sql = "CREATE TABLE $history_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                application_id mediumint(9) NOT NULL,
                employer_email varchar(191) NOT NULL,
                from_email varchar(191) NOT NULL,
                subject varchar(255) NOT NULL,
                content longtext NOT NULL,
                sent_by_user_id bigint(20) NOT NULL DEFAULT 0,
                sent_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY application_id (application_id),
                KEY employer_email (employer_email)
            ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($history_sql);
        }
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
     * Validate and normalize a Y-m-d date string for SQL filters and form fields.
     *
     * @param mixed $raw Raw input (typically from GET/POST).
     * @return string Empty string if invalid, otherwise Y-m-d.
     */
    public static function normalize_application_filter_date($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false) {
            return '';
        }

        return $dt->format('Y-m-d');
    }

    /**
     * Get applications with filters
     * 
     * @param array $filters Filter options (status, job_id, user_id, search, whitelisted_only, location, submitted_on, submitted_from, submitted_to). Location matches job posting meta "location". Dates are Y-m-d on application_date (calendar day in DB). If submitted_on is set, submitted_from/to are ignored.
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
            'location' => isset($filters['location']) ? sanitize_text_field((string) $filters['location']) : '',
            'submitted_on' => self::normalize_application_filter_date(isset($filters['submitted_on']) ? $filters['submitted_on'] : ''),
            'submitted_from' => self::normalize_application_filter_date(isset($filters['submitted_from']) ? $filters['submitted_from'] : ''),
            'submitted_to' => self::normalize_application_filter_date(isset($filters['submitted_to']) ? $filters['submitted_to'] : ''),
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

        if ($normalized_filters['submitted_on'] !== '') {
            $where[] = 'DATE(application_date) = %s';
            $where_values[] = $normalized_filters['submitted_on'];
        } else {
            if ($normalized_filters['submitted_from'] !== '') {
                $where[] = 'DATE(application_date) >= %s';
                $where_values[] = $normalized_filters['submitted_from'];
            }
            if ($normalized_filters['submitted_to'] !== '') {
                $where[] = 'DATE(application_date) <= %s';
                $where_values[] = $normalized_filters['submitted_to'];
            }
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

        if ($normalized_filters['location'] !== '') {
            $applications = self::filter_applications_by_location($applications, $normalized_filters['location']);
        }

        return $applications;
    }

    /**
     * Location from the job posting edit screen (post meta `location`).
     *
     * @param int $job_id Job post ID.
     * @return string Trimmed location or empty string.
     */
    public static function get_job_posting_location($job_id)
    {
        $job_id = absint($job_id);
        if ($job_id <= 0) {
            return '';
        }

        return trim((string) get_post_meta($job_id, 'location', true));
    }

    /**
     * Sorted list of distinct job-listing locations for a set of application rows.
     *
     * @param array $applications Objects from get_results (must include job_id).
     * @return string[]
     */
    public static function list_distinct_locations_from_applications($applications)
    {
        if (!is_array($applications) || empty($applications)) {
            return [];
        }

        $by_lower = [];
        foreach ($applications as $application) {
            if (!isset($application->job_id)) {
                continue;
            }
            $loc = self::get_job_posting_location((int) $application->job_id);
            if ($loc === '') {
                continue;
            }
            $lower = strtolower($loc);
            if (!isset($by_lower[$lower])) {
                $by_lower[$lower] = $loc;
            }
        }

        $list = array_values($by_lower);
        usort($list, 'strnatcasecmp');

        return $list;
    }

    /**
     * @param array  $applications Application objects.
     * @param string $location     Selected job listing location (matched case-insensitively).
     * @return array
     */
    private static function filter_applications_by_location($applications, $location)
    {
        $location = trim((string) $location);
        if ($location === '' || !is_array($applications)) {
            return $applications;
        }

        $want = strtolower($location);
        $filtered = [];

        foreach ($applications as $application) {
            if (!isset($application->job_id)) {
                continue;
            }
            $app_location = self::get_job_posting_location((int) $application->job_id);
            if ($want === strtolower($app_location)) {
                $filtered[] = $application;
            }
        }

        return $filtered;
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
     * Save employer / welfare-check contact for a whitelisted application.
     *
     * @param int   $application_id Application row ID.
     * @param array $fields         Keys: employer_first_name, employer_last_name, employer_phone, employer_email.
     * @return true|WP_Error
     */
    public static function update_application_employer_welfare($application_id, array $fields)
    {
        global $wpdb;
        $table = self::get_validated_applications_table();
        $application_id = absint($application_id);
        if ($application_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid application.', 'job-posting-manager'));
        }

        $row = self::get_application($application_id);
        if (!$row || (int) $row->whitelisted !== 1) {
            return new WP_Error('not_whitelisted', __('That application is not whitelisted.', 'job-posting-manager'));
        }

        $first = isset($fields['employer_first_name']) ? sanitize_text_field((string) $fields['employer_first_name']) : '';
        $last = isset($fields['employer_last_name']) ? sanitize_text_field((string) $fields['employer_last_name']) : '';
        $phone = isset($fields['employer_phone']) ? sanitize_text_field((string) $fields['employer_phone']) : '';
        $email_raw = isset($fields['employer_email']) ? trim((string) $fields['employer_email']) : '';
        $email = $email_raw !== '' ? sanitize_email($email_raw) : '';

        if ($first === '' || $last === '' || $phone === '' || $email_raw === '') {
            return new WP_Error('missing_fields', __('Please fill in all employer fields.', 'job-posting-manager'));
        }
        if ($email === '' || !is_email($email)) {
            return new WP_Error('invalid_email', __('Please enter a valid employer email address.', 'job-posting-manager'));
        }

        $updated = $wpdb->update(
            $table,
            [
                'employer_first_name' => $first,
                'employer_last_name' => $last,
                'employer_phone' => $phone,
                'employer_email' => $email,
                'employer_recorded_at' => current_time('mysql'),
            ],
            [
                'id' => $application_id,
                'whitelisted' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            return new WP_Error('db_error', __('Could not save employer details.', 'job-posting-manager'));
        }

        return true;
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

    /**
     * Save employer contact email history entry.
     *
     * @param int    $application_id Application ID.
     * @param string $employer_email Recipient email.
     * @param string $from_email     Sender email.
     * @param string $subject        Email subject.
     * @param string $content        Email body content.
     * @param int    $sent_by_user   Admin user ID.
     * @return bool
     */
    public static function add_employer_email_history($application_id, $employer_email, $from_email, $subject, $content, $sent_by_user = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'jpm_employer_email_history';
        $application_id = absint($application_id);
        $sent_by_user = absint($sent_by_user);
        $employer_email = sanitize_email((string) $employer_email);
        $from_email = sanitize_email((string) $from_email);
        $subject = sanitize_text_field((string) $subject);
        $content = wp_kses_post((string) $content);

        if ($application_id <= 0 || !is_email($employer_email) || !is_email($from_email) || $subject === '' || wp_strip_all_tags($content) === '') {
            return false;
        }

        $result = $wpdb->insert(
            $table,
            [
                'application_id' => $application_id,
                'employer_email' => $employer_email,
                'from_email' => $from_email,
                'subject' => $subject,
                'content' => $content,
                'sent_by_user_id' => $sent_by_user,
                'sent_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Get employer contact history for an application.
     *
     * @param int         $application_id Application ID.
     * @param string|null $employer_email Optional employer email filter.
     * @return array<int, array<string, mixed>>
     */
    public static function get_employer_email_history($application_id, $employer_email = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'jpm_employer_email_history';
        $application_id = absint($application_id);
        if ($application_id <= 0) {
            return [];
        }

        if ($employer_email !== null && $employer_email !== '') {
            $email = sanitize_email((string) $employer_email);
            if (!is_email($email)) {
                return [];
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE application_id = %d AND employer_email = %s ORDER BY sent_at DESC, id DESC",
                    $application_id,
                    $email
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE application_id = %d ORDER BY sent_at DESC, id DESC",
                    $application_id
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        return array_map(function ($row) {
            $sent_by = isset($row['sent_by_user_id']) ? absint($row['sent_by_user_id']) : 0;
            $sent_by_name = '';
            if ($sent_by > 0) {
                $user = get_userdata($sent_by);
                $sent_by_name = $user ? (string) $user->display_name : '';
            }

            return [
                'id' => isset($row['id']) ? absint($row['id']) : 0,
                'application_id' => isset($row['application_id']) ? absint($row['application_id']) : 0,
                'employer_email' => isset($row['employer_email']) ? sanitize_email((string) $row['employer_email']) : '',
                'from_email' => isset($row['from_email']) ? sanitize_email((string) $row['from_email']) : '',
                'subject' => isset($row['subject']) ? sanitize_text_field((string) $row['subject']) : '',
                'content' => isset($row['content']) ? wp_kses_post((string) $row['content']) : '',
                'sent_by_user_id' => $sent_by,
                'sent_by_name' => $sent_by_name,
                'sent_at' => isset($row['sent_at']) ? sanitize_text_field((string) $row['sent_at']) : '',
                'sent_at_display' => (isset($row['sent_at']) && (string) $row['sent_at'] !== '')
                    ? date_i18n(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        strtotime((string) $row['sent_at'])
                    )
                    : '',
            ];
        }, $rows);
    }
}
