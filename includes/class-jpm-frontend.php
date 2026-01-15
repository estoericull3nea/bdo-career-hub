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
        add_action('wp_ajax_jpm_find_register_page', [$this, 'find_register_page']);
        add_action('wp_ajax_nopriv_jpm_find_register_page', [$this, 'find_register_page']);
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
                            <a href="<?php echo esc_url(wp_login_url()); ?>" class="jpm-register-login-link">
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
                max-width: 200px;
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
                padding: 12px 16px;
                border-radius: 6px;
                border-left: 3px solid;
                font-size: 14px;
            }

            .jpm-register-message .notice-success {
                background: #f0fdf4;
                border-left-color: #22c55e;
                color: #166534;
            }

            .jpm-register-message .notice-error {
                background: #fef2f2;
                border-left-color: #ef4444;
                color: #991b1b;
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

            .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-input-wrapper {
                position: relative;
            }

            .jpm-input {
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

            .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb;
            }

            .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626;
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
                    max-width: 150px;
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

        // Auto-login user
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        // Determine redirect URL
        if (!empty($redirect_url)) {
            $final_redirect = $redirect_url;
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
            'message' => __('Account created successfully!', 'job-posting-manager'),
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
            'title' => __('Sign In', 'job-posting-manager'),
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
                        echo '<img src="' . esc_url($bdo_logo_url) . '" alt="BDO" class="jpm-logo-image" />';
                        ?>
                    </div>
                    <h2 class="jpm-login-title"><?php echo esc_html($atts['title']); ?></h2>
                </div>

                <div id="jpm-login-message" class="jpm-login-message" style="display: none;"></div>

                <form id="jpm-login-form" class="jpm-login-form">
                    <?php wp_nonce_field('jpm_login', 'jpm_login_nonce'); ?>
                    <input type="hidden" name="redirect_url" value="<?php echo esc_attr($atts['redirect_url']); ?>" />

                    <div class="jpm-form-field">
                        <label for="jpm-login-email" class="jpm-input-label">
                            <?php _e('Email Address', 'job-posting-manager'); ?> <span class="required">*</span>
                        </label>
                        <div class="jpm-input-wrapper">
                            <input type="email" id="jpm-login-email" name="email" required class="jpm-input"
                                placeholder="<?php esc_attr_e('your.email@example.com', 'job-posting-manager'); ?>" />
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
                        <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="jpm-forgot-password-link">
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
                            <a href="#" class="jpm-login-register-link" id="jpm-find-register-page">
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
                padding: 12px 16px;
                border-radius: 6px;
                border-left: 3px solid;
                font-size: 14px;
            }

            .jpm-login-message .notice-success {
                background: #f0fdf4;
                border-left-color: #22c55e;
                color: #166534;
            }

            .jpm-login-message .notice-error {
                background: #fef2f2;
                border-left-color: #ef4444;
                color: #991b1b;
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

            .jpm-input-label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
                font-size: 13px;
                color: #374151;
            }

            .jpm-input-label .required {
                color: #dc2626;
                margin-left: 2px;
            }

            .jpm-input-wrapper {
                position: relative;
            }

            .jpm-input {
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

            .jpm-input::placeholder {
                color: #9ca3af;
            }

            .jpm-input:focus {
                outline: none;
                border-bottom-color: #2563eb;
            }

            .jpm-input:invalid:not(:placeholder-shown) {
                border-bottom-color: #dc2626;
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

                // Find register page link
                $('#jpm-find-register-page').on('click', function (e) {
                    e.preventDefault();
                    $.ajax({
                        url: '<?php echo $ajax_url; ?>',
                        type: 'POST',
                        data: {
                            action: 'jpm_find_register_page'
                        },
                        success: function (response) {
                            if (response.success && response.data.url) {
                                window.location.href = response.data.url;
                            } else {
                                window.location.href = '<?php echo $register_url; ?>';
                            }
                        },
                        error: function () {
                            window.location.href = '<?php echo $register_url; ?>';
                        }
                    });
                });

                // Form submission
                $('#jpm-login-form').on('submit', function (e) {
                    e.preventDefault();

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
                                    if (response.data.redirect_url) {
                                        window.location.href = response.data.redirect_url;
                                    } else {
                                        window.location.reload();
                                    }
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
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] == '1';
        $redirect_url = esc_url_raw($_POST['redirect_url'] ?? '');

        // Validate required fields
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'job-posting-manager')]);
        }

        if (empty($password)) {
            wp_send_json_error(['message' => __('Password is required.', 'job-posting-manager')]);
        }

        // Find user by email
        $user = get_user_by('email', $email);
        if (!$user) {
            wp_send_json_error(['message' => __('Invalid email or password.', 'job-posting-manager')]);
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
            $final_redirect = $redirect_url;
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