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
        add_action('wp_ajax_jpm_update_application_status', [$this, 'update_application_status']);
    }

    public function add_menu()
    {
        add_menu_page(__('Job Manager', 'job-posting-manager'), __('Job Manager', 'job-posting-manager'), 'manage_options', 'jpm-dashboard', [$this, 'dashboard_page'], 'dashicons-businessman');
        add_submenu_page('jpm-dashboard', __('Applications', 'job-posting-manager'), __('Applications', 'job-posting-manager'), 'manage_options', 'jpm-applications', [$this, 'applications_page']);
        add_submenu_page('jpm-dashboard', __('Status Management', 'job-posting-manager'), __('Status Management', 'job-posting-manager'), 'manage_options', 'jpm-status-management', [$this, 'status_management_page']);
    }

    public function dashboard_page()
    {
        // Form for creating/editing jobs (use standard WP post editor)
        echo '<h1>' . __('Manage Job Postings', 'job-posting-manager') . '</h1>';
        // Include job form here (similar to post-new.php)
    }

    public function applications_page()
    {
        $filters = [
            'status' => $_GET['status'] ?? '',
            'job_id' => $_GET['job_id'] ?? '',
            'search' => $_GET['search'] ?? '',
        ];

        $applications = JPM_DB::get_applications($filters);

        // Get all jobs for filter dropdown
        $jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        ?>
        <div class="wrap">
            <h1><?php _e('Applications', 'job-posting-manager'); ?></h1>

            <div class="jpm-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccc;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="jpm-applications">

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <?php _e('Search Applications:', 'job-posting-manager'); ?>
                        </label>
                        <input type="text" name="search" class="regular-text"
                            value="<?php echo esc_attr($filters['search']); ?>"
                            placeholder="<?php esc_attr_e('Search by name, email, or application number...', 'job-posting-manager'); ?>"
                            style="width: 100%; max-width: 500px;">
                        <p class="description" style="margin-top: 5px;">
                            <?php _e('Search by given name, middle name, surname, email, or application number', 'job-posting-manager'); ?>
                        </p>
                    </div>

                    <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                        <label>
                            <?php _e('Filter by Job:', 'job-posting-manager'); ?>
                            <select name="job_id">
                                <option value=""><?php _e('All Jobs', 'job-posting-manager'); ?></option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo esc_attr($job->ID); ?>" <?php selected($filters['job_id'], $job->ID); ?>>
                                        <?php echo esc_html($job->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php _e('Filter by Status:', 'job-posting-manager'); ?>
                            <select name="status">
                                <option value=""><?php _e('All Statuses', 'job-posting-manager'); ?></option>
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
                            value="<?php _e('Search/Filter', 'job-posting-manager'); ?>">
                        <?php if (!empty($filters['search']) || !empty($filters['job_id']) || !empty($filters['status'])): ?>
                            <a href="<?php echo admin_url('admin.php?page=jpm-applications'); ?>" class="button">
                                <?php _e('Clear', 'job-posting-manager'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (empty($applications)): ?>
                <p><?php _e('No applications found.', 'job-posting-manager'); ?></p>
            <?php else: ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'job-posting-manager'); ?></th>
                            <th><?php _e('Job Title', 'job-posting-manager'); ?></th>
                            <th><?php _e('Application Date', 'job-posting-manager'); ?></th>
                            <th><?php _e('Status', 'job-posting-manager'); ?></th>
                            <th><?php _e('User', 'job-posting-manager'); ?></th>
                            <th><?php _e('Application Number', 'job-posting-manager'); ?></th>
                            <th><?php _e('Actions', 'job-posting-manager'); ?></th>
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
                                    <a href="<?php echo admin_url('post.php?post=' . $application->job_id . '&action=edit'); ?>">
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
                                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>">
                                            <?php echo esc_html($user->display_name); ?>
                                        </a>
                                        <br><small><?php echo esc_html($user->user_email); ?></small>
                                    <?php else: ?>
                                        <em><?php _e('Guest', 'job-posting-manager'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($application_number); ?></td>
                                <td>
                                    <select class="jpm-application-status-select"
                                        data-application-id="<?php echo esc_attr($application->id); ?>" style="min-width: 120px;">
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
            <?php endif; ?>
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
        </style>

        <script>
            jQuery(document).ready(function ($) {
                // Update status on change
                $('.jpm-application-status-select').on('change', function () {
                    var $select = $(this);
                    var applicationId = $select.data('application-id');
                    var newStatus = $select.val();
                    var $row = $select.closest('tr');

                    // Disable select while updating
                    $select.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_update_application_status',
                            application_id: applicationId,
                            status: newStatus,
                            nonce: '<?php echo wp_create_nonce('jpm_update_status'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                // Update status badge in the same row
                                var $statusBadge = $row.find('.jpm-status-badge');
                                $statusBadge.removeClass('jpm-status-pending jpm-status-reviewed jpm-status-accepted jpm-status-rejected');
                                $statusBadge.addClass('jpm-status-' + newStatus);
                                $statusBadge.text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));

                                // Show success message
                                $select.after('<span class="jpm-status-update-success" style="color: #28a745; margin-left: 5px; font-size: 12px;">✓ Updated</span>');
                                setTimeout(function () {
                                    $select.siblings('.jpm-status-update-success').fadeOut(function () {
                                        $(this).remove();
                                    });
                                }, 2000);
                            } else {
                                alert('Error updating status: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                                // Revert select to original value
                                location.reload();
                            }
                            $select.prop('disabled', false);
                        },
                        error: function () {
                            alert('Error updating status. Please try again.');
                            location.reload();
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function add_meta_boxes()
    {
        add_meta_box('jpm_job_details', __('Job Details', 'job-posting-manager'), [$this, 'job_meta_box'], 'job_posting');
        add_meta_box('jpm_company_image', __('Company Image', 'job-posting-manager'), [$this, 'company_image_meta_box'], 'job_posting', 'side');
        add_meta_box('jpm_job_applications', __('Applications', 'job-posting-manager'), [$this, 'job_applications_meta_box'], 'job_posting', 'normal');
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
            <tr>
                <th scope="row">
                    <label><?php _e('Posted Date', 'job-posting-manager'); ?></label>
                </th>
                <td>
                    <p>
                        <strong><?php echo esc_html(get_the_date('', $post->ID)); ?></strong>
                        <?php if ($post->post_date !== $post->post_modified): ?>
                            <br>
                            <span class="description">
                                <?php _e('Last modified:', 'job-posting-manager'); ?>
                                <?php echo esc_html(get_the_modified_date('', $post->ID)); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                    <p class="description">
                        <?php _e('This is the date when the job was posted. You can change it using the "Publish" box on the right.', 'job-posting-manager'); ?>
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
     * Job Applications meta box
     * @param WP_Post $post The post object
     */
    public function job_applications_meta_box($post)
    {
        // Get all applications for this job
        $applications = JPM_DB::get_applications(['job_id' => $post->ID]);

        if (empty($applications)) {
            echo '<p>' . __('No applications have been submitted for this job yet.', 'job-posting-manager') . '</p>';
            return;
        }

        ?>
        <div class="jpm-applications-list">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;"><?php _e('ID', 'job-posting-manager'); ?></th>
                        <th style="width: 15%;"><?php _e('Application Date', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php _e('Status', 'job-posting-manager'); ?></th>
                        <th style="width: 15%;"><?php _e('User', 'job-posting-manager'); ?></th>
                        <th style="width: 45%;"><?php _e('Application Data', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php _e('Actions', 'job-posting-manager'); ?></th>
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
                                    <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a>
                                    <br><small><?php echo esc_html($user->user_email); ?></small>
                                <?php else: ?>
                                    <em><?php _e('Guest', 'job-posting-manager'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($application_number): ?>
                                    <strong><?php _e('Application #:', 'job-posting-manager'); ?></strong>
                                    <?php echo esc_html($application_number); ?><br>
                                <?php endif; ?>
                                <?php if ($date_of_registration): ?>
                                    <strong><?php _e('Date:', 'job-posting-manager'); ?></strong>
                                    <?php echo esc_html($date_of_registration); ?><br>
                                <?php endif; ?>
                                <a href="#" class="jpm-view-application-details"
                                    data-application-id="<?php echo esc_attr($application->id); ?>">
                                    <?php _e('View Full Details', 'job-posting-manager'); ?>
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
            <div class="jpm-modal-content">
                <span class="jpm-modal-close">&times;</span>
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

        <script>
            jQuery(document).ready(function ($) {
                // Update status on change
                $('.jpm-application-status').on('change', function () {
                    var $select = $(this);
                    var applicationId = $select.data('application-id');
                    var newStatus = $select.val();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_update_application_status',
                            application_id: applicationId,
                            status: newStatus,
                            nonce: '<?php echo wp_create_nonce('jpm_update_status'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Error updating status');
                            }
                        }
                    });
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

        // Always display job details section (at minimum, it will show posted date)

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
                <li class="jpm-job-detail-item jpm-job-posted-date">
                    <strong><?php _e('Posted Date:', 'job-posting-manager'); ?></strong>
                    <span><?php echo esc_html(get_the_date('', $post->ID)); ?></span>
                </li>
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

    /**
     * Update application status via AJAX
     */
    public function update_application_status()
    {
        check_ajax_referer('jpm_update_status', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $application_id = intval($_POST['application_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if (!$application_id || !$status) {
            wp_send_json_error(['message' => __('Invalid data', 'job-posting-manager')]);
        }

        $result = JPM_DB::update_status($application_id, $status);

        if ($result !== false) {
            // Send email notification if email class exists
            if (class_exists('JPM_Emails')) {
                try {
                    JPM_Emails::send_status_update($application_id);
                } catch (Exception $e) {
                    // Log error but don't fail the request
                    error_log('JPM Email Error: ' . $e->getMessage());
                }
            }
            wp_send_json_success(['message' => __('Status updated successfully', 'job-posting-manager')]);
        } else {
            wp_send_json_error(['message' => __('Failed to update status', 'job-posting-manager')]);
        }
    }

    /**
     * Status Management Page
     */
    public function status_management_page()
    {
        // Handle form submissions
        if (isset($_POST['jpm_action'])) {
            check_admin_referer('jpm_status_management');

            if (!current_user_can('manage_options')) {
                wp_die(__('Permission denied', 'job-posting-manager'));
            }

            $action = sanitize_text_field($_POST['jpm_action']);

            if ($action === 'add') {
                $this->add_status();
            } elseif ($action === 'edit') {
                $this->update_status_item();
            } elseif ($action === 'delete') {
                $this->delete_status_item();
            }
        }

        // Get all statuses
        $statuses = $this->get_all_statuses();
        $editing_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
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
            <h1><?php _e('Status Management', 'job-posting-manager'); ?></h1>

            <?php if (isset($_GET['status_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Status saved successfully!', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['status_deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Status deleted successfully!', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>

            <div class="jpm-status-management">
                <div class="jpm-status-form-section"
                    style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #ccc; border-radius: 4px;">
                    <h2><?php echo $editing_status ? __('Edit Status', 'job-posting-manager') : __('Add New Status', 'job-posting-manager'); ?>
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
                                    <label for="status_name"><?php _e('Status Name', 'job-posting-manager'); ?> <span
                                            class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="status_name" name="status_name" class="regular-text"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['name']) : ''; ?>" required
                                        placeholder="<?php esc_attr_e('e.g., Pending, Reviewed, Accepted', 'job-posting-manager'); ?>">
                                    <p class="description"><?php _e('The display name of the status', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_slug"><?php _e('Status Slug', 'job-posting-manager'); ?> <span
                                            class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="status_slug" name="status_slug" class="regular-text"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['slug']) : ''; ?>" required
                                        placeholder="<?php esc_attr_e('e.g., pending, reviewed, accepted', 'job-posting-manager'); ?>">
                                    <p class="description">
                                        <?php _e('Unique identifier (lowercase, no spaces). Used in the database.', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_color"><?php _e('Status Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="status_color" name="status_color"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['color']) : '#ffc107'; ?>">
                                    <p class="description"><?php _e('Color for the status badge', 'job-posting-manager'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_text_color"><?php _e('Text Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="color" id="status_text_color" name="status_text_color"
                                        value="<?php echo $editing_status ? esc_attr($editing_status['text_color']) : '#000000'; ?>">
                                    <p class="description">
                                        <?php _e('Text color for the status badge', 'job-posting-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="status_description"><?php _e('Description', 'job-posting-manager'); ?></label>
                                </th>
                                <td>
                                    <textarea id="status_description" name="status_description" rows="3" class="large-text"
                                        placeholder="<?php esc_attr_e('Optional description for this status', 'job-posting-manager'); ?>"><?php echo $editing_status ? esc_textarea($editing_status['description']) : ''; ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="submit" class="button button-primary"
                                value="<?php echo $editing_status ? __('Update Status', 'job-posting-manager') : __('Add Status', 'job-posting-manager'); ?>">
                            <?php if ($editing_status): ?>
                                <a href="<?php echo admin_url('admin.php?page=jpm-status-management'); ?>" class="button">
                                    <?php _e('Cancel', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="jpm-status-list-section">
                    <h2><?php _e('Existing Statuses', 'job-posting-manager'); ?></h2>

                    <?php if (empty($statuses)): ?>
                        <p><?php _e('No statuses found. Add your first status above.', 'job-posting-manager'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 5%;"><?php _e('ID', 'job-posting-manager'); ?></th>
                                    <th style="width: 20%;"><?php _e('Name', 'job-posting-manager'); ?></th>
                                    <th style="width: 15%;"><?php _e('Slug', 'job-posting-manager'); ?></th>
                                    <th style="width: 20%;"><?php _e('Preview', 'job-posting-manager'); ?></th>
                                    <th style="width: 30%;"><?php _e('Description', 'job-posting-manager'); ?></th>
                                    <th style="width: 10%;"><?php _e('Actions', 'job-posting-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statuses as $status): ?>
                                    <tr>
                                        <td><?php echo esc_html($status['id']); ?></td>
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
                                            <a href="<?php echo admin_url('admin.php?page=jpm-status-management&edit=' . $status['id']); ?>"
                                                class="button button-small"><?php _e('Edit', 'job-posting-manager'); ?></a>
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
        <?php
    }

    /**
     * Get all statuses
     */
    private function get_all_statuses()
    {
        $statuses = get_option('jpm_application_statuses', []);

        // If no custom statuses, return default ones
        if (empty($statuses)) {
            return $this->get_default_statuses();
        }

        return $statuses;
    }

    /**
     * Get default statuses
     */
    private function get_default_statuses()
    {
        return [
            ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review'],
            ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed'],
            ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted'],
            ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected'],
        ];
    }

    /**
     * Add new status
     */
    private function add_status()
    {
        $status_name = sanitize_text_field($_POST['status_name'] ?? '');
        $status_slug = sanitize_text_field($_POST['status_slug'] ?? '');
        $status_color = sanitize_hex_color($_POST['status_color'] ?? '#ffc107');
        $status_text_color = sanitize_hex_color($_POST['status_text_color'] ?? '#000000');
        $status_description = sanitize_textarea_field($_POST['status_description'] ?? '');

        if (empty($status_name) || empty($status_slug)) {
            wp_die(__('Status name and slug are required', 'job-posting-manager'));
        }

        // Sanitize slug
        $status_slug = strtolower($status_slug);
        $status_slug = preg_replace('/[^a-z0-9_-]/', '', $status_slug);

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
        ];

        $statuses[] = $new_status;
        update_option('jpm_application_statuses', $statuses);

        wp_redirect(admin_url('admin.php?page=jpm-status-management&status_saved=1'));
        exit;
    }

    /**
     * Update status
     */
    private function update_status_item()
    {
        $status_id = intval($_POST['status_id'] ?? 0);
        $status_name = sanitize_text_field($_POST['status_name'] ?? '');
        $status_slug = sanitize_text_field($_POST['status_slug'] ?? '');
        $status_color = sanitize_hex_color($_POST['status_color'] ?? '#ffc107');
        $status_text_color = sanitize_hex_color($_POST['status_text_color'] ?? '#000000');
        $status_description = sanitize_textarea_field($_POST['status_description'] ?? '');

        if (!$status_id || empty($status_name) || empty($status_slug)) {
            wp_die(__('Invalid data', 'job-posting-manager'));
        }

        // Sanitize slug
        $status_slug = strtolower($status_slug);
        $status_slug = preg_replace('/[^a-z0-9_-]/', '', $status_slug);

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
                ];
                break;
            }
        }

        update_option('jpm_application_statuses', $statuses);

        wp_redirect(admin_url('admin.php?page=jpm-status-management&status_saved=1'));
        exit;
    }

    /**
     * Delete status
     */
    private function delete_status_item()
    {
        $status_id = intval($_POST['status_id'] ?? 0);

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

        wp_redirect(admin_url('admin.php?page=jpm-status-management&status_deleted=1'));
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
                ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review'],
                ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed'],
                ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted'],
                ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected'],
            ];
            $statuses = $default_statuses;
        }

        $options = [];
        foreach ($statuses as $status) {
            $options[$status['slug']] = $status['name'];
        }

        return $options;
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
                ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'Application is pending review'],
                ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Application has been reviewed'],
                ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Application has been accepted'],
                ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'Application has been rejected'],
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
}