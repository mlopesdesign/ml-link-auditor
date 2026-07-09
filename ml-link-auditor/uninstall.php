<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mlla_results");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mlla_quarantine");
delete_option('mlla_settings');
delete_option('mlla_scan_job');
delete_option('mlla_license_state');
