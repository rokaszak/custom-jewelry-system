<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Discrepancies {

    const CRON_HOOK = 'cjs_inv_daily';

    public static function add($type, array $data) {
        return CJS_Inv_DB::insert('discrepancies', [
            'type' => sanitize_key($type),
            'item_id' => $data['item_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'demand_id' => $data['demand_id'] ?? null,
            'qty' => isset($data['qty']) ? CJS_Inv_DB::num($data['qty']) : null,
            'details' => isset($data['details']) ? (string) $data['details'] : null,
            'resolved' => 0,
            'created_at' => $data['created_at'] ?? CJS_Inv_DB::now(),
        ]);
    }

    public static function open_count() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('discrepancies') . ' WHERE resolved = 0');
    }

    private static function has_resolution_column() {
        static $has = null;
        if ($has === null) {
            global $wpdb;
            $has = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . CJS_Inv_DB::t('discrepancies') . ' LIKE %s', 'resolution'));
        }
        return $has;
    }

    public static function list(array $args = []) {
        global $wpdb;
        $where = '1=1';
        $state = $args['state'] ?? 'open';
        if ($state === 'open') {
            $where .= ' AND x.resolved = 0';
        } elseif ($state === 'resolved') {
            $where .= ' AND x.resolved = 1';
        }
        $exclude = array_values(array_filter(array_map('sanitize_key', (array) ($args['exclude_types'] ?? []))));
        if ($exclude) {
            $where .= " AND x.type NOT IN ('" . implode("','", $exclude) . "')";
        }
        $per_page = min(200, max(1, absint($args['per_page'] ?? 50)));
        $page = max(1, absint($args['page'] ?? 1));
        $offset = isset($args['offset']) ? absint($args['offset']) : ($page - 1) * $per_page;
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('discrepancies') . " x WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT x.*, i.name AS item_name, i.category_id, i.unit_id, u.display_name AS resolved_by_name FROM ' . CJS_Inv_DB::t('discrepancies') . ' x
             LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = x.item_id
             LEFT JOIN ' . $wpdb->users . " u ON u.ID = x.resolved_by
             WHERE $where ORDER BY x.created_at DESC, x.id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A) ?: [];
        $orders = CJS_Orders::order_labels(array_map(function ($r) { return (int) $r['order_id']; }, $rows));
        return [
            'discrepancies' => array_map(function ($r) use ($orders) {
                $resolution = $r['resolution'] ?? null;
                if ((int) $r['resolved'] === 1 && !$resolution) {
                    $resolution = 'manual';
                }
                return [
                    'id' => (int) $r['id'],
                    'type' => $r['type'],
                    'item_id' => $r['item_id'] !== null ? (int) $r['item_id'] : null,
                    'item_name' => $r['item_name'],
                    'category_id' => (int) ($r['category_id'] ?? 0),
                    'unit' => $r['unit_id'] !== null ? CJS_Inv_Catalog::unit_label($r['unit_id']) : '',
                    'order' => $r['order_id'] ? ($orders[(int) $r['order_id']] ?? null) : null,
                    'qty' => $r['qty'] !== null ? (float) $r['qty'] : null,
                    'details' => $r['details'],
                    'resolved' => (bool) $r['resolved'],
                    'resolution' => (int) $r['resolved'] === 1 ? $resolution : null,
                    'resolved_by' => (int) $r['resolved'] === 1 ? ($r['resolved_by_name'] ?: __('System', 'custom-jewelry-system')) : null,
                    'resolved_at' => $r['resolved_at'],
                    'created_at' => $r['created_at'],
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $per_page),
            'open_count' => self::open_count(),
        ];
    }

    public static function resolve($id, $resolved = true, $resolution = 'manual', $user_id = null) {
        $row = [
            'resolved' => $resolved ? 1 : 0,
            'resolved_by' => $resolved ? ($user_id !== null ? (int) $user_id : get_current_user_id()) : null,
            'resolved_at' => $resolved ? CJS_Inv_DB::now() : null,
        ];
        if (self::has_resolution_column()) {
            $row['resolution'] = $resolved ? sanitize_key($resolution) : null;
        }
        CJS_Inv_DB::update('discrepancies', $row, ['id' => absint($id)]);
        return true;
    }

    public static function auto_close(array $item_ids = [], array $order_ids = [], $counted = false, $user_id = 0) {
        global $wpdb;
        try {
            $item_ids = array_values(array_filter(array_map('absint', $item_ids)));
            $order_ids = array_values(array_filter(array_map('absint', $order_ids)));
            $where = 'resolved = 0';
            $scope = [];
            if ($item_ids) {
                $scope[] = 'item_id IN (' . CJS_Inv_DB::ids_in($item_ids) . ')';
            }
            if ($order_ids) {
                $scope[] = 'order_id IN (' . CJS_Inv_DB::ids_in($order_ids) . ')';
            }
            if ($scope) {
                $where .= ' AND (' . implode(' OR ', $scope) . ')';
            }
            $rows = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('discrepancies') . " WHERE $where ORDER BY id ASC LIMIT 1000", ARRAY_A) ?: [];
            $closed = 0;
            $current = null;
            foreach ($rows as $r) {
                $resolution = null;
                if ($r['type'] === 'used_without_stock') {
                    $resolution = self::check_shortfall($r, $counted && in_array((int) $r['item_id'], $item_ids, true));
                } elseif ($r['type'] === 'integrity') {
                    if ($current === null) {
                        $current = self::current_issue_details();
                    }
                    if (!isset($current[(string) $r['details']])) {
                        $resolution = 'fixed';
                    }
                } elseif ($r['type'] === 'unattended' && class_exists('CJS_Woo_Sync')) {
                    if (CJS_Woo_Sync::in_order((int) $r['order_id'])) {
                        $resolution = 'fixed';
                    }
                }
                if ($resolution) {
                    self::resolve((int) $r['id'], true, $resolution, (int) $user_id);
                    $closed++;
                }
            }
            return $closed;
        } catch (Throwable $e) {
            error_log('CJS discrepancy auto close failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function check_shortfall(array $r, $counted_now) {
        global $wpdb;
        $demand = $r['demand_id'] ? CJS_Inv_Demands::row((int) $r['demand_id']) : null;
        if (!$demand || $demand['state'] !== 'done') {
            return 'fixed';
        }
        if ((float) $demand['qty_allocated'] >= (float) $demand['qty'] - 0.0001) {
            return 'fixed';
        }
        if ($counted_now) {
            return 'count';
        }
        if ($r['item_id']) {
            $counted = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('movements') . " m WHERE m.item_id = %d AND m.type = 'count' AND m.created_at >= %s",
                (int) $r['item_id'], $r['created_at']
            ));
            if ($counted > 0) {
                return 'count';
            }
        }
        return null;
    }

    private static function issue_details(array $issue) {
        return strtoupper($issue['type']) . ' #' . $issue['id'] . ': ' . implode('; ', $issue['problems']);
    }

    private static function current_issue_details() {
        $report = CJS_Inv_Stock::integrity_report(100000);
        $out = [];
        foreach ($report['issues'] as $issue) {
            $out[self::issue_details($issue)] = true;
        }
        return $out;
    }

    public static function run_integrity_check() {
        global $wpdb;
        $report = CJS_Inv_Stock::integrity_report(500);
        $added = 0;
        foreach ($report['issues'] as $issue) {
            $details = self::issue_details($issue);
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('discrepancies') . " WHERE type = 'integrity' AND resolved = 0 AND details = %s", $details));
            if ($exists) {
                continue;
            }
            self::add('integrity', [
                'item_id' => $issue['item_id'] ?? ($issue['type'] === 'item' ? $issue['id'] : null),
                'batch_id' => $issue['type'] === 'batch' ? $issue['id'] : null,
                'demand_id' => $issue['type'] === 'demand' ? $issue['id'] : null,
                'details' => $details,
            ]);
            $added++;
        }
        $closed = self::auto_close();
        return ['issues' => $report['issue_count'], 'added' => $added, 'closed' => $closed];
    }

    public static function ensure_scheduled() {
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
    }

    public static function daily() {
        try {
            if (!class_exists('WooCommerce') || CJS_Inv_DB::missing_tables()) {
                return;
            }
            CJS_Inv_Demands::reserve_pending();
            self::run_integrity_check();
        } catch (Throwable $e) {
            error_log('CJS daily inventory check failed: ' . $e->getMessage());
        }
    }
}
