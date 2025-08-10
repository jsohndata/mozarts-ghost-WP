<?php
/**
 * Plugin Name: Mozart's Ghost Redirector
 * Description: Custom URL redirector with ghost URLs and shortcodes
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Your Name
 * License: GPL v2 or later
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
    // ...existing code...

            
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
    private string $db_version = '1.0.0';

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
                new_tab tinyint(1) DEFAULT 0,
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
        // Example: Add a column in future
        // $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN example_column varchar(100) DEFAULT '';");
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

            // ...existing code...

            mg_log('Admin menu added');
            
        } catch (Exception $e) {
            mg_log('Admin menu error: ' . $e->getMessage());
        }
    }

    public function display_redirects_page(): void {
        global $wpdb;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['add_redirect']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $this->create_redirect($_POST);
            } elseif (isset($_POST['delete_redirect']) && check_admin_referer('mozarts_ghost_action', 'mozarts_ghost_nonce')) {
                $this->delete_redirect((int)$_POST['redirect_id']);
            }
        }

        $redirects = $wpdb->get_results("SELECT * FROM {$this->table_name}");
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
                    <tr>
                        <th><label for="new_tab">Open in New Tab</label></th>
                        <td><input type="checkbox" name="new_tab" value="1" /></td>
                    </tr>
                </table>
                <input type="submit" name="add_redirect" class="button button-primary" value="Add Redirect" />
            </form>

            <h2>Existing Redirects</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Ghost URL</th>
                        <th>Target URL</th>
                        <th>New Tab</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $redirect): ?>
                        <tr>
                            <td><?php echo esc_html($redirect->shortcode); ?></td>
                            <td><?php echo esc_url($this->get_ghost_url($redirect->shortcode)); ?></td>
                            <td><?php echo esc_url($redirect->target_url); ?></td>
                            <td><?php echo $redirect->new_tab ? 'Yes' : 'No'; ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('mozarts_ghost_action', 'mozarts_ghost_nonce'); ?>
                                    <input type="hidden" name="redirect_id" value="<?php echo $redirect->id; ?>" />
                                    <input type="submit" name="delete_redirect" class="button button-small" value="Delete" 
                                           onclick="return confirm('Are you sure?');" />
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
            
            $result = $wpdb->insert(
                $this->table_name,
                [
                    'post_id' => $post_id,
                    'shortcode' => sanitize_text_field($data['shortcode']),
                    'target_url' => esc_url_raw($data['target_url']),
                    'new_tab' => isset($data['new_tab']) ? 1 : 0
                ],
                ['%d', '%s', '%s', '%d']
            );
            
            if ($result === false) {
                wp_delete_post($post_id, true);
                throw new Exception($wpdb->last_error);
            }
            
            mg_log('Redirect created');
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
            
            $redirect = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id FROM {$this->table_name} WHERE id = %d",
                $id
            ));
            
            if ($redirect) {
                wp_delete_post($redirect->post_id, true);
                $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
            }
            
            mg_log('Redirect deleted');
            return true;
            
        } catch (Exception $e) {
            mg_log('Delete redirect error: ' . $e->getMessage());
            return false;
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
            
            // First, get all redirects ordered by ID
            $redirects = $wpdb->get_results(
                "SELECT id, shortcode, target_url FROM {$this->table_name} ORDER BY id ASC"
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