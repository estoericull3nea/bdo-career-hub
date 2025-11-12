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
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_init', [$this, 'handle_import']);
        add_action('admin_init', [$this, 'handle_print'], 1); // Priority 1 to run early
        add_action('wp_ajax_jpm_get_chart_data', [$this, 'get_chart_data_ajax']);
    }

    public function add_menu()
    {
        add_menu_page(__('Job Manager', 'job-posting-manager'), __('Job Manager', 'job-posting-manager'), 'manage_options', 'jpm-dashboard', [$this, 'dashboard_page'], 'dashicons-businessman');
        add_submenu_page('jpm-dashboard', __('Applications', 'job-posting-manager'), __('Applications', 'job-posting-manager'), 'manage_options', 'jpm-applications', [$this, 'applications_page']);
        add_submenu_page('jpm-dashboard', __('Status Management', 'job-posting-manager'), __('Status Management', 'job-posting-manager'), 'manage_options', 'jpm-status-management', [$this, 'status_management_page']);
    }

    public function dashboard_page()
    {
        // Get filter values
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        // Get analytics data
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';

        // Total jobs by status
        $total_published = wp_count_posts('job_posting')->publish ?? 0;
        $total_draft = wp_count_posts('job_posting')->draft ?? 0;
        $total_pending = wp_count_posts('job_posting')->pending ?? 0;
        $total_jobs = $total_published + $total_draft + $total_pending;

        // Total applications
        $total_applications = $wpdb->get_var("SELECT COUNT(*) FROM $table");

        // Applications by status
        $applications_by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table GROUP BY status",
            ARRAY_A
        );
        $status_counts = [];
        foreach ($applications_by_status as $row) {
            $status_counts[$row['status']] = intval($row['count']);
        }

        // Recent applications (last 7 days)
        $recent_applications = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE application_date >= %s",
                date('Y-m-d H:i:s', strtotime('-7 days'))
            )
        );

        // Applications this month
        $month_applications = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE MONTH(application_date) = %d AND YEAR(application_date) = %d",
                date('n'),
                date('Y')
            )
        );

        // Jobs with most applications (top 5)
        $top_jobs = $wpdb->get_results(
            "SELECT job_id, COUNT(*) as app_count 
             FROM $table 
             GROUP BY job_id 
             ORDER BY app_count DESC 
             LIMIT 5",
            ARRAY_A
        );

        // Get chart period filter
        $chart_period = isset($_GET['chart_period']) ? sanitize_text_field($_GET['chart_period']) : '7days';
        $chart_start_date = isset($_GET['chart_start_date']) ? sanitize_text_field($_GET['chart_start_date']) : '';
        $chart_end_date = isset($_GET['chart_end_date']) ? sanitize_text_field($_GET['chart_end_date']) : '';

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

        ?>
        <div class="wrap">
            <h1><?php _e('Job Postings', 'job-posting-manager'); ?></h1>

            <!-- Analytics Section -->
            <div class="jpm-analytics-section" style="margin: 20px 0;">
                <h2><?php _e('Analytics Overview', 'job-posting-manager'); ?></h2>

                <div class="jpm-analytics-cards"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <!-- Total Jobs Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php _e('Total Jobs', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-businessman" style="font-size: 24px; color: #0073aa;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #0073aa; margin-bottom: 10px;">
                            <?php echo esc_html($total_jobs); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <span><?php echo esc_html($total_published); ?>
                                <?php _e('Published', 'job-posting-manager'); ?></span> |
                            <span><?php echo esc_html($total_draft); ?>         <?php _e('Draft', 'job-posting-manager'); ?></span>
                            <?php if ($total_pending > 0): ?>
                                | <span><?php echo esc_html($total_pending); ?>
                                    <?php _e('Pending', 'job-posting-manager'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Total Applications Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php _e('Total Applications', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-clipboard" style="font-size: 24px; color: #28a745;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px;">
                            <?php echo esc_html($total_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <a href="<?php echo admin_url('admin.php?page=jpm-applications'); ?>"
                                style="color: #0073aa; text-decoration: none;">
                                <?php _e('View All Applications', 'job-posting-manager'); ?> →
                            </a>
                        </div>
                    </div>

                    <!-- Recent Applications Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php _e('Last 7 Days', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-calendar-alt" style="font-size: 24px; color: #ffc107;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #ffc107; margin-bottom: 10px;">
                            <?php echo esc_html($recent_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php _e('New applications', 'job-posting-manager'); ?>
                        </div>
                    </div>

                    <!-- This Month Card -->
                    <div class="jpm-analytics-card"
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <h3 style="margin: 0; font-size: 14px; color: #666; font-weight: 600;">
                                <?php _e('This Month', 'job-posting-manager'); ?>
                            </h3>
                            <span class="dashicons dashicons-chart-line" style="font-size: 24px; color: #dc3545;"></span>
                        </div>
                        <div style="font-size: 32px; font-weight: bold; color: #dc3545; margin-bottom: 10px;">
                            <?php echo esc_html($month_applications); ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            <?php _e('Applications', 'job-posting-manager'); ?>
                        </div>
                    </div>
                </div>

                <!-- Applications by Status -->
                <?php if (!empty($status_counts)): ?>
                    <div
                        style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0;"><?php _e('Applications by Status', 'job-posting-manager'); ?></h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                            <?php
                            $status_options = self::get_status_options();
                            foreach ($status_options as $slug => $name):
                                $count = isset($status_counts[$slug]) ? $status_counts[$slug] : 0;
                                $status_info = self::get_status_by_slug($slug);
                                $bg_color = $status_info ? $status_info['color'] : '#ffc107';
                                $text_color = $status_info ? $status_info['text_color'] : '#000';
                                ?>
                                <div
                                    style="flex: 1; min-width: 150px; padding: 15px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid <?php echo esc_attr($bg_color); ?>;">
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
                        <h3 style="margin: 0;"><?php _e('Applications Trend', 'job-posting-manager'); ?></h3>
                        <div class="jpm-chart-filters" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <span style="font-weight: 600;"><?php _e('Period:', 'job-posting-manager'); ?></span>
                                <select id="jpm-chart-period" name="chart_period" style="padding: 5px 10px;">
                                    <option value="7days" <?php selected($chart_period, '7days'); ?>>
                                        <?php _e('Last 7 Days', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="30days" <?php selected($chart_period, '30days'); ?>>
                                        <?php _e('Last Month', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="90days" <?php selected($chart_period, '90days'); ?>>
                                        <?php _e('Last 3 Months', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="365days" <?php selected($chart_period, '365days'); ?>>
                                        <?php _e('Last Year', 'job-posting-manager'); ?>
                                    </option>
                                    <option value="custom" <?php selected($chart_period, 'custom'); ?>>
                                        <?php _e('Custom Range', 'job-posting-manager'); ?>
                                    </option>
                                </select>
                            </label>
                            <div id="jpm-chart-custom-dates"
                                style="display: <?php echo $chart_period === 'custom' ? 'flex' : 'none'; ?>; gap: 10px; align-items: center;">
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <span><?php _e('From:', 'job-posting-manager'); ?></span>
                                    <input type="date" id="jpm-chart-start-date" name="chart_start_date"
                                        value="<?php echo esc_attr($chart_start_date); ?>" style="padding: 5px;">
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <span><?php _e('To:', 'job-posting-manager'); ?></span>
                                    <input type="date" id="jpm-chart-end-date" name="chart_end_date"
                                        value="<?php echo esc_attr($chart_end_date); ?>" style="padding: 5px;">
                                </label>
                            </div>
                            <button type="button" id="jpm-chart-apply" class="button button-primary" style="margin: 0;">
                                <?php _e('Apply', 'job-posting-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="jpm-chart-loading" style="display: none; text-align: center; padding: 20px;">
                        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                        <p><?php _e('Loading chart data...', 'job-posting-manager'); ?></p>
                    </div>
                    <div class="jpm-chart-container" id="jpm-chart-container" style="margin-top: 20px;">
                        <div
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

            <!-- Top Jobs by Applications -->
            <?php if (!empty($top_jobs)): ?>
                <div
                    style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0;"><?php _e('Top Jobs by Applications', 'job-posting-manager'); ?></h3>
                    <table class="widefat fixed striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th style="width: 5%;"><?php _e('Rank', 'job-posting-manager'); ?></th>
                                <th style="width: 60%;"><?php _e('Job Title', 'job-posting-manager'); ?></th>
                                <th style="width: 20%;"><?php _e('Applications', 'job-posting-manager'); ?></th>
                                <th style="width: 15%;"><?php _e('Actions', 'job-posting-manager'); ?></th>
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
                                    <td>
                                        <strong style="font-size: 18px; color: #0073aa;">#<?php echo esc_html($rank); ?></strong>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $top_job['job_id'] . '&action=edit'); ?>">
                                            <?php echo esc_html(get_the_title($top_job['job_id'])); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong style="font-size: 16px; color: #28a745;">
                                            <?php echo esc_html($top_job['app_count']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=jpm-applications&job_id=' . $top_job['job_id']); ?>"
                                            class="button button-small">
                                            <?php _e('View', 'job-posting-manager'); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php
                                $rank++;
                            endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <hr style="margin: 30px 0;">

        <div class="jpm-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccc;">
            <form method="get" action="">
                <input type="hidden" name="page" value="jpm-dashboard">

                <div style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <?php _e('Search Jobs:', 'job-posting-manager'); ?>
                        </label>
                        <input type="text" name="search" class="regular-text" value="<?php echo esc_attr($search); ?>"
                            placeholder="<?php esc_attr_e('Search by job title...', 'job-posting-manager'); ?>"
                            style="width: 300px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <?php _e('Filter by Status:', 'job-posting-manager'); ?>
                        </label>
                        <select name="status">
                            <option value=""><?php _e('All Statuses', 'job-posting-manager'); ?></option>
                            <option value="publish" <?php selected($status_filter, 'publish'); ?>>
                                <?php _e('Published', 'job-posting-manager'); ?>
                            </option>
                            <option value="draft" <?php selected($status_filter, 'draft'); ?>>
                                <?php _e('Draft', 'job-posting-manager'); ?>
                            </option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>>
                                <?php _e('Pending', 'job-posting-manager'); ?>
                            </option>
                        </select>
                    </div>
                    <div>
                        <input type="submit" class="button button-primary"
                            value="<?php _e('Search/Filter', 'job-posting-manager'); ?>">
                        <?php if (!empty($search) || !empty($status_filter)): ?>
                            <a href="<?php echo admin_url('admin.php?page=jpm-dashboard'); ?>" class="button">
                                <?php _e('Clear', 'job-posting-manager'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <div style="margin: 20px 0;">
            <a href="<?php echo admin_url('post-new.php?post_type=job_posting'); ?>" class="button button-primary">
                <?php _e('Add New Job', 'job-posting-manager'); ?>
            </a>
            <a href="<?php echo admin_url('edit.php?post_type=job_posting'); ?>" class="button">
                <?php _e('View All in WordPress', 'job-posting-manager'); ?>
            </a>
        </div>

        <?php if (empty($jobs)): ?>
            <p><?php _e('No jobs found.', 'job-posting-manager'); ?></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;"><?php _e('ID', 'job-posting-manager'); ?></th>
                        <th style="width: 25%;"><?php _e('Job Title', 'job-posting-manager'); ?></th>
                        <th style="width: 15%;"><?php _e('Company', 'job-posting-manager'); ?></th>
                        <th style="width: 12%;"><?php _e('Location', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php _e('Status', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php _e('Applications', 'job-posting-manager'); ?></th>
                        <th style="width: 10%;"><?php _e('Posted Date', 'job-posting-manager'); ?></th>
                        <th style="width: 13%;"><?php _e('Actions', 'job-posting-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job):
                        $company_name = get_post_meta($job->ID, 'company_name', true);
                        $location = get_post_meta($job->ID, 'location', true);

                        // Get application count
                        $application_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table WHERE job_id = %d",
                            $job->ID
                        ));

                        $edit_url = admin_url('post.php?post=' . $job->ID . '&action=edit');
                        $view_url = get_permalink($job->ID);
                        $applications_url = admin_url('admin.php?page=jpm-applications&job_id=' . $job->ID);
                        $post_status = get_post_status($job->ID);
                        ?>
                        <tr>
                            <td><?php echo esc_html($job->ID); ?></td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url($edit_url); ?>">
                                        <?php echo esc_html(get_the_title($job->ID)); ?>
                                    </a>
                                </strong>
                            </td>
                            <td><?php echo !empty($company_name) ? esc_html($company_name) : '—'; ?></td>
                            <td><?php echo !empty($location) ? esc_html($location) : '—'; ?></td>
                            <td>
                                <?php if ($post_status === 'publish'): ?>
                                    <span class="jpm-status-badge jpm-status-active"><?php _e('Published', 'job-posting-manager'); ?></span>
                                <?php elseif ($post_status === 'draft'): ?>
                                    <span class="jpm-status-badge jpm-status-draft"><?php _e('Draft', 'job-posting-manager'); ?></span>
                                <?php else: ?>
                                    <span class="jpm-status-badge"
                                        style="background-color: #ffc107; color: #000;"><?php echo esc_html(ucfirst($post_status)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($applications_url); ?>" style="font-weight: bold; color: #0073aa;">
                                    <?php echo esc_html($application_count); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(get_the_date('', $job->ID)); ?></td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                    <?php _e('Edit', 'job-posting-manager'); ?>
                                </a>
                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small" target="_blank">
                                    <?php _e('View', 'job-posting-manager'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
        <?php
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

                <?php if (current_user_can('edit_posts')): ?>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Export Section -->
                            <?php if (!empty($applications)): ?>
                                <div>
                                    <strong><?php _e('Export Applications:', 'job-posting-manager'); ?></strong>
                                    <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                                        <a href="<?php echo admin_url('admin.php?page=jpm-applications&export=csv&' . http_build_query($filters)); ?>"
                                            class="button">
                                            <?php _e('Export to CSV', 'job-posting-manager'); ?>
                                        </a>
                                        <a href="<?php echo admin_url('admin.php?page=jpm-applications&export=json&' . http_build_query($filters)); ?>"
                                            class="button">
                                            <?php _e('Export to JSON', 'job-posting-manager'); ?>
                                        </a>
                                    </div>
                                    <p class="description" style="margin-top: 5px;">
                                        <?php _e('Export will include all applications matching your current filters and search.', 'job-posting-manager'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- Import Section -->
                            <div>
                                <strong><?php _e('Import Applications:', 'job-posting-manager'); ?></strong>
                                <form method="post" action="" enctype="multipart/form-data" style="margin-top: 10px;">
                                    <?php wp_nonce_field('jpm_import_applications', 'jpm_import_nonce'); ?>
                                    <input type="hidden" name="jpm_import_action" value="import">
                                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                        <input type="file" name="jpm_import_file" accept=".csv,.json" required
                                            style="padding: 5px;">
                                        <select name="jpm_import_format" required style="padding: 5px;">
                                            <option value=""><?php _e('Select Format', 'job-posting-manager'); ?></option>
                                            <option value="csv"><?php _e('CSV', 'job-posting-manager'); ?></option>
                                            <option value="json"><?php _e('JSON', 'job-posting-manager'); ?></option>
                                        </select>
                                        <input type="submit" name="jpm_import_submit" class="button button-primary"
                                            value="<?php _e('Import', 'job-posting-manager'); ?>">
                                    </div>
                                    <p class="description" style="margin-top: 5px;">
                                        <?php _e('Import applications from a previously exported CSV or JSON file. File must match the export format.', 'job-posting-manager'); ?>
                                    </p>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
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
                                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
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
                                        <a href="<?php echo admin_url('admin.php?page=jpm-applications&action=print&application_id=' . $application->id); ?>"
                                            target="_blank" class="button button-small" style="text-decoration: none;">
                                            <?php _e('Print', 'job-posting-manager'); ?>
                                        </a>
                                    </div>
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
        $table = $wpdb->prefix . 'job_applications';
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

        // Generate date points based on interval
        $current = strtotime($start);
        $end_timestamp = strtotime($end);

        while ($current <= $end_timestamp) {
            $date_str = date('Y-m-d', $current);

            // Query count for this date/period
            if ($interval === 'day') {
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE DATE(application_date) = %s",
                        $date_str
                    )
                );
            } elseif ($interval === 'week') {
                $week_start = date('Y-m-d', strtotime('monday this week', $current));
                $week_end = date('Y-m-d', strtotime('sunday this week', $current));
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE DATE(application_date) >= %s AND DATE(application_date) <= %s",
                        $week_start,
                        $week_end
                    )
                );
                // Move to next week
                $current = strtotime('+1 week', $current);
                $date_str = $week_start . ' - ' . $week_end;
            } else { // month
                $month_start = date('Y-m-01', $current);
                $month_end = date('Y-m-t', $current);
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE DATE(application_date) >= %s AND DATE(application_date) <= %s",
                        $month_start,
                        $month_end
                    )
                );
                // Move to next month
                $current = strtotime('+1 month', $current);
                $date_str = $month_start;
            }

            $data[] = [
                'date' => $interval === 'week' ? $date_str : date($format, strtotime($date_str)),
                'count' => intval($count)
            ];

            // Move to next interval (already handled for week/month above)
            if ($interval === 'day') {
                $current = strtotime('+1 day', $current);
            }
        }

        return $data;
    }

    /**
     * AJAX handler for getting chart data
     */
    public function get_chart_data_ajax()
    {
        check_ajax_referer('jpm_chart_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'job-posting-manager')]);
            return;
        }

        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : '7days';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

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
            echo '<p style="text-align: center; padding: 40px; color: #666;">' . __('No data available for the selected period.', 'job-posting-manager') . '</p>';
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
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

    /**
     * Handle export requests
     */
    public function handle_export()
    {
        // Check if export is requested
        if (!isset($_GET['page']) || $_GET['page'] !== 'jpm-applications' || !isset($_GET['export'])) {
            return;
        }

        // Check user capabilities (admin or editor)
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export applications.', 'job-posting-manager'));
        }

        $export_format = sanitize_text_field($_GET['export'] ?? '');

        if (!in_array($export_format, ['csv', 'json'])) {
            wp_die(__('Invalid export format.', 'job-posting-manager'));
        }

        // Get filters
        $filters = [
            'status' => $_GET['status'] ?? '',
            'job_id' => $_GET['job_id'] ?? '',
            'search' => $_GET['search'] ?? '',
        ];

        // Get applications
        $applications = JPM_DB::get_applications($filters);

        if ($export_format === 'csv') {
            $this->export_to_csv($applications);
        } elseif ($export_format === 'json') {
            $this->export_to_json($applications);
        }

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

        fclose($output);
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
        if (!isset($_POST['jpm_import_action']) || $_POST['jpm_import_action'] !== 'import') {
            return;
        }

        // Check user capabilities (admin or editor)
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to import applications.', 'job-posting-manager'));
        }

        // Verify nonce
        if (!isset($_POST['jpm_import_nonce']) || !wp_verify_nonce($_POST['jpm_import_nonce'], 'jpm_import_applications')) {
            wp_die(__('Security check failed. Please try again.', 'job-posting-manager'));
        }

        // Check if file was uploaded
        if (!isset($_FILES['jpm_import_file'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . __('No file was uploaded. Please select a file to import.', 'job-posting-manager') . '</p></div>';
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
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . esc_html($error_message) . '</p></div>';
            });
            return;
        }

        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB in bytes
        if ($file['size'] > $max_size) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . __('The uploaded file is too large. Maximum file size is 10MB.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        // Check if file is empty
        if ($file['size'] === 0) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . __('The uploaded file is empty.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        $format = sanitize_text_field($_POST['jpm_import_format'] ?? '');

        if (empty($format)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . __('Please select an import format (CSV or JSON).', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        if (!in_array($format, ['csv', 'json'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' . __('Invalid import format selected. Please choose either CSV or JSON.', 'job-posting-manager') . '</p></div>';
            });
            return;
        }

        // Check file extension matches format
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (($format === 'csv' && $file_ext !== 'csv') || ($format === 'json' && $file_ext !== 'json')) {
            add_action('admin_notices', function () use ($format, $file_ext) {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' .
                    sprintf(__('File extension (.%s) does not match selected format (%s). Please select the correct format or upload a file with the matching extension.', 'job-posting-manager'), $file_ext, strtoupper($format)) .
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
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' .
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
                    echo '<div class="notice notice-warning"><p><strong>' . __('Import Warning:', 'job-posting-manager') . '</strong> ' .
                        __('No applications were found in the file to import.', 'job-posting-manager') .
                        '</p></div>';
                });
                return;
            }

            if ($success_count > 0) {
                add_action('admin_notices', function () use ($success_count, $total_processed) {
                    $message = sprintf(__('Successfully imported %d out of %d application(s).', 'job-posting-manager'), $success_count, $total_processed);
                    if ($success_count === $total_processed) {
                        $message = sprintf(__('Successfully imported all %d application(s).', 'job-posting-manager'), $success_count);
                    }
                    echo '<div class="notice notice-success is-dismissible"><p><strong>' . __('Import Success:', 'job-posting-manager') . '</strong> ' . $message . '</p></div>';
                });
            }

            if ($error_count > 0) {
                $error_message = '<strong>' . __('Import Errors:', 'job-posting-manager') . '</strong> ' .
                    sprintf(__('Failed to import %d out of %d application(s).', 'job-posting-manager'), $error_count, $total_processed);

                if (!empty($errors)) {
                    $error_message .= '<br><br><strong>' . __('Error Details:', 'job-posting-manager') . '</strong>';
                    $error_message .= '<ul style="margin-left: 20px; margin-top: 10px;">';
                    foreach (array_slice($errors, 0, 20) as $error) { // Show first 20 errors
                        $error_message .= '<li>' . esc_html($error) . '</li>';
                    }
                    if (count($errors) > 20) {
                        $error_message .= '<li><em>' . sprintf(__('... and %d more errors. Please check your file and try again.', 'job-posting-manager'), count($errors) - 20) . '</em></li>';
                    }
                    $error_message .= '</ul>';
                }

                add_action('admin_notices', function () use ($error_message) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . $error_message . '</p></div>';
                });
            }
        } else {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . __('Import Error:', 'job-posting-manager') . '</strong> ' .
                    __('Failed to process the import file. Please check the file format and try again.', 'job-posting-manager') .
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
            fclose($handle);
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
            fclose($handle);
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

        fclose($handle);

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
                'error' => sprintf(__('Row %d: Missing or invalid Job ID. Found value: "%s". Job ID must be a positive number and the job must exist.', 'job-posting-manager'), $row_num, esc_html($job_id_value))
            ];
        }

        $job = get_post($job_id);
        if (!$job) {
            return [
                'success' => false,
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
                        error_log('JPM Import: Failed to create user for email ' . $email . ' - ' . $user_id->get_error_message());
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
        $table = $wpdb->prefix . 'job_applications';
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
            $error_msg = sprintf(__('Row %d: Failed to insert application into database.', 'job-posting-manager'), $row_num);
            if (!empty($db_error)) {
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
                'error' => sprintf(__('Application %d: Missing or invalid Job ID. Found value: "%s". Job ID must be a positive number and the job must exist.', 'job-posting-manager'), $index, esc_html($job_id_value))
            ];
        }

        $job = get_post($job_id);
        if (!$job) {
            return [
                'success' => false,
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
                        error_log('JPM Import: Failed to create user for email ' . $email . ' - ' . $user_id->get_error_message());
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
        $table = $wpdb->prefix . 'job_applications';
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
            $error_msg = sprintf(__('Application %d: Failed to insert application into database.', 'job-posting-manager'), $index);
            if (!empty($db_error)) {
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
        if (!isset($_GET['page']) || $_GET['page'] !== 'jpm-applications' || !isset($_GET['action']) || $_GET['action'] !== 'print' || !isset($_GET['application_id'])) {
            return;
        }

        // Prevent WordPress admin from loading - must be defined before admin template loads
        if (!defined('IFRAME_REQUEST')) {
            define('IFRAME_REQUEST', true);
        }

        // Check user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to view this page.', 'job-posting-manager'));
        }

        $application_id = absint($_GET['application_id'] ?? 0);

        if ($application_id <= 0) {
            wp_die(__('Invalid application ID.', 'job-posting-manager'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $application = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $application_id));

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

        // Print page - standalone HTML without WordPress admin
        // Send headers to prevent caching
        nocache_headers();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>

        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php printf(__('Application #%d - Print', 'job-posting-manager'), $application_id); ?></title>
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
                    font-size: 11pt !important;
                    line-height: 1.7 !important;
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
                        margin-bottom: 25px !important;
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
                        margin: 0 1cm 1cm 1cm;
                    }

                    .section {
                        page-break-inside: avoid;
                        margin-bottom: 30px;
                    }

                    .print-header {
                        page-break-after: avoid;
                        margin-top: 0 !important;
                        margin-bottom: 30px !important;
                        padding-top: 0 !important;
                        padding-bottom: 20px;
                    }

                    .print-header h1 {
                        margin-top: 0 !important;
                        padding-top: 0 !important;
                    }

                    .print-container {
                        padding: 0 !important;
                        padding-top: 0 !important;
                        margin-top: 0 !important;
                        max-width: 100%;
                    }

                    .info-row {
                        page-break-inside: avoid;
                    }

                    .form-field {
                        page-break-inside: avoid;
                        margin-bottom: 15px;
                    }

                    .footer {
                        margin-top: 50px;
                        padding-top: 20px;
                    }
                }

                .print-container {
                    max-width: 210mm;
                    margin: 0 auto !important;
                    padding: 0 40px 30px 40px !important;
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
                    border-bottom: 3px solid #2c3e50;
                    padding-bottom: 25px;
                    padding-top: 0 !important;
                    margin-top: 0 !important;
                    margin-bottom: 45px;
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
                    font-size: 32pt;
                    margin-top: 0 !important;
                    margin-bottom: 10px;
                    padding-top: 0 !important;
                    color: #2c3e50;
                    font-weight: 700;
                    letter-spacing: -0.5px;
                    line-height: 1.2;
                }

                .print-header .subtitle {
                    font-size: 16pt;
                    color: #7f8c8d;
                    font-weight: 400;
                    margin-bottom: 8px;
                }

                .print-header .company-info {
                    margin-top: 12px;
                    font-size: 12pt;
                    color: #95a5a6;
                    font-weight: 500;
                }

                .section {
                    margin-bottom: 40px;
                    page-break-inside: avoid;
                }

                .section-title {
                    font-size: 15pt;
                    font-weight: 700;
                    color: #2c3e50;
                    border-bottom: 2px solid #3498db;
                    padding-bottom: 10px;
                    margin-bottom: 25px;
                    text-transform: uppercase;
                    letter-spacing: 0.8px;
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
                    width: 38%;
                    padding: 14px 18px;
                    font-weight: 600;
                    color: #34495e;
                    background: #f5f7fa;
                    vertical-align: middle;
                    border-right: 1px solid #e8e8e8;
                    font-size: 10.5pt;
                    line-height: 1.5;
                }

                .info-value {
                    display: table-cell;
                    padding: 14px 18px;
                    color: #2c3e50;
                    vertical-align: middle;
                    font-size: 11pt;
                    line-height: 1.6;
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
                    margin-top: 35px;
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
                    margin-top: 70px;
                    padding-top: 25px;
                    border-top: 2px solid #e0e0e0;
                    text-align: center;
                    font-size: 9.5pt;
                    color: #95a5a6;
                    line-height: 1.6;
                }

                .divider {
                    height: 0;
                    border: none;
                    border-top: 1px solid #e0e0e0;
                    margin: 35px 0;
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
                <button class="print-btn" onclick="window.print()"><?php _e('Print', 'job-posting-manager'); ?></button>
                <button onclick="window.close()"><?php _e('Close', 'job-posting-manager'); ?></button>
            </div>

            <div class="print-container" style="margin-top: 0 !important; padding-top: 0 !important;">
                <div class="print-header" style="margin-top: 0 !important; padding-top: 0 !important; margin-bottom: 30px;">
                    <h1><?php _e('Job Application', 'job-posting-manager'); ?></h1>
                    <div class="subtitle"><?php printf(__('Application #%d', 'job-posting-manager'), $application_id); ?></div>
                    <?php if (get_bloginfo('name')): ?>
                        <div class="company-info"><?php echo esc_html(get_bloginfo('name')); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Application Information -->
                <div class="section">
                    <div class="section-title"><?php _e('Application Information', 'job-posting-manager'); ?></div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label"><?php _e('Application ID', 'job-posting-manager'); ?></div>
                            <div class="info-value"><strong
                                    style="color: #2c3e50; font-size: 11.5pt;">#<?php echo esc_html($application_id); ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($application_number)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('Application Number', 'job-posting-manager'); ?></div>
                                <div class="info-value" style="font-weight: 500;"><?php echo esc_html($application_number); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <div class="info-label"><?php _e('Application Date', 'job-posting-manager'); ?></div>
                            <div class="info-value">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($application->application_date))); ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="info-label"><?php _e('Status', 'job-posting-manager'); ?></div>
                            <div class="info-value">
                                <span class="status-badge"
                                    style="background-color: <?php echo esc_attr($status_color); ?>; color: <?php echo esc_attr($status_text_color); ?>;">
                                    <?php echo esc_html($status_name); ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($date_of_registration)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('Date of Registration', 'job-posting-manager'); ?></div>
                                <div class="info-value"><?php echo esc_html($date_of_registration); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Job Information -->
                <div class="section">
                    <div class="section-title"><?php _e('Job Information', 'job-posting-manager'); ?></div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label"><?php _e('Job Title', 'job-posting-manager'); ?></div>
                            <div class="info-value"><strong style="color: #2c3e50; font-size: 11.5pt;">
                                    <?php echo esc_html($job ? $job->post_title : __('Job Deleted', 'job-posting-manager')); ?>
                                </strong></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">
                                <?php _e('Job ID', 'job-posting-manager'); ?>
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
                        <?php _e('Applicant Information', 'job-posting-manager'); ?>
                    </div>
                    <div class="info-grid">
                        <?php if (!empty($full_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('Full Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value"><strong style="color: #2c3e50; font-size: 11.5pt;">
                                        <?php echo esc_html($full_name); ?>
                                    </strong></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($first_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('First Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($first_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($middle_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('Middle Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($middle_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($last_name)): ?>
                            <div class="info-row">
                                <div class="info-label"><?php _e('Last Name', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($last_name); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($email)): ?>
                            <div class="info-row">
                                <div class="info-label">
                                    <?php _e('Email', 'job-posting-manager'); ?>
                                </div>
                                <div class="info-value">
                                    <?php echo esc_html($email); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <div class="info-label">
                                <?php _e('User Account', 'job-posting-manager'); ?>
                            </div>
                            <div class="info-value">
                                <?php if ($user): ?>
                                    <?php echo esc_html($user->display_name); ?> <span style="color: #95a5a6;">(ID:
                                        <?php echo esc_html($user->ID); ?>)</span>
                                <?php else: ?>
                                    <em style="color: #95a5a6;"><?php _e('Guest Application', 'job-posting-manager'); ?></em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Application Form Data -->
                <?php if (!empty($form_data)): ?>
                    <div class="section form-data-section">
                        <div class="section-title">
                            <?php _e('Application Form Data', 'job-posting-manager'); ?>
                        </div>
                        <?php
                        // Exclude internal fields from display
                        $excluded_fields = ['application_number', 'date_of_registration', 'applicant_number'];

                        foreach ($form_data as $field_name => $field_value):
                            if (in_array($field_name, $excluded_fields)) {
                                continue;
                            }

                            // Skip if already displayed in applicant information
                            $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                            $skip_fields = ['firstname', 'fname', 'givenname', 'given', 'middlename', 'mname', 'middle', 'lastname', 'lname', 'surname', 'familyname', 'family', 'email'];
                            if (in_array($field_name_lower, $skip_fields)) {
                                continue;
                            }

                            if (empty($field_value)) {
                                continue;
                            }

                            $field_label = ucwords(str_replace(['_', '-'], ' ', $field_name));
                            ?>
                            <div class="form-field">
                                <span class="form-field-label"><?php echo esc_html($field_label); ?></span>
                                <div class="form-field-value">
                                    <?php
                                    if (is_array($field_value)) {
                                        echo esc_html(implode(', ', $field_value));
                                    } else {
                                        echo esc_html($field_value);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <p><?php printf(__('Printed on %s from %s', 'job-posting-manager'), date_i18n(get_option('date_format') . ' ' . get_option('time_format')), get_bloginfo('name')); ?>
                    </p>
                </div>
            </div>

            <script>
                // Auto-print w         hen         page loads (optional)
                // window.onload = function() { window.print(); };
            </script>
        </body>

        </html>
        <?php
    }
}