<?php
class JPM_Settings
{
    public function __construct()
    {
        // Use higher priority so Settings is added last (after Form Templates, Email Notifications, etc.)
        add_action('admin_menu', [$this, 'add_settings_page'], 99);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_settings_page()
    {
        add_submenu_page('jpm-dashboard', __('Settings', 'job-posting-manager'), __('Settings', 'job-posting-manager'), 'manage_options', 'jpm-settings', [$this, 'settings_page']);
    }

    public function register_settings()
    {
        register_setting('jpm_settings', 'jpm_settings');
        // Settings registration removed - no longer using WordPress settings API for email templates
    }

    public function settings_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Job Posting Manager Settings', 'job-posting-manager'); ?></h1>

            <h2><?php _e('Available Shortcodes', 'job-posting-manager'); ?></h2>
            <p class="description"><?php _e('Use these shortcodes to display job listings and application features on your pages and posts.', 'job-posting-manager'); ?></p>

            <div class="jpm-shortcodes-section" style="margin-top: 20px;">
                <?php $this->display_shortcode_info('latest_jobs', [
                    'title' => __('Latest Jobs', 'job-posting-manager'),
                    'description' => __('Display the latest job postings on any page or post.', 'job-posting-manager'),
                    'usage' => '[latest_jobs count="3" view_all_url=""]',
                    'parameters' => [
                        'count' => __('Number of jobs to display (default: 3)', 'job-posting-manager'),
                        'view_all_url' => __('URL for the "View All Jobs" link (optional)', 'job-posting-manager'),
                    ],
                    'example' => '[latest_jobs count="5" view_all_url="/jobs/"]',
                ]); ?>

                <?php $this->display_shortcode_info('all_jobs', [
                    'title' => __('All Jobs', 'job-posting-manager'),
                    'description' => __('Display all job postings with search, filters, and pagination.', 'job-posting-manager'),
                    'usage' => '[all_jobs per_page="12"]',
                    'parameters' => [
                        'per_page' => __('Number of jobs per page (default: 12)', 'job-posting-manager'),
                    ],
                    'example' => '[all_jobs per_page="20"]',
                    'features' => [
                        __('Search by job title', 'job-posting-manager'),
                        __('Filter by location', 'job-posting-manager'),
                        __('Filter by company', 'job-posting-manager'),
                        __('Pagination', 'job-posting-manager'),
                        __('Quick View modal', 'job-posting-manager'),
                        __('Results count display', 'job-posting-manager'),
                    ],
                ]); ?>

                <?php $this->display_shortcode_info('application_tracker', [
                    'title' => __('Application Tracker', 'job-posting-manager'),
                    'description' => __('Allow applicants to track their application status using their application number.', 'job-posting-manager'),
                    'usage' => '[application_tracker title="Track Your Application"]',
                    'parameters' => [
                        'title' => __('Title for the tracker section (default: "Track Your Application")', 'job-posting-manager'),
                    ],
                    'example' => '[application_tracker title="Check Your Application Status"]',
                    'features' => [
                        __('Application number lookup', 'job-posting-manager'),
                        __('Status display with color-coded badges', 'job-posting-manager'),
                        __('Application details display', 'job-posting-manager'),
                        __('Status descriptions', 'job-posting-manager'),
                    ],
                ]); ?>

                <?php $this->display_shortcode_info('user_applications', [
                    'title' => __('User Applications', 'job-posting-manager'),
                    'description' => __('Display logged-in user\'s applications. Requires user to be logged in.', 'job-posting-manager'),
                    'usage' => '[user_applications]',
                    'parameters' => [],
                    'example' => '[user_applications]',
                    'features' => [
                        __('Lists all applications for the current user', 'job-posting-manager'),
                        __('Real-time status updates via AJAX polling', 'job-posting-manager'),
                        __('Application history', 'job-posting-manager'),
                    ],
                    'note' => __('This shortcode requires users to be logged in. Non-logged-in users will not see any content.', 'job-posting-manager'),
                ]); ?>

                <?php $this->display_shortcode_info('job_listings', [
                    'title' => __('Job Listings', 'job-posting-manager'),
                    'description' => __('Display job listings with filters. Requires user to be logged in.', 'job-posting-manager'),
                    'usage' => '[job_listings]',
                    'parameters' => [],
                    'example' => '[job_listings]',
                    'note' => __('This shortcode requires users to be logged in. Non-logged-in users will see a login message.', 'job-posting-manager'),
                ]); ?>
            </div>
        </div>
        <style>
            .jpm-shortcode-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .jpm-shortcode-card h3 {
                margin-top: 0;
                margin-bottom: 10px;
                font-size: 18px;
                color: #23282d;
            }
            .jpm-shortcode-card .shortcode-description {
                color: #646970;
                margin-bottom: 15px;
            }
            .jpm-shortcode-card .shortcode-usage {
                background: #f6f7f7;
                border-left: 4px solid #2271b1;
                padding: 12px;
                margin: 15px 0;
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                color: #23282d;
            }
            .jpm-shortcode-card .shortcode-parameters {
                margin: 15px 0;
            }
            .jpm-shortcode-card .shortcode-parameters strong {
                display: inline-block;
                min-width: 120px;
                color: #23282d;
            }
            .jpm-shortcode-card .shortcode-parameters ul {
                margin: 10px 0 0 20px;
                list-style: disc;
            }
            .jpm-shortcode-card .shortcode-example {
                background: #f0f6fc;
                border: 1px solid #c6d2e3;
                padding: 10px;
                margin: 15px 0;
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                color: #0a4b78;
                border-radius: 3px;
            }
            .jpm-shortcode-card .shortcode-note {
                background: #fff3cd;
                border-left: 4px solid #ffb900;
                padding: 10px 12px;
                margin: 15px 0;
                color: #856404;
            }
        </style>
        <?php
    }

    /**
     * Display shortcode information card
     */
    private function display_shortcode_info($shortcode, $info)
    {
        ?>
        <div class="jpm-shortcode-card">
            <h3><?php echo esc_html($info['title']); ?></h3>
            <p class="shortcode-description"><?php echo esc_html($info['description']); ?></p>
            
            <div class="shortcode-usage">
                <strong><?php _e('Usage:', 'job-posting-manager'); ?></strong><br>
                <code><?php echo esc_html($info['usage']); ?></code>
            </div>

            <?php if (!empty($info['parameters'])): ?>
                <div class="shortcode-parameters">
                    <strong><?php _e('Parameters:', 'job-posting-manager'); ?></strong>
                    <ul>
                        <?php foreach ($info['parameters'] as $param => $desc): ?>
                            <li>
                                <strong><?php echo esc_html($param); ?>:</strong> <?php echo esc_html($desc); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($info['example'])): ?>
                <div class="shortcode-example">
                    <strong><?php _e('Example:', 'job-posting-manager'); ?></strong><br>
                    <code><?php echo esc_html($info['example']); ?></code>
                </div>
            <?php endif; ?>

            <?php if (!empty($info['features'])): ?>
                <div class="shortcode-parameters">
                    <strong><?php _e('Features:', 'job-posting-manager'); ?></strong>
                    <ul>
                        <?php foreach ($info['features'] as $feature): ?>
                            <li><?php echo esc_html($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($info['note'])): ?>
                <div class="shortcode-note">
                    <strong><?php _e('Note:', 'job-posting-manager'); ?></strong> <?php echo esc_html($info['note']); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }


}