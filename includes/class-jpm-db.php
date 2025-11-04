<?php
class JPM_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_job_meta']);
        add_action('wp_ajax_jpm_bulk_update', [$this, 'bulk_update']);
    }

    public function add_menu()
    {
        add_menu_page(__('Job Manager', 'job-posting-manager'), __('Job Manager', 'job-posting-manager'), 'manage_options', 'jpm-dashboard', [$this, 'dashboard_page'], 'dashicons-businessman');
        add_submenu_page('jpm-dashboard', __('Applications', 'job-posting-manager'), __('Applications', 'job-posting-manager'), 'manage_options', 'jpm-applications', [$this, 'applications_page']);
    }

    public function dashboard_page()
    {
        // Form for creating/editing jobs (use standard WP post editor)
        echo '<h1>' . __('Manage Job Postings', 'job-posting-manager') . '</h1>';
        // Include job form here (similar to post-new.php)
    }

    public function applications_page()
    {
        $applications = JPM_DB::get_applications($_GET);
        echo '<h1>' . __('Applications', 'job-posting-manager') . '</h1>';
        // Display table with filters, bulk actions
        echo '<form method="post">';
        wp_nonce_field('jpm_bulk_nonce');
        // Table code here (use WP_List_Table for better UX)
        echo '<input type="submit" name="bulk_update" value="' . __('Update Status', 'job-posting-manager') . '">';
        echo '</form>';
    }

    public function add_meta_boxes()
    {

        add_meta_box('jpm_job_details', __('Job Details', 'job-posting-manager'), [$this, 'job_meta_box'], 'job_posting');
    }

    public function job_meta_box($post)         
    {
        // Add nonce field for validation when saving the post
        wp_nonce_field('jpm_job_meta', 'jpm_job_nonce');

        // Output form fields (ensure these are inside the form)
        ?>
<label label for="location"><?php _e('Location', 'job-posting-manager'); ?></label>
<input type="text" id="location" name="location"
    value="<?php echo esc_attr(get_post_meta($post->ID, 'location', true)); ?>" />

<label for="salary"><?php _e('Salary', 'job-posting-manager'); ?></label>
<input type="text" id="salary" name="salary"
    value="<?php echo esc_attr(get_post_meta($post->ID, 'salary', true)); ?>" />

<!-- Add other fields as needed -->
<?php
    }


  public function save_job_meta($post_id) {
    // Check if nonce is set and verify
    if (isset($_POST['jpm_job_nonce']) && wp_verify_nonce($_POST['jpm_job_nonce'], 'jpm_job_meta')) {
        // Save job metadata
        if (isset($_POST['location'])) {
            update_post_meta($post_id, 'location', sanitize_text_field($_POST['location']));
        }
        // Save other fields like salary, deadline, etc.
    } else {
        // If nonce verification fails, do not save any data
        return;
    }
}


    public function bulk_update()
    {
        check_ajax_referer('jpm_nonce');
        if (!current_user_can('manage_options'))
            wp_die();
        foreach ($_POST['applications'] as $id) {
            JPM_DB::update_status($id, sanitize_text_field($_POST['status']));
            // Send email
            JPM_Emails::send_status_update($id);
        }
        wp_die(__('Updated', 'job-posting-manager'));
    }
}