<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Metals {

    private static $tracked = [];
    private static $keys = [];
    private static $collation = null;

    public static function flush() {
        self::$tracked = [];
    }

    public static function tracked($category_id) {
        $category_id = (int) $category_id;
        if ($category_id <= 0) {
            return false;
        }
        if (!array_key_exists($category_id, self::$tracked)) {
            $cat = CJS_Inv_Catalog::category($category_id);
            self::$tracked[$category_id] = $cat && (int) ($cat['has_fineness'] ?? 0) === 1;
        }
        return self::$tracked[$category_id];
    }

    public static function clean($name) {
        if (!is_string($name) && !is_int($name) && !is_float($name)) {
            return '';
        }
        return trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field((string) $name)));
    }

    private static function collation() {
        if (self::$collation === null) {
            global $wpdb;
            self::$collation = '';
            try {
                $row = $wpdb->get_row($wpdb->prepare('SHOW FULL COLUMNS FROM ' . CJS_Inv_DB::t('demands') . ' LIKE %s', 'metal'), ARRAY_A);
                $c = is_array($row) ? (string) ($row['Collation'] ?? '') : '';
                if ($c !== '' && preg_match('/^[A-Za-z0-9_]+$/', $c) && stripos($c, 'utf8mb4') === 0) {
                    self::$collation = $c;
                }
            } catch (Throwable $e) {
                self::$collation = '';
            }
        }
        return self::$collation;
    }

    public static function key($name) {
        $clean = self::clean($name);
        if ($clean === '') {
            return '';
        }
        if (isset(self::$keys[$clean])) {
            return self::$keys[$clean];
        }
        $key = null;
        $collation = self::collation();
        if ($collation !== '') {
            global $wpdb;
            $suppress = $wpdb->suppress_errors(true);
            $weight = $wpdb->get_var($wpdb->prepare('SELECT HEX(WEIGHT_STRING(CONVERT(%s USING utf8mb4) COLLATE ' . $collation . '))', $clean));
            $wpdb->suppress_errors($suppress);
            if (is_string($weight) && $weight !== '') {
                $key = 'w' . $weight;
            }
        }
        if ($key === null) {
            $plain = function_exists('remove_accents') ? remove_accents($clean) : $clean;
            $key = 'p' . mb_strtolower($plain);
        }
        if (count(self::$keys) > 2000) {
            self::$keys = [];
        }
        self::$keys[$clean] = $key;
        return $key;
    }

    public static function sql($alias) {
        return "COALESCE(NULLIF($alias.metal, ''), $alias.name)";
    }

    public static function item_metal($item) {
        if (!is_array($item)) {
            return '';
        }
        $metal = self::clean($item['metal'] ?? '');
        return $metal !== '' ? $metal : self::clean($item['name'] ?? '');
    }

    public static function praba_of($item) {
        return is_array($item) ? CJS_Inv_Flow::clean_praba($item['praba'] ?? null) : null;
    }

    public static function conv_praba($item) {
        return self::praba_of($item) ?? CJS_Inv_Flow::PRABA_REF;
    }

    public static function display($metal, $praba) {
        $metal = self::clean($metal);
        $p = CJS_Inv_Flow::clean_praba($praba);
        return $p === null ? $metal : trim($metal . ' ' . CJS_Inv_DB::fmt($p));
    }

    public static function split_name($name) {
        $name = self::clean($name);
        if (preg_match('/^(.*\S)[\s·]+(\d{1,4}(?:[.,]\d{1,2})?)$/u', $name, $m)) {
            $base = trim($m[1], " \t·");
            $p = CJS_Inv_Flow::clean_praba(str_replace(',', '.', $m[2]));
            if ($base !== '' && $p !== null && preg_match('/\D/u', $base)) {
                return [$base, $p];
            }
        }
        return [$name, null];
    }

    public static function signature($category_id, $metal, $praba) {
        $p = CJS_Inv_Flow::clean_praba($praba);
        return sha1('c' . (int) $category_id . '|metal=' . self::key($metal) . '|praba=' . ($p === null ? '' : CJS_Inv_DB::fmt($p)));
    }

    public static function find_same($category_id, $metal, $praba, $exclude_id = 0, $prefer_unit = 0) {
        global $wpdb;
        $metal = self::clean($metal);
        $p = CJS_Inv_Flow::clean_praba($praba);
        if ((int) $category_id <= 0 || $metal === '' || $p === null) {
            return null;
        }
        $unit_order = (int) $prefer_unit > 0 ? $wpdb->prepare('(i.unit_id = %d) DESC, ', (int) $prefer_unit) : '';
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' i WHERE i.category_id = %d AND ' . self::sql('i') . ' = %s AND i.praba IS NOT NULL AND ABS(i.praba - %f) < 0.005 AND i.id <> %d ORDER BY i.archived ASC, ' . $unit_order . 'i.id ASC LIMIT 1', (int) $category_id, $metal, $p, (int) $exclude_id), ARRAY_A);
        return $row ?: null;
    }

    public static function of_item($item) {
        if (!is_array($item) || !self::tracked((int) ($item['category_id'] ?? 0))) {
            return null;
        }
        $metal = self::item_metal($item);
        return $metal === '' ? null : ['category_id' => (int) $item['category_id'], 'metal' => $metal];
    }

    public static function of_line($d) {
        if (!is_array($d)) {
            return null;
        }
        $metal = self::clean($d['metal'] ?? '');
        if ($metal === '') {
            return null;
        }
        $cid = (int) ($d['category_id'] ?? 0);
        if ($cid <= 0 && (int) ($d['item_id'] ?? 0) > 0) {
            $item = CJS_Inv_Catalog::item_row((int) $d['item_id']);
            $cid = $item ? (int) $item['category_id'] : 0;
        }
        return self::tracked($cid) ? ['category_id' => $cid, 'metal' => $metal] : null;
    }

    public static function same($a, $b) {
        return is_array($a) && is_array($b) && (int) $a['category_id'] === (int) $b['category_id'] && self::key($a['metal']) === self::key($b['metal']);
    }

    public static function same_unit($d, $unit_id) {
        $line_unit = is_array($d) ? (int) ($d['unit_id'] ?? 0) : 0;
        return $line_unit <= 0 || $line_unit === (int) $unit_id;
    }

    public static function same_stock(array $d, $item_id) {
        $m = self::of_line($d);
        if (!$m) {
            return (int) ($d['item_id'] ?? 0) === (int) $item_id;
        }
        $item = CJS_Inv_Catalog::item_row((int) $item_id);
        return $item && self::same($m, self::of_item($item)) && self::same_unit($d, (int) $item['unit_id']);
    }

    public static function gram_unit_id() {
        return (int) CJS_Inv_Catalog::unit_id_by_label('g');
    }

    public static function not_in_grams($item) {
        if (!is_array($item) || !self::tracked((int) ($item['category_id'] ?? 0))) {
            return false;
        }
        $g = self::gram_unit_id();
        return $g > 0 && (int) ($item['unit_id'] ?? 0) !== $g;
    }

    public static function legacy_unit_message($item) {
        return sprintf(__('%1$s is set up in %2$s. Metal is kept in grams: archive this item and create it again in grams.', 'custom-jewelry-system'), (string) ($item['name'] ?? ''), CJS_Inv_Catalog::unit_label((int) ($item['unit_id'] ?? 0)));
    }

    public static function unit_error($item) {
        return self::not_in_grams($item) ? new WP_Error('cjs_invalid', self::legacy_unit_message($item)) : null;
    }

    public static function items($category_id, $metal, $unit_id = 0, $with_archived = false) {
        global $wpdb;
        $metal = self::clean($metal);
        if ((int) $category_id <= 0 || $metal === '') {
            return [];
        }
        $where = $wpdb->prepare('i.category_id = %d AND ' . self::sql('i') . ' = %s', (int) $category_id, $metal);
        if (!$with_archived) {
            $where .= ' AND i.archived = 0';
        }
        if ((int) $unit_id > 0) {
            $where .= $wpdb->prepare(' AND i.unit_id = %d', (int) $unit_id);
        }
        return $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('items') . " i WHERE $where ORDER BY i.archived ASC, i.praba IS NULL ASC, i.praba DESC, i.id ASC", ARRAY_A) ?: [];
    }

    public static function item_ids($category_id, $metal, $unit_id = 0) {
        return array_map(function ($r) { return (int) $r['id']; }, self::items($category_id, $metal, $unit_id));
    }

    public static function best($category_id, $metal, $unit_id = 0) {
        $rows = self::items($category_id, $metal, $unit_id);
        return $rows ? $rows[0] : null;
    }

    public static function lines_sql(array $item_ids, $alias = 'd') {
        $item_ids = array_values(array_unique(array_filter(array_map('absint', $item_ids))));
        if (!$item_ids) {
            return '';
        }
        global $wpdb;
        $conds = ["$alias.item_id IN (" . CJS_Inv_DB::ids_in($item_ids) . ')'];
        $seen = [];
        foreach (CJS_Inv_Catalog::items_rows($item_ids) as $item) {
            $m = self::of_item($item);
            if (!$m) {
                continue;
            }
            $k = $m['category_id'] . '|' . self::key($m['metal']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $conds[] = $wpdb->prepare("($alias.category_id = %d AND $alias.metal = %s)", $m['category_id'], $m['metal']);
        }
        return '(' . implode(' OR ', $conds) . ')';
    }

    public static function repoint($category_id, $metal) {
        global $wpdb;
        $metal = self::clean($metal);
        if ((int) $category_id <= 0 || $metal === '') {
            return 0;
        }
        $d = CJS_Inv_DB::t('demands');
        $units = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT unit_id FROM $d WHERE category_id = %d AND metal = %s AND state = 'open'", (int) $category_id, $metal)) ?: []);
        $n = 0;
        foreach ($units as $unit_id) {
            $best = $unit_id > 0 ? self::best($category_id, $metal, $unit_id) : null;
            $item_id = $best ? (int) $best['id'] : 0;
            $r = $wpdb->query($wpdb->prepare("UPDATE $d SET item_id = %d WHERE category_id = %d AND metal = %s AND state = 'open' AND unit_id = %d AND item_id <> %d", $item_id, (int) $category_id, $metal, $unit_id, $item_id));
            $n += $r === false ? 0 : (int) $r;
        }
        CJS_Inv_Flow::flush();
        return $n;
    }

    public static function after_item_saved($item_id, $old_metal = null, $old_category_id = 0) {
        global $wpdb;
        $item = CJS_Inv_Catalog::item_row((int) $item_id);
        if (!$item) {
            return;
        }
        $now = self::of_item($item);
        $old_metal = $old_metal !== null ? self::clean($old_metal) : '';
        $old_cat = (int) $old_category_id ?: (int) $item['category_id'];
        if ($now && $old_metal !== '' && self::key($old_metal) !== self::key($now['metal']) && $old_cat === (int) $now['category_id'] && !self::items($old_cat, $old_metal)) {
            $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET metal = %s WHERE category_id = %d AND metal = %s', $now['metal'], $old_cat, $old_metal));
        }
        if ($now) {
            $added = self::repoint($now['category_id'], $now['metal']);
            if ($added) {
                CJS_Inv_Demands::reserve_pending([(int) $item_id]);
            }
        }
        if ($old_metal !== '' && (!$now || self::key($old_metal) !== self::key($now['metal']))) {
            self::repoint($old_cat, $old_metal);
        }
    }

    public static function sync_category($category_id) {
        global $wpdb;
        $category_id = (int) $category_id;
        self::flush();
        CJS_Inv_Flow::flush();
        if (!self::tracked($category_id)) {
            return ['lines' => 0, 'needs_praba' => []];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d ORDER BY id', $category_id), ARRAY_A) ?: [];
        $needs = [];
        foreach ($rows as $item) {
            $praba = self::praba_of($item);
            $metal = self::clean($item['metal'] ?? '');
            if ($metal === '') {
                [$base, $from_name] = self::split_name((string) $item['name']);
                $metal = $base;
                if ($praba === null && $from_name !== null && !self::find_same($category_id, $base, $from_name, (int) $item['id'])) {
                    $praba = $from_name;
                }
            }
            if ($metal === '') {
                continue;
            }
            $row = ['metal' => mb_substr($metal, 0, 191)];
            if ($praba !== null) {
                $row['praba'] = $praba;
                $row['name'] = mb_substr(self::display($metal, $praba), 0, 255);
                $sig = self::signature($category_id, $metal, $praba);
                $dup = CJS_Inv_Catalog::find_by_signature($category_id, $sig);
                if (!$dup || (int) $dup['id'] === (int) $item['id']) {
                    $row['signature'] = $sig;
                }
            } else {
                $needs[] = ['id' => (int) $item['id'], 'name' => (string) $item['name']];
            }
            CJS_Inv_DB::update('items', $row, ['id' => (int) $item['id']]);
        }
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' d INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id SET d.category_id = i.category_id, d.metal = " . self::sql('i') . " WHERE i.category_id = %d AND (d.metal IS NULL OR d.metal = '')", $category_id));
        CJS_Inv_Flow::flush();
        return ['lines' => $n === false ? 0 : (int) $n, 'needs_praba' => $needs];
    }

    public static function needs_praba($category_id) {
        global $wpdb;
        if (!self::tracked($category_id)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, name FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND archived = 0 AND praba IS NULL ORDER BY name, id LIMIT 200', (int) $category_id), ARRAY_A) ?: [];
        return array_map(function ($r) { return ['id' => (int) $r['id'], 'name' => (string) $r['name']]; }, $rows);
    }

    public static function payload($category_id, $metal, $unit_id = 0) {
        $out = [];
        foreach (self::items($category_id, $metal, $unit_id) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'praba' => self::praba_of($r),
                'unit' => CJS_Inv_Catalog::unit_label($r['unit_id']),
                'free' => round((float) $r['qty_on_hand'] - (float) $r['qty_reserved'], 4),
            ];
        }
        return $out;
    }

    public static function unit_note($category_id, $metal, $unit_id) {
        $unit_id = (int) $unit_id;
        if ($unit_id <= 0 || self::items($category_id, $metal, $unit_id)) {
            return null;
        }
        $names = [];
        $units = [];
        foreach (self::items($category_id, $metal) as $r) {
            if ((int) $r['unit_id'] === $unit_id) {
                continue;
            }
            $names[] = (string) $r['name'];
            $units[] = CJS_Inv_Catalog::unit_label($r['unit_id']);
        }
        if (!$names) {
            return null;
        }
        $line_unit = CJS_Inv_Catalog::unit_label($unit_id);
        $units = implode(', ', array_values(array_unique($units)));
        $set_up = count($names) === 1
            ? sprintf(__('%1$s is set up in %2$s.', 'custom-jewelry-system'), $names[0], $units)
            : sprintf(__('%1$s are set up in %2$s.', 'custom-jewelry-system'), implode(', ', $names), $units);
        $hint = $line_unit === 'g'
            ? (count($names) === 1 ? __('Metal is kept in grams: archive this item and create it again in grams.', 'custom-jewelry-system') : __('Metal is kept in grams: archive these items and create them again in grams.', 'custom-jewelry-system'))
            : sprintf(__('Metal from orders is in %1$s; change the item\'s unit or create a %1$s item.', 'custom-jewelry-system'), $line_unit);
        return $set_up . ' ' . $hint;
    }

    public static function not_in_grams_items() {
        global $wpdb;
        $g = self::gram_unit_id();
        if ($g <= 0) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare('SELECT i.id, i.name, i.unit_id FROM ' . CJS_Inv_DB::t('items') . ' i INNER JOIN ' . CJS_Inv_DB::t('categories') . ' c ON c.id = i.category_id WHERE c.has_fineness = 1 AND i.archived = 0 AND i.unit_id <> %d ORDER BY i.id', $g), ARRAY_A) ?: [];
    }

    public static function free_at_ref($category_id, $metal, $unit_id = 0) {
        $total = 0.0;
        foreach (self::items($category_id, $metal, $unit_id) as $r) {
            $free = round((float) $r['qty_on_hand'] - (float) $r['qty_reserved'], 4);
            if ($free > 0) {
                $total += CJS_Inv_Flow::fine_for($free, self::conv_praba($r));
            }
        }
        return round($total, 4);
    }

    public static function unit_mismatches() {
        global $wpdb;
        return $wpdb->get_results('SELECT i.category_id, MIN(' . self::sql('i') . ') AS metal, COUNT(DISTINCT i.unit_id) AS units, GROUP_CONCAT(i.id ORDER BY i.id) AS item_ids FROM ' . CJS_Inv_DB::t('items') . ' i INNER JOIN ' . CJS_Inv_DB::t('categories') . ' c ON c.id = i.category_id WHERE c.has_fineness = 1 AND i.archived = 0 GROUP BY i.category_id, ' . self::sql('i') . ' HAVING COUNT(DISTINCT i.unit_id) > 1', ARRAY_A) ?: [];
    }

    public static function ensure_item($category_id, $metal, $praba, $unit_id = 0) {
        $metal = self::clean($metal);
        $p = CJS_Inv_Flow::clean_praba($praba);
        if (!self::tracked($category_id) || $metal === '' || $p === null) {
            return new WP_Error('cjs_invalid', __('Item not found.', 'custom-jewelry-system'));
        }
        foreach (self::items($category_id, $metal, (int) $unit_id) as $r) {
            $rp = self::praba_of($r);
            if ($rp !== null && abs($rp - $p) < 0.005) {
                return (int) $r['id'];
            }
        }
        return CJS_Inv_Catalog::save_item(['category_id' => (int) $category_id, 'name' => $metal, 'name_custom' => 1, 'praba' => $p]);
    }
}
