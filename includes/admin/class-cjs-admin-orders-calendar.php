<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Orders_Calendar {

    public static function enqueue_assets() {
        wp_enqueue_style(
            'cjs-orders-calendar',
            CJS_PLUGIN_URL . 'assets/css/orders-calendar.css',
            ['cjs-admin'],
            filemtime(CJS_PLUGIN_DIR . 'assets/css/orders-calendar.css')
        );

        wp_enqueue_script(
            'cjs-orders-calendar',
            CJS_PLUGIN_URL . 'assets/js/orders-calendar.js',
            ['jquery', 'cjs-admin'],
            filemtime(CJS_PLUGIN_DIR . 'assets/js/orders-calendar.js'),
            true
        );

        wp_localize_script('cjs-orders-calendar', 'cjs_orders_calendar_data', [
            'orders' => self::get_orders(),
            'statuses' => CJS_Order_Extension::get_ordered_manufacturing_statuses(),
            'today' => current_time('Y-m-d'),
            'orders_url' => admin_url('admin.php?page=cjs-orders-list'),
            'labels' => [
                'no_status' => __('(Be statuso)', 'custom-jewelry-system'),
                'no_orders' => __('Nėra užsakymų pagal pasirinktus filtrus', 'custom-jewelry-system'),
                'ordered' => __('Užsakyta', 'custom-jewelry-system'),
                'manufacture_by' => __('Pagaminti iki', 'custom-jewelry-system'),
                'finish_by' => __('Užprabuoti iki', 'custom-jewelry-system'),
                'deliver_by' => __('Pristatyti iki', 'custom-jewelry-system'),
                'status' => __('Statusas', 'custom-jewelry-system'),
                'hours' => __('Valandos', 'custom-jewelry-system'),
                'orders_1' => __('užsakymas', 'custom-jewelry-system'),
                'orders_few' => __('užsakymai', 'custom-jewelry-system'),
                'orders_many' => __('užsakymų', 'custom-jewelry-system'),
                'assigned_short' => __('paskirta', 'custom-jewelry-system'),
                'done_short' => __('pabaigta', 'custom-jewelry-system'),
                'left_short' => __('likę', 'custom-jewelry-system'),
                'months' => [
                    __('Sausis', 'custom-jewelry-system'),
                    __('Vasaris', 'custom-jewelry-system'),
                    __('Kovas', 'custom-jewelry-system'),
                    __('Balandis', 'custom-jewelry-system'),
                    __('Gegužė', 'custom-jewelry-system'),
                    __('Birželis', 'custom-jewelry-system'),
                    __('Liepa', 'custom-jewelry-system'),
                    __('Rugpjūtis', 'custom-jewelry-system'),
                    __('Rugsėjis', 'custom-jewelry-system'),
                    __('Spalis', 'custom-jewelry-system'),
                    __('Lapkritis', 'custom-jewelry-system'),
                    __('Gruodis', 'custom-jewelry-system')
                ],
                'weekdays' => ['Sk', 'Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št']
            ]
        ]);
    }

    private static function get_orders() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT order_id, manufacture_by_date, finish_by_date, deliver_by_date, manufacturing_status, assigned_hours, completed_hours
             FROM {$wpdb->prefix}cjs_order_extensions
             WHERE (manufacture_by_date IS NOT NULL AND manufacture_by_date != '0000-00-00')
                OR (finish_by_date IS NOT NULL AND finish_by_date != '0000-00-00')
                OR (deliver_by_date IS NOT NULL AND deliver_by_date != '0000-00-00')"
        );

        if (empty($rows)) {
            return [];
        }

        $by_order = [];
        foreach ($rows as $row) {
            $by_order[(int) $row->order_id] = $row;
        }

        $excluded = ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];
        $allowed = [];
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            if (!in_array($status, $excluded, true)) {
                $allowed[] = substr($status, 0, 3) === 'wc-' ? substr($status, 3) : $status;
            }
        }

        $wc_orders = wc_get_orders([
            'type' => 'shop_order',
            'status' => $allowed,
            'limit' => -1
        ]);

        $orders = [];
        foreach ($wc_orders as $wc_order) {
            if (!($wc_order instanceof WC_Order) || $wc_order->get_parent_id()) {
                continue;
            }
            $order_id = $wc_order->get_id();
            if (!isset($by_order[$order_id])) {
                continue;
            }
            $row = $by_order[$order_id];

            $created = $wc_order->get_date_created();
            $orders[] = [
                'id' => $order_id,
                'number' => (string) $wc_order->get_order_number(),
                'name' => $wc_order->get_formatted_billing_full_name(),
                'status' => (string) ($row->manufacturing_status ?? ''),
                'assigned' => ($row->assigned_hours === null || $row->assigned_hours === '') ? null : (float) $row->assigned_hours,
                'completed' => (float) $row->completed_hours,
                'start' => $created ? $created->date('Y-m-d') : current_time('Y-m-d'),
                'manufacture_by' => self::clean_date($row->manufacture_by_date),
                'finish_by' => self::clean_date($row->finish_by_date),
                'deliver_by' => self::clean_date($row->deliver_by_date)
            ];
        }

        return $orders;
    }

    private static function clean_date($value) {
        if (empty($value) || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap cjs-orders-calendar-wrap">
            <h1><?php _e('Užsakymų kalendorius', 'custom-jewelry-system'); ?></h1>

            <div class="cjs-oc-toolbar">
                <div class="cjs-oc-toolbar-main">
                    <span class="cjs-oc-section-label"><?php _e('Kalendoriaus funkcijos', 'custom-jewelry-system'); ?></span>
                    <button type="button" class="button" id="cjs-oc-today"><?php _e('Grįžti į šiandien', 'custom-jewelry-system'); ?></button>
                    <span class="cjs-oc-zoom">
                        <button type="button" class="button" id="cjs-oc-zoom-out" title="<?php esc_attr_e('Mažiau detalu (-)', 'custom-jewelry-system'); ?>">&minus;</button>
                        <span class="cjs-oc-zoom-level" id="cjs-oc-zoom-level"></span>
                        <button type="button" class="button" id="cjs-oc-zoom-in" title="<?php esc_attr_e('Daugiau detalu (+)', 'custom-jewelry-system'); ?>">+</button>
                    </span>
                    <span class="cjs-oc-legend">
                        <span class="cjs-oc-legend-item"><span class="cjs-oc-legend-swatch"></span><?php _e('Pagaminti iki', 'custom-jewelry-system'); ?></span>
                        <span class="cjs-oc-legend-item"><span class="cjs-oc-legend-swatch cjs-oc-seg-stripes" style="opacity:0.85;"></span><?php _e('Užprabuoti iki', 'custom-jewelry-system'); ?></span>
                        <span class="cjs-oc-legend-item"><span class="cjs-oc-legend-swatch cjs-oc-seg-dots" style="opacity:0.7;"></span><?php _e('Pristatyti iki', 'custom-jewelry-system'); ?></span>
                    </span>
                </div>
                <div class="cjs-oc-filter-row">
                    <span class="cjs-oc-section-label"><?php _e('Filtrai', 'custom-jewelry-system'); ?></span>
                    <div class="cjs-oc-filter" id="cjs-oc-filter"></div>
                </div>
            </div>

            <div class="cjs-oc-holder">
                <div class="cjs-oc-scroll" id="cjs-oc-scroll"></div>
            </div>
        </div>
        <?php
    }
}
