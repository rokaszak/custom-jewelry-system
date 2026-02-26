<?php
/**
 * Stone Model - handles required stones with size units support
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Stone {
    
    private $id;
    private $data = [];
    
    /**
     * Constructor
     */
    public function __construct($stone_id = 0) {
        if ($stone_id) {
            $this->id = absint($stone_id);
            $this->load();
        }
    }
    
    /**
     * Create stone from database row
     */
    public static function from_row($row) {
        $stone = new self();
        $stone->id = $row->id;
        $stone->data = (array) $row;
        return $stone;
    }
    
    /**
     * Load stone data
     */
    private function load() {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_stones WHERE id = %d",
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
     * Set ID (for internal use)
     */
    public function set_id($id) {
        $this->id = absint($id);
    }
    
    /**
     * Set data (for internal use)
     */
    public function set_data($data) {
        $this->data = (array) $data;
    }
    
    /**
     * Get formatted size with unit
     */
    public function get_formatted_size() {
        $value = $this->get('stone_size_value');
        $unit = $this->get('stone_size_unit');
        
        if (!$value) {
            // Fallback to legacy weight field
            $legacy_weight = $this->get('stone_weight_carats');
            if ($legacy_weight) {
                return $legacy_weight . ' ct';
            }
            return '';
        }
        
        // Format based on unit
        if ($unit === 'mm') {
            return number_format($value, 1) . ' mm';
        } else {
            return number_format($value, 2) . ' ct';
        }
    }
    
    /**
     * Get size value for editing (backwards compatible)
     */
    public function get_size_value() {
        $value = $this->get('stone_size_value');
        if ($value) {
            return $value;
        }
        
        // Fallback to legacy field
        return $this->get('stone_weight_carats');
    }
    
    /**
     * Get size unit (defaults to carats)
     */
    public function get_size_unit() {
        $unit = $this->get('stone_size_unit');
        return $unit ?: 'carats';
    }
    
    /**
     * Save stone
     */
    public function save() {
        global $wpdb;
        
        // Prepare data for saving - handle empty values properly
        $data = [
            'order_id' => $this->get('order_id') ? absint($this->get('order_id')) : null,
            'order_item_id' => $this->get('order_item_id') ? absint($this->get('order_item_id')) : null,
            'stone_type' => $this->get('stone_type') ? sanitize_text_field($this->get('stone_type')) : null,
            'stone_origin' => sanitize_text_field($this->get('stone_origin')) ?: 'Natural',
            'stone_shape' => $this->get('stone_shape') ? sanitize_text_field($this->get('stone_shape')) : null,
            'stone_quantity' => absint($this->get('stone_quantity')) ?: 1,
            'stone_size_value' => $this->get('stone_size_value') ? floatval($this->get('stone_size_value')) : null,
            'stone_size_unit' => $this->get('stone_size_unit') ? sanitize_text_field($this->get('stone_size_unit')) : 'carats',
            'stone_color' => $this->get('stone_color') ? sanitize_text_field($this->get('stone_color')) : null,
            'stone_setting' => $this->get('stone_setting') ? sanitize_text_field($this->get('stone_setting')) : null,
            'stone_clarity' => $this->get('stone_clarity') ? sanitize_text_field($this->get('stone_clarity')) : null,
            'stone_cut_grade' => $this->get('stone_cut_grade') ? sanitize_text_field($this->get('stone_cut_grade')) : null,
            'origin_country' => $this->get('origin_country') ? sanitize_text_field($this->get('origin_country')) : null,
            'certificate' => $this->get('certificate') ? sanitize_text_field($this->get('certificate')) : null,
            'custom_comment' => $this->get('custom_comment') ? sanitize_textarea_field($this->get('custom_comment')) : null,
            'created_by' => get_current_user_id()
        ];
        
        // Define format array that matches the data structure
        $formats = [
            '%d', // order_id
            '%d', // order_item_id
            '%s', // stone_type
            '%s', // stone_origin
            '%s', // stone_shape
            '%d', // stone_quantity
            '%f', // stone_size_value
            '%s', // stone_size_unit
            '%s', // stone_color
            '%s', // stone_setting
            '%s', // stone_clarity
            '%s', // stone_cut_grade
            '%s', // origin_country
            '%s', // certificate
            '%s', // custom_comment
            '%d'  // created_by
        ];
        
        if ($this->id) {
            // Update existing stone
            unset($data['created_by']); // Don't update creator
            array_pop($formats); // Remove created_by format
            
            $result = $wpdb->update(
                $wpdb->prefix . 'cjs_stones',
                $data,
                ['id' => $this->id],
                $formats,
                ['%d']
            );
            
            if ($result !== false) {
                CJS_Logger::log('Stone updated', 'info', 'stone', $this->id, $data);
            } else {
                error_log('CJS: Failed to update stone ' . $this->id . '. Error: ' . $wpdb->last_error);
                error_log('CJS: Data: ' . print_r($data, true));
            }
        } else {
            // Insert new stone
            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_stones',
                $data,
                $formats
            );
            
            if ($result) {
                $this->id = $wpdb->insert_id;
                $this->data['id'] = $this->id;
                
                CJS_Logger::log('Stone created', 'success', 'stone', $this->id, $data);
            } else {
                error_log('CJS: Failed to create stone. Error: ' . $wpdb->last_error);
                error_log('CJS: Data: ' . print_r($data, true));
                error_log('CJS: Formats: ' . print_r($formats, true));
            }
            }
        
            return $this->id;
    }
    
    /**
     * Delete stone
     */
    public function delete() {
        global $wpdb;
        
        if (!$this->id) {
            return false;
        }
        
        // Remove from stone orders first
        $wpdb->delete(
            $wpdb->prefix . 'cjs_stone_order_items',
            ['stone_id' => $this->id],
            ['%d']
        );
        
        // Delete stone
        $result = $wpdb->delete(
            $wpdb->prefix . 'cjs_stones',
            ['id' => $this->id],
            ['%d']
        );
        
        if ($result) {
            CJS_Logger::log('Stone deleted', 'warning', 'stone', $this->id);
        }
        
        return $result !== false;
    }
    
    /**
     * Get stone's order info
     */
    public function get_stone_order() {
        global $wpdb;
        
        if (!$this->id) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT so.* FROM {$wpdb->prefix}cjs_stone_orders so
             INNER JOIN {$wpdb->prefix}cjs_stone_order_items soi ON so.id = soi.stone_order_id
             WHERE soi.stone_id = %d",
            $this->id
        ));
    }
    
    /**
     * Get formatted display string with new size format
     */
    public function get_display_string() {
        $parts = [];
        
        if ($this->get('stone_type')) {
            $parts[] = $this->get('stone_type');
        }
        
        if ($this->get('stone_origin')) {
            $parts[] = $this->get('stone_origin');
        }
        
        if ($this->get('stone_shape')) {
            $parts[] = $this->get('stone_shape');
        }
        
        // Use new size format
        $formatted_size = $this->get_formatted_size();
        if ($formatted_size) {
            $parts[] = $formatted_size;
        }
        
        if ($this->get('stone_quantity')) {
            $parts[] = $this->get('stone_quantity') . 'pc';
        }
        
        return implode(', ', $parts);
    }
    
    /**
     * Get stones by order
     */
    public static function get_by_order($order_id) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_stones WHERE order_id = %d ORDER BY id DESC",
            $order_id
        ));
        
        $stones = [];
        foreach ($results as $result) {
            $stones[] = self::from_row($result);
        }
        
        return $stones;
    }
    
    /**
     * Get stones by order item
     */
    public static function get_by_order_item($order_item_id) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_stones WHERE order_item_id = %d ORDER BY id DESC",
            $order_item_id
        ));
        
        $stones = [];
        foreach ($results as $result) {
            $stones[] = self::from_row($result);
        }
        
        return $stones;
    }
    
    /**
     * Get all stones with filters
     */
    public static function get_stones($args = []) {
        global $wpdb;
        
        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'order_id' => null,
            'order_item_id' => null,
            'has_stone_order' => null,
            'search' => null,
            'orderby' => 'id',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $values = [];
        
        if ($args['order_id']) {
            $where[] = 'order_id = %d';
            $values[] = $args['order_id'];
        }
        
        if ($args['order_item_id']) {
            $where[] = 'order_item_id = %d';
            $values[] = $args['order_item_id'];
        }
        
        if ($args['search']) {
            $where[] = '(stone_type LIKE %s OR stone_origin LIKE %s OR stone_shape LIKE %s OR custom_comment LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}cjs_stones WHERE $where_clause";
        if (!empty($values)) {
            $count_query = $wpdb->prepare($count_query, $values);
        }
        $total = $wpdb->get_var($count_query);
        
        // Get stones
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = in_array($args['orderby'], ['id', 'created_at', 'stone_quantity']) ? $args['orderby'] : 'id';
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
        
        $query = "SELECT s.*, 
                  CASE WHEN EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}cjs_stone_order_items soi 
                      WHERE soi.stone_id = s.id
                  ) THEN 1 ELSE 0 END as has_stone_order
                  FROM {$wpdb->prefix}cjs_stones s
                  WHERE $where_clause
                  ORDER BY $orderby $order
                  LIMIT %d, %d";
        
        $values[] = $offset;
        $values[] = $args['per_page'];
        
        $results = $wpdb->get_results($wpdb->prepare($query, $values));
        
        // Convert to Stone objects
        $stones = [];
        foreach ($results as $result) {
            $stones[] = self::from_row($result);
        }
        
        return [
            'stones' => $stones,
            'total' => $total,
            'pages' => ceil($total / $args['per_page'])
        ];
    }
    
    /**
     * Check if stone is valid
     */
    public function is_valid() {
        return !empty($this->get('stone_origin')) && !empty($this->get('stone_quantity'));
    }
    
    /**
     * Get all stone data as array
     */
    public function to_array() {
        $data = $this->data;
        $data['id'] = $this->id;
        
        // Add computed fields for compatibility
        $data['formatted_size'] = $this->get_formatted_size();
        $data['size_value'] = $this->get_size_value();
        $data['size_unit'] = $this->get_size_unit();
        
        return $data;
    }
}