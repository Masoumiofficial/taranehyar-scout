<?php
/**
 * Uninstall Taranehyar Scout
 * توسعه‌دهنده: اتحاد وردپرس - https://etehadwp.com/
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Check if user wants to remove data
$options = get_option('tscout_options', array());
$remove_data = isset($options['remove_data_on_uninstall']) ? (bool) $options['remove_data_on_uninstall'] : false;

// Only remove tables if explicitly requested or in development
// For safety, we keep data by default unless option is set
if ($remove_data) {
    $tables = array(
        $wpdb->prefix . 'taranehyar_scout_discoveries',
        $wpdb->prefix . 'taranehyar_scout_jobs',
        $wpdb->prefix . 'taranehyar_scout_logs',
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Remove options
    delete_option('tscout_options');
    delete_option('tscout_provider_status_mybia2music');
    
    // Clear cron
    wp_clear_scheduled_hook('tscout_process_queue');
    wp_clear_scheduled_hook('tscout_cleanup');
}

// Always clear cron hooks
wp_clear_scheduled_hook('tscout_process_queue');
wp_clear_scheduled_hook('tscout_cleanup');
