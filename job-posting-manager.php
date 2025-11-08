<?php
/**
 * Plugin Name: Job Posting Manager
 * Plugin URI: https://example.com/job-posting-manager
 * Description: A plugin for managing job postings, applications, and notifications.
 * Version: 1.0.0
 * Author: Ericson Palisoc
 * License: GPL v2 or later
 * Text Domain: job-posting-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('JPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JPM_VERSION', '1.0.0');

// Include classes
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-db.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-admin.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-frontend.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-emails.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-settings.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-smtp.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-templates.php';
require_once JPM_PLUGIN_DIR . 'includes/class-jpm-form-builder.php';

// Activation hook
register_activation_hook(__FILE__, 'jpm_activate_plugin');
function jpm_activate_plugin()
{
    JPM_DB::create_tables();
    // Only initialize SMTP settings if no external SMTP plugin is active
    if (!JPM_SMTP::has_existing_smtp_plugin()) {
        JPM_SMTP::init_default_settings();
    }
    JPM_Templates::init_default_template();
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'jpm_deactivate_plugin');
function jpm_deactivate_plugin()
{
    flush_rewrite_rules();
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'jpm_enqueue_frontend_scripts');
function jpm_enqueue_frontend_scripts()
{
    wp_enqueue_style('jpm-frontend-styles', JPM_PLUGIN_URL . 'assets/css/jpm-frontend.css', [], JPM_VERSION);
    wp_enqueue_script('jpm-frontend-js', JPM_PLUGIN_URL . 'assets/js/jpm-frontend.js', ['jquery'], JPM_VERSION, true);
    wp_localize_script('jpm-frontend-js', 'jpm_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('jpm_nonce')
    ]);
}

add_action('admin_enqueue_scripts', 'jpm_enqueue_admin_scripts');
function jpm_enqueue_admin_scripts($hook)
{
    // Enqueue for job posting edit screens and admin pages
    $is_jpm_page = strpos($hook, 'jpm') !== false;
    $is_post_edit = ($hook === 'post.php' || $hook === 'post-new.php');

    // Check if we're on a job posting edit screen
    $is_job_posting = false;
    if ($is_post_edit) {
        global $post_type;
        if (isset($post_type) && $post_type === 'job_posting') {
            $is_job_posting = true;
        } elseif (isset($_GET['post_type']) && $_GET['post_type'] === 'job_posting') {
            $is_job_posting = true;
        } elseif (isset($_GET['post'])) {
            $post_id = intval($_GET['post']);
            if ($post_id && get_post_type($post_id) === 'job_posting') {
                $is_job_posting = true;
            }
        }
    }

    if ($is_jpm_page || $is_job_posting) {
        wp_enqueue_style('jpm-admin-styles', JPM_PLUGIN_URL . 'assets/css/jpm-admin.css', [], JPM_VERSION);
        wp_enqueue_script('jquery-ui-draggable');
        wp_enqueue_script('jquery-ui-droppable');

        // Enqueue form builder components in dependency order
        wp_enqueue_script('jpm-form-builder-utils', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-utils.js', ['jquery'], JPM_VERSION, true);
        wp_enqueue_script('jpm-form-builder-fields', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-fields.js', ['jquery', 'jpm-form-builder-utils'], JPM_VERSION, true);
        wp_enqueue_script('jpm-form-builder-layout', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-layout.js', ['jquery', 'jpm-form-builder-utils'], JPM_VERSION, true);
        wp_enqueue_script('jpm-form-builder-dragdrop', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-dragdrop.js', ['jquery', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jpm-form-builder-utils', 'jpm-form-builder-layout'], JPM_VERSION, true);
        wp_enqueue_script('jpm-form-builder-persistence', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-persistence.js', ['jquery'], JPM_VERSION, true);
        wp_enqueue_script('jpm-form-builder-core', JPM_PLUGIN_URL . 'assets/js/components/jpm-form-builder-core.js', ['jquery', 'jpm-form-builder-utils', 'jpm-form-builder-fields', 'jpm-form-builder-layout', 'jpm-form-builder-dragdrop', 'jpm-form-builder-persistence'], JPM_VERSION, true);

        // Main admin script (loads last)
        wp_enqueue_script('jpm-admin-js', JPM_PLUGIN_URL . 'assets/js/jpm-admin.js', ['jquery', 'jpm-form-builder-core'], JPM_VERSION, true);
        wp_localize_script('jpm-admin-js', 'jpm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jpm_nonce')
        ]);
    }
}

// Register custom post type
add_action('init', 'jpm_register_post_type');
function jpm_register_post_type()
{
    register_post_type('job_posting', [
        'labels' => [
            'name' => __('Job Postings', 'job-posting-manager'),
            'singular_name' => __('Job Posting', 'job-posting-manager'),
        ],
        'public' => true,
        'supports' => ['title', 'editor', 'custom-fields', 'categories', 'tags'],
        'menu_icon' => 'dashicons-businessman',
        'rewrite' => ['slug' => 'job-postings'],
    ]);
}

// Load text domain
add_action('plugins_loaded', 'jpm_load_textdomain');
function jpm_load_textdomain()
{
    load_plugin_textdomain('job-posting-manager', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// Initialize SMTP settings on plugin load
add_action('plugins_loaded', 'jpm_init_smtp_settings');
function jpm_init_smtp_settings()
{
    // Only initialize if no other SMTP plugin is active
    // Note: We don't initialize default settings - user must configure an SMTP plugin
    // This ensures emails won't work unless an SMTP plugin is properly configured
    if (!JPM_SMTP::has_existing_smtp_plugin()) {
        // Don't initialize default settings - require user to configure SMTP plugin
        // Emails will not be sent until an SMTP plugin is installed and configured
    }
}

// Initialize default template on plugin load
add_action('plugins_loaded', 'jpm_init_default_template');
function jpm_init_default_template()
{
    $templates = get_option('jpm_form_templates', []);
    if (empty($templates)) {
        JPM_Templates::init_default_template();
    }
}

// Initialize classes
new JPM_Admin();
new JPM_Frontend();
new JPM_Emails();
new JPM_Settings();
// Only initialize JPM_SMTP if no other SMTP plugin is active
if (!JPM_SMTP::has_existing_smtp_plugin()) {
    new JPM_SMTP();
}
new JPM_Templates();
new JPM_Form_Builder();