<?php
/**
 * Plugin Name: Custom Jewelry System
 * Plugin URI: https://Proven.lt/
 * Description: Advanced order management and stone tracking system for jewelers
 * Version: 1.2.0
 * Author: Rokas Zakarauskas
 * Text Domain: custom-jewelry-system
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CJS_VERSION', '1.0.0');
define('CJS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CJS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CJS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
function cjs_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . __('Custom Jewelry System requires WooCommerce to be installed and active.', 'custom-jewelry-system') . '</p></div>';
        });
        return false;
    }
    return true;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});


// Main plugin class
class CustomJewelrySystem {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Load dependencies first
        $this->load_dependencies();
        
        // Set up activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize the plugin
        add_action('plugins_loaded', [$this, 'init'], 20); // Later priority to ensure WC is loaded
        
        // Admin-only functionality
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('admin_notices', [$this, 'admin_notices']);
        }
    }
    
    private function load_dependencies() {
        // Core includes
        require_once CJS_PLUGIN_DIR . 'includes/class-cjs-install.php';
        require_once CJS_PLUGIN_DIR . 'includes/class-cjs-logger.php';
        
        // Database models
        require_once CJS_PLUGIN_DIR . 'includes/models/class-cjs-stone.php';
        require_once CJS_PLUGIN_DIR . 'includes/models/class-cjs-stone-order.php';
        require_once CJS_PLUGIN_DIR . 'includes/models/class-cjs-order-extension.php';
        
        // Admin includes
        require_once CJS_PLUGIN_DIR . 'includes/admin/class-cjs-admin-orders.php';
        require_once CJS_PLUGIN_DIR . 'includes/admin/class-cjs-admin-stones.php';
        require_once CJS_PLUGIN_DIR . 'includes/admin/class-cjs-admin-stone-orders.php';
        require_once CJS_PLUGIN_DIR . 'includes/admin/class-cjs-admin-settings.php';
        require_once CJS_PLUGIN_DIR . 'includes/class-cjs-modals.php';
        // API includes
        require_once CJS_PLUGIN_DIR . 'includes/api/class-cjs-rest-api.php';
        
        // File handler
        require_once CJS_PLUGIN_DIR . 'includes/class-cjs-file-handler.php';

        // Frontend includes
        require_once CJS_PLUGIN_DIR . 'includes/class-cjs-frontend.php';
    }
    
    public function init() {
        // Check if WooCommerce is active
        if (!cjs_check_woocommerce()) {
            return;
        }
        
        // Check database integrity
        $this->check_database_integrity();
        
        // Load text domain
        load_plugin_textdomain('custom-jewelry-system', false, dirname(CJS_PLUGIN_BASENAME) . '/languages');
        
        // Initialize components
        CJS_Order_Extension::init();
        CJS_REST_API::init();
        CJS_File_Handler::init();
        CJS_Frontend::init();
        
        // Add custom order statuses for stones
        $this->register_stone_order_statuses();
        
        // Mark as initialized
        update_option('cjs_initialized', true);
    }
    
    public function activate() {
        // Check dependencies before activation
        if (!cjs_check_woocommerce()) {
            wp_die(__('Custom Jewelry System requires WooCommerce to be installed and active.', 'custom-jewelry-system'));
        }
        
        // Run installation
        CJS_Install::activate();
        
        // Log activation
        if (class_exists('CJS_Logger')) {
            CJS_Logger::log('Plugin activated', 'success');
        }
        
        // Clear any caches
        wp_cache_flush();
        
        // Set activation flag for admin notice
        set_transient('cjs_activation_notice', true, 30);
    }
    
    public function deactivate() {
        CJS_Install::deactivate();
        if (class_exists('CJS_Logger')) {
            CJS_Logger::log('Plugin deactivated', 'info');
        }
        
        // Clear caches
        wp_cache_flush();
        delete_option('cjs_initialized');
    }
    
    public function check_database_integrity() {
        // Only check for admins to avoid performance issues
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $missing_tables = CJS_Install::check_database();
        
        if (!empty($missing_tables)) {
            // Store missing tables for admin notice
            update_option('cjs_missing_tables', $missing_tables);
            
            // Try to recreate tables automatically
            CJS_Install::activate();
            
            // Check again
            $missing_tables = CJS_Install::check_database();
            if (empty($missing_tables)) {
                delete_option('cjs_missing_tables');
            }
        } else {
            delete_option('cjs_missing_tables');
        }
        
        // Check for universal options sort order table and migrate if needed
        $this->migrate_options_sort_order();
    }
    
    /**
     * Migrate options to support universal ordering
     */
    private function migrate_options_sort_order() {
    }
    
    public function admin_notices() {
        // Activation notice
        if (get_transient('cjs_activation_notice')) {
            delete_transient('cjs_activation_notice');
            
            $missing_tables = get_option('cjs_missing_tables', []);
            if (empty($missing_tables)) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . __('Custom Jewelry System has been activated successfully!', 'custom-jewelry-system') . '</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>' . __('Custom Jewelry System activation encountered database issues. Please check the error log.', 'custom-jewelry-system') . '</p>';
                echo '</div>';
            }
        }
        
        // Missing tables notice
        $missing_tables = get_option('cjs_missing_tables', []);
        if (!empty($missing_tables)) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('Custom Jewelry System:', 'custom-jewelry-system') . '</strong> ';
            echo sprintf(__('Some database tables are missing: %s. Please deactivate and reactivate the plugin.', 'custom-jewelry-system'), implode(', ', $missing_tables));
            echo '</p>';
            echo '</div>';
        }
    }
    
    public function add_admin_menu() {
        // Only add menu if WooCommerce is active and user is admin
        if (!cjs_check_woocommerce() || !current_user_can('manage_options')) {
            return;
        }
        
        // Main menu
        add_menu_page(
            __('Custom Jewelry System', 'custom-jewelry-system'),
            __('Jewelry System', 'custom-jewelry-system'),
            'manage_options',
            'custom-jewelry-system',
            [$this, 'render_main_page'],
            'dashicons-hammer',
            56
        );
        
        // Submenu - Orders List
        add_submenu_page(
            'custom-jewelry-system',
            __('Orders List', 'custom-jewelry-system'),
            __('Užsakymų sąrašas', 'custom-jewelry-system'),
            'manage_options',
            'cjs-orders-list',
            [CJS_Admin_Orders::class, 'render_page']
        );
        
        // Submenu - Stone Orders
        add_submenu_page(
            'custom-jewelry-system',
            __('Stone Orders', 'custom-jewelry-system'),
            __('Akmenų užsakymai', 'custom-jewelry-system'),
            'manage_options',
            'cjs-stone-orders',
            [CJS_Admin_Stone_Orders::class, 'render_page']
        );
        
        // Submenu - Required Stones
        add_submenu_page(
            'custom-jewelry-system',
            __('Required Stones', 'custom-jewelry-system'),
            __('Reikalingi akmenys', 'custom-jewelry-system'),
            'manage_options',
            'cjs-required-stones',
            [CJS_Admin_Stones::class, 'render_page']
        );
        
        // Submenu - Settings & Log
        add_submenu_page(
            'custom-jewelry-system',
            __('Settings & Log', 'custom-jewelry-system'),
            __('Nustatymai ir žurnalas', 'custom-jewelry-system'),
            'manage_options',
            'cjs-settings',
            [CJS_Admin_Settings::class, 'render_page']
        );
        
        // Remove duplicate main menu item
        remove_submenu_page('custom-jewelry-system', 'custom-jewelry-system');
    }
    
    public function enqueue_admin_assets($hook) {
        // Load scripts on CJS pages
        $load_scripts = false;
        
        if (strpos($hook, 'cjs-') !== false || strpos($hook, 'custom-jewelry-system') !== false) {
            $load_scripts = true;
        }
        
        // Load scripts on WooCommerce order pages (legacy)
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            global $post_type;
            if ($post_type === 'shop_order') {
                $load_scripts = true;
            }
        }
        
        // Load scripts on WooCommerce order pages (HPOS)
        if ($hook === 'woocommerce_page_wc-orders') {
            $load_scripts = true;
        }
        
        // Additional HPOS check
        if (function_exists('wc_get_page_screen_id')) {
            $order_screen_id = wc_get_page_screen_id('shop-order');
            if ($hook === $order_screen_id) {
                $load_scripts = true;
            }
        }
        
        if (!$load_scripts) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'cjs-admin',
            CJS_PLUGIN_URL . 'assets/css/admin.css',
            ['wp-admin'],
            CJS_VERSION
        );
        
        wp_enqueue_style(
            'cjs-order-extension-autofill',
            plugin_dir_url(__FILE__) . 'assets/css/order-extension-autofill.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/order-extension-autofill.css')
        );
        


        // Enqueue scripts
        wp_enqueue_script(
            'cjs-admin',
            CJS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-api'],
            CJS_VERSION,
            true
        );
        
        wp_enqueue_script(
            'cjs-order-extension-autofill',
            plugin_dir_url(__FILE__) . 'assets/js/order-extension-autofill.js',
            array('jquery', 'cjs-admin'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/order-extension-autofill.js'),
            true
        );

        // Get upload limits for JavaScript
        $upload_limits = CJS_File_Handler::get_upload_limits();
        
        // Localize script
        wp_localize_script('cjs-admin', 'cjs_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'api_url' => home_url('/wp-json/cjs/v1/'), // Kept for compatibility but not used
            'nonce' => wp_create_nonce('wp_rest'),
            'file_upload_nonce' => wp_create_nonce('cjs_file_upload'),
            'file_delete_nonce' => wp_create_nonce('cjs_file_delete'),
            'upload_limits' => $upload_limits,
            'upload_limits_formatted' => [
                'upload_max_filesize' => CJS_File_Handler::format_bytes($upload_limits['upload_max_filesize']),
                'post_max_size' => CJS_File_Handler::format_bytes($upload_limits['post_max_size']),
                'effective_limit' => CJS_File_Handler::format_bytes($upload_limits['effective_limit']),
                'memory_limit' => CJS_File_Handler::format_bytes($upload_limits['memory_limit'])
            ],
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this?', 'custom-jewelry-system'),
                'saving' => __('Saving...', 'custom-jewelry-system'),
                'saved' => __('Saved!', 'custom-jewelry-system'),
                'error' => __('Error occurred', 'custom-jewelry-system'),
                'file_too_large' => __('File is too large. Maximum size is {max_size}. Your file is {file_size}.', 'custom-jewelry-system'),
                'select_file' => __('Please select a file', 'custom-jewelry-system'),
                'upload_success' => __('File uploaded successfully', 'custom-jewelry-system'),
                'upload_failed' => __('Upload failed', 'custom-jewelry-system'),
                'delete_success' => __('File deleted successfully', 'custom-jewelry-system'),
                'delete_failed' => __('File deletion failed', 'custom-jewelry-system'),
            ]
        ]);
        
        // Media uploader for file uploads
        if (in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            wp_enqueue_media();
        }
    }
    
    public function render_main_page() {
        // Redirect to orders list
        wp_redirect(admin_url('admin.php?page=cjs-orders-list'));
        exit;
    }
    
    private function register_stone_order_statuses() {
        // Stone order statuses are handled internally, not as WC order statuses
        // This could be expanded to register custom post statuses if needed
    }
}

// Initialize plugin when WordPress loads plugins
add_action('plugins_loaded', function() {
    CustomJewelrySystem::get_instance();
}, 10);

