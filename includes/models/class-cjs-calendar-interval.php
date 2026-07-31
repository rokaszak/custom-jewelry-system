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

        CJS_Order_Extension::update_order_extension($order_id, ['completed_hours' => $completed]);

        $assigned = (float) $ext->assigned_hours;
        if ($delta > 0 && $assigned > 0 && $completed >= $assigned
            && CJS_Order_Extension::can_auto_advance_status($ext->manufacturing_status, 'Pagaminta')) {
            CJS_Order_Extension::update_order_extension($order_id, ['manufacturing_status' => 'Pagaminta']);
            CJS_Logger::log('Order auto-marked Pagaminta (visos valandos atliktos)', 'info', 'order', $order_id, ['completed_hours' => $completed, 'assigned_hours' => $assigned]);
        }
    }

    public static function mark_passed_work_done($now_mysql, $resource_id = 1) {
        global $wpdb;

        $spanning = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND is_done = 0 AND order_id IS NOT NULL AND start_datetime < %s AND end_datetime > %s",
            $resource_id,
            $now_mysql,
            $now_mysql
        ));

        foreach ($spanning as $row) {
            if (self::split($row->id, $now_mysql)) {
                $wpdb->update(self::table(), ['is_done' => 1, 'updated_at' => current_time('mysql')], ['id' => $row->id]);
                $hours = round((strtotime($now_mysql) - strtotime($row->start_datetime)) / 3600, 2);
                self::adjust_completed_hours($row->order_id, $hours);
            }
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND is_done = 0 AND end_datetime <= %s AND order_id IS NOT NULL",
            $resource_id,
            $now_mysql
        ));

        foreach ($rows as $row) {
            $wpdb->update(self::table(), ['is_done' => 1, 'updated_at' => current_time('mysql')], ['id' => $row->id]);
            self::adjust_completed_hours($row->order_id, self::duration_hours($row));
        }

        return count($rows) + count($spanning);
    }

    public static function clear_unconfirmed_work($resource_id = 1) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE resource_id = %d AND type = 'work' AND (is_done = 1 OR is_locked = 0)",
            $resource_id
        ));
    }

    public static function get_blocking($now_mysql, $resource_id = 1) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE resource_id = %d AND end_datetime > %s AND (is_locked = 1 OR is_done = 1 OR type <> 'work') ORDER BY start_datetime ASC",
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
