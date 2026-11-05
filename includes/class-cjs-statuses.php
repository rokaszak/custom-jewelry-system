<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Statuses {

    const LABELS_OPTION = 'cjs_status_labels';
    const VERSION_OPTION = 'cjs_status_model_version';
    const LOG_OPTION = 'cjs_status_model_log';
    const VERSION = 7;

    private static $labels = null;
    private static $table_ok = null;
    private static $hours_conflicts = [];

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cjs_order_status_times';
    }

    public static function flush() {
        self::$labels = null;
    }

    public static function labels() {
        if (self::$labels === null) {
            $stored = get_option(self::LABELS_OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $out = [];
            foreach (CJS_Inv_Flow::STATUS_KEYS as $key) {
                $label = isset($stored[$key]) && is_scalar($stored[$key]) ? trim((string) $stored[$key]) : '';
                $out[$key] = $label !== '' ? $label : CJS_Inv_Flow::STATUS_LABELS[$key];
            }
            self::$labels = $out;
        }
        return self::$labels;
    }

    public static function label($key) {
        $labels = self::labels();
        return (string) ($labels[(string) $key] ?? '');
    }

    public static function label_list() {
        return array_values(self::labels());
    }

    public static function all() {
        $out = [];
        foreach (self::labels() as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'default_label' => CJS_Inv_Flow::STATUS_LABELS[$key]];
        }
        return $out;
    }

    public static function key_from_label($value) {
        $norm = self::normalize($value);
        if ($norm === '') {
            return null;
        }
        foreach (self::labels() as $key => $label) {
            if (self::normalize($label) === $norm) {
                return $key;
            }
        }
        return null;
    }

    public static function normalize($value) {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    public static function stored_key($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'nepradeta';
        }
        return CJS_Inv_Flow::status_key($raw);
    }

    public static function display($raw) {
        $key = self::stored_key($raw);
        return $key === null ? trim((string) $raw) : self::label($key);
    }

    public static function save_labels($input) {
        if (!is_array($input)) {
            return new WP_Error('cjs_invalid', __('Send a label for each status.', 'custom-jewelry-system'));
        }
        $map = [];
        foreach ($input as $k => $v) {
            if (is_array($v) && isset($v['key'])) {
                $map[(string) $v['key']] = $v['label'] ?? '';
            } elseif (is_string($k)) {
                $map[$k] = $v;
            }
        }
        $current = self::labels();
        $out = [];
        $seen = [];
        foreach (CJS_Inv_Flow::STATUS_KEYS as $key) {
            $label = array_key_exists($key, $map) && is_scalar($map[$key]) ? trim(sanitize_text_field((string) $map[$key])) : $current[$key];
            if ($label === '') {
                $label = CJS_Inv_Flow::STATUS_LABELS[$key];
            }
            if (mb_strlen($label) > 100) {
                return new WP_Error('cjs_invalid', __('A status name can be at most 100 characters long.', 'custom-jewelry-system'));
            }
            $norm = self::normalize($label);
            if ($norm === '') {
                return new WP_Error('cjs_invalid', sprintf(__('“%s” is not a usable status name.', 'custom-jewelry-system'), $label));
            }
            if (isset($seen[$norm])) {
                return new WP_Error('cjs_invalid', sprintf(__('Two statuses cannot both be called “%s”.', 'custom-jewelry-system'), $label));
            }
            $alias = CJS_Inv_Flow::STATUS_ALIASES[CJS_Inv_Flow::normalize($label)] ?? null;
            if ($alias !== null && $alias !== $key) {
                return new WP_Error('cjs_invalid', sprintf(__('“%s” is the name of another status.', 'custom-jewelry-system'), $label));
            }
            $seen[$norm] = true;
            $out[$key] = $label;
        }
        update_option(self::LABELS_OPTION, $out, true);
        self::flush();
        CJS_Inv_Flow::flush();
        CJS_Logger::log('Status names updated', 'info', 'settings', null, ['labels' => $out]);
        return true;
    }

    private static function table_ok() {
        if (self::$table_ok === null) {
            global $wpdb;
            self::$table_ok = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table())) === self::table();
        }
        return self::$table_ok;
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            order_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL,
            reached_at datetime NOT NULL,
            PRIMARY KEY  (order_id, status)
        ) $c;");
        self::$table_ok = null;
    }

    public static function write($order_id, $key) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($key !== null && CJS_Inv_Flow::rank($key) < 0) {
            return new WP_Error('cjs_invalid', __('Unknown status.', 'custom-jewelry-system'));
        }
        $before = CJS_Inv_Flow::order_status_key($order_id);
        CJS_Order_Extension::create_order_extension($order_id);
        $ok = $wpdb->update($wpdb->prefix . 'cjs_order_extensions', ['manufacturing_status' => $key, 'updated_at' => current_time('mysql')], ['order_id' => $order_id]);
        if ($ok === false) {
            return new WP_Error('cjs_db', $wpdb->last_error);
        }
        $after = $key ?? 'nepradeta';
        if ($after !== $before) {
            self::stamp($order_id, $after);
        }
        if (CJS_Inv_Flow::rank($after) >= CJS_Inv_Flow::rank('pagaminta')) {
            self::clear_calendar($order_id);
            self::fill_hours($order_id, $after);
        }
        return true;
    }

    public static function clear_calendar($order_id) {
        try {
            return class_exists('CJS_Calendar_Interval') ? CJS_Calendar_Interval::remove_open_entries((int) $order_id) : 0;
        } catch (Throwable $e) {
            error_log('CJS calendar cleanup failed for order ' . $order_id . ': ' . $e->getMessage());
            return 0;
        }
    }

    public static function fill_hours($order_id, $key) {
        global $wpdb;
        $e = $wpdb->prefix . 'cjs_order_extensions';
        $row = $wpdb->get_row($wpdb->prepare("SELECT assigned_hours, completed_hours FROM $e WHERE order_id = %d", (int) $order_id));
        if ($row && ($row->assigned_hours === null || $row->assigned_hours === '') && CJS_Inv_Flow::rank($key) >= CJS_Inv_Flow::rank('pagaminta')) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
            if ($order && method_exists('CJS_Order_Extension', 'calculate_default_hours')) {
                $default = round((float) CJS_Order_Extension::calculate_default_hours($order), 2);
                $wpdb->update($e, ['assigned_hours' => $default, 'updated_at' => current_time('mysql')], ['order_id' => (int) $order_id]);
                $row->assigned_hours = $default;
            }
        }
        if (!$row || $row->assigned_hours === null || (float) $row->assigned_hours <= 0) {
            return false;
        }
        $assigned = round((float) $row->assigned_hours, 2);
        $completed = (float) $row->completed_hours;
        if ($completed >= $assigned - 0.001) {
            return false;
        }
        $wpdb->update($e, ['completed_hours' => $assigned, 'updated_at' => current_time('mysql')], ['order_id' => (int) $order_id]);
        CJS_Logger::log('Completed hours filled to the assigned hours', 'info', 'order', (int) $order_id, ['status' => (string) $key, 'from' => $completed, 'to' => $assigned]);
        return true;
    }

    public static function stamp($order_id, $key) {
        global $wpdb;
        $rank = CJS_Inv_Flow::rank($key);
        if ($rank < 0 || !self::table_ok()) {
            return;
        }
        $later = array_slice(CJS_Inv_Flow::STATUS_KEYS, $rank + 1);
        if ($later) {
            $in = implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, $later));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE order_id = %d AND status IN ($in)", (int) $order_id));
        }
        $wpdb->query($wpdb->prepare('REPLACE INTO ' . self::table() . ' (order_id, status, reached_at) VALUES (%d, %s, %s)', (int) $order_id, $key, current_time('mysql')));
    }

    public static function stamp_first($order_id, $key) {
        global $wpdb;
        if ((int) $order_id <= 0 || CJS_Inv_Flow::rank($key) < 0 || !self::table_ok()) {
            return;
        }
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . ' (order_id, status, reached_at) VALUES (%d, %s, %s)', (int) $order_id, $key, current_time('mysql')));
    }

    public static function times_for(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = array_fill_keys(CJS_Inv_Flow::STATUS_KEYS, null);
        }
        if (!$ids || !self::table_ok()) {
            return $out;
        }
        $rows = $wpdb->get_results('SELECT order_id, status, reached_at FROM ' . self::table() . ' WHERE order_id IN (' . CJS_Inv_DB::ids_in($ids) . ')', ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            if (isset($out[$oid]) && array_key_exists($r['status'], $out[$oid])) {
                $out[$oid][$r['status']] = (string) $r['reached_at'];
            }
        }
        return $out;
    }

    public static function hours_message() {
        return sprintf(__('All hours are done, so the order becomes %s. This inventory has to be fixed first.', 'custom-jewelry-system'), self::label('pagaminta'));
    }

    public static function hours_snapshot($order_id) {
        global $wpdb;
        $h = $wpdb->get_row($wpdb->prepare("SELECT assigned_hours, completed_hours FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d", (int) $order_id));
        return ['assigned' => $h ? (float) $h->assigned_hours : 0.0, 'completed' => $h ? (float) $h->completed_hours : 0.0];
    }

    public static function hours_complete($assigned, $completed) {
        return (float) $assigned > 0 && (float) $completed >= (float) $assigned - 0.001;
    }

    public static function hours_advance($order_id, array $before) {
        global $wpdb;
        $order_id = (int) $order_id;
        $h = $wpdb->get_row($wpdb->prepare("SELECT assigned_hours, completed_hours, manufacturing_status FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d", $order_id));
        if (!$h || !self::hours_complete($h->assigned_hours, $h->completed_hours)) {
            return null;
        }
        if (self::hours_complete($before['assigned'] ?? 0, $before['completed'] ?? 0)) {
            return null;
        }
        if (!CJS_Order_Extension::can_auto_advance_status($h->manufacturing_status, 'pagaminta')) {
            return null;
        }
        if (class_exists('CJS_Woo_Sync') && CJS_Woo_Sync::locked($order_id)) {
            return null;
        }
        $r = CJS_Inv_Flow::apply([$order_id], 'pagaminta', [$order_id => []]);
        if (is_wp_error($r)) {
            if ($r->get_error_code() === 'cjs_stock_conflicts') {
                $data = $r->get_error_data();
                $conflict = ['order_id' => $order_id, 'from_hours' => true, 'message' => self::hours_message(), 'preview' => is_array($data) ? ($data['preview'] ?? null) : null];
                self::$hours_conflicts[$order_id] = $conflict;
                return ['conflict' => true] + $conflict;
            }
            error_log('CJS hours auto-advance failed for order ' . $order_id . ': ' . $r->get_error_message());
            return null;
        }
        CJS_Logger::log('Order auto-marked Pagaminta (visos valandos atliktos)', 'info', 'order', $order_id, ['completed_hours' => $h->completed_hours, 'assigned_hours' => $h->assigned_hours]);
        return ['advanced' => true];
    }

    public static function take_hours_conflicts() {
        $out = array_values(self::$hours_conflicts);
        self::$hours_conflicts = [];
        return $out;
    }

    public static function maybe_upgrade() {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
            return;
        }
        if (get_transient('cjs_status_upgrade_lock')) {
            return;
        }
        set_transient('cjs_status_upgrade_lock', 1, 5 * MINUTE_IN_SECONDS);
        $from = (int) get_option(self::VERSION_OPTION, 0);
        if ($from >= 4) {
            try {
                self::create_table();
                self::fill_open_hours();
                if (self::drop_hours()) {
                    update_option(self::VERSION_OPTION, self::VERSION, true);
                }
            } catch (Throwable $e) {
                error_log('CJS status upgrade failed: ' . $e->getMessage());
            }
            delete_transient('cjs_status_upgrade_lock');
            return;
        }
        $log = ['started_at' => current_time('mysql'), 'converted' => [], 'unknown' => [], 'completed_set_done' => 0, 'errors' => []];
        try {
            self::create_table();
            self::upgrade_labels();
            self::upgrade_orders($log);
            self::backfill_times($log);
            $log['open_hours_filled'] = self::fill_open_hours();
            self::drop_hours();
            update_option(self::VERSION_OPTION, self::VERSION, true);
            $log['status'] = 'done';
        } catch (Throwable $e) {
            $log['status'] = 'failed';
            $log['errors'][] = $e->getMessage();
            error_log('CJS status upgrade failed: ' . $e->getMessage());
        }
        $log['finished_at'] = current_time('mysql');
        update_option(self::LOG_OPTION, $log, false);
        delete_transient('cjs_status_upgrade_lock');
        if (class_exists('CJS_Logger')) {
            CJS_Logger::log('CJS statuses moved to fixed keys', $log['status'] === 'done' ? 'success' : 'error', 'settings', null, $log);
        }
    }

    public static function fill_open_hours() {
        global $wpdb;
        $e = $wpdb->prefix . 'cjs_order_extensions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $e)) !== $e) {
            return 0;
        }
        $keys = array_slice(CJS_Inv_Flow::STATUS_KEYS, CJS_Inv_Flow::rank('pagaminta'));
        $in = implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, $keys));
        $closed = "'wc-cancelled','wc-refunded','wc-failed','trash'";
        if (CJS_Orders::hpos()) {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = e.order_id SET e.completed_hours = e.assigned_hours WHERE o.type = 'shop_order' AND o.status NOT IN ($closed) AND e.manufacturing_status IN ($in) AND e.assigned_hours > 0 AND (e.completed_hours IS NULL OR e.completed_hours < e.assigned_hours)";
        } else {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->posts} o ON o.ID = e.order_id SET e.completed_hours = e.assigned_hours WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ($closed) AND e.manufacturing_status IN ($in) AND e.assigned_hours > 0 AND (e.completed_hours IS NULL OR e.completed_hours < e.assigned_hours)";
        }
        $n = $wpdb->query($sql);
        return $n === false ? 0 : (int) $n;
    }

    private static function drop_hours() {
        global $wpdb;
        delete_option('cjs_hours_shares');
        return $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cjs_order_hours') !== false;
    }

    private static function backfill_times(array &$log) {
        global $wpdb;
        $e = $wpdb->prefix . 'cjs_order_extensions';
        $l = $wpdb->prefix . 'cjs_activity_log';
        $t = self::table();
        $log['times_from_log'] = 0;
        $log['started_from_paid'] = 0;
        if (!self::table_ok() || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $e)) !== $e) {
            return;
        }
        $stamped = array_flip(array_map('intval', $wpdb->get_col("SELECT DISTINCT order_id FROM $t") ?: []));
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $l)) === $l) {
            $rows = $wpdb->get_results("SELECT object_id, action, details, created_at FROM $l WHERE object_type = 'order' AND object_id IS NOT NULL AND (action LIKE 'Order field ''manufacturing\\_status'' updated%' OR action LIKE 'Order auto-marked Pagaminta%') ORDER BY created_at ASC, id ASC", ARRAY_A) ?: [];
            $events = [];
            foreach ($rows as $r) {
                $oid = (int) $r['object_id'];
                if ($oid <= 0 || isset($stamped[$oid])) {
                    continue;
                }
                if (strpos((string) $r['action'], 'Order auto-marked Pagaminta') === 0) {
                    $key = 'pagaminta';
                } else {
                    $d = json_decode((string) $r['details'], true);
                    if (!is_array($d) || ($d['field'] ?? '') !== 'manufacturing_status') {
                        continue;
                    }
                    $raw = (string) ($d['value'] ?? '');
                    $key = CJS_Inv_Flow::rank($raw) >= 0 ? $raw : CJS_Inv_Flow::status_key($raw);
                }
                if ($key === null || CJS_Inv_Flow::rank($key) < 0) {
                    continue;
                }
                $events[$oid][] = [$key, (string) $r['created_at']];
            }
            foreach ($events as $oid => $list) {
                $times = [];
                foreach ($list as [$key, $at]) {
                    $rank = CJS_Inv_Flow::rank($key);
                    foreach (array_keys($times) as $k) {
                        if (CJS_Inv_Flow::rank($k) > $rank) {
                            unset($times[$k]);
                        }
                    }
                    $times[$key] = $at;
                }
                $current = CJS_Inv_Flow::rank(CJS_Inv_Flow::order_status_key($oid));
                foreach ($times as $key => $at) {
                    if (CJS_Inv_Flow::rank($key) > $current) {
                        continue;
                    }
                    $n = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (order_id, status, reached_at) VALUES (%d, %s, %s)", $oid, $key, $at));
                    $log['times_from_log'] += (int) $n;
                }
            }
        }
        if (CJS_Orders::hpos()) {
            $paid = $wpdb->get_results("SELECT e.order_id, d.date_paid_gmt AS paid FROM $e e INNER JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = e.order_id WHERE d.date_paid_gmt IS NOT NULL", ARRAY_A) ?: [];
        } else {
            $paid = $wpdb->get_results("SELECT e.order_id, m.meta_value AS paid FROM $e e INNER JOIN {$wpdb->postmeta} m ON m.post_id = e.order_id AND m.meta_key = '_date_paid' WHERE m.meta_value <> ''", ARRAY_A) ?: [];
        }
        foreach ($paid as $r) {
            $raw = (string) $r['paid'];
            $gmt = ctype_digit($raw) ? gmdate('Y-m-d H:i:s', (int) $raw) : $raw;
            if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
                continue;
            }
            $n = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (order_id, status, reached_at) VALUES (%d, 'nepradeta', %s)", (int) $r['order_id'], get_date_from_gmt($gmt)));
            $log['started_from_paid'] += (int) $n;
        }
    }

    private static function upgrade_labels() {
        if (is_array(get_option(self::LABELS_OPTION, null))) {
            return;
        }
        $old = get_option('cjs_manufacturing_statuses', []);
        $old = is_array($old) ? $old : [];
        $labels = [];
        foreach (CJS_Inv_Flow::STATUS_KEYS as $key) {
            $default = CJS_Inv_Flow::STATUS_LABELS[$key];
            $labels[$key] = $default;
            foreach ($old as $value) {
                if (is_string($value) && trim($value) !== '' && self::normalize($value) === self::normalize($default)) {
                    $labels[$key] = trim($value);
                    break;
                }
            }
        }
        update_option(self::LABELS_OPTION, $labels, true);
        self::flush();
    }

    private static function upgrade_orders(array &$log) {
        global $wpdb;
        $e = $wpdb->prefix . 'cjs_order_extensions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $e)) !== $e) {
            return;
        }
        $values = $wpdb->get_col("SELECT DISTINCT manufacturing_status FROM $e WHERE manufacturing_status IS NOT NULL") ?: [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (trim($value) === '') {
                continue;
            }
            $key = CJS_Inv_Flow::status_key($value);
            if ($key === null) {
                $log['unknown'][$value] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $e WHERE manufacturing_status = %s", $value));
                continue;
            }
            if ($key !== $value) {
                $n = $wpdb->query($wpdb->prepare("UPDATE $e SET manufacturing_status = %s WHERE manufacturing_status = %s", $key, $value));
                $log['converted'][$value] = ['to' => $key, 'orders' => (int) $n];
            }
        }
        $now = current_time('mysql');
        if (CJS_Orders::hpos()) {
            $sql = $wpdb->prepare("INSERT INTO $e (order_id, manufacturing_status, created_at, updated_at) SELECT o.id, 'done', %s, %s FROM {$wpdb->prefix}wc_orders o LEFT JOIN $e x ON x.order_id = o.id WHERE o.type = 'shop_order' AND o.status = 'wc-completed' AND x.order_id IS NULL", $now, $now);
        } else {
            $sql = $wpdb->prepare("INSERT INTO $e (order_id, manufacturing_status, created_at, updated_at) SELECT o.ID, 'done', %s, %s FROM {$wpdb->posts} o LEFT JOIN $e x ON x.order_id = o.ID WHERE o.post_type = 'shop_order' AND o.post_status = 'wc-completed' AND x.order_id IS NULL", $now, $now);
        }
        $n = $wpdb->query($sql);
        $log['completed_rows_created'] = $n === false ? 0 : (int) $n;
        if (CJS_Orders::hpos()) {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = e.order_id SET e.manufacturing_status = 'done' WHERE o.type = 'shop_order' AND o.status = 'wc-completed' AND COALESCE(e.manufacturing_status, '') <> 'done'";
        } else {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->posts} o ON o.ID = e.order_id SET e.manufacturing_status = 'done' WHERE o.post_type = 'shop_order' AND o.post_status = 'wc-completed' AND COALESCE(e.manufacturing_status, '') <> 'done'";
        }
        $n = $wpdb->query($sql);
        $log['completed_set_done'] = $n === false ? 0 : (int) $n;
        if (CJS_Orders::hpos()) {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = e.order_id SET e.completed_hours = e.assigned_hours WHERE o.type = 'shop_order' AND o.status = 'wc-completed' AND e.assigned_hours > 0 AND (e.completed_hours IS NULL OR e.completed_hours < e.assigned_hours)";
        } else {
            $sql = "UPDATE $e e INNER JOIN {$wpdb->posts} o ON o.ID = e.order_id SET e.completed_hours = e.assigned_hours WHERE o.post_type = 'shop_order' AND o.post_status = 'wc-completed' AND e.assigned_hours > 0 AND (e.completed_hours IS NULL OR e.completed_hours < e.assigned_hours)";
        }
        $n = $wpdb->query($sql);
        $log['completed_hours_filled'] = $n === false ? 0 : (int) $n;
    }
}
