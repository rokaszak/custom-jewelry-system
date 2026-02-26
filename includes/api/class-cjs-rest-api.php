<?php
/**
 * REST API Handler - UPDATED with stone size unit support
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_REST_API {
    
    /**
     * Initialize AJAX handlers only (no REST API)
     */
    public static function init() {
        // ALL AJAX HANDLERS
        add_action('wp_ajax_cjs_get_stone_order_details', [__CLASS__, 'ajax_get_stone_order_details']);
        add_action('wp_ajax_cjs_get_stone_order_stones', [__CLASS__, 'ajax_get_stone_order_stones']);
        add_action('wp_ajax_cjs_update_order', [__CLASS__, 'ajax_update_order']);
        add_action('wp_ajax_cjs_create_stone', [__CLASS__, 'ajax_create_stone']);
        add_action('wp_ajax_cjs_update_stone', [__CLASS__, 'ajax_update_stone']);
        add_action('wp_ajax_cjs_delete_stone', [__CLASS__, 'ajax_delete_stone']);
        add_action('wp_ajax_cjs_get_stone', [__CLASS__, 'ajax_get_stone']);
        add_action('wp_ajax_cjs_create_stone_order', [__CLASS__, 'ajax_create_stone_order']);
        add_action('wp_ajax_cjs_update_stone_order', [__CLASS__, 'ajax_update_stone_order']);
        add_action('wp_ajax_cjs_delete_stone_order', [__CLASS__, 'ajax_delete_stone_order']);
        add_action('wp_ajax_cjs_manage_stone_order', [__CLASS__, 'ajax_manage_stone_order']);
        add_action('wp_ajax_cjs_update_stone_in_cart', [__CLASS__, 'ajax_update_stone_in_cart']);
        add_action('wp_ajax_cjs_find_stone_order', [__CLASS__, 'ajax_find_stone_order']);
        add_action('wp_ajax_cjs_get_whatsapp_message', [__CLASS__, 'ajax_get_whatsapp_message']);
        add_action('wp_ajax_cjs_add_option', [__CLASS__, 'ajax_add_option']);
        add_action('wp_ajax_cjs_delete_option', [__CLASS__, 'ajax_delete_option']);
        add_action('wp_ajax_cjs_get_order_items', [__CLASS__, 'ajax_get_order_items']);
        add_action('wp_ajax_cjs_get_available_stones', [__CLASS__, 'ajax_get_available_stones']);
        add_action('wp_ajax_cjs_get_order_variant_data', ['CJS_REST_API', 'ajax_get_order_variant_data']);
        //new update
        add_action('wp_ajax_cjs_get_stone_orders_for_assignment', [__CLASS__, 'ajax_get_stone_orders_for_assignment']);
        add_action('wp_ajax_cjs_assign_stone_to_order', [__CLASS__, 'ajax_assign_stone_to_order']);
        add_action('wp_ajax_cjs_create_stone_order_with_stones', [__CLASS__, 'ajax_create_stone_order_with_stones']);
        add_action('wp_ajax_cjs_edit_stone_order_with_stones', [__CLASS__, 'ajax_edit_stone_order_with_stones']);
        add_action('wp_ajax_cjs_get_available_stones_for_order', [__CLASS__, 'ajax_get_available_stones_for_order']);
        // File handlers
        add_action('wp_ajax_cjs_upload_file', [__CLASS__, 'handle_file_upload']);
        add_action('wp_ajax_cjs_delete_file', [__CLASS__, 'handle_file_delete']);
        add_action('wp_ajax_cjs_download_file', [__CLASS__, 'handle_file_download']);
        add_action('wp_ajax_cjs_update_options_order', [__CLASS__, 'ajax_update_options_order']);
    }
    
    /**
     * Check admin permission
     */
    private static function check_permission() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No permission']);
            exit;
        }
    }
    
    // =======================================================================
    // AJAX HANDLERS
    // =======================================================================
    
    /**
    * AJAX: Get stone order details
    */
    public static function ajax_get_stone_order_details() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $stone_order = new CJS_Stone_Order($stone_order_id);
        
        if (!$stone_order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $status_info = $stone_order->get_status_info();
        
        wp_send_json_success([
            'id' => $stone_order->get_id(),
            'order_number' => $stone_order->get('order_number'),
            'order_date' => $stone_order->get('order_date'),
            'status' => $stone_order->get('status'),
            'status_label' => $status_info['label'],
            'status_color' => $status_info['color']
        ]);
    }

    /**
    * AJAX: Get stones for a stone order
    */
    public static function ajax_get_stone_order_stones() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $stone_order = new CJS_Stone_Order($stone_order_id);
        
        if (!$stone_order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $stones = $stone_order->get_stones();
        $stones_data = [];
        
        foreach ($stones as $stone) {
            $stones_data[] = [
                'id' => $stone->get_id(),
                'display_string' => $stone->get_display_string(),
                'quantity' => $stone->get('stone_quantity'),
                'formatted_size' => $stone->get_formatted_size(),
                'stone_type' => $stone->get('stone_type'),
                'stone_origin' => $stone->get('stone_origin'),
                'stone_shape' => $stone->get('stone_shape')
            ];
        }
        
        wp_send_json_success($stones_data);
    }
    
    /**
    * Get order variant data for autofill
    * Add this method to CJS_REST_API class
    */
    public static function ajax_get_order_variant_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }
        
        $variant_data = [
            'product_name' => '',
            'variant_options' => []
        ];
        
        // Get the first item's variant data (or combine all items)
        $items = $order->get_items();
        
        foreach ($items as $item_id => $item) {
            $product = $item->get_product();
            
            if ($product) {
                // For first item, set product name
                if (empty($variant_data['product_name'])) {
                    $variant_data['product_name'] = $product->get_name();
                }
                
                // Get formatted meta data (excludes hidden meta automatically)
                $formatted_meta = $item->get_formatted_meta_data('', true);
                
                foreach ($formatted_meta as $meta_id => $meta) {
                    // Skip if this meta is already added (for multiple items with same variants)
                    $exists = false;
                    foreach ($variant_data['variant_options'] as $existing) {
                        if ($existing['name'] === wp_strip_all_tags($meta->display_key)) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $variant_data['variant_options'][] = [
                            'name' => wp_strip_all_tags($meta->display_key),
                            'value' => wp_strip_all_tags($meta->display_value)
                        ];
                    }
                }
                
                // For single product orders, we're done
                if (count($items) === 1) {
                    break;
                }
            }
        }
        
        wp_send_json_success($variant_data);
    }

    /**
    * AJAX: Get stone orders for assignment dropdown
    */
    public static function ajax_get_stone_orders_for_assignment() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        global $wpdb;
        
        // Get all stone orders
        $stone_orders = $wpdb->get_results(
            "SELECT id, order_number, order_date, status 
            FROM {$wpdb->prefix}cjs_stone_orders 
            ORDER BY order_date DESC, id DESC"
        );
        
        $formatted_orders = [];
        $statuses = get_option('cjs_stone_order_statuses', []);
        
        foreach ($stone_orders as $order) {
            $status_info = isset($statuses[$order->status]) ? $statuses[$order->status] : ['label' => $order->status];
            
            $formatted_orders[] = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date,
                'status' => $order->status,
                'status_label' => $status_info['label'],
                'display_text' => sprintf('%s (%s - %s)', $order->order_number, $order->order_date, $status_info['label'])
            ];
        }
        
        wp_send_json_success($formatted_orders);
    }
    /**
    * AJAX: Get available stones with order context
    */
    public static function ajax_get_available_stones_for_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        // Get unassigned stones
        $all_stones = CJS_Stone::get_stones(['per_page' => 200])['stones'];
        $available = [];
        $order_stones = [];
        
        foreach ($all_stones as $stone) {
            if (!$stone->get_stone_order()) {
                $data = [
                    'id' => $stone->get('id'),
                    'display_string' => $stone->get_display_string(),
                    'order_id' => $stone->get('order_id'),
                    'from_target_order' => false
                ];
                
                if ($stone->get('order_id')) {
                    $order = wc_get_order($stone->get('order_id'));
                    if ($order) {
                        $data['order_number'] = $order->get_order_number();
                        $data['from_target_order'] = ($stone->get('order_id') == $order_id);
                    }
                }
                
                $available[] = $data;
            }
        }
        
        // Separate stones from the target order
        foreach ($available as $stone) {
            if ($stone['from_target_order']) {
                $order_stones[] = $stone;
            }
        }
        
        wp_send_json_success([
            'all_stones' => $available,
            'order_stones' => $order_stones,
            'order_id' => $order_id
        ]);
    }
    /**
    * AJAX: Assign stone to order
    */
    public static function ajax_assign_stone_to_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_id = absint($_POST['stone_id']);
        $stone_order_id = absint($_POST['stone_order_id']);
        
        if (!$stone_id || !$stone_order_id) {
            wp_send_json_error(['message' => 'Invalid stone or order ID']);
            return;
        }
        
        $stone_order = new CJS_Stone_Order($stone_order_id);
        if (!$stone_order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $result = $stone_order->add_stone($stone_id);
        
        if ($result) {
            wp_send_json_success(['message' => 'Stone assigned successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to assign stone']);
        }
    }

    /**
    * AJAX: Create stone order with stones
    */
    public static function ajax_create_stone_order_with_stones() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order = new CJS_Stone_Order();
        
        $order_number = isset($_POST['order_number']) && !empty($_POST['order_number']) 
            ? sanitize_text_field($_POST['order_number']) 
            : '';
        
        $order->set('order_number', $order_number);
        $order->set('order_date', sanitize_text_field($_POST['order_date']));
        $order->set('status', sanitize_text_field($_POST['status']));
        
        $order_id = $order->save();
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Failed to create stone order']);
            return;
        }
        
        // Add selected stones if any
        if (isset($_POST['stone_ids']) && is_array($_POST['stone_ids'])) {
            foreach ($_POST['stone_ids'] as $stone_id) {
                $order->add_stone(absint($stone_id));
            }
        }
        
        wp_send_json_success([
            'order_id' => $order_id,
            'order_number' => $order->get('order_number'),
            'message' => 'Stone order created with number: ' . $order->get('order_number')
        ]);
    }

    /**
    * AJAX: Edit stone order with stones
    */
    public static function ajax_edit_stone_order_with_stones() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        // Update order details
        $order->set('order_number', sanitize_text_field($_POST['order_number']));
        $order->set('order_date', sanitize_text_field($_POST['order_date']));
        $order->set('status', sanitize_text_field($_POST['status']));
        
        $result = $order->save();
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to update stone order']);
            return;
        }
        
        // Handle stone updates if provided
        if (isset($_POST['stone_ids']) && is_array($_POST['stone_ids'])) {
            // Get current stones
            $current_stones = $order->get_stones();
            $current_stone_ids = array_map(function($stone) { return $stone->get_id(); }, $current_stones);
            $new_stone_ids = array_map('absint', $_POST['stone_ids']);
            
            // Remove stones that are no longer selected
            foreach ($current_stone_ids as $current_id) {
                if (!in_array($current_id, $new_stone_ids)) {
                    $order->remove_stone($current_id);
                }
            }
            
            // Add new stones
            foreach ($new_stone_ids as $new_id) {
                if (!in_array($new_id, $current_stone_ids)) {
                    $order->add_stone($new_id);
                }
            }
        }
        
        wp_send_json_success(['message' => 'Stone order updated successfully']);
    }
    /**
     * AJAX: Update order field
     */
    public static function ajax_update_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_id = absint($_POST['order_id']);
        $field = sanitize_text_field($_POST['field']);
        $value = $_POST['value'];
        
        $valid_fields = ['finish_by_date', 'deliver_by_date', 'order_model', 'order_production', 'casting_notes', 'order_printing', 'manufacturing_status'];
        if (!in_array($field, $valid_fields)) {
            wp_send_json_error(['message' => 'Invalid field']);
            return;
        }
        
        // Sanitize value
        switch ($field) {
            case 'order_model':
            case 'order_production':
                $value = $value ? 1 : 0;
                break;
            case 'casting_notes':
                $value = sanitize_textarea_field($value);
                break;
            case 'order_printing':
                $value = $value ? 1 : 0;
                break;
            default:
                $value = sanitize_text_field($value);
        }
        
        // Update database
        global $wpdb;
        CJS_Order_Extension::create_order_extension($order_id);
        
        $result = $wpdb->update(
            $wpdb->prefix . 'cjs_order_extensions',
            [$field => $value, 'updated_at' => current_time('mysql')],
            ['order_id' => $order_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
            return;
        }
        
        CJS_Logger::log("Order field '{$field}' updated via AJAX", 'info', 'order', $order_id, ['field' => $field, 'value' => $value]);
        
        wp_send_json_success(['message' => 'Updated successfully']);
    }
    
    /**
     * AJAX: Get order items
     */
    public static function ajax_get_order_items() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }
        
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $items[] = [
                'id' => $item_id,
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity()
            ];
        }
        
        wp_send_json_success($items);
    }

    /**
     * AJAX: Get available stones (not assigned to any stone order)
     */
    public static function ajax_get_available_stones() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        // Get unassigned stones
        $all_stones = CJS_Stone::get_stones(['per_page' => 100])['stones'];
        $available = [];
        
        foreach ($all_stones as $stone) {
            if (!$stone->get_stone_order()) {
                $data = [
                    'id' => $stone->get('id'),
                    'display_string' => $stone->get_display_string()
                ];
                
                if ($stone->get('order_id')) {
                    $order = wc_get_order($stone->get('order_id'));
                    if ($order) {
                        $data['order_number'] = $order->get_order_number();
                    }
                }
                
                $available[] = $data;
            }
        }
        
        wp_send_json_success($available);
    }

    /**
     * AJAX: Get stone data - UPDATED for size units
     */
    public static function ajax_get_stone() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_id = absint($_POST['stone_id']);
        $stone = new CJS_Stone($stone_id);
        
        if (!$stone->get_id()) {
            wp_send_json_error(['message' => 'Stone not found']);
            return;
        }
        
        $data = $stone->to_array();
        
        // Add product name if available
        if ($stone->get('order_item_id')) {
            $order_id = $stone->get('order_id');
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $item = $order->get_item($stone->get('order_item_id'));
                    if ($item) {
                        $data['product_name'] = $item->get_name();
                    }
                }
            }
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * AJAX: Create stone - UPDATED for size units
     */
    public static function ajax_create_stone() {
    if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    self::check_permission();
    
    $stone = new CJS_Stone();
    
    // Set all fields explicitly - handle empty values properly
    $stone->set('order_id', isset($_POST['order_id']) ? absint($_POST['order_id']) : null);
    $stone->set('order_item_id', isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : null);
    $stone->set('stone_type', isset($_POST['stone_type']) ? sanitize_text_field($_POST['stone_type']) : '');
    $stone->set('stone_origin', isset($_POST['stone_origin']) ? sanitize_text_field($_POST['stone_origin']) : 'Natural');
    $stone->set('stone_shape', isset($_POST['stone_shape']) ? sanitize_text_field($_POST['stone_shape']) : '');
    $stone->set('stone_quantity', isset($_POST['stone_quantity']) ? absint($_POST['stone_quantity']) : 1);
    $stone->set('stone_size_value', isset($_POST['stone_size_value']) ? floatval($_POST['stone_size_value']) : null);
    $stone->set('stone_size_unit', isset($_POST['stone_size_unit']) ? sanitize_text_field($_POST['stone_size_unit']) : 'carats');
    $stone->set('stone_color', isset($_POST['stone_color']) ? sanitize_text_field($_POST['stone_color']) : '');
    $stone->set('stone_setting', isset($_POST['stone_setting']) ? sanitize_text_field($_POST['stone_setting']) : '');
    $stone->set('stone_clarity', isset($_POST['stone_clarity']) ? sanitize_text_field($_POST['stone_clarity']) : '');
    $stone->set('stone_cut_grade', isset($_POST['stone_cut_grade']) ? sanitize_text_field($_POST['stone_cut_grade']) : '');
    $stone->set('origin_country', isset($_POST['origin_country']) ? sanitize_text_field($_POST['origin_country']) : '');
    $stone->set('certificate', isset($_POST['certificate']) ? sanitize_text_field($_POST['certificate']) : '');
    $stone->set('custom_comment', isset($_POST['custom_comment']) ? sanitize_textarea_field($_POST['custom_comment']) : '');
    
    // Debug logging
    error_log('CJS: Creating stone with data:');
    error_log('stone_size_unit: ' . $stone->get('stone_size_unit'));
    error_log('stone_size_value: ' . $stone->get('stone_size_value'));
    error_log('stone_color: ' . $stone->get('stone_color'));
    error_log('custom_comment: ' . $stone->get('custom_comment'));
    
    $stone_id = $stone->save();
    
    if (!$stone_id) {
        wp_send_json_error(['message' => 'Failed to create stone']);
        return;
    }
    
    wp_send_json_success(['stone_id' => $stone_id]);
}
    
    /**
     * AJAX: Update stone - UPDATED for size units
     */
    public static function ajax_update_stone() {
    if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    self::check_permission();
    
    $stone_id = absint($_POST['stone_id']);
    $stone = new CJS_Stone($stone_id);
    
    if (!$stone->get_id()) {
        wp_send_json_error(['message' => 'Stone not found']);
        return;
    }
    
    // Update all fields that are provided - handle empty values properly
    if (isset($_POST['stone_type'])) {
        $stone->set('stone_type', sanitize_text_field($_POST['stone_type']));
    }
    if (isset($_POST['stone_origin'])) {
        $stone->set('stone_origin', sanitize_text_field($_POST['stone_origin']) ?: 'Natural');
    }
    if (isset($_POST['stone_shape'])) {
        $stone->set('stone_shape', sanitize_text_field($_POST['stone_shape']));
    }
    if (isset($_POST['stone_quantity'])) {
        $stone->set('stone_quantity', absint($_POST['stone_quantity']) ?: 1);
    }
    if (isset($_POST['stone_size_value'])) {
        $stone->set('stone_size_value', $_POST['stone_size_value'] !== '' ? floatval($_POST['stone_size_value']) : null);
    }
    if (isset($_POST['stone_size_unit'])) {
        $stone->set('stone_size_unit', sanitize_text_field($_POST['stone_size_unit']) ?: 'carats');
    }
    if (isset($_POST['stone_color'])) {
        $stone->set('stone_color', sanitize_text_field($_POST['stone_color']));
    }
    if (isset($_POST['stone_setting'])) {
        $stone->set('stone_setting', sanitize_text_field($_POST['stone_setting']));
    }
    if (isset($_POST['stone_clarity'])) {
        $stone->set('stone_clarity', sanitize_text_field($_POST['stone_clarity']));
    }
    if (isset($_POST['stone_cut_grade'])) {
        $stone->set('stone_cut_grade', sanitize_text_field($_POST['stone_cut_grade']));
    }
    if (isset($_POST['origin_country'])) {
        $stone->set('origin_country', sanitize_text_field($_POST['origin_country']));
    }
    if (isset($_POST['certificate'])) {
        $stone->set('certificate', sanitize_text_field($_POST['certificate']));
    }
    if (isset($_POST['custom_comment'])) {
        $stone->set('custom_comment', sanitize_textarea_field($_POST['custom_comment']));
    }
    
    // Debug logging
    error_log('CJS: Updating stone with data:');
    error_log('stone_size_unit: ' . $stone->get('stone_size_unit'));
    error_log('stone_size_value: ' . $stone->get('stone_size_value'));
    error_log('stone_color: ' . $stone->get('stone_color'));
    error_log('custom_comment: ' . $stone->get('custom_comment'));
    
    $result = $stone->save();
    
    if (!$result) {
        wp_send_json_error(['message' => 'Failed to update stone']);
        return;
    }
    
    wp_send_json_success(['message' => 'Stone updated']);
}
    
    /**
     * AJAX: Delete stone
     */
    public static function ajax_delete_stone() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_id = absint($_POST['stone_id']);
        $stone = new CJS_Stone($stone_id);
        
        if (!$stone->get_id()) {
            wp_send_json_error(['message' => 'Stone not found']);
            return;
        }
        
        $result = $stone->delete();
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to delete stone']);
            return;
        }
        
        wp_send_json_success(['message' => 'Stone deleted']);
    }
    
    /**
     * AJAX: Create stone order
     */
    public static function ajax_create_stone_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order = new CJS_Stone_Order();
        
        // Order number is now auto-generated, don't require it from user
        $order_number = isset($_POST['order_number']) && !empty($_POST['order_number']) 
            ? sanitize_text_field($_POST['order_number']) 
            : ''; // Will be auto-generated in save()
        
        $order->set('order_number', $order_number);
        $order->set('order_date', sanitize_text_field($_POST['order_date']));
        $order->set('status', sanitize_text_field($_POST['status']));
        
        $order_id = $order->save();
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Failed to create stone order']);
            return;
        }
        
        // Return the auto-generated order number
        wp_send_json_success([
            'order_id' => $order_id,
            'order_number' => $order->get('order_number'),
            'message' => 'Stone order created with number: ' . $order->get('order_number')
        ]);
    }
    
    /**
     * AJAX: Update stone order
     */
    public static function ajax_update_stone_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $field = sanitize_text_field($_POST['field']);
        $value = sanitize_text_field($_POST['value']);
        
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $order->set($field, $value);
        $result = $order->save();
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to update stone order']);
            return;
        }
        
        wp_send_json_success(['message' => 'Stone order updated']);
    }
    
    /**
     * AJAX: Delete stone order
     */
    public static function ajax_delete_stone_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $result = $order->delete();
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to delete stone order']);
            return;
        }
        
        wp_send_json_success(['message' => 'Stone order deleted']);
    }
    
    /**
     * AJAX: Manage stones in stone order (add/remove)
     */
    public static function ajax_manage_stone_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $stone_action = sanitize_text_field($_POST['stone_action']);
        $stone_id = absint($_POST['stone_id']);
        
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        if (!$stone_id) {
            wp_send_json_error(['message' => 'Invalid stone ID']);
            return;
        }
        
        if ($stone_action === 'add') {
            $result = $order->add_stone($stone_id);
        } elseif ($stone_action === 'remove') {
            $result = $order->remove_stone($stone_id);
        } else {
            wp_send_json_error(['message' => 'Invalid action']);
            return;
        }
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to perform action']);
            return;
        }
        
        wp_send_json_success(['message' => 'Action completed']);
    }
    
    /**
     * AJAX: Find stone order by number
     */
    public static function ajax_find_stone_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_number = sanitize_text_field($_POST['order_number']);
        
        if (empty($order_number)) {
            wp_send_json_error(['message' => 'Order number required']);
            return;
        }
        
        global $wpdb;
        $stone_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_stone_orders WHERE order_number = %s",
            $order_number
        ));
        
        if (!$stone_order) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        wp_send_json_success($stone_order);
    }
    
    /**
     * AJAX: Update stone in_cart status
     */
    public static function ajax_update_stone_in_cart() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_id = absint($_POST['stone_id']);
        $stone_order_id = absint($_POST['stone_order_id']);
        $in_cart = isset($_POST['value']) ? (bool) $_POST['value'] : false;
        
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $result = $order->update_stone_in_cart($stone_id, $in_cart);
        
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to update in_cart status']);
            return;
        }
        
        wp_send_json_success(['message' => 'In cart status updated']);
    }
    
    /**
     * AJAX: Get WhatsApp message
     */
    public static function ajax_get_whatsapp_message() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $stone_order_id = absint($_POST['stone_order_id']);
        $order = new CJS_Stone_Order($stone_order_id);
        
        if (!$order->get_id()) {
            wp_send_json_error(['message' => 'Stone order not found']);
            return;
        }
        
        $message = $order->generate_whatsapp_message();
        
        wp_send_json_success($message);
    }
    
    /**
     * AJAX: Add dropdown option - UPDATED for size units
     */
    public static function ajax_add_option() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $option_type = sanitize_text_field($_POST['option_type']);
        $value = sanitize_text_field($_POST['value']);
        $label = sanitize_text_field($_POST['label'] ?? $value);
        
        $valid_types = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses', 'stone_size_units'
        ];
        
        if (!in_array($option_type, $valid_types)) {
            wp_send_json_error(['message' => 'Invalid option type']);
            return;
        }
        
        if (empty($value)) {
            wp_send_json_error(['message' => 'Value cannot be empty']);
            return;
        }
        
        $options = get_option('cjs_' . $option_type, []);
        
        // Handle different option formats
        if (in_array($option_type, ['stone_shapes', 'stone_clarities', 'stone_cut_grades', 'origin_countries', 'manufacturing_statuses'])) {
            // Simple array
            if (!in_array($value, $options)) {
                $options[] = $value;
            }
        } else {
            // Key-value pairs
            $options[$value] = $label;
        }
        
        update_option('cjs_' . $option_type, $options);
        
        // Special handling for sortable options - add to order table
        $sortable_options = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses'
        ];
        
        if (in_array($option_type, $sortable_options)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'cjs_options_sort_order';
            
            // Get the highest sort order for this option type
            $max_order = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(sort_order) FROM {$table_name} WHERE option_type = %s",
                $option_type
            ));
            $new_order = $max_order !== null ? intval($max_order) + 1 : 0;
            
            $wpdb->insert(
                $table_name,
                [
                    'option_type' => $option_type,
                    'option_value' => $value,
                    'sort_order' => $new_order
                ],
                ['%s', '%s', '%d']
            );
        }
        
        CJS_Logger::log("Option added to {$option_type}", 'info', 'settings', null, ['value' => $value, 'label' => $label]);
        
        wp_send_json_success(['options' => $options]);
    }
    
    /**
     * AJAX: Delete dropdown option - UPDATED for size units
     */
    public static function ajax_delete_option() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $option_type = sanitize_text_field($_POST['option_type']);
        $value = sanitize_text_field($_POST['value']);
        
        $valid_types = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses', 'stone_size_units'
        ];
        
        if (!in_array($option_type, $valid_types)) {
            wp_send_json_error(['message' => 'Invalid option type']);
            return;
        }
        
        $options = get_option('cjs_' . $option_type, []);
        
        // Handle different option formats
        if (in_array($option_type, ['stone_shapes', 'stone_clarities', 'stone_cut_grades', 'origin_countries', 'manufacturing_statuses'])) {
            // Simple array
            $options = array_diff($options, [$value]);
            $options = array_values($options); // Re-index
        } else {
            // Key-value pairs
            unset($options[$value]);
        }
        
        update_option('cjs_' . $option_type, $options);
        
        // Special handling for sortable options - remove from order table
        $sortable_options = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses'
        ];
        
        if (in_array($option_type, $sortable_options)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'cjs_options_sort_order';
            
            $wpdb->delete(
                $table_name,
                [
                    'option_type' => $option_type,
                    'option_value' => $value
                ],
                ['%s', '%s']
            );
        }
        
        CJS_Logger::log("Option deleted from {$option_type}", 'warning', 'settings', null, ['value' => $value]);
        
        wp_send_json_success(['options' => $options]);
    }
    
    // =======================================================================
    // FILE HANDLERS (unchanged)
    // =======================================================================
    
    /**
     * Handle file upload
     */
    public static function handle_file_upload() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cjs_file_upload')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $file_name = isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : '';
        $comment = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : '';
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
            return;
        }
        
        $file_handler = new CJS_File_Handler();
        
        // Handle main file
        $main_file = $file_handler->upload_file($_FILES['file'], $order_id);
        
        if (is_wp_error($main_file)) {
            wp_send_json_error(['message' => $main_file->get_error_message()]);
            return;
        }
        
        // Handle thumbnail if provided
        $thumbnail_path = '';
        if (!empty($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $thumbnail = $file_handler->upload_file($_FILES['thumbnail'], $order_id, 'thumb_');
            
            if (!is_wp_error($thumbnail)) {
                $thumbnail_path = $thumbnail['path'];
            }
        }
        
        // Save to database
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'cjs_order_files',
            [
                'order_id' => $order_id,
                'file_name' => $file_name ?: $main_file['name'],
                'file_path' => $main_file['path'],
                'file_type' => $main_file['type'],
                'file_size' => $main_file['size'],
                'thumbnail_path' => $thumbnail_path ?: null,
                'custom_comment' => $comment ?: null,
                'uploaded_by' => get_current_user_id()
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to save file record']);
            return;
        }
        
        CJS_Logger::log('File uploaded', 'success', 'order', $order_id, ['file' => $file_name ?: $main_file['name']]);
        
        wp_send_json_success(['message' => 'File uploaded successfully']);
    }

    public static function handle_file_delete() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cjs_file_delete')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $file_id = isset($_POST['file_id']) ? absint($_POST['file_id']) : 0;
        
        if (!$file_id) {
            wp_send_json_error(['message' => 'Invalid file ID']);
            return;
        }
        
        global $wpdb;
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_order_files WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            wp_send_json_error(['message' => 'File not found']);
            return;
        }
        
        // Delete physical files
        $file_handler = new CJS_File_Handler();
        $file_handler->delete_file($file->file_path);
        
        if ($file->thumbnail_path) {
            $file_handler->delete_file($file->thumbnail_path);
        }
        
        // Delete database record
        $result = $wpdb->delete(
            $wpdb->prefix . 'cjs_order_files',
            ['id' => $file_id],
            ['%d']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to delete file record']);
            return;
        }
        
        CJS_Logger::log('File deleted', 'warning', 'order', $file->order_id, ['file' => $file->file_name]);
        
        wp_send_json_success(['message' => 'File deleted successfully']);
    }
    
    /**
     * Handle file download
     */
    public static function handle_file_download() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'cjs_file_download')) {
            wp_die('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        if (!isset($_GET['file'])) {
            wp_die('No file specified');
        }
        
        // Decode the URL-safe base64 string
        $encoded_file = str_replace(['-', '_'], ['+', '/'], $_GET['file']);
        // Add padding if needed
        $padding = strlen($encoded_file) % 4;
        if ($padding) {
            $encoded_file .= str_repeat('=', 4 - $padding);
        }
        $file_path = base64_decode($encoded_file);
        
        if (empty($file_path)) {
            wp_die('Invalid file path');
        }
        
        $file_handler = new CJS_File_Handler();
        $file_handler->serve_file($file_path);
    }
    
    /**
     * AJAX: Update options order
     */
    public static function ajax_update_options_order() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        self::check_permission();
        
        $option_type = isset($_POST['option_type']) ? sanitize_text_field($_POST['option_type']) : '';
        $options = isset($_POST['options']) ? $_POST['options'] : [];
        
        error_log('CJS: Updating options order - Type: ' . $option_type . ', Options: ' . print_r($options, true));
        
        if (empty($option_type) || !is_array($options)) {
            wp_send_json_error(['message' => 'Invalid data']);
            return;
        }
        
        // Validate option type
        $valid_types = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses'
        ];
        
        if (!in_array($option_type, $valid_types)) {
            wp_send_json_error(['message' => 'Invalid option type']);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cjs_options_sort_order';
        
        // Begin transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // First, ensure all options exist in the database
            foreach ($options as $index => $option_value) {
                $sanitized_value = sanitize_text_field($option_value);
                
                // Check if option exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE option_type = %s AND option_value = %s",
                    $option_type,
                    $sanitized_value
                ));
                
                if (!$exists) {
                    // Insert the option if it doesn't exist
                    error_log('CJS: Inserting new option - Type: ' . $option_type . ', Value: ' . $sanitized_value . ', Order: ' . $index);
                    $wpdb->insert(
                        $table_name,
                        [
                            'option_type' => $option_type,
                            'option_value' => $sanitized_value,
                            'sort_order' => $index
                        ],
                        ['%s', '%s', '%d']
                    );
                } else {
                    // Update existing option
                    error_log('CJS: Updating existing option - Type: ' . $option_type . ', Value: ' . $sanitized_value . ', Order: ' . $index);
                    $result = $wpdb->update(
                        $table_name,
                        ['sort_order' => $index],
                        [
                            'option_type' => $option_type,
                            'option_value' => $sanitized_value
                        ],
                        ['%d'],
                        ['%s', '%s']
                    );
                    
                    if ($result === false) {
                        throw new Exception('Failed to update option order');
                    }
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            CJS_Logger::log("Options order updated for {$option_type}", 'success', 'settings', null, ['options' => $options]);
            
            wp_send_json_success(['message' => 'Options order updated successfully']);
            
        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}