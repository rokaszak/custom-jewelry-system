<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Settings {

    const LIST_TYPES = ['manufacturing_statuses', 'order_types'];

    public static function statuses_list() {
        $out = [];
        foreach (CJS_Inv_Stock_Orders::statuses() as $key => $s) {
            $out[] = ['key' => $key, 'label' => $s['label'], 'color' => $s['color']];
        }
        return $out;
    }

    public static function product_categories() {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(function ($t) {
            return ['id' => (int) $t->term_id, 'name' => html_entity_decode($t->name), 'parent' => (int) $t->parent, 'count' => (int) $t->count];
        }, $terms);
    }

    public static function all() {
        $deadlines = CJS_Deadlines::get_settings();
        $today = CJS_Deadlines::today();
        $example = CJS_Deadlines::default_dates($today);
        $map = get_option('cjs_order_type_category_map', []);
        $auto = get_option('cjs_order_type_auto_assign_enabled', null);
        $kit_cat = CJS_Inv_Catalog::category_by_slug('sizing-kits');
        $kit_offer = CJS_Size_Kit::kit_offer();
        return [
            'manufacturing_statuses' => CJS_Statuses::label_list(),
            'statuses' => CJS_Statuses::all(),
            'order_types' => array_values(CJS_Order_Extension::get_ordered_options('order_types')),
            'stock_order_statuses' => self::statuses_list(),
            'size_kit' => [
                'enabled' => (bool) $kit_offer['enabled'],
                'auto_assign' => $kit_cat ? (int) $kit_cat['auto_assign'] === 1 : false,
                'categories' => $kit_offer['product_categories'],
                'modal_text' => (string) $kit_offer['modal_text'],
                'items' => $kit_offer['items'],
            ],
            'order_type_assign' => [
                'enabled' => $auto === null ? true : (bool) $auto,
                'map' => is_array($map) ? array_map(function ($ids) { return array_map('intval', (array) $ids); }, $map) : [],
            ],
            'deadlines' => $deadlines,
            'deadline_example' => $example,
            'product_categories' => self::product_categories(),
            'kit_items' => self::kit_items(),
        ];
    }

    public static function kit_items() {
        $cat = CJS_Orders::kit_category_id();
        if (!$cat) {
            return [];
        }
        $list = CJS_Inv_Catalog::list_items(['category_id' => $cat, 'per_page' => 200]);
        return array_map(function ($i) {
            return ['id' => $i['id'], 'category_id' => (int) ($i['category_id'] ?? 0), 'name' => $i['name'], 'code' => $i['code'], 'free' => $i['free']];
        }, $list['items']);
    }

    public static function save_list($type, array $values) {
        global $wpdb;
        if (!in_array($type, self::LIST_TYPES, true)) {
            return new WP_Error('cjs_invalid', __('Unknown list.', 'custom-jewelry-system'));
        }
        if ($type === 'manufacturing_statuses') {
            return new WP_Error('cjs_invalid', __('Statuses are fixed. They cannot be added or removed, only renamed.', 'custom-jewelry-system'));
        }
        $clean = [];
        foreach ($values as $v) {
            $v = trim(sanitize_text_field(is_array($v) ? ($v['value'] ?? '') : (string) $v));
            if ($v !== '' && !in_array($v, $clean, true)) {
                $clean[] = $v;
            }
        }
        if ($type === 'order_types' && !$clean) {
            return new WP_Error('cjs_invalid', __('At least one order type is required.', 'custom-jewelry-system'));
        }
        update_option('cjs_' . $type, $clean);
        $table = $wpdb->prefix . 'cjs_options_sort_order';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $wpdb->delete($table, ['option_type' => $type]);
        }
        CJS_Logger::log('Options updated for ' . $type, 'info', 'settings', null, ['values' => $clean]);
        return self::all();
    }

    public static function save_size_kit(array $data) {
        $offer = [
            'enabled' => !empty($data['enabled']) && $data['enabled'] !== 'false',
            'product_categories' => (array) ($data['categories'] ?? []),
            'modal_text' => (string) ($data['modal_text'] ?? ''),
            'items' => (array) ($data['items'] ?? []),
        ];
        $auto = array_key_exists('auto_assign', $data) ? !empty($data['auto_assign']) && $data['auto_assign'] !== 'false' : null;
        $saved = CJS_Size_Kit::save_kit_offer($offer, $auto);
        if (is_wp_error($saved)) {
            return $saved;
        }
        if ($auto && class_exists('CJS_Inv_Demands') && !CJS_Inv_DB::missing_tables()) {
            try {
                global $wpdb;
                $kit_ids = array_map('intval', $wpdb->get_col('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE returnable = 1 AND archived = 0') ?: []);
                if ($kit_ids) {
                    CJS_Inv_Demands::reserve_pending($kit_ids);
                }
            } catch (Throwable $e) {
                error_log('CJS size kit auto assign failed: ' . $e->getMessage());
            }
        }
        CJS_Logger::log('Size kit settings updated', 'info', 'settings');
        return self::all();
    }

    public static function save_statuses(array $data) {
        $saved = CJS_Statuses::save_labels($data['labels'] ?? ($data['statuses'] ?? null));
        return is_wp_error($saved) ? $saved : self::all();
    }

    public static function save_order_type_map(array $data) {
        $types = array_values(CJS_Order_Extension::get_ordered_options('order_types'));
        $raw = (array) ($data['map'] ?? []);
        $used = [];
        $map = [];
        foreach ($types as $type) {
            $ids = array_values(array_filter(array_map('absint', (array) ($raw[$type] ?? []))));
            $ids = array_values(array_diff($ids, $used));
            if ($ids) {
                $map[$type] = $ids;
                $used = array_merge($used, $ids);
            }
        }
        update_option('cjs_order_type_auto_assign_enabled', !empty($data['enabled']));
        update_option('cjs_order_type_category_map', $map);
        CJS_Logger::log('Order type settings updated', 'info', 'settings', null, ['map' => $map]);
        return self::all();
    }

    public static function save_deadlines(array $data) {
        CJS_Deadlines::save_settings([
            'checkout_enabled' => !empty($data['checkout_enabled']) ? 1 : 0,
            'min_days' => absint($data['min_days'] ?? 0),
            'max_days' => absint($data['max_days'] ?? 0),
            'default_days' => max(1, absint($data['default_days'] ?? 84)),
        ]);
        CJS_Logger::log('Terminų nustatymai atnaujinti', 'info', 'settings', null, CJS_Deadlines::get_settings());
        return self::all();
    }

    public static function log(array $args) {
        $result = CJS_Logger::get_logs([
            'page' => max(1, absint($args['page'] ?? 1)),
            'per_page' => 50,
            'severity' => !empty($args['severity']) ? sanitize_key($args['severity']) : null,
            'search' => !empty($args['search']) ? sanitize_text_field($args['search']) : null,
        ]);
        $avatars = [];
        return [
            'logs' => array_map(function ($l) use (&$avatars) {
                $details = json_decode((string) $l->details, true);
                $uid = (int) $l->user_id;
                if ($uid && !array_key_exists($uid, $avatars)) {
                    $url = get_avatar_url($uid, ['size' => 64, 'default' => '404']);
                    $avatars[$uid] = is_string($url) && $url !== '' ? $url : null;
                }
                return [
                    'id' => (int) $l->id,
                    'created_at' => $l->created_at,
                    'user_id' => $uid,
                    'avatar' => $uid ? $avatars[$uid] : null,
                    'user' => $l->user_name ?: ($l->user_id ? '#' . $l->user_id : __('System', 'custom-jewelry-system')),
                    'action' => $l->action,
                    'object_type' => $l->object_type,
                    'object_id' => $l->object_id !== null ? (int) $l->object_id : null,
                    'severity' => $l->severity,
                    'details' => $details !== null ? $details : $l->details,
                ];
            }, $result['logs'] ?: []),
            'total' => (int) $result['total'],
            'pages' => (int) $result['pages'],
        ];
    }
}
