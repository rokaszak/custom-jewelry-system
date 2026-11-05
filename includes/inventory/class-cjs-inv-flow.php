<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Flow {

    const PRABA_REF = 999.0;
    const STATUS_KEYS = ['nepradeta', 'uzsakyti_modeli', 'atspausdinta', 'iduota_lieti', 'atlieta', 'pagaminta', 'isvezta_prabuoti', 'uzprabuota', 'done'];
    const STATUS_LABELS = [
        'nepradeta' => 'Nepradėta',
        'uzsakyti_modeli' => 'Užsakyti modelį',
        'atspausdinta' => 'Atspausdinta',
        'iduota_lieti' => 'Įduota lieti',
        'atlieta' => 'Atlieta',
        'pagaminta' => 'Pagaminta',
        'isvezta_prabuoti' => 'Išvežta prabuoti',
        'uzprabuota' => 'Užprabuota',
        'done' => 'DONE',
    ];
    const STATUS_ALIASES = [
        'nepradeta' => 'nepradeta',
        'uzsakytimodeli' => 'uzsakyti_modeli',
        'atspausdinta' => 'atspausdinta',
        'atspausdinti' => 'atspausdinta',
        'iduotalieti' => 'iduota_lieti',
        'iduotilieti' => 'iduota_lieti',
        'atlieta' => 'atlieta',
        'atlieti' => 'atlieta',
        'pagaminta' => 'pagaminta',
        'pagaminti' => 'pagaminta',
        'isveztaprabuoti' => 'isvezta_prabuoti',
        'isveztiprabuoti' => 'isvezta_prabuoti',
        'uzprabuota' => 'uzprabuota',
        'uzprabuoti' => 'uzprabuota',
        'done' => 'done',
    ];
    const WOO_TARGETS = ['cancelled', 'refunded', 'failed', 'trash', 'delete'];
    const LINE_STATES = ['waiting', 'partially_available', 'reserved', 'done', 'cancelled'];
    const RETURNABLE_FIXES = ['detach', 'returned', 'lost'];
    const LIVE = ['reserved', 'used', 'out'];
    const EPS = 0.00005;

    private static $rules = [];
    private static $guard = [];

    public static function flush() {
        self::$rules = [];
        if (class_exists('CJS_Inv_Metals')) {
            CJS_Inv_Metals::flush();
        }
    }

    public static function normalize($value) {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        return (string) preg_replace('/[^a-z]/', '', strtolower($value));
    }

    public static function status_key($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return 'nepradeta';
        }
        $key = self::STATUS_ALIASES[self::normalize($value)] ?? null;
        if ($key === null && class_exists('CJS_Statuses')) {
            $key = CJS_Statuses::key_from_label($value);
        }
        return $key;
    }

    public static function valid_key($value) {
        return self::status_key($value) ?? 'done';
    }

    public static function rank($key) {
        $i = array_search((string) $key, self::STATUS_KEYS, true);
        return $i === false ? -1 : (int) $i;
    }

    public static function is_woo_target($target) {
        return in_array(self::woo_target($target), self::WOO_TARGETS, true);
    }

    public static function woo_target($target) {
        return (string) preg_replace('/^wc-/', '', strtolower(trim((string) $target)));
    }

    public static function order_status_key($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        $raw = $order_id > 0 ? $wpdb->get_var($wpdb->prepare("SELECT manufacturing_status FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d", $order_id)) : null;
        $key = self::status_key((string) $raw) ?? 'nepradeta';
        return self::rank($key) >= 0 ? (string) $key : 'nepradeta';
    }

    public static function status_label($key) {
        $key = self::valid_key($key);
        return (string) (class_exists('CJS_Statuses') ? CJS_Statuses::label($key) : self::STATUS_LABELS[$key]);
    }

    public static function display_label($key) {
        $label = self::status_label($key);
        return $label !== '' ? $label : self::STATUS_LABELS[self::valid_key($key)];
    }

    public static function statuses() {
        $out = [];
        foreach (self::STATUS_KEYS as $key) {
            $out[] = ['key' => $key, 'label' => self::display_label($key)];
        }
        return $out;
    }

    public static function rules($item_id) {
        $item_id = (int) $item_id;
        if (!isset(self::$rules[$item_id])) {
            $item = CJS_Inv_Catalog::item_row($item_id);
            $cat = $item ? CJS_Inv_Catalog::category((int) $item['category_id']) : null;
            self::$rules[$item_id] = self::build_rules($item_id, $item, $cat);
        }
        return self::$rules[$item_id];
    }

    public static function category_rules($category_id) {
        $category_id = (int) $category_id;
        $key = 'c' . $category_id;
        if (!isset(self::$rules[$key])) {
            $cat = $category_id > 0 ? CJS_Inv_Catalog::category($category_id) : null;
            self::$rules[$key] = self::build_rules(0, $cat ? ['category_id' => $category_id, 'returnable' => $cat['returnable'], 'per_piece' => $cat['per_piece']] : null, $cat);
        }
        return self::$rules[$key];
    }

    public static function line_rules(array $d) {
        $item_id = (int) ($d['item_id'] ?? 0);
        if ($item_id > 0) {
            $r = self::rules($item_id);
            if ($r['exists']) {
                return $r;
            }
        }
        if ((int) ($d['category_id'] ?? 0) > 0 && trim((string) ($d['metal'] ?? '')) !== '') {
            return self::category_rules((int) $d['category_id']);
        }
        return self::rules($item_id);
    }

    private static function build_rules($item_id, $item, $cat) {
        $returnable = $item && (int) $item['returnable'] === 1;
        $used_by = self::valid_key($cat['used_by_status'] ?? 'done');
        $back_by = self::valid_key($cat['back_by_status'] ?? 'done');
        return [
            'item_id' => (int) $item_id,
            'exists' => (bool) $item,
            'category_id' => $item ? (int) $item['category_id'] : 0,
            'category' => $cat ? (string) $cat['name'] : '',
            'returnable' => $returnable,
            'per_piece' => $item && (int) $item['per_piece'] === 1,
            'auto_assign' => (int) ($cat['auto_assign'] ?? 1) === 1,
            'used_by' => $used_by,
            'back_by' => $back_by,
            'due' => $returnable ? $back_by : $used_by,
            'has_fineness' => (int) ($cat['has_fineness'] ?? 0) === 1,
        ];
    }

    public static function line_name(array $d) {
        $metal = CJS_Inv_Metals::of_line($d);
        return $metal ? $metal['metal'] : (string) ($d['item_name'] ?? ($d['name'] ?? ''));
    }

    public static function line_category(array $d) {
        $cid = (int) ($d['category_id'] ?? 0);
        return $cid > 0 ? $cid : self::line_rules($d)['category_id'];
    }

    public static function due_reached(array $rules, $status_key) {
        return self::rank($status_key) >= self::rank($rules['due']);
    }

    public static function ceil3($value) {
        return ceil(round((float) $value * 1000, 4)) / 1000;
    }

    public static function batch_fineness(array $batch, array $rules = []) {
        $item = CJS_Inv_Catalog::item_row((int) ($batch['item_id'] ?? 0));
        if (!$item || !CJS_Inv_Metals::tracked((int) $item['category_id'])) {
            return null;
        }
        return CJS_Inv_Metals::conv_praba($item);
    }

    public static function item_praba($item_id) {
        $item = CJS_Inv_Catalog::item_row((int) $item_id);
        return $item && CJS_Inv_Metals::tracked((int) $item['category_id']) ? CJS_Inv_Metals::praba_of($item) : null;
    }

    public static function physical_for($at_ref, $fineness) {
        return self::ceil3((float) $at_ref * self::PRABA_REF / (float) $fineness);
    }

    public static function fine_for($physical, $fineness) {
        return self::ceil3((float) $physical * (float) $fineness / self::PRABA_REF);
    }

    public static function from_pure($pure, array $rules) {
        return $rules['has_fineness'] ? self::ceil3((float) $pure * 1000 / self::PRABA_REF) : (float) $pure;
    }

    public static function clean_praba($value) {
        $f = CJS_Inv_DB::num($value);
        return $f !== null && $f > 0 && $f <= 1000 ? (float) $f : null;
    }

    public static function requirement_qty($grams, $praba, array $rules) {
        if (!$rules['has_fineness']) {
            return (float) $grams;
        }
        $p = self::clean_praba($praba);
        return $p !== null ? self::ceil3((float) $grams * $p / self::PRABA_REF) : self::ceil3((float) $grams);
    }

    public static function requirement_raw($grams, $praba, array $rules) {
        if (!$rules['has_fineness']) {
            return (float) $grams;
        }
        $p = self::clean_praba($praba);
        return $p !== null ? round((float) $grams * $p / self::PRABA_REF, 8) : round((float) $grams, 8);
    }

    public static function missing_at_default($missing, array $rules) {
        if ($missing <= 0) {
            return 0.0;
        }
        return $rules['has_fineness'] ? self::ceil3($missing) : (float) $missing;
    }

    public static function line_state(array $d) {
        $state = (string) ($d['state'] ?? 'open');
        if ($state === 'cancelled') {
            return 'cancelled';
        }
        if ($state === 'done') {
            return 'done';
        }
        $qty = (float) ($d['qty'] ?? 0);
        $alloc = (float) ($d['qty_allocated'] ?? 0);
        if ($qty > 0 && $alloc >= $qty - self::EPS) {
            return 'reserved';
        }
        return $alloc > self::EPS ? 'partially_available' : 'waiting';
    }

    public static function markers(array $states) {
        $sent = in_array('out', $states, true);
        return [
            'sent' => $sent,
            'returned' => !$sent && in_array('returned', $states, true),
        ];
    }

    public static function ordered_tag(array $d, $po) {
        if (!$po || !in_array(self::line_state($d), ['waiting', 'partially_available'], true)) {
            return null;
        }
        return ['id' => (int) $po['id'], 'number' => (string) $po['number'], 'line_id' => (int) ($po['line_id'] ?? 0)];
    }

    public static function alloc_states($demand_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare('SELECT state FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state <> 'released'", (int) $demand_id)) ?: [];
    }

    public static function live_allocs($demand_id, array $states = self::LIVE) {
        global $wpdb;
        $in = "'" . implode("','", array_map('sanitize_key', $states)) . "'";
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state IN ($in) ORDER BY id DESC", (int) $demand_id), ARRAY_A) ?: [];
    }

    public static function waiting_others($item_id, $demand_id, $demand = null) {
        return (bool) self::waiting_ids($item_id, (int) $demand_id, 1, $demand);
    }

    public static function waiting_ids($item_id, $exclude_id = 0, $want = 2, $demand = null) {
        global $wpdb;
        $metal = is_array($demand) ? CJS_Inv_Metals::of_line($demand) : null;
        $where = $metal ? $wpdb->prepare('category_id = %d AND metal = %s', $metal['category_id'], $metal['metal']) : $wpdb->prepare('item_id = %d', (int) $item_id);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . " WHERE $where AND id <> %d AND state = 'open' AND order_id > 0 AND qty_allocated < qty ORDER BY created_at ASC, id ASC LIMIT 50", (int) $exclude_id), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            if (self::may_auto_assign($row)) {
                $out[] = (int) $row['id'];
                if (count($out) >= $want) {
                    break;
                }
            }
        }
        return $out;
    }

    public static function may_auto_assign(array $demand) {
        $rules = self::line_rules($demand);
        if (!$rules['exists'] || !$rules['auto_assign'] || (int) $demand['order_id'] <= 0) {
            return false;
        }
        if (self::order_closed((int) $demand['order_id'])) {
            return false;
        }
        return (bool) apply_filters('cjs_inv_may_auto_assign', true, $demand, $rules);
    }

    public static function order_closed($order_id, $status = null) {
        $status = $status ?? CJS_Inv_Demands::order_status_fast((int) $order_id);
        return $status === '' || in_array($status, CJS_Inv_Demands::CLOSED_STATUSES, true);
    }

    public static function settle_line(array $d, $status_key) {
        if (($d['state'] ?? '') !== 'open' || (int) $d['order_id'] <= 0) {
            return false;
        }
        $rules = self::line_rules($d);
        if (!$rules['exists'] || !self::due_reached($rules, $status_key)) {
            return false;
        }
        if ($rules['returnable']) {
            $states = self::alloc_states((int) $d['id']);
            if (in_array('reserved', $states, true) || in_array('out', $states, true) || !in_array('returned', $states, true) || (float) $d['qty_allocated'] > self::EPS) {
                return false;
            }
            CJS_Inv_DB::update('demands', ['state' => 'done', 'updated_at' => CJS_Inv_DB::now()] + CJS_Inv_Demands::ended_row('returned'), ['id' => (int) $d['id']]);
            CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, ['order_id' => (int) $d['order_id'], 'demand_id' => (int) $d['id'], 'note' => sprintf(__('Returned, done at status %s', 'custom-jewelry-system'), self::display_label($status_key))]);
            return true;
        }
        if ((float) $d['qty_allocated'] < (float) $d['qty'] - self::EPS) {
            return false;
        }
        CJS_Inv_DB::transaction(function () use ($d, $status_key) {
            CJS_Inv_Stock::consume_reserved((int) $d['id'], sprintf(__('Used at status %s', 'custom-jewelry-system'), self::display_label($status_key)));
            CJS_Inv_DB::update('demands', ['state' => 'done', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $d['id']]);
        });
        return true;
    }

    public static function settle_order($order_id, $status_key = null) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }
        $key = $status_key !== null ? self::valid_key($status_key) : self::order_status_key($order_id);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND state = 'open' ORDER BY id", $order_id), ARRAY_A) ?: [];
        $n = 0;
        foreach ($rows as $d) {
            if (self::settle_line($d, $key)) {
                $n++;
            }
        }
        return $n;
    }

    public static function after_status_change($order_id, array $preview) {
        try {
            $touched = [];
            foreach ((array) ($preview['reopen_line_ids'] ?? []) as $did) {
                $d = CJS_Inv_Demands::row((int) $did);
                if ($d && $d['state'] === 'done') {
                    CJS_Inv_DB::update('demands', ['state' => 'open', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $did]);
                    CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, ['order_id' => (int) $order_id, 'demand_id' => (int) $did, 'note' => sprintf(__('Order went back to %s', 'custom-jewelry-system'), self::display_label($preview['to'] ?? 'nepradeta'))]);
                    $touched[] = (int) $d['item_id'];
                }
            }
            self::settle_order((int) $order_id);
            if ($touched) {
                CJS_Inv_Demands::reserve_pending(array_values(array_unique($touched)));
            }
            CJS_Inv_Discrepancies::auto_close([], [(int) $order_id], false, get_current_user_id());
        } catch (Throwable $e) {
            error_log('CJS inventory status follow-up failed for order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    public static function guarded($order_id) {
        return !empty(self::$guard[(int) $order_id]);
    }

    public static function with_guard(array $order_ids, callable $fn) {
        $added = [];
        foreach ($order_ids as $id) {
            $id = (int) $id;
            if (empty(self::$guard[$id])) {
                self::$guard[$id] = true;
                $added[] = $id;
            }
        }
        try {
            return $fn();
        } finally {
            foreach ($added as $id) {
                unset(self::$guard[$id]);
            }
        }
    }

    public static function mark_attended($order_id, $target) {
        set_transient('cjs_inv_attended_' . (int) $order_id, self::woo_target($target), 15 * MINUTE_IN_SECONDS);
    }

    public static function is_attended($order_id, $to = '') {
        if (self::guarded($order_id)) {
            return true;
        }
        $mark = get_transient('cjs_inv_attended_' . (int) $order_id);
        if ($mark === false) {
            return false;
        }
        return $to === '' || (string) $mark === self::woo_target($to);
    }

    public static function resolve_order($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        if ($order && is_a($order, 'WC_Order_Refund')) {
            $parent = (int) $order->get_parent_id();
            $order = $parent > 0 ? wc_get_order($parent) : null;
        }
        return $order && is_a($order, 'WC_Order') ? $order : null;
    }

    public static function order_ids(array $ids) {
        $out = [];
        foreach (array_filter(array_map('absint', $ids)) as $id) {
            $order = self::resolve_order($id);
            $out[] = $order ? (int) $order->get_id() : (int) $id;
        }
        return array_values(array_unique(array_filter($out)));
    }

    private static function order_head($order) {
        $id = (int) $order->get_id();
        return [
            'order_id' => $id,
            'number' => (string) $order->get_order_number(),
            'customer' => method_exists($order, 'get_billing_first_name') ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '',
            'wc_status' => (string) $order->get_status(),
            'cjs_status' => self::order_status_key($id),
        ];
    }

    private static function order_rows($order_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT d.*, i.name AS item_name, i.code FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND d.state <> 'cancelled' AND (i.id IS NOT NULL OR d.metal IS NOT NULL) ORDER BY d.order_item_id IS NULL, d.order_item_id, d.id",
            (int) $order_id
        ), ARRAY_A) ?: [];
        foreach ($rows as &$r) {
            $r['item_name'] = self::line_name($r);
        }
        unset($r);
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $allocs = [];
        if ($ids) {
            $list = $wpdb->get_results('SELECT a.*, p.identifier, b.item_id, bi.name AS batch_item_name, bi.praba AS batch_praba, bi.category_id AS batch_category_id FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id LEFT JOIN ' . CJS_Inv_DB::t('items') . ' bi ON bi.id = b.item_id LEFT JOIN ' . CJS_Inv_DB::t('pieces') . ' p ON p.id = a.piece_id WHERE a.demand_id IN (' . CJS_Inv_DB::ids_in($ids) . ") AND a.state <> 'released' ORDER BY a.id", ARRAY_A) ?: [];
            foreach ($list as $a) {
                $allocs[(int) $a['demand_id']][] = $a;
            }
        }
        return [$rows, $allocs];
    }

    public static function alloc_praba(array $a) {
        if (!array_key_exists('batch_praba', $a)) {
            return isset($a['batch_id']) ? self::batch_fineness(['item_id' => (int) ($a['item_id'] ?? 0)]) : null;
        }
        if (!CJS_Inv_Metals::tracked((int) ($a['batch_category_id'] ?? 0))) {
            return null;
        }
        return self::clean_praba($a['batch_praba']) ?? self::PRABA_REF;
    }

    public static function line_payload(array $d, array $allocs) {
        $rules = self::line_rules($d);
        $states = array_column($allocs, 'state');
        $missing = $d['state'] === 'open' ? max(0, round((float) $d['qty'] - (float) $d['qty_allocated'], 4)) : 0.0;
        $stock = 0.0;
        foreach ($allocs as $a) {
            if (in_array($a['state'], self::LIVE, true)) {
                $stock += (float) $a['qty'];
            }
        }
        return [
            'demand_id' => (int) $d['id'],
            'order_id' => (int) $d['order_id'],
            'order_item_id' => $d['order_item_id'] !== null ? (int) $d['order_item_id'] : null,
            'item_id' => (int) $d['item_id'],
            'item_name' => self::line_name($d),
            'metal' => ($m = CJS_Inv_Metals::of_line($d)) ? $m['metal'] : null,
            'category_id' => $rules['category_id'],
            'category' => $rules['category'],
            'unit' => CJS_Inv_Catalog::unit_label($d['unit_id']),
            'qty' => (float) $d['qty'],
            'allocated' => (float) $d['qty_allocated'],
            'missing' => $missing,
            'missing_at_default' => self::missing_at_default($missing, $rules),
            'fineness' => $rules['has_fineness'],
            'returnable' => $rules['returnable'],
            'per_piece' => $rules['per_piece'],
            'auto_assign' => $rules['auto_assign'],
            'due_status' => $rules['due'],
            'state' => (string) $d['state'],
            'status' => self::line_state($d),
            'markers' => self::markers($states),
            'has_stock' => $stock > self::EPS,
            'stock_qty' => round($stock, 4),
            'allocations' => array_map(function ($a) {
                return [
                    'id' => (int) $a['id'],
                    'state' => (string) $a['state'],
                    'qty' => (float) $a['qty'],
                    'covers' => CJS_Inv_Stock::cover($a),
                    'batch_id' => (int) $a['batch_id'],
                    'item_id' => (int) ($a['item_id'] ?? 0),
                    'item_name' => (string) ($a['batch_item_name'] ?? ''),
                    'fineness' => self::alloc_praba($a),
                    'piece_id' => $a['piece_id'] ? (int) $a['piece_id'] : null,
                    'identifier' => $a['identifier'] ?? null,
                ];
            }, $allocs),
        ];
    }

    public static function pieces_in($item_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, identifier FROM ' . CJS_Inv_DB::t('pieces') . " WHERE item_id = %d AND status = 'in' ORDER BY identifier IS NULL, LENGTH(identifier), identifier, id LIMIT 500", (int) $item_id), ARRAY_A) ?: [];
        return array_map(function ($p) { return ['id' => (int) $p['id'], 'identifier' => $p['identifier']]; }, $rows);
    }

    public static function batches_in($item_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, qty_on_hand, qty_reserved, received_at FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d AND qty_on_hand - qty_reserved > 0 ORDER BY received_at ASC, id ASC LIMIT 200', (int) $item_id), ARRAY_A) ?: [];
        $praba = self::item_praba((int) $item_id);
        return array_map(function ($b) use ($praba) {
            return [
                'id' => (int) $b['id'],
                'free' => round((float) $b['qty_on_hand'] - (float) $b['qty_reserved'], 4),
                'fineness' => $praba,
                'received_at' => $b['received_at'],
            ];
        }, $rows);
    }

    private static function lost_qty(array $l, $f) {
        $qty = is_array($f) && isset($f['qty']) ? CJS_Inv_DB::num($f['qty']) : null;
        $missing = round((float) $l['qty'] - (float) $l['allocated'], 4);
        return $qty !== null && $qty > 0 ? min($qty, $missing) : $missing;
    }

    private static function lost_batch_error(array $l, $f) {
        $batch_id = is_array($f) ? absint($f['batch_id'] ?? 0) : 0;
        $batch = $batch_id ? CJS_Inv_Stock::batch($batch_id) : null;
        if (!$batch || (int) $batch['item_id'] !== (int) $l['item_id']) {
            return true;
        }
        $qty = self::lost_qty($l, $f);
        if ($qty <= self::EPS) {
            return true;
        }
        $fineness = self::batch_fineness($batch);
        $need = $fineness ? self::physical_for($qty, $fineness) : $qty;
        return round((float) $batch['qty_on_hand'] - (float) $batch['qty_reserved'], 4) < $need - self::EPS;
    }

    public static function preview(array $order_ids, $target = null) {
        $order_ids = self::order_ids($order_ids);
        if (!$order_ids) {
            return new WP_Error('cjs_invalid', __('No orders selected.', 'custom-jewelry-system'));
        }
        $orders = [];
        $skipped = [];
        foreach ($order_ids as $id) {
            $p = self::preview_order($id, $target);
            if (is_wp_error($p)) {
                if (count($order_ids) === 1) {
                    return $p;
                }
                $skipped[] = $id;
                continue;
            }
            if (($p['kind'] ?? '') === 'cancel' && empty($p['has_inventory'])) {
                $skipped[] = $id;
                continue;
            }
            $orders[] = $p;
        }
        $needs = false;
        foreach ($orders as $p) {
            $needs = $needs || !empty($p['needs_decision']);
        }
        return [
            'target' => $target === null || $target === '' ? null : (self::is_woo_target($target) ? self::woo_target($target) : self::status_key($target)),
            'needs_decision' => $needs,
            'orders' => $orders,
            'skipped' => $skipped,
        ];
    }

    public static function preview_order($order_id, $target = null) {
        $order = self::resolve_order($order_id);
        if (!$order) {
            if ($target !== null && $target !== '' && self::is_woo_target($target) && self::missing_order($order_id)) {
                return self::missing_cancel_preview((int) $order_id, self::woo_target($target));
            }
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        if ($target !== null && $target !== '' && self::is_woo_target($target)) {
            return self::cancel_preview($order, self::woo_target($target));
        }
        $from = self::order_status_key((int) $order->get_id());
        $to = $target === null || $target === '' ? $from : self::status_key($target);
        if ($to === null) {
            return new WP_Error('cjs_invalid', __('Unknown status.', 'custom-jewelry-system'), ['status' => 400]);
        }
        [$rows, $allocs] = self::order_rows((int) $order->get_id());
        $shortages = [];
        $used = [];
        $returnables = [];
        $reopen = [];
        foreach ($rows as $d) {
            $rules = self::line_rules($d);
            if (!$rules['exists']) {
                continue;
            }
            $list = $allocs[(int) $d['id']] ?? [];
            $states = array_column($list, 'state');
            $reached = self::due_reached($rules, $to);
            if ($rules['returnable']) {
                if ($d['state'] !== 'open' || !$reached) {
                    continue;
                }
                if (in_array('out', $states, true)) {
                    $case = 'not_returned';
                } elseif (!in_array('reserved', $states, true) && in_array('returned', $states, true) && (float) $d['qty_allocated'] <= self::EPS) {
                    continue;
                } else {
                    $case = 'never_sent';
                }
                $piece = null;
                foreach ($list as $a) {
                    if (in_array($a['state'], ['reserved', 'out'], true) && $a['piece_id']) {
                        $piece = ['id' => (int) $a['piece_id'], 'identifier' => $a['identifier'] ?? null, 'allocation_id' => (int) $a['id']];
                        break;
                    }
                }
                $needs_piece = $case === 'never_sent' && !$piece && $rules['per_piece'];
                $needs_batch = $case === 'never_sent' && !$rules['per_piece'] && !array_intersect($states, ['reserved', 'out']);
                $returnables[] = self::line_payload($d, $list) + [
                    'case' => $case,
                    'piece' => $piece,
                    'needs_piece' => $needs_piece,
                    'piece_options' => $needs_piece ? self::pieces_in((int) $d['item_id']) : [],
                    'needs_batch' => $needs_batch,
                    'batch_options' => $needs_batch ? self::batches_in((int) $d['item_id']) : [],
                    'fixes' => self::RETURNABLE_FIXES,
                ];
                continue;
            }
            if ($d['state'] === 'open' && $reached && (float) $d['qty_allocated'] < (float) $d['qty'] - self::EPS) {
                $payload = self::line_payload($d, $list);
                $payload['found_qty'] = self::found_qty($payload['missing'], $rules);
                $shortages[] = $payload;
                continue;
            }
            if ($d['state'] === 'done' && !$reached) {
                $used_qty = 0.0;
                foreach ($list as $a) {
                    if ($a['state'] === 'used') {
                        $used_qty += $rules['has_fineness'] ? CJS_Inv_Stock::cover($a) : (float) $a['qty'];
                    }
                }
                if ($used_qty > self::EPS) {
                    $used[] = self::line_payload($d, $list) + ['used_qty' => round($used_qty, 4), 'choices' => ['write_off', 'return']];
                } else {
                    $reopen[] = (int) $d['id'];
                }
            }
        }
        $rf = self::rank($from);
        $rt = self::rank($to);
        return self::order_head($order) + [
            'kind' => 'status',
            'from' => $from,
            'to' => $to,
            'direction' => $rt > $rf ? 'forward' : ($rt < $rf ? 'back' : 'same'),
            'shortages' => $shortages,
            'used_lines' => $used,
            'returnables' => $returnables,
            'reopen_line_ids' => $reopen,
            'needs_decision' => (bool) ($shortages || $used || $returnables),
        ];
    }

    private static function found_qty($missing, array $rules) {
        if ($rules['has_fineness']) {
            return self::ceil3($missing);
        }
        if ($rules['per_piece']) {
            return (float) ceil(round($missing, 4) - 0.00001);
        }
        return round((float) $missing, 4);
    }

    public static function missing_order($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0 || self::resolve_order($order_id) || CJS_Inv_Demands::order_status_fast($order_id) !== '') {
            return false;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' WHERE order_id = %d', $order_id)) > 0;
    }

    private static function missing_cancel_preview($order_id, $target) {
        [$rows] = self::order_rows($order_id);
        $names = [];
        foreach ($rows as $d) {
            if ($d['order_item_id'] !== null && (int) $d['order_item_id'] > 0) {
                $names[(int) $d['order_item_id']] = sprintf(__('Order item #%d', 'custom-jewelry-system'), (int) $d['order_item_id']);
            }
        }
        $label = class_exists('CJS_Woo_Sync') ? CJS_Woo_Sync::deleted_label($order_id) : ['number' => (string) $order_id, 'customer' => ''];
        $head = [
            'order_id' => $order_id,
            'number' => (string) $label['number'],
            'customer' => (string) $label['customer'],
            'wc_status' => 'deleted',
            'cjs_status' => self::order_status_key($order_id),
            'deleted' => true,
        ];
        return self::cancel_tree($order_id, $head, $names, $target);
    }

    private static function cancel_preview($order, $target) {
        $names = [];
        foreach ($order->get_items() as $item_id => $item) {
            $names[(int) $item_id] = $item->get_name();
        }
        return self::cancel_tree((int) $order->get_id(), self::order_head($order), $names, $target);
    }

    private static function cancel_tree($order_id, array $head, array $names, $target) {
        [$rows, $allocs] = self::order_rows((int) $order_id);
        $groups = [];
        $has = false;
        foreach ($rows as $d) {
            $payload = self::line_payload($d, $allocs[(int) $d['id']] ?? []);
            $has = $has || $payload['has_stock'];
            $key = $d['order_item_id'] !== null && isset($names[(int) $d['order_item_id']]) ? (int) $d['order_item_id'] : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = ['order_item_id' => $key ?: null, 'name' => $key ? $names[$key] : null, 'lines' => []];
            }
            $groups[$key]['lines'][] = $payload;
        }
        $products = [];
        foreach ($groups as $key => $g) {
            if ($key !== 0) {
                $products[] = $g;
            }
        }
        if (isset($groups[0])) {
            $products[] = $groups[0];
        }
        return $head + [
            'kind' => 'cancel',
            'to' => $target,
            'products' => $products,
            'has_inventory' => $has,
            'needs_decision' => $has,
        ];
    }

    private static function conflict($message, $preview) {
        return new WP_Error('cjs_stock_conflicts', $message, ['status' => 409, 'preview' => $preview]);
    }

    public static function apply(array $order_ids, $target, array $decisions = [], array $opts = []) {
        $map = [];
        foreach (array_filter(array_map('absint', $order_ids)) as $raw) {
            $resolved = self::resolve_order($raw);
            $id = $resolved ? (int) $resolved->get_id() : (int) $raw;
            if (!isset($map[$id])) {
                $map[$id] = $raw;
            }
        }
        if (!$map) {
            return new WP_Error('cjs_invalid', __('No orders selected.', 'custom-jewelry-system'));
        }
        $perform = !array_key_exists('perform', $opts) || (!empty($opts['perform']) && $opts['perform'] !== 'false');
        $previews = [];
        foreach ($map as $id => $raw) {
            $p = self::preview_order($id, $target);
            if (is_wp_error($p)) {
                return $p;
            }
            $dec = [];
            foreach ([$id, $raw] as $k) {
                if (isset($decisions[$k]) && is_array($decisions[$k])) {
                    $dec = $decisions[$k];
                    break;
                }
                if (isset($decisions[(string) $k]) && is_array($decisions[(string) $k])) {
                    $dec = $decisions[(string) $k];
                    break;
                }
            }
            if ($p['kind'] === 'status') {
                $check = self::validate_status($p, $dec);
                if (is_wp_error($check)) {
                    return $check;
                }
            }
            $previews[$id] = [$p, $dec];
        }
        $results = [];
        if (self::is_woo_target($target)) {
            $doc = 0;
            $numbers = [];
            foreach ($previews as $id => [$p, $dec]) {
                $results[] = self::apply_cancel($id, $p, (array) ($dec['write_off'] ?? []), $doc, $perform, $wrote);
                if ($wrote) {
                    $numbers[] = '#' . $p['number'];
                }
            }
            if ($doc) {
                CJS_Inv_DB::update('documents', ['note' => sprintf(__('Written off: %1$s %2$s', 'custom-jewelry-system'), implode(', ', $numbers), self::target_word(self::woo_target($target)))], ['id' => $doc]);
            }
        } else {
            foreach ($previews as $id => [$p, $dec]) {
                $r = self::apply_status($id, $p, $dec);
                if (is_wp_error($r)) {
                    return $r;
                }
                $results[] = $r;
            }
        }
        return ['target' => self::is_woo_target($target) ? self::woo_target($target) : self::status_key($target), 'orders' => $results];
    }

    private static function target_word($target) {
        $words = [
            'cancelled' => __('cancelled', 'custom-jewelry-system'),
            'refunded' => __('refunded', 'custom-jewelry-system'),
            'failed' => __('failed', 'custom-jewelry-system'),
            'trash' => __('moved to trash', 'custom-jewelry-system'),
            'delete' => __('deleted', 'custom-jewelry-system'),
        ];
        return $words[$target] ?? $target;
    }

    private static function validate_status(array $p, array $dec) {
        if ($p['shortages'] && empty($dec['auto_correct'])) {
            return self::conflict(__('Some inventory is not ready for this status. Fix it by hand or let auto-correct fix it.', 'custom-jewelry-system'), $p);
        }
        $lines = (array) ($dec['lines'] ?? []);
        foreach ($p['used_lines'] as $l) {
            $c = $lines[$l['demand_id']] ?? ($lines[(string) $l['demand_id']] ?? null);
            if (!in_array($c, ['write_off', 'return'], true)) {
                return self::conflict(__('Choose for every used line whether its stock is written off or returned to stock.', 'custom-jewelry-system'), $p);
            }
        }
        $fixes = (array) ($dec['returnables'] ?? []);
        foreach ($p['returnables'] as $l) {
            $f = $fixes[$l['demand_id']] ?? ($fixes[(string) $l['demand_id']] ?? null);
            $fix = is_array($f) ? ($f['fix'] ?? null) : $f;
            if (!in_array($fix, self::RETURNABLE_FIXES, true)) {
                return self::conflict(__('Choose a fix for every returnable item.', 'custom-jewelry-system'), $p);
            }
            if ($l['needs_piece'] && $fix !== 'detach') {
                $piece_id = is_array($f) ? absint($f['piece_id'] ?? 0) : 0;
                $piece = $piece_id ? CJS_Inv_Stock::piece($piece_id) : null;
                if (!$piece || (int) $piece['item_id'] !== (int) $l['item_id'] || $piece['status'] !== 'in') {
                    return self::conflict(__('Choose which piece it was.', 'custom-jewelry-system'), $p);
                }
            }
            if (!empty($l['needs_batch']) && $fix === 'lost' && self::lost_batch_error($l, $f)) {
                return self::conflict(__('Choose which lot it was and how much was lost.', 'custom-jewelry-system'), $p);
            }
        }
        return true;
    }

    private static function apply_status($order_id, array $p, array $dec) {
        $to = $p['to'];
        $label = self::display_label($to);
        $number = $p['number'];
        $touched = [];
        $docs = [];
        $result = self::with_guard([$order_id], function () use ($order_id, $p, $dec, $to, $label, $number, &$touched, &$docs) {
            CJS_Inv_DB::transaction(function () use ($order_id, $p, $dec, $to, $label, $number, &$touched, &$docs) {
                $lines = (array) ($dec['lines'] ?? []);
                $write_doc = 0;
                foreach ($p['used_lines'] as $l) {
                    $did = (int) $l['demand_id'];
                    $choice = $lines[$did] ?? ($lines[(string) $did] ?? 'return');
                    $used = self::live_allocs($did, ['used']);
                    if ($choice === 'write_off') {
                        if (!$write_doc) {
                            $write_doc = CJS_Inv_Stock::create_document('count', sprintf(__('Written off: order #%1$s went back to %2$s', 'custom-jewelry-system'), $number, $label));
                            $docs[] = $write_doc;
                        }
                        foreach ($used as $a) {
                            CJS_Inv_Stock::write_off_allocation($a, $write_doc, sprintf(__('Written off from order #%s', 'custom-jewelry-system'), $number));
                        }
                        $touched[] = (int) $l['item_id'];
                    } else {
                        foreach ($used as $a) {
                            CJS_Inv_Stock::unuse_allocation($a, sprintf(__('Back in stock, still reserved for order #%s', 'custom-jewelry-system'), $number));
                        }
                    }
                    CJS_Inv_DB::update('demands', ['state' => 'open', 'updated_at' => CJS_Inv_DB::now()], ['id' => $did]);
                    CJS_Inv_Stock::log('demand', (int) $l['item_id'], 0, ['order_id' => $order_id, 'demand_id' => $did, 'note' => sprintf(__('Order went back to %s', 'custom-jewelry-system'), $label)]);
                }
                foreach ($p['reopen_line_ids'] as $did) {
                    $d = CJS_Inv_Demands::row($did);
                    if ($d && $d['state'] === 'done') {
                        CJS_Inv_DB::update('demands', ['state' => 'open', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $did]);
                        CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, ['order_id' => $order_id, 'demand_id' => (int) $did, 'note' => sprintf(__('Order went back to %s', 'custom-jewelry-system'), $label)]);
                        $touched[] = (int) $d['item_id'];
                    }
                }
                $fixes = (array) ($dec['returnables'] ?? []);
                foreach ($p['returnables'] as $l) {
                    $f = $fixes[$l['demand_id']] ?? ($fixes[(string) $l['demand_id']] ?? []);
                    $fix = is_array($f) ? (string) ($f['fix'] ?? '') : (string) $f;
                    $piece_id = is_array($f) ? absint($f['piece_id'] ?? 0) : 0;
                    $lot = !empty($l['needs_batch']) && $fix === 'lost' ? ['batch_id' => absint($f['batch_id'] ?? 0), 'qty' => self::lost_qty($l, $f)] : null;
                    $doc = self::fix_returnable($order_id, $number, $l, $fix, $piece_id, $lot);
                    if ($doc) {
                        $docs[] = $doc;
                    }
                    $touched[] = (int) $l['item_id'];
                }
                if ($p['shortages']) {
                    $found_doc = CJS_Inv_Stock::create_document('count', sprintf(__('Found for order #%1$s at status %2$s', 'custom-jewelry-system'), $number, $label));
                    $docs[] = $found_doc;
                    foreach ($p['shortages'] as $l) {
                        self::auto_correct_line((int) $l['demand_id'], $found_doc, $number, $label);
                    }
                }
                self::set_cjs_status($order_id, $to);
                self::settle_order($order_id, $to);
            });
            return true;
        });
        if ($touched) {
            CJS_Inv_Demands::reserve_pending(array_values(array_unique($touched)));
        }
        CJS_Inv_Stock_Orders::relink_pending();
        CJS_Inv_Discrepancies::auto_close([], [$order_id], false, get_current_user_id());
        return [
            'order_id' => $order_id,
            'status' => self::order_status_key($order_id),
            'document_ids' => array_values(array_unique(array_filter($docs))),
            'ok' => (bool) $result,
        ];
    }

    private static function auto_correct_line($demand_id, $doc, $number, $label) {
        $d = CJS_Inv_Demands::row($demand_id);
        if (!$d || $d['state'] !== 'open') {
            return;
        }
        $missing = round((float) $d['qty'] - (float) $d['qty_allocated'], 4);
        if ($missing <= self::EPS) {
            return;
        }
        $rules = self::line_rules($d);
        $qty = self::found_qty($missing, $rules);
        $item_id = (int) $d['item_id'];
        $metal = CJS_Inv_Metals::of_line($d);
        if ($metal) {
            $best = CJS_Inv_Metals::best($metal['category_id'], $metal['metal'], (int) $d['unit_id']);
            if (!$best) {
                $new = CJS_Inv_Metals::ensure_item($metal['category_id'], $metal['metal'], self::PRABA_REF, (int) $d['unit_id']);
                if (is_wp_error($new)) {
                    throw new CJS_Inv_Exception($new);
                }
                CJS_Inv_Metals::repoint($metal['category_id'], $metal['metal']);
                $best = CJS_Inv_Catalog::item_row((int) $new);
            }
            $item_id = (int) $best['id'];
            $qty = self::physical_for($qty, CJS_Inv_Metals::conv_praba($best));
        }
        $batch_id = CJS_Inv_Stock::create_batch([
            'item_id' => $item_id,
            'qty' => $qty,
            'source_type' => 'count',
            'note' => sprintf(__('+%1$s found for order #%2$s at status %3$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($qty), $number, $label),
        ], $doc);
        if (is_wp_error($batch_id)) {
            throw new CJS_Inv_Exception($batch_id);
        }
        CJS_Inv_Stock::allocate_demand($demand_id, ['batch_id' => (int) $batch_id, 'note' => sprintf(__('Found for order #%1$s at status %2$s', 'custom-jewelry-system'), $number, $label)]);
    }

    private static function fix_returnable($order_id, $number, array $l, $fix, $piece_id, $lot = null) {
        $did = (int) $l['demand_id'];
        $doc = 0;
        $live = self::live_allocs($did, ['reserved', 'out']);
        if (!$live && $piece_id && $fix !== 'detach') {
            CJS_Inv_Stock::allocate_demand($did, ['piece_id' => $piece_id, 'note' => sprintf(__('Piece named when fixing order #%s', 'custom-jewelry-system'), $number)]);
            $live = self::live_allocs($did, ['reserved', 'out']);
        }
        if (!$live && is_array($lot) && $lot['batch_id'] && $lot['qty'] > 0) {
            CJS_Inv_Stock::allocate_demand($did, ['batch_id' => (int) $lot['batch_id'], 'qty' => (float) $lot['qty'], 'note' => sprintf(__('Lot named when fixing order #%s', 'custom-jewelry-system'), $number)]);
            $live = self::live_allocs($did, ['reserved', 'out']);
        }
        if ($fix === 'detach') {
            foreach ($live as $a) {
                if ($a['state'] === 'out') {
                    CJS_Inv_Stock::reverse_send($a, __('It was never sent', 'custom-jewelry-system'));
                } else {
                    CJS_Inv_Stock::release_allocation_qty($a, (float) $a['qty'], __('It was never sent', 'custom-jewelry-system'));
                }
            }
            self::close_line($did, 'cancelled', __('Detached: it was never sent', 'custom-jewelry-system'), 'detached');
        } elseif ($fix === 'returned') {
            foreach ($live as $a) {
                if ($a['state'] === 'reserved') {
                    CJS_Inv_Stock::send_core($a, __('It was sent', 'custom-jewelry-system'));
                    $a = CJS_Inv_Stock::allocation((int) $a['id']);
                }
                CJS_Inv_Stock::return_out($a, __('It was returned', 'custom-jewelry-system'));
            }
            self::close_line($did, 'done', __('Sent and returned, removed from the order\'s inventory', 'custom-jewelry-system'), 'returned');
        } elseif ($fix === 'lost') {
            if ($live) {
                $doc = CJS_Inv_Stock::create_document('count', sprintf(__('Written off: lost on order #%s', 'custom-jewelry-system'), $number));
                foreach ($live as $a) {
                    CJS_Inv_Stock::write_off_allocation($a, $doc, sprintf(__('Lost on order #%s', 'custom-jewelry-system'), $number));
                }
            }
            self::close_line($did, 'cancelled', __('Lost, written off and removed from the order\'s inventory', 'custom-jewelry-system'), 'lost');
        }
        return $doc;
    }

    private static function close_line($demand_id, $state, $note, $ended = null) {
        $d = CJS_Inv_Demands::row($demand_id);
        if (!$d) {
            return;
        }
        CJS_Inv_DB::update('demands', ['state' => $state, 'cancelled_by_order' => 0, 'updated_at' => CJS_Inv_DB::now()] + CJS_Inv_Demands::ended_row($ended), ['id' => (int) $demand_id]);
        CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, ['order_id' => (int) $d['order_id'], 'demand_id' => (int) $demand_id, 'note' => $note]);
    }

    public static function set_cjs_status($order_id, $key) {
        $key = self::valid_key($key);
        if (self::order_status_key($order_id) === $key) {
            if (self::rank($key) >= self::rank('pagaminta')) {
                CJS_Statuses::clear_calendar((int) $order_id);
                CJS_Statuses::fill_hours((int) $order_id, $key);
            }
            return true;
        }
        $r = CJS_Orders::update_field((int) $order_id, 'manufacturing_status', self::status_label($key));
        if (is_wp_error($r)) {
            throw new CJS_Inv_Exception($r);
        }
        return true;
    }

    private static function apply_cancel($order_id, array $p, array $write_ids, &$doc, $perform, &$wrote = false) {
        global $wpdb;
        $wrote = false;
        $write = array_flip(array_map('absint', $write_ids));
        $target = $p['to'];
        $number = $p['number'];
        $touched = [];
        self::with_guard([$order_id], function () use ($order_id, $write, $target, $number, &$doc, &$touched, $perform, $wpdb, &$wrote) {
            CJS_Inv_DB::transaction(function () use ($order_id, $write, $number, &$doc, &$touched, $wpdb, &$wrote) {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND state <> 'cancelled' ORDER BY id", $order_id), ARRAY_A) ?: [];
                foreach ($rows as $d) {
                    $did = (int) $d['id'];
                    $live = self::live_allocs($did);
                    if (!$live && $d['state'] !== 'open') {
                        continue;
                    }
                    $written = $live && isset($write[$did]);
                    if ($written) {
                        $wrote = true;
                        if (!$doc) {
                            $doc = CJS_Inv_Stock::create_document('count', sprintf(__('Written off: #%s', 'custom-jewelry-system'), $number));
                        }
                        foreach ($live as $a) {
                            CJS_Inv_Stock::write_off_allocation($a, $doc, sprintf(__('Written off from order #%s', 'custom-jewelry-system'), $number));
                        }
                    } else {
                        foreach ($live as $a) {
                            if ($a['state'] === 'out') {
                                CJS_Inv_Stock::return_out($a, __('Order cancelled', 'custom-jewelry-system'));
                            } else {
                                CJS_Inv_Stock::release_allocation_qty($a, (float) $a['qty'], __('Order cancelled', 'custom-jewelry-system'));
                            }
                        }
                    }
                    $gone = $written && self::line_rules($d)['returnable'];
                    CJS_Inv_DB::update('demands', ['state' => 'cancelled', 'cancelled_by_order' => $gone ? 0 : 1, 'updated_at' => CJS_Inv_DB::now()] + ($gone ? CJS_Inv_Demands::ended_row('written_off') : []), ['id' => $did]);
                    CJS_Inv_Stock::log('demand', (int) $d['item_id'], 0, ['order_id' => $order_id, 'demand_id' => $did, 'note' => isset($write[$did]) && $live ? __('Order cancelled, stock written off', 'custom-jewelry-system') : __('Order cancelled', 'custom-jewelry-system')]);
                    $touched[] = (int) $d['item_id'];
                }
            });
            if (!self::resolve_order($order_id)) {
                return;
            }
            if ($perform) {
                self::perform_woo($order_id, $target);
            } else {
                self::mark_attended($order_id, $target);
            }
        });
        if ($touched) {
            CJS_Inv_Demands::reserve_pending(array_values(array_unique($touched)));
        }
        CJS_Inv_Discrepancies::auto_close([], [$order_id], false, get_current_user_id());
        return ['order_id' => $order_id, 'target' => $target, 'document_ids' => $doc && $wrote ? [(int) $doc] : [], 'ok' => true];
    }

    private static function perform_woo($order_id, $target) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ($target === 'delete') {
            $order->delete(true);
        } elseif ($target === 'trash') {
            if ($order->get_status() !== 'trash') {
                $order->delete(false);
            }
        } elseif (!$order->has_status($target)) {
            $order->update_status($target);
        }
    }
}
