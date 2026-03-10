<?php
/**
 * Inventory Item Model - CRUD and event history
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inventory_Item {

    private $id;
    private $data = [];

    public function __construct($item_id = 0) {
        if ($item_id) {
            $this->id = absint($item_id);
            $this->load();
        }
    }

    public static function from_row($row) {
        $item = new self();
        $item->id = $row->id;
        $item->data = (array) $row;
        return $item;
    }

    private function load() {
        global $wpdb;
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_inventory_items WHERE id = %d",
            $this->id
        ), ARRAY_A);
        if ($data) {
            $this->data = $data;
        }
    }

    public function get($key) {
        if ($key === 'id') {
            return $this->id;
        }
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    public function set($key, $value) {
        if ($key === 'id') {
            $this->id = absint($value);
        } else {
            $this->data[$key] = $value;
        }
    }

    public function get_id() {
        return $this->id;
    }

    public function get_events() {
        $raw = $this->get('events');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function append_event($field, $old_value, $new_value) {
        $events = $this->get_events();
        $user = wp_get_current_user();
        $events[] = [
            'timestamp' => current_time('Y-m-d H:i'),
            'field'     => $field,
            'old'       => $old_value === null ? '' : (string) $old_value,
            'new'       => $new_value === null ? '' : (string) $new_value,
            'user'      => $user->display_name ?: 'unknown'
        ];
        $this->set('events', wp_json_encode($events));
    }

    public function get_display_string() {
        $name = $this->get('name');
        $identifier = $this->get('identifier');
        if ($name && $identifier) {
            return $name . ' (' . $identifier . ')';
        }
        if ($name) {
            return $name;
        }
        if ($identifier) {
            return $identifier;
        }
        return '#' . $this->id;
    }

    public function save() {
        global $wpdb;

        $tracked = ['item_status', 'item_category', 'order_id', 'name', 'identifier'];
        $old_data = [];
        if ($this->id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT name, identifier, order_id, item_status, item_category, events FROM {$wpdb->prefix}cjs_inventory_items WHERE id = %d",
                $this->id
            ), ARRAY_A);
            if ($row) {
                $old_data = $row;
                if (!empty($row['events'])) {
                    $old_data['events'] = $row['events'];
                }
            }
        }

        $order_id = $this->get('order_id');
        $data = [
            'name'         => $this->get('name') ? sanitize_text_field($this->get('name')) : null,
            'identifier'   => $this->get('identifier') ? sanitize_text_field($this->get('identifier')) : null,
            'order_id'     => $order_id ? absint($order_id) : null,
            'item_status'  => $this->get('item_status') ? sanitize_text_field($this->get('item_status')) : 'Sandėlyje',
            'item_category'=> $this->get('item_category') ? sanitize_text_field($this->get('item_category')) : null,
            'created_by'   => get_current_user_id()
        ];

        if ($this->id) {
            foreach ($tracked as $field) {
                $old = isset($old_data[$field]) ? $old_data[$field] : null;
                $new = isset($data[$field]) ? $data[$field] : $this->get($field);
                if ($field === 'order_id') {
                    $new = $data['order_id'];
                }
                if ((string) $old !== (string) $new) {
                    $this->append_event($field, $old, $new);
                }
            }
            $data['events'] = $this->get('events');
            unset($data['created_by']);

            $result = $wpdb->update(
                $wpdb->prefix . 'cjs_inventory_items',
                $data,
                ['id' => $this->id],
                ['%s', '%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
            if ($result !== false && class_exists('CJS_Logger')) {
                CJS_Logger::log('Inventory item updated', 'info', 'inventory_item', $this->id, $data);
            }
        } else {
            $data['events'] = wp_json_encode([]);
            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_inventory_items',
                $data,
                ['%s', '%s', '%d', '%s', '%s', '%d', '%s']
            );
            if ($result) {
                $this->id = $wpdb->insert_id;
                $this->data['id'] = $this->id;
                $this->data = array_merge($this->data, $data);
                if (class_exists('CJS_Logger')) {
                    CJS_Logger::log('Inventory item created', 'success', 'inventory_item', $this->id, $data);
                }
            }
        }
        return $this->id;
    }

    public function delete() {
        global $wpdb;
        if (!$this->id) {
            return false;
        }
        $result = $wpdb->delete(
            $wpdb->prefix . 'cjs_inventory_items',
            ['id' => $this->id],
            ['%d']
        );
        if ($result && class_exists('CJS_Logger')) {
            CJS_Logger::log('Inventory item deleted', 'warning', 'inventory_item', $this->id);
        }
        return $result !== false;
    }

    public function to_array() {
        $arr = $this->data;
        $arr['id'] = $this->id;
        $arr['display_string'] = $this->get_display_string();
        return $arr;
    }

    public static function get_by_order($order_id) {
        global $wpdb;
        $order_id = absint($order_id);
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_inventory_items WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ));
        $items = [];
        foreach ($results as $row) {
            $items[] = self::from_row($row);
        }
        return $items;
    }

    public static function get_available($category = null, $status = null) {
        global $wpdb;
        $where = 'order_id IS NULL';
        $values = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND item_status = %s';
            $values[] = $status;
        }
        if ($category !== null && $category !== '') {
            $where .= ' AND item_category = %s';
            $values[] = $category;
        }
        $sql = "SELECT * FROM {$wpdb->prefix}cjs_inventory_items WHERE $where ORDER BY id ASC";
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        $results = $wpdb->get_results($sql);
        $items = [];
        foreach ($results as $row) {
            $items[] = self::from_row($row);
        }
        return $items;
    }

    public static function get_items($args = []) {
        global $wpdb;

        $defaults = [
            'page'      => 1,
            'per_page'  => 20,
            'search'    => '',
            'status'    => '',
            'category'  => '',
            'orderby'   => 'id',
            'order'     => 'DESC'
        ];
        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $values = [];

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(name LIKE %s OR identifier LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }
        if ($args['status'] !== '') {
            $where[] = 'item_status = %s';
            $values[] = $args['status'];
        }
        if ($args['category'] !== '') {
            $where[] = 'item_category = %s';
            $values[] = $args['category'];
        }

        $where_sql = implode(' AND ', $where);

        $total = $wpdb->get_var(
            empty($values)
                ? "SELECT COUNT(*) FROM {$wpdb->prefix}cjs_inventory_items WHERE $where_sql"
                : $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cjs_inventory_items WHERE $where_sql", $values)
        );

        $orderby = in_array($args['orderby'], ['id', 'created_at', 'name', 'identifier', 'item_status', 'item_category'], true) ? $args['orderby'] : 'id';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $sql = "SELECT * FROM {$wpdb->prefix}cjs_inventory_items WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;
        $results = $wpdb->get_results($wpdb->prepare($sql, $values));

        $items = [];
        foreach ($results as $row) {
            $items[] = self::from_row($row);
        }

        return [
            'items' => $items,
            'total' => (int) $total,
            'pages' => ceil((int) $total / $args['per_page'])
        ];
    }

    /**
     * Get the page number that contains the given inventory item (for deep-linking).
     */
    public static function get_page_for_item($item_id, $status = '', $category = '', $per_page = 20) {
        $result = self::get_items([
            'page'     => 1,
            'per_page' => 9999,
            'status'   => $status,
            'category' => $category
        ]);
        foreach ($result['items'] as $i => $item) {
            if ((int) $item->get_id() === (int) $item_id) {
                return floor($i / $per_page) + 1;
            }
        }
        return 1;
    }

    public static function search_orders($term, $limit = 20) {
        if (!class_exists('WooCommerce')) {
            return [];
        }
        $term = trim($term);
        if (strlen($term) < 2) {
            return [];
        }
        $args = [
            'type'   => 'shop_order',
            'limit'  => $limit,
            'return' => 'ids'
        ];
        if (is_numeric($term)) {
            $args['include'] = [absint($term)];
        } else {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_billing_first_name', 'value' => $term, 'compare' => 'LIKE'],
                ['key' => '_billing_last_name', 'value' => $term, 'compare' => 'LIKE'],
                ['key' => '_billing_email', 'value' => $term, 'compare' => 'LIKE']
            ];
        }
        $query = new WC_Order_Query($args);
        $ids = $query->get_orders();
        $out = [];
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $out[] = [
                    'id'   => $id,
                    'text' => '#' . $order->get_order_number() . ' - ' . $order->get_formatted_billing_full_name()
                ];
            }
        }
        return $out;
    }
}
