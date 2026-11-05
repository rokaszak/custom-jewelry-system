<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Demands {

    const ACTIVE_STATUSES = ['processing', 'partially-paid'];
    const CLOSED_STATUSES = ['cancelled', 'refunded', 'failed', 'trash'];
    const UNPAID_STATUSES = ['pending', 'on-hold'];
    const FIRST_ASSIGN_META = '_cjs_inv_first_assign_done';

    public static function init() {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 30, 4);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_checkout'], 30, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'on_checkout'], 30, 1);
        add_action('woocommerce_trash_order', [__CLASS__, 'on_order_trashed'], 10, 1);
        add_action('wp_trash_post', [__CLASS__, 'on_post_trashed'], 10, 1);
        add_action('woocommerce_before_delete_order', [__CLASS__, 'on_order_trashed'], 10, 1);
        add_action('before_delete_post', [__CLASS__, 'on_post_trashed'], 10, 1);
        add_action('woocommerce_untrash_order', [__CLASS__, 'on_order_restored'], 10, 1);
        add_action(CJS_Inv_Discrepancies::CRON_HOOK, [CJS_Inv_Discrepancies::class, 'daily']);
        add_action('init', [CJS_Inv_Discrepancies::class, 'ensure_scheduled'], 20);
    }

    public static function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function on_status_changed($order_id, $from, $to, $order = null) {
        try {
            $person = CJS_Inv_Flow::is_attended($order_id, $to);
            $attended = $person;
            if (in_array($to, self::ACTIVE_STATUSES, true)) {
                self::reopen_order($order_id);
                self::apply_requirements($order_id);
                self::first_assign($order_id);
                self::reserve_order($order_id);
            } elseif (in_array($to, self::UNPAID_STATUSES, true)) {
                if (in_array($from, self::CLOSED_STATUSES, true)) {
                    self::reopen_order($order_id);
                }
                self::apply_requirements($order_id);
            } elseif ($to === 'completed') {
                self::apply_requirements($order_id);
                self::reserve_order($order_id);
            } elseif (in_array($to, self::CLOSED_STATUSES, true)) {
                $attended = $attended || !self::has_stock($order_id);
            } elseif (in_array($from, self::CLOSED_STATUSES, true)) {
                self::reopen_order($order_id);
                self::reserve_order($order_id);
            }
            CJS_Inv_Discrepancies::auto_close([], [(int) $order_id], false, $person ? get_current_user_id() : 0);
            if (!$attended && ($to === 'completed' || in_array($to, self::CLOSED_STATUSES, true))) {
                do_action('cjs_inv_unattended_status_change', (int) $order_id, (string) $from, (string) $to);
            }
        } catch (Throwable $e) {
            error_log('CJS inventory status hook failed for order ' . $order_id . ': ' . $e->getMessage());
            CJS_Logger::log('Inventory update failed on order status change', 'error', 'order', $order_id, ['error' => $e->getMessage(), 'to' => $to]);
        }
    }

    public static function on_checkout($order) {
        try {
            $order_id = is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : absint($order);
            if ($order_id <= 0) {
                return;
            }
            $status = self::order_status_fast($order_id);
            if (in_array($status, self::UNPAID_STATUSES, true) || in_array($status, self::ACTIVE_STATUSES, true)) {
                self::apply_requirements($order_id);
            }
        } catch (Throwable $e) {
            error_log('CJS checkout inventory lines failed: ' . $e->getMessage());
        }
    }

    public static function first_assign($order_id) {
        global $wpdb;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::FIRST_ASSIGN_META) !== 'pending') {
            return;
        }
        $modes = [];
        foreach (CJS_Size_Kit::offers() as $o) {
            $modes[(int) $o['category']['id']] = (string) ($o['offer']['assign'] ?? 'add');
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT d.id, i.category_id FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND d.state = 'open' AND d.qty_allocated = 0 AND d.source IN ('size_kit', 'checkout_offer')
             AND NOT EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('allocations') . ' a WHERE a.demand_id = d.id)',
            (int) $order_id
        ), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if (($modes[(int) $r['category_id']] ?? 'add') !== 'reserve') {
                continue;
            }
            try {
                CJS_Inv_DB::transaction(function () use ($r) {
                    return CJS_Inv_Stock::allocate_demand((int) $r['id'], ['note' => __('Assigned automatically when the order was paid', 'custom-jewelry-system')]);
                });
            } catch (Throwable $e) {
                error_log('CJS first assign failed for demand ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        $order->update_meta_data(self::FIRST_ASSIGN_META, 'yes');
        $order->save_meta_data();
    }

    public static function has_stock($order_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('demands') . " d ON d.id = a.demand_id WHERE d.order_id = %d AND d.state <> 'cancelled' AND a.state IN ('reserved','used','out')", (int) $order_id)) > 0;
    }

    public static function on_order_restored($order_id) {
        try {
            $order = wc_get_order($order_id);
            if ($order && !in_array($order->get_status(), array_merge(self::CLOSED_STATUSES, ['completed']), true)) {
                self::reopen_order($order_id);
                self::reserve_order($order_id);
            }
        } catch (Throwable $e) {
            error_log('CJS inventory untrash hook failed: ' . $e->getMessage());
        }
    }

    public static function reopen_order($order_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND state = 'cancelled' AND cancelled_by_order = 1", $order_id));
        foreach ($ids as $id) {
            CJS_Inv_DB::update('demands', ['state' => 'open', 'cancelled_by_order' => 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $id]);
            $demand = self::row((int) $id);
            CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, ['order_id' => (int) $order_id, 'demand_id' => (int) $id, 'note' => __('Order reopened', 'custom-jewelry-system')]);
            CJS_Inv_Stock_Orders::queue_relink((int) $id);
        }
        return count($ids);
    }

    public static function on_order_trashed($order_id) {
        try {
            $to = current_filter() === 'woocommerce_before_delete_order' || current_filter() === 'before_delete_post' ? 'delete' : 'trash';
            if (!CJS_Inv_Flow::is_attended($order_id, $to) && self::has_stock($order_id)) {
                do_action('cjs_inv_unattended_status_change', (int) $order_id, '', $to);
            }
        } catch (Throwable $e) {
            error_log('CJS inventory trash hook failed: ' . $e->getMessage());
        }
    }

    public static function on_post_trashed($post_id) {
        if (get_post_type($post_id) === 'shop_order') {
            self::on_order_trashed($post_id);
        }
    }

    public static function apply_requirements($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ($order->get_meta('_cjs_inv_requirements_applied') === 'yes') {
            return;
        }
        $requirements = apply_filters('cjs_inv_order_requirements', [], $order);
        CJS_Inv_DB::transaction(function () use ($requirements, $order_id) {
            $plan = [];
            $metal_groups = [];
            $per_order = [];
            foreach (is_array($requirements) ? $requirements : [] as $req) {
                if (!is_array($req)) {
                    continue;
                }
                $qty = CJS_Inv_DB::num(array_key_exists('qty', $req) ? $req['qty'] : ($req['grams'] ?? 0));
                if (!$qty || $qty <= 0) {
                    continue;
                }
                $praba = self::requirement_praba($req);
                $order_level = ($req['scope'] ?? 'product') === 'order';
                $order_item_id = $order_level ? 0 : absint(is_scalar($req['order_item_id'] ?? null) ? $req['order_item_id'] : 0);
                $metal = self::requirement_metal($req, $praba);
                if ($metal) {
                    if ($praba === null && $metal['item_praba'] !== null) {
                        $praba = $metal['item_praba'];
                    }
                    $at_ref = CJS_Inv_Flow::requirement_raw($qty, $praba, CJS_Inv_Flow::category_rules($metal['category_id']));
                    $gk = ($order_level ? 'o' : 'p' . $order_item_id) . '|' . $metal['category_id'] . '|' . CJS_Inv_Metals::key($metal['metal']);
                    if (!isset($metal_groups[$gk])) {
                        $metal_groups[$gk] = ['order_item_id' => $order_item_id ?: null, 'metal' => $metal, 'qty' => 0.0, 'req' => $req];
                        $plan[] = ['metal', $gk];
                    }
                    $metal_groups[$gk]['qty'] = round($metal_groups[$gk]['qty'] + $at_ref, 8);
                    continue;
                }
                $item_id = absint(is_scalar($req['item_id'] ?? null) ? $req['item_id'] : 0);
                if (!$item_id || !CJS_Inv_Catalog::item_row($item_id)) {
                    continue;
                }
                $req['item_id'] = $item_id;
                $req['qty'] = CJS_Inv_Flow::requirement_qty($qty, $praba, CJS_Inv_Flow::rules($item_id));
                if ($order_level) {
                    $per_order[] = $req;
                    continue;
                }
                $plan[] = ['item', $req + ['order_item_id_clean' => $order_item_id ?: null]];
            }
            foreach ($plan as [$kind, $entry]) {
                if ($kind === 'metal') {
                    $g = $metal_groups[$entry];
                    self::insert_metal($order_id, $g['order_item_id'], $g['metal']['category_id'], $g['metal']['metal'], CJS_Inv_Flow::ceil3($g['qty']), self::requirement_opts($g['req']));
                    continue;
                }
                self::insert($order_id, $entry['order_item_id_clean'], (int) $entry['item_id'], (float) $entry['qty'], self::requirement_opts($entry));
            }
            foreach (self::merge_order_level($per_order) as $req) {
                self::insert($order_id, null, (int) $req['item_id'], (float) $req['qty'], self::requirement_opts($req));
            }
        });
        $order->update_meta_data('_cjs_inv_requirements_applied', 'yes');
        if ($order->get_meta(self::FIRST_ASSIGN_META) !== 'yes') {
            $order->update_meta_data(self::FIRST_ASSIGN_META, 'pending');
        }
        $order->save_meta_data();
    }

    private static function requirement_opts(array $req) {
        $text = function ($v) {
            return is_scalar($v) && $v !== '' ? (string) $v : null;
        };
        return [
            'source' => $text($req['source'] ?? null) ?? 'pofw',
            'source_ref' => $text($req['source_ref'] ?? null),
            'sale_price' => is_scalar($req['sale_price'] ?? null) ? $req['sale_price'] : null,
            'note' => $text($req['note'] ?? null),
        ];
    }

    public static function requirement_praba(array $req) {
        $raw = array_key_exists('praba', $req) ? $req['praba'] : ($req['fineness'] ?? null);
        return is_scalar($raw) ? CJS_Inv_Flow::clean_praba($raw) : null;
    }

    public static function requirement_metal(array $req, $praba = null) {
        $item_id = absint(is_scalar($req['item_id'] ?? null) ? $req['item_id'] : 0);
        if ($item_id) {
            $item = CJS_Inv_Catalog::item_row($item_id);
            $m = $item ? CJS_Inv_Metals::of_item($item) : null;
            return $m ? $m + ['item_praba' => CJS_Inv_Metals::praba_of($item)] : null;
        }
        $cid = absint(is_scalar($req['category_id'] ?? null) ? $req['category_id'] : 0);
        if (!$cid && !empty($req['category']) && is_string($req['category'])) {
            $cat = CJS_Inv_Catalog::category_by_slug(sanitize_title($req['category']));
            $cid = $cat ? (int) $cat['id'] : 0;
        }
        $raw = array_key_exists('metal', $req) ? $req['metal'] : ($req['name'] ?? '');
        $metal = is_string($raw) ? CJS_Inv_Metals::clean($raw) : '';
        if (!$cid || $metal === '' || !CJS_Inv_Metals::tracked($cid)) {
            return null;
        }
        $p = CJS_Inv_Flow::clean_praba($praba);
        if ($p !== null) {
            $metal = CJS_Inv_Catalog::strip_praba($metal, $p);
        }
        return ['category_id' => $cid, 'metal' => mb_substr($metal, 0, 191), 'item_praba' => null];
    }

    public static function insert_metal($order_id, $order_item_id, $category_id, $metal, $qty, array $opts = []) {
        $metal = CJS_Inv_Metals::clean($metal);
        if (!CJS_Inv_Metals::tracked($category_id) || $metal === '') {
            throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Item not found.', 'custom-jewelry-system')));
        }
        $unit_id = self::requirement_unit($category_id);
        $best = CJS_Inv_Metals::best($category_id, $metal, $unit_id);
        return self::insert($order_id, $order_item_id, $best ? (int) $best['id'] : 0, $qty, $opts + ['category_id' => (int) $category_id, 'metal' => $metal, 'unit_id' => $unit_id]);
    }

    public static function requirement_unit($category_id) {
        $grams = (int) CJS_Inv_Catalog::unit_id_by_label('g');
        if ($grams > 0) {
            return $grams;
        }
        $cat = CJS_Inv_Catalog::category((int) $category_id);
        return $cat ? (int) $cat['unit_id'] : 0;
    }

    public static function merge_order_level(array $reqs) {
        global $wpdb;
        $by_item = [];
        foreach ($reqs as $req) {
            $item_id = (int) $req['item_id'];
            if (!isset($by_item[$item_id]) || (float) $req['qty'] > (float) $by_item[$item_id]['qty']) {
                $by_item[$item_id] = $req;
            }
        }
        $items = CJS_Inv_Catalog::items_by_ids(array_keys($by_item));
        $groups = [];
        foreach ($by_item as $item_id => $req) {
            $item = $items[$item_id] ?? null;
            if (!$item) {
                continue;
            }
            $values = CJS_Inv_Catalog::item_values($item_id);
            $size_field = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND is_size = 1 ORDER BY sort_order, id LIMIT 1', $item['category_id']));
            $key_values = $values;
            unset($key_values[$size_field]);
            ksort($key_values);
            $key = $item['category_id'] . '|' . md5(wp_json_encode($key_values));
            $size_rank = null;
            if ($size_field && isset($values[$size_field])) {
                $v = $values[$size_field];
                if (!empty($v['choice_id'])) {
                    $size_rank = ['u' => 0, 'n' => (float) $wpdb->get_var($wpdb->prepare('SELECT sort_order FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE id = %d', $v['choice_id']))];
                } elseif ($v['value_number'] !== null) {
                    $size_rank = ['u' => (int) $v['unit_id'], 'n' => (float) $v['value_number']];
                }
            }
            $groups[$key][] = ['req' => $req, 'rank' => $size_rank];
        }
        $out = [];
        foreach ($groups as $members) {
            $best = [];
            foreach ($members as $m) {
                if ($m['rank'] === null) {
                    $out[] = $m['req'];
                    continue;
                }
                $u = $m['rank']['u'];
                if (!isset($best[$u]) || $m['rank']['n'] > $best[$u]['rank']['n']) {
                    $best[$u] = $m;
                }
            }
            foreach ($best as $m) {
                $out[] = $m['req'];
            }
        }
        return $out;
    }

    public static function insert($order_id, $order_item_id, $item_id, $qty, array $opts = []) {
        $item = (int) $item_id > 0 ? CJS_Inv_Catalog::item_row($item_id) : null;
        $metal = null;
        if (!empty($opts['metal']) && CJS_Inv_Metals::tracked((int) ($opts['category_id'] ?? 0))) {
            $metal = ['category_id' => (int) $opts['category_id'], 'metal' => CJS_Inv_Metals::clean($opts['metal'])];
        } elseif ($item) {
            $metal = CJS_Inv_Metals::of_item($item);
        }
        $line_unit = 0;
        if ($metal) {
            $line_unit = absint($opts['unit_id'] ?? 0) ?: ($item ? (int) $item['unit_id'] : 0);
            if (!$line_unit) {
                $metal_cat = CJS_Inv_Catalog::category($metal['category_id']);
                $line_unit = (int) ($metal_cat['unit_id'] ?? 0);
            }
            $item = CJS_Inv_Metals::best($metal['category_id'], $metal['metal'], $line_unit);
        } elseif (!$item) {
            throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Item not found.', 'custom-jewelry-system')));
        }
        $item_id = $item ? (int) $item['id'] : 0;
        $cat = $item ? null : CJS_Inv_Catalog::category($metal['category_id']);
        $unit_id = $line_unit ?: ($item ? (int) $item['unit_id'] : (int) ($cat['unit_id'] ?? 0));
        $unit_label = CJS_Inv_Catalog::unit_label($unit_id);
        $qty = (float) CJS_Inv_DB::num($qty);
        $rules = $item ? CJS_Inv_Flow::rules($item_id) : CJS_Inv_Flow::category_rules($metal['category_id']);
        if (!$metal && (int) $item['per_piece'] === 1) {
            $qty = (float) ceil(round($qty, 4) - 0.00001);
        } elseif ($rules['has_fineness'] && $qty > 0) {
            $qty = CJS_Inv_Flow::ceil3($qty);
        }
        if ($qty <= 0) {
            throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Quantity must be greater than zero.', 'custom-jewelry-system')));
        }
        $id = CJS_Inv_DB::insert('demands', [
            'order_id' => absint($order_id),
            'order_item_id' => $order_item_id ? absint($order_item_id) : null,
            'item_id' => $item_id,
            'category_id' => $metal ? (int) $metal['category_id'] : null,
            'metal' => $metal ? mb_substr($metal['metal'], 0, 191) : null,
            'unit_id' => $unit_id,
            'qty' => $qty,
            'qty_allocated' => 0,
            'qty_fineness' => $rules['has_fineness'] ? CJS_Inv_Flow::PRABA_REF : null,
            'state' => $opts['state'] ?? 'open',
            'source' => sanitize_key($opts['source'] ?? 'manual'),
            'source_ref' => isset($opts['source_ref']) ? mb_substr((string) $opts['source_ref'], 0, 100) : null,
            'sale_price' => isset($opts['sale_price']) ? CJS_Inv_DB::num($opts['sale_price']) : null,
            'note' => isset($opts['note']) && $opts['note'] !== '' ? sanitize_textarea_field($opts['note']) : null,
            'po_line_id' => $opts['po_line_id'] ?? null,
            'cancelled_by_order' => !empty($opts['cancelled_by_order']) ? 1 : 0,
            'created_by' => $opts['created_by'] ?? get_current_user_id(),
            'created_at' => $opts['created_at'] ?? CJS_Inv_DB::now(),
            'updated_at' => CJS_Inv_DB::now(),
        ]);
        CJS_Inv_Stock::log('demand', $item_id, 0, [
            'order_id' => absint($order_id),
            'demand_id' => $id,
            'note' => $rules['has_fineness']
                ? sprintf(__('Needs %1$s %2$s at %3$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($qty), $unit_label, CJS_Inv_DB::fmt(CJS_Inv_Flow::PRABA_REF))
                : sprintf(__('Needs %1$s %2$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($qty), $unit_label),
            'created_at' => $opts['created_at'] ?? null,
        ]);
        return $id;
    }

    public static function order_status($order_id) {
        $order = wc_get_order($order_id);
        return $order ? $order->get_status() : '';
    }

    private static function check_manual($order, $order_item_id, $item_id, $qty) {
        if (!$order) {
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'));
        }
        if (in_array($order->get_status(), self::CLOSED_STATUSES, true)) {
            return new WP_Error('cjs_invalid', __('Inventory cannot be added to a cancelled or refunded order.', 'custom-jewelry-system'));
        }
        if ($order->get_status() === 'completed') {
            return new WP_Error('cjs_invalid', __('This order is completed in WooCommerce, its inventory is final.', 'custom-jewelry-system'));
        }
        if ($order_item_id && !$order->get_item($order_item_id)) {
            return new WP_Error('cjs_invalid', __('Order item not found.', 'custom-jewelry-system'));
        }
        $qty = CJS_Inv_DB::num($qty);
        if (!$qty || $qty <= 0) {
            return new WP_Error('cjs_invalid', __('Quantity must be greater than zero.', 'custom-jewelry-system'));
        }
        $inv_item = CJS_Inv_Catalog::item_row($item_id);
        if (!$inv_item) {
            return new WP_Error('cjs_invalid', __('Item not found.', 'custom-jewelry-system'));
        }
        if ((int) $inv_item['per_piece'] === 1 && floor($qty) != $qty) {
            return new WP_Error('cjs_invalid', __('Per-piece items need a whole number.', 'custom-jewelry-system'));
        }
        return $qty;
    }

    private static function check_new_pieces($item_id, $qty, array $piece_ids) {
        if (count($piece_ids) > $qty) {
            return new WP_Error('cjs_invalid', sprintf(__('Tick at most %s pieces.', 'custom-jewelry-system'), CJS_Inv_DB::fmt($qty)));
        }
        foreach ($piece_ids as $pid) {
            $piece = CJS_Inv_Stock::piece((int) $pid);
            if (!$piece || (int) $piece['item_id'] !== (int) $item_id || $piece['status'] !== 'in') {
                return new WP_Error('cjs_invalid', sprintf(__('%s is no longer on the shelf.', 'custom-jewelry-system'), $piece && $piece['identifier'] ? $piece['identifier'] : __('A piece', 'custom-jewelry-system')));
            }
        }
        return true;
    }

    public static function set_pieces($demand_id, $qty, array $piece_ids) {
        global $wpdb;
        $demand = self::row($demand_id);
        if (!$demand || $demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
        }
        $item = CJS_Inv_Catalog::item_row((int) $demand['item_id']);
        if (!$item || (int) $item['per_piece'] !== 1 || CJS_Inv_Metals::of_line($demand)) {
            return new WP_Error('cjs_invalid', __('This item is not tracked by piece.', 'custom-jewelry-system'));
        }
        $qty = CJS_Inv_DB::num($qty);
        if (!$qty || $qty < 1 || floor($qty) != $qty) {
            return new WP_Error('cjs_invalid', __('Per-piece items need a whole number.', 'custom-jewelry-system'));
        }
        $piece_ids = array_values(array_unique(array_filter(array_map('absint', $piece_ids))));
        if (count($piece_ids) > $qty) {
            return new WP_Error('cjs_invalid', sprintf(__('Tick at most %s pieces.', 'custom-jewelry-system'), CJS_Inv_DB::fmt($qty)));
        }
        $current = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state IN ('reserved','out') AND piece_id IS NOT NULL ORDER BY id", (int) $demand_id), ARRAY_A) ?: [] as $a) {
            $current[(int) $a['piece_id']] = $a;
        }
        foreach ($current as $pid => $a) {
            if ($a['state'] === 'out' && !in_array($pid, $piece_ids, true)) {
                $piece = CJS_Inv_Stock::piece($pid);
                return new WP_Error('cjs_invalid', sprintf(__('%s is sent. Mark it as returned before removing it.', 'custom-jewelry-system'), $piece && $piece['identifier'] ? $piece['identifier'] : __('A piece', 'custom-jewelry-system')));
            }
        }
        $new = array_values(array_filter($piece_ids, function ($pid) use ($current) { return !isset($current[$pid]); }));
        $check = self::check_new_pieces((int) $item['id'], $qty, $new);
        if (is_wp_error($check)) {
            return $check;
        }
        $rules = CJS_Inv_Flow::rules((int) $item['id']);
        $last = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id = %d', (int) $demand_id));
        CJS_Inv_DB::transaction(function () use ($demand, $qty, $piece_ids, $current, $new, $rules, $last, $wpdb) {
            foreach ($current as $pid => $a) {
                if (!in_array($pid, $piece_ids, true)) {
                    CJS_Inv_Stock::release_allocation_qty($a, (float) $a['qty'], __('Piece unticked', 'custom-jewelry-system'));
                }
            }
            if ((float) $qty !== (float) $demand['qty']) {
                CJS_Inv_DB::update('demands', ['qty' => $qty, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand['id']]);
                CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, [
                    'order_id' => (int) $demand['order_id'],
                    'demand_id' => (int) $demand['id'],
                    'note' => sprintf(__('Quantity changed from %1$s to %2$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($demand['qty']), CJS_Inv_DB::fmt($qty)),
                ]);
            }
            foreach ($new as $pid) {
                $got = CJS_Inv_Stock::allocate_demand((int) $demand['id'], ['piece_id' => (int) $pid]);
                if ($got <= 0) {
                    throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('A ticked piece is no longer on the shelf.', 'custom-jewelry-system')));
                }
            }
            if (!$rules['auto_assign'] && $rules['returnable']) {
                $fresh = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND id > %d AND state = 'reserved' ORDER BY id", (int) $demand['id'], $last), ARRAY_A) ?: [];
                foreach ($fresh as $a) {
                    CJS_Inv_Stock::send_core($a, __('Sent', 'custom-jewelry-system'));
                }
            }
        });
        if ($rules['auto_assign']) {
            self::reserve_pending([(int) $item['id']]);
        }
        $fresh = self::row((int) $demand['id']);
        if ($fresh) {
            CJS_Inv_Flow::settle_line($fresh, CJS_Inv_Flow::order_status_key((int) $fresh['order_id']));
        }
        self::close_order_discrepancy((int) $demand['order_id']);
        return true;
    }

    public static function add_manual($order_id, $order_item_id, $item_id, $qty, $note = '', array $alloc_opts = []) {
        $order = wc_get_order($order_id);
        $qty = self::check_manual($order, $order_item_id, $item_id, $qty);
        if (is_wp_error($qty)) {
            return $qty;
        }
        $piece_ids = array_key_exists('piece_ids', $alloc_opts) ? array_values(array_unique(array_filter(array_map('absint', (array) $alloc_opts['piece_ids'])))) : null;
        unset($alloc_opts['piece_ids']);
        if ($piece_ids) {
            $check = self::check_new_pieces($item_id, $qty, $piece_ids);
            if (is_wp_error($check)) {
                return $check;
            }
        }
        $demand_id = CJS_Inv_DB::transaction(function () use ($order_id, $order_item_id, $item_id, $qty, $note) {
            return self::insert($order_id, $order_item_id, $item_id, $qty, ['source' => 'manual', 'note' => $note]);
        });
        if ($piece_ids !== null) {
            $set = self::set_pieces($demand_id, $qty, $piece_ids);
            if (is_wp_error($set)) {
                return $set;
            }
            return $demand_id;
        }
        $rules = CJS_Inv_Flow::rules($item_id);
        $pinned = 0.0;
        if ($rules['auto_assign'] && !empty($alloc_opts['piece_id'])) {
            $pinned = CJS_Inv_DB::transaction(function () use ($demand_id, $alloc_opts) {
                return CJS_Inv_Stock::allocate_demand((int) $demand_id, ['piece_id' => (int) $alloc_opts['piece_id']]);
            });
        }
        if ($rules['auto_assign'] && $pinned <= 0) {
            self::reserve_pending([(int) $item_id]);
        } elseif (!$rules['auto_assign'] && array_filter($alloc_opts)) {
            $assigned = self::assign($demand_id, $alloc_opts);
            if (is_wp_error($assigned) && $assigned->get_error_code() !== 'cjs_no_stock') {
                return $assigned;
            }
        }
        $row = self::row($demand_id);
        if ($row) {
            CJS_Inv_Flow::settle_line($row, CJS_Inv_Flow::order_status_key($order_id));
        }
        self::close_order_discrepancy((int) $order_id);
        return $demand_id;
    }

    public static function add_metal_manual($order_id, $order_item_id, $category_id, $metal, $praba, $weight) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'));
        }
        if (in_array($order->get_status(), self::CLOSED_STATUSES, true)) {
            return new WP_Error('cjs_invalid', __('Inventory cannot be added to a cancelled or refunded order.', 'custom-jewelry-system'));
        }
        if ($order->get_status() === 'completed') {
            return new WP_Error('cjs_invalid', __('This order is completed in WooCommerce, its inventory is final.', 'custom-jewelry-system'));
        }
        if ($order_item_id && !$order->get_item($order_item_id)) {
            return new WP_Error('cjs_invalid', __('Order item not found.', 'custom-jewelry-system'));
        }
        $category_id = (int) $category_id;
        $metal = CJS_Inv_Metals::clean($metal);
        if ($metal === '' || !CJS_Inv_Metals::tracked($category_id)) {
            return new WP_Error('cjs_invalid', __('Metal not found.', 'custom-jewelry-system'));
        }
        $p = CJS_Inv_Flow::clean_praba($praba);
        if ($p === null) {
            return new WP_Error('cjs_invalid', __('Enter a praba between 1 and 1000.', 'custom-jewelry-system'));
        }
        $weight = CJS_Inv_DB::num($weight);
        if (!$weight || $weight <= 0) {
            return new WP_Error('cjs_invalid', __('Quantity must be greater than zero.', 'custom-jewelry-system'));
        }
        $item_ids = CJS_Inv_Metals::item_ids($category_id, $metal);
        if (!$item_ids) {
            return new WP_Error('cjs_invalid', __('Metal not found.', 'custom-jewelry-system'));
        }
        $at_ref = CJS_Inv_Flow::ceil3(CJS_Inv_Flow::requirement_raw((float) $weight, $p, CJS_Inv_Flow::category_rules($category_id)));
        $demand_id = CJS_Inv_DB::transaction(function () use ($order_id, $order_item_id, $category_id, $metal, $at_ref) {
            return self::insert_metal($order_id, $order_item_id, $category_id, $metal, $at_ref, ['source' => 'manual']);
        });
        if (CJS_Inv_Flow::category_rules($category_id)['auto_assign']) {
            self::reserve_pending($item_ids);
        }
        $row = self::row($demand_id);
        if ($row) {
            CJS_Inv_Flow::settle_line($row, CJS_Inv_Flow::order_status_key($order_id));
        }
        self::close_order_discrepancy((int) $order_id);
        return $demand_id;
    }

    public static function close_order_discrepancy($order_id) {
        if ((int) $order_id > 0) {
            CJS_Inv_Discrepancies::auto_close([], [(int) $order_id], false, get_current_user_id());
        }
    }

    public static function ended_row($outcome) {
        static $has = null;
        if ($outcome === null || $outcome === '') {
            return [];
        }
        if ($has === null) {
            global $wpdb;
            $has = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . CJS_Inv_DB::t('demands') . ' LIKE %s', 'ended_as'));
        }
        return $has ? ['ended_as' => sanitize_key($outcome)] : [];
    }

    public static function order_statuses_fast(array $order_ids) {
        global $wpdb;
        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
        $out = array_fill_keys($order_ids, '');
        if (!$order_ids) {
            return $out;
        }
        $in = CJS_Inv_DB::ids_in($order_ids);
        $rows = [];
        if (CJS_Orders::hpos()) {
            $rows = $wpdb->get_results("SELECT id, status FROM {$wpdb->prefix}wc_orders WHERE id IN ($in)", ARRAY_A) ?: [];
        }
        $found = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $left = array_diff($order_ids, $found);
        if ($left) {
            $posts = $wpdb->get_results("SELECT ID AS id, post_status AS status FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND ID IN (" . CJS_Inv_DB::ids_in($left) . ')', ARRAY_A) ?: [];
            $rows = array_merge($rows, $posts);
        }
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $out[(int) $r['id']] = strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
        }
        return $out;
    }

    public static function returnable_history($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT d.*, i.name AS item_name, i.category_id AS item_category_id FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND i.returnable = 1 ORDER BY d.id",
            $order_id
        ), ARRAY_A) ?: [];
        if (!$rows) {
            return [];
        }
        $ids = CJS_Inv_DB::ids_in(array_map(function ($r) { return (int) $r['id']; }, $rows));
        $allocs = [];
        $list = $wpdb->get_results('SELECT a.id, a.demand_id, a.state, a.piece_id, p.identifier FROM ' . CJS_Inv_DB::t('allocations') . ' a LEFT JOIN ' . CJS_Inv_DB::t('pieces') . " p ON p.id = a.piece_id WHERE a.demand_id IN ($ids) ORDER BY a.id", ARRAY_A) ?: [];
        foreach ($list as $a) {
            $allocs[(int) $a['demand_id']][] = $a;
        }
        $moves = [];
        $list = $wpdb->get_results('SELECT allocation_id, type, MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM ' . CJS_Inv_DB::t('movements') . " WHERE demand_id IN ($ids) AND allocation_id IS NOT NULL AND type IN ('out','return','count') GROUP BY allocation_id, type", ARRAY_A) ?: [];
        foreach ($list as $m) {
            $moves[(int) $m['allocation_id']][$m['type']] = $m;
        }
        $out = [];
        foreach ($rows as $d) {
            $did = (int) $d['id'];
            $ended = isset($d['ended_as']) ? (string) $d['ended_as'] : '';
            foreach ($allocs[$did] ?? [] as $a) {
                $mv = $moves[(int) $a['id']] ?? [];
                if (empty($mv['out']) || in_array($a['state'], ['reserved', 'out'], true)) {
                    continue;
                }
                $returned_at = null;
                $ended_at = (string) $d['updated_at'];
                if ($a['state'] === 'writeoff' || $a['state'] === 'used') {
                    $outcome = ($ended === 'written_off' || (int) $d['cancelled_by_order'] === 1) ? 'written_off' : 'lost';
                    if (!empty($mv['count'])) {
                        $ended_at = (string) $mv['count']['last_at'];
                    }
                } elseif (!empty($mv['return'])) {
                    $outcome = 'returned';
                    $returned_at = (string) $mv['return']['last_at'];
                    $ended_at = $returned_at;
                } else {
                    continue;
                }
                $out[] = [
                    'demand_id' => $did,
                    'item_id' => (int) $d['item_id'],
                    'category_id' => (int) $d['item_category_id'],
                    'item_name' => (string) $d['item_name'],
                    'piece' => $a['identifier'] !== null ? (string) $a['identifier'] : null,
                    'piece_id' => $a['piece_id'] ? (int) $a['piece_id'] : null,
                    'outcome' => $outcome,
                    'sent_at' => (string) $mv['out']['first_at'],
                    'returned_at' => $returned_at,
                    'ended_at' => $ended_at,
                ];
            }
        }
        return $out;
    }

    public static function set_note($demand_id, $note) {
        $demand = self::row($demand_id);
        if (!$demand || $demand['state'] === 'cancelled') {
            return new WP_Error('cjs_not_found', __('Inventory line not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $note = trim(sanitize_textarea_field((string) $note));
        CJS_Inv_DB::update('demands', ['note' => $note !== '' ? $note : null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand_id]);
        return (int) $demand_id;
    }

    public static function update_demand($demand_id, array $data) {
        $demand = self::row($demand_id);
        if (!$demand) {
            return new WP_Error('cjs_not_found', __('Inventory line not found.', 'custom-jewelry-system'));
        }
        if ($demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('Only open lines can be changed. Completed orders keep their history.', 'custom-jewelry-system'));
        }
        if (isset($data['item_id']) && absint($data['item_id']) !== (int) $demand['item_id'] && CJS_Inv_Metals::of_line($demand) && CJS_Inv_Metals::same_stock($demand, absint($data['item_id']))) {
            unset($data['item_id']);
        }
        if (isset($data['item_id']) && absint($data['item_id']) !== (int) $demand['item_id']) {
            $new_item = absint($data['item_id']);
            $qty = isset($data['qty']) ? $data['qty'] : $demand['qty'];
            $note = array_key_exists('note', $data) ? $data['note'] : $demand['note'];
            $order_item_id = $demand['order_item_id'] ? (int) $demand['order_item_id'] : null;
            $check = self::check_manual(wc_get_order((int) $demand['order_id']), $order_item_id, $new_item, $qty);
            if (is_wp_error($check)) {
                return $check;
            }
            $removed = self::remove_demand($demand_id, __('Replaced with another item', 'custom-jewelry-system'));
            if (is_wp_error($removed)) {
                return $removed;
            }
            return self::add_manual((int) $demand['order_id'], $order_item_id, $new_item, $check, (string) $note);
        }
        $item_id = (int) $demand['item_id'];
        $result = CJS_Inv_DB::transaction(function () use ($demand, $data) {
            global $wpdb;
            $refill = false;
            $row = ['updated_at' => CJS_Inv_DB::now()];
            if (array_key_exists('note', $data)) {
                $row['note'] = $data['note'] !== '' ? sanitize_textarea_field($data['note']) : null;
            }
            if (isset($data['qty'])) {
                $qty = CJS_Inv_DB::num($data['qty']);
                if (!$qty || $qty <= 0) {
                    throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Quantity must be greater than zero.', 'custom-jewelry-system')));
                }
                $item = CJS_Inv_Catalog::item_row((int) $demand['item_id']);
                if ($item && (int) $item['per_piece'] === 1 && floor($qty) != $qty) {
                    throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('Per-piece items need a whole number.', 'custom-jewelry-system')));
                }
                if (CJS_Inv_Flow::line_rules($demand)['has_fineness']) {
                    $qty = CJS_Inv_Flow::ceil3($qty);
                }
                $row['qty'] = $qty;
                $excess = round((float) $demand['qty_allocated'] - $qty, 4);
                if ($excess > 0) {
                    $allocs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state IN ('reserved','used','out') ORDER BY state = 'out', id DESC", $demand['id']), ARRAY_A) ?: [];
                    foreach ($allocs as $alloc) {
                        if ($excess <= 0) {
                            break;
                        }
                        if ($alloc['state'] === 'used' && CJS_Inv_Stock::is_lost_allocation($alloc)) {
                            continue;
                        }
                        $cover = CJS_Inv_Stock::cover($alloc);
                        if ($cover <= $excess + 0.00005) {
                            CJS_Inv_Stock::release_allocation_qty($alloc, (float) $alloc['qty'], __('Quantity reduced', 'custom-jewelry-system'));
                            $excess = round($excess - $cover, 4);
                        } elseif ($alloc['qty_fine'] !== null) {
                            CJS_Inv_Stock::release_allocation_qty($alloc, (float) $alloc['qty'], __('Quantity reduced', 'custom-jewelry-system'));
                            $excess = round($excess - $cover, 4);
                        } else {
                            CJS_Inv_Stock::release_allocation_qty($alloc, $excess, __('Quantity reduced', 'custom-jewelry-system'));
                            $excess = 0;
                        }
                    }
                    $refill = $excess < -0.00005;
                }
                CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, [
                    'order_id' => (int) $demand['order_id'],
                    'demand_id' => (int) $demand['id'],
                    'note' => sprintf(__('Quantity changed from %1$s to %2$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($demand['qty']), CJS_Inv_DB::fmt($qty)),
                ]);
            }
            CJS_Inv_DB::update('demands', $row, ['id' => (int) $demand['id']]);
            if ($refill) {
                CJS_Inv_Stock::allocate_demand((int) $demand['id']);
            }
            return (int) $demand['id'];
        });
        self::reserve_pending([$item_id]);
        $fresh = self::row((int) $demand['id']);
        if ($fresh) {
            CJS_Inv_Flow::settle_line($fresh, CJS_Inv_Flow::order_status_key((int) $fresh['order_id']));
        }
        self::close_order_discrepancy((int) $demand['order_id']);
        return $result;
    }

    public static function remove_demand($demand_id, $note = '') {
        global $wpdb;
        $demand = self::row($demand_id);
        if (!$demand) {
            return new WP_Error('cjs_not_found', __('Inventory line not found.', 'custom-jewelry-system'));
        }
        if ($demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('Only open lines can be removed.', 'custom-jewelry-system'));
        }
        $out = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state = 'out'", $demand_id));
        if ($out > 0) {
            return new WP_Error('cjs_invalid', __('A returnable piece is still out on this line. Use “Mark as returned” or unassign it first.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($demand, $note) {
            CJS_Inv_Stock::release_demand((int) $demand['id'], false, $note !== '' ? $note : __('Line removed', 'custom-jewelry-system'));
            CJS_Inv_DB::update('demands', ['state' => 'cancelled', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $demand['id']]);
            CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, [
                'order_id' => (int) $demand['order_id'],
                'demand_id' => (int) $demand['id'],
                'note' => $note !== '' ? $note : __('Line removed', 'custom-jewelry-system'),
            ]);
        });
        self::reserve_pending([(int) $demand['item_id']]);
        self::close_order_discrepancy((int) $demand['order_id']);
        return true;
    }

    public static function reserve_order($order_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT item_id FROM ' . CJS_Inv_DB::t('demands') . " WHERE order_id = %d AND order_id > 0 AND state = 'open' AND qty_allocated < qty", $order_id));
        if ($ids) {
            self::reserve_pending(array_map('intval', $ids));
        }
        CJS_Inv_Stock_Orders::relink_pending();
        CJS_Inv_Flow::settle_order((int) $order_id);
    }

    public static function may_auto_allocate($demand_id) {
        $demand = self::row($demand_id);
        return $demand ? CJS_Inv_Flow::may_auto_assign($demand) : false;
    }

    public static function order_status_fast($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return '';
        }
        $status = null;
        if (CJS_Orders::hpos()) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id));
        }
        if ($status === null) {
            $status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'", $order_id));
        }
        if ($status === null || $status === '') {
            return '';
        }
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }

    public static function reserve_pending(array $item_ids = [], array $prefer_po_line_ids = [], array $exclude_demand_ids = []) {
        global $wpdb;
        $asked = (bool) $item_ids;
        $item_ids = array_values(array_filter(array_map('absint', $item_ids)));
        if ($asked && !$item_ids) {
            return;
        }
        $where = "d.state = 'open' AND d.qty_allocated < d.qty AND d.order_id > 0";
        if ($item_ids) {
            $where .= ' AND ' . CJS_Inv_Metals::lines_sql($item_ids, 'd');
        }
        if ($exclude_demand_ids) {
            $where .= ' AND d.id NOT IN (' . CJS_Inv_DB::ids_in($exclude_demand_ids) . ')';
        }
        $prefer = $prefer_po_line_ids ? 'CASE WHEN d.po_line_id IN (' . CJS_Inv_DB::ids_in($prefer_po_line_ids) . ') OR d.id IN (SELECT k.demand_id FROM ' . CJS_Inv_DB::t('demand_links') . ' k WHERE k.po_line_id IN (' . CJS_Inv_DB::ids_in($prefer_po_line_ids) . ')) THEN 0 ELSE 1 END,' : '';
        $rows = $wpdb->get_results('SELECT d.* FROM ' . CJS_Inv_DB::t('demands') . " d WHERE $where ORDER BY $prefer d.created_at ASC, d.id ASC", ARRAY_A) ?: [];
        $exhausted = [];
        $status = [];
        foreach ($rows as $row) {
            $m = CJS_Inv_Metals::of_line($row);
            $stock_key = $m ? 'm' . $m['category_id'] . '|' . CJS_Inv_Metals::key($m['metal']) . '|' . (int) $row['unit_id'] : 'i' . (int) $row['item_id'];
            if (isset($exhausted[$stock_key])) {
                continue;
            }
            if (!CJS_Inv_Flow::may_auto_assign($row)) {
                continue;
            }
            try {
                $got = CJS_Inv_DB::transaction(function () use ($row) {
                    return CJS_Inv_Stock::allocate_demand((int) $row['id']);
                });
                if ($got <= 0) {
                    $exhausted[$stock_key] = true;
                    continue;
                }
                $oid = (int) $row['order_id'];
                if (!isset($status[$oid])) {
                    $status[$oid] = CJS_Inv_Flow::order_status_key($oid);
                }
                $fresh = self::row((int) $row['id']);
                if ($fresh) {
                    CJS_Inv_Flow::settle_line($fresh, $status[$oid]);
                }
            } catch (Throwable $e) {
                error_log('CJS reserve_pending failed for demand ' . $row['id'] . ': ' . $e->getMessage());
            }
        }
        CJS_Inv_Stock_Orders::relink_pending();
        if ($status) {
            CJS_Inv_Discrepancies::auto_close([], array_keys($status));
        }
    }

    public static function warnings_for_order($order_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT d.*, i.name AS item_name FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND d.state = 'open' AND d.qty_allocated < d.qty AND (i.id IS NOT NULL OR d.metal IS NOT NULL)",
            $order_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = sprintf('%s: %s %s missing', CJS_Inv_Flow::line_name($r), CJS_Inv_DB::fmt((float) $r['qty'] - (float) $r['qty_allocated']), CJS_Inv_Catalog::unit_label($r['unit_id']));
        }
        $po_open = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('stock_order_lines') . " l ON l.id = d.po_line_id
             WHERE d.order_id = %d AND d.state = 'open' AND l.qty_received < l.qty",
            $order_id
        ));
        if ($po_open > 0) {
            $out[] = sprintf(_n('%d line is waiting on a stock order that is not fully received.', '%d lines are waiting on stock orders that are not fully received.', $po_open, 'custom-jewelry-system'), $po_open);
        }
        return $out;
    }

    public static function lines_select() {
        return 'd.*, i.name AS item_name, i.code, COALESCE(i.per_piece, 0) AS per_piece, COALESCE(i.returnable, 0) AS returnable, COALESCE(i.category_id, d.category_id) AS category_id, COALESCE(i.qty_on_hand, 0) AS qty_on_hand, COALESCE(i.qty_reserved, 0) AS qty_reserved';
    }

    public static function for_order($order_id) {
        global $wpdb;
        $demands = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::lines_select() . ' FROM ' . CJS_Inv_DB::t('demands') . ' d
             LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND d.state <> 'cancelled' AND (i.id IS NOT NULL OR d.metal IS NOT NULL) AND NOT (COALESCE(i.returnable, 0) = 1 AND d.state = 'done') ORDER BY d.order_item_id IS NULL, d.order_item_id, d.id",
            $order_id
        ), ARRAY_A) ?: [];
        if (!$demands) {
            return [];
        }
        $ids = array_map(function ($d) { return (int) $d['id']; }, $demands);
        $allocs = $wpdb->get_results(
            'SELECT a.*, b.unit_cost, b.received_at, b.item_id, bi.name AS batch_item_name, bi.praba AS batch_praba, bi.category_id AS batch_category_id, p.identifier FROM ' . CJS_Inv_DB::t('allocations') . ' a
             INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id
             LEFT JOIN ' . CJS_Inv_DB::t('items') . ' bi ON bi.id = b.item_id
             LEFT JOIN ' . CJS_Inv_DB::t('pieces') . ' p ON p.id = a.piece_id
             WHERE a.demand_id IN (' . CJS_Inv_DB::ids_in($ids) . ") AND a.state <> 'released' ORDER BY a.id",
            ARRAY_A
        ) ?: [];
        $order_status = self::order_status_fast((int) $order_id);
        $by_id = [];
        foreach ($demands as $d) {
            $by_id[(int) $d['id']] = $d;
        }
        $alloc_by_demand = [];
        foreach ($allocs as $a) {
            $alloc_by_demand[(int) $a['demand_id']][] = [
                'id' => (int) $a['id'],
                'batch_id' => (int) $a['batch_id'],
                'item_id' => (int) $a['item_id'],
                'item_name' => (string) ($a['batch_item_name'] ?? ''),
                'piece_id' => $a['piece_id'] ? (int) $a['piece_id'] : null,
                'identifier' => $a['identifier'],
                'qty' => (float) $a['qty'],
                'covers' => CJS_Inv_Stock::cover($a),
                'fineness' => CJS_Inv_Flow::alloc_praba($a),
                'state' => $a['state'],
                'unit_cost' => $a['unit_cost'] !== null ? (float) $a['unit_cost'] : null,
                'received_at' => $a['received_at'],
                'can_unassign' => CJS_Inv_Stock::can_unassign($a, $by_id[(int) $a['demand_id']] ?? null, ['order_status' => $order_status]),
            ];
        }
        $po_lines = self::po_lines_for(array_filter(array_map(function ($d) { return (int) $d['po_line_id']; }, $demands)));
        $sources = self::source_stock_orders($ids);
        $parts = CJS_Inv_Stock_Orders::ordered_parts($demands);
        $metal_items = [];
        return array_map(function ($d) use ($alloc_by_demand, $po_lines, $sources, $parts, &$metal_items) {
            $allocs = $alloc_by_demand[(int) $d['id']] ?? [];
            $rules = CJS_Inv_Flow::line_rules($d);
            $metal = CJS_Inv_Metals::of_line($d);
            $cost = null;
            foreach ($allocs as $a) {
                if ($a['unit_cost'] !== null && in_array($a['state'], ['reserved', 'used', 'out'], true)) {
                    $cost = ($cost ?? 0) + $a['unit_cost'] * $a['qty'];
                }
            }
            $missing = $d['state'] === 'open' ? max(0, round((float) $d['qty'] - (float) $d['qty_allocated'], 4)) : 0.0;
            $po = $d['po_line_id'] ? ($po_lines[(int) $d['po_line_id']] ?? null) : null;
            $states = array_column($allocs, 'state');
            $markers = CJS_Inv_Flow::markers($states);
            $mkey = $metal ? $metal['category_id'] . '|' . CJS_Inv_Metals::key($metal['metal']) . '|' . (int) $d['unit_id'] : '';
            if ($metal && !isset($metal_items[$mkey])) {
                $metal_items[$mkey] = ['items' => CJS_Inv_Metals::payload($metal['category_id'], $metal['metal'], (int) $d['unit_id']), 'free' => CJS_Inv_Metals::free_at_ref($metal['category_id'], $metal['metal'], (int) $d['unit_id'])];
                $metal_items[$mkey]['note'] = $metal_items[$mkey]['items'] ? null : CJS_Inv_Metals::unit_note($metal['category_id'], $metal['metal'], (int) $d['unit_id']);
            }
            return [
                'id' => (int) $d['id'],
                'order_id' => (int) $d['order_id'],
                'order_item_id' => $d['order_item_id'] !== null ? (int) $d['order_item_id'] : null,
                'item_id' => (int) $d['item_id'],
                'item_name' => CJS_Inv_Flow::line_name($d),
                'metal' => $metal ? $metal['metal'] : null,
                'metal_items' => $metal ? $metal_items[$mkey]['items'] : null,
                'metal_unit_note' => $metal ? $metal_items[$mkey]['note'] : null,
                'code' => $metal ? null : $d['code'],
                'category_id' => (int) $d['category_id'],
                'per_piece' => (bool) $d['per_piece'],
                'returnable' => (bool) $d['returnable'],
                'auto_assign' => $rules['auto_assign'],
                'due_status' => $rules['due'],
                'fineness' => $rules['has_fineness'],
                'unit' => CJS_Inv_Catalog::unit_label($d['unit_id']),
                'qty' => (float) $d['qty'],
                'allocated' => (float) $d['qty_allocated'],
                'missing' => $missing,
                'missing_at_default' => CJS_Inv_Flow::missing_at_default($missing, $rules),
                'item_free' => $metal ? $metal_items[$mkey]['free'] : (float) $d['qty_on_hand'] - (float) $d['qty_reserved'],
                'state' => $d['state'],
                'status' => CJS_Inv_Flow::line_state($d),
                'markers' => $markers,
                'ordered' => CJS_Inv_Flow::ordered_tag($d, $po),
                'can_remove_returned' => $d['state'] === 'open' && $rules['returnable'] && $markers['returned'] && !array_intersect($states, ['reserved', 'out', 'used']),
                'source' => $d['source'],
                'note' => $d['note'],
                'sale_price' => $d['sale_price'] !== null ? (float) $d['sale_price'] : null,
                'cost' => $cost,
                'allocations' => $allocs,
                'stock_order' => self::po_for_demand($po, $d),
                'source_stock_order' => $sources[(int) $d['id']] ?? null,
                'stock_order_status_for_order' => $po ? CJS_Inv_Stock_Orders::status_for_order((int) $po['id'], (int) $d['order_id']) : null,
                'created_at' => $d['created_at'],
            ] + CJS_Inv_Stock_Orders::parts_fields($d, $parts);
        }, $demands);
    }

    public static function line_status(array $d, array $allocs = [], $missing = null, $po = null) {
        return CJS_Inv_Flow::line_state($d);
    }

    public static function swap_piece($demand_id, $piece_id) {
        $demand = self::row($demand_id);
        if (!$demand || $demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
        }
        $item = CJS_Inv_Catalog::item_row((int) $demand['item_id']);
        $piece = CJS_Inv_Stock::piece((int) $piece_id);
        if (!$item || (int) $item['per_piece'] !== 1 || !$piece || (int) $piece['item_id'] !== (int) $demand['item_id'] || $piece['status'] !== 'in') {
            return new WP_Error('cjs_invalid', __('That piece is not available.', 'custom-jewelry-system'));
        }
        $states = CJS_Inv_Flow::alloc_states((int) $demand_id);
        if (in_array('out', $states, true)) {
            return new WP_Error('cjs_invalid', __('The current piece is sent. Mark it as returned first.', 'custom-jewelry-system'));
        }
        $rules = CJS_Inv_Flow::rules((int) $demand['item_id']);
        global $wpdb;
        $last = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id = %d', (int) $demand_id));
        $got = CJS_Inv_DB::transaction(function () use ($demand_id, $piece_id, $rules, $last, $wpdb) {
            CJS_Inv_Stock::release_demand((int) $demand_id, false, __('Swapped for another piece', 'custom-jewelry-system'));
            $got = CJS_Inv_Stock::allocate_demand((int) $demand_id, ['piece_id' => (int) $piece_id]);
            if ($got <= 0) {
                throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', __('That piece is not available.', 'custom-jewelry-system')));
            }
            if (!$rules['auto_assign'] && $rules['returnable']) {
                $fresh = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND id > %d AND state = 'reserved' ORDER BY id", (int) $demand_id, $last), ARRAY_A) ?: [];
                foreach ($fresh as $a) {
                    CJS_Inv_Stock::send_core($a, __('Sent', 'custom-jewelry-system'));
                }
            }
            return $got;
        });
        if ($rules['auto_assign']) {
            self::reserve_pending([(int) $demand['item_id']], [], [(int) $demand_id]);
        }
        return $got;
    }

    public static function remove_returned($demand_id) {
        $demand = self::row($demand_id);
        if (!$demand || $demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
        }
        $rules = CJS_Inv_Flow::rules((int) $demand['item_id']);
        $states = CJS_Inv_Flow::alloc_states((int) $demand_id);
        if (!$rules['returnable'] || !in_array('returned', $states, true) || array_intersect($states, ['out', 'used'])) {
            return new WP_Error('cjs_invalid', __('Only a returned item can be removed from the order\'s inventory.', 'custom-jewelry-system'));
        }
        $had_reserved = in_array('reserved', $states, true);
        CJS_Inv_DB::transaction(function () use ($demand, $demand_id, $had_reserved) {
            if ($had_reserved) {
                CJS_Inv_Stock::release_demand((int) $demand_id, false, __('Returned item removed from the order\'s inventory', 'custom-jewelry-system'));
            }
            CJS_Inv_DB::update('demands', ['state' => 'done', 'updated_at' => CJS_Inv_DB::now()] + self::ended_row('returned'), ['id' => (int) $demand_id]);
            CJS_Inv_Stock::log('demand', (int) $demand['item_id'], 0, ['order_id' => (int) $demand['order_id'], 'demand_id' => (int) $demand_id, 'note' => __('Returned, removed from the order\'s inventory', 'custom-jewelry-system')]);
        });
        if ($had_reserved) {
            self::reserve_pending([(int) $demand['item_id']], [], [(int) $demand_id]);
        }
        self::close_order_discrepancy((int) $demand['order_id']);
        return true;
    }

    public static function po_lines_for(array $line_ids) {
        global $wpdb;
        $line_ids = array_values(array_unique(array_filter($line_ids)));
        if (!$line_ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            'SELECT l.id, l.stock_order_id, l.qty, l.qty_received, l.in_cart, s.number, s.status, s.order_date FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l
             INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id WHERE l.id IN (' . CJS_Inv_DB::ids_in($line_ids) . ')',
            ARRAY_A
        ) ?: [];
        $statuses = CJS_Inv_Stock_Orders::statuses();
        $out = [];
        foreach ($rows as $r) {
            $status = $statuses[$r['status']] ?? ['label' => $r['status'], 'color' => '#6b7280'];
            $out[(int) $r['id']] = [
                'line_id' => (int) $r['id'],
                'id' => (int) $r['stock_order_id'],
                'number' => $r['number'],
                'order_date' => $r['order_date'],
                'status' => $r['status'],
                'status_label' => $status['label'],
                'status_color' => $status['color'],
                'in_cart' => (bool) $r['in_cart'],
                'received' => (float) $r['qty_received'] >= (float) $r['qty'],
                'ordered' => (int) $r['in_cart'] > 0 || (float) $r['qty_received'] > 0,
                'qty' => (float) $r['qty'],
                'qty_received' => (float) $r['qty_received'],
            ];
        }
        return $out;
    }

    public static function po_for_demand($po, array $demand) {
        if (!$po) {
            return null;
        }
        $po['line_state'] = CJS_Inv_Stock_Orders::demand_state($demand, ['qty' => $po['qty'] ?? 0, 'qty_received' => $po['qty_received'] ?? 0, 'in_cart' => !empty($po['in_cart']) ? 1 : 0]);
        return $po;
    }

    public static function source_stock_orders(array $demand_ids) {
        global $wpdb;
        $demand_ids = array_values(array_unique(array_filter(array_map('absint', $demand_ids))));
        if (!$demand_ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            'SELECT a.demand_id, s.id AS stock_order_id, s.number FROM ' . CJS_Inv_DB::t('allocations') . ' a
             INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = a.batch_id AND b.source_type = 'stock_order'
             INNER JOIN " . CJS_Inv_DB::t('stock_order_lines') . ' l ON l.id = b.source_id
             INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id
             WHERE a.demand_id IN (' . CJS_Inv_DB::ids_in($demand_ids) . ") AND a.state <> 'released' ORDER BY a.id",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $did = (int) $r['demand_id'];
            if (!isset($out[$did])) {
                $out[$did] = ['id' => (int) $r['stock_order_id'], 'number' => (string) $r['number']];
            }
        }
        return $out;
    }

    public static function for_item($item_id) {
        global $wpdb;
        $item = CJS_Inv_Catalog::item_row((int) $item_id);
        $held = '';
        if ($item && CJS_Inv_Metals::of_item($item)) {
            $held = $wpdb->prepare(' OR id IN (SELECT a.demand_id FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = a.batch_id WHERE b.item_id = %d AND a.state IN ('reserved','out'))", (int) $item_id);
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('demands') . " WHERE state = 'open' AND (item_id = %d$held) ORDER BY created_at ASC, id ASC LIMIT 200", $item_id), ARRAY_A) ?: [];
        $orders = CJS_Orders::order_labels(array_map(function ($r) { return (int) $r['order_id']; }, $rows));
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $po_lines = self::po_lines_for(array_map(function ($r) { return (int) $r['po_line_id']; }, $rows));
        $sources = self::source_stock_orders($ids);
        $rules = CJS_Inv_Flow::rules((int) $item_id);
        $alloc_states = [];
        if ($ids) {
            $alloc_rows = $wpdb->get_results('SELECT demand_id, state FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id IN (' . CJS_Inv_DB::ids_in($ids) . ") AND state <> 'released'", ARRAY_A) ?: [];
            foreach ($alloc_rows as $a) {
                $alloc_states[(int) $a['demand_id']][] = (string) $a['state'];
            }
        }
        $parts = CJS_Inv_Stock_Orders::ordered_parts($rows);
        $split = self::planned_split();
        $out = array_map(function ($r) use ($orders, $po_lines, $sources, $rules, $alloc_states, $parts, $split) {
            $missing = max(0, round((float) $r['qty'] - (float) $r['qty_allocated'], 4));
            $po = $r['po_line_id'] ? ($po_lines[(int) $r['po_line_id']] ?? null) : null;
            return [
                'id' => (int) $r['id'],
                'order' => $orders[(int) $r['order_id']] ?? ['id' => (int) $r['order_id'], 'number' => (string) $r['order_id']],
                'metal' => ($lm = CJS_Inv_Metals::of_line($r)) ? $lm['metal'] : null,
                'qty' => (float) $r['qty'],
                'allocated' => (float) $r['qty_allocated'],
                'missing' => $missing,
                'missing_at_default' => CJS_Inv_Flow::missing_at_default($missing, $rules),
                'returnable' => $rules['returnable'],
                'status' => CJS_Inv_Flow::line_state($r),
                'markers' => CJS_Inv_Flow::markers($alloc_states[(int) $r['id']] ?? []),
                'ordered' => CJS_Inv_Flow::ordered_tag($r, $po),
                'stock_order' => self::po_for_demand($po, $r),
                'source_stock_order' => $sources[(int) $r['id']] ?? null,
                'planned' => $split['demand'][(int) $r['id']] ?? [],
                'note' => $r['note'],
                'created_at' => $r['created_at'],
            ] + CJS_Inv_Stock_Orders::parts_fields($r, $parts);
        }, $rows);
        global $wpdb;
        foreach (self::stock_lines([$wpdb->prepare('l.item_id = %d', (int) $item_id)]) as $l) {
            $open = self::stock_open($l, $split);
            if ($open <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $r = $l;
            if (!CJS_Inv_Metals::of_line($r)) {
                $r['metal'] = null;
            }
            $out[] = self::stock_row($l, $r, $open, $split) + ['returnable' => $rules['returnable'], 'missing_at_default' => $open];
        }
        return $out;
    }

    public static function missing_at_default_by_item(array $item_ids) {
        global $wpdb;
        $item_ids = array_values(array_unique(array_filter(array_map('absint', $item_ids))));
        if (!$item_ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT item_id, qty - qty_allocated AS m FROM ' . CJS_Inv_DB::t('demands') . ' WHERE item_id IN (' . CJS_Inv_DB::ids_in($item_ids) . ") AND state = 'open' AND qty_allocated < qty", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['item_id'];
            $out[$id] = ($out[$id] ?? 0.0) + CJS_Inv_Flow::missing_at_default(round((float) $r['m'], 4), CJS_Inv_Flow::rules($id));
        }
        foreach ($out as $id => $v) {
            $out[$id] = round($v, 4);
        }
        return $out;
    }

    public static function piece_tags(array $demand_ids) {
        global $wpdb;
        $demand_ids = array_values(array_unique(array_filter(array_map('absint', $demand_ids))));
        if (!$demand_ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT a.id, a.demand_id, a.state, p.identifier FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('pieces') . " p ON p.id = a.piece_id WHERE a.demand_id IN (" . CJS_Inv_DB::ids_in($demand_ids) . ") AND a.state <> 'released' AND p.identifier IS NOT NULL AND p.identifier <> '' ORDER BY a.id", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['demand_id']][] = ['id' => (int) $r['id'], 'identifier' => (string) $r['identifier'], 'state' => (string) $r['state']];
        }
        return $out;
    }

    public static function summaries_for_orders(array $order_ids) {
        global $wpdb;
        $order_ids = array_values(array_unique(array_filter(array_map('absint', $order_ids))));
        if (!$order_ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            'SELECT d.order_id, d.id, d.item_id, d.metal, d.qty, d.qty_allocated, d.state, d.unit_id, d.po_line_id, d.created_at, i.name, COALESCE(i.returnable, 0) AS returnable, COALESCE(i.category_id, d.category_id) AS category_id,
                (SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('allocations') . " a WHERE a.demand_id = d.id AND a.state = 'out') AS out_count,
                (SELECT COUNT(*) FROM " . CJS_Inv_DB::t('allocations') . " a WHERE a.demand_id = d.id AND a.state = 'reserved') AS reserved_count,
                (SELECT COUNT(*) FROM " . CJS_Inv_DB::t('allocations') . " a WHERE a.demand_id = d.id AND a.state = 'returned') AS returned_count
             FROM " . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = d.item_id
             WHERE d.order_id IN (' . CJS_Inv_DB::ids_in($order_ids) . ") AND d.state <> 'cancelled' AND (i.id IS NOT NULL OR d.metal IS NOT NULL) AND NOT (COALESCE(i.returnable, 0) = 1 AND d.state = 'done') ORDER BY d.id",
            ARRAY_A
        ) ?: [];
        $po_lines = self::po_lines_for(array_map(function ($r) { return (int) $r['po_line_id']; }, $rows));
        $parts = CJS_Inv_Stock_Orders::ordered_parts($rows);
        $tags = self::piece_tags(array_map(function ($r) { return (int) $r['id']; }, $rows));
        $out = [];
        foreach ($order_ids as $oid) {
            $out[$oid] = ['lines' => 0, 'missing_lines' => 0, 'ordered_lines' => 0, 'out_lines' => 0, 'to_send_lines' => 0, 'chips' => [], 'items' => []];
        }
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            $missing = $r['state'] === 'open' ? max(0, round((float) $r['qty'] - (float) $r['qty_allocated'], 4)) : 0;
            $po = $r['po_line_id'] ? ($po_lines[(int) $r['po_line_id']] ?? null) : null;
            $out[$oid]['lines']++;
            $status = CJS_Inv_Flow::line_state($r);
            $states = array_merge((int) $r['out_count'] > 0 ? ['out'] : [], (int) $r['reserved_count'] > 0 ? ['reserved'] : [], (int) $r['returned_count'] > 0 ? ['returned'] : []);
            $markers = CJS_Inv_Flow::markers($states);
            $short = in_array($status, ['waiting', 'partially_available'], true);
            $pf = CJS_Inv_Stock_Orders::parts_fields($r, $parts);
            if ($short && (float) $pf['unordered'] > CJS_Inv_Flow::EPS) {
                $out[$oid]['missing_lines']++;
            } elseif ($short) {
                $out[$oid]['ordered_lines']++;
            }
            if ($markers['sent']) {
                $out[$oid]['out_lines']++;
            } elseif ((int) $r['returnable'] === 1 && (int) $r['reserved_count'] > 0 && $r['state'] === 'open') {
                $out[$oid]['to_send_lines']++;
            }
            $rules = CJS_Inv_Flow::line_rules($r);
            $entry = [
                'demand_id' => (int) $r['id'],
                'item_id' => (int) $r['item_id'],
                'category_id' => (int) $r['category_id'],
                'returnable' => (int) $r['returnable'] === 1,
                'name' => CJS_Inv_Flow::line_name($r),
                'qty' => (float) $r['qty'],
                'allocated' => (float) $r['qty_allocated'],
                'missing' => $missing,
                'missing_at_default' => CJS_Inv_Flow::missing_at_default((float) $missing, $rules),
                'fineness' => $rules['has_fineness'],
                'unit' => CJS_Inv_Catalog::unit_label($r['unit_id']),
                'status' => $status,
                'markers' => $markers,
                'ordered' => CJS_Inv_Flow::ordered_tag($r, $po),
                'stock_order' => $po ? ['id' => $po['id'], 'number' => $po['number'], 'status_label' => $po['status_label'], 'status_color' => $po['status_color']] : null,
                'pieces' => $tags[(int) $r['id']] ?? [],
            ] + $pf;
            $out[$oid]['items'][] = $entry;
            if ($short || $markers['sent']) {
                $out[$oid]['chips'][] = $entry + ['stock_order_status_for_order' => $po ? CJS_Inv_Stock_Orders::status_for_order((int) $po['id'], $oid) : null];
            }
        }
        return $out;
    }

    public static function required_list(array $args = []) {
        global $wpdb;
        $where = ["d.state = 'open'", 'd.qty_allocated < d.qty', '(i.id IS NOT NULL OR d.metal IS NOT NULL)'];
        if (!empty($args['category_id'])) {
            $where[] = $wpdb->prepare('COALESCE(i.category_id, d.category_id) = %d', $args['category_id']);
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(trim($args['search'])) . '%';
            $where[] = $wpdb->prepare('(i.name LIKE %s OR i.code LIKE %s OR d.metal LIKE %s OR d.order_id = %d)', $like, $like, $like, absint($args['search']));
        }
        $ordered = $args['ordered'] ?? '';
        if ($ordered === 'yes') {
            $where[] = '(d.po_line_id IS NOT NULL OR EXISTS (SELECT 1 FROM ' . CJS_Inv_DB::t('demand_links') . ' k WHERE k.demand_id = d.id))';
        }
        $where_sql = implode(' AND ', $where);
        $rows = $wpdb->get_results(
            'SELECT ' . self::lines_select() . ' FROM ' . CJS_Inv_DB::t('demands') . ' d
             LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE $where_sql ORDER BY COALESCE(d.metal, i.name) ASC, d.created_at ASC, d.id ASC LIMIT 2000",
            ARRAY_A
        ) ?: [];
        $orders = CJS_Orders::order_labels(array_map(function ($r) { return (int) $r['order_id']; }, $rows));
        $parts = CJS_Inv_Stock_Orders::ordered_parts($rows);
        $po_lines = self::po_lines_for(array_map(function ($r) { return (int) $r['po_line_id']; }, $rows));
        $metal_ids = [];
        foreach ($rows as $r) {
            if (CJS_Inv_Metals::of_line($r)) {
                $metal_ids[] = (int) $r['id'];
            }
        }
        $fine_allocs = $metal_ids ? self::fine_allocations($metal_ids) : [];
        $tags = self::piece_tags(array_map(function ($r) { return (int) $r['id']; }, $rows));
        $split = self::planned_split();
        $groups = [];
        $unit_notes = [];
        foreach ($rows as $r) {
            $item_id = (int) $r['item_id'];
            $pf = CJS_Inv_Stock_Orders::parts_fields($r, $parts);
            if ($ordered === 'no' && (float) $pf['unordered'] <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $rules = CJS_Inv_Flow::line_rules($r);
            $metal = CJS_Inv_Metals::of_line($r);
            $key = $metal ? 'm' . $metal['category_id'] . '|' . CJS_Inv_Metals::key($metal['metal']) . '|' . (int) $r['unit_id'] : 'i' . $item_id;
            if (!isset($groups[$key])) {
                $groups[$key] = self::required_group($r, $metal, $key, $rules, $unit_notes);
            }
            $missing = round((float) $r['qty'] - (float) $r['qty_allocated'], 4);
            $at_default = CJS_Inv_Flow::missing_at_default($missing, $rules);
            $po = $r['po_line_id'] ? ($po_lines[(int) $r['po_line_id']] ?? null) : null;
            $groups[$key]['missing'] = round($groups[$key]['missing'] + $missing, 4);
            $groups[$key]['missing_at_default'] = round($groups[$key]['missing_at_default'] + $at_default, 4);
            $groups[$key]['not_ordered'] = round($groups[$key]['not_ordered'] + (float) $pf['unordered'], 4);
            $groups[$key]['demands'][] = [
                'id' => (int) $r['id'],
                'order' => $orders[(int) $r['order_id']] ?? ['id' => (int) $r['order_id'], 'number' => (string) $r['order_id']],
                'order_item_id' => $r['order_item_id'] !== null ? (int) $r['order_item_id'] : null,
                'qty' => (float) $r['qty'],
                'allocated' => (float) $r['qty_allocated'],
                'missing' => $missing,
                'missing_at_default' => $at_default,
                'status' => CJS_Inv_Flow::line_state($r),
                'state' => (string) $r['state'],
                'item_name' => CJS_Inv_Flow::line_name($r),
                'allocations' => $fine_allocs[(int) $r['id']] ?? [],
                'pieces' => array_merge($tags[(int) $r['id']] ?? [], $split['demand'][(int) $r['id']] ?? []),
                'note' => $r['note'],
                'stock_order' => self::po_for_demand($po, $r),
                'created_at' => $r['created_at'],
            ] + $pf;
        }
        if ($ordered !== 'no') {
            self::add_stock_lines($groups, $args, $unit_notes, $split);
        }
        return array_values($groups);
    }

    private static function required_group(array $r, $metal, $key, array $rules, array &$unit_notes) {
        $metal_payload = $metal ? CJS_Inv_Metals::payload($metal['category_id'], $metal['metal'], (int) $r['unit_id']) : null;
        if ($metal && !$metal_payload && !array_key_exists($key, $unit_notes)) {
            $unit_notes[$key] = CJS_Inv_Metals::unit_note($metal['category_id'], $metal['metal'], (int) $r['unit_id']);
        }
        return [
            'item_id' => (int) $r['item_id'],
            'name' => CJS_Inv_Flow::line_name($r),
            'metal' => $metal ? $metal['metal'] : null,
            'metal_items' => $metal_payload,
            'metal_unit_note' => $metal && !$metal_payload ? $unit_notes[$key] : null,
            'code' => $metal ? null : $r['code'],
            'category_id' => (int) $r['category_id'],
            'unit' => CJS_Inv_Catalog::unit_label($r['unit_id']),
            'free' => $metal ? CJS_Inv_Metals::free_at_ref($metal['category_id'], $metal['metal'], (int) $r['unit_id']) : (float) $r['qty_on_hand'] - (float) $r['qty_reserved'],
            'missing' => 0,
            'missing_at_default' => 0,
            'not_ordered' => 0,
            'fineness' => $rules['has_fineness'],
            'auto_assign' => $rules['auto_assign'],
            'demands' => [],
        ];
    }

    private static function planned_split() {
        global $wpdb;
        $k = CJS_Inv_DB::t('demand_links');
        $linked = $wpdb->get_results('SELECT d.id, d.order_id, d.item_id, d.category_id, d.metal, d.unit_id, d.qty, d.qty_allocated, d.state, d.po_line_id, d.created_at FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.state = 'open' AND d.qty_allocated < d.qty AND (d.po_line_id IS NOT NULL OR EXISTS (SELECT 1 FROM $k k WHERE k.demand_id = d.id)) ORDER BY d.created_at ASC, d.id ASC", ARRAY_A) ?: [];
        $parts = $linked ? CJS_Inv_Stock_Orders::ordered_parts($linked) : [];
        $planned = [];
        if (CJS_Inv_DB::has_column('stock_order_lines', 'planned_identifiers')) {
            foreach ($wpdb->get_results('SELECT id, planned_identifiers FROM ' . CJS_Inv_DB::t('stock_order_lines') . " WHERE qty > qty_received AND planned_identifiers IS NOT NULL AND planned_identifiers <> ''", ARRAY_A) ?: [] as $l) {
                $list = CJS_Inv_Stock_Orders::planned_of($l);
                if ($list) {
                    $planned[(int) $l['id']] = $list;
                }
            }
        }
        $used = [];
        $at = [];
        $by_demand = [];
        foreach ($linked as $d) {
            $did = (int) $d['id'];
            foreach ($parts[$did]['parts'] ?? [] as $part) {
                $lid = (int) $part['line_id'];
                $used[$lid] = ($used[$lid] ?? 0) + (float) $part['qty'];
                if (empty($planned[$lid])) {
                    continue;
                }
                $take = max(0, (int) round((float) $part['qty']));
                $from = $at[$lid] ?? 0;
                foreach (array_slice($planned[$lid], $from, $take) as $ident) {
                    $by_demand[$did][] = ['identifier' => $ident, 'state' => 'planned'];
                }
                $at[$lid] = $from + $take;
            }
        }
        $left = [];
        foreach ($planned as $lid => $list) {
            foreach (array_slice($list, $at[$lid] ?? 0) as $ident) {
                $left[$lid][] = ['identifier' => $ident, 'state' => 'planned'];
            }
        }
        return ['used' => $used, 'demand' => $by_demand, 'line' => $left];
    }

    private static function stock_lines(array $where) {
        global $wpdb;
        array_unshift($where, 'l.qty > l.qty_received');
        return $wpdb->get_results(
            'SELECT l.id AS line_id, l.stock_order_id, l.qty AS line_qty, l.qty_received, l.in_cart, l.note AS line_note, l.created_at, s.number, i.id AS item_id, i.name AS item_name, i.code, i.per_piece, i.returnable, i.category_id, i.unit_id, i.qty_on_hand, i.qty_reserved, ' . CJS_Inv_Metals::sql('i') . ' AS metal
             FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' l
             INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = l.stock_order_id
             INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = l.item_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.name ASC, l.created_at ASC, l.id ASC LIMIT 2000',
            ARRAY_A
        ) ?: [];
    }

    private static function stock_row(array $l, array $r, $open, array $split) {
        $lid = (int) $l['line_id'];
        return [
            'id' => 'line-' . $lid,
            'stock_line_id' => $lid,
            'order' => ['id' => 0, 'number' => ''],
            'order_item_id' => null,
            'qty' => $open,
            'allocated' => 0.0,
            'missing' => $open,
            'missing_at_default' => $open,
            'status' => 'waiting',
            'state' => 'open',
            'item_name' => CJS_Inv_Flow::line_name($r),
            'allocations' => [],
            'pieces' => $split['line'][$lid] ?? [],
            'note' => $l['line_note'],
            'stock_order' => ['id' => (int) $l['stock_order_id'], 'number' => (string) $l['number']],
            'created_at' => $l['created_at'],
            'parts' => [[
                'stock_order_id' => (int) $l['stock_order_id'],
                'number' => (string) $l['number'],
                'line_id' => $lid,
                'item_id' => (int) $l['item_id'],
                'item_name' => (string) $l['item_name'],
                'qty' => $open,
                'fineness' => null,
                'at_ref' => $open,
                'placed' => (int) $l['in_cart'] > 0 || (float) $l['qty_received'] > 0,
            ]],
            'unordered' => 0.0,
        ];
    }

    private static function stock_open(array $l, array $split) {
        $lid = (int) $l['line_id'];
        return round((float) $l['line_qty'] - (float) $l['qty_received'] - ($split['used'][$lid] ?? 0), 4);
    }

    private static function add_stock_lines(array &$groups, array $args, array &$unit_notes, array $split) {
        global $wpdb;
        $where = [];
        if (!empty($args['category_id'])) {
            $where[] = $wpdb->prepare('i.category_id = %d', $args['category_id']);
        }
        if (!empty($args['search'])) {
            $term = trim($args['search']);
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where[] = $wpdb->prepare('(i.name LIKE %s OR i.code LIKE %s OR s.number = %s)', $like, $like, $term);
        }
        foreach (self::stock_lines($where) as $l) {
            $open = self::stock_open($l, $split);
            if ($open <= CJS_Inv_Flow::EPS) {
                continue;
            }
            $r = $l;
            $rules = CJS_Inv_Flow::line_rules($r);
            $metal = CJS_Inv_Metals::of_line($r);
            if (!$metal) {
                $r['metal'] = null;
            }
            $key = $metal ? 'm' . $metal['category_id'] . '|' . CJS_Inv_Metals::key($metal['metal']) . '|' . (int) $r['unit_id'] : 'i' . (int) $r['item_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = self::required_group($r, $metal, $key, $rules, $unit_notes);
            }
            $groups[$key]['demands'][] = self::stock_row($l, $r, $open, $split);
        }
    }

    private static function fine_allocations(array $demand_ids) {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT a.*, b.item_id, bi.name AS batch_item_name, bi.praba AS batch_praba, bi.category_id AS batch_category_id FROM ' . CJS_Inv_DB::t('allocations') . ' a
             INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id
             LEFT JOIN ' . CJS_Inv_DB::t('items') . ' bi ON bi.id = b.item_id
             WHERE a.demand_id IN (' . CJS_Inv_DB::ids_in($demand_ids) . ") AND a.state <> 'released' ORDER BY a.id",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $a) {
            $out[(int) $a['demand_id']][] = [
                'id' => (int) $a['id'],
                'item_id' => (int) $a['item_id'],
                'item_name' => (string) ($a['batch_item_name'] ?? ''),
                'qty' => (float) $a['qty'],
                'covers' => CJS_Inv_Stock::cover($a),
                'fineness' => CJS_Inv_Flow::alloc_praba($a),
                'state' => $a['state'],
            ];
        }
        return $out;
    }

    public static function unordered_lines() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT d.id, d.order_id, d.item_id, d.category_id, d.metal, d.qty, d.qty_allocated, d.state, d.po_line_id, d.created_at FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE d.state = 'open' AND d.qty_allocated < d.qty AND (i.id IS NOT NULL OR d.metal IS NOT NULL)", ARRAY_A) ?: [];
        if (!$rows) {
            return [];
        }
        $parts = CJS_Inv_Stock_Orders::ordered_parts($rows);
        return array_values(array_filter($rows, function ($r) use ($parts) {
            return (float) CJS_Inv_Stock_Orders::parts_fields($r, $parts)['unordered'] > CJS_Inv_Flow::EPS;
        }));
    }

    public static function unordered_order_ids() {
        $out = [];
        foreach (self::unordered_lines() as $r) {
            if ((int) $r['order_id'] > 0) {
                $out[(int) $r['order_id']] = true;
            }
        }
        return array_keys($out);
    }

    public static function missing_count() {
        global $wpdb;
        $k = CJS_Inv_DB::t('demand_links');
        $rows = $wpdb->get_results('SELECT d.id, d.order_id, d.item_id, d.category_id, d.metal, d.qty, d.qty_allocated, d.state, d.po_line_id, d.created_at, (d.po_line_id IS NOT NULL OR EXISTS (SELECT 1 FROM ' . $k . ' k WHERE k.demand_id = d.id)) AS linked FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE d.state = 'open' AND d.qty_allocated < d.qty AND (i.id IS NOT NULL OR d.metal IS NOT NULL)", ARRAY_A) ?: [];
        $count = 0;
        $linked = [];
        foreach ($rows as $r) {
            if ((int) $r['linked'] === 1) {
                $linked[] = $r;
            } else {
                $count++;
            }
        }
        if ($linked) {
            $parts = CJS_Inv_Stock_Orders::ordered_parts($linked);
            foreach ($linked as $r) {
                if ((float) CJS_Inv_Stock_Orders::parts_fields($r, $parts)['unordered'] > CJS_Inv_Flow::EPS) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function assign($demand_id, array $opts) {
        $demand = self::row($demand_id);
        if (!$demand || $demand['state'] !== 'open') {
            return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
        }
        $rules = CJS_Inv_Flow::rules((int) $demand['item_id']);
        if ($rules['auto_assign']) {
            return new WP_Error('cjs_invalid', __('Stock for this category is assigned automatically by the queue.', 'custom-jewelry-system'));
        }
        if (!empty($opts['piece_id'])) {
            $piece = CJS_Inv_Stock::piece((int) $opts['piece_id']);
            if (!$piece || (int) $piece['item_id'] !== (int) $demand['item_id'] || $piece['status'] !== 'in') {
                return new WP_Error('cjs_invalid', __('That piece is not available.', 'custom-jewelry-system'));
            }
            if ((float) $demand['qty_allocated'] >= (float) $demand['qty'] && !empty($opts['replace_allocation_id'])) {
                $alloc = CJS_Inv_Stock::allocation((int) $opts['replace_allocation_id']);
                if ($alloc && (int) $alloc['demand_id'] === (int) $demand_id) {
                    CJS_Inv_DB::transaction(function () use ($alloc) {
                        CJS_Inv_Stock::release_allocation_qty($alloc, (float) $alloc['qty'], __('Swapped for another piece', 'custom-jewelry-system'));
                    });
                }
            }
        }
        global $wpdb;
        $last = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE demand_id = %d', (int) $demand_id));
        $got = CJS_Inv_DB::transaction(function () use ($demand_id, $opts, $rules, $last, $wpdb) {
            $got = CJS_Inv_Stock::allocate_demand((int) $demand_id, $opts);
            if ($got > 0 && $rules['returnable']) {
                $fresh = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND id > %d AND state = 'reserved' ORDER BY id", (int) $demand_id, $last), ARRAY_A) ?: [];
                foreach ($fresh as $a) {
                    CJS_Inv_Stock::send_core($a, __('Sent', 'custom-jewelry-system'));
                }
            }
            return $got;
        });
        if ($got <= 0) {
            return new WP_Error('cjs_no_stock', __('No available stock to assign.', 'custom-jewelry-system'));
        }
        $row = self::row((int) $demand_id);
        if ($row) {
            CJS_Inv_Flow::settle_line($row, CJS_Inv_Flow::order_status_key((int) $row['order_id']));
        }
        self::close_order_discrepancy((int) $demand['order_id']);
        return $got;
    }
}
