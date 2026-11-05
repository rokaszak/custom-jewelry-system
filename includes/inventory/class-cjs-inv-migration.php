<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Migration {

    const DONE_OPTION = 'cjs_inv_migration_done';
    const REPORT_OPTION = 'cjs_inv_migration_report';
    const LOCK = 'cjs_inv_migration_lock';
    const MODEL_OPTION = 'cjs_inv_model_version';
    const MODEL_VERSION = 7;
    const MODEL_LOG_OPTION = 'cjs_inv_model_upgrade_log';
    const MODEL_LOCK = 'cjs_inv_model_upgrade_lock';
    const LOCK_TTL = 600;

    private static $order_status_cache = [];
    private static $report = [];

    public static function is_done() {
        return get_option(self::DONE_OPTION) === 'yes';
    }

    public static function legacy_table_exists($name) {
        global $wpdb;
        $table = $wpdb->prefix . $name;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function mapped($legacy_type, $legacy_id, $new_type) {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare('SELECT new_id FROM ' . CJS_Inv_DB::t('migration_map') . ' WHERE legacy_type = %s AND legacy_id = %d AND new_type = %s', $legacy_type, $legacy_id, $new_type));
        return $id !== null ? (int) $id : null;
    }

    public static function map($legacy_type, $legacy_id, $new_type, $new_id) {
        global $wpdb;
        $wpdb->replace(CJS_Inv_DB::t('migration_map'), [
            'legacy_type' => $legacy_type,
            'legacy_id' => (int) $legacy_id,
            'new_type' => $new_type,
            'new_id' => (int) $new_id,
            'created_at' => CJS_Inv_DB::now(),
        ]);
    }

    private static function note($key, $amount = 1) {
        self::$report['counts'][$key] = (self::$report['counts'][$key] ?? 0) + $amount;
    }

    private static function error($message, array $context = []) {
        self::$report['errors'][] = ['message' => $message, 'context' => $context];
    }

    private static function order_status($order_id) {
        if (!$order_id) {
            return '';
        }
        if (!isset(self::$order_status_cache[$order_id])) {
            $status = self::db_order_status($order_id);
            if ($status === null) {
                $order = wc_get_order($order_id);
                $status = $order ? $order->get_status() : 'missing';
            }
            self::$order_status_cache[$order_id] = $status;
        }
        return self::$order_status_cache[$order_id];
    }

    private static function db_order_status($order_id) {
        global $wpdb;
        $status = null;
        if (CJS_Orders::hpos()) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d AND type = 'shop_order'", $order_id));
        }
        if ($status === null) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'", $order_id));
        }
        if ($status === null || $status === '') {
            return null;
        }
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }

    public static function legacy_order_ids() {
        global $wpdb;
        $ids = [];
        if (self::legacy_table_exists('cjs_stones')) {
            $ids = array_merge($ids, $wpdb->get_col("SELECT DISTINCT order_id FROM {$wpdb->prefix}cjs_stones WHERE order_id IS NOT NULL AND order_id > 0"));
        }
        if (self::legacy_table_exists('cjs_inventory_items')) {
            $ids = array_merge($ids, $wpdb->get_col("SELECT DISTINCT order_id FROM {$wpdb->prefix}cjs_inventory_items WHERE order_id IS NOT NULL AND order_id > 0"));
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private static function check_orders_resolvable() {
        $ids = self::legacy_order_ids();
        $missing = [];
        foreach ($ids as $id) {
            if (self::order_status($id) === 'missing') {
                $missing[] = $id;
            }
        }
        self::$report['orders_checked'] = count($ids);
        self::$report['orders_missing'] = array_slice($missing, 0, 50);
        if (count($ids) >= 5 && count($missing) > count($ids) / 2) {
            throw new RuntimeException(sprintf('%d of %d orders referenced by legacy stones/kits could not be found, so nothing was migrated.', count($missing), count($ids)));
        }
    }

    public static function reset() {
        global $wpdb;
        $token = self::lock_acquire(self::MODEL_LOCK, self::LOCK_TTL);
        if ($token === null) {
            return new WP_Error('cjs_locked', 'The migration is running right now.');
        }
        try {
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cjs_inv_') . '%'));
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $wpdb->query("TRUNCATE TABLE `$table`");
            }
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
            foreach ([self::DONE_OPTION, self::REPORT_OPTION, 'cjs_inv_seeded', 'cjs_inv_seed_version', self::MODEL_OPTION, self::MODEL_LOG_OPTION, 'cjs_stock_order_statuses', 'cjs_size_kit_items', CJS_Size_Kit::MOVED_OPTION] as $option) {
                delete_option($option);
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cjs_inv_requirements_applied'));
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta'))) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s", '_cjs_inv_requirements_applied'));
            }
            wp_cache_flush();
            self::$order_status_cache = [];
            return self::run_locked();
        } finally {
            self::lock_release(self::MODEL_LOCK, $token);
        }
    }

    private static $held = [];

    private static function extend_time() {
        if (!function_exists('set_time_limit')) {
            return;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('set_time_limit', $disabled, true)) {
            return;
        }
        @set_time_limit(self::LOCK_TTL);
    }

    public static function heartbeat($name = self::MODEL_LOCK, $force = false) {
        global $wpdb;
        if (empty(self::$held[$name]) || CJS_Inv_DB::in_transaction()) {
            return false;
        }
        if (!$force && time() - (int) self::$held[$name]['at'] < 30) {
            return false;
        }
        $old = (string) self::$held[$name]['token'];
        $suffix = (string) substr((string) strstr($old, '|'), 1);
        $new = time() . '|' . $suffix;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $old));
        wp_cache_delete($name, 'options');
        self::extend_time();
        if ((int) $wpdb->rows_affected === 1 || $new === $old) {
            self::$held[$name] = ['token' => $new, 'at' => time()];
            return true;
        }
        return false;
    }

    public static function lock_acquire($name, $ttl) {
        global $wpdb;
        $token = time() . '|' . wp_generate_password(12, false);
        for ($i = 0; $i < 2; $i++) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token));
            if ((int) $wpdb->rows_affected === 1) {
                wp_cache_delete($name, 'options');
                self::$held[$name] = ['token' => $token, 'at' => time()];
                return $token;
            }
            $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            if ($held === null) {
                continue;
            }
            $at = (int) strtok((string) $held, '|');
            if ($at > 0 && time() - $at < (int) $ttl) {
                return null;
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held));
        }
        return null;
    }

    public static function lock_release($name, $token) {
        global $wpdb;
        if ($token === null || $token === '') {
            return;
        }
        $token = (string) $token;
        $held = self::$held[$name]['token'] ?? null;
        if ($held !== null && strstr($held, '|') === strstr($token, '|')) {
            $token = $held;
            unset(self::$held[$name]);
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token));
        wp_cache_delete($name, 'options');
    }

    public static function lock_held($name, $ttl) {
        global $wpdb;
        $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($held === null) {
            return false;
        }
        $at = (int) strtok((string) $held, '|');
        return $at > 0 && time() - $at < (int) $ttl;
    }

    public static function note_backoff(array $missing) {
        $missing = array_values(array_map('strval', $missing));
        $reason = sprintf('Database update incomplete, missing: %s. It is retried automatically in one hour, or now with Re-run migration on the Debug page.', implode(', ', $missing));
        $log = get_option(self::MODEL_LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        if (($log['status'] ?? '') === 'backoff' && ($log['reason'] ?? '') === $reason) {
            return $log;
        }
        $at = (int) get_option(CJS_Inv_DB::BACKOFF_OPTION, 0) ?: time();
        $entry = [
            'status' => 'backoff',
            'reason' => $reason,
            'missing' => $missing,
            'since' => current_time('mysql'),
            'retry_after' => wp_date('Y-m-d H:i:s', $at + CJS_Inv_DB::BACKOFF_SECONDS),
            'previous_status' => (string) ($log['status'] ?? ''),
            'counts' => isset($log['counts']) && is_array($log['counts']) ? $log['counts'] : [],
            'errors' => [],
        ];
        if (!empty($log['finished_at'])) {
            $entry['previous_finished_at'] = (string) $log['finished_at'];
        } elseif (!empty($log['previous_finished_at'])) {
            $entry['previous_finished_at'] = (string) $log['previous_finished_at'];
        }
        update_option(self::MODEL_LOG_OPTION, $entry, false);
        return $entry;
    }

    public static function model_needs_upgrade() {
        return (int) get_option(self::MODEL_OPTION, 0) < self::MODEL_VERSION;
    }

    public static function upgrade_model($force = false) {
        global $wpdb;
        if (!$force && !self::model_needs_upgrade()) {
            return get_option(self::MODEL_LOG_OPTION, []);
        }
        if (!$force && CJS_Inv_DB::backoff_active()) {
            $missing = CJS_Inv_DB::missing_columns();
            if ($missing) {
                return self::note_backoff($missing);
            }
        }
        if (CJS_Inv_DB::missing_tables()) {
            return ['status' => 'tables_missing'];
        }
        $token = self::lock_acquire(self::MODEL_LOCK, self::LOCK_TTL);
        if ($token === null) {
            return ['status' => 'locked'];
        }
        self::extend_time();
        $log =['status' => 'running', 'from' => (int) get_option(self::MODEL_OPTION, 0), 'to' => self::MODEL_VERSION, 'started_at' => current_time('mysql'), 'counts' => [], 'errors' => []];
        $complete = true;
        $count = function ($key, $n = 1) use (&$log) {
            $log['counts'][$key] = ($log['counts'][$key] ?? 0) + $n;
        };
        try {
            CJS_Inv_Seed::run();
            $rows = $wpdb->get_results('SELECT a.id, a.demand_id, d.state AS demand_state FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = b.item_id LEFT JOIN ' . CJS_Inv_DB::t('demands') . " d ON d.id = a.demand_id WHERE a.state = 'reserved' AND i.returnable = 0 AND (d.id IS NULL OR d.state <> 'open') ORDER BY a.id", ARRAY_A) ?: [];
            foreach ($rows as $r) {
                self::heartbeat();
                try {
                    $alloc = CJS_Inv_Stock::allocation((int) $r['id']);
                    if ($alloc) {
                        CJS_Inv_DB::transaction(function () use ($alloc) {
                            CJS_Inv_Stock::release_allocation_qty($alloc, (float) $alloc['qty'], __('Line is closed', 'custom-jewelry-system'));
                        });
                        $count('stale_reservations_released');
                    }
                } catch (Throwable $e) {
                    $log['errors'][] = 'allocation ' . $r['id'] . ': ' . $e->getMessage();
                }
            }
            if ($force || $log['from'] < 4) {
                self::upgrade_v4($count, $log);
            }
            if ($force || $log['from'] < 3) {
                self::upgrade_v3($count, $log);
            }
            if ($force || $log['from'] < 6) {
                $complete = self::upgrade_v6($count, $log, $force) && $complete;
            }
            if ($force || $log['from'] < 7) {
                $complete = self::upgrade_v7($count, $log, $force) && $complete;
            }
            if (($force || $log['from'] < 5) && self::is_done()) {
                [$added, $errors] = self::add_requested_kit_lines();
                if ($added) {
                    $count('kit_lines_added_for_requests', $added);
                }
                foreach ($errors as $err) {
                    $log['errors'][] = $err;
                }
            }

            $kit_lines = $wpdb->get_results('SELECT d.*, m.new_id AS piece_id FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('migration_map') . " m ON m.legacy_type = 'inventory_item' AND m.new_type = 'piece' AND m.legacy_id = CAST(SUBSTRING(d.source_ref, 11) AS UNSIGNED)
                WHERE d.source = 'migration' AND d.source_ref LIKE 'inventory:%' AND d.state = 'open' AND d.qty_allocated < d.qty", ARRAY_A) ?: [];
            foreach ($kit_lines as $l) {
                self::heartbeat();
                try {
                    $piece = $l['piece_id'] ? CJS_Inv_Stock::piece((int) $l['piece_id']) : null;
                    if ($piece && $piece['status'] === 'in' && (int) $piece['item_id'] === (int) $l['item_id']) {
                        $got = CJS_Inv_DB::transaction(function () use ($l, $piece) {
                            return CJS_Inv_Stock::allocate_demand((int) $l['id'], ['piece_id' => (int) $piece['id'], 'note' => sprintf('Assigned to order #%d in 1.x, not sent yet', (int) $l['order_id'])]);
                        });
                        if ($got > 0) {
                            $count('kit_lines_set_to_send');
                        }
                    }
                } catch (Throwable $e) {
                    $log['errors'][] = 'kit line ' . $l['id'] . ': ' . $e->getMessage();
                }
            }

            $noted = $wpdb->get_results('SELECT id, order_id, item_id, note, source_ref FROM ' . CJS_Inv_DB::t('demands') . " WHERE source = 'migration' AND note IS NOT NULL AND note <> ''", ARRAY_A) ?: [];
            $legacy_stones = self::legacy_table_exists('cjs_stones');
            foreach ($noted as $d) {
                self::heartbeat();
                $new = null;
                if (strpos((string) $d['source_ref'], 'inventory:') === 0) {
                    $new = '';
                } elseif ($legacy_stones && strpos((string) $d['source_ref'], 'stone:') === 0) {
                    $stone = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cjs_stones WHERE id = %d", (int) substr($d['source_ref'], 6)), ARRAY_A);
                    $new = $stone ? self::stone_line_note($stone) : null;
                }
                if ($new === null || $new === (string) $d['note']) {
                    continue;
                }
                CJS_Inv_DB::update('demands', ['note' => $new !== '' ? $new : null], ['id' => (int) $d['id']]);
                CJS_Inv_Stock::log('legacy', (int) $d['item_id'], 0, ['order_id' => (int) $d['order_id'] ?: null, 'demand_id' => (int) $d['id'], 'note' => mb_substr('Migration note: ' . str_replace(["\r", "\n"], ' ', (string) $d['note']), 0, 255), 'user_id' => 0]);
                $count('line_notes_moved_to_history');
            }
            $pieces = $wpdb->get_results('SELECT id, item_id, batch_id, note FROM ' . CJS_Inv_DB::t('pieces') . " WHERE note LIKE '%(legacy identifier %'", ARRAY_A) ?: [];
            foreach ($pieces as $p) {
                self::heartbeat();
                $clean = trim((string) preg_replace('/\s*\(legacy identifier [^)]*\)/', '', (string) $p['note']));
                CJS_Inv_DB::update('pieces', ['note' => $clean !== '' ? $clean : null], ['id' => (int) $p['id']]);
                CJS_Inv_Stock::log('legacy', (int) $p['item_id'], 0, ['batch_id' => (int) $p['batch_id'], 'piece_id' => (int) $p['id'], 'note' => mb_substr('Migration note: ' . $p['note'], 0, 255), 'user_id' => 0]);
                $count('piece_notes_moved_to_history');
            }

            $phantom = $wpdb->get_results('SELECT b.*, i.per_piece FROM ' . CJS_Inv_DB::t('batches') . ' b INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = b.item_id INNER JOIN ' . CJS_Inv_DB::t('migration_map') . " mb ON mb.new_type = 'batch' AND mb.new_id = b.id AND mb.legacy_type = 'stone'
                LEFT JOIN " . CJS_Inv_DB::t('migration_map') . " md ON md.legacy_type = 'stone' AND md.legacy_id = mb.legacy_id AND md.new_type = 'demand'
                LEFT JOIN " . CJS_Inv_DB::t('demands') . " d ON d.id = md.new_id
                WHERE i.per_piece = 0 AND b.qty_on_hand > 0 AND b.qty_reserved = 0 AND ABS(b.qty_on_hand - b.qty_received) < 0.0001
                  AND (d.id IS NULL OR d.order_id = 0 OR (d.state = 'cancelled' AND d.cancelled_by_order = 1))
                  AND NOT EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('allocations') . ' a WHERE a.batch_id = b.id)', ARRAY_A) ?: [];
            if ($phantom) {
                $doc = CJS_Inv_Stock::create_document('count', __('Removed 1.x stock that was never on an active or finished order', 'custom-jewelry-system'));
                foreach ($phantom as $b) {
                    self::heartbeat();
                    try {
                        CJS_Inv_DB::transaction(function () use ($b, $doc) {
                            $q = (float) $b['qty_on_hand'];
                            CJS_Inv_Stock::bump((int) $b['id'], -$q, 0);
                            CJS_Inv_Stock::log('count', (int) $b['item_id'], -$q, ['batch_id' => (int) $b['id'], 'document_id' => $doc, 'unit_cost' => $b['unit_cost'], 'note' => __('Not stock: never used on an order in 1.x', 'custom-jewelry-system'), 'user_id' => 0]);
                        });
                        $count('phantom_batches_removed');
                        $count('phantom_qty_removed', (float) $b['qty_on_hand']);
                    } catch (Throwable $e) {
                        $log['errors'][] = 'batch ' . $b['id'] . ': ' . $e->getMessage();
                    }
                }
            }

            CJS_Inv_Demands::reserve_pending();
            $settled = self::settle_all();
            if ($settled) {
                $count('lines_done_at_status', $settled);
            }
            CJS_Inv_Discrepancies::auto_close();
            if ($complete) {
                $log['status'] = $log['errors'] ? 'done_with_errors' : 'done';
                update_option(self::MODEL_OPTION, self::MODEL_VERSION, false);
            } else {
                $log['status'] = 'incomplete';
            }
        } catch (Throwable $e) {
            $log['status'] = 'failed';
            $log['errors'][] = $e->getMessage();
            error_log('CJS inventory model upgrade failed: ' . $e->getMessage());
        } finally {
            self::lock_release(self::MODEL_LOCK, $token);
        }
        $log['finished_at'] = current_time('mysql');
        update_option(self::MODEL_LOG_OPTION, $log, false);
        if ($log['counts'] || $log['errors']) {
            CJS_Logger::log('Inventory stock model updated', $log['status'] === 'done' ? 'success' : 'error', 'settings', null, ['counts' => $log['counts'], 'errors' => count($log['errors'])]);
        }
        return $log;
    }

    private static function upgrade_v3(callable $count, array &$log) {
        global $wpdb;
        $a = CJS_Inv_DB::t('allocations');
        $b = CJS_Inv_DB::t('batches');
        $i = CJS_Inv_DB::t('items');
        $d = CJS_Inv_DB::t('demands');
        $rows = $wpdb->get_results("SELECT a.* FROM $a a INNER JOIN $b b ON b.id = a.batch_id INNER JOIN $i i ON i.id = b.item_id INNER JOIN $d d ON d.id = a.demand_id WHERE a.state = 'used' AND i.returnable = 0 AND d.state = 'open' ORDER BY a.id", ARRAY_A) ?: [];
        foreach ($rows as $alloc) {
            self::heartbeat();
            try {
                $q = CJS_Inv_DB::transaction(function () use ($alloc) {
                    return CJS_Inv_Stock::unuse_allocation($alloc, __('Stock stays reserved until the order reaches its status', 'custom-jewelry-system'));
                });
                if ($q > 0) {
                    $count('used_stock_back_to_reserved');
                    $count('used_qty_back_to_reserved', $q);
                }
            } catch (Throwable $e) {
                $log['errors'][] = 'allocation ' . $alloc['id'] . ': ' . $e->getMessage();
            }
        }
        $lost = $wpdb->get_results("SELECT a.* FROM $a a INNER JOIN $b b ON b.id = a.batch_id INNER JOIN $i i ON i.id = b.item_id WHERE a.state = 'used' AND i.returnable = 1 ORDER BY a.id", ARRAY_A) ?: [];
        foreach ($lost as $alloc) {
            self::heartbeat();
            try {
                CJS_Inv_DB::transaction(function () use ($alloc, $d) {
                    CJS_Inv_DB::update('allocations', ['state' => 'writeoff', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
                    $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
                    if ($demand && $demand['state'] !== 'cancelled' && !CJS_Inv_Flow::live_allocs((int) $demand['id'])) {
                        CJS_Inv_DB::update('demands', ['state' => 'cancelled', 'cancelled_by_order' => 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand['id']]);
                        CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, ['order_id' => (int) $demand['order_id'], 'demand_id' => (int) $demand['id'], 'note' => __('Lost, removed from the order\'s inventory', 'custom-jewelry-system'), 'user_id' => 0]);
                    }
                });
                $count('lost_pieces_written_off');
            } catch (Throwable $e) {
                $log['errors'][] = 'allocation ' . $alloc['id'] . ': ' . $e->getMessage();
            }
        }
        $fixed = (int) $wpdb->query("UPDATE $d d LEFT JOIN (SELECT demand_id, SUM(COALESCE(qty_fine, qty)) AS s FROM $a WHERE state IN ('reserved','used','out') GROUP BY demand_id) x ON x.demand_id = d.id SET d.qty_allocated = COALESCE(x.s, 0) WHERE ABS(d.qty_allocated - COALESCE(x.s, 0)) > 0.00005");
        if ($fixed > 0) {
            $count('lines_recounted', $fixed);
        }
    }

    private static function upgrade_v4(callable $count, array &$log) {
        global $wpdb;
        $has_column = function () use ($wpdb) {
            return (bool) $wpdb->get_var("SHOW COLUMNS FROM " . CJS_Inv_DB::t('demands') . " LIKE 'qty_fineness'");
        };
        if (!$has_column()) {
            CJS_Inv_DB::create_tables();
            if (!$has_column()) {
                throw new RuntimeException('Column qty_fineness is missing on the inventory lines table.');
            }
        }
        $ids = $wpdb->get_col('SELECT d.id FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = d.item_id INNER JOIN ' . CJS_Inv_DB::t('categories') . ' c ON c.id = i.category_id WHERE c.has_fineness = 1 AND d.qty_fineness IS NULL ORDER BY d.id') ?: [];
        foreach ($ids as $id) {
            self::heartbeat();
            try {
                $done = CJS_Inv_DB::transaction(function () use ($id) {
                    return self::convert_fine_line((int) $id);
                });
                if ($done) {
                    $count('fineness_lines_converted');
                }
            } catch (Throwable $e) {
                $log['errors'][] = 'line ' . $id . ': ' . $e->getMessage();
            }
        }
    }

    private static function upgrade_v6(callable $count, array &$log, $force = false) {
        global $wpdb;
        $missing = CJS_Inv_DB::missing_columns(['items', 'demands']);
        if ($missing) {
            if ($force || !CJS_Inv_DB::backoff_active()) {
                CJS_Inv_DB::create_tables();
                $missing = CJS_Inv_DB::missing_columns(['items', 'demands']);
            }
            if ($missing) {
                CJS_Inv_DB::start_backoff($missing);
                $log['errors'][] = 'praba split skipped: columns missing (' . implode(', ', $missing) . '); it runs again on the next update';
                return false;
            }
        }
        CJS_Inv_Flow::flush();
        $cats = $wpdb->get_results('SELECT id, default_fineness FROM ' . CJS_Inv_DB::t('categories') . ' WHERE has_fineness = 1', ARRAY_A) ?: [];
        foreach ($cats as $cat) {
            $cid = (int) $cat['id'];
            $fallback = CJS_Inv_Flow::clean_praba($cat['default_fineness'] ?? null) ?? CJS_Inv_Flow::PRABA_REF;
            $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND praba IS NULL ORDER BY id', $cid), ARRAY_A) ?: [];
            foreach ($items as $item) {
                self::heartbeat();
                try {
                    $notes = [];
                    $done = CJS_Inv_DB::transaction(function () use ($item, $fallback, &$notes) {
                        return self::split_praba_item($item, $fallback, $notes);
                    });
                    foreach ($done as $key => $n) {
                        $count($key, $n);
                    }
                    foreach ($notes as $note) {
                        $log['left'][] = $note;
                    }
                } catch (Throwable $e) {
                    $log['errors'][] = 'item ' . $item['id'] . ': ' . $e->getMessage();
                }
            }
            try {
                $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('items') . " SET metal = name WHERE category_id = %d AND (metal IS NULL OR metal = '')", $cid));
                $n = $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = d.item_id SET d.category_id = i.category_id, d.metal = ' . CJS_Inv_Metals::sql('i') . " WHERE i.category_id = %d AND (d.metal IS NULL OR d.metal = '')", $cid));
                if ($n) {
                    $count('metal_lines_keyed', (int) $n);
                }
                foreach ($wpdb->get_col($wpdb->prepare('SELECT DISTINCT metal FROM ' . CJS_Inv_DB::t('demands') . " WHERE category_id = %d AND metal IS NOT NULL AND state = 'open'", $cid)) ?: [] as $metal) {
                    CJS_Inv_Metals::repoint($cid, (string) $metal);
                }
            } catch (Throwable $e) {
                $log['errors'][] = 'category ' . $cid . ': ' . $e->getMessage();
            }
        }
        CJS_Inv_Flow::flush();
        return true;
    }

    private static function upgrade_v7(callable $count, array &$log, $force = false) {
        global $wpdb;
        $missing = CJS_Inv_DB::missing_columns(['movements']);
        if ($missing) {
            if ($force || !CJS_Inv_DB::backoff_active()) {
                CJS_Inv_DB::create_tables();
                $missing = CJS_Inv_DB::missing_columns(['movements']);
            }
            if ($missing) {
                CJS_Inv_DB::start_backoff($missing);
                $log['errors'][] = 'stock order history markers skipped: columns missing (' . implode(', ', $missing) . '); it runs again on the next update';
                return false;
            }
        }
        $m = CJS_Inv_DB::t('movements');
        $last = 0;
        while (true) {
            self::heartbeat();
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, note FROM $m WHERE id > %d AND type = 'demand' AND stock_order_id IS NOT NULL AND link_mark IS NULL AND note IS NOT NULL ORDER BY id LIMIT 500", $last), ARRAY_A) ?: [];
            if (!$rows) {
                break;
            }
            $by_kind = [];
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $kind = CJS_Inv_Stock_Orders::note_link_kind($r['note']);
                if ($kind !== null) {
                    $by_kind[$kind][] = (int) $r['id'];
                }
            }
            foreach ($by_kind as $kind => $ids) {
                try {
                    $n = CJS_Inv_DB::query($wpdb->prepare("UPDATE $m SET link_mark = %s WHERE link_mark IS NULL AND id IN (" . CJS_Inv_DB::ids_in($ids) . ')', $kind));
                    if ($n) {
                        $count('stock_order_history_marked', (int) $n);
                    }
                } catch (Throwable $e) {
                    $log['errors'][] = 'history markers ' . $kind . ': ' . $e->getMessage();
                }
            }
        }
        return true;
    }

    public static function split_praba_item(array $item, $fallback = null, array &$notes = []) {
        global $wpdb;
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE id = %d AND praba IS NULL FOR UPDATE', (int) ($item['id'] ?? 0)), ARRAY_A);
        if (!$fresh) {
            return [];
        }
        $item = $fresh;
        $id = (int) $item['id'];
        $cid = (int) $item['category_id'];
        $fallback = CJS_Inv_Flow::clean_praba($fallback) ?? CJS_Inv_Flow::PRABA_REF;
        $label = sprintf('item %d (%s)', $id, (string) $item['name']);
        $out = [];
        $buckets = [];
        $order = [];
        $praba_of = function ($raw, $what) use ($fallback, $label, &$notes) {
            if ($raw === null || $raw === '') {
                return $fallback;
            }
            $p = CJS_Inv_Flow::clean_praba($raw);
            if ($p === null) {
                $notes[] = sprintf('%s: %s had praba %s, which is not valid; it was treated as unknown and given %s', $label, $what, CJS_Inv_DB::fmt($raw), CJS_Inv_DB::fmt($fallback));
                return $fallback;
            }
            return $p;
        };
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id, fineness FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d ORDER BY received_at ASC, id ASC', $id), ARRAY_A) ?: [] as $b) {
            $p = $praba_of($b['fineness'], 'lot ' . (int) $b['id']);
            $k = CJS_Inv_DB::fmt($p);
            $order[$k] = $p;
            $buckets[$k]['batches'][] = (int) $b['id'];
        }
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id, fineness FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE item_id = %d ORDER BY id ASC', $id), ARRAY_A) ?: [] as $l) {
            $p = $praba_of($l['fineness'], 'stock order line ' . (int) $l['id']);
            $k = CJS_Inv_DB::fmt($p);
            $order[$k] = $p;
            $buckets[$k]['lines'][] = (int) $l['id'];
        }
        [$base, $name_praba] = CJS_Inv_Metals::split_name(CJS_Inv_Metals::item_metal($item));
        if ($base === '') {
            $base = CJS_Inv_Metals::item_metal($item);
        }
        if (!$order) {
            if ($name_praba === null || (int) $item['archived'] === 1) {
                $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('items') . " SET metal = name WHERE id = %d AND (metal IS NULL OR metal = '')", $id));
                $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . " SET category_id = %d, metal = %s WHERE item_id = %d AND (metal IS NULL OR metal = '')", $cid, CJS_Inv_Metals::item_metal($item), $id));
                if ((int) $item['archived'] !== 1) {
                    $notes[] = sprintf('%s has no stock and no stock order line, so its praba is unknown; it was left without a praba', $label);
                }
                return ['praba_items_left_without_praba' => 1];
            }
            $order[CJS_Inv_DB::fmt($name_praba)] = $name_praba;
            $buckets[CJS_Inv_DB::fmt($name_praba)] = [];
        }
        $name_key = $name_praba !== null ? CJS_Inv_DB::fmt($name_praba) : null;
        $keep_key = $name_key !== null && isset($order[$name_key]) ? $name_key : (string) array_key_first($order);
        if ($name_key !== null && $keep_key !== $name_key) {
            $notes[] = sprintf('%s: the name ends with %s but its stock has another praba; it is now %s', $label, $name_key, CJS_Inv_Metals::display($base, $order[$keep_key]));
        }
        $keep = $order[$keep_key];
        $values = CJS_Inv_Catalog::item_values($id);
        $touched = [$id];
        $merged_into = 0;
        foreach ($order as $k => $p) {
            $k = (string) $k;
            $existing = CJS_Inv_Metals::find_same($cid, $base, $p, $id);
            if ($existing) {
                $target = (int) $existing['id'];
                if ((int) $existing['archived'] === 1 && (!empty($buckets[$k]['batches']) || !empty($buckets[$k]['lines']))) {
                    CJS_Inv_DB::update('items', ['archived' => 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => $target]);
                    $notes[] = sprintf('%s: archived item %d (%s) was restored because %s of its praba moved to it', $label, $target, (string) $existing['name'], !empty($buckets[$k]['batches']) ? 'stock' : 'stock order lines');
                }
                if ($k === $keep_key) {
                    $merged_into = $target;
                }
                $out['praba_items_merged'] = ($out['praba_items_merged'] ?? 0) + 1;
            } elseif ($k === $keep_key) {
                continue;
            } else {
                $target = CJS_Inv_DB::insert('items', [
                    'category_id' => $cid,
                    'name' => mb_substr(CJS_Inv_Metals::display($base, $p), 0, 255),
                    'name_custom' => 1,
                    'code' => null,
                    'unit_id' => (int) $item['unit_id'],
                    'returnable' => (int) $item['returnable'],
                    'per_piece' => (int) $item['per_piece'],
                    'default_cost' => $item['default_cost'],
                    'default_price' => $item['default_price'],
                    'reorder_level' => null,
                    'praba' => $p,
                    'metal' => mb_substr($base, 0, 191),
                    'qty_on_hand' => 0,
                    'qty_reserved' => 0,
                    'signature' => self::free_signature($cid, $base, $p, 0),
                    'archived' => (int) $item['archived'],
                    'created_by' => (int) $item['created_by'],
                    'created_at' => CJS_Inv_DB::now(),
                    'updated_at' => CJS_Inv_DB::now(),
                ]);
                foreach ($values as $field_id => $v) {
                    CJS_Inv_DB::insert('item_values', ['item_id' => $target, 'field_id' => (int) $field_id, 'choice_id' => $v['choice_id'], 'value_number' => $v['value_number'], 'unit_id' => $v['unit_id'], 'value_text' => $v['value_text']]);
                }
                $out['praba_items_created'] = ($out['praba_items_created'] ?? 0) + 1;
            }
            $bids = $buckets[$k]['batches'] ?? [];
            if ($bids) {
                $in = CJS_Inv_DB::ids_in($bids);
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('batches') . " SET item_id = %d WHERE id IN ($in)", $target));
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('movements') . " SET item_id = %d WHERE batch_id IN ($in)", $target));
                $renamed = self::rename_clashing_pieces($bids, (int) $target, $label, $notes);
                if ($renamed) {
                    $out['praba_piece_identifiers_renamed'] = ($out['praba_piece_identifiers_renamed'] ?? 0) + $renamed;
                }
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('pieces') . " SET item_id = %d WHERE batch_id IN ($in)", $target));
                $out['praba_lots_moved'] = ($out['praba_lots_moved'] ?? 0) + count($bids);
            }
            $lids = $buckets[$k]['lines'] ?? [];
            if ($lids) {
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET item_id = %d WHERE id IN (' . CJS_Inv_DB::ids_in($lids) . ')', $target));
                $out['praba_stock_order_lines_moved'] = ($out['praba_stock_order_lines_moved'] ?? 0) + count($lids);
            }
            $touched[] = (int) $target;
        }
        if ($merged_into) {
            CJS_Inv_DB::update('items', ['metal' => mb_substr($base, 0, 191), 'archived' => 1, 'updated_at' => CJS_Inv_DB::now()], ['id' => $id]);
            $merged = CJS_Inv_Catalog::item_row($merged_into);
            $notes[] = sprintf('%s was merged into item %d (%s), which has the same name and praba; it is archived and keeps its history', $label, $merged_into, $merged ? (string) $merged['name'] : '');
        } else {
            CJS_Inv_DB::update('items', ['praba' => $keep, 'metal' => mb_substr($base, 0, 191), 'name' => mb_substr(CJS_Inv_Metals::display($base, $keep), 0, 255), 'signature' => self::free_signature($cid, $base, $keep, $id), 'updated_at' => CJS_Inv_DB::now()], ['id' => $id]);
            $out['praba_items_set'] = 1;
        }
        $b = CJS_Inv_DB::t('batches');
        CJS_Inv_DB::query('UPDATE ' . CJS_Inv_DB::t('items') . " i SET i.qty_on_hand = (SELECT COALESCE(SUM(b.qty_on_hand), 0) FROM $b b WHERE b.item_id = i.id), i.qty_reserved = (SELECT COALESCE(SUM(b.qty_reserved), 0) FROM $b b WHERE b.item_id = i.id) WHERE i.id IN (" . CJS_Inv_DB::ids_in($touched) . ')');
        CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET category_id = %d, metal = %s WHERE item_id = %d', $cid, mb_substr($base, 0, 191), $id));
        return $out;
    }

    private static function identifier_key($identifier) {
        $s = trim((string) $identifier);
        $s = function_exists('remove_accents') ? remove_accents($s) : $s;
        return mb_strtolower($s);
    }

    private static function rename_clashing_pieces(array $batch_ids, $target, $label, array &$notes) {
        global $wpdb;
        $p = CJS_Inv_DB::t('pieces');
        $in = CJS_Inv_DB::ids_in($batch_ids);
        $moving = $wpdb->get_results("SELECT id, identifier FROM $p WHERE batch_id IN ($in) AND item_id <> " . (int) $target . " AND identifier IS NOT NULL AND identifier <> '' ORDER BY id", ARRAY_A) ?: [];
        if (!$moving) {
            return 0;
        }
        $on_target = [];
        foreach ($wpdb->get_col($wpdb->prepare("SELECT identifier FROM $p WHERE item_id = %d AND identifier IS NOT NULL", (int) $target)) ?: [] as $ident) {
            $on_target[self::identifier_key($ident)] = true;
        }
        $all = $on_target;
        foreach ($moving as $row) {
            $all[self::identifier_key($row['identifier'])] = true;
        }
        $n = 0;
        foreach ($moving as $row) {
            $ident = (string) $row['identifier'];
            if (!isset($on_target[self::identifier_key($ident)])) {
                continue;
            }
            $new = '';
            for ($i = 2; $i < 1000; $i++) {
                $candidate = mb_substr($ident, 0, 90) . '-' . $i;
                if (isset($all[self::identifier_key($candidate)])) {
                    continue;
                }
                if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE item_id = %d AND identifier = %s", (int) $target, $candidate)) > 0) {
                    $all[self::identifier_key($candidate)] = true;
                    continue;
                }
                $new = $candidate;
                break;
            }
            if ($new === '') {
                $new = mb_substr($ident, 0, 80) . '-' . (int) $row['id'];
            }
            CJS_Inv_DB::update('pieces', ['identifier' => $new, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $row['id']]);
            CJS_Inv_Stock::log('legacy', (int) $target, 0, ['piece_id' => (int) $row['id'], 'note' => mb_substr(sprintf('Identifier %1$s renamed to %2$s: the item already had a piece %1$s', $ident, $new), 0, 255), 'user_id' => 0]);
            $all[self::identifier_key($new)] = true;
            $on_target[self::identifier_key($new)] = true;
            $notes[] = sprintf('%s: piece %d had the identifier %s, which item %d already uses; it was renamed to %s', $label, (int) $row['id'], $ident, (int) $target, $new);
            $n++;
        }
        return $n;
    }

    private static function free_signature($cid, $metal, $praba, $self_id) {
        $sig = CJS_Inv_Metals::signature($cid, $metal, $praba);
        $taken = CJS_Inv_Catalog::find_by_signature($cid, $sig);
        return $taken && (int) $taken['id'] !== (int) $self_id ? sha1($sig . '|' . (int) $self_id . '|' . wp_generate_password(8, false)) : $sig;
    }

    public static function convert_fine_lines_early() {
        global $wpdb;
        try {
            if ((int) get_option(self::MODEL_OPTION, 0) >= 4 || self::lock_held(self::MODEL_LOCK, self::LOCK_TTL) || CJS_Inv_DB::missing_tables()) {
                return 0;
            }
            if (!$wpdb->get_var('SHOW COLUMNS FROM ' . CJS_Inv_DB::t('demands') . " LIKE 'qty_fineness'")) {
                return 0;
            }
            $log = ['errors' => []];
            $n = 0;
            self::upgrade_v4(function ($key, $add = 1) use (&$n) {
                $n += $add;
            }, $log);
            foreach ($log['errors'] as $err) {
                error_log('CJS praba line conversion: ' . $err);
            }
            return $n;
        } catch (Throwable $e) {
            error_log('CJS praba line conversion failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function convert_fine_line($demand_id) {
        global $wpdb;
        $d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . ' WHERE id = %d FOR UPDATE', (int) $demand_id), ARRAY_A);
        if (!$d || $d['qty_fineness'] !== null) {
            return false;
        }
        $rules = CJS_Inv_Flow::rules((int) $d['item_id']);
        if (!$rules['has_fineness']) {
            return false;
        }
        $old_qty = (float) $d['qty'];
        $was_full = (float) $d['qty_allocated'] >= $old_qty - 0.00005;
        $qty = CJS_Inv_Flow::from_pure($old_qty, $rules);
        $allocs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id = %d ORDER BY id', (int) $demand_id), ARRAY_A) ?: [];
        $live = 0.0;
        $last = null;
        foreach ($allocs as $a) {
            $cover = CJS_Inv_Flow::from_pure(CJS_Inv_Stock::cover($a), $rules);
            CJS_Inv_DB::update('allocations', ['qty_fine' => $cover], ['id' => (int) $a['id']]);
            if (in_array($a['state'], ['reserved', 'used', 'out'], true)) {
                $live += $cover;
                $last = [(int) $a['id'], $cover];
            }
        }
        $over = round($live - $qty, 4);
        if ($was_full && $last && $over > 0.00005 && $last[1] - $over > 0) {
            CJS_Inv_DB::update('allocations', ['qty_fine' => round($last[1] - $over, 4)], ['id' => $last[0]]);
            $live = $qty;
        } elseif ($was_full && $last && $over < -0.00005) {
            CJS_Inv_DB::update('allocations', ['qty_fine' => round($last[1] - $over, 4)], ['id' => $last[0]]);
            $live = $qty;
        }
        CJS_Inv_DB::update('demands', ['qty' => $qty, 'qty_allocated' => round($live, 4), 'qty_fineness' => CJS_Inv_Flow::PRABA_REF], ['id' => (int) $demand_id]);
        return true;
    }

    public static function add_requested_kit_lines() {
        $added = 0;
        $errors = [];
        CJS_Size_Kit::flush();
        foreach (CJS_Size_Kit::requested_open_order_ids() as $oid) {
            try {
                $added += (int) CJS_Inv_DB::transaction(function () use ($oid) {
                    return CJS_Size_Kit::add_missing_kit_line($oid);
                });
            } catch (Throwable $e) {
                $errors[] = 'kit line for order ' . $oid . ': ' . $e->getMessage();
            }
        }
        return [$added, $errors];
    }

    public static function settle_all(?array $only = null) {
        global $wpdb;
        $ids = $wpdb->get_col('SELECT DISTINCT order_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE state = 'open' AND order_id > 0 ORDER BY order_id") ?: [];
        if ($only !== null) {
            $ids = array_values(array_intersect(array_map('intval', $ids), array_map('intval', $only)));
        }
        $n = 0;
        foreach ($ids as $oid) {
            self::heartbeat();
            try {
                $status = self::order_status((int) $oid);
                $key = $status === 'completed' ? 'done' : CJS_Inv_Flow::order_status_key((int) $oid);
                $n += CJS_Inv_Flow::settle_order((int) $oid, $key);
            } catch (Throwable $e) {
                error_log('CJS settle failed for order ' . $oid . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    private static function mark_requirements_applied($order_id) {
        if (!$order_id) {
            return;
        }
        $order = wc_get_order($order_id);
        if ($order && $order->get_meta('_cjs_inv_requirements_applied') !== 'yes') {
            $order->update_meta_data('_cjs_inv_requirements_applied', 'yes');
            $order->save_meta_data();
        }
    }

    private static function demand_state_for_order($order_id) {
        $status = self::order_status($order_id);
        if ($status === 'completed') {
            return 'done';
        }
        if (in_array($status, ['cancelled', 'refunded', 'failed', 'trash', 'missing'], true)) {
            return 'cancelled';
        }
        return 'open';
    }

    public static function run($force = false) {
        if (!$force && self::is_done()) {
            return get_option(self::REPORT_OPTION, []);
        }
        $token = self::lock_acquire(self::MODEL_LOCK, self::LOCK_TTL);
        if ($token === null) {
            return ['status' => 'locked'];
        }
        try {
            if (!$force && self::is_done()) {
                return get_option(self::REPORT_OPTION, []);
            }
            return self::run_locked();
        } finally {
            self::lock_release(self::MODEL_LOCK, $token);
        }
    }

    private static function run_locked() {
        self::extend_time();
        $started = microtime(true);
        self::$report = [
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'counts' => [],
            'errors' => [],
        ];
        try {
            self::check_orders_resolvable();
            CJS_Inv_Seed::run();
            self::statuses();
            self::stock_orders();
            self::stones();
            self::finalize_po_lines();
            self::inventory_items();
            self::size_kit_settings();
            [$kits_added, $kit_errors] = self::add_requested_kit_lines();
            if ($kits_added) {
                self::note('kit_lines_added_for_requests', $kits_added);
            }
            foreach ($kit_errors as $err) {
                self::error($err);
            }
            CJS_Inv_Demands::reserve_pending();
            CJS_Inv_Stock_Orders::unlink_received();
            $settled = self::settle_all(self::legacy_order_ids());
            if ($settled) {
                self::note('lines_done_at_status', $settled);
            }
            self::$report['status'] = self::$report['errors'] ? 'done_with_errors' : 'done';
            update_option(self::DONE_OPTION, 'yes', false);
            update_option(self::MODEL_OPTION, CJS_Inv_DB::missing_columns(['movements']) ? self::MODEL_VERSION - 1 : self::MODEL_VERSION, false);
        } catch (Throwable $e) {
            self::$report['status'] = 'failed';
            self::error('Migration aborted: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        }
        self::$report['finished_at'] = current_time('mysql');
        self::$report['duration_seconds'] = round(microtime(true) - $started, 2);
        update_option(self::REPORT_OPTION, self::$report, false);
        CJS_Logger::log('Inventory migration ' . self::$report['status'], self::$report['status'] === 'done' ? 'success' : 'error', 'settings', null, ['counts' => self::$report['counts'], 'errors' => count(self::$report['errors'])]);
        return self::$report;
    }

    public static function fix_status_colours() {
        $map = ['#dc3545' => '#b02a37', '#ffc107' => '#a35200', '#ffff07' => '#7c6500', '#90ee90' => '#0f766e', '#28a745' => '#146c2e'];
        $keys = ['reikia_apmoketi' => '#dc3545', 'apmoketa' => '#ffc107', 'uzsakyta' => '#ffff07', 'siunčiama' => '#90ee90', 'gauta' => '#28a745'];
        $changed = 0;
        foreach (['cjs_stone_order_statuses', 'cjs_stock_order_statuses'] as $option) {
            $value = get_option($option, null);
            if (!is_array($value)) {
                continue;
            }
            $dirty = false;
            foreach ($value as $k => $status) {
                if (!is_array($status) || !isset($status['color'])) {
                    continue;
                }
                $key = isset($status['key']) ? (string) $status['key'] : (string) $k;
                $color = strtolower(trim((string) $status['color']));
                if (isset($keys[$key]) && $keys[$key] === $color) {
                    $value[$k]['color'] = $map[$color];
                    $dirty = true;
                    $changed++;
                }
            }
            if ($dirty) {
                update_option($option, $value);
            }
        }
        return $changed;
    }

    private static function statuses() {
        if (!is_array(get_option(CJS_Inv_Stock_Orders::OPTION, null))) {
            $legacy = get_option('cjs_stone_order_statuses', null);
            update_option(CJS_Inv_Stock_Orders::OPTION, is_array($legacy) && $legacy ? $legacy : CJS_Inv_Stock_Orders::default_statuses());
        }
        self::fix_status_colours();
    }

    private static function stock_orders() {
        global $wpdb;
        if (!self::legacy_table_exists('cjs_stone_orders')) {
            return;
        }
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjs_stone_orders ORDER BY order_date ASC, id ASC", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            self::heartbeat();
            if (self::mapped('stone_order', (int) $r['id'], 'stock_order')) {
                self::note('stone_orders_already_mapped');
                continue;
            }
            $id = CJS_Inv_DB::insert('stock_orders', [
                'number' => (string) $r['order_number'],
                'order_date' => CJS_Deadlines::is_valid_ymd((string) $r['order_date']) ? $r['order_date'] : substr((string) $r['created_at'], 0, 10),
                'status' => (string) $r['status'],
                'legacy_id' => (int) $r['id'],
                'created_by' => (int) $r['created_by'],
                'created_at' => $r['created_at'] ?: CJS_Inv_DB::now(),
                'updated_at' => $r['updated_at'] ?: CJS_Inv_DB::now(),
            ]);
            self::map('stone_order', (int) $r['id'], 'stock_order', $id);
            self::note('stone_orders_migrated');
        }
    }

    private static function stone_category() {
        $cat = CJS_Inv_Catalog::category_by_slug('stones');
        if (!$cat) {
            throw new RuntimeException('Stones category missing');
        }
        return (int) $cat['id'];
    }

    private static function field_ids($category_id) {
        $out = [];
        foreach (CJS_Inv_Catalog::fields_for_category($category_id) as $f) {
            $out[$f['slug']] = (int) $f['id'];
        }
        return $out;
    }

    private static function choice_for($field_id, $value, $is_shape = false) {
        global $wpdb;
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', remove_accents($value)));
        $choices = $wpdb->get_results($wpdb->prepare('SELECT id, label FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $field_id), ARRAY_A) ?: [];
        foreach ($choices as $c) {
            if (strtolower(preg_replace('/[^a-z0-9]/i', '', remove_accents($c['label']))) === $norm) {
                return (int) $c['id'];
            }
        }
        $label = $is_shape ? CJS_Inv_Seed::shape_label($value) : $value;
        $id = CJS_Inv_Catalog::add_choice($field_id, $label);
        return is_wp_error($id) ? null : (int) $id;
    }

    const SHAPE_WORDS = ['round' => 'round', 'oval' => 'oval', 'pear' => 'pear', 'marquise' => 'marquise', 'markizes' => 'marquise', 'markizės' => 'marquise', 'radiant' => 'radiant', 'cushion' => 'cushion', 'emerald' => 'emerald', 'princess' => 'princess', 'heart' => 'heart', 'asscher' => 'asscher', 'baguette' => 'baguette'];
    const LT_COLOR_WORDS = ['rožinės' => 'Pink', 'rozines' => 'Pink', 'juodi' => 'Black', 'juodas' => 'Black'];
    const FLUO_WORDS = ['NONE/FAINT' => 'None/Faint', 'NONE' => null, 'FAINT' => 'Faint', 'MEDIUM' => 'Medium', 'STRONG' => 'Strong'];
    const CERT_RE = '/\b(?:(IGI|GIA|HRD|AGS|EGL)\s*:?\s*)?(LG\d{6,}|\d{8,})\b/iu';

    public static function cert_ids($text) {
        $out = [];
        if (preg_match_all(self::CERT_RE, (string) $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = trim((($x[1] ?? '') !== '' ? strtoupper($x[1]) . ' ' : '') . strtoupper($x[2]));
            }
        }
        return $out;
    }

    public static function parse_stone_text($text) {
        $found = [];
        $rest = [];
        $s = ' ' . str_replace(["\r", "\n", ';'], ' , ', (string) $text) . ' ';
        $s = preg_replace('/(\d),(\d)/u', '$1.$2', $s);
        $take = function ($re, callable $fn) use (&$s) {
            $s = (string) preg_replace_callback($re, function ($m) use ($fn) {
                $fn($m);
                return ' ';
            }, $s);
        };
        $take(self::CERT_RE, function ($m) use (&$found) {
            $found['certs'][] = trim((($m[1] ?? '') !== '' ? strtoupper($m[1]) . ' ' : '') . strtoupper($m[2]));
        });
        $take('/(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)\s*(mm|m)?(?=[\s,]|$)/iu', function ($m) use (&$rest) {
            $rest[] = trim($m[1] . ' - ' . $m[2] . (($m[3] ?? '') !== '' ? ' mm' : ''));
        });
        $take('/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*(mm)?(?=[\s,]|$)/iu', function ($m) use (&$found) {
            $found['size'] = $found['size'] ?? ['value' => (float) $m[1], 'unit' => 'mm'];
            $found['width'] = $found['width'] ?? (float) $m[2];
        });
        $take('/~?\s*(\d+(?:\.\d+)?)\s*(ct|cts|carats?)\.?(?=[\s,\/]|$)/iu', function ($m) use (&$found) {
            $found['size'] = $found['size'] ?? ['value' => (float) $m[1], 'unit' => 'ct'];
        });
        $take('/(?<![\w.])(\d+(?:\.\d+)?)\s*mm(?=[\s,]|$)/iu', function ($m) use (&$found) {
            $found['size'] = $found['size'] ?? ['value' => (float) $m[1], 'unit' => 'mm'];
        });
        $take('/\bfancy\s+(?:(light|intense|vivid|deep|dark)\s+)?(yellow|pink|blue|green|orange|brown)\b/iu', function ($m) use (&$found) {
            $found['color'] = $found['color'] ?? trim('Fancy ' . (($m[1] ?? '') !== '' ? ucfirst(strtolower($m[1])) . ' ' : '') . ucfirst(strtolower($m[2])));
        });
        $take('/\bocean\s+blue\b/iu', function () use (&$found) {
            $found['color'] = $found['color'] ?? 'Ocean blue';
        });
        $take('/\brainbow\b/iu', function () use (&$found) {
            $found['color'] = $found['color'] ?? 'Rainbow';
        });
        $take('/(?<![\w])(3\s?EX|EX\s+EX\s+EX|EX\s+EX)(?![\w])/iu', function () use (&$found) {
            $found['cut'] = $found['cut'] ?? 'Excellent';
        });
        $take('/(?<![\w])ID(?![\w])/u', function () use (&$found) {
            $found['cut'] = 'Ideal';
        });
        $take('/(?<![\w])(none\/faint|none|faint|medium|strong)(?![\w])/iu', function ($m) use (&$found) {
            $word = self::FLUO_WORDS[strtoupper($m[1])];
            if ($word !== null) {
                $found['fluorescence'] = $found['fluorescence'] ?? $word;
            }
        });
        $take('/(?<![\w])([D-K])\s*\/\s*(VVS1|VVS2|VS1|VS2|SI1|SI2|IF|FL)(?![\w])/iu', function ($m) use (&$found) {
            $found['color_grade'] = $found['color_grade'] ?? strtoupper($m[1]);
            $found['clarity'] = $found['clarity'] ?? strtoupper($m[2]);
        });
        $take('/(?<![\w\/])([D-K])\s*[\/-]\s*([D-K])(?![\w\/])/iu', function ($m) use (&$found) {
            $found['color_grade'] = $found['color_grade'] ?? strtoupper($m[1]) . '/' . strtoupper($m[2]);
        });
        $take('/(?<![\w])(VVS1\+|VVS2\+|VS1\+|VS2\+|VVS\+|VS\+|VVS1|VVS2|VS1|VS2|SI1|SI2|VVS|VS|SI|IF|FL|I1|I2|I3)(?![\w+])/iu', function ($m) use (&$found) {
            $found['clarity'] = $found['clarity'] ?? strtoupper($m[1]);
        });
        $take('/(?<![\w])([D-K])(\+?)(?![\w])/u', function ($m) use (&$found) {
            $found['color_grade'] = $found['color_grade'] ?? $m[1] . ($m[2] ?? '');
        });
        $take('/(?<![\w])(IGI|GIA|HRD|AGS|EGL)(?![\w])/iu', function ($m) use (&$found) {
            $found['labs'][] = strtoupper($m[1]);
        });
        foreach (self::SHAPE_WORDS as $word => $shape) {
            $take('/(?<![\w])' . preg_quote($word, '/') . '(?![\w])/iu', function () use (&$found, $shape) {
                $found['shape'] = $found['shape'] ?? $shape;
            });
        }
        foreach (self::LT_COLOR_WORDS as $word => $color) {
            $take('/(?<![\w])' . preg_quote($word, '/') . '(?![\w])/iu', function () use (&$found, $color) {
                $found['color'] = $found['color'] ?? $color;
            });
        }
        $take('/(?<![\w])(spalvos|deimantai|deimantas)(?![\w])/iu', function () {
        });
        $left = str_replace(['(', ')'], ' ', $s);
        $left = trim(preg_replace('/\s+/u', ' ', preg_replace('/\s*,\s*(,\s*)+/u', ', ', $left)));
        $left = trim($left, " ,-/:");
        if ($left !== '' && preg_match('/[\p{L}\d]/u', $left)) {
            $rest[] = $left;
        }
        $found['rest'] = implode(', ', $rest);
        return $found;
    }

    private static function grade_like($value) {
        return (bool) preg_match('/^[D-K](\s*\/\s*[D-K])?\+?$/i', trim((string) $value));
    }

    private static function stone_base_values(array $stone, array $f) {
        $values = [];
        $selects = [
            'type' => 'stone_type',
            'origin' => 'stone_origin',
            'shape' => 'stone_shape',
            'color' => 'stone_color',
            'clarity' => 'stone_clarity',
            'cut-grade' => 'stone_cut_grade',
            'origin-country' => 'origin_country',
        ];
        foreach ($selects as $slug => $col) {
            $raw = trim((string) ($stone[$col] ?? ''));
            if ($slug === 'origin' && self::is_empty_descriptor($raw)) {
                $raw = 'Natural';
            }
            if (self::is_empty_descriptor($raw)) {
                continue;
            }
            if ($slug === 'color' && self::grade_like($raw) && isset($f['color-grade'])) {
                $choice = self::choice_for($f['color-grade'], strtoupper(preg_replace('/\s+/', '', $raw)));
                if ($choice) {
                    $values[$f['color-grade']] = ['choice_id' => $choice];
                }
                continue;
            }
            if (!isset($f[$slug])) {
                continue;
            }
            $choice = self::choice_for($f[$slug], $raw, $slug === 'shape');
            if ($choice) {
                $values[$f[$slug]] = ['choice_id' => $choice];
            }
        }
        return $values;
    }

    private static function stone_size(array $stone) {
        $size = $stone['stone_size_value'] ?? null;
        $unit = ($stone['stone_size_unit'] ?? 'carats') === 'mm' ? 'mm' : 'ct';
        if (($size === null || (float) $size == 0) && !empty($stone['stone_weight_carats'])) {
            $size = $stone['stone_weight_carats'];
            $unit = 'ct';
        }
        return $size !== null && (float) $size > 0 ? ['value' => (float) $size, 'unit' => $unit] : null;
    }

    private static function apply_parsed(array $values, array $found, array $f, $size, array &$notes) {
        $fill = [
            'shape' => ['shape', true],
            'color' => ['color', false],
            'color_grade' => ['color-grade', false],
            'clarity' => ['clarity', false],
            'cut' => ['cut-grade', false],
            'fluorescence' => ['fluorescence', false],
        ];
        foreach ($fill as $key => [$slug, $is_shape]) {
            if (!isset($found[$key], $f[$slug]) || isset($values[$f[$slug]])) {
                continue;
            }
            $choice = self::choice_for($f[$slug], $found[$key], $is_shape);
            if ($choice) {
                $values[$f[$slug]] = ['choice_id' => $choice];
            }
        }
        $parsed = $found['size'] ?? null;
        if ($parsed && $size && $parsed['unit'] !== $size['unit']) {
            $notes[] = CJS_Inv_DB::fmt($parsed['value']) . ' ' . $parsed['unit'];
            $parsed = null;
        }
        $final = $parsed ?: $size;
        if ($final && isset($f['size'])) {
            $values[$f['size']] = ['value_number' => (float) $final['value'], 'unit_id' => CJS_Inv_Catalog::unit_id_by_label($final['unit'])];
        }
        if (!empty($found['width']) && $final && $final['unit'] === 'mm' && isset($f['width'])) {
            $values[$f['width']] = ['value_number' => (float) $found['width'], 'unit_id' => CJS_Inv_Catalog::unit_id_by_label('mm')];
        }
        return $values;
    }

    public static function stone_plan(array $stone, array $f) {
        $qty = max(1, (int) ($stone['stone_quantity'] ?? 1));
        $cert = trim((string) ($stone['certificate'] ?? ''));
        $comment = trim((string) ($stone['custom_comment'] ?? ''));
        $notes = [];
        $ids = [];
        $cert_found = [];
        if (!self::is_empty_descriptor($cert)) {
            foreach (preg_split('/\s+\/\s+/u', $cert) as $part) {
                $part_ids = self::cert_ids($part);
                $ids = array_merge($ids, $part_ids);
                $parsed = self::parse_stone_text(preg_replace(self::CERT_RE, ' ', $part));
                if (!empty($parsed['rest'])) {
                    $notes[] = $parsed['rest'];
                }
                unset($parsed['rest'], $parsed['certs']);
                $cert_found = $cert_found + $parsed;
            }
        }
        $segments = $comment !== '' ? preg_split('/\s+\/\s+/u', $comment) : [];
        $per_stone = $qty > 1 && count($segments) === $qty;
        $common = $per_stone ? [] : self::parse_stone_text($comment);
        if (!$per_stone && !empty($common['rest'])) {
            $notes[] = $common['rest'];
        }
        if (!$ids && !empty($common['certs'])) {
            $ids = $common['certs'];
        }
        $labs = $common['labs'] ?? [];
        $stone_found = [];
        if ($per_stone) {
            foreach ($segments as $k => $seg) {
                $p = self::parse_stone_text($seg);
                if (!empty($p['rest'])) {
                    $notes[] = $p['rest'];
                }
                if (!$ids && !empty($p['certs'])) {
                    $ids = array_merge($ids, $p['certs']);
                }
                $labs = array_merge($labs, $p['labs'] ?? []);
                $stone_found[$k] = $p;
            }
        }
        $ids = array_values(array_unique($ids));
        $labs = array_values(array_unique($labs));
        $certified = $ids || $labs;
        $identifiers = [];
        if ($ids) {
            $identifiers = array_slice($ids, 0, $qty);
        } elseif ($labs) {
            $lab = $labs[0];
            for ($k = 1; $k <= $qty; $k++) {
                $identifiers[] = $qty > 1 ? $lab . $k : $lab;
            }
        }
        $setting = trim((string) ($stone['stone_setting'] ?? ''));
        if (!self::is_empty_descriptor($setting)) {
            $notes[] = $setting;
        }
        $base = self::stone_base_values($stone, $f);
        $size = self::stone_size($stone);
        if ($certified && isset($f['certified'])) {
            $choice = self::choice_for($f['certified'], 'Sertifikuotas');
            if ($choice) {
                $base[$f['certified']] = ['choice_id' => $choice];
            }
        }
        if ($certified && isset($f['type']) && !isset($base[$f['type']])) {
            $choice = self::choice_for($f['type'], 'Diamond');
            if ($choice) {
                $base[$f['type']] = ['choice_id' => $choice];
            }
        }
        $groups = [];
        for ($k = 0; $k < $qty; $k++) {
            $found = ($per_stone ? $stone_found[$k] : $common) + $cert_found;
            $values = self::apply_parsed($base, $found, $f, $size, $notes);
            ksort($values);
            $key = md5(wp_json_encode($values));
            if (!isset($groups[$key])) {
                $groups[$key] = ['values' => $values, 'qty' => 0, 'identifiers' => []];
            }
            $groups[$key]['qty']++;
            if (isset($identifiers[$k])) {
                $groups[$key]['identifiers'][] = $identifiers[$k];
            }
        }
        return [
            'groups' => array_values($groups),
            'certified' => $certified,
            'identifiers' => $identifiers,
            'note' => implode(', ', array_values(array_unique(array_filter(array_map('trim', $notes), 'strlen')))),
        ];
    }

    public static function stone_values(array $stone, array $f) {
        $plan = self::stone_plan($stone, $f);
        return $plan['groups'][0]['values'] ?? [];
    }

    private static function is_empty_descriptor($value) {
        $v = strtolower(trim((string) $value));
        return $v === '' || in_array($v, ['none', 'null', 'n/a', 'na', '-', '0'], true);
    }

    public static function stone_line_note(array $stone) {
        return self::stone_plan($stone, self::field_ids(self::stone_category()))['note'];
    }

    private static function stones() {
        global $wpdb;
        if (!self::legacy_table_exists('cjs_stones')) {
            return;
        }
        $cat = self::stone_category();
        $f = self::field_ids($cat);
        $has_junction = self::legacy_table_exists('cjs_stone_order_items');
        $stones = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjs_stones ORDER BY id ASC", ARRAY_A) ?: [];
        $memberships = [];
        if ($has_junction) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cjs_stone_order_items");
            $sel_cart = in_array('in_cart', $cols, true) ? 'soi.in_cart' : '0';
            $sel_recv = in_array('received', $cols, true) ? 'soi.received' : '0';
            $rows = $wpdb->get_results("SELECT soi.stone_id, soi.stone_order_id, $sel_cart AS in_cart, $sel_recv AS received, so.order_date, so.updated_at
                FROM {$wpdb->prefix}cjs_stone_order_items soi INNER JOIN {$wpdb->prefix}cjs_stone_orders so ON so.id = soi.stone_order_id ORDER BY soi.id ASC", ARRAY_A) ?: [];
            foreach ($rows as $r) {
                $sid = (int) $r['stone_id'];
                if (isset($memberships[$sid])) {
                    self::note('stones_in_multiple_stone_orders');
                    if ((int) $r['received'] && !(int) $memberships[$sid]['received']) {
                        $memberships[$sid] = $r;
                    }
                    continue;
                }
                $memberships[$sid] = $r;
            }
        }
        usort($stones, function ($a, $b) use ($memberships) {
            $da = isset($memberships[(int) $a['id']]) ? $memberships[(int) $a['id']]['order_date'] : '9999-12-31';
            $db = isset($memberships[(int) $b['id']]) ? $memberships[(int) $b['id']]['order_date'] : '9999-12-31';
            return [$da, (int) $a['id']] <=> [$db, (int) $b['id']];
        });
        self::$report['legacy']['stones'] = count($stones);
        foreach ($stones as $stone) {
            self::heartbeat();
            $sid = (int) $stone['id'];
            if (self::mapped('stone', $sid, 'item')) {
                self::note('stones_already_mapped');
                continue;
            }
            try {
                CJS_Inv_DB::transaction(function () use ($stone, $sid, $cat, $f, $memberships) {
                    self::migrate_stone($stone, $sid, $cat, $f, $memberships[$sid] ?? null);
                });
            } catch (Throwable $e) {
                self::error('Stone #' . $sid . ' failed: ' . $e->getMessage());
                self::note('stones_failed');
            }
        }
    }

    private static function migrate_stone(array $stone, int $sid, int $cat, array $f, $membership) {
        global $wpdb;
        $plan = self::stone_plan($stone, $f);
        $order_id = (int) ($stone['order_id'] ?? 0);
        $state = $order_id ? self::demand_state_for_order($order_id) : 'open';
        $so_id = $membership ? self::mapped('stone_order', (int) $membership['stone_order_id'], 'stock_order') : null;
        $received = $membership && (int) $membership['received'];
        $inferred = !$received && $state === 'done' && $order_id > 0;
        $got = $received || $inferred;
        $stale = $membership && !$got && ($state === 'cancelled' || !$order_id) && CJS_Deadlines::is_valid_ymd((string) $membership['order_date']) && strtotime($membership['order_date'] . ' 00:00:00') < strtotime('-2 months', (int) current_time('timestamp'));
        $line_got = $got || $stale;
        $note = $plan['note'];
        if ($note !== trim((string) ($stone['custom_comment'] ?? ''))) {
            self::note('stone_comments_to_attributes');
        }
        if ($plan['certified']) {
            self::note('certified_stones', count($plan['identifiers']) ?: 1);
        }
        $first = true;
        foreach ($plan['groups'] as $group) {
            $extra = $plan['certified'] ? ['per_piece' => 1] : [];
            $item_id = CJS_Inv_Catalog::find_or_create_item($cat, $group['values'], '', $extra);
            if (is_wp_error($item_id)) {
                throw new RuntimeException($item_id->get_error_message());
            }
            $qty = (int) $group['qty'];
            $identifiers = self::free_identifiers($item_id, $group['identifiers']);
            if ($first) {
                self::map('stone', $sid, 'item', $item_id);
            }
            $demand_id = null;
            $po_line_id = null;
            if ($so_id) {
                $line = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d AND item_id = %d', $so_id, $item_id), ARRAY_A);
                if ($line) {
                    $po_line_id = (int) $line['id'];
                    CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET qty = qty + %d, qty_received = qty_received + %d, in_cart = in_cart + %d WHERE id = %d', $qty, $line_got ? $qty : 0, (int) $membership['in_cart'] ? 1 : 0, $po_line_id));
                } else {
                    $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $so_id));
                    $po_line_id = CJS_Inv_DB::insert('stock_order_lines', [
                        'stock_order_id' => $so_id,
                        'item_id' => $item_id,
                        'qty' => $qty,
                        'qty_received' => $line_got ? $qty : 0,
                        'in_cart' => (int) $membership['in_cart'] ? 1 : 0,
                        'sort_order' => $sort,
                        'created_at' => CJS_Inv_DB::now(),
                        'updated_at' => CJS_Inv_DB::now(),
                    ]);
                    $line = ['note' => null, 'planned_identifiers' => null];
                }
                if (!$line_got && $identifiers && CJS_Inv_DB::has_column('stock_order_lines', 'planned_identifiers')) {
                    $planned = array_values(array_unique(array_merge(CJS_Inv_Stock_Orders::planned_of($line), $identifiers)));
                    CJS_Inv_DB::update('stock_order_lines', ['planned_identifiers' => wp_json_encode($planned)], ['id' => $po_line_id]);
                    self::note('certificates_planned_on_stock_orders', count($identifiers));
                }
                if (!$order_id && $note !== '') {
                    $old = trim((string) ($line['note'] ?? ''));
                    $joined = $old === '' ? $note : (strpos($old, $note) === false ? $old . ' · ' . $note : $old);
                    CJS_Inv_DB::update('stock_order_lines', ['note' => mb_substr($joined, 0, 255)], ['id' => $po_line_id]);
                }
                if ($first) {
                    self::map('stone', $sid, 'po_line', $po_line_id);
                }
                self::note('stones_in_stone_orders');
            }
            if ($order_id || !$membership) {
                $insert_state = $state === 'cancelled' ? 'cancelled' : 'open';
                $demand_id = CJS_Inv_Demands::insert($order_id, !empty($stone['order_item_id']) ? (int) $stone['order_item_id'] : null, $item_id, $qty, [
                    'source' => 'migration',
                    'source_ref' => 'stone:' . $sid,
                    'note' => $note,
                    'state' => $insert_state,
                    'cancelled_by_order' => $insert_state === 'cancelled',
                    'po_line_id' => $po_line_id,
                    'created_by' => (int) $stone['created_by'],
                    'created_at' => $stone['created_at'] ?: CJS_Inv_DB::now(),
                ]);
                if ($first) {
                    self::map('stone', $sid, 'demand', $demand_id);
                }
                self::mark_requirements_applied($order_id);
                self::note('demands_' . $state);
                if (!$order_id) {
                    self::note('stones_without_order_as_demand');
                }
            } else {
                self::note('stones_without_order_in_stone_order');
            }
            if ($stale) {
                self::note('stale_lines_marked_received');
                self::note('stale_qty_marked_received', $qty);
                CJS_Inv_Stock::log('legacy', $item_id, 0, ['order_id' => $order_id ?: null, 'stock_order_id' => $so_id, 'note' => mb_substr(sprintf('Never marked received in 1.x, older than 2 months: closed as received, not put in stock%s', $identifiers ? ' (' . implode(', ', $identifiers) . ')' : ''), 0, 255), 'user_id' => 0]);
            } elseif ($got) {
                $stocked = $demand_id && $state !== 'cancelled' && $order_id > 0;
                if (!$stocked) {
                    self::note('received_stones_not_stocked');
                    self::note('received_qty_not_stocked', $qty);
                    if ($identifiers) {
                        CJS_Inv_Stock::log('legacy', $item_id, 0, ['note' => mb_substr(sprintf('Migrated from 1.x: %s received, not in stock', implode(', ', $identifiers)), 0, 255), 'user_id' => 0]);
                    }
                } else {
                    if ($membership) {
                        $document_id = self::mapped('stone_order', (int) $membership['stone_order_id'], 'document');
                        if (!$document_id) {
                            $document_id = CJS_Inv_Stock::create_document('migration', sprintf('Migrated from stone order #%d', (int) $membership['stone_order_id']), CJS_Inv_DB::now());
                            self::map('stone_order', (int) $membership['stone_order_id'], 'document', $document_id);
                        }
                        $received_at = CJS_Deadlines::is_valid_ymd((string) $membership['order_date']) ? $membership['order_date'] . ' 12:00:00' : CJS_Inv_DB::now();
                    } else {
                        $document_id = self::mapped('used_without_stone_order', 0, 'document');
                        if (!$document_id) {
                            $document_id = CJS_Inv_Stock::create_document('migration', 'Migrated from 1.x: stones used on completed orders without a stone order', CJS_Inv_DB::now());
                            self::map('used_without_stone_order', 0, 'document', $document_id);
                        }
                        $received_at = !empty($stone['created_at']) ? $stone['created_at'] : CJS_Inv_DB::now();
                    }
                    $batch_id = CJS_Inv_Stock::create_batch([
                        'item_id' => $item_id,
                        'qty' => $qty,
                        'unit_cost' => null,
                        'no_default_cost' => 1,
                        'source_type' => $membership ? 'stock_order' : 'migration',
                        'source_id' => $membership ? $po_line_id : null,
                        'stock_order_id' => $so_id,
                        'received_at' => $received_at,
                        'identifiers' => $identifiers,
                    ], $document_id);
                    if (is_wp_error($batch_id)) {
                        throw new RuntimeException($batch_id->get_error_message());
                    }
                    if ($first) {
                        self::map('stone', $sid, 'batch', $batch_id);
                    }
                    if ($inferred) {
                        self::note($membership ? 'used_on_completed_orders_not_ticked_received' : 'used_on_completed_orders_without_stone_order');
                        self::note($membership ? 'used_qty_not_ticked_received' : 'used_qty_without_stone_order', $qty);
                    } else {
                        self::note('stones_received_to_batches');
                        self::note('stones_received_qty', $qty);
                    }
                    CJS_Inv_Stock::allocate_demand($demand_id, ['batch_id' => $batch_id]);
                    self::note($state === 'done' ? 'received_stones_used' : 'received_stones_assigned');
                }
            } elseif ($membership) {
                self::note('stones_ordered_not_received');
            }
            if ($demand_id && $state === 'done') {
                CJS_Inv_Stock::consume_reserved($demand_id, 'Used in 1.x');
                CJS_Inv_DB::update('demands', ['state' => 'done', 'updated_at' => CJS_Inv_DB::now()], ['id' => $demand_id]);
                $demand = CJS_Inv_Demands::row($demand_id);
                if ($demand && (float) $demand['qty_allocated'] < (float) $demand['qty']) {
                    self::note('completed_orders_without_received_stone');
                }
            }
            $first = false;
        }
    }

    private static function free_identifiers($item_id, array $ids) {
        global $wpdb;
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $try = $id;
            $n = 2;
            while (in_array($try, $out, true) || $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id = %d AND identifier = %s', (int) $item_id, $try))) {
                $try = $id . '-' . $n++;
            }
            $out[] = $try;
        }
        return $out;
    }

    private static function finalize_po_lines() {
        global $wpdb;
        $lines = CJS_Inv_DB::t('stock_order_lines');
        $wpdb->query("UPDATE $lines SET in_cart = 1 WHERE in_cart > 1");
        $wpdb->query("UPDATE $lines SET qty_received = qty WHERE qty_received > qty");
    }

    public static function kit_code_parts($identifier) {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return [null, null];
        }
        if (preg_match('/^(.+?)-(\d+)(?:-([A-Za-z][\w-]*))?$/', $identifier, $m)) {
            $code = strtoupper($m[1] . (isset($m[3]) && $m[3] !== '' ? '-' . $m[3] : ''));
            return [$code, (int) $m[2]];
        }
        return [strtoupper($identifier), null];
    }

    private static function inventory_items() {
        global $wpdb;
        if (!self::legacy_table_exists('cjs_inventory_items')) {
            return;
        }
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cjs_inventory_items ORDER BY id ASC", ARRAY_A) ?: [];
        self::$report['legacy']['inventory_items'] = count($rows);
        $groups = [];
        foreach ($rows as $r) {
            if (self::mapped('inventory_item', (int) $r['id'], 'piece')) {
                self::note('inventory_items_already_mapped');
                continue;
            }
            [$code, $number] = self::kit_code_parts($r['identifier']);
            $category = trim((string) ($r['item_category'] ?? '')) ?: 'Matavimo Rinkinys';
            $base = mb_convert_case(mb_strtolower($category), MB_CASE_TITLE);
            $base = mb_strtoupper(mb_substr($base, 0, 1)) . mb_strtolower(mb_substr($base, 1));
            $suffix = '';
            if ($code && preg_match('/-([A-Z][A-Z0-9]*)$/', $code, $m) && preg_match('/-\d+-/', (string) $r['identifier'])) {
                $suffix = ' ' . mb_strtolower($m[1]);
            }
            $key = $category . '|' . ($code ?: ('name:' . mb_strtolower(trim((string) $r['name']))));
            $groups[$key]['category'] = $category;
            $groups[$key]['code'] = $code;
            $groups[$key]['name'] = $code ? $base . $suffix : (trim((string) $r['name']) ?: $base);
            $groups[$key]['rows'][] = ['row' => $r, 'number' => $number];
        }
        foreach ($groups as $group) {
            self::heartbeat();
            try {
                CJS_Inv_DB::transaction(function () use ($group) {
                    self::migrate_kit_group($group);
                });
            } catch (Throwable $e) {
                self::error('Inventory group ' . $group['name'] . ' failed: ' . $e->getMessage());
                self::note('inventory_groups_failed');
            }
        }
    }

    private static function migrate_kit_group(array $group) {
        global $wpdb;
        $category = $group['category'];
        if (mb_strtolower($category) === 'matavimo rinkinys') {
            $cat = CJS_Inv_Catalog::category_by_slug('sizing-kits');
            $cat_id = $cat ? (int) $cat['id'] : 0;
        } else {
            $cat = CJS_Inv_Catalog::category_by_slug(sanitize_title($category));
            $cat_id = $cat ? (int) $cat['id'] : CJS_Inv_Catalog::save_category(['name' => $category, 'slug' => sanitize_title($category), 'unit_id' => CJS_Inv_Catalog::unit_id_by_label('pcs'), 'returnable' => 1, 'per_piece' => 1, 'auto_assign' => 0]);
        }
        if (!$cat_id || is_wp_error($cat_id)) {
            throw new RuntimeException('No category for ' . $category);
        }
        $item_id = null;
        if ($group['code']) {
            $item_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE code = %s', $group['code']));
        }
        if (!$item_id) {
            $item_id = CJS_Inv_Catalog::find_or_create_item($cat_id, [], $group['name'], [
                'code' => $group['code'],
                'per_piece' => 1,
                'returnable' => 1,
            ]);
            if (is_wp_error($item_id)) {
                throw new RuntimeException($item_id->get_error_message());
            }
        }
        $item_id = (int) $item_id;
        $rows = $group['rows'];
        usort($rows, function ($a, $b) {
            return [$a['number'] ?? PHP_INT_MAX, (int) $a['row']['id']] <=> [$b['number'] ?? PHP_INT_MAX, (int) $b['row']['id']];
        });
        $identifiers = [];
        $notes = [];
        $renames = [];
        $used = [];
        foreach ($wpdb->get_col($wpdb->prepare('SELECT identifier FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id = %d AND identifier IS NOT NULL', $item_id)) as $taken) {
            $used[mb_strtoupper($taken)] = true;
        }
        $max = 0;
        foreach ($rows as $entry) {
            $max = max($max, (int) $entry['number']);
        }
        foreach ($rows as $entry) {
            $r = $entry['row'];
            $legacy_identifier = trim((string) $r['identifier']);
            if ($group['code'] && $entry['number'] !== null) {
                $identifier = CJS_Inv_Catalog::format_identifier($group['code'], $entry['number']);
            } else {
                $identifier = $legacy_identifier;
            }
            $legacy_name = trim((string) $r['name']);
            $note = ($legacy_name !== '' && mb_strtolower($legacy_name) !== mb_strtolower($group['name'])) ? $legacy_name : '';
            $renamed = '';
            if ($identifier !== '' && isset($used[mb_strtoupper($identifier)])) {
                $identifier = $group['code'] ? CJS_Inv_Catalog::format_identifier($group['code'], ++$max) : '';
                $renamed = $legacy_identifier;
                self::note('kit_identifiers_renamed');
            }
            if ($identifier !== '') {
                $used[mb_strtoupper($identifier)] = true;
            }
            $identifiers[] = $identifier;
            $notes[] = $note;
            $renames[] = $renamed;
        }
        $earliest = min(array_map(function ($e) { return $e['row']['created_at'] ?: CJS_Inv_DB::now(); }, $rows));
        $document_id = CJS_Inv_Stock::create_document('migration', 'Migrated from legacy inventory', CJS_Inv_DB::now());
        $batch_id = CJS_Inv_Stock::create_batch([
            'item_id' => $item_id,
            'qty' => count($rows),
            'identifiers' => $identifiers,
            'piece_notes' => $notes,
            'source_type' => 'opening',
            'received_at' => $earliest,
        ], $document_id);
        if (is_wp_error($batch_id)) {
            throw new RuntimeException($batch_id->get_error_message());
        }
        $pieces = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE batch_id = %d ORDER BY id ASC', $batch_id), ARRAY_A) ?: [];
        foreach ($rows as $k => $entry) {
            $r = $entry['row'];
            $piece = $pieces[$k] ?? null;
            if (!$piece) {
                continue;
            }
            self::map('inventory_item', (int) $r['id'], 'piece', (int) $piece['id']);
            self::note('inventory_items_migrated');
            $events = json_decode((string) $r['events'], true);
            if (is_array($events)) {
                foreach ($events as $ev) {
                    $field = (string) ($ev['field'] ?? '');
                    $note = $field === 'order_id'
                        ? (($ev['new'] ?? '') !== '' ? 'Order assigned to #' . $ev['new'] : 'Order unassigned (was #' . ($ev['old'] ?? '') . ')')
                        : ucfirst(str_replace('_', ' ', $field)) . ' changed from "' . ($ev['old'] ?? '') . '" to "' . ($ev['new'] ?? '') . '"';
                    $note .= isset($ev['user']) && $ev['user'] !== '' ? ' by ' . $ev['user'] : '';
                    $ts = isset($ev['timestamp']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $ev['timestamp']) ? substr($ev['timestamp'], 0, 16) . ':00' : CJS_Inv_DB::now();
                    CJS_Inv_Stock::log('legacy', $item_id, 0, ['batch_id' => $batch_id, 'piece_id' => (int) $piece['id'], 'note' => $note, 'user_id' => 0, 'created_at' => $ts]);
                    self::note('legacy_events_copied');
                }
            }
            $order_id = (int) ($r['order_id'] ?? 0);
            $status_label = trim((string) ($r['item_status'] ?? ''));
            $in_warehouse = $status_label === '' || mb_strtolower($status_label) === mb_strtolower('Sandėlyje');
            if (($renames[$k] ?? '') !== '') {
                CJS_Inv_Stock::log('legacy', $item_id, 0, ['batch_id' => $batch_id, 'piece_id' => (int) $piece['id'], 'note' => sprintf('Identifier in 1.x was %s', $renames[$k]), 'user_id' => 0]);
            }
            if ($in_warehouse) {
                if (!$order_id) {
                    continue;
                }
                self::note('kits_assigned_but_in_warehouse');
                $has_line = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND item_id = %d AND state <> 'cancelled'", $order_id, $item_id));
                if (self::demand_state_for_order($order_id) === 'open' && !$has_line) {
                    $demand_id = CJS_Inv_Demands::insert($order_id, null, $item_id, 1, [
                        'source' => 'migration',
                        'source_ref' => 'inventory:' . (int) $r['id'],
                        'created_at' => $r['updated_at'] ?: CJS_Inv_DB::now(),
                    ]);
                    self::map('inventory_item', (int) $r['id'], 'demand', $demand_id);
                    self::mark_requirements_applied($order_id);
                    $got = CJS_Inv_Stock::allocate_demand($demand_id, ['piece_id' => (int) $piece['id'], 'note' => sprintf('Assigned to order #%d in 1.x, not sent yet', $order_id)]);
                    self::note($got > 0 ? 'kits_to_send' : 'kit_lines_waiting_to_be_sent');
                } else {
                    CJS_Inv_Stock::log('legacy', $item_id, 0, [
                        'batch_id' => $batch_id,
                        'piece_id' => (int) $piece['id'],
                        'order_id' => $order_id,
                        'note' => sprintf('Was assigned to order #%d in 1.x while still in stock', $order_id),
                        'user_id' => 0,
                    ]);
                }
                continue;
            }
            $state = $order_id ? self::demand_state_for_order($order_id) : 'done';
            $demand_id = CJS_Inv_Demands::insert($order_id, null, $item_id, 1, [
                'source' => 'migration',
                'source_ref' => 'inventory:' . (int) $r['id'],
                'state' => 'open',
                'created_at' => $r['updated_at'] ?: CJS_Inv_DB::now(),
            ]);
            self::map('inventory_item', (int) $r['id'], 'demand', $demand_id);
            if ($order_id) {
                self::mark_requirements_applied($order_id);
            } else {
                CJS_Inv_Stock::log('legacy', $item_id, 0, ['batch_id' => $batch_id, 'piece_id' => (int) $piece['id'], 'demand_id' => $demand_id, 'note' => sprintf('Status in 1.x: %s, without an order', $status_label), 'user_id' => 0]);
            }
            CJS_Inv_Stock::allocate_demand($demand_id, ['piece_id' => (int) $piece['id']]);
            $live = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state = 'reserved' ORDER BY id DESC LIMIT 1", $demand_id));
            if ($live) {
                CJS_Inv_Stock::send_allocation($live, sprintf('Status in 1.x: %s', $status_label));
            }
            if ($state !== 'open') {
                CJS_Inv_DB::update('demands', ['state' => 'done', 'updated_at' => CJS_Inv_DB::now()], ['id' => $demand_id]);
            }
            self::note($order_id ? 'kits_out_on_orders' : 'kits_out_without_order');
        }
    }

    private static function size_kit_settings() {
        global $wpdb;
        CJS_Size_Kit::maybe_move_settings();
        $cat = CJS_Inv_Catalog::category_by_slug('sizing-kits');
        if (!$cat || CJS_Size_Kit::offer_of($cat)['items']) {
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND archived = 0 ORDER BY (code LIKE %s), id ASC', (int) $cat['id'], '%MINI%'));
        if (get_option(CJS_Size_Kit::MOVED_OPTION)) {
            CJS_Size_Kit::save_kit_offer(['items' => array_map('intval', $ids)]);
        } else {
            update_option('cjs_size_kit_items', array_map('intval', $ids), false);
        }
    }
}
