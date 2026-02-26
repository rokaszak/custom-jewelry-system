<?php
/**
 * Uninstall Custom Jewelry System
 * 
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the installer to handle cleanup
require_once plugin_dir_path(__FILE__) . 'includes/class-cjs-install.php';

// Run uninstall cleanup
CJS_Install::uninstall();