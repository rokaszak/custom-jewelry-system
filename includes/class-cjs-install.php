<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Install {

    public static function activate() {
        self::install();
    }

    public static function install() {
        self::create_tables();
        if (!CJS_Inv_DB::create_tables() && !CJS_Inv_DB::missing_columns()) {
            update_option(CJS_Inv_DB::SCHEMA_OPTION, CJS_Inv_DB::SCHEMA_VERSION, true);
        }
        CJS_Inv_Seed::run();
        self::create_upload_directory();
        self::create_default_options();
        if (class_exists('CJS_Inv_Stock_Orders')) {
            CJS_Inv_Stock_Orders::ensure_model();
        }
        if (method_exists('CJS_Inv_Discrepancies', 'ensure_scheduled')) {
            CJS_Inv_Discrepancies::ensure_scheduled();
        }
        if (class_exists('WooCommerce') && method_exists('CJS_Inv_Migration', 'upgrade_model')) {
            CJS_Inv_Migration::upgrade_model();
            CJS_Inv_Migration::fix_status_colours();
        }
        self::upgrade_statuses();
        $missing = self::check_database();
        if ($missing) {
            update_option('cjs_missing_tables', $missing, false);
        } else {
            delete_option('cjs_missing_tables');
        }
        update_option('cjs_db_version', CJS_VERSION);
        delete_option('cjs_install_error');
    }

    public static function maybe_upgrade() {
        CJS_Inv_DB::maybe_update_schema();
        if (method_exists('CJS_Inv_Migration', 'convert_fine_lines_early')) {
            CJS_Inv_Migration::convert_fine_lines_early();
        }
        $stored = (string) get_option('cjs_db_version', '0');
        if (version_compare($stored, CJS_VERSION, '<') && !get_transient('cjs_installing')) {
            set_transient('cjs_installing', 1, 2 * MINUTE_IN_SECONDS);
            try {
                self::install();
            } catch (Throwable $e) {
                error_log('CJS install failed: ' . $e->getMessage());
                update_option('cjs_install_error', $e->getMessage(), false);
            }
            delete_transient('cjs_installing');
        }
        self::upgrade_statuses();
    }

    public static function upgrade_statuses() {
        try {
            CJS_Statuses::maybe_upgrade();
            CJS_Size_Kit::maybe_move_settings();
        } catch (Throwable $e) {
            error_log('CJS status upgrade failed: ' . $e->getMessage());
        }
    }

    public static function maybe_migrate() {
        if (!current_user_can('manage_options') || wp_doing_ajax()) {
            return;
        }
        if (CJS_Inv_Migration::is_done()) {
            if (method_exists('CJS_Inv_Migration', 'model_needs_upgrade') && CJS_Inv_Migration::model_needs_upgrade()) {
                CJS_Inv_Migration::upgrade_model();
                self::upgrade_statuses();
            }
            return;
        }
        if (!did_action('woocommerce_after_register_post_type') || (function_exists('wc_get_order_type') && !wc_get_order_type('shop_order'))) {
            return;
        }
        CJS_Inv_Migration::run();
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}cjs_order_extensions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            manufacture_by_date date DEFAULT NULL,
            finish_by_date date DEFAULT NULL,
            deliver_by_date date DEFAULT NULL,
            order_model tinyint(1) DEFAULT 0,
            order_production tinyint(1) DEFAULT 0,
            casting_notes text,
            order_printing tinyint(1) DEFAULT 0,
            manufacturing_status varchar(100) DEFAULT NULL,
            order_type varchar(100) DEFAULT 'Įprastas',
            assigned_hours decimal(6,2) DEFAULT NULL,
            completed_hours decimal(6,2) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY deliver_by_date (deliver_by_date),
            KEY manufacturing_status (manufacturing_status)
        ) $c;");

        dbDelta("CREATE TABLE {$p}cjs_order_files (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_type varchar(100) DEFAULT NULL,
            file_size bigint(20) DEFAULT NULL,
            thumbnail_path varchar(500) DEFAULT NULL,
            custom_comment text,
            uploaded_by bigint(20) unsigned NOT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY uploaded_by (uploaded_by)
        ) $c;");

        dbDelta("CREATE TABLE {$p}cjs_activity_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            details text,
            severity varchar(20) DEFAULT 'info',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY object_type (object_type),
            KEY severity (severity)
        ) $c;");

        dbDelta("CREATE TABLE {$p}cjs_calendar_intervals (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            resource_id bigint(20) unsigned NOT NULL DEFAULT 1,
            order_id bigint(20) unsigned DEFAULT NULL,
            type varchar(50) NOT NULL DEFAULT 'work',
            name varchar(255) NOT NULL DEFAULT '',
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            is_locked tinyint(1) NOT NULL DEFAULT 0,
            is_done tinyint(1) NOT NULL DEFAULT 0,
            color varchar(7) DEFAULT NULL,
            deadline_date date DEFAULT NULL,
            order_finish datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY resource_id (resource_id),
            KEY order_id (order_id),
            KEY type (type),
            KEY start_datetime (start_datetime),
            KEY end_datetime (end_datetime)
        ) $c;");

        dbDelta("CREATE TABLE {$p}cjs_options_sort_order (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            option_type varchar(100) NOT NULL,
            option_value varchar(191) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY type_value (option_type, option_value),
            KEY option_type (option_type)
        ) $c;");

        CJS_Statuses::create_table();

        $ext = $p . 'cjs_order_extensions';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $ext");
        $adds = [
            'order_production' => "ADD COLUMN order_production tinyint(1) DEFAULT 0 AFTER order_model",
            'order_type' => "ADD COLUMN order_type varchar(100) DEFAULT 'Įprastas' AFTER manufacturing_status",
            'manufacture_by_date' => 'ADD COLUMN manufacture_by_date date DEFAULT NULL AFTER order_id',
            'assigned_hours' => 'ADD COLUMN assigned_hours decimal(6,2) DEFAULT NULL AFTER order_type',
            'completed_hours' => 'ADD COLUMN completed_hours decimal(6,2) NOT NULL DEFAULT 0 AFTER assigned_hours',
        ];
        foreach ($adds as $col => $sql) {
            if ($columns && !in_array($col, $columns, true)) {
                $wpdb->query("ALTER TABLE $ext $sql");
            }
        }
    }

    private static function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/cjs-uploads';
        if (!file_exists($dir) && wp_mkdir_p($dir)) {
            file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\n");
            file_put_contents($dir . '/index.php', "<?php\n");
        }
    }

    private static function create_default_options() {
        $defaults = [
            'cjs_manufacturing_statuses' => ['Atspausdinti', 'Įduoti lieti', 'Pagaminti', 'Išvežti prabuoti', 'Užprabuoti', 'DONE'],
            'cjs_order_types' => ['Įprastas', 'Vestuvinis'],
            'cjs_interval_types' => [
                'work' => ['label' => 'Darbas', 'color' => '#4285f4'],
                'atostogos' => ['label' => 'Atostogos', 'color' => '#e67c73'],
                'asmeninis' => ['label' => 'Asmeninis', 'color' => '#f6bf26'],
                'kita' => ['label' => 'Kita', 'color' => '#616161'],
            ],
        ];
        foreach ($defaults as $name => $value) {
            if (get_option($name, null) === null) {
                add_option($name, $value);
            }
        }
        if (get_option('cjs_calendar_settings', null) === null && class_exists('CJS_Scheduler')) {
            add_option('cjs_calendar_settings', ['1' => CJS_Scheduler::defaults()]);
        }
        if (get_option(CJS_Inv_Stock_Orders::OPTION, null) === null) {
            $legacy = get_option('cjs_stone_order_statuses', null);
            add_option(CJS_Inv_Stock_Orders::OPTION, is_array($legacy) && $legacy ? $legacy : CJS_Inv_Stock_Orders::default_statuses());
        }
    }

    public static function deactivate() {
        delete_option('cjs_plugin_activated');
        if (class_exists('CJS_Inv_Discrepancies')) {
            wp_clear_scheduled_hook(CJS_Inv_Discrepancies::CRON_HOOK);
        }
    }

    public static function check_database() {
        global $wpdb;
        $tables = ['cjs_order_extensions', 'cjs_order_files', 'cjs_activity_log', 'cjs_calendar_intervals', 'cjs_options_sort_order', 'cjs_order_status_times'];
        $missing = [];
        foreach ($tables as $table) {
            $name = $wpdb->prefix . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                $missing[] = $table;
            }
        }
        return array_merge($missing, CJS_Inv_DB::missing_tables());
    }
}
