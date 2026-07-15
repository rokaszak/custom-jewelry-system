<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Calendar_API {

    public static function init() {
        add_action('wp_ajax_cjs_calendar_intervals', [__CLASS__, 'ajax_intervals']);
        add_action('wp_ajax_cjs_calendar_interval_save', [__CLASS__, 'ajax_interval_save']);
        add_action('wp_ajax_cjs_calendar_interval_delete', [__CLASS__, 'ajax_interval_delete']);
        add_action('wp_ajax_cjs_calendar_interval_split', [__CLASS__, 'ajax_interval_split']);
        add_action('wp_ajax_cjs_calendar_recalculate', [__CLASS__, 'ajax_recalculate']);
        add_action('wp_ajax_cjs_calendar_save_settings', [__CLASS__, 'ajax_save_settings']);
    }

    private static function check_permission() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No permission']);
            exit;
        }
    }

    private static function verify_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_rest')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            exit;
        }
    }

    private static function parse_datetime($value) {
        $value = str_replace('T', ' ', sanitize_text_field($value));
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return false;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function text_color($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance > 0.6 ? '#1f2937' : '#ffffff';
    }

    private static function event_from_row($row) {
        $types = get_option('cjs_interval_types', []);
        $color = $row->color;
        if (empty($color)) {
            if (isset($types[$row->type]['color'])) {
                $color = $types[$row->type]['color'];
            } else {
                $color = '#4285f4';
            }
        }

        $locked = (int) $row->is_locked === 1;
        $done = (int) $row->is_done === 1;

        $class_names = ['cjs-ev'];
        if ($locked) {
            $class_names[] = 'cjs-ev-locked';
        }
        if ($done) {
            $class_names[] = 'cjs-ev-done';
        }

        $days_late = null;
        if (!empty($row->deadline_date) && !empty($row->order_finish)) {
            $deadline_ts = strtotime($row->deadline_date . ' 23:59:59');
            $finish_ts = strtotime($row->order_finish);
            $days_late = $finish_ts > $deadline_ts ? (int) ceil(($finish_ts - $deadline_ts) / 86400) : 0;
        }

        return [
            'id' => (int) $row->id,
            'title' => $row->name,
            'start' => str_replace(' ', 'T', $row->start_datetime),
            'end' => str_replace(' ', 'T', $row->end_datetime),
            'backgroundColor' => $color,
            'borderColor' => $color,
            'textColor' => self::text_color($color),
            'editable' => !$locked && !$done,
            'classNames' => $class_names,
            'extendedProps' => [
                'order_id' => $row->order_id ? (int) $row->order_id : null,
                'type' => $row->type,
                'is_locked' => $locked ? 1 : 0,
                'is_done' => $done ? 1 : 0,
                'raw_color' => $row->color ? $row->color : '',
                'hours' => CJS_Calendar_Interval::duration_hours($row),
                'deadline' => !empty($row->deadline_date) ? $row->deadline_date : '',
                'finish' => !empty($row->order_finish) ? substr($row->order_finish, 0, 16) : '',
                'days_late' => $days_late
            ]
        ];
    }

    public static function ajax_intervals() {
        self::verify_nonce();
        self::check_permission();

        $start = substr(sanitize_text_field($_POST['start'] ?? ''), 0, 10);
        $end = substr(sanitize_text_field($_POST['end'] ?? ''), 0, 10);

        if (!$start || !$end) {
            wp_send_json_error(['message' => 'Invalid range']);
            return;
        }

        $rows = CJS_Calendar_Interval::get_range($start . ' 00:00:00', $end . ' 23:59:59', 1);

        $events = [];
        foreach ($rows as $row) {
            $events[] = self::event_from_row($row);
        }

        wp_send_json_success(['events' => $events]);
    }

    public static function ajax_interval_save() {
        self::verify_nonce();
        self::check_permission();

        $id = absint($_POST['id'] ?? 0);

        $data = [];

        if (isset($_POST['start'])) {
            $start = self::parse_datetime($_POST['start']);
            if (!$start) {
                wp_send_json_error(['message' => 'Neteisinga pradžios data']);
                return;
            }
            $data['start_datetime'] = $start;
        }

        if (isset($_POST['end'])) {
            $end = self::parse_datetime($_POST['end']);
            if (!$end) {
                wp_send_json_error(['message' => 'Neteisinga pabaigos data']);
                return;
            }
            $data['end_datetime'] = $end;
        }

        if (isset($data['start_datetime']) && isset($data['end_datetime']) && strtotime($data['end_datetime']) <= strtotime($data['start_datetime'])) {
            wp_send_json_error(['message' => 'Pabaiga turi būti vėliau už pradžią']);
            return;
        }

        if (isset($_POST['name'])) {
            $data['name'] = sanitize_text_field($_POST['name']);
        }
        if (isset($_POST['type'])) {
            $data['type'] = sanitize_key($_POST['type']);
        }
        if (isset($_POST['color'])) {
            $data['color'] = sanitize_text_field($_POST['color']);
        }
        if (isset($_POST['is_locked'])) {
            $data['is_locked'] = absint($_POST['is_locked']);
        }
        if (isset($_POST['is_done'])) {
            $data['is_done'] = absint($_POST['is_done']);
        }

        if ($id) {
            $existing = CJS_Calendar_Interval::get($id);
            if (!$existing) {
                wp_send_json_error(['message' => 'Įrašas nerastas']);
                return;
            }
            if (isset($data['start_datetime']) && !isset($data['end_datetime']) && strtotime($existing->end_datetime) <= strtotime($data['start_datetime'])) {
                wp_send_json_error(['message' => 'Pabaiga turi būti vėliau už pradžią']);
                return;
            }
            $result = CJS_Calendar_Interval::update($id, $data);
            if (!$result) {
                wp_send_json_error(['message' => 'Nepavyko išsaugoti']);
                return;
            }
        } else {
            if (empty($data['start_datetime']) || empty($data['end_datetime'])) {
                wp_send_json_error(['message' => 'Nurodykite pradžią ir pabaigą']);
                return;
            }
            if (empty($data['type']) || $data['type'] === 'work') {
                $data['type'] = 'kita';
            }
            $data['resource_id'] = 1;
            $id = CJS_Calendar_Interval::create($data);
            if (!$id) {
                wp_send_json_error(['message' => 'Nepavyko sukurti įrašo']);
                return;
            }
        }

        $row = CJS_Calendar_Interval::get($id);
        wp_send_json_success(['event' => self::event_from_row($row)]);
    }

    public static function ajax_interval_delete() {
        self::verify_nonce();
        self::check_permission();

        $id = absint($_POST['id'] ?? 0);
        if (!$id || !CJS_Calendar_Interval::get($id)) {
            wp_send_json_error(['message' => 'Įrašas nerastas']);
            return;
        }

        if (!CJS_Calendar_Interval::delete($id)) {
            wp_send_json_error(['message' => 'Nepavyko ištrinti']);
            return;
        }

        wp_send_json_success(['message' => 'Ištrinta']);
    }

    public static function ajax_interval_split() {
        self::verify_nonce();
        self::check_permission();

        $id = absint($_POST['id'] ?? 0);
        $at = self::parse_datetime($_POST['at'] ?? '');

        if (!$id || !$at) {
            wp_send_json_error(['message' => 'Neteisingi duomenys']);
            return;
        }

        $new_id = CJS_Calendar_Interval::split($id, $at);
        if (!$new_id) {
            wp_send_json_error(['message' => 'Dalinimo laikas turi būti intervalo viduje']);
            return;
        }

        wp_send_json_success([
            'event' => self::event_from_row(CJS_Calendar_Interval::get($id)),
            'new_event' => self::event_from_row(CJS_Calendar_Interval::get($new_id))
        ]);
    }

    public static function ajax_recalculate() {
        self::verify_nonce();
        self::check_permission();

        $treat_passed = !empty($_POST['treat_passed']);

        $summary = CJS_Scheduler::recalculate(1, $treat_passed);

        wp_send_json_success($summary);
    }

    public static function ajax_save_settings() {
        self::verify_nonce();
        self::check_permission();

        $raw = json_decode(wp_unslash($_POST['settings'] ?? ''), true);
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Neteisingi nustatymai']);
            return;
        }

        $settings = self::sanitize_settings($raw);

        $rest_minutes = 0;
        foreach ($settings['rest'] as $block) {
            $from = self::minutes_of_day($block['from']);
            $to = self::minutes_of_day($block['to']);
            $rest_minutes += $from < $to ? ($to - $from) : (1440 - $from + $to);
        }
        $required_minutes = (int) round($settings['rest_hours'] * 60);
        if ($rest_minutes !== $required_minutes) {
            wp_send_json_error(['message' => sprintf(
                'Poilsio blokai sudaro %s val., o privaloma lygiai %s val.',
                round($rest_minutes / 60, 1),
                round($required_minutes / 60, 1)
            )]);
            return;
        }

        CJS_Scheduler::save_settings($settings, 1);

        wp_send_json_success(['settings' => $settings]);
    }

    private static function minutes_of_day($time) {
        $parts = explode(':', $time);
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    private static function sanitize_settings($raw) {
        $defaults = CJS_Scheduler::defaults();
        $settings = $defaults;

        $sanitize_time = function($value, $fallback) {
            $value = trim((string) $value);
            return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
        };

        $sanitize_blocks = function($blocks) use ($sanitize_time) {
            $out = [];
            if (is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $from = $sanitize_time($block['from'] ?? '', '');
                    $to = $sanitize_time($block['to'] ?? '', '');
                    if ($from !== '' && $to !== '' && $from !== $to) {
                        $out[] = ['from' => $from, 'to' => $to];
                    }
                }
            }
            return $out;
        };

        if (isset($raw['week']) && is_array($raw['week'])) {
            foreach ($defaults['week'] as $key => $day_defaults) {
                if (!isset($raw['week'][$key]) || !is_array($raw['week'][$key])) {
                    continue;
                }
                $day = $raw['week'][$key];
                $settings['week'][$key] = [
                    'enabled' => !empty($day['enabled']) ? 1 : 0,
                    'start' => $sanitize_time($day['start'] ?? '', $day_defaults['start']),
                    'end' => $sanitize_time($day['end'] ?? '', $day_defaults['end']),
                    'breaks' => $sanitize_blocks($day['breaks'] ?? [])
                ];
            }
        }

        if (isset($raw['rest'])) {
            $settings['rest'] = $sanitize_blocks($raw['rest']);
        }

        if (isset($raw['rest_hours'])) {
            $settings['rest_hours'] = max(0, min(23, round((float) $raw['rest_hours'] * 2) / 2));
        }

        if (isset($raw['algorithm']) && in_array($raw['algorithm'], ['fcfs', 'sjf', 'rr'], true)) {
            $settings['algorithm'] = $raw['algorithm'];
        }

        if (isset($raw['overflow']) && in_array($raw['overflow'], ['delay', 'increase'], true)) {
            $settings['overflow'] = $raw['overflow'];
        }

        if (isset($raw['aggro'])) {
            $settings['aggro'] = max(1, min(4, absint($raw['aggro'])));
        }

        if (isset($raw['auto_split']) && in_array((int) $raw['auto_split'], [0, 30, 60, 120, 240], true)) {
            $settings['auto_split'] = (int) $raw['auto_split'];
        }

        return $settings;
    }
}
