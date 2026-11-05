<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Woo_Sync {

    const UNATTENDED_COMPLETED_SETTLES = true;
    const DISCREPANCY_TYPE = 'unattended';
    const NEGATIVE = ['cancelled', 'refunded', 'failed'];
    const LABELS_OPTION = 'cjs_deleted_order_labels';
    const ASSIGN_STATUSES = ['processing', 'partially-paid', 'completed'];

    public static function init() {
        add_action('cjs_inv_unattended_status_change', [__CLASS__, 'on_unattended'], 10, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 40, 4);
        add_filter('cjs_inv_may_auto_assign', [__CLASS__, 'may_auto_assign'], 10, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'stamp_started'], 45, 4);
    }

    public static function stamp_started($order_id, $from, $to, $order = null) {
        try {
            if (in_array($to, ['processing', 'partially-paid'], true)) {
                CJS_Statuses::stamp_first((int) $order_id, 'nepradeta');
            }
        } catch (Throwable $e) {
            error_log('CJS start stamp failed for order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    public static function locked($order_id) {
        return CJS_Inv_Demands::order_status_fast((int) $order_id) === 'completed';
    }

    public static function locked_error() {
        return new WP_Error('cjs_locked', __('This order is Completed in WooCommerce, so it cannot be changed here. Change its WooCommerce status first.', 'custom-jewelry-system'), ['status' => 423]);
    }

    public static function calendar_lock($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }
        return self::calendar_locks([$order_id])[$order_id] ?? null;
    }

    public static function calendar_locks(array $order_ids) {
        global $wpdb;
        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
        if (!$order_ids) {
            return [];
        }
        $out = [];
        foreach (CJS_Inv_Demands::order_statuses_fast($order_ids) as $id => $status) {
            if ($status === 'completed') {
                $out[(int) $id] = 'completed';
            }
        }
        $rows = $wpdb->get_results('SELECT order_id, manufacturing_status FROM ' . $wpdb->prefix . 'cjs_order_extensions WHERE order_id IN (' . implode(',', $order_ids) . ')', ARRAY_A) ?: [];
        $min = CJS_Inv_Flow::rank('pagaminta');
        foreach ($rows as $r) {
            $id = (int) $r['order_id'];
            if (isset($out[$id])) {
                continue;
            }
            $key = CJS_Inv_Flow::status_key((string) $r['manufacturing_status']) ?? 'nepradeta';
            if (CJS_Inv_Flow::rank($key) >= $min) {
                $out[$id] = 'made';
            }
        }
        return $out;
    }

    public static function calendar_lock_error($reason) {
        if ($reason === 'completed') {
            return self::locked_error();
        }
        $label = CJS_Inv_Flow::display_label('pagaminta');
        return new WP_Error('cjs_locked', sprintf(__('This order is %1$s or later, so its calendar entries are locked. Move the order back before %1$s to change them.', 'custom-jewelry-system'), $label), ['status' => 423]);
    }

    public static function may_auto_assign($ok, $demand, $rules) {
        if (!$ok) {
            return $ok;
        }
        return in_array(CJS_Inv_Demands::order_status_fast((int) $demand['order_id']), self::ASSIGN_STATUSES, true);
    }

    public static function on_status_changed($order_id, $from, $to, $order = null) {
        try {
            if ($to !== 'completed') {
                return;
            }
            CJS_Statuses::clear_calendar((int) $order_id);
            if (CJS_Inv_Flow::order_status_key((int) $order_id) === 'done') {
                CJS_Statuses::fill_hours((int) $order_id, 'done');
                return;
            }
            if (!CJS_Inv_Flow::is_attended((int) $order_id, 'completed')) {
                return;
            }
            try {
                $r = CJS_Inv_Flow::apply([(int) $order_id], 'done', [(int) $order_id => []]);
            } catch (Throwable $e) {
                error_log('CJS completed sync apply failed for order ' . $order_id . ': ' . $e->getMessage());
                $r = new WP_Error('cjs_error', $e->getMessage());
            }
            if (is_wp_error($r)) {
                $used = self::complete_untouched((int) $order_id);
                self::log_discrepancy((int) $order_id, (string) $from, (string) $to, $used);
            }
        } catch (Throwable $e) {
            error_log('CJS completed sync failed for order ' . $order_id . ': ' . $e->getMessage());
            CJS_Logger::log('Setting DONE on WooCommerce Completed failed', 'error', 'order', (int) $order_id, ['error' => $e->getMessage()]);
        }
    }

    public static function on_unattended($order_id, $from, $to) {
        try {
            $used = false;
            if ($to === 'completed') {
                $used = self::complete_untouched((int) $order_id);
            }
            self::log_discrepancy((int) $order_id, (string) $from, (string) $to, $used);
        } catch (Throwable $e) {
            error_log('CJS unattended status change handling failed for order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    private static function complete_untouched($order_id) {
        global $wpdb;
        if (CJS_Inv_Flow::order_status_key($order_id) !== 'done') {
            CJS_Inv_Flow::with_guard([$order_id], function () use ($order_id) {
                return CJS_Statuses::write($order_id, 'done');
            });
        }
        if (!self::UNATTENDED_COMPLETED_SETTLES) {
            return false;
        }
        $m = CJS_Inv_DB::t('movements');
        $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id), 0) FROM $m WHERE order_id = %d", (int) $order_id));
        try {
            CJS_Inv_Flow::settle_order($order_id, 'done');
        } catch (Throwable $e) {
            error_log('CJS settle on unattended Completed failed for order ' . $order_id . ': ' . $e->getMessage());
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $m WHERE order_id = %d AND type = 'use' AND id > %d", (int) $order_id, $before)) > 0;
    }

    private static function status_word($status) {
        if ($status === 'trash') {
            return __('Trash', 'custom-jewelry-system');
        }
        if ($status === 'delete') {
            return __('Deleted permanently', 'custom-jewelry-system');
        }
        if ($status === '') {
            return '?';
        }
        return wp_strip_all_tags(CJS_Orders::status_label($status));
    }

    public static function log_discrepancy($order_id, $from, $to, $used = false) {
        global $wpdb;
        if (self::in_order((int) $order_id, (string) $to)) {
            return;
        }
        $settled = $to === 'completed' && self::UNATTENDED_COMPLETED_SETTLES && $used;
        if ($settled) {
            $details = $from !== ''
                ? sprintf(__('WooCommerce status changed from %1$s to %2$s without a person. Reserved stock was used. Nothing else was changed.', 'custom-jewelry-system'), self::status_word($from), self::status_word($to))
                : sprintf(__('WooCommerce status changed to %s without a person. Reserved stock was used. Nothing else was changed.', 'custom-jewelry-system'), self::status_word($to));
        } else {
            $details = $from !== ''
                ? sprintf(__('WooCommerce status changed from %1$s to %2$s without a person. Inventory was not changed.', 'custom-jewelry-system'), self::status_word($from), self::status_word($to))
                : sprintf(__('WooCommerce status changed to %s without a person. Inventory was not changed.', 'custom-jewelry-system'), self::status_word($to));
        }
        $open = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('discrepancies') . ' WHERE type = %s AND order_id = %d AND resolved = 0 ORDER BY id DESC LIMIT 1', self::DISCREPANCY_TYPE, (int) $order_id));
        if ($open) {
            CJS_Inv_DB::update('discrepancies', ['details' => $details], ['id' => (int) $open]);
        } else {
            CJS_Inv_Discrepancies::add(self::DISCREPANCY_TYPE, ['order_id' => (int) $order_id, 'details' => $details]);
        }
        CJS_Logger::log('Status changed without a person, discrepancy logged', 'warning', 'order', (int) $order_id, ['from' => $from, 'to' => $to]);
        if ($to === 'delete') {
            self::remember_label((int) $order_id);
        }
        if (!in_array($to, ['trash', 'delete'], true)) {
            CJS_Inv_Discrepancies::auto_close([], [(int) $order_id]);
        }
    }

    private static function remember_label($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $map = get_option(self::LABELS_OPTION, []);
        $map = is_array($map) ? $map : [];
        $map[(int) $order_id] = [
            'number' => (string) $order->get_order_number(),
            'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        ];
        if (count($map) > 500) {
            $map = array_slice($map, -500, null, true);
        }
        update_option(self::LABELS_OPTION, $map, false);
    }

    public static function deleted_label($order_id) {
        $map = get_option(self::LABELS_OPTION, []);
        $stored = is_array($map) && isset($map[(int) $order_id]) && is_array($map[(int) $order_id]) ? $map[(int) $order_id] : [];
        return [
            'number' => (string) (($stored['number'] ?? '') !== '' ? $stored['number'] : (int) $order_id),
            'customer' => (string) ($stored['customer'] ?? ''),
        ];
    }

    public static function fix_for($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        if (!$order) {
            return CJS_Inv_Demands::has_stock((int) $order_id) ? ['kind' => 'cancel', 'target' => 'delete'] : null;
        }
        $status = (string) $order->get_status();
        if (in_array($status, self::NEGATIVE, true) || $status === 'trash') {
            return ['kind' => 'cancel', 'target' => $status];
        }
        if ($status === 'completed') {
            return ['kind' => 'status', 'target' => 'done'];
        }
        return ['kind' => 'status', 'target' => CJS_Inv_Flow::order_status_key((int) $order_id)];
    }

    public static function in_order($order_id, $to = '') {
        $order_id = (int) $order_id;
        if (in_array($to, ['trash', 'delete'], true)) {
            return !CJS_Inv_Demands::has_stock($order_id);
        }
        $fix = self::fix_for($order_id);
        if ($fix === null || $fix['target'] === 'delete') {
            return !CJS_Inv_Demands::has_stock($order_id);
        }
        $p = CJS_Inv_Flow::preview_order($order_id, $fix['target']);
        if (is_wp_error($p)) {
            return false;
        }
        if ($fix['kind'] === 'cancel') {
            return empty($p['has_inventory']);
        }
        if (!empty($p['needs_decision']) || CJS_Inv_Flow::order_status_key($order_id) !== $fix['target']) {
            return false;
        }
        return !self::would_settle($order_id, $fix['target']);
    }

    public static function would_settle($order_id, $key) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND state = 'open'", (int) $order_id), ARRAY_A) ?: [];
        foreach ($rows as $d) {
            $rules = CJS_Inv_Flow::line_rules($d);
            if (!$rules['exists'] || !CJS_Inv_Flow::due_reached($rules, $key)) {
                continue;
            }
            if ($rules['returnable']) {
                $states = CJS_Inv_Flow::alloc_states((int) $d['id']);
                if (!in_array('reserved', $states, true) && !in_array('out', $states, true) && in_array('returned', $states, true) && (float) $d['qty_allocated'] <= CJS_Inv_Flow::EPS) {
                    return true;
                }
                continue;
            }
            if ((float) $d['qty_allocated'] >= (float) $d['qty'] - CJS_Inv_Flow::EPS) {
                return true;
            }
        }
        return false;
    }

    public static function woo_status_value($value) {
        $value = sanitize_key(preg_replace('/^wc-/', '', strtolower(trim((string) $value))));
        if ($value === '') {
            return '';
        }
        $all = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];
        if (!in_array('wc-' . $value, $all, true) || in_array($value, array_merge(self::NEGATIVE, ['checkout-draft']), true)) {
            return null;
        }
        return $value;
    }

    public static function check_apply_lock(array $order_ids, $target, $woo_status) {
        if ($target === null || $target === '' || CJS_Inv_Flow::is_woo_target($target)) {
            return true;
        }
        $key = CJS_Inv_Flow::status_key($target);
        foreach ($order_ids as $id) {
            if (!self::locked((int) $id)) {
                continue;
            }
            if ($key === 'done' || $key === CJS_Inv_Flow::order_status_key((int) $id) || ($woo_status !== '' && $woo_status !== 'completed')) {
                continue;
            }
            return self::locked_error();
        }
        return true;
    }

    public static function set_woo_status(array $order_ids, $woo_status, $perform) {
        $out = [];
        foreach ($order_ids as $id) {
            $id = (int) $id;
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }
            if ($perform) {
                CJS_Inv_Flow::with_guard([$id], function () use ($order, $woo_status) {
                    if (!$order->has_status($woo_status)) {
                        $order->update_status($woo_status);
                    }
                });
            } else {
                CJS_Inv_Flow::mark_attended($id, $woo_status);
            }
            $fresh = wc_get_order($id);
            $out[] = ['order_id' => $id, 'wc_status' => $fresh ? (string) $fresh->get_status() : ''];
        }
        return $out;
    }

    public static function discrepancy_orders(array $args = []) {
        global $wpdb;
        $state = (string) ($args['state'] ?? 'open');
        $where = 'x.type = %s';
        if ($state === 'open') {
            $where .= ' AND x.resolved = 0';
        } elseif ($state === 'resolved') {
            $where .= ' AND x.resolved = 1';
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT x.*, u.display_name AS resolved_by_name FROM ' . CJS_Inv_DB::t('discrepancies') . ' x LEFT JOIN ' . $wpdb->users . " u ON u.ID = x.resolved_by WHERE $where ORDER BY x.id DESC", self::DISCREPANCY_TYPE), ARRAY_A) ?: [];
        $by_order = [];
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            if ($oid && !isset($by_order[$oid])) {
                $by_order[$oid] = $r;
            }
        }
        $existing = [];
        $deleted = [];
        foreach ($by_order as $oid => $r) {
            if (wc_get_order($oid)) {
                $existing[] = $oid;
            } else {
                $deleted[] = $r;
            }
        }
        $per_page = min(200, max(1, absint($args['per_page'] ?? 50)));
        $page = max(1, absint($args['page'] ?? 1));
        $result = $existing ? CJS_Orders::query(['ids' => $existing, 'any_status' => true, 'page' => $page, 'per_page' => $per_page]) : ['orders' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
        $orders = [];
        foreach ($result['orders'] as $row) {
            $r = $by_order[(int) $row['id']];
            $row['discrepancy'] = self::discrepancy_payload($r);
            $row['fix'] = self::fix_for((int) $row['id']);
            $orders[] = $row;
        }
        $extra_per_page = min(200, max(1, absint($args['extra_per_page'] ?? 50)));
        $extra_page = max(1, absint($args['extra_page'] ?? 1));
        $offset = ($extra_page - 1) * $extra_per_page;
        $other = CJS_Inv_Discrepancies::list(['state' => $state, 'exclude_types' => [self::DISCREPANCY_TYPE], 'per_page' => $extra_per_page, 'offset' => $offset]);
        $other_total = (int) $other['total'];
        $extra = $offset < $other_total ? $other['discrepancies'] : [];
        $extra_total = $other_total + count($deleted);
        $deleted_from = max(0, $offset - $other_total);
        $deleted_take = $extra_per_page - count($extra);
        if ($deleted_take > 0) {
            foreach (array_slice($deleted, $deleted_from, $deleted_take) as $r) {
                $oid = (int) $r['order_id'];
                $label = CJS_Orders::order_labels([$oid])[$oid] ?? null;
                if (is_array($label) && ($label['status'] ?? '') === 'deleted') {
                    $label = array_merge($label, self::deleted_label($oid));
                }
                $extra[] = self::discrepancy_payload($r) + ['order' => $label, 'fix' => $state === 'resolved' || (int) $r['resolved'] === 1 ? null : self::fix_for($oid)];
            }
        }
        return [
            'orders' => $orders,
            'extra' => $extra,
            'extra_total' => $extra_total,
            'extra_page' => $extra_page,
            'extra_pages' => (int) ceil($extra_total / $extra_per_page),
            'total' => (int) $result['total'],
            'page' => (int) $result['page'],
            'pages' => (int) $result['pages'],
            'open_count' => CJS_Inv_Discrepancies::open_count(),
        ];
    }

    private static function discrepancy_payload(array $r) {
        $resolved = (int) $r['resolved'] === 1;
        return [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'order_id' => (int) $r['order_id'],
            'details' => (string) $r['details'],
            'resolved' => $resolved,
            'resolution' => $resolved ? ($r['resolution'] ?? 'manual') : null,
            'resolved_by' => $resolved ? (($r['resolved_by_name'] ?? '') ?: __('System', 'custom-jewelry-system')) : null,
            'resolved_at' => $r['resolved_at'],
            'created_at' => $r['created_at'],
        ];
    }
}
