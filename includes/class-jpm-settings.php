<?php
class JPM_Settings
{
    public function __construct()
    {
        // Use higher priority so Settings is added last (after Form Templates, Email Notifications, etc.)
        add_action('admin_menu', [$this, 'add_settings_page'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_jpm_save_email_settings', [$this, 'ajax_save_email_settings']);
        add_action('wp_ajax_jpm_send_test_email', [$this, 'ajax_send_test_email']);
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
        $shortcodes = [
            'latest_jobs' => [
                'title' => __('Latest Jobs', 'job-posting-manager'),
                'description' => __('Display the latest job postings on any page or post.', 'job-posting-manager'),
                'usage' => '[latest_jobs count="3" view_all_url=""]',
                'parameters' => [
                    'count' => __('Number of jobs to display (default: 3)', 'job-posting-manager'),
                    'view_all_url' => __('URL for the "View All Jobs" link (optional)', 'job-posting-manager'),
                ],
                'example' => '[latest_jobs count="5" view_all_url="/jobs/"]',
            ],
            'all_jobs' => [
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
            ],
            'application_tracker' => [
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
            ],
            'job_listings' => [
                'title' => __('Job Listings', 'job-posting-manager'),
                'description' => __('Display job listings with filters. Requires user to be logged in.', 'job-posting-manager'),
                'usage' => '[job_listings]',
                'parameters' => [],
                'example' => '[job_listings]',
                'note' => __('This shortcode requires users to be logged in. Non-logged-in users will see a login message.', 'job-posting-manager'),
            ],
            'jpm_register' => [
                'title' => __('Registration Form', 'job-posting-manager'),
                'description' => __('Display a user registration form for creating new accounts.', 'job-posting-manager'),
                'usage' => '[jpm_register title="Create Account" redirect_url=""]',
                'parameters' => [
                    'title' => __('Title for the registration form (default: "Create Account")', 'job-posting-manager'),
                    'redirect_url' => __('URL to redirect after successful registration (optional)', 'job-posting-manager'),
                ],
                'example' => '[jpm_register title="Sign Up" redirect_url="/jobs/"]',
                'features' => [
                    __('User registration with first name, last name, email, and password', 'job-posting-manager'),
                    __('Password confirmation field', 'job-posting-manager'),
                    __('Automatic account creation email', 'job-posting-manager'),
                    __('Auto-login after registration', 'job-posting-manager'),
                    __('Admin notification for new registrations', 'job-posting-manager'),
                    __('Redirect to jobs page or custom URL after registration', 'job-posting-manager'),
                ],
                'note' => __('Logged-in users will see a message to logout first. The form automatically validates email uniqueness and password strength.', 'job-posting-manager'),
            ],
        ];
        // Get current settings
        $smtp_settings = get_option('jpm_smtp_settings', []);
        $email_settings = get_option('jpm_email_settings', [
            'recipient_email' => get_option('admin_email'),
            'cc_emails' => '',
            'bcc_emails' => '',
        ]);

        // Check for save success message
        $save_message = '';
        if (isset($_GET['settings_saved']) && $_GET['settings_saved'] == '1') {
            $save_message = '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'job-posting-manager') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Job Posting Manager Settings', 'job-posting-manager'); ?></h1>


            <div class="jpm-settings-tabs" style="margin-top: 20px;">
                <nav class="jpm-tab-nav">
                    <a href="#" class="jpm-tab-link active" data-tab="shortcodes">
                        <?php _e('Shortcodes', 'job-posting-manager'); ?>
                    </a>
                    <a href="#" class="jpm-tab-link" data-tab="email">
                        <?php _e('Email Settings', 'job-posting-manager'); ?>
                    </a>
                </nav>

                <div class="jpm-tab-content-wrapper">
                    <!-- Shortcodes Tab -->
                    <div class="jpm-tab-content active" id="tab-shortcodes">
                        <h2><?php _e('Available Shortcodes', 'job-posting-manager'); ?></h2>
                        <p class="description">
                            <?php _e('Use these shortcodes to display job listings and application features on your pages and posts.', 'job-posting-manager'); ?>
                        </p>

                        <div class="jpm-shortcodes-tabs" style="margin-top: 20px;">
                            <nav class="jpm-shortcode-tab-nav">
                                <?php
                                $first = true;
                                foreach ($shortcodes as $key => $shortcode):
                                    ?>
                                    <a href="#" class="jpm-shortcode-tab-link <?php echo $first ? 'active' : ''; ?>"
                                        data-shortcode-tab="<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($shortcode['title']); ?>
                                    </a>
                                    <?php
                                    $first = false;
                                endforeach;
                                ?>
                            </nav>

                            <div class="jpm-shortcode-tab-content-wrapper">
                                <?php
                                $first = true;
                                foreach ($shortcodes as $key => $shortcode):
                                    ?>
                                    <div class="jpm-shortcode-tab-content <?php echo $first ? 'active' : ''; ?>"
                                        id="shortcode-tab-<?php echo esc_attr($key); ?>">
                                        <?php $this->display_shortcode_info($key, $shortcode); ?>
                                    </div>
                                    <?php
                                    $first = false;
                                endforeach;
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Email Settings Tab -->
                    <div class="jpm-tab-content" id="tab-email">
                        <h2><?php _e('Email Settings', 'job-posting-manager'); ?></h2>
                        <p class="description">
                            <?php _e('Configure SMTP settings and email recipients for application notifications.', 'job-posting-manager'); ?>
                        </p>

                        <div id="jpm-save-settings-message" style="margin-top: 15px; display: none;"></div>

                        <form id="jpm-save-email-settings-form" style="margin-top: 20px;">
                            <?php wp_nonce_field('jpm_save_email_settings', 'jpm_email_settings_nonce'); ?>

                            <h3><?php _e('SMTP Configuration', 'job-posting-manager'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_host"><?php _e('SMTP Host', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="smtp_host" name="smtp_host"
                                            value="<?php echo esc_attr($smtp_settings['host'] ?? 'smtp.gmail.com'); ?>"
                                            class="regular-text" />
                                        <p class="description">
                                            <?php _e('Your SMTP server hostname (e.g., smtp.gmail.com)', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_port"><?php _e('SMTP Port', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="smtp_port" name="smtp_port"
                                            value="<?php echo esc_attr($smtp_settings['port'] ?? '587'); ?>"
                                            class="small-text" />
                                        <p class="description">
                                            <?php _e('SMTP port (usually 587 for TLS, 465 for SSL)', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_encryption"><?php _e('Encryption', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="smtp_encryption" name="smtp_encryption">
                                            <option value="tls" <?php selected($smtp_settings['encryption'] ?? 'tls', 'tls'); ?>><?php _e('TLS', 'job-posting-manager'); ?></option>
                                            <option value="ssl" <?php selected($smtp_settings['encryption'] ?? 'tls', 'ssl'); ?>><?php _e('SSL', 'job-posting-manager'); ?></option>
                                            <option value="none" <?php selected($smtp_settings['encryption'] ?? 'tls', 'none'); ?>><?php _e('None', 'job-posting-manager'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_auth"><?php _e('Authentication', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="smtp_auth" name="smtp_auth" value="1" <?php checked(!empty($smtp_settings['auth'])); ?> />
                                            <?php _e('Enable SMTP authentication', 'job-posting-manager'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_username"><?php _e('SMTP Username', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="smtp_username" name="smtp_username"
                                            value="<?php echo esc_attr($smtp_settings['username'] ?? ''); ?>"
                                            class="regular-text" />
                                        <p class="description">
                                            <?php _e('Your SMTP username (usually your email address)', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_password"><?php _e('SMTP Password', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="password" id="smtp_password" name="smtp_password"
                                            value="<?php echo esc_attr($smtp_settings['password'] ?? ''); ?>"
                                            class="regular-text" />
                                        <p class="description">
                                            <?php _e('Your SMTP password or app password', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_from_email"><?php _e('From Email', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="email" id="smtp_from_email" name="smtp_from_email"
                                            value="<?php echo esc_attr($smtp_settings['from_email'] ?? get_option('admin_email')); ?>"
                                            class="regular-text" />
                                        <p class="description">
                                            <?php _e('Email address to send emails from', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="smtp_from_name"><?php _e('From Name', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="smtp_from_name" name="smtp_from_name"
                                            value="<?php echo esc_attr($smtp_settings['from_name'] ?? get_bloginfo('name')); ?>"
                                            class="regular-text" />
                                        <p class="description"><?php _e('Name to display as sender', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <h3 style="margin-top: 30px;"><?php _e('Email Recipients', 'job-posting-manager'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="recipient_email"><?php _e('Recipient Email', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="email" id="recipient_email" name="recipient_email"
                                            value="<?php echo esc_attr($email_settings['recipient_email'] ?? get_option('admin_email')); ?>"
                                            class="regular-text" required />
                                        <p class="description">
                                            <?php _e('Primary email address where application notifications will be sent', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="cc_emails"><?php _e('CC Emails', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <textarea id="cc_emails" name="cc_emails" rows="3" class="large-text"
                                            placeholder="email1@example.com, email2@example.com"><?php echo esc_textarea($email_settings['cc_emails'] ?? ''); ?></textarea>
                                        <p class="description">
                                            <?php _e('Comma-separated list of email addresses to CC on all emails', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="bcc_emails"><?php _e('BCC Emails', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <textarea id="bcc_emails" name="bcc_emails" rows="3" class="large-text"
                                            placeholder="email1@example.com, email2@example.com"><?php echo esc_textarea($email_settings['bcc_emails'] ?? ''); ?></textarea>
                                        <p class="description">
                                            <?php _e('Comma-separated list of email addresses to BCC on all emails', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" id="jpm-save-settings-btn" class="button button-primary">
                                    <span class="jpm-btn-text"><?php _e('Save Settings', 'job-posting-manager'); ?></span>
                                </button>
                            </p>
                        </form>

                        <hr style="margin: 30px 0;">

                        <h3><?php _e('Send Test Email', 'job-posting-manager'); ?></h3>
                        <p class="description">
                            <?php _e('Send a test email to verify your SMTP configuration is working correctly.', 'job-posting-manager'); ?>
                        </p>

                        <div id="jpm-test-email-message" style="margin-top: 15px; display: none;"></div>

                        <form id="jpm-test-email-form" style="margin-top: 20px;">
                            <?php wp_nonce_field('jpm_send_test_email', 'jpm_test_email_nonce'); ?>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="test_email_address"><?php _e('Test Email Address', 'job-posting-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="email" id="test_email_address" name="test_email_address"
                                            value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text"
                                            required />
                                        <p class="description">
                                            <?php _e('Enter the email address where you want to receive the test email', 'job-posting-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" id="jpm-send-test-email-btn" class="button button-secondary">
                                    <span class="jpm-btn-text"><?php _e('Send Test Email', 'job-posting-manager'); ?></span>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .jpm-settings-tabs {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            }

            .jpm-tab-nav {
                display: flex;
                border-bottom: 1px solid #ccd0d4;
                background: #f6f7f7;
                margin: 0;
                padding: 0;
            }

            .jpm-tab-link {
                display: inline-block;
                padding: 12px 20px;
                margin: 0;
                text-decoration: none;
                color: #50575e;
                font-weight: 500;
                border: none;
                border-bottom: 3px solid transparent;
                background: transparent;
                cursor: pointer;
                transition: all 0.2s;
            }

            .jpm-tab-link:hover {
                color: #2271b1;
                background: #fff;
            }

            .jpm-tab-link.active {
                color: #2271b1;
                background: #fff;
                border-bottom-color: #2271b1;
            }

            .jpm-tab-content-wrapper {
                position: relative;
            }

            .jpm-tab-content {
                display: none;
                padding: 20px;
            }

            .jpm-tab-content.active {
                display: block;
            }

            .jpm-shortcodes-tabs {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                margin-top: 20px;
            }

            .jpm-shortcode-tab-nav {
                display: flex;
                border-bottom: 1px solid #ddd;
                background: #f0f0f0;
                margin: 0;
                padding: 0;
            }

            .jpm-shortcode-tab-link {
                display: inline-block;
                padding: 10px 16px;
                margin: 0;
                text-decoration: none;
                color: #50575e;
                font-weight: 500;
                border: none;
                border-bottom: 2px solid transparent;
                background: transparent;
                cursor: pointer;
                transition: all 0.2s;
                font-size: 13px;
            }

            .jpm-shortcode-tab-link:hover {
                color: #2271b1;
                background: #fff;
            }

            .jpm-shortcode-tab-link.active {
                color: #2271b1;
                background: #fff;
                border-bottom-color: #2271b1;
            }

            .jpm-shortcode-tab-content-wrapper {
                position: relative;
            }

            .jpm-shortcode-tab-content {
                display: none;
                padding: 20px;
            }

            .jpm-shortcode-tab-content.active {
                display: block;
            }

            .jpm-shortcode-card {
                background: transparent;
                border: none;
                padding: 0;
                margin: 0;
                box-shadow: none;
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

            #jpm-send-test-email-btn {
                position: relative;
            }

            #jpm-send-test-email-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            #jpm-save-settings-btn,
            #jpm-send-test-email-btn {
                position: relative;
            }

            #jpm-save-settings-btn:disabled,
            #jpm-send-test-email-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            #jpm-save-settings-btn .jpm-btn-spinner,
            #jpm-send-test-email-btn .jpm-btn-spinner {
                display: inline-block;
                vertical-align: middle;
            }
        </style>
        <script>
            jQuery(document).ready(function ($) {
                // Main tabs (Shortcodes / Email Settings)
                $('.jpm-tab-link').on('click', function (e) {
                    e.preventDefault();

                    var tabId = $(this).data('tab');

                    // Remove active class from all tabs and content
                    $('.jpm-tab-link').removeClass('active');
                    $('.jpm-tab-content').removeClass('active');

                    // Add active class to clicked tab and corresponding content
                    $(this).addClass('active');
                    $('#tab-' + tabId).addClass('active');
                });

                // Shortcode tabs
                $('.jpm-shortcode-tab-link').on('click', function (e) {
                    e.preventDefault();

                    var tabId = $(this).data('shortcode-tab');

                    // Remove active class from all shortcode tabs and content
                    $('.jpm-shortcode-tab-link').removeClass('active');
                    $('.jpm-shortcode-tab-content').removeClass('active');

                    // Add active class to clicked tab and corresponding content
                    $(this).addClass('active');
                    $('#shortcode-tab-' + tabId).addClass('active');
                });

                // Save settings form AJAX
                $('#jpm-save-email-settings-form').on('submit', function (e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $message = $('#jpm-save-settings-message');
                    var $button = $('#jpm-save-settings-btn');
                    var $btnText = $button.find('.jpm-btn-text');
                    var originalText = $btnText.text();

                    // Disable button and show loading
                    $button.prop('disabled', true);
                    $btnText.text('<?php echo esc_js(__('Saving...', 'job-posting-manager')); ?>');
                    $message.hide();

                    // Collect form data
                    var formData = $form.serialize();
                    formData += '&action=jpm_save_email_settings';

                    // Send AJAX request
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        success: function (response) {
                            if (response.success) {
                                $message.html('<div class="notice notice-success is-dismissible"><p>' + (response.data.message || '<?php echo esc_js(__('Settings saved successfully!', 'job-posting-manager')); ?>') + '</p></div>').show();
                            } else {
                                $message.html('<div class="notice notice-error is-dismissible"><p>' + (response.data.message || '<?php echo esc_js(__('Failed to save settings. Please try again.', 'job-posting-manager')); ?>') + '</p></div>').show();
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error is-dismissible"><p><?php echo esc_js(__('An error occurred while saving settings. Please try again.', 'job-posting-manager')); ?></p></div>').show();
                        },
                        complete: function () {
                            $button.prop('disabled', false);
                            $btnText.text('<?php echo esc_js(__('Save Settings', 'job-posting-manager')); ?>');
                        }
                    });
                });

                // Test email form AJAX
                $('#jpm-test-email-form').on('submit', function (e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $message = $('#jpm-test-email-message');
                    var $button = $('#jpm-send-test-email-btn');
                    var email = $('#test_email_address').val();

                    // Validate email
                    if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                        $message.html('<div class="notice notice-error"><p><?php echo esc_js(__('Please enter a valid email address.', 'job-posting-manager')); ?></p></div>').show();
                        return;
                    }

                    // Disable button and show loading
                    var $btnText = $button.find('.jpm-btn-text');
                    var originalText = $btnText.text();

                    $button.prop('disabled', true);
                    $btnText.text('<?php echo esc_js(__('Sending...', 'job-posting-manager')); ?>');
                    $message.hide();

                    // Send AJAX request
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'jpm_send_test_email',
                            email: email,
                            nonce: '<?php echo wp_create_nonce('jpm_send_test_email'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                $message.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>').show();
                            } else {
                                $message.html('<div class="notice notice-error is-dismissible"><p>' + (response.data.message || '<?php echo esc_js(__('Failed to send test email. Please check your SMTP settings.', 'job-posting-manager')); ?>') + '</p></div>').show();
                            }
                        },
                        error: function () {
                            $message.html('<div class="notice notice-error is-dismissible"><p><?php echo esc_js(__('An error occurred while sending the test email. Please try again.', 'job-posting-manager')); ?></p></div>').show();
                        },
                        complete: function () {
                            $button.prop('disabled', false);
                            $button.find('.jpm-btn-text').text('<?php echo esc_js(__('Send Test Email', 'job-posting-manager')); ?>');
                        }
                    });
                });
            });
        </script>
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

    /**
     * AJAX handler for saving email settings
     */
    public function ajax_save_email_settings()
    {
        check_ajax_referer('jpm_save_email_settings', 'jpm_email_settings_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
        }

        // Save SMTP settings
        $smtp_settings = [
            'host' => sanitize_text_field($_POST['smtp_host'] ?? 'smtp.gmail.com'),
            'port' => intval($_POST['smtp_port'] ?? 587),
            'encryption' => sanitize_text_field($_POST['smtp_encryption'] ?? 'tls'),
            'auth' => !empty($_POST['smtp_auth']),
            'username' => sanitize_text_field($_POST['smtp_username'] ?? ''),
            'password' => sanitize_text_field($_POST['smtp_password'] ?? ''),
            'from_email' => sanitize_email($_POST['smtp_from_email'] ?? get_option('admin_email')),
            'from_name' => sanitize_text_field($_POST['smtp_from_name'] ?? get_bloginfo('name')),
        ];

        $smtp_result = update_option('jpm_smtp_settings', $smtp_settings);

        // Save email recipient settings
        $email_settings = [
            'recipient_email' => sanitize_email($_POST['recipient_email'] ?? get_option('admin_email')),
            'cc_emails' => sanitize_textarea_field($_POST['cc_emails'] ?? ''),
            'bcc_emails' => sanitize_textarea_field($_POST['bcc_emails'] ?? ''),
        ];

        $email_result = update_option('jpm_email_settings', $email_settings);

        if ($smtp_result !== false || $email_result !== false) {
            wp_send_json_success([
                'message' => __('Settings saved successfully!', 'job-posting-manager')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to save settings. Please try again.', 'job-posting-manager')
            ]);
        }
    }

    /**
     * AJAX handler for sending test email
     */
    public function ajax_send_test_email()
    {
        check_ajax_referer('jpm_send_test_email', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'job-posting-manager')]);
        }

        $test_email = sanitize_email($_POST['email'] ?? '');

        if (empty($test_email) || !is_email($test_email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'job-posting-manager')]);
        }

        // Check if SMTP is available
        if (!JPM_Emails::is_smtp_available()) {
            wp_send_json_error(['message' => __('SMTP is not configured. Please configure your SMTP settings first.', 'job-posting-manager')]);
        }

        // Prepare test email
        $subject = __('Test Email from Job Posting Manager', 'job-posting-manager');
        $body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">';
        $body .= '<div style="max-width: 600px; margin: 0 auto; padding: 20px;">';
        $body .= '<h2 style="color: #2271b1;">' . __('Test Email', 'job-posting-manager') . '</h2>';
        $body .= '<p>' . __('This is a test email to verify your SMTP configuration is working correctly.', 'job-posting-manager') . '</p>';
        $body .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
        $body .= '<p style="color: #666; font-size: 12px;">';
        $body .= __('If you received this email, your SMTP settings are configured correctly!', 'job-posting-manager');
        $body .= '</p>';
        $body .= '<p style="color: #666; font-size: 12px;">';
        $body .= sprintf(__('Sent from: %s', 'job-posting-manager'), get_bloginfo('name'));
        $body .= '<br>';
        $body .= sprintf(__('Time: %s', 'job-posting-manager'), current_time('mysql'));
        $body .= '</p>';
        $body .= '</div>';
        $body .= '</body></html>';

        // Email headers
        $smtp_settings = get_option('jpm_smtp_settings', []);
        $from_email = !empty($smtp_settings['from_email']) ? $smtp_settings['from_email'] : get_option('admin_email');
        $from_name = !empty($smtp_settings['from_name']) ? $smtp_settings['from_name'] : get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>'
        ];

        // Add CC and BCC from settings
        $email_settings = get_option('jpm_email_settings', []);
        if (!empty($email_settings['cc_emails'])) {
            $cc_emails = array_map('trim', explode(',', $email_settings['cc_emails']));
            $cc_emails = array_filter($cc_emails, 'is_email');
            if (!empty($cc_emails)) {
                $headers[] = 'Cc: ' . implode(', ', $cc_emails);
            }
        }
        if (!empty($email_settings['bcc_emails'])) {
            $bcc_emails = array_map('trim', explode(',', $email_settings['bcc_emails']));
            $bcc_emails = array_filter($bcc_emails, 'is_email');
            if (!empty($bcc_emails)) {
                $headers[] = 'Bcc: ' . implode(', ', $bcc_emails);
            }
        }

        // Send test email
        $result = wp_mail($test_email, $subject, $body, $headers);

        if ($result) {
            wp_send_json_success([
                'message' => sprintf(__('Test email sent successfully to %s!', 'job-posting-manager'), $test_email)
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to send test email. Please check your SMTP settings and try again.', 'job-posting-manager')
            ]);
        }
    }
}