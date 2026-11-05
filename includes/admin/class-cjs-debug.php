<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Debug {

    const LEGACY_TABLES = ['cjs_stones', 'cjs_stone_orders', 'cjs_stone_order_items', 'cjs_inventory_items', 'cjs_order_extensions', 'cjs_order_files', 'cjs_activity_log', 'cjs_calendar_intervals', 'cjs_options_sort_order'];

    private static function count_table($table) {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    private static function safe(callable $fn) {
        try {
            return $fn();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function report() {
        global $wpdb;
        $report = [
            'generated_at' => current_time('mysql'),
            'environment' => [
                'plugin_version' => CJS_VERSION,
                'db_version' => get_option('cjs_db_version'),
                'php' => PHP_VERSION,
                'mysql' => $wpdb->db_version(),
                'wordpress' => get_bloginfo('version'),
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
                'hpos' => CJS_Orders::hpos(),
                'prefix' => $wpdb->prefix,
                'install_error' => get_option('cjs_install_error', null),
            ],
        ];
        $report['tables'] = self::safe(function () use ($wpdb) {
            $new = [];
            foreach (CJS_Inv_DB::TABLES as $name) {
                $new[$name] = self::count_table(CJS_Inv_DB::t($name));
            }
            $legacy = [];
            foreach (self::LEGACY_TABLES as $name) {
                $legacy[$name] = self::count_table($wpdb->prefix . $name);
            }
            return ['inventory' => $new, 'legacy' => $legacy, 'missing_inventory_tables' => CJS_Inv_DB::missing_tables()];
        });
        $migration = get_option(CJS_Inv_Migration::REPORT_OPTION, []);
        $report['migration'] = [
            'done' => CJS_Inv_Migration::is_done(),
            'status' => $migration['status'] ?? 'not run',
            'started_at' => $migration['started_at'] ?? null,
            'finished_at' => $migration['finished_at'] ?? null,
            'duration_seconds' => $migration['duration_seconds'] ?? null,
            'counts' => $migration['counts'] ?? [],
            'legacy' => $migration['legacy'] ?? [],
            'errors' => array_slice($migration['errors'] ?? [], 0, 50),
            'error_count' => count($migration['errors'] ?? []),
        ];
        $report['model_upgrade'] = self::safe(function () {
            $log = get_option(CJS_Inv_Migration::MODEL_LOG_OPTION, []);
            return [
                'schema_version' => (int) get_option(CJS_Inv_DB::SCHEMA_OPTION, 0),
                'schema_backoff' => CJS_Inv_DB::backoff_active(),
                'model_version' => (int) get_option(CJS_Inv_Migration::MODEL_OPTION, 0),
                'log' => is_array($log) ? $log : [],
            ];
        });
        $report['checks'] = self::safe([__CLASS__, 'checks']);
        $report['integrity'] = self::safe(function () {
            return CJS_Inv_Stock::integrity_report(100);
        });
        $report['inventory'] = self::safe([__CLASS__, 'inventory_overview']);
        $report['options'] = self::safe(function () {
            return [
                'manufacturing_statuses' => CJS_Statuses::label_list(),
                'status_labels' => CJS_Statuses::labels(),
                'status_model' => get_option(CJS_Statuses::LOG_OPTION, []),
                'order_types' => array_values(CJS_Order_Extension::get_ordered_options('order_types')),
                'stock_order_statuses' => array_keys(CJS_Inv_Stock_Orders::statuses()),
                'legacy_stone_order_statuses' => array_keys((array) get_option('cjs_stone_order_statuses', [])),
                'size_kit_items' => CJS_Size_Kit::kit_offer()['items'],
                'size_kit_auto_assign' => (bool) (int) ((CJS_Inv_Catalog::category_by_slug('sizing-kits') ?: [])['auto_assign'] ?? 0),
            ];
        });
        return $report;
    }

    public static function checks() {
        global $wpdb;
        $out = [];
        $map = CJS_Inv_DB::t('migration_map');
        if (CJS_Inv_Migration::legacy_table_exists('cjs_stones')) {
            $stones = $wpdb->prefix . 'cjs_stones';
            $out['stones_total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $stones");
            $out['stones_mapped_to_item'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $map WHERE legacy_type = 'stone' AND new_type = 'item'");
            $out['stones_unmapped_ids'] = array_map('intval', $wpdb->get_col("SELECT s.id FROM $stones s WHERE NOT EXISTS (SELECT 1 FROM $map m WHERE m.legacy_type = 'stone' AND m.legacy_id = s.id AND m.new_type = 'item') LIMIT 50"));
            $legacy_qty = (float) $wpdb->get_var("SELECT COALESCE(SUM(GREATEST(stone_quantity, 1)), 0) FROM $stones WHERE order_id > 0");
            $demand_qty = (float) $wpdb->get_var('SELECT COALESCE(SUM(d.qty), 0) FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.source = 'migration' AND d.source_ref LIKE 'stone:%' AND d.order_id > 0");
            $out['stone_qty_on_orders_legacy'] = $legacy_qty;
            $out['stone_qty_on_orders_demands'] = $demand_qty;
            $out['stone_qty_match'] = abs($legacy_qty - $demand_qty) < 0.001;
            $per_order = $wpdb->get_results("SELECT s.order_id, SUM(GREATEST(s.stone_quantity, 1)) AS legacy_qty,
                (SELECT COALESCE(SUM(d.qty), 0) FROM " . CJS_Inv_DB::t('demands') . " d WHERE d.order_id = s.order_id AND d.source = 'migration' AND d.source_ref LIKE 'stone:%') AS new_qty
                FROM $stones s WHERE s.order_id > 0 GROUP BY s.order_id HAVING ABS(legacy_qty - new_qty) > 0.001 LIMIT 50", ARRAY_A) ?: [];
            $out['orders_with_qty_mismatch'] = $per_order;
            if (CJS_Inv_Migration::legacy_table_exists('cjs_stone_order_items')) {
                $soi = $wpdb->prefix . 'cjs_stone_order_items';
                $cols = $wpdb->get_col("SHOW COLUMNS FROM $soi");
                if (in_array('received', $cols, true)) {
                    $out['received_stone_qty_legacy'] = (float) $wpdb->get_var("SELECT COALESCE(SUM(GREATEST(s.stone_quantity, 1)), 0) FROM $soi i INNER JOIN $stones s ON s.id = i.stone_id WHERE i.received = 1 AND i.id = (SELECT MIN(i2.id) FROM $soi i2 WHERE i2.stone_id = i.stone_id AND i2.received = 1)");
                    $out['received_qty_in_batches'] = (float) $wpdb->get_var('SELECT COALESCE(SUM(b.qty_received), 0) FROM ' . CJS_Inv_DB::t('batches') . ' b INNER JOIN ' . CJS_Inv_DB::t('documents') . " d ON d.id = b.document_id WHERE d.type = 'migration' AND b.source_type = 'stock_order'");
                    $report = get_option(CJS_Inv_Migration::REPORT_OPTION, []);
                    $counts = is_array($report) && isset($report['counts']) && is_array($report['counts']) ? $report['counts'] : [];
                    $out['received_qty_not_stocked'] = (float) ($counts['received_qty_not_stocked'] ?? 0);
                    $out['received_stones_not_stocked'] = (int) ($counts['received_stones_not_stocked'] ?? 0);
                    $out['received_stones_assigned'] = (int) ($counts['received_stones_assigned'] ?? 0);
                    $out['used_qty_not_ticked_received'] = (float) ($counts['used_qty_not_ticked_received'] ?? 0);
                    $out['used_qty_without_stone_order'] = (float) ($counts['used_qty_without_stone_order'] ?? 0);
                    $out['received_qty_explained'] = $out['received_qty_in_batches'] - $out['used_qty_not_ticked_received'] + $out['received_qty_not_stocked'];
                    $out['received_qty_match'] = abs($out['received_stone_qty_legacy'] - $out['received_qty_explained']) < 0.001;
                }
                $out['stone_order_memberships_legacy'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT stone_id) FROM $soi");
                $out['stones_linked_to_po_lines'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $map WHERE legacy_type = 'stone' AND new_type = 'po_line'");
            }
        }
        if (CJS_Inv_Migration::legacy_table_exists('cjs_stone_orders')) {
            $out['stone_orders_legacy'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cjs_stone_orders");
            $out['stock_orders_migrated'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE legacy_id IS NOT NULL');
            $out['stock_orders_status_unknown'] = $wpdb->get_col('SELECT DISTINCT status FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE status NOT IN (' . implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, array_keys(CJS_Inv_Stock_Orders::statuses()) ?: ['__none__'])) . ')');
        }
        if (CJS_Inv_Migration::legacy_table_exists('cjs_inventory_items')) {
            $out['inventory_items_legacy'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cjs_inventory_items");
            $out['inventory_items_assigned_legacy'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cjs_inventory_items WHERE order_id IS NOT NULL AND order_id > 0");
            $out['pieces_migrated'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $map WHERE legacy_type = 'inventory_item' AND new_type = 'piece'");
            $out['pieces_out'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('pieces') . " WHERE status = 'out'");
            $out['piece_identifiers'] = $wpdb->get_results('SELECT p.identifier, p.status, p.note, i.name AS item, i.code FROM ' . CJS_Inv_DB::t('pieces') . ' p INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = p.item_id ORDER BY i.id, p.id LIMIT 100', ARRAY_A);
        }
        $out['demand_states'] = $wpdb->get_results('SELECT state, source, COUNT(*) AS n, SUM(qty) AS qty, SUM(qty_allocated) AS allocated FROM ' . CJS_Inv_DB::t('demands') . ' GROUP BY state, source', ARRAY_A);
        $out['open_demands_missing'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . " WHERE state = 'open' AND qty_allocated < qty");
        $out['open_demands_on_closed_orders'] = self::open_demands_on_closed_orders();
        $out['duplicate_item_signatures'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM (SELECT category_id, signature FROM ' . CJS_Inv_DB::t('items') . ' GROUP BY category_id, signature HAVING COUNT(*) > 1) x');
        $out['orders_active_without_extension'] = self::orders_without_extension();
        return $out;
    }

    private static function open_demands_on_closed_orders() {
        global $wpdb;
        $ids = $wpdb->get_col('SELECT DISTINCT order_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE state = 'open' AND order_id > 0 LIMIT 2000");
        $labels = CJS_Orders::order_labels(array_map('intval', $ids));
        $bad = [];
        foreach ($labels as $l) {
            if (in_array($l['status'], ['completed', 'cancelled', 'refunded', 'failed', 'trash', 'deleted'], true)) {
                $bad[] = ['order_id' => $l['id'], 'status' => $l['status']];
            }
        }
        return ['count' => count($bad), 'sample' => array_slice($bad, 0, 30)];
    }

    private static function orders_without_extension() {
        global $wpdb;
        $q = CJS_Orders::query(['per_page' => 1]);
        $hpos = CJS_Orders::hpos();
        $orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $id = $hpos ? 'o.id' : 'o.ID';
        $type = $hpos ? "o.type = 'shop_order'" : "o.post_type = 'shop_order'";
        $status = $hpos ? 'o.status' : 'o.post_status';
        $missing = (int) $wpdb->get_var("SELECT COUNT(*) FROM $orders o LEFT JOIN {$wpdb->prefix}cjs_order_extensions e ON e.order_id = $id WHERE $type AND $status NOT IN ('wc-completed','wc-cancelled','wc-refunded','wc-failed','wc-checkout-draft','trash','auto-draft') AND e.id IS NULL");
        return ['active_orders' => $q['total'], 'without_extension_row' => $missing];
    }

    public static function inventory_overview() {
        global $wpdb;
        return [
            'items_by_category' => $wpdb->get_results('SELECT c.name, COUNT(i.id) AS items, COALESCE(SUM(i.qty_on_hand), 0) AS on_hand, COALESCE(SUM(i.qty_reserved), 0) AS reserved FROM ' . CJS_Inv_DB::t('categories') . ' c LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.category_id = c.id GROUP BY c.id ORDER BY c.sort_order', ARRAY_A),
            'stock_orders_by_status' => $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . CJS_Inv_DB::t('stock_orders') . ' GROUP BY status', ARRAY_A),
            'movements_by_type' => $wpdb->get_results('SELECT type, COUNT(*) AS n, SUM(qty) AS qty FROM ' . CJS_Inv_DB::t('movements') . ' GROUP BY type', ARRAY_A),
            'open_discrepancies' => CJS_Inv_Discrepancies::open_count(),
            'units' => array_values(CJS_Inv_Catalog::units()),
        ];
    }

    private static function render_safety($email_test, $http_test) {
        $on = CJS_Safe_Mode::active();
        $s = CJS_Safe_Mode::settings();
        $stale = !empty($s['on']) && !$on;
        $result = function ($value) {
            if ($value === null) {
                return esc_html__('not available', 'custom-jewelry-system');
            }
            return $value ? '<strong style="color:#b32d2e;">' . esc_html__('SENT', 'custom-jewelry-system') . '</strong>' : '<strong style="color:#008a20;">' . esc_html__('BLOCKED', 'custom-jewelry-system') . '</strong>';
        };
        $me = wp_get_current_user();
        ?>
        <div style="margin:0 0 16px;padding:12px 14px;background:#fff;border:1px solid <?php echo $on ? '#dba617' : '#c3c4c7'; ?>;border-left-width:4px;">
            <h2 style="margin:0 0 6px;"><?php esc_html_e('Staging safety switch', 'custom-jewelry-system'); ?></h2>
            <p style="margin:0 0 8px;"><?php esc_html_e('Blocks every outgoing email (WooCommerce order emails, admin emails, plugin emails) and every connection to outside services (email services, SMS, Omnisend, payment providers, webhooks). Use it on the staging site so you can change, cancel or refund orders without anyone being contacted.', 'custom-jewelry-system'); ?></p>
            <p style="margin:0 0 8px;">
                <?php if ($on) : ?>
                    <strong style="color:#008a20;"><?php esc_html_e('ON', 'custom-jewelry-system'); ?></strong>
                    <?php echo esc_html(sprintf(__('for %1$s, turned on %2$s by %3$s.', 'custom-jewelry-system'), $s['host'] ?? '', $s['at'] ?? '', $s['by'] ?? '')); ?>
                <?php else : ?>
                    <strong><?php esc_html_e('OFF', 'custom-jewelry-system'); ?></strong>
                    <?php esc_html_e('Emails and outside connections work normally.', 'custom-jewelry-system'); ?>
                <?php endif; ?>
            </p>
            <?php if ($stale) : ?>
                <p style="margin:0 0 8px;color:#b32d2e;"><?php echo esc_html(sprintf(__('It was turned on for %1$s, so it is not active on this site (%2$s).', 'custom-jewelry-system'), $s['host'] ?? '', CJS_Safe_Mode::site_host())); ?></p>
            <?php endif; ?>
            <p style="margin:0 0 10px;color:#646970;"><?php echo esc_html(sprintf(__('It only works on the site address it was turned on for (%s). If this database is copied to the live site, the switch does nothing there and live emails keep working.', 'custom-jewelry-system'), CJS_Safe_Mode::site_host())); ?></p>
            <form method="post" style="display:inline-block;margin:0 8px 0 0;">
                <?php wp_nonce_field('cjs_safe_toggle'); ?>
                <?php if ($on) : ?>
                    <button type="submit" name="cjs_safe_toggle" value="off" class="button" onclick="return confirm('Turn the safety switch off? Emails and outside connections will work again.');"><?php esc_html_e('Turn off', 'custom-jewelry-system'); ?></button>
                <?php else : ?>
                    <button type="submit" name="cjs_safe_toggle" value="on" class="button button-primary"><?php esc_html_e('Turn on: block all emails and outside connections', 'custom-jewelry-system'); ?></button>
                <?php endif; ?>
            </form>
            <form method="post" style="display:inline-block;margin:0;">
                <?php wp_nonce_field('cjs_safe_test'); ?>
                <button type="submit" name="cjs_safe_test_email" value="1" class="button"><?php echo esc_html(sprintf(__('Send a test email to me (%s)', 'custom-jewelry-system'), $me ? $me->user_email : '')); ?></button>
                <button type="submit" name="cjs_safe_test_http" value="1" class="button"><?php esc_html_e('Test an outside connection', 'custom-jewelry-system'); ?></button>
            </form>
            <?php if ($email_test) : ?>
                <div style="margin:10px 0 0;padding:8px 10px;background:#f6f7f7;">
                    <?php if (empty($email_test['ok'])) : ?>
                        <?php echo esc_html($email_test['text']); ?>
                    <?php else : ?>
                        <div><?php esc_html_e('WooCommerce mailer (the same path as order emails):', 'custom-jewelry-system'); ?> <?php echo $result($email_test['woo']); ?></div>
                        <div><?php esc_html_e('WordPress wp_mail:', 'custom-jewelry-system'); ?> <?php echo $result($email_test['wp']); ?></div>
                        <div style="color:#646970;"><?php echo esc_html($on ? __('Nothing should arrive in your inbox.', 'custom-jewelry-system') : sprintf(__('Check %s: two test emails should arrive. SENT means WordPress handed them to the mail server.', 'custom-jewelry-system'), $email_test['to'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($http_test) : ?>
                <div style="margin:10px 0 0;padding:8px 10px;background:#f6f7f7;">
                    <?php esc_html_e('Outside connection:', 'custom-jewelry-system'); ?>
                    <?php echo $http_test['blocked'] ? '<strong style="color:#008a20;">' . esc_html__('BLOCKED', 'custom-jewelry-system') . '</strong>' : '<strong style="color:#b32d2e;">' . esc_html__('ALLOWED', 'custom-jewelry-system') . '</strong>'; ?>
                    <span style="color:#646970;"><?php echo esc_html($http_test['text']); ?></span>
                </div>
            <?php endif; ?>
            <?php $log = CJS_Safe_Mode::log(); ?>
            <?php if ($log) : ?>
                <details style="margin:10px 0 0;">
                    <summary><?php echo esc_html(sprintf(__('Last %d blocked attempts', 'custom-jewelry-system'), count($log))); ?></summary>
                    <table class="widefat striped" style="margin-top:6px;">
                        <thead><tr><th><?php esc_html_e('When', 'custom-jewelry-system'); ?></th><th><?php esc_html_e('What', 'custom-jewelry-system'); ?></th><th><?php esc_html_e('To', 'custom-jewelry-system'); ?></th><th><?php esc_html_e('Details', 'custom-jewelry-system'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($log as $row) : ?>
                                <tr><td><?php echo esc_html($row['at'] ?? ''); ?></td><td><?php echo esc_html($row['type'] ?? ''); ?></td><td><?php echo esc_html($row['target'] ?? ''); ?></td><td><?php echo esc_html($row['detail'] ?? ''); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function rerun() {
        try {
            CJS_Inv_DB::maybe_update_schema(true);
            if (CJS_Inv_Migration::is_done() && CJS_Inv_Migration::model_needs_upgrade()) {
                CJS_Inv_Migration::upgrade_model(true);
            }
        } catch (Throwable $e) {
            error_log('CJS forced schema update failed: ' . $e->getMessage());
        }
        return CJS_Inv_Migration::run(true);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['cjs_rerun_migration']) && check_admin_referer('cjs_rerun_migration')) {
            self::rerun();
        }
        if (isset($_POST['cjs_reset_migration']) && check_admin_referer('cjs_reset_migration') && trim((string) ($_POST['cjs_reset_confirm'] ?? '')) === 'RESET') {
            CJS_Inv_Migration::reset();
        }
        $email_test = isset($_POST['cjs_safe_test_email']) && check_admin_referer('cjs_safe_test') ? CJS_Safe_Mode::test_email() : null;
        $http_test = isset($_POST['cjs_safe_test_http']) && check_admin_referer('cjs_safe_test') ? CJS_Safe_Mode::test_connection() : null;
        $json = wp_json_encode(self::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CJS 2.0 migration debug', 'custom-jewelry-system'); ?></h1>
            <p><?php esc_html_e('Temporary page. Copy the JSON below and paste it to the developer. It contains counts and IDs only, no customer details.', 'custom-jewelry-system'); ?></p>
            <p>
                <button type="button" class="button button-primary" id="cjs-debug-copy"><?php esc_html_e('Copy JSON', 'custom-jewelry-system'); ?></button>
                <span id="cjs-debug-copied" style="margin-left:8px;color:#15803d;display:none;"><?php esc_html_e('Copied', 'custom-jewelry-system'); ?></span>
            </p>
            <?php self::render_safety($email_test, $http_test); ?>
            <form method="post" style="margin:0 0 12px;">
                <?php wp_nonce_field('cjs_rerun_migration'); ?>
                <button type="submit" name="cjs_rerun_migration" value="1" class="button" onclick="return confirm('Re-run the migration? Already migrated rows are skipped.');"><?php esc_html_e('Re-run migration (safe, skips migrated rows)', 'custom-jewelry-system'); ?></button>
            </form>
            <form method="post" style="margin:0 0 12px;padding:10px 12px;border:1px solid #d63638;background:#fff;">
                <?php wp_nonce_field('cjs_reset_migration'); ?>
                <strong><?php esc_html_e('Start over:', 'custom-jewelry-system'); ?></strong>
                <?php esc_html_e('deletes all CJS 2.0 inventory data (items, stock, stock orders, history) and migrates again from the untouched 1.x tables. For staging only.', 'custom-jewelry-system'); ?>
                <br><label><?php esc_html_e('Type RESET to confirm:', 'custom-jewelry-system'); ?> <input type="text" name="cjs_reset_confirm" autocomplete="off" style="width:90px;"></label>
                <button type="submit" name="cjs_reset_migration" value="1" class="button button-link-delete"><?php esc_html_e('Reset and migrate again', 'custom-jewelry-system'); ?></button>
            </form>
            <textarea id="cjs-debug-json" readonly style="width:100%;min-height:70vh;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea($json); ?></textarea>
        </div>
        <script>
            document.getElementById('cjs-debug-copy').addEventListener('click', function () {
                var t = document.getElementById('cjs-debug-json');
                t.select();
                var done = function () { document.getElementById('cjs-debug-copied').style.display = 'inline'; };
                if (navigator.clipboard) { navigator.clipboard.writeText(t.value).then(done, function () { document.execCommand('copy'); done(); }); } else { document.execCommand('copy'); done(); }
            });
        </script>
        <?php
    }
}
