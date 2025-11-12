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
        add_action('wp_ajax_jpm_apply', [$this, 'handle_application']);
        add_action('wp_ajax_nopriv_jpm_apply', [$this, 'handle_application']); // But redirect if not logged in
        add_action('wp_ajax_jpm_get_status', [$this, 'get_status']);
        add_action('wp_ajax_jpm_get_job_details', [$this, 'get_job_details']);
        add_action('wp_ajax_nopriv_jpm_get_job_details', [$this, 'get_job_details']);
        add_action('wp_ajax_jpm_filter_jobs', [$this, 'filter_jobs_ajax']);
        add_action('wp_ajax_nopriv_jpm_filter_jobs', [$this, 'filter_jobs_ajax']);
        add_action('wp_ajax_jpm_track_application', [$this, 'track_application_ajax']);
        add_action('wp_ajax_nopriv_jpm_track_application', [$this, 'track_application_ajax']);
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
                                        <?php echo esc_html(get_the_date()); ?>
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
                                <?php echo esc_html(get_the_date()); ?>
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

        ob_start();
        ?>
        <div class="jpm-application-tracker">
            <div class="jpm-tracker-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <p class="jpm-tracker-description">
                    <?php _e('Enter your application number to view the status and details of your job application.', 'job-posting-manager'); ?>
                </p>
            </div>

            <form class="jpm-tracker-form" id="jpm-tracker-form">
                <div class="jpm-form-group">
                    <label for="application_number">
                        <?php _e('Application Number', 'job-posting-manager'); ?>
                    </label>
                    <input type="text" id="application_number" name="application_number" class="jpm-input"
                        placeholder="<?php esc_attr_e('Enter your application number (e.g., 25-BDO-79226701)', 'job-posting-manager'); ?>"
                        required>
                </div>
                <button type="submit" class="jpm-btn jpm-btn-primary">
                    <span class="jpm-btn-text"><?php _e('Track Application', 'job-posting-manager'); ?></span>
                    <span class="jpm-btn-spinner" style="display: none;">
                        <span class="spinner is-active"></span>
                    </span>
                </button>
            </form>

            <div class="jpm-tracker-error" id="jpm-tracker-error" style="display: none;"></div>
            <div class="jpm-tracker-results" id="jpm-tracker-results" style="display: none;"></div>
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
}