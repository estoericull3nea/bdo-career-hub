<?php
class JPM_Frontend
{
    public function __construct()
    {
        add_shortcode('job_listings', [$this, 'job_listings_shortcode']);
        add_shortcode('user_applications', [$this, 'user_applications_shortcode']);
        add_shortcode('latest_jobs', [$this, 'latest_jobs_shortcode']);
        add_action('wp_ajax_jpm_apply', [$this, 'handle_application']);
        add_action('wp_ajax_nopriv_jpm_apply', [$this, 'handle_application']); // But redirect if not logged in
        add_action('wp_ajax_jpm_get_status', [$this, 'get_status']);
        add_action('wp_ajax_jpm_get_job_details', [$this, 'get_job_details']);
        add_action('wp_ajax_nopriv_jpm_get_job_details', [$this, 'get_job_details']);
    }

    public function job_listings_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view jobs.', 'job-posting-manager') . '</p>';
        }
        // Query active jobs, display with filters
        ob_start();
        // Loop through jobs, show apply button
        return ob_get_clean();
    }

    public function user_applications_shortcode()
    {
        if (!is_user_logged_in())
            return '';
        $user_id = get_current_user_id();
        $applications = JPM_DB::get_applications(['user_id' => $user_id]);
        // Display table with AJAX polling for status
        echo '<div id="jpm-status-updates"></div>';
    }

    public function handle_application()
    {
        check_ajax_referer('jpm_nonce');
        if (!is_user_logged_in())
            wp_die(__('Please log in.', 'job-posting-manager'));
        $job_id = intval($_POST['job_id']);
        $cover_letter = sanitize_textarea_field($_POST['cover_letter']);
        // Handle file upload
        $upload = wp_handle_upload($_FILES['resume'], ['upload_error_handler' => 'jpm_upload_error']);
        if (isset($upload['error']))
            wp_die($upload['error']);
        $result = JPM_DB::insert_application(get_current_user_id(), $job_id, $upload['file'], $cover_letter);
        if (is_wp_error($result))
            wp_die($result->get_error_message());
        JPM_Emails::send_confirmation($result); // Pass insert ID
        wp_die(__('Application submitted. ID: ' . $result, 'job-posting-manager'));
    }

    public function get_status()
    {
        $applications = JPM_DB::get_applications(['user_id' => get_current_user_id()]);
        wp_send_json($applications);
    }

    /**
     * Shortcode to display latest jobs
     * Usage: [latest_jobs count="3"]
     */
    public function latest_jobs_shortcode($atts)
    {
        $atts = shortcode_atts([
            'count' => 3,
        ], $atts);

        $count = intval($atts['count']);
        if ($count < 1) {
            $count = 3;
        }

        // Query latest published jobs
        $jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => $count,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        if (empty($jobs)) {
            return '<p class="jpm-no-jobs">' . __('No jobs available at the moment.', 'job-posting-manager') . '</p>';
        }

        ob_start();
        ?>
        <div class="jpm-latest-jobs">
            <?php foreach ($jobs as $job):
                $company_name = get_post_meta($job->ID, 'company_name', true);
                $location = get_post_meta($job->ID, 'location', true);
                $salary = get_post_meta($job->ID, 'salary', true);
                $duration = get_post_meta($job->ID, 'duration', true);
                $company_image_id = get_post_meta($job->ID, 'company_image', true);
                $company_image_url = '';
                if ($company_image_id) {
                    $company_image_url = wp_get_attachment_image_url($company_image_id, 'thumbnail');
                }
                $job_link = get_permalink($job->ID);
                $excerpt = wp_trim_words(get_the_excerpt($job->ID), 20);
                if (empty($excerpt)) {
                    $excerpt = wp_trim_words($job->post_content, 20);
                }
                ?>
                <div class="jpm-job-card" data-job-id="<?php echo esc_attr($job->ID); ?>">
                    <?php if ($company_image_url): ?>
                        <div class="jpm-job-card-image">
                            <img src="<?php echo esc_url($company_image_url); ?>"
                                alt="<?php echo esc_attr($company_name ?: get_the_title($job->ID)); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="jpm-job-card-content">
                        <h3 class="jpm-job-card-title">
                            <a href="<?php echo esc_url($job_link); ?>"><?php echo esc_html(get_the_title($job->ID)); ?></a>
                        </h3>
                        <?php if (!empty($company_name)): ?>
                            <div class="jpm-job-card-meta">
                                <span class="jpm-job-company">
                                    <i class="dashicons dashicons-building"></i>
                                    <?php echo esc_html($company_name); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="jpm-job-card-info">
                            <?php if (!empty($location)): ?>
                                <span class="jpm-job-info-item">
                                    <i class="dashicons dashicons-location"></i>
                                    <?php echo esc_html($location); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($salary)): ?>
                                <span class="jpm-job-info-item">
                                    <i class="dashicons dashicons-money-alt"></i>
                                    <?php echo esc_html($salary); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($duration)): ?>
                                <span class="jpm-job-info-item">
                                    <i class="dashicons dashicons-clock"></i>
                                    <?php echo esc_html($duration); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($excerpt)): ?>
                            <p class="jpm-job-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                        <?php endif; ?>
                        <div class="jpm-job-card-footer">
                            <span class="jpm-job-posted-date">
                                <i class="dashicons dashicons-calendar-alt"></i>
                                <?php echo esc_html(get_the_date('', $job->ID)); ?>
                            </span>
                        </div>
                        <div class="jpm-job-card-actions">
                            <button type="button" class="jpm-btn jpm-btn-quick-view"
                                data-job-id="<?php echo esc_attr($job->ID); ?>">
                                <?php _e('Quick View', 'job-posting-manager'); ?>
                            </button>
                            <a href="<?php echo esc_url($job_link); ?>" class="jpm-btn jpm-btn-apply">
                                <?php _e('Apply Now', 'job-posting-manager'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Modal for Quick View -->
        <div id="jpm-job-modal" class="jpm-modal">
            <div class="jpm-modal-overlay"></div>
            <div class="jpm-modal-content">
                <button type="button" class="jpm-modal-close" aria-label="<?php esc_attr_e('Close', 'job-posting-manager'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
                <div class="jpm-modal-body">
                    <div class="jpm-modal-loading">
                        <span class="spinner is-active"></span>
                        <p><?php _e('Loading job details...', 'job-posting-manager'); ?></p>
                    </div>
                    <div class="jpm-modal-job-content" style="display: none;"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to get job details for modal
     */
    public function get_job_details()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        }

        $job_id = intval($_POST['job_id'] ?? 0);

        if ($job_id <= 0) {
            wp_send_json_error(['message' => __('Invalid job ID.', 'job-posting-manager')]);
        }

        $job = get_post($job_id);

        if (!$job || $job->post_type !== 'job_posting' || $job->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Job not found.', 'job-posting-manager')]);
        }

        $company_name = get_post_meta($job_id, 'company_name', true);
        $location = get_post_meta($job_id, 'location', true);
        $salary = get_post_meta($job_id, 'salary', true);
        $duration = get_post_meta($job_id, 'duration', true);
        $company_image_id = get_post_meta($job_id, 'company_image', true);
        $company_image_url = '';
        if ($company_image_id) {
            $company_image_url = wp_get_attachment_image_url($company_image_id, 'medium');
        }
        $job_link = get_permalink($job_id);

        ob_start();
        ?>
        <div class="jpm-modal-job-header">
            <?php if ($company_image_url): ?>
                <div class="jpm-modal-job-image">
                    <img src="<?php echo esc_url($company_image_url); ?>"
                        alt="<?php echo esc_attr($company_name ?: get_the_title($job_id)); ?>">
                </div>
            <?php endif; ?>
            <div class="jpm-modal-job-title-section">
                <h2><?php echo esc_html(get_the_title($job_id)); ?></h2>
                <?php if (!empty($company_name)): ?>
                    <p class="jpm-modal-job-company"><?php echo esc_html($company_name); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="jpm-modal-job-details">
            <h3><?php _e('Job Details', 'job-posting-manager'); ?></h3>
            <ul class="jpm-modal-job-details-list">
                <?php if (!empty($location)): ?>
                    <li>
                        <strong><?php _e('Location:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($location); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($salary)): ?>
                    <li>
                        <strong><?php _e('Salary:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($salary); ?></span>
                    </li>
                <?php endif; ?>
                <?php if (!empty($duration)): ?>
                    <li>
                        <strong><?php _e('Duration:', 'job-posting-manager'); ?></strong>
                        <span><?php echo esc_html($duration); ?></span>
                    </li>
                <?php endif; ?>
                <li>
                    <strong><?php _e('Posted Date:', 'job-posting-manager'); ?></strong>
                    <span><?php echo esc_html(get_the_date('', $job_id)); ?></span>
                </li>
            </ul>
        </div>

        <div class="jpm-modal-job-description">
            <h3><?php _e('Job Description', 'job-posting-manager'); ?></h3>
            <div class="jpm-modal-job-content-text">
                <?php echo wp_kses_post(apply_filters('the_content', $job->post_content)); ?>
            </div>
        </div>

        <div class="jpm-modal-job-actions">
            <a href="<?php echo esc_url($job_link); ?>" class="jpm-btn jpm-btn-apply jpm-btn-block">
                <?php _e('Apply Now', 'job-posting-manager'); ?>
            </a>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
}