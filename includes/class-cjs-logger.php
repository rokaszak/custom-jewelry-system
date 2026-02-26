<?php
/**
 * Logger class - handles activity logging
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Logger {
    
    /**
     * Log an activity
     * 
     * @param string $action Action performed
     * @param string $severity info|warning|error|success
     * @param string $object_type Type of object (order, stone, stone_order, etc)
     * @param int $object_id ID of the object
     * @param array $details Additional details
     */
    public static function log($action, $severity = 'info', $object_type = '', $object_id = null, $details = []) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        
        $data = [
            'user_id' => $user_id,
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => $object_id ? absint($object_id) : null,
            'details' => !empty($details) ? json_encode($details) : null,
            'severity' => sanitize_text_field($severity),
            'created_at' => current_time('mysql')
        ];
        
        $wpdb->insert(
            $wpdb->prefix . 'cjs_activity_log',
            $data,
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        // Clean old logs (keep only 30 days)
        self::clean_old_logs();
    }
    
    /**
     * Clean logs older than 30 days
     */
    private static function clean_old_logs() {
        global $wpdb;
        
        // Run cleanup only 1% of the time to avoid performance issues
        if (rand(1, 100) !== 1) {
            return;
        }
        
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}cjs_activity_log WHERE created_at < %s",
            $thirty_days_ago
        ));
    }
    
    /**
     * Get logs with pagination
     * 
     * @param array $args
     * @return array
     */
    public static function get_logs($args = []) {
        global $wpdb;
        
        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'user_id' => null,
            'object_type' => null,
            'severity' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $values = [];
        
        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }
        
        if ($args['object_type']) {
            $where[] = 'object_type = %s';
            $values[] = $args['object_type'];
        }
        
        if ($args['severity']) {
            $where[] = 'severity = %s';
            $values[] = $args['severity'];
        }
        
        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'];
        }
        
        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'];
        }
        
        if ($args['search']) {
            $search = trim(str_replace('#', ' ', $args['search']));
            $type_part = '';
            $id_part = null;
            if (preg_match('/\d+/', $search, $m)) {
                $id_part = (int) $m[0];
                $type_part = trim(preg_replace('/#?\d+/', '', $search));
            } else {
                $type_part = $search;
            }
            $search_conditions = [];
            if ($type_part !== '') {
                $search_conditions[] = 'object_type LIKE %s';
                $values[] = '%' . $wpdb->esc_like($type_part) . '%';
            }
            if ($id_part !== null) {
                $id_like = '%' . $wpdb->esc_like((string) $id_part) . '%';
                $search_conditions[] = '(object_id = %d OR CAST(object_id AS CHAR) LIKE %s)';
                $values[] = $id_part;
                $values[] = $id_like;
            }
            if (!empty($search_conditions)) {
                $where[] = '(' . implode(' AND ', $search_conditions) . ')';
            }
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}cjs_activity_log WHERE $where_clause";
        if (!empty($values)) {
            $count_query = $wpdb->prepare($count_query, $values);
        }
        $total = $wpdb->get_var($count_query);
        
        // Get logs
        $offset = ($args['page'] - 1) * $args['per_page'];
        $query = "SELECT l.*, u.display_name as user_name 
                  FROM {$wpdb->prefix}cjs_activity_log l
                  LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
                  WHERE $where_clause
                  ORDER BY l.created_at DESC
                  LIMIT %d, %d";
        
        $values[] = $offset;
        $values[] = $args['per_page'];
        
        $logs = $wpdb->get_results($wpdb->prepare($query, $values));
        
        return [
            'logs' => $logs,
            'total' => $total,
            'pages' => ceil($total / $args['per_page'])
        ];
    }
    
    /**
     * Get severity color
     * 
     * @param string $severity
     * @return string
     */
    public static function get_severity_color($severity) {
        $colors = [
            'info' => '#6c757d',
            'success' => '#28a745',
            'warning' => '#ffc107',
            'error' => '#dc3545'
        ];
        
        return isset($colors[$severity]) ? $colors[$severity] : '#6c757d';
    }
    
    /**
     * Format log entry for display
     * 
     * @param object $log
     * @return string
     */
    public static function format_log_entry($log) {
        $details = !empty($log->details) ? json_decode($log->details, true) : [];
        $time = date_i18n('Y-m-d H:i:s', strtotime($log->created_at));
        
        $formatted = sprintf(
            '<span style="color: %s">●</span> [%s] %s - %s',
            self::get_severity_color($log->severity),
            $time,
            esc_html($log->user_name),
            esc_html($log->action)
        );
        
        if ($log->object_type && $log->object_id) {
            $formatted .= sprintf(' (%s #%d)', esc_html($log->object_type), $log->object_id);
        }
        
        if (!empty($details)) {
            $formatted .= ' - ' . esc_html(json_encode($details));
        }
        
        return $formatted;
    }
}