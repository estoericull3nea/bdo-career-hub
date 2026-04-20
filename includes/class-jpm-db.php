<?php
if (!defined('ABSPATH')) {
    exit;
}

class JPM_Admin
{
    /**
     * Get validated applications table name.
     *
     * @return string
     */
    private function get_validated_applications_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $expected_pattern = '/^' . preg_quote($wpdb->prefix, '/') . 'job_applications$/';

        if (!preg_match($expected_pattern, $table)) {
            return $wpdb->prefix . 'job_applications';
        }

        return $table;
    }

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_job_meta']);
        add_action('wp_ajax_jpm_bulk_update', [$this, 'bulk_update']);
        add_filter('the_content', [$this, 'display_job_details'], 10);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_uploader']);
        add_filter('the_title', [$this, 'display_company_image_with_title'], 10, 2);
        add_action('template_redirect', [$this, 'restrict_draft_job_access']);
        add_action('wp_ajax_jpm_update_application_status', [$this, 'update_application_status']);
        add_action('wp_ajax_jpm_get_medical_details', [$this, 'get_medical_details_ajax']);
        add_action('wp_ajax_jpm_save_medical_details', [$this, 'save_medical_details_ajax']);
        add_action('wp_ajax_jpm_get_rejection_details', [$this, 'get_rejection_details_ajax']);
        add_action('wp_ajax_jpm_save_rejection_details', [$this, 'save_rejection_details_ajax']);
        add_action('wp_ajax_jpm_get_interview_details', [$this, 'get_interview_details_ajax']);
        add_action('wp_ajax_jpm_save_interview_details', [$this, 'save_interview_details_ajax']);
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_init', [$this, 'handle_import']);
        add_action('admin_init', [$this, 'handle_print'], 1); // Priority 1 to run early
        add_action('admin_init', [$this, 'handle_status_actions'], 1);
        add_action('wp_ajax_jpm_get_chart_data', [$this, 'get_chart_data_ajax']);
        add_action('load-edit.php', [$this, 'redirect_job_postings_list']);
        add_action('admin_notices', [$this, 'display_expiration_duration_error']);
        add_action('admin_post_jpm_update_expiration', [$this, 'handle_update_expiration']);
        add_action('admin_post_jpm_mark_expired', [$this, 'handle_mark_expired']);
        add_action('admin_post_jpm_delete_job', [$this, 'handle_delete_job']);
        add_action('admin_post_jpm_delete_application', [$this, 'handle_delete_application']);
        add_action('admin_post_jpm_whitelist_application', [$this, 'handle_whitelist_application']);
        add_action('admin_post_jpm_unwhitelist_application', [$this, 'handle_unwhitelist_application']);
        add_action('admin_post_jpm_save_employer_welfare', [$this, 'handle_save_employer_welfare']);
        add_action('admin_post_jpm_contact_employer_welfare', [$this, 'handle_contact_employer_welfare']);

        // Removed cache-related hooks
    }

    /**
     * Clear caches when post status changes
     */
    public function clear_caches_on_status_change($new_status, $old_status, $post)
    {
        return;
    }

    /**
     * Clear caches when post is deleted
     */
    public function clear_caches_on_delete($post_id)
    {
        return;
    }

    /**
     * Prevent direct access to the default Job Postings list page since it has been removed from the menu.
     * Redirects to the dashboard for consistency.
     */
    public function redirect_job_postings_list()
    {
        if (isset($_GET['post_type']) && sanitize_text_field(wp_unslash($_GET['post_type'])) === 'job_posting') {
            wp_safe_redirect(admin_url('admin.php?page=jpm-dashboard'));
            exit;
        }
    }

    public function add_menu()
    {
        add_menu_page(__('Job Postings', 'job-posting-manager'), __('Job Postings', 'job-posting-manager'), 'manage_options', 'jpm-dashboard', [$this, 'dashboard_page'], 'dashicons-businessman');
        add_submenu_page('jpm-dashboard', __('Dashboard', 'job-posting-manager'), __('Dashboard', 'job-posting-manager'), 'manage_options', 'jpm-dashboard', [$this, 'dashboard_page']);
        add_submenu_page('jpm-dashboard', __('Job Listings', 'job-posting-manager'), __('Job Listings', 'job-posting-manager'), 'manage_options', 'jpm-job-listings', [$this, 'job_listings_page']);
        add_submenu_page('jpm-dashboard', __('Add New Job', 'job-posting-manager'), __('Add New Job', 'job-posting-manager'), 'manage_options', 'post-new.php?post_type=job_posting');
        add_submenu_page('jpm-dashboard', __('Applications', 'job-posting-manager'), __('Applications', 'job-posting-manager'), 'manage_options', 'jpm-applications', [$this, 'applications_page']);
        add_submenu_page('jpm-dashboard', __('Whitelisted Applications', 'job-posting-manager'), __('Whitelisted', 'job-posting-manager'), 'manage_options', 'jpm-whitelisted-applications', [$this, 'whitelisted_applications_page']);
        add_submenu_page('jpm-dashboard', __('Status Management', 'job-posting-manager'), __('Status Management', 'job-posting-manager'), 'manage_options', 'jpm-status-management', [$this, 'status_management_page']);
    }

    /**
     * Handle updating a job's expiration from the Job Listings list table.
     * Runs via admin-post.php to avoid "headers already sent" issues.
     */
    public function handle_update_expiration()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_expiration_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_expiration_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_update_expiration')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $job_id = isset($_POST['job_id']) ? absint(wp_unslash($_POST['job_id'])) : 0;
        $duration = isset($_POST['expiration_duration']) ? absint(wp_unslash($_POST['expiration_duration'])) : 0;
        $unit = isset($_POST['expiration_unit']) ? sanitize_text_field(wp_unslash($_POST['expiration_unit'])) : '';

        $allowed_units = ['minutes', 'hours', 'days', 'months'];
        if ($job_id > 0 && $duration > 0 && in_array($unit, $allowed_units, true)) {
            $current_time = current_time('timestamp');
            $expiration_timestamp = $current_time;

            switch ($unit) {
                case 'minutes':
                    $expiration_timestamp = $current_time + ($duration * 60);
                    break;
                case 'hours':
                    $expiration_timestamp = $current_time + ($duration * 60 * 60);
                    break;
                case 'days':
                    $expiration_timestamp = $current_time + ($duration * 24 * 60 * 60);
                    break;
                case 'months':
                    $expiration_timestamp = strtotime('+' . $duration . ' months', $current_time);
                    break;
            }

            update_post_meta($job_id, 'expiration_duration', $duration);
            update_post_meta($job_id, 'expiration_unit', $unit);
            update_post_meta($job_id, 'expiration_date', $expiration_timestamp);
            update_post_meta($job_id, 'expiration_date_formatted', date('Y-m-d H:i:s', $expiration_timestamp));
        }

        $redirect_url = add_query_arg(
            [
                'page' => 'jpm-job-listings',
                'updated_expiration' => '1',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Mark a job as expired by setting expiration_date to "now (or slightly in the past)".
     * Runs via admin-post.php to avoid "headers already sent" issues.
     */
    public function handle_mark_expired()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_mark_expired_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_mark_expired_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_mark_expired')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $job_id = isset($_POST['job_id']) ? absint(wp_unslash($_POST['job_id'])) : 0;
        if ($job_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=jpm-job-listings'));
            exit;
        }

        $current_time = current_time('timestamp');
        // Set to slightly in the past to ensure it is treated as expired consistently.
        $expiration_timestamp = $current_time - 1;

        update_post_meta($job_id, 'expiration_date', $expiration_timestamp);
        update_post_meta($job_id, 'expiration_date_formatted', date('Y-m-d H:i:s', $expiration_timestamp));

        $redirect_url = add_query_arg(
            [
                'page' => 'jpm-job-listings',
                'marked_expired' => '1',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Permanently delete a job posting and its application rows from the Job Listings screen.
     */
    public function handle_delete_job()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_delete_job_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_delete_job_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_delete_job')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $job_id = isset($_POST['job_id']) ? absint(wp_unslash($_POST['job_id'])) : 0;
        if ($job_id <= 0 || get_post_type($job_id) !== 'job_posting') {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'jpm-job-listings',
                        'job_delete_error' => '1',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if (!wp_delete_post($job_id, true)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'jpm-job-listings',
                        'job_delete_error' => '1',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        global $wpdb;
        $table = $this->get_validated_applications_table();
        $wpdb->delete($table, ['job_id' => $job_id], ['%d']);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'jpm-job-listings',
                    'job_deleted' => '1',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Permanently delete a single application from the Applications screen.
     */
    public function handle_delete_application()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_delete_application_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_delete_application_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_delete_application')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;

        $redirect_args = [
            'page' => 'jpm-applications',
            'application_delete_error' => '1',
        ];
        if (isset($_POST['jpm_return_search']) && $_POST['jpm_return_search'] !== '') {
            $redirect_args['search'] = sanitize_text_field(wp_unslash($_POST['jpm_return_search']));
        }
        if (isset($_POST['jpm_return_job_id'])) {
            $rj = absint(wp_unslash($_POST['jpm_return_job_id']));
            if ($rj > 0) {
                $redirect_args['job_id'] = $rj;
            }
        }
        if (isset($_POST['jpm_return_status']) && $_POST['jpm_return_status'] !== '') {
            $redirect_args['status'] = sanitize_text_field(wp_unslash($_POST['jpm_return_status']));
        }

        if ($application_id <= 0) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $application = JPM_Database::get_application($application_id);
        if (!$application) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $deleted = JPM_DB::delete_application($application_id);
        if (!$deleted) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        unset($redirect_args['application_delete_error']);
        $redirect_args['application_deleted'] = '1';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Add an accepted application to the whitelist (admin-post).
     */
    public function handle_whitelist_application()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_whitelist_application_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_whitelist_application_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_whitelist_application')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;

        $redirect_args = [
            'page' => 'jpm-applications',
            'whitelist_error' => '1',
        ];
        if (isset($_POST['jpm_return_search']) && $_POST['jpm_return_search'] !== '') {
            $redirect_args['search'] = sanitize_text_field(wp_unslash($_POST['jpm_return_search']));
        }
        if (isset($_POST['jpm_return_job_id'])) {
            $rj = absint(wp_unslash($_POST['jpm_return_job_id']));
            if ($rj > 0) {
                $redirect_args['job_id'] = $rj;
            }
        }
        if (isset($_POST['jpm_return_status']) && $_POST['jpm_return_status'] !== '') {
            $redirect_args['status'] = sanitize_text_field(wp_unslash($_POST['jpm_return_status']));
        }

        if ($application_id <= 0) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $application = JPM_Database::get_application($application_id);
        if (!$application || strtolower((string) $application->status) !== 'accepted') {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $updated = JPM_DB::set_whitelisted($application_id, true);
        if ($updated === false) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        unset($redirect_args['whitelist_error']);
        $redirect_args['whitelist_added'] = '1';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Remove an application from the whitelist (admin-post).
     */
    public function handle_unwhitelist_application()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_unwhitelist_application_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_unwhitelist_application_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_unwhitelist_application')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;

        $redirect_args = [
            'page' => 'jpm-whitelisted-applications',
            'whitelist_error' => '1',
        ];
        if (isset($_POST['jpm_return_search']) && $_POST['jpm_return_search'] !== '') {
            $redirect_args['search'] = sanitize_text_field(wp_unslash($_POST['jpm_return_search']));
        }
        if (isset($_POST['jpm_return_job_id'])) {
            $rj = absint(wp_unslash($_POST['jpm_return_job_id']));
            if ($rj > 0) {
                $redirect_args['job_id'] = $rj;
            }
        }
        if (isset($_POST['jpm_return_location']) && $_POST['jpm_return_location'] !== '') {
            $redirect_args['location'] = sanitize_text_field(wp_unslash($_POST['jpm_return_location']));
        }
        if (isset($_POST['jpm_return_submitted_on']) && $_POST['jpm_return_submitted_on'] !== '') {
            $on = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_on']));
            if ($on !== '') {
                $redirect_args['submitted_on'] = $on;
            }
        }
        if (isset($_POST['jpm_return_submitted_from']) && $_POST['jpm_return_submitted_from'] !== '') {
            $from = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_from']));
            if ($from !== '') {
                $redirect_args['submitted_from'] = $from;
            }
        }
        if (isset($_POST['jpm_return_submitted_to']) && $_POST['jpm_return_submitted_to'] !== '') {
            $to = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_to']));
            if ($to !== '') {
                $redirect_args['submitted_to'] = $to;
            }
        }

        if ($application_id <= 0) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $application = JPM_Database::get_application($application_id);
        if (!$application) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $updated = JPM_DB::set_whitelisted($application_id, false);
        if ($updated === false) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        unset($redirect_args['whitelist_error']);
        $redirect_args['whitelist_removed'] = '1';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Save employer welfare-check fields for a whitelisted application (admin-post).
     */
    public function handle_save_employer_welfare()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_save_employer_welfare_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_save_employer_welfare_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_save_employer_welfare')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $redirect_args = [
            'page' => 'jpm-whitelisted-applications',
            'employer_error' => '1',
        ];
        if (isset($_POST['jpm_return_search']) && $_POST['jpm_return_search'] !== '') {
            $redirect_args['search'] = sanitize_text_field(wp_unslash($_POST['jpm_return_search']));
        }
        if (isset($_POST['jpm_return_job_id'])) {
            $rj = absint(wp_unslash($_POST['jpm_return_job_id']));
            if ($rj > 0) {
                $redirect_args['job_id'] = $rj;
            }
        }
        if (isset($_POST['jpm_return_location']) && $_POST['jpm_return_location'] !== '') {
            $redirect_args['location'] = sanitize_text_field(wp_unslash($_POST['jpm_return_location']));
        }
        if (isset($_POST['jpm_return_submitted_on']) && $_POST['jpm_return_submitted_on'] !== '') {
            $on = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_on']));
            if ($on !== '') {
                $redirect_args['submitted_on'] = $on;
            }
        }
        if (isset($_POST['jpm_return_submitted_from']) && $_POST['jpm_return_submitted_from'] !== '') {
            $from = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_from']));
            if ($from !== '') {
                $redirect_args['submitted_from'] = $from;
            }
        }
        if (isset($_POST['jpm_return_submitted_to']) && $_POST['jpm_return_submitted_to'] !== '') {
            $to = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_to']));
            if ($to !== '') {
                $redirect_args['submitted_to'] = $to;
            }
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;
        if ($application_id <= 0) {
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $fields = [
            'employer_first_name' => isset($_POST['employer_first_name']) ? wp_unslash($_POST['employer_first_name']) : '',
            'employer_last_name' => isset($_POST['employer_last_name']) ? wp_unslash($_POST['employer_last_name']) : '',
            'employer_phone' => isset($_POST['employer_phone']) ? wp_unslash($_POST['employer_phone']) : '',
            'employer_email' => isset($_POST['employer_email']) ? wp_unslash($_POST['employer_email']) : '',
        ];

        $result = JPM_DB::update_application_employer_welfare($application_id, $fields);
        if (is_wp_error($result)) {
            set_transient(
                'jpm_employer_welfare_err_' . get_current_user_id(),
                $result->get_error_message(),
                60
            );
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        unset($redirect_args['employer_error']);
        $redirect_args['employer_saved'] = '1';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Send an email to employer welfare contact from whitelisted applications page.
     */
    public function handle_contact_employer_welfare()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'job-posting-manager'));
        }

        $nonce = isset($_POST['jpm_contact_employer_welfare_nonce']) ? sanitize_text_field(wp_unslash($_POST['jpm_contact_employer_welfare_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_contact_employer_welfare')) {
            wp_die(__('Invalid request.', 'job-posting-manager'));
        }

        $redirect_args = [
            'page' => 'jpm-whitelisted-applications',
            'employer_contact_error' => '1',
        ];
        if (isset($_POST['jpm_return_search']) && $_POST['jpm_return_search'] !== '') {
            $redirect_args['search'] = sanitize_text_field(wp_unslash($_POST['jpm_return_search']));
        }
        if (isset($_POST['jpm_return_job_id'])) {
            $rj = absint(wp_unslash($_POST['jpm_return_job_id']));
            if ($rj > 0) {
                $redirect_args['job_id'] = $rj;
            }
        }
        if (isset($_POST['jpm_return_location']) && $_POST['jpm_return_location'] !== '') {
            $redirect_args['location'] = sanitize_text_field(wp_unslash($_POST['jpm_return_location']));
        }
        if (isset($_POST['jpm_return_submitted_on']) && $_POST['jpm_return_submitted_on'] !== '') {
            $on = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_on']));
            if ($on !== '') {
                $redirect_args['submitted_on'] = $on;
            }
        }
        if (isset($_POST['jpm_return_submitted_from']) && $_POST['jpm_return_submitted_from'] !== '') {
            $from_filter = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_from']));
            if ($from_filter !== '') {
                $redirect_args['submitted_from'] = $from_filter;
            }
        }
        if (isset($_POST['jpm_return_submitted_to']) && $_POST['jpm_return_submitted_to'] !== '') {
            $to_filter = JPM_Database::normalize_application_filter_date(wp_unslash($_POST['jpm_return_submitted_to']));
            if ($to_filter !== '') {
                $redirect_args['submitted_to'] = $to_filter;
            }
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;
        $to_email_raw = isset($_POST['to_email']) ? wp_unslash($_POST['to_email']) : '';
        $from_email_raw = isset($_POST['from_email']) ? wp_unslash($_POST['from_email']) : '';
        $subject_raw = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
        $content_raw = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';

        $to_email = sanitize_email(trim((string) $to_email_raw));
        $from_email = sanitize_email(trim((string) $from_email_raw));
        $subject = sanitize_text_field((string) $subject_raw);
        $content = trim(wp_kses_post((string) $content_raw));

        if ($application_id <= 0 || !is_email($to_email) || !is_email($from_email) || $subject === '' || wp_strip_all_tags($content) === '') {
            set_transient(
                'jpm_employer_contact_err_' . get_current_user_id(),
                __('Please provide valid To, From, Subject, and Content fields.', 'job-posting-manager'),
                60
            );
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $application = JPM_Database::get_application($application_id);
        if (!$application || !isset($application->whitelisted) || (int) $application->whitelisted !== 1) {
            set_transient(
                'jpm_employer_contact_err_' . get_current_user_id(),
                __('The selected application is not available for employer contact.', 'job-posting-manager'),
                60
            );
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $stored_employer_email = sanitize_email(trim((string) ($application->employer_email ?? '')));
        if (!is_email($stored_employer_email) || strtolower($stored_employer_email) !== strtolower($to_email)) {
            set_transient(
                'jpm_employer_contact_err_' . get_current_user_id(),
                __('Employer email does not match the saved welfare contact.', 'job-posting-manager'),
                60
            );
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . $from_email . '>',
            'Reply-To: ' . $from_email,
        ];

        $sent = wp_mail($to_email, $subject, wpautop($content), $headers);
        if (!$sent) {
            set_transient(
                'jpm_employer_contact_err_' . get_current_user_id(),
                __('Email could not be sent. Please check your mail configuration.', 'job-posting-manager'),
                60
            );
            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        JPM_Database::add_employer_email_history(
            $application_id,
            $to_email,
            $from_email,
            $subject,
            $content,
            get_current_user_id()
        );

        unset($redirect_args['employer_contact_error']);
        $redirect_args['employer_contact_sent'] = '1';
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function dashboard_page()
    {
        // Get filter values
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';

        // Get analytics data
        global $wpdb;
        $table = $this->get_validated_applications_table();

        // Total jobs by status - compute live
        $post_counts = wp_count_posts('job_posting');
        $total_published = $post_counts->publish ?? 0;
        $total_draft = $post_counts->draft ?? 0;
        $total_pending = $post_counts->pending ?? 0;
        $total_jobs = $total_published + $total_draft + $total_pending;

        // Compute application stats live
        $total_applications = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $applications_by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );
        $status_counts = [];
        foreach ($applications_by_status as $row) {
            $status_counts[$row['status']] = intval($row['count']);
        }

        $recent_applications = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE application_date >= %s",
                gmdate('Y-m-d H:i:s', strtotime('-7 days'))
            )
        );

        $month_applications = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE MONTH(application_date) = %d AND YEAR(application_date) = %d",
                gmdate('n'),
                gmdate('Y')
            )
        );

        $top_jobs = $wpdb->get_results(
            "SELECT job_id, COUNT(*) as app_count 
                 FROM {$table} 
                 GROUP BY job_id 
                 ORDER BY app_count DESC 
                 LIMIT 5",
            ARRAY_A
        );

        // Get chart period filter
        $chart_period = isset($_GET['chart_period']) ? sanitize_text_field(wp_unslash($_GET['chart_period'])) : '7days';
        $chart_start_date = isset($_GET['chart_start_date']) ? sanitize_text_field(wp_unslash($_GET['chart_start_date'])) : '';
        $chart_end_date = isset($_GET['chart_end_date']) ? sanitize_text_field(wp_unslash($_GET['chart_end_date'])) : '';

        // Get chart data based on selected period
        $applications_by_day = $this->get_chart_data($chart_period, $chart_start_date, $chart_end_date);

        // Query jobs for table
        $args = [
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => $status_filter ? $status_filter : 'any',
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $jobs = get_posts($args);

        // Optimize: Pre-fetch all post meta and application counts to avoid N+1 queries
        if (!empty($jobs)) {
            $job_ids = wp_list_pluck($jobs, 'ID');

            // Batch fetch application counts for all jobs
            $job_ids_placeholders = implode(',', array_fill(0, count($job_ids), '%d'));
            $application_counts_results = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic IN placeholders are built from validated IDs above.
                $wpdb->prepare(
                    "SELECT job_id, COUNT(*) as count 
                    FROM {$table} 
                    WHERE job_id IN ($job_ids_placeholders)
                    GROUP BY job_id",
                    ...$job_ids
                ),
                ARRAY_A
            );
            $application_counts = [];
            foreach ($application_counts_results as $row) {
                $application_counts[$row['job_id']] = intval($row['count']);
            }
        } else {
            $application_counts = [];
        }

        ?>
        <div class="wrap jpm-dashboard-page">
            <h1><?php esc_html_e('Job Postings', 'job-posting-manager'); ?></h1>

            <!-- Analytics Section -->
            <div class="jpm-analytics-section" style="margin: 20px 0;">
                <h2><?php esc_html_e('Analytics Overview', 'job-posting-manager'); ?></h2>

                <div class="jpm-analytics-cards"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <!-- Total Jobs Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Total Jobs', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-businessman" style="font-size: 24px; color: #0073aa;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #0073aa; margin-bottom: 10px;">
                            <?php echo esc_html($total_jobs); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <span><?php echo esc_html($total_published); ?>
                                <?php esc_html_e('Published', 'job-posting-manager'); ?></span> |
                            <span><?php echo esc_html($total_draft); ?>
                                <?php esc_html_e('Draft', 'job-posting-manager'); ?></span>
                            <?php if ($total_pending > 0): ?>
                                | <span><?php echo esc_html($total_pending); ?>
                                    <?php esc_html_e('Pending', 'job-posting-manager'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Total Applications Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Total Applications', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-clipboard" style="font-size: 24px; color: #28a745;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px;">
                            <?php echo esc_html($total_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-applications')); ?>"
                                style="color: #0073aa; text-decoration: none;">
                                <?php esc_html_e('View All Applications', 'job-posting-manager'); ?> ->
                            </a>
                        </div>
                    </div>

                    <!-- Recent Applications Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Last 7 Days', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-calendar-alt" style="font-size: 24px; color: #ffc107;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #ffc107; margin-bottom: 10px;">
                            <?php echo esc_html($recent_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php esc_html_e('New applications', 'job-posting-manager'); ?>
                        </div>
                    </div>

                    <!-- This Month Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('This Month', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-chart-line" style="font-size: 24px; color: #dc3545;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #dc3545; margin-bottom: 10px;">
                            <?php echo esc_html($month_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php esc_html_e('Applications', 'job-posting-manager'); ?>
                        </div>
                    </div>
                </div>

                <!-- Applications by Status -->
                <?php if (!empty($status_counts)): ?>
                    <div
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0;"><?php esc_html_e('Applications by Status', 'job-posting-manager'); ?></h3>
                        <div class="jpm-dashboard-status-cards" style="display: flex; flex-wrap: wrap; gap: 15px;">
                            <?php
                            $status_options = self::get_status_options();
                            foreach ($status_options as $slug => $name):
                                $count = isset($status_counts[$slug]) ? $status_counts[$slug] : 0;
                                $status_info = self::get_status_by_slug($slug);
                                $bg_color = $status_info ? $status_info['color'] : '#ffc107';
                                $text_color = $status_info ? $status_info['text_color'] : '#000';
                                ?>
                                <div style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                                    <div
                                        style="font-size: 24px; font-weight: bold; color: <?php echo esc_attr($bg_color); ?>; margin-bottom: 5px;">
                                        <?php echo esc_html($count); ?>
                                    </div>
                                    <div style="font-size: 14px; color: #666;">
                                        <?php echo esc_html($name); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Applications Chart -->
                <div
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                        <h3 style="margin: 0;"><?php esc_html_e('Applications Trend', 'job-posting-manager'); ?></h3>
                        <div class="jpm-chart-filters" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <span style="font-weight: 600;"><?php esc_html_e('Period:', 'job-posting-manager'); ?></span>
                                <select id="jpm-chart-period" name="chart_period" style="padding: 5px 10px;">
                                    <option value="7days" <?php selected($chart_period, '7days'); ?>>
                                        <?php esc_html_e('Last 7 Days', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="30days" <?php selected($chart_period, '30days'); ?>>
                                        <?php esc_html_e('Last Month', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="90days" <?php selected($chart_period, '90days'); ?>>
                                        <?php esc_html_e('Last 3 Months', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="365days" <?php selected($chart_period, '365days'); ?>>
                                        <?php esc_html_e('Last Year', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="custom" <?php selected($chart_period, 'custom'); ?>>
                                        <?php esc_html_e('Custom Range', 'job-posting-manager'); ?>
                                    </option>
                                </select>
                            </label>
                            <div id="jpm-chart-custom-dates"
                                style="display: <?php echo $chart_period === 'custom' ? 'flex' : 'none'; ?>; gap: 10px; align-items: center;">
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <span><?php esc_html_e('From:', 'job-posting-manager'); ?></span>
                                    <input type="date" id="jpm-chart-start-date" name="chart_start_date"
                                        value="<?php echo esc_attr($chart_start_date); ?>" style="padding: 5px;">
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <span><?php esc_html_e('To:', 'job-posting-manager'); ?></span>
                                    <input type="date" id="jpm-chart-end-date" name="chart_end_date"
                                        value="<?php echo esc_attr($chart_end_date); ?>" style="padding: 5px;">
                                </label>
                            </div>
                            <button type="button" id="jpm-chart-apply" class="button button-primary" style="margin: 0;">
                                <?php esc_html_e('Apply', 'job-posting-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="jpm-chart-loading" style="display: none; text-align: center; padding: 20px;">
                        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                        <p><?php esc_html_e('Loading chart data...', 'job-posting-manager'); ?></p>
                    </div>
                    <div class="jpm-chart-container" id="jpm-chart-container" style="margin-top: 20px;">
                        <div class="jpm-dashboard-chart-scroll">
                            <div class="jpm-dashboard-chart-bars"
                                style="display: flex; align-items: flex-end; justify-content: space-around; height: 200px; border-bottom: 2px solid #ddd; padding-bottom: 10px; position: relative;">
                                <?php
                                $max_count = max(array_column($applications_by_day, 'count'));
                                $max_count = $max_count > 0 ? $max_count : 1;
                                foreach ($applications_by_day as $day):
                                    $height_percent = ($day['count'] / $max_count) * 100;
                                    $height_px = ($day['count'] / $max_count) * 180; // 180px max height (200px - 20px padding)
                                    ?>
                                    <div
                                        style="flex: 1; display: flex; flex-direction: column; align-items: center; margin: 0 5px; height: 100%;">
                                        <div class="jpm-chart-bar"
                                            style="width: 100%; max-width: 40px; background: #0073aa; border-radius: 4px 4px 0 0; margin-bottom: 10px; transition: all 0.3s ease; height: <?php echo esc_attr($height_px); ?>px; min-height: <?php echo $day['count'] > 0 ? '5px' : '0'; ?>;"
                                            title="<?php echo esc_attr($day['date'] . ': ' . $day['count'] . ' applications'); ?>">
                                        </div>
                                        <div
                                            style="font-size: 11px; color: #666; text-align: center; transform: rotate(-45deg); transform-origin: center; white-space: nowrap; margin-top: 5px;">
                                            <?php echo esc_html($day['date']); ?>
                                        </div>
                                        <div style="font-size: 12px; font-weight: bold; color: #333; margin-top: 5px;">
                                            <?php echo esc_html($day['count']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Jobs by Applications -->
            <?php if (!empty($top_jobs)): ?>
                <div
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Top Jobs by Applications', 'job-posting-manager'); ?></h3>
                    <div class="jpm-table-responsive">
                        <table class="widefat striped jpm-dashboard-table jpm-dashboard-table--top-jobs" style="margin-top: 15px;">
                            <thead>
                                <tr>
                                    <th style="width: 5%;"><?php esc_html_e('Rank', 'job-posting-manager'); ?></th>
                                    <th style="width: 60%;"><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                                    <th style="width: 20%;"><?php esc_html_e('Applications', 'job-posting-manager'); ?></th>
                                    <th style="width: 15%;"><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rank = 1;
                                foreach ($top_jobs as $top_job):
                                    $job_post = get_post($top_job['job_id']);
                                    if (!$job_post)
                                        continue;
                                    ?>
                                    <tr>
                                        <td data-label="<?php echo esc_attr(__('Rank', 'job-posting-manager')); ?>">
                                            <strong style="font-size: 18px; color: #0073aa;">#<?php echo esc_html($rank); ?></strong>
                                        </td>
                                        <td data-label="<?php echo esc_attr(__('Job Title', 'job-posting-manager')); ?>">
                                            <a
                                                href="<?php echo esc_url(admin_url('post.php?post=' . $top_job['job_id'] . '&action=edit')); ?>">
                                                <?php echo esc_html(get_the_title($top_job['job_id'])); ?>
                                            </a>
                                        </td>
                                        <td data-label="<?php echo esc_attr(__('Applications', 'job-posting-manager')); ?>">
                                            <strong style="font-size: 16px; color: #28a745;">
                                                <?php echo esc_html($top_job['app_count']); ?>
                                            </strong>
                                        </td>
                                        <td class="jpm-td-actions"
                                            data-label="<?php echo esc_attr(__('Actions', 'job-posting-manager')); ?>">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-applications&job_id=' . $top_job['job_id'])); ?>"
                                                class="button button-small">
                                                <?php esc_html_e('View', 'job-posting-manager'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php
                                    $rank++;
                                endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <hr class="jpm-dashboard-hr" style="margin: 30px 0;">

            <div style="margin: 16px 0 8px;">
                <button type="button" class="button" id="jpm-toggle-dashboard-filters" aria-expanded="false">
                    <?php esc_html_e('Search/Filter', 'job-posting-manager'); ?>
                </button>
            </div>

            <div class="jpm-filters jpm-dashboard-filters" id="jpm-dashboard-filters-panel"
                style="display:none; margin: 12px 0 20px; padding: 15px; background: #fff; border: 1px solid #ccc;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="jpm-dashboard">

                    <div class="jpm-dashboard-filter-row"
                        style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                        <div class="jpm-dashboard-filter-field">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Search Jobs:', 'job-posting-manager'); ?>
                            </label>
                            <input type="text" name="search" class="regular-text jpm-dashboard-search-input"
                                value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php esc_attr_e('Search by job title...', 'job-posting-manager'); ?>"
                                style="width: 300px; max-width: 100%; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Filter by Status:', 'job-posting-manager'); ?>
                            </label>
                            <select name="status">
                                <option value=""><?php esc_html_e('All Statuses', 'job-posting-manager'); ?></option>
                                <option value="publish" <?php selected($status_filter, 'publish'); ?>>
                                    <?php esc_html_e('Published', 'job-posting-manager'); ?>
                                </option>
                                <option value="draft" <?php selected($status_filter, 'draft'); ?>>
                                    <?php esc_html_e('Draft', 'job-posting-manager'); ?>
                                </option>
                                <option value="pending" <?php selected($status_filter, 'pending'); ?>>
                                    <?php esc_html_e('Pending', 'job-posting-manager'); ?>
                                </option>
                            </select>
                        </div>
                        <div>
                            <input type="submit" class="button button-primary"
                                value="<?php esc_html_e('Apply Filters', 'job-posting-manager'); ?>">
                            <?php if (!empty($search) || !empty($status_filter)): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-dashboard')); ?>" class="button">
                                    <?php esc_html_e('Clear', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="jpm-dashboard-toolbar" style="margin: 20px 0;">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=job_posting')); ?>" class="button button-primary">
                    <?php esc_html_e('Add New Job', 'job-posting-manager'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=job_posting')); ?>" class="button">
                    <?php esc_html_e('View All in Job Listings', 'job-posting-manager'); ?>
                </a>
            </div>

            <?php if (empty($jobs)): ?>
                <p><?php esc_html_e('No jobs found.', 'job-posting-manager'); ?></p>
            <?php else: ?>
                <div class="jpm-table-responsive">
                    <table class="widefat striped jpm-dashboard-table jpm-dashboard-table--jobs">
                        <thead>
                            <tr>
                                <th style="width: 5%;"><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                                <th style="width: 25%;"><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                                <th style="width: 15%;"><?php esc_html_e('Company', 'job-posting-manager'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Location', 'job-posting-manager'); ?></th>
                                <th style="width: 10%;"><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                                <th style="width: 10%;"><?php esc_html_e('Applications', 'job-posting-manager'); ?></th>
                                <th style="width: 10%;"><?php esc_html_e('Posted Date', 'job-posting-manager'); ?></th>
                                <th style="width: 13%;"><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobs as $job):
                                $company_name = get_post_meta($job->ID, 'company_name', true);
                                $location = get_post_meta($job->ID, 'location', true);

                                // Get application count (from pre-fetched data)
                                $application_count = isset($application_counts[$job->ID]) ? $application_counts[$job->ID] : 0;

                                $edit_url = admin_url('post.php?post=' . $job->ID . '&action=edit');
                                $view_url = get_permalink($job->ID);
                                $applications_url = admin_url('admin.php?page=jpm-applications&job_id=' . $job->ID);
                                $post_status = get_post_status($job->ID); ?>
                                <tr>
                                    <td data-label="<?php echo esc_attr(__('ID', 'job-posting-manager')); ?>">
                                        <?php echo esc_html($job->ID); ?>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Job Title', 'job-posting-manager')); ?>">
                                        <strong>
                                            <a href="<?php echo esc_url($edit_url); ?>">
                                                <?php echo esc_html(get_the_title($job->ID)); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Company', 'job-posting-manager')); ?>">
                                        <?php echo !empty($company_name) ? esc_html($company_name) : '--'; ?>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Location', 'job-posting-manager')); ?>">
                                        <?php echo !empty($location) ? esc_html($location) : '--'; ?>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Status', 'job-posting-manager')); ?>">
                                        <?php if ($post_status === 'publish'): ?>
                                            <span
                                                class="jpm-status-badge jpm-status-active"><?php esc_html_e('Published', 'job-posting-manager'); ?></span>
                                        <?php elseif ($post_status === 'draft'): ?>
                                            <span
                                                class="jpm-status-badge jpm-status-draft"><?php esc_html_e('Draft', 'job-posting-manager'); ?></span>
                                        <?php else: ?>
                                            <span class="jpm-status-badge"
                                                style="background-color: #ffc107; color: #000;"><?php echo esc_html(ucfirst($post_status)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Applications', 'job-posting-manager')); ?>">
                                        <a href="<?php echo esc_url($applications_url); ?>" style="font-weight: bold; color: #0073aa;">
                                            <?php echo esc_html($application_count); ?>
                                        </a>
                                    </td>
                                    <td data-label="<?php echo esc_attr(__('Posted Date', 'job-posting-manager')); ?>">
                                        <?php echo esc_html(get_the_date('', $job->ID)); ?>
                                    </td>
                                    <td class="jpm-td-actions"
                                        data-label="<?php echo esc_attr(__('Actions', 'job-posting-manager')); ?>">
                                        <div class="jpm-actions-menu" style="position: relative; display: inline-block;">
                                            <button type="button" class="button button-small jpm-actions-menu__toggle" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Open actions', 'job-posting-manager'); ?>" style="min-width:34px;text-align:center;padding:0 8px;line-height:1.2;font-size:18px;">
                                                &bull;&bull;&bull;
                                            </button>
                                            <div class="jpm-actions-menu__dropdown" style="display:none; position:absolute; right:0; top:calc(100% + 6px); z-index:30; min-width:180px; background:#fff; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 4px 16px rgba(0,0,0,0.14); padding:8px;">
                                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" style="display:block;width:100%;box-sizing:border-box;margin:0 0 8px;text-align:left;">
                                                    <?php esc_html_e('Edit', 'job-posting-manager'); ?>
                                                </a>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small" target="_blank" style="display:block;width:100%;box-sizing:border-box;margin:0;text-align:left;">
                                                    <?php esc_html_e('View', 'job-posting-manager'); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <script>
                jQuery(function ($) {
                    const $dashboardFiltersPanel = $('#jpm-dashboard-filters-panel');
                    const $toggleDashboardFiltersBtn = $('#jpm-toggle-dashboard-filters');

                    function updateDashboardFiltersToggleLabel() {
                        const isVisible = $dashboardFiltersPanel.is(':visible');
                        $toggleDashboardFiltersBtn
                            .attr('aria-expanded', isVisible ? 'true' : 'false')
                            .text(isVisible
                                ? '<?php echo esc_js(__('Hide Search/Filter', 'job-posting-manager')); ?>'
                                : '<?php echo esc_js(__('Search/Filter', 'job-posting-manager')); ?>');
                    }

                    $toggleDashboardFiltersBtn.on('click', function () {
                        $dashboardFiltersPanel.stop(true, true).slideToggle(120, updateDashboardFiltersToggleLabel);
                    });

                    function closeAllDashboardActionMenus() {
                        $('.jpm-dashboard-page .jpm-actions-menu__dropdown').hide();
                        $('.jpm-dashboard-page .jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                    }

                    $(document).on('click', '.jpm-dashboard-page .jpm-actions-menu__toggle', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const $toggle = $(this);
                        const $menu = $toggle.closest('.jpm-actions-menu');
                        const $dropdown = $menu.find('.jpm-actions-menu__dropdown').first();
                        const isOpen = $dropdown.is(':visible');
                        closeAllDashboardActionMenus();
                        if (!isOpen) {
                            $dropdown.show();
                            $toggle.attr('aria-expanded', 'true');
                        }
                    });

                    $(document).on('click', function () {
                        closeAllDashboardActionMenus();
                    });

                    $(document).on('click', '.jpm-dashboard-page .jpm-actions-menu__dropdown', function (e) {
                        e.stopPropagation();
                    });

                    updateDashboardFiltersToggleLabel();
                });
            </script>
        </div>
        <?php
    }

    public function job_listings_page()
    {
        global $wpdb;
        $table = $this->get_validated_applications_table();

        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $expired_filter = isset($_GET['expired']) ? sanitize_text_field(wp_unslash($_GET['expired'])) : '';
        $current_time = current_time('timestamp');
        $per_page = 10;
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;

        // Basic stats (across all jobs; not just current pagination page)
        $total_jobs_all = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'job_posting')
        );
        $post_counts = wp_count_posts('job_posting');
        $published_count = (int) ($post_counts->publish ?? 0);
        $draft_count = (int) ($post_counts->draft ?? 0);
        $pending_count = (int) ($post_counts->pending ?? 0);
        $expired_jobs_all = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                    ON p.ID = pm.post_id
                    AND pm.meta_key = %s
                 WHERE p.post_type = %s
                   AND CAST(pm.meta_value AS UNSIGNED) <= %d",
                'expiration_date',
                'job_posting',
                $current_time
            )
        );
        $not_expired_jobs_all = max(0, $total_jobs_all - $expired_jobs_all);

        $query_args = [
            'post_type' => 'job_posting',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'post_status' => $status_filter ? $status_filter : 'any',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (!empty($search)) {
            $query_args['s'] = $search;
        }

        if ($expired_filter === 'expired') {
            $query_args['meta_query'] = [
                [
                    'key' => 'expiration_date',
                    'value' => $current_time,
                    'compare' => '<=',
                    'type' => 'NUMERIC',
                ],
            ];
        } elseif ($expired_filter === 'not_expired') {
            $query_args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'expiration_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'expiration_date',
                    'value' => '',
                    'compare' => '=',
                ],
                [
                    'key' => 'expiration_date',
                    'value' => $current_time,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
            ];
        }

        $jobs_query = new WP_Query($query_args);
        $jobs = $jobs_query->posts;
        $application_counts = [];

        if (!empty($jobs)) {
            $job_ids = wp_list_pluck($jobs, 'ID');
            $placeholders = implode(',', array_fill(0, count($job_ids), '%d'));

            $query = $wpdb->prepare(
                "SELECT job_id, COUNT(*) AS app_count FROM {$table} WHERE job_id IN ({$placeholders}) GROUP BY job_id",
                $job_ids
            );
            $results = $wpdb->get_results($query, ARRAY_A);

            foreach ($results as $row) {
                $application_counts[(int) $row['job_id']] = (int) $row['app_count'];
            }
        }

        $total_jobs = (int) ($jobs_query->found_posts ?? 0);
        $total_pages = (int) ($jobs_query->max_num_pages ?? 0);
        if ($total_pages < 1) {
            $total_pages = 1;
        }

        $pagination_base_args = [
            'page' => 'jpm-job-listings',
        ];
        if (!empty($search)) {
            $pagination_base_args['search'] = $search;
        }
        if (!empty($status_filter)) {
            $pagination_base_args['status'] = $status_filter;
        }
        if (!empty($expired_filter)) {
            $pagination_base_args['expired'] = $expired_filter;
        }

        $prev_url = $paged > 1 ? add_query_arg(array_merge($pagination_base_args, ['paged' => $paged - 1]), admin_url('admin.php')) : '';
        $next_url = $paged < $total_pages ? add_query_arg(array_merge($pagination_base_args, ['paged' => $paged + 1]), admin_url('admin.php')) : '';
        ?>
        <div class="wrap jpm-job-listings-page">
            <style>
                .jpm-job-listings-page .jpm-actions-menu__dropdown {
                    padding: 8px;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown form {
                    display: block;
                    width: 100%;
                    margin: 0;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown .button {
                    display: block;
                    width: 100%;
                    box-sizing: border-box;
                    margin: 0 0 8px;
                    text-align: left;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown .button:last-child {
                    margin-bottom: 0;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown details {
                    margin-top: 0;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown details summary {
                    list-style: none;
                    cursor: pointer;
                    font-weight: 600;
                    color: #2271b1;
                    padding: 6px 4px;
                    border-radius: 4px;
                    margin-bottom: 4px;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown details summary:hover {
                    background: #f0f6fc;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown details summary::-webkit-details-marker {
                    display: none;
                }

                .jpm-job-listings-page .jpm-actions-menu__dropdown details[open] summary {
                    margin-bottom: 8px;
                }
            </style>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap;">
                <h1 style="margin-bottom: 0;"><?php esc_html_e('Job Listings', 'job-posting-manager'); ?></h1>
                <?php if (current_user_can('manage_options')): ?>
                    <?php
                    $export_filters = [];
                    if (!empty($search)) {
                        $export_filters['search'] = $search;
                    }
                    if (!empty($status_filter)) {
                        $export_filters['status'] = $status_filter;
                    }
                    if (!empty($expired_filter)) {
                        $export_filters['expired'] = $expired_filter;
                    }
                    $export_query = !empty($export_filters) ? ('&' . http_build_query($export_filters)) : '';
                    $export_csv_url = wp_nonce_url(
                        admin_url('admin.php?page=jpm-job-listings&export=csv' . $export_query),
                        'jpm_export_jobs',
                        'jpm_export_nonce'
                    );
                    $export_json_url = wp_nonce_url(
                        admin_url('admin.php?page=jpm-job-listings&export=json' . $export_query),
                        'jpm_export_jobs',
                        'jpm_export_nonce'
                    );
                    ?>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url($export_csv_url); ?>"
                            class="button"><?php esc_html_e('Export CSV', 'job-posting-manager'); ?></a>
                        <a href="<?php echo esc_url($export_json_url); ?>"
                            class="button"><?php esc_html_e('Export JSON', 'job-posting-manager'); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="jpm-analytics-cards"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">


                <!-- Published Card -->
                <div class="jpm-analytics-card"
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                            <?php esc_html_e('Published', 'job-posting-manager'); ?>
                        </h3>
                        <span class="dashicons dashicons-yes" style="font-size: 24px; color: #28a745;"></span>
                    </div>
                    <div style="font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px;">
                        <?php echo esc_html($published_count); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        <?php esc_html_e('Total job postings', 'job-posting-manager'); ?>
                    </div>
                </div>

                <!-- Draft Card -->
                <div class="jpm-analytics-card"
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                            <?php esc_html_e('Draft', 'job-posting-manager'); ?>
                        </h3>
                        <span class="dashicons dashicons-edit" style="font-size: 24px; color: #6c757d;"></span>
                    </div>
                    <div style="font-size: 32px; font-weight: bold; color: #6c757d; margin-bottom: 10px;">
                        <?php echo esc_html($draft_count); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        <?php esc_html_e('Not published yet', 'job-posting-manager'); ?>
                    </div>
                </div>

                <!-- Pending Card -->
                <div class="jpm-analytics-card"
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                            <?php esc_html_e('Pending', 'job-posting-manager'); ?>
                        </h3>
                        <span class="dashicons dashicons-clock" style="font-size: 24px; color: #ffc107;"></span>
                    </div>
                    <div style="font-size: 32px; font-weight: bold; color: #ffc107; margin-bottom: 10px;">
                        <?php echo esc_html($pending_count); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        <?php esc_html_e('Awaiting review', 'job-posting-manager'); ?>
                    </div>
                </div>

                <!-- Expiration Card -->
                <div class="jpm-analytics-card"
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                            <?php esc_html_e('Expiration', 'job-posting-manager'); ?>
                        </h3>
                        <span class="dashicons dashicons-clock" style="font-size: 24px; color: #0073aa;"></span>
                    </div>
                    <div style="display: flex; gap: 15px; flex-direction: row;">
                        <div
                            style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px; #b32d2e;">
                            <div style="font-size: 24px; font-weight: bold; color: #b32d2e; margin-bottom: 5px;">
                                <?php echo esc_html($expired_jobs_all); ?>
                            </div>
                            <div style="font-size: 14px; color: #666;">
                                <?php esc_html_e('Expired', 'job-posting-manager'); ?>
                            </div>
                        </div>
                        <div
                            style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px; #1e7e34;">
                            <div style="font-size: 24px; font-weight: bold; color: #1e7e34; margin-bottom: 5px;">
                                <?php echo esc_html($not_expired_jobs_all); ?>
                            </div>
                            <div style="font-size: 14px; color: #666;">
                                <?php esc_html_e('Not expired', 'job-posting-manager'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (isset($_GET['updated_expiration']) && sanitize_text_field(wp_unslash($_GET['updated_expiration'])) === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php esc_html_e('Job expiration updated successfully.', 'job-posting-manager'); ?>
                    </p>
                </div>
            <?php elseif (isset($_GET['marked_expired']) && sanitize_text_field(wp_unslash($_GET['marked_expired'])) === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Job marked as expired successfully.', 'job-posting-manager'); ?></p>
                </div>
            <?php elseif (isset($_GET['job_deleted']) && sanitize_text_field(wp_unslash($_GET['job_deleted'])) === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Job deleted permanently.', 'job-posting-manager'); ?></p>
                </div>
            <?php elseif (isset($_GET['job_delete_error']) && sanitize_text_field(wp_unslash($_GET['job_delete_error'])) === '1'): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Could not delete that job. It may have already been removed.', 'job-posting-manager'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="margin: 16px 0 8px;">
                <button type="button" class="button" id="jpm-toggle-job-listings-filters" aria-expanded="false">
                    <?php esc_html_e('Search/Filter', 'job-posting-manager'); ?>
                </button>
            </div>

            <div class="jpm-filters" id="jpm-job-listings-filters-panel" style="display:none; margin: 12px 0 20px; padding: 15px; background: #fff; border: 1px solid #ccc;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="jpm-job-listings">

                    <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Search Jobs:', 'job-posting-manager'); ?>
                            </label>
                            <input type="text" name="search" class="regular-text" value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php esc_attr_e('Search by job title...', 'job-posting-manager'); ?>"
                                style="width: 300px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Filter by Status:', 'job-posting-manager'); ?>
                            </label>
                            <select name="status">
                                <option value=""><?php esc_html_e('All Statuses', 'job-posting-manager'); ?></option>
                                <option value="publish" <?php selected($status_filter, 'publish'); ?>>
                                    <?php esc_html_e('Published', 'job-posting-manager'); ?>
                                </option>
                                <option value="draft" <?php selected($status_filter, 'draft'); ?>>
                                    <?php esc_html_e('Draft', 'job-posting-manager'); ?>
                                </option>
                                <option value="pending" <?php selected($status_filter, 'pending'); ?>>
                                    <?php esc_html_e('Pending', 'job-posting-manager'); ?>
                                </option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Filter by Expiration:', 'job-posting-manager'); ?>
                            </label>
                            <select name="expired">
                                <option value=""><?php esc_html_e('All', 'job-posting-manager'); ?></option>
                                <option value="expired" <?php selected($expired_filter, 'expired'); ?>>
                                    <?php esc_html_e('Expired', 'job-posting-manager'); ?>
                                </option>
                                <option value="not_expired" <?php selected($expired_filter, 'not_expired'); ?>>
                                    <?php esc_html_e('Not expired', 'job-posting-manager'); ?>
                                </option>
                            </select>
                        </div>
                        <div>
                            <input type="submit" class="button button-primary"
                                value="<?php esc_attr_e('Apply Filters', 'job-posting-manager'); ?>">
                            <?php if (!empty($search) || !empty($status_filter) || !empty($expired_filter)): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-job-listings')); ?>" class="button">
                                    <?php esc_html_e('Clear', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div style="margin: 20px 0;">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=job_posting')); ?>" class="button button-primary">
                    <?php esc_html_e('Add New Job', 'job-posting-manager'); ?>
                </a>
            </div>

            <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 12px 0;">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(__('Showing %d of %d job(s)', 'job-posting-manager'), min($total_jobs, ($paged * $per_page)), $total_jobs)); ?>
                        </span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <?php if (!empty($prev_url)): ?>
                            <a class="button" href="<?php echo esc_url($prev_url); ?>">&laquo;
                                <?php esc_html_e('Previous', 'job-posting-manager'); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($next_url)): ?>
                            <a class="button"
                                href="<?php echo esc_url($next_url); ?>"><?php esc_html_e('Next', 'job-posting-manager'); ?> &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($jobs)): ?>
                <p><?php esc_html_e('No jobs found.', 'job-posting-manager'); ?></p>
            <?php else: ?>
                <div class="jpm-table-responsive">
                    <table class="widefat striped jpm-job-listings-table">
                        <thead>
                            <tr>
                                <th style="width: 6%;"><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                                <th style="width: 26%;"><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                                <th style="width: 14%;"><?php esc_html_e('Company', 'job-posting-manager'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Location', 'job-posting-manager'); ?></th>
                                <th style="width: 10%;"><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Publish Date', 'job-posting-manager'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Is Expired', 'job-posting-manager'); ?></th>
                                <th style="width: 8%;"><?php esc_html_e('Applications', 'job-posting-manager'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobs as $job):
                                $company_name = get_post_meta($job->ID, 'company_name', true);
                                $location = get_post_meta($job->ID, 'location', true);
                                $post_status = get_post_status($job->ID);
                                $expiration_duration = absint(get_post_meta($job->ID, 'expiration_duration', true));
                                $expiration_unit = get_post_meta($job->ID, 'expiration_unit', true);
                                $application_count = isset($application_counts[$job->ID]) ? $application_counts[$job->ID] : 0;
                                $expiration_timestamp = (int) get_post_meta($job->ID, 'expiration_date', true);
                                $is_expired = !empty($expiration_timestamp) && $expiration_timestamp <= current_time('timestamp');
                                $expires_in = !$is_expired ? $this->get_expires_in($job->ID) : false;
                                $edit_url = admin_url('post.php?post=' . $job->ID . '&action=edit');
                                $view_url = get_permalink($job->ID);
                                $applications_url = admin_url('admin.php?page=jpm-applications&job_id=' . $job->ID);
                                ?>
                                <tr>
                                    <td><?php echo esc_html($job->ID); ?></td>
                                    <td>
                                        <strong><a
                                                href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html(get_the_title($job->ID)); ?></a></strong>
                                    </td>
                                    <td><?php echo !empty($company_name) ? esc_html($company_name) : '--'; ?></td>
                                    <td><?php echo !empty($location) ? esc_html($location) : '--'; ?></td>
                                    <td><?php echo esc_html(ucfirst($post_status)); ?></td>
                                    <td><?php echo esc_html(get_the_date('', $job->ID)); ?></td>
                                    <td>
                                        <?php if ($is_expired): ?>
                                            <span
                                                style="color: #b32d2e; font-weight: 600;"><?php esc_html_e('Expired', 'job-posting-manager'); ?></span>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                <span
                                                    style="color: #1e7e34; font-weight: 600;"><?php esc_html_e('Not expired', 'job-posting-manager'); ?></span>
                                                <span style="color: #d39e00; font-weight: 600; font-size: 12px;">
                                                    <?php
                                                    if ($expires_in) {
                                                        echo esc_html(sprintf(__('Expires in %s', 'job-posting-manager'), $expires_in));
                                                    } else {
                                                        esc_html_e('Expires in --', 'job-posting-manager');
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($applications_url); ?>" style="font-weight: bold; color: #0073aa;">
                                            <?php echo esc_html($application_count); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="jpm-actions-menu" style="position: relative; display: inline-block;">
                                            <button type="button" class="button button-small jpm-actions-menu__toggle" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Open actions', 'job-posting-manager'); ?>" style="min-width:34px;text-align:center;padding:0 8px;line-height:1.2;font-size:18px;">
                                                &bull;&bull;&bull;
                                            </button>
                                            <div class="jpm-actions-menu__dropdown" style="display:none; position:absolute; right:0; top:calc(100% + 6px); z-index:30; min-width:220px; background:#fff; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 4px 16px rgba(0,0,0,0.14); padding:8px;">
                                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                                    <?php esc_html_e('Edit', 'job-posting-manager'); ?>
                                                </a>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small" target="_blank">
                                                    <?php esc_html_e('View', 'job-posting-manager'); ?>
                                                </a>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <?php wp_nonce_field('jpm_delete_job', 'jpm_delete_job_nonce'); ?>
                                                    <input type="hidden" name="action" value="jpm_delete_job">
                                                    <input type="hidden" name="job_id" value="<?php echo esc_attr($job->ID); ?>">
                                                    <button type="submit" class="button button-small"
                                                        style="border-color: #b32d2e; color: #b32d2e;"
                                                        onclick="return confirm('<?php echo esc_js(__('Delete this job and all of its applications permanently? This cannot be undone.', 'job-posting-manager')); ?>');">
                                                        <?php esc_html_e('Delete', 'job-posting-manager'); ?>
                                                    </button>
                                                </form>
                                                <?php if (!$is_expired): ?>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('jpm_mark_expired', 'jpm_mark_expired_nonce'); ?>
                                                        <input type="hidden" name="action" value="jpm_mark_expired">
                                                        <input type="hidden" name="job_id" value="<?php echo esc_attr($job->ID); ?>">
                                                        <button type="submit" class="button button-small" style="border-color: #b32d2e;"
                                                            onclick="return confirm('<?php echo esc_js(__('Mark this job as expired?', 'job-posting-manager')); ?>');">
                                                            <?php esc_html_e('Mark as expired', 'job-posting-manager'); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <details style="margin-top: 6px;">
                                                    <summary style="cursor: pointer; color: #0073aa; font-weight: 600;">
                                                        <?php esc_html_e('Edit Expiration', 'job-posting-manager'); ?>
                                                    </summary>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                                        style="margin-top: 8px;">
                                                        <?php wp_nonce_field('jpm_update_expiration', 'jpm_expiration_nonce'); ?>
                                                        <input type="hidden" name="action" value="jpm_update_expiration">
                                                        <input type="hidden" name="job_id" value="<?php echo esc_attr($job->ID); ?>">
                                                        <input type="number" name="expiration_duration" min="1" step="1"
                                                            value="<?php echo esc_attr($expiration_duration > 0 ? $expiration_duration : 30); ?>"
                                                            style="width: 70px;">
                                                        <select name="expiration_unit">
                                                            <option value="minutes" <?php selected($expiration_unit, 'minutes'); ?>>
                                                                <?php esc_html_e('Minutes', 'job-posting-manager'); ?>
                                                            </option>
                                                            <option value="hours" <?php selected($expiration_unit, 'hours'); ?>>
                                                                <?php esc_html_e('Hours', 'job-posting-manager'); ?>
                                                            </option>
                                                            <option value="days" <?php selected($expiration_unit, 'days'); ?>>
                                                                <?php esc_html_e('Days', 'job-posting-manager'); ?>
                                                            </option>
                                                            <option value="months" <?php selected($expiration_unit, 'months'); ?>>
                                                                <?php esc_html_e('Months', 'job-posting-manager'); ?>
                                                            </option>
                                                        </select>
                                                        <button type="submit" class="button button-small">
                                                            <?php esc_html_e('Save', 'job-posting-manager'); ?>
                                                        </button>
                                                    </form>
                                                </details>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin: 12px 0;">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo esc_html(sprintf(__('Showing %d of %d job(s)', 'job-posting-manager'), min($total_jobs, ($paged * $per_page)), $total_jobs)); ?>
                            </span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <?php if (!empty($prev_url)): ?>
                                <a class="button" href="<?php echo esc_url($prev_url); ?>">&laquo;
                                    <?php esc_html_e('Previous', 'job-posting-manager'); ?></a>
                            <?php endif; ?>
                            <?php if (!empty($next_url)): ?>
                                <a class="button"
                                    href="<?php echo esc_url($next_url); ?>"><?php esc_html_e('Next', 'job-posting-manager'); ?> &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <script>
                jQuery(function ($) {
                    const $jobListingsFiltersPanel = $('#jpm-job-listings-filters-panel');
                    const $toggleJobListingsFiltersBtn = $('#jpm-toggle-job-listings-filters');

                    function updateJobListingsFiltersToggleLabel() {
                        const isVisible = $jobListingsFiltersPanel.is(':visible');
                        $toggleJobListingsFiltersBtn
                            .attr('aria-expanded', isVisible ? 'true' : 'false')
                            .text(isVisible
                                ? '<?php echo esc_js(__('Hide Search/Filter', 'job-posting-manager')); ?>'
                                : '<?php echo esc_js(__('Search/Filter', 'job-posting-manager')); ?>');
                    }

                    $toggleJobListingsFiltersBtn.on('click', function () {
                        $jobListingsFiltersPanel.stop(true, true).slideToggle(120, updateJobListingsFiltersToggleLabel);
                    });

                    function closeAllJobListingActionMenus() {
                        $('.jpm-job-listings-page .jpm-actions-menu__dropdown').hide();
                        $('.jpm-job-listings-page .jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                    }

                    $(document).on('click', '.jpm-job-listings-page .jpm-actions-menu__toggle', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const $toggle = $(this);
                        const $menu = $toggle.closest('.jpm-actions-menu');
                        const $dropdown = $menu.find('.jpm-actions-menu__dropdown').first();
                        const isOpen = $dropdown.is(':visible');
                        closeAllJobListingActionMenus();
                        if (!isOpen) {
                            $dropdown.show();
                            $toggle.attr('aria-expanded', 'true');
                        }
                    });

                    $(document).on('click', function () {
                        closeAllJobListingActionMenus();
                    });

                    $(document).on('click', '.jpm-job-listings-page .jpm-actions-menu__dropdown', function (e) {
                        e.stopPropagation();
                    });

                    updateJobListingsFiltersToggleLabel();
                });
            </script>
        </div>
        <?php
    }

    public function applications_page()
    {
        $filters = [
            'status' => isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '',
            'job_id' => isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0,
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
        ];
        $report_range = isset($_GET['report_range']) ? sanitize_key(wp_unslash($_GET['report_range'])) : '';
        $report_start = isset($_GET['report_start']) ? sanitize_text_field(wp_unslash($_GET['report_start'])) : '';
        $report_end = isset($_GET['report_end']) ? sanitize_text_field(wp_unslash($_GET['report_end'])) : '';
        $report_format = isset($_GET['report_format']) ? sanitize_key(wp_unslash($_GET['report_format'])) : 'csv';

        $applications = JPM_DB::get_applications($filters);
        $has_applications = !empty($applications);

        // Get all jobs for filter dropdown
        $jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        // Get medical status slug for checking
        $medical_status_slug = $this->get_medical_status_slug();

        // Get interview status slug for checking
        $interview_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
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

        global $wpdb;
        $apps_table = $this->get_validated_applications_table();
        $applications_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$apps_table}");
        $applications_guest = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$apps_table} WHERE user_id = %d", 0));
        $applications_registered = max(0, $applications_total - $applications_guest);
        $status_count_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$apps_table} GROUP BY status",
            ARRAY_A
        );
        $status_counts = [];
        if (is_array($status_count_rows)) {
            foreach ($status_count_rows as $row) {
                if (isset($row['status'])) {
                    $status_counts[(string) $row['status']] = isset($row['c']) ? (int) $row['c'] : 0;
                }
            }
        }
        $filtered_count = is_array($applications) ? count($applications) : 0;
        $has_active_filters = ($filters['search'] !== '' || $filters['job_id'] > 0 || $filters['status'] !== '');

        ?>
        <div class="wrap jpm-applications-page">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <h1 style="margin:0;"><?php esc_html_e('Applications', 'job-posting-manager'); ?></h1>
                <button type="button" class="button button-primary" id="jpm-open-report-modal">
                    <?php esc_html_e('Generate Report', 'job-posting-manager'); ?>
                </button>
            </div>

            <?php if (!empty($_GET['application_deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Application deleted.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['application_delete_error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Could not delete that application.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['whitelist_added'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Application added to the whitelist.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['whitelist_error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Could not update the whitelist. Only accepted applications can be whitelisted.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($report_range)): ?>
                <div class="notice notice-info is-dismissible" style="margin-top: 12px;">
                    <p>
                        <?php
                        if ($report_range === 'custom' && !empty($report_start) && !empty($report_end)) {
                            echo esc_html(sprintf(__('Report range selected: %1$s to %2$s', 'job-posting-manager'), $report_start, $report_end));
                        } else {
                            echo esc_html(sprintf(__('Report range selected: %s', 'job-posting-manager'), ucfirst(str_replace('_', ' ', $report_range))));
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="jpm-analytics-section jpm-applications-analytics" role="region"
                aria-label="<?php esc_attr_e('Application statistics', 'job-posting-manager'); ?>"
                style="margin: 20px 0;">
                <h2 style="margin: 0 0 4px;"><?php esc_html_e('Application overview', 'job-posting-manager'); ?></h2>
                <p class="description" style="margin-top: 0; margin-bottom: 16px;">
                    <?php esc_html_e('Summary of all applications and how they break down.', 'job-posting-manager'); ?>
                </p>

                <div class="jpm-analytics-cards"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Total applications', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-clipboard" style="font-size: 24px; color: #28a745;" aria-hidden="true"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px;">
                            <?php echo esc_html(number_format_i18n($applications_total)); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php esc_html_e('All records in the database', 'job-posting-manager'); ?>
                        </div>
                    </div>

                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Registered users', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-groups" style="font-size: 24px; color: #0073aa;" aria-hidden="true"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #0073aa; margin-bottom: 10px;">
                            <?php echo esc_html(number_format_i18n($applications_registered)); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php esc_html_e('Submitted while logged in', 'job-posting-manager'); ?>
                        </div>
                    </div>

                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php esc_html_e('Guest applications', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-admin-users" style="font-size: 24px; color: #ffc107;" aria-hidden="true"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #ffc107; margin-bottom: 10px;">
                            <?php echo esc_html(number_format_i18n($applications_guest)); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php esc_html_e('No WordPress account linked', 'job-posting-manager'); ?>
                        </div>
                    </div>

                    <?php if ($has_active_filters): ?>
                        <div class="jpm-analytics-card jpm-applications-filter-match-card"
                            style="background: #fff; border: 1px solid #2271b1; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                    <?php esc_html_e('Matching filters', 'job-posting-manager'); ?>
                                </h3>
                                <span class="dashicons dashicons-filter" style="font-size: 24px; color: #2271b1;" aria-hidden="true"></span>
                            </div>
                            <div style="font-size: 32px; font-weight: bold; color: #2271b1; margin-bottom: 10px;">
                                <?php echo esc_html(number_format_i18n($filtered_count)); ?>
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                <?php esc_html_e('Rows shown in the table below', 'job-posting-manager'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($all_statuses) || !empty($status_counts)): ?>
                    <div
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0;"><?php esc_html_e('Applications by status', 'job-posting-manager'); ?></h3>
                        <div class="jpm-dashboard-status-cards" style="display: flex; flex-wrap: wrap; gap: 15px;">
                            <?php
                            $status_slugs_shown = [];
                            foreach ($all_statuses as $st) {
                                if (empty($st['slug'])) {
                                    continue;
                                }
                                $slug = $st['slug'];
                                $status_slugs_shown[$slug] = true;
                                $cnt = isset($status_counts[$slug]) ? $status_counts[$slug] : 0;
                                $status_info = self::get_status_by_slug($slug);
                                $bg_color = $status_info ? sanitize_hex_color($status_info['color']) : '';
                                if (!$bg_color) {
                                    $bg_color = '#ffc107';
                                }
                                $name = isset($st['name']) ? $st['name'] : $slug;
                                ?>
                                <div style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                                    <div
                                        style="font-size: 24px; font-weight: bold; color: <?php echo esc_attr($bg_color); ?>; margin-bottom: 5px;">
                                        <?php echo esc_html(number_format_i18n($cnt)); ?>
                                    </div>
                                    <div style="font-size: 14px; color: #666;">
                                        <?php echo esc_html($name); ?>
                                    </div>
                                </div>
                                <?php
                            }
                            foreach ($status_counts as $orphan_slug => $cnt) {
                                if (isset($status_slugs_shown[$orphan_slug])) {
                                    continue;
                                }
                                ?>
                                <div style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px;"
                                    title="<?php echo esc_attr(__('Status exists on records but is not in Status Management', 'job-posting-manager')); ?>">
                                    <div style="font-size: 24px; font-weight: bold; color: #6c757d; margin-bottom: 5px;">
                                        <?php echo esc_html(number_format_i18n($cnt)); ?>
                                    </div>
                                    <div style="font-size: 14px; color: #666;">
                                        <?php echo esc_html($orphan_slug); ?>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_applications): ?>
                <div style="margin: 16px 0 8px;">
                    <button type="button" class="button" id="jpm-toggle-applications-filters" aria-expanded="false">
                        <?php esc_html_e('Search/Filter', 'job-posting-manager'); ?>
                    </button>
                </div>

                <div class="jpm-filters" id="jpm-applications-filters-panel" style="display:none; margin: 12px 0 20px; padding: 15px; background: #fff; border: 1px solid #ccc;">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="jpm-applications">

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                <?php esc_html_e('Search Applications:', 'job-posting-manager'); ?>
                            </label>
                            <input type="text" name="search" class="regular-text"
                                value="<?php echo esc_attr($filters['search']); ?>"
                                placeholder="<?php esc_attr_e('Search by name, email, or application number...', 'job-posting-manager'); ?>"
                                style="width: 100%; max-width: 500px;">
                            <p class="description" style="margin-top: 5px;">
                                <?php esc_html_e('Search by given name, middle name, surname, email, or application number', 'job-posting-manager'); ?>
                            </p>
                        </div>

                        <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                            <label>
                                <?php esc_html_e('Filter by Job:', 'job-posting-manager'); ?>
                                <select name="job_id">
                                    <option value=""><?php esc_html_e('All Jobs', 'job-posting-manager'); ?></option>
                                    <?php foreach ($jobs as $job): ?>
                                        <option value="<?php echo esc_attr($job->ID); ?>" <?php selected($filters['job_id'], $job->ID); ?>>
                                            <?php echo esc_html($job->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Filter by Status:', 'job-posting-manager'); ?>
                                <select name="status">
                                    <option value=""><?php esc_html_e('All Statuses', 'job-posting-manager'); ?></option>
                                    <?php
                                    $status_options = self::get_status_options();
                                    foreach ($status_options as $slug => $name):
                                        ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['status'], $slug); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input type="submit" class="button button-primary"
                                value="<?php esc_html_e('Apply Filters', 'job-posting-manager'); ?>">
                            <?php if (!empty($filters['search']) || !empty($filters['job_id']) || !empty($filters['status'])): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-applications')); ?>" class="button">
                                    <?php esc_html_e('Clear', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (current_user_can('edit_posts')): ?>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <!-- Export Section -->
                                <?php if (!empty($applications)): ?>
                                    <div>
                                        <strong><?php esc_html_e('Export Applications:', 'job-posting-manager'); ?></strong>
                                        <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=jpm-applications&export=csv&' . http_build_query($filters)), 'jpm_export_applications', 'jpm_export_nonce')); ?>"
                                                class="button">
                                                <?php esc_html_e('Export to CSV', 'job-posting-manager'); ?>
                                            </a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=jpm-applications&export=json&' . http_build_query($filters)), 'jpm_export_applications', 'jpm_export_nonce')); ?>"
                                                class="button">
                                                <?php esc_html_e('Export to JSON', 'job-posting-manager'); ?>
                                            </a>
                                        </div>
                                        <p class="description" style="margin-top: 5px;">
                                            <?php esc_html_e('Export will include all applications matching your current filters and search.', 'job-posting-manager'); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <!-- Import Section -->
                                <div>
                                    <strong><?php esc_html_e('Import Applications:', 'job-posting-manager'); ?></strong>
                                    <form method="post" action="" enctype="multipart/form-data" style="margin-top: 10px;">
                                        <?php wp_nonce_field('jpm_import_applications', 'jpm_import_nonce'); ?>
                                        <input type="hidden" name="jpm_import_action" value="import">
                                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                            <input type="file" name="jpm_import_file" accept=".csv,.json" required
                                                style="padding: 5px;">
                                            <select name="jpm_import_format" required style="padding: 5px;">
                                                <option value=""><?php esc_html_e('Select Format', 'job-posting-manager'); ?></option>
                                                <option value="csv"><?php esc_html_e('CSV', 'job-posting-manager'); ?></option>
                                                <option value="json"><?php esc_html_e('JSON', 'job-posting-manager'); ?></option>
                                            </select>
                                            <input type="submit" name="jpm_import_submit" class="button button-primary"
                                                value="<?php esc_html_e('Import', 'job-posting-manager'); ?>">
                                        </div>
                                        <p class="description" style="margin-top: 5px;">
                                            <?php esc_html_e('Import applications from a previously exported CSV or JSON file. File must match the export format.', 'job-posting-manager'); ?>
                                        </p>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!$has_applications): ?>
                <div class="jpm-empty-state">
                    <div class="jpm-empty-card">
                        <div class="jpm-empty-icon">[ ]</div>
                        <h2><?php esc_html_e('No applications found', 'job-posting-manager'); ?></h2>
                        <p><?php esc_html_e('Once candidates submit applications, they will appear here. You can also import applications if you have previous data.', 'job-posting-manager'); ?>
                        </p>
                        <div class="jpm-empty-actions">
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=jpm-dashboard')); ?>">
                                <?php esc_html_e('Go to Dashboard', 'job-posting-manager'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="jpm-table-responsive">
                <table class="widefat striped jpm-applications-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('Application Date', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('User', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('Application Number', 'job-posting-manager'); ?></th>
                            <th><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $application):
                            $job = get_post($application->job_id);
                            $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
                            $form_data = json_decode($application->notes, true);
                            $application_number = isset($form_data['application_number']) ? $form_data['application_number'] : '';
                            ?>
                            <tr>
                                <td><?php echo esc_html($application->id); ?></td>
                                <td>
                                    <a
                                        href="<?php echo esc_url(admin_url('post.php?post=' . $application->job_id . '&action=edit')); ?>">
                                        <?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?>
                                </td>
                                <td>
                                    <?php
                                    $status_info = self::get_status_by_slug($application->status);
                                    if ($status_info):
                                        $bg_color = $status_info['color'];
                                        $text_color = $status_info['text_color'];
                                        $status_name = $status_info['name'];
                                    else:
                                        $bg_color = '#ffc107';
                                        $text_color = '#000000';
                                        $status_name = ucfirst($application->status);
                                    endif;
                                    ?>
                                    <span class="jpm-status-badge jpm-status-<?php echo esc_attr($application->status); ?>"
                                        style="background-color: <?php echo esc_attr($bg_color); ?>; color: <?php echo esc_attr($text_color); ?>;">
                                        <?php echo esc_html($status_name); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user): ?>
                                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->ID)); ?>">
                                            <?php echo esc_html($user->display_name); ?>
                                        </a>
                                        <br><small><?php echo esc_html($user->user_email); ?></small>
                                    <?php else: ?>
                                        <em><?php esc_html_e('Guest', 'job-posting-manager'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($application_number); ?></td>
                                <td>
                                    <div class="jpm-actions-menu">
                                        <button type="button" class="button button-small jpm-actions-menu__toggle" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Open actions', 'job-posting-manager'); ?>">
                                            &bull;&bull;&bull;
                                        </button>
                                        <div class="jpm-actions-menu__dropdown">
                                            <select class="jpm-application-status-select"
                                                data-application-id="<?php echo esc_attr($application->id); ?>">
                                                <?php
                                                $status_options = self::get_status_options();
                                                foreach ($status_options as $slug => $name):
                                                    ?>
                                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($application->status, $slug); ?>>
                                                        <?php echo esc_html($name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=jpm-applications&action=print&application_id=' . absint($application->id)), 'jpm_print_application', 'jpm_print_nonce')); ?>"
                                                target="_blank" class="button button-small" style="text-decoration: none;">
                                                <?php esc_html_e('View Details', 'job-posting-manager'); ?>
                                            </a>
                                            <?php
                                            $is_accepted = strtolower((string) $application->status) === 'accepted';
                                            $is_whitelisted = isset($application->whitelisted) && (int) $application->whitelisted === 1;
                                            ?>
                                            <span class="jpm-whitelist-container<?php echo $is_accepted ? '' : ' jpm-whitelist-container--hidden'; ?>">
                                                <?php if ($is_whitelisted): ?>
                                                    <span class="button button-small" style="opacity:0.85;cursor:default;background:#f0f0f1;border-color:#c3c4c7;color:#2c3338;">
                                                        <?php esc_html_e('Whitelisted', 'job-posting-manager'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('jpm_whitelist_application', 'jpm_whitelist_application_nonce'); ?>
                                                        <input type="hidden" name="action" value="jpm_whitelist_application">
                                                        <input type="hidden" name="application_id" value="<?php echo esc_attr($application->id); ?>">
                                                        <input type="hidden" name="jpm_return_search" value="<?php echo esc_attr($filters['search']); ?>">
                                                        <input type="hidden" name="jpm_return_job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                                                        <input type="hidden" name="jpm_return_status" value="<?php echo esc_attr($filters['status']); ?>">
                                                        <button type="button" class="button button-small jpm-open-pending-form-confirm"
                                                            data-confirm-message="<?php echo esc_attr(__('Add this applicant to the whitelist?', 'job-posting-manager')); ?>">
                                                            <?php esc_html_e('Whitelist', 'job-posting-manager'); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($medical_status_slug && $application->status === $medical_status_slug): ?>
                                                <button type="button" class="button button-small jpm-view-requirements-btn"
                                                    data-application-id="<?php echo esc_attr($application->id); ?>"
                                                    data-requirements-type="medical" style="text-decoration: none;">
                                                    <?php esc_html_e('View Requirements', 'job-posting-manager'); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($interview_status_slug && $application->status === $interview_status_slug): ?>
                                                <button type="button" class="button button-small jpm-view-requirements-btn"
                                                    data-application-id="<?php echo esc_attr($application->id); ?>"
                                                    data-requirements-type="interview" style="text-decoration: none;">
                                                    <?php esc_html_e('View Requirements', 'job-posting-manager'); ?>
                                                </button>
                                            <?php endif; ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('jpm_delete_application', 'jpm_delete_application_nonce'); ?>
                                                <input type="hidden" name="action" value="jpm_delete_application">
                                                <input type="hidden" name="application_id" value="<?php echo esc_attr($application->id); ?>">
                                                <input type="hidden" name="jpm_return_search" value="<?php echo esc_attr($filters['search']); ?>">
                                                <input type="hidden" name="jpm_return_job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                                                <input type="hidden" name="jpm_return_status" value="<?php echo esc_attr($filters['status']); ?>">
                                                <button type="submit" class="button button-small"
                                                    style="border-color: #b32d2e; color: #b32d2e;"
                                                    onclick="return confirm('<?php echo esc_js(__('Delete this application permanently? This cannot be undone.', 'job-posting-manager')); ?>');">
                                                    <?php esc_html_e('Delete', 'job-posting-manager'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="jpm-view-requirements-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                aria-labelledby="jpm-view-requirements-modal-title" style="max-width: 600px;">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-view-requirements-modal-title"><?php esc_html_e('Requirements', 'job-posting-manager'); ?></h2>
                <div id="jpm-view-requirements-content" style="margin-top: 20px;">
                    <div style="text-align: center; padding: 20px;">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        <p><?php esc_html_e('Loading requirements...', 'job-posting-manager'); ?></p>
                    </div>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="button jpm-view-requirements-close">
                        <?php esc_html_e('Close', 'job-posting-manager'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="jpm-report-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jpm-report-modal-title"
                style="max-width: 520px;">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-report-modal-title"><?php esc_html_e('Generate Applications Report', 'job-posting-manager'); ?></h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('Select a date range for your report.', 'job-posting-manager'); ?>
                </p>
                <form method="get" action="" id="jpm-report-form">
                    <input type="hidden" name="page" value="jpm-applications">
                    <input type="hidden" name="report_generate" value="1">
                    <input type="hidden" name="status" value="<?php echo esc_attr($filters['status']); ?>">
                    <input type="hidden" name="job_id" value="<?php echo esc_attr((string) $filters['job_id']); ?>">
                    <input type="hidden" name="search" value="<?php echo esc_attr($filters['search']); ?>">
                    <?php wp_nonce_field('jpm_generate_applications_report', 'jpm_report_nonce'); ?>

                    <div class="jpm-admin-field">
                        <label style="margin-bottom: 10px;"><?php esc_html_e('Date Range', 'job-posting-manager'); ?></label>
                        <div style="display:grid;gap:8px;">
                            <label><input type="radio" name="report_range" value="today" <?php checked($report_range === '' || $report_range === 'today'); ?>> <?php esc_html_e('Today', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="last_week" <?php checked($report_range, 'last_week'); ?>> <?php esc_html_e('Last Week', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="last_month" <?php checked($report_range, 'last_month'); ?>> <?php esc_html_e('Last Month', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="last_3_months" <?php checked($report_range, 'last_3_months'); ?>> <?php esc_html_e('Last 3 Months', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="last_6_months" <?php checked($report_range, 'last_6_months'); ?>> <?php esc_html_e('Last 6 Months', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="last_year" <?php checked($report_range, 'last_year'); ?>> <?php esc_html_e('Last Year', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_range" value="custom" <?php checked($report_range, 'custom'); ?>> <?php esc_html_e('Custom Range', 'job-posting-manager'); ?></label>
                        </div>
                    </div>

                    <div id="jpm-report-custom-range" class="jpm-admin-field jpm-admin-field--inline" style="display:none;">
                        <div>
                            <label for="jpm-report-start"><?php esc_html_e('Start Date', 'job-posting-manager'); ?></label>
                            <input type="date" id="jpm-report-start" name="report_start" value="<?php echo esc_attr($report_start); ?>">
                        </div>
                        <div>
                            <label for="jpm-report-end"><?php esc_html_e('End Date', 'job-posting-manager'); ?></label>
                            <input type="date" id="jpm-report-end" name="report_end" value="<?php echo esc_attr($report_end); ?>">
                        </div>
                    </div>

                    <div class="jpm-admin-field">
                        <label style="margin-bottom: 10px;"><?php esc_html_e('Report Format', 'job-posting-manager'); ?></label>
                        <div style="display:flex;gap:20px;flex-wrap:wrap;">
                            <label><input type="radio" name="report_format" value="pdf" <?php checked($report_format, 'pdf'); ?>> <?php esc_html_e('PDF', 'job-posting-manager'); ?></label>
                            <label><input type="radio" name="report_format" value="csv" <?php checked($report_format !== 'pdf'); ?>> <?php esc_html_e('CSV', 'job-posting-manager'); ?></label>
                        </div>
                    </div>

                    <div style="margin-top: 20px; text-align: right;">
                        <button type="button" class="button jpm-report-cancel">
                            <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Generate Report', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="jpm-medical-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jpm-medical-modal-title">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-medical-modal-title"><?php esc_html_e('Set Medical Requirements', 'job-posting-manager'); ?></h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Provide the requirements and schedule details for this applicant.', 'job-posting-manager'); ?>
                </p>
                <form id="jpm-medical-form">
                    <input type="hidden" name="application_id" value="">
                    <div class="jpm-admin-field">
                        <label for="jpm-medical-requirements">
                            <?php esc_html_e('Requirements', 'job-posting-manager'); ?>
                        </label>
                        <textarea id="jpm-medical-requirements" name="requirements" rows="4" required
                            placeholder="<?php esc_attr_e('e.g., Bring two valid IDs, chest X-ray results, vaccination card...', 'job-posting-manager'); ?>"></textarea>
                        <small
                            class="description"><?php esc_html_e('List what the customer must bring.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field">
                        <label for="jpm-medical-address">
                            <?php esc_html_e('Medical Address', 'job-posting-manager'); ?>
                        </label>
                        <input id="jpm-medical-address" type="text" name="address"
                            value="<?php echo esc_attr($this->get_default_medical_address()); ?>" required />
                        <small
                            class="description"><?php esc_html_e('Default clinic address is pre-filled.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field jpm-admin-field--inline">
                        <div>
                            <label for="jpm-medical-date">
                                <?php esc_html_e('Date', 'job-posting-manager'); ?>
                            </label>
                            <input id="jpm-medical-date" type="date" name="date" />
                        </div>
                        <div>
                            <label for="jpm-medical-time">
                                <?php esc_html_e('Time', 'job-posting-manager'); ?>
                            </label>
                            <input id="jpm-medical-time" type="time" name="time" />
                        </div>
                    </div>
                    <div class="jpm-admin-field" style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Save and Update Status', 'job-posting-manager'); ?>
                        </button>
                        <button type="button" class="button jpm-medical-cancel">
                            <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Rejection Modal -->
        <div id="jpm-rejection-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jpm-rejection-modal-title">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-rejection-modal-title"><?php esc_html_e('Application Rejection Details', 'job-posting-manager'); ?>
                </h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Please provide the reason for rejection to notify the applicant.', 'job-posting-manager'); ?>
                </p>
                <form id="jpm-rejection-form">
                    <input type="hidden" name="application_id" value="">
                    <div class="jpm-admin-field">
                        <label for="jpm-rejection-problem-area">
                            <?php esc_html_e('The problem is in the:', 'job-posting-manager'); ?>
                        </label>
                        <select id="jpm-rejection-problem-area" name="problem_area" required>
                            <option value=""><?php esc_html_e('-- Select --', 'job-posting-manager'); ?></option>
                            <option value="personal_information">
                                <?php esc_html_e('Personal Information', 'job-posting-manager'); ?>
                            </option>
                            <option value="education"><?php esc_html_e('Education', 'job-posting-manager'); ?></option>
                            <option value="employment"><?php esc_html_e('Employment', 'job-posting-manager'); ?></option>
                        </select>
                        <small
                            class="description"><?php esc_html_e('Select the area where the problem was found.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field">
                        <label for="jpm-rejection-notes">
                            <?php esc_html_e('Notes', 'job-posting-manager'); ?>
                        </label>
                        <textarea id="jpm-rejection-notes" name="notes" rows="6" required
                            placeholder="<?php esc_attr_e('Provide detailed notes about the rejection reason...', 'job-posting-manager'); ?>"></textarea>
                        <small
                            class="description"><?php esc_html_e('These notes will be sent to the applicant via email.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field" style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Save and Update Status', 'job-posting-manager'); ?>
                        </button>
                        <button type="button" class="button jpm-rejection-cancel">
                            <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Interview Modal -->
        <div id="jpm-interview-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jpm-interview-modal-title">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-interview-modal-title"><?php esc_html_e('Set For Interview Requirements', 'job-posting-manager'); ?>
                </h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Provide the interview requirements and schedule details for this applicant.', 'job-posting-manager'); ?>
                </p>
                <form id="jpm-interview-form">
                    <input type="hidden" name="application_id" value="">
                    <div class="jpm-admin-field">
                        <label for="jpm-interview-requirements">
                            <?php esc_html_e('Requirements', 'job-posting-manager'); ?>
                        </label>
                        <textarea id="jpm-interview-requirements" name="requirements" rows="4" required
                            placeholder="<?php esc_attr_e('e.g., Bring two valid IDs, resume, portfolio...', 'job-posting-manager'); ?>"></textarea>
                        <small
                            class="description"><?php esc_html_e('List what the customer must bring.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field">
                        <label for="jpm-interview-address">
                            <?php esc_html_e('Interview Address', 'job-posting-manager'); ?>
                        </label>
                        <input id="jpm-interview-address" type="text" name="address" required
                            value="<?php echo esc_attr('2250 Singalong St., Malate Manila'); ?>"
                            placeholder="<?php esc_attr_e('Enter interview location address', 'job-posting-manager'); ?>" />
                        <small
                            class="description"><?php esc_html_e('Default interview address is pre-filled.', 'job-posting-manager'); ?></small>
                    </div>
                    <div class="jpm-admin-field jpm-admin-field--inline">
                        <div>
                            <label for="jpm-interview-date">
                                <?php esc_html_e('Date', 'job-posting-manager'); ?>
                            </label>
                            <input id="jpm-interview-date" type="date" name="date" />
                        </div>
                        <div>
                            <label for="jpm-interview-time">
                                <?php esc_html_e('Time', 'job-posting-manager'); ?>
                            </label>
                            <input id="jpm-interview-time" type="time" name="time" />
                        </div>
                    </div>
                    <div class="jpm-admin-field" style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Save and Update Status', 'job-posting-manager'); ?>
                        </button>
                        <button type="button" class="button jpm-interview-cancel">
                            <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="jpm-pending-form-confirm-modal" class="jpm-admin-modal" style="display:none;">
            <div class="jpm-admin-modal__backdrop"></div>
            <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                aria-labelledby="jpm-pending-form-confirm-title" style="max-width: 480px;">
                <button type="button" class="jpm-admin-modal__close"
                    aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                <h2 id="jpm-pending-form-confirm-title"><?php esc_html_e('Please confirm', 'job-posting-manager'); ?></h2>
                <p id="jpm-pending-form-confirm-text" class="description" style="margin-bottom: 16px;"></p>
                <div style="text-align: right;">
                    <button type="button" class="button jpm-pending-form-confirm-cancel">
                        <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                    </button>
                    <button type="button" class="button button-primary jpm-pending-form-confirm-ok">
                        <?php esc_html_e('Confirm', 'job-posting-manager'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .jpm-status-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .jpm-status-pending {
                background: #ffc107;
                color: #000;
            }

            .jpm-status-reviewed {
                background: #17a2b8;
                color: #fff;
            }

            .jpm-status-accepted {
                background: #28a745;
                color: #fff;
            }

            .jpm-status-rejected {
                background: #dc3545;
                color: #fff;
            }

            .jpm-application-status-select {
                padding: 4px 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-size: 13px;
            }

            .jpm-whitelist-container {
                display: inline-block;
            }

            .jpm-whitelist-container.jpm-whitelist-container--hidden {
                display: none !important;
            }

            .jpm-empty-state {
                display: flex;
                justify-content: center;
                align-items: center;
                margin: 60px 0;
            }

            .jpm-empty-card {
                text-align: center;
                max-width: 520px;
                padding: 40px 32px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            }

            .jpm-empty-icon {
                font-size: 48px;
                margin-bottom: 16px;
            }

            .jpm-empty-card h2 {
                margin: 0 0 12px;
            }

            .jpm-empty-card p {
                margin: 0 0 20px;
                color: #555d66;
            }

            .jpm-empty-actions {
                display: flex;
                justify-content: center;
                gap: 12px;
                flex-wrap: wrap;
            }

            .jpm-admin-modal {
                position: fixed;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 99999;
            }

            .jpm-admin-modal__backdrop {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
            }

            .jpm-admin-modal__dialog {
                position: relative;
                background: #fff;
                padding: 24px;
                border-radius: 6px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                width: 100%;
                max-width: 560px;
                z-index: 2;
            }

            .jpm-admin-modal__close {
                position: absolute;
                top: 10px;
                right: 10px;
                border: none;
                background: none;
                font-size: 22px;
                cursor: pointer;
            }

            .jpm-admin-field {
                margin-bottom: 14px;
            }

            .jpm-admin-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px;
            }

            .jpm-admin-field textarea,
            .jpm-admin-field input[type="text"],
            .jpm-admin-field input[type="date"],
            .jpm-admin-field input[type="time"] {
                width: 100%;
                max-width: 100%;
            }

            .jpm-admin-field--inline {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 12px;
            }

            .jpm-actions-menu {
                position: relative;
                display: inline-block;
            }

            .jpm-actions-menu__toggle {
                min-width: 34px;
                text-align: center;
                padding: 0 8px;
                line-height: 1.2;
                font-size: 18px;
            }

            .jpm-actions-menu__dropdown {
                position: absolute;
                right: 0;
                top: calc(100% + 6px);
                z-index: 30;
                min-width: 220px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.14);
                padding: 8px;
                display: none;
            }

            .jpm-actions-menu.is-open .jpm-actions-menu__dropdown {
                display: block;
            }

            .jpm-actions-menu__dropdown .button,
            .jpm-actions-menu__dropdown .jpm-application-status-select {
                display: block;
                width: 100%;
                margin: 0 0 6px;
                text-align: left;
            }

            .jpm-actions-menu__dropdown .button:last-child {
                margin-bottom: 0;
            }

            .jpm-actions-menu__dropdown form {
                margin: 0;
            }

            .jpm-status-update-success {
                color: #28a745;
                margin-left: 5px;
                font-size: 12px;
            }
        </style>

        <?php
        $medical_status_slug = $this->get_medical_status_slug();
        // Get rejected status slug
        $rejected_status_slug = '';
        // Get interview status slug
        $interview_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'rejected' || $name === 'rejected') {
                $rejected_status_slug = $status['slug'];
            }
            if (
                $slug === 'for-interview' || $slug === 'for_interview' || $slug === 'forinterview' ||
                $name === 'for interview' || stripos($name, 'for interview') !== false || stripos($name, 'interview') !== false
            ) {
                $interview_status_slug = $status['slug'];
            }
        }
        $status_labels = self::get_status_options();
        $status_styles_for_js = [];
        foreach ($all_statuses as $status) {
            if (empty($status['slug'])) {
                continue;
            }
            $slug = $status['slug'];
            $bg = isset($status['color']) ? sanitize_hex_color($status['color']) : '';
            $fg = isset($status['text_color']) ? sanitize_hex_color($status['text_color']) : '';
            $status_styles_for_js[$slug] = [
                'color' => $bg ? $bg : '#ffc107',
                'text_color' => $fg ? $fg : '#000000',
            ];
        }
        ?>
        <script>
            jQuery(function ($) {
                const statusLabels = <?php echo wp_json_encode($status_labels); ?>;
                const statusStyles = <?php echo wp_json_encode($status_styles_for_js); ?>;
                const medicalStatusSlug = '<?php echo esc_js($medical_status_slug); ?>';
                const rejectedStatusSlug = '<?php echo esc_js($rejected_status_slug); ?>';
                const interviewStatusSlug = '<?php echo esc_js($interview_status_slug); ?>';
                const updateNonce = '<?php echo esc_js(wp_create_nonce('jpm_update_status')); ?>';
                const medicalNonce = '<?php echo esc_js(wp_create_nonce('jpm_medical_details')); ?>';
                const rejectionNonce = '<?php echo esc_js(wp_create_nonce('jpm_rejection_details')); ?>';
                const interviewNonce = '<?php echo esc_js(wp_create_nonce('jpm_interview_details')); ?>';
                const defaultMedicalAddress = '<?php echo esc_js($this->get_default_medical_address()); ?>';
                const defaultInterviewAddress = '<?php echo esc_js('2250 Singalong St., Malate Manila'); ?>';

                let activeSelect = null;
                let activeRow = null;
                let previousStatus = null;
                let activeApplicationId = null;
                let jpmPendingConfirmForm = null;
                const $applicationsFiltersPanel = $('#jpm-applications-filters-panel');
                const $toggleApplicationsFiltersBtn = $('#jpm-toggle-applications-filters');

                function updateApplicationsFiltersToggleLabel() {
                    const isVisible = $applicationsFiltersPanel.is(':visible');
                    $toggleApplicationsFiltersBtn
                        .attr('aria-expanded', isVisible ? 'true' : 'false')
                        .text(isVisible
                            ? '<?php echo esc_js(__('Hide Search/Filter', 'job-posting-manager')); ?>'
                            : '<?php echo esc_js(__('Search/Filter', 'job-posting-manager')); ?>');
                }

                $toggleApplicationsFiltersBtn.on('click', function () {
                    $applicationsFiltersPanel.stop(true, true).slideToggle(120, updateApplicationsFiltersToggleLabel);
                });
                updateApplicationsFiltersToggleLabel();

                function closePendingFormConfirmModal() {
                    $('#jpm-pending-form-confirm-modal').hide();
                    jpmPendingConfirmForm = null;
                }

                $(document).on('click', '.jpm-actions-menu__toggle', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const $menu = $(this).closest('.jpm-actions-menu');
                    const isOpen = $menu.hasClass('is-open');
                    $('.jpm-actions-menu').removeClass('is-open').find('.jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                    if (!isOpen) {
                        $menu.addClass('is-open');
                        $(this).attr('aria-expanded', 'true');
                    }
                });

                $(document).on('click', function () {
                    $('.jpm-actions-menu').removeClass('is-open').find('.jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                });

                $(document).on('click', '.jpm-actions-menu__dropdown', function (e) {
                    e.stopPropagation();
                });

                $(document).on('click', '.jpm-open-pending-form-confirm', function (e) {
                    e.preventDefault();
                    const msg = $(this).attr('data-confirm-message') || '';
                    jpmPendingConfirmForm = $(this).closest('form').get(0);
                    $('#jpm-pending-form-confirm-text').text(msg);
                    $('#jpm-pending-form-confirm-modal').show();
                });
                $(document).on('click', '.jpm-pending-form-confirm-cancel, #jpm-pending-form-confirm-modal .jpm-admin-modal__close, #jpm-pending-form-confirm-modal .jpm-admin-modal__backdrop', function () {
                    closePendingFormConfirmModal();
                });
                $(document).on('click', '.jpm-pending-form-confirm-ok', function () {
                    if (jpmPendingConfirmForm) {
                        jpmPendingConfirmForm.submit();
                    }
                    closePendingFormConfirmModal();
                });

                $('.jpm-application-status-select').each(function () {
                    $(this).data('previous', $(this).val());
                });

                function updateBadge($row, statusSlug) {
                    const label = statusLabels[statusSlug] || statusSlug.charAt(0).toUpperCase() + statusSlug.slice(1);
                    const $badge = $row.find('.jpm-status-badge');

                    const cleanedClass = ($badge.attr('class') || '').replace(/\bjpm-status-[^\s]+/g, '').trim();
                    $badge.attr('class', `${cleanedClass} jpm-status-badge jpm-status-${statusSlug}`);
                    $badge.text(label);

                    let st = statusStyles[statusSlug];
                    if (!st && statusSlug) {
                        const lower = String(statusSlug).toLowerCase();
                        const keys = Object.keys(statusStyles);
                        for (let i = 0; i < keys.length; i++) {
                            if (String(keys[i]).toLowerCase() === lower) {
                                st = statusStyles[keys[i]];
                                break;
                            }
                        }
                    }
                    const bg = st && st.color ? st.color : '#ffc107';
                    const fg = st && st.text_color ? st.text_color : '#000000';
                    $badge.attr('style', 'background-color: ' + bg + '; color: ' + fg + ';');
                }

                function syncWhitelistVisibility($row, statusSlug) {
                    const $wl = $row.find('.jpm-whitelist-container');
                    if (!$wl.length) {
                        return;
                    }
                    if (String(statusSlug).toLowerCase() === 'accepted') {
                        $wl.removeClass('jpm-whitelist-container--hidden');
                    } else {
                        $wl.addClass('jpm-whitelist-container--hidden');
                    }
                }

                function showSuccess($select) {
                    $select.next('.jpm-status-update-success').remove();
                    $select.after('<span class="jpm-status-update-success">&#10003; <?php echo esc_js(__('Updated', 'job-posting-manager')); ?></span>');
                    setTimeout(function () {
                        $select.siblings('.jpm-status-update-success').fadeOut(function () {
                            $(this).remove();
                        });
                    }, 2000);
                }

                function updateStatus(applicationId, newStatus, $select, $row) {
                    $select.prop('disabled', true);
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_update_application_status',
                            application_id: applicationId,
                            status: newStatus,
                            nonce: updateNonce
                        }
                    }).done(function (response) {
                        if (response.success) {
                            updateBadge($row, newStatus);
                            syncWhitelistVisibility($row, newStatus);
                            $select.data('previous', newStatus);
                            showSuccess($select);
                        } else {
                            alert('Error updating status: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                            $select.val($select.data('previous'));
                        }
                    }).fail(function () {
                        alert('Error updating status. Please try again.');
                        $select.val($select.data('previous'));
                    }).always(function () {
                        $select.prop('disabled', false);
                    });
                }

                function closeMedicalModal(revertSelect = false) {
                    $('#jpm-medical-modal').hide();
                    if (revertSelect && activeSelect && previousStatus !== null) {
                        activeSelect.val(previousStatus);
                    }
                    activeSelect = null;
                    activeRow = null;
                    activeApplicationId = null;
                    previousStatus = null;
                }

                function openMedicalModal(applicationId, $select, $row) {
                    if (!medicalStatusSlug) {
                        updateStatus(applicationId, $select.data('previous'), $select, $row);
                        return;
                    }

                    activeSelect = $select;
                    activeRow = $row;
                    activeApplicationId = applicationId;
                    previousStatus = $select.data('previous');

                    const $modal = $('#jpm-medical-modal');
                    const $form = $('#jpm-medical-form');

                    $form[0].reset();
                    $form.find('input[name="application_id"]').val(applicationId);
                    $form.find('input[name="address"]').val(defaultMedicalAddress);

                    $modal.show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_get_medical_details',
                            application_id: applicationId,
                            nonce: medicalNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data && response.data.details) {
                            const details = response.data.details;
                            $form.find('textarea[name="requirements"]').val(details.requirements || '');
                            $form.find('input[name="address"]').val(details.address || defaultMedicalAddress);
                            $form.find('input[name="date"]').val(details.date || '');
                            $form.find('input[name="time"]').val(details.time || '');
                        }
                    });
                }

                function closeRejectionModal(revertSelect = false) {
                    $('#jpm-rejection-modal').hide();
                    if (revertSelect && activeSelect && previousStatus !== null) {
                        activeSelect.val(previousStatus);
                    }
                    activeSelect = null;
                    activeRow = null;
                    activeApplicationId = null;
                    previousStatus = null;
                }

                function openRejectionModal(applicationId, $select, $row) {
                    if (!rejectedStatusSlug) {
                        updateStatus(applicationId, $select.data('previous'), $select, $row);
                        return;
                    }

                    activeSelect = $select;
                    activeRow = $row;
                    activeApplicationId = applicationId;
                    previousStatus = $select.data('previous');

                    const $modal = $('#jpm-rejection-modal');
                    const $form = $('#jpm-rejection-form');

                    $form[0].reset();
                    $form.find('input[name="application_id"]').val(applicationId);

                    $modal.show();

                    // Load existing rejection details if any
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_get_rejection_details',
                            application_id: applicationId,
                            nonce: rejectionNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data && response.data.details) {
                            const details = response.data.details;
                            $form.find('select[name="problem_area"]').val(details.problem_area || '');
                            $form.find('textarea[name="notes"]').val(details.notes || '');
                        }
                    });
                }

                function closeInterviewModal(revertSelect = false) {
                    $('#jpm-interview-modal').hide();
                    if (revertSelect && activeSelect && previousStatus !== null) {
                        activeSelect.val(previousStatus);
                    }
                    activeSelect = null;
                    activeRow = null;
                    activeApplicationId = null;
                    previousStatus = null;
                }

                function openInterviewModal(applicationId, $select, $row) {
                    if (!interviewStatusSlug) {
                        updateStatus(applicationId, $select.data('previous'), $select, $row);
                        return;
                    }

                    activeSelect = $select;
                    activeRow = $row;
                    activeApplicationId = applicationId;
                    previousStatus = $select.data('previous');

                    const $modal = $('#jpm-interview-modal');
                    const $form = $('#jpm-interview-form');

                    $form[0].reset();
                    $form.find('input[name="application_id"]').val(applicationId);
                    $form.find('input[name="address"]').val(defaultInterviewAddress);

                    $modal.show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_get_interview_details',
                            application_id: applicationId,
                            nonce: interviewNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data && response.data.details) {
                            const details = response.data.details;
                            $form.find('textarea[name="requirements"]').val(details.requirements || '');
                            $form.find('input[name="address"]').val(details.address || defaultInterviewAddress);
                            $form.find('input[name="date"]').val(details.date || '');
                            $form.find('input[name="time"]').val(details.time || '');
                        }
                    });
                }

                $('.jpm-application-status-select').on('change', function () {
                    const $select = $(this);
                    const applicationId = $select.data('application-id');
                    const newStatus = $select.val();
                    const $row = $select.closest('tr');

                    if (medicalStatusSlug && newStatus === medicalStatusSlug) {
                        openMedicalModal(applicationId, $select, $row);
                        return;
                    }

                    if (rejectedStatusSlug && newStatus === rejectedStatusSlug) {
                        openRejectionModal(applicationId, $select, $row);
                        return;
                    }

                    if (interviewStatusSlug && newStatus === interviewStatusSlug) {
                        openInterviewModal(applicationId, $select, $row);
                        return;
                    }

                    updateStatus(applicationId, newStatus, $select, $row);
                });

                $('#jpm-medical-form').on('submit', function (e) {
                    e.preventDefault();
                    const $form = $(this);

                    if (!activeSelect || !activeRow || !activeApplicationId) {
                        closeMedicalModal(true);
                        return;
                    }

                    const requirements = $form.find('textarea[name="requirements"]').val();
                    const address = $form.find('input[name="address"]').val();
                    const date = $form.find('input[name="date"]').val();
                    const time = $form.find('input[name="time"]').val();

                    if (!requirements.trim()) {
                        alert('<?php echo esc_js(__('Please enter the requirements.', 'job-posting-manager')); ?>');
                        return;
                    }

                    const $submitBtn = $form.find('button[type="submit"]');
                    $submitBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'job-posting-manager')); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_save_medical_details',
                            application_id: activeApplicationId,
                            requirements: requirements,
                            address: address,
                            date: date,
                            time: time,
                            nonce: medicalNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data) {
                            const statusSlug = response.data.status_slug || medicalStatusSlug;
                            if (activeSelect) {
                                activeSelect.val(statusSlug);
                                activeSelect.data('previous', statusSlug);
                            }
                            if (activeRow) {
                                updateBadge(activeRow, statusSlug);
                                syncWhitelistVisibility(activeRow, statusSlug);
                            }
                            if (activeSelect) {
                                showSuccess(activeSelect);
                            }
                            closeMedicalModal(false);
                        } else {
                            alert(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Failed to save medical details.', 'job-posting-manager')); ?>');
                        }
                    }).fail(function () {
                        alert('<?php echo esc_js(__('Error saving medical details. Please try again.', 'job-posting-manager')); ?>');
                    }).always(function () {
                        $submitBtn.prop('disabled', false).text('<?php echo esc_js(__('Save and Update Status', 'job-posting-manager')); ?>');
                    });
                });

                $('#jpm-rejection-form').on('submit', function (e) {
                    e.preventDefault();
                    const $form = $(this);

                    if (!activeSelect || !activeRow || !activeApplicationId) {
                        closeRejectionModal(true);
                        return;
                    }

                    const problemArea = $form.find('select[name="problem_area"]').val();
                    const notes = $form.find('textarea[name="notes"]').val();

                    if (!problemArea) {
                        alert('<?php echo esc_js(__('Please select the problem area.', 'job-posting-manager')); ?>');
                        return;
                    }

                    if (!notes.trim()) {
                        alert('<?php echo esc_js(__('Please enter the rejection notes.', 'job-posting-manager')); ?>');
                        return;
                    }

                    const $submitBtn = $form.find('button[type="submit"]');
                    $submitBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'job-posting-manager')); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_save_rejection_details',
                            application_id: activeApplicationId,
                            problem_area: problemArea,
                            notes: notes,
                            nonce: rejectionNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data) {
                            const statusSlug = response.data.status_slug || rejectedStatusSlug;
                            if (activeSelect) {
                                activeSelect.val(statusSlug);
                                activeSelect.data('previous', statusSlug);
                            }
                            if (activeRow) {
                                updateBadge(activeRow, statusSlug);
                                syncWhitelistVisibility(activeRow, statusSlug);
                            }
                            if (activeSelect) {
                                showSuccess(activeSelect);
                            }
                            closeRejectionModal(false);
                        } else {
                            alert(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Failed to save rejection details.', 'job-posting-manager')); ?>');
                        }
                    }).fail(function () {
                        alert('<?php echo esc_js(__('Error saving rejection details. Please try again.', 'job-posting-manager')); ?>');
                    }).always(function () {
                        $submitBtn.prop('disabled', false).text('<?php echo esc_js(__('Save and Update Status', 'job-posting-manager')); ?>');
                    });
                });

                $('.jpm-medical-cancel, .jpm-admin-modal__close, .jpm-admin-modal__backdrop').on('click', function () {
                    if ($(this).closest('#jpm-medical-modal').length) {
                        closeMedicalModal(true);
                    }
                });

                $('.jpm-rejection-cancel, #jpm-rejection-modal .jpm-admin-modal__close, #jpm-rejection-modal .jpm-admin-modal__backdrop').on('click', function () {
                    closeRejectionModal(true);
                });

                $('#jpm-interview-form').on('submit', function (e) {
                    e.preventDefault();
                    const $form = $(this);

                    if (!activeSelect || !activeRow || !activeApplicationId) {
                        closeInterviewModal(true);
                        return;
                    }

                    const requirements = $form.find('textarea[name="requirements"]').val();
                    const address = $form.find('input[name="address"]').val();
                    const date = $form.find('input[name="date"]').val();
                    const time = $form.find('input[name="time"]').val();

                    if (!requirements.trim()) {
                        alert('<?php echo esc_js(__('Please enter the requirements.', 'job-posting-manager')); ?>');
                        return;
                    }

                    if (!address.trim()) {
                        alert('<?php echo esc_js(__('Please enter the interview address.', 'job-posting-manager')); ?>');
                        return;
                    }

                    const $submitBtn = $form.find('button[type="submit"]');
                    $submitBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'job-posting-manager')); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_save_interview_details',
                            application_id: activeApplicationId,
                            requirements: requirements,
                            address: address,
                            date: date,
                            time: time,
                            nonce: interviewNonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data) {
                            const statusSlug = response.data.status_slug || interviewStatusSlug;
                            if (activeSelect) {
                                activeSelect.val(statusSlug);
                                activeSelect.data('previous', statusSlug);
                            }
                            if (activeRow) {
                                updateBadge(activeRow, statusSlug);
                                syncWhitelistVisibility(activeRow, statusSlug);
                            }
                            if (activeSelect) {
                                showSuccess(activeSelect);
                            }
                            closeInterviewModal(false);
                        } else {
                            alert(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Failed to save interview details.', 'job-posting-manager')); ?>');
                        }
                    }).fail(function () {
                        alert('<?php echo esc_js(__('Error saving interview details. Please try again.', 'job-posting-manager')); ?>');
                    }).always(function () {
                        $submitBtn.prop('disabled', false).text('<?php echo esc_js(__('Save and Update Status', 'job-posting-manager')); ?>');
                    });
                });

                $('.jpm-interview-cancel, #jpm-interview-modal .jpm-admin-modal__close, #jpm-interview-modal .jpm-admin-modal__backdrop').on('click', function () {
                    closeInterviewModal(true);
                });

                // View Requirements functionality - no client-side caching

                function closeViewRequirementsModal() {
                    $('#jpm-view-requirements-modal').hide();
                }

                function formatDate(dateString) {
                    if (!dateString) return '';

                    // Parse the date (assuming format YYYY-MM-DD)
                    const date = new Date(dateString + 'T00:00:00');
                    if (isNaN(date.getTime())) return dateString; // Return original if invalid

                    const months = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];

                    const month = months[date.getMonth()];
                    const day = date.getDate();
                    const year = date.getFullYear();

                    return month + ' ' + day + ', ' + year;
                }

                function renderRequirementsHTML(details, type) {
                    type = type || 'medical';
                    let html = '<div class="jpm-view-requirements-details">';

                    if (details.requirements) {
                        html += '<div class="jpm-admin-field" style="margin-bottom: 20px;">';
                        html += '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #0073aa;"><?php echo esc_js(__('Requirements:', 'job-posting-manager')); ?></label>';
                        html += '<div style="padding: 12px; background: #f5f5f5; border-radius: 4px; line-height: 1.6; white-space: pre-wrap;">' + $('<div>').text(details.requirements).html().replace(/\n/g, '<br>') + '</div>';
                        html += '</div>';
                    }

                    if (details.address) {
                        html += '<div class="jpm-admin-field" style="margin-bottom: 20px;">';
                        const addressLabel = type === 'interview' ? '<?php echo esc_js(__('Interview Address:', 'job-posting-manager')); ?>' : '<?php echo esc_js(__('Medical Address:', 'job-posting-manager')); ?>';
                        html += '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #0073aa;">' + addressLabel + '</label>';
                        html += '<div style="padding: 12px; background: #f5f5f5; border-radius: 4px;">' + $('<div>').text(details.address).html() + '</div>';
                        html += '</div>';
                    }

                    if (details.date || details.time) {
                        html += '<div class="jpm-admin-field" style="margin-bottom: 20px;">';
                        html += '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #0073aa;"><?php echo esc_js(__('Schedule:', 'job-posting-manager')); ?></label>';
                        html += '<div style="padding: 12px; background: #f5f5f5; border-radius: 4px;">';
                        if (details.date) {
                            const formattedDate = formatDate(details.date);
                            html += '<strong><?php echo esc_js(__('Date:', 'job-posting-manager')); ?></strong> ' + $('<div>').text(formattedDate).html();
                        }
                        if (details.time) {
                            if (details.date) html += '<br>';
                            html += '<strong><?php echo esc_js(__('Time:', 'job-posting-manager')); ?></strong> ' + $('<div>').text(details.time).html();
                        }
                        html += '</div>';
                        html += '</div>';
                    }

                    if (!details.requirements && !details.address && !details.date && !details.time) {
                        html += '<div style="text-align: center; padding: 20px; color: #666;">';
                        html += '<p><?php echo esc_js(__('No requirements have been set for this application yet.', 'job-posting-manager')); ?></p>';
                        html += '</div>';
                    }

                    html += '</div>';
                    return html;
                }

                function openViewRequirementsModal(applicationId, type) {
                    type = type || 'medical';
                    const $modal = $('#jpm-view-requirements-modal');
                    const $content = $('#jpm-view-requirements-content');
                    const $title = $('#jpm-view-requirements-modal-title');

                    // Update modal title based on type
                    if (type === 'interview') {
                        $title.text('<?php echo esc_js(__('Interview Requirements', 'job-posting-manager')); ?>');
                    } else {
                        $title.text('<?php echo esc_js(__('Medical Requirements', 'job-posting-manager')); ?>');
                    }

                    // Show loading state
                    $content.html('<div style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none; margin: 0;"></span><p><?php echo esc_js(__('Loading requirements...', 'job-posting-manager')); ?></p></div>');
                    $modal.show();

                    // Determine which AJAX action and nonce to use
                    const action = type === 'interview' ? 'jpm_get_interview_details' : 'jpm_get_medical_details';
                    const nonce = type === 'interview' ? interviewNonce : medicalNonce;

                    // Fetch details
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: action,
                            application_id: applicationId,
                            nonce: nonce
                        }
                    }).done(function (response) {
                        if (response.success && response.data && response.data.details) {
                            const details = response.data.details;
                            const html = renderRequirementsHTML(details, type);

                            $content.html(html);
                        } else {
                            $content.html('<div style="text-align: center; padding: 20px; color: #dc3545;"><p><?php echo esc_js(__('Failed to load requirements. Please try again.', 'job-posting-manager')); ?></p></div>');
                        }
                    }).fail(function () {
                        $content.html('<div style="text-align: center; padding: 20px; color: #dc3545;"><p><?php echo esc_js(__('Error loading requirements. Please try again.', 'job-posting-manager')); ?></p></div>');
                    });
                }

                // Handle View Requirements button click
                $(document).on('click', '.jpm-view-requirements-btn', function () {
                    const applicationId = $(this).data('application-id');
                    const requirementsType = $(this).data('requirements-type') || 'medical';
                    if (applicationId) {
                        openViewRequirementsModal(applicationId, requirementsType);
                    }
                });

                // Close View Requirements modal
                $(document).on('click', '.jpm-view-requirements-close, #jpm-view-requirements-modal .jpm-admin-modal__close, #jpm-view-requirements-modal .jpm-admin-modal__backdrop', function () {
                    closeViewRequirementsModal();
                });

                function closeReportModal() {
                    $('#jpm-report-modal').hide();
                }

                function toggleCustomReportRange() {
                    const selectedRange = $('input[name="report_range"]:checked').val();
                    const isCustom = selectedRange === 'custom';
                    $('#jpm-report-custom-range').toggle(isCustom);
                    $('#jpm-report-start, #jpm-report-end').prop('required', isCustom);
                }

                $('#jpm-open-report-modal').on('click', function () {
                    $('#jpm-report-modal').show();
                    toggleCustomReportRange();
                });

                $(document).on('change', 'input[name="report_range"]', function () {
                    toggleCustomReportRange();
                });

                $('#jpm-report-form').on('submit', function (e) {
                    const selectedRange = $('input[name="report_range"]:checked').val();
                    if (selectedRange !== 'custom') {
                        return;
                    }

                    const start = $('#jpm-report-start').val();
                    const end = $('#jpm-report-end').val();
                    if (!start || !end) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Please select both start and end dates.', 'job-posting-manager')); ?>');
                        return;
                    }

                    if (start > end) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Start date cannot be later than end date.', 'job-posting-manager')); ?>');
                    }
                });

                $(document).on('click', '.jpm-report-cancel, #jpm-report-modal .jpm-admin-modal__close, #jpm-report-modal .jpm-admin-modal__backdrop', function () {
                    closeReportModal();
                });
            });
        </script>
        <?php
    }

    public function whitelisted_applications_page()
    {
        $report_range = isset($_GET['report_range']) ? sanitize_key(wp_unslash($_GET['report_range'])) : '';
        $report_start = isset($_GET['report_start']) ? sanitize_text_field(wp_unslash($_GET['report_start'])) : '';
        $report_end = isset($_GET['report_end']) ? sanitize_text_field(wp_unslash($_GET['report_end'])) : '';
        $report_format = isset($_GET['report_format']) ? sanitize_key(wp_unslash($_GET['report_format'])) : 'csv';

        $filters = [
            'job_id' => isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0,
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
            'location' => isset($_GET['location']) ? sanitize_text_field(wp_unslash($_GET['location'])) : '',
            'submitted_on' => isset($_GET['submitted_on']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_on'])) : '',
            'submitted_from' => isset($_GET['submitted_from']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_from'])) : '',
            'submitted_to' => isset($_GET['submitted_to']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_to'])) : '',
            'whitelisted_only' => true,
        ];

        $base_filters = [
            'job_id' => $filters['job_id'],
            'search' => $filters['search'],
            'submitted_on' => $filters['submitted_on'],
            'submitted_from' => $filters['submitted_from'],
            'submitted_to' => $filters['submitted_to'],
            'whitelisted_only' => true,
        ];
        $location_options = JPM_Database::list_distinct_locations_from_applications(
            JPM_DB::get_applications($base_filters)
        );
        if ($filters['location'] !== '') {
            $in_list = false;
            foreach ($location_options as $opt) {
                if (strtolower((string) $opt) === strtolower($filters['location'])) {
                    $in_list = true;
                    break;
                }
            }
            if (!$in_list) {
                $location_options[] = $filters['location'];
                usort($location_options, 'strnatcasecmp');
            }
        }

        $applications = JPM_DB::get_applications($filters);
        $has_applications = !empty($applications);
        $total_whitelisted = count($applications);
        $registered_count = 0;
        $jobs_with_applications = [];
        $with_employer_count = 0;
        foreach ($applications as $app) {
            if (!empty($app->user_id) && (int) $app->user_id > 0) {
                $registered_count++;
            }
            if (!empty($app->job_id)) {
                $jobs_with_applications[(int) $app->job_id] = true;
            }
            if (!empty($app->employer_email)) {
                $with_employer_count++;
            }
        }
        $has_filters = (
            $filters['search'] !== '' ||
            $filters['job_id'] > 0 ||
            $filters['location'] !== '' ||
            $filters['submitted_on'] !== '' ||
            $filters['submitted_from'] !== '' ||
            $filters['submitted_to'] !== ''
        );

        $jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        ?>
        <div class="wrap jpm-applications-page">
            <style>
                .jpm-status-badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .jpm-empty-state {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    margin: 60px 0;
                }

                .jpm-empty-card {
                    text-align: center;
                    max-width: 520px;
                    padding: 40px 32px;
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
                }

                .jpm-empty-icon {
                    font-size: 48px;
                    margin-bottom: 16px;
                }

                .jpm-empty-card h2 {
                    margin: 0 0 12px;
                }

                .jpm-empty-card p {
                    margin: 0 0 20px;
                    color: #555d66;
                }

                .jpm-empty-actions {
                    display: flex;
                    justify-content: center;
                    gap: 12px;
                    flex-wrap: wrap;
                }

                .jpm-admin-modal {
                    position: fixed;
                    inset: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 99999;
                }

                .jpm-admin-modal__backdrop {
                    position: absolute;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.45);
                }

                .jpm-admin-modal__dialog {
                    position: relative;
                    background: #fff;
                    padding: 24px;
                    border-radius: 6px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                    width: 100%;
                    max-width: 560px;
                    z-index: 2;
                }

                .jpm-admin-modal__close {
                    position: absolute;
                    top: 10px;
                    right: 10px;
                    border: none;
                    background: none;
                    font-size: 22px;
                    cursor: pointer;
                }

                .jpm-admin-field {
                    margin-bottom: 14px;
                }

                .jpm-admin-field label {
                    display: block;
                    font-weight: 600;
                    margin-bottom: 6px;
                }

                .jpm-admin-field textarea,
                .jpm-admin-field input[type="text"],
                .jpm-admin-field input[type="email"],
                .jpm-admin-field input[type="tel"],
                .jpm-admin-field input[type="date"],
                .jpm-admin-field input[type="time"] {
                    width: 100%;
                    max-width: 100%;
                }

                .jpm-admin-field--inline {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                    gap: 12px;
                }

                .jpm-actions-menu {
                    position: relative;
                    display: inline-block;
                }

                .jpm-actions-menu__toggle {
                    min-width: 34px;
                    text-align: center;
                    padding: 0 8px;
                    line-height: 1.2;
                    font-size: 18px;
                }

                .jpm-actions-menu__dropdown {
                    position: absolute;
                    right: 0;
                    top: calc(100% + 6px);
                    z-index: 25;
                    min-width: 180px;
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.14);
                    padding: 8px;
                    display: none;
                }

                .jpm-actions-menu.is-open .jpm-actions-menu__dropdown {
                    display: block;
                }

                .jpm-actions-menu__dropdown .button {
                    display: block;
                    width: 100%;
                    margin: 0 0 6px;
                    text-align: left;
                }

                .jpm-actions-menu__dropdown .button:last-child {
                    margin-bottom: 0;
                }

                .jpm-actions-menu__dropdown form {
                    margin: 0;
                }
            </style>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <h1 style="margin:0;"><?php esc_html_e('Whitelisted applications', 'job-posting-manager'); ?></h1>
                <button type="button" class="button button-primary" id="jpm-open-whitelist-report-modal">
                    <?php esc_html_e('Generate Report', 'job-posting-manager'); ?>
                </button>
            </div>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e('Applicants you marked as whitelisted from the Applications screen (accepted status only).', 'job-posting-manager'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-applications')); ?>"><?php esc_html_e('Back to all applications', 'job-posting-manager'); ?></a>
            </p>
            <div class="jpm-dashboard-status-cards" style="display:flex;flex-wrap:wrap;gap:15px;margin:16px 0 20px;">
                <div style="flex:1;min-width:180px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                    <div style="font-size:24px;font-weight:bold;color:#2271b1;margin-bottom:5px;"><?php echo esc_html((string) $total_whitelisted); ?></div>
                    <div style="font-size:14px;color:#666;"><?php esc_html_e('Total Whitelisted', 'job-posting-manager'); ?></div>
                </div>
                <div style="flex:1;min-width:180px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                    <div style="font-size:24px;font-weight:bold;color:#2c3338;margin-bottom:5px;"><?php echo esc_html((string) count($jobs_with_applications)); ?></div>
                    <div style="font-size:14px;color:#666;"><?php esc_html_e('Unique Jobs Applied', 'job-posting-manager'); ?></div>
                </div>
                <div style="flex:1;min-width:180px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                    <div style="font-size:24px;font-weight:bold;color:#008a20;margin-bottom:5px;"><?php echo esc_html((string) $registered_count); ?></div>
                    <div style="font-size:14px;color:#666;"><?php esc_html_e('Registered Users', 'job-posting-manager'); ?></div>
                </div>
                <div style="flex:1;min-width:180px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                    <div style="font-size:24px;font-weight:bold;color:#d63638;margin-bottom:5px;"><?php echo esc_html((string) $with_employer_count); ?></div>
                    <div style="font-size:14px;color:#666;"><?php esc_html_e('With Employer Contact', 'job-posting-manager'); ?></div>
                </div>
            </div>

            <?php if (!empty($report_range)): ?>
                <div class="notice notice-info is-dismissible" style="margin-top: 12px;">
                    <p>
                        <?php
                        if ($report_range === 'custom' && !empty($report_start) && !empty($report_end)) {
                            echo esc_html(sprintf(__('Report range selected: %1$s to %2$s', 'job-posting-manager'), $report_start, $report_end));
                        } else {
                            echo esc_html(sprintf(__('Report range selected: %s', 'job-posting-manager'), ucfirst(str_replace('_', ' ', $report_range))));
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['whitelist_removed'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Application removed from the whitelist.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['whitelist_error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Could not update the whitelist.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['employer_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Employer welfare details saved.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['employer_contact_sent'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Employer contact email sent successfully.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
            <?php
            $employer_err_transient = 'jpm_employer_welfare_err_' . get_current_user_id();
            $employer_err_msg = get_transient($employer_err_transient);
            if (!empty($_GET['employer_error']) && $employer_err_msg !== false && $employer_err_msg !== '') {
                delete_transient($employer_err_transient);
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html((string) $employer_err_msg); ?></p>
                </div>
            <?php } ?>
            <?php
            $employer_contact_err_transient = 'jpm_employer_contact_err_' . get_current_user_id();
            $employer_contact_err_msg = get_transient($employer_contact_err_transient);
            if (!empty($_GET['employer_contact_error']) && $employer_contact_err_msg !== false && $employer_contact_err_msg !== '') {
                delete_transient($employer_contact_err_transient);
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html((string) $employer_contact_err_msg); ?></p>
                </div>
            <?php } ?>

            <div style="margin: 16px 0 8px;">
                <button type="button" class="button" id="jpm-toggle-whitelist-filters" aria-expanded="false">
                    <?php esc_html_e('Search/Filter', 'job-posting-manager'); ?>
                </button>
            </div>

            <div class="jpm-filters" id="jpm-whitelist-filters-panel" style="display:none; margin: 12px 0 20px; padding: 15px; background: #fff; border: 1px solid #ccc;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="jpm-whitelisted-applications">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <?php esc_html_e('Search Applications:', 'job-posting-manager'); ?>
                        </label>
                        <input type="text" name="search" class="regular-text"
                            value="<?php echo esc_attr($filters['search']); ?>"
                            placeholder="<?php esc_attr_e('Search by name, email, or application number...', 'job-posting-manager'); ?>"
                            style="width: 100%; max-width: 500px;">
                    </div>
                    <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                        <label>
                            <?php esc_html_e('Filter by Job:', 'job-posting-manager'); ?>
                            <select name="job_id">
                                <option value=""><?php esc_html_e('All Jobs', 'job-posting-manager'); ?></option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo esc_attr($job->ID); ?>" <?php selected($filters['job_id'], $job->ID); ?>>
                                        <?php echo esc_html($job->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php esc_html_e('Filter by job location:', 'job-posting-manager'); ?>
                            <select name="location" style="min-width: 200px;">
                                <option value="" <?php selected($filters['location'], ''); ?>><?php esc_html_e('All locations', 'job-posting-manager'); ?></option>
                                <?php foreach ($location_options as $location_opt): ?>
                                    <option value="<?php echo esc_attr($location_opt); ?>" <?php selected(strtolower((string) $filters['location']), strtolower((string) $location_opt)); ?>>
                                        <?php echo esc_html($location_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div style="margin-top: 16px; display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 4px;">
                                <?php esc_html_e('Submitted on (exact date)', 'job-posting-manager'); ?>
                            </label>
                            <input type="date" name="submitted_on" class="regular-text"
                                value="<?php echo esc_attr($filters['submitted_on']); ?>">
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 4px;">
                                <?php esc_html_e('Submitted from', 'job-posting-manager'); ?>
                            </label>
                            <input type="date" name="submitted_from" class="regular-text"
                                value="<?php echo esc_attr($filters['submitted_from']); ?>">
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 4px;">
                                <?php esc_html_e('Submitted to', 'job-posting-manager'); ?>
                            </label>
                            <input type="date" name="submitted_to" class="regular-text"
                                value="<?php echo esc_attr($filters['submitted_to']); ?>">
                        </div>
                    </div>
                    <p class="description" style="margin-top: 10px; margin-bottom: 0;">
                        <?php esc_html_e('If you set an exact "Submitted on" date, the from/to range is ignored. Otherwise use from and/or to for a range.', 'job-posting-manager'); ?>
                    </p>
                    <div style="margin-top: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="submit" class="button button-primary"
                            value="<?php esc_html_e('Apply Filters', 'job-posting-manager'); ?>">
                        <?php if ($has_filters): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-whitelisted-applications')); ?>" class="button">
                                <?php esc_html_e('Clear', 'job-posting-manager'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (!$has_applications): ?>
                <?php if ($has_filters): ?>
                    <p><?php esc_html_e('No whitelisted applications match your search or filters.', 'job-posting-manager'); ?></p>
                <?php else: ?>
                <div class="jpm-empty-state">
                    <div class="jpm-empty-card">
                        <div class="jpm-empty-icon">[ ]</div>
                        <h2><?php esc_html_e('No whitelisted applications', 'job-posting-manager'); ?></h2>
                        <p><?php esc_html_e('When an application has status Accepted, use the Whitelist action on the Applications page to add it here.', 'job-posting-manager'); ?></p>
                        <div class="jpm-empty-actions">
                            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=jpm-applications')); ?>">
                                <?php esc_html_e('Go to Applications', 'job-posting-manager'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="jpm-table-responsive">
                    <table class="widefat striped jpm-applications-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Application Date', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('User', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Application Number', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Job location', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Employer (welfare)', 'job-posting-manager'); ?></th>
                                <th><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $application):
                                $job = get_post($application->job_id);
                                $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
                                $form_data = json_decode($application->notes, true);
                                if (!is_array($form_data)) {
                                    $form_data = [];
                                }
                                $application_number = isset($form_data['application_number']) ? $form_data['application_number'] : '';
                                $row_location = $job ? JPM_Database::get_job_posting_location((int) $application->job_id) : '';
                                $emp_fn = isset($application->employer_first_name) ? trim((string) $application->employer_first_name) : '';
                                $emp_ln = isset($application->employer_last_name) ? trim((string) $application->employer_last_name) : '';
                                $emp_phone = isset($application->employer_phone) ? trim((string) $application->employer_phone) : '';
                                $emp_email = isset($application->employer_email) ? trim((string) $application->employer_email) : '';
                                $employer_history = [];
                                if ($emp_email !== '') {
                                    $employer_history = JPM_Database::get_employer_email_history((int) $application->id, $emp_email);
                                }
                                $employer_history_payload = !empty($employer_history) ? wp_json_encode($employer_history) : '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($application->id); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $application->job_id . '&action=edit')); ?>">
                                            <?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?></td>
                                    <td>
                                        <?php
                                        $status_info = self::get_status_by_slug($application->status);
                                        if ($status_info):
                                            $bg_color = $status_info['color'];
                                            $text_color = $status_info['text_color'];
                                            $status_name = $status_info['name'];
                                        else:
                                            $bg_color = '#ffc107';
                                            $text_color = '#000000';
                                            $status_name = ucfirst($application->status);
                                        endif;
                                        ?>
                                        <span class="jpm-status-badge jpm-status-<?php echo esc_attr($application->status); ?>"
                                            style="background-color: <?php echo esc_attr($bg_color); ?>; color: <?php echo esc_attr($text_color); ?>;">
                                            <?php echo esc_html($status_name); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user): ?>
                                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->ID)); ?>">
                                                <?php echo esc_html($user->display_name); ?>
                                            </a>
                                            <br><small><?php echo esc_html($user->user_email); ?></small>
                                        <?php else: ?>
                                            <em><?php esc_html_e('Guest', 'job-posting-manager'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($application_number); ?></td>
                                    <td><?php echo $row_location !== '' ? esc_html($row_location) : '&mdash;'; ?></td>
                                    <td>
                                        <?php
                                        if ($emp_fn !== '' || $emp_ln !== '' || $emp_email !== '') {
                                            $name_line = trim($emp_fn . ' ' . $emp_ln);
                                            if ($name_line !== '') {
                                                echo esc_html($name_line);
                                            }
                                            if ($emp_email !== '') {
                                                echo $name_line !== '' ? '<br>' : '';
                                                echo '<small>' . esc_html($emp_email) . '</small>';
                                                echo '<br><button type="button" class="button button-small jpm-open-employer-contact-modal" style="margin-top:6px;"';
                                                echo ' data-application-id="' . esc_attr((string) (int) $application->id) . '"';
                                                echo ' data-to-email="' . esc_attr($emp_email) . '"';
                                                echo '>';
                                                echo esc_html__('Contact', 'job-posting-manager');
                                                echo '</button>';
                                                if (!empty($employer_history_payload)) {
                                                    echo '<button type="button" class="button button-small jpm-open-employer-history-modal" style="margin-top:6px;margin-left:6px;"';
                                                    echo ' data-application-id="' . esc_attr((string) (int) $application->id) . '"';
                                                    echo ' data-employer-email="' . esc_attr($emp_email) . '"';
                                                    echo ' data-history="' . esc_attr($employer_history_payload) . '"';
                                                    echo '>';
                                                    echo esc_html__('History', 'job-posting-manager');
                                                    echo '</button>';
                                                }
                                            }
                                        } else {
                                            echo '&mdash;';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="jpm-actions-menu">
                                            <button type="button" class="button button-small jpm-actions-menu__toggle" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Open actions', 'job-posting-manager'); ?>">
                                                &bull;&bull;&bull;
                                            </button>
                                            <div class="jpm-actions-menu__dropdown">
                                                <button type="button" class="button button-small jpm-open-employer-welfare-modal"
                                                    data-application-id="<?php echo esc_attr((string) (int) $application->id); ?>"
                                                    data-employer-first="<?php echo esc_attr($emp_fn); ?>"
                                                    data-employer-last="<?php echo esc_attr($emp_ln); ?>"
                                                    data-employer-phone="<?php echo esc_attr($emp_phone); ?>"
                                                    data-employer-email="<?php echo esc_attr($emp_email); ?>">
                                                    <?php echo $emp_email !== '' ? esc_html__('Update employer', 'job-posting-manager') : esc_html__('Add employer', 'job-posting-manager'); ?>
                                                </button>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=jpm-applications&action=print&application_id=' . absint($application->id)), 'jpm_print_application', 'jpm_print_nonce')); ?>"
                                                    target="_blank" class="button button-small" style="text-decoration: none;">
                                                    <?php esc_html_e('View Details', 'job-posting-manager'); ?>
                                                </a>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <?php wp_nonce_field('jpm_unwhitelist_application', 'jpm_unwhitelist_application_nonce'); ?>
                                                    <input type="hidden" name="action" value="jpm_unwhitelist_application">
                                                    <input type="hidden" name="application_id" value="<?php echo esc_attr($application->id); ?>">
                                                    <input type="hidden" name="jpm_return_search" value="<?php echo esc_attr($filters['search']); ?>">
                                                    <input type="hidden" name="jpm_return_job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                                                    <input type="hidden" name="jpm_return_location" value="<?php echo esc_attr($filters['location']); ?>">
                                                    <input type="hidden" name="jpm_return_submitted_on" value="<?php echo esc_attr($filters['submitted_on']); ?>">
                                                    <input type="hidden" name="jpm_return_submitted_from" value="<?php echo esc_attr($filters['submitted_from']); ?>">
                                                    <input type="hidden" name="jpm_return_submitted_to" value="<?php echo esc_attr($filters['submitted_to']); ?>">
                                                    <button type="button" class="button button-small jpm-open-pending-form-confirm"
                                                        data-confirm-message="<?php echo esc_attr(__('Remove this applicant from the whitelist?', 'job-posting-manager')); ?>">
                                                        <?php esc_html_e('Remove from whitelist', 'job-posting-manager'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div id="jpm-pending-form-confirm-modal" class="jpm-admin-modal" style="display:none;">
                <div class="jpm-admin-modal__backdrop"></div>
                <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="jpm-pending-form-confirm-title" style="max-width: 480px;">
                    <button type="button" class="jpm-admin-modal__close"
                        aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                    <h2 id="jpm-pending-form-confirm-title"><?php esc_html_e('Please confirm', 'job-posting-manager'); ?></h2>
                    <p id="jpm-pending-form-confirm-text" class="description" style="margin-bottom: 16px;"></p>
                    <div style="text-align: right;">
                        <button type="button" class="button jpm-pending-form-confirm-cancel">
                            <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                        </button>
                        <button type="button" class="button button-primary jpm-pending-form-confirm-ok">
                            <?php esc_html_e('Confirm', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div id="jpm-employer-welfare-modal" class="jpm-admin-modal" style="display:none;">
                <div class="jpm-admin-modal__backdrop"></div>
                <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="jpm-employer-welfare-modal-title" style="max-width: 520px;">
                    <button type="button" class="jpm-admin-modal__close"
                        aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                    <h2 id="jpm-employer-welfare-modal-title"><?php esc_html_e('Employer welfare check', 'job-posting-manager'); ?></h2>
                    <p id="jpm-employer-welfare-app-ref" class="description" style="margin-bottom: 12px;"></p>
                    <p class="description" style="margin-bottom: 8px;">
                        <?php esc_html_e('Record the applicant\'s employer contact for a welfare check. All fields are required.', 'job-posting-manager'); ?>
                    </p>
                    <p class="description" style="margin-bottom: 16px; font-style: italic; color: #646970;">
                        <?php esc_html_e('Example: Maria Santos · +63 917 000 0000 · hr.contact@employer.com', 'job-posting-manager'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="jpm-employer-welfare-form">
                        <?php wp_nonce_field('jpm_save_employer_welfare', 'jpm_save_employer_welfare_nonce'); ?>
                        <input type="hidden" name="action" value="jpm_save_employer_welfare">
                        <input type="hidden" name="application_id" id="jpm-employer-welfare-application-id" value="">
                        <input type="hidden" name="jpm_return_search" value="<?php echo esc_attr($filters['search']); ?>">
                        <input type="hidden" name="jpm_return_job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                        <input type="hidden" name="jpm_return_location" value="<?php echo esc_attr($filters['location']); ?>">
                        <input type="hidden" name="jpm_return_submitted_on" value="<?php echo esc_attr($filters['submitted_on']); ?>">
                        <input type="hidden" name="jpm_return_submitted_from" value="<?php echo esc_attr($filters['submitted_from']); ?>">
                        <input type="hidden" name="jpm_return_submitted_to" value="<?php echo esc_attr($filters['submitted_to']); ?>">
                        <div class="jpm-admin-field jpm-admin-field--inline">
                            <div>
                                <label for="jpm-employer-first"><?php esc_html_e('Employer first name', 'job-posting-manager'); ?></label>
                                <input type="text" id="jpm-employer-first" name="employer_first_name" class="regular-text" required
                                    autocomplete="given-name" maxlength="191"
                                    placeholder="<?php esc_attr_e('e.g. Maria', 'job-posting-manager'); ?>">
                            </div>
                            <div>
                                <label for="jpm-employer-last"><?php esc_html_e('Employer last name', 'job-posting-manager'); ?></label>
                                <input type="text" id="jpm-employer-last" name="employer_last_name" class="regular-text" required
                                    autocomplete="family-name" maxlength="191"
                                    placeholder="<?php esc_attr_e('e.g. Santos', 'job-posting-manager'); ?>">
                            </div>
                        </div>
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-phone"><?php esc_html_e('Phone number', 'job-posting-manager'); ?></label>
                            <input type="tel" id="jpm-employer-phone" name="employer_phone" class="regular-text" required
                                autocomplete="tel" maxlength="100"
                                placeholder="<?php esc_attr_e('e.g. +63 917 000 0000', 'job-posting-manager'); ?>">
                        </div>
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-email"><?php esc_html_e('Email', 'job-posting-manager'); ?></label>
                            <input type="email" id="jpm-employer-email" name="employer_email" class="regular-text" required
                                autocomplete="email" maxlength="191"
                                placeholder="<?php esc_attr_e('e.g. hr.contact@employer.com', 'job-posting-manager'); ?>">
                        </div>
                        <div style="margin-top: 20px; text-align: right;">
                            <button type="button" class="button jpm-employer-welfare-cancel">
                                <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Save employer', 'job-posting-manager'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="jpm-employer-contact-modal" class="jpm-admin-modal" style="display:none;">
                <div class="jpm-admin-modal__backdrop"></div>
                <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="jpm-employer-contact-modal-title" style="max-width: 620px;">
                    <button type="button" class="jpm-admin-modal__close"
                        aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                    <h2 id="jpm-employer-contact-modal-title"><?php esc_html_e('Contact employer', 'job-posting-manager'); ?></h2>
                    <p id="jpm-employer-contact-app-ref" class="description" style="margin-bottom: 12px;"></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="jpm-employer-contact-form">
                        <?php wp_nonce_field('jpm_contact_employer_welfare', 'jpm_contact_employer_welfare_nonce'); ?>
                        <input type="hidden" name="action" value="jpm_contact_employer_welfare">
                        <input type="hidden" name="application_id" id="jpm-employer-contact-application-id" value="">
                        <input type="hidden" name="jpm_return_search" value="<?php echo esc_attr($filters['search']); ?>">
                        <input type="hidden" name="jpm_return_job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                        <input type="hidden" name="jpm_return_location" value="<?php echo esc_attr($filters['location']); ?>">
                        <input type="hidden" name="jpm_return_submitted_on" value="<?php echo esc_attr($filters['submitted_on']); ?>">
                        <input type="hidden" name="jpm_return_submitted_from" value="<?php echo esc_attr($filters['submitted_from']); ?>">
                        <input type="hidden" name="jpm_return_submitted_to" value="<?php echo esc_attr($filters['submitted_to']); ?>">
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-contact-to"><?php esc_html_e('To', 'job-posting-manager'); ?></label>
                            <input type="email" id="jpm-employer-contact-to" name="to_email" class="regular-text" required
                                autocomplete="email" maxlength="191">
                        </div>
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-contact-from"><?php esc_html_e('From', 'job-posting-manager'); ?></label>
                            <input type="email" id="jpm-employer-contact-from" name="from_email" class="regular-text" required
                                value="<?php echo esc_attr(get_option('admin_email')); ?>" autocomplete="email" maxlength="191">
                        </div>
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-contact-subject"><?php esc_html_e('Subject', 'job-posting-manager'); ?></label>
                            <input type="text" id="jpm-employer-contact-subject" name="subject" class="regular-text" required maxlength="191"
                                placeholder="<?php esc_attr_e('Welfare check regarding your employee application', 'job-posting-manager'); ?>">
                        </div>
                        <div class="jpm-admin-field">
                            <label for="jpm-employer-contact-content"><?php esc_html_e('Content', 'job-posting-manager'); ?></label>
                            <textarea id="jpm-employer-contact-content" name="content" rows="8" required
                                placeholder="<?php esc_attr_e('Write your message to the employer here.', 'job-posting-manager'); ?>"></textarea>
                        </div>
                        <div style="margin-top: 20px; text-align: right;">
                            <button type="button" class="button jpm-employer-contact-cancel">
                                <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Send Email', 'job-posting-manager'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="jpm-employer-history-modal" class="jpm-admin-modal" style="display:none;">
                <div class="jpm-admin-modal__backdrop"></div>
                <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="jpm-employer-history-modal-title" style="max-width: 760px;">
                    <button type="button" class="jpm-admin-modal__close"
                        aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                    <h2 id="jpm-employer-history-modal-title"><?php esc_html_e('Employer email history', 'job-posting-manager'); ?></h2>
                    <p id="jpm-employer-history-app-ref" class="description" style="margin-bottom: 10px;"></p>
                    <div id="jpm-employer-history-content" style="max-height: 420px; overflow: auto; padding-right: 4px;"></div>
                    <div style="margin-top: 16px; text-align: right;">
                        <button type="button" class="button jpm-employer-history-cancel">
                            <?php esc_html_e('Close', 'job-posting-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div id="jpm-whitelist-report-modal" class="jpm-admin-modal" style="display:none;">
                <div class="jpm-admin-modal__backdrop"></div>
                <div class="jpm-admin-modal__dialog" role="dialog" aria-modal="true"
                    aria-labelledby="jpm-whitelist-report-modal-title" style="max-width: 520px;">
                    <button type="button" class="jpm-admin-modal__close"
                        aria-label="<?php esc_attr_e('Close modal', 'job-posting-manager'); ?>">&times;</button>
                    <h2 id="jpm-whitelist-report-modal-title"><?php esc_html_e('Generate whitelisted applications report', 'job-posting-manager'); ?></h2>
                    <p class="description" style="margin-bottom: 16px;">
                        <?php esc_html_e('Select a date range for the report. Current list filters (job, search, location, submitted dates) are applied.', 'job-posting-manager'); ?>
                    </p>
                    <form method="get" action="" id="jpm-whitelist-report-form">
                        <input type="hidden" name="page" value="jpm-whitelisted-applications">
                        <input type="hidden" name="report_generate" value="1">
                        <input type="hidden" name="job_id" value="<?php echo esc_attr((string) (int) $filters['job_id']); ?>">
                        <input type="hidden" name="search" value="<?php echo esc_attr($filters['search']); ?>">
                        <input type="hidden" name="location" value="<?php echo esc_attr($filters['location']); ?>">
                        <input type="hidden" name="submitted_on" value="<?php echo esc_attr($filters['submitted_on']); ?>">
                        <input type="hidden" name="submitted_from" value="<?php echo esc_attr($filters['submitted_from']); ?>">
                        <input type="hidden" name="submitted_to" value="<?php echo esc_attr($filters['submitted_to']); ?>">
                        <?php wp_nonce_field('jpm_generate_whitelist_report', 'jpm_report_nonce'); ?>

                        <div class="jpm-admin-field">
                            <label style="margin-bottom: 10px;"><?php esc_html_e('Date Range', 'job-posting-manager'); ?></label>
                            <div style="display:grid;gap:8px;">
                                <label><input type="radio" name="report_range" value="today" <?php checked($report_range === '' || $report_range === 'today'); ?>> <?php esc_html_e('Today', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="last_week" <?php checked($report_range, 'last_week'); ?>> <?php esc_html_e('Last Week', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="last_month" <?php checked($report_range, 'last_month'); ?>> <?php esc_html_e('Last Month', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="last_3_months" <?php checked($report_range, 'last_3_months'); ?>> <?php esc_html_e('Last 3 Months', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="last_6_months" <?php checked($report_range, 'last_6_months'); ?>> <?php esc_html_e('Last 6 Months', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="last_year" <?php checked($report_range, 'last_year'); ?>> <?php esc_html_e('Last Year', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_range" value="custom" <?php checked($report_range, 'custom'); ?>> <?php esc_html_e('Custom Range', 'job-posting-manager'); ?></label>
                            </div>
                        </div>

                        <div id="jpm-whitelist-report-custom-range" class="jpm-admin-field jpm-admin-field--inline" style="display:none;">
                            <div>
                                <label for="jpm-whitelist-report-start"><?php esc_html_e('Start Date', 'job-posting-manager'); ?></label>
                                <input type="date" id="jpm-whitelist-report-start" name="report_start" value="<?php echo esc_attr($report_start); ?>">
                            </div>
                            <div>
                                <label for="jpm-whitelist-report-end"><?php esc_html_e('End Date', 'job-posting-manager'); ?></label>
                                <input type="date" id="jpm-whitelist-report-end" name="report_end" value="<?php echo esc_attr($report_end); ?>">
                            </div>
                        </div>

                        <div class="jpm-admin-field">
                            <label style="margin-bottom: 10px;"><?php esc_html_e('Report Format', 'job-posting-manager'); ?></label>
                            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                                <label><input type="radio" name="report_format" value="pdf" <?php checked($report_format, 'pdf'); ?>> <?php esc_html_e('PDF', 'job-posting-manager'); ?></label>
                                <label><input type="radio" name="report_format" value="csv" <?php checked($report_format !== 'pdf'); ?>> <?php esc_html_e('CSV', 'job-posting-manager'); ?></label>
                            </div>
                        </div>

                        <div style="margin-top: 20px; text-align: right;">
                            <button type="button" class="button jpm-whitelist-report-cancel">
                                <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Generate Report', 'job-posting-manager'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                jQuery(function ($) {
                    let jpmPendingConfirmForm = null;
                    const $whitelistFiltersPanel = $('#jpm-whitelist-filters-panel');
                    const $toggleWhitelistFiltersBtn = $('#jpm-toggle-whitelist-filters');

                    function updateWhitelistFiltersToggleLabel() {
                        const isVisible = $whitelistFiltersPanel.is(':visible');
                        $toggleWhitelistFiltersBtn
                            .attr('aria-expanded', isVisible ? 'true' : 'false')
                            .text(isVisible
                                ? '<?php echo esc_js(__('Hide Search/Filter', 'job-posting-manager')); ?>'
                                : '<?php echo esc_js(__('Search/Filter', 'job-posting-manager')); ?>');
                    }

                    $toggleWhitelistFiltersBtn.on('click', function () {
                        $whitelistFiltersPanel.stop(true, true).slideToggle(120, updateWhitelistFiltersToggleLabel);
                    });
                    updateWhitelistFiltersToggleLabel();

                    function closePendingFormConfirmModal() {
                        $('#jpm-pending-form-confirm-modal').hide();
                        jpmPendingConfirmForm = null;
                    }

                    $(document).on('click', '.jpm-actions-menu__toggle', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const $menu = $(this).closest('.jpm-actions-menu');
                        const isOpen = $menu.hasClass('is-open');
                        $('.jpm-actions-menu').removeClass('is-open').find('.jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                        if (!isOpen) {
                            $menu.addClass('is-open');
                            $(this).attr('aria-expanded', 'true');
                        }
                    });

                    $(document).on('click', function () {
                        $('.jpm-actions-menu').removeClass('is-open').find('.jpm-actions-menu__toggle').attr('aria-expanded', 'false');
                    });

                    $(document).on('click', '.jpm-actions-menu__dropdown', function (e) {
                        e.stopPropagation();
                    });

                    $(document).on('click', '.jpm-open-pending-form-confirm', function (e) {
                        e.preventDefault();
                        const msg = $(this).attr('data-confirm-message') || '';
                        jpmPendingConfirmForm = $(this).closest('form').get(0);
                        $('#jpm-pending-form-confirm-text').text(msg);
                        $('#jpm-pending-form-confirm-modal').show();
                    });
                    $(document).on('click', '.jpm-pending-form-confirm-cancel, #jpm-pending-form-confirm-modal .jpm-admin-modal__close, #jpm-pending-form-confirm-modal .jpm-admin-modal__backdrop', function () {
                        closePendingFormConfirmModal();
                    });
                    $(document).on('click', '.jpm-pending-form-confirm-ok', function () {
                        if (jpmPendingConfirmForm) {
                            jpmPendingConfirmForm.submit();
                        }
                        closePendingFormConfirmModal();
                    });

                    function closeEmployerWelfareModal() {
                        $('#jpm-employer-welfare-modal').hide();
                    }

                    $(document).on('click', '.jpm-open-employer-welfare-modal', function (e) {
                        e.preventDefault();
                        const $btn = $(this);
                        const appId = $btn.attr('data-application-id') || '';
                        $('#jpm-employer-welfare-application-id').val(appId);
                        $('#jpm-employer-first').val($btn.attr('data-employer-first') || '');
                        $('#jpm-employer-last').val($btn.attr('data-employer-last') || '');
                        $('#jpm-employer-phone').val($btn.attr('data-employer-phone') || '');
                        $('#jpm-employer-email').val($btn.attr('data-employer-email') || '');
                        const refTpl = '<?php echo esc_js(__('Application #%s', 'job-posting-manager')); ?>';
                        $('#jpm-employer-welfare-app-ref').text(refTpl.replace('%s', appId));
                        $('#jpm-employer-welfare-modal').show();
                    });
                    $(document).on('click', '.jpm-employer-welfare-cancel, #jpm-employer-welfare-modal .jpm-admin-modal__close, #jpm-employer-welfare-modal .jpm-admin-modal__backdrop', function () {
                        closeEmployerWelfareModal();
                    });

                    function closeEmployerContactModal() {
                        $('#jpm-employer-contact-modal').hide();
                    }

                    $(document).on('click', '.jpm-open-employer-contact-modal', function (e) {
                        e.preventDefault();
                        const $btn = $(this);
                        const appId = $btn.attr('data-application-id') || '';
                        const toEmail = $btn.attr('data-to-email') || '';
                        const refTpl = '<?php echo esc_js(__('Application #%s', 'job-posting-manager')); ?>';
                        const defaultSubject = '<?php echo esc_js(__('Welfare check regarding employee application', 'job-posting-manager')); ?>';

                        $('#jpm-employer-contact-application-id').val(appId);
                        $('#jpm-employer-contact-to').val(toEmail);
                        $('#jpm-employer-contact-subject').val(defaultSubject);
                        if (!$('#jpm-employer-contact-content').val()) {
                            $('#jpm-employer-contact-content').val('');
                        }
                        $('#jpm-employer-contact-app-ref').text(refTpl.replace('%s', appId));
                        $('#jpm-employer-contact-modal').show();
                    });

                    $(document).on('click', '.jpm-employer-contact-cancel, #jpm-employer-contact-modal .jpm-admin-modal__close, #jpm-employer-contact-modal .jpm-admin-modal__backdrop', function () {
                        closeEmployerContactModal();
                    });

                    function closeEmployerHistoryModal() {
                        $('#jpm-employer-history-modal').hide();
                        $('#jpm-employer-history-content').empty();
                    }

                    $(document).on('click', '.jpm-open-employer-history-modal', function (e) {
                        e.preventDefault();
                        const $btn = $(this);
                        const appId = $btn.attr('data-application-id') || '';
                        const employerEmail = $btn.attr('data-employer-email') || '';
                        const refTpl = '<?php echo esc_js(__('Application #%1$s · Employer: %2$s', 'job-posting-manager')); ?>';
                        const noDataText = '<?php echo esc_js(__('No email history found.', 'job-posting-manager')); ?>';
                        let history = [];

                        try {
                            const raw = $btn.attr('data-history') || '[]';
                            history = JSON.parse(raw);
                        } catch (err) {
                            history = [];
                        }

                        const $content = $('#jpm-employer-history-content');
                        $content.empty();
                        $('#jpm-employer-history-app-ref').text(refTpl.replace('%1$s', appId).replace('%2$s', employerEmail));

                        if (!Array.isArray(history) || history.length === 0) {
                            $content.append($('<p>').text(noDataText));
                        } else {
                            history.forEach(function (item, idx) {
                                const sentAt = item.sent_at_display || item.sent_at || '';
                                const sentBy = item.sent_by_name || '';
                                const fromEmail = item.from_email || '';
                                const subject = item.subject || '';
                                const body = item.content || '';
                                const $card = $('<div style="border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:10px;background:#fff;"></div>');
                                $card.append($('<div style="font-weight:600;margin-bottom:6px;"></div>').text('#' + (idx + 1) + ' - ' + subject));
                                $card.append($('<div style="font-size:12px;color:#555;margin-bottom:2px;"></div>').text('<?php echo esc_js(__('Sent at:', 'job-posting-manager')); ?> ' + sentAt));
                                $card.append($('<div style="font-size:12px;color:#555;margin-bottom:2px;"></div>').text('<?php echo esc_js(__('From:', 'job-posting-manager')); ?> ' + fromEmail));
                                if (sentBy !== '') {
                                    $card.append($('<div style="font-size:12px;color:#555;margin-bottom:8px;"></div>').text('<?php echo esc_js(__('Sent by:', 'job-posting-manager')); ?> ' + sentBy));
                                }
                                $card.append($('<div style="font-size:12px;color:#111;white-space:pre-wrap;background:#f8f8f8;border:1px solid #eee;padding:8px;border-radius:4px;"></div>').text(body));
                                $content.append($card);
                            });
                        }

                        $('#jpm-employer-history-modal').show();
                    });

                    $(document).on('click', '.jpm-employer-history-cancel, #jpm-employer-history-modal .jpm-admin-modal__close, #jpm-employer-history-modal .jpm-admin-modal__backdrop', function () {
                        closeEmployerHistoryModal();
                    });

                    function closeWhitelistReportModal() {
                        $('#jpm-whitelist-report-modal').hide();
                    }
                    function toggleWhitelistCustomReportRange() {
                        const selectedRange = $('#jpm-whitelist-report-form input[name="report_range"]:checked').val();
                        const isCustom = selectedRange === 'custom';
                        $('#jpm-whitelist-report-custom-range').toggle(isCustom);
                        $('#jpm-whitelist-report-start, #jpm-whitelist-report-end').prop('required', isCustom);
                    }
                    $('#jpm-open-whitelist-report-modal').on('click', function () {
                        $('#jpm-whitelist-report-modal').show();
                        toggleWhitelistCustomReportRange();
                    });
                    $(document).on('change', '#jpm-whitelist-report-form input[name="report_range"]', function () {
                        toggleWhitelistCustomReportRange();
                    });
                    $('#jpm-whitelist-report-form').on('submit', function (e) {
                        const selectedRange = $('#jpm-whitelist-report-form input[name="report_range"]:checked').val();
                        if (selectedRange !== 'custom') {
                            return;
                        }
                        const start = $('#jpm-whitelist-report-start').val();
                        const end = $('#jpm-whitelist-report-end').val();
                        if (!start || !end) {
                            e.preventDefault();
                            alert('<?php echo esc_js(__('Please select both start and end dates.', 'job-posting-manager')); ?>');
                            return;
                        }
                        if (start > end) {
                            e.preventDefault();
                            alert('<?php echo esc_js(__('Start date cannot be later than end date.', 'job-posting-manager')); ?>');
                        }
                    });
                    $(document).on('click', '.jpm-whitelist-report-cancel, #jpm-whitelist-report-modal .jpm-admin-modal__close, #jpm-whitelist-report-modal .jpm-admin-modal__backdrop', function () {
                        closeWhitelistReportModal();
                    });
                });
            </script>
        </div>
        <?php
    }

    public function add_meta_boxes()
    {
        add_meta_box('jpm_job_details', __('Job Details', 'job-posting-manager'), [$this, 'job_meta_box'], 'job_posting');
        add_meta_box('jpm_company_image', __('Company Image', 'job-posting-manager'), [$this, 'company_image_meta_box'], 'job_posting', 'side');
        add_meta_box('jpm_job_applications', __('Applications', 'job-posting-manager'), [$this, 'job_applications_meta_box'], 'job_posting', 'normal');
    }

    /**
     * Salary currencies for job postings (admin). Symbol is stored as a prefix on the salary meta value.
     *
     * @return array<string, array{symbol: string, label: string}>
     */
    private static function jpm_job_salary_currency_definitions(): array
    {
        return [
            'php' => ['symbol' => '₱', 'label' => __('PHP — Philippine peso', 'job-posting-manager')],
            'usd' => ['symbol' => '$', 'label' => __('USD — US dollar', 'job-posting-manager')],
            'eur' => ['symbol' => '€', 'label' => __('EUR — Euro', 'job-posting-manager')],
            'gbp' => ['symbol' => '£', 'label' => __('GBP — British pound', 'job-posting-manager')],
            'jpy' => ['symbol' => '¥', 'label' => __('JPY — Japanese yen', 'job-posting-manager')],
            'cny' => ['symbol' => '¥', 'label' => __('CNY — Chinese yuan', 'job-posting-manager')],
            'inr' => ['symbol' => '₹', 'label' => __('INR — Indian rupee', 'job-posting-manager')],
            'aud' => ['symbol' => 'A$', 'label' => __('AUD — Australian dollar', 'job-posting-manager')],
            'cad' => ['symbol' => 'C$', 'label' => __('CAD — Canadian dollar', 'job-posting-manager')],
            'chf' => ['symbol' => 'CHF ', 'label' => __('CHF — Swiss franc', 'job-posting-manager')],
            'sek' => ['symbol' => 'SEK ', 'label' => __('SEK — Swedish krona', 'job-posting-manager')],
            'nok' => ['symbol' => 'NOK ', 'label' => __('NOK — Norwegian krone', 'job-posting-manager')],
            'dkk' => ['symbol' => 'DKK ', 'label' => __('DKK — Danish krone', 'job-posting-manager')],
            'pln' => ['symbol' => 'zł', 'label' => __('PLN — Polish złoty', 'job-posting-manager')],
            'try' => ['symbol' => '₺', 'label' => __('TRY — Turkish lira', 'job-posting-manager')],
            'ils' => ['symbol' => '₪', 'label' => __('ILS — Israeli new shekel', 'job-posting-manager')],
            'aed' => ['symbol' => 'AED ', 'label' => __('AED — UAE dirham', 'job-posting-manager')],
            'sar' => ['symbol' => 'SAR ', 'label' => __('SAR — Saudi riyal', 'job-posting-manager')],
            'qar' => ['symbol' => 'QAR ', 'label' => __('QAR — Qatari riyal', 'job-posting-manager')],
            'sgd' => ['symbol' => 'S$', 'label' => __('SGD — Singapore dollar', 'job-posting-manager')],
            'hkd' => ['symbol' => 'HK$', 'label' => __('HKD — Hong Kong dollar', 'job-posting-manager')],
            'twd' => ['symbol' => 'NT$', 'label' => __('TWD — New Taiwan dollar', 'job-posting-manager')],
            'krw' => ['symbol' => '₩', 'label' => __('KRW — South Korean won', 'job-posting-manager')],
            'thb' => ['symbol' => '฿', 'label' => __('THB — Thai baht', 'job-posting-manager')],
            'myr' => ['symbol' => 'RM ', 'label' => __('MYR — Malaysian ringgit', 'job-posting-manager')],
            'idr' => ['symbol' => 'Rp ', 'label' => __('IDR — Indonesian rupiah', 'job-posting-manager')],
            'vnd' => ['symbol' => '₫', 'label' => __('VND — Vietnamese đồng', 'job-posting-manager')],
            'nzd' => ['symbol' => 'NZ$', 'label' => __('NZD — New Zealand dollar', 'job-posting-manager')],
            'mxn' => ['symbol' => 'MX$', 'label' => __('MXN — Mexican peso', 'job-posting-manager')],
            'brl' => ['symbol' => 'R$', 'label' => __('BRL — Brazilian real', 'job-posting-manager')],
            'ars' => ['symbol' => 'AR$', 'label' => __('ARS — Argentine peso', 'job-posting-manager')],
            'clp' => ['symbol' => 'CL$', 'label' => __('CLP — Chilean peso', 'job-posting-manager')],
            'cop' => ['symbol' => 'COL$', 'label' => __('COP — Colombian peso', 'job-posting-manager')],
            'pen' => ['symbol' => 'S/', 'label' => __('PEN — Peruvian sol', 'job-posting-manager')],
            'zar' => ['symbol' => 'R ', 'label' => __('ZAR — South African rand', 'job-posting-manager')],
            'egp' => ['symbol' => 'EGP ', 'label' => __('EGP — Egyptian pound', 'job-posting-manager')],
            'ngn' => ['symbol' => '₦', 'label' => __('NGN — Nigerian naira', 'job-posting-manager')],
            'kes' => ['symbol' => 'KSh', 'label' => __('KES — Kenyan shilling', 'job-posting-manager')],
            'huf' => ['symbol' => 'Ft ', 'label' => __('HUF — Hungarian forint', 'job-posting-manager')],
            'czk' => ['symbol' => 'Kč', 'label' => __('CZK — Czech koruna', 'job-posting-manager')],
            'ron' => ['symbol' => 'lei ', 'label' => __('RON — Romanian leu', 'job-posting-manager')],
            'bdt' => ['symbol' => '৳', 'label' => __('BDT — Bangladeshi taka', 'job-posting-manager')],
            'pkr' => ['symbol' => 'PKR ', 'label' => __('PKR — Pakistani rupee', 'job-posting-manager')],
        ];
    }

    private static function jpm_normalize_job_salary_currency(string $code): string
    {
        $code = strtolower(sanitize_key($code));
        return array_key_exists($code, self::jpm_job_salary_currency_definitions()) ? $code : 'php';
    }

    private static function jpm_job_salary_currency_symbol(string $code): string
    {
        $code = self::jpm_normalize_job_salary_currency($code);

        return self::jpm_job_salary_currency_definitions()[$code]['symbol'];
    }

    /**
     * Remove known currency glyphs/prefixes from a salary string for editing (amount only).
     */
    private static function jpm_strip_salary_amount_symbols(string $amount): string
    {
        $symbols = [];
        foreach (self::jpm_job_salary_currency_definitions() as $def) {
            if ($def['symbol'] !== '') {
                $symbols[] = $def['symbol'];
            }
        }
        $symbols = array_values(array_unique($symbols, SORT_STRING));
        usort(
            $symbols,
            static function ($a, $b) {
                return strlen($b) <=> strlen($a);
            }
        );
        $out = $amount;
        foreach ($symbols as $sym) {
            $out = str_replace($sym, '', $out);
        }

        return trim($out);
    }

    public function job_meta_box($post)
    {
        // Add nonce field for validation when saving the post
        wp_nonce_field('jpm_job_meta', 'jpm_job_nonce');

        // Get saved values
        $company_name = get_post_meta($post->ID, 'company_name', true);
        $location = get_post_meta($post->ID, 'location', true);
        $salary = get_post_meta($post->ID, 'salary', true);
        $salary_currency = self::jpm_normalize_job_salary_currency((string) get_post_meta($post->ID, 'salary_currency', true));
        $salary_amount = self::jpm_strip_salary_amount_symbols((string) $salary);
        $duration = get_post_meta($post->ID, 'duration', true);
        $expiration_duration = get_post_meta($post->ID, 'expiration_duration', true);
        $expiration_unit = get_post_meta($post->ID, 'expiration_unit', true);

        // Output form fields (ensure these are inside the form)
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="company_name"><?php esc_html_e('Company Name', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="company_name" name="company_name" class="regular-text"
                        value="<?php echo esc_attr($company_name); ?>"
                        placeholder="<?php esc_attr_e('e.g., Acme Corporation', 'job-posting-manager'); ?>" />
                    <p class="description"><?php esc_html_e('Optional: Company or organization name', 'job-posting-manager'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location"><?php esc_html_e('Location', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="location" name="location" class="regular-text"
                        value="<?php echo esc_attr($location); ?>"
                        placeholder="<?php esc_attr_e('e.g., Manila, NCR', 'job-posting-manager'); ?>" />
                    <p class="description"><?php esc_html_e('Optional: Job location', 'job-posting-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="salary"><?php esc_html_e('Salary', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <select id="salary_currency" name="salary_currency" class="regular-text"
                        style="min-width: min(420px, 100%); max-width: 100%;">
                        <?php foreach (self::jpm_job_salary_currency_definitions() as $code => $def): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($salary_currency, $code); ?>>
                                <?php echo esc_html(trim($def['symbol'] . ' ' . $def['label'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="salary" name="salary" class="regular-text"
                        value="<?php echo esc_attr($salary_amount); ?>"
                        placeholder="<?php esc_attr_e('e.g., 50,000 - 70,000', 'job-posting-manager'); ?>" />
                    <p class="description"><?php esc_html_e('Optional: Salary range or amount', 'job-posting-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="duration"><?php esc_html_e('Duration', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="duration" name="duration" class="regular-text"
                        value="<?php echo esc_attr($duration); ?>"
                        placeholder="<?php esc_attr_e('e.g., Full-time, Part-time, Contract', 'job-posting-manager'); ?>" />
                    <p class="description">
                        <?php esc_html_e('Optional: Job duration or employment type', 'job-posting-manager'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="expiration_duration"><?php esc_html_e('Expiration Duration', 'job-posting-manager'); ?> <span
                            class="required">*</span></label>
                </th>
                <td>
                    <input type="number" id="expiration_duration" name="expiration_duration" class="small-text"
                        value="<?php echo esc_attr($expiration_duration); ?>" min="1" step="1" required />
                    <select id="expiration_unit" name="expiration_unit" required>
                        <option value=""><?php esc_html_e('Select unit', 'job-posting-manager'); ?></option>
                        <option value="minutes" <?php selected($expiration_unit, 'minutes'); ?>>
                            <?php esc_html_e('Minutes', 'job-posting-manager'); ?>
                        </option>
                        <option value="hours" <?php selected($expiration_unit, 'hours'); ?>>
                            <?php esc_html_e('Hours', 'job-posting-manager'); ?>
                        </option>
                        <option value="days" <?php selected($expiration_unit, 'days'); ?>>
                            <?php esc_html_e('Days', 'job-posting-manager'); ?>
                        </option>
                        <option value="months" <?php selected($expiration_unit, 'months'); ?>>
                            <?php esc_html_e('Months', 'job-posting-manager'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Required: How long until this job posting expires', 'job-posting-manager'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Posted Date', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <p>
                        <strong><?php echo esc_html(get_the_date('', $post->ID)); ?></strong>
                        <?php if ($post->post_date !== $post->post_modified): ?>
                            <br>
                            <span class="description">
                                <?php esc_html_e('Last modified:', 'job-posting-manager'); ?>
                                <?php echo esc_html(get_the_modified_date('', $post->ID)); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('This is the date when the job was posted. You can change it using the "Publish" box on the right.', 'job-posting-manager'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_job_meta($post_id)
    {
        // Check if user has permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check post type
        if (get_post_type($post_id) !== 'job_posting') {
            return;
        }

        // Save job metadata
        if (isset($_POST['jpm_job_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jpm_job_nonce'])), 'jpm_job_meta')) {
            // Validate required expiration duration field
            $expiration_missing = empty($_POST['expiration_duration']) || empty($_POST['expiration_unit']);
            if ($expiration_missing) {
                // Prevent saving if this is a new post or if explicitly saving (not autosave)
                if (!defined('DOING_AUTOSAVE') || !DOING_AUTOSAVE) {
                    // For new posts, we'll let it save but show an error
                    // The HTML5 required attribute should prevent form submission anyway
                }
            }
            if (isset($_POST['company_name'])) {
                update_post_meta($post_id, 'company_name', sanitize_text_field(wp_unslash($_POST['company_name'])));
            } else {
                delete_post_meta($post_id, 'company_name');
            }

            if (isset($_POST['location'])) {
                update_post_meta($post_id, 'location', sanitize_text_field(wp_unslash($_POST['location'])));
            } else {
                delete_post_meta($post_id, 'location');
            }

            if (isset($_POST['salary'])) {
                $salary_currency = self::jpm_normalize_job_salary_currency(
                    isset($_POST['salary_currency']) ? (string) wp_unslash($_POST['salary_currency']) : 'php'
                );
                $salary_symbol = self::jpm_job_salary_currency_symbol($salary_currency);
                $salary_amount = sanitize_text_field(wp_unslash($_POST['salary']));
                $salary_amount = self::jpm_strip_salary_amount_symbols($salary_amount);
                $salary_amount = trim($salary_amount);

                if (!empty($salary_amount)) {
                    update_post_meta($post_id, 'salary', $salary_symbol . $salary_amount);
                } else {
                    delete_post_meta($post_id, 'salary');
                }

                update_post_meta($post_id, 'salary_currency', $salary_currency);
            } else {
                delete_post_meta($post_id, 'salary');
                delete_post_meta($post_id, 'salary_currency');
            }

            if (isset($_POST['duration'])) {
                update_post_meta($post_id, 'duration', sanitize_text_field(wp_unslash($_POST['duration'])));
            } else {
                delete_post_meta($post_id, 'duration');
            }

            // Handle expiration duration (required field)
            if (
                isset($_POST['expiration_duration']) && isset($_POST['expiration_unit']) &&
                !empty($_POST['expiration_duration']) && !empty($_POST['expiration_unit'])
            ) {
                $expiration_duration = absint(wp_unslash($_POST['expiration_duration']));
                $expiration_unit = sanitize_text_field(wp_unslash($_POST['expiration_unit']));

                // Validate unit
                $allowed_units = ['minutes', 'hours', 'days', 'months'];
                if (!in_array($expiration_unit, $allowed_units)) {
                    $expiration_unit = 'days'; // Default to days if invalid
                }

                // Save expiration duration and unit
                update_post_meta($post_id, 'expiration_duration', $expiration_duration);
                update_post_meta($post_id, 'expiration_unit', $expiration_unit);

                // Calculate expiration date based on current time
                $current_time = current_time('timestamp');
                $expiration_timestamp = $current_time;

                switch ($expiration_unit) {
                    case 'minutes':
                        $expiration_timestamp = $current_time + ($expiration_duration * 60);
                        break;
                    case 'hours':
                        $expiration_timestamp = $current_time + ($expiration_duration * 60 * 60);
                        break;
                    case 'days':
                        $expiration_timestamp = $current_time + ($expiration_duration * 24 * 60 * 60);
                        break;
                    case 'months':
                        // Use strtotime for accurate month calculation (handles different month lengths)
                        $expiration_timestamp = strtotime('+' . $expiration_duration . ' months', $current_time);
                        break;
                }

                // Save expiration date as timestamp and formatted date
                update_post_meta($post_id, 'expiration_date', $expiration_timestamp);
                update_post_meta($post_id, 'expiration_date_formatted', date('Y-m-d H:i:s', $expiration_timestamp));
            }

            // Clear filter caches when location or company is updated
            if (isset($_POST['location']) || isset($_POST['company_name'])) {
                // No caching to clear
            }

            // No stats cache to clear

            // Handle case when expiration fields are missing (only if not already handled above)
            if ($expiration_missing && (!defined('DOING_AUTOSAVE') || !DOING_AUTOSAVE)) {
                delete_post_meta($post_id, 'expiration_duration');
                delete_post_meta($post_id, 'expiration_unit');
                delete_post_meta($post_id, 'expiration_date');
                delete_post_meta($post_id, 'expiration_date_formatted');
            }
        }

        // Save company image (separate nonce check)
        if (isset($_POST['jpm_company_image_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jpm_company_image_nonce'])), 'jpm_company_image')) {
            if (isset($_POST['company_image']) && !empty($_POST['company_image'])) {
                update_post_meta($post_id, 'company_image', absint(wp_unslash($_POST['company_image'])));
            } else {
                delete_post_meta($post_id, 'company_image');
            }
        }
    }

    /**
     * Display admin notice for expiration duration validation error
     */
    public function display_expiration_duration_error()
    {
        // Only show on job posting edit screens
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'job_posting') {
            return;
        }
        // No transient-based error display
    }

    /**
     * Company Image meta box
     * @param WP_Post $post The post object
     */
    public function company_image_meta_box($post)
    {
        wp_nonce_field('jpm_company_image', 'jpm_company_image_nonce');

        $company_image_id = get_post_meta($post->ID, 'company_image', true);
        $company_image_url = '';

        if ($company_image_id) {
            $company_image_url = wp_get_attachment_image_url($company_image_id, 'medium');
        }
        ?>
        <div class="jpm-company-image-wrapper">
            <input type="hidden" id="company_image" name="company_image" value="<?php echo esc_attr($company_image_id); ?>" />
            <div id="company_image_preview" style="margin-bottom: 10px;">
                <?php if ($company_image_url): ?>
                    <img src="<?php echo esc_url($company_image_url); ?>" style="max-width: 100%; height: auto; display: block;" />
                <?php endif; ?>
            </div>
            <p>
                <button type="button" class="button" id="upload_company_image_btn">
                    <?php echo $company_image_id ? esc_html__('Change Image', 'job-posting-manager') : esc_html__('Upload Image', 'job-posting-manager'); ?>
                </button>
                <?php if ($company_image_id): ?>
                    <button type="button" class="button" id="remove_company_image_btn" style="margin-left: 5px;">
                        <?php esc_html_e('Remove Image', 'job-posting-manager'); ?>
                    </button>
                <?php endif; ?>
            </p>
            <p class="description">
                <?php esc_html_e('Optional: Upload a company logo or image. This will be displayed on the job posting page.', 'job-posting-manager'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Job Applications meta box
     * @param WP_Post $post The post object
     */
    public function job_applications_meta_box($post)
    {
        // Get all applications for this job
        $applications = JPM_DB::get_applications(['job_id' => $post->ID]);

        if (empty($applications)) {
            echo '<p>' . esc_html__('No applications have been submitted for this job yet.', 'job-posting-manager') . '</p>';
            return;
        }

        ?>
        <div class="jpm-applications-list">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;"><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('Application Date', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                        <th style="width: 15%;"><?php esc_html_e('User', 'job-posting-manager'); ?></th>
                        <th style="width: 45%;"><?php esc_html_e('Application Data', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $application):
                        $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
                        $form_data = json_decode($application->notes, true);
                        $application_number = isset($form_data['application_number']) ? $form_data['application_number'] : '';
                        $date_of_registration = isset($form_data['date_of_registration']) ? $form_data['date_of_registration'] : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html($application->id); ?></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?>
                            </td>
                            <td>
                                <?php
                                $status_info = self::get_status_by_slug($application->status);
                                if ($status_info):
                                    $bg_color = $status_info['color'];
                                    $text_color = $status_info['text_color'];
                                    $status_name = $status_info['name'];
                                else:
                                    $bg_color = '#ffc107';
                                    $text_color = '#000000';
                                    $status_name = ucfirst($application->status);
                                endif;
                                ?>
                                <span class="jpm-status-badge jpm-status-<?php echo esc_attr($application->status); ?>"
                                    style="background-color: <?php echo esc_attr($bg_color); ?>; color: <?php echo esc_attr($text_color); ?>;">
                                    <?php echo esc_html($status_name); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user): ?>
                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->ID)); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a>
                                    <br><small><?php echo esc_html($user->user_email); ?></small>
                                <?php else: ?>
                                    <em><?php esc_html_e('Guest', 'job-posting-manager'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($application_number): ?>
                                    <strong><?php esc_html_e('Application #:', 'job-posting-manager'); ?></strong>
                                    <?php echo esc_html($application_number); ?><br>
                                <?php endif; ?>
                                <?php if ($date_of_registration): ?>
                                    <strong><?php esc_html_e('Date:', 'job-posting-manager'); ?></strong>
                                    <?php echo esc_html($date_of_registration); ?><br>
                                <?php endif; ?>
                                <a href="#" class="jpm-view-application-details"
                                    data-application-id="<?php echo esc_attr($application->id); ?>">
                                    <?php esc_html_e('View Full Details', 'job-posting-manager'); ?>
                                </a>
                            </td>
                            <td>
                                <select class="jpm-application-status"
                                    data-application-id="<?php echo esc_attr($application->id); ?>">
                                    <?php
                                    $status_options = self::get_status_options();
                                    foreach ($status_options as $slug => $name):
                                        ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($application->status, $slug); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="jpm-application-details-modal" style="display: none;">
            <div class="jpm-modal-content"><span class="jpm-modal-close">&times;</span>
                <div id="jpm-application-details-content"></div>
            </div>
        </div>

        <style>
            .jpm-status-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .jpm-status-pending {
                background: #ffc107;
                color: #000;
            }

            .jpm-status-reviewed {
                background: #17a2b8;
                color: #fff;
            }

            .jpm-status-accepted {
                background: #28a745;
                color: #fff;
            }

            .jpm-status-rejected {
                background: #dc3545;
                color: #fff;
            }

            .jpm-view-application-details {
                cursor: pointer;
                color: #2271b1;
            }

            .jpm-view-application-details:hover {
                text-decoration: underline;
            }
        </style>

        <script>     jQuery(document).ready(function ($) {         // Update status on change         $('.jpm-application-status').on('change', function () {             var $select = $(this);             var applicationId = $select.data('application-id');             var newStatus = $select.val();                                $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'jpm_update_application_status', application_id: applicationId, status: newStatus, nonce: '<?php echo esc_js(wp_create_nonce('jpm_update_status')); ?>' }, success: function (response) { if (response.success) { location.reload(); } else { alert('Error updating status'); } } });
            });
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         });
        </script>
        <?php
    }

    /**
     * Enqueue media uploader scripts
     */
    public function enqueue_media_uploader($hook)
    {
        // Load on job posting edit screens
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            global $post_type;
            if ($post_type === 'job_posting') {
                wp_enqueue_media();
                // Add inline script for media uploader
                wp_add_inline_script('jquery', '
                    jQuery(document).ready(function($) {
                        var companyImageFrame;
                        var $companyImageInput = $("#company_image");
                        var $companyImagePreview = $("#company_image_preview");
                        var $uploadBtn = $("#upload_company_image_btn");
                        var $removeBtn = $("#remove_company_image_btn");

                        $uploadBtn.on("click", function(e) {
                            e.preventDefault();
                            if (companyImageFrame) {
                                companyImageFrame.open();
                                return;
                            }
                            companyImageFrame = wp.media({
                                title: "' . esc_js(__('Select Company Image', 'job-posting-manager')) . '",
                                button: {
                                    text: "' . esc_js(__('Use this image', 'job-posting-manager')) . '"
                                },
                                multiple: false
                            });
                            companyImageFrame.on("select", function() {
                                var attachment = companyImageFrame.state().get("selection").first().toJSON();
                                $companyImageInput.val(attachment.id);
                                $companyImagePreview.html("<img src=\"" + attachment.url + "\" style=\"max-width: 100%; height: auto; display: block;\" />");
                                $uploadBtn.text("' . esc_js(__('Change Image', 'job-posting-manager')) . '");
                                $removeBtn.show();
                            });
                            companyImageFrame.open();
                        });

                        $removeBtn.on("click", function(e) {
                            e.preventDefault();
                            $companyImageInput.val("");
                            $companyImagePreview.html("");
                            $uploadBtn.text("' . esc_js(__('Upload Image', 'job-posting-manager')) . '");
                            $removeBtn.hide();
                        });
                    });
                ');
            }
        }

        // Load on dashboard page for chart functionality
        if ($hook === 'toplevel_page_jpm-dashboard') {
            wp_enqueue_script('jquery');
            wp_localize_script('jquery', 'jpm_chart_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('jpm_chart_nonce')
            ]);
            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($) {
                    // Show/hide custom date range inputs
                    $("#jpm-chart-period").on("change", function() {
                        if ($(this).val() === "custom") {
                            $("#jpm-chart-custom-dates").show();
                        } else {
                            $("#jpm-chart-custom-dates").hide();
                        }
                    });

                    // Handle chart filter apply
                    $("#jpm-chart-apply").on("click", function() {
                        var period = $("#jpm-chart-period").val();
                        var startDate = $("#jpm-chart-start-date").val();
                        var endDate = $("#jpm-chart-end-date").val();
                        
                        // Validate custom date range
                        if (period === "custom") {
                            if (!startDate || !endDate) {
                                alert("' . esc_js(__('Please select both start and end dates for custom range.', 'job-posting-manager')) . '");
                                return;
                            }
                            if (startDate > endDate) {
                                alert("' . esc_js(__('Start date must be before end date.', 'job-posting-manager')) . '");
                                return;
                            }
                        }

                        // Show loading
                        $("#jpm-chart-loading").show();
                        $("#jpm-chart-container").hide();

                        // AJAX request
                        $.ajax({
                            url: jpm_chart_ajax.ajax_url,
                            type: "POST",
                            data: {
                                action: "jpm_get_chart_data",
                                period: period,
                                start_date: startDate,
                                end_date: endDate,
                                nonce: jpm_chart_ajax.nonce
                            },
                            success: function(response) {
                                $("#jpm-chart-loading").hide();
                                if (response.success && response.data.html) {
                                    $("#jpm-chart-container").html(response.data.html).show();
                                } else {
                                    alert(response.data?.message || "' . esc_js(__('Error loading chart data.', 'job-posting-manager')) . '");
                                    $("#jpm-chart-container").show();
                                }
                            },
                            error: function() {
                                $("#jpm-chart-loading").hide();
                                alert("' . esc_js(__('An error occurred while loading chart data.', 'job-posting-manager')) . '");
                                $("#jpm-chart-container").show();
                            }
                        });
                    });
                });
            ');
        }
    }

    /**
     * Get chart data based on period
     * @param string $period Period type (7days, 30days, 90days, 365days, custom)
     * @param string $start_date Start date for custom range
     * @param string $end_date End date for custom range
     * @return array Chart data array
     */
    private function get_chart_data($period = '7days', $start_date = '', $end_date = '')
    {
        global $wpdb;
        $table = $this->get_validated_applications_table();
        $data = [];

        // Determine date range
        $end = date('Y-m-d');
        switch ($period) {
            case '7days':
                $start = date('Y-m-d', strtotime('-7 days'));
                $interval = 'day';
                $format = 'M j';
                break;
            case '30days':
                $start = date('Y-m-d', strtotime('-30 days'));
                $interval = 'day';
                $format = 'M j';
                break;
            case '90days':
                $start = date('Y-m-d', strtotime('-90 days'));
                $interval = 'week';
                $format = 'M j';
                break;
            case '365days':
                $start = date('Y-m-d', strtotime('-365 days'));
                $interval = 'month';
                $format = 'M Y';
                break;
            case 'custom':
                if (!empty($start_date) && !empty($end_date)) {
                    $start = $start_date;
                    $end = $end_date;
                    // Determine interval based on range
                    $days = (strtotime($end) - strtotime($start)) / (60 * 60 * 24);
                    if ($days <= 30) {
                        $interval = 'day';
                        $format = 'M j';
                    } elseif ($days <= 90) {
                        $interval = 'week';
                        $format = 'M j';
                    } else {
                        $interval = 'month';
                        $format = 'M Y';
                    }
                } else {
                    // Default to 7 days if custom dates not provided
                    $start = date('Y-m-d', strtotime('-7 days'));
                    $interval = 'day';
                    $format = 'M j';
                }
                break;
            default:
                $start = date('Y-m-d', strtotime('-7 days'));
                $interval = 'day';
                $format = 'M j';
        }

        // Optimized: Use single query with GROUP BY instead of loop queries
        $start_datetime = $start . ' 00:00:00';
        $end_datetime = $end . ' 23:59:59';

        if ($interval === 'day') {
            // Single query for all days
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE(application_date) as date, COUNT(*) as count 
                    FROM {$table} 
                    WHERE application_date >= %s AND application_date <= %s
                    GROUP BY DATE(application_date)
                    ORDER BY date ASC",
                    $start_datetime,
                    $end_datetime
                ),
                ARRAY_A
            );

            // Create a map for quick lookup
            $counts_map = [];
            foreach ($results as $row) {
                $counts_map[$row['date']] = intval($row['count']);
            }

            // Generate all dates in range and fill in counts
            $current = strtotime($start);
            $end_timestamp = strtotime($end);
            while ($current <= $end_timestamp) {
                $date_str = date('Y-m-d', $current);
                $count = isset($counts_map[$date_str]) ? $counts_map[$date_str] : 0;
                $data[] = [
                    'date' => date($format, $current),
                    'count' => $count
                ];
                $current = strtotime('+1 day', $current);
            }
        } elseif ($interval === 'week') {
            // Single query for all weeks
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT YEARWEEK(application_date, 1) as week, COUNT(*) as count 
                    FROM {$table} 
                    WHERE application_date >= %s AND application_date <= %s
                    GROUP BY YEARWEEK(application_date, 1)
                    ORDER BY week ASC",
                    $start_datetime,
                    $end_datetime
                ),
                ARRAY_A
            );

            // Create a map for quick lookup
            $counts_map = [];
            foreach ($results as $row) {
                $counts_map[$row['week']] = intval($row['count']);
            }

            // Generate all weeks in range
            $current = strtotime($start);
            $end_timestamp = strtotime($end);
            while ($current <= $end_timestamp) {
                $week_start = date('Y-m-d', strtotime('monday this week', $current));
                $week_end = date('Y-m-d', strtotime('sunday this week', $current));
                $week_key = date('oW', $current); // ISO week number
                $count = isset($counts_map[$week_key]) ? $counts_map[$week_key] : 0;
                $data[] = [
                    'date' => $week_start . ' - ' . $week_end,
                    'count' => $count
                ];
                $current = strtotime('+1 week', $current);
            }
        } else { // month
            // Single query for all months
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE_FORMAT(application_date, '%%Y-%%m') as month, COUNT(*) as count 
                    FROM {$table} 
                    WHERE application_date >= %s AND application_date <= %s
                    GROUP BY DATE_FORMAT(application_date, '%%Y-%%m')
                    ORDER BY month ASC",
                    $start_datetime,
                    $end_datetime
                ),
                ARRAY_A
            );

            // Create a map for quick lookup
            $counts_map = [];
            foreach ($results as $row) {
                $counts_map[$row['month']] = intval($row['count']);
            }

            // Generate all months in range
            $current = strtotime($start);
            $end_timestamp = strtotime($end);
            while ($current <= $end_timestamp) {
                $month_start = date('Y-m-01', $current);
                $month_key = date('Y-m', $current);
                $count = isset($counts_map[$month_key]) ? $counts_map[$month_key] : 0;
                $data[] = [
                    'date' => date($format, strtotime($month_start)),
                    'count' => $count
                ];
                $current = strtotime('+1 month', $current);
            }
        }

        return $data;
    }

    /**
     * AJAX handler for getting chart data
     */
    public function get_chart_data_ajax()
    {
        // Prevent any PHP notices/warnings from corrupting the JSON response.
        if (!ob_get_level()) {
            ob_start();
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'jpm_chart_nonce')) {
            ob_clean();
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            ob_clean();
            wp_send_json_error(['message' => __('Unauthorized access.', 'job-posting-manager')]);
            return;
        }

        $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : '7days';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        $data = $this->get_chart_data($period, $start_date, $end_date);

        // Generate chart HTML
        ob_start();
        if (!empty($data)) {
            $max_count = max(array_column($data, 'count'));
            $max_count = $max_count > 0 ? $max_count : 1;
            ?>
            <div
                style="display: flex; align-items: flex-end; justify-content: space-around; height: 200px; border-bottom: 2px solid #ddd; padding-bottom: 10px; position: relative;">
                <?php foreach ($data as $day):
                    $height_px = ($day['count'] / $max_count) * 180; // 180px max height
                    ?>
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; margin: 0 5px; height: 100%;">
                        <div class="jpm-chart-bar"
                            style="width: 100%; max-width: 40px; background: #0073aa; border-radius: 4px 4px 0 0; margin-bottom: 10px; transition: all 0.3s ease; height: <?php echo esc_attr($height_px); ?>px; min-height: <?php echo $day['count'] > 0 ? '5px' : '0'; ?>;"
                            title="<?php echo esc_attr($day['date'] . ': ' . $day['count'] . ' applications'); ?>">
                        </div>
                        <div
                            style="font-size: 11px; color: #666; text-align: center; transform: rotate(-45deg); transform-origin: center; white-space: nowrap; margin-top: 5px;">
                            <?php echo esc_html($day['date']); ?>
                        </div>
                        <div style="font-size: 12px; font-weight: bold; color: #333; margin-top: 5px;">
                            <?php echo esc_html($day['count']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
        } else {
            echo '<p style="text-align: center; padding: 40px; color: #666;">' . esc_html__('No data available for the selected period.', 'job-posting-manager') . '</p>';
        }
        $html = ob_get_clean();

        ob_clean();
        wp_send_json_success(['html' => $html]);
    }

    /**
     * Calculate and format time remaining until job expiration
     * @param int $job_id Job post ID
     * @return string|false Formatted time remaining or false if no expiration set
     */
    private function get_time_remaining($job_id)
    {
        $expiration_date = get_post_meta($job_id, 'expiration_date', true);

        if (empty($expiration_date)) {
            return false;
        }

        $current_time = current_time('timestamp');
        $expiration_timestamp = intval($expiration_date);

        // If already expired, return false
        if ($expiration_timestamp <= $current_time) {
            return false;
        }

        $seconds_remaining = $expiration_timestamp - $current_time;
        $expiration_unit = get_post_meta($job_id, 'expiration_unit', true);

        // If the original unit was minutes, always show minutes
        if ($expiration_unit === 'minutes') {
            $minutes_remaining = floor($seconds_remaining / 60);
            if ($minutes_remaining <= 0) {
                return false; // Already expired
            }
            return sprintf(
                _n('%d minute left', '%d minutes left', $minutes_remaining, 'job-posting-manager'),
                $minutes_remaining
            );
        }

        // Otherwise, show days (convert hours, days, months all to days)
        $days_remaining = floor($seconds_remaining / 86400);
        if ($days_remaining <= 0) {
            // Less than a day remaining, show hours or minutes for better accuracy
            $hours_remaining = floor($seconds_remaining / 3600);
            if ($hours_remaining > 0) {
                return sprintf(
                    _n('%d hour left', '%d hours left', $hours_remaining, 'job-posting-manager'),
                    $hours_remaining
                );
            } else {
                $minutes_remaining = floor($seconds_remaining / 60);
                if ($minutes_remaining <= 0) {
                    return false; // Already expired
                }
                return sprintf(
                    _n('%d minute left', '%d minutes left', $minutes_remaining, 'job-posting-manager'),
                    $minutes_remaining
                );
            }
        }

        return sprintf(
            _n('%d day left', '%d days left', $days_remaining, 'job-posting-manager'),
            $days_remaining
        );
    }

    /**
     * Calculate and format time remaining until job expiration for admin listing.
     * Unlike get_time_remaining(), this returns wording without the trailing "left"
     * so UI can read nicely as: "Expires in X days".
     *
     * @param int $job_id Job post ID
     * @return string|false e.g. "151 days", "3 hours"
     */
    private function get_expires_in($job_id)
    {
        $expiration_date = get_post_meta($job_id, 'expiration_date', true);
        if (empty($expiration_date)) {
            return false;
        }

        $current_time = current_time('timestamp');
        $expiration_timestamp = intval($expiration_date);

        if ($expiration_timestamp <= $current_time) {
            return false;
        }

        $seconds_remaining = $expiration_timestamp - $current_time;
        $expiration_unit = get_post_meta($job_id, 'expiration_unit', true);

        // If the original unit was minutes, always show minutes.
        if ($expiration_unit === 'minutes') {
            $minutes_remaining = floor($seconds_remaining / 60);
            if ($minutes_remaining <= 0) {
                return false;
            }
            return sprintf(
                _n('%d minute', '%d minutes', $minutes_remaining, 'job-posting-manager'),
                $minutes_remaining
            );
        }

        // Otherwise, show days (convert hours/days/months all to days)
        $days_remaining = floor($seconds_remaining / 86400);
        if ($days_remaining > 0) {
            return sprintf(
                _n('%d day', '%d days', $days_remaining, 'job-posting-manager'),
                $days_remaining
            );
        }

        // Less than a day remaining, show hours or minutes for better accuracy.
        $hours_remaining = floor($seconds_remaining / 3600);
        if ($hours_remaining > 0) {
            return sprintf(
                _n('%d hour', '%d hours', $hours_remaining, 'job-posting-manager'),
                $hours_remaining
            );
        }

        $minutes_remaining = floor($seconds_remaining / 60);
        if ($minutes_remaining > 0) {
            return sprintf(
                _n('%d minute', '%d minutes', $minutes_remaining, 'job-posting-manager'),
                $minutes_remaining
            );
        }

        return false;
    }

    /**
     * Display job details on single job posting page
     * @param string $content The post content
     * @return string Modified content with job details
     */
    public function display_job_details($content)
    {
        // Only display on single job posting pages
        if (!is_singular('job_posting')) {
            return $content;
        }

        global $post;
        $company_image_id = get_post_meta($post->ID, 'company_image', true);
        $location = get_post_meta($post->ID, 'location', true);
        $duration = get_post_meta($post->ID, 'duration', true);
        $time_remaining = $this->get_time_remaining($post->ID);

        // Always display job details section (at minimum, it will show posted date)

        ob_start(); ?>
        <div class="jpm-job-details">
            <h3><?php esc_html_e('Job Details', 'job-posting-manager'); ?></h3>
            <ul class="jpm-job-details-list">
                <?php if (!empty($location)): ?>
                    <li class="jpm-job-detail-item jpm-job-location">
                        <strong><?php esc_html_e('Location:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($location); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($duration)): ?>
                    <li class="jpm-job-detail-item jpm-job-duration">
                        <strong><?php esc_html_e('Duration:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($duration); ?></span>
                    </li>
                <?php endif; ?>
                <li class="jpm-job-detail-item jpm-job-posted-date">
                    <strong><?php esc_html_e('Posted Date:', 'job-posting-manager'); ?></strong>
                    <span><?php echo esc_html(get_the_date('', $post->ID)); ?></span>
                </li>
                <?php if ($time_remaining): ?>
                    <li class="jpm-job-detail-item jpm-job-expiration">
                        <strong><?php esc_html_e('Expiration:', 'job-posting-manager'); ?></strong>
                        <span class="jpm-job-expiration-text"> <i class="dashicons dashicons-clock"></i>
                            <?php echo esc_html($time_remaining); ?></span>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
        $job_details = ob_get_clean();

        // Prepend job details to content
        return $job_details . $content;
    }

    /**
     * Display company image with post title and status badge
     * @param string $title The post title
     * @param int $post_id The post ID
     * @return string Modified title with company image and status badge
     */
    public function display_company_image_with_title($title, $post_id = null)
    {
        // Only on single job posting pages
        if (!is_singular('job_posting')) {
            return $title;
        }

        // Get post ID if not provided
        if (!$post_id) {
            global $post;
            if (!$post) {
                return $title;
            }
            $post_id = $post->ID;
        }

        // Check if this is a job posting
        if (get_post_type($post_id) !== 'job_posting') {
            return $title;
        }

        // Get post status
        $post_status = get_post_status($post_id);
        $status_badge = '';

        if ($post_status === 'publish') {
            $status_badge = '<span class="jpm-status-badge jpm-status-active">' . __('Active', 'job-posting-manager') . '</span>';
        } elseif ($post_status === 'draft') {
            $status_badge = '<span class="jpm-status-badge jpm-status-draft">' . __('Draft', 'job-posting-manager') . '</span>';
        }

        // Get company image
        $company_image_id = get_post_meta($post_id, 'company_image', true);
        $image_html = '';

        if (!empty($company_image_id)) {
            $image_html = wp_get_attachment_image($company_image_id, 'thumbnail', false, ['class' => 'jpm-company-image-title']);
        }

        // Build the title structure
        $title_html = '<span class="jpm-title-text">' . $title . '</span>';

        if ($status_badge) {
            $title_html .= $status_badge;
        }

        // If there's an image, wrap everything in a container
        if (!empty($image_html)) {
            return '<div class="jpm-title-with-image">' . $image_html . '<div class="jpm-title-wrapper">' . $title_html . '</div></div>';
        } else {
            // No image, just wrap title and badge
            return '<div class="jpm-title-wrapper">' . $title_html . '</div>';
        }
    }

    /**
     * Restrict draft job posts to admins only
     * Show "job not found" message for non-admins
     */
    public function restrict_draft_job_access()
    {
        // Only on single job posting pages
        if (!is_singular('job_posting')) {
            return;
        }

        global $post;

        // Check if post exists and is a draft
        if (!$post || get_post_status($post->ID) !== 'draft') {
            return;
        }

        // Check if user is admin
        if (!current_user_can('manage_options')) {
            // Set 404 status
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            // Replace content with "job not found" message
            add_filter('the_content', [$this, 'show_job_not_found_message'], 999);
            add_filter('the_title', [$this, 'show_job_not_found_title'], 999);
        }
    }

    /**
     * Show "job not found" message
     * @param string $content The post content
     * @return string "Job not found" message
     */
    public function show_job_not_found_message($content)
    {
        // Only on single job posting pages
        if (!is_singular('job_posting')) {
            return $content;
        }

        global $post;
        if (!$post || get_post_status($post->ID) !== 'draft') {
            return $content;
        }

        // Check if user is admin
        if (current_user_can('manage_options')) {
            return $content;
        }

        // Return "job not found" message
        return '<div class="jpm-job-not-found"><p>' . __('This job is not found.', 'job-posting-manager') . '</p></div>';
    }

    /**
     * Show "job not found" title
     * @param string $title The post title
     * @return string "Job not found" title
     */
    public function show_job_not_found_title($title)
    {
        // Only on single job posting pages
        if (!is_singular('job_posting')) {
            return $title;
        }

        global $post;
        if (!$post || get_post_status($post->ID) !== 'draft') {
            return $title;
        }

        // Check if user is admin
        if (current_user_can('manage_options')) {
            return $title;
        }

        // Return "job not found" title
        return __('Job Not Found', 'job-posting-manager');
    }

    public function bulk_update()
    {
        check_ajax_referer('jpm_nonce');
        if (!current_user_can('manage_options'))
            wp_die();
        $applications = isset($_POST['applications']) && is_array($_POST['applications']) ? wp_unslash($_POST['applications']) : [];
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        foreach ($applications as $id) {
            JPM_DB::update_status(absint($id), $status);
            // Send email
            JPM_Emails::send_status_update(absint($id));
        }
        wp_die(__('Updated', 'job-posting-manager'));
    }

    /**
     * Update application status via AJAX
     */
    public function update_application_status()
    {
        // Verify nonce
        if (!JPM_Security::verify_nonce(isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '', 'jpm_update_status', 'ajax')) {
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
            return;
        }

        // Check capability
        if (!JPM_Security::check_capability('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
            return;
        }

        // Validate inputs
        $application_id = JPM_Security::validate_int(isset($_POST['application_id']) ? wp_unslash($_POST['application_id']) : 0, 1);
        $status = JPM_Security::validate_text(isset($_POST['status']) ? wp_unslash($_POST['status']) : '', 50);

        if (!$application_id || !$status) {
            wp_send_json_error(['message' => __('Invalid data.', 'job-posting-manager')]);
            return;
        }

        // Verify application exists
        $application = JPM_Database::get_application($application_id);
        if (!$application) {
            wp_send_json_error(['message' => __('Application not found.', 'job-posting-manager')]);
            return;
        }

        $result = JPM_DB::update_status($application_id, $status);

        if ($result !== false) {
            // Send email notification if email class exists
            if (class_exists('JPM_Emails')) {
                try {
                    JPM_Emails::send_status_update($application_id);
                } catch (Exception $e) {
                    // Log error but don't fail the request
                    do_action('jpm_log_error', 'JPM Email Error: ' . $e->getMessage());
                }
            }
            wp_send_json_success(['message' => __('Status updated successfully', 'job-posting-manager')]);
        } else {
            wp_send_json_error(['message' => __('Failed to update status', 'job-posting-manager')]);
        }
    }

    /**
     * Find the slug configured for the "For Medical" status (by slug or display name).
     */
    private function get_medical_status_slug()
    {
        $statuses = self::get_all_statuses_info();
        foreach ($statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'for-medical' || $slug === 'for_medical' || $name === 'for medical') {
                return $status['slug'];
            }
        }
        return '';
    }

    /**
     * Default medical address used when none is provided.
     */
    private function get_default_medical_address()
    {
        return '2250 Singalong St., Malate Manila';
    }

    /**
     * Get stored medical details for an application.
     *
     * @param int $application_id
     * @return array
     */
    private function get_application_medical_details($application_id)
    {
        $details = get_option('jpm_application_medical_details_' . absint($application_id), []);
        if (!is_array($details)) {
            return [
                'requirements' => '',
                'address' => '',
                'date' => '',
                'time' => '',
                'updated_at' => '',
            ];
        }

        return [
            'requirements' => isset($details['requirements']) ? wp_kses_post($details['requirements']) : '',
            'address' => isset($details['address']) ? sanitize_text_field($details['address']) : '',
            'date' => isset($details['date']) ? sanitize_text_field($details['date']) : '',
            'time' => isset($details['time']) ? sanitize_text_field($details['time']) : '',
            'updated_at' => isset($details['updated_at']) ? sanitize_text_field($details['updated_at']) : '',
        ];
    }

    /**
     * Format date to "January 13, 2003" format.
     *
     * @param string $date_string Date string in YYYY-MM-DD format
     * @return string Formatted date string
     */
    private function format_medical_date($date_string)
    {
        if (empty($date_string)) {
            return '';
        }

        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return $date_string; // Return original if invalid
        }

        $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December'
        ];

        $month = $months[(int) date('n', $timestamp)];
        $day = date('j', $timestamp);
        $year = date('Y', $timestamp);

        return $month . ' ' . $day . ', ' . $year;
    }

    /**
     * AJAX: Fetch medical details for an application.
     */
    public function get_medical_details_ajax()
    {
        check_ajax_referer('jpm_medical_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = absint($_REQUEST['application_id'] ?? 0);
        if ($application_id <= 0) {
            wp_send_json_error(['message' => __('Invalid application ID', 'job-posting-manager')]);
        }

        $details = $this->get_application_medical_details($application_id);
        if (empty($details['address'])) {
            $details['address'] = $this->get_default_medical_address();
        }

        wp_send_json_success([
            'details' => $details,
            'status_slug' => $this->get_medical_status_slug(),
        ]);
    }

    /**
     * AJAX: Save medical details and update status.
     */
    public function save_medical_details_ajax()
    {
        // Verify nonce
        if (!JPM_Security::verify_nonce(isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '', 'jpm_medical_details', 'ajax')) {
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
            return;
        }

        // Check capability
        if (!JPM_Security::check_capability('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
            return;
        }

        // Validate inputs
        $application_id = JPM_Security::validate_int(isset($_POST['application_id']) ? wp_unslash($_POST['application_id']) : 0, 1);
        $requirements = isset($_POST['requirements']) ? wp_kses_post(wp_unslash($_POST['requirements'])) : '';
        $address = JPM_Security::validate_text(isset($_POST['address']) ? wp_unslash($_POST['address']) : '', 255);
        $date = JPM_Security::validate_text(isset($_POST['date']) ? wp_unslash($_POST['date']) : '', 50);
        $time = JPM_Security::validate_text(isset($_POST['time']) ? wp_unslash($_POST['time']) : '', 50);

        if (!$application_id) {
            wp_send_json_error(['message' => __('Invalid application ID.', 'job-posting-manager')]);
            return;
        }

        // Verify application exists
        $application = JPM_Database::get_application($application_id);
        if (!$application) {
            wp_send_json_error(['message' => __('Application not found.', 'job-posting-manager')]);
            return;
        }

        if (empty($requirements)) {
            wp_send_json_error(['message' => __('Please enter the requirements.', 'job-posting-manager')]);
            return;
        }

        // Validate requirements length
        if (strlen($requirements) > 10000) {
            wp_send_json_error(['message' => __('Requirements text is too long.', 'job-posting-manager')]);
            return;
        }

        $medical_status_slug = $this->get_medical_status_slug();
        if (empty($medical_status_slug)) {
            wp_send_json_error(['message' => __('The "For Medical" status is not configured.', 'job-posting-manager')]);
        }

        // Persist details
        $details = [
            'requirements' => $requirements,
            'address' => !empty($address) ? $address : $this->get_default_medical_address(),
            'date' => $date,
            'time' => $time,
            'updated_at' => current_time('mysql'),
        ];

        update_option('jpm_application_medical_details_' . $application_id, $details, false);

        // Update status
        JPM_DB::update_status($application_id, $medical_status_slug);

        // Send notification (reuse existing flow)
        if (class_exists('JPM_Emails')) {
            try {
                JPM_Emails::send_status_update($application_id);
            } catch (Exception $e) {
                do_action('jpm_log_error', 'JPM Medical Email Error: ' . $e->getMessage());
            }
        }

        $status_info = self::get_status_by_slug($medical_status_slug);

        wp_send_json_success([
            'message' => __('Medical details saved and status updated.', 'job-posting-manager'),
            'status_slug' => $medical_status_slug,
            'status_label' => $status_info ? $status_info['name'] : ucfirst($medical_status_slug),
            'details' => $details,
        ]);
    }

    /**
     * Get application rejection details
     */
    public function get_application_rejection_details($application_id)
    {
        $stored = get_option('jpm_application_rejection_details_' . $application_id, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'problem_area' => isset($stored['problem_area']) ? sanitize_text_field($stored['problem_area']) : '',
            'notes' => isset($stored['notes']) ? wp_kses_post($stored['notes']) : '',
            'updated_at' => isset($stored['updated_at']) ? sanitize_text_field($stored['updated_at']) : '',
        ];
    }

    /**
     * AJAX: Get rejection details
     */
    public function get_rejection_details_ajax()
    {
        check_ajax_referer('jpm_rejection_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = absint($_REQUEST['application_id'] ?? 0);
        if ($application_id <= 0) {
            wp_send_json_error(['message' => __('Invalid application ID', 'job-posting-manager')]);
        }

        $details = $this->get_application_rejection_details($application_id);

        // Get rejected status slug
        $rejected_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'rejected' || $name === 'rejected') {
                $rejected_status_slug = $status['slug'];
                break;
            }
        }

        wp_send_json_success([
            'details' => $details,
            'status_slug' => $rejected_status_slug,
        ]);
    }

    /**
     * AJAX: Save rejection details and update status.
     */
    public function save_rejection_details_ajax()
    {
        check_ajax_referer('jpm_rejection_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;
        $problem_area = isset($_POST['problem_area']) ? sanitize_text_field(wp_unslash($_POST['problem_area'])) : '';
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';

        if ($application_id <= 0) {
            wp_send_json_error(['message' => __('Invalid application ID', 'job-posting-manager')]);
        }

        if (empty($problem_area)) {
            wp_send_json_error(['message' => __('Please select the problem area.', 'job-posting-manager')]);
        }

        if (empty($notes)) {
            wp_send_json_error(['message' => __('Please enter the rejection notes.', 'job-posting-manager')]);
        }

        // Get rejected status slug
        $rejected_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'rejected' || $name === 'rejected') {
                $rejected_status_slug = $status['slug'];
                break;
            }
        }

        if (empty($rejected_status_slug)) {
            wp_send_json_error(['message' => __('The "Rejected" status is not configured.', 'job-posting-manager')]);
        }

        // Validate problem area
        $allowed_areas = ['personal_information', 'education', 'employment'];
        if (!in_array($problem_area, $allowed_areas)) {
            wp_send_json_error(['message' => __('Invalid problem area selected.', 'job-posting-manager')]);
        }

        // Persist details
        $details = [
            'problem_area' => $problem_area,
            'notes' => $notes,
            'updated_at' => current_time('mysql'),
        ];

        update_option('jpm_application_rejection_details_' . $application_id, $details, false);

        // Update status
        JPM_DB::update_status($application_id, $rejected_status_slug);

        // Send notification (reuse existing flow)
        if (class_exists('JPM_Emails')) {
            try {
                JPM_Emails::send_status_update($application_id);
            } catch (Exception $e) {
                do_action('jpm_log_error', 'JPM Rejection Email Error: ' . $e->getMessage());
            }
        }

        $status_info = self::get_status_by_slug($rejected_status_slug);

        wp_send_json_success([
            'message' => __('Rejection details saved and status updated.', 'job-posting-manager'),
            'status_slug' => $rejected_status_slug,
            'status_label' => $status_info ? $status_info['name'] : ucfirst($rejected_status_slug),
            'details' => $details,
        ]);
    }

    /**
     * Get application interview details
     */
    public function get_application_interview_details($application_id)
    {
        $stored = get_option('jpm_application_interview_details_' . $application_id, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'requirements' => isset($stored['requirements']) ? wp_kses_post($stored['requirements']) : '',
            'address' => isset($stored['address']) ? sanitize_text_field($stored['address']) : '',
            'date' => isset($stored['date']) ? sanitize_text_field($stored['date']) : '',
            'time' => isset($stored['time']) ? sanitize_text_field($stored['time']) : '',
            'updated_at' => isset($stored['updated_at']) ? sanitize_text_field($stored['updated_at']) : '',
        ];
    }

    /**
     * AJAX: Get interview details
     */
    public function get_interview_details_ajax()
    {
        check_ajax_referer('jpm_interview_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = absint($_REQUEST['application_id'] ?? 0);
        if ($application_id <= 0) {
            wp_send_json_error(['message' => __('Invalid application ID', 'job-posting-manager')]);
        }

        $details = $this->get_application_interview_details($application_id);

        // Get interview status slug
        $interview_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
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

        wp_send_json_success([
            'details' => $details,
            'status_slug' => $interview_status_slug,
        ]);
    }

    /**
     * AJAX: Save interview details and update status.
     */
    public function save_interview_details_ajax()
    {
        check_ajax_referer('jpm_interview_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = isset($_POST['application_id']) ? absint(wp_unslash($_POST['application_id'])) : 0;
        $requirements = isset($_POST['requirements']) ? wp_kses_post(wp_unslash($_POST['requirements'])) : '';
        $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $time = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';

        if ($application_id <= 0) {
            wp_send_json_error(['message' => __('Invalid application ID', 'job-posting-manager')]);
        }

        if (empty($requirements)) {
            wp_send_json_error(['message' => __('Please enter the requirements.', 'job-posting-manager')]);
        }

        if (empty($address)) {
            wp_send_json_error(['message' => __('Please enter the interview address.', 'job-posting-manager')]);
        }

        // Get interview status slug
        $interview_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
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

        if (empty($interview_status_slug)) {
            wp_send_json_error(['message' => __('The "For Interview" status is not configured.', 'job-posting-manager')]);
        }

        // Persist details
        $details = [
            'requirements' => $requirements,
            'address' => $address,
            'date' => $date,
            'time' => $time,
            'updated_at' => current_time('mysql'),
        ];

        update_option('jpm_application_interview_details_' . $application_id, $details, false);

        // Update status
        JPM_DB::update_status($application_id, $interview_status_slug);

        // Send notification (reuse existing flow)
        if (class_exists('JPM_Emails')) {
            try {
                JPM_Emails::send_status_update($application_id);
            } catch (Exception $e) {
                do_action('jpm_log_error', 'JPM Interview Email Error: ' . $e->getMessage());
            }
        }

        $status_info = self::get_status_by_slug($interview_status_slug);

        wp_send_json_success([
            'message' => __('Interview details saved and status updated.', 'job-posting-manager'),
            'status_slug' => $interview_status_slug,
            'status_label' => $status_info ? $status_info['name'] : ucfirst($interview_status_slug),
            'details' => $details,
        ]);
    }

    /**
     * Status Management Page
     */
    public function status_management_page()
    {
        // Get all statuses
        $statuses = $this->get_all_statuses();
        $editing_id = isset($_GET['edit']) ? absint(wp_unslash($_GET['edit'])) : 0;
        $editing_status = null;

        if ($editing_id > 0) {
            foreach ($statuses as $status) {
                if ($status['id'] == $editing_id) {
                    $editing_status = $status;
                    break;
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Status Management', 'job-posting-manager'); ?></h1>

            <?php if (isset($_GET['status_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Status saved successfully!', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['status_deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Status deleted successfully!', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>

            <div class="jpm-status-management">
                <div class="jpm-status-form-section"
                    style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #ccc; border-radius: 4px;">
                    <h2><?php echo $editing_status ? esc_html__('Edit Status', 'job-posting-manager') : esc_html__('Add New Status', 'job-posting-manager'); ?>
                    </h2>

                    <form method="post" action="">
                        <?php wp_nonce_field('jpm_status_management'); ?>
                        <input type="hidden" name="jpm_action" value="<?php echo $editing_status ? 'edit' : 'add'; ?>">
                        <?php if ($editing_status): ?>
                            <input type="hidden" name="status_id" value="<?php echo esc_attr($editing_status['id']); ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="status_name"><?php esc_html_e('Status Name', 'job-posting-manager'); ?> <span
                                            class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="status_name" name="status_name" class="regular-text"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['name']) : ''; ?>" required
                                        placeholder="<?php esc_attr_e('e.g., Pending, Reviewed, Accepted', 'job-posting-manager'); ?>">
                                    <p class="description">
                                        <?php esc_html_e('The display name of the status', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_slug"><?php esc_html_e('Status Slug', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="status_slug" name="status_slug" class="regular-text"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['slug']) : ''; ?>"
                                        placeholder="<?php esc_attr_e('Leave empty to auto-generate from the name', 'job-posting-manager'); ?>">
                                    <p class="description">
                                        <?php esc_html_e('Lowercase, hyphenated identifier. Left blank, it is generated from the status name.', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="status_color"><?php esc_html_e('Status Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="status_color" name="status_color"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['color']) : '#ffc107'; ?>">
                                    <p class="description">
                                        <?php esc_html_e('Color for the status badge', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="status_text_color"><?php esc_html_e('Text Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="status_text_color" name="status_text_color"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['text_color']) : '#000000'; ?>">
                                    <p class="description">
                                        <?php esc_html_e('Text color for the status badge', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label
                                        for="status_description"><?php esc_html_e('Description', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <textarea id="status_description" name="status_description" rows="3" class="large-text"
                                        placeholder="<?php esc_attr_e('Optional description for this status', 'job-posting-manager'); ?>"><?php echo $editing_status ? esc_textarea($editing_status['description']) : ''; ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_ordering"><?php esc_html_e('Ordering', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="status_ordering" name="status_ordering" class="small-text"
                                        value="<?php echo $editing_status ? (isset($editing_status['ordering']) ? esc_attr($editing_status['ordering']) : '0') : '0'; ?>"
                                        min="0" step="1">
                                    <p class="description">
                                        <?php esc_html_e('Lower numbers appear first in the status dropdown. Use 1, 2, 3, etc.', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="submit" class="button button-primary"
                                value="<?php echo $editing_status ? esc_attr__('Update Status', 'job-posting-manager') : esc_attr__('Add Status', 'job-posting-manager'); ?>">
                            <?php if ($editing_status): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-status-management')); ?>" class="button">
                                    <?php esc_html_e('Cancel', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="jpm-status-list-section">
                    <h2><?php esc_html_e('Existing Statuses', 'job-posting-manager'); ?></h2>

                    <?php if (empty($statuses)): ?>
                        <p><?php esc_html_e('No statuses found. Add your first status above.', 'job-posting-manager'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 5%;"><?php esc_html_e('ID', 'job-posting-manager'); ?></th>
                                    <th style="width: 10%;"><?php esc_html_e('Ordering', 'job-posting-manager'); ?></th>
                                    <th style="width: 18%;"><?php esc_html_e('Name', 'job-posting-manager'); ?></th>
                                    <th style="width: 15%;"><?php esc_html_e('Slug', 'job-posting-manager'); ?></th>
                                    <th style="width: 18%;"><?php esc_html_e('Preview', 'job-posting-manager'); ?></th>
                                    <th style="width: 24%;"><?php esc_html_e('Description', 'job-posting-manager'); ?></th>
                                    <th style="width: 10%;"><?php esc_html_e('Actions', 'job-posting-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statuses as $status): ?>
                                    <tr>
                                        <td><?php echo esc_html($status['id']); ?></td>
                                        <td><?php echo esc_html(isset($status['ordering']) ? $status['ordering'] : 0); ?></td>
                                        <td><strong><?php echo esc_html($status['name']); ?></strong></td>
                                        <td><code><?php echo esc_html($status['slug']); ?></code></td>
                                        <td>
                                            <span class="jpm-status-badge-preview"
                                                style="background-color: <?php echo esc_attr($status['color']); ?>; color: <?php echo esc_attr($status['text_color']); ?>; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                                <?php echo esc_html($status['name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($status['description']); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=jpm-status-management&edit=' . $status['id'])); ?>"
                                                class="button button-small"><?php esc_html_e('Edit', 'job-posting-manager'); ?></a>
                                            <form method="post" action="" style="display: inline-block; margin-left: 5px;"
                                                onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this status?', 'job-posting-manager'); ?>');">
                                                <?php wp_nonce_field('jpm_status_management'); ?>
                                                <input type="hidden" name="jpm_action" value="delete">
                                                <input type="hidden" name="status_id" value="<?php echo esc_attr($status['id']); ?>">
                                                <input type="submit" class="button button-small button-link-delete"
                                                    value="<?php esc_attr_e('Delete', 'job-posting-manager'); ?>">
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <style>
            .jpm-status-badge-preview {
                display: inline-block;
            }
        </style>
        <script>
            (function ($) {
                const $name = $('#status_name');
                const $slug = $('#status_slug');
                let slugManuallyEdited = $slug.val().length > 0;

                const buildSlug = (text) => {
                    return text
                        .toString()
                        .toLowerCase()
                        .trim()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-');
                };

                const maybeFillSlug = () => {
                    if (slugManuallyEdited) {
                        return;
                    }
                    const generated = buildSlug($name.val());
                    $slug.val(generated);
                };

                $name.on('input', maybeFillSlug);
                $slug.on('input', function () {
                    slugManuallyEdited = $(this).val().length > 0;
                });

                $(document).ready(maybeFillSlug);
            })(jQuery);
        </script>
        <?php
    }

    /**
     * Handle status add/edit/delete before output to avoid header warnings.
     */
    public function handle_status_actions()
    {
        $is_status_page = isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'jpm-status-management';
        if (!$is_status_page || !isset($_POST['jpm_action'])) {
            return;
        }

        check_admin_referer('jpm_status_management');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'job-posting-manager'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['jpm_action']));

        if ($action === 'add') {
            $this->add_status();
        } elseif ($action === 'edit') {
            $this->update_status_item();
        } elseif ($action === 'delete') {
            $this->delete_status_item();
        }
    }

    /**
     * Get all statuses
     */
    private function get_all_statuses()
    {
        $statuses = get_option('jpm_application_statuses', []);

        // If no custom statuses, return default ones
        if (empty($statuses)) {
            $statuses = $this->get_default_statuses();
        }

        // Ensure ordering field exists for all statuses and set default if missing
        foreach ($statuses as $index => $status) {
            if (!isset($status['ordering'])) {
                $statuses[$index]['ordering'] = isset($status['id']) ? $status['id'] : 0;
            }
        }

        // Sort by ordering, then by ID as fallback
        usort($statuses, function ($a, $b) {
            $order_a = isset($a['ordering']) ? intval($a['ordering']) : (isset($a['id']) ? $a['id'] : 0);
            $order_b = isset($b['ordering']) ? intval($b['ordering']) : (isset($b['id']) ? $b['id'] : 0);
            if ($order_a == $order_b) {
                return (isset($a['id']) ? $a['id'] : 0) - (isset($b['id']) ? $b['id'] : 0);
            }
            return $order_a - $order_b;
        });

        return $statuses;
    }

    /**
     * Get default statuses
     */
    private function get_default_statuses()
    {
        return [
            ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review', 'ordering' => 1],
            ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed', 'ordering' => 2],
            ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted', 'ordering' => 3],
            ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected', 'ordering' => 4],
        ];
    }

    /**
     * Add new status
     */
    private function add_status()
    {
        $status_name = isset($_POST['status_name']) ? sanitize_text_field(wp_unslash($_POST['status_name'])) : '';
        $status_slug = isset($_POST['status_slug']) ? sanitize_text_field(wp_unslash($_POST['status_slug'])) : '';
        $status_color = isset($_POST['status_color']) ? sanitize_hex_color(wp_unslash($_POST['status_color'])) : '#ffc107';
        $status_text_color = isset($_POST['status_text_color']) ? sanitize_hex_color(wp_unslash($_POST['status_text_color'])) : '#000000';
        $status_description = isset($_POST['status_description']) ? sanitize_textarea_field(wp_unslash($_POST['status_description'])) : '';
        $status_ordering = isset($_POST['status_ordering']) ? absint(wp_unslash($_POST['status_ordering'])) : 0;

        if (empty($status_name)) {
            wp_die(__('Status name is required', 'job-posting-manager'));
        }

        $status_slug = $this->generate_status_slug($status_name, $status_slug);

        if (empty($status_slug)) {
            wp_die(__('Unable to generate a valid status slug', 'job-posting-manager'));
        }

        $statuses = $this->get_all_statuses();

        // Check if slug already exists
        foreach ($statuses as $status) {
            if ($status['slug'] === $status_slug) {
                wp_die(__('A status with this slug already exists', 'job-posting-manager'));
            }
        }

        // Get next ID
        $max_id = 0;
        foreach ($statuses as $status) {
            if ($status['id'] > $max_id) {
                $max_id = $status['id'];
            }
        }

        $new_status = [
            'id' => $max_id + 1,
            'name' => $status_name,
            'slug' => $status_slug,
            'color' => $status_color,
            'text_color' => $status_text_color,
            'description' => $status_description,
            'ordering' => $status_ordering,
        ];

        $statuses[] = $new_status;
        update_option('jpm_application_statuses', $statuses);

        wp_safe_redirect(admin_url('admin.php?page=jpm-status-management&status_saved=1'));
        exit;
    }

    /**
     * Update status
     */
    private function update_status_item()
    {
        $status_id = isset($_POST['status_id']) ? absint(wp_unslash($_POST['status_id'])) : 0;
        $status_name = isset($_POST['status_name']) ? sanitize_text_field(wp_unslash($_POST['status_name'])) : '';
        $status_slug = isset($_POST['status_slug']) ? sanitize_text_field(wp_unslash($_POST['status_slug'])) : '';
        $status_color = isset($_POST['status_color']) ? sanitize_hex_color(wp_unslash($_POST['status_color'])) : '#ffc107';
        $status_text_color = isset($_POST['status_text_color']) ? sanitize_hex_color(wp_unslash($_POST['status_text_color'])) : '#000000';
        $status_description = isset($_POST['status_description']) ? sanitize_textarea_field(wp_unslash($_POST['status_description'])) : '';
        $status_ordering = isset($_POST['status_ordering']) ? absint(wp_unslash($_POST['status_ordering'])) : 0;

        if (!$status_id || empty($status_name)) {
            wp_die(__('Invalid data', 'job-posting-manager'));
        }

        $status_slug = $this->generate_status_slug($status_name, $status_slug);

        if (empty($status_slug)) {
            wp_die(__('Unable to generate a valid status slug', 'job-posting-manager'));
        }

        $statuses = $this->get_all_statuses();

        // Check if slug already exists (excluding current status)
        foreach ($statuses as $index => $status) {
            if ($status['slug'] === $status_slug && $status['id'] != $status_id) {
                wp_die(__('A status with this slug already exists', 'job-posting-manager'));
            }

            if ($status['id'] == $status_id) {
                $statuses[$index] = [
                    'id' => $status_id,
                    'name' => $status_name,
                    'slug' => $status_slug,
                    'color' => $status_color,
                    'text_color' => $status_text_color,
                    'description' => $status_description,
                    'ordering' => $status_ordering,
                ];
                break;
            }
        }

        update_option('jpm_application_statuses', $statuses);

        wp_safe_redirect(admin_url('admin.php?page=jpm-status-management&status_saved=1'));
        exit;
    }

    /**
     * Generate a sanitized status slug from either the provided slug or the name.
     */
    private function generate_status_slug($status_name, $status_slug_input = '')
    {
        $source = !empty($status_slug_input) ? $status_slug_input : $status_name;
        $slug = sanitize_title($source);

        // Fallback: if sanitize_title strips everything, try again with the name
        if (empty($slug) && !empty($status_name)) {
            $slug = sanitize_title($status_name);
        }

        return $slug;
    }

    /**
     * Delete status
     */
    private function delete_status_item()
    {
        $status_id = isset($_POST['status_id']) ? absint(wp_unslash($_POST['status_id'])) : 0;

        if (!$status_id) {
            wp_die(__('Invalid status ID', 'job-posting-manager'));
        }

        $statuses = $this->get_all_statuses();

        // Remove status
        foreach ($statuses as $index => $status) {
            if ($status['id'] == $status_id) {
                unset($statuses[$index]);
                break;
            }
        }

        // Re-index array
        $statuses = array_values($statuses);

        update_option('jpm_application_statuses', $statuses);

        wp_safe_redirect(admin_url('admin.php?page=jpm-status-management&status_deleted=1'));
        exit;
    }

    /**
     * Get status options for dropdown (used in forms)
     */
    public static function get_status_options()
    {
        // Get statuses from option
        $statuses = get_option('jpm_application_statuses', []);

        // If no custom statuses, return default ones
        if (empty($statuses)) {
            $default_statuses = [
                ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review', 'ordering' => 1],
                ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed', 'ordering' => 2],
                ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted', 'ordering' => 3],
                ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected', 'ordering' => 4],
            ];
            $statuses = $default_statuses;
        }

        // Ensure ordering field exists for all statuses and set default if missing
        foreach ($statuses as $index => $status) {
            if (!isset($status['ordering'])) {
                $statuses[$index]['ordering'] = isset($status['id']) ? $status['id'] : 0;
            }
        }

        // Sort by ordering, then by ID as fallback
        usort($statuses, function ($a, $b) {
            $order_a = isset($a['ordering']) ? intval($a['ordering']) : (isset($a['id']) ? $a['id'] : 0);
            $order_b = isset($b['ordering']) ? intval($b['ordering']) : (isset($b['id']) ? $b['id'] : 0);
            if ($order_a == $order_b) {
                return (isset($a['id']) ? $a['id'] : 0) - (isset($b['id']) ? $b['id'] : 0);
            }
            return $order_a - $order_b;
        });

        $options = [];
        foreach ($statuses as $status) {
            $options[$status['slug']] = $status['name'];
        }

        return $options;
    }

    /**
     * Get all statuses with full information
     * Delegates to JPM_Status_Manager for modularity
     */
    public static function get_all_statuses_info()
    {
        return JPM_Status_Manager::get_all_statuses_info();
    }

    /**
     * Get status by slug
     */
    public static function get_status_by_slug($slug)
    {
        // Get statuses from option
        $statuses = get_option('jpm_application_statuses', []);

        // If no custom statuses, return default ones
        if (empty($statuses)) {
            $default_statuses = [
                ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review', 'ordering' => 1],
                ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed', 'ordering' => 2],
                ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted', 'ordering' => 3],
                ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected', 'ordering' => 4],
            ];
            $statuses = $default_statuses;
        }

        foreach ($statuses as $status) {
            if ($status['slug'] === $slug) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Handle export requests
     */
    public function handle_export()
    {
        global $wpdb;

        if (!isset($_GET['page']) || (!isset($_GET['export']) && !isset($_GET['report_generate']))) {
            return;
        }

        $page = sanitize_text_field(wp_unslash($_GET['page']));
        $export_format = sanitize_text_field(wp_unslash($_GET['export'] ?? ''));

        if ($page === 'jpm-applications' && isset($_GET['report_generate'])) {
            $this->handle_applications_report_export();
            exit;
        }

        if ($page === 'jpm-whitelisted-applications' && isset($_GET['report_generate'])) {
            $this->handle_whitelist_report_export();
            exit;
        }

        // Applications export (existing)
        if ($page === 'jpm-applications') {
            // Check user capabilities (admin or editor)
            if (!current_user_can('edit_posts')) {
                wp_die(__('You do not have permission to export applications.', 'job-posting-manager'));
            }

            if (
                !isset($_GET['jpm_export_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['jpm_export_nonce'])), 'jpm_export_applications')
            ) {
                wp_die(__('Security check failed.', 'job-posting-manager'));
            }

            if (!in_array($export_format, ['csv', 'json'], true)) {
                wp_die(__('Invalid export format.', 'job-posting-manager'));
            }

            // Get filters
            $filters = [
                'status' => isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '',
                'job_id' => isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0,
                'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
            ];

            // Get applications
            $applications = JPM_DB::get_applications($filters);

            if ($export_format === 'csv') {
                $this->export_to_csv($applications);
            } else {
                $this->export_to_json($applications);
            }

            exit;
        }

        // Job Listings export
        if ($page === 'jpm-job-listings') {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to export jobs.', 'job-posting-manager'));
            }

            if (
                !isset($_GET['jpm_export_nonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['jpm_export_nonce'])), 'jpm_export_jobs')
            ) {
                wp_die(__('Security check failed.', 'job-posting-manager'));
            }

            if (!in_array($export_format, ['csv', 'json'], true)) {
                wp_die(__('Invalid export format.', 'job-posting-manager'));
            }

            $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
            $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
            $expired_filter = isset($_GET['expired']) ? sanitize_text_field(wp_unslash($_GET['expired'])) : '';

            $table = $this->get_validated_applications_table();
            $current_time = current_time('timestamp');

            $query_args = [
                'post_type' => 'job_posting',
                'posts_per_page' => -1,
                'paged' => 1,
                'post_status' => $status_filter ? $status_filter : 'any',
                'orderby' => 'date',
                'order' => 'DESC',
            ];

            if (!empty($search)) {
                $query_args['s'] = $search;
            }

            if ($expired_filter === 'expired') {
                $query_args['meta_query'] = [
                    [
                        'key' => 'expiration_date',
                        'value' => $current_time,
                        'compare' => '<=',
                        'type' => 'NUMERIC',
                    ],
                ];
            } elseif ($expired_filter === 'not_expired') {
                $query_args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => 'expiration_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => 'expiration_date',
                        'value' => '',
                        'compare' => '=',
                    ],
                    [
                        'key' => 'expiration_date',
                        'value' => $current_time,
                        'compare' => '>',
                        'type' => 'NUMERIC',
                    ],
                ];
            }

            $jobs_query = new WP_Query($query_args);
            $jobs = $jobs_query->posts;

            $application_counts = [];
            if (!empty($jobs)) {
                $job_ids = wp_list_pluck($jobs, 'ID');
                $placeholders = implode(',', array_fill(0, count($job_ids), '%d'));

                $query = $wpdb->prepare(
                    "SELECT job_id, COUNT(*) AS app_count FROM {$table} WHERE job_id IN ({$placeholders}) GROUP BY job_id",
                    $job_ids
                );
                $results = $wpdb->get_results($query, ARRAY_A);
                foreach ($results as $row) {
                    $application_counts[(int) $row['job_id']] = (int) $row['app_count'];
                }
            }

            if ($export_format === 'csv') {
                $this->export_jobs_to_csv($jobs, $application_counts);
            }

            if ($export_format === 'json') {
                $this->export_jobs_to_json($jobs, $application_counts);
            }

            exit;
        }
    }

    /**
     * Handle detailed applications report export by date range.
     */
    private function handle_applications_report_export()
    {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to generate reports.', 'job-posting-manager'));
        }

        if (
            !isset($_GET['jpm_report_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['jpm_report_nonce'])), 'jpm_generate_applications_report')
        ) {
            wp_die(__('Security check failed.', 'job-posting-manager'));
        }

        $report_format = isset($_GET['report_format']) ? sanitize_key(wp_unslash($_GET['report_format'])) : 'csv';
        if (!in_array($report_format, ['csv', 'pdf'], true)) {
            wp_die(__('Invalid report format.', 'job-posting-manager'));
        }

        $report_range = isset($_GET['report_range']) ? sanitize_key(wp_unslash($_GET['report_range'])) : 'today';
        $custom_start = isset($_GET['report_start']) ? sanitize_text_field(wp_unslash($_GET['report_start'])) : '';
        $custom_end = isset($_GET['report_end']) ? sanitize_text_field(wp_unslash($_GET['report_end'])) : '';

        $range = $this->resolve_report_date_range($report_range, $custom_start, $custom_end);
        if (!$range) {
            wp_die(__('Invalid date range.', 'job-posting-manager'));
        }

        $filters = [
            'status' => isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '',
            'job_id' => isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0,
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
        ];

        $applications = JPM_DB::get_applications($filters);
        $from_ts = strtotime($range['start']);
        $to_ts = strtotime($range['end']);

        $applications = array_values(array_filter($applications, function ($app) use ($from_ts, $to_ts) {
            $app_ts = strtotime((string) $app->application_date);
            if (!$app_ts) {
                return false;
            }
            return $app_ts >= $from_ts && $app_ts <= $to_ts;
        }));

        $report_context = [
            'range_label' => $range['label'],
            'range_start' => $range['start'],
            'range_end' => $range['end'],
            'filters' => $filters,
        ];

        if ($report_format === 'pdf') {
            $this->export_applications_report_pdf_html($applications, $report_context);
            return;
        }

        $this->export_applications_report_csv($applications, $report_context);
    }

    /**
     * Whitelisted applications report (same formats as Applications report).
     */
    private function handle_whitelist_report_export()
    {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to generate reports.', 'job-posting-manager'));
        }

        if (
            !isset($_GET['jpm_report_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['jpm_report_nonce'])), 'jpm_generate_whitelist_report')
        ) {
            wp_die(__('Security check failed.', 'job-posting-manager'));
        }

        $report_format = isset($_GET['report_format']) ? sanitize_key(wp_unslash($_GET['report_format'])) : 'csv';
        if (!in_array($report_format, ['csv', 'pdf'], true)) {
            wp_die(__('Invalid report format.', 'job-posting-manager'));
        }

        $report_range = isset($_GET['report_range']) ? sanitize_key(wp_unslash($_GET['report_range'])) : 'today';
        $custom_start = isset($_GET['report_start']) ? sanitize_text_field(wp_unslash($_GET['report_start'])) : '';
        $custom_end = isset($_GET['report_end']) ? sanitize_text_field(wp_unslash($_GET['report_end'])) : '';

        $range = $this->resolve_report_date_range($report_range, $custom_start, $custom_end);
        if (!$range) {
            wp_die(__('Invalid date range.', 'job-posting-manager'));
        }

        $filters = [
            'whitelisted_only' => true,
            'job_id' => isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0,
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
            'location' => isset($_GET['location']) ? sanitize_text_field(wp_unslash($_GET['location'])) : '',
            'submitted_on' => isset($_GET['submitted_on']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_on'])) : '',
            'submitted_from' => isset($_GET['submitted_from']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_from'])) : '',
            'submitted_to' => isset($_GET['submitted_to']) ? JPM_Database::normalize_application_filter_date(wp_unslash($_GET['submitted_to'])) : '',
            'status' => '',
        ];

        $applications = JPM_DB::get_applications($filters);
        $from_ts = strtotime($range['start']);
        $to_ts = strtotime($range['end']);

        $applications = array_values(array_filter($applications, function ($app) use ($from_ts, $to_ts) {
            $app_ts = strtotime((string) $app->application_date);
            if (!$app_ts) {
                return false;
            }
            return $app_ts >= $from_ts && $app_ts <= $to_ts;
        }));

        $report_context = [
            'range_label' => $range['label'],
            'range_start' => $range['start'],
            'range_end' => $range['end'],
            'filters' => $filters,
            'is_whitelist_report' => true,
        ];

        if ($report_format === 'pdf') {
            $this->export_applications_report_pdf_html($applications, $report_context);
            return;
        }

        $this->export_applications_report_csv($applications, $report_context);
    }

    /**
     * Resolve report date range into start/end datetime strings.
     *
     * @return array|null
     */
    private function resolve_report_date_range($report_range, $custom_start, $custom_end)
    {
        $now_ts = current_time('timestamp');

        $build = function ($start_ts, $end_ts, $label) {
            return [
                'start' => date('Y-m-d 00:00:00', $start_ts),
                'end' => date('Y-m-d 23:59:59', $end_ts),
                'label' => $label,
            ];
        };

        switch ($report_range) {
            case 'today':
                return $build($now_ts, $now_ts, __('Today', 'job-posting-manager'));
            case 'last_week':
                return $build(strtotime('-7 days', $now_ts), $now_ts, __('Last Week', 'job-posting-manager'));
            case 'last_month':
                return $build(strtotime('-1 month', $now_ts), $now_ts, __('Last Month', 'job-posting-manager'));
            case 'last_3_months':
                return $build(strtotime('-3 months', $now_ts), $now_ts, __('Last 3 Months', 'job-posting-manager'));
            case 'last_6_months':
                return $build(strtotime('-6 months', $now_ts), $now_ts, __('Last 6 Months', 'job-posting-manager'));
            case 'last_year':
                return $build(strtotime('-1 year', $now_ts), $now_ts, __('Last Year', 'job-posting-manager'));
            case 'custom':
                if (
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start) ||
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end)
                ) {
                    return null;
                }
                $start_ts = strtotime($custom_start . ' 00:00:00');
                $end_ts = strtotime($custom_end . ' 23:59:59');
                if (!$start_ts || !$end_ts || $start_ts > $end_ts) {
                    return null;
                }
                return [
                    'start' => date('Y-m-d 00:00:00', $start_ts),
                    'end' => date('Y-m-d 23:59:59', $end_ts),
                    'label' => sprintf(__('Custom Range (%1$s to %2$s)', 'job-posting-manager'), $custom_start, $custom_end),
                ];
            default:
                return null;
        }
    }

    /**
     * Extract a single value from possible form keys.
     */
    private function get_first_form_value($form_data, $keys)
    {
        foreach ($keys as $key) {
            if (isset($form_data[$key]) && $form_data[$key] !== '') {
                return is_scalar($form_data[$key]) ? sanitize_text_field((string) $form_data[$key]) : '';
            }
        }
        return '';
    }

    /**
     * Build a normalized, detailed report row for one application.
     */
    private function build_application_report_row($application)
    {
        $job = get_post($application->job_id);
        $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
        $form_data = json_decode($application->notes, true);
        if (!is_array($form_data)) {
            $form_data = [];
        }

        $first_name = $this->get_first_form_value($form_data, ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name']);
        $middle_name = $this->get_first_form_value($form_data, ['middle_name', 'middlename', 'mname', 'middle-name', 'middle name']);
        $last_name = $this->get_first_form_value($form_data, ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name']);
        $email = $this->get_first_form_value($form_data, ['email', 'email_address', 'e-mail', 'email-address']);
        $application_number = $this->get_first_form_value($form_data, ['application_number']);
        $date_of_registration = $this->get_first_form_value($form_data, ['date_of_registration']);
        $phone = $this->get_first_form_value($form_data, ['phone', 'phone_number', 'mobile', 'contact_number']);
        $address = $this->get_first_form_value($form_data, ['address', 'full_address', 'home_address', 'current_address']);
        $birth_date = $this->get_first_form_value($form_data, ['birth_date', 'date_of_birth', 'birthday']);
        $gender = $this->get_first_form_value($form_data, ['gender', 'sex']);
        $civil_status = $this->get_first_form_value($form_data, ['civil_status', 'marital_status']);
        $education = $this->get_first_form_value($form_data, ['education', 'educational_attainment', 'highest_education']);
        $work_experience = $this->get_first_form_value($form_data, ['work_experience', 'experience', 'years_of_experience']);
        $skills = $this->get_first_form_value($form_data, ['skills', 'technical_skills', 'core_skills']);
        $cover_letter = $this->get_first_form_value($form_data, ['cover_letter', 'message', 'application_message']);

        if ($user) {
            if ($first_name === '' && isset($user->first_name)) {
                $first_name = sanitize_text_field($user->first_name);
            }
            if ($last_name === '' && isset($user->last_name)) {
                $last_name = sanitize_text_field($user->last_name);
            }
            if ($email === '' && isset($user->user_email)) {
                $email = sanitize_email($user->user_email);
            }
        }

        $full_name = trim(preg_replace('/\s+/', ' ', $first_name . ' ' . $middle_name . ' ' . $last_name));
        $status_info = self::get_status_by_slug($application->status);
        $status_name = $status_info ? $status_info['name'] : ucfirst((string) $application->status);

        $emp_fn = isset($application->employer_first_name) ? sanitize_text_field((string) $application->employer_first_name) : '';
        $emp_ln = isset($application->employer_last_name) ? sanitize_text_field((string) $application->employer_last_name) : '';
        $emp_phone = isset($application->employer_phone) ? sanitize_text_field((string) $application->employer_phone) : '';
        $emp_email_raw = isset($application->employer_email) ? trim((string) $application->employer_email) : '';
        $emp_email = $emp_email_raw !== '' ? sanitize_email($emp_email_raw) : '';
        if ($emp_email !== '' && !is_email($emp_email)) {
            $emp_email = '';
        }
        $emp_recorded = '';
        if (!empty($application->employer_recorded_at)) {
            $emp_ts = strtotime((string) $application->employer_recorded_at);
            if ($emp_ts) {
                $emp_recorded = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $emp_ts);
            }
        }

        return [
            'id' => (int) $application->id,
            'application_number' => $application_number,
            'application_date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date)),
            'application_date_raw' => (string) $application->application_date,
            'status' => $status_name,
            'status_slug' => (string) $application->status,
            'job_id' => (int) $application->job_id,
            'job_title' => $job ? $job->post_title : __('Job Deleted', 'job-posting-manager'),
            'user_type' => $user ? __('Registered', 'job-posting-manager') : __('Guest', 'job-posting-manager'),
            'user_id' => (int) $application->user_id,
            'user_name' => $user ? $user->display_name : __('Guest', 'job-posting-manager'),
            'user_email' => $user ? $user->user_email : '',
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'full_name' => $full_name,
            'email' => $email,
            'date_of_registration' => $date_of_registration,
            'phone' => $phone,
            'address' => $address,
            'birth_date' => $birth_date,
            'gender' => $gender,
            'civil_status' => $civil_status,
            'education' => $education,
            'work_experience' => $work_experience,
            'skills' => $skills,
            'cover_letter' => $cover_letter,
            'form_data' => $form_data,
            'employer_first_name' => $emp_fn,
            'employer_last_name' => $emp_ln,
            'employer_phone' => $emp_phone,
            'employer_email' => $emp_email,
            'employer_recorded_at' => $emp_recorded,
        ];
    }

    /**
     * Whether any row in a built report has employer welfare data (whitelist exports).
     *
     * @param array $rows Rows from build_application_report_row().
     */
    private function whitelist_report_includes_employer_data(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!empty($row['employer_email'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Export detailed applications report to CSV.
     */
    private function export_applications_report_csv($applications, $context)
    {
        $is_whitelist = !empty($context['is_whitelist_report']);
        $report_title = $is_whitelist
            ? __('Whitelisted applications report', 'job-posting-manager')
            : __('Detailed Applications Report', 'job-posting-manager');
        $file_prefix = $is_whitelist ? 'whitelisted-applications-report-' : 'applications-report-';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $file_prefix . date('Y-m-d-H-i-s') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $rows = [];
        $status_counts = [];
        $job_counts = [];
        $registered_count = 0;
        $guest_count = 0;

        foreach ($applications as $application) {
            $row = $this->build_application_report_row($application);
            $rows[] = $row;

            $status_key = $row['status'];
            $status_counts[$status_key] = isset($status_counts[$status_key]) ? $status_counts[$status_key] + 1 : 1;

            $job_key = $row['job_title'];
            $job_counts[$job_key] = isset($job_counts[$job_key]) ? $job_counts[$job_key] + 1 : 1;

            if ($row['user_type'] === __('Registered', 'job-posting-manager')) {
                $registered_count++;
            } else {
                $guest_count++;
            }
        }

        arsort($status_counts);
        arsort($job_counts);

        $include_employer_columns = $is_whitelist && $this->whitelist_report_includes_employer_data($rows);

        fputcsv($output, [$report_title]);
        fputcsv($output, [__('Generated At', 'job-posting-manager'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'))]);
        fputcsv($output, [__('Date Range', 'job-posting-manager'), $context['range_label']]);
        fputcsv($output, [__('Range Start', 'job-posting-manager'), $context['range_start']]);
        fputcsv($output, [__('Range End', 'job-posting-manager'), $context['range_end']]);
        if ($is_whitelist) {
            $f = $context['filters'];
            fputcsv($output, [__('Scope', 'job-posting-manager'), __('Whitelisted applications only', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Job ID', 'job-posting-manager'), !empty($f['job_id']) ? (string) (int) $f['job_id'] : __('All', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Search', 'job-posting-manager'), !empty($f['search']) ? $f['search'] : __('None', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Job location', 'job-posting-manager'), !empty($f['location']) ? $f['location'] : __('All', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Submitted on', 'job-posting-manager'), !empty($f['submitted_on']) ? $f['submitted_on'] : __('None', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Submitted from', 'job-posting-manager'), !empty($f['submitted_from']) ? $f['submitted_from'] : __('None', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Submitted to', 'job-posting-manager'), !empty($f['submitted_to']) ? $f['submitted_to'] : __('None', 'job-posting-manager')]);
            if ($include_employer_columns) {
                $with_emp = 0;
                foreach ($rows as $r) {
                    if (!empty($r['employer_email'])) {
                        $with_emp++;
                    }
                }
                fputcsv($output, [
                    __('Employer welfare', 'job-posting-manager'),
                    sprintf(
                        /* translators: 1: count with employer on file, 2: total applications in report. */
                        __('%1$d of %2$d applications include employer contact', 'job-posting-manager'),
                        $with_emp,
                        count($rows)
                    ),
                ]);
            }
        } else {
            fputcsv($output, [__('Filter: Status', 'job-posting-manager'), $context['filters']['status'] !== '' ? $context['filters']['status'] : __('All', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Job ID', 'job-posting-manager'), $context['filters']['job_id'] > 0 ? (string) $context['filters']['job_id'] : __('All', 'job-posting-manager')]);
            fputcsv($output, [__('Filter: Search', 'job-posting-manager'), $context['filters']['search'] !== '' ? $context['filters']['search'] : __('None', 'job-posting-manager')]);
        }
        fputcsv($output, [__('Total Matching Applications', 'job-posting-manager'), count($rows)]);
        fputcsv($output, [__('Registered Users', 'job-posting-manager'), $registered_count]);
        fputcsv($output, [__('Guest Applications', 'job-posting-manager'), $guest_count]);
        fputcsv($output, [__('Unique Jobs Applied', 'job-posting-manager'), count($job_counts)]);
        fputcsv($output, []);

        fputcsv($output, [__('Applications by Status', 'job-posting-manager')]);
        fputcsv($output, [__('Status', 'job-posting-manager'), __('Count', 'job-posting-manager')]);
        if (empty($status_counts)) {
            fputcsv($output, [__('No data available', 'job-posting-manager'), 0]);
        } else {
            foreach ($status_counts as $status_name => $count) {
                fputcsv($output, [$status_name, $count]);
            }
        }
        fputcsv($output, []);

        fputcsv($output, [__('Top Jobs by Applications', 'job-posting-manager')]);
        fputcsv($output, [__('Job Title', 'job-posting-manager'), __('Applications', 'job-posting-manager')]);
        if (empty($job_counts)) {
            fputcsv($output, [__('No data available', 'job-posting-manager'), 0]);
        } else {
            foreach ($job_counts as $job_title => $count) {
                fputcsv($output, [$job_title, $count]);
            }
        }
        fputcsv($output, []);

        $detail_header = [
            __('ID', 'job-posting-manager'),
            __('Application Number', 'job-posting-manager'),
            __('Application Date', 'job-posting-manager'),
            __('Status', 'job-posting-manager'),
            __('Status Slug', 'job-posting-manager'),
            __('Job ID', 'job-posting-manager'),
            __('Job Title', 'job-posting-manager'),
            __('User Type', 'job-posting-manager'),
            __('User ID', 'job-posting-manager'),
            __('User Name', 'job-posting-manager'),
            __('User Email', 'job-posting-manager'),
            __('First Name', 'job-posting-manager'),
            __('Middle Name', 'job-posting-manager'),
            __('Last Name', 'job-posting-manager'),
            __('Full Name', 'job-posting-manager'),
            __('Applicant Email', 'job-posting-manager'),
            __('Date of Registration', 'job-posting-manager'),
            __('Phone', 'job-posting-manager'),
            __('Address', 'job-posting-manager'),
            __('Birth Date', 'job-posting-manager'),
            __('Gender', 'job-posting-manager'),
            __('Civil Status', 'job-posting-manager'),
            __('Education', 'job-posting-manager'),
            __('Work Experience', 'job-posting-manager'),
            __('Skills', 'job-posting-manager'),
            __('Cover Letter / Message', 'job-posting-manager'),
            __('Additional Form Data (Readable)', 'job-posting-manager'),
        ];
        if ($include_employer_columns) {
            $detail_header = array_merge(
                $detail_header,
                [
                    __('Employer first name', 'job-posting-manager'),
                    __('Employer last name', 'job-posting-manager'),
                    __('Employer phone', 'job-posting-manager'),
                    __('Employer email', 'job-posting-manager'),
                    __('Employer recorded at', 'job-posting-manager'),
                ]
            );
        }
        fputcsv($output, $detail_header);

        foreach ($rows as $row) {
            $readable_form_data = [];
            if (!empty($row['form_data']) && is_array($row['form_data'])) {
                foreach ($row['form_data'] as $field_key => $field_value) {
                    $display_key = ucwords(str_replace(['_', '-'], ' ', (string) $field_key));
                    if (is_array($field_value)) {
                        $display_items = array_map(function ($item) {
                            return is_scalar($item) ? sanitize_text_field((string) $item) : '';
                        }, $field_value);
                        $display_items = array_filter($display_items, function ($item) {
                            return $item !== '';
                        });
                        $display_value = implode(', ', $display_items);
                    } elseif (is_bool($field_value)) {
                        $display_value = $field_value ? __('Yes', 'job-posting-manager') : __('No', 'job-posting-manager');
                    } elseif (is_scalar($field_value)) {
                        $display_value = sanitize_text_field((string) $field_value);
                    } else {
                        $display_value = '';
                    }
                    if ($display_key !== '' && $display_value !== '') {
                        $readable_form_data[] = $display_key . ': ' . $display_value;
                    }
                }
            }

            $detail_row = [
                $row['id'],
                $row['application_number'],
                $row['application_date'],
                $row['status'],
                $row['status_slug'],
                $row['job_id'],
                $row['job_title'],
                $row['user_type'],
                $row['user_id'],
                $row['user_name'],
                $row['user_email'],
                $row['first_name'],
                $row['middle_name'],
                $row['last_name'],
                $row['full_name'],
                $row['email'],
                $row['date_of_registration'],
                $row['phone'],
                $row['address'],
                $row['birth_date'],
                $row['gender'],
                $row['civil_status'],
                $row['education'],
                $row['work_experience'],
                $row['skills'],
                $row['cover_letter'],
                !empty($readable_form_data) ? implode(' | ', $readable_form_data) : __('No additional form data.', 'job-posting-manager'),
            ];
            if ($include_employer_columns) {
                $detail_row[] = $row['employer_first_name'];
                $detail_row[] = $row['employer_last_name'];
                $detail_row[] = $row['employer_phone'];
                $detail_row[] = $row['employer_email'];
                $detail_row[] = $row['employer_recorded_at'];
            }
            fputcsv($output, $detail_row);
        }

        exit;
    }

    /**
     * Export detailed applications report to printable HTML (PDF-ready).
     */
    private function export_applications_report_pdf_html($applications, $context)
    {
        header('Content-Type: text/html; charset=utf-8');

        $is_whitelist = !empty($context['is_whitelist_report']);
        $report_heading = $is_whitelist
            ? __('Whitelisted applications report', 'job-posting-manager')
            : __('Detailed Applications Report', 'job-posting-manager');

        $rows = [];
        $status_counts = [];
        $job_counts = [];
        $registered_count = 0;
        $guest_count = 0;
        $range_start_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($context['range_start']));
        $range_end_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($context['range_end']));

        foreach ($applications as $application) {
            $row = $this->build_application_report_row($application);
            $rows[] = $row;
            $status_key = $row['status'];
            $status_counts[$status_key] = isset($status_counts[$status_key]) ? $status_counts[$status_key] + 1 : 1;
            $job_key = $row['job_title'];
            $job_counts[$job_key] = isset($job_counts[$job_key]) ? $job_counts[$job_key] + 1 : 1;
            if ($row['user_type'] === __('Registered', 'job-posting-manager')) {
                $registered_count++;
            } else {
                $guest_count++;
            }
        }

        arsort($status_counts);
        arsort($job_counts);

        $include_employer_columns = $is_whitelist && $this->whitelist_report_includes_employer_data($rows);

        ?>
        <!doctype html>
        <html>

        <head>
            <meta charset="utf-8">
            <title><?php echo esc_html($report_heading); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; color: #111; }
                h1, h2, h3 { margin: 0 0 10px; }
                .meta { margin-bottom: 18px; color: #444; font-size: 12px; }
                .grid { display: grid; grid-template-columns: repeat(4, minmax(140px, 1fr)); gap: 10px; margin: 14px 0 18px; }
                .card { border: 1px solid #ddd; border-radius: 5px; padding: 10px; background: #fafafa; }
                .card .label { font-size: 11px; color: #666; margin-bottom: 5px; text-transform: uppercase; }
                .card .value { font-size: 20px; font-weight: 700; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
                th, td { border: 1px solid #ddd; padding: 6px 8px; font-size: 12px; vertical-align: top; }
                th { background: #f5f5f5; text-align: left; }
                .app-block { border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin: 0 0 14px; page-break-inside: avoid; }
                .row { margin: 3px 0; font-size: 12px; }
                .row strong { display: inline-block; min-width: 180px; color: #444; }
                .raw-data-list { background: #f8f8f8; border: 1px solid #eee; padding: 8px; font-size: 11px; }
                .raw-data-list div { margin: 2px 0; }
                @media print { .no-print { display: none; } body { margin: 10mm; } }
            </style>
            <script>
                window.onload = function () { window.print(); };
            </script>
        </head>

        <body>
            <h1><?php echo esc_html($report_heading); ?></h1>
            <div class="meta">
                <div><?php echo esc_html(sprintf(__('Generated: %s', 'job-posting-manager'), date_i18n(get_option('date_format') . ' ' . get_option('time_format')))); ?></div>
                <div><?php echo esc_html(sprintf(__('Range: %1$s (%2$s to %3$s)', 'job-posting-manager'), $context['range_label'], $range_start_display, $range_end_display)); ?></div>
                <?php if ($is_whitelist): ?>
                    <?php $wf = $context['filters']; ?>
                    <div><?php echo esc_html(sprintf(__('Scope: %s', 'job-posting-manager'), __('Whitelisted applications only', 'job-posting-manager'))); ?></div>
                    <div><?php echo esc_html(sprintf(__('List filters — Job ID: %1$s | Search: %2$s | Job location: %3$s', 'job-posting-manager'), !empty($wf['job_id']) ? (string) (int) $wf['job_id'] : __('All', 'job-posting-manager'), !empty($wf['search']) ? $wf['search'] : __('None', 'job-posting-manager'), !empty($wf['location']) ? $wf['location'] : __('All', 'job-posting-manager'))); ?></div>
                    <div><?php echo esc_html(sprintf(__('List filters — Submitted on: %1$s | From: %2$s | To: %3$s', 'job-posting-manager'), !empty($wf['submitted_on']) ? $wf['submitted_on'] : __('None', 'job-posting-manager'), !empty($wf['submitted_from']) ? $wf['submitted_from'] : __('None', 'job-posting-manager'), !empty($wf['submitted_to']) ? $wf['submitted_to'] : __('None', 'job-posting-manager'))); ?></div>
                    <?php if ($include_employer_columns): ?>
                        <?php
                        $with_emp = 0;
                        foreach ($rows as $r) {
                            if (!empty($r['employer_email'])) {
                                $with_emp++;
                            }
                        }
                        ?>
                        <div><?php echo esc_html(sprintf(
                            /* translators: 1: count with employer on file, 2: total applications in report. */
                            __('Employer welfare: %1$d of %2$d applications include employer contact', 'job-posting-manager'),
                            $with_emp,
                            count($rows)
                        )); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div><?php echo esc_html(sprintf(__('Filters - Status: %1$s | Job ID: %2$s | Search: %3$s', 'job-posting-manager'), $context['filters']['status'] !== '' ? $context['filters']['status'] : __('All', 'job-posting-manager'), $context['filters']['job_id'] > 0 ? (string) $context['filters']['job_id'] : __('All', 'job-posting-manager'), $context['filters']['search'] !== '' ? $context['filters']['search'] : __('None', 'job-posting-manager'))); ?></div>
                <?php endif; ?>
            </div>

            <div class="grid">
                <div class="card"><div class="label"><?php esc_html_e('Total Applications', 'job-posting-manager'); ?></div><div class="value"><?php echo esc_html((string) count($rows)); ?></div></div>
                <div class="card"><div class="label"><?php esc_html_e('Registered Users', 'job-posting-manager'); ?></div><div class="value"><?php echo esc_html((string) $registered_count); ?></div></div>
                <div class="card"><div class="label"><?php esc_html_e('Guest Applications', 'job-posting-manager'); ?></div><div class="value"><?php echo esc_html((string) $guest_count); ?></div></div>
                <div class="card"><div class="label"><?php esc_html_e('Unique Jobs Applied', 'job-posting-manager'); ?></div><div class="value"><?php echo esc_html((string) count($job_counts)); ?></div></div>
            </div>

            <h2><?php esc_html_e('Applications by Status', 'job-posting-manager'); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Status', 'job-posting-manager'); ?></th>
                        <th><?php esc_html_e('Count', 'job-posting-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($status_counts)): ?>
                        <tr><td colspan="2"><?php esc_html_e('No data available', 'job-posting-manager'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($status_counts as $status_name => $count): ?>
                            <tr>
                                <td><?php echo esc_html($status_name); ?></td>
                                <td><?php echo esc_html((string) $count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Top Jobs by Applications', 'job-posting-manager'); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Job Title', 'job-posting-manager'); ?></th>
                        <th><?php esc_html_e('Applications', 'job-posting-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($job_counts)): ?>
                        <tr><td colspan="2"><?php esc_html_e('No data available', 'job-posting-manager'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($job_counts as $job_title => $count): ?>
                            <tr>
                                <td><?php echo esc_html($job_title); ?></td>
                                <td><?php echo esc_html((string) $count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Detailed Application Records', 'job-posting-manager'); ?></h2>
            <?php if (empty($rows)): ?>
                <p><?php esc_html_e('No applications found for the selected report criteria.', 'job-posting-manager'); ?></p>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <div class="app-block">
                        <h3><?php echo esc_html(sprintf(__('Application #%1$s (ID: %2$d)', 'job-posting-manager'), $row['application_number'] !== '' ? $row['application_number'] : '-', $row['id'])); ?></h3>
                        <div class="row"><strong><?php esc_html_e('Submitted', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['application_date']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Status', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['status'] . ' [' . $row['status_slug'] . ']'); ?></div>
                        <div class="row"><strong><?php esc_html_e('Job', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['job_title'] . ' (ID: ' . $row['job_id'] . ')'); ?></div>
                        <div class="row"><strong><?php esc_html_e('Applicant Full Name', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['full_name']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Applicant Email', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['email']); ?></div>
                        <div class="row"><strong><?php esc_html_e('First / Middle / Last', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['first_name'] . ' / ' . $row['middle_name'] . ' / ' . $row['last_name']); ?></div>
                        <div class="row"><strong><?php esc_html_e('User Account', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['user_type'] . ' | ID: ' . $row['user_id'] . ' | ' . $row['user_name'] . ' | ' . $row['user_email']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Date of Registration', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['date_of_registration']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Phone', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['phone']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Address', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['address']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Birth Date', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['birth_date']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Gender', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['gender']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Civil Status', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['civil_status']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Education', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['education']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Work Experience', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['work_experience']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Skills', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['skills']); ?></div>
                        <div class="row"><strong><?php esc_html_e('Cover Letter / Message', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['cover_letter']); ?></div>
                        <?php if ($include_employer_columns && !empty($row['employer_email'])): ?>
                            <h4 style="margin:12px 0 6px;font-size:13px;"><?php esc_html_e('Employer (welfare check)', 'job-posting-manager'); ?></h4>
                            <div class="row"><strong><?php esc_html_e('Employer first name', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['employer_first_name']); ?></div>
                            <div class="row"><strong><?php esc_html_e('Employer last name', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['employer_last_name']); ?></div>
                            <div class="row"><strong><?php esc_html_e('Employer phone', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['employer_phone']); ?></div>
                            <div class="row"><strong><?php esc_html_e('Employer email', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['employer_email']); ?></div>
                            <div class="row"><strong><?php esc_html_e('Employer recorded at', 'job-posting-manager'); ?>:</strong> <?php echo esc_html($row['employer_recorded_at']); ?></div>
                        <?php endif; ?>
                        <div class="row"><strong><?php esc_html_e('Raw Form Data', 'job-posting-manager'); ?>:</strong></div>
                        <div class="raw-data-list">
                            <?php if (empty($row['form_data'])): ?>
                                <div><?php esc_html_e('No additional form data.', 'job-posting-manager'); ?></div>
                            <?php else: ?>
                                <?php foreach ($row['form_data'] as $field_key => $field_value): ?>
                                    <?php
                                    $display_key = ucwords(str_replace(['_', '-'], ' ', (string) $field_key));
                                    if (is_array($field_value)) {
                                        $display_items = array_map(function ($item) {
                                            if (is_scalar($item)) {
                                                return sanitize_text_field((string) $item);
                                            }
                                            return '';
                                        }, $field_value);
                                        $display_items = array_filter($display_items, function ($item) {
                                            return $item !== '';
                                        });
                                        $display_value = implode(', ', $display_items);
                                    } elseif (is_bool($field_value)) {
                                        $display_value = $field_value ? __('Yes', 'job-posting-manager') : __('No', 'job-posting-manager');
                                    } elseif (is_scalar($field_value)) {
                                        $display_value = sanitize_text_field((string) $field_value);
                                    } else {
                                        $display_value = '';
                                    }
                                    ?>
                                    <div><strong><?php echo esc_html($display_key); ?>:</strong> <?php echo esc_html($display_value); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </body>

        </html>
        <?php
        exit;
    }

    /**
     * Export jobs (job_posting) to CSV.
     *
     * @param array $jobs WP posts array
     * @param array $application_counts job_id => count
     */
    private function export_jobs_to_csv($jobs, $application_counts)
    {
        global $wpdb;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=job-listings-' . date('Y-m-d-H-i-s') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = [
            __('ID', 'job-posting-manager'),
            __('Job Title', 'job-posting-manager'),
            __('Company', 'job-posting-manager'),
            __('Location', 'job-posting-manager'),
            __('Status', 'job-posting-manager'),
            __('Is Expired', 'job-posting-manager'),
            __('Expiration Date', 'job-posting-manager'),
            __('Applications', 'job-posting-manager'),
        ];
        fputcsv($output, $headers);

        $current_time = current_time('timestamp');

        foreach ($jobs as $job) {
            $company_name = get_post_meta($job->ID, 'company_name', true);
            $location = get_post_meta($job->ID, 'location', true);
            $expiration_timestamp = (int) get_post_meta($job->ID, 'expiration_date', true);
            $expiration_formatted = get_post_meta($job->ID, 'expiration_date_formatted', true);
            if (empty($expiration_formatted) && !empty($expiration_timestamp)) {
                $expiration_formatted = date('Y-m-d H:i:s', $expiration_timestamp);
            }

            $is_expired = !empty($expiration_timestamp) && $expiration_timestamp <= $current_time;

            $row = [
                $job->ID,
                $job->post_title,
                $company_name,
                $location,
                get_post_status($job->ID),
                $is_expired ? 'Yes' : 'No',
                $expiration_formatted,
                isset($application_counts[$job->ID]) ? $application_counts[$job->ID] : 0,
            ];
            fputcsv($output, $row);
        }

        exit;
    }

    /**
     * Export jobs (job_posting) to JSON.
     *
     * @param array $jobs WP posts array
     * @param array $application_counts job_id => count
     */
    private function export_jobs_to_json($jobs, $application_counts)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=job-listings-' . date('Y-m-d-H-i-s') . '.json');
        header('Pragma: no-cache');
        header('Expires: 0');

        $current_time = current_time('timestamp');

        $data = [];
        foreach ($jobs as $job) {
            $company_name = get_post_meta($job->ID, 'company_name', true);
            $location = get_post_meta($job->ID, 'location', true);

            $expiration_timestamp = (int) get_post_meta($job->ID, 'expiration_date', true);
            $expiration_formatted = get_post_meta($job->ID, 'expiration_date_formatted', true);
            if (empty($expiration_formatted) && !empty($expiration_timestamp)) {
                $expiration_formatted = date('Y-m-d H:i:s', $expiration_timestamp);
            }

            $is_expired = !empty($expiration_timestamp) && $expiration_timestamp <= $current_time;

            $data[] = [
                'id' => (int) $job->ID,
                'title' => $job->post_title,
                'company' => $company_name,
                'location' => $location,
                'status' => get_post_status($job->ID),
                'isExpired' => $is_expired ? 'Yes' : 'No',
                'expirationDate' => $expiration_formatted,
                'expirationTimestamp' => $expiration_timestamp,
                'applications' => isset($application_counts[$job->ID]) ? (int) $application_counts[$job->ID] : 0,
            ];
        }

        echo wp_json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Export jobs (job_posting) to a printable HTML view (user can Save as PDF).
     *
     * @param array $jobs WP posts array
     * @param array $application_counts job_id => count
     * @param array $filters
     */
    private function export_jobs_to_pdf_html($jobs, $application_counts, $filters = [])
    {
        global $wpdb;

        header('Content-Type: text/html; charset=utf-8');

        $title = __('Job Listings Export', 'job-posting-manager');
        $filters_text = [];
        if (!empty($filters['search'])) {
            $filters_text[] = sprintf(__('Search: %s', 'job-posting-manager'), $filters['search']);
        }
        if (!empty($filters['status'])) {
            $filters_text[] = sprintf(__('Status: %s', 'job-posting-manager'), $filters['status']);
        }
        if (!empty($filters['expired'])) {
            $filters_text[] = sprintf(__('Expired filter: %s', 'job-posting-manager'), $filters['expired']);
        }
        $filters_text = !empty($filters_text) ? implode(' | ', $filters_text) : __('All jobs', 'job-posting-manager');

        $current_time = current_time('timestamp');

        ?>
        <!doctype html>
        <html>

        <head>
            <meta charset="utf-8">
            <title><?php echo esc_html($title); ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    padding: 18px;
                }

                h1 {
                    font-size: 18px;
                    margin: 0 0 8px 0;
                }

                .meta {
                    color: #666;
                    margin-bottom: 14px;
                    font-size: 12px;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                }

                th,
                td {
                    border: 1px solid #ddd;
                    padding: 8px;
                    font-size: 12px;
                    vertical-align: top;
                }

                th {
                    background: #f5f5f5;
                    text-align: left;
                }

                .badge {
                    font-weight: bold;
                }
            </style>
            <script>
                window.onload = function () {
                    window.print();
                };
            </script>
        </head>

        <body>
            <h1><?php echo esc_html($title); ?></h1>
            <div class="meta">
                <?php echo esc_html($filters_text); ?> | <?php echo esc_html(date('Y-m-d H:i:s', $current_time)); ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th><?php echo esc_html(__('ID', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Job Title', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Company', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Location', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Status', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Is Expired', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Expiration Date', 'job-posting-manager')); ?></th>
                        <th><?php echo esc_html(__('Applications', 'job-posting-manager')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($jobs)): ?>
                        <tr>
                            <td colspan="8"><?php echo esc_html(__('No jobs found.', 'job-posting-manager')); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($jobs as $job): ?>
                            <?php
                            $company_name = get_post_meta($job->ID, 'company_name', true);
                            $location = get_post_meta($job->ID, 'location', true);
                            $expiration_timestamp = (int) get_post_meta($job->ID, 'expiration_date', true);
                            $expiration_formatted = get_post_meta($job->ID, 'expiration_date_formatted', true);
                            if (empty($expiration_formatted) && !empty($expiration_timestamp)) {
                                $expiration_formatted = date('Y-m-d H:i:s', $expiration_timestamp);
                            }
                            $is_expired = !empty($expiration_timestamp) && $expiration_timestamp <= $current_time;
                            ?>
                            <tr>
                                <td><?php echo esc_html($job->ID); ?></td>
                                <td><?php echo esc_html($job->post_title); ?></td>
                                <td><?php echo esc_html($company_name); ?></td>
                                <td><?php echo esc_html($location); ?></td>
                                <td><?php echo esc_html(get_post_status($job->ID)); ?></td>
                                <td class="badge" style="color: <?php echo $is_expired ? '#b32d2e' : '#1e7e34'; ?>;">
                                    <?php echo esc_html($is_expired ? __('Expired', 'job-posting-manager') : __('Not expired', 'job-posting-manager')); ?>
                                </td>
                                <td><?php echo esc_html($expiration_formatted); ?></td>
                                <td><?php echo esc_html(isset($application_counts[$job->ID]) ? $application_counts[$job->ID] : 0); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </body>

        </html>
        <?php
        exit;
    }

    /**
     * Export applications to CSV
     */
    private function export_to_csv($applications)
    {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=applications-' . date('Y-m-d-H-i-s') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Define CSV headers
        $headers = [
            __('ID', 'job-posting-manager'),
            __('Application Number', 'job-posting-manager'),
            __('Application Date', 'job-posting-manager'),
            __('Status', 'job-posting-manager'),
            __('Job Title', 'job-posting-manager'),
            __('Job ID', 'job-posting-manager'),
            __('First Name', 'job-posting-manager'),
            __('Middle Name', 'job-posting-manager'),
            __('Last Name', 'job-posting-manager'),
            __('Full Name', 'job-posting-manager'),
            __('Email', 'job-posting-manager'),
            __('User ID', 'job-posting-manager'),
            __('User Name', 'job-posting-manager'),
            __('User Email', 'job-posting-manager'),
            __('Date of Registration', 'job-posting-manager'),
        ];

        // Write headers
        fputcsv($output, $headers);

        // Write application data
        foreach ($applications as $application) {
            $job = get_post($application->job_id);
            $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
            $form_data = json_decode($application->notes, true);

            if (!is_array($form_data)) {
                $form_data = [];
            }

            // Extract customer information
            $first_name = '';
            $middle_name = '';
            $last_name = '';
            $email = '';
            $application_number = '';
            $date_of_registration = '';

            // First name variations
            $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
            foreach ($first_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $first_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Middle name variations
            $middle_name_fields = ['middle_name', 'middlename', 'mname', 'middle-name', 'middle name'];
            foreach ($middle_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $middle_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Last name variations
            $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
            foreach ($last_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $last_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Email variations
            $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];
            foreach ($email_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $email = sanitize_email($form_data[$field_name]);
                    break;
                }
            }

            // Application number and date of registration
            if (isset($form_data['application_number'])) {
                $application_number = sanitize_text_field($form_data['application_number']);
            }
            if (isset($form_data['date_of_registration'])) {
                $date_of_registration = sanitize_text_field($form_data['date_of_registration']);
            }

            // Fallback to user data if not found in form data
            if (empty($first_name) && $user) {
                $first_name = $user->first_name;
            }
            if (empty($last_name) && $user) {
                $last_name = $user->last_name;
            }
            if (empty($email) && $user) {
                $email = $user->user_email;
            }

            $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
            $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces

            // Get status name
            $status_info = self::get_status_by_slug($application->status);
            $status_name = $status_info ? $status_info['name'] : ucfirst($application->status);

            // Prepare row data
            $row = [
                $application->id,
                $application_number,
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date)),
                $status_name,
                $job ? $job->post_title : __('Job Deleted', 'job-posting-manager'),
                $application->job_id,
                $first_name,
                $middle_name,
                $last_name,
                $full_name,
                $email,
                $application->user_id,
                $user ? $user->display_name : __('Guest', 'job-posting-manager'),
                $user ? $user->user_email : '',
                $date_of_registration,
            ];

            // Write row
            fputcsv($output, $row);
        }

        exit;
    }

    /**
     * Export applications to JSON
     */
    private function export_to_json($applications)
    {
        // Set headers for JSON download
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=applications-' . date('Y-m-d-H-i-s') . '.json');
        header('Pragma: no-cache');
        header('Expires: 0');

        $export_data = [
            'export_date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'total_applications' => count($applications),
            'applications' => [],
        ];

        foreach ($applications as $application) {
            $job = get_post($application->job_id);
            $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
            $form_data = json_decode($application->notes, true);

            if (!is_array($form_data)) {
                $form_data = [];
            }

            // Extract customer information
            $first_name = '';
            $middle_name = '';
            $last_name = '';
            $email = '';
            $application_number = '';
            $date_of_registration = '';

            // First name variations
            $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
            foreach ($first_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $first_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Middle name variations
            $middle_name_fields = ['middle_name', 'middlename', 'mname', 'middle-name', 'middle name'];
            foreach ($middle_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $middle_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Last name variations
            $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
            foreach ($last_name_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $last_name = sanitize_text_field($form_data[$field_name]);
                    break;
                }
            }

            // Email variations
            $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];
            foreach ($email_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $email = sanitize_email($form_data[$field_name]);
                    break;
                }
            }

            // Application number and date of registration
            if (isset($form_data['application_number'])) {
                $application_number = sanitize_text_field($form_data['application_number']);
            }
            if (isset($form_data['date_of_registration'])) {
                $date_of_registration = sanitize_text_field($form_data['date_of_registration']);
            }

            // Fallback to user data if not found in form data
            if (empty($first_name) && $user) {
                $first_name = $user->first_name;
            }
            if (empty($last_name) && $user) {
                $last_name = $user->last_name;
            }
            if (empty($email) && $user) {
                $email = $user->user_email;
            }

            $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
            $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces

            // Get status name
            $status_info = self::get_status_by_slug($application->status);
            $status_name = $status_info ? $status_info['name'] : ucfirst($application->status);

            // Build application data
            $app_data = [
                'id' => $application->id,
                'application_number' => $application_number,
                'application_date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date)),
                'status' => $status_name,
                'status_slug' => $application->status,
                'job' => [
                    'id' => $application->job_id,
                    'title' => $job ? $job->post_title : __('Job Deleted', 'job-posting-manager'),
                ],
                'applicant' => [
                    'first_name' => $first_name,
                    'middle_name' => $middle_name,
                    'last_name' => $last_name,
                    'full_name' => $full_name,
                    'email' => $email,
                ],
                'user' => [
                    'id' => $application->user_id,
                    'name' => $user ? $user->display_name : __('Guest', 'job-posting-manager'),
                    'email' => $user ? $user->user_email : '',
                ],
                'date_of_registration' => $date_of_registration,
                'form_data' => $form_data, // Include all form data
            ];

            $export_data['applications'][] = $app_data;
        }

        // Output JSON
        echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Handle import requests
     */
    public function handle_import()
    {
        // Check if import is requested
        if (!isset($_POST['jpm_import_action']) || sanitize_text_field(wp_unslash($_POST['jpm_import_action'])) !== 'import') {
            return;
        }

        // Check user capabilities (admin or editor)
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to import applications.', 'job-posting-manager'));
        }

        // Verify nonce
        if (!isset($_POST['jpm_import_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jpm_import_nonce'])), 'jpm_import_applications')) {
            wp_die(__('Security check failed. Please try again.', 'job-posting-manager'));
        }

        // Check if file was uploaded
        if (!isset($_FILES['jpm_import_file'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html__('No file was uploaded. Please select a file to import.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        $file = $_FILES['jpm_import_file'];

        // Check upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'job-posting-manager'),
                UPLOAD_ERR_FORM_SIZE => __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'job-posting-manager'),
                UPLOAD_ERR_PARTIAL => __('The uploaded file was only partially uploaded.', 'job-posting-manager'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'job-posting-manager'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'job-posting-manager'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'job-posting-manager'),
                UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'job-posting-manager'),
            ];

            $error_message = $error_messages[$file['error']] ?? __('Unknown upload error occurred.', 'job-posting-manager');

            add_action('admin_notices', function () use ($error_message) {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html($error_message) . '</p></div>';
            });
            return;
        }

        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB in bytes
        if ($file['size'] > $max_size) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html__('The uploaded file is too large. Maximum file size is 10MB.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        // Check if file is empty
        if ($file['size'] === 0) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html__('The uploaded file is empty.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        $format = isset($_POST['jpm_import_format']) ? sanitize_text_field(wp_unslash($_POST['jpm_import_format'])) : '';

        if (empty($format)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html__('Please select an import format (CSV or JSON).', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        if (!in_array($format, ['csv', 'json'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html__('Invalid import format selected. Please choose either CSV or JSON.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        // Check file extension matches format
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (($format === 'csv' && $file_ext !== 'csv') || ($format === 'json' && $file_ext !== 'json')) {
            add_action('admin_notices', function () use ($format, $file_ext) {
                $safe_file_ext = esc_html($file_ext);
                $safe_format_label = esc_html(strtoupper((string) $format));
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' .
                    /* translators: 1: Uploaded file extension, 2: selected import format. */
                    sprintf(__('File extension (.%s) does not match selected format (%s). Please select the correct format or upload a file with the matching extension.', 'job-posting-manager'), $safe_file_ext, $safe_format_label) .
                    '</p></div>';
            });
            return;
        }

        // Process import
        $result = null;
        try {
            if ($format === 'csv') {
                $result = $this->import_from_csv($file);
            } elseif ($format === 'json') {
                $result = $this->import_from_json($file);
            }
        } catch (Exception $e) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' .
                    /* translators: %s: Exception message. */
                    sprintf(__('An unexpected error occurred during import: %s', 'job-posting-manager'), esc_html($e->getMessage())) .
                    '</p></div>';
            });
            return;
        }

        // Show results
        if ($result) {
            $success_count = $result['success'] ?? 0;
            $error_count = $result['errors'] ?? 0;
            $errors = $result['error_messages'] ?? [];
            $total_processed = $success_count + $error_count;

            if ($total_processed === 0) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Import Warning:', 'job-posting-manager') . '</strong> ' .
                        esc_html__('No applications were found in the file to import.', 'job-posting-manager') .
                        '</p></div>';
                });
                return;
            }

            if ($success_count > 0) {
                add_action('admin_notices', function () use ($success_count, $total_processed) {
                    /* translators: 1: Number of successfully imported applications, 2: total processed applications. */
                    $message = sprintf(__('Successfully imported %d out of %d application(s).', 'job-posting-manager'), $success_count, $total_processed);
                    if ($success_count === $total_processed) {
                        /* translators: %d: Number of imported applications. */
                        $message = sprintf(__('Successfully imported all %d application(s).', 'job-posting-manager'), $success_count);
                    }
                    echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('Import Success:', 'job-posting-manager') . '</strong> ' . esc_html($message) . '</p></div>';
                });
            }

            if ($error_count > 0) {
                $error_message = '<strong>' . esc_html__('Import Errors:', 'job-posting-manager') . '</strong> ' .
                    /* translators: 1: Number of failed imports, 2: total processed applications. */
                    sprintf(__('Failed to import %d out of %d application(s).', 'job-posting-manager'), $error_count, $total_processed);

                if (!empty($errors)) {
                    $error_message .= '<br><br><strong>' . esc_html__('Error Details:', 'job-posting-manager') . '</strong>';
                    $error_message .= '<ul style="margin-left: 20px; margin-top: 10px;">';
                    foreach (array_slice($errors, 0, 20) as $error) { // Show first 20 errors
                        $error_message .= '<li>' . esc_html($error) . '</li>';
                    }
                    if (count($errors) > 20) {
                        /* translators: %d: Number of additional errors not shown. */
                        $error_message .= '<li><em>' . sprintf(__('... and %d more errors. Please check your file and try again.', 'job-posting-manager'), count($errors) - 20) . '</em></li>';
                    }
                    $error_message .= '</ul>';
                }

                add_action('admin_notices', function () use ($error_message) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($error_message) . '</p></div>';
                });
            }
        } else {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import Error:', 'job-posting-manager') . '</strong> ' .
                    esc_html__('Failed to process the import file. Please check the file format and try again.', 'job-posting-manager') .
                    '</p></div>';
            });
        }
    }

    /**
     * Import applications from CSV
     */
    private function import_from_csv($file)
    {
        $success_count = 0;
        $error_count = 0;
        $error_messages = [];

        // Read CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            $error_messages[] = __('Failed to open CSV file. The file may be corrupted or inaccessible.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Skip BOM if present
        $first_line = fgets($handle);
        rewind($handle);
        if (substr($first_line, 0, 3) === chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            fseek($handle, 3);
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            $error_messages[] = __('Failed to read CSV headers. The file may be empty or not in the correct CSV format.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Check for required headers
        $headers_lower = array_map('strtolower', array_map('trim', $headers));
        $required_headers = ['job id', 'job_id'];
        $has_job_id = false;
        foreach ($required_headers as $req_header) {
            if (in_array($req_header, $headers_lower)) {
                $has_job_id = true;
                break;
            }
        }

        if (!$has_job_id) {
            $error_messages[] = __('CSV file is missing required column: "Job ID" or "Job Id". Please ensure your CSV file matches the export format.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Map headers to lowercase for easier matching
        $headers = array_map('strtolower', $headers);
        $headers = array_map('trim', $headers);

        // Process rows
        $row_num = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            if (count($row) < count($headers)) {
                /* translators: 1: CSV row number, 2: expected column count, 3: actual column count. */
                $error_messages[] = sprintf(__('Row %d: Insufficient columns. Expected %d columns but found %d. Please check the CSV format.', 'job-posting-manager'), $row_num, count($headers), count($row));
                $error_count++;
                continue;
            }

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Map row data
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = isset($row[$index]) ? trim($row[$index]) : '';
            }

            // Import this application
            $result = $this->import_application($data, $row_num);
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
                $error_messages[] = $result['error'];
            }
        }

        return [
            'success' => $success_count,
            'errors' => $error_count,
            'error_messages' => $error_messages
        ];
    }

    /**
     * Import applications from JSON
     */
    private function import_from_json($file)
    {
        $success_count = 0;
        $error_count = 0;
        $error_messages = [];

        // Read JSON file
        $json_content = file_get_contents($file['tmp_name']);
        if ($json_content === false) {
            $error_messages[] = __('Failed to read JSON file. The file may be corrupted or inaccessible.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        if (empty(trim($json_content))) {
            $error_messages[] = __('JSON file is empty. Please ensure the file contains valid JSON data.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Decode JSON
        $data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_errors = [
                JSON_ERROR_DEPTH => __('Maximum stack depth exceeded.', 'job-posting-manager'),
                JSON_ERROR_STATE_MISMATCH => __('Underflow or the modes mismatch.', 'job-posting-manager'),
                JSON_ERROR_CTRL_CHAR => __('Unexpected control character found.', 'job-posting-manager'),
                JSON_ERROR_SYNTAX => __('Syntax error, malformed JSON.', 'job-posting-manager'),
                JSON_ERROR_UTF8 => __('Malformed UTF-8 characters, possibly incorrectly encoded.', 'job-posting-manager'),
            ];
            $error_detail = $json_errors[json_last_error()] ?? json_last_error_msg();
            /* translators: %s: JSON parsing error detail. */
            $error_messages[] = sprintf(__('Invalid JSON format: %s. Please ensure the file is valid JSON and matches the export format.', 'job-posting-manager'), $error_detail);
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Check if it's the export format
        if (!isset($data['applications'])) {
            $error_messages[] = __('Invalid JSON format: Missing "applications" key. Please ensure the file matches the export format.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        if (!is_array($data['applications'])) {
            $error_messages[] = __('Invalid JSON format: "applications" must be an array. Please ensure the file matches the export format.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        if (empty($data['applications'])) {
            $error_messages[] = __('JSON file contains no applications to import. The "applications" array is empty.', 'job-posting-manager');
            return ['success' => 0, 'errors' => 1, 'error_messages' => $error_messages];
        }

        // Process each application
        $index = 0;
        foreach ($data['applications'] as $app_data) {
            $index++;
            $result = $this->import_application_from_json($app_data, $index);
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
                $error_messages[] = $result['error'];
            }
        }

        return [
            'success' => $success_count,
            'errors' => $error_count,
            'error_messages' => $error_messages
        ];
    }

    /**
     * Import a single application from CSV data
     */
    private function import_application($data, $row_num = 0)
    {
        global $wpdb;

        // Get job ID (required)
        $job_id = 0;
        if (isset($data['job id']) && !empty($data['job id'])) {
            $job_id = absint($data['job id']);
        } elseif (isset($data['job_id']) && !empty($data['job_id'])) {
            $job_id = absint($data['job_id']);
        }

        if ($job_id <= 0) {
            $job_id_value = isset($data['job id']) ? $data['job id'] : (isset($data['job_id']) ? $data['job_id'] : '');
            return [
                'success' => false,
                /* translators: 1: CSV row number, 2: invalid Job ID value. */
                'error' => sprintf(__('Row %d: Missing or invalid Job ID. Found value: "%s". Job ID must be a positive number and the job must exist.', 'job-posting-manager'), $row_num, esc_html($job_id_value))
            ];
        }

        $job = get_post($job_id);
        if (!$job) {
            return [
                'success' => false,
                /* translators: 1: CSV row number, 2: Job ID value. */
                'error' => sprintf(__('Row %d: Job ID %d does not exist. Please ensure the job exists before importing applications.', 'job-posting-manager'), $row_num, $job_id)
            ];
        }

        // Get or create user
        $user_id = 0;
        $email = '';

        // Try to get email from various fields
        $email_fields = ['email', 'user email'];
        foreach ($email_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && is_email($data[$field])) {
                $email = sanitize_email($data[$field]);
                break;
            }
        }

        // Try to find existing user by email
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = $user->ID;
            } else {
                // Create new user if email provided
                $first_name = '';
                $last_name = '';

                if (isset($data['first name']) && !empty($data['first name'])) {
                    $first_name = sanitize_text_field($data['first name']);
                } elseif (isset($data['first_name']) && !empty($data['first_name'])) {
                    $first_name = sanitize_text_field($data['first_name']);
                }

                if (isset($data['last name']) && !empty($data['last name'])) {
                    $last_name = sanitize_text_field($data['last name']);
                } elseif (isset($data['last_name']) && !empty($data['last_name'])) {
                    $last_name = sanitize_text_field($data['last_name']);
                }

                if (!empty($first_name) && !empty($last_name)) {
                    $username = sanitize_user($email, true);
                    $original_username = $username;
                    $counter = 1;
                    while (username_exists($username)) {
                        $username = $original_username . $counter;
                        $counter++;
                    }

                    $password = wp_generate_password(32, false);
                    $user_id = wp_create_user($username, $password, $email);

                    if (!is_wp_error($user_id)) {
                        $user = new WP_User($user_id);
                        if (get_role('customer')) {
                            $user->set_role('customer');
                        } else {
                            $user->set_role('subscriber');
                        }

                        update_user_meta($user_id, 'first_name', $first_name);
                        update_user_meta($user_id, 'last_name', $last_name);
                        wp_update_user([
                            'ID' => $user_id,
                            'display_name' => trim($first_name . ' ' . $last_name),
                            'first_name' => $first_name,
                            'last_name' => $last_name
                        ]);
                    } else {
                        // Log user creation error but continue as guest
                        do_action('jpm_log_error', 'JPM Import: Failed to create user for email ' . $email . ' - ' . $user_id->get_error_message());
                        $user_id = 0; // Continue as guest if user creation fails
                    }
                }
            }
        }

        // Build form data (notes) from CSV data
        $form_data = [];
        $csv_field_mapping = [
            'application number' => 'application_number',
            'application_number' => 'application_number',
            'first name' => 'first_name',
            'first_name' => 'first_name',
            'middle name' => 'middle_name',
            'middle_name' => 'middle_name',
            'last name' => 'last_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'date of registration' => 'date_of_registration',
            'date_of_registration' => 'date_of_registration',
        ];

        foreach ($csv_field_mapping as $csv_field => $form_field) {
            if (isset($data[$csv_field]) && !empty($data[$csv_field])) {
                $form_data[$form_field] = sanitize_text_field($data[$csv_field]);
            }
        }

        // Get status
        $status = 'pending';
        if (isset($data['status']) && !empty($data['status'])) {
            $status_slug = sanitize_text_field($data['status']);
            // Check if status exists
            $status_options = self::get_status_options();
            if (isset($status_options[$status_slug])) {
                $status = $status_slug;
            } else {
                // Try to find by name
                foreach ($status_options as $slug => $name) {
                    if (strtolower($name) === strtolower($status_slug)) {
                        $status = $slug;
                        break;
                    }
                }
            }
        }

        // Get application date
        $application_date = current_time('mysql');
        if (isset($data['application date']) && !empty($data['application date'])) {
            $date_str = sanitize_text_field($data['application date']);
            $parsed_date = strtotime($date_str);
            if ($parsed_date !== false) {
                $application_date = date('Y-m-d H:i:s', $parsed_date);
            }
        }

        // Insert application
        $table = $this->get_validated_applications_table();
        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'job_id' => $job_id,
            'resume_file_path' => '',
            'notes' => json_encode($form_data, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'application_date' => $application_date,
        ]);

        if ($result === false) {
            $db_error = $wpdb->last_error;
            /* translators: %d: CSV row number. */
            $error_msg = sprintf(__('Row %d: Failed to insert application into database.', 'job-posting-manager'), $row_num);
            if (!empty($db_error)) {
                /* translators: %s: Database error message. */
                $error_msg .= ' ' . sprintf(__('Database error: %s', 'job-posting-manager'), $db_error);
            }
            return [
                'success' => false,
                'error' => $error_msg
            ];
        }

        return ['success' => true];
    }

    /**
     * Import a single application from JSON data
     */
    private function import_application_from_json($app_data, $index = 0)
    {
        global $wpdb;

        // Get job ID (required)
        $job_id = 0;
        if (isset($app_data['job']['id']) && !empty($app_data['job']['id'])) {
            $job_id = absint($app_data['job']['id']);
        } elseif (isset($app_data['job_id']) && !empty($app_data['job_id'])) {
            $job_id = absint($app_data['job_id']);
        }

        if ($job_id <= 0) {
            $job_id_value = isset($app_data['job']['id']) ? $app_data['job']['id'] : (isset($app_data['job_id']) ? $app_data['job_id'] : '');
            return [
                'success' => false,
                /* translators: 1: Application index, 2: invalid Job ID value. */
                'error' => sprintf(__('Application %d: Missing or invalid Job ID. Found value: "%s". Job ID must be a positive number and the job must exist.', 'job-posting-manager'), $index, esc_html($job_id_value))
            ];
        }

        $job = get_post($job_id);
        if (!$job) {
            return [
                'success' => false,
                /* translators: 1: Application index, 2: Job ID value. */
                'error' => sprintf(__('Application %d: Job ID %d does not exist. Please ensure the job exists before importing applications.', 'job-posting-manager'), $index, $job_id)
            ];
        }

        // Get or create user
        $user_id = 0;
        $email = '';

        // Get email from applicant or user data
        if (isset($app_data['applicant']['email']) && !empty($app_data['applicant']['email'])) {
            $email = sanitize_email($app_data['applicant']['email']);
        } elseif (isset($app_data['user']['email']) && !empty($app_data['user']['email'])) {
            $email = sanitize_email($app_data['user']['email']);
        }

        // Try to find existing user by email
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = $user->ID;
            } else {
                // Create new user if email and name provided
                $first_name = $app_data['applicant']['first_name'] ?? '';
                $last_name = $app_data['applicant']['last_name'] ?? '';

                if (!empty($first_name) && !empty($last_name)) {
                    $username = sanitize_user($email, true);
                    $original_username = $username;
                    $counter = 1;
                    while (username_exists($username)) {
                        $username = $original_username . $counter;
                        $counter++;
                    }

                    $password = wp_generate_password(32, false);
                    $user_id = wp_create_user($username, $password, $email);

                    if (!is_wp_error($user_id)) {
                        $user = new WP_User($user_id);
                        if (get_role('customer')) {
                            $user->set_role('customer');
                        } else {
                            $user->set_role('subscriber');
                        }

                        update_user_meta($user_id, 'first_name', $first_name);
                        update_user_meta($user_id, 'last_name', $last_name);
                        wp_update_user([
                            'ID' => $user_id,
                            'display_name' => trim($first_name . ' ' . $last_name),
                            'first_name' => $first_name,
                            'last_name' => $last_name
                        ]);
                    } else {
                        // Log user creation error but continue as guest
                        do_action('jpm_log_error', 'JPM Import: Failed to create user for email ' . $email . ' - ' . $user_id->get_error_message());
                        $user_id = 0; // Continue as guest if user creation fails
                    }
                }
            }
        }

        // Get form data
        $form_data = [];
        if (isset($app_data['form_data']) && is_array($app_data['form_data'])) {
            $form_data = $app_data['form_data'];
        } else {
            // Build from applicant data
            if (isset($app_data['applicant'])) {
                $applicant = $app_data['applicant'];
                if (isset($applicant['first_name']))
                    $form_data['first_name'] = $applicant['first_name'];
                if (isset($applicant['middle_name']))
                    $form_data['middle_name'] = $applicant['middle_name'];
                if (isset($applicant['last_name']))
                    $form_data['last_name'] = $applicant['last_name'];
                if (isset($applicant['email']))
                    $form_data['email'] = $applicant['email'];
            }
            if (isset($app_data['application_number'])) {
                $form_data['application_number'] = $app_data['application_number'];
            }
            if (isset($app_data['date_of_registration'])) {
                $form_data['date_of_registration'] = $app_data['date_of_registration'];
            }
        }

        // Get status
        $status = 'pending';
        if (isset($app_data['status_slug']) && !empty($app_data['status_slug'])) {
            $status_slug = sanitize_text_field($app_data['status_slug']);
            $status_options = self::get_status_options();
            if (isset($status_options[$status_slug])) {
                $status = $status_slug;
            }
        } elseif (isset($app_data['status']) && !empty($app_data['status'])) {
            $status_name = sanitize_text_field($app_data['status']);
            $status_options = self::get_status_options();
            foreach ($status_options as $slug => $name) {
                if (strtolower($name) === strtolower($status_name)) {
                    $status = $slug;
                    break;
                }
            }
        }

        // Get application date
        $application_date = current_time('mysql');
        if (isset($app_data['application_date']) && !empty($app_data['application_date'])) {
            $date_str = sanitize_text_field($app_data['application_date']);
            $parsed_date = strtotime($date_str);
            if ($parsed_date !== false) {
                $application_date = date('Y-m-d H:i:s', $parsed_date);
            }
        }

        // Insert application
        $table = $this->get_validated_applications_table();
        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'job_id' => $job_id,
            'resume_file_path' => '',
            'notes' => json_encode($form_data, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'application_date' => $application_date,
        ]);

        if ($result === false) {
            $db_error = $wpdb->last_error;
            /* translators: %d: Application index in the import payload. */
            $error_msg = sprintf(__('Application %d: Failed to insert application into database.', 'job-posting-manager'), $index);
            if (!empty($db_error)) {
                /* translators: %s: Database error message. */
                $error_msg .= ' ' . sprintf(__('Database error: %s', 'job-posting-manager'), $db_error);
            }
            return [
                'success' => false,
                'error' => $error_msg
            ];
        }

        return ['success' => true];
    }

    /**
     * Handle print action early to prevent admin template loading
     */
    public function handle_print()
    {
        // Check if print is requested
        if (
            !isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'jpm-applications' ||
            !isset($_GET['action']) || sanitize_text_field(wp_unslash($_GET['action'])) !== 'print' ||
            !isset($_GET['application_id'])
        ) {
            return;
        }

        // Prevent WordPress admin from loading - must be defined before admin template loads
        if (!defined('IFRAME_REQUEST')) {
            define('IFRAME_REQUEST', true);
        }

        // Capability first: print view shows applicant PII; only staff who can review applications.
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to view this page.', 'job-posting-manager'));
        }

        // Nonce is optional so email/bookmark links work. Notifications are built while the applicant
        // request may be logged out, so wp_nonce_url in mail would not verify for the admin user.
        // If a nonce is present (e.g. from the applications list), it must be valid.
        if (
            isset($_GET['jpm_print_nonce']) &&
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['jpm_print_nonce'])), 'jpm_print_application')
        ) {
            wp_die(__('Security check failed.', 'job-posting-manager'));
        }

        $application_id = isset($_GET['application_id']) ? absint(wp_unslash($_GET['application_id'])) : 0;

        if ($application_id <= 0) {
            wp_die(__('Invalid application ID.', 'job-posting-manager'));
        }

        global $wpdb;
        $table = $this->get_validated_applications_table();
        $application = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $application_id));

        if (!$application) {
            wp_die(__('Application not found.', 'job-posting-manager'));
        }

        // Disable admin bar completely
        add_filter('show_admin_bar', '__return_false');
        add_action('admin_head', function () {
            echo '<style>#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap { display: none !important; }</style>';
        }, 999);

        // Remove all admin menu and header actions
        remove_all_actions('admin_head');
        remove_all_actions('admin_footer');
        remove_all_actions('admin_notices');

        // Output print page and exit immediately - this prevents WordPress from loading admin template
        $this->print_application_page($application, $application_id);
        exit;
    }

    /**
     * Print application page
     */
    private function print_application_page($application, $application_id)
    {

        // Get job details
        $job = get_post($application->job_id);
        $user = $application->user_id > 0 ? get_userdata($application->user_id) : null;
        $form_data = json_decode($application->notes, true);

        if (!is_array($form_data)) {
            $form_data = [];
        }

        // Extract customer information
        $first_name = '';
        $middle_name = '';
        $last_name = '';
        $email = '';
        $application_number = '';
        $date_of_registration = '';

        // First name variations
        $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
        foreach ($first_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $first_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // Middle name variations
        $middle_name_fields = ['middle_name', 'middlename', 'mname', 'middle-name', 'middle name'];
        foreach ($middle_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $middle_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // Last name variations
        $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
        foreach ($last_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $last_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // Email variations
        $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];
        foreach ($email_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $email = sanitize_email($form_data[$field_name]);
                break;
            }
        }

        // Application number and date of registration
        if (isset($form_data['application_number'])) {
            $application_number = sanitize_text_field($form_data['application_number']);
        }
        if (isset($form_data['date_of_registration'])) {
            $date_of_registration = sanitize_text_field($form_data['date_of_registration']);
        }

        // Fallback to user data if not found in form data
        if (empty($first_name) && $user) {
            $first_name = $user->first_name;
        }
        if (empty($last_name) && $user) {
            $last_name = $user->last_name;
        }
        if (empty($email) && $user) {
            $email = $user->user_email;
        }

        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);

        // Get status information
        $status_info = self::get_status_by_slug($application->status);
        $status_name = $status_info ? $status_info['name'] : ucfirst($application->status);
        $status_color = $status_info ? $status_info['color'] : '#ffc107';
        $status_text_color = $status_info ? $status_info['text_color'] : '#000000';

        $medical_details = $this->get_application_medical_details($application_id);
        $medical_status_slug = $this->get_medical_status_slug();

        // Get rejection details if status is rejected
        $rejection_details = [];
        $rejected_status_slug = '';
        $all_statuses = self::get_all_statuses_info();
        foreach ($all_statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'rejected' || $name === 'rejected') {
                $rejected_status_slug = $status['slug'];
                break;
            }
        }

        if ($rejected_status_slug && $application->status === $rejected_status_slug) {
            $stored = get_option('jpm_application_rejection_details_' . $application_id, []);
            if (is_array($stored) && !empty($stored)) {
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
        }

        // Print page - standalone HTML without WordPress admin
        // Send headers to prevent caching
        nocache_headers(); ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>

        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php /* translators: %d: Application ID. */ ?>
            <title><?php printf(__('Application #%d - Print', 'job-posting-manager'), absint($application_id)); ?></title>
            <style>
                /* Hide all WordPress admin elements */
                #wpadminbar,
                #adminmenumain,
                #adminmenuback,
                #adminmenuwrap,
                #wpcontent,
                #wpfooter,
                .wp-core-ui,
                .wp-admin,
                body.wp-admin,
                body.admin-bar {
                    display: none !important;
                    visibility: hidden !important;
                    height: 0 !important;
                    width: 0 !important;
                    overflow: hidden !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                html,
                body {
                    width: 100% !important;
                    height: 100% !important;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
                    font-size: 10pt !important;
                    line-height: 1.5 !important;
                    color: #2c3e50 !important;
                    background: #fff !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    border: 0 !important;
                    overflow: visible !important;
                }

                /* Remove all top spacing */
                body>* {
                    margin-top: 0 !important;
                    padding-top: 0 !important;
                }

                /* Ensure print container starts at top */
                body>.print-container {
                    margin-top: 0 !important;
                    padding-top: 0 !important;
                }

                body>.print-container>.print-header {
                    margin-top: 0 !important;
                    padding-top: 0 !important;
                }

                @media print {

                    html,
                    body {
                        padding: 0 !important;
                        margin: 0 !important;
                        border: 0 !important;
                    }

                    body>* {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                    }

                    body>.print-container {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                    }

                    body>.print-container>.print-header {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                        margin-bottom: 15px !important;
                    }

                    body>.print-container>.print-header>h1 {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                    }

                    .no-print {
                        display: none !important;
                    }

                    @page {
                        size: A4;
                        margin: 0.3cm 0.5cm 0.3cm 0.5cm;
                    }

                    .section {
                        page-break-inside: auto;
                        margin-bottom: 4px !important;
                        padding-bottom: 0 !important;
                    }

                    .print-header {
                        page-break-after: avoid;
                        margin-top: 0 !important;
                        margin-bottom: 5px !important;
                        padding-top: 0 !important;
                        padding-bottom: 3px !important;
                        border-bottom-width: 1px !important;
                    }

                    .print-header h1 {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                        margin-bottom: 2px !important;
                        font-size: 16pt !important;
                        line-height: 1.0 !important;
                    }

                    .print-header .subtitle {
                        font-size: 9pt !important;
                        margin-bottom: 1px !important;
                        line-height: 1.2 !important;
                    }

                    .print-header .company-info {
                        font-size: 8pt !important;
                        margin-top: 2px !important;
                        line-height: 1.2 !important;
                    }

                    .print-container {
                        padding: 0 5px 5px 5px !important;
                        padding-top: 0 !important;
                        margin-top: 0 !important;
                        max-width: 100%;
                    }

                    .section-title {
                        font-size: 9.5pt !important;
                        margin-bottom: 3px !important;
                        padding-bottom: 2px !important;
                        border-bottom-width: 1px !important;
                        line-height: 1.2 !important;
                    }

                    .info-grid {
                        margin: 0 !important;
                        border-width: 1px !important;
                    }

                    .info-row {
                        page-break-inside: avoid;
                    }

                    .info-row:last-child {
                        border-bottom: none !important;
                    }

                    .info-label {
                        padding: 4px 8px !important;
                        font-size: 8.5pt !important;
                        line-height: 1.3 !important;
                        width: 32% !important;
                    }

                    .info-value {
                        padding: 4px 8px !important;
                        font-size: 9pt !important;
                        line-height: 1.4 !important;
                    }

                    .divider {
                        margin: 3px 0 !important;
                        border-top-width: 0.5px !important;
                    }

                    .form-field {
                        page-break-inside: avoid;
                        margin-bottom: 6px !important;
                        padding: 6px 8px !important;
                    }

                    .form-data-section {
                        margin-top: 4px !important;
                    }

                    .footer {
                        margin-top: 10px !important;
                        padding-top: 5px !important;
                        border-top-width: 0.5px !important;
                        font-size: 7.5pt !important;
                        line-height: 1.3 !important;
                    }

                    /* Reduce line heights for compact printing */
                    .info-value ul {
                        margin: 2px 0 !important;
                        padding-left: 15px !important;
                    }

                    .info-value li {
                        margin-bottom: 1px !important;
                        line-height: 1.3 !important;
                    }

                    /* Optimize long text boxes */
                    .info-value div[style*="max-height"] {
                        max-height: 150px !important;
                        padding: 5px !important;
                        font-size: 8.5pt !important;
                        line-height: 1.3 !important;
                    }

                    /* Reduce overall body font size for print */
                    body {
                        font-size: 9pt !important;
                        line-height: 1.3 !important;
                    }

                    /* Optimize status badge for print */
                    .status-badge {
                        padding: 3px 10px !important;
                        font-size: 8pt !important;
                        margin: 0 !important;
                        line-height: 1.2 !important;
                    }

                    /* Better page break control - allow grids to break but keep rows together */
                    .info-grid {
                        page-break-inside: auto;
                    }

                    .info-row {
                        page-break-inside: avoid;
                        page-break-after: auto;
                        break-inside: avoid;
                    }

                    /* Prevent orphaned section titles */
                    .section-title {
                        page-break-after: avoid;
                        orphans: 3;
                        widows: 3;
                    }

                    /* Optimize table cell spacing */
                    .info-label,
                    .info-value {
                        page-break-inside: avoid;
                    }

                    /* MAXIMUM DENSITY - Reduce all spacing to absolute minimum */
                    .section+.section {
                        margin-top: 4px !important;
                    }

                    .section+.divider {
                        margin-top: 4px !important;
                        margin-bottom: 4px !important;
                    }

                    /* Remove spacing after section titles */
                    .section-title+.info-grid {
                        margin-top: 0 !important;
                    }

                    /* Ultra-compact table borders */
                    .info-grid {
                        border-width: 0.5px !important;
                    }

                    .info-row {
                        border-bottom-width: 0.5px !important;
                    }

                    .info-label {
                        border-right-width: 0.5px !important;
                    }

                    /* Ultra-compact cell padding - MAXIMUM DENSITY */
                    .info-label,
                    .info-value {
                        padding: 3px 6px !important;
                    }

                    /* Minimal section spacing */
                    .section {
                        margin-top: 0 !important;
                        margin-bottom: 4px !important;
                    }

                    .section-title {
                        margin-top: 0 !important;
                        margin-bottom: 3px !important;
                    }

                    .divider {
                        margin: 3px 0 !important;
                    }

                    .footer {
                        margin-top: 6px !important;
                        padding-top: 2px !important;
                    }

                    /* Remove all top margins from text elements */
                    p,
                    div,
                    span,
                    h1,
                    h2,
                    h3,
                    h4,
                    h5,
                    h6 {
                        margin-top: 0 !important;
                    }

                    /* Compact header - no spacing */
                    .print-header h1,
                    .print-header .subtitle,
                    .print-header .company-info {
                        margin-top: 0 !important;
                    }
                }

                .print-container {
                    max-width: 210mm;
                    margin: 0 auto !important;
                    padding: 0 20px 20px 20px !important;
                    padding-top: 0 !important;
                    margin-top: 0 !important;
                    background: #fff;
                }

                .print-actions {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 1000;
                    background: #fff;
                    padding: 15px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }

                .print-actions button {
                    padding: 10px 20px;
                    font-size: 14px;
                    background: #2c3e50;
                    color: #fff;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    margin: 0 5px;
                    font-weight: 500;
                    transition: background 0.3s;
                }

                .print-actions button:hover {
                    background: #34495e;
                }

                .print-actions button.print-btn {
                    background: #27ae60;
                }

                .print-actions button.print-btn:hover {
                    background: #229954;
                }

                .print-header {
                    text-align: center;
                    border-bottom: 2px solid #2c3e50;
                    padding-bottom: 15px;
                    padding-top: 0 !important;
                    margin-top: 0 !important;
                    margin-bottom: 25px;
                    position: relative;
                }

                .print-header::after {
                    content: '';
                    position: absolute;
                    bottom: -3px;
                    left: 50%;
                    transform: translateX(-50%);
                    width: 120px;
                    height: 3px;
                    background: #3498db;
                }

                .print-header h1 {
                    font-size: 24pt;
                    margin-top: 0 !important;
                    margin-bottom: 6px;
                    padding-top: 0 !important;
                    color: #2c3e50;
                    font-weight: 700;
                    letter-spacing: -0.3px;
                    line-height: 1.1;
                }

                .print-header .subtitle {
                    font-size: 12pt;
                    color: #7f8c8d;
                    font-weight: 400;
                    margin-bottom: 5px;
                }

                .print-header .company-info {
                    margin-top: 8px;
                    font-size: 10pt;
                    color: #95a5a6;
                    font-weight: 500;
                }

                .section {
                    margin-bottom: 25px;
                    page-break-inside: avoid;
                }

                .section-title {
                    font-size: 13pt;
                    font-weight: 700;
                    color: #2c3e50;
                    border-bottom: 2px solid #3498db;
                    padding-bottom: 8px;
                    margin-bottom: 15px;
                    text-transform: uppercase;
                    letter-spacing: 0.6px;
                    line-height: 1.3;
                }

                .info-grid {
                    display: table;
                    width: 100%;
                    border-collapse: collapse;
                    border: 1px solid #e0e0e0;
                    border-radius: 4px;
                    overflow: hidden;
                }

                .info-row {
                    display: table-row;
                    border-bottom: 1px solid #e8e8e8;
                }

                .info-row:last-child {
                    border-bottom: none;
                }

                .info-label {
                    display: table-cell;
                    width: 35%;
                    padding: 10px 14px;
                    font-weight: 600;
                    color: #34495e;
                    background: #f5f7fa;
                    vertical-align: middle;
                    border-right: 1px solid #e8e8e8;
                    font-size: 10pt;
                    line-height: 1.4;
                }

                .info-value {
                    display: table-cell;
                    padding: 10px 14px;
                    color: #2c3e50;
                    vertical-align: middle;
                    font-size: 10.5pt;
                    line-height: 1.5;
                }

                .status-badge {
                    display: inline-block;
                    padding: 7px 20px;
                    border-radius: 20px;
                    font-weight: 600;
                    font-size: 10pt;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
                    line-height: 1.2;
                }

                .form-data-section {
                    margin-top: 20px;
                }

                .form-field {
                    margin-bottom: 18px;
                    padding: 18px 20px;
                    background: #f8f9fa;
                    border-left: 4px solid #3498db;
                    border-radius: 4px;
                    transition: box-shadow 0.3s;
                    page-break-inside: avoid;
                }

                .form-field:hover {
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
                }

                .form-field-label {
                    font-weight: 600;
                    color: #34495e;
                    margin-bottom: 10px;
                    display: block;
                    font-size: 10.5pt;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
                    line-height: 1.3;
                }

                .form-field-value {
                    color: #2c3e50;
                    font-size: 11pt;
                    line-height: 1.7;
                    word-wrap: break-word;
                }

                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #e0e0e0;
                    text-align: center;
                    font-size: 9pt;
                    color: #95a5a6;
                    line-height: 1.5;
                }

                .divider {
                    height: 0;
                    border: none;
                    border-top: 1px solid #e0e0e0;
                    margin: 20px 0;
                }

                @media screen {
                    body {
                        background: #f5f6fa;
                        padding: 20px;
                        min-height: 100vh;
                    }

                    .print-container {
                        background: #fff;
                        box-shadow: 0 0 25px rgba(0, 0, 0, 0.08);
                        border-radius: 8px;
                        padding: 40px 50px;
                        margin-bottom: 30px;
                    }
                }

                /* Remove extra whitespace */
                .print-container>*:first-child {
                    margin-top: 0;
                }

                .print-container>*:last-child {
                    margin-bottom: 0;
                }

                /* Better spacing for nested elements */
                .section>*:first-child {
                    margin-top: 0;
                }

                .section>*:last-child {
                    margin-bottom: 0;
                }
            </style>
        </head>

        <body style="margin: 0 !important; padding: 0 !important; border: 0 !important;">
            <div class="print-actions no-print">
                <button class="print-btn" onclick="window.print()"><?php esc_html_e('Print', 'job-posting-manager'); ?></button>
                <button onclick="window.close()"><?php esc_html_e('Close', 'job-posting-manager'); ?></button>
            </div>

            <div class="print-container" style="margin-top: 0 !important; padding-top: 0 !important;">
                <div class="print-header" style="margin-top: 0 !important; padding-top: 0 !important;">
                    <h1><?php esc_html_e('Job Application', 'job-posting-manager'); ?></h1>
                    <?php /* translators: %d: Application ID. */ ?>
                    <div class="subtitle">
                        <?php printf(__('Application #%d', 'job-posting-manager'), absint($application_id)); ?>
                    </div>
                    <?php if (get_bloginfo('name')): ?>
                        <div class="company-info"><?php echo esc_html(get_bloginfo('name')); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Application Information -->
                <div class="section">
                    <div class="section-title"><?php esc_html_e('Application Information', 'job-posting-manager'); ?></div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label"><?php esc_html_e('Application ID', 'job-posting-manager'); ?></div>
                            <div class="info-value"><strong
                                    style="color: #2c3e50; font-size: 11.5pt;">#<?php echo esc_html($application_id); ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($application_number)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('Application Number', 'job-posting-manager'); ?></div>
                                <div class="info-value" style="font-weight: 500;"><?php echo esc_html($application_number); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <div class="info-label"><?php esc_html_e('Application Date', 'job-posting-manager'); ?></div>
                            <div class="info-value">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-label"><?php esc_html_e('Status', 'job-posting-manager'); ?></div>
                            <div class="info-value">
                                <span class="status-badge"
                                    style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                                    <?php echo esc_html($status_name); ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($date_of_registration)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('Date of Registration', 'job-posting-manager'); ?></div>
                                <div class="info-value"><?php echo esc_html($date_of_registration); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                $has_medical_details = $medical_status_slug && ($application->status === $medical_status_slug) && (trim(implode('', $medical_details)) !== '');
                if ($has_medical_details):
                    ?>
                    <div class="divider"></div>
                    <div class="section">
                        <div class="section-title"><?php esc_html_e('Medical Requirements & Schedule', 'job-posting-manager'); ?>
                        </div>
                        <div class="info-grid">
                            <?php if (!empty($medical_details['requirements'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Requirements', 'job-posting-manager'); ?></div>
                                    <div class="info-value">
                                        <?php echo nl2br(wp_kses_post($medical_details['requirements'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($medical_details['address'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Address', 'job-posting-manager'); ?></div>
                                    <div class="info-value"><?php echo esc_html($medical_details['address']); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($medical_details['date']) || !empty($medical_details['time'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Schedule', 'job-posting-manager'); ?></div>
                                    <div class="info-value">
                                        <?php if (!empty($medical_details['date'])): ?>
                                            <div><?php esc_html_e('Date:', 'job-posting-manager'); ?>
                                                <strong><?php echo esc_html($this->format_medical_date($medical_details['date'])); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($medical_details['time'])): ?>
                                            <div><?php esc_html_e('Time:', 'job-posting-manager'); ?>
                                                <strong><?php echo esc_html($medical_details['time']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($medical_details['updated_at'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Last Updated', 'job-posting-manager'); ?></div>
                                    <div class="info-value">
                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($medical_details['updated_at']))); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                $has_rejection_details = $rejected_status_slug && ($application->status === $rejected_status_slug) && !empty($rejection_details['notes']);
                if ($has_rejection_details):
                    ?>
                    <div class="divider"></div>
                    <div class="section">
                        <div class="section-title"><?php esc_html_e('Rejection Details', 'job-posting-manager'); ?></div>
                        <div class="info-grid">
                            <?php if (!empty($rejection_details['problem_area_label'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('The problem is in the:', 'job-posting-manager'); ?></div>
                                    <div class="info-value"><?php echo esc_html($rejection_details['problem_area_label']); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($rejection_details['notes'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Notes', 'job-posting-manager'); ?></div>
                                    <div class="info-value">
                                        <?php echo nl2br(wp_kses_post($rejection_details['notes'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($rejection_details['updated_at'])): ?>
                                <div class="info-row">
                                    <div class="info-label"><?php esc_html_e('Last Updated', 'job-posting-manager'); ?></div>
                                    <div class="info-value">
                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($rejection_details['updated_at']))); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="divider"></div>

                <!-- Job Information -->
                <div class="section">
                    <div class="section-title"><?php esc_html_e('Job Information', 'job-posting-manager'); ?></div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label"><?php esc_html_e('Job Title', 'job-posting-manager'); ?></div>
                            <div class="info-value"><strong style="color: #2c3e50; font-size: 11.5pt;">
                                    <?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                </strong></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">
                                <?php esc_html_e('Job ID', 'job-posting-manager'); ?>
                            </div>
                            <div class="info-value">#
                                <?php echo esc_html($application->job_id); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Applicant Information -->
                <div class="section">
                    <div class="section-title">
                        <?php esc_html_e('Applicant Information', 'job-posting-manager'); ?>
                    </div>
                    <div class="info-grid">
                        <?php if (!empty($full_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('Full Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value"><strong style="color: #2c3e50; font-size: 11.5pt;">
                                        <?php echo esc_html($full_name); ?>
                                    </strong></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($first_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('First Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($first_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($middle_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('Middle Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($middle_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($last_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php esc_html_e('Last Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($last_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($email)): ?>
                            <div class="info-row">
                                <div class="info-label">
                                    <?php esc_html_e('Email', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($email); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <div class="info-label">
                                <?php esc_html_e('User Account', 'job-posting-manager'); ?>
                            </div>
                            <div class="info-value">
                                <?php if ($user): ?>
                                    <?php echo esc_html($user->display_name); ?> <span style="color: #95a5a6;">(ID:
                                        <?php echo esc_html($user->ID); ?>)</span>
                                <?php else: ?>
                                    <em
                                        style="color: #95a5a6;"><?php esc_html_e('Guest Application', 'job-posting-manager'); ?></em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Education Section -->
                <?php
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

                if ($has_education):
                    ?>
                    <div class="section">
                        <div class="section-title"><?php esc_html_e('Education', 'job-posting-manager'); ?></div>
                        <div class="info-grid">
                            <!-- Primary Education -->
                            <?php if (!empty($form_data['edu_primary_school_name']) || !empty($form_data['edu_primary_school_address'])): ?>
                                <div class="info-row" style="grid-column: 1 / -1; margin-top: 15px;">
                                    <div class="info-label"
                                        style="font-weight: 700; color: #0073aa; font-size: 11pt; margin-bottom: 10px;">
                                        <?php esc_html_e('Primary Education', 'job-posting-manager'); ?>
                                    </div>
                                </div>
                                <?php if (!empty($form_data['edu_primary_school_name'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Name', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_primary_school_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_primary_school_address'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Address', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_primary_school_address']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_primary_start_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Start Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_primary_start_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_primary_end_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('End Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_primary_end_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_primary_completed'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Completed', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_primary_completed']); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- Secondary Education -->
                            <?php if (!empty($form_data['edu_secondary_school_name']) || !empty($form_data['edu_secondary_school_address'])): ?>
                                <div class="info-row"
                                    style="grid-column: 1 / -1; margin-top: 15px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                                    <div class="info-label"
                                        style="font-weight: 700; color: #0073aa; font-size: 11pt; margin-bottom: 10px;">
                                        <?php esc_html_e('Secondary Education', 'job-posting-manager'); ?>
                                    </div>
                                </div>
                                <?php if (!empty($form_data['edu_secondary_school_name'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Name', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_school_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_secondary_school_address'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Address', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_school_address']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_secondary_school_type'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Type', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_school_type']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_secondary_start_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Start Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_start_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_secondary_end_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('End Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_end_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_secondary_completed'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Completed', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_secondary_completed']); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- Tertiary Education -->
                            <?php if (!empty($form_data['edu_tertiary_institution_name']) || !empty($form_data['edu_tertiary_school_address'])): ?>
                                <div class="info-row"
                                    style="grid-column: 1 / -1; margin-top: 15px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                                    <div class="info-label"
                                        style="font-weight: 700; color: #0073aa; font-size: 11pt; margin-bottom: 10px;">
                                        <?php esc_html_e('Tertiary Education', 'job-posting-manager'); ?>
                                    </div>
                                </div>
                                <?php if (!empty($form_data['edu_tertiary_institution_name'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Institution Name', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_institution_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_school_address'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('School Address', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_school_address']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_program'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Program / Course', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_program']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_degree_level'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Degree Level', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_degree_level']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_start_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Start Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_start_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_end_year'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('End Year', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_end_year']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($form_data['edu_tertiary_status'])): ?>
                                    <div class="info-row">
                                        <div class="info-label"><?php esc_html_e('Status', 'job-posting-manager'); ?></div>
                                        <div class="info-value"><?php echo esc_html($form_data['edu_tertiary_status']); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="divider"></div>
                <?php endif; ?>

                <!-- Employment Section -->
                <?php
                $has_employment = false;
                if (
                    (isset($form_data['emp_company_name']) && !empty($form_data['emp_company_name'])) ||
                    (isset($form_data['emp_position']) && !empty($form_data['emp_position'])) ||
                    (isset($form_data['emp_years']) && !empty($form_data['emp_years'])) ||
                    (isset($form_data['employment_entries']) && !empty($form_data['employment_entries']))
                ) {
                    $has_employment = true;
                }

                if ($has_employment):
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
                    ?>
                    <div class="section">
                        <div class="section-title"><?php esc_html_e('Employment History', 'job-posting-manager'); ?></div>
                        <div class="info-grid">
                            <?php if (!empty($employment_entries)): ?>
                                <?php foreach ($employment_entries as $index => $entry): ?>
                                    <?php if ($index > 0): ?>
                                        <div class="info-row"
                                            style="grid-column: 1 / -1; margin-top: 15px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                                        </div>
                                    <?php endif; ?>
                                    <div class="info-row"
                                        style="grid-column: 1 / -1; margin-top: <?php echo esc_attr($index > 0 ? '15px' : '0'); ?>;">
                                        <div class="info-label"
                                            style="font-weight: 700; color: #0073aa; font-size: 11pt; margin-bottom: 10px;">
                                            <?php /* translators: %d: Employment entry number. */ ?>
                                            <?php printf(__('Employment #%d', 'job-posting-manager'), absint($index + 1)); ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($entry['company_name'])): ?>
                                        <div class="info-row">
                                            <div class="info-label"><?php esc_html_e('Company Name', 'job-posting-manager'); ?></div>
                                            <div class="info-value"><?php echo esc_html($entry['company_name']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['position'])): ?>
                                        <div class="info-row">
                                            <div class="info-label"><?php esc_html_e('Position', 'job-posting-manager'); ?></div>
                                            <div class="info-value"><?php echo esc_html($entry['position']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['years'])): ?>
                                        <div class="info-row">
                                            <div class="info-label"><?php esc_html_e('Years', 'job-posting-manager'); ?></div>
                                            <div class="info-value"><?php echo esc_html($entry['years']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="divider"></div>
                <?php endif; ?>

                <!-- Application Form Data -->
                <?php if (!empty($form_data)): ?>
                    <div class="section form-data-section">
                        <div class="section-title">
                            <?php esc_html_e('Application Form Data', 'job-posting-manager'); ?>
                        </div>
                        <?php
                        // Exclude internal fields from display
                        $excluded_fields = [
                            'application_number',
                            'date_of_registration',
                            'applicant_number',
                            // Exclude education and employment fields as they are shown in dedicated sections above
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

                        // Skip if already displayed in applicant information
                        $skip_fields = ['firstname', 'fname', 'givenname', 'given', 'middlename', 'mname', 'middle', 'lastname', 'lname', 'surname', 'familyname', 'family', 'email'];

                        // Collect valid form fields
                        $valid_fields = [];
                        foreach ($form_data as $field_name => $field_value):
                            if (in_array($field_name, $excluded_fields)) {
                                continue;
                            }

                            $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                            if (in_array($field_name_lower, $skip_fields)) {
                                continue;
                            }

                            if (empty($field_value)) {
                                continue;
                            }

                            $field_label = ucwords(str_replace(['_', '-'], ' ', $field_name));
                            $valid_fields[$field_label] = $field_value;
                        endforeach;

                        if (!empty($valid_fields)):
                            ?>
                            <div class="info-grid">
                                <?php foreach ($valid_fields as $field_label => $field_value): ?>
                                    <div class="info-row">
                                        <div class="info-label">
                                            <?php echo esc_html($field_label); ?>
                                        </div>
                                        <div class="info-value">
                                            <?php
                                            if (is_array($field_value)) {
                                                // Handle array values (e.g., checkboxes, multiple selections)
                                                echo '<ul style="margin: 0; padding-left: 20px; list-style-type: disc;">';
                                                foreach ($field_value as $item) {
                                                    echo '<li style="margin-bottom: 5px;">' . esc_html($item) . '</li>';
                                                }
                                                echo '</ul>';
                                            } elseif (
                                                filter_var($field_value, FILTER_VALIDATE_URL) &&
                                                (strpos($field_value, '.pdf') !== false ||
                                                    strpos($field_value, '.doc') !== false ||
                                                    strpos($field_value, '.docx') !== false ||
                                                    strpos($field_value, '.jpg') !== false ||
                                                    strpos($field_value, '.png') !== false ||
                                                    strpos($field_value, '.jpeg') !== false)
                                            ) {
                                                // Handle file URLs
                                                $file_name = basename($field_value);
                                                echo '<a href="' . esc_url($field_value) . '" target="_blank" style="color: #3498db; text-decoration: none; font-weight: 600;">';
                                                echo '<span style="margin-right: 8px;">[file]</span>';
                                                echo esc_html($file_name);
                                                echo ' <span style="font-size: 9pt; color: #7f8c8d;">(' . esc_html__('Click to download', 'job-posting-manager') . ')</span>';
                                                echo '</a>';
                                            } elseif (filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
                                                // Handle email addresses
                                                echo '<a href="mailto:' . esc_attr($field_value) . '" style="color: #3498db; text-decoration: none;">' . esc_html($field_value) . '</a>';
                                            } elseif (filter_var($field_value, FILTER_VALIDATE_URL)) {
                                                // Handle URLs
                                                echo '<a href="' . esc_url($field_value) . '" target="_blank" style="color: #3498db; text-decoration: none;">' . esc_html($field_value) . '</a>';
                                            } elseif (strlen($field_value) > 200) {
                                                // Handle long text - preserve line breaks and format nicely
                                                $formatted_value = nl2br(esc_html($field_value));
                                                echo '<div style="max-height: 300px; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #e0e0e0;">' . wp_kses_post($formatted_value) . '</div>';
                                            } else {
                                                // Regular text - preserve line breaks
                                                $formatted_value = nl2br(esc_html($field_value));
                                                echo wp_kses_post($formatted_value);
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #95a5a6; font-style: italic; padding: 20px; text-align: center;">
                                <?php esc_html_e('No additional form data available.', 'job-posting-manager'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <?php /* translators: 1: Printed date/time, 2: Site name. */ ?>
                    <p><?php printf(__('Printed on %1$s from %2$s', 'job-posting-manager'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'))), esc_html(get_bloginfo('name'))); ?>
                    </p>
                </div>
            </div>

            <script>         // Auto-print w         hen         page loads (optional)         // window.onload = function() { window.print(); };
            </script>
        </body>

        </html>
        <?php
    }
}



