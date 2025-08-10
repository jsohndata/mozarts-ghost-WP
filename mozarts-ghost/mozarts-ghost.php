    <?php
/**
 * Plugin Name: Mozart's Ghost Redirector
 * Description: Custom URL redirector with ghost URLs and shortcodes
 * Version: 1.2.3
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: FireAIMedia
 * License: GPL v2 or later
 *
 * Changelog:
 * - v1.2.3 (2025-08-10): Fixed bug with redirects not respecting custom sort order.
 * - v1.2.2 (2025-08-10): Fixed issue with order_id values showing as 0 in admin UI.
 * - v1.2.1 (2025-08-10): Fixed ordering bugs, improved error handling for sort operations.
 * - v1.2.0 (2025-08-10): Added sort functionality with up/down controls.
 * - v1.1.0 (2025-08-10): Removed all 'new tab' references, cleaned up code, and ensured DB table adjusts on upgrade.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define custom log file in plugin directory
define('MG_LOG_FILE', plugin_dir_path(__FILE__) . 'mozart-debug.log');

// Custom logging function
if (!function_exists('mg_log')) {
    function mg_log($message) {
        $timestamp = date('[Y-m-d H:i:s] ');
        if (is_array($message) || is_object($message)) {
            file_put_contents(MG_LOG_FILE, $timestamp . print_r($message, true) . "\n", FILE_APPEND);
        } else {
            file_put_contents(MG_LOG_FILE, $timestamp . $message . "\n", FILE_APPEND);
        }
    }
}

class MozartsGhostRedirector {
    private static ?MozartsGhostRedirector $instance = null;
    private string $post_type = 'mozarts_ghost';
    private string $table_name;
    private bool $initialized = false;

    public static function getInstance(): MozartsGhostRedirector {
        if (self::$instance === null) {
            mg_log('Creating new instance');
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        try {
            mg_log('Constructor started');
            
            global $wpdb;
            $this->table_name = $wpdb->prefix . 'mozarts_ghost_redirects';

            // Register early hooks
            $this->register_early_hooks();

            mg_log('Constructor completed');
            
        } catch (Exception $e) {
            mg_log('Constructor error: ' . $e->getMessage());
        }
    }

    private function register_early_hooks(): void {
        // Register post type early
        add_action('init', [$this, 'register_post_type'], 0);
        
        // Initialize plugin
        add_action('init', [$this, 'initialize'], 10);
        
        // Register activation hook
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);

        // Add filter for Read More links
        add_filter('the_content_more_link', [$this, 'modify_read_more_link'], 10, 2);
    }

    public function initialize(): void {
        try {
            if ($this->initialized) {
                return;
            }

            mg_log('Initializing plugin');

            // Add admin menu
            add_action('admin_menu', [$this, 'add_admin_menu']);

            // Hide 'Add New' button on All Entries page
            add_action('admin_head', [$this, 'hide_add_new_button']);

            
            // Add hooks for redirect handling
            add_action('template_redirect', [$this, 'handle_redirect']);
            add_filter('post_type_link', [$this, 'modify_ghost_permalink'], 10, 2);
            add_filter('the_permalink', [$this, 'modify_ghost_links']);

            $this->initialized = true;
            mg_log('Plugin initialized');

        } catch (Exception $e) {
            mg_log('Initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Hide 'Add New Ghost Entry' button on All Entries admin page
     */
    public function hide_add_new_button() {
        global $pagenow;
        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === $this->post_type) {
            echo '<style>.page-title-action { display: none !important; }</style>';
        }
    }

    public function modify_read_more_link($more_link, $more_link_text): string {
        global $post;
        
        if ($post->post_type === $this->post_type) {
            return str_replace('<a ', '<a target="_blank" ', $more_link);
        }
        
        return $more_link;
    }

    // Database version constant
    private string $db_version = '1.2.3';

    public function activate_plugin(): void {
        try {
            mg_log('Starting activation');

            global $wpdb;

            if (!function_exists('dbDelta')) {
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            }

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                shortcode varchar(50) NOT NULL,
                target_url varchar(255) NOT NULL,
                order_id int(11) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY shortcode (shortcode)
            ) $charset_collate;";

            dbDelta($sql);

            // Verify table creation
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_name
            ));

            if (!$table_exists) {
                throw new Exception('Failed to create database table');
            }

            // Check and update database version
            $stored_version = get_option('mozarts_ghost_db_version');
            if ($stored_version !== $this->db_version) {
                $this->update_database($stored_version);
                update_option('mozarts_ghost_db_version', $this->db_version);
            }

            mg_log('Activation completed');

        } catch (Exception $e) {
            mg_log('Activation error: ' . $e->getMessage());
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Plugin activation failed: ' . $e->getMessage());
        }
    }

    /**
     * Update database structure if needed
     * @param string|null $old_version
     */
    private function update_database(?string $old_version): void {
        global $wpdb;
        mg_log('Checking for database updates. Old version: ' . ($old_version ?? 'none'));

        // Example: If upgrading from a previous version, run ALTER TABLE, etc.
        // if ($old_version === '1.0.0') { ... }
        // For now, just a placeholder for future upgrades
        // Remove 'new_tab' column if upgrading from previous version
        if ($old_version && version_compare($old_version, '1.0.1', '<')) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'new_tab'");
            if (!empty($columns)) {
                $wpdb->query("ALTER TABLE {$this->table_name} DROP COLUMN new_tab;");
                mg_log("Dropped 'new_tab' column from table.");
            }
        }

        // Add order_id column if upgrading from previous version
        if ($old_version && version_compare($old_version, '1.2.0', '<')) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'order_id'");
            if (empty($columns)) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN order_id int(11) NOT NULL DEFAULT 0;");
                mg_log("Added 'order_id' column to table.");
                // Initialize order_id values based on id order
                $redirects = $wpdb->get_results("SELECT id FROM {$this->table_name} ORDER BY id ASC");
                $order = 1;
                foreach ($redirects as $redirect) {
                    $wpdb->update($this->table_name, ['order_id' => $order], ['id' => $redirect->id]);
                    $order++;
                }
                mg_log("Initialized 'order_id' values.");
            }
        }
    }

    public function register_post_type(): void {
        try {
            mg_log('Registering post type');
            
            $labels = [
                'name' => 'Mozart\'s Ghost',
                'singular_name' => 'Ghost Entry',
                'menu_name' => 'Mozart\'s Ghost',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New Ghost Entry',
                'edit_item' => 'Edit Ghost Entry',
                'view_item' => 'View Ghost Entry',
                'search_items' => 'Search Ghost Entries'
            ];

            $args = [
                'labels' => $labels,
                'public' => true,
                'show_in_menu' => false,
                'supports' => ['title', 'editor', 'thumbnail','excerpt'],
                'has_archive' => true,
                'show_in_rest' => true,
                'capability_type' => 'post',
                'map_meta_cap' => true,
                'rewrite' => ['slug' => 'ghost-entry']
            ];

            $result = register_post_type($this->post_type, $args);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            mg_log('Post type registered');
            
        } catch (Exception $e) {
            mg_log('Post type registration error: ' . $e->getMessage());
        }
    }

    public function add_admin_menu(): void {
        try {
            mg_log('Adding admin menu');

            add_menu_page(
                'Mozart\'s Ghost',
                'Mozart\'s Ghost',
                'manage_options',
                'mozarts-ghost',
                [$this, 'display_redirects_page'],
                'dashicons-randomize'
            );

            add_submenu_page(
                'mozarts-ghost',
                'Redirects',
                'Redirects',
                'manage_options',
                'mozarts-ghost',
                [$this, 'display_redirects_page']
            );

            add_submenu_page(
                'mozarts-ghost',
                'All Entries',
                'All Entries',
                'manage_options',
                "edit.php?post_type={$this->post_type}"
            );

            

            mg_log('Admin menu added');
            
        } catch (Exception $e) {
            mg_log('Admin menu error: ' . $e->getMessage());
        }
    }

    /**
     * Ensures all redirects have valid order_id values
     * Fixes any entries with order_id = 0
     */
    private function repair_order_values(): void {
        global $wpdb;
        
        // Check for any records with order_id = 0
        $invalid_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE order_id = 0");
        
        if ($invalid_orders > 0) {
            mg_log("Found {$invalid_orders} records with invalid order_id values. Repairing...");
            
            // Get all redirects ordered by ID
            $redirects = $wpdb->get_results("SELECT id FROM {$this->table_name} ORDER BY id ASC");
            
            // Reassign order_id values sequentially
            $order = 1;
            foreach ($redirects as $redirect) {
                $wpdb->update($this->table_name, ['order_id' => $order], ['id' => $redirect->id]);
                $order++;
            }
            
            mg_log("Order values repaired. Assigned sequential order_id values to " . ($order-1) . " records.");
        }
    }
    
    public function display_redirects_page(): void {
        global $wpdb;
        
        // Repair any invalid order values before proceeding
        $this->repair_order_values();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['add_redirect']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $this->create_redirect($_POST);
            } elseif (isset($_POST['delete_redirect']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $this->delete_redirect((int)$_POST['redirect_id']);
            } elseif (isset($_POST['move_up']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $id = (int)$_POST['redirect_id'];
                $order_id = (int)$_POST['order_id'];
                
                // Server-side validation - prevent moving up if already at the top
                if ($order_id <= 1) {
                    mg_log("Prevented moving up item already at the top (order_id: {$order_id})");
                    // Add admin notice
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-warning is-dismissible"><p>Cannot move item up: already at the top.</p></div>';
                    });
                    // Skip the operation
                } else {
                    $prev = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE order_id = %d", $order_id - 1));
                    if ($prev) {
                        $this->swap_order($id, $prev->id);
                    }
                }
            } elseif (isset($_POST['move_down']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $id = (int)$_POST['redirect_id'];
                $order_id = (int)$_POST['order_id'];
                
                // Get total count for server-side validation
                $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
                
                // Server-side validation - prevent moving down if already at the bottom
                if ($order_id >= $total_count) {
                    mg_log("Prevented moving down item already at the bottom (order_id: {$order_id}, total: {$total_count})");
                    // Add admin notice
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-warning is-dismissible"><p>Cannot move item down: already at the bottom.</p></div>';
                    });
                    // Skip the operation
                } else {
                    $next = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE order_id = %d", $order_id + 1));
                    if ($next) {
                        $this->swap_order($id, $next->id);
                    }
                }
            }
        }

    $redirects = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY order_id ASC");
        ?>
        <div class="wrap">
            <h1>Mozart's Ghost Redirects</h1>
            
            <h2>Add New Redirect</h2>
            <form method="post" action="">
                <?php wp_nonce_field('mozarts_ghost_action', 'mozarts_ghost_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="shortcode">Shortcode</label></th>
                        <td><input type="text" name="shortcode" required /></td>
                    </tr>
                    <tr>
                        <th><label for="target_url">Target URL</label></th>
                        <td><input type="url" name="target_url" required /></td>
                    </tr>
                    
                </table>
                <input type="submit" name="add_redirect" class="button button-primary" value="Add Redirect" />
            </form>

            <h2>Existing Redirects</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style='width:60px;'>Order</th>
                        <th>Shortcode</th>
                        <th>Ghost URL</th>
                        <th>Target URL</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $redirect): ?>
                        <tr>
                            <td><?php echo esc_html($redirect->order_id); ?></td>
                            <td><?php echo esc_html($redirect->shortcode); ?></td>
                            <td><?php echo esc_url($this->get_ghost_url($redirect->shortcode)); ?></td>
                            <td><?php echo esc_url($redirect->target_url); ?></td>
                            <td>
                                <form method="post" style="display:inline; margin-right:4px;">
                                    <?php wp_nonce_field('mozarts_ghost_action', 'mozarts_ghost_nonce'); ?>
                                    <input type="hidden" name="redirect_id" value="<?php echo $redirect->id; ?>" />
                                    <input type="hidden" name="order_id" value="<?php echo $redirect->order_id; ?>" />
                                    <button type="submit" name="move_up" class="button button-small" title="Move Up" <?php if ($redirect->order_id == 1) echo 'disabled'; ?>>↑</button>
                                    <button type="submit" name="move_down" class="button button-small" title="Move Down" <?php if ($redirect->order_id == count($redirects)) echo 'disabled'; ?>>↓</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('mozarts_ghost_action', 'mozarts_ghost_nonce'); ?>
                                    <input type="hidden" name="redirect_id" value="<?php echo $redirect->id; ?>" />
                                    <button type="submit" name="delete_redirect" class="button button-small" onclick="return confirm('Are you sure?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function create_redirect(array $data): bool {
        try {
            mg_log('Creating redirect');
            
            global $wpdb;
            
            if (empty($data['shortcode']) || empty($data['target_url'])) {
                throw new Exception('Missing required fields');
            }
            
            $post_data = [
                'post_title' => sanitize_text_field($data['shortcode']),
                'post_type' => $this->post_type,
                'post_status' => 'publish'
            ];
            
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }
            
            // Get the highest order_id value and add 1 for the new redirect
            $max_order = $wpdb->get_var("SELECT MAX(order_id) FROM {$this->table_name}");
            $new_order = is_null($max_order) ? 1 : (int)$max_order + 1;
            
            $result = $wpdb->insert(
                $this->table_name,
                [
                    'post_id' => $post_id,
                    'shortcode' => sanitize_text_field($data['shortcode']),
                    'target_url' => esc_url_raw($data['target_url']),
                    'order_id' => $new_order
                ],
                ['%d', '%s', '%s', '%d']
            );
            
            if ($result === false) {
                wp_delete_post($post_id, true);
                throw new Exception($wpdb->last_error);
            }
            
            mg_log('Redirect created with order_id: ' . $new_order);
            return true;
            
        } catch (Exception $e) {
            mg_log('Create redirect error: ' . $e->getMessage());
            return false;
        }
    }

    private function delete_redirect(int $id): bool {
        try {
            mg_log('Deleting redirect');
            
            global $wpdb;
            
            // Get the order_id of the redirect to be deleted
            $redirect = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, order_id FROM {$this->table_name} WHERE id = %d",
                $id
            ));
            
            if ($redirect) {
                $deleted_order_id = $redirect->order_id;
                
                // Delete the post
                wp_delete_post($redirect->post_id, true);
                
                // Delete the redirect record
                $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
                
                // Update order_id values for all redirects with higher order_id
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_name} SET order_id = order_id - 1 WHERE order_id > %d",
                    $deleted_order_id
                ));
                
                mg_log('Redirect deleted and order_id values updated');
            }
            
            return true;
            
        } catch (Exception $e) {
            mg_log('Delete redirect error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Swap order_id between two redirects
     * @param int $id1
     * @param int $id2
     */
    private function swap_order($id1, $id2): void {
        global $wpdb;
        $row1 = $wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$this->table_name} WHERE id = %d", $id1));
        $row2 = $wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$this->table_name} WHERE id = %d", $id2));
        
        if ($row1 && $row2) {
            // Log the current values before swapping
            mg_log("Before swap: ID {$id1} has order_id {$row1->order_id}, ID {$id2} has order_id {$row2->order_id}");
            
            // Perform the swap
            $wpdb->update($this->table_name, ['order_id' => $row2->order_id], ['id' => $id1]);
            $wpdb->update($this->table_name, ['order_id' => $row1->order_id], ['id' => $id2]);
            
            mg_log("After swap: ID {$id1} now has order_id {$row2->order_id}, ID {$id2} now has order_id {$row1->order_id}");
        } else {
            mg_log("Error: Could not swap order_id between ID {$id1} and ID {$id2}. One or both records not found.");
        }
    }

    public function handle_redirect(): void {
        try {
            if (!isset($_GET['on'])) {
                return;
            }
            
            mg_log('Handling redirect');
            
            global $wpdb;
            
            $shortcode = sanitize_text_field($_GET['on']);
            
            // Get all redirects ordered by order_id (not by ID)
            $redirects = $wpdb->get_results(
                "SELECT id, shortcode, target_url, order_id FROM {$this->table_name} ORDER BY order_id ASC"
            );
            
            if (!$redirects) {
                return;
            }
            
            // Find the current redirect's position
            $current_position = -1;
            foreach ($redirects as $index => $redirect) {
                if ($redirect->shortcode === $shortcode) {
                    $current_position = $index;
                    break;
                }
            }
            
            if ($current_position === -1) {
                return;
            }
            
            // Calculate the next position
            $next_position = ($current_position + 1) % count($redirects);
            
            // Get the target URL for redirect
            $target_url = $redirects[$next_position]->target_url;
            
            mg_log("Redirect sequence: {$redirects[$current_position]->shortcode} (order_id: {$redirects[$current_position]->order_id}) -> {$redirects[$next_position]->shortcode} (order_id: {$redirects[$next_position]->order_id})");
            
            if ($target_url) {
                mg_log('Redirecting to next URL: ' . $target_url);
                wp_redirect(esc_url($target_url));
                exit;
            }
            
        } catch (Exception $e) {
            mg_log('Redirect handling error: ' . $e->getMessage());
        }
    }

    private function get_ghost_url(string $shortcode): string {
        return home_url("/rock?on={$shortcode}");
    }

    public function modify_ghost_permalink($permalink, $post): string {
        if ($post->post_type === $this->post_type) {
            global $wpdb;
            
            $redirect = $wpdb->get_row($wpdb->prepare(
                "SELECT target_url FROM {$this->table_name} WHERE post_id = %d",
                $post->ID
            ));
            
            if ($redirect) {
                return $redirect->target_url;
            }
        }
        return $permalink;
    }
    
    public function modify_ghost_links($url): string {
        global $post;
        
        if (is_object($post) && $post->post_type === $this->post_type) {
            return $this->modify_ghost_permalink($url, $post);
        }
        return $url;
    }
}
// Initialize plugin
try {
    MozartsGhostRedirector::getInstance();
} catch (Exception $e) {
    mg_log('Plugin initialization error: ' . $e->getMessage());
}