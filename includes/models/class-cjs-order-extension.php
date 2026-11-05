<?php
if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

class CJS_Order_Extension {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_order_columns']);
            add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_order_columns'], 10, 2);
        } else {
            add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_order_columns']);
            add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_order_columns'], 10, 2);
        }
        add_action('woocommerce_new_order', [__CLASS__, 'create_order_extension']);
        add_action('woocommerce_update_order', [__CLASS__, 'ensure_order_extension']);
        add_action('woocommerce_checkout_order_created', [__CLASS__, 'recalculate_checkout_hours']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_product_hours_field']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_hours_field']);
    }

    public static function ensure_order_extension($order_id) {
        self::create_order_extension($order_id);
    }

    public static function recalculate_checkout_hours($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $order_id = $order->get_id();
        self::create_order_extension($order_id);
        global $wpdb;
        $completed = (float) $wpdb->get_var($wpdb->prepare("SELECT completed_hours FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d", $order_id));
        if ($completed > 0) {
            return;
        }
        self::update_order_extension($order_id, ['assigned_hours' => self::calculate_default_hours($order)]);
    }

    public static function render_product_hours_field() {
        woocommerce_wp_text_input([
            'id' => '_cjs_work_hours',
            'label' => __('Gamybos valandos (h)', 'custom-jewelry-system'),
            'type' => 'number',
            'custom_attributes' => ['step' => '0.25', 'min' => '0'],
            'desc_tip' => true,
            'description' => __('Numatytos gamybos valandos vienam vienetui. Palikite tuščią, jei norite naudoti 10 val.', 'custom-jewelry-system')
        ]);
    }

    public static function save_product_hours_field($product) {
        $value = isset($_POST['_cjs_work_hours']) ? wc_clean(wp_unslash($_POST['_cjs_work_hours'])) : '';
        if ($value === '' || (float) $value <= 0) {
            $product->delete_meta_data('_cjs_work_hours');
        } else {
            $product->update_meta_data('_cjs_work_hours', (float) $value);
        }
    }

    public static function calculate_default_hours($order) {
        $total = 0;
        foreach ($order->get_items() as $item) {
            $quantity = max(1, (int) $item->get_quantity());
            $hours = 0;
            $product = $item->get_product();
            if ($product) {
                $meta = $product->get_meta('_cjs_work_hours');
                if ($meta === '' && $product->get_parent_id()) {
                    $parent = wc_get_product($product->get_parent_id());
                    if ($parent) {
                        $meta = $parent->get_meta('_cjs_work_hours');
                    }
                }
                $hours = (float) $meta;
            }
            if ($hours <= 0) {
                $hours = 10;
            }
            $total += $quantity * $hours;
        }
        return $total > 0 ? round($total, 2) : 10;
    }
    
    /**
     * Get order extension data - (handles NULL values and new field)
     */
    public static function get_order_extension($order_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
            $order_id
        ), ARRAY_A);
        
        if (!$data) {
            return [
                'manufacture_by_date' => '',
                'finish_by_date' => '',
                'deliver_by_date' => '',
                'order_model' => 0,
                'order_production' => 0,
                'casting_notes' => '',
                'order_printing' => 0,
                'manufacturing_status' => '',
                'order_type' => 'Įprastas',
                'assigned_hours' => '',
                'completed_hours' => 0
            ];
        }
        
        // Get order date to calculate default dates if needed
        $order = wc_get_order($order_id);
        $order_date = $order ? $order->get_date_created() : new DateTime();
        
        if (!$order_date) {
            $order_date = new DateTime();
        }
        
        $defaults = CJS_Deadlines::default_dates($order_date);
        $finish_by_date = $data['finish_by_date'] ?? '';
        $deliver_by_date = $data['deliver_by_date'] ?? '';

        if (empty($deliver_by_date) || $deliver_by_date === '0000-00-00' || $deliver_by_date === 'null') {
            $deliver_by_date = $defaults['deliver_by_date'];
            self::update_order_extension($order_id, ['deliver_by_date' => $deliver_by_date]);
        }

        if (empty($finish_by_date) || $finish_by_date === '0000-00-00' || $finish_by_date === 'null') {
            $finish_by_date = CJS_Deadlines::add_days($deliver_by_date, -CJS_Deadlines::FINISH_GAP_DAYS);
            self::update_order_extension($order_id, ['finish_by_date' => $finish_by_date]);
        }

        $manufacture_by_date = $data['manufacture_by_date'] ?? '';
        if (empty($manufacture_by_date) || $manufacture_by_date === '0000-00-00' || $manufacture_by_date === 'null') {
            $manufacture_by_date = CJS_Deadlines::add_days($finish_by_date, -CJS_Deadlines::FINISH_GAP_DAYS);
            self::update_order_extension($order_id, ['manufacture_by_date' => $manufacture_by_date]);
        }

        $assigned_hours = $data['assigned_hours'] ?? null;
        if (($assigned_hours === null || $assigned_hours === '') && $order) {
            $assigned_hours = self::calculate_default_hours($order);
            self::update_order_extension($order_id, ['assigned_hours' => $assigned_hours]);
        }

        // Ensure no NULL values
        return [
            'manufacture_by_date' => $manufacture_by_date,
            'finish_by_date' => $finish_by_date,
            'deliver_by_date' => $deliver_by_date,
            'order_model' => $data['order_model'] ?? 0,
            'order_production' => $data['order_production'] ?? 0,
            'casting_notes' => $data['casting_notes'] ?? '',
            'order_printing' => $data['order_printing'] ?? 0,
            'manufacturing_status' => $data['manufacturing_status'] ?? '',
            'order_type' => $data['order_type'] ?? 'Įprastas',
            'assigned_hours' => $assigned_hours !== null ? $assigned_hours : '',
            'completed_hours' => $data['completed_hours'] ?? 0
        ];
    }
    
    /**
     * Create order extension (ensure it exists)
     */
    public static function create_order_extension($order_id) {
        global $wpdb;
        
        if (!$order_id) {
            return false;
        }
        
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
            $order_id
        ));
        
        if (!$exists) {
            // Get order date to calculate default dates
            $order = wc_get_order($order_id);
            $order_date = $order ? $order->get_date_created() : new DateTime();
            
            if (!$order_date) {
                $order_date = new DateTime();
            }
            
            $defaults = CJS_Deadlines::default_dates($order_date);

            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_order_extensions',
                [
                    'order_id' => $order_id,
                    'manufacture_by_date' => $defaults['manufacture_by_date'],
                    'finish_by_date' => $defaults['finish_by_date'],
                    'deliver_by_date' => $defaults['deliver_by_date'],
                    'order_type' => 'Įprastas',
                    'assigned_hours' => $order ? self::calculate_default_hours($order) : 10,
                    'completed_hours' => 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s']
            );
            
            if ($result === false) {
                error_log('CJS: Failed to create order extension for order ' . $order_id . '. Error: ' . $wpdb->last_error);
                return false;
            }
            
            return $wpdb->insert_id;
        }
        
        return $exists;
    }
    /**
     * Update order extension
     */
    public static function update_order_extension($order_id, $data = null) {
        global $wpdb;
        
        // Ensure extension exists
        self::create_order_extension($order_id);
        
        if ($data && !empty($data)) {
            // Add updated timestamp
            $data['updated_at'] = current_time('mysql');
            
            $result = $wpdb->update(
                $wpdb->prefix . 'cjs_order_extensions',
                $data,
                ['order_id' => $order_id],
                null, // Let wpdb determine format
                ['%d']
            );
            
            if ($result === false) {
                error_log('CJS: Failed to update order extension for order ' . $order_id . '. Error: ' . $wpdb->last_error);
                return false;
            }
            if (array_key_exists('assigned_hours', $data) && class_exists('CJS_Statuses') && class_exists('CJS_Inv_Flow')) {
                $key = CJS_Inv_Flow::order_status_key((int) $order_id);
                if (CJS_Inv_Flow::rank($key) >= CJS_Inv_Flow::rank('pagaminta')) {
                    CJS_Statuses::fill_hours((int) $order_id, $key);
                }
            }

            return true;
        }
        
        return true;
    }
    
    /**
     * Add custom columns to orders list (HPOS compatible)
     */
    public static function add_order_columns($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            if ('order_status' === $key) {
                $new_columns['cjs_manufacture_by'] = __('Pagaminti iki', 'custom-jewelry-system');
                $new_columns['cjs_finish_by'] = __('Užprabuoti iki', 'custom-jewelry-system');
                $new_columns['cjs_deliver_by'] = __('Pristatyti iki', 'custom-jewelry-system');
                $new_columns['cjs_manufacturing_status'] = __('Gamybos statusas', 'custom-jewelry-system');
                $new_columns['cjs_order_type'] = __('Užsakymo tipas', 'custom-jewelry-system');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render custom columns (HPOS compatible)
     */
    public static function render_order_columns($column, $order) {
        $order_id = is_object($order) ? $order->get_id() : $order;
        $data = self::get_order_extension($order_id);
        
        switch ($column) {
            case 'cjs_manufacture_by':
                if ($data['manufacture_by_date']) {
                    echo esc_html(date_i18n('Y-m-d', strtotime($data['manufacture_by_date'])));
                }
                break;

            case 'cjs_finish_by':
                if ($data['finish_by_date']) {
                    echo esc_html(date_i18n('Y-m-d', strtotime($data['finish_by_date'])));
                }
                break;
                
            case 'cjs_deliver_by':
                if ($data['deliver_by_date']) {
                    $days_left = self::calculate_days_left($data['deliver_by_date']);
                    $color = self::get_date_color($days_left);
                    
                    echo sprintf(
                        '<span style="color: %s;">%s (%s)</span>',
                        $color,
                        date_i18n('Y-m-d', strtotime($data['deliver_by_date'])),
                        $days_left >= 0 ? sprintf(__('%d days', 'custom-jewelry-system'), $days_left) : 
                                         sprintf(__('Overdue %d days', 'custom-jewelry-system'), abs($days_left))
                    );
                }
                break;
                
            case 'cjs_manufacturing_status':
                echo esc_html(trim((string) $data['manufacturing_status']) === '' ? '' : CJS_Statuses::display($data['manufacturing_status']));
                break;

            case 'cjs_order_type':
                echo esc_html($data['order_type']);
                break;
        }
    }
    
    /**
     * Calculate days left
     */
    private static function calculate_days_left($date) {
        $days = CJS_Deadlines::days_until(substr((string) $date, 0, 10));
        return $days === null ? 0 : $days;
    }
    
    /**
     * Get color based on days left
     */
    private static function get_date_color($days) {
        if ($days < 0) return '#dc3545'; // Red for overdue
        if ($days < 5) return '#dc3545'; // Red
        if ($days < 14) return '#ffc107'; // Yellow
        return '#28a745'; // Green
    }
    
    /**
     * Get ordered manufacturing statuses for dropdown
     */
    public static function get_ordered_manufacturing_statuses() {
        return CJS_Statuses::label_list();
    }

    public static function manufacturing_status_index($status) {
        if ($status === null || trim((string) $status) === '') {
            return -1;
        }
        $key = CJS_Inv_Flow::status_key((string) $status);
        return $key === null ? -1 : CJS_Inv_Flow::rank($key);
    }

    public static function can_auto_advance_status($current_status, $target_status) {
        $target_idx = self::manufacturing_status_index($target_status);
        if ($target_idx < 0) {
            return false;
        }
        if ($current_status === null || trim((string) $current_status) === '') {
            return true;
        }
        $current_idx = self::manufacturing_status_index($current_status);
        if ($current_idx < 0) {
            return false;
        }
        return $current_idx < $target_idx;
    }
    
    /**
     * Get ordered options from database.
     * Merges sort-order table with full option list so defaults (only in wp_options) are never dropped.
     */
    public static function get_ordered_options($option_type) {
        global $wpdb;
        
        $from_option = get_option('cjs_' . $option_type, []);
        if (!is_array($from_option)) {
            return [];
        }
        
        $table_name = $wpdb->prefix . 'cjs_options_sort_order';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$table_name} WHERE option_type = %s ORDER BY sort_order ASC",
                $option_type
            ),
            ARRAY_A
        );
        
        if (empty($results)) {
            return $from_option;
        }
        
        $ordered = array_column($results, 'option_value');
        $is_key_value = !empty($from_option) && array_keys($from_option) !== range(0, count($from_option) - 1);
        
        if ($is_key_value) {
            $option_keys = array_keys($from_option);
            $merged_keys = $ordered;
            foreach ($option_keys as $key) {
                if (!in_array($key, $merged_keys, true)) {
                    $merged_keys[] = $key;
                }
            }
            $reordered = [];
            foreach ($merged_keys as $key) {
                if (isset($from_option[$key])) {
                    $reordered[$key] = $from_option[$key];
                }
            }
            return $reordered;
        }
        
        foreach ($from_option as $val) {
            if (!in_array($val, $ordered, true)) {
                $ordered[] = $val;
            }
        }
        return $ordered;
    }
}
