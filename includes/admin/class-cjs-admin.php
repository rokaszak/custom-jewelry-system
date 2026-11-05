<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin {

    const CAP = 'manage_options';

    const PAGES = [
        'cjs-orders-list' => ['Orders', 'orders'],
        'cjs-inventory' => ['Stock', 'inventory'],
        'cjs-required' => ['Missing Stock', 'required'],
        'cjs-stock-orders' => ['Stock Orders', 'stock-orders'],
        'cjs-calendar' => ['Manufacturing Calendar', 'calendar'],
        'cjs-orders-calendar' => ['Order Calendar', 'timeline'],
        'cjs-discrepancies' => ['Discrepancies', 'discrepancies'],
        'cjs-settings' => ['Settings and Logs', 'settings'],
    ];

    const LEGACY_REDIRECTS = [
        'cjs-required-stones' => 'cjs-required',
        'cjs-stone-orders' => 'cjs-stock-orders',
        'custom-jewelry-system' => 'cjs-orders-list',
    ];

    private static $hooks = [];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'legacy_redirects']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes'], 10, 2);
        add_filter('admin_body_class', [__CLASS__, 'body_class']);
        add_filter('script_loader_tag', [__CLASS__, 'module_tag'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function legacy_redirects() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page && isset(self::LEGACY_REDIRECTS[$page]) && current_user_can(self::CAP)) {
            $args = map_deep(wp_unslash($_GET), 'sanitize_text_field');
            $args['page'] = self::LEGACY_REDIRECTS[$page];
            if (!empty($args['stone_order_id'])) {
                global $wpdb;
                $so = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE legacy_id = %d', absint($args['stone_order_id'])));
                if ($so) {
                    $args['so'] = (int) $so;
                }
                unset($args['stone_order_id']);
            }
            if (!empty($args['highlight_order'])) {
                $args['order'] = absint($args['highlight_order']);
                unset($args['highlight_order']);
            }
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }
    }

    private static function bubble($count, $submenu = false) {
        $count = (int) $count;
        if ($count <= 0) {
            return '';
        }
        $class = $submenu ? 'menu-counter' : 'awaiting-mod update-plugins';
        return ' <span class="' . $class . ' count-' . $count . '"><span class="pending-count">' . number_format_i18n($count) . '</span></span>';
    }

    public static function menu() {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $required = 0;
        $discrepancies = 0;
        try {
            $required = CJS_Inv_Demands::missing_count();
            $discrepancies = CJS_Inv_Discrepancies::open_count();
        } catch (Throwable $e) {
            $required = 0;
            $discrepancies = 0;
        }
        add_menu_page(
            __('Jewelry', 'custom-jewelry-system'),
            __('Jewelry', 'custom-jewelry-system') . self::bubble($required + $discrepancies),
            self::CAP,
            'cjs-orders-list',
            [__CLASS__, 'render'],
            'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xNiw5SDE5TDE0LDE2TTEwLDlIMTRMMTIsMTdNNSw5SDhMMTAsMTZNMTUsNEgxN0wxOSw3SDE2TTExLDRIMTNMMTQsN0gxME03LDRIOUw4LDdINU02LDJMMiw4TDEyLDIyTDIyLDhMMTgsMkg2WiIvPjwvc3ZnPg==',
            56
        );
        foreach (self::PAGES as $slug => $page) {
            $label = __($page[0], 'custom-jewelry-system');
            if ($slug === 'cjs-required') {
                $label .= self::bubble($required, true);
            } elseif ($slug === 'cjs-discrepancies') {
                $label .= self::bubble($discrepancies, true);
            }
            self::$hooks[$slug] = add_submenu_page('cjs-orders-list', __($page[0], 'custom-jewelry-system'), $label, self::CAP, $slug, [__CLASS__, 'render']);
        }
        self::$hooks['cjs-migration-debug'] = add_submenu_page('cjs-orders-list', __('Migration and Debug', 'custom-jewelry-system'), __('Migration and Debug', 'custom-jewelry-system'), self::CAP, 'cjs-migration-debug', ['CJS_Debug', 'render_page']);
    }

    public static function current_view() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return isset(self::PAGES[$page]) ? self::PAGES[$page][1] : null;
    }

    public static function render() {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $view = self::current_view() ?: 'orders';
        echo '<div class="cjs-shell"><div id="cjs-root" class="cjs-app cjs-scope light" data-theme="light" data-view="' . esc_attr($view) . '"><div class="cjs-boot-loading"><span></span></div></div></div>';
    }

    public static function is_order_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }
        $ids = ['shop_order', 'woocommerce_page_wc-orders'];
        if (function_exists('wc_get_page_screen_id')) {
            $ids[] = wc_get_page_screen_id('shop-order');
        }
        if (!in_array($screen->id, $ids, true)) {
            return false;
        }
        if ($screen->id === 'woocommerce_page_wc-orders') {
            return isset($_GET['action']) && in_array($_GET['action'], ['edit', 'new'], true);
        }
        return $screen->base === 'post';
    }

    public static function is_orders_list_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }
        if ($screen->id === 'edit-shop_order') {
            return true;
        }
        if ($screen->id === 'woocommerce_page_wc-orders') {
            return !isset($_GET['action']) || !in_array($_GET['action'], ['edit', 'new'], true);
        }
        return false;
    }

    public static function body_class($classes) {
        if (self::current_view()) {
            $classes .= ' cjs-page cjs-view-' . self::current_view();
        }
        if (self::is_order_screen()) {
            $classes .= ' cjs-order-screen';
        }
        return $classes;
    }

    public static function meta_boxes($post_type_or_screen_id, $post_or_order = null) {
        $ids = ['shop_order', 'woocommerce_page_wc-orders'];
        if (function_exists('wc_get_page_screen_id')) {
            $ids[] = wc_get_page_screen_id('shop-order');
        }
        if (!in_array($post_type_or_screen_id, $ids, true) || !current_user_can(self::CAP)) {
            return;
        }
        add_meta_box('cjs_order_panel', __('Jewelry System', 'custom-jewelry-system'), [__CLASS__, 'render_order_box'], $post_type_or_screen_id, 'normal', 'high');
    }

    public static function render_order_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        $order_id = $order ? $order->get_id() : 0;
        echo '<div id="cjs-order-root" class="cjs-app cjs-scope light" data-theme="light" data-view="order" data-order-id="' . esc_attr((string) $order_id) . '"><div class="cjs-boot-loading"><span></span></div></div>';
    }

    public static function enqueue($hook) {
        $view = self::current_view();
        $order_screen = self::is_order_screen();
        $orders_list = !$view && !$order_screen && current_user_can(self::CAP) && self::is_orders_list_screen();
        if (!$view && !$order_screen && !$orders_list) {
            return;
        }
        $entry = self::entry_file();
        $css = self::css_file();
        if ($entry === '') {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__('Custom Jewelry System: the interface build is missing (build/manifest.json). Please reinstall the plugin zip.', 'custom-jewelry-system') . '</p></div>';
            });
            return;
        }
        if ($css !== '') {
            wp_enqueue_style('cjs-app', CJS_PLUGIN_URL . 'build/' . $css, [], $css === 'app.css' ? (string) filemtime(CJS_PLUGIN_DIR . 'build/app.css') : null);
        }
        wp_enqueue_script('cjs-app', CJS_PLUGIN_URL . 'build/' . $entry, [], null, true);
        if ($view && function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }
        $boot = [
            'rest' => esc_url_raw(rest_url(CJS_API::NS . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajax' => admin_url('admin-ajax.php'),
            'admin' => admin_url(),
            'pluginUrl' => CJS_PLUGIN_URL,
            'version' => CJS_VERSION,
            'view' => $view ?: ($orders_list ? 'woo-orders' : 'order'),
            'pages' => array_map(function ($p) { return $p[1]; }, self::PAGES),
            'locale' => get_user_locale(),
            'hpos' => CJS_Orders::hpos(),
        ];
        wp_add_inline_script('cjs-app', 'window.CJS_BOOT = ' . wp_json_encode($boot) . ';', 'before');
    }

    public static function css_file() {
        $manifest = CJS_PLUGIN_DIR . 'build/manifest.json';
        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            $files = [];
            if (is_array($data)) {
                $files = (array) ($data['src/main.jsx']['css'] ?? []);
                if (!empty($data['style.css']['file'])) {
                    $files[] = (string) $data['style.css']['file'];
                }
            }
            foreach ($files as $file) {
                $file = (string) $file;
                if ($file !== '' && strpos($file, '..') === false && substr($file, -4) === '.css' && file_exists(CJS_PLUGIN_DIR . 'build/' . $file)) {
                    return $file;
                }
            }
        }
        return file_exists(CJS_PLUGIN_DIR . 'build/app.css') ? 'app.css' : '';
    }

    public static function entry_file() {
        $manifest = CJS_PLUGIN_DIR . 'build/manifest.json';
        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            $file = is_array($data) ? (string) ($data['src/main.jsx']['file'] ?? '') : '';
            if ($file !== '' && strpos($file, '..') === false && file_exists(CJS_PLUGIN_DIR . 'build/' . $file)) {
                return $file;
            }
        }
        return file_exists(CJS_PLUGIN_DIR . 'build/app.js') ? 'app.js' : '';
    }

    public static function module_tag($tag, $handle, $src) {
        if ($handle !== 'cjs-app') {
            return $tag;
        }
        return preg_replace_callback('/<script\b([^>]*\bsrc=[^>]*)>/i', function ($m) {
            $attrs = preg_replace('/\stype=(["\'])[^"\']*\1/i', '', $m[1]);
            return '<script type="module"' . $attrs . '>';
        }, $tag);
    }

    public static function notices() {
        $missing = get_option('cjs_missing_tables', []);
        if (!empty($missing) && current_user_can(self::CAP)) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Custom Jewelry System:', 'custom-jewelry-system') . '</strong> ' . esc_html(sprintf(__('Some database tables are missing: %s.', 'custom-jewelry-system'), implode(', ', (array) $missing))) . '</p></div>';
        }
        if (self::current_view() && get_option(CJS_Inv_Migration::REPORT_OPTION)) {
            $report = get_option(CJS_Inv_Migration::REPORT_OPTION);
            if (is_array($report) && ($report['status'] ?? '') === 'failed') {
                echo '<div class="notice notice-error"><p>' . esc_html__('Custom Jewelry System: the 2.0 data migration failed. Open Jewelry → Migration and Debug for details.', 'custom-jewelry-system') . '</p></div>';
            }
        }
    }
}
