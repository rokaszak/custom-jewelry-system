<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Stock_Orders {

    const OPTION = 'cjs_stock_order_statuses';

    private static $model_checked = false;
    private static $order_state_cache = [];

    public static function default_statuses() {
        return [
            'ideta_i_krepseli' => ['label' => 'Įdėta į krepšelį', 'color' => '#bd0000'],
            'reikia_apmoketi' => ['label' => 'Reikia apmokėti', 'color' => '#b02a37'],
            'apmoketa' => ['label' => 'Apmokėta', 'color' => '#a35200'],
            'uzsakyta' => ['label' => 'Užsakyta', 'color' => '#7c6500'],
            'siunčiama' => ['label' => 'Siunčiama', 'color' => '#0f766e'],
            'gauta' => ['label' => 'Gauta', 'color' => '#146c2e'],
        ];
    }

    public static function statuses() {
        return [
            'not_ordered' => ['label' => __('Not ordered', 'custom-jewelry-system'), 'color' => '#b91c1c'],
            'partly_ordered' => ['label' => __('Partly ordered', 'custom-jewelry-system'), 'color' => '#b45309'],
            'ordered' => ['label' => __('Ordered', 'custom-jewelry-system'), 'color' => '#2563eb'],
            'partly_received' => ['label' => __('Partly received', 'custom-jewelry-system'), 'color' => '#7c6500'],
            'received' => ['label' => __('Received', 'custom-jewelry-system'), 'color' => '#146c2e'],
        ];
    }

    public static function legacy_statuses() {
        $statuses = get_option(self::OPTION, null);
        if (!is_array($statuses) || !$statuses) {
            $legacy = get_option('cjs_stone_order_statuses', null);
            $statuses = is_array($legacy) && $legacy ? $legacy : self::default_statuses();
        }
        $out = [];
        foreach ($statuses as $key => $data) {
            $out[(string) $key] = [
                'label' => is_array($data) ? (string) ($data['label'] ?? $key) : (string) $data,
                'color' => is_array($data) && !empty($data['color']) ? (string) $data['color'] : '#6b7280',
            ];
        }
        return $out;
    }

    public static function save_statuses(array $list) {
        $existing = self::legacy_statuses();
        $out = [];
        foreach ($list as $row) {
            $label = trim(sanitize_text_field($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $raw_key = (string) ($row['key'] ?? '');
            $key = ($raw_key !== '' && isset($existing[$raw_key])) ? $raw_key : sanitize_key($raw_key);
            if ($key === '') {
                $key = sanitize_key(remove_accents($label)) ?: 'status';
            }
            $base = $key;
            $n = 2;
            while (isset($out[$key])) {
                $key = $base . '_' . $n++;
            }
            $color = sanitize_hex_color($row['color'] ?? '') ?: '#6b7280';
            $out[$key] = ['label' => $label, 'color' => $color];
        }
        if (!$out) {
            return new WP_Error('cjs_invalid', __('At least one status is required.', 'custom-jewelry-system'));
        }
        update_option(self::OPTION, $out);
        return $out;
    }

    public static function default_status_key() {
        return 'not_ordered';
    }

    public static function received_status_key() {
        return 'received';
    }

    public static function status_payload($key) {
        $statuses = self::statuses();
        $key = isset($statuses[$key]) ? $key : 'not_ordered';
        return ['key' => $key, 'label' => $statuses[$key]['label'], 'color' => $statuses[$key]['color']];
    }

    public static function line_state(array $line) {
        $qty = (float) ($line['qty'] ?? 0);
        $received = (float) ($line['qty_received'] ?? 0);
        if ($qty > 0 && $received >= $qty) {
            return 'received';
        }
        if ($received > 0) {
            return 'partly_received';
        }
        if (!empty($line['in_cart'])) {
            return 'ordered';
        }
        return 'not_ordered';
    }

    public static function combine_states(array $states) {
        $n = count($states);
        if (!$n) {
            return 'not_ordered';
        }
        $c = array_count_values($states);
        $received = $c['received'] ?? 0;
        $partly = $c['partly_received'] ?? 0;
        $ordered = $c['ordered'] ?? 0;
        if ($received === $n) {
            return 'received';
        }
        if ($received + $partly > 0) {
            return 'partly_received';
        }
        if ($ordered === $n) {
            return 'ordered';
        }
        if ($ordered > 0) {
            return 'partly_ordered';
        }
        return 'not_ordered';
    }

    public static function demand_state(array $demand, array $line) {
        $qty = (float) ($demand['qty'] ?? 0);
        $allocated = (float) ($demand['qty_allocated'] ?? 0);
        if ($qty > 0 && $allocated >= $qty) {
            return 'received';
        }
        if ($allocated > 0) {
            return 'partly_received';
        }
        $line_state = self::line_state($line);
        if (($demand['state'] ?? '') === 'done') {
            return $line_state;
        }
        return $line_state === 'not_ordered' ? 'not_ordered' : 'ordered';
    }

    public static function order_states_for(array $stock_order_ids) {
        global $wpdb;
        $stock_order_ids = array_values(array_unique(array_filter(array_map('absint', $stock_order_ids))));
        if (!$stock_order_ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            'SELECT l.stock_order_id, l.qty AS line_qty, l.qty_received, l.in_cart, d.order_id, d.qty, d.qty_allocated, d.state FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l
             INNER JOIN ' . CJS_Inv_DB::t('demands') . ' d ON (d.po_line_id = l.id OR d.id IN (SELECT k.demand_id FROM ' . CJS_Inv_DB::t('demand_links') . ' k WHERE k.po_line_id = l.id))
             WHERE l.stock_order_id IN (' . CJS_Inv_DB::ids_in($stock_order_ids) . ") AND d.state <> 'cancelled' AND d.order_id > 0 ORDER BY d.id",
            ARRAY_A
        ) ?: [];
        $states = [];
        foreach ($rows as $r) {
            $line = ['qty' => $r['line_qty'], 'qty_received' => $r['qty_received'], 'in_cart' => (int) $r['in_cart']];
            $states[(int) $r['stock_order_id']][(int) $r['order_id']][] = self::demand_state($r, $line);
        }
        $out = [];
        foreach ($stock_order_ids as $so_id) {
            $out[$so_id] = [];
            foreach ($states[$so_id] ?? [] as $order_id => $list) {
                $out[$so_id][$order_id] = self::combine_states($list);
            }
        }
        return $out;
    }

    public static function status_for_order($stock_order_id, $order_id) {
        $stock_order_id = absint($stock_order_id);
        $order_id = absint($order_id);
        if (!$stock_order_id || !$order_id) {
            return null;
        }
        if (!array_key_exists($stock_order_id, self::$order_state_cache)) {
            $all = self::order_states_for([$stock_order_id]);
            self::$order_state_cache[$stock_order_id] = $all[$stock_order_id] ?? [];
        }
        $key = self::$order_state_cache[$stock_order_id][$order_id] ?? null;
        return $key ? self::status_payload($key) : null;
    }

    public static function refresh_status($stock_order_id) {
        global $wpdb;
        $stock_order_id = absint($stock_order_id);
        unset(self::$order_state_cache[$stock_order_id]);
        $row = self::row($stock_order_id);
        if (!$row) {
            return null;
        }
        $lines = $wpdb->get_results($wpdb->prepare('SELECT qty, qty_received, in_cart FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $stock_order_id), ARRAY_A) ?: [];
        $key = self::combine_states(array_map([__CLASS__, 'line_state'], $lines));
        if ($row['status'] !== $key) {
            CJS_Inv_DB::update('stock_orders', ['status' => $key], ['id' => $stock_order_id]);
        }
        return $key;
    }

    public static function upgrade_status_model($check_tables = true) {
        global $wpdb;
        if ($check_tables && CJS_Inv_DB::missing_tables()) {
            return 0;
        }
        $keys = array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, array_keys(self::statuses()));
        $rows = $wpdb->get_col('SELECT id FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE status NOT IN (' . implode(',', $keys) . ') ORDER BY id LIMIT 5000') ?: [];
        foreach ($rows as $id) {
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET in_cart = 1 WHERE stock_order_id = %d AND in_cart <> 1', (int) $id));
            self::refresh_status((int) $id);
        }
        if ($rows) {
            CJS_Inv_DB::query('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET in_cart = 1 WHERE in_cart > 1 OR (in_cart = 0 AND qty_received > 0)');
        }
        return count($rows);
    }

    public static function ensure_model() {
        if (self::$model_checked) {
            return;
        }
        self::$model_checked = true;
        try {
            self::upgrade_status_model(false);
        } catch (Throwable $e) {
            error_log('CJS stock order status upgrade failed: ' . $e->getMessage());
        }
    }

    public static function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function line($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function next_number() {
        global $wpdb;
        $max = $wpdb->get_var('SELECT MAX(CAST(number AS UNSIGNED)) FROM ' . CJS_Inv_DB::t('stock_orders') . " WHERE number REGEXP '^[0-9]+$'");
        return (string) (((int) $max) + 1);
    }

    public static function list(array $args = []) {
        global $wpdb;
        self::ensure_model();
        $s = CJS_Inv_DB::t('stock_orders');
        $l = CJS_Inv_DB::t('stock_order_lines');
        $d = CJS_Inv_DB::t('demands');
        $where = ['1=1'];
        if (!empty($args['status']) && is_array($args['status'])) {
            $in = array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', sanitize_text_field($v)); }, $args['status']);
            $where[] = 's.status IN (' . implode(',', $in) . ')';
        }
        if (!empty($args['search'])) {
            $term = trim($args['search']);
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where[] = $wpdb->prepare("(s.number LIKE %s OR s.supplier LIKE %s OR EXISTS (SELECT 1 FROM $l l2 INNER JOIN " . CJS_Inv_DB::t('items') . " i2 ON i2.id = l2.item_id WHERE l2.stock_order_id = s.id AND i2.name LIKE %s) OR EXISTS (SELECT 1 FROM $l l3 INNER JOIN $d d3 ON (d3.po_line_id = l3.id OR d3.id IN (SELECT k3.demand_id FROM " . CJS_Inv_DB::t('demand_links') . " k3 WHERE k3.po_line_id = l3.id)) WHERE l3.stock_order_id = s.id AND d3.order_id = %d))", $like, $like, $like, absint($term));
        }
        if (!empty($args['from'])) {
            $where[] = $wpdb->prepare('s.order_date >= %s', $args['from']);
        }
        if (!empty($args['to'])) {
            $where[] = $wpdb->prepare('s.order_date <= %s', $args['to']);
        }
        if (($args['open'] ?? '') === 'yes') {
            $where[] = "EXISTS (SELECT 1 FROM $l l4 WHERE l4.stock_order_id = s.id AND l4.qty_received < l4.qty)";
        }
        $where_sql = implode(' AND ', $where);
        $per_page = min(200, max(1, absint($args['per_page'] ?? 25)));
        $page = max(1, absint($args['page'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $s s WHERE $where_sql");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.display_name,
                (SELECT COUNT(*) FROM $l l WHERE l.stock_order_id = s.id) AS line_count,
                (SELECT COALESCE(SUM(l.qty), 0) FROM $l l WHERE l.stock_order_id = s.id) AS qty_total,
                (SELECT COALESCE(SUM(LEAST(l.qty_received, l.qty)), 0) FROM $l l WHERE l.stock_order_id = s.id) AS qty_received,
                (SELECT COUNT(*) FROM $l l WHERE l.stock_order_id = s.id AND (l.in_cart > 0 OR l.qty_received > 0)) AS in_cart_count,
                (SELECT COALESCE(SUM(l.qty * l.unit_cost), 0) FROM $l l WHERE l.stock_order_id = s.id AND l.unit_cost IS NOT NULL) AS cost_total
             FROM $s s LEFT JOIN {$wpdb->users} u ON u.ID = s.created_by
             WHERE $where_sql ORDER BY s.order_date DESC, s.id DESC LIMIT %d OFFSET %d",
            $per_page, ($page - 1) * $per_page
        ), ARRAY_A) ?: [];
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $related = [];
        $previews = [];
        if ($ids) {
            $order_states = self::order_states_for($ids);
            $order_ids = [];
            foreach ($order_states as $map) {
                foreach (array_keys($map) as $oid) {
                    $order_ids[] = (int) $oid;
                }
            }
            $labels = CJS_Orders::order_labels(array_values(array_unique($order_ids)));
            foreach ($order_states as $so_id => $map) {
                foreach ($map as $oid => $state) {
                    $related[(int) $so_id][] = ($labels[$oid] ?? ['id' => (int) $oid, 'number' => (string) $oid]) + ['status_for_order' => self::status_payload($state)];
                }
            }
            $lines = $wpdb->get_results("SELECT l.id, l.stock_order_id, l.qty, l.qty_received, l.in_cart, l.note, l.item_id, i.name, i.unit_id, i.category_id, i.praba FROM $l l INNER JOIN " . CJS_Inv_DB::t('items') . " i ON i.id = l.item_id WHERE l.stock_order_id IN (" . CJS_Inv_DB::ids_in($ids) . ') ORDER BY l.sort_order, l.id', ARRAY_A) ?: [];
            $notes = [];
            if ($lines) {
                $in = CJS_Inv_DB::ids_in(array_map(function ($ln) { return (int) $ln['id']; }, $lines));
                $noted = $wpdb->get_results('SELECT x.line_id, d.order_id, d.note FROM (SELECT id AS demand_id, po_line_id AS line_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE po_line_id IN ($in) UNION SELECT demand_id, po_line_id FROM " . CJS_Inv_DB::t('demand_links') . " WHERE po_line_id IN ($in)) x INNER JOIN " . CJS_Inv_DB::t('demands') . " d ON d.id = x.demand_id WHERE d.state <> 'cancelled' AND d.note IS NOT NULL AND d.note <> '' ORDER BY d.id", ARRAY_A) ?: [];
                $note_labels = CJS_Orders::order_labels(array_map(function ($n) { return (int) $n['order_id']; }, $noted));
                foreach ($noted as $n) {
                    $oid = (int) $n['order_id'];
                    $notes[(int) $n['line_id']][] = ['label' => $oid > 0 ? '#' . (($note_labels[$oid]['number'] ?? '') ?: $oid) : null, 'text' => (string) $n['note']];
                }
            }
            foreach ($lines as $ln) {
                $comments = $notes[(int) $ln['id']] ?? [];
                if (trim((string) $ln['note']) !== '') {
                    $comments[] = ['label' => $comments ? __('Stock', 'custom-jewelry-system') : null, 'text' => (string) $ln['note']];
                }
                $state = self::line_state($ln);
                $previews[(int) $ln['stock_order_id']][] = [
                    'item_id' => (int) $ln['item_id'],
                    'category_id' => (int) $ln['category_id'],
                    'name' => $ln['name'],
                    'qty' => (float) $ln['qty'],
                    'qty_received' => (float) $ln['qty_received'],
                    'fineness' => self::line_fineness($ln),
                    'unit' => CJS_Inv_Catalog::unit_label($ln['unit_id']),
                    'state' => $state,
                    'received' => $state === 'received',
                    'in_cart' => $state !== 'not_ordered',
                    'comments' => $comments,
                ];
            }
        }
        return [
            'stock_orders' => array_map(function ($r) use ($related, $previews) {
                $id = (int) $r['id'];
                $preview = $previews[$id] ?? [];
                return self::format_header($r, array_column($preview, 'state')) + [
                    'line_count' => (int) $r['line_count'],
                    'qty_total' => (float) $r['qty_total'],
                    'qty_received' => (float) $r['qty_received'],
                    'in_cart_count' => (int) $r['in_cart_count'],
                    'ordered_count' => (int) $r['in_cart_count'],
                    'cost_total' => (float) $r['cost_total'],
                    'created_by_name' => $r['display_name'],
                    'orders' => $related[$id] ?? [],
                    'lines_preview' => $preview,
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    public static function format_header(array $r, $line_states = null) {
        $key = is_array($line_states) ? self::combine_states($line_states) : (isset(self::statuses()[$r['status']]) ? $r['status'] : 'not_ordered');
        $status = self::status_payload($key);
        return [
            'id' => (int) $r['id'],
            'number' => $r['number'],
            'order_date' => $r['order_date'],
            'status' => $status['key'],
            'status_label' => $status['label'],
            'status_color' => $status['color'],
            'supplier' => $r['supplier'],
            'note' => $r['note'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }

    public static function get($id) {
        global $wpdb;
        self::ensure_model();
        $row = self::row($id);
        if (!$row) {
            return null;
        }
        $lines = $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, i.name, i.code, i.unit_id, i.per_piece, i.category_id, i.qty_on_hand, i.qty_reserved, i.praba FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l
             INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = l.item_id WHERE l.stock_order_id = %d ORDER BY l.sort_order, l.id',
            $id
        ), ARRAY_A) ?: [];
        $out = self::format_header($row, array_map([__CLASS__, 'line_state'], $lines));
        $user = $row['created_by'] ? get_userdata((int) $row['created_by']) : null;
        $out['created_by_name'] = $user ? $user->display_name : '';
        $line_by_id = [];
        foreach ($lines as $l) {
            $line_by_id[(int) $l['id']] = $l;
        }
        $demands = [];
        $order_states = [];
        $for_orders = [];
        $waiting_qty = [];
        if ($line_by_id) {
            $in = CJS_Inv_DB::ids_in(array_keys($line_by_id));
            $rows = $wpdb->get_results('SELECT d.*, x.link_line_id FROM (SELECT id AS link_demand_id, po_line_id AS link_line_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE po_line_id IN ($in) UNION SELECT demand_id, po_line_id FROM " . CJS_Inv_DB::t('demand_links') . " WHERE po_line_id IN ($in)) x INNER JOIN " . CJS_Inv_DB::t('demands') . " d ON d.id = x.link_demand_id WHERE d.state <> 'cancelled' ORDER BY d.id, x.link_line_id", ARRAY_A) ?: [];
            $labels = CJS_Orders::order_labels(array_map(function ($r) { return (int) $r['order_id']; }, $rows));
            foreach ($rows as $r) {
                $state = self::demand_state($r, $line_by_id[(int) $r['link_line_id']] ?? []);
                if ((int) $r['order_id'] > 0) {
                    $order_states[(int) $r['order_id']][] = $state;
                }
                $demands[(int) $r['link_line_id']][] = [
                    'id' => (int) $r['id'],
                    'order' => $labels[(int) $r['order_id']] ?? ['id' => (int) $r['order_id'], 'number' => (string) $r['order_id']],
                    'order_item_id' => $r['order_item_id'] !== null ? (int) $r['order_item_id'] : null,
                    'qty' => (float) $r['qty'],
                    'allocated' => (float) $r['qty_allocated'],
                    'missing' => max(0, round((float) $r['qty'] - (float) $r['qty_allocated'], 4)),
                    'state' => $r['state'],
                    'note' => $r['note'],
                    'status_for_order' => self::status_payload($state),
                ];
            }
            $taken = $wpdb->get_results('SELECT b.source_id AS line_id, SUM(a.qty) AS q FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id INNER JOIN ' . CJS_Inv_DB::t('demands') . " d ON d.id = a.demand_id WHERE b.source_type = 'stock_order' AND b.source_id IN ($in) AND a.state <> 'released' AND d.order_id > 0 GROUP BY b.source_id", ARRAY_A) ?: [];
            foreach ($taken as $t) {
                $for_orders[(int) $t['line_id']] = ($for_orders[(int) $t['line_id']] ?? 0) + (float) $t['q'];
            }
            foreach (self::ordered_parts($rows) as $pf) {
                foreach ($pf['parts'] ?? [] as $part) {
                    $lid = (int) $part['line_id'];
                    if (isset($line_by_id[$lid])) {
                        $for_orders[$lid] = ($for_orders[$lid] ?? 0) + (float) $part['qty'];
                        $waiting_qty[$lid] = ($waiting_qty[$lid] ?? 0) + (float) $part['qty'];
                    }
                }
            }
        }
        $out['lines'] = array_map(function ($l) use ($demands, $for_orders, $waiting_qty) {
            $state = self::line_state($l);
            return [
                'id' => (int) $l['id'],
                'item_id' => (int) $l['item_id'],
                'name' => $l['name'],
                'code' => $l['code'],
                'unit' => CJS_Inv_Catalog::unit_label($l['unit_id']),
                'per_piece' => (bool) $l['per_piece'],
                'category_id' => (int) $l['category_id'],
                'qty' => (float) $l['qty'],
                'qty_received' => (float) $l['qty_received'],
                'fineness' => self::line_fineness($l),
                'unit_cost' => $l['unit_cost'] !== null ? (float) $l['unit_cost'] : null,
                'state' => $state,
                'ordered' => $state !== 'not_ordered',
                'in_cart' => $state !== 'not_ordered',
                'note' => $l['note'],
                'received' => $state === 'received',
                'item_free' => (float) $l['qty_on_hand'] - (float) $l['qty_reserved'],
                'demands' => $demands[(int) $l['id']] ?? [],
                'for_orders' => round($for_orders[(int) $l['id']] ?? 0, 4),
                'waiting_qty' => round($waiting_qty[(int) $l['id']] ?? 0, 4),
                'planned_identifiers' => self::planned_of($l),
            ];
        }, $lines);
        $out['orders'] = [];
        $seen = [];
        foreach ($demands as $list) {
            foreach ($list as $dmd) {
                $oid = (int) $dmd['order']['id'];
                if ($oid > 0 && !isset($seen[$oid])) {
                    $seen[$oid] = true;
                    $out['orders'][] = $dmd['order'] + ['status_for_order' => self::status_payload(self::combine_states($order_states[$oid] ?? []))];
                }
            }
        }
        $out['whatsapp'] = self::whatsapp_message($id);
        return $out;
    }

    public static function line_fineness(array $line) {
        if (array_key_exists('praba', $line) && array_key_exists('category_id', $line)) {
            return CJS_Inv_Metals::tracked((int) $line['category_id']) ? (CJS_Inv_Flow::clean_praba($line['praba']) ?? CJS_Inv_Flow::PRABA_REF) : null;
        }
        $item = CJS_Inv_Catalog::item_row((int) ($line['item_id'] ?? 0));
        return $item && CJS_Inv_Metals::tracked((int) $item['category_id']) ? CJS_Inv_Metals::conv_praba($item) : null;
    }

    public static function extra_links(array $demand_ids) {
        global $wpdb;
        $demand_ids = array_values(array_unique(array_filter(array_map('absint', $demand_ids))));
        if (!$demand_ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT demand_id, po_line_id FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE demand_id IN (' . CJS_Inv_DB::ids_in($demand_ids) . ') ORDER BY po_line_id', ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['demand_id']][] = (int) $r['po_line_id'];
        }
        return $out;
    }

    public static function link_demand($demand_id, $line_id) {
        global $wpdb;
        $d = CJS_Inv_Demands::row((int) $demand_id);
        $line_id = (int) $line_id;
        if (!$d || !$line_id) {
            return false;
        }
        if (!$d['po_line_id']) {
            CJS_Inv_DB::update('demands', ['po_line_id' => $line_id, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $d['id']]);
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE demand_id = %d AND po_line_id = %d', (int) $d['id'], $line_id));
        } elseif ((int) $d['po_line_id'] !== $line_id) {
            CJS_Inv_DB::query($wpdb->prepare('INSERT IGNORE INTO ' . CJS_Inv_DB::t('demand_links') . ' (demand_id, po_line_id, created_at) VALUES (%d, %d, %s)', (int) $d['id'], $line_id, CJS_Inv_DB::now()));
        }
        self::$order_state_cache = [];
        return true;
    }

    public static function drop_covered_links($demand_id) {
        global $wpdb;
        $d = CJS_Inv_Demands::row((int) $demand_id);
        if (!$d || $d['state'] !== 'open' || (float) $d['qty_allocated'] < (float) $d['qty'] - CJS_Inv_Flow::EPS) {
            return 0;
        }
        $k = CJS_Inv_DB::t('demand_links');
        $linked = $d['po_line_id'] ? [(int) $d['po_line_id']] : [];
        foreach (self::extra_links([(int) $d['id']])[(int) $d['id']] ?? [] as $lid) {
            if (!in_array($lid, $linked, true)) {
                $linked[] = $lid;
            }
        }
        if (!$linked) {
            return 0;
        }
        $lines = $wpdb->get_results('SELECT l.id, l.stock_order_id, l.qty, l.qty_received, s.number FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id WHERE l.id IN (' . CJS_Inv_DB::ids_in($linked) . ')', ARRAY_A) ?: [];
        $fed = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT DISTINCT b.source_id FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = a.batch_id WHERE a.demand_id = %d AND a.state <> 'released' AND b.source_type = 'stock_order'", (int) $d['id'])) ?: []);
        $drop = [];
        $numbers = [];
        foreach ($lines as $l) {
            if ((float) $l['qty_received'] < (float) $l['qty'] && !in_array((int) $l['id'], $fed, true)) {
                $drop[] = (int) $l['id'];
                $numbers[(int) $l['stock_order_id']] = (string) $l['number'];
            }
        }
        if (!$drop) {
            return 0;
        }
        $keep = array_values(array_diff($linked, $drop));
        $primary = $d['po_line_id'] && in_array((int) $d['po_line_id'], $keep, true) ? (int) $d['po_line_id'] : ($keep[0] ?? null);
        CJS_Inv_DB::update('demands', ['po_line_id' => $primary, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $d['id']]);
        CJS_Inv_DB::query($wpdb->prepare("DELETE FROM $k WHERE demand_id = %d AND po_line_id IN (" . CJS_Inv_DB::ids_in(array_merge($drop, $primary ? [$primary] : [])) . ')', (int) $d['id']));
        foreach ($numbers as $so_id => $number) {
            self::link_log($d, (int) $so_id, 'covered', sprintf(__('Covered from stock, no longer waiting for stock order #%s', 'custom-jewelry-system'), $number));
        }
        self::$order_state_cache = [];
        return count($drop);
    }

    private static $relink_queue = [];
    private static $relinking = false;
    private static $relink_hold = 0;

    public static function queue_relink($demand_id) {
        $demand_id = (int) $demand_id;
        if ($demand_id > 0) {
            self::$relink_queue[$demand_id] = true;
        }
    }

    public static function hold_relink() {
        self::$relink_hold++;
    }

    public static function release_relink() {
        self::$relink_hold = max(0, self::$relink_hold - 1);
    }

    public static function relink_pending() {
        if (self::$relinking || self::$relink_hold > 0 || !self::$relink_queue) {
            return 0;
        }
        self::$relinking = true;
        $n = 0;
        try {
            while (self::$relink_queue) {
                $ids = array_keys(self::$relink_queue);
                self::$relink_queue = [];
                foreach ($ids as $did) {
                    try {
                        $n += self::relink_dropped((int) $did);
                    } catch (Throwable $e) {
                        error_log('CJS relink to stock order failed for line ' . $did . ': ' . $e->getMessage());
                    }
                }
            }
        } finally {
            self::$relinking = false;
        }
        return $n;
    }

    const LINK_MARKS = ['covered', 'relinked', 'removed', 'linked'];

    public static function note_link_kind($note) {
        static $patterns = null;
        if ($patterns === null) {
            $patterns = [];
            $formats = [
                'covered' => ['Covered from stock, no longer waiting for stock order #%s'],
                'relinked' => ['Needs stock again, back on stock order #%1$s for %2$s %3$s at %4$s', 'Needs stock again, back on stock order #%1$s for %2$s %3$s'],
                'removed' => ['Removed from stock order #%s by hand'],
            ];
            foreach ($formats as $kind => $list) {
                foreach ($list as $format) {
                    foreach (array_unique([$format, (string) __($format, 'custom-jewelry-system')]) as $text) {
                        if (strpos($text, '%') === false) {
                            continue;
                        }
                        $regex = preg_replace('/%(\d+\\\\\$)?s/', '(.+?)', preg_quote($text, '/'));
                        if (is_string($regex) && $regex !== '') {
                            $patterns[] = ['/^' . $regex . '$/su', $kind];
                        }
                    }
                }
            }
        }
        $note = (string) $note;
        $found = null;
        foreach ($patterns as [$regex, $kind]) {
            if (@preg_match($regex, $note) === 1) {
                if ($found !== null && $found !== $kind) {
                    return null;
                }
                $found = $kind;
            }
        }
        return $found;
    }

    private static function link_log(array $d, $stock_order_id, $kind, $note) {
        CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, [
            'order_id' => (int) $d['order_id'],
            'demand_id' => (int) $d['id'],
            'stock_order_id' => (int) $stock_order_id,
            'link_mark' => $kind,
            'note' => $note,
        ]);
    }

    private static function link_marks(array $demands) {
        global $wpdb;
        $ids = [];
        $orders = [];
        foreach ($demands as $d) {
            if ((int) ($d['id'] ?? 0) > 0 && (int) ($d['order_id'] ?? 0) > 0) {
                $ids[] = (int) $d['id'];
                $orders[] = (int) $d['order_id'];
            }
        }
        if (!$ids) {
            return [];
        }
        $marked = CJS_Inv_DB::has_column('movements', 'link_mark');
        $notes = !$marked || self::marks_pending();
        $rows = $wpdb->get_results('SELECT id, demand_id, stock_order_id, note' . ($marked ? ', link_mark' : '') . ' FROM ' . CJS_Inv_DB::t('movements') . ' WHERE order_id IN (' . CJS_Inv_DB::ids_in(array_unique($orders)) . ') AND demand_id IN (' . CJS_Inv_DB::ids_in($ids) . ") AND type = 'demand' AND stock_order_id IS NOT NULL" . ($notes ? '' : ' AND link_mark IS NOT NULL') . ' ORDER BY id', ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $kind = $marked && !empty($r['link_mark']) ? (string) $r['link_mark'] : ($notes ? self::note_link_kind($r['note']) : null);
            if (!in_array($kind, self::LINK_MARKS, true)) {
                continue;
            }
            $did = (int) $r['demand_id'];
            $so = (int) $r['stock_order_id'];
            if (!isset($out[$did][$so])) {
                $out[$did][$so] = array_fill_keys(self::LINK_MARKS, 0);
            }
            $out[$did][$so][$kind] = max($out[$did][$so][$kind], (int) $r['id']);
        }
        return $out;
    }

    private static function marks_pending() {
        if (!class_exists('CJS_Inv_Migration')) {
            return false;
        }
        return (int) get_option(CJS_Inv_Migration::MODEL_OPTION, 0) < CJS_Inv_Migration::MODEL_VERSION;
    }

    private static function remembered(array $mark) {
        return $mark['covered'] > 0 && $mark['covered'] > $mark['removed'];
    }

    private static function relinked_seq(array $mark) {
        return $mark['relinked'] > max($mark['covered'], $mark['removed'], $mark['linked']) ? $mark['relinked'] : 0;
    }

    private static function line_parts_total($line_id) {
        global $wpdb;
        $k = CJS_Inv_DB::t('demand_links');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT d.* FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.state = 'open' AND d.qty_allocated < d.qty AND (d.po_line_id = %d OR d.id IN (SELECT k.demand_id FROM $k k WHERE k.po_line_id = %d))", (int) $line_id, (int) $line_id), ARRAY_A) ?: [];
        if (!$rows) {
            return 0.0;
        }
        $total = 0.0;
        foreach (self::ordered_parts($rows) as $entry) {
            foreach ($entry['parts'] as $part) {
                if ((int) $part['line_id'] === (int) $line_id) {
                    $total += (float) $part['qty'];
                }
            }
        }
        return round($total, 4);
    }

    public static function mark_hand_link($demand_id, $line_id) {
        $d = CJS_Inv_Demands::row((int) $demand_id);
        $line = self::line((int) $line_id);
        if (!$d || !$line || (int) $d['order_id'] <= 0) {
            return false;
        }
        $so_id = (int) $line['stock_order_id'];
        $mark = self::link_marks([$d])[(int) $d['id']][$so_id] ?? null;
        if (!$mark || self::relinked_seq($mark) <= 0) {
            return false;
        }
        $so = self::row($so_id);
        self::link_log($d, $so_id, 'linked', sprintf(__('Added to stock order #%s by hand', 'custom-jewelry-system'), $so ? $so['number'] : $so_id));
        self::$order_state_cache = [];
        return true;
    }

    private static function mark_lines_removed(array $line_ids) {
        global $wpdb;
        $line_ids = array_values(array_unique(array_filter(array_map('absint', $line_ids))));
        if (!$line_ids) {
            return;
        }
        $in = CJS_Inv_DB::ids_in($line_ids);
        $d = CJS_Inv_DB::t('demands');
        $l = CJS_Inv_DB::t('stock_order_lines');
        $s = CJS_Inv_DB::t('stock_orders');
        $k = CJS_Inv_DB::t('demand_links');
        $rows = $wpdb->get_results("SELECT d.id, d.item_id, d.order_id, l.stock_order_id, s.number FROM $d d INNER JOIN $l l ON l.id = d.po_line_id INNER JOIN $s s ON s.id = l.stock_order_id WHERE d.po_line_id IN ($in) AND d.order_id > 0 AND d.state = 'open'
            UNION SELECT d.id, d.item_id, d.order_id, l.stock_order_id, s.number FROM $k k INNER JOIN $d d ON d.id = k.demand_id INNER JOIN $l l ON l.id = k.po_line_id INNER JOIN $s s ON s.id = l.stock_order_id WHERE k.po_line_id IN ($in) AND d.order_id > 0 AND d.state = 'open'", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            self::link_log($r, (int) $r['stock_order_id'], 'removed', sprintf(__('Removed from stock order #%s by hand', 'custom-jewelry-system'), (string) $r['number']));
        }
    }

    public static function relink_dropped($demand_id) {
        global $wpdb;
        $d = CJS_Inv_Demands::row((int) $demand_id);
        if (!$d || $d['state'] !== 'open' || (int) $d['order_id'] <= 0 || (float) $d['qty_allocated'] >= (float) $d['qty'] - CJS_Inv_Flow::EPS) {
            return 0;
        }
        if (CJS_Inv_Flow::order_closed((int) $d['order_id'])) {
            return 0;
        }
        $dropped = [];
        foreach (self::link_marks([$d])[(int) $d['id']] ?? [] as $so_id => $mark) {
            if (self::remembered($mark)) {
                $dropped[(int) $so_id] = true;
            }
        }
        if (!$dropped) {
            return 0;
        }
        $unordered = (float) self::parts_fields($d, self::ordered_parts([$d]))['unordered'];
        if ($unordered <= CJS_Inv_Flow::EPS) {
            return 0;
        }
        $linked = $d['po_line_id'] ? [(int) $d['po_line_id']] : [];
        foreach (self::extra_links([(int) $d['id']])[(int) $d['id']] ?? [] as $lid) {
            $linked[] = (int) $lid;
        }
        $candidates = $wpdb->get_results('SELECT l.id, l.stock_order_id, l.item_id, l.qty, l.qty_received, s.number, i.praba, i.category_id, i.unit_id AS item_unit_id, ' . CJS_Inv_Metals::sql('i') . ' AS item_metal FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = l.item_id WHERE l.stock_order_id IN (' . CJS_Inv_DB::ids_in(array_keys($dropped)) . ') AND l.qty_received < l.qty ORDER BY l.stock_order_id, l.sort_order, l.id', ARRAY_A) ?: [];
        $metal = CJS_Inv_Metals::of_line($d);
        $done = 0;
        foreach ($candidates as $l) {
            if ($unordered <= CJS_Inv_Flow::EPS) {
                break;
            }
            if (in_array((int) $l['id'], $linked, true)) {
                continue;
            }
            $line_metal = $l['category_id'] !== null && CJS_Inv_Metals::tracked((int) $l['category_id']) ? ['category_id' => (int) $l['category_id'], 'metal' => (string) $l['item_metal']] : null;
            $fits = $metal ? CJS_Inv_Metals::same($metal, $line_metal) && CJS_Inv_Metals::same_unit($d, $l['item_unit_id']) : (int) $l['item_id'] === (int) $d['item_id'];
            if (!$fits) {
                continue;
            }
            $praba = self::line_fineness($l);
            $left = max(0.0, round((float) $l['qty'] - (float) $l['qty_received'], 4));
            $free_g = round($left - self::line_parts_total((int) $l['id']), 4);
            if ($free_g <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $free = $praba ? CJS_Inv_Flow::fine_for($free_g, $praba) : $free_g;
            self::link_demand((int) $d['id'], (int) $l['id']);
            $linked[] = (int) $l['id'];
            $take = min($free, $unordered);
            $unordered = round($unordered - $take, 4);
            $unit = CJS_Inv_Catalog::unit_label($d['unit_id']);
            self::link_log($d, (int) $l['stock_order_id'], 'relinked', $praba !== null
                ? sprintf(__('Needs stock again, back on stock order #%1$s for %2$s %3$s at %4$s', 'custom-jewelry-system'), $l['number'], CJS_Inv_DB::fmt($take), $unit, CJS_Inv_DB::fmt(CJS_Inv_Flow::PRABA_REF))
                : sprintf(__('Needs stock again, back on stock order #%1$s for %2$s %3$s', 'custom-jewelry-system'), $l['number'], CJS_Inv_DB::fmt($take), $unit));
            $done++;
        }
        if ($done) {
            self::$order_state_cache = [];
        }
        return $done;
    }

    public static function drop_line_links(array $line_ids) {
        global $wpdb;
        $line_ids = array_values(array_unique(array_filter(array_map('absint', $line_ids))));
        if (!$line_ids) {
            return;
        }
        $in = CJS_Inv_DB::ids_in($line_ids);
        $k = CJS_Inv_DB::t('demand_links');
        $primary = $wpdb->get_col('SELECT id FROM ' . CJS_Inv_DB::t('demands') . " WHERE po_line_id IN ($in)") ?: [];
        foreach ($primary as $did) {
            $next = (int) $wpdb->get_var($wpdb->prepare("SELECT po_line_id FROM $k WHERE demand_id = %d AND po_line_id NOT IN ($in) ORDER BY po_line_id LIMIT 1", (int) $did));
            if ($next) {
                CJS_Inv_DB::update('demands', ['po_line_id' => $next, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $did]);
                CJS_Inv_DB::query($wpdb->prepare("DELETE FROM $k WHERE demand_id = %d AND po_line_id = %d", (int) $did, $next));
            }
        }
        CJS_Inv_DB::query("DELETE FROM $k WHERE po_line_id IN ($in)");
        self::$order_state_cache = [];
    }

    public static function ordered_parts(array $rows) {
        global $wpdb;
        $seed = [];
        $linked = false;
        foreach ($rows as $r) {
            $did = (int) ($r['id'] ?? 0);
            if ($did <= 0 || (string) ($r['state'] ?? 'open') !== 'open') {
                continue;
            }
            $seed[] = $did;
            if (!empty($r['po_line_id'])) {
                $linked = true;
            }
        }
        if (!$seed) {
            return [];
        }
        if (!$linked && !self::extra_links($seed)) {
            return [];
        }
        $k = CJS_Inv_DB::t('demand_links');
        $demands = $wpdb->get_results('SELECT d.id, d.order_id, d.item_id, d.category_id, d.metal, d.unit_id, d.qty, d.qty_allocated, d.po_line_id FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.state = 'open' AND d.qty_allocated < d.qty AND (d.po_line_id IS NOT NULL OR EXISTS (SELECT 1 FROM $k k WHERE k.demand_id = d.id)) ORDER BY d.created_at ASC, d.id ASC", ARRAY_A) ?: [];
        $order_ids = array_values(array_unique(array_filter(array_map(function ($d) { return (int) $d['order_id']; }, $demands))));
        if ($order_ids) {
            $statuses = CJS_Inv_Demands::order_statuses_fast($order_ids);
            $demands = array_values(array_filter($demands, function ($d) use ($statuses) {
                $oid = (int) $d['order_id'];
                return $oid <= 0 || !CJS_Inv_Flow::order_closed($oid, (string) ($statuses[$oid] ?? ''));
            }));
        }
        if (!$demands) {
            return [];
        }
        $extra = self::extra_links(array_map(function ($d) { return (int) $d['id']; }, $demands));
        $line_ids = [];
        foreach ($demands as $d) {
            if ($d['po_line_id']) {
                $line_ids[] = (int) $d['po_line_id'];
            }
            foreach ($extra[(int) $d['id']] ?? [] as $lid) {
                $line_ids[] = $lid;
            }
        }
        $lines = [];
        if ($line_ids) {
            $list = $wpdb->get_results('SELECT l.id, l.stock_order_id, l.item_id, l.qty, l.qty_received, l.in_cart, s.number, i.name, i.praba, i.category_id, i.unit_id AS item_unit_id, ' . CJS_Inv_Metals::sql('i') . ' AS item_metal FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = l.item_id WHERE l.id IN (' . CJS_Inv_DB::ids_in($line_ids) . ')', ARRAY_A) ?: [];
            foreach ($list as $l) {
                $praba = self::line_fineness($l);
                $left = max(0.0, round((float) $l['qty'] - (float) $l['qty_received'], 4));
                $lines[(int) $l['id']] = $l + [
                    'line_praba' => $praba,
                    'metal_ref' => $praba !== null ? ['category_id' => (int) $l['category_id'], 'metal' => (string) $l['item_metal']] : null,
                    'left_g' => $left,
                    'left_ref' => $left > 0 ? ($praba ? CJS_Inv_Flow::fine_for($left, $praba) : $left) : 0.0,
                ];
            }
        }
        $late = [];
        $marks = self::link_marks($demands);
        if ($marks) {
            foreach ($demands as $d) {
                $did = (int) $d['id'];
                if (empty($marks[$did])) {
                    continue;
                }
                $own = $d['po_line_id'] ? [(int) $d['po_line_id']] : [];
                foreach (array_merge($own, $extra[$did] ?? []) as $lid) {
                    $l = $lines[$lid] ?? null;
                    $mark = $l ? ($marks[$did][(int) $l['stock_order_id']] ?? null) : null;
                    $seq = $mark ? self::relinked_seq($mark) : 0;
                    if ($seq > 0) {
                        $late[$did][(int) $lid] = $seq;
                    }
                }
            }
        }
        $needs = [];
        $parts = [];
        $metals = [];
        $by_id = [];
        $queue = [];
        $late_pairs = [];
        foreach ($demands as $d) {
            $did = (int) $d['id'];
            $by_id[$did] = $d;
            $needs[$did] = round((float) $d['qty'] - (float) $d['qty_allocated'], 4);
            $metals[$did] = CJS_Inv_Metals::of_line($d);
            $parts[$did] = [];
            $order = $d['po_line_id'] ? [(int) $d['po_line_id']] : [];
            foreach ($extra[$did] ?? [] as $lid) {
                if (!in_array($lid, $order, true)) {
                    $order[] = $lid;
                }
            }
            foreach ($order as $pos => $lid) {
                if (isset($late[$did][$lid])) {
                    $late_pairs[] = [$late[$did][$lid], $did, $pos, $lid];
                } else {
                    $queue[] = [$did, $lid];
                }
            }
        }
        usort($late_pairs, function ($a, $b) {
            return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
        });
        foreach ($late_pairs as $pair) {
            $queue[] = [$pair[1], $pair[3]];
        }
        $sequence = [];
        foreach ($queue as [$did, $lid]) {
            if ($needs[$did] <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $d = $by_id[$did];
            $l = $lines[$lid] ?? null;
            if (!$l || $lines[$lid]['left_g'] <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $metal = $metals[$did];
            $fits = $metal ? CJS_Inv_Metals::same($metal, $l['metal_ref']) && CJS_Inv_Metals::same_unit($d, $l['item_unit_id']) : (int) $l['item_id'] === (int) $d['item_id'];
            if (!$fits) {
                continue;
            }
            $need = $needs[$did];
            $left_g = $lines[$lid]['left_g'];
            $praba = $l['line_praba'];
            if ($praba) {
                $want_g = CJS_Inv_Flow::physical_for($need, $praba);
                if ($want_g >= $left_g - CJS_Inv_Flow::EPS) {
                    $grams = $left_g;
                    $take = min($need, CJS_Inv_Flow::fine_for($left_g, $praba));
                } else {
                    $grams = $want_g;
                    $take = $need;
                }
            } else {
                $take = min($need, $left_g);
                $grams = $take;
            }
            $lines[$lid]['left_g'] = round($left_g - $grams, 4);
            $lines[$lid]['left_ref'] = $praba ? ($lines[$lid]['left_g'] > CJS_Inv_Flow::EPS ? CJS_Inv_Flow::fine_for($lines[$lid]['left_g'], $praba) : 0.0) : $lines[$lid]['left_g'];
            $needs[$did] = round($need - $take, 4);
            $parts[$did][] = [
                'stock_order_id' => (int) $l['stock_order_id'],
                'number' => (string) $l['number'],
                'line_id' => $lid,
                'item_id' => (int) $l['item_id'],
                'item_name' => (string) ($l['name'] ?? ''),
                'qty' => round($grams, 4),
                'fineness' => $l['line_praba'] !== null ? (float) $l['line_praba'] : null,
                'at_ref' => round($take, 4),
                'placed' => (int) $l['in_cart'] > 0 || (float) $l['qty_received'] > 0,
            ];
            $sequence[] = ['demand_id' => $did, 'line_id' => $lid, 'at_ref' => round($take, 4)];
        }
        self::$last_sequence = $sequence;
        $out = [];
        foreach ($demands as $d) {
            $did = (int) $d['id'];
            $out[$did] = ['parts' => $parts[$did] ?? [], 'unordered' => max(0.0, $needs[$did] ?? 0.0)];
        }
        return $out;
    }

    private static $last_sequence = [];

    public static function line_queue($line_id) {
        global $wpdb;
        $line_id = (int) $line_id;
        if ($line_id <= 0) {
            return [];
        }
        $k = CJS_Inv_DB::t('demand_links');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT d.* FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.state = 'open' AND d.order_id > 0 AND d.qty_allocated < d.qty AND (d.po_line_id = %d OR d.id IN (SELECT k.demand_id FROM $k k WHERE k.po_line_id = %d))", $line_id, $line_id), ARRAY_A) ?: [];
        if (!$rows) {
            return [];
        }
        self::$last_sequence = [];
        self::ordered_parts($rows);
        $out = [];
        foreach (self::$last_sequence as $s) {
            if ((int) $s['line_id'] === $line_id && $s['at_ref'] > CJS_Inv_Flow::EPS) {
                $out[] = $s;
            }
        }
        self::$last_sequence = [];
        return $out;
    }

    private static function serve_line_queue(array $plan, $batch_id) {
        $batch_id = (int) $batch_id;
        if (!$plan || $batch_id <= 0) {
            return;
        }
        $status = [];
        foreach ($plan as $p) {
            $row = CJS_Inv_Demands::row((int) $p['demand_id']);
            if (!$row || $row['state'] !== 'open' || (int) $row['order_id'] <= 0 || !CJS_Inv_Flow::may_auto_assign($row)) {
                continue;
            }
            try {
                $got = CJS_Inv_DB::transaction(function () use ($p, $batch_id) {
                    return CJS_Inv_Stock::allocate_demand((int) $p['demand_id'], ['batch_id' => $batch_id, 'cap' => (float) $p['at_ref']]);
                });
                if ($got <= 0) {
                    continue;
                }
                $oid = (int) $row['order_id'];
                if (!isset($status[$oid])) {
                    $status[$oid] = CJS_Inv_Flow::order_status_key($oid);
                }
                $fresh = CJS_Inv_Demands::row((int) $p['demand_id']);
                if ($fresh) {
                    CJS_Inv_Flow::settle_line($fresh, $status[$oid]);
                }
            } catch (Throwable $e) {
                error_log('CJS serving stock order line failed for line ' . (int) $p['demand_id'] . ': ' . $e->getMessage());
            }
        }
        if ($status) {
            CJS_Inv_Discrepancies::auto_close([], array_keys($status));
        }
    }

    public static function parts_fields(array $row, array $map) {
        $did = (int) ($row['id'] ?? 0);
        if ($did > 0 && isset($map[$did])) {
            return $map[$did];
        }
        $open = (string) ($row['state'] ?? 'open') === 'open';
        $missing = $open && isset($row['qty']) ? max(0.0, round((float) $row['qty'] - (float) ($row['qty_allocated'] ?? 0), 4)) : 0.0;
        return ['parts' => [], 'unordered' => $missing];
    }

    public static function save(array $data) {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $existing = $id ? self::row($id) : null;
        if ($id && !$existing) {
            return new WP_Error('cjs_not_found', __('Stock order not found.', 'custom-jewelry-system'));
        }
        $date = sanitize_text_field($data['order_date'] ?? ($existing['order_date'] ?? current_time('Y-m-d')));
        if (!CJS_Deadlines::is_valid_ymd($date)) {
            $date = $existing && CJS_Deadlines::is_valid_ymd((string) $existing['order_date']) ? $existing['order_date'] : current_time('Y-m-d');
        }
        $number = trim(sanitize_text_field($data['number'] ?? ($existing['number'] ?? '')));
        if ($number === '') {
            $number = $existing && trim((string) $existing['number']) !== '' ? $existing['number'] : self::next_number();
        }
        $header = [
            'number' => mb_substr($number, 0, 100),
            'order_date' => $date,
            'supplier' => array_key_exists('supplier', $data) ? (trim(sanitize_text_field((string) $data['supplier'])) ?: null) : ($existing['supplier'] ?? null),
            'note' => array_key_exists('note', $data) ? (trim(sanitize_textarea_field((string) $data['note'])) ?: null) : ($existing['note'] ?? null),
            'updated_at' => CJS_Inv_DB::now(),
        ];
        $result = CJS_Inv_DB::transaction(function () use ($id, $header, $data) {
            if ($id) {
                CJS_Inv_DB::update('stock_orders', $header, ['id' => $id]);
                $so_id = $id;
            } else {
                $header['status'] = self::default_status_key();
                $header['created_by'] = get_current_user_id();
                $header['created_at'] = CJS_Inv_DB::now();
                $so_id = CJS_Inv_DB::insert('stock_orders', $header);
            }
            if (isset($data['lines']) && is_array($data['lines'])) {
                self::sync_lines($so_id, $data['lines']);
            }
            return $so_id;
        });
        if (!is_wp_error($result)) {
            self::unlink_received([], (int) $result);
            $status = self::refresh_status((int) $result);
            CJS_Logger::log($id ? 'Stock order updated' : 'Stock order created', $id ? 'info' : 'success', 'stock_order', $result, ['number' => $header['number'], 'status' => $status]);
        }
        return $result;
    }

    private static function line_ordered_value(array $line, $old) {
        if (array_key_exists('ordered', $line)) {
            $ordered = !empty($line['ordered']) && $line['ordered'] !== 'false';
        } elseif (array_key_exists('in_cart', $line)) {
            $ordered = !empty($line['in_cart']) && $line['in_cart'] !== 'false';
        } else {
            $ordered = $old ? (int) $old['in_cart'] > 0 : false;
        }
        if ($old && (float) $old['qty_received'] > 0) {
            $ordered = true;
        }
        return $ordered ? 1 : 0;
    }

    private static function sync_lines($so_id, array $lines) {
        global $wpdb;
        $existing = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $so_id), ARRAY_A) ?: [];
        $by_id = [];
        foreach ($existing as $e) {
            $by_id[(int) $e['id']] = $e;
        }
        $keep = [];
        $pos = 1;
        foreach ($lines as $line) {
            $line_id = absint($line['id'] ?? 0);
            $item_id = absint($line['item_id'] ?? 0);
            $qty = CJS_Inv_DB::num($line['qty'] ?? 0);
            $item = CJS_Inv_Catalog::item_row($item_id);
            if (!$item) {
                throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('A line has no valid item.', 'custom-jewelry-system')));
            }
            if (!$qty || $qty <= 0) {
                throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', sprintf(__('Quantity for %s must be greater than zero.', 'custom-jewelry-system'), $item['name'])));
            }
            $old = $line_id && isset($by_id[$line_id]) ? $by_id[$line_id] : null;
            if (!$old || (int) $old['item_id'] !== $item_id) {
                $unit_error = CJS_Inv_Metals::unit_error($item);
                if ($unit_error) {
                    throw new CJS_Inv_Exception($unit_error);
                }
            }
            $row = [
                'item_id' => $item_id,
                'qty' => $qty,
                'unit_cost' => array_key_exists('unit_cost', $line) ? CJS_Inv_DB::num($line['unit_cost']) : ($old ? $old['unit_cost'] : null),
                'in_cart' => self::line_ordered_value($line, $old),
                'note' => array_key_exists('note', $line) ? (trim((string) $line['note']) !== '' ? mb_substr(sanitize_text_field($line['note']), 0, 255) : null) : ($old ? $old['note'] : null),
                'sort_order' => $pos++,
                'updated_at' => CJS_Inv_DB::now(),
            ];
            if ($old) {
                if ((float) $old['qty_received'] > 0) {
                    if ((int) $old['item_id'] !== $item_id) {
                        throw new CJS_Inv_Exception(new WP_Error('cjs_locked', __('The item of a line that already received stock cannot change.', 'custom-jewelry-system')));
                    }
                    if ($qty < (float) $old['qty_received']) {
                        throw new CJS_Inv_Exception(new WP_Error('cjs_locked', __('A line cannot be smaller than what was already received.', 'custom-jewelry-system')));
                    }
                }
                if ((int) $old['item_id'] !== $item_id) {
                    $linked = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('demands') . ' WHERE po_line_id = %d UNION SELECT demand_id FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE po_line_id = %d', $line_id, $line_id)) ?: [];
                    foreach ($linked as $did) {
                        $dmd = CJS_Inv_Demands::row((int) $did);
                        if (!$dmd || CJS_Inv_Metals::same_stock($dmd, $item_id)) {
                            continue;
                        }
                        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE demand_id = %d AND po_line_id = %d', (int) $did, $line_id));
                        if ((int) $dmd['po_line_id'] === $line_id) {
                            CJS_Inv_DB::update('demands', ['po_line_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $did]);
                        }
                    }
                }
                CJS_Inv_DB::update('stock_order_lines', $row, ['id' => $line_id]);
                $keep[] = $line_id;
                $new_line_id = $line_id;
            } else {
                $row['stock_order_id'] = $so_id;
                $row['created_at'] = CJS_Inv_DB::now();
                $new_line_id = CJS_Inv_DB::insert('stock_order_lines', $row);
                $keep[] = $new_line_id;
                CJS_Logger::log('Stock order line added', 'info', 'stock_order', (int) $so_id, ['line_id' => (int) $new_line_id, 'item_id' => $item_id, 'item' => $item['name'], 'qty' => $qty]);
            }
            if (isset($line['demand_ids']) && is_array($line['demand_ids'])) {
                foreach ($line['demand_ids'] as $demand_id) {
                    $dmd = CJS_Inv_Demands::row(absint($demand_id));
                    if ($dmd && CJS_Inv_Metals::same_stock($dmd, $item_id) && $dmd['state'] === 'open') {
                        CJS_Inv_DB::update('demands', ['po_line_id' => $new_line_id, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $dmd['id']]);
                        self::mark_hand_link((int) $dmd['id'], $new_line_id);
                    }
                }
            }
        }
        foreach ($by_id as $line_id => $old) {
            if (in_array($line_id, $keep, true)) {
                continue;
            }
            if ((float) $old['qty_received'] > 0) {
                throw new CJS_Inv_Exception(new WP_Error('cjs_locked', __('A line that already received stock cannot be removed.', 'custom-jewelry-system')));
            }
            self::mark_lines_removed([$line_id]);
            self::drop_line_links([$line_id]);
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET po_line_id = NULL WHERE po_line_id = %d', $line_id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE id = %d', $line_id));
            CJS_Logger::log('Stock order line removed', 'warning', 'stock_order', (int) $so_id, ['line_id' => (int) $line_id, 'item_id' => (int) $old['item_id'], 'qty' => (float) $old['qty']]);
        }
        return true;
    }

    public static function fully_received($stock_order_id) {
        global $wpdb;
        $l = CJS_Inv_DB::t('stock_order_lines');
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN qty_received < qty THEN 1 ELSE 0 END) AS open_n FROM $l WHERE stock_order_id = %d", (int) $stock_order_id), ARRAY_A);
        return $row && (int) $row['n'] > 0 && (int) $row['open_n'] === 0;
    }

    public static function add_line($stock_order_id, array $data) {
        global $wpdb;
        $so = self::row($stock_order_id);
        if (!$so) {
            return new WP_Error('cjs_not_found', __('Stock order not found.', 'custom-jewelry-system'));
        }
        if (self::fully_received((int) $stock_order_id)) {
            return new WP_Error('cjs_invalid', __('This stock order is fully received and closed. Start a new stock order.', 'custom-jewelry-system'));
        }
        $item = CJS_Inv_Catalog::item_row(absint($data['item_id'] ?? 0));
        if (!$item) {
            return new WP_Error('cjs_invalid', __('Choose an item first.', 'custom-jewelry-system'));
        }
        $unit_error = CJS_Inv_Metals::unit_error($item);
        if ($unit_error) {
            return $unit_error;
        }
        $qty = array_key_exists('qty', $data) && $data['qty'] !== '' ? CJS_Inv_DB::num($data['qty']) : 1.0;
        if (!$qty || $qty <= 0) {
            return new WP_Error('cjs_invalid', sprintf(__('Quantity for %s must be greater than zero.', 'custom-jewelry-system'), $item['name']));
        }
        $cost = array_key_exists('unit_cost', $data) ? CJS_Inv_DB::num($data['unit_cost']) : ($item['default_cost'] !== null ? (float) $item['default_cost'] : null);
        $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', (int) $stock_order_id));
        $line_id = CJS_Inv_DB::insert('stock_order_lines', [
            'stock_order_id' => (int) $stock_order_id,
            'item_id' => (int) $item['id'],
            'qty' => $qty,
            'unit_cost' => $cost,
            'in_cart' => !empty($data['ordered']) ? 1 : 0,
            'sort_order' => $sort,
            'created_at' => CJS_Inv_DB::now(),
            'updated_at' => CJS_Inv_DB::now(),
        ]);
        if (!$line_id) {
            return new WP_Error('cjs_error', __('Could not add the line.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::update('stock_orders', ['updated_at' => CJS_Inv_DB::now()], ['id' => (int) $stock_order_id]);
        self::refresh_status((int) $stock_order_id);
        CJS_Logger::log('Stock order line added', 'info', 'stock_order', (int) $stock_order_id, ['line_id' => (int) $line_id, 'item_id' => (int) $item['id'], 'qty' => $qty]);
        return (int) $line_id;
    }

    public static function update_line($line_id, array $data) {
        $line = self::line($line_id);
        if (!$line) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'));
        }
        $row = [];
        if (array_key_exists('qty', $data)) {
            $qty = CJS_Inv_DB::num($data['qty']);
            if (!$qty || $qty <= 0) {
                return new WP_Error('cjs_invalid', __('Quantity must be greater than zero.', 'custom-jewelry-system'));
            }
            if ($qty < (float) $line['qty_received']) {
                return new WP_Error('cjs_locked', sprintf(__('%s already arrived, the line cannot be smaller.', 'custom-jewelry-system'), CJS_Inv_DB::fmt((float) $line['qty_received'])));
            }
            $row['qty'] = $qty;
        }
        if (array_key_exists('unit_cost', $data)) {
            $row['unit_cost'] = CJS_Inv_DB::num($data['unit_cost']);
        }
        if (array_key_exists('ordered', $data) || array_key_exists('in_cart', $data)) {
            $row['in_cart'] = self::line_ordered_value($data, $line);
        }
        if (array_key_exists('note', $data)) {
            $note = trim(sanitize_text_field((string) $data['note']));
            $row['note'] = $note !== '' ? mb_substr($note, 0, 255) : null;
        }
        if (array_key_exists('planned_identifiers', $data) && CJS_Inv_DB::has_column('stock_order_lines', 'planned_identifiers')) {
            $planned = self::clean_planned($data['planned_identifiers']);
            $row['planned_identifiers'] = $planned ? wp_json_encode($planned) : null;
        }
        if (!$row) {
            return (int) $line['stock_order_id'];
        }
        $row['updated_at'] = CJS_Inv_DB::now();
        CJS_Inv_DB::update('stock_order_lines', $row, ['id' => (int) $line_id]);
        CJS_Inv_DB::update('stock_orders', ['updated_at' => CJS_Inv_DB::now()], ['id' => (int) $line['stock_order_id']]);
        if (isset($row['qty'])) {
            self::unlink_received([(int) $line_id]);
        }
        self::refresh_status((int) $line['stock_order_id']);
        return (int) $line['stock_order_id'];
    }

    public static function delete_line($line_id) {
        global $wpdb;
        $line = self::line($line_id);
        if (!$line) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'));
        }
        if ((float) $line['qty_received'] > 0) {
            return new WP_Error('cjs_locked', __('A line that already received stock cannot be removed.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($wpdb, $line_id) {
            self::mark_lines_removed([(int) $line_id]);
            self::drop_line_links([(int) $line_id]);
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET po_line_id = NULL, updated_at = %s WHERE po_line_id = %d', CJS_Inv_DB::now(), (int) $line_id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE id = %d', (int) $line_id));
        });
        self::refresh_status((int) $line['stock_order_id']);
        CJS_Logger::log('Stock order line removed', 'warning', 'stock_order', (int) $line['stock_order_id'], ['line_id' => (int) $line_id, 'item_id' => (int) $line['item_id']]);
        return (int) $line['stock_order_id'];
    }

    public static function bulk($stock_order_id, $action, array $line_ids) {
        global $wpdb;
        if (!self::row($stock_order_id)) {
            return new WP_Error('cjs_not_found', __('Stock order not found.', 'custom-jewelry-system'));
        }
        $line_ids = array_values(array_unique(array_filter(array_map('absint', $line_ids))));
        if (!$line_ids) {
            return new WP_Error('cjs_invalid', __('Select at least one line.', 'custom-jewelry-system'));
        }
        $lines = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d AND id IN (' . CJS_Inv_DB::ids_in($line_ids) . ') ORDER BY sort_order, id', (int) $stock_order_id), ARRAY_A) ?: [];
        $done = 0;
        $skipped = 0;
        $document_id = 0;
        foreach ($lines as $l) {
            if ($action === 'ordered' || $action === 'not_ordered') {
                if ((float) $l['qty_received'] > 0 || (bool) ((int) $l['in_cart']) === ($action === 'ordered')) {
                    $skipped++;
                    continue;
                }
                CJS_Inv_DB::update('stock_order_lines', ['in_cart' => $action === 'ordered' ? 1 : 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $l['id']]);
                $done++;
            } elseif ($action === 'receive') {
                if ((float) $l['qty_received'] >= (float) $l['qty']) {
                    $skipped++;
                    continue;
                }
                $document_id = $document_id ?: self::receipt_document((int) $stock_order_id);
                $r = self::receive_line((int) $l['id'], null, null, [], $document_id);
                if (is_wp_error($r)) {
                    self::drop_empty_document($document_id);
                    return $r;
                }
                $done++;
            } elseif ($action === 'delete') {
                if ((float) $l['qty_received'] > 0) {
                    $skipped++;
                    continue;
                }
                $r = self::delete_line((int) $l['id']);
                if (is_wp_error($r)) {
                    return $r;
                }
                $done++;
            } else {
                return new WP_Error('cjs_invalid', __('Unknown action.', 'custom-jewelry-system'));
            }
        }
        self::refresh_status((int) $stock_order_id);
        if ($done && ($action === 'ordered' || $action === 'not_ordered')) {
            CJS_Logger::log($action === 'ordered' ? 'Stock order lines marked ordered' : 'Stock order lines marked not ordered', 'info', 'stock_order', (int) $stock_order_id, ['count' => $done]);
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    public static function unlink_received(array $line_ids = [], $stock_order_id = 0) {
        global $wpdb;
        if (!$line_ids && !$stock_order_id) {
            self::$model_checked = false;
            self::ensure_model();
        }
        $where = "d.state = 'open' AND d.qty_allocated < d.qty AND l.qty_received >= l.qty";
        if ($line_ids) {
            $where .= ' AND l.id IN (' . CJS_Inv_DB::ids_in($line_ids) . ')';
        }
        if ($stock_order_id) {
            $where .= $wpdb->prepare(' AND l.stock_order_id = %d', $stock_order_id);
        }
        $rows = $wpdb->get_results('SELECT d.id, d.item_id, d.category_id, d.metal, d.order_id, d.qty, d.qty_allocated, d.unit_id, s.number, l.stock_order_id FROM ' . CJS_Inv_DB::t('demands') . ' d
            INNER JOIN ' . CJS_Inv_DB::t('stock_order_lines') . ' l ON l.id = d.po_line_id
            INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . " s ON s.id = l.stock_order_id WHERE $where", ARRAY_A) ?: [];
        $k = CJS_Inv_DB::t('demand_links');
        $unlinked = 0;
        foreach ($rows as $r) {
            $linked = (int) $wpdb->get_var($wpdb->prepare("SELECT k.po_line_id FROM $k k INNER JOIN " . CJS_Inv_DB::t('stock_order_lines') . ' l ON l.id = k.po_line_id WHERE k.demand_id = %d AND l.qty_received < l.qty ORDER BY k.po_line_id LIMIT 1', (int) $r['id']));
            if ($linked) {
                CJS_Inv_DB::update('demands', ['po_line_id' => $linked, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $r['id']]);
                CJS_Inv_DB::query($wpdb->prepare("DELETE FROM $k WHERE demand_id = %d AND po_line_id = %d", (int) $r['id'], $linked));
                continue;
            }
            $metal = CJS_Inv_Metals::of_line($r);
            $same = $metal ? CJS_Inv_Metals::item_ids($metal['category_id'], $metal['metal'], (int) $r['unit_id']) :(CJS_Inv_Flow::line_rules($r)['has_fineness'] ? [(int) $r['item_id']] : []);
            $next = $same ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d AND item_id IN (' . CJS_Inv_DB::ids_in($same) . ') AND qty_received < qty ORDER BY sort_order, id LIMIT 1', (int) $r['stock_order_id'])) : 0;
            if ($next) {
                CJS_Inv_DB::update('demands', ['po_line_id' => $next, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $r['id']]);
                continue;
            }
            $unlinked++;
            CJS_Inv_DB::update('demands', ['po_line_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $r['id']]);
            CJS_Inv_DB::query($wpdb->prepare("DELETE FROM $k WHERE demand_id = %d", (int) $r['id']));
            CJS_Inv_Stock::log('demand', (int) $r['item_id'], 0, [
                'order_id' => (int) $r['order_id'],
                'demand_id' => (int) $r['id'],
                'note' => sprintf(__('Stock order #%1$s is fully received but this line still misses %2$s %3$s, so it is back in Required', 'custom-jewelry-system'), $r['number'], CJS_Inv_DB::fmt((float) $r['qty'] - (float) $r['qty_allocated']), CJS_Inv_Catalog::unit_label($r['unit_id'])),
            ]);
        }
        self::$order_state_cache = [];
        return $unlinked;
    }

    private static function orderable_rows(array $demand_ids) {
        global $wpdb;
        $demand_ids = array_values(array_unique(array_filter(array_map('absint', $demand_ids))));
        if (!$demand_ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('demands') . ' WHERE id IN (' . CJS_Inv_DB::ids_in($demand_ids) . ") AND state = 'open' AND qty_allocated < qty ORDER BY item_id, id", ARRAY_A) ?: [];
        $parts = self::ordered_parts($rows);
        $out = [];
        foreach ($rows as $r) {
            $rules = CJS_Inv_Flow::line_rules($r);
            if (!$rules['exists']) {
                continue;
            }
            $missing = (float) self::parts_fields($r, $parts)['unordered'];
            if ($missing <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $out[] = $r + ['orderable' => $rules['has_fineness'] ? CJS_Inv_Flow::ceil3($missing) : $missing];
        }
        return $out;
    }

    public static function create_from_demands(array $demand_ids, array $header = []) {
        $demand_ids = array_values(array_unique(array_filter(array_map('absint', $demand_ids))));
        if (!$demand_ids) {
            return new WP_Error('cjs_invalid', __('Select at least one required line.', 'custom-jewelry-system'));
        }
        $rows = [];
        $no_item = [];
        $unit_notes = [];
        $notes = [];
        $skipped = [];
        $orderable = self::orderable_rows($demand_ids);
        $seen = array_map(function ($r) { return (int) $r['id']; }, $orderable);
        foreach (array_diff($demand_ids, $seen) as $gone) {
            $skipped[] = self::skipped_entry(CJS_Inv_Demands::row((int) $gone), (int) $gone, __('Nothing left to order: the line is covered, already on a stock order or closed.', 'custom-jewelry-system'));
        }
        foreach ($orderable as $r) {
            $metal = CJS_Inv_Metals::of_line($r);
            if ($metal) {
                $best = CJS_Inv_Metals::best($metal['category_id'], $metal['metal'], (int) ($r['unit_id'] ?? 0));
                if (!$best) {
                    $no_item[] = $metal['metal'];
                    $nk = $metal['category_id'] . '|' . CJS_Inv_Metals::key($metal['metal']) . '|' . (int) ($r['unit_id'] ?? 0);
                    if (!array_key_exists($nk, $notes)) {
                        $notes[$nk] = CJS_Inv_Metals::unit_note($metal['category_id'], $metal['metal'], (int) ($r['unit_id'] ?? 0));
                    }
                    $note = $notes[$nk];
                    if ($note !== null) {
                        $unit_notes[] = $note;
                    }
                    $skipped[] = self::skipped_entry($r, (int) $r['id'], $note ?? sprintf(__('No item of %s exists yet. Create one first.', 'custom-jewelry-system'), $metal['metal']));
                    continue;
                }
                $rows[] = ['demand_id' => (int) $r['id'], 'item_id' => (int) $best['id'], 'qty' => CJS_Inv_Flow::physical_for($r['orderable'], CJS_Inv_Metals::conv_praba($best))];
                continue;
            }
            $rows[] = ['demand_id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'qty' => $r['orderable']];
        }
        self::$last_skipped = [];
        if (!$rows) {
            if ($unit_notes) {
                return new WP_Error('cjs_invalid', implode(' ', array_values(array_unique($unit_notes))), ['skipped' => $skipped]);
            }
            if ($no_item) {
                return new WP_Error('cjs_invalid', sprintf(__('No item of %s exists yet. Create one first.', 'custom-jewelry-system'), implode(', ', array_unique($no_item))), ['skipped' => $skipped]);
            }
            return new WP_Error('cjs_invalid', __('None of the selected lines are missing stock anymore.', 'custom-jewelry-system'), ['skipped' => $skipped]);
        }
        $result = self::create_from_rows($rows, $header);
        if (!is_wp_error($result)) {
            self::$last_skipped = array_merge($skipped, self::$last_skipped);
        }
        return $result;
    }

    private static $last_skipped = [];

    public static function take_skipped() {
        $out = self::$last_skipped;
        self::$last_skipped = [];
        return $out;
    }

    private static function skipped_entry($demand, $demand_id, $reason) {
        $order_id = is_array($demand) ? (int) $demand['order_id'] : 0;
        $label = $order_id > 0 ? (CJS_Orders::order_labels([$order_id])[$order_id] ?? null) : null;
        $name = '';
        if (is_array($demand)) {
            $metal = CJS_Inv_Metals::of_line($demand);
            $item = $metal ? null : CJS_Inv_Catalog::item_row((int) $demand['item_id']);
            $name = $metal ? $metal['metal'] : ($item ? (string) $item['name'] : '');
        }
        return [
            'demand_id' => (int) $demand_id,
            'order_id' => $order_id,
            'order_number' => $label ? (string) $label['number'] : ($order_id > 0 ? (string) $order_id : ''),
            'name' => $name,
            'reason' => (string) $reason,
        ];
    }

    public static function create_from_rows(array $rows, array $header = []) {
        global $wpdb;
        self::$last_skipped = [];
        if (!$rows) {
            return new WP_Error('cjs_invalid', __('Add at least one item.', 'custom-jewelry-system'));
        }
        $groups = [];
        $skipped = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $demand = !empty($row['demand_id']) ? CJS_Inv_Demands::row(absint($row['demand_id'])) : null;
            $item_id = absint($row['item_id'] ?? 0) ?: ($demand ? (int) $demand['item_id'] : 0);
            $item = $item_id ? CJS_Inv_Catalog::item_row($item_id) : null;
            if (!$item) {
                return new WP_Error('cjs_invalid', __('A line has no valid item.', 'custom-jewelry-system'));
            }
            if ((int) $item['archived'] === 1) {
                return new WP_Error('cjs_invalid', sprintf(__('%s is archived. Restore it first or choose another item.', 'custom-jewelry-system'), $item['name']));
            }
            $unit_error = CJS_Inv_Metals::unit_error($item);
            if ($unit_error) {
                return $unit_error;
            }
            $qty = CJS_Inv_DB::num($row['qty'] ?? null);
            if ($qty === null || $qty <= 0) {
                return new WP_Error('cjs_invalid', sprintf(__('Quantity for %s must be greater than zero.', 'custom-jewelry-system'), $item['name']));
            }
            $link = 0;
            $open = $demand && $demand['state'] === 'open' && (float) $demand['qty_allocated'] < (float) $demand['qty'];
            if ($open && CJS_Inv_Metals::same_stock($demand, (int) $item['id'])) {
                $link = (int) $demand['id'];
            } elseif ($demand) {
                $skipped[] = self::skipped_entry($demand, (int) $demand['id'], $open
                    ? sprintf(__('%s is not what this line needs, so it was added for stock.', 'custom-jewelry-system'), $item['name'])
                    : __('This line no longer misses stock, so it was added for stock.', 'custom-jewelry-system'));
            }
            $key = (int) $item['id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['item' => $item, 'qty' => 0.0, 'demand_ids' => []];
            }
            $groups[$key]['qty'] = round($groups[$key]['qty'] + $qty, 4);
            if ($link && !in_array($link, $groups[$key]['demand_ids'], true)) {
                $groups[$key]['demand_ids'][] = $link;
            }
        }
        $target = absint($header['stock_order_id'] ?? 0);
        if ($target && !self::row($target)) {
            return new WP_Error('cjs_not_found', __('Stock order not found.', 'custom-jewelry-system'));
        }
        if ($target && self::fully_received($target)) {
            return new WP_Error('cjs_invalid', __('This stock order is fully received and closed. Start a new stock order.', 'custom-jewelry-system'));
        }
        $ordered = !empty($header['ordered']);
        unset($header['stock_order_id'], $header['status'], $header['ordered'], $header['lines'], $header['id']);
        try {
            $so_id = CJS_Inv_DB::transaction(function () use ($wpdb, $groups, $target, $header, $ordered) {
                $so_id = $target;
                if (!$so_id) {
                    $so_id = self::save($header);
                    if (is_wp_error($so_id)) {
                        throw new CJS_Inv_Exception($so_id);
                    }
                }
                $existing = $target ? ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d AND qty_received < qty ORDER BY sort_order, id', (int) $so_id), ARRAY_A) ?: []) : [];
                foreach ($groups as $g) {
                    $line_id = 0;
                    foreach ($existing as $e) {
                        if ((int) $e['item_id'] === (int) $g['item']['id']) {
                            $line_id = (int) $e['id'];
                            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET qty = qty + %f, updated_at = %s WHERE id = %d', $g['qty'], CJS_Inv_DB::now(), $line_id));
                            break;
                        }
                    }
                    if (!$line_id) {
                        $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', (int) $so_id));
                        $line_id = (int) CJS_Inv_DB::insert('stock_order_lines', [
                            'stock_order_id' => (int) $so_id,
                            'item_id' => (int) $g['item']['id'],
                            'qty' => $g['qty'],
                            'unit_cost' => null,
                            'in_cart' => $ordered ? 1 : 0,
                            'sort_order' => $sort,
                            'created_at' => CJS_Inv_DB::now(),
                            'updated_at' => CJS_Inv_DB::now(),
                        ]);
                        if (!$line_id) {
                            throw new CJS_Inv_Exception(new WP_Error('cjs_error', __('Could not add the line.', 'custom-jewelry-system')));
                        }
                        CJS_Logger::log('Stock order line added', 'info', 'stock_order', (int) $so_id, ['line_id' => $line_id, 'item_id' => (int) $g['item']['id'], 'item' => $g['item']['name'], 'qty' => $g['qty']]);
                    }
                    foreach ($g['demand_ids'] as $did) {
                        self::link_demand($did, $line_id);
                        self::mark_hand_link($did, $line_id);
                    }
                }
                CJS_Inv_DB::update('stock_orders', ['updated_at' => CJS_Inv_DB::now()], ['id' => (int) $so_id]);
                return (int) $so_id;
            });
        } catch (CJS_Inv_Exception $e) {
            return $e->error;
        }
        self::refresh_status((int) $so_id);
        self::$last_skipped = $skipped;
        return (int) $so_id;
    }

    public static function set_in_cart($line_id, $in_cart) {
        return self::set_ordered($line_id, $in_cart);
    }

    public static function missing_demand_ids_for_order($order_id) {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND state = 'open' AND qty_allocated < qty ORDER BY id", absint($order_id))) ?: []);
        $out = array_map(function ($r) { return (int) $r['id']; }, self::orderable_rows($ids));
        sort($out);
        return $out;
    }

    public static function create_from_order($order_id, array $header = []) {
        $ids = self::missing_demand_ids_for_order($order_id);
        if (!$ids) {
            return new WP_Error('cjs_invalid', __('Nothing on this order is missing or everything missing is already ordered.', 'custom-jewelry-system'));
        }
        return self::create_from_demands($ids, $header);
    }

    public static function set_ordered($line_id, $ordered) {
        $line = self::line($line_id);
        if (!$line) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'));
        }
        if (!$ordered && (float) $line['qty_received'] > 0) {
            return new WP_Error('cjs_locked', __('Part of this line already arrived, so it stays ordered.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::update('stock_order_lines', ['in_cart' => $ordered ? 1 : 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $line_id]);
        self::refresh_status((int) $line['stock_order_id']);
        if ((bool) ((int) $line['in_cart']) !== (bool) $ordered) {
            CJS_Logger::log($ordered ? 'Stock order line marked ordered' : 'Stock order line marked not ordered', 'info', 'stock_order', (int) $line['stock_order_id'], ['line_id' => (int) $line_id, 'item_id' => (int) $line['item_id']]);
        }
        return true;
    }

    public static function receipt_document($stock_order_id) {
        $so = self::row((int) $stock_order_id);
        return CJS_Inv_Stock::create_document('receipt', sprintf(__('Received from stock order #%s', 'custom-jewelry-system'), $so ? $so['number'] : $stock_order_id));
    }

    private static function drop_empty_document($document_id) {
        global $wpdb;
        $document_id = (int) $document_id;
        if (!$document_id) {
            return;
        }
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('movements') . ' WHERE document_id = %d', $document_id));
        if (!$used) {
            $wpdb->delete(CJS_Inv_DB::t('documents'), ['id' => $document_id]);
        }
    }

    public static function planned_of(array $line) {
        if (!array_key_exists('planned_identifiers', $line) || $line['planned_identifiers'] === null || $line['planned_identifiers'] === '') {
            return [];
        }
        $list = json_decode((string) $line['planned_identifiers'], true);
        return is_array($list) ? array_values(array_filter(array_map(function ($v) { return is_scalar($v) ? trim((string) $v) : ''; }, $list), 'strlen')) : [];
    }

    public static function clean_planned($value) {
        $list = is_array($value) ? $value : (is_string($value) && $value !== '' ? (json_decode($value, true) ?: []) : []);
        $out = [];
        foreach ($list as $v) {
            $v = is_scalar($v) ? mb_substr(trim(sanitize_text_field((string) $v)), 0, 100) : '';
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return array_slice($out, 0, 500);
    }

    public static function receive_line($line_id, $qty = null, $unit_cost = null, array $identifiers = [], $document_id = null) {
        global $wpdb;
        $found = self::line($line_id);
        if (!$found) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'));
        }
        $unit_error = CJS_Inv_Metals::unit_error(CJS_Inv_Catalog::item_row((int) $found['item_id']));
        if ($unit_error) {
            return $unit_error;
        }
        $plan = [];
        try {
            $plan = self::line_queue((int) $line_id);
        } catch (Throwable $e) {
            error_log('CJS stock order line queue failed for line ' . (int) $line_id . ': ' . $e->getMessage());
        }
        try {
            [$result, $line, $qty] = CJS_Inv_DB::transaction(function () use ($wpdb, $line_id, $qty, $unit_cost, $identifiers, $document_id) {
                $line = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE id = %d FOR UPDATE', $line_id), ARRAY_A);
                if (!$line) {
                    throw new CJS_Inv_Exception(new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system')));
                }
                $remaining = round((float) $line['qty'] - (float) $line['qty_received'], 4);
                $qty = $qty === null || $qty === '' ? $remaining : CJS_Inv_DB::num($qty);
                if (!$qty || $qty <= 0) {
                    throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Nothing left to receive on this line.', 'custom-jewelry-system')));
                }
                $cost = $unit_cost === null || $unit_cost === '' ? ($line['unit_cost'] !== null ? (float) $line['unit_cost'] : null) : CJS_Inv_DB::num($unit_cost);
                $planned = self::planned_of($line);
                if (!$identifiers && $planned) {
                    $identifiers = array_slice($planned, 0, max(1, (int) ceil($qty)));
                }
                if ($planned) {
                    $left = array_values(array_diff($planned, array_map('strval', $identifiers)));
                    CJS_Inv_DB::update('stock_order_lines', ['planned_identifiers' => $left ? wp_json_encode($left) : null], ['id' => (int) $line['id']]);
                }
                $so = self::row((int) $line['stock_order_id']);
                $result = CJS_Inv_Stock::receive([[
                    'item_id' => (int) $line['item_id'],
                    'qty' => $qty,
                    'unit_cost' => $cost,
                    'identifiers' => $identifiers,
                    'source_type' => 'stock_order',
                    'source_id' => (int) $line['id'],
                    'stock_order_id' => (int) $line['stock_order_id'],
                ]], [
                    'note' => sprintf(__('Received from stock order #%s', 'custom-jewelry-system'), $so ? $so['number'] : $line['stock_order_id']),
                    'document_id' => $document_id ? (int) $document_id : null,
                    'stock_order_id' => (int) $line['stock_order_id'],
                    'source_type' => 'stock_order',
                    'skip_reserve' => true,
                ]);
                if ($cost === null) {
                    CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET qty_received = qty_received + %f, qty = GREATEST(qty, qty_received), in_cart = 1, updated_at = %s WHERE id = %d', $qty, CJS_Inv_DB::now(), (int) $line['id']));
                } else {
                    CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET qty_received = qty_received + %f, qty = GREATEST(qty, qty_received), unit_cost = COALESCE(unit_cost, %f), in_cart = 1, updated_at = %s WHERE id = %d', $qty, $cost, CJS_Inv_DB::now(), (int) $line['id']));
                }
                return [$result, $line, $qty];
            });
        } catch (CJS_Inv_Exception $e) {
            return $e->error;
        }
        self::serve_line_queue($plan, (int) ($result['batch_ids'][0] ?? 0));
        CJS_Inv_Demands::reserve_pending([(int) $line['item_id']]);
        self::unlink_received([(int) $line['id']]);
        self::refresh_status((int) $line['stock_order_id']);
        CJS_Logger::log('Stock order line received', 'success', 'stock_order', (int) $line['stock_order_id'], ['line_id' => (int) $line['id'], 'qty' => $qty]);
        return $result;
    }

    public static function receive_all($stock_order_id) {
        global $wpdb;
        $lines = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d AND qty_received < qty ORDER BY sort_order, id', $stock_order_id), ARRAY_A) ?: [];
        $done = 0;
        $document_id = 0;
        foreach ($lines as $l) {
            $document_id = $document_id ?: self::receipt_document((int) $stock_order_id);
            $r = self::receive_line((int) $l['id'], null, null, [], $document_id);
            if (is_wp_error($r)) {
                self::drop_empty_document($document_id);
                return $r;
            }
            $done++;
        }
        self::refresh_status((int) $stock_order_id);
        return $done;
    }

    public static function maybe_mark_received($stock_order_id) {
        return self::refresh_status($stock_order_id);
    }

    public static function delete($id) {
        global $wpdb;
        $received = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(qty_received), 0) FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $id));
        if ($received > 0) {
            return new WP_Error('cjs_locked', __('This stock order already received stock, so it cannot be deleted.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($wpdb, $id) {
            self::drop_line_links($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $id)) ?: []);
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET po_line_id = NULL WHERE po_line_id IN (SELECT id FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d)', $id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE stock_order_id = %d', $id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('stock_orders') . ' WHERE id = %d', $id));
        });
        unset(self::$order_state_cache[(int) $id]);
        CJS_Logger::log('Stock order deleted', 'warning', 'stock_order', (int) $id);
        return true;
    }

    public static function unlink_demand($demand_id, $line_id = 0) {
        $demand = CJS_Inv_Demands::row($demand_id);
        if (!$demand) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'));
        }
        global $wpdb;
        $line_id = absint($line_id);
        $linked = $demand['po_line_id'] ? [(int) $demand['po_line_id']] : [];
        foreach (self::extra_links([(int) $demand_id])[(int) $demand_id] ?? [] as $lid) {
            $linked[] = (int) $lid;
        }
        if ($line_id) {
            if (!in_array($line_id, $linked, true)) {
                return new WP_Error('cjs_invalid', __('This line is not on that stock order line.', 'custom-jewelry-system'));
            }
            $linked = [$line_id];
        }
        $numbers = [];
        if ($linked) {
            $rows = $wpdb->get_results('SELECT DISTINCT s.id, s.number FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id WHERE l.id IN (' . CJS_Inv_DB::ids_in($linked) . ') ORDER BY s.id', ARRAY_A) ?: [];
            foreach ($rows as $r) {
                $numbers[(int) $r['id']] = (string) $r['number'];
            }
        }
        if ($line_id) {
            if ((int) $demand['po_line_id'] === $line_id) {
                CJS_Inv_DB::update('demands', ['po_line_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand_id]);
            }
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE demand_id = %d AND po_line_id = %d', (int) $demand_id, $line_id));
        } else {
            CJS_Inv_DB::update('demands', ['po_line_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand_id]);
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('demand_links') . ' WHERE demand_id = %d', (int) $demand_id));
        }
        foreach ($numbers as $so_id => $number) {
            self::link_log($demand, (int) $so_id, 'removed', sprintf(__('Removed from stock order #%s by hand', 'custom-jewelry-system'), $number));
        }
        self::$order_state_cache = [];
        return true;
    }

    public static function whatsapp_message($id) {
        global $wpdb;
        $so = self::row($id);
        if (!$so) {
            return '';
        }
        $lines = $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, i.name, i.category_id, i.unit_id, i.praba FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = l.item_id WHERE l.stock_order_id = %d ORDER BY l.sort_order, l.id',
            $id
        ), ARRAY_A) ?: [];
        if (!$lines) {
            return '';
        }
        $msg = 'Order dated ' . $so['order_date'] . "\n";
        $msg .= 'Required items:' . "\n\n";
        $in = CJS_Inv_DB::ids_in(array_map(function ($ln) { return (int) $ln['id']; }, $lines));
        $order_notes = [];
        foreach ($wpdb->get_results('SELECT x.line_id, d.note FROM (SELECT id AS demand_id, po_line_id AS line_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE po_line_id IN ($in) UNION SELECT demand_id, po_line_id FROM " . CJS_Inv_DB::t('demand_links') . " WHERE po_line_id IN ($in)) x INNER JOIN " . CJS_Inv_DB::t('demands') . " d ON d.id = x.demand_id WHERE d.state <> 'cancelled' AND d.note IS NOT NULL AND d.note <> '' ORDER BY d.id", ARRAY_A) ?: [] as $n) {
            $order_notes[(int) $n['line_id']][] = $n['note'];
        }
        $received = [];
        foreach ($wpdb->get_results('SELECT b.source_id AS line_id, p.identifier FROM ' . CJS_Inv_DB::t('pieces') . ' p INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = p.batch_id WHERE b.source_type = 'stock_order' AND b.source_id IN ($in) AND p.identifier IS NOT NULL AND p.identifier <> '' ORDER BY p.id", ARRAY_A) ?: [] as $p) {
            $received[(int) $p['line_id']][] = $p['identifier'];
        }
        $groups = [];
        foreach ($lines as $ln) {
            $lid = (int) $ln['id'];
            $key = (int) $ln['item_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['line' => $ln, 'qty' => 0, 'notes' => [], 'identifiers' => [], 'fineness' => self::line_fineness($ln)];
            }
            $groups[$key]['qty'] += (float) $ln['qty'];
            foreach ($order_notes[$lid] ?? [] as $n) {
                $groups[$key]['notes'][] = $n;
            }
            if (!empty($ln['note'])) {
                $groups[$key]['notes'][] = $ln['note'];
            }
            foreach (array_merge($received[$lid] ?? [], self::planned_of($ln)) as $ident) {
                $groups[$key]['identifiers'][] = $ident;
            }
        }
        foreach ($groups as $g) {
            $ln = $g['line'];
            $qty = $g['qty'];
            $unit = CJS_Inv_Catalog::unit_label($ln['unit_id']);
            if ($unit === 'pcs' || $unit === '') {
                $qty_text = CJS_Inv_DB::fmt($qty) . ' ' . ($qty == 1 ? 'pc' : 'pcs');
            } else {
                $qty_text = CJS_Inv_DB::fmt($qty) . ' ' . $unit;
            }
            $msg .= $g['fineness'] !== null ? '- ' . $ln['name'] . ': ' . $qty_text . "\n" : '- ' . str_replace(' · ', ', ', (string) $ln['name']) . ' - ' . $qty_text . "\n";
            $identifiers = array_values(array_unique(array_filter($g['identifiers'], 'strlen')));
            if ($identifiers) {
                $msg .= '  Identifiers: ' . implode(', ', $identifiers) . "\n";
            }
            $notes = array_values(array_unique(array_filter(array_map('trim', $g['notes']), 'strlen')));
            if ($notes) {
                $msg .= '  Note: ' . implode('; ', $notes) . "\n";
            }
            $msg .= "\n";
        }
        return trim($msg);
    }
}
