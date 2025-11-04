<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}job_applications");
delete_option('jpm_settings');