<?php
class JPM_Frontend {
    public function __construct() {
        add_shortcode('job_listings', [$this, 'job_listings_shortcode']);
        add_shortcode('user_applications', [$this, 'user_applications_shortcode']);
        add_action('wp_ajax_jpm_apply', [$this, 'handle_application']);
        add_action('wp_ajax_nopriv_jpm_apply', [$this, 'handle_application']); // But redirect if not logged in
        add_action('wp_ajax_jpm_get_status', [$this, 'get_status']);
    }

    public function job_listings_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view jobs.', 'job-posting-manager') . '</p>';
        }
        // Query active jobs, display with filters
        ob_start();
        // Loop through jobs, show apply button
        return ob_get_clean();
    }

    public function user_applications_shortcode() {
        if (!is_user_logged_in()) return '';
        $user_id = get_current_user_id();
        $applications = JPM_DB::get_applications(['user_id' => $user_id]);
        // Display table with AJAX polling for status
        echo '<div id="jpm-status-updates"></div>';
    }

    public function handle_application() {
        check_ajax_referer('jpm_nonce');
        if (!is_user_logged_in()) wp_die(__('Please log in.', 'job-posting-manager'));
        $job_id = intval($_POST['job_id']);
        $cover_letter = sanitize_textarea_field($_POST['cover_letter']);
        // Handle file upload
        $upload = wp_handle_upload($_FILES['resume'], ['upload_error_handler' => 'jpm_upload_error']);
        if (isset($upload['error'])) wp_die($upload['error']);
        $result = JPM_DB::insert_application(get_current_user_id(), $job_id, $upload['file'], $cover_letter);
        if (is_wp_error($result)) wp_die($result->get_error_message());
        JPM_Emails::send_confirmation($result); // Pass insert ID
        wp_die(__('Application submitted. ID: ' . $result, 'job-posting-manager'));
    }

    public function get_status() {
        $applications = JPM_DB::get_applications(['user_id' => get_current_user_id()]);
        wp_send_json($applications);
    }
}