<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

class CJS_Orders {

    const EXCLUDED_STATUSES = ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft', 'trash', 'auto-draft', 'draft'];
    const EARLY_STATUSES = ['', 'nepradeta', 'uzsakyti_modeli'];
    const EXT_FIELDS = ['manufacture_by_date', 'finish_by_date', 'deliver_by_date', 'order_model', 'order_production', 'casting_notes', 'order_printing', 'manufacturing_status', 'order_type', 'assigned_hours', 'completed_hours'];

    private static $label_cache = [];

    public static function hpos() {
        return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private static function src() {
        global $wpdb;
        if (self::hpos()) {
            return [
                'orders' => $wpdb->prefix . 'wc_orders',
                'id' => 'o.id',
                'status' => 'o.status',
                'type' => "o.type = 'shop_order'",
                'created' => 'o.date_created_gmt',
                'first' => 'ba.first_name',
                'last' => 'ba.last_name',
                'email' => 'o.billing_email',
                'join_billing' => 'LEFT JOIN ' . $wpdb->prefix . "wc_order_addresses ba ON ba.order_id = o.id AND ba.address_type = 'billing'",
                'meta' => $wpdb->prefix . 'wc_orders_meta',
                'meta_fk' => 'order_id',
            ];
        }
        return [
            'orders' => $wpdb->posts,
            'id' => 'o.ID',
            'status' => 'o.post_status',
            'type' => "o.post_type = 'shop_order'",
            'created' => 'o.post_date_gmt',
            'first' => 'bf.meta_value',
            'last' => 'bl.meta_value',
            'email' => 'be.meta_value',
            'join_billing' => "LEFT JOIN {$wpdb->postmeta} bf ON bf.post_id = o.ID AND bf.meta_key = '_billing_first_name'
                LEFT JOIN {$wpdb->postmeta} bl ON bl.post_id = o.ID AND bl.meta_key = '_billing_last_name'
                LEFT JOIN {$wpdb->postmeta} be ON be.post_id = o.ID AND be.meta_key = '_billing_email'",
            'meta' => $wpdb->postmeta,
            'meta_fk' => 'post_id',
        ];
    }

    public static function order_number($order_id) {
        if (!has_filter('woocommerce_order_number')) {
            return (string) $order_id;
        }
        $order = wc_get_order($order_id);
        return $order ? (string) $order->get_order_number() : (string) $order_id;
    }

    public static function edit_url($order_id) {
        if (self::hpos()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . absint($order_id));
        }
        return admin_url('post.php?post=' . absint($order_id) . '&action=edit');
    }

    public static function status_label($status) {
        $status = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
        $labels = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        return $labels[$status] ?? ucfirst(str_replace(['wc-', '-'], ['', ' '], $status));
    }

