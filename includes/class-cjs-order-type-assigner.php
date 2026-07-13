<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Order_Type_Assigner {

    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_action('woocommerce_new_order', [__CLASS__, 'maybe_assign'], 20, 2);
    }

    public static function is_enabled() {
        $value = get_option('cjs_order_type_auto_assign_enabled', null);
        if ($value === null) {
            return true;
        }
        return (bool) $value;
    }

    public static function get_category_map() {
        $map = get_option('cjs_order_type_category_map', []);
        if (!is_array($map)) {
            return [];
        }
        $types = get_option('cjs_order_types', []);
        if (!is_array($types)) {
            $types = [];
        }
        $clean = [];
        foreach ($map as $type => $term_ids) {
            if (!in_array($type, $types, true) || !is_array($term_ids)) {
                continue;
            }
            $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
            if (!empty($term_ids)) {
                $clean[$type] = $term_ids;
            }
        }
        return $clean;
    }

    public static function maybe_assign($order_id, $order = null) {
        if (!self::is_enabled()) {
            return;
        }
        $order = $order ? $order : wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $map = self::get_category_map();
        if (empty($map)) {
            return;
        }

        $term_type = [];
        $lookup = [];
        foreach ($map as $type => $term_ids) {
            foreach ($term_ids as $term_id) {
                $term_type[$term_id] = $type;
                $lookup[$term_id] = $term_id;
            }
        }
        foreach ($term_type as $term_id => $type) {
            $children = get_term_children($term_id, 'product_cat');
            if (is_wp_error($children)) {
                continue;
            }
            foreach ($children as $child_id) {
                if (!isset($lookup[$child_id])) {
                    $lookup[$child_id] = $term_id;
                }
            }
        }

        $counts = [];
        $max_totals = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }
            $product_id = $item->get_product_id();
            if (!$product_id) {
                continue;
            }
            $qty = max(1, (int) $item->get_quantity());
            $line_total = (float) $item->get_total();
            $product_terms = wc_get_product_term_ids($product_id, 'product_cat');
            $matched = [];
            foreach ($product_terms as $product_term) {
                if (isset($lookup[$product_term])) {
                    $matched[$lookup[$product_term]] = true;
                }
            }
            foreach (array_keys($matched) as $mapped_term) {
                $counts[$mapped_term] = ($counts[$mapped_term] ?? 0) + $qty;
                $max_totals[$mapped_term] = max($max_totals[$mapped_term] ?? 0.0, $line_total);
            }
        }

        if (empty($counts)) {
            return;
        }

        $winner = null;
        foreach (array_keys($counts) as $term_id) {
            if ($winner === null) {
                $winner = $term_id;
                continue;
            }
            if ($counts[$term_id] > $counts[$winner]) {
                $winner = $term_id;
                continue;
            }
            if ($counts[$term_id] < $counts[$winner]) {
                continue;
            }
            $candidate_depth = count(get_ancestors($term_id, 'product_cat'));
            $winner_depth = count(get_ancestors($winner, 'product_cat'));
            if ($candidate_depth < $winner_depth) {
                $winner = $term_id;
                continue;
            }
            if ($candidate_depth > $winner_depth) {
                continue;
            }
            if ($max_totals[$term_id] > $max_totals[$winner]) {
                $winner = $term_id;
            }
        }

        $type = $term_type[$winner];
        CJS_Order_Extension::update_order_extension($order_id, ['order_type' => $type]);

        if (class_exists('CJS_Logger')) {
            CJS_Logger::log('Order type auto-assigned', 'info', 'order', $order_id, [
                'order_type' => $type,
                'category_id' => $winner
            ]);
        }
    }
}
