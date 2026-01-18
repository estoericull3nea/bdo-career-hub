<?php
class JPM_Frontend
{
    public function __construct()
    {
        add_shortcode('job_listings', [$this, 'job_listings_shortcode']);
        add_shortcode('user_applications', [$this, 'user_applications_shortcode']);
        add_shortcode('latest_jobs', [$this, 'latest_jobs_shortcode']);
        add_shortcode('all_jobs', [$this, 'all_jobs_shortcode']);
        add_shortcode('application_tracker', [$this, 'application_tracker_shortcode']);
        add_shortcode('jpm_register', [$this, 'register_shortcode']);
        add_shortcode('jpm_login', [$this, 'login_shortcode']);
        add_shortcode('jpm_logout', [$this, 'logout_shortcode']);
        add_shortcode('jpm_forgot_password', [$this, 'forgot_password_shortcode']);
        add_shortcode('jpm_reset_password', [$this, 'reset_password_shortcode']);
        add_shortcode('jpm_user_profile', [$this, 'user_profile_shortcode']);
        add_action('wp_ajax_jpm_apply', [$this, 'handle_application']);
        add_action('wp_ajax_nopriv_jpm_apply', [$this, 'handle_application']); // But redirect if not logged in
        add_action('wp_ajax_jpm_get_status', [$this, 'get_status']);
        add_action('wp_ajax_jpm_get_job_details', [$this, 'get_job_details']);
        add_action('wp_ajax_nopriv_jpm_get_job_details', [$this, 'get_job_details']);
        add_action('wp_ajax_jpm_filter_jobs', [$this, 'filter_jobs_ajax']);
        add_action('wp_ajax_nopriv_jpm_filter_jobs', [$this, 'filter_jobs_ajax']);
        add_action('wp_ajax_jpm_track_application', [$this, 'track_application_ajax']);
        add_action('wp_ajax_nopriv_jpm_track_application', [$this, 'track_application_ajax']);
        add_action('wp_ajax_jpm_register', [$this, 'handle_registration']);
        add_action('wp_ajax_nopriv_jpm_register', [$this, 'handle_registration']);
        add_action('wp_ajax_jpm_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_jpm_login', [$this, 'handle_login']);
        add_action('wp_ajax_jpm_logout', [$this, 'handle_logout']);
        add_action('wp_ajax_jpm_forgot_password', [$this, 'handle_forgot_password']);
        add_action('wp_ajax_nopriv_jpm_forgot_password', [$this, 'handle_forgot_password']);
        add_action('wp_ajax_jpm_reset_password', [$this, 'handle_reset_password']);
        add_action('wp_ajax_nopriv_jpm_reset_password', [$this, 'handle_reset_password']);
        add_action('wp_ajax_jpm_find_register_page', [$this, 'find_register_page']);
        add_action('wp_ajax_nopriv_jpm_find_register_page', [$this, 'find_register_page']);
        add_action('wp_ajax_jpm_update_personal_info', [$this, 'handle_update_personal_info']);

        // Filter password reset email to use custom reset page
        add_filter('retrieve_password_message', [$this, 'customize_password_reset_email'], 10, 4);
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
            'view_all_url' => '',
        ], $atts);

        $count = intval($atts['count']);
        if ($count < 1) {
            $count = 3;
        }

        // Get the "View All Jobs" URL
        $view_all_url = !empty($atts['view_all_url']) ? esc_url($atts['view_all_url']) : '';

        // If no URL provided, try to find a page with [all_jobs] shortcode
        if (empty($view_all_url)) {
            $pages = get_pages();
            foreach ($pages as $page) {
                if (has_shortcode($page->post_content, 'all_jobs')) {
                    $view_all_url = get_permalink($page->ID);
                    break;
                }
            }
        }

        // If still no URL, use current page with a query parameter or home URL
        if (empty($view_all_url)) {
            $view_all_url = home_url('/jobs/');
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
                $post_status = get_post_status($job->ID);
                $status_badge = '';
                if ($post_status === 'publish') {
                    $status_badge = '<span class="jpm-status-badge jpm-status-active">' . __('Active', 'job-posting-manager') . '</span>';
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
                        <div class="jpm-job-card-title-wrapper">
                            <h3 class="jpm-job-card-title">
                                <a href="<?php echo esc_url($job_link); ?>"><?php echo esc_html(get_the_title($job->ID)); ?></a>
                            </h3>
                            <?php if (!empty($status_badge)): ?>
                                <?php echo $status_badge; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($company_name)): ?>
                            <div class="jpm-job-card-meta"><span class="jpm-job-company"><i class="dashicons dashicons-building"></i>
                                    <?php echo esc_html($company_name); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="jpm-job-card-info">
                            <?php if (!empty($location)): ?><span class="jpm-job-info-item"> <i
                                        class="dashicons dashicons-location"></i><?php echo esc_html($location); ?> </span>
                            <?php endif; ?>
                            <?php if (!empty($salary)): ?><span class="jpm-job-info-item"> <i
                                        class="dashicons dashicons-money-alt"></i> <?php echo esc_html($salary); ?> </span>
                            <?php endif; ?>
                            <?php if (!empty($duration)): ?> <span class="jpm-job-info-item"> <i
                                        class="dashicons dashicons-clock"></i> <?php echo esc_html($duration); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($excerpt)): ?>
                            <p class="jpm-job-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                        <?php endif; ?>
                        <div class="jpm-job-card-footer"> <span class="jpm-job-posted-date"> <i
                                    class="dashicons dashicons-calendar-alt"></i><?php echo esc_html(get_the_date('', $job->ID)); ?>
                            </span>
                        </div>
                        <div class="jpm-job-card-actions">
                            <button type="button" class="jpm-btn jpm-btn-quick-view"
                                data-job-id="<?php echo esc_attr($job->ID); ?>"><?php _e('Quick View', 'job-posting-manager'); ?></button>
                            <a href="<?php echo esc_url($job_link); ?>" class="jpm-btn jpm-btn-apply">
                                <?php _e('Apply Now', 'job-posting-manager'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($view_all_url)): ?>
            <div class="jpm-view-all-jobs">
                <a href="<?php echo esc_url($view_all_url); ?>" class="jpm-btn jpm-btn-view-all">
                    <?php _e('View All Jobs', 'job-posting-manager'); ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Modal for Quick View -->
        <div id="jpm-job-modal" class="jpm-modal">
            <div class="jpm-modal-overlay"></div>
            <div class="jpm-modal-content">
                <button type="button" class="jpm-modal-close"
                    aria-label="<?php esc_attr_e('Close', 'job-posting-manager'); ?>"><span
                        class="dashicons dashicons-no-alt"></span></button>
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
            <a href="<?php echo esc_url($job_link); ?>"
                class="jpm-btn jpm-btn-apply jpm-btn-block"><?php _e('Apply Now', 'job-posting-manager'); ?></a>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Shortcode to display all jobs with filters, search, and pagination
     * Usage: [all_jobs per_page="12"]
     */
    public function all_jobs_shortcode($atts)
    {
        $atts = shortcode_atts([
            'per_page' => 12,
        ], $atts);

        $per_page = intval($atts['per_page']);
        if ($per_page < 1) {
            $per_page = 12;
        }

        // Get current page
        $paged = isset($_GET['jpm_page']) ? max(1, intval($_GET['jpm_page'])) : 1;

        // Get filter values
        $search = isset($_GET['jpm_search']) ? sanitize_text_field($_GET['jpm_search']) : '';
        $location_filter = isset($_GET['jpm_location']) ? sanitize_text_field($_GET['jpm_location']) : '';
        $company_filter = isset($_GET['jpm_company']) ? sanitize_text_field($_GET['jpm_company']) : '';

        // Query jobs with filters
        $args = [
            'post_type' => 'job_posting',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        // Add search filter
        if (!empty($search)) {
            $args['s'] = $search;
        }

        // Add meta query for location and company filters
        $meta_query = [];
        if (!empty($location_filter)) {
            $meta_query[] = [
                'key' => 'location',
                'value' => $location_filter,
                'compare' => 'LIKE'
            ];
        }
        if (!empty($company_filter)) {
            $meta_query[] = [
                'key' => 'company_name',
                'value' => $company_filter,
                'compare' => 'LIKE'
            ];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $jobs_query = new WP_Query($args);

        // Get unique locations and companies for filter dropdowns
        $all_jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        $locations = [];
        $companies = [];
        foreach ($all_jobs as $job) {
            $location = get_post_meta($job->ID, 'location', true);
            $company = get_post_meta($job->ID, 'company_name', true);
            if (!empty($location) && !in_array($location, $locations)) {
                $locations[] = $location;
            }
            if (!empty($company) && !in_array($company, $companies)) {
                $companies[] = $company;
            }
        }
        sort($locations);
        sort($companies);

        ob_start();
        ?>
        <div class="jpm-all-jobs-wrapper">
            <!-- Filters Section -->
            <div class="jpm-jobs-filters">
                <form method="get" action="" class="jpm-filter-form">
                    <div class="jpm-filter-row">
                        <div class="jpm-filter-group">
                            <label for="jpm_search"><?php _e('Search', 'job-posting-manager'); ?></label>
                            <input type="text" id="jpm_search" name="jpm_search" class="jpm-filter-input jpm-search-input"
                                value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php esc_attr_e('Search by job title...', 'job-posting-manager'); ?>"
                                autocomplete="off">
                            <span class="jpm-search-indicator"
                                style="display: none; font-size: 0.85em; color: #666; margin-top: 5px;">
                                <?php _e('Type to search...', 'job-posting-manager'); ?>
                            </span>
                        </div>
                        <div class="jpm-filter-group">
                            <label for="jpm_location"><?php _e('Location', 'job-posting-manager'); ?></label>
                            <select id="jpm_location" name="jpm_location" class="jpm-filter-select">
                                <option value=""><?php _e('All Locations', 'job-posting-manager'); ?></option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo esc_attr($loc); ?>" <?php selected($location_filter, $loc); ?>>
                                        <?php echo esc_html($loc); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="jpm-filter-group">
                            <label for="jpm_company"><?php _e('Company', 'job-posting-manager'); ?></label>
                            <select id="jpm_company" name="jpm_company" class="jpm-filter-select">
                                <option value=""><?php _e('All Companies', 'job-posting-manager'); ?></option>
                                <?php foreach ($companies as $comp): ?>
                                    <option value="<?php echo esc_attr($comp); ?>" <?php selected($company_filter, $comp); ?>>
                                        <?php echo esc_html($comp); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="jpm-filter-group jpm-filter-actions">
                            <button type="submit"
                                class="jpm-btn jpm-btn-filter"><?php _e('Filter', 'job-posting-manager'); ?></button>
                            <?php if (!empty($search) || !empty($location_filter) || !empty($company_filter)): ?>
                                <a href="<?php echo esc_url(remove_query_arg(['jpm_search', 'jpm_location', 'jpm_company', 'jpm_page'])); ?>"
                                    class="jpm-btn jpm-btn-reset">
                                    <?php _e('Reset', 'job-posting-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Count -->
            <div class="jpm-jobs-results-count">
                <p>
                    <?php
                    $total = $jobs_query->found_posts;
                    $start = ($paged - 1) * $per_page + 1;
                    $end = min($paged * $per_page, $total);
                    if ($total > 0) {
                        printf(
                            __('Showing %d-%d of %d jobs', 'job-posting-manager'),
                            $start,
                            $end,
                            $total
                        );
                    } else {
                        _e('No jobs found.', 'job-posting-manager');
                    }
                    ?>
                </p>
            </div>

            <!-- Jobs Grid -->
            <?php if ($jobs_query->have_posts()): ?>
                <div class="jpm-latest-jobs">
                    <?php while ($jobs_query->have_posts()):
                        $jobs_query->the_post();
                        $job_id = get_the_ID();
                        $company_name = get_post_meta($job_id, 'company_name', true);
                        $location = get_post_meta($job_id, 'location', true);
                        $salary = get_post_meta($job_id, 'salary', true);
                        $duration = get_post_meta($job_id, 'duration', true);
                        $company_image_id = get_post_meta($job_id, 'company_image', true);
                        $company_image_url = '';
                        if ($company_image_id) {
                            $company_image_url = wp_get_attachment_image_url($company_image_id, 'thumbnail');
                        }
                        $job_link = get_permalink($job_id);
                        $excerpt = wp_trim_words(get_the_excerpt(), 20);
                        if (empty($excerpt)) {
                            $excerpt = wp_trim_words(get_the_content(), 20);
                        }
                        $post_status = get_post_status($job_id);
                        $status_badge = '';
                        if ($post_status === 'publish') {
                            $status_badge = '<span class="jpm-status-badge jpm-status-active">' . __('Active', 'job-posting-manager') . '</span>';
                        }
                        ?>
                        <div class="jpm-job-card" data-job-id="<?php echo esc_attr($job_id); ?>">
                            <?php if ($company_image_url): ?>
                                <div class="jpm-job-card-image">
                                    <img src="<?php echo esc_url($company_image_url); ?>"
                                        alt="<?php echo esc_attr($company_name ?: get_the_title()); ?>">
                                </div>
                            <?php endif; ?>
                            <div class="jpm-job-card-content">
                                <div class="jpm-job-card-title-wrapper">
                                    <h3 class="jpm-job-card-title">
                                        <a href="<?php echo esc_url($job_link); ?>"><?php echo esc_html(get_the_title()); ?></a>
                                    </h3>
                                    <?php if (!empty($status_badge)): ?>
                                        <?php echo $status_badge; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($company_name)): ?>
                                    <div class="jpm-job-card-meta"><span class="jpm-job-company"> <i
                                                class="dashicons dashicons-building"></i><?php echo esc_html($company_name); ?> </span>
                                    </div>
                                <?php endif; ?>
                                <div class="jpm-job-card-info">
                                    <?php if (!empty($location)): ?><span class="jpm-job-info-item"> <i
                                                class="dashicons dashicons-location"></i> <?php echo esc_html($location); ?> </span>
                                    <?php endif; ?>
                                    <?php if (!empty($salary)): ?> <span class="jpm-job-info-item"> <i
                                                class="dashicons dashicons-money-alt"></i> <?php echo esc_html($salary); ?> </span>
                                    <?php endif; ?>
                                    <?php if (!empty($duration)): ?> <span class="jpm-job-info-item"> <i
                                                class="dashicons dashicons-clock"></i> <?php echo esc_html($duration); ?> </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($excerpt)): ?>
                                    <p class="jpm-job-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                                <?php endif; ?>
                                <div class="jpm-job-card-footer"> <span class="jpm-job-posted-date"> <i
                                            class="dashicons dashicons-calendar-alt"></i> <?php echo esc_html(get_the_date()); ?>
                                    </span>
                                </div>
                                <div class="jpm-job-card-actions">
                                    <button type="button" class="jpm-btn jpm-btn-quick-view"
                                        data-job-id="<?php echo esc_attr($job_id); ?>"><?php _e('Quick View', 'job-posting-manager'); ?></button>
                                    <a href="<?php echo esc_url($job_link); ?>"
                                        class="jpm-btn jpm-btn-apply"><?php _e('Apply Now', 'job-posting-manager'); ?></a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>

                <!-- Pagination -->
                <?php
                $total_pages = $jobs_query->max_num_pages;
                if ($total_pages > 1):
                    $current_url = remove_query_arg('jpm_page');
                    $base_url = add_query_arg('jpm_page', '%#%', $current_url);
                    ?>
                    <div class="jpm-jobs-pagination">
                        <?php
                        echo paginate_links([
                            'base' => str_replace('%#%', '%#%', $base_url),
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                            'prev_text' => __('&laquo; Previous', 'job-posting-manager'),
                            'next_text' => __('Next &raquo;', 'job-posting-manager'),
                            'type' => 'list',
                            'end_size' => 2,
                            'mid_size' => 1
                        ]);
                        ?>
                    </div>
                <?php endif; ?>

                <?php wp_reset_postdata(); ?>
            <?php else: ?>
                <div class="jpm-no-jobs">
                    <p><?php _e('No jobs found matching your criteria.', 'job-posting-manager'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal for Quick View (reuse from latest_jobs) -->
        <div id="jpm-job-modal" class="jpm-modal">
            <div class="jpm-modal-overlay"></div>
            <div class="jpm-modal-content">
                <button type="button" class="jpm-modal-close"
                    aria-label="<?php esc_attr_e('Close', 'job-posting-manager'); ?>"><span
                        class="dashicons dashicons-no-alt"></span></button>
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
     * AJAX handler for filtering jobs (optional - for dynamic filtering without page reload)
     */
    public function filter_jobs_ajax()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        }

        $per_page = intval($_POST['per_page'] ?? 12);
        $paged = max(1, intval($_POST['paged'] ?? 1));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $location_filter = sanitize_text_field($_POST['location'] ?? '');
        $company_filter = sanitize_text_field($_POST['company'] ?? '');

        $args = [
            'post_type' => 'job_posting',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $meta_query = [];
        if (!empty($location_filter)) {
            $meta_query[] = [
                'key' => 'location',
                'value' => $location_filter,
                'compare' => 'LIKE'
            ];
        }
        if (!empty($company_filter)) {
            $meta_query[] = [
                'key' => 'company_name',
                'value' => $company_filter,
                'compare' => 'LIKE'
            ];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $jobs_query = new WP_Query($args);

        ob_start();
        if ($jobs_query->have_posts()):
            while ($jobs_query->have_posts()):
                $jobs_query->the_post();
                $job_id = get_the_ID();
                $company_name = get_post_meta($job_id, 'company_name', true);
                $location = get_post_meta($job_id, 'location', true);
                $salary = get_post_meta($job_id, 'salary', true);
                $duration = get_post_meta($job_id, 'duration', true);
                $company_image_id = get_post_meta($job_id, 'company_image', true);
                $company_image_url = '';
                if ($company_image_id) {
                    $company_image_url = wp_get_attachment_image_url($company_image_id, 'thumbnail');
                }
                $job_link = get_permalink($job_id);
                $excerpt = wp_trim_words(get_the_excerpt(), 20);
                if (empty($excerpt)) {
                    $excerpt = wp_trim_words(get_the_content(), 20);
                }
                ?>
                <div class="jpm-job-card" data-job-id="<?php echo esc_attr($job_id); ?>">
                    <?php if ($company_image_url): ?>
                        <div class="jpm-job-card-image">
                            <img src="<?php echo esc_url($company_image_url); ?>"
                                alt="<?php echo esc_attr($company_name ?: get_the_title()); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="jpm-job-card-content">
                        <h3 class="jpm-job-card-title">
                            <a href="<?php echo esc_url($job_link); ?>"><?php echo esc_html(get_the_title()); ?></a>
                        </h3>
                        <?php if (!empty($company_name)): ?>
                            <div class="jpm-job-card-meta"><span class="jpm-job-company"><i
                                        class="dashicons dashicons-building"></i><?php echo esc_html($company_name); ?></span>
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
                        <div class="jpm-job-card-footer"> <span class="jpm-job-posted-date"> <i
                                    class="dashicons dashicons-calendar-alt"></i><?php echo esc_html(get_the_date()); ?> </span>
                        </div>
                        <div class="jpm-job-card-actions">
                            <button type="button" class="jpm-btn jpm-btn-quick-view"
                                data-job-id="<?php echo esc_attr($job_id); ?>"><?php _e('Quick View', 'job-posting-manager'); ?></button>
                            <a href="<?php echo esc_url($job_link); ?>"
                                class="jpm-btn jpm-btn-apply"><?php _e('Apply Now', 'job-posting-manager'); ?></a>
                        </div>
                    </div>
                </div>
                <?php
            endwhile;
        endif;
        wp_reset_postdata();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'total' => $jobs_query->found_posts,
            'pages' => $jobs_query->max_num_pages
        ]);
    }

    /**
     * Shortcode to display application tracker
     * Usage: [application_tracker]
     */
    public function application_tracker_shortcode($atts)
    {
        $atts = shortcode_atts([
            'title' => __('Track Your Application', 'job-posting-manager'),
        ], $atts);

        // Find the job listings page URL
        $jobs_listing_url = '';
        $pages = get_pages();
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'all_jobs')) {
                $jobs_listing_url = get_permalink($page->ID);
                break;
            }
        }
        // If no page found, try post type archive
        if (empty($jobs_listing_url)) {
            $jobs_listing_url = get_post_type_archive_link('job_posting');
        }
        // If still no URL, use fallback
        if (empty($jobs_listing_url)) {
            $jobs_listing_url = home_url('/job-postings/');
        }

        ob_start();
        ?>
        <div class="jpm-application-tracker">
            <div class="jpm-tracker-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <p class="jpm-tracker-intro">
                    <?php _e('We understand the importance of staying informed about your job application status. This page provides you with real-time updates on your application, ensuring transparency throughout the hiring process. You can easily track where your application stands and what to expect next.', 'job-posting-manager'); ?>
                </p>
                <p class="jpm-tracker-description">
                    <?php _e('To track your job application, just enter your application number below:', 'job-posting-manager'); ?>
                </p>
            </div>

            <form class="jpm-tracker-form" id="jpm-tracker-form">
                <div class="jpm-form-group">
                    <label for="application_number">
                        <?php _e('Application Number', 'job-posting-manager'); ?>
                    </label>
                    <input type="text" id="application_number" name="application_number" class="jpm-input"
                        placeholder="<?php esc_attr_e('Enter your application number (e.g., 25-BDO-792*****)', 'job-posting-manager'); ?>"
                        required>
                </div>
                <button type="submit" class="jpm-btn jpm-btn-primary"><span
                        class="jpm-btn-text"><?php _e('Track Application', 'job-posting-manager'); ?></span> <span
                        class="jpm-btn-spinner" style="display: none;"> <span class="spinner is-active"></span> </span>
                </button>
            </form>

            <div class="jpm-tracker-error" id="jpm-tracker-error" style="display: none;"></div>
            <div class="jpm-tracker-results" id="jpm-tracker-results" style="display: none;"></div>

            <div class="jpm-tracker-footer" id="jpm-tracker-footer">
                <h3 class="jpm-tracker-footer-title"><?php _e('Application Status:', 'job-posting-manager'); ?></h3>
                <ul class="jpm-tracker-status-list">
                    <?php
                    // Get all statuses from database
                    $all_statuses = JPM_DB::get_all_statuses_info();
                    if (!empty($all_statuses)):
                        foreach ($all_statuses as $status):
                            $status_name = isset($status['name']) ? $status['name'] : ucfirst($status['slug']);
                            // Format status name to match PSA page style: "Your application is [status]"
                            // Use past tense for completed actions, present for ongoing
                            $status_lower = strtolower($status_name);
                            if (in_array($status_lower, ['accepted', 'rejected', 'reviewed'])) {
                                $status_title = sprintf(__('Your application has been %s', 'job-posting-manager'), $status_lower);
                            } else {
                                $status_title = sprintf(__('Your application is %s', 'job-posting-manager'), $status_lower);
                            }

                            // Get description, allow HTML for links
                            $status_description = isset($status['description']) && !empty($status['description'])
                                ? $status['description']
                                : sprintf(__('This means that your application status is %s.', 'job-posting-manager'), strtolower($status_name));
                            ?>
                            <li class="jpm-tracker-status-item">
                                <strong class="jpm-tracker-status-name"><?php echo esc_html($status_title); ?></strong>
                                <p class="jpm-tracker-status-desc"><?php echo wp_kses_post($status_description); ?></p>
                            </li>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </ul>

                <div class="jpm-tracker-footer-links">
                    <p class="jpm-tracker-footer-text">
                        <?php
                        printf(
                            __('No Application Number? Please proceed to the %s and start your job application.', 'job-posting-manager'),
                            '<a href="' . esc_url($jobs_listing_url) . '">' . __('Job Listings page', 'job-posting-manager') . '</a>'
                        );
                        ?>
                    </p>
                    <?php if (!is_user_logged_in()): ?>
                        <p class="jpm-tracker-footer-text">
                            <?php
                            printf(
                                __('Have an existing account? %s to login.', 'job-posting-manager'),
                                '<a href="' . esc_url(wp_login_url()) . '">' . __('Click here', 'job-posting-manager') . '</a>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to track application
     */
    public function track_application_ajax()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
        }

        $application_number_input = isset($_POST['application_number']) ? sanitize_text_field($_POST['application_number']) : '';

        if (empty($application_number_input)) {
            wp_send_json_error(['message' => __('Please enter a valid application number.', 'job-posting-manager')]);
        }

        $application = $this->get_application_by_number($application_number_input);

        if (!$application) {
            wp_send_json_error(['message' => __('Application not found. Please check your application number and try again.', 'job-posting-manager')]);
        }

        // Render application details HTML
        $html = $this->render_application_details($application);

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Get application by application number
     */
    private function get_application_by_number($application_number_input)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';

        // Search for application by application number in notes field
        $search_term = '%' . $wpdb->esc_like($application_number_input) . '%';

        // First try exact match with common field names
        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE notes LIKE %s",
            '%"application_number":"' . $wpdb->esc_like($application_number_input) . '"%'
        ));

        // If not found, try other field name variations
        if (empty($applications)) {
            $applications = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE notes LIKE %s",
                $search_term
            ));
        }

        // Check each application to find exact match
        foreach ($applications as $app) {
            $form_data = json_decode($app->notes, true);
            if (!is_array($form_data)) {
                continue;
            }

            // Check various field name variations for application number
            $app_number_fields = ['application_number', 'applicationnumber', 'app_number', 'app-number', 'application-number', 'application number', 'reference_number', 'referencenumber', 'reference-number', 'reference number'];

            foreach ($app_number_fields as $field_name) {
                if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                    $stored_app_number = sanitize_text_field($form_data[$field_name]);
                    // Case-insensitive comparison
                    if (strcasecmp(trim($stored_app_number), trim($application_number_input)) === 0) {
                        return $app;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Render application details HTML
     */
    private function render_application_details($application)
    {
        $job = get_post($application->job_id);
        $status_info = JPM_Admin::get_status_by_slug($application->status);

        if ($status_info) {
            $status_name = $status_info['name'];
            $status_color = $status_info['color'];
            $status_text_color = $status_info['text_color'];
        } else {
            $status_name = ucfirst($application->status);
            $status_color = '#ffc107';
            $status_text_color = '#000000';
        }

        // Parse form data from notes
        $form_data = json_decode($application->notes, true);
        if (!is_array($form_data)) {
            $form_data = [];
        }

        // Extract applicant information
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

        // Application number variations
        $app_number_fields = ['application_number', 'applicationnumber', 'app_number', 'app-number', 'application-number', 'application number', 'reference_number', 'referencenumber', 'reference-number', 'reference number'];
        foreach ($app_number_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $application_number = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // Date of registration
        $date_fields = ['date_of_registration', 'dateofregistration', 'date-of-registration', 'date of registration', 'registration_date', 'registrationdate', 'registration-date', 'registration date'];
        foreach ($date_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $date_of_registration = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);

        ob_start();
        ?>
        <div class="jpm-tracker-section">
            <h3><?php _e('Application Information', 'job-posting-manager'); ?></h3>
            <div class="jpm-tracker-info-grid">
                <div class="jpm-tracker-info-row">
                    <span class="jpm-tracker-label"><?php _e('Application ID', 'job-posting-manager'); ?>:</span>
                    <span class="jpm-tracker-value">#<?php echo esc_html($application->id); ?></span>
                </div>

                <?php if (!empty($application_number)): ?>
                    <div class="jpm-tracker-info-row">
                        <span class="jpm-tracker-label"><?php _e('Application Number', 'job-posting-manager'); ?>:</span>
                        <span class="jpm-tracker-value"><?php echo esc_html($application_number); ?></span>
                    </div>
                <?php endif; ?>

                <div class="jpm-tracker-info-row">
                    <span class="jpm-tracker-label"><?php _e('Application Date', 'job-posting-manager'); ?>:</span>
                    <span class="jpm-tracker-value">
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?>
                    </span>
                </div>

                <div class="jpm-tracker-info-row">
                    <span class="jpm-tracker-label"><?php _e('Status', 'job-posting-manager'); ?>:</span>
                    <span class="jpm-tracker-value">
                        <span class="jpm-status-badge jpm-status-<?php echo esc_attr($application->status); ?>"
                            style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                            <?php echo esc_html($status_name); ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <div class="jpm-tracker-section">
            <h3><?php _e('Job Information', 'job-posting-manager'); ?></h3>
            <div class="jpm-tracker-info-grid">
                <div class="jpm-tracker-info-row">
                    <span class="jpm-tracker-label"><?php _e('Job Title', 'job-posting-manager'); ?>:</span>
                    <span class="jpm-tracker-value">
                        <?php if ($job): ?>
                            <a href="<?php echo esc_url(get_permalink($job->ID)); ?>" target="_blank">
                                <?php echo esc_html($job->post_title); ?>
                            </a>
                        <?php else: ?>
                            <?php _e('Job Deleted', 'job-posting-manager'); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="jpm-tracker-info-row">
                    <span class="jpm-tracker-label"><?php _e('Job ID', 'job-posting-manager'); ?>:</span>
                    <span class="jpm-tracker-value">#<?php echo esc_html($application->job_id); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($full_name) || !empty($email)): ?>
            <div class="jpm-tracker-section">
                <h3><?php _e('Applicant Information', 'job-posting-manager'); ?></h3>
                <div class="jpm-tracker-info-grid">
                    <?php if (!empty($full_name)): ?>
                        <div class="jpm-tracker-info-row">
                            <span class="jpm-tracker-label"><?php _e('Full Name', 'job-posting-manager'); ?>:</span>
                            <span class="jpm-tracker-value"><?php echo esc_html($full_name); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($email)): ?>
                        <div class="jpm-tracker-info-row">
                            <span class="jpm-tracker-label"><?php _e('Email', 'job-posting-manager'); ?>:</span>
                            <span class="jpm-tracker-value"><?php echo esc_html($email); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($date_of_registration)): ?>
                        <div class="jpm-tracker-info-row">
                            <span class="jpm-tracker-label"><?php _e('Date of Registration', 'job-posting-manager'); ?>:</span>
                            <span class="jpm-tracker-value"><?php echo esc_html($date_of_registration); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Registration form shortcode
     * Usage: [jpm_register title="Create Account" redirect_url="/login/"]
     */
    public function register_shortcode($atts)
    {
        // If user is already logged in, show message
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return '<div class="jpm-register-message"><p>' . sprintf(__('You are already logged in as %s. <a href="%s">Logout</a> to create a new account.', 'job-posting-manager'), esc_html($current_user->display_name), wp_logout_url(home_url())) . '</p></div>';
        }

        $atts = shortcode_atts([
            'title' => __('Create Account', 'job-posting-manager'),
            'redirect_url' => '',
        ], $atts);

        ob_start();
        ?>
        <div class="jpm-register-form-wrapper">
            <div class="jpm-register-form-container">
                <div class="jpm-register-header">
                    <div class="jpm-register-logo">
                        <?php
                        $bdo_logo_url = JPM_PLUGIN_URL . 'assets/images/BDO-Favicon.png';
                        echo '<img src="' . esc_url($bdo_logo_url) . '" alt="BDO" class="jpm-logo-image" />';
                        ?>
                    </div>
                    <h2 class="jpm-register-title"><?php echo esc_html($atts['title']); ?></h2>
                </div>

                <div id="jpm-register-message" class="jpm-register-message" style="display: none;"></div>

                <form id="jpm-register-form" class="jpm-register-form">
                    <?php wp_nonce_field('jpm_register', 'jpm_register_nonce'); ?>
                    <input type="hidden" name="redirect_url" value="<?php echo esc_attr($atts['redirect_url']); ?>" />

                    <div class="jpm-form-row">
                        <div class="jpm-form-field jpm-form-field-half" style="margin-bottom: 0;">
                            <label for="jpm-register-first-name" class="jpm-input-label">
                                <?php _e('First Name', 'job-posting-manager'); ?> <span class="required">*</span>
                            </label>
                            <div class="jpm-input-wrapper">
                                <input type="text" id="jpm-register-first-name" name="first_name" required class="jpm-input"
                                    placeholder="<?php esc_attr_e('Enter your first name', 'job-posting-manager'); ?>" />
                            </div>
                        </div>

                        <div class="jpm-form-field jpm-form-field-half" style="margin-bottom: 0;">
                            <label for="jpm-register-last-name" class="jpm-input-label">
                                <?php _e('Last Name', 'job-posting-manager'); ?> <span class="required">*</span>
                            </label>
                            <div class="jpm-input-wrapper">
                                <input type="text" id="jpm-register-last-name" name="last_name" required class="jpm-input"
                                    placeholder="<?php esc_attr_e('Enter your last name', 'job-posting-manager'); ?>" />
                            </div>
                        </div>
                    </div>

                    <div class="jpm-form-field">
                        <label for="jpm-register-email" class="jpm-input-label">
                            <?php _e('Email Address', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper">
                            <input type="email" id="jpm-register-email" name="email" required class="jpm-input"
                                placeholder="<?php esc_attr_e('your.email@example.com', 'job-posting-manager'); ?>" />
                        </div>
                    </div>

                    <div class="jpm-form-field">
                        <label for="jpm-register-password" class="jpm-input-label">
                            <?php _e('Password', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper jpm-password-wrapper">
                            <input type="password" id="jpm-register-password" name="password" required class="jpm-input"
                                minlength="8"
                                placeholder="<?php esc_attr_e('Create a strong password', 'job-posting-manager'); ?>" />
                            <button type="button" class="jpm-password-toggle"
                                aria-label="<?php esc_attr_e('Show password', 'job-posting-manager'); ?>">
                                <svg class="jpm-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg class="jpm-eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="display: none;">
                                    <path
                                        d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
                                    </path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                        <div class="jpm-password-strength" id="jpm-password-strength" style="display: none;">
                            <div class="jpm-password-strength-bar">
                                <div class="jpm-password-strength-fill" id="jpm-password-strength-fill"></div>
                            </div>
                            <div class="jpm-password-strength-text" id="jpm-password-strength-text"></div>
                        </div>
                    </div>

                    <div class="jpm-form-field">
                        <label for="jpm-register-password-confirm" class="jpm-input-label">
                            <?php _e('Confirm Password', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper jpm-password-wrapper">
                            <input type="password" id="jpm-register-password-confirm" name="password_confirm" required
                                class="jpm-input"
                                placeholder="<?php esc_attr_e('Re-enter your password', 'job-posting-manager'); ?>" />
                            <button type="button" class="jpm-password-toggle"
                                aria-label="<?php esc_attr_e('Show password', 'job-posting-manager'); ?>">
                                <svg class="jpm-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg class="jpm-eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="display: none;">
                                    <path
                                        d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
                                    </path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                        <div id="jpm-password-match" class="jpm-password-match" style="display: none;"></div>
                    </div>

                    <div class="jpm-form-field">
                        <button type="submit" id="jpm-register-submit"
                            class="jpm-btn jpm-btn-primary jpm-btn-block jpm-btn-large">
                            <span class="jpm-btn-text"><?php _e('Create Account', 'job-posting-manager'); ?></span>
                        </button>
                    </div>

                    <div class="jpm-register-footer">
                        <p class="jpm-register-footer-text">
                            <?php _e('Already have an account?', 'job-posting-manager'); ?>
                            <a href="<?php echo esc_url(home_url('/sign-in/')); ?>" class="jpm-register-login-link">
                                <?php _e('Sign in', 'job-posting-manager'); ?>
                            </a>
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <style>
            .jpm-register-form-wrapper {
                max-width: 480px;
                margin: 30px auto;
                padding: 15px;
            }

            .jpm-register-form-container {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .jpm-register-header {
                padding: 24px 24px 16px;
                border-bottom: 1px solid #e5e7eb;
                text-align: center;
            }

            .jpm-register-logo {
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .jpm-logo-image {
                max-height: 60px;
                width: auto;
                height: auto;
                object-fit: contain;
            }

            .jpm-logo-text {
                width: 50px;
                height: 50px;
                border-radius: 6px;
                background: #2563eb;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                font-weight: 700;
                letter-spacing: 1px;
            }

            .jpm-register-title {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-register-form {
                padding: 24px;
            }

            .jpm-register-message {
                margin: 0 24px 16px;
            }

            .jpm-register-message .notice {
                margin: 0;
                padding: 14px 18px;
                border-radius: 8px;
                border: none;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .jpm-register-message .notice-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
            }

            .jpm-register-message .notice-success p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-register-message .notice-error {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: #ffffff;
            }

            .jpm-register-message .notice-error p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-form-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                padding-inline: 10px;
            }

            .jpm-form-field {
                margin-bottom: 16px;
            }

            .jpm-form-field-half {
                margin-bottom: 16px;
            }

            .jpm-register-form .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-register-form .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-register-form .jpm-input-wrapper {
                position: relative;
            }

            .jpm-register-form .jpm-input {
                width: 100%;
                padding: 8px 12px;
                border: none !important;
                border-bottom: 2px solid #e5e7eb !important;
                border-radius: 0;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
                font-size: 14px;
                color: #111827;
                background: transparent;
                box-sizing: border-box;
                transition: border-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-register-form .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-register-form .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-register-form .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626 !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-password-wrapper {
                position: relative;
            }

            .jpm-password-toggle {
                position: absolute;
                right: 0;
                bottom: 8px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 4px;
                color: #6b7280;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.15s ease;
            }

            .jpm-password-toggle:hover {
                color: #374151;
            }

            .jpm-password-toggle:focus {
                outline: none;
                color: #2563eb;
            }

            .jpm-password-strength {
                margin-top: 6px;
            }

            .jpm-password-strength-bar {
                height: 3px;
                background: #e5e7eb;
                border-radius: 2px;
                overflow: hidden;
                margin-bottom: 6px;
            }

            .jpm-password-strength-fill {
                height: 100%;
                width: 0%;
                background: #e5e7eb;
                border-radius: 2px;
                transition: width 0.2s ease, background-color 0.2s ease;
            }

            .jpm-password-strength-fill.weak {
                width: 33%;
                background: #ef4444;
            }

            .jpm-password-strength-fill.medium {
                width: 66%;
                background: #f59e0b;
            }

            .jpm-password-strength-fill.strong {
                width: 100%;
                background: #22c55e;
            }

            .jpm-password-strength-text {
                font-size: 12px;
                font-weight: 500;
                margin-top: 2px;
            }

            .jpm-password-strength-text.weak {
                color: #ef4444;
            }

            .jpm-password-strength-text.medium {
                color: #f59e0b;
            }

            .jpm-password-strength-text.strong {
                color: #22c55e;
            }

            .jpm-password-match {
                margin-top: 4px;
                font-size: 13px;
                font-weight: 500;
            }

            .jpm-password-match.match {
                color: #22c55e;
            }

            .jpm-password-match.no-match {
                color: #dc2626;
            }

            .jpm-field-description {
                display: block;
                font-size: 13px;
                color: #6b7280;
                margin-top: 4px;
            }

            .jpm-btn {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                text-decoration: none;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-btn-primary {
                background: #2563eb;
                color: #ffffff;
            }

            .jpm-btn-primary:hover:not(:disabled) {
                background: #1d4ed8;
            }

            .jpm-btn-primary:active:not(:disabled) {
                background: #1e40af;
            }

            .jpm-btn-block {
                width: 100%;
                display: block;
            }

            .jpm-btn-large {
                padding: 12px 24px;
                font-size: 15px;
            }

            .jpm-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .jpm-register-footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }

            .jpm-register-footer-text {
                margin: 0;
                font-size: 14px;
                color: #6b7280;
            }

            .jpm-register-login-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 500;
                margin-left: 4px;
            }

            .jpm-register-login-link:hover {
                text-decoration: underline;
            }

            @media (max-width: 600px) {
                .jpm-register-form-wrapper {
                    margin: 15px auto;
                    padding: 10px;
                }

                .jpm-register-header {
                    padding: 20px 20px 16px;
                }

                .jpm-register-form {
                    padding: 20px;
                }

                .jpm-register-message {
                    margin: 0 20px 12px;
                }

                .jpm-form-row {
                    grid-template-columns: 1fr;
                    gap: 0;
                }

                .jpm-form-field-half {
                    margin-bottom: 16px;
                }

                .jpm-register-title {
                    font-size: 18px;
                }

                .jpm-logo-image {
                    max-height: 45px;
                }

                .jpm-logo-text {
                    width: 45px;
                    height: 45px;
                    font-size: 18px;
                }

                .jpm-form-field {
                    margin-bottom: 14px;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                // Password toggle functionality
                $('.jpm-password-toggle').on('click', function () {
                    var $button = $(this);
                    var $input = $button.closest('.jpm-password-wrapper').find('input');
                    var $eyeIcon = $button.find('.jpm-eye-icon');
                    var $eyeOffIcon = $button.find('.jpm-eye-off-icon');

                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $eyeIcon.hide();
                        $eyeOffIcon.show();
                        $button.attr('aria-label', '<?php echo esc_js(__('Hide password', 'job-posting-manager')); ?>');
                    } else {
                        $input.attr('type', 'password');
                        $eyeIcon.show();
                        $eyeOffIcon.hide();
                        $button.attr('aria-label', '<?php echo esc_js(__('Show password', 'job-posting-manager')); ?>');
                    }
                });

                // Real-time password strength indicator
                $('#jpm-register-password').on('input', function () {
                    var password = $(this).val();
                    var $strengthContainer = $('#jpm-password-strength');
                    var $strengthFill = $('#jpm-password-strength-fill');
                    var $strengthText = $('#jpm-password-strength-text');

                    if (password.length === 0) {
                        $strengthContainer.hide();
                        return;
                    }

                    $strengthContainer.show();

                    // Calculate strength score
                    var score = 0;
                    var feedback = [];

                    // Length checks
                    if (password.length >= 8) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('At least 8 characters', 'job-posting-manager')); ?>');
                    }

                    if (password.length >= 12) {
                        score += 1;
                    }

                    // Character variety checks
                    if (/[a-z]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Lowercase letter', 'job-posting-manager')); ?>');
                    }

                    if (/[A-Z]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Uppercase letter', 'job-posting-manager')); ?>');
                    }

                    if (/\d/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Number', 'job-posting-manager')); ?>');
                    }

                    if (/[^a-zA-Z\d]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Special character', 'job-posting-manager')); ?>');
                    }

                    // Determine strength level
                    var strengthLevel = '';
                    var strengthLabel = '';
                    var strengthWidth = '0%';

                    if (score <= 2) {
                        strengthLevel = 'weak';
                        strengthLabel = '<?php echo esc_js(__('Weak', 'job-posting-manager')); ?>';
                        strengthWidth = '33%';
                    } else if (score <= 4) {
                        strengthLevel = 'medium';
                        strengthLabel = '<?php echo esc_js(__('Medium', 'job-posting-manager')); ?>';
                        strengthWidth = '66%';
                    } else {
                        strengthLevel = 'strong';
                        strengthLabel = '<?php echo esc_js(__('Strong', 'job-posting-manager')); ?>';
                        strengthWidth = '100%';
                    }

                    // Update visual indicator
                    $strengthFill.removeClass('weak medium strong').addClass(strengthLevel).css('width', strengthWidth);

                    // Update text feedback
                    if (feedback.length > 0 && score < 5) {
                        $strengthText.removeClass('weak medium strong').addClass(strengthLevel)
                            .html('<span style="font-weight: 600;">' + strengthLabel + '</span> - <?php echo esc_js(__('Add:', 'job-posting-manager')); ?> ' + feedback.slice(0, 2).join(', '));
                    } else {
                        $strengthText.removeClass('weak medium strong').addClass(strengthLevel)
                            .html('<span style="font-weight: 600;">' + strengthLabel + '</span>');
                    }
                });

                // Password match indicator
                $('#jpm-register-password-confirm').on('input', function () {
                    var password = $('#jpm-register-password').val();
                    var confirmPassword = $(this).val();
                    var $matchIndicator = $('#jpm-password-match');

                    if (confirmPassword.length === 0) {
                        $matchIndicator.hide();
                        return;
                    }

                    if (password === confirmPassword) {
                        $matchIndicator.html('✓ <?php echo esc_js(__('Passwords match', 'job-posting-manager')); ?>').removeClass('no-match').addClass('match').show();
                    } else {
                        $matchIndicator.html('✗ <?php echo esc_js(__('Passwords do not match', 'job-posting-manager')); ?>').removeClass('match').addClass('no-match').show();
                    }
                });

                // Form submission
                $('#jpm-register-form').on('submit', function (e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $message = $('#jpm-register-message');
                    var $button = $('#jpm-register-submit');
                    var $btnText = $button.find('.jpm-btn-text');

                    // Get form values
                    var password = $('#jpm-register-password').val();
                    var passwordConfirm = $('#jpm-register-password-confirm').val();

                    // Validate passwords match
                    if (password !== passwordConfirm) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Passwords do not match.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    // Validate password length
                    if (password.length < 8) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Password must be at least 8 characters long.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    // Disable button and show loading
                    $button.prop('disabled', true);
                    $btnText.text('<?php echo esc_js(__('Creating Account...', 'job-posting-manager')); ?>');
                    $message.hide();

                    // Send AJAX request
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_register',
                            first_name: $('#jpm-register-first-name').val(),
                            last_name: $('#jpm-register-last-name').val(),
                            email: $('#jpm-register-email').val(),
                            password: password,
                            redirect_url: $('input[name="redirect_url"]').val(),
                            nonce: $('#jpm_register_nonce').val()
                        },
                        success: function (response) {
                            if (response.success) {
                                $message.html('<div class="notice notice-success"><p>' + (response.data.message || '<?php echo esc_js(__('Account created successfully! Redirecting...', 'job-posting-manager')); ?>') + '</p></div>').show();

                                // Redirect after 2 seconds
                                setTimeout(function () {
                                    if (response.data.redirect_url) {
                                        window.location.href = response.data.redirect_url;
                                    } else {
                                        window.location.reload();
                                    }
                                }, 2000);
                            } else {
                                $message.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php echo esc_js(__('Failed to create account. Please try again.', 'job-posting-manager')); ?>') + '</p></div>').show();
                                $button.prop('disabled', false);
                                $btnText.text('<?php echo esc_js(__('Create Account', 'job-posting-manager')); ?>');
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('An error occurred. Please try again.', 'job-posting-manager')); ?></p></div>').show();
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo esc_js(__('Create Account', 'job-posting-manager')); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle user registration via AJAX
     */
    public function handle_registration()
    {
        check_ajax_referer('jpm_register', 'nonce');

        // If user is already logged in
        if (is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are already logged in.', 'job-posting-manager')]);
        }

        // Get form data
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $redirect_url = esc_url_raw($_POST['redirect_url'] ?? '');

        // Validate required fields
        if (empty($first_name)) {
            wp_send_json_error(['message' => __('First name is required.', 'job-posting-manager')]);
        }

        if (empty($last_name)) {
            wp_send_json_error(['message' => __('Last name is required.', 'job-posting-manager')]);
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'job-posting-manager')]);
        }

        if (empty($password) || strlen($password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters long.', 'job-posting-manager')]);
        }

        // Check if email already exists
        if (email_exists($email)) {
            wp_send_json_error(['message' => __('An account with this email address already exists. Please login instead.', 'job-posting-manager')]);
        }

        // Create username from email
        $username = sanitize_user($email, true);
        $original_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }

        // Create user
        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Set user role
        $user = new WP_User($user_id);
        if (get_role('customer')) {
            $user->set_role('customer');
        } else {
            $user->set_role('subscriber');
        }

        // Set user meta
        update_user_meta($user_id, 'first_name', $first_name);
        update_user_meta($user_id, 'last_name', $last_name);

        // Update display name
        wp_update_user([
            'ID' => $user_id,
            'display_name' => trim($first_name . ' ' . $last_name),
            'first_name' => $first_name,
            'last_name' => $last_name
        ]);

        // Send account creation email
        if (class_exists('JPM_Emails')) {
            try {
                JPM_Emails::send_account_creation_notification($user_id, $email, $password, $first_name, $last_name);
            } catch (Exception $e) {
                error_log('JPM: Failed to send account creation email - ' . $e->getMessage());
            }
        }

        // Send new customer notification to admin
        if (class_exists('JPM_Emails')) {
            try {
                $email_settings = get_option('jpm_email_settings', []);
                $admin_email = !empty($email_settings['recipient_email']) ? $email_settings['recipient_email'] : get_option('admin_email');
                JPM_Emails::send_new_customer_notification($user_id, $email, $first_name, $last_name, $admin_email);
            } catch (Exception $e) {
                error_log('JPM: Failed to send new customer notification - ' . $e->getMessage());
            }
        }

        // Redirect to login page (do not auto-login)
        $final_redirect = home_url('/sign-in/');

        wp_send_json_success([
            'message' => __('Account created successfully! Please login to continue.', 'job-posting-manager'),
            'redirect_url' => $final_redirect
        ]);
    }

    /**
     * Login form shortcode
     * Usage: [jpm_login title="Sign In" redirect_url="/dashboard/"]
     */
    public function login_shortcode($atts)
    {
        // If user is already logged in, show message
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return '<div class="jpm-login-message"><p>' . sprintf(__('You are already logged in as %s. <a href="%s">Logout</a> to login as a different user.', 'job-posting-manager'), esc_html($current_user->display_name), wp_logout_url(home_url())) . '</p></div>';
        }

        $atts = shortcode_atts([
            'title' => __('Welcome Back!', 'job-posting-manager'),
            'redirect_url' => '',
        ], $atts);

        ob_start();
        ?>
        <div class="jpm-login-form-wrapper">
            <div class="jpm-login-form-container">
                <div class="jpm-login-header">
                    <div class="jpm-login-logo">
                        <?php
                        $bdo_logo_url = JPM_PLUGIN_URL . 'assets/images/BDO-Favicon.png';
                        echo '<img src="' . esc_url($bdo_logo_url) . '" alt="BDO" class="jpm-logo-image" style="max-height: 60px;"/>';
                        ?>
                    </div>
                    <h2 class="jpm-login-title"><?php echo esc_html($atts['title']); ?></h2>
                </div>

                <div id="jpm-login-message" class="jpm-login-message" style="display: none;"></div>

                <form id="jpm-login-form" class="jpm-login-form" method="post" action="">
                    <?php wp_nonce_field('jpm_login', 'jpm_login_nonce'); ?>
                    <input type="hidden" name="redirect_url" value="<?php echo esc_attr($atts['redirect_url']); ?>" />

                    <div class="jpm-form-field">
                        <label for="jpm-login-email" class="jpm-input-label">
                            <?php _e('Username or Email Address', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper">
                            <input type="text" id="jpm-login-email" name="email" required class="jpm-input"
                                placeholder="<?php esc_attr_e('username or your.email@example.com', 'job-posting-manager'); ?>" />
                        </div>
                    </div>

                    <div class="jpm-form-field">
                        <label for="jpm-login-password" class="jpm-input-label">
                            <?php _e('Password', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper jpm-password-wrapper">
                            <input type="password" id="jpm-login-password" name="password" required class="jpm-input"
                                placeholder="<?php esc_attr_e('Enter your password', 'job-posting-manager'); ?>" />
                            <button type="button" class="jpm-password-toggle"
                                aria-label="<?php esc_attr_e('Show password', 'job-posting-manager'); ?>">
                                <svg class="jpm-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg class="jpm-eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="display: none;">
                                    <path
                                        d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
                                    </path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="jpm-form-field jpm-login-options">
                        <label class="jpm-checkbox-label">
                            <input type="checkbox" id="jpm-login-remember" name="remember" value="1" />
                            <span><?php _e('Remember me', 'job-posting-manager'); ?></span>
                        </label>
                        <a href="<?php echo esc_url(home_url('/forgot-password/')); ?>" class="jpm-forgot-password-link">
                            <?php _e('Forgot Password?', 'job-posting-manager'); ?>
                        </a>
                    </div>

                    <div class="jpm-form-field">
                        <button type="submit" id="jpm-login-submit" class="jpm-btn jpm-btn-primary jpm-btn-block jpm-btn-large">
                            <span class="jpm-btn-text"><?php _e('Sign In', 'job-posting-manager'); ?></span>
                        </button>
                    </div>

                    <div class="jpm-login-footer">
                        <p class="jpm-login-footer-text">
                            <?php _e('Don\'t have an account?', 'job-posting-manager'); ?>
                            <a href="<?php echo esc_url(home_url('/sign-up/')); ?>" class="jpm-login-register-link">
                                <?php _e('Create Account', 'job-posting-manager'); ?>
                            </a>
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <style>
            .jpm-login-form-wrapper {
                max-width: 480px;
                margin: 30px auto;
                padding: 15px;
            }

            .jpm-login-form-container {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .jpm-login-header {
                padding: 24px 24px 16px;
                border-bottom: 1px solid #e5e7eb;
                text-align: center;
            }

            .jpm-login-logo {
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .jpm-login-title {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-login-form {
                padding: 24px;
            }

            .jpm-login-message {
                margin: 0 24px 16px;
            }

            .jpm-login-message .notice {
                margin: 0;
                padding: 14px 18px;
                border-radius: 8px;
                border: none;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .jpm-login-message .notice-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
            }

            .jpm-login-message .notice-success p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-login-message .notice-error {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: #ffffff;
            }

            .jpm-login-message .notice-error p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-login-options {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .jpm-checkbox-label {
                display: flex;
                align-items: center;
                cursor: pointer;
                font-size: 13px;
                color: #374151;
                margin: 0;
            }

            .jpm-checkbox-label input[type="checkbox"] {
                margin-right: 6px;
                cursor: pointer;
            }

            .jpm-forgot-password-link {
                color: #2563eb;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
            }

            .jpm-forgot-password-link:hover {
                text-decoration: underline;
            }

            .jpm-login-footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }

            .jpm-login-footer-text {
                margin: 0;
                font-size: 14px;
                color: #6b7280;
            }

            .jpm-login-register-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 500;
                margin-left: 4px;
            }

            .jpm-login-register-link:hover {
                text-decoration: underline;
            }

            .jpm-login-form .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-login-form .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-login-form .jpm-input-wrapper {
                position: relative;
            }

            .jpm-login-form .jpm-input {
                width: 100%;
                padding: 8px 12px;
                border: none !important;
                border-bottom: 2px solid #e5e7eb !important;
                border-radius: 0;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
                font-size: 14px;
                color: #111827;
                background: transparent;
                box-sizing: border-box;
                transition: border-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-login-form .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-login-form .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-login-form .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626 !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-password-wrapper {
                position: relative;
            }

            .jpm-password-toggle {
                position: absolute;
                right: 0;
                bottom: 8px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 4px;
                color: #6b7280;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.15s ease;
            }

            .jpm-password-toggle:hover {
                color: #374151;
            }

            .jpm-password-toggle:focus {
                outline: none;
                color: #2563eb;
            }

            .jpm-form-field {
                margin-bottom: 16px;
            }

            .jpm-btn {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                text-decoration: none;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-btn-primary {
                background: #2563eb;
                color: #ffffff;
            }

            .jpm-btn-primary:hover:not(:disabled) {
                background: #1d4ed8;
            }

            .jpm-btn-primary:active:not(:disabled) {
                background: #1e40af;
            }

            .jpm-btn-block {
                width: 100%;
                display: block;
            }

            .jpm-btn-large {
                padding: 10px 20px;
                font-size: 14px;
            }

            .jpm-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            @media (max-width: 600px) {
                .jpm-login-form-wrapper {
                    margin: 15px auto;
                    padding: 10px;
                }

                .jpm-login-header {
                    padding: 20px 20px 16px;
                }

                .jpm-login-form {
                    padding: 20px;
                }

                .jpm-login-message {
                    margin: 0 20px 12px;
                }

                .jpm-login-title {
                    font-size: 18px;
                }

                .jpm-login-options {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 8px;
                }
            }
        </style>

        <?php
        // Prepare all JavaScript values in PHP first
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $register_url = esc_url(home_url('/register/'));
        $hide_password = esc_js(__('Hide password', 'job-posting-manager'));
        $show_password = esc_js(__('Show password', 'job-posting-manager'));
        $signing_in = esc_js(__('Signing In...', 'job-posting-manager'));
        $sign_in = esc_js(__('Sign In', 'job-posting-manager'));
        $login_success = esc_js(__('Login successful! Redirecting...', 'job-posting-manager'));
        $invalid_creds = esc_js(__('Invalid email or password. Please try again.', 'job-posting-manager'));
        $error_occurred = esc_js(__('An error occurred. Please try again.', 'job-posting-manager'));
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Clean URL on page load - remov        eany query parameters
                if (window.location.search) {
                    var cleanUrl = window.location.pathname;
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, document.title, cleanUrl);
                    }
                }

                // Password toggle functionality
                $('#jpm-login-form .jpm-password-toggle').on('click', function () {
                    var $button = $(this);
                    var $input = $button.closest('.jpm-password-wrapper').find('input');
                    var $eyeIcon = $button.find('.jpm-eye-icon');
                    var $eyeOffIcon = $button.find('.jpm-eye-off-icon');

                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $eyeIcon.hide();
                        $eyeOffIcon.show();
                        $button.attr('aria-label', '<?php echo $hide_password; ?>');
                    } else {
                        $input.attr('type', 'password');
                        $eyeIcon.show();
                        $eyeOffIcon.hide();
                        $button.attr('aria-label', '<?php echo $show_password; ?>');
                    }
                });

                // Handle Enter key submission
                $('#jpm-login-form input').on('keypress', function (e) {
                    if (e.which === 13 || e.keyCode === 13) { // Enter key
                        e.preventDefault();
                        e.stopPropagation();
                        $('#jpm-login-form').trigger('submit');
                        return false;
                    }
                });

                // Form submission
                $('#jpm-login-form').on('submit', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var $form = $(this);
                    var $message = $('#jpm-login-message');
                    var $button = $('#jpm-login-submit');
                    var $btnText = $button.find('.jpm-btn-text');

                    $button.prop('disabled', true);
                    $btnText.text('<?php echo $signing_in; ?>');
                    $message.hide();

                    $.ajax({
                        url: '<?php echo $ajax_url; ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_login',
                            email: $('#jpm-login-email').val(),
                            password: $('#jpm-login-password').val(),
                            remember: $('#jpm-login-remember').is(':checked') ? 1 : 0,
                            redirect_url: $('input[name="redirect_url"]').val(),
                            nonce: $('#jpm_login_nonce').val()
                        },
                        success: function (response) {
                            if (response.success) {
                                $message.html('<div class="notice notice-success"><p>' + (response.data.message || '<?php echo $login_success; ?>') + '</p></div>').show();
                                setTimeout(function () {
                                    // Clear any query parameters from URL before redirecting
                                    var redirectUrl = response.data.redirect_url || window.location.pathname;
                                    // Remove query parameters
                                    redirectUrl = redirectUrl.split('?')[0];
                                    window.location.href = redirectUrl;
                                }, 1000);
                            } else {
                                $message.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php echo $invalid_creds; ?>') + '</p></div>').show();
                                $button.prop('disabled', false);
                                $btnText.text('<?php echo $sign_in; ?>');
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error"><p><?php echo $error_occurred; ?></p></div>').show();
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo $sign_in; ?>');
                        }
                    });

                    return false;
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Forgot password form shortcode
     * Usage: [jpm_forgot_password title="Reset Password"]
     */
    public function forgot_password_shortcode($atts)
    {
        // If user is already logged in, show message
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return '<div class="jpm-forgot-password-message"><p>' . sprintf(__('You are already logged in as %s. <a href="%s">Logout</a> to reset password for a different account.', 'job-posting-manager'), esc_html($current_user->display_name), wp_logout_url(home_url())) . '</p></div>';
        }

        $atts = shortcode_atts([
            'title' => __('Reset Password', 'job-posting-manager'),
        ], $atts);

        $ajax_url = admin_url('admin-ajax.php');
        $sending = esc_js(__('Sending...', 'job-posting-manager'));
        $send_link = esc_js(__('Send Reset Link', 'job-posting-manager'));
        $error_occurred = esc_js(__('An error occurred. Please try again.', 'job-posting-manager'));

        ob_start();
        ?>
        <div class="jpm-forgot-password-form-wrapper">
            <div class="jpm-forgot-password-form-container">
                <div class="jpm-forgot-password-header">
                    <div class="jpm-forgot-password-logo">
                        <?php
                        $bdo_logo_url = JPM_PLUGIN_URL . 'assets/images/BDO-Favicon.png';
                        echo '<img src="' . esc_url($bdo_logo_url) . '" alt="BDO" class="jpm-logo-image" style="max-height: 60px;"/>';
                        ?>
                    </div>
                    <h2 class="jpm-forgot-password-title"><?php echo esc_html($atts['title']); ?></h2>
                </div>

                <div id="jpm-forgot-password-message" class="jpm-forgot-password-message" style="display: none;"></div>

                <form id="jpm-forgot-password-form" class="jpm-forgot-password-form">
                    <?php wp_nonce_field('jpm_forgot_password', 'jpm_forgot_password_nonce'); ?>

                    <div class="jpm-form-field">
                        <label for="jpm-forgot-password-email" class="jpm-input-label">
                            <?php _e('Email Address', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper">
                            <input type="email" id="jpm-forgot-password-email" name="email" required class="jpm-input"
                                placeholder="<?php esc_attr_e('your.email@example.com', 'job-posting-manager'); ?>" />
                        </div>
                        <p class="jpm-forgot-password-description">
                            <?php _e('Enter your email address and we\'ll send you a secure link to reset your password. Click the link in the email to set a new password.', 'job-posting-manager'); ?>
                        </p>
                    </div>

                    <div class="jpm-form-field">
                        <button type="submit" id="jpm-forgot-password-submit"
                            class="jpm-btn jpm-btn-primary jpm-btn-block jpm-btn-large">
                            <span class="jpm-btn-text"><?php _e('Send Reset Link', 'job-posting-manager'); ?></span>
                        </button>
                    </div>

                    <div class="jpm-forgot-password-footer">
                        <p class="jpm-forgot-password-footer-text">
                            <?php _e('Remember your password?', 'job-posting-manager'); ?>
                            <a href="<?php echo esc_url(home_url('/sign-in/')); ?>" class="jpm-forgot-password-login-link">
                                <?php _e('Sign In', 'job-posting-manager'); ?>
                            </a>
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <style>
            .jpm-forgot-password-form-wrapper {
                max-width: 480px;
                margin: 30px auto;
                padding: 15px;
            }

            .jpm-forgot-password-form-container {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .jpm-forgot-password-header {
                padding: 24px 24px 16px;
                border-bottom: 1px solid #e5e7eb;
                text-align: center;
            }

            .jpm-forgot-password-logo {
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .jpm-forgot-password-title {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-forgot-password-form {
                padding: 24px;
            }

            .jpm-forgot-password-message {
                margin: 0 24px 16px;
            }

            .jpm-forgot-password-message .notice {
                margin: 0;
                padding: 14px 18px;
                border-radius: 8px;
                border: none;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .jpm-forgot-password-message .notice-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
            }

            .jpm-forgot-password-message .notice-success p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-forgot-password-message .notice-error {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: #ffffff;
            }

            .jpm-forgot-password-message .notice-error p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-forgot-password-description {
                margin: 8px 0 0;
                font-size: 13px;
                color: #6b7280;
                line-height: 1.5;
            }

            .jpm-forgot-password-form .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-forgot-password-form .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-forgot-password-form .jpm-input-wrapper {
                position: relative;
            }

            .jpm-forgot-password-form .jpm-input {
                width: 100%;
                padding: 8px 12px;
                border: none !important;
                border-bottom: 2px solid #e5e7eb !important;
                border-radius: 0;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
                font-size: 14px;
                color: #111827;
                background: transparent;
                box-sizing: border-box;
                transition: border-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-forgot-password-form .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-forgot-password-form .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-forgot-password-form .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626 !important;
                border-top: none !important;
                border-left: none !important;
                border-right: none !important;
            }

            .jpm-forgot-password-form .jpm-btn {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                text-decoration: none;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-forgot-password-form .jpm-btn-primary {
                background: #2563eb;
                color: #ffffff;
            }

            .jpm-forgot-password-form .jpm-btn-primary:hover:not(:disabled) {
                background: #1d4ed8;
            }

            .jpm-forgot-password-form .jpm-btn-primary:active:not(:disabled) {
                background: #1e40af;
            }

            .jpm-forgot-password-form .jpm-btn-block {
                width: 100%;
                display: block;
            }

            .jpm-forgot-password-form .jpm-btn-large {
                padding: 10px 20px;
                font-size: 14px;
            }

            .jpm-forgot-password-form .jpm-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .jpm-forgot-password-footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }

            .jpm-forgot-password-footer-text {
                margin: 0;
                font-size: 14px;
                color: #6b7280;
            }

            .jpm-forgot-password-login-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 500;
                margin-left: 4px;
            }

            .jpm-forgot-password-login-link:hover {
                text-decoration: underline;
            }

            @media (max-width: 768px) {
                .jpm-forgot-password-form-wrapper {
                    margin: 20px auto;
                    padding: 10px;
                }

                .jpm-forgot-password-form-container {
                    border-radius: 4px;
                }

                .jpm-forgot-password-header {
                    padding: 20px 20px 12px;
                }

                .jpm-forgot-password-form {
                    padding: 20px;
                }

                .jpm-forgot-password-message {
                    margin: 0 20px 12px;
                }

                .jpm-forgot-password-title {
                    font-size: 18px;
                }
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Form submission
                $('#jpm-forgot-password-form').on('submit', function (e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $message = $('#jpm-forgot-password-message');
                    var $button = $('#jpm-forgot-password-submit');
                    var $btnText = $button.find('.jpm-btn-text');

                    $button.prop('disabled', true);
                    $btnText.text('<?php echo $sending; ?>');
                    $message.hide();

                    $.ajax({
                        url: '<?php echo $ajax_url; ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_forgot_password',
                            email: $('#jpm-forgot-password-email').val(),
                            nonce: $('#jpm_forgot_password_nonce').val()
                        },
                        success: function (response) {
                            // Reset button to normal state for any response
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo $send_link; ?>');
                            if (response.success) {
                                var successMessage = response.data.message || '<?php echo esc_js(__('If an account exists with this email address, a password reset link has been sent. Please check your email and click the link to reset your password.', 'job-posting-manager')); ?>';
                                $message.html('<div class="notice notice-success"><p>' + successMessage + '</p></div>').show();
                                $form[0].reset();
                            } else {
                                $message.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php echo esc_js(__('Invalid email address or user not found.', 'job-posting-manager')); ?>') + '</p></div>').show();
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error"><p><?php echo $error_occurred; ?></p></div>').show();
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo $send_link; ?>');
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Reset password form shortcode
     * Usage: [jpm_reset_password title="Set New Password"]
     */
    public function reset_password_shortcode($atts)
    {
        // If user is already logged in, show message
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return '<div class="jpm-reset-password-message"><p>' . sprintf(__('You are already logged in as %s. <a href="%s">Logout</a> to reset password for a different account.', 'job-posting-manager'), esc_html($current_user->display_name), wp_logout_url(home_url())) . '</p></div>';
        }

        // Get reset key and login from URL
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $login = isset($_GET['login']) ? sanitize_user($_GET['login']) : '';

        // Validate reset key
        $user = null;
        $is_valid = false;
        $error_message = '';

        if (!empty($key) && !empty($login)) {
            $user = check_password_reset_key($key, $login);
            if (is_wp_error($user)) {
                $error_code = $user->get_error_code();
                if ($error_code === 'expired_key') {
                    $error_message = __('This password reset link has expired. Please request a new one.', 'job-posting-manager');
                } else {
                    $error_message = __('This password reset link is invalid. Please request a new one.', 'job-posting-manager');
                }
            } else {
                $is_valid = true;
            }
        } else {
            $error_message = __('Invalid password reset link. Please check your email for the correct link.', 'job-posting-manager');
        }

        $atts = shortcode_atts([
            'title' => __('Set New Password', 'job-posting-manager'),
        ], $atts);

        $ajax_url = admin_url('admin-ajax.php');
        $resetting = esc_js(__('Resetting...', 'job-posting-manager'));
        $reset_password = esc_js(__('Reset Password', 'job-posting-manager'));
        $error_occurred = esc_js(__('An error occurred. Please try again.', 'job-posting-manager'));

        ob_start();
        ?>
        <div class="jpm-reset-password-form-wrapper">
            <div class="jpm-reset-password-form-container">
                <div class="jpm-reset-password-header">
                    <div class="jpm-reset-password-logo">
                        <?php
                        $bdo_logo_url = JPM_PLUGIN_URL . 'assets/images/BDO-Favicon.png';
                        echo '<img src="' . esc_url($bdo_logo_url) . '" alt="BDO" class="jpm-logo-image" style="max-height: 60px;"/>';
                        ?>
                    </div>
                    <h2 class="jpm-reset-password-title"><?php echo esc_html($atts['title']); ?></h2>
                </div>

                <div id="jpm-reset-password-message" class="jpm-reset-password-message" style="display: none;"></div>

                <?php if (!$is_valid): ?>
                    <div class="jpm-reset-password-message">
                        <div class="notice notice-error">
                            <p><?php echo esc_html($error_message); ?></p>
                        </div>
                    </div>
                    <div class="jpm-reset-password-footer">
                        <p class="jpm-reset-password-footer-text">
                            <a href="<?php echo esc_url(home_url('/forgot-password/')); ?>" class="jpm-reset-password-forgot-link">
                                <?php _e('Request a new password reset link', 'job-posting-manager'); ?>
                            </a>
                        </p>
                    </div>
                <?php else: ?>
                    <form id="jpm-reset-password-form" class="jpm-reset-password-form">
                        <?php wp_nonce_field('jpm_reset_password', 'jpm_reset_password_nonce'); ?>
                        <input type="hidden" name="key" value="<?php echo esc_attr($key); ?>" />
                        <input type="hidden" name="login" value="<?php echo esc_attr($login); ?>" />

                        <div class="jpm-form-field">
                            <label for="jpm-reset-password-new" class="jpm-input-label">
                                <?php _e('New Password', 'job-posting-manager'); ?> <span class="required">*</span>
                            </label>
                            <div class="jpm-input-wrapper jpm-password-wrapper">
                                <input type="password" id="jpm-reset-password-new" name="password" required class="jpm-input"
                                    minlength="8"
                                    placeholder="<?php esc_attr_e('Enter your new password', 'job-posting-manager'); ?>" />
                                <button type="button" class="jpm-password-toggle"
                                    aria-label="<?php esc_attr_e('Show password', 'job-posting-manager'); ?>">
                                    <svg class="jpm-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <svg class="jpm-eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" style="display: none;">
                                        <path
                                            d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
                                        </path>
                                        <line x1="1" y1="1" x2="23" y2="23"></line>
                                    </svg>
                                </button>
                            </div>
                            <div class="jpm-password-strength" id="jpm-reset-password-strength" style="display: none;">
                                <div class="jpm-password-strength-bar">
                                    <div class="jpm-password-strength-fill" id="jpm-reset-password-strength-fill"></div>
                                </div>
                                <div class="jpm-password-strength-text" id="jpm-reset-password-strength-text"></div>
                            </div>
                        </div>

                        <div class="jpm-form-field">
                            <label for="jpm-reset-password-confirm" class="jpm-input-label">
                                <?php _e('Confirm New Password', 'job-posting-manager'); ?> <span class="required">*</span>
                            </label>
                            <div class="jpm-input-wrapper jpm-password-wrapper">
                                <input type="password" id="jpm-reset-password-confirm" name="password_confirm" required
                                    class="jpm-input"
                                    placeholder="<?php esc_attr_e('Re-enter your new password', 'job-posting-manager'); ?>" />
                                <button type="button" class="jpm-password-toggle"
                                    aria-label="<?php esc_attr_e('Show password', 'job-posting-manager'); ?>">
                                    <svg class="jpm-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <svg class="jpm-eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" style="display: none;">
                                        <path
                                            d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
                                        </path>
                                        <line x1="1" y1="1" x2="23" y2="23"></line>
                                    </svg>
                                </button>
                            </div>
                            <div id="jpm-reset-password-match" class="jpm-password-match" style="display: none;"></div>
                        </div>

                        <div class="jpm-form-field jpm-reset-password-button-wrapper">
                            <button type="submit" id="jpm-reset-password-submit"
                                class="jpm-btn jpm-btn-primary jpm-btn-block jpm-btn-large">
                                <span class="jpm-btn-text"><?php _e('Reset Password', 'job-posting-manager'); ?></span>
                            </button>
                        </div>

                        <div class="jpm-reset-password-footer">
                            <p class="jpm-reset-password-footer-text">
                                <?php _e('Remember your password?', 'job-posting-manager'); ?>
                                <a href="<?php echo esc_url(home_url('/sign-in/')); ?>" class="jpm-reset-password-login-link">
                                    <?php _e('Sign In', 'job-posting-manager'); ?>
                                </a>
                            </p>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .jpm-reset-password-form-wrapper {
                max-width: 480px;
                margin: 30px auto;
                padding: 15px;
            }

            .jpm-reset-password-form-container {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .jpm-reset-password-header {
                padding: 24px 24px 16px;
                border-bottom: 1px solid #e5e7eb;
                text-align: center;
            }

            .jpm-reset-password-logo {
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .jpm-reset-password-title {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-reset-password-form {
                padding: 24px;
            }

            .jpm-reset-password-message {
                margin: 0 24px 16px;
            }

            .jpm-reset-password-message .notice {
                margin: 0;
                padding: 14px 18px;
                border-radius: 8px;
                border: none;
                font-size: 14px;
                font-weight: 500;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .jpm-reset-password-message .notice-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
            }

            .jpm-reset-password-message .notice-success p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-reset-password-message .notice-error {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: #ffffff;
            }

            .jpm-reset-password-message .notice-error p {
                margin: 0;
                color: #ffffff;
            }

            .jpm-reset-password-form .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-reset-password-form .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-reset-password-form .jpm-input-wrapper {
                position: relative;
            }

            .jpm-reset-password-form .jpm-input {
                width: 100%;
                padding: 8px 12px;
                border: none;
                border-bottom: 2px solid #e5e7eb;
                border-radius: 0;
                font-size: 14px;
                color: #111827;
                background: transparent;
                box-sizing: border-box;
                transition: border-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-reset-password-form .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-reset-password-form .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb;
            }

            .jpm-reset-password-form .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626;
            }

            .jpm-reset-password-form .jpm-password-wrapper {
                position: relative;
            }

            .jpm-reset-password-form .jpm-password-toggle {
                position: absolute;
                right: 0;
                bottom: 8px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 4px;
                color: #6b7280;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.15s ease;
            }

            .jpm-reset-password-form .jpm-password-toggle:hover {
                color: #374151;
            }

            .jpm-reset-password-form .jpm-password-toggle:focus {
                outline: none;
                color: #2563eb;
            }

            .jpm-reset-password-form .jpm-form-field {
                margin-bottom: 16px;
            }

            .jpm-reset-password-form .jpm-btn {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                text-decoration: none;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-reset-password-form .jpm-btn-primary {
                background: #2563eb;
                color: #ffffff;
            }

            .jpm-reset-password-form .jpm-btn-primary:hover:not(:disabled) {
                background: #1d4ed8;
            }

            .jpm-reset-password-form .jpm-btn-primary:active:not(:disabled) {
                background: #1e40af;
            }

            .jpm-reset-password-form .jpm-btn-block {
                width: 100%;
                display: block;
            }

            .jpm-reset-password-form .jpm-password-strength {
                margin-top: 6px;
            }

            .jpm-reset-password-form .jpm-password-strength-bar {
                height: 3px;
                background: #e5e7eb;
                border-radius: 2px;
                overflow: hidden;
                margin-bottom: 6px;
            }

            .jpm-reset-password-form .jpm-password-strength-fill {
                height: 100%;
                width: 0%;
                background: #e5e7eb;
                border-radius: 2px;
                transition: width 0.2s ease, background-color 0.2s ease;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.weak {
                width: 33%;
                background: #ef4444;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.medium {
                width: 66%;
                background: #f59e0b;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.strong {
                width: 100%;
                background: #22c55e;
            }

            .jpm-reset-password-form .jpm-password-strength-text {
                font-size: 12px;
                font-weight: 500;
                margin-top: 2px;
            }

            .jpm-reset-password-form .jpm-password-strength-text.weak {
                color: #ef4444;
            }

            .jpm-reset-password-form .jpm-password-strength-text.medium {
                color: #f59e0b;
            }

            .jpm-reset-password-form .jpm-password-strength-text.strong {
                color: #22c55e;
            }

            .jpm-reset-password-form .jpm-btn-large {
                padding: 10px 20px;
                font-size: 14px;
            }

            .jpm-reset-password-form .jpm-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .jpm-reset-password-footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }

            .jpm-reset-password-footer-text {
                margin: 0;
                font-size: 14px;
                color: #6b7280;
            }

            .jpm-reset-password-login-link,
            .jpm-reset-password-forgot-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 500;
                margin-left: 4px;
            }

            .jpm-reset-password-login-link:hover,
            .jpm-reset-password-forgot-link:hover {
                text-decoration: underline;
            }

            .jpm-password-match {
                margin-top: 4px;
                font-size: 13px;
                font-weight: 500;
            }

            .jpm-password-match.match {
                color: #22c55e;
            }

            .jpm-password-match.no-match {
                color: #ef4444;
            }

            .jpm-reset-password-form .jpm-password-strength {
                margin-top: 6px;
            }

            .jpm-reset-password-form .jpm-password-strength-bar {
                height: 3px;
                background: #e5e7eb;
                border-radius: 2px;
                overflow: hidden;
                margin-bottom: 6px;
            }

            .jpm-reset-password-form .jpm-password-strength-fill {
                height: 100%;
                width: 0%;
                background: #e5e7eb;
                border-radius: 2px;
                transition: width 0.2s ease, background-color 0.2s ease;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.weak {
                width: 33%;
                background: #ef4444;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.medium {
                width: 66%;
                background: #f59e0b;
            }

            .jpm-reset-password-form .jpm-password-strength-fill.strong {
                width: 100%;
                background: #22c55e;
            }

            .jpm-reset-password-form .jpm-password-strength-text {
                font-size: 12px;
                font-weight: 500;
                margin-top: 2px;
            }

            .jpm-reset-password-form .jpm-password-strength-text.weak {
                color: #ef4444;
            }

            .jpm-reset-password-form .jpm-password-strength-text.medium {
                color: #f59e0b;
            }

            .jpm-reset-password-form .jpm-password-strength-text.strong {
                color: #22c55e;
            }

            @media (max-width: 600px) {
                .jpm-reset-password-form-wrapper {
                    margin: 15px auto;
                    padding: 10px;
                }

                .jpm-reset-password-header {
                    padding: 20px 20px 16px;
                }

                .jpm-reset-password-form {
                    padding: 20px;
                }

                .jpm-reset-password-message {
                    margin: 0 20px 12px;
                }

                .jpm-reset-password-title {
                    font-size: 18px;
                }
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Password toggle functionality
                $('#jpm-reset-password-form .jpm-password-toggle').on('click', function () {
                    var $button = $(this);
                    var $input = $button.closest('.jpm-password-wrapper').find('input');
                    var $eyeIcon = $button.find('.jpm-eye-icon');
                    var $eyeOffIcon = $button.find('.jpm-eye-off-icon');

                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $eyeIcon.hide();
                        $eyeOffIcon.show();
                        $button.attr('aria-label', '<?php echo esc_js(__('Hide password', 'job-posting-manager')); ?>');
                    } else {
                        $input.attr('type', 'password');
                        $eyeIcon.show();
                        $eyeOffIcon.hide();
                        $button.attr('aria-label', '<?php echo esc_js(__('Show password', 'job-posting-manager')); ?>');
                    }
                });

                // Real-time password strength indicator
                $('#jpm-reset-password-new').on('input', function () {
                    var password = $(this).val();
                    var $strengthContainer = $('#jpm-reset-password-strength');
                    var $strengthFill = $('#jpm-reset-password-strength-fill');
                    var $strengthText = $('#jpm-reset-password-strength-text');

                    if (password.length === 0) {
                        $strengthContainer.hide();
                        return;
                    }

                    $strengthContainer.show();

                    // Calculate strength score
                    var score = 0;
                    var feedback = [];

                    // Length checks
                    if (password.length >= 8) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('At least 8 characters', 'job-posting-manager')); ?>');
                    }

                    if (password.length >= 12) {
                        score += 1;
                    }

                    // Character variety checks
                    if (/[a-z]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Lowercase letter', 'job-posting-manager')); ?>');
                    }

                    if (/[A-Z]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Uppercase letter', 'job-posting-manager')); ?>');
                    }

                    if (/\d/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Number', 'job-posting-manager')); ?>');
                    }

                    if (/[^a-zA-Z\d]/.test(password)) {
                        score += 1;
                    } else {
                        feedback.push('<?php echo esc_js(__('Special character', 'job-posting-manager')); ?>');
                    }

                    // Determine strength level
                    var strengthLevel = '';
                    var strengthLabel = '';
                    var strengthWidth = '0%';

                    if (score <= 2) {
                        strengthLevel = 'weak';
                        strengthLabel = '<?php echo esc_js(__('Weak', 'job-posting-manager')); ?>';
                        strengthWidth = '33%';
                    } else if (score <= 4) {
                        strengthLevel = 'medium';
                        strengthLabel = '<?php echo esc_js(__('Medium', 'job-posting-manager')); ?>';
                        strengthWidth = '66%';
                    } else {
                        strengthLevel = 'strong';
                        strengthLabel = '<?php echo esc_js(__('Strong', 'job-posting-manager')); ?>';
                        strengthWidth = '100%';
                    }

                    // Update visual indicator
                    $strengthFill.removeClass('weak medium strong').addClass(strengthLevel).css('width', strengthWidth);

                    // Update text feedback
                    if (feedback.length > 0 && score < 5) {
                        $strengthText.removeClass('weak medium strong').addClass(strengthLevel)
                            .html('<span style="font-weight: 600;">' + strengthLabel + '</span> - <?php echo esc_js(__('Add:', 'job-posting-manager')); ?> ' + feedback.slice(0, 2).join(', '));
                    } else {
                        $strengthText.removeClass('weak medium strong').addClass(strengthLevel)
                            .html('<span style="font-weight: 600;">' + strengthLabel + '</span>');
                    }
                });

                // Password match indicator
                $('#jpm-reset-password-confirm').on('input', function () {
                    var password = $('#jpm-reset-password-new').val();
                    var confirmPassword = $(this).val();
                    var $matchIndicator = $('#jpm-reset-password-match');

                    if (confirmPassword.length === 0) {
                        $matchIndicator.hide();
                        return;
                    }

                    if (password === confirmPassword) {
                        $matchIndicator.html('✓ <?php echo esc_js(__('Passwords match', 'job-posting-manager')); ?>').removeClass('no-match').addClass('match').show();
                    } else {
                        $matchIndicator.html('✗ <?php echo esc_js(__('Passwords do not match', 'job-posting-manager')); ?>').removeClass('match').addClass('no-match').show();
                    }
                });

                // Form submission
                $('#jpm-reset-password-form').on('submit', function (e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $message = $('#jpm-reset-password-message');
                    var $button = $('#jpm-reset-password-submit');
                    var $btnText = $button.find('.jpm-btn-text');

                    // Get form values
                    var password = $('#jpm-reset-password-new').val();
                    var passwordConfirm = $('#jpm-reset-password-confirm').val();

                    // Validate passwords match
                    if (password !== passwordConfirm) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Passwords do not match.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    // Validate password length
                    if (password.length < 8) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Password must be at least 8 characters long.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    $button.prop('disabled', true);
                    $btnText.text('<?php echo $resetting; ?>');
                    $message.hide();

                    $.ajax({
                        url: '<?php echo $ajax_url; ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_reset_password',
                            key: $('input[name="key"]').val(),
                            login: $('input[name="login"]').val(),
                            password: password,
                            nonce: $('#jpm_reset_password_nonce').val()
                        },
                        success: function (response) {
                            // Reset button to normal state for any response
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo $reset_password; ?>');

                            if (response.success) {
                                $message.html('<div class="notice notice-success"><p>' + (response.data.message || '<?php echo esc_js(__('Password has been reset successfully! Redirecting to login...', 'job-posting-manager')); ?>') + '</p></div>').show();

                                // Redirect to login page after 2 seconds
                                setTimeout(function () {
                                    if (response.data.redirect_url) {
                                        window.location.href = response.data.redirect_url;
                                    } else {
                                        window.location.href = '<?php echo esc_url(home_url('/sign-in/')); ?>';
                                    }
                                }, 2000);
                            } else {
                                $message.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php echo esc_js(__('Failed to reset password. Please try again.', 'job-posting-manager')); ?>') + '</p></div>').show();
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error"><p><?php echo $error_occurred; ?></p></div>').show();
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo $reset_password; ?>');
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * User profile shortcode
     * Usage: [jpm_user_profile title="My Profile"]
     */
    public function user_profile_shortcode($atts)
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="jpm-user-profile-message"><p>' . sprintf(__('Please <a href="%s">login</a> to view your profile.', 'job-posting-manager'), esc_url(home_url('/sign-in/'))) . '</p></div>';
        }

        $user_id = get_current_user_id();
        $current_user = wp_get_current_user();

        // Get user applications
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY application_date DESC",
            $user_id
        ));

        // Get all jobs for dashboard stats
        $all_jobs = get_posts([
            'post_type' => 'job_posting',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        $atts = shortcode_atts([
            'title' => __('My Profile', 'job-posting-manager'),
        ], $atts);

        ob_start();
        ?>
        <div class="jpm-user-profile-wrapper">
            <div class="jpm-user-profile-layout"
                style="display: flex !important; flex-direction: row !important; align-items: stretch !important; width: 100% !important; min-height: 100% !important;">
                <!-- Sidebar Navigation -->
                <aside class="jpm-profile-sidebar"
                    style="width: 280px !important; min-width: 280px !important; max-width: 280px !important; flex: 0 0 280px !important; flex-shrink: 0 !important; flex-grow: 0 !important; display: flex !important; flex-direction: column !important; float: none !important; align-self: stretch !important; min-height: 100% !important;">
                    <div class="jpm-profile-sidebar-header">
                        <div class="jpm-profile-user-info">
                            <div class="jpm-profile-user-avatar">
                                <?php echo get_avatar($user_id, 80); ?>
                            </div>
                            <div class="jpm-profile-user-name"><?php echo esc_html($current_user->display_name); ?></div>
                            <div class="jpm-profile-user-email"><?php echo esc_html($current_user->user_email); ?></div>
                        </div>
                    </div>

                    <nav class="jpm-profile-nav">
                        <a href="#" class="jpm-profile-nav-item active" data-tab="dashboard">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                            <span><?php _e('Dashboard', 'job-posting-manager'); ?></span>
                        </a>
                        <a href="#" class="jpm-profile-nav-item" data-tab="applications">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            <span><?php _e('Jobs Applied', 'job-posting-manager'); ?></span>
                            <?php if (count($applications) > 0): ?>
                                <span class="jpm-nav-badge"><?php echo count($applications); ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="#" class="jpm-profile-nav-item" data-tab="information">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span><?php _e('Information', 'job-posting-manager'); ?></span>
                        </a>
                        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="jpm-profile-nav-item jpm-nav-logout">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                            <span><?php _e('Logout', 'job-posting-manager'); ?></span>
                        </a>
                    </nav>
                </aside>

                <!-- Main Content Area -->
                <main class="jpm-profile-main"
                    style="flex: 1 1 auto !important; flex-grow: 1 !important; flex-shrink: 1 !important; min-width: 0 !important; display: block !important; width: auto !important; float: none !important;">
                    <!-- Dashboard Tab -->
                    <div class="jpm-profile-tab-content active" id="jpm-tab-dashboard">
                        <div class="jpm-profile-tab-header">
                            <h2 class="jpm-profile-tab-title"><?php _e('Dashboard', 'job-posting-manager'); ?></h2>
                        </div>

                        <div class="jpm-dashboard-stats">
                            <div class="jpm-stat-card">
                                <div class="jpm-stat-icon" style="background: #e0f2fe; color: #0369a1;">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                    </svg>
                                </div>
                                <div class="jpm-stat-content">
                                    <div class="jpm-stat-value"><?php echo count($applications); ?></div>
                                    <div class="jpm-stat-label"><?php _e('Total Applications', 'job-posting-manager'); ?></div>
                                </div>
                            </div>

                            <div class="jpm-stat-card">
                                <div class="jpm-stat-icon" style="background: #fef3c7; color: #d97706;">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <polyline points="12 6 12 12 16 14"></polyline>
                                    </svg>
                                </div>
                                <div class="jpm-stat-content">
                                    <div class="jpm-stat-value"><?php echo count($all_jobs); ?></div>
                                    <div class="jpm-stat-label"><?php _e('Available Jobs', 'job-posting-manager'); ?></div>
                                </div>
                            </div>

                            <?php
                            // Count applications by status
                            $status_counts = [];
                            foreach ($applications as $application) {
                                $status = $application->status;
                                if (!isset($status_counts[$status])) {
                                    $status_counts[$status] = 0;
                                }
                                $status_counts[$status]++;
                            }
                            ?>

                            <?php if (!empty($status_counts)): ?>
                                <div class="jpm-stat-card">
                                    <div class="jpm-stat-icon" style="background: #dbeafe; color: #1e40af;">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                        </svg>
                                    </div>
                                    <div class="jpm-stat-content">
                                        <div class="jpm-stat-value"><?php echo count($status_counts); ?></div>
                                        <div class="jpm-stat-label"><?php _e('Status Types', 'job-posting-manager'); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($status_counts)): ?>
                            <div class="jpm-dashboard-status-breakdown">
                                <h3 class="jpm-dashboard-section-title">
                                    <?php _e('Applications by Status', 'job-posting-manager'); ?>
                                </h3>
                                <div class="jpm-status-breakdown-list">
                                    <?php foreach ($status_counts as $status_slug => $count):
                                        $status_info = JPM_DB::get_status_by_slug($status_slug);
                                        if ($status_info) {
                                            $status_name = $status_info['name'];
                                            $status_color = $status_info['color'];
                                            $status_text_color = $status_info['text_color'];
                                        } else {
                                            $status_name = ucfirst($status_slug);
                                            $status_color = '#ffc107';
                                            $status_text_color = '#000000';
                                        }
                                        ?>
                                        <div class="jpm-status-breakdown-item">
                                            <div class="jpm-status-breakdown-info">
                                                <span class="jpm-status-breakdown-badge"
                                                    style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                                                    <?php echo esc_html($status_name); ?>
                                                </span>
                                                <span class="jpm-status-breakdown-count"><?php echo $count; ?>
                                                    <?php echo _n('application', 'applications', $count, 'job-posting-manager'); ?></span>
                                            </div>
                                            <div class="jpm-status-breakdown-bar">
                                                <div class="jpm-status-breakdown-fill"
                                                    style="width: <?php echo count($applications) > 0 ? round(($count / count($applications)) * 100) : 0; ?>%; background-color: <?php echo esc_attr($status_color); ?>;">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($applications)): ?>
                            <div class="jpm-dashboard-recent">
                                <div class="jpm-dashboard-section-header">
                                    <h3 class="jpm-dashboard-section-title">
                                        <?php _e('Recent Applications', 'job-posting-manager'); ?>
                                    </h3>
                                    <a href="#" class="jpm-view-all-link"
                                        data-tab="applications"><?php _e('View All', 'job-posting-manager'); ?></a>
                                </div>
                                <div class="jpm-applications-list">
                                    <?php
                                    $recent_applications = array_slice($applications, 0, 5);
                                    foreach ($recent_applications as $application):
                                        $job = get_post($application->job_id);
                                        $form_data = json_decode($application->notes, true);
                                        if (!is_array($form_data)) {
                                            $form_data = [];
                                        }

                                        // Get status information
                                        $status_info = JPM_DB::get_status_by_slug($application->status);
                                        if ($status_info) {
                                            $status_name = $status_info['name'];
                                            $status_color = $status_info['color'];
                                            $status_text_color = $status_info['text_color'];
                                        } else {
                                            $status_name = ucfirst($application->status);
                                            $status_color = '#ffc107';
                                            $status_text_color = '#000000';
                                        }

                                        // Extract application number
                                        $application_number = '';
                                        $app_number_fields = ['application_number', 'applicationnumber', 'app_number', 'app-number', 'application-number', 'application number', 'reference_number', 'referencenumber', 'reference-number', 'reference number'];
                                        foreach ($app_number_fields as $field_name) {
                                            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                                                $application_number = sanitize_text_field($form_data[$field_name]);
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="jpm-application-card">
                                            <div class="jpm-application-header">
                                                <div class="jpm-application-job-title">
                                                    <h4><?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                                    </h4>
                                                    <?php if ($application_number): ?>
                                                        <span
                                                            class="jpm-application-number"><?php printf(__('Application #%s', 'job-posting-manager'), esc_html($application_number)); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="jpm-status-badge"
                                                    style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                                                    <?php echo esc_html($status_name); ?>
                                                </span>
                                            </div>

                                            <div class="jpm-application-details">
                                                <div class="jpm-application-detail-item">
                                                    <span
                                                        class="jpm-detail-label"><?php _e('Applied Date:', 'job-posting-manager'); ?></span>
                                                    <span
                                                        class="jpm-detail-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?></span>
                                                </div>
                                                <?php if ($job): ?>
                                                    <div class="jpm-application-detail-item">
                                                        <span class="jpm-detail-label"><?php _e('Job ID:', 'job-posting-manager'); ?></span>
                                                        <span class="jpm-detail-value">#<?php echo esc_html($application->job_id); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Application Form Data -->
                                            <?php if (!empty($form_data)): ?>
                                                <div class="jpm-application-form-data">
                                                    <button type="button" class="jpm-toggle-details"
                                                        data-application-id="<?php echo esc_attr($application->id); ?>">
                                                        <span
                                                            class="jpm-toggle-text"><?php _e('View Application Details', 'job-posting-manager'); ?></span>
                                                        <span class="jpm-toggle-icon">▼</span>
                                                    </button>
                                                    <div class="jpm-application-details-content"
                                                        id="jpm-details-<?php echo esc_attr($application->id); ?>" style="display: none;">
                                                        <div class="jpm-form-data-grid">
                                                            <?php foreach ($form_data as $key => $value):
                                                                // Skip empty values
                                                                if (empty($value) && $value !== '0' && $value !== 0)
                                                                    continue;

                                                                // Skip file uploads (they're usually URLs)
                                                                if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                    ?>
                                                                    <div class="jpm-form-data-item">
                                                                        <span
                                                                            class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                        <span class="jpm-form-data-value">
                                                                            <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                                rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                                        </span>
                                                                    </div>
                                                                    <?php
                                                                    continue;
                                                                }

                                                                $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                                ?>
                                                                <div class="jpm-form-data-item">
                                                                    <span
                                                                        class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                    <span
                                                                        class="jpm-form-data-value"><?php echo esc_html($field_value); ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="jpm-no-applications">
                                    <p><?php _e('You haven\'t applied to any jobs yet.', 'job-posting-manager'); ?></p>
                                    <a href="<?php echo esc_url(home_url('/all-jobs/')); ?>" class="jpm-btn jpm-btn-primary">
                                        <?php _e('Browse Jobs', 'job-posting-manager'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Applications Tab -->
                    <div class="jpm-profile-tab-content" id="jpm-tab-applications">
                        <div class="jpm-profile-tab-header">
                            <h2 class="jpm-profile-tab-title">
                                <?php _e('Jobs Applied', 'job-posting-manager'); ?>
                                <span class="jpm-applications-count">(<?php echo count($applications); ?>)</span>
                            </h2>
                        </div>

                        <?php if (empty($applications)): ?>
                            <div class="jpm-no-applications">
                                <p><?php _e('You haven\'t applied to any jobs yet.', 'job-posting-manager'); ?></p>
                                <a href="<?php echo esc_url(home_url('/job-postings/')); ?>" class="jpm-btn jpm-btn-primary">
                                    <?php _e('Browse Jobs', 'job-posting-manager'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="jpm-applications-list">
                                <?php foreach ($applications as $application):
                                    $job = get_post($application->job_id);
                                    $form_data = json_decode($application->notes, true);
                                    if (!is_array($form_data)) {
                                        $form_data = [];
                                    }

                                    // Get status information
                                    $status_info = JPM_DB::get_status_by_slug($application->status);
                                    if ($status_info) {
                                        $status_name = $status_info['name'];
                                        $status_color = $status_info['color'];
                                        $status_text_color = $status_info['text_color'];
                                    } else {
                                        $status_name = ucfirst($application->status);
                                        $status_color = '#ffc107';
                                        $status_text_color = '#000000';
                                    }

                                    // Extract application number
                                    $application_number = '';
                                    $app_number_fields = ['application_number', 'applicationnumber', 'app_number', 'app-number', 'application-number', 'application number', 'reference_number', 'referencenumber', 'reference-number', 'reference number'];
                                    foreach ($app_number_fields as $field_name) {
                                        if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                                            $application_number = sanitize_text_field($form_data[$field_name]);
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="jpm-application-card">
                                        <div class="jpm-application-header">
                                            <div class="jpm-application-job-title">
                                                <h4><?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                                </h4>
                                                <?php if ($application_number): ?>
                                                    <span
                                                        class="jpm-application-number"><?php printf(__('Application #%s', 'job-posting-manager'), esc_html($application_number)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="jpm-status-badge"
                                                style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                                                <?php echo esc_html($status_name); ?>
                                            </span>
                                        </div>

                                        <div class="jpm-application-details">
                                            <div class="jpm-application-detail-item">
                                                <span
                                                    class="jpm-detail-label"><?php _e('Applied Date:', 'job-posting-manager'); ?></span>
                                                <span
                                                    class="jpm-detail-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?></span>
                                            </div>
                                            <?php if ($job): ?>
                                                <div class="jpm-application-detail-item">
                                                    <span class="jpm-detail-label"><?php _e('Job ID:', 'job-posting-manager'); ?></span>
                                                    <span class="jpm-detail-value">#<?php echo esc_html($application->job_id); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Application Form Data -->
                                        <?php if (!empty($form_data)):
                                            // Get form fields structure from job posting to determine categories
                                            $job_form_fields = [];
                                            if ($job) {
                                                $job_form_fields = get_post_meta($job->ID, '_jpm_form_fields', true);
                                                if (!is_array($job_form_fields)) {
                                                    $job_form_fields = [];
                                                }
                                            }

                                            // Categorize fields into sections
                                            $personal_fields = [];
                                            $education_fields = [];
                                            $employment_fields = [];
                                            $additional_fields = [];

                                            foreach ($form_data as $key => $value) {
                                                // Skip empty values
                                                if (empty($value) && $value !== '0' && $value !== 0) {
                                                    continue;
                                                }

                                                $key_lower = strtolower($key);
                                                $field_label = ucwords(str_replace(['_', '-'], ' ', $key));

                                                // Try to find field in job form structure
                                                $field_category = 'additional';
                                                foreach ($job_form_fields as $field) {
                                                    if (isset($field['name']) && $field['name'] === $key) {
                                                        // Check if field has category/step info
                                                        if (isset($field['step']) || isset($field['category'])) {
                                                            $step = isset($field['step']) ? $field['step'] : (isset($field['category']) ? $field['category'] : '');
                                                            $step_lower = strtolower($step);
                                                            if (stripos($step_lower, 'personal') !== false) {
                                                                $field_category = 'personal';
                                                            } elseif (stripos($step_lower, 'education') !== false) {
                                                                $field_category = 'education';
                                                            } elseif (stripos($step_lower, 'employment') !== false) {
                                                                $field_category = 'employment';
                                                            }
                                                        }
                                                        break;
                                                    }
                                                }

                                                // Auto-categorize based on field name if not found in structure
                                                if ($field_category === 'additional') {
                                                    if (
                                                        stripos($key_lower, 'personal') !== false ||
                                                        stripos($key_lower, 'name') !== false ||
                                                        stripos($key_lower, 'email') !== false ||
                                                        stripos($key_lower, 'phone') !== false ||
                                                        stripos($key_lower, 'address') !== false ||
                                                        stripos($key_lower, 'birth') !== false ||
                                                        stripos($key_lower, 'gender') !== false ||
                                                        stripos($key_lower, 'civil') !== false ||
                                                        stripos($key_lower, 'nationality') !== false
                                                    ) {
                                                        $field_category = 'personal';
                                                    } elseif (
                                                        stripos($key_lower, 'education') !== false ||
                                                        stripos($key_lower, 'school') !== false ||
                                                        stripos($key_lower, 'degree') !== false ||
                                                        stripos($key_lower, 'course') !== false ||
                                                        stripos($key_lower, 'university') !== false ||
                                                        stripos($key_lower, 'college') !== false ||
                                                        stripos($key_lower, 'graduation') !== false
                                                    ) {
                                                        $field_category = 'education';
                                                    } elseif (
                                                        stripos($key_lower, 'employment') !== false ||
                                                        stripos($key_lower, 'employer') !== false ||
                                                        stripos($key_lower, 'company') !== false ||
                                                        stripos($key_lower, 'position') !== false ||
                                                        stripos($key_lower, 'job') !== false ||
                                                        stripos($key_lower, 'work') !== false ||
                                                        stripos($key_lower, 'experience') !== false ||
                                                        stripos($key_lower, 'salary') !== false
                                                    ) {
                                                        $field_category = 'employment';
                                                    }
                                                }

                                                // Add to appropriate category
                                                switch ($field_category) {
                                                    case 'personal':
                                                        $personal_fields[$key] = $value;
                                                        break;
                                                    case 'education':
                                                        $education_fields[$key] = $value;
                                                        break;
                                                    case 'employment':
                                                        $employment_fields[$key] = $value;
                                                        break;
                                                    default:
                                                        $additional_fields[$key] = $value;
                                                        break;
                                                }
                                            }
                                            ?>
                                            <div class="jpm-application-form-data">
                                                <button type="button" class="jpm-toggle-details"
                                                    data-application-id="<?php echo esc_attr($application->id); ?>">
                                                    <span
                                                        class="jpm-toggle-text"><?php _e('View Application Details', 'job-posting-manager'); ?></span>
                                                    <span class="jpm-toggle-icon">▼</span>
                                                </button>
                                                <div class="jpm-application-details-content"
                                                    id="jpm-details-<?php echo esc_attr($application->id); ?>" style="display: none;">

                                                    <?php if (!empty($personal_fields)): ?>
                                                        <div class="jpm-form-data-section">
                                                            <h4 class="jpm-form-data-section-title">
                                                                <?php _e('Personal Information', 'job-posting-manager'); ?></h4>
                                                            <div class="jpm-form-data-grid">
                                                                <?php foreach ($personal_fields as $key => $value):
                                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                                        ?>
                                                                        <div class="jpm-form-data-item">
                                                                            <span
                                                                                class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                            <span class="jpm-form-data-value">
                                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                                            </span>
                                                                        </div>
                                                                        <?php
                                                                        continue;
                                                                    }
                                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                                    ?>
                                                                    <div class="jpm-form-data-item">
                                                                        <span
                                                                            class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                        <span
                                                                            class="jpm-form-data-value"><?php echo esc_html($field_value); ?></span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($education_fields)): ?>
                                                        <div class="jpm-form-data-section">
                                                            <h4 class="jpm-form-data-section-title">
                                                                <?php _e('Education', 'job-posting-manager'); ?></h4>
                                                            <div class="jpm-form-data-grid">
                                                                <?php foreach ($education_fields as $key => $value):
                                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                                        ?>
                                                                        <div class="jpm-form-data-item">
                                                                            <span
                                                                                class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                            <span class="jpm-form-data-value">
                                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                                            </span>
                                                                        </div>
                                                                        <?php
                                                                        continue;
                                                                    }
                                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                                    ?>
                                                                    <div class="jpm-form-data-item">
                                                                        <span
                                                                            class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                        <span
                                                                            class="jpm-form-data-value"><?php echo esc_html($field_value); ?></span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($employment_fields)): ?>
                                                        <div class="jpm-form-data-section">
                                                            <h4 class="jpm-form-data-section-title">
                                                                <?php _e('Employment', 'job-posting-manager'); ?></h4>
                                                            <div class="jpm-form-data-grid">
                                                                <?php foreach ($employment_fields as $key => $value):
                                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                                        ?>
                                                                        <div class="jpm-form-data-item">
                                                                            <span
                                                                                class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                            <span class="jpm-form-data-value">
                                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                                            </span>
                                                                        </div>
                                                                        <?php
                                                                        continue;
                                                                    }
                                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                                    ?>
                                                                    <div class="jpm-form-data-item">
                                                                        <span
                                                                            class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                        <span
                                                                            class="jpm-form-data-value"><?php echo esc_html($field_value); ?></span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($additional_fields)): ?>
                                                        <div class="jpm-form-data-section">
                                                            <h4 class="jpm-form-data-section-title">
                                                                <?php _e('Additional Information', 'job-posting-manager'); ?></h4>
                                                            <div class="jpm-form-data-grid">
                                                                <?php foreach ($additional_fields as $key => $value):
                                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                                        ?>
                                                                        <div class="jpm-form-data-item">
                                                                            <span
                                                                                class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                            <span class="jpm-form-data-value">
                                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                                            </span>
                                                                        </div>
                                                                        <?php
                                                                        continue;
                                                                    }
                                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                                    ?>
                                                                    <div class="jpm-form-data-item">
                                                                        <span
                                                                            class="jpm-form-data-label"><?php echo esc_html($field_label); ?>:</span>
                                                                        <span
                                                                            class="jpm-form-data-value"><?php echo esc_html($field_value); ?></span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Information Tab -->
                    <div class="jpm-profile-tab-content" id="jpm-tab-information">
                        <div class="jpm-profile-tab-header">
                            <h2 class="jpm-profile-tab-title"><?php _e('Account Information', 'job-posting-manager'); ?></h2>
                        </div>

                        <div class="jpm-information-content">
                            <div class="jpm-info-section">
                                <div class="jpm-info-section-header">
                                    <h3 class="jpm-info-section-title">
                                        <?php _e('Personal Information', 'job-posting-manager'); ?>
                                    </h3>
                                    <div class="jpm-edit-buttons">
                                        <button type="button" class="jpm-edit-info-btn" id="jpm-edit-personal-info">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                            <span><?php _e('Edit', 'job-posting-manager'); ?></span>
                                        </button>
                                        <button type="button" class="jpm-edit-info-btn" id="jpm-save-personal-info"
                                            style="display: none;">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                            <span><?php _e('Save', 'job-posting-manager'); ?></span>
                                        </button>
                                        <button type="button" class="jpm-btn jpm-btn-secondary" id="jpm-cancel-edit-info"
                                            style="display: none;">
                                            <?php _e('Cancel', 'job-posting-manager'); ?>
                                        </button>
                                    </div>
                                </div>

                                <form id="jpm-update-personal-info-form">
                                    <?php wp_nonce_field('jpm_update_personal_info', 'jpm_update_info_nonce'); ?>
                                    <div class="jpm-info-grid" id="jpm-info-display">
                                        <div class="jpm-info-item">
                                            <span
                                                class="jpm-info-label"><?php _e('Display Name:', 'job-posting-manager'); ?></span>
                                            <span class="jpm-info-value jpm-editable-value" id="jpm-display-name-value"
                                                data-field="display_name"><?php echo esc_html($current_user->display_name); ?></span>
                                            <input type="text" id="jpm-edit-display-name" name="display_name"
                                                class="jpm-edit-input"
                                                value="<?php echo esc_attr($current_user->display_name); ?>"
                                                style="display: none;" required>
                                        </div>
                                        <div class="jpm-info-item">
                                            <span
                                                class="jpm-info-label"><?php _e('Email Address:', 'job-posting-manager'); ?></span>
                                            <span
                                                class="jpm-info-value"><?php echo esc_html($current_user->user_email); ?></span>
                                        </div>
                                        <div class="jpm-info-item">
                                            <span
                                                class="jpm-info-label"><?php _e('First Name:', 'job-posting-manager'); ?></span>
                                            <span class="jpm-info-value jpm-editable-value" id="jpm-first-name-value"
                                                data-field="first_name"><?php echo esc_html($current_user->first_name ? $current_user->first_name : ''); ?></span>
                                            <input type="text" id="jpm-edit-first-name" name="first_name" class="jpm-edit-input"
                                                value="<?php echo esc_attr($current_user->first_name); ?>"
                                                style="display: none;">
                                        </div>
                                        <div class="jpm-info-item">
                                            <span
                                                class="jpm-info-label"><?php _e('Last Name:', 'job-posting-manager'); ?></span>
                                            <span class="jpm-info-value jpm-editable-value" id="jpm-last-name-value"
                                                data-field="last_name"><?php echo esc_html($current_user->last_name ? $current_user->last_name : ''); ?></span>
                                            <input type="text" id="jpm-edit-last-name" name="last_name" class="jpm-edit-input"
                                                value="<?php echo esc_attr($current_user->last_name); ?>"
                                                style="display: none;">
                                        </div>
                                        <?php if (!empty($current_user->user_registered)): ?>
                                            <div class="jpm-info-item">
                                                <span
                                                    class="jpm-info-label"><?php _e('Member Since:', 'job-posting-manager'); ?></span>
                                                <span
                                                    class="jpm-info-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($current_user->user_registered))); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="jpm-edit-message" id="jpm-edit-message" style="display: none;"></div>
                                </form>
                            </div>

                            <div class="jpm-info-section">
                                <h3 class="jpm-info-section-title"><?php _e('Account Statistics', 'job-posting-manager'); ?>
                                </h3>
                                <div class="jpm-info-grid">
                                    <div class="jpm-info-item">
                                        <span
                                            class="jpm-info-label"><?php _e('Total Applications:', 'job-posting-manager'); ?></span>
                                        <span class="jpm-info-value"><?php echo count($applications); ?></span>
                                    </div>
                                    <div class="jpm-info-item">
                                        <span class="jpm-info-label"><?php _e('User ID:', 'job-posting-manager'); ?></span>
                                        <span class="jpm-info-value">#<?php echo esc_html($user_id); ?></span>
                                    </div>
                                </div>
                            </div>

                            <?php
                            // Collect all form data from all applications and organize by category
                            $all_personal_fields = [];
                            $all_education_fields = [];
                            $all_employment_fields = [];
                            $all_additional_fields = [];

                            foreach ($applications as $application) {
                                $job = get_post($application->job_id);
                                $form_data = json_decode($application->notes, true);
                                if (!is_array($form_data)) {
                                    $form_data = [];
                                }

                                // Get form fields structure from job posting
                                $job_form_fields = [];
                                if ($job) {
                                    $job_form_fields = get_post_meta($job->ID, '_jpm_form_fields', true);
                                    if (!is_array($job_form_fields)) {
                                        $job_form_fields = [];
                                    }
                                }

                                foreach ($form_data as $key => $value) {
                                    // Skip empty values
                                    if (empty($value) && $value !== '0' && $value !== 0) {
                                        continue;
                                    }

                                    $key_lower = strtolower($key);
                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));

                                    // Try to find field in job form structure
                                    $field_category = 'additional';
                                    foreach ($job_form_fields as $field) {
                                        if (isset($field['name']) && $field['name'] === $key) {
                                            if (isset($field['step']) || isset($field['category'])) {
                                                $step = isset($field['step']) ? $field['step'] : (isset($field['category']) ? $field['category'] : '');
                                                $step_lower = strtolower($step);
                                                if (stripos($step_lower, 'personal') !== false) {
                                                    $field_category = 'personal';
                                                } elseif (stripos($step_lower, 'education') !== false) {
                                                    $field_category = 'education';
                                                } elseif (stripos($step_lower, 'employment') !== false) {
                                                    $field_category = 'employment';
                                                }
                                            }
                                            break;
                                        }
                                    }

                                    // Auto-categorize based on field name if not found in structure
                                    if ($field_category === 'additional') {
                                        if (
                                            stripos($key_lower, 'personal') !== false ||
                                            stripos($key_lower, 'name') !== false ||
                                            stripos($key_lower, 'email') !== false ||
                                            stripos($key_lower, 'phone') !== false ||
                                            stripos($key_lower, 'address') !== false ||
                                            stripos($key_lower, 'birth') !== false ||
                                            stripos($key_lower, 'gender') !== false ||
                                            stripos($key_lower, 'civil') !== false ||
                                            stripos($key_lower, 'nationality') !== false
                                        ) {
                                            $field_category = 'personal';
                                        } elseif (
                                            stripos($key_lower, 'education') !== false ||
                                            stripos($key_lower, 'school') !== false ||
                                            stripos($key_lower, 'degree') !== false ||
                                            stripos($key_lower, 'course') !== false ||
                                            stripos($key_lower, 'university') !== false ||
                                            stripos($key_lower, 'college') !== false ||
                                            stripos($key_lower, 'graduation') !== false
                                        ) {
                                            $field_category = 'education';
                                        } elseif (
                                            stripos($key_lower, 'employment') !== false ||
                                            stripos($key_lower, 'employer') !== false ||
                                            stripos($key_lower, 'company') !== false ||
                                            stripos($key_lower, 'position') !== false ||
                                            stripos($key_lower, 'job') !== false ||
                                            stripos($key_lower, 'work') !== false ||
                                            stripos($key_lower, 'experience') !== false ||
                                            stripos($key_lower, 'salary') !== false
                                        ) {
                                            $field_category = 'employment';
                                        }
                                    }

                                    // Store unique fields (keep latest value if duplicate)
                                    switch ($field_category) {
                                        case 'personal':
                                            $all_personal_fields[$key] = $value;
                                            break;
                                        case 'education':
                                            $all_education_fields[$key] = $value;
                                            break;
                                        case 'employment':
                                            $all_employment_fields[$key] = $value;
                                            break;
                                        default:
                                            $all_additional_fields[$key] = $value;
                                            break;
                                    }
                                }
                            }
                            ?>

                            <?php if (!empty($all_personal_fields) || !empty($all_education_fields) || !empty($all_employment_fields) || !empty($all_additional_fields)): ?>
                                <div class="jpm-info-section">
                                    <h3 class="jpm-info-section-title">
                                        <?php _e('Application Information', 'job-posting-manager'); ?></h3>

                                    <?php if (!empty($all_personal_fields)): ?>
                                        <div class="jpm-form-data-section">
                                            <h4 class="jpm-form-data-section-title">
                                                <?php _e('Personal Information', 'job-posting-manager'); ?></h4>
                                            <div class="jpm-info-grid">
                                                <?php foreach ($all_personal_fields as $key => $value):
                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                        ?>
                                                        <div class="jpm-info-item">
                                                            <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                            <span class="jpm-info-value">
                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        continue;
                                                    }
                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                    ?>
                                                    <div class="jpm-info-item">
                                                        <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                        <span class="jpm-info-value"><?php echo esc_html($field_value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($all_education_fields)): ?>
                                        <div class="jpm-form-data-section">
                                            <h4 class="jpm-form-data-section-title"><?php _e('Education', 'job-posting-manager'); ?>
                                            </h4>
                                            <div class="jpm-info-grid">
                                                <?php foreach ($all_education_fields as $key => $value):
                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                        ?>
                                                        <div class="jpm-info-item">
                                                            <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                            <span class="jpm-info-value">
                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        continue;
                                                    }
                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                    ?>
                                                    <div class="jpm-info-item">
                                                        <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                        <span class="jpm-info-value"><?php echo esc_html($field_value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($all_employment_fields)): ?>
                                        <div class="jpm-form-data-section">
                                            <h4 class="jpm-form-data-section-title"><?php _e('Employment', 'job-posting-manager'); ?>
                                            </h4>
                                            <div class="jpm-info-grid">
                                                <?php foreach ($all_employment_fields as $key => $value):
                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                        ?>
                                                        <div class="jpm-info-item">
                                                            <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                            <span class="jpm-info-value">
                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        continue;
                                                    }
                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                    ?>
                                                    <div class="jpm-info-item">
                                                        <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                        <span class="jpm-info-value"><?php echo esc_html($field_value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($all_additional_fields)): ?>
                                        <div class="jpm-form-data-section">
                                            <h4 class="jpm-form-data-section-title">
                                                <?php _e('Additional Information', 'job-posting-manager'); ?></h4>
                                            <div class="jpm-info-grid">
                                                <?php foreach ($all_additional_fields as $key => $value):
                                                    $field_label = ucwords(str_replace(['_', '-'], ' ', $key));
                                                    if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, '/wp-content/uploads/') !== false)) {
                                                        ?>
                                                        <div class="jpm-info-item">
                                                            <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                            <span class="jpm-info-value">
                                                                <a href="<?php echo esc_url($value); ?>" target="_blank"
                                                                    rel="noopener"><?php _e('View File', 'job-posting-manager'); ?></a>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        continue;
                                                    }
                                                    $field_value = is_array($value) ? implode(', ', $value) : $value;
                                                    ?>
                                                    <div class="jpm-info-item">
                                                        <span class="jpm-info-label"><?php echo esc_html($field_label); ?>:</span>
                                                        <span class="jpm-info-value"><?php echo esc_html($field_value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </main>
            </div>
        </div>

        <style>
            .jpm-user-profile-wrapper {
                max-width: 1400px;
                margin: 30px auto;
                padding: 15px;
                width: 100%;
                box-sizing: border-box;
            }

            .jpm-user-profile-wrapper * {
                box-sizing: border-box;
            }

            .jpm-user-profile-layout {
                display: flex !important;
                flex-direction: row !important;
                align-items: stretch !important;
                gap: 0 !important;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                overflow: visible !important;
                width: 100% !important;
                clear: both !important;
                position: relative !important;
                flex-wrap: nowrap !important;
                min-height: 100%;
            }

            .jpm-user-profile-layout::before,
            .jpm-user-profile-layout::after {
                display: none !important;
                content: none !important;
            }

            .jpm-user-profile-layout>aside {
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
                display: flex !important;
                width: 280px !important;
                min-width: 280px !important;
                max-width: 280px !important;
            }

            .jpm-user-profile-layout>main {
                flex-shrink: 1 !important;
                flex-grow: 1 !important;
                display: block !important;
                width: auto !important;
                min-width: 0 !important;
            }

            /* Additional override rules */
            .jpm-user-profile-wrapper .jpm-user-profile-layout>.jpm-profile-sidebar {
                width: 280px !important;
                min-width: 280px !important;
                max-width: 280px !important;
                flex: 0 0 280px !important;
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
                display: flex !important;
                float: none !important;
            }

            .jpm-user-profile-wrapper .jpm-user-profile-layout>.jpm-profile-main {
                flex: 1 1 auto !important;
                flex-grow: 1 !important;
                flex-shrink: 1 !important;
                display: block !important;
                float: none !important;
            }

            /* Sidebar Styles */
            .jpm-profile-sidebar {
                width: 280px !important;
                min-width: 280px !important;
                max-width: 280px !important;
                flex: 0 0 280px !important;
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
                background: #f9fafb;
                border-right: 1px solid #e5e7eb;
                display: flex !important;
                flex-direction: column !important;
                float: none !important;
                position: relative !important;
                vertical-align: top !important;
                margin: 0 !important;
                padding: 0 !important;
                align-self: stretch !important;
                min-height: 100% !important;
            }

            .jpm-profile-sidebar-header {
                padding: 24px 20px;
                border-bottom: 1px solid #e5e7eb;
                background: #ffffff;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .jpm-profile-user-info {
                text-align: center;
                width: 100%;
            }

            .jpm-profile-user-avatar {
                margin-bottom: 12px;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .jpm-profile-user-avatar img {
                border-radius: 50%;
                border: 3px solid #e5e7eb;
                width: 80px !important;
                height: 80px !important;
                display: block;
                margin: 0 auto;
            }

            .jpm-profile-user-name {
                font-size: 16px;
                font-weight: 600;
                color: #111827;
                margin-bottom: 4px;
            }

            .jpm-profile-user-email {
                font-size: 13px;
                color: #6b7280;
            }

            .jpm-profile-nav {
                flex: 1;
                padding: 16px 0;
                display: flex;
                flex-direction: column;
            }

            .jpm-profile-nav-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 20px;
                color: #6b7280;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.15s ease;
                border-left: 3px solid transparent;
                position: relative;
            }

            .jpm-profile-nav-item:hover {
                background: #f3f4f6;
                color: #111827;
            }

            .jpm-profile-nav-item.active {
                background: #eff6ff;
                color: #2563eb;
                border-left-color: #2563eb;
            }

            .jpm-profile-nav-item svg {
                flex-shrink: 0;
            }

            .jpm-nav-badge {
                margin-left: auto;
                background: #2563eb;
                color: #ffffff;
                font-size: 11px;
                font-weight: 600;
                padding: 2px 8px;
                border-radius: 12px;
                min-width: 20px;
                text-align: center;
            }

            .jpm-profile-nav-item.active .jpm-nav-badge {
                background: #1d4ed8;
            }

            .jpm-nav-logout {
                margin-top: auto;
                border-top: 1px solid #e5e7eb;
                color: #dc2626;
            }

            .jpm-nav-logout:hover {
                background: #fef2f2;
                color: #dc2626;
            }

            /* Main Content Styles */
            .jpm-profile-main {
                flex: 1 1 auto !important;
                flex-grow: 1 !important;
                flex-shrink: 1 !important;
                min-width: 0 !important;
                width: auto !important;
                padding: 32px;
                background: #ffffff;
                min-height: 600px;
                float: none !important;
                display: block !important;
                position: relative !important;
                overflow: visible !important;
                vertical-align: top !important;
                margin: 0 !important;
            }

            /* Ensure all tab content stays inside main */
            .jpm-profile-main .jpm-profile-tab-content {
                display: none !important;
                position: relative !important;
                width: 100% !important;
                float: none !important;
                clear: both !important;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box !important;
            }

            .jpm-profile-main .jpm-profile-tab-content.active {
                display: block !important;
                position: relative !important;
                width: 100% !important;
                float: none !important;
                clear: both !important;
                box-sizing: border-box !important;
            }

            /* Prevent tabs from breaking out */
            .jpm-profile-main {
                contain: layout style !important;
            }

            .jpm-profile-tab-content {
                display: none !important;
                position: relative !important;
                width: 100% !important;
                float: none !important;
                clear: both !important;
            }

            .jpm-profile-tab-content.active {
                display: block !important;
                position: relative !important;
                width: 100% !important;
                float: none !important;
                clear: both !important;
            }

            .jpm-profile-tab-header {
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid #e5e7eb;
            }

            .jpm-profile-tab-title {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                color: #111827;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .jpm-applications-count {
                font-size: 16px;
                font-weight: 400;
                color: #6b7280;
            }

            /* Dashboard Stats */
            .jpm-dashboard-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 32px;
            }

            .jpm-stat-card {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 16px;
                transition: box-shadow 0.15s ease;
            }

            .jpm-stat-card:hover {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .jpm-stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .jpm-stat-content {
                flex: 1;
            }

            .jpm-stat-value {
                font-size: 28px;
                font-weight: 700;
                color: #111827;
                line-height: 1;
                margin-bottom: 4px;
            }

            .jpm-stat-label {
                font-size: 13px;
                color: #6b7280;
                font-weight: 500;
            }

            .jpm-dashboard-status-breakdown {
                margin-bottom: 32px;
            }

            .jpm-status-breakdown-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .jpm-status-breakdown-item {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 16px;
            }

            .jpm-status-breakdown-info {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }

            .jpm-status-breakdown-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }

            .jpm-status-breakdown-count {
                font-size: 14px;
                color: #6b7280;
                font-weight: 500;
            }

            .jpm-status-breakdown-bar {
                height: 6px;
                background: #e5e7eb;
                border-radius: 3px;
                overflow: hidden;
            }

            .jpm-status-breakdown-fill {
                height: 100%;
                border-radius: 3px;
                transition: width 0.3s ease;
            }

            .jpm-dashboard-recent {
                margin-top: 32px;
            }

            .jpm-dashboard-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .jpm-dashboard-section-title {
                font-size: 18px;
                font-weight: 600;
                color: #111827;
                margin: 0;
            }

            .jpm-view-all-link {
                color: #2563eb;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
            }

            .jpm-view-all-link:hover {
                text-decoration: underline;
                color: #1d4ed8;
            }

            /* Application Cards */
            .jpm-applications-list {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            .jpm-application-card {
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 20px;
                background: #ffffff;
                transition: box-shadow 0.15s ease;
            }

            .jpm-application-card:hover {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .jpm-application-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 16px;
                flex-wrap: wrap;
                gap: 12px;
            }

            .jpm-application-job-title h4 {
                margin: 0 0 4px 0;
                font-size: 16px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-application-number {
                font-size: 12px;
                color: #6b7280;
            }

            .jpm-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
                white-space: nowrap;
            }

            .jpm-application-details {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 12px;
                margin-bottom: 16px;
            }

            .jpm-application-detail-item {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .jpm-detail-label {
                font-size: 12px;
                color: #6b7280;
                font-weight: 500;
            }

            .jpm-detail-value {
                font-size: 14px;
                color: #111827;
            }

            .jpm-toggle-details {
                background: none;
                border: none;
                color: #2563eb;
                cursor: pointer;
                padding: 8px 0;
                font-size: 13px;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 6px;
                transition: color 0.15s ease;
            }

            .jpm-toggle-details:hover {
                color: #1d4ed8;
                text-decoration: underline;
            }

            .jpm-toggle-icon {
                font-size: 10px;
                transition: transform 0.2s ease;
            }

            .jpm-toggle-details.active .jpm-toggle-icon {
                transform: rotate(180deg);
            }

            .jpm-application-details-content {
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid #e5e7eb;
            }

            .jpm-form-data-section {
                margin-bottom: 32px;
            }

            .jpm-form-data-section:last-child {
                margin-bottom: 0;
            }

            .jpm-form-data-section-title {
                font-size: 16px;
                font-weight: 600;
                color: #111827;
                margin: 0 0 16px 0;
                padding-bottom: 8px;
                border-bottom: 2px solid #e5e7eb;
            }

            .jpm-form-data-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 12px;
            }

            .jpm-form-data-item {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .jpm-form-data-label {
                font-size: 12px;
                color: #6b7280;
                font-weight: 500;
            }

            .jpm-form-data-value {
                font-size: 14px;
                color: #111827;
                word-break: break-word;
            }

            .jpm-form-data-value a {
                color: #2563eb;
                text-decoration: none;
            }

            .jpm-form-data-value a:hover {
                text-decoration: underline;
            }

            /* Information Tab */
            .jpm-information-content {
                display: flex;
                flex-direction: column;
                gap: 32px;
            }

            .jpm-info-section {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 24px;
            }

            .jpm-info-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                margin-bottom: 20px;
            }

            .jpm-edit-buttons {
                display: flex;
                gap: 12px;
                align-items: center;
            }

            .jpm-editable-value {
                cursor: text;
            }

            .jpm-edit-input {
                width: 100%;
                padding: 0;
                border: none;
                border-bottom: 2px solid #2563eb;
                border-radius: 0;
                font-size: 15px;
                color: #111827;
                background: transparent;
                font-weight: 500;
                transition: border-color 0.15s ease;
                outline: none;
            }

            .jpm-edit-input:focus {
                border-bottom-color: #1d4ed8;
            }

            .jpm-info-section-title {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: #111827;
            }

            .jpm-edit-info-btn {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                background: #2563eb;
                color: #ffffff;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: background-color 0.15s ease;
            }

            .jpm-edit-info-btn:hover {
                background: #1d4ed8;
            }

            .jpm-edit-info-btn svg {
                width: 16px;
                height: 16px;
            }

            .jpm-info-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                margin-bottom: 20px;
            }

            .jpm-editable-value {
                cursor: text;
            }

            .jpm-edit-input {
                width: 100%;
                padding: 0;
                border: none;
                border-bottom: 2px solid #2563eb;
                border-radius: 0;
                font-size: 15px;
                color: #111827;
                background: transparent;
                font-weight: 500;
                transition: border-color 0.15s ease;
                outline: none;
            }

            .jpm-edit-input:focus {
                border-bottom-color: #1d4ed8;
            }

            .jpm-btn-secondary {
                background: #6b7280;
                color: #ffffff;
                padding: 8px 16px;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: background-color 0.15s ease;
            }

            .jpm-btn-secondary:hover {
                background: #4b5563;
            }

            .jpm-edit-message {
                margin-top: 16px;
            }

            .jpm-edit-message .notice {
                padding: 12px 16px;
                border-radius: 6px;
                margin: 0;
            }

            .jpm-edit-message .notice-success {
                background: #d1fae5;
                color: #065f46;
                border: 1px solid #6ee7b7;
            }

            .jpm-edit-message .notice-error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fca5a5;
            }

            .jpm-info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 16px;
            }

            .jpm-info-item {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .jpm-info-label {
                font-size: 13px;
                font-weight: 500;
                color: #6b7280;
            }

            .jpm-info-value {
                font-size: 15px;
                color: #111827;
                font-weight: 500;
            }

            .jpm-no-applications {
                text-align: center;
                padding: 60px 20px;
                color: #6b7280;
            }

            .jpm-no-applications p {
                margin: 0 0 20px 0;
                font-size: 15px;
            }

            .jpm-btn {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 500;
                text-align: center;
                text-decoration: none;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.15s ease;
                font-family: inherit;
            }

            .jpm-btn-primary {
                background: #2563eb;
                color: #ffffff;
            }

            .jpm-btn-primary:hover {
                background: #1d4ed8;
            }

            /* Responsive */
            @media (max-width: 1024px) {
                .jpm-user-profile-layout {
                    flex-direction: column;
                }

                .jpm-profile-sidebar {
                    width: 100%;
                    min-width: auto;
                    border-right: none;
                    border-bottom: 1px solid #e5e7eb;
                }

                .jpm-profile-nav {
                    flex-direction: row;
                    overflow-x: auto;
                    padding: 12px 0;
                }

                .jpm-profile-nav-item {
                    white-space: nowrap;
                    border-left: none;
                    border-bottom: 3px solid transparent;
                }

                .jpm-profile-nav-item.active {
                    border-left: none;
                    border-bottom-color: #2563eb;
                }

                .jpm-profile-main {
                    padding: 24px;
                }
            }

            @media (max-width: 768px) {
                .jpm-user-profile-wrapper {
                    margin: 20px auto;
                    padding: 10px;
                }

                .jpm-profile-main {
                    padding: 20px;
                }

                .jpm-dashboard-stats {
                    grid-template-columns: 1fr;
                }

                .jpm-application-header {
                    flex-direction: column;
                }

                .jpm-application-details,
                .jpm-form-data-grid,
                .jpm-info-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Force correct layout on load
                function fixLayout() {
                    var $layout = $('.jpm-user-profile-layout');
                    var $sidebar = $('.jpm-profile-sidebar');
                    var $main = $('.jpm-profile-main');

                    if ($layout.length && $sidebar.length && $main.length) {
                        $layout.css({
                            'display': 'flex',
                            'flex-direction': 'row',
                            'align-items': 'flex-start',
                            'width': '100%'
                        });
                        $sidebar.css({
                            'width': '280px',
                            'min-width': '280px',
                            'max-width': '280px',
                            'flex': '0 0 280px',
                            'flex-shrink': '0',
                            'flex-grow': '0',
                            'display': 'flex',
                            'flex-direction': 'column',
                            'float': 'none'
                        });
                        $main.css({
                            'flex': '1 1 auto',
                            'flex-grow': '1',
                            'flex-shrink': '1',
                            'display': 'block',
                            'float': 'none'
                        });
                    }
                }

                // Fix layout immediately and after delays
                fixLayout();
                setTimeout(fixLayout, 100);
                setTimeout(fixLayout, 500);
                setTimeout(fixLayout, 1000);

                // Ensure tab content stays in main container
                function fixTabContent() {
                    $('.jpm-profile-tab-content').each(function () {
                        var $tab = $(this);
                        var $main = $tab.closest('.jpm-profile-main');
                        if ($main.length && !$main.is($tab.parent())) {
                            // Tab is outside main, move it inside
                            $main.append($tab);
                        }
                        $tab.css({
                            'display': $tab.hasClass('active') ? 'block' : 'none',
                            'position': 'relative',
                            'width': '100%',
                            'float': 'none',
                            'clear': 'both'
                        });
                    });
                }

                fixTabContent();
                setTimeout(fixTabContent, 100);
                setTimeout(fixTabContent, 500);

                // Tab switching
                $('.jpm-profile-nav-item').on('click', function (e) {
                    e.preventDefault();

                    // Don't handle logout link
                    if ($(this).hasClass('jpm-nav-logout')) {
                        return true;
                    }

                    var tab = $(this).data('tab');
                    switchTab(tab);
                });

                // View All link handler
                $('.jpm-view-all-link').on('click', function (e) {
                    e.preventDefault();
                    var tab = $(this).data('tab');
                    switchTab(tab);
                });

                // Tab switching function
                function switchTab(tab) {
                    // Update active nav item
                    $('.jpm-profile-nav-item').removeClass('active');
                    $('.jpm-profile-nav-item[data-tab="' + tab + '"]').addClass('active');

                    // Update active tab content
                    $('.jpm-profile-tab-content').removeClass('active');
                    var $activeTab = $('#jpm-tab-' + tab);

                    // Ensure tab is inside main container
                    var $main = $('.jpm-profile-main');
                    if ($main.length && !$main.is($activeTab.parent())) {
                        $main.append($activeTab);
                    }

                    $activeTab.addClass('active');

                    // Force correct display
                    $activeTab.css({
                        'display': 'block',
                        'position': 'relative',
                        'width': '100%',
                        'float': 'none',
                        'clear': 'both'
                    });

                    // Hide other tabs
                    $('.jpm-profile-tab-content').not($activeTab).css('display', 'none');

                    // Scroll to top of main content
                    $('.jpm-profile-main').scrollTop(0);

                    // Re-apply layout fix
                    fixLayout();
                }

                // Toggle application details
                $('.jpm-toggle-details').on('click', function () {
                    var $button = $(this);
                    var applicationId = $button.data('application-id');
                    var $content = $('#jpm-details-' + applicationId);

                    $content.slideToggle(300);
                    $button.toggleClass('active');
                });

                // Edit personal information - toggle to edit mode
                $('#jpm-edit-personal-info').on('click', function () {
                    // Hide edit button, show save and cancel
                    $(this).hide();
                    $('#jpm-save-personal-info').show();
                    $('#jpm-cancel-edit-info').show();

                    // Switch editable fields to input mode
                    $('.jpm-editable-value').each(function () {
                        var $value = $(this);
                        var fieldName = $value.data('field');
                        var $input = $('#jpm-edit-' + fieldName);

                        if ($input.length) {
                            // Set input value from span text
                            var currentValue = $value.text().trim();
                            $input.val(currentValue);
                            // Hide span and show input
                            $value.hide();
                            $input.css('display', 'block').show();
                            // Focus on first input
                            if ($input.attr('name') === 'display_name') {
                                setTimeout(function () {
                                    $input.focus();
                                }, 100);
                            }
                        }
                    });

                    $('#jpm-edit-message').html('').hide();
                });

                // Cancel edit - revert to display mode
                $('#jpm-cancel-edit-info').on('click', function () {
                    // Show edit button, hide save and cancel
                    $('#jpm-edit-personal-info').show();
                    $('#jpm-save-personal-info').hide();
                    $(this).hide();

                    // Switch input fields back to display mode
                    $('.jpm-edit-input').each(function () {
                        var $input = $(this);
                        var fieldName = $input.attr('name');
                        var $value = $('[data-field="' + fieldName + '"]');

                        if ($value.length) {
                            // Reset input to original value
                            $input.val($value.text().trim());
                            $input.hide();
                            $value.show();
                        }
                    });

                    $('#jpm-edit-message').html('').hide();
                });

                // Save changes
                $('#jpm-save-personal-info').on('click', function () {
                    var $button = $(this);
                    var $message = $('#jpm-edit-message');
                    var $btnText = $button.find('span').text();

                    // Validate required field
                    var displayName = $('#jpm-edit-display-name').val().trim();
                    if (!displayName) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Display name is required.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    // Disable button
                    $button.prop('disabled', true);
                    $button.find('span').text('<?php echo esc_js(__('Saving...', 'job-posting-manager')); ?>');
                    $message.hide();

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_update_personal_info',
                            jpm_update_info_nonce: $('#jpm_update_info_nonce').val(),
                            display_name: displayName,
                            first_name: $('#jpm-edit-first-name').val().trim(),
                            last_name: $('#jpm-edit-last-name').val().trim()
                        },
                        success: function (response) {
                            if (response.success) {
                                // Update displayed values
                                if (response.data.data && response.data.data.display_name) {
                                    $('#jpm-display-name-value').text(response.data.data.display_name);
                                    $('#jpm-edit-display-name').val(response.data.data.display_name);
                                }
                                if (response.data.data && response.data.data.first_name !== undefined) {
                                    $('#jpm-first-name-value').text(response.data.data.first_name || '');
                                    $('#jpm-edit-first-name').val(response.data.data.first_name || '');
                                }
                                if (response.data.data && response.data.data.last_name !== undefined) {
                                    $('#jpm-last-name-value').text(response.data.data.last_name || '');
                                    $('#jpm-edit-last-name').val(response.data.data.last_name || '');
                                }

                                // Switch back to display mode
                                $('.jpm-edit-input').hide();
                                $('.jpm-editable-value').show();

                                // Show edit button, hide save and cancel
                                $('#jpm-edit-personal-info').show();
                                $('#jpm-save-personal-info').hide();
                                $('#jpm-cancel-edit-info').hide();

                                // Show success message
                                $message.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();

                                // Hide message after 3 seconds
                                setTimeout(function () {
                                    $message.fadeOut();
                                }, 3000);
                            } else {
                                $message.html('<div class="notice notice-error"><p>' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('An error occurred. Please try again.', 'job-posting-manager')); ?>') + '</p></div>').show();
                            }

                            // Re-enable button
                            $button.prop('disabled', false);
                            $button.find('span').text($btnText);
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('An error occurred. Please try again.', 'job-posting-manager')); ?></p></div>').show();
                            $button.prop('disabled', false);
                            $button.find('span').text($btnText);
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle user login via AJAX
     */
    public function handle_login()
    {
        check_ajax_referer('jpm_login', 'nonce');

        // If user is already logged in
        if (is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are already logged in.', 'job-posting-manager')]);
        }

        // Get form data
        $login = sanitize_text_field($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] == '1';
        $redirect_url = esc_url_raw($_POST['redirect_url'] ?? '');

        // Validate required fields
        if (empty($login)) {
            wp_send_json_error(['message' => __('Please enter your username or email address.', 'job-posting-manager')]);
        }

        if (empty($password)) {
            wp_send_json_error(['message' => __('Password is required.', 'job-posting-manager')]);
        }

        // Find user by email or username
        $user = null;
        if (is_email($login)) {
            // Try email first if it looks like an email
            $user = get_user_by('email', $login);
        }
        
        // If not found by email, try username
        if (!$user) {
            $user = get_user_by('login', $login);
        }
        
        if (!$user) {
            wp_send_json_error(['message' => __('Invalid username/email or password.', 'job-posting-manager')]);
        }

        // Verify password
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            wp_send_json_error(['message' => __('Invalid email or password.', 'job-posting-manager')]);
        }

        // Login user
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        // Determine redirect URL
        if (!empty($redirect_url)) {
            $final_redirect = esc_url_raw($redirect_url);
            // Remove any query parameters from redirect URL
            $final_redirect = strtok($final_redirect, '?');
        } else {
            // Try to find a page with [all_jobs] shortcode
            $pages = get_pages();
            foreach ($pages as $page) {
                if (has_shortcode($page->post_content, 'all_jobs')) {
                    $final_redirect = get_permalink($page->ID);
                    break;
                }
            }
            if (empty($final_redirect)) {
                $final_redirect = home_url();
            }
        }

        wp_send_json_success([
            'message' => __('Login successful!', 'job-posting-manager'),
            'redirect_url' => $final_redirect
        ]);
    }

    /**
     * Handle logout via AJAX
     */
    public function handle_logout()
    {
        check_ajax_referer('jpm_logout', 'nonce');

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are not logged in.', 'job-posting-manager')]);
        }

        // Get redirect URL
        $redirect_url = esc_url_raw($_POST['redirect_url'] ?? home_url('/sign-in/'));

        // Logout user
        wp_logout();

        wp_send_json_success([
            'message' => __('Logout successful!', 'job-posting-manager'),
            'redirect_url' => $redirect_url
        ]);
    }

    /**
     * Logout shortcode
     * Usage: [jpm_logout text="Logout" redirect_url="/sign-in/"]
     */
    public function logout_shortcode($atts)
    {
        // If user is not logged in, show message
        if (!is_user_logged_in()) {
            return '<div class="jpm-logout-message"><p>' . __('You are not logged in.', 'job-posting-manager') . '</p></div>';
        }

        $atts = shortcode_atts([
            'text' => __('Logout', 'job-posting-manager'),
            'redirect_url' => home_url('/sign-in/'),
        ], $atts);

        $redirect_url = esc_url_raw($atts['redirect_url']);

        ob_start();
        ?>
        <div class="jpm-logout-wrapper">
            <button type="button" class="jpm-logout-button" data-redirect-url="<?php echo esc_url($redirect_url); ?>">
                <?php echo esc_html($atts['text']); ?>
            </button>
        </div>

        <!-- Logout Confirmation Modal -->
        <div id="jpm-logout-modal" class="jpm-logout-modal">
            <div class="jpm-logout-modal-overlay"></div>
            <div class="jpm-logout-modal-content">
                <?php wp_nonce_field('jpm_logout', 'jpm_logout_nonce'); ?>
                <input type="hidden" id="jpm-logout-redirect-url" value="<?php echo esc_attr($redirect_url); ?>" />
                <div class="jpm-logout-modal-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </div>
                <h3 class="jpm-logout-modal-title"><?php _e('Confirm Logout', 'job-posting-manager'); ?></h3>
                <p class="jpm-logout-modal-message"><?php _e('Are you sure you want to logout? You will need to sign in again to access your account.', 'job-posting-manager'); ?></p>
                <div class="jpm-logout-modal-actions">
                    <button type="button" class="jpm-logout-modal-cancel"><?php _e('No, stay logged in', 'job-posting-manager'); ?></button>
                    <button type="button" class="jpm-logout-modal-confirm">
                        <span class="jpm-logout-confirm-text"><?php _e('Yes, log out', 'job-posting-manager'); ?></span>
                        <span class="jpm-logout-loading-text" style="display: none;"><?php _e('Logging out...', 'job-posting-manager'); ?></span>
                    </button>
                </div>
            </div>
        </div>

        <style>
            @keyframes jpm-modal-fade-in {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }
            @keyframes jpm-modal-slide-up {
                from {
                    opacity: 0;
                    transform: translate(-50%, -40px);
                }
                to {
                    opacity: 1;
                    transform: translate(-50%, -50%);
                }
            }
            .jpm-logout-wrapper {
                width: 100%;
            }
            .jpm-logout-button {
                display: block;
                width: 100%;
                box-sizing: border-box;
                padding: 10px 20px;
                background-color: transparent;
                border: 2px solid #ffc527 !important;
                color: #ffffff;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 500;
                transition: all 0.3s ease;
                cursor: pointer;
                text-align: center;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-family: inherit;
                font-size: inherit;
            }
            .jpm-logout-button:hover {
                background-color: #ffc527;
                border: 2px solid transparent !important;
                color: #000000;
            }
            .jpm-logout-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999999;
            }
            .jpm-logout-modal.active {
                display: block;
                animation: jpm-modal-fade-in 0.3s ease;
            }
            .jpm-logout-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(4px);
            }
            .jpm-logout-modal-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 40px;
                max-width: 420px;
                width: 90%;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                animation: jpm-modal-slide-up 0.4s ease;
                text-align: center;
            }
            .jpm-logout-modal-icon {
                width: 80px;
                height: 80px;
                margin: 0 auto 20px;
                background: rgba(37, 99, 235, 0.1);
                border: 2px solid #2563eb;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #2563eb;
            }
            .jpm-logout-modal-icon svg {
                width: 40px;
                height: 40px;
            }
            .jpm-logout-modal-title {
                color: #111827;
                font-size: 24px;
                font-weight: 600;
                margin: 0 0 16px;
            }
            .jpm-logout-modal-message {
                color: #6b7280;
                font-size: 15px;
                line-height: 1.6;
                margin: 0 0 32px;
            }
            .jpm-logout-modal-actions {
                display: flex;
                gap: 12px;
                justify-content: center;
            }
            .jpm-logout-modal-cancel,
            .jpm-logout-modal-confirm {
                padding: 12px 32px;
                border-radius: 8px;
                font-weight: 500;
                font-size: 15px;
                cursor: pointer;
                transition: all 0.3s ease;
                border: 2px solid transparent;
                font-family: inherit;
            }
            .jpm-logout-modal-cancel {
                background-color: transparent;
                border-color: #e5e7eb;
                color: #374151;
            }
            .jpm-logout-modal-cancel:hover {
                background-color: #f9fafb;
                border-color: #2563eb;
                color: #2563eb;
                transform: translateY(-2px);
            }
            .jpm-logout-modal-confirm {
                background-color: transparent;
                border-color: #2563eb;
                color: #2563eb;
            }
            .jpm-logout-modal-confirm:hover {
                background-color: transparent;
                border-color: #2563eb;
                color: #2563eb;
                transform: translateY(-2px);
            }
            .jpm-logout-modal-confirm:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                pointer-events: none;
            }
        </style>

        <script>
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                const logoutButton = document.querySelector('.jpm-logout-button');
                const modal = document.getElementById('jpm-logout-modal');
                const cancelButton = document.querySelector('.jpm-logout-modal-cancel');
                const confirmButton = document.querySelector('.jpm-logout-modal-confirm');
                const overlay = document.querySelector('.jpm-logout-modal-overlay');
                const confirmText = document.querySelector('.jpm-logout-confirm-text');
                const loadingText = document.querySelector('.jpm-logout-loading-text');

                if (!logoutButton || !modal) return;

                function openModal() {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    // Reset button state
                    if (confirmButton) {
                        confirmButton.disabled = false;
                        if (confirmText) confirmText.style.display = 'inline';
                        if (loadingText) loadingText.style.display = 'none';
                    }
                }

                function closeModal() {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                    // Reset button state
                    if (confirmButton) {
                        confirmButton.disabled = false;
                        if (confirmText) confirmText.style.display = 'inline';
                        if (loadingText) loadingText.style.display = 'none';
                    }
                }

                function proceedLogout() {
                    if (!confirmButton || confirmButton.disabled) return;

                    // Show loading state
                    confirmButton.disabled = true;
                    if (confirmText) confirmText.style.display = 'none';
                    if (loadingText) loadingText.style.display = 'inline';

                    const nonce = document.getElementById('jpm_logout_nonce')?.value || '';
                    const redirectUrl = document.getElementById('jpm-logout-redirect-url')?.value || '<?php echo esc_js(home_url('/sign-in/')); ?>';

                    // Make AJAX request
                    const formData = new FormData();
                    formData.append('action', 'jpm_logout');
                    formData.append('nonce', nonce);
                    formData.append('redirect_url', redirectUrl);

                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Redirect to sign-in page
                            window.location.href = data.data.redirect_url || redirectUrl;
                        } else {
                            // Show error and reset button
                            alert(data.data?.message || 'Logout failed. Please try again.');
                            confirmButton.disabled = false;
                            if (confirmText) confirmText.style.display = 'inline';
                            if (loadingText) loadingText.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Logout error:', error);
                        alert('An error occurred. Please try again.');
                        confirmButton.disabled = false;
                        if (confirmText) confirmText.style.display = 'inline';
                        if (loadingText) loadingText.style.display = 'none';
                    });
                }

                logoutButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal();
                });

                if (cancelButton) {
                    cancelButton.addEventListener('click', closeModal);
                }
                if (confirmButton) {
                    confirmButton.addEventListener('click', proceedLogout);
                }
                if (overlay) {
                    overlay.addEventListener('click', closeModal);
                }

                // Close on Escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeModal();
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle forgot password request via AJAX
     */
    public function handle_forgot_password()
    {
        check_ajax_referer('jpm_forgot_password', 'nonce');

        // If user is already logged in
        if (is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are already logged in.', 'job-posting-manager')]);
        }

        // Get form data
        $email = sanitize_email($_POST['email'] ?? '');

        // Validate required fields
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'job-posting-manager')]);
        }

        // Find user by email
        $user = get_user_by('email', $email);
        if (!$user) {
            // For security, don't reveal if email exists or not
            wp_send_json_success([
                'message' => __('If an account exists with this email address, a password reset link has been sent. Please check your email and click the link to reset your password on the reset password page.', 'job-posting-manager')
            ]);
            return;
        }

        // Use WordPress's built-in password reset function
        $result = retrieve_password($user->user_login);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success([
                'message' => __('Password reset link has been sent to your email address. Please check your inbox and click the link to reset your password on the reset password page.', 'job-posting-manager')
            ]);
        }
    }

    /**
     * Handle password reset via AJAX
     */
    public function handle_reset_password()
    {
        check_ajax_referer('jpm_reset_password', 'nonce');

        // If user is already logged in
        if (is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are already logged in.', 'job-posting-manager')]);
        }

        // Get form data
        $key = sanitize_text_field($_POST['key'] ?? '');
        $login = sanitize_user($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        // Validate required fields
        if (empty($key) || empty($login)) {
            wp_send_json_error(['message' => __('Invalid reset link. Please request a new password reset link.', 'job-posting-manager')]);
        }

        if (empty($password)) {
            wp_send_json_error(['message' => __('Password is required.', 'job-posting-manager')]);
        }

        // Validate password length
        if (strlen($password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters long.', 'job-posting-manager')]);
        }

        // Validate passwords match (client-side should catch this, but verify server-side)
        if (!empty($password_confirm) && $password !== $password_confirm) {
            wp_send_json_error(['message' => __('Passwords do not match.', 'job-posting-manager')]);
        }

        // Validate reset key
        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            $error_code = $user->get_error_code();
            if ($error_code === 'expired_key') {
                wp_send_json_error(['message' => __('This password reset link has expired. Please request a new one.', 'job-posting-manager')]);
            } else {
                wp_send_json_error(['message' => __('This password reset link is invalid. Please request a new one.', 'job-posting-manager')]);
            }
        }

        // Reset the password
        reset_password($user, $password);

        // Determine redirect URL
        $final_redirect = home_url('/sign-in/');

        wp_send_json_success([
            'message' => __('Password has been reset successfully! Redirecting to login...', 'job-posting-manager'),
            'redirect_url' => $final_redirect
        ]);
    }

    /**
     * Handle update personal information via AJAX
     */
    public function handle_update_personal_info()
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to update your information.', 'job-posting-manager')]);
        }

        check_ajax_referer('jpm_update_personal_info', 'jpm_update_info_nonce');

        $user_id = get_current_user_id();
        $current_user = wp_get_current_user();

        // Verify user is updating their own information
        if ($user_id != $current_user->ID) {
            wp_send_json_error(['message' => __('You can only update your own information.', 'job-posting-manager')]);
        }

        // Get and sanitize form data
        $display_name = sanitize_text_field($_POST['display_name'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');

        // Validate required fields
        if (empty($display_name)) {
            wp_send_json_error(['message' => __('Display name is required.', 'job-posting-manager')]);
        }

        // Update user data
        $user_data = [
            'ID' => $user_id,
            'display_name' => $display_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];

        $result = wp_update_user($user_data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Return updated data
        $updated_user = get_userdata($user_id);
        wp_send_json_success([
            'message' => __('Your information has been updated successfully.', 'job-posting-manager'),
            'data' => [
                'display_name' => $updated_user->display_name,
                'first_name' => $updated_user->first_name,
                'last_name' => $updated_user->last_name,
            ]
        ]);
    }

    /**
     * Customize password reset email to use custom reset page
     */
    public function customize_password_reset_email($message, $key, $user_login, $user_data)
    {
        // Build custom reset URL
        $reset_url = add_query_arg([
            'key' => $key,
            'login' => rawurlencode($user_login)
        ], home_url('/reset-password/'));

        // Build various possible URL patterns that WordPress might use
        $base_urls = [
            network_site_url('wp-login.php'),
            site_url('wp-login.php'),
            home_url('wp-login.php'),
        ];

        // Create patterns for different parameter orders and combinations
        $patterns = [];
        foreach ($base_urls as $base_url) {
            // Pattern 1: action=rp&key=...&login=...
            $patterns[] = add_query_arg([
                'action' => 'rp',
                'key' => $key,
                'login' => rawurlencode($user_login)
            ], $base_url);

            // Pattern 2: login=...&key=...&action=rp
            $patterns[] = add_query_arg([
                'login' => rawurlencode($user_login),
                'key' => $key,
                'action' => 'rp'
            ], $base_url);

            // Pattern 3: with wp_lang parameter
            $patterns[] = add_query_arg([
                'login' => rawurlencode($user_login),
                'key' => $key,
                'action' => 'rp',
                'wp_lang' => get_locale()
            ], $base_url);
        }

        // Replace exact URL matches
        foreach ($patterns as $pattern) {
            $message = str_replace($pattern, $reset_url, $message);
        }

        // Also use regex to catch any wp-login.php URL with action=rp
        // This catches URLs with different parameter orders or additional parameters like wp_lang
        $message = preg_replace(
            '/https?:\/\/[^\s"<>]+\/wp-login\.php\?[^"\s<>]*action=rp[^"\s<>]*/i',
            $reset_url,
            $message
        );

        return $message;
    }

    /**
     * Find register page AJAX handler
     */
    public function find_register_page()
    {
        $pages = get_pages();
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'jpm_register')) {
                wp_send_json_success(['url' => get_permalink($page->ID)]);
            }
        }
        wp_send_json_error(['message' => __('Register page not found.', 'job-posting-manager')]);
    }
}