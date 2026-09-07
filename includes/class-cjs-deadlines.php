<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Deadlines {

    const OPTION = 'cjs_deadline_settings';
    const FINISH_GAP_DAYS = 7;
    const MANUFACTURE_GAP_DAYS = 14;

    public static function defaults() {
        return [
            'checkout_enabled' => 0,
            'min_days' => 14,
            'max_days' => 168,
            'default_days' => 84
        ];
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::defaults(), $stored);
        $settings['checkout_enabled'] = !empty($settings['checkout_enabled']) ? 1 : 0;
        $settings['min_days'] = max(0, (int) $settings['min_days']);
        $settings['max_days'] = max(0, (int) $settings['max_days']);
        $settings['default_days'] = max(1, (int) $settings['default_days']);
        return $settings;
    }

    public static function save_settings($settings) {
        $clean = array_merge(self::defaults(), is_array($settings) ? $settings : []);
        $clean['checkout_enabled'] = !empty($clean['checkout_enabled']) ? 1 : 0;
        $clean['min_days'] = max(0, (int) $clean['min_days']);
        $clean['max_days'] = max(0, (int) $clean['max_days']);
        if ($clean['max_days'] > 0 && $clean['max_days'] < $clean['min_days']) {
            $clean['max_days'] = $clean['min_days'];
        }
        $clean['default_days'] = max(1, (int) $clean['default_days']);
        update_option(self::OPTION, $clean);
        return $clean;
    }

    public static function is_checkout_enabled() {
        $settings = self::get_settings();
        return (bool) $settings['checkout_enabled'];
    }

    public static function min_days() {
        $settings = self::get_settings();
        return (int) $settings['min_days'];
    }

    public static function default_days() {
        $settings = self::get_settings();
        return (int) $settings['default_days'];
    }

    public static function today() {
        return current_time('Y-m-d');
    }

    public static function is_valid_ymd($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    public static function add_days($ymd, $days) {
        $ts = strtotime($ymd . ' 00:00:00');
        if ($ts === false) {
            return $ymd;
        }
        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . (int) $days . ' days', $ts));
    }

    public static function max_days() {
        $settings = self::get_settings();
        return (int) $settings['max_days'];
    }

    public static function earliest_custom_date() {
        return self::add_days(self::today(), self::min_days());
    }

    public static function latest_custom_date() {
        $max = self::max_days();
        return $max > 0 ? self::add_days(self::today(), $max) : '';
    }

    public static function dates_from_deliver($deliver_ymd) {
        return [
            'manufacture_by_date' => self::add_days($deliver_ymd, -self::MANUFACTURE_GAP_DAYS),
            'finish_by_date' => self::add_days($deliver_ymd, -self::FINISH_GAP_DAYS),
            'deliver_by_date' => $deliver_ymd
        ];
    }

    public static function default_dates($order_date = null) {
        if ($order_date instanceof DateTimeInterface) {
            $base = $order_date->format('Y-m-d');
        } elseif (is_string($order_date) && self::is_valid_ymd(substr($order_date, 0, 10))) {
            $base = substr($order_date, 0, 10);
        } else {
            $base = self::today();
        }
        return self::dates_from_deliver(self::add_days($base, self::default_days()));
    }

    public static function days_between($from_ymd, $to_ymd) {
        $from = strtotime($from_ymd . ' 00:00:00');
        $to = strtotime($to_ymd . ' 00:00:00');
        if ($from === false || $to === false) {
            return null;
        }
        return (int) round(($to - $from) / 86400);
    }

    public static function days_until($ymd) {
        if (!self::is_valid_ymd($ymd)) {
            return null;
        }
        return self::days_between(self::today(), $ymd);
    }

    public static function order_created_ymd($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return null;
        }
        $created = $order->get_date_created();
        return $created ? $created->date('Y-m-d') : null;
    }

    public static function coefficient($order, $deliver_by_date) {
        if (empty($deliver_by_date) || !self::is_valid_ymd($deliver_by_date)) {
            return null;
        }
        $created = self::order_created_ymd($order);
        if (!$created) {
            return null;
        }
        $term = self::days_between($created, $deliver_by_date);
        if ($term === null) {
            return null;
        }
        return max(0, $term) / self::default_days();
    }

    public static function format_coefficient($ratio) {
        $rounded = round((float) $ratio, 2);
        $text = number_format($rounded, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        return $text === '' ? '0' : $text;
    }

    private static function gradient_stops() {
        return [
            [0.0, [220, 38, 38]],
            [0.33, [249, 115, 22]],
            [0.66, [250, 204, 21]],
            [0.85, [132, 204, 22]],
            [1.0, [34, 197, 94]],
            [2.0, [21, 128, 61]]
        ];
    }

    public static function coefficient_background($ratio) {
        $ratio = max(0.0, (float) $ratio);
        $stops = self::gradient_stops();
        $last = $stops[count($stops) - 1];
        if ($ratio >= $last[0]) {
            $rgb = $last[1];
        } else {
            $rgb = $stops[0][1];
            for ($i = 1; $i < count($stops); $i++) {
                if ($ratio <= $stops[$i][0]) {
                    $a = $stops[$i - 1];
                    $b = $stops[$i];
                    $span = $b[0] - $a[0];
                    $t = $span > 0 ? ($ratio - $a[0]) / $span : 0;
                    $rgb = [
                        (int) round($a[1][0] + ($b[1][0] - $a[1][0]) * $t),
                        (int) round($a[1][1] + ($b[1][1] - $a[1][1]) * $t),
                        (int) round($a[1][2] + ($b[1][2] - $a[1][2]) * $t)
                    ];
                    break;
                }
            }
        }
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function relative_luminance($hex) {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast_text_color($background_hex) {
        $bg = self::relative_luminance($background_hex);
        $white = (1.05) / ($bg + 0.05);
        $dark = ($bg + 0.05) / (self::relative_luminance('#111827') + 0.05);
        return $white >= $dark ? '#ffffff' : '#111827';
    }

    public static function coefficient_text_color($ratio, $background_hex) {
        if ((float) $ratio >= 0.93) {
            return '#ffffff';
        }
        return self::contrast_text_color($background_hex);
    }

    public static function render_term_summary($order, $deliver_by_date) {
        $created = self::order_created_ymd($order);
        if (!$created || empty($deliver_by_date) || !self::is_valid_ymd($deliver_by_date)) {
            return '';
        }
        $term = self::days_between($created, $deliver_by_date);
        if ($term === null) {
            return '';
        }
        return sprintf(
            '<span class="cjs-term-label">%s</span>%s',
            esc_html(sprintf(__('Terminas: %d d.', 'custom-jewelry-system'), $term)),
            self::render_coefficient_badge($order, $deliver_by_date)
        );
    }

    public static function render_coefficient_badge($order, $deliver_by_date) {
        $ratio = self::coefficient($order, $deliver_by_date);
        if ($ratio === null) {
            return '';
        }
        $bg = self::coefficient_background($ratio);
        $fg = self::coefficient_text_color($ratio, $bg);
        $created = self::order_created_ymd($order);
        $term = $created ? self::days_between($created, $deliver_by_date) : null;
        $title = sprintf(
            __('Terminas %d d. / standartas %d d.', 'custom-jewelry-system'),
            (int) $term,
            self::default_days()
        );
        return sprintf(
            '<span class="cjs-coef-badge" style="background-color:%s;color:%s;" title="%s">%s</span>',
            esc_attr($bg),
            esc_attr($fg),
            esc_attr($title),
            esc_html(self::format_coefficient($ratio))
        );
    }
}
