<?php
class JPM_Settings {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_settings_page() {
        add_submenu_page('jpm-dashboard', __('Settings', 'job-posting-manager'), __('Settings', 'job-posting-manager'), 'manage_options', 'jpm-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('jpm_settings', 'jpm_settings');
        add_settings_section('jpm_email', __('Email Templates', 'job-posting-manager'), null, 'jpm-settings');
        add_settings_field('confirmation_subject', __('Confirmation Subject', 'job-posting-manager'), [$this, 'field_callback'], 'jpm-settings', 'jpm_email');
        // Add more fields
    }

    public function settings_page() {
        echo '<form method="post" action="options.php">';
        settings_fields('jpm_settings');
        do_settings_sections('jpm-settings');
        submit_button();
        echo '</form>';
    }

    public function field_callback() {
        $options = get_option('jpm_settings');
        echo '<input type="text" name="jpm_settings[confirmation_subject]" value="' . esc_attr($options['confirmation_subject']) . '">';
    }
}