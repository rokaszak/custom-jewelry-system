<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Scheduler {

    private $resource_id;
    private $settings;
    private $now_ts;
    private $now_mysql;
    private $sched_start_ts;
    private $blocked_by_day = [];
    private $normal_windows_cache = [];
    private $reference_breaks = null;
    private $horizon_days = 1095;
    private $grid = 900;

    public function __construct($resource_id = 1) {
        $this->resource_id = $resource_id;
        $this->settings = self::get_settings($resource_id);
        $this->now_mysql = current_time('mysql');
        $this->now_ts = strtotime($this->now_mysql);
        $this->sched_start_ts = (int) (ceil($this->now_ts / $this->grid) * $this->grid);
    }

    private function start_day() {
        return date('Y-m-d', $this->sched_start_ts);
    }

    public static function defaults() {
        $day_on = ['enabled' => 1, 'start' => '08:00', 'end' => '17:00', 'breaks' => [['from' => '12:00', 'to' => '13:00']]];
        $day_off = ['enabled' => 0, 'start' => '08:00', 'end' => '17:00', 'breaks' => []];
        return [
            'week' => [
                'mon' => $day_on,
                'tue' => $day_on,
                'wed' => $day_on,
                'thu' => $day_on,
                'fri' => $day_on,
                'sat' => $day_off,
                'sun' => $day_off
            ],
            'rest' => [['from' => '22:00', 'to' => '06:00']],
            'rest_hours' => 8,
            'algorithm' => 'fcfs',
            'overflow' => 'delay',
            'aggro' => 1,
            'auto_split' => 0
        ];
    }

    public static function get_settings($resource_id = 1) {
        $all = get_option('cjs_calendar_settings', []);
        $stored = is_array($all) && isset($all[(string) $resource_id]) ? $all[(string) $resource_id] : [];
        $defaults = self::defaults();
        $settings = array_merge($defaults, is_array($stored) ? $stored : []);
        foreach ($defaults['week'] as $key => $day) {
            if (!isset($settings['week'][$key]) || !is_array($settings['week'][$key])) {
                $settings['week'][$key] = $day;
            } else {
                $settings['week'][$key] = array_merge($day, $settings['week'][$key]);
            }
        }
        return $settings;
    }

    public static function save_settings($settings, $resource_id = 1) {
        $all = get_option('cjs_calendar_settings', []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[(string) $resource_id] = $settings;
        update_option('cjs_calendar_settings', $all);
    }

    public static function recalculate($resource_id = 1, $treat_passed_done = false) {
        $engine = new self($resource_id);
        return $engine->run($treat_passed_done);
    }

    public function run($treat_passed_done) {
        if ($treat_passed_done) {
            CJS_Calendar_Interval::mark_passed_work_done($this->now_mysql, $this->resource_id);
        }

        CJS_Calendar_Interval::clear_unconfirmed_work($this->resource_id);
        $this->load_blocking();

        $orders = $this->get_schedulable_orders();

        if (empty($orders)) {
            return ['created' => 0, 'scheduled_orders' => 0, 'late' => []];
        }

        $increase = $this->settings['overflow'] === 'increase';
        $algorithm = $this->settings['algorithm'];

        if ($algorithm === 'rr') {
            list($intervals, $finishes) = $this->pack_rr($orders, $increase);
        } else {
            if ($algorithm === 'sjf') {
                $sequence = $this->sequence_sjf($orders);
            } else {
                $sequence = $this->sort_edf($orders);
            }
            list($intervals, $finishes) = $this->pack_sequential($sequence, $increase);
        }

        $deadline_map = [];
        foreach ($orders as $order) {
            $deadline_map[$order['order_id']] = $order['deadline_ts'];
        }

        $created = 0;
        foreach ($intervals as $interval) {
            $order_id = $interval['order_id'];
            $interval['deadline_date'] = isset($deadline_map[$order_id]) ? date('Y-m-d', $deadline_map[$order_id]) : null;
            $interval['order_finish'] = isset($finishes[$order_id]) ? date('Y-m-d H:i:s', $finishes[$order_id]) : null;
            $id = CJS_Calendar_Interval::create($interval);
            if ($id) {
                $created++;
            }
        }

        $late = [];
        foreach ($orders as $order) {
            $key = $order['order_id'];
            $finish = isset($finishes[$key]) ? $finishes[$key] : null;
            if ($finish === null) {
                $late[] = [
                    'order_id' => $key,
                    'name' => $order['name'],
                    'deadline' => date('Y-m-d', $order['deadline_ts']),
                    'finish' => null,
                    'days_late' => null
                ];
            } elseif ($finish > $order['deadline_ts']) {
                $late[] = [
                    'order_id' => $key,
                    'name' => $order['name'],
                    'deadline' => date('Y-m-d', $order['deadline_ts']),
                    'finish' => date('Y-m-d H:i', $finish),
                    'days_late' => (int) ceil(($finish - $order['deadline_ts']) / 86400)
                ];
            }
        }

        CJS_Logger::log('Gamybos grafikas perskaičiuotas', 'info', 'calendar', $this->resource_id, [
            'algorithm' => $algorithm,
            'overflow' => $this->settings['overflow'],
            'orders' => count($orders),
            'intervals_created' => $created,
            'late_orders' => count($late)
        ]);

        return ['created' => $created, 'scheduled_orders' => count($orders), 'late' => $late];
    }

    private function get_schedulable_orders() {
        global $wpdb;

        $all_statuses = array_keys(wc_get_order_statuses());
        $excluded = ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];
        $allowed = [];
        foreach ($all_statuses as $status) {
            if (!in_array($status, $excluded, true)) {
                $allowed[] = substr($status, 0, 3) === 'wc-' ? substr($status, 3) : $status;
            }
        }

        $wc_orders = wc_get_orders([
            'limit' => -1,
            'type' => 'shop_order',
            'status' => $allowed
        ]);

        if (empty($wc_orders)) {
            return [];
        }

        $ids = [];
        foreach ($wc_orders as $wc_order) {
            $ids[] = $wc_order->get_id();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $ext_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id IN ({$placeholders})",
            $ids
        ), OBJECT_K);

        $by_order = [];
        foreach ($ext_rows as $row) {
            $by_order[(int) $row->order_id] = $row;
        }

        $locked_hours = CJS_Calendar_Interval::locked_future_work_hours($this->now_mysql, $this->resource_id);

        $orders = [];
        foreach ($wc_orders as $wc_order) {
            $order_id = $wc_order->get_id();

            if (!isset($by_order[$order_id])) {
                CJS_Order_Extension::create_order_extension($order_id);
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
                    $order_id
                ));
                if (!$row) {
                    continue;
                }
                $by_order[$order_id] = $row;
            }

            $ext = $by_order[$order_id];

            if ($ext->manufacturing_status !== 'Atlieta') {
                continue;
            }

            $assigned = $ext->assigned_hours;
            if ($assigned === null || $assigned === '') {
                $assigned = CJS_Order_Extension::calculate_default_hours($wc_order);
                CJS_Order_Extension::update_order_extension($order_id, ['assigned_hours' => $assigned]);
            }
            $assigned = (float) $assigned;

            $remaining_hours = $assigned - (float) $ext->completed_hours - (isset($locked_hours[$order_id]) ? $locked_hours[$order_id] : 0);
            if ($remaining_hours * 3600 < 180) {
                continue;
            }
            $remaining = (int) (ceil(($remaining_hours * 3600) / $this->grid) * $this->grid);

            $deadline_date = $ext->manufacture_by_date;
            if (empty($deadline_date) || $deadline_date === '0000-00-00') {
                $created = $wc_order->get_date_created();
                $created_ymd = $created ? $created->date('Y-m-d') : date('Y-m-d', $this->now_ts);
                $deadline_date = date('Y-m-d', strtotime($created_ymd . ' +9 weeks'));
            }

            $color = $this->work_color($assigned);

            $orders[] = [
                'order_id' => $order_id,
                'name' => '#' . $wc_order->get_order_number() . ' ' . $wc_order->get_formatted_billing_full_name(),
                'deadline_ts' => strtotime($deadline_date . ' 23:59:59'),
                'remaining' => $remaining,
                'color' => $color,
                'created_ts' => $wc_order->get_date_created() ? strtotime($wc_order->get_date_created()->date('Y-m-d H:i:s')) : $order_id
            ];
        }

        return $orders;
    }

    private function work_color($hours) {
        $t = ($hours - 2) / 18;
        $t = max(0, min(1, $t));
        $light = [138, 180, 248];
        $dark = [23, 78, 166];
        $r = (int) round($light[0] + ($dark[0] - $light[0]) * $t);
        $g = (int) round($light[1] + ($dark[1] - $light[1]) * $t);
        $b = (int) round($light[2] + ($dark[2] - $light[2]) * $t);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function load_blocking() {
        $rows = CJS_Calendar_Interval::get_blocking($this->now_mysql, $this->resource_id);
        foreach ($rows as $row) {
            $start = strtotime($row->start_datetime);
            $end = strtotime($row->end_datetime);
            if ($end <= $start) {
                continue;
            }
            $day = date('Y-m-d', $start);
            $last_day = date('Y-m-d', $end - 1);
            while (true) {
                $this->blocked_by_day[$day][] = [$start, $end];
                if ($day === $last_day) {
                    break;
                }
                $day = date('Y-m-d', strtotime($day . ' +1 day'));
            }
        }
    }

    private function time_to_minutes($time) {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return 0;
        }
        return max(0, min(1440, (int) $parts[0] * 60 + (int) $parts[1]));
    }

    private function subtract_range($ranges, $from, $to) {
        if ($to <= $from) {
            return $ranges;
        }
        $out = [];
        foreach ($ranges as $range) {
            if ($to <= $range[0] || $from >= $range[1]) {
                $out[] = $range;
                continue;
            }
            if ($from > $range[0]) {
                $out[] = [$range[0], $from];
            }
            if ($to < $range[1]) {
                $out[] = [$to, $range[1]];
            }
        }
        return $out;
    }

    private function get_reference_breaks() {
        if ($this->reference_breaks !== null) {
            return $this->reference_breaks;
        }
        $this->reference_breaks = [];
        foreach ($this->settings['week'] as $spec) {
            if (!empty($spec['enabled'])) {
                $this->reference_breaks = isset($spec['breaks']) && is_array($spec['breaks']) ? $spec['breaks'] : [];
                break;
            }
        }
        return $this->reference_breaks;
    }

    private function day_windows($day_str, $expanded) {
        if (!$expanded && isset($this->normal_windows_cache[$day_str])) {
            return $this->normal_windows_cache[$day_str];
        }

        $weekday_map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $weekday = $weekday_map[(int) date('N', strtotime($day_str))];
        $spec = $this->settings['week'][$weekday];
        $enabled = !empty($spec['enabled']);
        $breaks = isset($spec['breaks']) && is_array($spec['breaks']) ? $spec['breaks'] : [];

        $ranges = [];

        if (!$expanded) {
            if ($enabled) {
                $start = $this->time_to_minutes($spec['start']);
                $end = $this->time_to_minutes($spec['end']);
                if ($end > $start) {
                    $ranges = [[$start, $end]];
                    foreach ($breaks as $break) {
                        $ranges = $this->subtract_range($ranges, $this->time_to_minutes($break['from']), $this->time_to_minutes($break['to']));
                    }
                }
            }
        } else {
            $aggro = (int) $this->settings['aggro'];
            if ($enabled || $aggro >= 2) {
                $ranges = [[0, 1440]];
                $day_breaks = $enabled ? $breaks : $this->get_reference_breaks();
                if ($aggro <= 2) {
                    foreach ($day_breaks as $break) {
                        $ranges = $this->subtract_range($ranges, $this->time_to_minutes($break['from']), $this->time_to_minutes($break['to']));
                    }
                } elseif ($aggro === 3) {
                    $longest = null;
                    $longest_len = -1;
                    foreach ($day_breaks as $break) {
                        $len = $this->time_to_minutes($break['to']) - $this->time_to_minutes($break['from']);
                        if ($len > $longest_len) {
                            $longest_len = $len;
                            $longest = $break;
                        }
                    }
                    if ($longest) {
                        $ranges = $this->subtract_range($ranges, $this->time_to_minutes($longest['from']), $this->time_to_minutes($longest['to']));
                    }
                }
            }
        }

        if (!empty($ranges)) {
            $rest_blocks = isset($this->settings['rest']) && is_array($this->settings['rest']) ? $this->settings['rest'] : [];
            foreach ($rest_blocks as $rest) {
                $from = $this->time_to_minutes($rest['from']);
                $to = $this->time_to_minutes($rest['to']);
                if ($from < $to) {
                    $ranges = $this->subtract_range($ranges, $from, $to);
                } elseif ($from > $to) {
                    $ranges = $this->subtract_range($ranges, $from, 1440);
                    $ranges = $this->subtract_range($ranges, 0, $to);
                }
            }
        }

        $day_start_ts = strtotime($day_str . ' 00:00:00');
        $windows = [];
        foreach ($ranges as $range) {
            $windows[] = [$day_start_ts + $range[0] * 60, $day_start_ts + $range[1] * 60];
        }

        if (isset($this->blocked_by_day[$day_str])) {
            foreach ($this->blocked_by_day[$day_str] as $blocked) {
                $from = (int) (floor($blocked[0] / $this->grid) * $this->grid);
                $to = (int) (ceil($blocked[1] / $this->grid) * $this->grid);
                $windows = $this->subtract_range($windows, $from, $to);
            }
        }

        $clipped = [];
        foreach ($windows as $window) {
            $start = max($window[0], $this->sched_start_ts);
            if ($window[1] - $start >= 60) {
                $clipped[] = [$start, $window[1]];
            }
        }

        usort($clipped, function($a, $b) {
            return $a[0] - $b[0];
        });

        if (!$expanded) {
            $this->normal_windows_cache[$day_str] = $clipped;
        }

        return $clipped;
    }

    private function sort_edf($orders) {
        usort($orders, function($a, $b) {
            if ($a['deadline_ts'] === $b['deadline_ts']) {
                return $a['created_ts'] - $b['created_ts'];
            }
            return $a['deadline_ts'] - $b['deadline_ts'];
        });
        return $orders;
    }

    private function sim_finishes($from_day, $states) {
        $finishes = [];
        $remaining = [];
        foreach ($states as $i => $state) {
            $remaining[$i] = $state['remaining'];
        }

        $idx = 0;
        $n = count($states);
        $day = $from_day;
        $count = 0;

        while ($idx < $n && $count < $this->horizon_days) {
            $windows = $this->day_windows($day, false);
            foreach ($windows as $window) {
                $ws = $window[0];
                $we = $window[1];
                while ($idx < $n && $ws < $we) {
                    $space = $we - $ws;
                    if ($remaining[$idx] <= $space) {
                        $ws += $remaining[$idx];
                        $remaining[$idx] = 0;
                        $finishes[$idx] = $ws;
                        $idx++;
                    } else {
                        $remaining[$idx] -= $space;
                        $ws = $we;
                    }
                }
                if ($idx >= $n) {
                    break;
                }
            }
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
            $count++;
        }

        return $finishes;
    }

    private function late_count($states) {
        $finishes = $this->sim_finishes($this->start_day(), $states);
        $late = 0;
        foreach ($states as $i => $state) {
            if (!isset($finishes[$i]) || $finishes[$i] > $state['deadline_ts']) {
                $late++;
            }
        }
        return $late;
    }

    private function feasible_edf($from_day, $states) {
        $sorted = $this->sort_edf($states);
        $finishes = $this->sim_finishes($from_day, $sorted);
        foreach ($sorted as $i => $state) {
            if (!isset($finishes[$i]) || $finishes[$i] > $state['deadline_ts']) {
                return false;
            }
        }
        return true;
    }

    private function sequence_sjf($orders) {
        $sequence = [];
        $rest = $orders;

        while (!empty($rest)) {
            $edf_rest = $this->sort_edf($rest);
            $baseline = $this->late_count(array_merge($sequence, $edf_rest));

            $by_hours = $rest;
            usort($by_hours, function($a, $b) {
                return $a['remaining'] - $b['remaining'];
            });

            $pick = null;
            foreach ($by_hours as $candidate) {
                $others = [];
                foreach ($rest as $order) {
                    if ($order['order_id'] !== $candidate['order_id']) {
                        $others[] = $order;
                    }
                }
                $try = array_merge($sequence, [$candidate], $this->sort_edf($others));
                if ($this->late_count($try) <= $baseline) {
                    $pick = $candidate;
                    break;
                }
            }

            if (!$pick) {
                $pick = $edf_rest[0];
            }

            $sequence[] = $pick;
            $filtered = [];
            foreach ($rest as $order) {
                if ($order['order_id'] !== $pick['order_id']) {
                    $filtered[] = $order;
                }
            }
            $rest = $filtered;
        }

        return $sequence;
    }

    private function split_cap() {
        $minutes = (int) $this->settings['auto_split'];
        return $minutes > 0 ? $minutes * 60 : PHP_INT_MAX;
    }

    private function make_interval($order, $start_ts, $end_ts) {
        return [
            'resource_id' => $this->resource_id,
            'order_id' => $order['order_id'],
            'type' => 'work',
            'name' => $order['name'],
            'start_datetime' => date('Y-m-d H:i:s', $start_ts),
            'end_datetime' => date('Y-m-d H:i:s', $end_ts),
            'is_locked' => 0,
            'is_done' => 0,
            'color' => $order['color']
        ];
    }

    private function pack_sequential($sequence, $increase) {
        $intervals = [];
        $finishes = [];
        $cap = $this->split_cap();

        $states = [];
        foreach ($sequence as $order) {
            $order['left'] = $order['remaining'];
            $states[] = $order;
        }

        $expand = $increase && !$this->feasible_edf($this->start_day(), $states);

        $idx = 0;
        $n = count($states);
        $day = $this->start_day();
        $count = 0;

        while ($idx < $n && $count < $this->horizon_days) {
            if ($expand) {
                $unfinished = array_slice($states, $idx);
                foreach ($unfinished as $i => $state) {
                    $unfinished[$i]['remaining'] = $state['left'];
                }
                if ($this->feasible_edf($day, $unfinished)) {
                    $expand = false;
                }
            }

            $windows = $this->day_windows($day, $expand);
            foreach ($windows as $window) {
                $ws = $window[0];
                $we = $window[1];
                while ($idx < $n && $we - $ws >= 60) {
                    $chunk = min($we - $ws, $states[$idx]['left'], $cap);
                    if ($chunk < 60) {
                        break;
                    }
                    $intervals[] = $this->make_interval($states[$idx], $ws, $ws + $chunk);
                    $ws += $chunk;
                    $states[$idx]['left'] -= $chunk;
                    if ($states[$idx]['left'] < 60) {
                        $finishes[$states[$idx]['order_id']] = $ws;
                        $idx++;
                    }
                }
                if ($idx >= $n) {
                    break;
                }
            }
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
            $count++;
        }

        return [$intervals, $finishes];
    }

    private function capacity_until($from_day, $deadline_ts, $enough) {
        $total = 0;
        $day = $from_day;
        $count = 0;
        while ($count < $this->horizon_days) {
            if (strtotime($day . ' 00:00:00') > $deadline_ts) {
                break;
            }
            $windows = $this->day_windows($day, false);
            foreach ($windows as $window) {
                $end = min($window[1], $deadline_ts);
                if ($end > $window[0]) {
                    $total += $end - $window[0];
                }
            }
            if ($total >= $enough) {
                break;
            }
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
            $count++;
        }
        return $total;
    }

    private function pack_rr($orders, $increase) {
        $intervals = [];
        $finishes = [];
        $cap = $this->split_cap();

        $states = $this->sort_edf($orders);
        foreach ($states as $i => $order) {
            $states[$i]['left'] = $order['remaining'];
        }

        $expand = $increase && !$this->feasible_edf($this->start_day(), $states);

        $day = $this->start_day();
        $count = 0;

        while ($count < $this->horizon_days) {
            $has_work = false;
            foreach ($states as $state) {
                if ($state['left'] >= 60) {
                    $has_work = true;
                    break;
                }
            }
            if (!$has_work) {
                break;
            }

            if ($expand) {
                $unfinished = [];
                foreach ($states as $state) {
                    if ($state['left'] >= 60) {
                        $state['remaining'] = $state['left'];
                        $unfinished[] = $state;
                    }
                }
                if ($this->feasible_edf($day, $unfinished)) {
                    $expand = false;
                }
            }

            $windows = $this->day_windows($day, $expand);
            $wi = 0;
            $ws = isset($windows[0]) ? $windows[0][0] : 0;

            while (true) {
                $day_left = 0;
                for ($i = $wi; $i < count($windows); $i++) {
                    $start = $i === $wi ? max($ws, $windows[$i][0]) : $windows[$i][0];
                    $day_left += max(0, $windows[$i][1] - $start);
                }
                if ($day_left < 60) {
                    break;
                }

                $active = [];
                foreach ($states as $i => $state) {
                    if ($state['left'] >= 60) {
                        $active[] = $i;
                    }
                    if (count($active) === 2) {
                        break;
                    }
                }
                if (empty($active)) {
                    break;
                }

                $first = $active[0];
                $quotas = [];

                if (count($active) === 1) {
                    $quotas[$first] = min($day_left, $states[$first]['left']);
                } else {
                    $second = $active[1];
                    $available = $this->capacity_until($day, $states[$first]['deadline_ts'], $states[$first]['left'] + $states[$second]['left']);
                    $second_share = min($states[$second]['left'], (int) floor($available / 2));
                    if ($available - $second_share < $states[$first]['left']) {
                        $quotas[$first] = min($day_left, $states[$first]['left']);
                        $leftover = $day_left - $quotas[$first];
                        if ($leftover >= 60) {
                            $quotas[$second] = min($leftover, $states[$second]['left']);
                        }
                    } else {
                        $quotas[$first] = min((int) ceil($day_left / 2), $states[$first]['left']);
                        $leftover = $day_left - $quotas[$first];
                        if ($leftover >= 60) {
                            $quotas[$second] = min($leftover, $states[$second]['left']);
                        }
                    }
                }

                $queue = [];
                foreach ($quotas as $state_idx => $quota) {
                    if ($quota >= 60) {
                        $queue[] = ['idx' => $state_idx, 'quota' => $quota];
                    }
                }
                if (empty($queue)) {
                    break;
                }

                $consumed = 0;
                $qi = 0;
                while ($qi < count($queue) && $wi < count($windows)) {
                    if ($ws < $windows[$wi][0]) {
                        $ws = $windows[$wi][0];
                    }
                    $we = $windows[$wi][1];
                    if ($we - $ws < 60) {
                        $wi++;
                        if ($wi < count($windows)) {
                            $ws = $windows[$wi][0];
                        }
                        continue;
                    }
                    $chunk = min($we - $ws, $queue[$qi]['quota'], $states[$queue[$qi]['idx']]['left'], $cap);
                    if ($chunk < 60) {
                        $qi++;
                        continue;
                    }
                    $state_idx = $queue[$qi]['idx'];
                    $intervals[] = $this->make_interval($states[$state_idx], $ws, $ws + $chunk);
                    $ws += $chunk;
                    $consumed += $chunk;
                    $queue[$qi]['quota'] -= $chunk;
                    $states[$state_idx]['left'] -= $chunk;
                    if ($states[$state_idx]['left'] < 60) {
                        $finishes[$states[$state_idx]['order_id']] = $ws;
                        $queue[$qi]['quota'] = 0;
                    }
                    if ($queue[$qi]['quota'] < 60) {
                        $qi++;
                    }
                }

                if ($consumed < 60) {
                    break;
                }
            }

            $day = date('Y-m-d', strtotime($day . ' +1 day'));
            $count++;
        }

        return [$intervals, $finishes];
    }
}
