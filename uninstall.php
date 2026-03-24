<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall routine must remove plugin-created table.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}job_applications");

delete_option('jpm_settings');