    public static function order_labels(array $order_ids) {
        global $wpdb;
        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
        $missing = array_values(array_diff($order_ids, array_keys(self::$label_cache)));
        if ($missing) {
            $s = self::src();
            $rows = $wpdb->get_results(
                "SELECT {$s['id']} AS id, {$s['status']} AS status, {$s['first']} AS first_name, {$s['last']} AS last_name
                 FROM {$s['orders']} o {$s['join_billing']} WHERE {$s['id']} IN (" . CJS_Inv_DB::ids_in($missing) . ')',
                ARRAY_A
            ) ?: [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                self::$label_cache[$id] = [
                    'id' => $id,
                    'number' => self::order_number($id),
                    'customer' => $name,
                    'status' => preg_replace('/^wc-/', '', (string) $r['status']),
                    'status_label' => self::status_label((string) $r['status']),
                    'edit_url' => self::edit_url($id),
                    'in_list' => !in_array((string) $r['status'], self::EXCLUDED_STATUSES, true),
                ];
            }
            foreach ($missing as $id) {
                if (!isset(self::$label_cache[$id])) {
                    self::$label_cache[$id] = ['id' => $id, 'number' => (string) $id, 'customer' => '', 'status' => 'deleted', 'status_label' => __('Deleted', 'custom-jewelry-system'), 'edit_url' => '', 'in_list' => false];
                }
            }
        }
        $out = [];
        foreach ($order_ids as $id) {
            $out[$id] = self::$label_cache[$id];
        }
        return $out;
    }

    public static function kit_category_id() {
        $cat = CJS_Inv_Catalog::category_by_slug('sizing-kits');
        return $cat ? (int) $cat['id'] : 0;
    }

    public static function offer_meta_keys() {
        $keys = [];
        foreach (CJS_Size_Kit::offers() as $o) {
            $keys[(int) $o['category']['id']] = CJS_Size_Kit::meta_key($o['category']);
        }
        return $keys;
    }

    public static function requested_offers(array $meta) {
        $out = [];
        foreach (CJS_Size_Kit::offers() as $o) {
            if (($meta[CJS_Size_Kit::meta_key($o['category'])] ?? '') === 'yes') {
                $out[] = ['category_id' => (int) $o['category']['id'], 'name' => (string) $o['category']['name']];
            }
        }
        return $out;
    }

    private static $wc_palette = null;

    private static function wc_palette() {
        if (self::$wc_palette !== null) {
            return self::$wc_palette;
        }
        $version = defined('WC_VERSION') ? (string) WC_VERSION : '';
        $cached = get_transient('cjs_wc_status_palette');
        if (is_array($cached) && ($cached['v'] ?? null) === $version && isset($cached['base'], $cached['status'])) {
            return self::$wc_palette = $cached;
        }
        $palette = ['v' => $version, 'base' => ['bg' => '#e5e5e5', 'color' => '#454545'], 'status' => []];
        $file = function_exists('WC') && WC() ? WC()->plugin_path() . '/assets/css/admin.css' : '';
        $css = $file !== '' && is_readable($file) ? (string) file_get_contents($file) : '';
        $read = function ($body) {
            $out = [];
            if (preg_match('/(?:^|;)s*background(?:-color)?s*:s*(#[0-9a-f]{3,8})/i', $body, $m)) {
                $out['bg'] = strtolower($m[1]);
            }
            if (preg_match('/(?:^|;)s*colors*:s*(#[0-9a-f]{3,8})/i', $body, $m)) {
                $out['color'] = strtolower($m[1]);
            }
            return $out;
        };
        if ($css !== '') {
            if (preg_match('/[,}]s*.order-statuss*{([^}]*)}/i', $css, $m)) {
                $palette['base'] = array_merge($palette['base'], $read($m[1]));
            }
            if (preg_match_all('/.order-status.status-([a-z0-9_-]+)s*{([^}]*)}/i', $css, $all, PREG_SET_ORDER)) {
                foreach ($all as $rule) {
                    $palette['status'][strtolower($rule[1])] = $read($rule[2]);
                }
            }
        }
        set_transient('cjs_wc_status_palette', $palette, WEEK_IN_SECONDS);
        return self::$wc_palette = $palette;
    }

    public static function wc_status_color($status) {
        $status = preg_replace('/^wc-/', '', (string) $status);
        $p = self::wc_palette();
        $color = $p['status'][$status]['color'] ?? $p['base']['color'];
        return (string) apply_filters('cjs_wc_status_color', $color, $status);
    }

    public static function wc_status_bg($status) {
        $status = preg_replace('/^wc-/', '', (string) $status);
        $p = self::wc_palette();
        $bg = $p['status'][$status]['bg'] ?? $p['base']['bg'];
        return (string) apply_filters('cjs_wc_status_bg', $bg, $status);
    }

    public static function wc_statuses() {
        $labels = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $out = [];
        foreach ($labels as $key => $label) {
            $value = preg_replace('/^wc-/', '', (string) $key);
            if ($value === 'checkout-draft') {
                continue;
            }
            $out[] = ['value' => $value, 'label' => wp_strip_all_tags((string) $label), 'color' => self::wc_status_color($value), 'bg' => self::wc_status_bg($value), 'open' => !in_array('wc-' . $value, self::EXCLUDED_STATUSES, true)];
        }
        return $out;
    }

    private static function offer_state_sql(array $cat, $state, array $s) {
        global $wpdb;
        $d = CJS_Inv_DB::t('demands');
        $a = CJS_Inv_DB::t('allocations');
        $i = CJS_Inv_DB::t('items');
        $cond = 'ik.category_id = ' . (int) $cat['id'];
        $meta_key = $wpdb->prepare('%s', CJS_Size_Kit::meta_key($cat));
        $line = "SELECT 1 FROM $d dk INNER JOIN $i ik ON ik.id = dk.item_id WHERE dk.order_id = {$s['id']} AND dk.state <> 'cancelled' AND $cond";
        $with_alloc = function ($states) use ($line, $a) {
            return "EXISTS ($line AND EXISTS (SELECT 1 FROM $a ak WHERE ak.demand_id = dk.id AND ak.state IN ($states)))";
        };
        $missing_line = "EXISTS ($line AND dk.state = 'open' AND dk.qty_allocated < dk.qty)";
        if ($state === 'missing' || $state === 'requested_missing') {
            $early = implode(',', array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', $v); }, self::EARLY_STATUSES));
            $meta = "EXISTS (SELECT 1 FROM {$s['meta']} mk WHERE mk.{$s['meta_fk']} = {$s['id']} AND mk.meta_key = $meta_key AND mk.meta_value = 'yes')";
            return "($missing_line OR ($meta AND COALESCE(e.manufacturing_status, '') IN ($early) AND NOT EXISTS ($line)))";
        }
        if ($state === 'to_send') {
            return $with_alloc("'reserved'");
        }
        if ($state === 'sent') {
            return $with_alloc("'out'");
        }
        if ($state === 'returned') {
            return '(' . $with_alloc("'returned','used'") . ' AND NOT ' . $with_alloc("'reserved','out'") . " AND NOT $missing_line)";
        }
        if ($state === 'has') {
            return $with_alloc("'reserved','out','returned','used'");
        }
        return '';
    }

    private static function build_where(array $args, array $s, array &$joins) {
        global $wpdb;
        $where = [$s['type']];
        $excluded = array_map(function ($st) use ($wpdb) { return $wpdb->prepare('%s', $st); }, self::EXCLUDED_STATUSES);
        $wc_status = !empty($args['wc_status']) && is_array($args['wc_status']) ? array_values(array_filter(array_map(function ($v) { return sanitize_key(preg_replace('/^wc-/', '', (string) $v)); }, $args['wc_status']))) : [];
        if ($wc_status) {
            $vals = array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', 'wc-' . $v); }, $wc_status);
            $where[] = $s['status'] . ' IN (' . implode(',', $vals) . ')';
        } elseif (!empty($args['any_status'])) {
            $where[] = $s['status'] . " NOT IN ('auto-draft','draft','wc-checkout-draft')";
        } elseif (!empty($args['include_closed'])) {
            $where[] = $s['status'] . " NOT IN ('trash','auto-draft','draft','wc-checkout-draft')";
        } else {
            $where[] = $s['status'] . ' NOT IN (' . implode(',', $excluded) . ')';
        }
        if (!empty($args['ids']) && is_array($args['ids'])) {
            $where[] = $s['id'] . ' IN (' . CJS_Inv_DB::ids_in($args['ids']) . ')';
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $term = ltrim($search, '#');
            $like = '%' . $wpdb->esc_like($search) . '%';
            $conds = [
                $wpdb->prepare("CONCAT_WS(' ', {$s['first']}, {$s['last']}) LIKE %s", $like),
                $wpdb->prepare("{$s['email']} LIKE %s", $like),
            ];
            if (ctype_digit($term)) {
                $conds[] = $wpdb->prepare("{$s['id']} = %d", (int) $term);
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
        if (!empty($args['status']) && is_array($args['status'])) {
            $vals = [];
            foreach ($args['status'] as $v) {
                $v = trim((string) $v);
                if ($v === '' || $v === '__none') {
                    $vals[''] = true;
                    continue;
                }
                $key = CJS_Inv_Flow::status_key($v);
                if ($key === 'nepradeta') {
                    $vals[''] = true;
                }
                $vals[$key ?? $v] = true;
            }
            $vals = array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', (string) $v); }, array_keys($vals));
            $where[] = "COALESCE(e.manufacturing_status, '') IN (" . implode(',', $vals) . ')';
        }
        foreach (['order_model', 'order_production', 'order_printing'] as $flag) {
            if (isset($args[$flag]) && $args[$flag] !== '' && $args[$flag] !== null) {
                $where[] = 'COALESCE(e.' . $flag . ', 0) = ' . ((int) $args[$flag] ? 1 : 0);
            }
        }
        if (!empty($args['order_type'])) {
            $where[] = $wpdb->prepare("COALESCE(e.order_type, 'Įprastas') = %s", $args['order_type']);
        }
        $d = CJS_Inv_DB::t('demands');
        $inv = $args['inventory'] ?? '';
        if ($inv === 'any') {
            $where[] = "EXISTS (SELECT 1 FROM $d dd WHERE dd.order_id = {$s['id']} AND dd.state <> 'cancelled')";
        } elseif ($inv === 'none') {
            $where[] = "NOT EXISTS (SELECT 1 FROM $d dd WHERE dd.order_id = {$s['id']} AND dd.state <> 'cancelled')";
        } elseif ($inv === 'missing') {
            $where[] = "{$s['id']} IN (" . CJS_Inv_DB::ids_in(CJS_Inv_Demands::unordered_order_ids()) . ')';
        } elseif ($inv === 'ordered') {
            $where[] = "EXISTS (SELECT 1 FROM $d dd WHERE dd.order_id = {$s['id']} AND dd.state = 'open' AND dd.qty_allocated < dd.qty AND dd.po_line_id IS NOT NULL)";
        } elseif ($inv === 'waiting') {
            $where[] = "EXISTS (SELECT 1 FROM $d dd WHERE dd.order_id = {$s['id']} AND dd.state = 'open' AND dd.qty_allocated < dd.qty)";
        } elseif ($inv === 'covered') {
            $where[] = "EXISTS (SELECT 1 FROM $d dd WHERE dd.order_id = {$s['id']} AND dd.state <> 'cancelled') AND NOT EXISTS (SELECT 1 FROM $d dd2 WHERE dd2.order_id = {$s['id']} AND dd2.state = 'open' AND dd2.qty_allocated < dd2.qty)";
        }
        if (!empty($args['stock_order_status']) && is_array($args['stock_order_status'])) {
            $vals = array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', (string) $v); }, $args['stock_order_status']);
            $where[] = "EXISTS (SELECT 1 FROM $d dd INNER JOIN " . CJS_Inv_DB::t('stock_order_lines') . ' sl ON sl.id = dd.po_line_id INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . " so ON so.id = sl.stock_order_id WHERE dd.order_id = {$s['id']} AND dd.state <> 'cancelled' AND so.status IN (" . implode(',', $vals) . '))';
        }
        $kit = (string) ($args['size_kit'] ?? '');
        $kit_cat = $kit !== '' ? CJS_Inv_Catalog::category_by_slug('sizing-kits') : null;
        if ($kit_cat) {
            $kit_sql = self::offer_state_sql($kit_cat, $kit, $s);
            if ($kit_sql !== '') {
                $where[] = $kit_sql;
            }
        }
        foreach (['manufacture' => 'manufacture_by_date', 'finish' => 'finish_by_date', 'deliver' => 'deliver_by_date'] as $key => $col) {
            $from = $args[$key . '_from'] ?? '';
            $to = $args[$key . '_to'] ?? '';
            if ($from !== '' && CJS_Deadlines::is_valid_ymd($from)) {
                $where[] = $wpdb->prepare("e.$col >= %s", $from);
            }
            if ($to !== '' && CJS_Deadlines::is_valid_ymd($to)) {
                $where[] = $wpdb->prepare("e.$col <= %s", $to);
            }
            if (($from !== '' || $to !== '')) {
                $where[] = "e.$col IS NOT NULL AND e.$col <> '0000-00-00'";
            }
        }
        return implode(' AND ', $where);
    }

    public static function query(array $args = []) {
        global $wpdb;
        $s = self::src();
        $e = $wpdb->prefix . 'cjs_order_extensions';
        self::fill_missing_extensions();
        $joins = [];
        $where = self::build_where($args, $s, $joins);
        $from = "FROM {$s['orders']} o LEFT JOIN $e e ON e.order_id = {$s['id']} {$s['join_billing']}";
        $per_page = min(200, max(1, absint($args['per_page'] ?? 20)));
        $page = max(1, absint($args['page'] ?? 1));
        $null_date = "(e.deliver_by_date IS NULL OR e.deliver_by_date = '0000-00-00')";
        $order_by = "$null_date ASC, e.deliver_by_date ASC, CASE WHEN $null_date THEN -{$s['id']} ELSE {$s['id']} END ASC";
        $stats_rows = $wpdb->get_results("SELECT {$s['id']} AS id, e.assigned_hours, e.completed_hours, e.manufacturing_status $from WHERE $where", ARRAY_A) ?: [];
        $total = count($stats_rows);
        if (!empty($args['locate'])) {
            $ids = $wpdb->get_col("SELECT {$s['id']} $from WHERE $where ORDER BY $order_by");
            $pos = array_search((int) $args['locate'], array_map('intval', $ids), true);
            if ($pos !== false) {
                $page = (int) floor($pos / $per_page) + 1;
            }
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$s['id']} AS id, {$s['status']} AS wc_status, {$s['created']} AS created_gmt, {$s['first']} AS first_name, {$s['last']} AS last_name, e.order_id AS ext_id,
                e.manufacture_by_date, e.finish_by_date, e.deliver_by_date, e.order_model, e.order_production, e.casting_notes, e.order_printing, e.manufacturing_status, e.order_type, e.assigned_hours, e.completed_hours
             $from WHERE $where ORDER BY $order_by LIMIT %d OFFSET %d",
            $per_page, ($page - 1) * $per_page
        ), ARRAY_A) ?: [];
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $fill = [];
        foreach ($rows as $r) {
            if (empty($r['ext_id']) || empty($r['deliver_by_date']) || $r['deliver_by_date'] === '0000-00-00' || empty($r['finish_by_date']) || empty($r['manufacture_by_date']) || $r['assigned_hours'] === null) {
                $fill[] = (int) $r['id'];
            }
        }
        $filled = [];
        foreach ($fill as $oid) {
            CJS_Order_Extension::create_order_extension($oid);
            $filled[$oid] = CJS_Order_Extension::get_order_extension($oid);
        }
        $lines = self::inventory_lines_for($ids);
        $meta = self::meta_for($ids, array_merge(['cjs_custom_deadline'], array_values(self::offer_meta_keys())));
        $files = self::file_counts($ids);
        $items = self::line_items_for($ids);
        $times = CJS_Statuses::times_for($ids);
        $out = [];
        foreach ($rows as $r) {
            $oid = (int) $r['id'];
            $ext = isset($filled[$oid]) ? $filled[$oid] : $r;
            $out[] = self::format_row($r, $ext, $lines[$oid] ?? [], $meta[$oid] ?? [], $files[$oid] ?? 0, $items[$oid] ?? [], false, $times[$oid] ?? null);
        }
        return [
            'orders' => $out,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
            'stats' => self::hours_stats($stats_rows),
        ];
    }

