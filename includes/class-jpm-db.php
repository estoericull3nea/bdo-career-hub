<?php
class JPM_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_job_meta']);
        add_action('wp_ajax_jpm_bulk_update', [$this, 'bulk_update']);
        add_filter('the_content', [$this, 'display_job_details'], 10);
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

        // Get saved values
        $company_name = get_post_meta($post->ID, 'company_name', true);
        $location = get_post_meta($post->ID, 'location', true);
        $salary = get_post_meta($post->ID, 'salary', true);
        $duration = get_post_meta($post->ID, 'duration', true);

        // Output form fields (ensure these are inside the form)
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="company_name"><?php _e('Company Name', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="company_name" name="company_name" class="regular-text"
                        value="<?php echo esc_attr($company_name); ?>"
                        placeholder="<?php esc_attr_e('e.g., Acme Corporation', 'job-posting-manager'); ?>" />
                    <p class="description"><?php _e('Optional: Company or organization name', 'job-posting-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="location"><?php _e('Location', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="location" name="location" class="regular-text"
                        value="<?php echo esc_attr($location); ?>"
                        placeholder="<?php esc_attr_e('e.g., New York, NY', 'job-posting-manager'); ?>" />
                    <p class="description"><?php _e('Optional: Job location', 'job-posting-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="salary"><?php _e('Salary', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="salary" name="salary" class="regular-text" value="<?php echo esc_attr($salary); ?>"
                        placeholder="<?php esc_attr_e('e.g., $50,000 - $70,000', 'job-posting-manager'); ?>" />
                    <p class="description"><?php _e('Optional: Salary range or amount', 'job-posting-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="duration"><?php _e('Duration', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <input type="text" id="duration" name="duration" class="regular-text"
                        value="<?php echo esc_attr($duration); ?>"
                        placeholder="<?php esc_attr_e('e.g., Full-time, Part-time, Contract', 'job-posting-manager'); ?>" />
                    <p class="description"><?php _e('Optional: Job duration or employment type', 'job-posting-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_job_meta($post_id)
    {
        // Check if nonce is set and verify
        if (isset($_POST['jpm_job_nonce']) && wp_verify_nonce($_POST['jpm_job_nonce'], 'jpm_job_meta')) {
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

            // Save job metadata (all fields are optional)
            if (isset($_POST['company_name'])) {
                update_post_meta($post_id, 'company_name', sanitize_text_field($_POST['company_name']));
            } else {
                delete_post_meta($post_id, 'company_name');
            }

            if (isset($_POST['location'])) {
                update_post_meta($post_id, 'location', sanitize_text_field($_POST['location']));
            } else {
                delete_post_meta($post_id, 'location');
            }

            if (isset($_POST['salary'])) {
                update_post_meta($post_id, 'salary', sanitize_text_field($_POST['salary']));
            } else {
                delete_post_meta($post_id, 'salary');
            }

            if (isset($_POST['duration'])) {
                update_post_meta($post_id, 'duration', sanitize_text_field($_POST['duration']));
            } else {
                delete_post_meta($post_id, 'duration');
            }
        } else {
            // If nonce verification fails, do not save any data
            return;
        }
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
        $company_name = get_post_meta($post->ID, 'company_name', true);
        $location = get_post_meta($post->ID, 'location', true);
        $salary = get_post_meta($post->ID, 'salary', true);
        $duration = get_post_meta($post->ID, 'duration', true);

        // Only display if at least one field has a value
        if (empty($company_name) && empty($location) && empty($salary) && empty($duration)) {
            return $content;
        }

        ob_start();
        ?>
        <div class="jpm-job-details">
            <h3><?php _e('Job Details', 'job-posting-manager'); ?></h3>
            <ul class="jpm-job-details-list">
                <?php if (!empty($company_name)): ?>
                    <li class="jpm-job-detail-item jpm-job-company">
                        <strong><?php _e('Company:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($company_name); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($location)): ?>
                    <li class="jpm-job-detail-item jpm-job-location">
                        <strong><?php _e('Location:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($location); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($salary)): ?>
                    <li class="jpm-job-detail-item jpm-job-salary">
                        <strong><?php _e('Salary:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($salary); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($duration)): ?>
                    <li class="jpm-job-detail-item jpm-job-duration">
                        <strong><?php _e('Duration:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($duration); ?></span>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
        $job_details = ob_get_clean();

        // Prepend job details to content
        return $job_details . $content;
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