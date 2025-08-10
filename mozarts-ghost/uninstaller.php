<?php
// uninstall.php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Access WordPress database
global $wpdb;

// Drop custom table
$table_name = $wpdb->prefix . 'mozarts_ghost_redirects';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Remove all posts of our custom post type
$posts = get_posts([
    'post_type' => 'mozarts_ghost',
    'numberposts' => -1,
    'post_status' => 'any'
]);

foreach ($posts as $post) {
    wp_delete_post($post->ID, true);
}

// Delete plugin options
delete_option('mozarts_ghost_settings');

// Remove log directory and files
$log_dir = WP_CONTENT_DIR . '/mozarts-ghost-logs';
if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}

// Clear any transients we may have set
delete_transient('mozarts_ghost_cache');

// If using WordPress multisite, you might want to handle network-wide uninstall
if (is_multisite()) {
    $sites = get_sites();
    foreach ($sites as $site) {
        switch_to_blog($site->blog_id);
        
        // Perform the same cleanup for each site
        $site_table = $wpdb->prefix . 'mozarts_ghost_redirects';
        $wpdb->query("DROP TABLE IF EXISTS $site_table");
        
        delete_option('mozarts_ghost_settings');
        restore_current_blog();
    }
}