    public static function fill_missing_extensions($limit = 150) {
        global $wpdb;
        $s = self::src();
        $e = $wpdb->prefix . 'cjs_order_extensions';
        $excluded = implode(',', array_map(function ($st) use ($wpdb) { return $wpdb->prepare('%s', $st); }, self::EXCLUDED_STATUSES));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT {$s['id']} FROM {$s['orders']} o LEFT JOIN $e e ON e.order_id = {$s['id']}
             WHERE {$s['type']} AND {$s['status']} NOT IN ($excluded)
               AND (e.order_id IS NULL OR e.assigned_hours IS NULL OR e.deliver_by_date IS NULL OR e.deliver_by_date = '0000-00-00' OR e.finish_by_date IS NULL OR e.manufacture_by_date IS NULL)
             LIMIT %d",
            $limit
        ));
        foreach ($ids as $oid) {
            CJS_Order_Extension::create_order_extension((int) $oid);
            CJS_Order_Extension::get_order_extension((int) $oid);
        }
        return count($ids);
    }

    public static function format_row(array $r, array $ext, $lines, array $meta, $file_count, array $items, $kit_assigned = false, $times = null) {
        $oid = (int) $r['id'];
        $lines = is_array($lines) ? array_values($lines) : [];
        $created = !empty($r['created_gmt']) ? get_date_from_gmt($r['created_gmt'], 'Y-m-d') : '';
        $deliver = self::clean_date($ext['deliver_by_date'] ?? '');
        $status = (string) ($ext['manufacturing_status'] ?? '');
        $status_key = CJS_Statuses::stored_key($status);
        $assigned = ($ext['assigned_hours'] ?? '') === '' || $ext['assigned_hours'] === null ? null : (float) $ext['assigned_hours'];
        if (!is_array($times)) {
            $times = CJS_Statuses::times_for([$oid])[$oid] ?? [];
        }
        $shown = [];
        foreach ($lines as $l) {
            if (empty($l['removed'])) {
                unset($l['removed']);
                $shown[] = $l;
            }
        }
        $lines = $shown;
        $days_left = $deliver ? CJS_Deadlines::days_until($deliver) : null;
        $summary = ['lines' => count($lines), 'missing_lines' => 0, 'ordered_lines' => 0, 'out_lines' => 0, 'chips' => []];
        foreach ($lines as $l) {
            if ($l['state'] === 'missing') {
                $summary['missing_lines']++;
            } elseif ($l['state'] === 'ordered') {
                $summary['ordered_lines']++;
            } elseif ($l['state'] === 'sent') {
                $summary['out_lines']++;
            }
        }
        return [
            'id' => $oid,
            'number' => self::order_number($oid),
            'customer' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'wc_status' => preg_replace('/^wc-/', '', (string) $r['wc_status']),
            'wc_status_label' => self::status_label((string) $r['wc_status']),
            'wc_status_color' => self::wc_status_color((string) $r['wc_status']),
            'wc_status_bg' => self::wc_status_bg((string) $r['wc_status']),
            'created' => $created,
            'edit_url' => self::edit_url($oid),
            'manufacture_by_date' => self::clean_date($ext['manufacture_by_date'] ?? ''),
            'finish_by_date' => self::clean_date($ext['finish_by_date'] ?? ''),
            'deliver_by_date' => $deliver,
            'days_left' => $days_left,
            'term' => CJS_Deadlines::term_data($created, $deliver),
            'term_end' => 'deliver_by_date',
            'order_model' => (bool) (int) ($ext['order_model'] ?? 0),
            'order_production' => (bool) (int) ($ext['order_production'] ?? 0),
            'order_printing' => (bool) (int) ($ext['order_printing'] ?? 0),
            'casting_notes' => (string) ($ext['casting_notes'] ?? ''),
            'manufacturing_status' => CJS_Statuses::display($status),
            'status_key' => $status_key ?? 'nepradeta',
            'status_times' => $times,
            'locked' => preg_replace('/^wc-/', '', (string) $r['wc_status']) === 'completed',
            'order_type' => (string) (($ext['order_type'] ?? '') !== '' ? $ext['order_type'] : 'Įprastas'),
            'assigned_hours' => $assigned,
            'completed_hours' => (float) ($ext['completed_hours'] ?? 0),
            'checkout_offers' => self::requested_offers($meta),
            'custom_deadline' => isset($meta['cjs_custom_deadline']) && CJS_Deadlines::is_valid_ymd($meta['cjs_custom_deadline']) ? $meta['cjs_custom_deadline'] : null,
            'inventory' => $summary,
            'inv_lines' => $lines,
            'file_count' => (int) $file_count,
            'items' => $items,
        ];
    }

    public static function clean_date($value) {
        $value = (string) $value;
        return ($value === '' || $value === '0000-00-00' || $value === 'null') ? '' : substr($value, 0, 10);
    }

    public static function meta_for(array $ids, array $keys) {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $s = self::src();
        $in_keys = implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, $keys));
        $rows = $wpdb->get_results("SELECT {$s['meta_fk']} AS oid, meta_key, meta_value FROM {$s['meta']} WHERE {$s['meta_fk']} IN (" . CJS_Inv_DB::ids_in($ids) . ") AND meta_key IN ($in_keys)", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['oid']][$r['meta_key']] = $r['meta_value'];
        }
        return $out;
    }

    public static function file_counts(array $ids) {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT order_id, COUNT(*) AS n FROM ' . $wpdb->prefix . 'cjs_order_files WHERE order_id IN (' . CJS_Inv_DB::ids_in($ids) . ') GROUP BY order_id', ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['order_id']] = (int) $r['n'];
        }
        return $out;
    }

    public static function line_items_for(array $ids) {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $oi = $wpdb->prefix . 'woocommerce_order_items';
        $oim = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $rows = $wpdb->get_results(
            "SELECT i.order_id, i.order_item_id, i.order_item_name, q.meta_value AS qty, p.meta_value AS product_id
             FROM $oi i
             LEFT JOIN $oim q ON q.order_item_id = i.order_item_id AND q.meta_key = '_qty'
             LEFT JOIN $oim p ON p.order_item_id = i.order_item_id AND p.meta_key = '_product_id'
             WHERE i.order_item_type = 'line_item' AND i.order_id IN (" . CJS_Inv_DB::ids_in($ids) . ') ORDER BY i.order_item_id',
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['order_id']][] = [
                'id' => (int) $r['order_item_id'],
                'name' => $r['order_item_name'],
                'qty' => (float) $r['qty'],
                'product_id' => (int) $r['product_id'],
            ];
        }
        return $out;
    }

    public static function inventory_lines_for(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            'SELECT d.id, d.order_id, d.item_id, d.metal, d.qty, d.qty_allocated, d.state, d.unit_id, d.po_line_id, d.created_at, i.name, COALESCE(i.returnable, 0) AS returnable, COALESCE(i.category_id, d.category_id) AS category_id
             FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = d.item_id
             WHERE d.order_id IN (' . CJS_Inv_DB::ids_in($ids) . ") AND d.state <> 'cancelled' AND (i.id IS NOT NULL OR d.metal IS NOT NULL)
             ORDER BY d.order_id, d.order_item_id IS NULL, d.order_item_id, d.id",
            ARRAY_A
        ) ?: [];
        if (!$rows) {
            return [];
        }
        $demand_ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $alloc_rows = $wpdb->get_results(
            'SELECT demand_id, state, COUNT(*) AS n FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id IN (' . CJS_Inv_DB::ids_in($demand_ids) . ") AND state <> 'released' GROUP BY demand_id, state",
            ARRAY_A
        ) ?: [];
        $allocs = [];
        foreach ($alloc_rows as $a) {
            $allocs[(int) $a['demand_id']][(string) $a['state']] = (int) $a['n'];
        }
        $po_lines = CJS_Inv_Demands::po_lines_for(array_map(function ($r) { return (int) $r['po_line_id']; }, $rows));
        $sources = method_exists('CJS_Inv_Demands', 'source_stock_orders') ? CJS_Inv_Demands::source_stock_orders($demand_ids) : [];
        $parts = method_exists('CJS_Inv_Stock_Orders', 'ordered_parts') ? CJS_Inv_Stock_Orders::ordered_parts($rows) : [];
        $tags = CJS_Inv_Demands::piece_tags($demand_ids);
        $metal_items = [];
        $unit_notes = [];
        $out = [];
        foreach ($rows as $r) {
            $did = (int) $r['id'];
            $missing = $r['state'] === 'open' ? max(0, round((float) $r['qty'] - (float) $r['qty_allocated'], 4)) : 0;
            $po = $r['po_line_id'] ? ($po_lines[(int) $r['po_line_id']] ?? null) : null;
            $line_state = $po ? CJS_Inv_Stock_Orders::demand_state($r, ['qty' => $po['qty'] ?? 0, 'qty_received' => $po['qty_received'] ?? 0, 'in_cart' => !empty($po['in_cart']) ? 1 : 0]) : null;
            $rules = CJS_Inv_Flow::line_rules($r);
            $out[(int) $r['order_id']][] = [
                'id' => $did,
                'item_id' => (int) $r['item_id'],
                'category_id' => (int) $r['category_id'],
                'name' => CJS_Inv_Flow::line_name($r),
                'metal' => ($lm = CJS_Inv_Metals::of_line($r)) ? $lm['metal'] : null,
                'metal_items' => $lm ? ($metal_items[$lm['category_id'] . '|' . CJS_Inv_Metals::key($lm['metal']) . '|' . (int) $r['unit_id']] ??= CJS_Inv_Metals::payload($lm['category_id'], $lm['metal'], (int) $r['unit_id'])) : null,
                'metal_unit_note' => $lm && !$metal_items[$lm['category_id'] . '|' . CJS_Inv_Metals::key($lm['metal']) . '|' . (int) $r['unit_id']] ? ($unit_notes[$lm['category_id'] . '|' . CJS_Inv_Metals::key($lm['metal']) . '|' . (int) $r['unit_id']] ??= CJS_Inv_Metals::unit_note($lm['category_id'], $lm['metal'], (int) $r['unit_id'])) : null,
                'qty' => (float) $r['qty'],
                'allocated' => (float) $r['qty_allocated'],
                'missing' => (float) $missing,
                'missing_at_default' => CJS_Inv_Flow::missing_at_default((float) $missing, $rules),
                'fineness' => $rules['has_fineness'],
                'unit' => CJS_Inv_Catalog::unit_label($r['unit_id']),
                'state' => CJS_Inv_Flow::line_state($r),
                'markers' => CJS_Inv_Flow::markers(array_keys($allocs[$did] ?? [])),
                'ordered' => CJS_Inv_Flow::ordered_tag($r, $po),
                'returnable' => (bool) (int) $r['returnable'],
                'pieces' => $tags[$did] ?? [],
                'stock_order' => $po && $missing > 0 ? [
                    'id' => (int) $po['id'],
                    'number' => (string) $po['number'],
                    'ordered' => !empty($po['ordered']),
                    'line_state' => $line_state,
                    'status' => CJS_Inv_Stock_Orders::status_payload($line_state),
                ] : null,
                'source_stock_order' => $sources[$did] ?? null,
                'removed' => (int) $r['returnable'] === 1 && $r['state'] === 'done',
            ] + CJS_Inv_Stock_Orders::parts_fields($r, $parts);
        }
        return $out;
    }

    public static function bulk_update(array $ids, array $fields) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 200);
        if (!$ids) {
            return new WP_Error('cjs_invalid', __('No orders selected.', 'custom-jewelry-system'));
        }
        $allowed = [
            'manufacturing_status' => true,
            'order_type' => array_values(CJS_Order_Extension::get_ordered_options('order_types')),
        ];
        $clean = [];
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (!isset($allowed[$field])) {
                continue;
            }
            $value = (string) $value;
            if ($field === 'manufacturing_status') {
                if (trim($value) !== '' && $value !== '__none' && CJS_Inv_Flow::status_key($value) === null) {
                    return new WP_Error('cjs_invalid', __('Invalid value.', 'custom-jewelry-system'));
                }
            } elseif (!in_array($value, $allowed[$field], true)) {
                return new WP_Error('cjs_invalid', __('Invalid value.', 'custom-jewelry-system'));
            }
            $clean[$field] = $value;
        }
        if (!$clean) {
            return new WP_Error('cjs_invalid', __('Nothing to update.', 'custom-jewelry-system'));
        }
        $orders = [];
        $failed = [];
        foreach ($ids as $oid) {
            $result = self::update_fields($oid, $clean);
            if (is_wp_error($result) || !$result) {
                $failed[] = $oid;
                continue;
            }
            $orders[] = $result['order'];
        }
        return ['orders' => $orders, 'failed' => $failed];
    }

    public static function timeline() {
        global $wpdb;
        $s = self::src();
        $e = $wpdb->prefix . 'cjs_order_extensions';
        $excluded = implode(',', array_map(function ($st) use ($wpdb) { return $wpdb->prepare('%s', $st); }, self::EXCLUDED_STATUSES));
        $parent = self::hpos() ? 'o.parent_order_id' : 'o.post_parent';
        $rows = $wpdb->get_results(
            "SELECT {$s['id']} AS id, {$s['created']} AS created_gmt, {$s['first']} AS first_name, {$s['last']} AS last_name,
                e.manufacture_by_date, e.finish_by_date, e.deliver_by_date, e.manufacturing_status, e.assigned_hours, e.completed_hours
             FROM {$s['orders']} o INNER JOIN $e e ON e.order_id = {$s['id']} {$s['join_billing']}
             WHERE {$s['type']} AND {$s['status']} NOT IN ($excluded) AND COALESCE($parent, 0) = 0
               AND ((e.manufacture_by_date IS NOT NULL AND e.manufacture_by_date <> '0000-00-00')
                 OR (e.finish_by_date IS NOT NULL AND e.finish_by_date <> '0000-00-00')
                 OR (e.deliver_by_date IS NOT NULL AND e.deliver_by_date <> '0000-00-00'))",
            ARRAY_A
        ) ?: [];
        return array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'number' => self::order_number((int) $r['id']),
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'status' => CJS_Statuses::display((string) ($r['manufacturing_status'] ?? '')),
                'status_key' => CJS_Statuses::stored_key((string) ($r['manufacturing_status'] ?? '')) ?? 'nepradeta',
                'assigned' => ($r['assigned_hours'] === null || $r['assigned_hours'] === '') ? null : (float) $r['assigned_hours'],
                'completed' => (float) $r['completed_hours'],
                'start' => $r['created_gmt'] ? get_date_from_gmt($r['created_gmt'], 'Y-m-d') : current_time('Y-m-d'),
                'manufacture_by' => self::clean_date($r['manufacture_by_date']) ?: null,
                'finish_by' => self::clean_date($r['finish_by_date']) ?: null,
                'deliver_by' => self::clean_date($r['deliver_by_date']) ?: null,
                'edit_url' => self::edit_url((int) $r['id']),
            ];
        }, $rows);
    }

    public static function hours_stats(array $rows) {
        $atlieta = CJS_Inv_Flow::rank('atlieta');
        $assigned_total = 0.0;
        $done_total = 0.0;
        foreach ($rows as $r) {
            $assigned = ($r['assigned_hours'] === null || $r['assigned_hours'] === '') ? 0.0 : (float) $r['assigned_hours'];
            $completed = (float) ($r['completed_hours'] ?? 0);
            $key = CJS_Statuses::stored_key((string) ($r['manufacturing_status'] ?? ''));
            $idx = $key === null ? -1 : CJS_Inv_Flow::rank($key);
            $hours_pct = $assigned > 0 ? min(1.0, max(0.0, $completed / $assigned)) : 0.0;
            if ($idx < $atlieta) {
                $pct = 0.0;
            } elseif ($idx === $atlieta) {
                $pct = $hours_pct;
            } else {
                $pct = 1.0;
            }
            $assigned_total += $assigned;
            $done_total += $assigned * $pct;
        }
        return [
            'count' => count($rows),
            'assigned' => round($assigned_total, 2),
            'done' => round($done_total, 2),
            'left' => round($assigned_total - $done_total, 2),
        ];
    }

    public static function single(int $order_id) {
        $order = CJS_Inv_Flow::resolve_order($order_id);
        if (!$order) {
            return null;
        }
        $order_id = (int) $order->get_id();
        CJS_Order_Extension::create_order_extension($order_id);
        $ext = CJS_Order_Extension::get_order_extension($order_id);
        $row = [
            'id' => $order_id,
            'wc_status' => $order->get_status(),
            'created_gmt' => $order->get_date_created() ? gmdate('Y-m-d H:i:s', $order->get_date_created()->getTimestamp()) : '',
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        ];
        $meta = ['cjs_custom_deadline' => $order->get_meta('cjs_custom_deadline')];
        foreach (self::offer_meta_keys() as $key) {
            $meta[$key] = $order->get_meta($key);
        }
        $lines = self::inventory_lines_for([$order_id]);
        $files = self::file_counts([$order_id]);
        $items = self::line_items_for([$order_id]);
        $out = self::format_row($row, $ext, $lines[$order_id] ?? [], $meta, $files[$order_id] ?? 0, $items[$order_id] ?? []);
        $out['number'] = $order->get_order_number();
        return $out;
    }

    public static function panel(int $order_id) {
        $order = CJS_Inv_Flow::resolve_order($order_id);
        if (!$order) {
            return null;
        }
        $order_id = (int) $order->get_id();
        $row = self::single($order_id);
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $meta = [];
            foreach ($item->get_formatted_meta_data('', true) as $m) {
                $meta[] = ['key' => wp_strip_all_tags($m->display_key), 'value' => wp_strip_all_tags($m->display_value)];
            }
            $product = $item->get_product();
            $image = '';
            if ($product) {
                $img_id = $product->get_image_id();
                if ($img_id) {
                    $src = wp_get_attachment_image_src($img_id, 'thumbnail');
                    $image = $src ? $src[0] : '';
                }
            }
            $items[] = [
                'id' => (int) $item_id,
                'name' => $item->get_name(),
                'qty' => (float) $item->get_quantity(),
                'total' => (float) $item->get_total(),
                'product_id' => (int) $item->get_product_id(),
                'meta' => $meta,
                'image' => $image,
            ];
        }
        $row['line_items'] = $items;
        $row['demands'] = CJS_Inv_Demands::for_order($order_id);
        $row['returnable_history'] = CJS_Inv_Demands::returnable_history($order_id);
        $row['warnings'] = CJS_Inv_Demands::warnings_for_order($order_id);
        $row['currency'] = $order->get_currency();
        $row['total'] = (float) $order->get_total();
        $row['shipping'] = implode(', ', array_map(function ($m) { return $m->get_name(); }, $order->get_shipping_methods()));
        $row['shipping_methods'] = self::shipping_badges($order);
        return $row;
    }

    public static function shipping_badges($order) {
        $out = [];
        if (!$order || !method_exists($order, 'get_shipping_methods')) {
            return $out;
        }
        foreach ($order->get_shipping_methods() as $item) {
            $method_id = (string) $item->get_method_id();
            $instance_id = (int) $item->get_instance_id();
            $logo = '';
            try {
                $logo = self::shipping_logo($item);
            } catch (\Throwable $e) {
                $logo = '';
            }
            $logo = apply_filters('cjs_shipping_logo_url', $logo, $method_id, $instance_id, $item, $order);
            $logo = is_string($logo) ? esc_url_raw($logo) : '';
            $out[] = [
                'title' => wp_strip_all_tags((string) $item->get_name()),
                'method_id' => $method_id,
                'instance_id' => $instance_id,
                'logo' => $logo,
            ];
        }
        return $out;
    }

    private static function logo_from_value($value) {
        if (is_numeric($value) && (int) $value > 0) {
            $url = wp_get_attachment_image_url((int) $value, 'thumbnail');
            return $url ? (string) $url : '';
        }
        if (is_array($value)) {
            foreach (['url', 'src', 'logo', 'icon'] as $k) {
                if (isset($value[$k]) && is_string($value[$k])) {
                    $found = self::logo_from_value($value[$k]);
                    if ($found !== '') {
                        return $found;
                    }
                }
            }
            return '';
        }
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (stripos($value, '<img') !== false && preg_match('/src=["\']([^"\']+)["\']/i', $value, $m)) {
            return html_entity_decode($m[1]);
        }
        if (preg_match('#^(https?:)?//#i', $value) && preg_match('/\.(png|jpe?g|gif|svg|webp)(\?.*)?$/i', $value)) {
            return $value;
        }
        return '';
    }

    public static function shipping_logo($item) {
        foreach (['logo', '_logo', 'carrier_logo', 'icon', '_icon'] as $key) {
            $found = self::logo_from_value($item->get_meta($key));
            if ($found !== '') {
                return $found;
            }
        }
        $method_id = (string) $item->get_method_id();
        $instance_id = (int) $item->get_instance_id();
        if ($method_id === '') {
            return '';
        }
        $cache_key = 'cjs_ship_logo_' . md5($method_id . '|' . $instance_id . '|' . (defined('CJS_VERSION') ? CJS_VERSION : ''));
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['url'])) {
            return (string) $cached['url'];
        }
        $url = self::detect_shipping_logo($method_id, $instance_id, (string) $item->get_name());
        set_transient($cache_key, ['url' => $url], DAY_IN_SECONDS);
        return $url;
    }

    private static function detect_shipping_logo($method_id, $instance_id, $title) {
        $method = null;
        if ($instance_id > 0 && class_exists('WC_Shipping_Zones')) {
            try {
                $method = WC_Shipping_Zones::get_shipping_method($instance_id);
            } catch (\Throwable $e) {
                $method = null;
            }
        }
        if (!$method && function_exists('WC') && WC()->shipping()) {
            try {
                $all = WC()->shipping()->get_shipping_methods();
                $method = $all[$method_id] ?? null;
            } catch (\Throwable $e) {
                $method = null;
            }
        }
        if (!is_object($method)) {
            return '';
        }
        $keys = ['logo', 'icon', 'carrier_logo', 'image', 'logo_url', 'icon_url'];
        if ($method instanceof WC_Shipping_Method) {
            $sets = [];
            try {
                if ($instance_id > 0 && method_exists($method, 'get_instance_option_key') && $method->get_instance_option_key()) {
                    $sets[] = get_option($method->get_instance_option_key(), []);
                }
                if (method_exists($method, 'get_option_key')) {
                    $sets[] = get_option($method->get_option_key(), []);
                }
            } catch (\Throwable $e) {
                $sets = [];
            }
            foreach ($sets as $set) {
                if (!is_array($set)) {
                    continue;
                }
                foreach ($keys as $key) {
                    if (isset($set[$key])) {
                        $found = self::logo_from_value($set[$key]);
                        if ($found !== '') {
                            return $found;
                        }
                    }
                }
            }
        }
        $props = get_object_vars($method);
        foreach (['logo', 'icon', 'method_icon', 'carrier_logo', 'logo_url', 'icon_url', 'image'] as $prop) {
            if (isset($props[$prop])) {
                $found = self::logo_from_value($props[$prop]);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        foreach (['get_logo_url', 'get_logo', 'get_icon_url', 'get_icon'] as $fn) {
            if (!is_callable([$method, $fn])) {
                continue;
            }
            try {
                $ref = new ReflectionMethod($method, $fn);
                if ($ref->getNumberOfRequiredParameters() > 0) {
                    continue;
                }
                $found = self::logo_from_value($method->$fn());
            } catch (\Throwable $e) {
                $found = '';
            }
            if ($found !== '') {
                return $found;
            }
        }
        try {
            $file = (new ReflectionClass($method))->getFileName();
        } catch (\Throwable $e) {
            $file = '';
        }
        return $file ? self::plugin_logo_asset($file, $method_id, $title) : '';
    }

    private static function plugin_logo_asset($file, $method_id, $title) {
        if (!defined('WP_PLUGIN_DIR')) {
            return '';
        }
        $base = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $file = wp_normalize_path($file);
        if (strpos($file, $base) !== 0) {
            return '';
        }
        $slug = strtok(substr($file, strlen($base)), '/');
        if (!$slug || $slug === 'woocommerce' || $slug === 'custom-jewelry-system') {
            return '';
        }
        $root = $base . $slug;
        if (!is_dir($root)) {
            return '';
        }
        $stop = ['shipping', 'method', 'methods', 'wc', 'woo', 'woocommerce', 'delivery', 'pristatymas', 'the', 'and', 'ir', 'to', 'free', 'flat', 'rate', 'local', 'pickup', 'point', 'points', 'terminal', 'terminals', 'parcel', 'parcels', 'pastomatai', 'pastomatas', 'pastomatu', 'pastomate', 'courier', 'kurjeris', 'kurjeriu', 'home', 'door', 'logo', 'icon'];
        $words = preg_split('/[^a-z0-9]+/', strtolower(remove_accents($method_id . ' ' . $title)));
        $tokens = array_values(array_unique(array_filter($words, function ($w) use ($stop) { return strlen($w) >= 2 && !in_array($w, $stop, true) && !ctype_digit($w); })));
        $best = '';
        $best_score = 0;
        $logos = [];
        $seen = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    function ($current) {
                        if ($current->isDir()) {
                            return !in_array(strtolower($current->getFilename()), ['node_modules', 'vendor', 'languages', 'tests', '.git', 'src', 'lib', 'includes'], true);
                        }
                        return (bool) preg_match('/\.(png|svg|jpe?g|webp|gif)$/i', $current->getFilename());
                    }
                )
            );
            $it->setMaxDepth(4);
            foreach ($it as $f) {
                if (++$seen > 600) {
                    break;
                }
                if ($f->getSize() > 300000) {
                    continue;
                }
                $name = strtolower(pathinfo($f->getFilename(), PATHINFO_FILENAME));
                $parts = preg_split('/[^a-z0-9]+/', $name);
                $matched = count(array_intersect($tokens, $parts));
                $is_logo = in_array('logo', $parts, true) || in_array('logos', $parts, true) || strpos(strtolower(wp_normalize_path($f->getPath())), '/logo') !== false;
                $path = wp_normalize_path($f->getPathname());
                if ($is_logo) {
                    $logos[] = $path;
                }
                if ($matched === 0) {
                    continue;
                }
                $score = $matched * 10 + ($is_logo ? 5 : 0) - substr_count(substr($path, strlen($root)), '/');
                if ($score > $best_score || ($score === $best_score && $best !== '' && strlen($path) < strlen($best))) {
                    $best = $path;
                    $best_score = $score;
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        if ($best === '' && count($logos) === 1) {
            $best = $logos[0];
        }
        if ($best === '') {
            return '';
        }
        return plugins_url(substr($best, strlen($base)));
    }

    public static function completed_below_assigned(int $order_id, float $completed, array $target = []) {
        global $wpdb;
        $key = array_key_exists('status', $target) ? (string) $target['status'] : CJS_Inv_Flow::order_status_key($order_id);
        if (CJS_Inv_Flow::rank($key) < CJS_Inv_Flow::rank('pagaminta')) {
            return null;
        }
        $assigned = array_key_exists('assigned', $target) ? $target['assigned'] : $wpdb->get_var($wpdb->prepare("SELECT assigned_hours FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d", $order_id));
        if (($assigned === null || $assigned === '') && !array_key_exists('assigned', $target) && array_key_exists('status', $target) && method_exists('CJS_Order_Extension', 'calculate_default_hours')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $assigned = round((float) CJS_Order_Extension::calculate_default_hours($order), 2);
            }
        }
        if ($assigned === null || (float) $assigned <= 0 || $completed >= round((float) $assigned, 2) - 0.001) {
            return null;
        }
        return new WP_Error('cjs_invalid', sprintf(__('At %1$s or later all hours are done, so the completed hours cannot be lower than the assigned hours (%2$s h).', 'custom-jewelry-system'), CJS_Inv_Flow::display_label('pagaminta'), CJS_Inv_DB::fmt(round((float) $assigned, 2))), ['status' => 400, 'min' => round((float) $assigned, 2)]);
    }

    public static function update_field(int $order_id, string $field, $value) {
        $prepared = self::prepare_field($order_id, $field, $value);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        return self::commit_fields($order_id, [$field => $prepared]);
    }

    public static function update_fields(int $order_id, array $fields) {
        if (!$fields) {
            return ['order' => self::single($order_id)];
        }
        $prepared = [];
        $target = [];
        foreach (['manufacturing_status', 'assigned_hours'] as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $p = self::prepare_field($order_id, $field, $fields[$field]);
            if (is_wp_error($p)) {
                return $p;
            }
            $prepared[$field] = $p;
        }
        if (isset($prepared['manufacturing_status'])) {
            $target['status'] = $prepared['manufacturing_status']['value'] ?? 'nepradeta';
        }
        if (isset($prepared['assigned_hours'])) {
            $target['assigned'] = (float) $prepared['assigned_hours']['value'];
        }
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (isset($prepared[$field])) {
                continue;
            }
            $p = self::prepare_field($order_id, $field, $value, $target);
            if (is_wp_error($p)) {
                return $p;
            }
            $prepared[$field] = $p;
        }
        $ordered = [];
        if (isset($prepared['manufacturing_status'])) {
            $ordered['manufacturing_status'] = $prepared['manufacturing_status'];
        }
        foreach ($prepared as $field => $p) {
            if (!in_array($field, ['manufacturing_status', 'assigned_hours', 'completed_hours'], true)) {
                $ordered[$field] = $p;
            }
        }
        foreach (['assigned_hours', 'completed_hours'] as $field) {
            if (isset($prepared[$field])) {
                $ordered[$field] = $prepared[$field];
            }
        }
        return self::commit_fields($order_id, $ordered);
    }

    private static function prepare_field(int $order_id, string $field, $value, array $target = []) {
        if (!in_array($field, self::EXT_FIELDS, true)) {
            return new WP_Error('cjs_invalid', __('Invalid field.', 'custom-jewelry-system'));
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'));
        }
        if (!CJS_Inv_Flow::guarded($order_id) && CJS_Woo_Sync::locked($order_id)) {
            return CJS_Woo_Sync::locked_error();
        }
        switch ($field) {
            case 'manufacturing_status':
                $raw = trim(sanitize_text_field((string) $value));
                $value = $raw === '' || $raw === '__none' ? null : CJS_Inv_Flow::status_key($raw);
                if ($raw !== '' && $raw !== '__none' && $value === null) {
                    return new WP_Error('cjs_invalid', __('Unknown status.', 'custom-jewelry-system'));
                }
                break;
            case 'order_model':
            case 'order_production':
            case 'order_printing':
                $value = $value && $value !== 'false' && $value !== '0' ? 1 : 0;
                break;
            case 'casting_notes':
                $value = sanitize_textarea_field((string) $value);
                break;
            case 'order_type':
                $value = is_scalar($value) ? (string) $value : '';
                if (!in_array($value, array_values(CJS_Order_Extension::get_ordered_options('order_types')), true)) {
                    return new WP_Error('cjs_invalid', __('Invalid value.', 'custom-jewelry-system'));
                }
                break;
            case 'assigned_hours':
            case 'completed_hours':
                $value = (string) max(0, round((float) str_replace(',', '.', is_scalar($value) ? (string) $value : '0'), 2));
                if ($field === 'completed_hours') {
                    $low = self::completed_below_assigned($order_id, (float) $value, $target);
                    if ($low !== null) {
                        return $low;
                    }
                }
                break;
            case 'manufacture_by_date':
            case 'finish_by_date':
            case 'deliver_by_date':
                $value = (string) $value;
                if ($value !== '' && !CJS_Deadlines::is_valid_ymd($value)) {
                    return new WP_Error('cjs_invalid', __('Invalid date.', 'custom-jewelry-system'));
                }
                $value = $value === '' ? null : $value;
                break;
            default:
                $value = sanitize_text_field((string) $value);
        }
        $stock_preview = null;
        if ($field === 'manufacturing_status' && !CJS_Inv_Flow::guarded($order_id)) {
            $stock_preview = CJS_Inv_Flow::preview_order($order_id, $value ?? 'nepradeta');
            if (!is_wp_error($stock_preview) && !empty($stock_preview['needs_decision'])) {
                return new WP_Error('cjs_stock_conflicts', __('This status change needs an inventory decision.', 'custom-jewelry-system'), ['status' => 409, 'preview' => $stock_preview]);
            }
        }
        return ['value' => $value, 'preview' => $stock_preview];
    }

    private static function commit_fields(int $order_id, array $prepared) {
        global $wpdb;
        CJS_Order_Extension::create_order_extension($order_id);
        $hours_before = null;
        $logged = [];
        foreach ($prepared as $field => $p) {
            $field = (string) $field;
            $value = $p['value'];
            $is_hours = $field === 'completed_hours' || $field === 'assigned_hours';
            if ($is_hours && $hours_before === null) {
                $hours_before = CJS_Statuses::hours_snapshot($order_id);
            }
            if ($field === 'manufacturing_status') {
                $ok = CJS_Statuses::write($order_id, $value);
                if (is_wp_error($ok)) {
                    return $ok;
                }
            } else {
                $ok = $wpdb->update($wpdb->prefix . 'cjs_order_extensions', [$field => $value, 'updated_at' => current_time('mysql')], ['order_id' => $order_id]);
                if ($ok === false) {
                    return new WP_Error('cjs_db', $wpdb->last_error);
                }
                $now_key = CJS_Inv_Flow::order_status_key($order_id);
                if ($field === 'assigned_hours' && CJS_Inv_Flow::rank($now_key) >= CJS_Inv_Flow::rank('pagaminta')) {
                    CJS_Statuses::fill_hours($order_id, $now_key);
                }
            }
            if (is_array($p['preview'] ?? null)) {
                CJS_Inv_Flow::after_status_change($order_id, $p['preview']);
            }
            $logged[$field] = $value;
        }
        $advanced = false;
        if ($hours_before !== null) {
            $adv = CJS_Statuses::hours_advance($order_id, $hours_before);
            if (!empty($adv['conflict'])) {
                foreach ($logged as $field => $value) {
                    CJS_Logger::log("Order field '{$field}' updated", 'info', 'order', $order_id, ['field' => $field, 'value' => $value]);
                }
                return new WP_Error('cjs_stock_conflicts', $adv['message'], ['status' => 409, 'preview' => $adv['preview'], 'from_hours' => true, 'message' => $adv['message'], 'order' => self::single($order_id)]);
            }
            $advanced = !empty($adv['advanced']);
        }
        foreach ($logged as $field => $value) {
            CJS_Logger::log("Order field '{$field}' updated", 'info', 'order', $order_id, ['field' => $field, 'value' => $value]);
        }
        return ['order' => self::single($order_id), 'auto_advanced' => $advanced];
    }

    public static function complete(int $order_id, array $opts = []) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'));
        }
        if (!$order->has_status('completed') && CJS_Inv_Flow::order_status_key($order_id) !== 'done') {
            if (empty($opts['confirmed'])) {
                $preview = CJS_Inv_Flow::preview_order($order_id, 'done');
                return new WP_Error('cjs_not_done', sprintf(__('The production status is %1$s, not %2$s. Completing the order sets it to %2$s.', 'custom-jewelry-system'), CJS_Inv_Flow::display_label(CJS_Inv_Flow::order_status_key($order_id)), CJS_Inv_Flow::display_label('done')), ['status' => 409, 'cjs_status' => CJS_Inv_Flow::order_status_key($order_id), 'preview' => is_wp_error($preview) ? null : $preview]);
            }
            $r = CJS_Inv_Flow::apply([$order_id], 'done', [$order_id => (array) ($opts['decisions'] ?? [])]);
            if (is_wp_error($r)) {
                return $r;
            }
        }
        if (!$order->has_status('completed')) {
            CJS_Inv_Flow::with_guard([$order_id], function () use ($order_id) {
                $fresh = wc_get_order($order_id);
                if ($fresh && !$fresh->has_status('completed')) {
                    $fresh->update_status('completed');
                }
            });
        }
        CJS_Logger::log('Order marked as completed', 'success', 'order', $order_id);
        return true;
    }

    public static function search(string $term, int $limit = 20) {
        global $wpdb;
        $s = self::src();
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        $digits = ltrim($term, '#');
        $conds = [
            $wpdb->prepare("CONCAT_WS(' ', {$s['first']}, {$s['last']}) LIKE %s", $like),
            $wpdb->prepare("{$s['email']} LIKE %s", $like),
        ];
        if (ctype_digit($digits)) {
            $conds[] = $wpdb->prepare("CAST({$s['id']} AS CHAR) LIKE %s", $wpdb->esc_like($digits) . '%');
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT {$s['id']} FROM {$s['orders']} o {$s['join_billing']} WHERE {$s['type']} AND {$s['status']} NOT IN ('trash','auto-draft','draft','wc-checkout-draft') AND (" . implode(' OR ', $conds) . ") ORDER BY {$s['id']} DESC LIMIT %d",
            $limit
        ));
        return array_values(self::order_labels(array_map('intval', $ids)));
    }

    public static function variant_text(int $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return '';
        }
        $items = $order->get_items();
        $seen = [];
        $parts = [];
        foreach ($items as $item) {
            foreach ($item->get_formatted_meta_data('', true) as $meta) {
                $key = wp_strip_all_tags($meta->display_key);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $parts[] = $key . ': ' . wp_strip_all_tags($meta->display_value);
            }
            if (count($items) === 1) {
                break;
            }
        }
        return implode(', ', $parts);
    }
}
