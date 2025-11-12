<?php
/**
 * Email Templates Management Class
 * Allows admins to edit email notification formats, layouts, and content through UI
 */
class JPM_Email_Templates
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_email_templates_menu']);
        add_action('admin_init', [$this, 'save_email_templates']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_editor_scripts']);
    }

    /**
     * Add submenu for Email Notifications
     */
    public function add_email_templates_menu()
    {
        add_submenu_page(
            'jpm-dashboard',
            __('Email Notifications', 'job-posting-manager'),
            __('Email Notifications', 'job-posting-manager'),
            'manage_options',
            'jpm-email-templates',
            [$this, 'email_templates_page']
        );
    }

    /**
     * Enqueue scripts for rich text editor
     */
    public function enqueue_editor_scripts($hook)
    {
        if (strpos($hook, 'jpm-email-templates') === false) {
            return;
        }

        // Enqueue WordPress editor
        wp_enqueue_editor();
        wp_enqueue_media();
        wp_enqueue_style('jpm-admin-styles', JPM_PLUGIN_URL . 'assets/css/jpm-admin.css', [], JPM_VERSION);
    }

    /**
     * Save email templates
     */
    public function save_email_templates()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['jpm_save_email_templates']) && check_admin_referer('jpm_email_templates_nonce')) {
            $templates = [];

            // Save confirmation email template
            $templates['confirmation'] = [
                'subject' => sanitize_text_field($_POST['confirmation_subject'] ?? ''),
                'header_color' => sanitize_hex_color($_POST['confirmation_header_color'] ?? '#f8f9fa'),
                'header_text_color' => sanitize_hex_color($_POST['confirmation_header_text_color'] ?? '#2c3e50'),
                'body_bg_color' => sanitize_hex_color($_POST['confirmation_body_bg_color'] ?? '#ffffff'),
                'body_text_color' => sanitize_hex_color($_POST['confirmation_body_text_color'] ?? '#333'),
                'greeting' => wp_kses_post($_POST['confirmation_greeting'] ?? ''),
                'intro_message' => wp_kses_post($_POST['confirmation_intro_message'] ?? ''),
                'details_section_title' => sanitize_text_field($_POST['confirmation_details_section_title'] ?? ''),
                'details_bg_color' => sanitize_hex_color($_POST['confirmation_details_bg_color'] ?? '#f8f9fa'),
                'closing_message' => wp_kses_post($_POST['confirmation_closing_message'] ?? ''),
                'footer_message' => wp_kses_post($_POST['confirmation_footer_message'] ?? ''),
                'footer_bg_color' => sanitize_hex_color($_POST['confirmation_footer_bg_color'] ?? '#f8f9fa'),
            ];

            // Save status update email template
            $templates['status_update'] = [
                'subject' => sanitize_text_field($_POST['status_update_subject'] ?? ''),
                'header_color' => sanitize_hex_color($_POST['status_update_header_color'] ?? '#ffc107'),
                'header_text_color' => sanitize_hex_color($_POST['status_update_header_text_color'] ?? '#000000'),
                'body_bg_color' => sanitize_hex_color($_POST['status_update_body_bg_color'] ?? '#ffffff'),
                'body_text_color' => sanitize_hex_color($_POST['status_update_body_text_color'] ?? '#333'),
                'greeting' => wp_kses_post($_POST['status_update_greeting'] ?? ''),
                'intro_message' => wp_kses_post($_POST['status_update_intro_message'] ?? ''),
                'status_section_title' => sanitize_text_field($_POST['status_update_status_section_title'] ?? ''),
                'status_bg_color' => sanitize_hex_color($_POST['status_update_status_bg_color'] ?? '#f8f9fa'),
                'details_section_title' => sanitize_text_field($_POST['status_update_details_section_title'] ?? ''),
                'details_bg_color' => sanitize_hex_color($_POST['status_update_details_bg_color'] ?? '#f8f9fa'),
                'status_specific_message' => wp_kses_post($_POST['status_update_status_specific_message'] ?? ''),
                'closing_message' => wp_kses_post($_POST['status_update_closing_message'] ?? ''),
                'footer_message' => wp_kses_post($_POST['status_update_footer_message'] ?? ''),
                'footer_bg_color' => sanitize_hex_color($_POST['status_update_footer_bg_color'] ?? '#f8f9fa'),
            ];

            // Save admin notification email template
            $templates['admin_notification'] = [
                'subject' => sanitize_text_field($_POST['admin_notification_subject'] ?? ''),
                'header_color' => sanitize_hex_color($_POST['admin_notification_header_color'] ?? '#dc3545'),
                'header_text_color' => sanitize_hex_color($_POST['admin_notification_header_text_color'] ?? '#ffffff'),
                'body_bg_color' => sanitize_hex_color($_POST['admin_notification_body_bg_color'] ?? '#ffffff'),
                'body_text_color' => sanitize_hex_color($_POST['admin_notification_body_text_color'] ?? '#333'),
                'job_section_title' => sanitize_text_field($_POST['admin_notification_job_section_title'] ?? ''),
                'job_section_bg_color' => sanitize_hex_color($_POST['admin_notification_job_section_bg_color'] ?? '#f8f9fa'),
                'applicant_section_title' => sanitize_text_field($_POST['admin_notification_applicant_section_title'] ?? ''),
                'applicant_section_bg_color' => sanitize_hex_color($_POST['admin_notification_applicant_section_bg_color'] ?? '#f8f9fa'),
                'details_section_title' => sanitize_text_field($_POST['admin_notification_details_section_title'] ?? ''),
                'details_section_bg_color' => sanitize_hex_color($_POST['admin_notification_details_section_bg_color'] ?? '#f8f9fa'),
                'action_required_message' => wp_kses_post($_POST['admin_notification_action_required_message'] ?? ''),
                'footer_message' => wp_kses_post($_POST['admin_notification_footer_message'] ?? ''),
                'footer_bg_color' => sanitize_hex_color($_POST['admin_notification_footer_bg_color'] ?? '#f8f9fa'),
            ];

            update_option('jpm_email_templates', $templates);

            wp_redirect(admin_url('admin.php?page=jpm-email-templates&saved=1'));
            exit;
        }
    }

    /**
     * Get default email templates
     */
    public static function get_default_templates()
    {
        return [
            'confirmation' => [
                'subject' => __('Application Confirmation #[Application ID] - [Job Title]', 'job-posting-manager'),
                'header_color' => '#f8f9fa',
                'header_text_color' => '#2c3e50',
                'body_bg_color' => '#ffffff',
                'body_text_color' => '#333',
                'greeting' => __('Dear [Full Name],', 'job-posting-manager'),
                'intro_message' => __('Thank you for submitting your job application. We have successfully received your application and it is now <strong>pending</strong>.', 'job-posting-manager'),
                'details_section_title' => __('Application Details', 'job-posting-manager'),
                'details_bg_color' => '#f8f9fa',
                'closing_message' => __('Our team will carefully review your application and qualifications. We will contact you via email if we need any additional information or to schedule an interview.', 'job-posting-manager'),
                'footer_message' => __('This is an automated confirmation email. Please do not reply to this message.', 'job-posting-manager'),
                'footer_bg_color' => '#f8f9fa',
            ],
            'status_update' => [
                'subject' => __('Application Status Update: [Status Name] - [Job Title]', 'job-posting-manager'),
                'header_color' => '#ffc107',
                'header_text_color' => '#000000',
                'body_bg_color' => '#ffffff',
                'body_text_color' => '#333',
                'greeting' => __('Dear [Full Name],', 'job-posting-manager'),
                'intro_message' => __('We would like to inform you that the status of your job application has been updated.', 'job-posting-manager'),
                'status_section_title' => __('New Status:', 'job-posting-manager'),
                'status_bg_color' => '#f8f9fa',
                'details_section_title' => __('Application Details', 'job-posting-manager'),
                'details_bg_color' => '#f8f9fa',
                'status_specific_message' => __('We will keep you updated on any further changes to your application status.', 'job-posting-manager'),
                'closing_message' => __('If you have any questions about your application, please feel free to contact us.', 'job-posting-manager'),
                'footer_message' => __('This is an automated notification. Please do not reply to this message.', 'job-posting-manager'),
                'footer_bg_color' => '#f8f9fa',
            ],
            'admin_notification' => [
                'subject' => __('New Job Application: [Job Title] - [Full Name]', 'job-posting-manager'),
                'header_color' => '#dc3545',
                'header_text_color' => '#ffffff',
                'body_bg_color' => '#ffffff',
                'body_text_color' => '#333',
                'job_section_title' => __('Job Information', 'job-posting-manager'),
                'job_section_bg_color' => '#f8f9fa',
                'applicant_section_title' => __('Applicant Information', 'job-posting-manager'),
                'applicant_section_bg_color' => '#f8f9fa',
                'details_section_title' => __('Application Details', 'job-posting-manager'),
                'details_section_bg_color' => '#f8f9fa',
                'action_required_message' => __('<strong>Action Required:</strong> Please review this application and update its status in the admin panel.', 'job-posting-manager'),
                'footer_message' => __('This is an automated notification from Job Posting Manager.', 'job-posting-manager'),
                'footer_bg_color' => '#f8f9fa',
            ],
        ];
    }

    /**
     * Get email template (with defaults if not set)
     */
    public static function get_template($type)
    {
        $templates = get_option('jpm_email_templates', []);
        $defaults = self::get_default_templates();

        if (isset($templates[$type])) {
            return array_merge($defaults[$type] ?? [], $templates[$type]);
        }

        return $defaults[$type] ?? [];
    }

    /**
     * Email templates page
     */
    public function email_templates_page()
    {
        // Get current templates or defaults
        $templates = get_option('jpm_email_templates', []);
        $defaults = self::get_default_templates();

        $confirmation = array_merge($defaults['confirmation'], $templates['confirmation'] ?? []);
        $status_update = array_merge($defaults['status_update'], $templates['status_update'] ?? []);
        $admin_notification = array_merge($defaults['admin_notification'], $templates['admin_notification'] ?? []);

        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'confirmation';

        // Show success message
        if (isset($_GET['saved']) && $_GET['saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Email templates saved successfully!', 'job-posting-manager') . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Email Notifications', 'job-posting-manager'); ?></h1>
            <p class="description">
                <?php _e('Customize the format, layout, and content of your email notifications. Use the following placeholders: [Application ID], [Job Title], [Full Name], [Status Name], [Application Number], [Email].', 'job-posting-manager'); ?>
            </p>

            <nav class="nav-tab-wrapper">
                <a href="?page=jpm-email-templates&tab=confirmation"
                    class="nav-tab <?php echo $active_tab === 'confirmation' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Confirmation Email', 'job-posting-manager'); ?>
                </a>
                <a href="?page=jpm-email-templates&tab=status_update"
                    class="nav-tab <?php echo $active_tab === 'status_update' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Status Update Email', 'job-posting-manager'); ?>
                </a>
                <a href="?page=jpm-email-templates&tab=admin_notification"
                    class="nav-tab <?php echo $active_tab === 'admin_notification' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Admin Notification Email', 'job-posting-manager'); ?>
                </a>
            </nav>

            <form method="post" action="">
                <?php wp_nonce_field('jpm_email_templates_nonce'); ?>

                <?php if ($active_tab === 'confirmation'): ?>
                    <div class="jpm-email-template-section">
                        <h2><?php _e('Confirmation Email Template', 'job-posting-manager'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_subject"><?php _e('Email Subject', 'job-posting-manager'); ?></label></th>
                                <td><input type="text" id="confirmation_subject" name="confirmation_subject"
                                        value="<?php echo esc_attr($confirmation['subject']); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Header Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="confirmation_header_color"
                                            value="<?php echo esc_attr($confirmation['header_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="confirmation_header_text_color"
                                            value="<?php echo esc_attr($confirmation['header_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Body Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="confirmation_body_bg_color"
                                            value="<?php echo esc_attr($confirmation['body_bg_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="confirmation_body_text_color"
                                            value="<?php echo esc_attr($confirmation['body_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_greeting"><?php _e('Greeting', 'job-posting-manager'); ?></label></th>
                                <td><textarea id="confirmation_greeting" name="confirmation_greeting" rows="2"
                                        class="large-text"><?php echo esc_textarea($confirmation['greeting']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_intro_message"><?php _e('Introduction Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="confirmation_intro_message" name="confirmation_intro_message" rows="3"
                                        class="large-text"><?php echo esc_textarea($confirmation['intro_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_details_section_title"><?php _e('Details Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="confirmation_details_section_title"
                                        name="confirmation_details_section_title"
                                        value="<?php echo esc_attr($confirmation['details_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Details Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="confirmation_details_bg_color"
                                        value="<?php echo esc_attr($confirmation['details_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_closing_message"><?php _e('Closing Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="confirmation_closing_message" name="confirmation_closing_message" rows="3"
                                        class="large-text"><?php echo esc_textarea($confirmation['closing_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="confirmation_footer_message"><?php _e('Footer Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="confirmation_footer_message" name="confirmation_footer_message" rows="2"
                                        class="large-text"><?php echo esc_textarea($confirmation['footer_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Footer Background Color', 'job-posting-manager'); ?></label></th>
                                <td><input type="color" name="confirmation_footer_bg_color"
                                        value="<?php echo esc_attr($confirmation['footer_bg_color']); ?>" /></td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'status_update'): ?>
                    <div class="jpm-email-template-section">
                        <h2><?php _e('Status Update Email Template', 'job-posting-manager'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label
                                        for="status_update_subject"><?php _e('Email Subject', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="status_update_subject" name="status_update_subject"
                                        value="<?php echo esc_attr($status_update['subject']); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Header Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="status_update_header_color"
                                            value="<?php echo esc_attr($status_update['header_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="status_update_header_text_color"
                                            value="<?php echo esc_attr($status_update['header_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Body Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="status_update_body_bg_color"
                                            value="<?php echo esc_attr($status_update['body_bg_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="status_update_body_text_color"
                                            value="<?php echo esc_attr($status_update['body_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_greeting"><?php _e('Greeting', 'job-posting-manager'); ?></label></th>
                                <td><textarea id="status_update_greeting" name="status_update_greeting" rows="2"
                                        class="large-text"><?php echo esc_textarea($status_update['greeting']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_intro_message"><?php _e('Introduction Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="status_update_intro_message" name="status_update_intro_message" rows="3"
                                        class="large-text"><?php echo esc_textarea($status_update['intro_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_status_section_title"><?php _e('Status Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="status_update_status_section_title"
                                        name="status_update_status_section_title"
                                        value="<?php echo esc_attr($status_update['status_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Status Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="status_update_status_bg_color"
                                        value="<?php echo esc_attr($status_update['status_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_details_section_title"><?php _e('Details Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="status_update_details_section_title"
                                        name="status_update_details_section_title"
                                        value="<?php echo esc_attr($status_update['details_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Details Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="status_update_details_bg_color"
                                        value="<?php echo esc_attr($status_update['details_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_status_specific_message"><?php _e('Status-Specific Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="status_update_status_specific_message"
                                        name="status_update_status_specific_message" rows="3"
                                        class="large-text"><?php echo esc_textarea($status_update['status_specific_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_closing_message"><?php _e('Closing Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="status_update_closing_message" name="status_update_closing_message" rows="3"
                                        class="large-text"><?php echo esc_textarea($status_update['closing_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="status_update_footer_message"><?php _e('Footer Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="status_update_footer_message" name="status_update_footer_message" rows="2"
                                        class="large-text"><?php echo esc_textarea($status_update['footer_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Footer Background Color', 'job-posting-manager'); ?></label></th>
                                <td><input type="color" name="status_update_footer_bg_color"
                                        value="<?php echo esc_attr($status_update['footer_bg_color']); ?>" /></td>
                            </tr>
                        </table>
                    </div>

                <?php elseif ($active_tab === 'admin_notification'): ?>
                    <div class="jpm-email-template-section">
                        <h2><?php _e('Admin Notification Email Template', 'job-posting-manager'); ?></h2>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_subject"><?php _e('Email Subject', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="admin_notification_subject" name="admin_notification_subject"
                                        value="<?php echo esc_attr($admin_notification['subject']); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Header Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="admin_notification_header_color"
                                            value="<?php echo esc_attr($admin_notification['header_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="admin_notification_header_text_color"
                                            value="<?php echo esc_attr($admin_notification['header_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Body Colors', 'job-posting-manager'); ?></label></th>
                                <td>
                                    <label><?php _e('Background Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="admin_notification_body_bg_color"
                                            value="<?php echo esc_attr($admin_notification['body_bg_color']); ?>" />
                                    </label>
                                    <label style="margin-left: 20px;"><?php _e('Text Color:', 'job-posting-manager'); ?>
                                        <input type="color" name="admin_notification_body_text_color"
                                            value="<?php echo esc_attr($admin_notification['body_text_color']); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_job_section_title"><?php _e('Job Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="admin_notification_job_section_title"
                                        name="admin_notification_job_section_title"
                                        value="<?php echo esc_attr($admin_notification['job_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Job Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="admin_notification_job_section_bg_color"
                                        value="<?php echo esc_attr($admin_notification['job_section_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_applicant_section_title"><?php _e('Applicant Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="admin_notification_applicant_section_title"
                                        name="admin_notification_applicant_section_title"
                                        value="<?php echo esc_attr($admin_notification['applicant_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Applicant Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="admin_notification_applicant_section_bg_color"
                                        value="<?php echo esc_attr($admin_notification['applicant_section_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_details_section_title"><?php _e('Details Section Title', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="text" id="admin_notification_details_section_title"
                                        name="admin_notification_details_section_title"
                                        value="<?php echo esc_attr($admin_notification['details_section_title']); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Details Section Background Color', 'job-posting-manager'); ?></label>
                                </th>
                                <td><input type="color" name="admin_notification_details_section_bg_color"
                                        value="<?php echo esc_attr($admin_notification['details_section_bg_color']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_action_required_message"><?php _e('Action Required Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="admin_notification_action_required_message"
                                        name="admin_notification_action_required_message" rows="2"
                                        class="large-text"><?php echo esc_textarea($admin_notification['action_required_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label
                                        for="admin_notification_footer_message"><?php _e('Footer Message', 'job-posting-manager'); ?></label>
                                </th>
                                <td><textarea id="admin_notification_footer_message" name="admin_notification_footer_message"
                                        rows="2"
                                        class="large-text"><?php echo esc_textarea($admin_notification['footer_message']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php _e('Footer Background Color', 'job-posting-manager'); ?></label></th>
                                <td><input type="color" name="admin_notification_footer_bg_color"
                                        value="<?php echo esc_attr($admin_notification['footer_bg_color']); ?>" /></td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>

                <?php submit_button(__('Save Email Templates', 'job-posting-manager'), 'primary', 'jpm_save_email_templates'); ?>
            </form>
        </div>
        <?php
    }
}

