<?php
// If uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Access WordPress database object
global $wpdb;

// Drop custom tables
$tables = array(
    $wpdb->prefix . 'sac_keywords',
    $wpdb->prefix . 'sac_content_log',
    $wpdb->prefix . 'sac_api_logs',
    $wpdb->prefix . 'sac_duplicates'
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Delete plugin options
$options = array(
    'sac_options',
    'sac_db_version',
    'sac_activation_date',
    'sac_daily_monitoring_stats',
    'sac_content_generation_stats',
    'sac_emergency_stop_active',
    'sac_emergency_stop_time'
);

foreach ($options as $option) {
    delete_option($option);
}

// Delete post meta for all products
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_sac_keyword'));

// Get all posts with generated content
$generated_posts = get_posts(array(
    'post_type' => 'post',
    'meta_key' => '_sac_generated_content',
    'meta_value' => '1',
    'posts_per_page' => -1,
    'fields' => 'ids'
));

// Delete all generated posts
foreach ($generated_posts as $post_id) {
    wp_delete_post($post_id, true);
}

// Clear any scheduled cron jobs
wp_clear_scheduled_hook('sac_daily_monitoring');
wp_clear_scheduled_hook('sac_content_generation');
wp_clear_scheduled_hook('sac_cleanup'); 