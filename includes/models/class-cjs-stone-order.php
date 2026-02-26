<?php
/**
 * Stone Order Model - handles stone orders with updated size display
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Stone_Order {
    
    private $id;
    private $data = [];
    
    /**
     * Constructor
     */
    public function __construct($order_id = 0) {
        if ($order_id) {
            $this->id = absint($order_id);
            $this->load();
        }
    }
    
    /**
     * Create stone order from database row
     */
    public static function from_row($row) {
        $stone_order = new self();
        $stone_order->id = $row->id;
        $stone_order->data = (array) $row;
        return $stone_order;
    }
    
    /**
     * Load stone order data
     */
    private function load() {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_stone_orders WHERE id = %d",
            $this->id
        ), ARRAY_A);
        
        if ($data) {
            $this->data = $data;
        }
    }
    
    /**
     * Get property
     */
    public function get($key) {
        if ($key === 'id') {
            return $this->id;
        }
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
    
    /**
     * Set property
     */
    public function set($key, $value) {
        if ($key === 'id') {
            $this->id = absint($value);
        } else {
            $this->data[$key] = $value;
        }
    }
    
    /**
     * Get ID
     */
    public function get_id() {
        return $this->id;
    }
    
    /**
     * Save stone order
     */
    public function save() {
        global $wpdb;
        
        $data = [
            'order_number' => sanitize_text_field($this->get('order_number')),
            'order_date' => sanitize_text_field($this->get('order_date')),
            'status' => sanitize_text_field($this->get('status')),
            'created_by' => get_current_user_id()
        ];
        
        if ($this->id) {
            // Update (existing code stays the same)
            unset($data['created_by']); // Don't update creator
            
            $result = $wpdb->update(
                $wpdb->prefix . 'cjs_stone_orders',
                $data,
                ['id' => $this->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            
            if ($result !== false) {
                CJS_Logger::log('Stone order updated', 'info', 'stone_order', $this->id, $data);
            } else {
                error_log('CJS: Failed to update stone order ' . $this->id . '. Error: ' . $wpdb->last_error);
            }
        } else {
            // INSERT - ADD THIS AUTO-GENERATION LOGIC
            // Auto-generate order number if not set
            if (empty($data['order_number'])) {
                $data['order_number'] = self::generate_order_number();
                $this->set('order_number', $data['order_number']);
            }
            
            // Insert (rest of existing code stays the same)
            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_stone_orders',
                $data,
                ['%s', '%s', '%s', '%d']
            );
            
            if ($result) {
                $this->id = $wpdb->insert_id;
                $this->data['id'] = $this->id;
                
                CJS_Logger::log('Stone order created', 'success', 'stone_order', $this->id, $data);
            } else {
                error_log('CJS: Failed to create stone order. Error: ' . $wpdb->last_error);
                error_log('CJS: Data: ' . print_r($data, true));
            }
        }
        
        return $this->id;
    }
    
    /**
     * Delete stone order
     */
    public function delete() {
        global $wpdb;
        
        if (!$this->id) {
            return false;
        }
        
        // Remove stone associations
        $wpdb->delete(
            $wpdb->prefix . 'cjs_stone_order_items',
            ['stone_order_id' => $this->id],
            ['%d']
        );
        
        // Delete stone order
        $result = $wpdb->delete(
            $wpdb->prefix . 'cjs_stone_orders',
            ['id' => $this->id],
            ['%d']
        );
        
        if ($result) {
            CJS_Logger::log('Stone order deleted', 'warning', 'stone_order', $this->id);
        }
        
        return $result !== false;
    }
    
    /**
     * Add stone to order
     */
    public function add_stone($stone_id) {
        global $wpdb;
        
        if (!$this->id || !$stone_id) {
            return false;
        }
        
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cjs_stone_order_items 
             WHERE stone_id = %d AND stone_order_id = %d",
            $stone_id,
            $this->id
        ));
        
        if (!$exists) {
            // Check if in_cart column exists
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cjs_stone_order_items");
            $has_in_cart_column = in_array('in_cart', $columns);
            
            $insert_data = [
                'stone_id' => $stone_id,
                'stone_order_id' => $this->id
            ];
            
            $format = ['%d', '%d'];
            
            if ($has_in_cart_column) {
                $insert_data['in_cart'] = 0;
                $format[] = '%d';
            }
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_stone_order_items',
                $insert_data,
                $format
            );
            
            if ($result) {
                CJS_Logger::log('Stone added to order', 'info', 'stone_order', $this->id, ['stone_id' => $stone_id]);
                return true;
            } else {
                error_log('CJS: Failed to add stone to order. Error: ' . $wpdb->last_error);
                return false;
            }
        }
        
        return true; // Already exists, so consider it successful
    }
    
    /**
     * Remove stone from order
     */
    public function remove_stone($stone_id) {
        global $wpdb;
        
        if (!$this->id || !$stone_id) {
            return false;
        }
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'cjs_stone_order_items',
            [
                'stone_id' => $stone_id,
                'stone_order_id' => $this->id
            ],
            ['%d', '%d']
        );
        
        if ($result) {
            CJS_Logger::log('Stone removed from order', 'info', 'stone_order', $this->id, ['stone_id' => $stone_id]);
        }
        
        return $result !== false;
    }
    
    /**
     * Get stones in this order with in_cart status
     */
    public function get_stones() {
        global $wpdb;
        
        if (!$this->id) {
            return [];
        }
        
        // First check if in_cart column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cjs_stone_order_items");
        $has_in_cart_column = in_array('in_cart', $columns);
        
        if ($has_in_cart_column) {
            // Use the new query with in_cart column
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, soi.in_cart FROM {$wpdb->prefix}cjs_stones s
                 INNER JOIN {$wpdb->prefix}cjs_stone_order_items soi ON s.id = soi.stone_id
                 WHERE soi.stone_order_id = %d
                 ORDER BY s.id DESC",
                $this->id
            ));
        } else {
            // Fallback to old query without in_cart column
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT s.* FROM {$wpdb->prefix}cjs_stones s
                 INNER JOIN {$wpdb->prefix}cjs_stone_order_items soi ON s.id = soi.stone_id
                 WHERE soi.stone_order_id = %d
                 ORDER BY s.id DESC",
                $this->id
            ));
        }
        
        $stones = [];
        foreach ($results as $result) {
            $stone = CJS_Stone::from_row($result);
            // Add in_cart property to the stone object (default to 0 if column doesn't exist)
            $stone->set('in_cart', $has_in_cart_column ? $result->in_cart : 0);
            $stones[] = $stone;
        }
        
        return $stones;
    }
    
    /**
     * Update in_cart status for a stone in this order
     */
    public function update_stone_in_cart($stone_id, $in_cart) {
        global $wpdb;
        
        if (!$this->id || !$stone_id) {
            return false;
        }
        
        // Check if in_cart column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cjs_stone_order_items");
        if (!in_array('in_cart', $columns)) {
            error_log("CJS: in_cart column does not exist in stone_order_items table");
            return false;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'cjs_stone_order_items',
            ['in_cart' => $in_cart ? 1 : 0],
            [
                'stone_id' => $stone_id,
                'stone_order_id' => $this->id
            ],
            ['%d'],
            ['%d', '%d']
        );
        
        if ($result !== false) {
            CJS_Logger::log('Stone in_cart status updated', 'info', 'stone_order', $this->id, [
                'stone_id' => $stone_id,
                'in_cart' => $in_cart
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get related WooCommerce orders
     */
    public function get_related_orders() {
        global $wpdb;
        
        if (!$this->id) {
            return [];
        }
        
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT s.order_id 
             FROM {$wpdb->prefix}cjs_stones s
             INNER JOIN {$wpdb->prefix}cjs_stone_order_items soi ON s.id = soi.stone_id
             WHERE soi.stone_order_id = %d AND s.order_id IS NOT NULL",
            $this->id
        ));
        
        $orders = [];
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $orders[] = $order;
            }
        }
        
        return $orders;
    }
    
    /**
     * Generate next order number automatically (ADD THIS METHOD)
     */
    public static function generate_order_number() {
        global $wpdb;
        
        // Get the highest existing order number
        $latest_order = $wpdb->get_var(
            "SELECT order_number FROM {$wpdb->prefix}cjs_stone_orders 
             WHERE order_number REGEXP '^[0-9]+$' 
             ORDER BY CAST(order_number AS UNSIGNED) DESC 
             LIMIT 1"
        );
        
        if ($latest_order) {
            return strval(intval($latest_order) + 1);
        } else {
            return '1';
        }
    }

    /**
     * Generate WhatsApp message (in English) - UPDATED for new size format
     */
    public function generate_whatsapp_message() {
        $stones = $this->get_stones();
        
        if (empty($stones)) {
            return '';
        }
        
        // Group stones by EXACT characteristics (not display string)
        $grouped = [];
        foreach ($stones as $stone) {
            // Create a unique key based on actual stone characteristics
            $key_parts = [
                $stone->get('stone_type') ?: '',
                $stone->get('stone_origin') ?: '',
                $stone->get('stone_shape') ?: '',
                $stone->get('stone_size_value') ?: '',
                $stone->get('stone_size_unit') ?: '',
                $stone->get('stone_color') ?: '',
                $stone->get('stone_clarity') ?: '',
                $stone->get('stone_cut_grade') ?: '',
                $stone->get('origin_country') ?: '',
                $stone->get('certificate') ?: '',
                $stone->get('custom_comment') ?: ''
            ];
            
            $unique_key = implode('|', $key_parts);
            
            if (!isset($grouped[$unique_key])) {
                $grouped[$unique_key] = [
                    'total_quantity' => 0,
                    'stone_details' => $stone,
                    'certificates' => [],
                    'comments' => [],
                    'origins' => []
                ];
            }
            
            $grouped[$unique_key]['total_quantity'] += $stone->get('stone_quantity');
            
            // Collect unique additional details
            if ($stone->get('certificate') && !in_array($stone->get('certificate'), $grouped[$unique_key]['certificates'])) {
                $grouped[$unique_key]['certificates'][] = $stone->get('certificate');
            }
            
            if ($stone->get('custom_comment') && !in_array($stone->get('custom_comment'), $grouped[$unique_key]['comments'])) {
                $grouped[$unique_key]['comments'][] = $stone->get('custom_comment');
            }
            
            if ($stone->get('origin_country') && !in_array($stone->get('origin_country'), $grouped[$unique_key]['origins'])) {
                $grouped[$unique_key]['origins'][] = $stone->get('origin_country');
            }
        }
        
        // Build message in English
        $date = date_i18n('Y-m-d', strtotime($this->get('order_date')));
        $message = "Stone order dated " . $date . "\n";
        $message .= "Required stones:\n\n";
        
        foreach ($grouped as $group_data) {
            $stone = $group_data['stone_details'];
            $total_qty = $group_data['total_quantity'];
            $parts = [];
            
            // Build detailed description
            if ($stone->get('stone_type')) {
                $parts[] = $stone->get('stone_type');
            }
            
            // Only show origin if it's not "Natural"
            if ($stone->get('stone_origin') && $stone->get('stone_origin') !== 'Natural') {
                $parts[] = $stone->get('stone_origin');
            }
            
            if ($stone->get('stone_shape')) {
                $parts[] = $stone->get('stone_shape') . ' cut';
            }
            
            // Use formatted size
            $formatted_size = $stone->get_formatted_size();
            if ($formatted_size) {
                $parts[] = $formatted_size;
            }
            
            if ($stone->get('stone_color')) {
                $parts[] = $stone->get('stone_color') . ' color';
            }
            
            if ($stone->get('stone_clarity')) {
                $parts[] = $stone->get('stone_clarity') . ' clarity';
            }
            
            // Format quantity with pc/pcs
            $qty_text = $total_qty . ' ' . ($total_qty == 1 ? 'pc' : 'pcs');
            
            $message .= "- " . implode(', ', $parts) . " - " . $qty_text . "\n";
            
            // Add additional details if present (and not empty)
            if (!empty($group_data['certificates'])) {
                $message .= "  Certificate: " . implode(', ', $group_data['certificates']) . "\n";
            }
            
            if (!empty($group_data['origins'])) {
                $message .= "  Origin: " . implode(', ', $group_data['origins']) . "\n";
            }
            
            if (!empty($group_data['comments'])) {
                $message .= "  Note: " . implode('; ', $group_data['comments']) . "\n";
            }
            
            $message .= "\n";
        }
        
        return trim($message);
    }
    
    /**
     * Get status label and color
     */
    public function get_status_info() {
        $statuses = get_option('cjs_stone_order_statuses', []);
        $status = $this->get('status');
        
        if (isset($statuses[$status])) {
            return $statuses[$status];
        }
        
        return [
            'label' => $status,
            'color' => '#6c757d'
        ];
    }
    
    /**
     * Get all stone orders with filters
     */
    public static function get_stone_orders($args = []) {
        global $wpdb;
        
        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'status' => null,
            'search' => null,
            'orderby' => 'id',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $values = [];
        
        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        
        if ($args['search']) {
            $where[] = 'order_number LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}cjs_stone_orders WHERE $where_clause";
        if (!empty($values)) {
            $count_query = $wpdb->prepare($count_query, $values);
        }
        $total = $wpdb->get_var($count_query);
        
        // Get orders
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = in_array($args['orderby'], ['id', 'order_date', 'created_at']) ? $args['orderby'] : 'id';
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
        
        $query = "SELECT so.*, u.display_name as created_by_name,
                  (SELECT COUNT(*) FROM {$wpdb->prefix}cjs_stone_order_items WHERE stone_order_id = so.id) as stone_count
                  FROM {$wpdb->prefix}cjs_stone_orders so
                  LEFT JOIN {$wpdb->users} u ON so.created_by = u.ID
                  WHERE $where_clause
                  ORDER BY $orderby $order
                  LIMIT %d, %d";
        
        $values[] = $offset;
        $values[] = $args['per_page'];
        
        $results = $wpdb->get_results($wpdb->prepare($query, $values));
        
        // Convert to StoneOrder objects
        $orders = [];
        foreach ($results as $result) {
            $orders[] = self::from_row($result);
        }
        
        return [
            'orders' => $orders,
            'total' => $total,
            'pages' => ceil($total / $args['per_page'])
        ];
    }
    
    /**
     * Get all stone order data as array
     */
    public function to_array() {
        $data = $this->data;
        $data['id'] = $this->id;
        return $data;
    }
}