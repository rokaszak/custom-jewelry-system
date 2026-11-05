<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Calendar_Interval {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cjs_calendar_intervals';
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ));
    }

    public static function get_range($start, $end, $resource_id = 1) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND start_datetime < %s AND end_datetime > %s ORDER BY start_datetime ASC",
            $resource_id,
            $end,
            $start
        ));
    }

    public static function duration_hours($row) {
        $seconds = strtotime($row->end_datetime) - strtotime($row->start_datetime);
        return round(max(0, $seconds) / 3600, 2);
    }

    public static function create($data) {
        global $wpdb;

        $insert = [
            'resource_id' => isset($data['resource_id']) ? absint($data['resource_id']) : 1,
            'order_id' => !empty($data['order_id']) ? absint($data['order_id']) : null,
            'type' => isset($data['type']) ? sanitize_key($data['type']) : 'work',
            'name' => isset($data['name']) ? sanitize_text_field($data['name']) : '',
            'start_datetime' => $data['start_datetime'],
            'end_datetime' => $data['end_datetime'],
            'is_locked' => !empty($data['is_locked']) ? 1 : 0,
            'is_done' => !empty($data['is_done']) ? 1 : 0,
            'color' => !empty($data['color']) ? sanitize_hex_color($data['color']) : null,
            'deadline_date' => !empty($data['deadline_date']) ? $data['deadline_date'] : null,
            'order_finish' => !empty($data['order_finish']) ? $data['order_finish'] : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $result = $wpdb->insert(self::table(), $insert);
        if ($result === false) {
            return false;
        }

        $id = $wpdb->insert_id;

        if ($insert['is_done'] && $insert['order_id']) {
            self::adjust_completed_hours($insert['order_id'], self::duration_hours((object) $insert));
        }

        return $id;
    }

    public static function update($id, $data) {
        global $wpdb;

        $old = self::get($id);
        if (!$old) {
            return false;
        }

        $update = [];
        foreach (['name', 'start_datetime', 'end_datetime'] as $field) {
            if (isset($data[$field])) {
                $update[$field] = $field === 'name' ? sanitize_text_field($data[$field]) : $data[$field];
            }
        }
        if (isset($data['type'])) {
            $update['type'] = sanitize_key($data['type']);
        }
        if (array_key_exists('color', $data)) {
            $update['color'] = !empty($data['color']) ? sanitize_hex_color($data['color']) : null;
        }
        if (isset($data['is_locked'])) {
            $update['is_locked'] = !empty($data['is_locked']) ? 1 : 0;
        }
        if (isset($data['is_done'])) {
            $update['is_done'] = !empty($data['is_done']) ? 1 : 0;
        }

        if (empty($update)) {
            return true;
        }

        $update['updated_at'] = current_time('mysql');

        $result = $wpdb->update(self::table(), $update, ['id' => $id]);
        if ($result === false) {
            return false;
        }

        if ($old->order_id) {
            $new = self::get($id);
            $old_hours = self::duration_hours($old);
            $new_hours = self::duration_hours($new);
            $was_done = (int) $old->is_done;
            $is_done = (int) $new->is_done;

            $delta = 0;
            if ($was_done && $is_done) {
                $delta = $new_hours - $old_hours;
            } elseif (!$was_done && $is_done) {
                $delta = $new_hours;
            } elseif ($was_done && !$is_done) {
                $delta = -$old_hours;
            }

            if (abs($delta) > 0.001) {
                self::adjust_completed_hours($old->order_id, $delta);
            }
        }

        return true;
    }

    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete(self::table(), ['id' => $id], ['%d']) !== false;
    }

    public static function split($id, $at_datetime) {
        global $wpdb;

        $row = self::get($id);
        if (!$row) {
            return false;
        }

        $start = strtotime($row->start_datetime);
        $end = strtotime($row->end_datetime);
        $at = strtotime($at_datetime);

        if ($at <= $start || $at >= $end) {
            return false;
        }

        $at_mysql = date('Y-m-d H:i:s', $at);

        $wpdb->update(self::table(), [
            'end_datetime' => $at_mysql,
            'updated_at' => current_time('mysql')
        ], ['id' => $id]);

        $wpdb->insert(self::table(), [
            'resource_id' => $row->resource_id,
            'order_id' => $row->order_id,
            'type' => $row->type,
            'name' => $row->name,
            'start_datetime' => $at_mysql,
            'end_datetime' => $row->end_datetime,
            'is_locked' => $row->is_locked,
            'is_done' => $row->is_done,
            'color' => $row->color,
            'deadline_date' => $row->deadline_date,
            'order_finish' => $row->order_finish,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
    }

    public static function adjust_completed_hours($order_id, $delta) {
        global $wpdb;

        $ext = $wpdb->get_row($wpdb->prepare(
            "SELECT assigned_hours, completed_hours, manufacturing_status FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
            $order_id
        ));

        if (!$ext) {
            return;
        }

        $completed = max(0, round((float) $ext->completed_hours + $delta, 2));
        $before = ['assigned' => (float) $ext->assigned_hours, 'completed' => (float) $ext->completed_hours];

        CJS_Order_Extension::update_order_extension($order_id, ['completed_hours' => $completed]);

        if ($delta > 0) {
            try {
                CJS_Statuses::hours_advance((int) $order_id, $before);
            } catch (Throwable $e) {
                error_log('CJS hours auto-advance failed for order ' . $order_id . ': ' . $e->getMessage());
            }
        }
    }

    public static function locked_order_ids($resource_id = 1, $scope = '') {
        global $wpdb;
        if (!class_exists('CJS_Inv_Demands') || !class_exists('CJS_Woo_Sync')) {
            return [];
        }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT order_id FROM " . self::table() . " WHERE resource_id = %d AND order_id IS NOT NULL", $resource_id) . ($scope !== '' ? ' AND (' . $scope . ')' : '')) ?: [];
        return self::locked_among($ids);
    }

    private static function locked_among(array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !class_exists('CJS_Woo_Sync')) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (array_keys(CJS_Woo_Sync::calendar_locks($chunk)) as $id) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    private static function skip_locked(array $rows, array $locked) {
        return array_values(array_filter($rows, function ($row) use ($locked) {
            return !in_array((int) $row->order_id, $locked, true);
        }));
    }

    private static function lock_now($order_id, array &$locked) {
        $order_id = (int) $order_id;
        if (in_array($order_id, $locked, true)) {
            return true;
        }
        if (class_exists('CJS_Woo_Sync') && CJS_Woo_Sync::calendar_lock($order_id) !== null) {
            $locked[] = $order_id;
            return true;
        }
        return false;
    }

    private static function still_open($row) {
        $fresh = self::get((int) $row->id);
        return $fresh && (int) $fresh->is_done === 0 ? $fresh : null;
    }

    public static function mark_passed_work_done($now_mysql, $resource_id = 1) {
        global $wpdb;

        $locked = self::locked_order_ids($resource_id, $wpdb->prepare("type = 'work' AND is_done = 0 AND start_datetime < %s", $now_mysql));
        $done = 0;

        $spanning = self::skip_locked($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND is_done = 0 AND order_id IS NOT NULL AND start_datetime < %s AND end_datetime > %s ORDER BY start_datetime ASC, id ASC",
            $resource_id,
            $now_mysql,
            $now_mysql
        )) ?: [], $locked);

        foreach ($spanning as $row) {
            if (self::lock_now($row->order_id, $locked)) {
                continue;
            }
            $row = self::still_open($row);
            if (!$row || strtotime($row->start_datetime) >= strtotime($now_mysql) || strtotime($row->end_datetime) <= strtotime($now_mysql)) {
                continue;
            }
            if (self::split($row->id, $now_mysql)) {
                $wpdb->update(self::table(), ['is_done' => 1, 'updated_at' => current_time('mysql')], ['id' => $row->id]);
                $hours = round((strtotime($now_mysql) - strtotime($row->start_datetime)) / 3600, 2);
                self::adjust_completed_hours($row->order_id, $hours);
                $done++;
                self::lock_now($row->order_id, $locked);
            }
        }

        $rows = self::skip_locked($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND is_done = 0 AND end_datetime <= %s AND order_id IS NOT NULL ORDER BY start_datetime ASC, id ASC",
            $resource_id,
            $now_mysql
        )) ?: [], $locked);

        foreach ($rows as $row) {
            if (self::lock_now($row->order_id, $locked)) {
                continue;
            }
            $row = self::still_open($row);
            if (!$row) {
                continue;
            }
            $wpdb->update(self::table(), ['is_done' => 1, 'updated_at' => current_time('mysql')], ['id' => $row->id]);
            self::adjust_completed_hours($row->order_id, self::duration_hours($row));
            $done++;
            self::lock_now($row->order_id, $locked);
        }

        return $done;
    }

    public static function remove_open_entries($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }
        $n = $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE order_id = %d AND is_done = 0", $order_id));
        if ($n === false) {
            error_log('CJS calendar entries of order ' . $order_id . ' could not be removed: ' . $wpdb->last_error);
            return 0;
        }
        if ($n > 0 && class_exists('CJS_Logger')) {
            CJS_Logger::log('Calendar entries that were not done removed', 'info', 'order', $order_id, ['count' => (int) $n]);
        }
        return (int) $n;
    }

    public static function remove_locked_open_work($resource_id = 1) {
        global $wpdb;
        $t = self::table();
        $locked = self::locked_order_ids($resource_id, "order_id IN (SELECT o.order_id FROM (SELECT DISTINCT order_id FROM $t WHERE is_done = 0 AND order_id IS NOT NULL) o)");
        $n = 0;
        foreach (array_chunk($locked, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $counts = $wpdb->get_results("SELECT order_id, COUNT(*) AS n FROM $t WHERE is_done = 0 AND order_id IN ($in) GROUP BY order_id", ARRAY_A) ?: [];
            if (!$counts) {
                continue;
            }
            $deleted = $wpdb->query("DELETE FROM $t WHERE is_done = 0 AND order_id IN ($in)");
            if ($deleted === false) {
                error_log('CJS calendar entries of locked orders could not be removed: ' . $wpdb->last_error);
                continue;
            }
            $n += (int) $deleted;
            if (class_exists('CJS_Logger')) {
                foreach ($counts as $c) {
                    if ((int) $c['n'] > 0) {
                        CJS_Logger::log('Calendar entries that were not done removed', 'info', 'order', (int) $c['order_id'], ['count' => (int) $c['n']]);
                    }
                }
            }
        }
        return $n;
    }

    public static function clear_unconfirmed_work($resource_id = 1) {
        global $wpdb;
        $t = self::table();
        $where = $wpdb->prepare("resource_id = %d AND type = 'work' AND (is_done = 1 OR is_locked = 0)", $resource_id);
        $wpdb->query("DELETE FROM $t WHERE $where AND order_id IS NULL");
        $unlocked = self::unlocked_work_orders($resource_id);
        if ($unlocked === null) {
            $ids = array_map('intval', $wpdb->get_col("SELECT DISTINCT order_id FROM $t WHERE $where AND order_id IS NOT NULL") ?: []);
            $unlocked = $ids ? array_values(array_diff($ids, self::locked_among($ids))) : [];
        }
        foreach (array_chunk($unlocked, 500) as $chunk) {
            $wpdb->query("DELETE FROM $t WHERE $where AND order_id IN (" . implode(',', array_map('intval', $chunk)) . ')');
        }
    }

    private static function unlocked_work_orders($resource_id) {
        global $wpdb;
        if (!class_exists('CJS_Woo_Sync') || !class_exists('CJS_Inv_Flow') || !class_exists('CJS_Orders')) {
            return null;
        }
        try {
            $ext = $wpdb->prefix . 'cjs_order_extensions';
            $min = CJS_Inv_Flow::rank('pagaminta');
            $made = [];
            foreach ($wpdb->get_col("SELECT DISTINCT manufacturing_status FROM $ext WHERE manufacturing_status IS NOT NULL") ?: [] as $value) {
                $key = CJS_Inv_Flow::status_key((string) $value) ?? 'nepradeta';
                if (CJS_Inv_Flow::rank($key) >= $min) {
                    $made[] = $wpdb->prepare('%s', (string) $value);
                }
            }
            $joins = "LEFT JOIN $ext e ON e.order_id = t.order_id";
            $status = "COALESCE(p.post_status, '')";
            if (CJS_Orders::hpos()) {
                $joins .= " LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id = t.order_id";
                $status = "COALESCE(o.status, p.post_status, '')";
            }
            $joins .= " LEFT JOIN {$wpdb->posts} p ON p.ID = t.order_id AND p.post_type = 'shop_order'";
            $made_sql = $made ? "COALESCE(e.manufacturing_status, '') IN (" . implode(',', $made) . ')' : '0 = 1';
            $sql = $wpdb->prepare("SELECT DISTINCT t.order_id FROM " . self::table() . " t $joins WHERE t.resource_id = %d AND t.type = 'work' AND (t.is_done = 1 OR t.is_locked = 0) AND t.order_id IS NOT NULL AND $status NOT IN ('wc-completed', 'completed') AND NOT (e.order_id IS NOT NULL AND $made_sql)", $resource_id);
            $suppress = $wpdb->suppress_errors(true);
            $ids = $wpdb->get_col($sql);
            $failed = $wpdb->last_error !== '';
            $wpdb->suppress_errors($suppress);
            if ($failed || !is_array($ids)) {
                return null;
            }
            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function get_blocking($now_mysql, $resource_id = 1) {
        global $wpdb;

        $locked = self::locked_order_ids($resource_id, $wpdb->prepare("end_datetime > %s AND is_done = 0 AND is_locked = 0 AND type = 'work'", $now_mysql));
        $also = $locked ? ' OR order_id IN (' . implode(',', array_map('intval', $locked)) . ')' : '';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND end_datetime > %s AND (is_locked = 1 OR is_done = 1 OR type <> 'work'" . $also . ") ORDER BY start_datetime ASC",
            $resource_id,
            $now_mysql
        ));
    }

    public static function locked_future_work_hours($now_mysql, $resource_id = 1) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, start_datetime, end_datetime FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND is_locked = 1 AND is_done = 0 AND end_datetime > %s AND order_id IS NOT NULL",
            $resource_id,
            $now_mysql
        ));

        $hours = [];
        $now_ts = strtotime($now_mysql);
        foreach ($rows as $row) {
            $start = max(strtotime($row->start_datetime), $now_ts);
            $end = strtotime($row->end_datetime);
            if ($end <= $start) {
                continue;
            }
            $order_id = (int) $row->order_id;
            $hours[$order_id] = ($hours[$order_id] ?? 0) + ($end - $start) / 3600;
        }

        return $hours;
    }
}
