<?php
class JPM_Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'save_smtp_settings']);
        add_action('admin_init', [$this, 'test_smtp_connection']);
    }

    public function add_settings_page()
    {
        add_submenu_page('jpm-dashboard', __('Settings', 'job-posting-manager'), __('Settings', 'job-posting-manager'), 'manage_options', 'jpm-settings', [$this, 'settings_page']);
    }

    public function register_settings()
    {
        register_setting('jpm_settings', 'jpm_settings');
        add_settings_section('jpm_email', __('Email Templates', 'job-posting-manager'), null, 'jpm-settings');
        add_settings_field('confirmation_subject', __('Confirmation Subject', 'job-posting-manager'), [$this, 'field_callback'], 'jpm-settings', 'jpm_email');
        // Add more fields
    }

    public function settings_page()
    {
        $smtp_settings = get_option('jpm_smtp_settings', []);
        $test_message = '';

        if (isset($_GET['smtp_test']) && $_GET['smtp_test'] === 'success') {
            $test_message = '<div class="notice notice-success"><p>' . __('SMTP test email sent successfully!', 'job-posting-manager') . '</p></div>';
        } elseif (isset($_GET['smtp_test']) && $_GET['smtp_test'] === 'failed') {
            $test_message = '<div class="notice notice-error"><p>' . __('SMTP test failed. Please check your settings.', 'job-posting-manager') . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Job Posting Manager Settings', 'job-posting-manager'); ?></h1>

            <?php echo $test_message; ?>

            <h2><?php _e('SMTP Configuration', 'job-posting-manager'); ?></h2>
            <p><?php _e('Email notifications are sent using the following SMTP settings:', 'job-posting-manager'); ?></p>

            <table class="form-table">
                <tr>
                    <th><?php _e('SMTP Host', 'job-posting-manager'); ?></th>
                    <td><?php echo esc_html($smtp_settings['host'] ?? 'smtp.gmail.com'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Port', 'job-posting-manager'); ?></th>
                    <td><?php echo esc_html($smtp_settings['port'] ?? '587'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Encryption', 'job-posting-manager'); ?></th>
                    <td><?php echo esc_html(strtoupper($smtp_settings['encryption'] ?? 'tls')); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Authentication', 'job-posting-manager'); ?></th>
                    <td><?php echo !empty($smtp_settings['auth']) ? __('Enabled', 'job-posting-manager') : __('Disabled', 'job-posting-manager'); ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Username', 'job-posting-manager'); ?></th>
                    <td><?php echo esc_html($smtp_settings['username'] ?? 'noreply050623@gmail.com'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('From Email', 'job-posting-manager'); ?></th>
                    <td><?php echo esc_html($smtp_settings['from_email'] ?? 'noreply050623@gmail.com'); ?></td>
                </tr>
            </table>

            <p>
                <a href="<?php echo admin_url('admin.php?page=jpm-settings&test_smtp=1'); ?>" class="button">
                    <?php _e('Test SMTP Connection', 'job-posting-manager'); ?>
                </a>
            </p>

            <hr>

            <h2><?php _e('Email Templates', 'job-posting-manager'); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('jpm_settings');
                do_settings_sections('jpm-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function field_callback()
    {
        $options = get_option('jpm_settings');
        echo '<input type="text" name="jpm_settings[confirmation_subject]" value="' . esc_attr($options['confirmation_subject'] ?? '') . '">';
    }

    public function save_smtp_settings()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['jpm_save_smtp']) && check_admin_referer('jpm_smtp_settings')) {
            $smtp_settings = [
                'host' => sanitize_text_field($_POST['smtp_host'] ?? 'smtp.gmail.com'),
                'port' => intval($_POST['smtp_port'] ?? 587),
                'encryption' => sanitize_text_field($_POST['smtp_encryption'] ?? 'tls'),
                'auth' => !empty($_POST['smtp_auth']),
                'username' => sanitize_email($_POST['smtp_username'] ?? ''),
                'password' => sanitize_text_field($_POST['smtp_password'] ?? ''),
                'from_email' => sanitize_email($_POST['smtp_from_email'] ?? ''),
                'from_name' => sanitize_text_field($_POST['smtp_from_name'] ?? ''),
            ];

            update_option('jpm_smtp_settings', $smtp_settings);
            wp_redirect(admin_url('admin.php?page=jpm-settings&settings_saved=1'));
            exit;
        }
    }

    public function test_smtp_connection()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['test_smtp']) && $_GET['test_smtp'] === '1') {
            $result = JPM_SMTP::test_smtp_connection();

            if (is_wp_error($result)) {
                wp_redirect(admin_url('admin.php?page=jpm-settings&smtp_test=failed'));
            } else {
                wp_redirect(admin_url('admin.php?page=jpm-settings&smtp_test=success'));
            }
            exit;
        }
    }
}