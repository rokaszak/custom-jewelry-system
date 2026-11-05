<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_DB {

    const TABLES = [
        'units',
        'categories',
        'fields',
        'field_choices',
        'field_units',
        'items',
        'item_values',
        'batches',
        'pieces',
        'demands',
        'allocations',
        'movements',
        'documents',
        'stock_orders',
        'stock_order_lines',
        'demand_links',
        'discrepancies',
        'migration_map',
    ];

    const SCHEMA_VERSION = 10;
    const SCHEMA_OPTION = 'cjs_inv_schema_version';
    const BACKOFF_OPTION = 'cjs_inv_schema_backoff';
    const BACKOFF_SECONDS = 3600;

    private static $transaction_depth = 0;
    private static $columns = [];

    public static function t($name) {
        global $wpdb;
        return $wpdb->prefix . 'cjs_inv_' . $name;
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'cjs_inv_';

        $sql = [];

        $sql[] = "CREATE TABLE {$p}units (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            label varchar(20) NOT NULL,
            name varchar(100) NOT NULL DEFAULT '',
            decimals tinyint(3) unsigned NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY label (label)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}categories (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            returnable tinyint(1) NOT NULL DEFAULT 0,
            per_piece tinyint(1) NOT NULL DEFAULT 0,
            auto_assign tinyint(1) NOT NULL DEFAULT 1,
            used_by_status varchar(30) NOT NULL DEFAULT 'done',
            back_by_status varchar(30) NOT NULL DEFAULT 'done',
            has_fineness tinyint(1) NOT NULL DEFAULT 0,
            default_fineness decimal(7,2) NOT NULL DEFAULT 999,
            assign_woo_statuses varchar(255) NOT NULL DEFAULT '',
            checkout_offer longtext,
            sort_order int(11) NOT NULL DEFAULT 0,
            archived tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY archived_sort (archived, sort_order)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}fields (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            type varchar(10) NOT NULL DEFAULT 'select',
            in_name tinyint(1) NOT NULL DEFAULT 1,
            is_size tinyint(1) NOT NULL DEFAULT 0,
            required tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY category_slug (category_id, slug),
            KEY category_sort (category_id, sort_order)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}field_choices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            field_id bigint(20) unsigned NOT NULL,
            label varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            archived tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY field_slug (field_id, slug),
            KEY field_sort (field_id, sort_order)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}field_units (
            field_id bigint(20) unsigned NOT NULL,
            unit_id bigint(20) unsigned NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (field_id, unit_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            name_custom tinyint(1) NOT NULL DEFAULT 0,
            code varchar(64) DEFAULT NULL,
            unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            returnable tinyint(1) NOT NULL DEFAULT 0,
            per_piece tinyint(1) NOT NULL DEFAULT 0,
            default_cost decimal(14,4) DEFAULT NULL,
            default_price decimal(14,2) DEFAULT NULL,
            reorder_level decimal(14,4) DEFAULT NULL,
            praba decimal(7,2) DEFAULT NULL,
            metal varchar(191) DEFAULT NULL,
            qty_on_hand decimal(14,4) NOT NULL DEFAULT 0,
            qty_reserved decimal(14,4) NOT NULL DEFAULT 0,
            signature char(40) NOT NULL,
            archived tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY category_signature (category_id, signature),
            KEY category_archived (category_id, archived),
            KEY code (code),
            KEY name (name(191)),
            KEY category_metal (category_id,metal)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}item_values (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL,
            field_id bigint(20) unsigned NOT NULL,
            choice_id bigint(20) unsigned DEFAULT NULL,
            value_number decimal(14,4) DEFAULT NULL,
            unit_id bigint(20) unsigned DEFAULT NULL,
            value_text varchar(255) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY item_field (item_id, field_id),
            KEY field_choice (field_id, choice_id, item_id),
            KEY field_number (field_id, unit_id, value_number, item_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}batches (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL,
            unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            qty_received decimal(14,4) NOT NULL DEFAULT 0,
            qty_on_hand decimal(14,4) NOT NULL DEFAULT 0,
            qty_reserved decimal(14,4) NOT NULL DEFAULT 0,
            unit_cost decimal(14,4) DEFAULT NULL,
            fineness decimal(7,2) DEFAULT NULL,
            source_type varchar(20) NOT NULL DEFAULT 'receipt',
            source_id bigint(20) unsigned DEFAULT NULL,
            document_id bigint(20) unsigned DEFAULT NULL,
            received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY item_fifo (item_id, qty_on_hand, received_at),
            KEY source (source_type, source_id),
            KEY document_id (document_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}pieces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            identifier varchar(100) DEFAULT NULL,
            status varchar(10) NOT NULL DEFAULT 'in',
            allocation_id bigint(20) unsigned DEFAULT NULL,
            note varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY item_identifier (item_id, identifier),
            KEY batch_id (batch_id),
            KEY item_status (item_id, status)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}demands (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned DEFAULT NULL,
            item_id bigint(20) unsigned NOT NULL,
            category_id bigint(20) unsigned DEFAULT NULL,
            metal varchar(191) DEFAULT NULL,
            unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            qty decimal(14,4) NOT NULL DEFAULT 0,
            qty_allocated decimal(14,4) NOT NULL DEFAULT 0,
            qty_fineness decimal(7,2) DEFAULT NULL,
            state varchar(10) NOT NULL DEFAULT 'open',
            source varchar(20) NOT NULL DEFAULT 'manual',
            source_ref varchar(100) DEFAULT NULL,
            sale_price decimal(14,2) DEFAULT NULL,
            note text,
            po_line_id bigint(20) unsigned DEFAULT NULL,
            cancelled_by_order tinyint(1) NOT NULL DEFAULT 0,
            ended_as varchar(20) DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY state_item (state, item_id),
            KEY category_metal (category_id,metal),
            KEY po_line_id (po_line_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}allocations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            demand_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            piece_id bigint(20) unsigned DEFAULT NULL,
            qty decimal(14,4) NOT NULL DEFAULT 0,
            qty_fine decimal(14,4) DEFAULT NULL,
            state varchar(10) NOT NULL DEFAULT 'reserved',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY demand_id (demand_id),
            KEY batch_state (batch_id, state),
            KEY piece_state (piece_id, state)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}movements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned DEFAULT NULL,
            piece_id bigint(20) unsigned DEFAULT NULL,
            qty decimal(14,4) NOT NULL DEFAULT 0,
            unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
            unit_cost decimal(14,4) DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            demand_id bigint(20) unsigned DEFAULT NULL,
            allocation_id bigint(20) unsigned DEFAULT NULL,
            document_id bigint(20) unsigned DEFAULT NULL,
            stock_order_id bigint(20) unsigned DEFAULT NULL,
            note varchar(255) DEFAULT NULL,
            link_mark varchar(20) DEFAULT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY item_created (item_id, created_at),
            KEY batch_id (batch_id),
            KEY piece_id (piece_id),
            KEY order_id (order_id),
            KEY document_id (document_id),
            KEY stock_order_id (stock_order_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}documents (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            note text,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type_created (type, created_at)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}stock_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            number varchar(100) NOT NULL DEFAULT '',
            order_date date NOT NULL,
            status varchar(50) NOT NULL DEFAULT '',
            supplier varchar(191) DEFAULT NULL,
            note text,
            legacy_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY order_date (order_date),
            KEY number (number),
            KEY legacy_id (legacy_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}stock_order_lines (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stock_order_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            qty decimal(14,4) NOT NULL DEFAULT 0,
            qty_received decimal(14,4) NOT NULL DEFAULT 0,
            unit_cost decimal(14,4) DEFAULT NULL,
            fineness decimal(7,2) DEFAULT NULL,
            in_cart tinyint(1) NOT NULL DEFAULT 0,
            note varchar(255) DEFAULT NULL,
            planned_identifiers text,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY stock_order_id (stock_order_id),
            KEY item_id (item_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}demand_links (
            demand_id bigint(20) unsigned NOT NULL,
            po_line_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (demand_id,po_line_id),
            KEY po_line_id (po_line_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}discrepancies (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(30) NOT NULL,
            item_id bigint(20) unsigned DEFAULT NULL,
            batch_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            demand_id bigint(20) unsigned DEFAULT NULL,
            qty decimal(14,4) DEFAULT NULL,
            details text,
            resolved tinyint(1) NOT NULL DEFAULT 0,
            resolved_by bigint(20) unsigned DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            resolution varchar(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY resolved_created (resolved, created_at),
            KEY item_id (item_id),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $c;";

        $sql[] = "CREATE TABLE {$p}migration_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            legacy_type varchar(30) NOT NULL,
            legacy_id bigint(20) unsigned NOT NULL,
            new_type varchar(30) NOT NULL,
            new_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY legacy_new (legacy_type, legacy_id, new_type),
            KEY new_ref (new_type, new_id)
        ) ENGINE=InnoDB $c;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        self::$columns = [];

        return self::missing_tables();
    }

    public static function maybe_update_schema($force = false) {
        if ((int) get_option(self::SCHEMA_OPTION, 0) >= self::SCHEMA_VERSION) {
            return;
        }
        if ($force) {
            delete_option(self::BACKOFF_OPTION);
        } elseif (self::backoff_active()) {
            return;
        }
        try {
            $missing = array_merge(self::create_tables(), self::missing_columns());
            if (!$missing) {
                update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
                delete_option(self::BACKOFF_OPTION);
            } else {
                self::start_backoff($missing);
            }
        } catch (Throwable $e) {
            self::start_backoff([$e->getMessage()]);
        }
    }

    public static function backoff_active() {
        $at = (int) get_option(self::BACKOFF_OPTION, 0);
        return $at > 0 && time() - $at < self::BACKOFF_SECONDS && time() >= $at;
    }

    public static function start_backoff(array $missing) {
        if (self::backoff_active()) {
            return;
        }
        update_option(self::BACKOFF_OPTION, time(), true);
        error_log('CJS inventory schema update incomplete (' . implode(', ', array_map('strval', $missing)) . '); it is retried in one hour.');
        if (class_exists('CJS_Inv_Migration') && method_exists('CJS_Inv_Migration', 'note_backoff')) {
            try {
                CJS_Inv_Migration::note_backoff($missing);
            } catch (Throwable $e) {
                error_log('CJS back-off note failed: ' . $e->getMessage());
            }
        }
    }

    public static function in_transaction() {
        return self::$transaction_depth > 0;
    }

    public static function missing_columns(array $only = []) {
        global $wpdb;
        $need = ['items' => ['praba', 'metal'], 'demands' => ['category_id', 'metal'], 'movements' => ['link_mark'], 'stock_order_lines' => ['planned_identifiers']];
        if ($only) {
            $need = array_intersect_key($need, array_flip($only));
        }
        $missing = [];
        foreach ($need as $table => $columns) {
            $have = $wpdb->get_col('SHOW COLUMNS FROM ' . self::t($table)) ?: [];
            foreach ($columns as $column) {
                if (!in_array($column, $have, true)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        return $missing;
    }

    public static function has_column($table, $column) {
        global $wpdb;
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columns)) {
            $suppress = $wpdb->suppress_errors(true);
            try {
                self::$columns[$key] = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . self::t($table) . ' LIKE %s', $column));
            } catch (Throwable $e) {
                self::$columns[$key] = false;
            }
            $wpdb->suppress_errors($suppress);
        }
        return self::$columns[$key];
    }

    public static function missing_tables() {
        global $wpdb;
        $missing = [];
        foreach (self::TABLES as $name) {
            $table = self::t($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    public static function transaction(callable $callback) {
        global $wpdb;
        $outer = self::$transaction_depth === 0;
        if ($outer) {
            $wpdb->query('START TRANSACTION');
        }
        self::$transaction_depth++;
        try {
            $result = $callback();
            self::$transaction_depth--;
            if ($outer) {
                $wpdb->query('COMMIT');
            }
            return $result;
        } catch (Throwable $e) {
            self::$transaction_depth--;
            if ($outer) {
                $wpdb->query('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function now() {
        return current_time('mysql');
    }

    public static function num($value) {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 4);
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        $s = str_replace([' ', "\u{00A0}"], '', trim((string) $value));
        if ($s === '') {
            return null;
        }
        if (strpos($s, ',') !== false && strpos($s, '.') === false && substr_count($s, ',') === 1) {
            $s = str_replace(',', '.', $s);
        }
        if (!is_numeric($s)) {
            return null;
        }
        return round((float) $s, 4);
    }

    public static function fmt($value) {
        if ($value === null || $value === '') {
            return '';
        }
        $s = number_format((float) $value, 4, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    public static function insert($table, array $data) {
        global $wpdb;
        $ok = $wpdb->insert(self::t($table), $data);
        if ($ok === false) {
            throw new RuntimeException('DB insert failed on ' . $table . ': ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    public static function update($table, array $data, array $where) {
        global $wpdb;
        $ok = $wpdb->update(self::t($table), $data, $where);
        if ($ok === false) {
            throw new RuntimeException('DB update failed on ' . $table . ': ' . $wpdb->last_error);
        }
        return $ok;
    }

    public static function query($sql) {
        global $wpdb;
        $ok = $wpdb->query($sql);
        if ($ok === false) {
            throw new RuntimeException('DB query failed: ' . $wpdb->last_error);
        }
        return $ok;
    }

    public static function ids_in(array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        return $ids ? implode(',', $ids) : '0';
    }
}
