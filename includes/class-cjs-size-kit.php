<?php
/**
 * Size Kit Checkout - modal consent and settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Size_Kit {

    const KIT_SLUG = 'sizing-kits';
    const MOVED_OPTION = 'cjs_kit_settings_moved';
    const KIT_CHECKBOX_TEXT = 'Noriu gauti <strong>Žiedų dydžio matavimo rinkinį</strong> ir sutinku su <a href="https://juvelyrasjuozas.lt/siuntimo-grazinimo-taisykles/" target="_blank">5. Žiedų dydžio matavimo rinkinio naudojimo ir grąžinimo sąlygomis</a>.';
    const KIT_MODAL_TITLE = 'Matavimo rinkinio sąlygos';
    const KIT_WOO_STATUSES = ['processing', 'partially-paid'];
    const KIT_BACKFILL_STATUSES = ['processing', 'partially-paid', 'on-hold', 'pending'];

    private static $offers = null;

    /**
     * Initialize hooks (frontend + admin)
     */
    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_after_checkout_billing_form', [__CLASS__, 'checkout_display'], 10, 1);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_order_meta'], 10, 2);
        add_filter('cjs_inv_order_requirements', [__CLASS__, 'add_requirement'], 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_notice'], 10, 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
    }

    public static function flush() {
        self::$offers = null;
    }

    public static function is_kit($cat) {
        return is_array($cat) && (string) ($cat['slug'] ?? '') === self::KIT_SLUG;
    }

    public static function offer_defaults() {
        return [
            'enabled' => false,
            'product_categories' => [],
            'checkbox_text' => '',
            'modal_title' => '',
            'modal_text' => '',
            'items' => [],
            'assign' => 'add',
        ];
    }

    public static function legacy_kit_offer() {
        return [
            'enabled' => (bool) get_option('cjs_size_kit_enabled', false),
            'product_categories' => self::ids(get_option('cjs_size_kit_categories', [])),
            'checkbox_text' => self::KIT_CHECKBOX_TEXT,
            'modal_title' => self::KIT_MODAL_TITLE,
            'modal_text' => (string) get_option('cjs_size_kit_modal_text', ''),
            'items' => self::ids(get_option('cjs_size_kit_items', [])),
            'assign' => 'add',
        ];
    }

    private static function ids($value) {
        return array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : []))));
    }

    public static function offer_of($cat) {
        $cat = is_array($cat) ? $cat : [];
        $raw = $cat['checkout_offer'] ?? null;
        if (($raw === null || $raw === '') && self::is_kit($cat) && !get_option(self::MOVED_OPTION)) {
            return self::legacy_kit_offer();
        }
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];
        return [
            'enabled' => !empty($data['enabled']),
            'product_categories' => self::ids($data['product_categories'] ?? []),
            'checkbox_text' => (string) ($data['checkbox_text'] ?? ''),
            'modal_title' => (string) ($data['modal_title'] ?? ''),
            'modal_text' => (string) ($data['modal_text'] ?? ''),
            'items' => self::ids($data['items'] ?? []),
            'assign' => ($data['assign'] ?? '') === 'reserve' ? 'reserve' : 'add',
        ];
    }

    public static function clean_offer($input, $cat = null) {
        if (is_string($input)) {
            $decoded = json_decode(wp_unslash($input), true);
            $input = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($input)) {
            return new WP_Error('cjs_invalid', __('The checkout offer is invalid.', 'custom-jewelry-system'));
        }
        $out = is_array($cat) ? self::offer_of($cat) : self::offer_defaults();
        if (array_key_exists('enabled', $input)) {
            $out['enabled'] = !empty($input['enabled']) && $input['enabled'] !== 'false';
        }
        if (array_key_exists('product_categories', $input)) {
            $out['product_categories'] = self::ids($input['product_categories']);
        }
        if (array_key_exists('checkbox_text', $input)) {
            $out['checkbox_text'] = wp_kses_post((string) $input['checkbox_text']);
        }
        if (array_key_exists('modal_title', $input)) {
            $out['modal_title'] = sanitize_text_field((string) $input['modal_title']);
        }
        if (array_key_exists('modal_text', $input)) {
            $out['modal_text'] = wp_kses_post((string) $input['modal_text']);
        }
        if (array_key_exists('items', $input)) {
            $out['items'] = self::ids($input['items']);
        }
        if (array_key_exists('assign', $input)) {
            $out['assign'] = $input['assign'] === 'reserve' ? 'reserve' : 'add';
        }
        return $out;
    }

    public static function offers() {
        if (self::$offers !== null) {
            return self::$offers;
        }
        self::$offers = [];
        if (!class_exists('CJS_Inv_DB')) {
            return self::$offers;
        }
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('categories') . ' WHERE archived = 0 ORDER BY sort_order ASC, id ASC', ARRAY_A) ?: [];
        $wpdb->suppress_errors($suppress);
        foreach ($rows as $cat) {
            if ((int) ($cat['returnable'] ?? 0) !== 1) {
                continue;
            }
            $raw = $cat['checkout_offer'] ?? null;
            if (($raw !== null && $raw !== '') || self::is_kit($cat)) {
                self::$offers[] = ['category' => $cat, 'offer' => self::offer_of($cat)];
            }
        }
        return self::$offers;
    }

    public static function kit_offer() {
        $cat = class_exists('CJS_Inv_Catalog') ? CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG) : null;
        return $cat ? self::offer_of($cat) : self::legacy_kit_offer();
    }

    public static function save_offer($category_id, $offer) {
        $cat = CJS_Inv_Catalog::category((int) $category_id);
        if (!$cat) {
            return new WP_Error('cjs_not_found', __('Category not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        if ((int) $cat['returnable'] !== 1) {
            return new WP_Error('cjs_invalid', __('Only returnable categories can have a checkout offer.', 'custom-jewelry-system'));
        }
        $clean = self::clean_offer($offer, $cat);
        if (is_wp_error($clean)) {
            return $clean;
        }
        CJS_Inv_DB::update('categories', ['checkout_offer' => wp_json_encode($clean), 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $cat['id']]);
        CJS_Inv_Flow::flush();
        self::flush();
        CJS_Logger::log('Checkout offer saved', 'info', 'settings', (int) $cat['id'], ['offer' => $clean]);
        return true;
    }

    public static function save_kit_offer(array $offer, $auto_assign = null) {
        $cat = CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG);
        if (!$cat) {
            return new WP_Error('cjs_not_found', __('The Sizing kits category was not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $clean = self::clean_offer($offer, $cat);
        if (is_wp_error($clean)) {
            return $clean;
        }
        $row = ['checkout_offer' => wp_json_encode($clean), 'updated_at' => CJS_Inv_DB::now()];
        if ($auto_assign !== null) {
            $row['auto_assign'] = $auto_assign ? 1 : 0;
        }
        CJS_Inv_DB::update('categories', $row, ['id' => (int) $cat['id']]);
        CJS_Inv_Flow::flush();
        self::flush();
        return true;
    }

    public static function maybe_move_settings() {
        if (get_option(self::MOVED_OPTION)) {
            return;
        }
        try {
            if ((int) get_option(CJS_Inv_Seed::VERSION_OPTION, 0) < 3 || (int) get_option(CJS_Inv_DB::SCHEMA_OPTION, 0) < 4) {
                return;
            }
            $cat = CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG);
            if (!$cat || !array_key_exists('checkout_offer', $cat)) {
                return;
            }
            $offer = self::legacy_kit_offer();
            $row = ['assign_woo_statuses' => implode(',', self::KIT_WOO_STATUSES), 'updated_at' => CJS_Inv_DB::now()];
            if ($cat['checkout_offer'] === null || $cat['checkout_offer'] === '') {
                $row['checkout_offer'] = wp_json_encode($offer);
            }
            $auto = get_option('cjs_size_kit_auto_assign', null);
            if ($auto !== null) {
                $row['auto_assign'] = $auto ? 1 : 0;
            }
            CJS_Inv_DB::update('categories', $row, ['id' => (int) $cat['id']]);
            update_option(self::MOVED_OPTION, 1, true);
            CJS_Inv_Flow::flush();
            self::flush();
            CJS_Logger::log('Sizing kit settings moved into the Sizing kits category', 'info', 'settings', null, ['offer' => $offer, 'auto_assign' => $auto]);
        } catch (Throwable $e) {
            error_log('CJS sizing kit settings move failed: ' . $e->getMessage());
        }
    }

    public static function field_name(array $cat) {
        return self::is_kit($cat) ? 'size_kit_checkbox' : 'cjs_offer_' . (int) $cat['id'];
    }

    public static function meta_key(array $cat) {
        return self::is_kit($cat) ? 'size_kit_requested' : 'cjs_offer_' . (int) $cat['id'] . '_requested';
    }

    private static function dom_id(array $cat, $part) {
        if (self::is_kit($cat)) {
            $kit = ['wrap' => 'size_kit_checkout_field', 'modal' => 'cjs-size-kit-modal', 'title' => 'cjs-size-kit-modal-title'];
            return $kit[$part];
        }
        $id = (int) $cat['id'];
        $other = ['wrap' => 'cjs_offer_' . $id . '_checkout_field', 'modal' => 'cjs-offer-' . $id . '-modal', 'title' => 'cjs-offer-' . $id . '-modal-title'];
        return $other[$part];
    }

    public static function add_requirement($requirements, $order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return $requirements;
        }
        global $wpdb;
        foreach (self::offers() as $o) {
            $cat = $o['category'];
            if ($order->get_meta(self::meta_key($cat)) !== 'yes') {
                continue;
            }
            $has = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE d.order_id = %d AND i.category_id = %d AND d.state <> 'cancelled'", $order->get_id(), (int) $cat['id']));
            if ($has > 0) {
                continue;
            }
            $item_id = self::pick_item($cat, $o['offer']);
            if (!$item_id) {
                continue;
            }
            $kit = self::is_kit($cat);
            $requirements[] = [
                'item_id' => $item_id,
                'qty' => 1,
                'scope' => 'order',
                'source' => $kit ? 'size_kit' : 'checkout_offer',
                'source_ref' => $kit ? 'size_kit' : 'offer:' . (int) $cat['id'],
            ];
        }
        return $requirements;
    }

    public static function requested_open_order_ids() {
        global $wpdb;
        $statuses = implode(',', array_map(function ($s) use ($wpdb) { return $wpdb->prepare('%s', 'wc-' . $s); }, self::KIT_BACKFILL_STATUSES));
        if (CJS_Orders::hpos()) {
            $sql = "SELECT DISTINCT o.id FROM {$wpdb->prefix}wc_orders o INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = 'size_kit_requested' AND m.meta_value = 'yes' WHERE o.type = 'shop_order' AND o.status IN ($statuses) ORDER BY o.id";
        } else {
            $sql = "SELECT DISTINCT o.ID FROM {$wpdb->posts} o INNER JOIN {$wpdb->postmeta} m ON m.post_id = o.ID AND m.meta_key = 'size_kit_requested' AND m.meta_value = 'yes' WHERE o.post_type = 'shop_order' AND o.post_status IN ($statuses) ORDER BY o.ID";
        }
        return array_map('intval', $wpdb->get_col($sql) ?: []);
    }

    public static function add_missing_kit_line($order_id) {
        global $wpdb;
        $order = wc_get_order((int) $order_id);
        if (!$order || $order->get_meta('size_kit_requested') !== 'yes' || !in_array($order->get_status(), self::KIT_BACKFILL_STATUSES, true)) {
            return 0;
        }
        $cat = CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG);
        if (!$cat) {
            return 0;
        }
        $has = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = d.item_id WHERE d.order_id = %d AND i.category_id = %d', $order->get_id(), (int) $cat['id']));
        if ($has > 0) {
            return 0;
        }
        $reqs = array_values(array_filter(self::add_requirement([], $order), function ($r) {
            return ($r['source'] ?? '') === 'size_kit';
        }));
        $n = 0;
        foreach (CJS_Inv_Demands::merge_order_level($reqs) as $req) {
            CJS_Inv_Demands::insert($order->get_id(), null, (int) $req['item_id'], (float) $req['qty'], [
                'source' => $req['source'],
                'source_ref' => $req['source_ref'] ?? null,
            ]);
            $n++;
        }
        return $n;
    }

    public static function order_kit_state($order) {
        global $wpdb;
        $order_id = is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : absint($order);
        if (!$order_id) {
            return 'none';
        }
        $kit_cat = CJS_Orders::kit_category_id();
        if (!$kit_cat) {
            return 'none';
        }
        $states = $wpdb->get_col($wpdb->prepare(
            'SELECT a.state FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('demands') . ' d ON d.id = a.demand_id INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id
             WHERE d.order_id = %d AND i.category_id = %d AND d.state <> 'cancelled' AND a.state IN ('reserved','out','used','returned')",
            $order_id, $kit_cat
        )) ?: [];
        if (in_array('out', $states, true)) {
            return 'sent';
        }
        if (in_array('reserved', $states, true)) {
            return 'to_send';
        }
        if (in_array('returned', $states, true) || in_array('used', $states, true)) {
            return 'returned';
        }
        $lines = $wpdb->get_results($wpdb->prepare(
            'SELECT d.state, d.qty, d.qty_allocated FROM ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE d.order_id = %d AND i.category_id = %d AND d.state <> 'cancelled'",
            $order_id, $kit_cat
        ), ARRAY_A) ?: [];
        foreach ($lines as $l) {
            if ($l['state'] === 'open' && (float) $l['qty_allocated'] < (float) $l['qty']) {
                return 'requested_missing';
            }
        }
        if ($lines) {
            return 'none';
        }
        $wc = is_object($order) && method_exists($order, 'get_meta') ? $order : (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
        if (!$wc || $wc->get_meta('size_kit_requested') !== 'yes') {
            return 'none';
        }
        if (in_array($wc->get_status(), array_merge(CJS_Inv_Demands::CLOSED_STATUSES, ['completed']), true)) {
            return 'none';
        }
        $status = (string) $wpdb->get_var($wpdb->prepare('SELECT manufacturing_status FROM ' . $wpdb->prefix . 'cjs_order_extensions WHERE order_id = %d', $order_id));
        return in_array($status, CJS_Orders::EARLY_STATUSES, true) ? 'requested_missing' : 'none';
    }

    public static function state_sql($state, $order_id_expr, $meta_table, $meta_fk, $status_expr = "COALESCE(e.manufacturing_status, '')") {
        global $wpdb;
        $kit_cat = (int) CJS_Orders::kit_category_id();
        $d = CJS_Inv_DB::t('demands');
        $a = CJS_Inv_DB::t('allocations');
        $i = CJS_Inv_DB::t('items');
        $alloc = function (array $states) use ($d, $a, $i, $kit_cat, $order_id_expr) {
            return "EXISTS (SELECT 1 FROM $d kd INNER JOIN $a ka ON ka.demand_id = kd.id INNER JOIN $i ki ON ki.id = kd.item_id WHERE kd.order_id = $order_id_expr AND ki.category_id = $kit_cat AND kd.state <> 'cancelled' AND ka.state IN ('" . implode("','", $states) . "'))";
        };
        $sent = $alloc(['out']);
        $to_send = $alloc(['reserved']);
        $returned = $alloc(['returned', 'used']);
        $any = $alloc(['reserved', 'out', 'used', 'returned']);
        $open_missing = "EXISTS (SELECT 1 FROM $d kd INNER JOIN $i ki ON ki.id = kd.item_id WHERE kd.order_id = $order_id_expr AND ki.category_id = $kit_cat AND kd.state = 'open' AND kd.qty_allocated < kd.qty)";
        $no_line = "NOT EXISTS (SELECT 1 FROM $d kd INNER JOIN $i ki ON ki.id = kd.item_id WHERE kd.order_id = $order_id_expr AND ki.category_id = $kit_cat AND kd.state <> 'cancelled')";
        $early = implode(',', array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', $v); }, CJS_Orders::EARLY_STATUSES));
        $requested = "EXISTS (SELECT 1 FROM $meta_table km WHERE km.$meta_fk = $order_id_expr AND km.meta_key = 'size_kit_requested' AND km.meta_value = 'yes')";
        switch ($state) {
            case 'sent':
                return $sent;
            case 'to_send':
                return "($to_send AND NOT $sent)";
            case 'returned':
                return "($returned AND NOT " . $alloc(['reserved', 'out']) . ')';
            case 'has':
                return $any;
            case 'requested_missing':
            case 'missing':
                return "(NOT $any AND ($open_missing OR ($no_line AND $requested AND $status_expr IN ($early))))";
            case 'none':
                return "(NOT $any AND NOT $open_missing AND NOT ($no_line AND $requested AND $status_expr IN ($early)))";
        }
        return '1=1';
    }

    public static function offer_items(array $cat, array $offer) {
        global $wpdb;
        $ids = self::ids($offer['items'] ?? []);
        if ($ids) {
            $live = array_map('intval', $wpdb->get_col('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE archived = 0 AND id IN (' . CJS_Inv_DB::ids_in($ids) . ')'));
            $ids = array_values(array_filter($ids, function ($id) use ($live) { return in_array($id, $live, true); }));
            if ($ids) {
                return $ids;
            }
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND archived = 0 ORDER BY id ASC', (int) $cat['id'])));
    }

    public static function pick_item(array $cat, array $offer) {
        global $wpdb;
        $ids = self::offer_items($cat, $offer);
        foreach ($ids as $id) {
            $item = CJS_Inv_Catalog::item_row($id);
            if (!$item) {
                continue;
            }
            if ((int) $item['per_piece'] === 1) {
                $free = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('pieces') . " WHERE item_id = %d AND status = 'in'", $id));
            } else {
                $free = (float) $item['qty_on_hand'] - (float) $item['qty_reserved'];
            }
            if ($free > 0) {
                return $id;
            }
        }
        return $ids ? $ids[0] : 0;
    }

    public static function kit_items() {
        $cat = CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG);
        return $cat ? self::offer_items($cat, self::offer_of($cat)) : [];
    }

    public static function pick_kit_item() {
        $cat = CJS_Inv_Catalog::category_by_slug(self::KIT_SLUG);
        return $cat ? self::pick_item($cat, self::offer_of($cat)) : 0;
    }

    /**
     * Check if the size kit feature is enabled
     */
    public static function is_enabled() {
        return !empty(self::kit_offer()['enabled']);
    }

    /**
     * Get category IDs that qualify for the size kit
     */
    public static function get_qualifying_categories() {
        return self::kit_offer()['product_categories'];
    }

    private static function cart_has_product_in(array $categories) {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        if (empty($categories)) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            $product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
            foreach ($categories as $term_id) {
                if (has_term($term_id, 'product_cat', $product_id)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function shown_offers() {
        $out = [];
        foreach (self::offers() as $o) {
            if (!empty($o['offer']['enabled']) && self::cart_has_product_in($o['offer']['product_categories'])) {
                $out[] = $o;
            }
        }
        return $out;
    }

    /**
     * Display size kit checkbox and modal on checkout (below billing)
     */
    public static function checkout_display($checkout) {
        foreach (self::shown_offers() as $o) {
            self::render_offer($checkout, $o['category'], $o['offer']);
        }
    }

    private static function render_offer($checkout, array $cat, array $offer) {
        $modal_text = (string) $offer['modal_text'];
        if (trim($modal_text) === '') {
            $modal_text = __('Missing Text, Check Settings to add', 'custom-jewelry-system');
        }
        $title = trim((string) $offer['modal_title']);
        if ($title === '') {
            $title = self::is_kit($cat) ? self::KIT_MODAL_TITLE : (string) $cat['name'];
        }
        $field = self::field_name($cat);
        $modal = self::dom_id($cat, 'modal');
        $title_id = self::dom_id($cat, 'title');

        echo '<div id="' . esc_attr(self::dom_id($cat, 'wrap')) . '" class="cjs-offer-field" data-cjs-field="' . esc_attr($field) . '" data-cjs-modal="' . esc_attr($modal) . '">';
        woocommerce_form_field($field, [
            'type'          => 'checkbox',
            'class'         => ['form-row-wide'],
            'label_class'   => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class'   => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'required'      => false,
            'label'         => $offer['checkbox_text'],
        ], $checkout->get_value($field));
        echo '</div>';

        echo '<div id="' . esc_attr($modal) . '" class="cjs-size-kit-modal" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($title_id) . '" hidden>';
        echo '<div class="cjs-size-kit-modal-backdrop"></div>';
        echo '<div class="cjs-size-kit-modal-panel">';
        echo '<h2 id="' . esc_attr($title_id) . '" class="cjs-size-kit-modal-title">' . esc_html($title) . '</h2>';
        echo '<div class="cjs-size-kit-modal-content">' . wp_kses_post($modal_text) . '</div>';
        echo '<div class="cjs-size-kit-modal-actions">';
        echo '<button type="button" class="button cjs-size-kit-btn-decline">' . esc_html__('Nesutinku', 'custom-jewelry-system') . '</button>';
        echo '<button type="button" class="button button-primary cjs-size-kit-btn-accept">' . esc_html__('Sutinku', 'custom-jewelry-system') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Save checkbox value to order meta (HPOS compatible)
     */
    public static function save_order_meta($order_id, $data = null) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $changed = false;
        foreach (self::offers() as $o) {
            $field = self::field_name($o['category']);
            if (isset($_POST[$field]) && $_POST[$field]) {
                $order->update_meta_data(self::meta_key($o['category']), 'yes');
                $changed = true;
            }
        }
        if ($changed) {
            $order->save();
        }
    }

    /**
     * Display size kit notice in admin order view (HPOS compatible)
     */
    public static function display_admin_notice($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        foreach (self::offers() as $o) {
            $cat = $o['category'];
            if ($order->get_meta(self::meta_key($cat)) !== 'yes') {
                continue;
            }
            if (self::is_kit($cat)) {
                echo '<p><strong>' . esc_html__('Klientas pasirinko gauti Žiedų dydžio matavimo rinkinį.', 'custom-jewelry-system') . '</strong></p>';
            } else {
                echo '<p><strong>' . esc_html(sprintf(__('The customer asked for %s at checkout.', 'custom-jewelry-system'), (string) $cat['name'])) . '</strong></p>';
            }
        }
    }

    /**
     * Enqueue checkout assets only when feature is enabled and on checkout
     */
    public static function enqueue_assets() {
        if (is_admin()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!self::shown_offers()) {
            return;
        }

        wp_enqueue_style(
            'cjs-size-kit-checkout',
            CJS_PLUGIN_URL . 'assets/css/size-kit-checkout.css',
            [],
            CJS_VERSION
        );
        wp_enqueue_script(
            'cjs-size-kit-checkout',
            CJS_PLUGIN_URL . 'assets/js/size-kit-checkout.js',
            ['jquery'],
            CJS_VERSION,
            true
        );
    }
}
