<?php
/**
 * Plugin Name: Custom Jewelry System
 * Plugin URI: https://Proven.lt/
 * Description: Order production, inventory and stock management for jewelers
 * Version: 2.0.1
 * Author: Rokas Zakarauskas
 * Text Domain: custom-jewelry-system
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 * WC tested up to: 11.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CJS_VERSION', '2.0.1');
define('CJS_PLUGIN_FILE', __FILE__);
define('CJS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CJS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CJS_PLUGIN_BASENAME', plugin_basename(__FILE__));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

$cjs_files = [
    'includes/class-cjs-safe-mode.php',
    'includes/class-cjs-logger.php',
    'includes/inventory/class-cjs-inv-db.php',
    'includes/inventory/class-cjs-inv-catalog.php',
    'includes/inventory/class-cjs-inv-metals.php',
    'includes/inventory/class-cjs-inv-stock.php',
    'includes/inventory/class-cjs-inv-demands.php',
    'includes/inventory/class-cjs-inv-flow.php',
    'includes/inventory/class-cjs-inv-stock-orders.php',
    'includes/inventory/class-cjs-inv-discrepancies.php',
    'includes/inventory/class-cjs-inv-seed.php',
    'includes/inventory/class-cjs-inv-migration.php',
    'includes/class-cjs-install.php',
    'includes/models/class-cjs-order-extension.php',
    'includes/models/class-cjs-calendar-interval.php',
    'includes/class-cjs-deadlines.php',
    'includes/class-cjs-scheduler.php',
    'includes/class-cjs-orders.php',
    'includes/class-cjs-statuses.php',
    'includes/class-cjs-woo-sync.php',
    'includes/class-cjs-file-handler.php',
    'includes/class-cjs-files.php',
    'includes/class-cjs-settings.php',
    'includes/class-cjs-frontend.php',
    'includes/class-cjs-size-kit.php',
    'includes/class-cjs-checkout-deadline.php',
    'includes/class-cjs-order-type-assigner.php',
    'includes/api/class-cjs-api.php',
    'includes/api/class-cjs-calendar-api.php',
    'includes/admin/class-cjs-admin.php',
    'includes/admin/class-cjs-debug.php',
];
foreach ($cjs_files as $cjs_file) {
    require_once CJS_PLUGIN_DIR . $cjs_file;
}
unset($cjs_files, $cjs_file);

CJS_Safe_Mode::init();
add_action('admin_init', ['CJS_Safe_Mode', 'handle_post']);

register_activation_hook(__FILE__, function () {
    if (class_exists('WooCommerce')) {
        CJS_Install::activate();
    }
});

register_deactivation_hook(__FILE__, ['CJS_Install', 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('Custom Jewelry System requires WooCommerce to be installed and active.', 'custom-jewelry-system') . '</p></div>';
        });
        return;
    }
    load_plugin_textdomain('custom-jewelry-system', false, dirname(CJS_PLUGIN_BASENAME) . '/languages');
    CJS_Order_Extension::init();
    CJS_Inv_Demands::init();
    CJS_Woo_Sync::init();
    CJS_API::init();
    CJS_Calendar_API::init();
    CJS_Files::init();
    CJS_Frontend::init();
    CJS_Size_Kit::init();
    CJS_Checkout_Deadline::init();
    CJS_Order_Type_Assigner::init();
    if (is_admin()) {
        CJS_Admin::init();
    }
}, 20);

add_action('init', function () {
    if (class_exists('WooCommerce')) {
        CJS_Install::maybe_upgrade();
    }
}, 5);

add_action('admin_init', function () {
    if (class_exists('WooCommerce')) {
        CJS_Install::maybe_migrate();
        CJS_Install::maybe_clean_comments();
    }
}, 99);
