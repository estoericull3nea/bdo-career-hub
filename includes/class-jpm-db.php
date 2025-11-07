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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_uploader']);
        add_filter('the_title', [$this, 'display_company_image_with_title'], 10, 2);
        add_action('template_redirect', [$this, 'restrict_draft_job_access']);
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
        add_meta_box('jpm_company_image', __('Company Image', 'job-posting-manager'), [$this, 'company_image_meta_box'], 'job_posting', 'side');
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
        if (isset($_POST['jpm_job_nonce']) && wp_verify_nonce($_POST['jpm_job_nonce'], 'jpm_job_meta')) {
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
        }

        // Save company image (separate nonce check)
        if (isset($_POST['jpm_company_image_nonce']) && wp_verify_nonce($_POST['jpm_company_image_nonce'], 'jpm_company_image')) {
            if (isset($_POST['company_image']) && !empty($_POST['company_image'])) {
                update_post_meta($post_id, 'company_image', absint($_POST['company_image']));
            } else {
                delete_post_meta($post_id, 'company_image');
            }
        }
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
                    <?php echo $company_image_id ? __('Change Image', 'job-posting-manager') : __('Upload Image', 'job-posting-manager'); ?>
                </button>
                <?php if ($company_image_id): ?>
                    <button type="button" class="button" id="remove_company_image_btn" style="margin-left: 5px;">
                        <?php _e('Remove Image', 'job-posting-manager'); ?>
                    </button>
                <?php endif; ?>
            </p>
            <p class="description">
                <?php _e('Optional: Upload a company logo or image. This will be displayed on the job posting page.', 'job-posting-manager'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Enqueue media uploader scripts
     */
    public function enqueue_media_uploader($hook)
    {
        // Only load on job posting edit screens
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        global $post_type;
        if ($post_type !== 'job_posting') {
            return;
        }

        // Enqueue WordPress media uploader
        wp_enqueue_media();

        // Add inline script for media uploader
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                var companyImageFrame;
                var $companyImageInput = $("#company_image");
                var $companyImagePreview = $("#company_image_preview");
                var $uploadBtn = $("#upload_company_image_btn");
                var $removeBtn = $("#remove_company_image_btn");

                // Upload button click
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
                        if (!$removeBtn.length) {
                            $uploadBtn.after("<button type=\"button\" class=\"button\" id=\"remove_company_image_btn\" style=\"margin-left: 5px;\">' . esc_js(__('Remove Image', 'job-posting-manager')) . '</button>");
                            $("#remove_company_image_btn").on("click", function() {
                                $companyImageInput.val("");
                                $companyImagePreview.html("");
                                $uploadBtn.text("' . esc_js(__('Upload Image', 'job-posting-manager')) . '");
                                $(this).remove();
                            });
                        }
                    });

                    companyImageFrame.open();
                });

                // Remove button click
                $(document).on("click", "#remove_company_image_btn", function() {
                    $companyImageInput.val("");
                    $companyImagePreview.html("");
                    $uploadBtn.text("' . esc_js(__('Upload Image', 'job-posting-manager')) . '");
                    $(this).remove();
                });
            });
        ');
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
        $company_name = get_post_meta($post->ID, 'company_name', true);
        $location = get_post_meta($post->ID, 'location', true);
        $salary = get_post_meta($post->ID, 'salary', true);
        $duration = get_post_meta($post->ID, 'duration', true);

        // Only display if at least one field has a value
        if (empty($company_image_id) && empty($company_name) && empty($location) && empty($salary) && empty($duration)) {
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
        foreach ($_POST['applications'] as $id) {
            JPM_DB::update_status($id, sanitize_text_field($_POST['status']));
            // Send email
            JPM_Emails::send_status_update($id);
        }
        wp_die(__('Updated', 'job-posting-manager'));
    }
}