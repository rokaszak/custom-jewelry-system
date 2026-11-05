<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Catalog {

    const FIELD_TYPES = ['select', 'number', 'text'];
    const PIECE_STATES = ['in' => 'in', 'to_send' => 'reserved', 'out' => 'out', 'used' => 'used', 'lost' => 'removed'];
    const ICON_OPTION = 'cjs_category_icons';
    const ICON_DEFAULTS = ['stones' => 'diamond-stone', 'metals' => 'gold', 'packaging' => 'gift', 'sizing-kits' => 'ring'];

    private static $units_cache = null;

    public static function flush_cache() {
        self::$units_cache = null;
    }

    public static function units() {
        global $wpdb;
        if (self::$units_cache === null) {
            $rows = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('units') . ' ORDER BY sort_order ASC, id ASC', ARRAY_A);
            self::$units_cache = [];
            foreach ($rows ?: [] as $row) {
                self::$units_cache[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'label' => $row['label'],
                    'name' => $row['name'],
                    'decimals' => (int) $row['decimals'],
                ];
            }
        }
        return self::$units_cache;
    }

    public static function unit_label($unit_id) {
        $units = self::units();
        return isset($units[(int) $unit_id]) ? $units[(int) $unit_id]['label'] : '';
    }

    public static function unit_id_by_label($label) {
        foreach (self::units() as $unit) {
            if (strcasecmp($unit['label'], (string) $label) === 0) {
                return $unit['id'];
            }
        }
        return 0;
    }

    public static function schema($include_archived = false) {
        global $wpdb;
        $where = $include_archived ? '' : 'WHERE archived = 0';
        $categories = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('categories') . " $where ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
        $fields = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('fields') . ' ORDER BY sort_order ASC, id ASC', ARRAY_A) ?: [];
        $choices = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('field_choices') . ' ORDER BY sort_order ASC, id ASC', ARRAY_A) ?: [];
        $field_units = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('field_units') . ' ORDER BY sort_order ASC', ARRAY_A) ?: [];
        $counts = $wpdb->get_results('SELECT category_id, COUNT(*) AS n FROM ' . CJS_Inv_DB::t('items') . ' WHERE archived = 0 GROUP BY category_id', ARRAY_A) ?: [];
        $count_map = [];
        foreach ($counts as $row) {
            $count_map[(int) $row['category_id']] = (int) $row['n'];
        }

        $choices_by_field = [];
        foreach ($choices as $ch) {
            $choices_by_field[(int) $ch['field_id']][] = [
                'id' => (int) $ch['id'],
                'label' => $ch['label'],
                'slug' => $ch['slug'],
                'archived' => (bool) $ch['archived'],
            ];
        }
        $units_by_field = [];
        foreach ($field_units as $fu) {
            $units_by_field[(int) $fu['field_id']][] = (int) $fu['unit_id'];
        }
        $fields_by_cat = [];
        foreach ($fields as $f) {
            $fid = (int) $f['id'];
            $fields_by_cat[(int) $f['category_id']][] = [
                'id' => $fid,
                'category_id' => (int) $f['category_id'],
                'name' => $f['name'],
                'slug' => $f['slug'],
                'type' => $f['type'],
                'in_name' => (bool) $f['in_name'],
                'is_size' => (bool) $f['is_size'],
                'required' => (bool) $f['required'],
                'choices' => $choices_by_field[$fid] ?? [],
                'unit_ids' => $units_by_field[$fid] ?? [],
            ];
        }
        $tracked_ids = [];
        foreach ($categories as $cat) {
            if ((int) ($cat['has_fineness'] ?? 0) === 1) {
                $tracked_ids[] = (int) $cat['id'];
            }
        }
        $needs_praba = [];
        if ($tracked_ids) {
            $list = $wpdb->get_results('SELECT id, name, category_id FROM ' . CJS_Inv_DB::t('items') . ' WHERE archived = 0 AND praba IS NULL AND category_id IN (' . CJS_Inv_DB::ids_in($tracked_ids) . ') ORDER BY name, id LIMIT 200', ARRAY_A) ?: [];
            foreach ($list as $r) {
                $needs_praba[(int) $r['category_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
            }
        }
        $out = [];
        foreach ($categories as $cat) {
            $cid = (int) $cat['id'];
            $out[] = [
                'needs_praba' => $needs_praba[$cid] ?? [],
                'id' => $cid,
                'name' => $cat['name'],
                'slug' => $cat['slug'],
                'unit_id' => (int) $cat['unit_id'],
                'returnable' => (bool) $cat['returnable'],
                'per_piece' => (bool) $cat['per_piece'],
                'auto_assign' => (int) ($cat['auto_assign'] ?? 1) === 1,
                'used_by_status' => CJS_Inv_Flow::valid_key($cat['used_by_status'] ?? 'done'),
                'back_by_status' => CJS_Inv_Flow::valid_key($cat['back_by_status'] ?? 'done'),
                'has_fineness' => (int) ($cat['has_fineness'] ?? 0) === 1,
                'checkout_offer' => (int) $cat['returnable'] === 1 ? CJS_Size_Kit::offer_of($cat) : CJS_Size_Kit::offer_defaults(),
                'icon' => self::category_icon($cat),
                'archived' => (bool) $cat['archived'],
                'item_count' => $count_map[$cid] ?? 0,
                'fields' => $fields_by_cat[$cid] ?? [],
            ];
        }
        return [
            'units' => array_values(self::units()),
            'categories' => $out,
        ];
    }

    public static function category($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('categories') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function category_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('categories') . ' WHERE slug = %s', $slug), ARRAY_A);
    }

    public static function fields_for_category($category_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d ORDER BY sort_order ASC, id ASC', $category_id), ARRAY_A) ?: [];
    }

    public static function field($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('fields') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function category_icon($cat) {
        $cat = is_array($cat) ? $cat : [];
        $map = get_option(self::ICON_OPTION, []);
        $id = (int) ($cat['id'] ?? 0);
        if ($id && is_array($map) && array_key_exists($id, $map)) {
            return (string) $map[$id];
        }
        return self::ICON_DEFAULTS[(string) ($cat['slug'] ?? '')] ?? '';
    }

    private static function save_category_icon($id, $icon) {
        $id = (int) $id;
        if (!$id) {
            return;
        }
        $icon = substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) $icon)), 0, 40);
        $map = get_option(self::ICON_OPTION, []);
        $map = is_array($map) ? $map : [];
        $map[$id] = $icon;
        update_option(self::ICON_OPTION, $map, false);
    }

    public static function save_category(array $data) {
        $id = absint($data['id'] ?? 0);
        $name = trim(sanitize_text_field($data['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('cjs_invalid', __('Category name is required.', 'custom-jewelry-system'));
        }
        $unit_id = absint($data['unit_id'] ?? 0);
        if (!$unit_id || !isset(self::units()[$unit_id])) {
            $unit_id = self::unit_id_by_label('pcs');
        }
        $row = [
            'name' => $name,
            'unit_id' => $unit_id,
            'returnable' => empty($data['returnable']) ? 0 : 1,
            'per_piece' => empty($data['per_piece']) ? 0 : 1,
            'updated_at' => CJS_Inv_DB::now(),
        ];
        foreach (['auto_assign', 'has_fineness'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $row[$flag] = !empty($data[$flag]) && $data[$flag] !== 'false' ? 1 : 0;
            }
        }
        foreach (['used_by_status', 'back_by_status'] as $status_field) {
            if (array_key_exists($status_field, $data)) {
                $key = CJS_Inv_Flow::status_key((string) $data[$status_field]);
                if ($key === null) {
                    return new WP_Error('cjs_invalid', __('Unknown status.', 'custom-jewelry-system'));
                }
                $row[$status_field] = $key;
            }
        }
        $prev = $id ? self::category($id) : null;
        $praba_on = isset($row['has_fineness']) ? (int) $row['has_fineness'] === 1 : (int) ($prev['has_fineness'] ?? 0) === 1;
        if ($praba_on) {
            $row['returnable'] = 0;
            $row['per_piece'] = 0;
            $row['auto_assign'] = 1;
            $gram = CJS_Inv_Metals::gram_unit_id();
            if ($gram > 0) {
                $row['unit_id'] = $gram;
            }
        } elseif ($row['returnable']) {
            $row['per_piece'] = 1;
        }
        if (!$row['returnable']) {
            $row['checkout_offer'] = null;
        } elseif (array_key_exists('checkout_offer', $data)) {
            $offer = CJS_Size_Kit::clean_offer($data['checkout_offer'], $id ? self::category($id) : null);
            if (is_wp_error($offer)) {
                return $offer;
            }
            $row['checkout_offer'] = wp_json_encode($offer);
        }
        CJS_Inv_Flow::flush();
        CJS_Size_Kit::flush();
        if ($id) {
            $before = self::category($id);
            if (!$before) {
                return new WP_Error('cjs_not_found', __('Category not found.', 'custom-jewelry-system'));
            }
            if (isset($row['has_fineness']) && (int) $row['has_fineness'] !== (int) ($before['has_fineness'] ?? 0) && self::category_in_use($id)) {
                return new WP_Error('cjs_locked', __('“Tracked by praba” can only be switched while this category has no stock and no open order lines. Use up, count out or move its stock and finish or remove its open lines first.', 'custom-jewelry-system'));
            }
            $ret_changed = (int) $row['returnable'] !== (int) ($before['returnable'] ?? 0);
            $praba_changed = isset($row['has_fineness']) && (int) $row['has_fineness'] !== (int) ($before['has_fineness'] ?? 0);
            if ($ret_changed && self::category_in_use($id)) {
                return new WP_Error('cjs_locked', __('Returnable can only be switched while this category has no stock and no open order lines. Use up, count out or move its stock and finish or remove its open lines first.', 'custom-jewelry-system'));
            }
            CJS_Inv_DB::update('categories', $row, ['id' => $id]);
            if ($ret_changed || $praba_changed) {
                global $wpdb;
                $wpdb->query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('items') . ' SET returnable = %d, per_piece = CASE WHEN %d = 1 THEN 1 WHEN %d = 1 THEN 0 ELSE per_piece END, updated_at = %s WHERE category_id = %d', (int) $row['returnable'], (int) $row['returnable'], $praba_on ? 1 : 0, CJS_Inv_DB::now(), $id));
            }
            CJS_Inv_Flow::flush();
            if (isset($row['has_fineness']) && (int) $row['has_fineness'] === 1 && (int) ($before['has_fineness'] ?? 0) !== 1) {
                CJS_Inv_Metals::sync_category($id);
            }
            if (array_key_exists('icon', $data)) {
                self::save_category_icon($id, $data['icon']);
            }
            return $id;
        }
        global $wpdb;
        $row['slug'] = self::unique_slug('categories', sanitize_title($data['slug'] ?? $name) ?: 'category', null);
        $row['sort_order'] = (int) $wpdb->get_var('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('categories'));
        $row['created_at'] = CJS_Inv_DB::now();
        $new_id = CJS_Inv_DB::insert('categories', $row);
        if ($new_id && !is_wp_error($new_id) && array_key_exists('icon', $data)) {
            self::save_category_icon($new_id, $data['icon']);
        }
        return $new_id;
    }

    public static function category_in_use($id) {
        global $wpdb;
        $id = (int) $id;
        $stock = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('batches') . ' b INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = b.item_id WHERE i.category_id = %d AND b.qty_on_hand > 0', $id));
        if ($stock > 0) {
            return true;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' d LEFT JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = d.item_id WHERE d.state = 'open' AND (i.category_id = %d OR (i.id IS NULL AND d.category_id = %d))", $id, $id)) > 0;
    }

    public static function archive_category($id, $archived = true) {
        CJS_Inv_DB::update('categories', ['archived' => $archived ? 1 : 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => absint($id)]);
        return true;
    }

    public static function reorder($table, array $ids, $scope_column = null, $scope_value = null) {
        $allowed = ['categories', 'fields', 'field_choices', 'stock_order_lines'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }
        $pos = 1;
        foreach ($ids as $id) {
            $where = ['id' => absint($id)];
            if ($scope_column) {
                $where[$scope_column] = absint($scope_value);
            }
            CJS_Inv_DB::update($table, ['sort_order' => $pos++], $where);
        }
        return true;
    }

    public static function save_field($category_id, array $data) {
        global $wpdb;
        $category_id = absint($category_id);
        if (!self::category($category_id)) {
            return new WP_Error('cjs_not_found', __('Category not found.', 'custom-jewelry-system'));
        }
        $id = absint($data['id'] ?? 0);
        $name = trim(sanitize_text_field($data['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('cjs_invalid', __('Field name is required.', 'custom-jewelry-system'));
        }
        $type = in_array($data['type'] ?? '', self::FIELD_TYPES, true) ? $data['type'] : 'select';
        $row = [
            'name' => $name,
            'in_name' => empty($data['in_name']) ? 0 : 1,
            'is_size' => empty($data['is_size']) ? 0 : 1,
            'required' => empty($data['required']) ? 0 : 1,
            'updated_at' => CJS_Inv_DB::now(),
        ];
        if ($id) {
            $existing = self::field($id);
            if (!$existing || (int) $existing['category_id'] !== $category_id) {
                return new WP_Error('cjs_not_found', __('Field not found.', 'custom-jewelry-system'));
            }
            if ($existing['type'] !== $type) {
                $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $id));
                if ($used > 0) {
                    return new WP_Error('cjs_locked', __('The field type cannot change while items use this field.', 'custom-jewelry-system'));
                }
                $row['type'] = $type;
            }
        }
        return CJS_Inv_DB::transaction(function () use ($wpdb, $category_id, $id, $type, $row, $data, $name) {
            if ($row['is_size']) {
                $others = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND is_size = 1 AND id <> %d ORDER BY sort_order, id', $category_id, $id));
                foreach (array_slice(array_map('intval', $others ?: []), 1) as $drop) {
                    CJS_Inv_DB::update('fields', ['is_size' => 0], ['id' => $drop]);
                }
            }
            if ($id) {
                CJS_Inv_DB::update('fields', $row, ['id' => $id]);
                $field_id = $id;
            } else {
                $row['category_id'] = $category_id;
                $row['type'] = $type;
                $row['slug'] = self::unique_field_slug($category_id, sanitize_title($name) ?: 'field');
                $row['sort_order'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d', $category_id));
                $row['created_at'] = CJS_Inv_DB::now();
                $field_id = CJS_Inv_DB::insert('fields', $row);
            }
            if ($type === 'number' && isset($data['unit_ids']) && is_array($data['unit_ids'])) {
                self::set_field_units($field_id, $data['unit_ids']);
            }
            if ($type === 'select' && isset($data['choices']) && is_array($data['choices'])) {
                foreach ($data['choices'] as $label) {
                    if (is_string($label) && trim($label) !== '') {
                        self::add_choice($field_id, $label);
                    }
                }
            }
            if ($id) {
                self::rebuild_category_names($category_id);
            }
            return $field_id;
        });
    }

    public static function delete_field($id) {
        global $wpdb;
        $field = self::field($id);
        if (!$field) {
            return new WP_Error('cjs_not_found', __('Field not found.', 'custom-jewelry-system'));
        }
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $id));
        if ($used > 0) {
            return new WP_Error('cjs_locked', sprintf(__('%d items use this field, so it cannot be deleted.', 'custom-jewelry-system'), $used));
        }
        CJS_Inv_DB::transaction(function () use ($wpdb, $id) {
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d', $id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('fields') . ' WHERE id = %d', $id));
        });
        return true;
    }

    public static function set_field_units($field_id, array $unit_ids) {
        global $wpdb;
        $units = self::units();
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d', $field_id));
        $pos = 1;
        foreach (array_unique(array_map('absint', $unit_ids)) as $unit_id) {
            if (isset($units[$unit_id])) {
                CJS_Inv_DB::insert('field_units', ['field_id' => $field_id, 'unit_id' => $unit_id, 'sort_order' => $pos++]);
            }
        }
    }

    public static function add_choice($field_id, $label) {
        global $wpdb;
        $label = trim(sanitize_text_field($label));
        if ($label === '') {
            return new WP_Error('cjs_invalid', __('Choice label is required.', 'custom-jewelry-system'));
        }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d AND LOWER(label) = LOWER(%s)', $field_id, $label), ARRAY_A);
        if ($existing) {
            if ((int) $existing['archived'] === 1) {
                CJS_Inv_DB::update('field_choices', ['archived' => 0], ['id' => (int) $existing['id']]);
            }
            return (int) $existing['id'];
        }
        $slug = sanitize_title($label) ?: 'choice';
        $base = $slug;
        $n = 2;
        while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d AND slug = %s', $field_id, $slug))) {
            $slug = $base . '-' . $n++;
        }
        $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $field_id));
        return CJS_Inv_DB::insert('field_choices', [
            'field_id' => $field_id,
            'label' => $label,
            'slug' => $slug,
            'sort_order' => $sort,
        ]);
    }

    public static function update_choice($choice_id, array $data) {
        global $wpdb;
        $choice = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE id = %d', $choice_id), ARRAY_A);
        if (!$choice) {
            return new WP_Error('cjs_not_found', __('Choice not found.', 'custom-jewelry-system'));
        }
        $row = [];
        if (isset($data['label'])) {
            $label = trim(sanitize_text_field($data['label']));
            if ($label === '') {
                return new WP_Error('cjs_invalid', __('Choice label is required.', 'custom-jewelry-system'));
            }
            $row['label'] = $label;
        }
        if (isset($data['archived'])) {
            $row['archived'] = $data['archived'] ? 1 : 0;
        }
        if ($row) {
            CJS_Inv_DB::update('field_choices', $row, ['id' => (int) $choice_id]);
            if (isset($row['label'])) {
                $field = self::field((int) $choice['field_id']);
                if ($field) {
                    self::rebuild_category_names((int) $field['category_id']);
                }
            }
        }
        return (int) $choice_id;
    }

    public static function delete_choice($choice_id) {
        global $wpdb;
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE choice_id = %d', $choice_id));
        if ($used > 0) {
            CJS_Inv_DB::update('field_choices', ['archived' => 1], ['id' => (int) $choice_id]);
            return 'archived';
        }
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE id = %d', $choice_id));
        return 'deleted';
    }

    private static function unique_slug($table, $slug, $exclude_id) {
        global $wpdb;
        $base = $slug;
        $n = 2;
        while (true) {
            $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t($table) . ' WHERE slug = %s', $slug));
            if (!$found || ($exclude_id && (int) $found === (int) $exclude_id)) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
        }
    }

    private static function unique_field_slug($category_id, $slug) {
        global $wpdb;
        $base = $slug;
        $n = 2;
        while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $category_id, $slug))) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    public static function normalize_values($category_id, array $raw_values) {
        global $wpdb;
        $fields = self::fields_for_category($category_id);
        $by_id = [];
        foreach ($fields as $f) {
            $by_id[(int) $f['id']] = $f;
        }
        $units = self::units();
        $normalized = [];
        $errors = [];
        foreach ($raw_values as $field_id => $value) {
            $field_id = absint($field_id);
            if (!isset($by_id[$field_id])) {
                continue;
            }
            $field = $by_id[$field_id];
            if (is_array($value) && array_key_exists('value', $value) && !array_key_exists('choice_id', $value)) {
                $flat = $value;
            } else {
                $flat = is_array($value) ? $value : ['value' => $value];
            }
            if ($field['type'] === 'select') {
                $choice_id = absint($flat['choice_id'] ?? ($flat['value'] ?? 0));
                if (!$choice_id && !empty($flat['label'])) {
                    $choice_id = (int) self::add_choice($field_id, $flat['label']);
                }
                if (!$choice_id) {
                    continue;
                }
                $ok = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE id = %d AND field_id = %d', $choice_id, $field_id));
                if (!$ok) {
                    $errors[] = sprintf(__('Invalid choice for %s.', 'custom-jewelry-system'), $field['name']);
                    continue;
                }
                $normalized[$field_id] = ['choice_id' => $choice_id, 'value_number' => null, 'unit_id' => null, 'value_text' => null];
            } elseif ($field['type'] === 'number') {
                $num = $flat['value_number'] ?? ($flat['value'] ?? null);
                if ($num === null || $num === '') {
                    continue;
                }
                $num = str_replace(',', '.', (string) $num);
                if (!is_numeric($num)) {
                    $errors[] = sprintf(__('%s must be a number.', 'custom-jewelry-system'), $field['name']);
                    continue;
                }
                $unit_id = absint($flat['unit_id'] ?? 0);
                if (!$unit_id || !isset($units[$unit_id])) {
                    $allowed = $wpdb->get_col($wpdb->prepare('SELECT unit_id FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d ORDER BY sort_order ASC', $field_id));
                    $unit_id = $allowed ? (int) $allowed[0] : 0;
                }
                if (!$unit_id) {
                    $errors[] = sprintf(__('%s needs a unit.', 'custom-jewelry-system'), $field['name']);
                    continue;
                }
                $normalized[$field_id] = ['choice_id' => null, 'value_number' => CJS_Inv_DB::num($num), 'unit_id' => $unit_id, 'value_text' => null];
            } else {
                $text = trim(sanitize_text_field($flat['value_text'] ?? ($flat['value'] ?? '')));
                if ($text === '') {
                    continue;
                }
                $normalized[$field_id] = ['choice_id' => null, 'value_number' => null, 'unit_id' => null, 'value_text' => mb_substr($text, 0, 255)];
            }
        }
        foreach ($fields as $f) {
            if ((int) $f['required'] === 1 && !isset($normalized[(int) $f['id']])) {
                $errors[] = sprintf(__('%s is required.', 'custom-jewelry-system'), $f['name']);
            }
        }
        return ['values' => $normalized, 'errors' => $errors];
    }

    public static function build_name($category_id, array $values, $fallback = '') {
        global $wpdb;
        $fields = self::fields_for_category($category_id);
        $choice_ids = [];
        foreach ($values as $v) {
            if (!empty($v['choice_id'])) {
                $choice_ids[] = (int) $v['choice_id'];
            }
        }
        $labels = [];
        if ($choice_ids) {
            $rows = $wpdb->get_results('SELECT id, label FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE id IN (' . CJS_Inv_DB::ids_in($choice_ids) . ')', ARRAY_A);
            foreach ($rows ?: [] as $r) {
                $labels[(int) $r['id']] = $r['label'];
            }
        }
        $parts = [];
        $size_ids = [];
        foreach ($fields as $f) {
            if ((int) $f['is_size'] && $f['type'] === 'number') {
                $size_ids[] = (int) $f['id'];
            }
        }
        $pair = null;
        if (count($size_ids) >= 2) {
            $a = $values[$size_ids[0]] ?? null;
            $b = $values[$size_ids[1]] ?? null;
            if ($a && $b && ($a['value_number'] ?? null) !== null && ($b['value_number'] ?? null) !== null && (int) ($a['unit_id'] ?? 0) === (int) ($b['unit_id'] ?? 0)) {
                $pair = [$size_ids[0], $size_ids[1], trim(CJS_Inv_DB::fmt($a['value_number']) . ' × ' . CJS_Inv_DB::fmt($b['value_number']) . ' ' . self::unit_label($a['unit_id']))];
            }
        }
        foreach ($fields as $f) {
            $fid = (int) $f['id'];
            if ($pair && $fid === $pair[1]) {
                continue;
            }
            if ($pair && $fid === $pair[0]) {
                $parts[] = $pair[2];
                continue;
            }
            if (!(int) $f['in_name'] || !isset($values[$fid])) {
                continue;
            }
            $v = $values[$fid];
            if (!empty($v['choice_id'])) {
                $parts[] = $labels[(int) $v['choice_id']] ?? '';
            } elseif ($v['value_number'] !== null) {
                $parts[] = trim(CJS_Inv_DB::fmt($v['value_number']) . ' ' . self::unit_label($v['unit_id']));
            } elseif ($v['value_text'] !== null) {
                $parts[] = $v['value_text'];
            }
        }
        $parts = array_values(array_filter($parts, 'strlen'));
        if ($parts) {
            return implode(' · ', $parts);
        }
        if ($fallback !== '') {
            return $fallback;
        }
        $cat = self::category($category_id);
        return $cat ? $cat['name'] : '';
    }

    public static function signature($category_id, array $values, $name) {
        ksort($values);
        $parts = ['c' . (int) $category_id];
        foreach ($values as $field_id => $v) {
            if (!empty($v['choice_id'])) {
                $parts[] = 'f' . $field_id . '=c' . (int) $v['choice_id'];
            } elseif ($v['value_number'] !== null) {
                $parts[] = 'f' . $field_id . '=n' . CJS_Inv_DB::fmt($v['value_number']) . 'u' . (int) $v['unit_id'];
            } elseif ($v['value_text'] !== null) {
                $parts[] = 'f' . $field_id . '=t' . mb_strtolower(trim($v['value_text']));
            }
        }
        if (count($parts) === 1) {
            $parts[] = 'name=' . mb_strtolower(trim((string) $name));
        }
        return sha1(implode('|', $parts));
    }

    public static function find_by_signature($category_id, $signature) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND signature = %s', $category_id, $signature), ARRAY_A);
    }

    public static function item_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE id = %d', $id), ARRAY_A);
    }

    public static function item_values($item_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE item_id = %d', $item_id), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['field_id']] = [
                'choice_id' => $r['choice_id'] !== null ? (int) $r['choice_id'] : null,
                'value_number' => $r['value_number'] !== null ? (float) $r['value_number'] : null,
                'unit_id' => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
                'value_text' => $r['value_text'],
            ];
        }
        return $out;
    }

    public static function item_usage($item_id) {
        global $wpdb;
        $item_id = (int) $item_id;
        return [
            'batches' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d', $item_id)),
            'lines' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . " WHERE item_id = %d AND state <> 'cancelled'", $item_id)),
            'open_lines' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . " WHERE item_id = %d AND state = 'open'", $item_id)),
            'stock_order_lines' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE item_id = %d', $item_id)),
            'live_allocations' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id INNER JOIN ' . CJS_Inv_DB::t('demands') . " d ON d.id = a.demand_id WHERE b.item_id = %d AND (a.state IN ('reserved','out') OR (a.state = 'used' AND d.state = 'open'))", $item_id)),
        ];
    }

    public static function save_item(array $data) {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $existing = $id ? self::item_row($id) : null;
        if ($id && !$existing) {
            return new WP_Error('cjs_not_found', __('Item not found.', 'custom-jewelry-system'));
        }
        $category_id = $existing ? (int) $existing['category_id'] : absint($data['category_id'] ?? 0);
        $category = self::category($category_id);
        if (!$category) {
            return new WP_Error('cjs_invalid', __('Choose a category.', 'custom-jewelry-system'));
        }
        $raw_values = isset($data['values']) && is_array($data['values']) ? $data['values'] : ($existing ? self::item_values($id) : []);
        $norm = self::normalize_values($category_id, $raw_values);
        if ($norm['errors']) {
            return new WP_Error('cjs_invalid', implode(' ', $norm['errors']));
        }
        $values = $norm['values'];
        $tracked = CJS_Inv_Metals::tracked($category_id);
        $praba = null;
        if ($tracked) {
            $praba = CJS_Inv_Flow::clean_praba(array_key_exists('praba', $data) ? $data['praba'] : ($existing['praba'] ?? null));
            if ($praba === null) {
                return new WP_Error('cjs_invalid', __('Enter the praba: greater than 0 and at most 1000.', 'custom-jewelry-system'));
            }
            $old_praba = $existing ? CJS_Inv_Metals::praba_of($existing) : null;
            if ($old_praba !== null && abs($old_praba - $praba) > 0.005 && self::item_usage($id)['batches'] > 0) {
                return new WP_Error('cjs_locked', __('The praba is locked because this item already has stock.', 'custom-jewelry-system'));
            }
        }
        $custom_name = trim(sanitize_text_field($data['name'] ?? ''));
        if ($tracked && $custom_name !== '') {
            $custom_name = self::strip_praba($custom_name, $praba);
        }
        $name_custom = !empty($data['name_custom']) && $custom_name !== '';
        $auto_name = self::build_name($category_id, $values, '');
        if ($name_custom) {
            $name = $custom_name;
        } elseif ($auto_name !== '' && $values) {
            $name = $auto_name;
        } elseif ($custom_name !== '') {
            $name = $custom_name;
            $name_custom = true;
        } elseif ($tracked && $existing && CJS_Inv_Metals::item_metal($existing) !== '') {
            $name = CJS_Inv_Metals::item_metal($existing);
        } elseif ($tracked && !$existing) {
            return new WP_Error('cjs_invalid', __('Type the item name, for example Auksas. The praba is added to it.', 'custom-jewelry-system'));
        } else {
            $name = $existing['name'] ?? $category['name'];
        }
        $metal = $tracked ? CJS_Inv_Metals::clean($name) : null;
        $unit_id = absint($data['unit_id'] ?? ($existing['unit_id'] ?? $category['unit_id']));
        if (!$unit_id || !isset(self::units()[$unit_id])) {
            $unit_id = (int) $category['unit_id'];
        }
        $gram = $tracked ? CJS_Inv_Metals::gram_unit_id() : 0;
        if ($gram > 0) {
            $sent_unit = absint($data['unit_id'] ?? 0);
            if ($sent_unit && isset(self::units()[$sent_unit]) && $sent_unit !== $gram) {
                return new WP_Error('cjs_invalid', __('Metal is kept in grams. Choose g as the stock unit.', 'custom-jewelry-system'));
            }
            if (!$existing || $sent_unit === $gram) {
                $unit_id = $gram;
            }
            if ($existing && $unit_id !== $gram) {
                return new WP_Error('cjs_invalid', CJS_Inv_Metals::legacy_unit_message($existing));
            }
            if ($existing && (int) $existing['unit_id'] !== $gram) {
                $legacy_usage = self::item_usage($id);
                if ($legacy_usage['batches'] > 0 || $legacy_usage['lines'] > 0 || $legacy_usage['stock_order_lines'] > 0) {
                    return new WP_Error('cjs_locked', CJS_Inv_Metals::legacy_unit_message($existing));
                }
            }
        }
        $signature = self::signature($category_id, $values, $name);
        $dup = null;
        if ($tracked) {
            $signature = CJS_Inv_Metals::signature($category_id, $metal, $praba);
            $name = CJS_Inv_Metals::display($metal, $praba);
            $dup = CJS_Inv_Metals::find_same($category_id, $metal, $praba, $id, $gram > 0 ? $unit_id : 0);
            if ($dup && (int) $dup['archived'] === 1 && $gram > 0 && (int) $dup['unit_id'] !== $unit_id) {
                $dup = null;
            }
            if ($dup && !$id && (int) $dup['archived'] === 1) {
                return self::restore_archived($dup, $data);
            }
            if (!$dup) {
                $taken = self::find_by_signature($category_id, $signature);
                if ($taken && (int) $taken['id'] !== $id) {
                    $signature = sha1($signature . '|' . ($id ?: wp_generate_password(12, false)));
                }
            }
        } else {
            $dup = self::find_by_signature($category_id, $signature);
        }
        if ($dup && (int) $dup['id'] !== $id) {
            return new WP_Error('cjs_duplicate', sprintf(__('Identical item already exists: %s', 'custom-jewelry-system'), $dup['name']), [
                'item_id' => (int) $dup['id'],
                'name' => $dup['name'],
                'archived' => (bool) $dup['archived'],
            ]);
        }
        $usage = $existing ? self::item_usage($id) : null;
        $returnable = $tracked ? 0 : (int) $category['returnable'];
        if ($existing && (int) $existing['returnable'] !== $returnable && $usage['live_allocations'] > 0) {
            $returnable = (int) $existing['returnable'];
        }
        $per_piece = array_key_exists('per_piece', $data) ? ($data['per_piece'] ? 1 : 0) : (int) ($existing['per_piece'] ?? $category['per_piece']);
        if ($tracked || $returnable) {
            $per_piece = $returnable ? 1 : 0;
            if ($existing && (int) $existing['per_piece'] !== $per_piece && ($usage['batches'] > 0 || $usage['open_lines'] > 0)) {
                $per_piece = (int) $existing['per_piece'];
            }
        }
        if ($existing && (int) $existing['unit_id'] !== $unit_id) {
            if ($usage['batches'] > 0) {
                return new WP_Error('cjs_locked', __('The stock unit is locked because this item already has stock.', 'custom-jewelry-system'));
            }
            if ($usage['lines'] > 0 || $usage['stock_order_lines'] > 0) {
                return new WP_Error('cjs_locked', __('The stock unit is locked because orders or stock orders already use this item.', 'custom-jewelry-system'));
            }
        }
        $code = trim(sanitize_text_field($data['code'] ?? ($existing['code'] ?? '')));
        $row = [
            'name' => mb_substr($name, 0, 255),
            'name_custom' => $name_custom ? 1 : 0,
            'code' => $code !== '' ? mb_substr($code, 0, 64) : null,
            'unit_id' => $unit_id,
            'returnable' => $returnable,
            'per_piece' => $per_piece,
            'default_cost' => array_key_exists('default_cost', $data) ? CJS_Inv_DB::num($data['default_cost']) : ($existing['default_cost'] ?? null),
            'default_price' => array_key_exists('default_price', $data) ? CJS_Inv_DB::num($data['default_price']) : ($existing['default_price'] ?? null),
            'reorder_level' => array_key_exists('reorder_level', $data) ? CJS_Inv_DB::num($data['reorder_level']) : ($existing['reorder_level'] ?? null),
            'signature' => $signature,
            'updated_at' => CJS_Inv_DB::now(),
        ];
        if ($tracked) {
            $row['praba'] = $praba;
            $row['metal'] = $metal;
        }
        if ($existing && (int) $existing['per_piece'] !== $row['per_piece']) {
            if ($usage['batches'] > 0) {
                return new WP_Error('cjs_locked', __('Per-piece tracking cannot change after the item has stock.', 'custom-jewelry-system'));
            }
            if ($usage['open_lines'] > 0) {
                return new WP_Error('cjs_locked', __('Per-piece tracking cannot change while orders are waiting for this item.', 'custom-jewelry-system'));
            }
        }
        if ($existing && (int) $existing['returnable'] !== $row['returnable'] && $usage['live_allocations'] > 0) {
            return new WP_Error('cjs_locked', __('Returnable cannot change while open orders hold stock of this item or pieces are out.', 'custom-jewelry-system'));
        }
        if ($row['code'] !== null) {
            $code_dup = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE code = %s AND id <> %d', $row['code'], $id));
            if ($code_dup) {
                return new WP_Error('cjs_duplicate_code', __('Another item already uses this item code.', 'custom-jewelry-system'), ['item_id' => (int) $code_dup]);
            }
        }
        CJS_Inv_Flow::flush();
        $old_metal = $existing && $tracked ? CJS_Inv_Metals::item_metal($existing) : null;
        $saved = CJS_Inv_DB::transaction(function () use ($wpdb, $id, $row, $values, $category_id) {
            if ($id) {
                CJS_Inv_DB::update('items', $row, ['id' => $id]);
                $item_id = $id;
                CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE item_id = %d', $item_id));
            } else {
                $row['category_id'] = $category_id;
                $row['created_by'] = get_current_user_id();
                $row['created_at'] = CJS_Inv_DB::now();
                $item_id = CJS_Inv_DB::insert('items', $row);
            }
            foreach ($values as $field_id => $v) {
                CJS_Inv_DB::insert('item_values', [
                    'item_id' => $item_id,
                    'field_id' => $field_id,
                    'choice_id' => $v['choice_id'],
                    'value_number' => $v['value_number'],
                    'unit_id' => $v['unit_id'],
                    'value_text' => $v['value_text'],
                ]);
            }
            return $item_id;
        });
        if ($tracked && !is_wp_error($saved)) {
            try {
                CJS_Inv_Metals::after_item_saved((int) $saved, $old_metal);
            } catch (Throwable $e) {
                error_log('CJS metal lines update failed for item ' . (int) $saved . ': ' . $e->getMessage());
            }
        }
        return $saved;
    }

    private static $restored = null;

    public static function take_restored() {
        $r = self::$restored;
        self::$restored = null;
        return $r;
    }

    private static function restore_archived(array $dup, array $data) {
        global $wpdb;
        $dup_id = (int) $dup['id'];
        $err_data = ['item_id' => $dup_id, 'name' => $dup['name'], 'archived' => true];
        $sent_unit = absint($data['unit_id'] ?? 0);
        if ($sent_unit && isset(self::units()[$sent_unit]) && $sent_unit !== (int) $dup['unit_id']) {
            return new WP_Error('cjs_invalid', sprintf(__('An archived item %1$s already exists with a different unit (%2$s). Restore it from the archive or use another name.', 'custom-jewelry-system'), $dup['name'], self::unit_label($dup['unit_id'])), $err_data);
        }
        if (array_key_exists('per_piece', $data) && ($data['per_piece'] ? 1 : 0) !== (int) $dup['per_piece']) {
            $message = (int) $dup['per_piece'] === 1
                ? __('An archived item %s already exists that is tracked per piece. Restore it from the archive or use another name.', 'custom-jewelry-system')
                : __('An archived item %s already exists that is not tracked per piece. Restore it from the archive or use another name.', 'custom-jewelry-system');
            return new WP_Error('cjs_invalid', sprintf($message, $dup['name']), $err_data);
        }
        $row = [];
        foreach (['default_cost', 'reorder_level'] as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $num = CJS_Inv_DB::num($data[$key]);
                if ($num !== null) {
                    $row[$key] = $num;
                }
            }
        }
        $code = array_key_exists('code', $data) && is_scalar($data['code']) ? trim(sanitize_text_field((string) $data['code'])) : '';
        if ($code !== '') {
            $row['code'] = mb_substr($code, 0, 64);
            $code_dup = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE code = %s AND id <> %d', $row['code'], $dup_id));
            if ($code_dup) {
                return new WP_Error('cjs_duplicate_code', __('Another item already uses this item code.', 'custom-jewelry-system'), ['item_id' => (int) $code_dup]);
            }
        }
        $values = null;
        if (isset($data['values']) && is_array($data['values']) && $data['values']) {
            $norm = self::normalize_values((int) $dup['category_id'], $data['values']);
            if ($norm['errors']) {
                return new WP_Error('cjs_invalid', implode(' ', $norm['errors']));
            }
            $values = $norm['values'] ?: null;
        }
        CJS_Inv_DB::transaction(function () use ($wpdb, $dup_id, $row, $values) {
            if ($row) {
                CJS_Inv_DB::update('items', $row + ['updated_at' => CJS_Inv_DB::now()], ['id' => $dup_id]);
            }
            if ($values !== null) {
                CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE item_id = %d', $dup_id));
                foreach ($values as $field_id => $v) {
                    CJS_Inv_DB::insert('item_values', [
                        'item_id' => $dup_id,
                        'field_id' => $field_id,
                        'choice_id' => $v['choice_id'],
                        'value_number' => $v['value_number'],
                        'unit_id' => $v['unit_id'],
                        'value_text' => $v['value_text'],
                    ]);
                }
            }
        });
        self::set_archived($dup_id, false);
        $row = self::item_row($dup_id);
        $name = $row ? (string) $row['name'] : (string) $dup['name'];
        CJS_Logger::log('Archived item restored', 'info', 'inventory', $dup_id, ['name' => $name]);
        try {
            CJS_Inv_Demands::reserve_pending([$dup_id]);
        } catch (Throwable $e) {
            error_log('CJS queue after restoring item ' . $dup_id . ' failed: ' . $e->getMessage());
        }
        self::$restored = ['id' => $dup_id, 'name' => $name, 'notice' => sprintf(__('Restored the archived item %s', 'custom-jewelry-system'), $name)];
        return $dup_id;
    }

    public static function strip_praba($name, $praba) {
        $name = trim((string) $name);
        if ($praba === null) {
            return $name;
        }
        $suffix = CJS_Inv_DB::fmt($praba);
        if (preg_match('/^(.+?)[\s·]+' . preg_quote($suffix, '/') . '$/u', $name, $m) && trim($m[1]) !== '') {
            return trim($m[1]);
        }
        return $name;
    }

    public static function items_rows(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) {
            return [];
        }
        return $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE id IN (' . CJS_Inv_DB::ids_in($ids) . ')', ARRAY_A) ?: [];
    }

    public static function find_or_create_item($category_id, array $values, $name = '', array $extra = []) {
        $norm = self::normalize_values($category_id, $values);
        $name_auto = self::build_name($category_id, $norm['values'], $name);
        $signature = self::signature($category_id, $norm['values'], $name !== '' && !$norm['values'] ? $name : $name_auto);
        $found = self::find_by_signature($category_id, $signature);
        if ($found) {
            return (int) $found['id'];
        }
        $payload = array_merge($extra, ['category_id' => $category_id, 'values' => $values]);
        if ($name !== '' && !$norm['values']) {
            $payload['name'] = $name;
            $payload['name_custom'] = 1;
        }
        return self::save_item($payload);
    }

    public static function rebuild_category_names($category_id) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND name_custom = 0', $category_id));
        $tracked = CJS_Inv_Metals::tracked((int) $category_id);
        foreach ($ids as $item_id) {
            $values = self::item_values((int) $item_id);
            if (!$values) {
                continue;
            }
            $name = self::build_name($category_id, $values, '');
            if ($name === '') {
                continue;
            }
            if ($tracked) {
                $item = self::item_row((int) $item_id);
                $old = $item ? CJS_Inv_Metals::item_metal($item) : '';
                $wpdb->update(CJS_Inv_DB::t('items'), ['name' => mb_substr(CJS_Inv_Metals::display($name, $item['praba'] ?? null), 0, 255), 'metal' => mb_substr(CJS_Inv_Metals::clean($name), 0, 191)], ['id' => (int) $item_id]);
                if ($old !== '' && CJS_Inv_Metals::key($old) !== CJS_Inv_Metals::key($name)) {
                    CJS_Inv_Metals::after_item_saved((int) $item_id, $old);
                }
            } else {
                $wpdb->update(CJS_Inv_DB::t('items'), ['name' => mb_substr($name, 0, 255)], ['id' => (int) $item_id]);
            }
        }
    }

    public static function set_archived($item_id, $archived) {
        CJS_Inv_DB::update('items', ['archived' => $archived ? 1 : 0, 'updated_at' => CJS_Inv_DB::now()], ['id' => absint($item_id)]);
        $item = self::item_row(absint($item_id));
        $metal = $item ? CJS_Inv_Metals::of_item($item) : null;
        if ($metal) {
            CJS_Inv_Metals::repoint($metal['category_id'], $metal['metal']);
        }
        return true;
    }

    public static function delete_item($item_id) {
        global $wpdb;
        $item_id = absint($item_id);
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d', $item_id))
            + (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('demands') . ' WHERE item_id = %d', $item_id))
            + (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('stock_order_lines') . ' WHERE item_id = %d', $item_id));
        if ($used > 0) {
            self::set_archived($item_id, true);
            return 'archived';
        }
        CJS_Inv_DB::transaction(function () use ($wpdb, $item_id) {
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE item_id = %d', $item_id));
            CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('items') . ' WHERE id = %d', $item_id));
        });
        return 'deleted';
    }

    public static function list_items(array $args = []) {
        global $wpdb;
        $i = CJS_Inv_DB::t('items');
        $where = ['1=1'];
        $params = [];
        if (!empty($args['category_id'])) {
            $where[] = 'i.category_id = %d';
            $params[] = absint($args['category_id']);
        }
        $archived = $args['archived'] ?? 'active';
        if ($archived === 'active') {
            $where[] = 'i.archived = 0';
        } elseif ($archived === 'archived') {
            $where[] = 'i.archived = 1';
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like(trim($args['search'])) . '%';
            $where[] = '(i.name LIKE %s OR i.code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($args['ids']) && is_array($args['ids'])) {
            $where[] = 'i.id IN (' . CJS_Inv_DB::ids_in($args['ids']) . ')';
        }
        if (!empty($args['filters']) && is_array($args['filters'])) {
            foreach (self::field_filter_where($args['filters']) as $w) {
                $where[] = $w;
            }
        }
        $stock = $args['stock'] ?? '';
        if ($stock === 'in') {
            $where[] = 'i.qty_on_hand - i.qty_reserved > 0';
        } elseif ($stock === 'out') {
            $where[] = 'i.qty_on_hand - i.qty_reserved <= 0';
        } elseif ($stock === 'low') {
            $where[] = 'i.reorder_level IS NOT NULL AND i.qty_on_hand - i.qty_reserved <= i.reorder_level';
        } elseif ($stock === 'missing') {
            $where[] = 'EXISTS (SELECT 1 FROM ' . CJS_Inv_DB::t('demands') . " d WHERE d.item_id = i.id AND d.state = 'open' AND d.qty_allocated < d.qty)";
        }
        $piece_states = [];
        foreach ((array) ($args['piece_state'] ?? []) as $ps) {
            if (isset(self::PIECE_STATES[$ps])) {
                $piece_states[] = "'" . self::PIECE_STATES[$ps] . "'";
            }
        }
        if ($piece_states) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . CJS_Inv_DB::t('pieces') . ' ps WHERE ps.item_id = i.id AND ps.status IN (' . implode(',', array_unique($piece_states)) . '))';
        }
        $where_sql = implode(' AND ', $where);
        $sort_map = [
            'name' => 'i.name',
            'code' => 'i.code',
            'on_hand' => 'i.qty_on_hand',
            'free' => '(i.qty_on_hand - i.qty_reserved)',
            'updated' => 'i.updated_at',
            'created' => 'i.id',
        ];
        $sort = $sort_map[$args['sort'] ?? 'name'] ?? 'i.name';
        $dir = strtoupper($args['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        if ($sort === 'i.name') {
            $sort = "CASE WHEN i.praba IS NOT NULL AND i.metal IS NOT NULL AND i.metal <> '' THEN i.metal ELSE i.name END $dir, i.praba DESC, i.name";
        }
        $per_page = min(500, max(1, absint($args['per_page'] ?? 50)));
        $page = max(1, absint($args['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM $i i WHERE $where_sql";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $d = CJS_Inv_DB::t('demands');
        $b = CJS_Inv_DB::t('batches');
        $sql = "SELECT i.*,
                (SELECT COALESCE(SUM(d.qty - d.qty_allocated), 0) FROM $d d WHERE d.item_id = i.id AND d.state = 'open' AND d.qty_allocated < d.qty) AS qty_missing,
                (SELECT COALESCE(SUM(b.qty_on_hand * b.unit_cost), 0) FROM $b b WHERE b.item_id = i.id AND b.unit_cost IS NOT NULL AND b.qty_on_hand > 0) AS stock_value
                FROM $i i WHERE $where_sql ORDER BY $sort $dir, i.id ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page, $offset])), ARRAY_A) ?: [];
        $items = array_map([__CLASS__, 'format_item_row'], $rows);
        $fine_ids = [];
        foreach ($items as $it) {
            if ($it['missing'] > 0 && CJS_Inv_Flow::rules($it['id'])['has_fineness']) {
                $fine_ids[] = $it['id'];
            }
        }
        $fine = $fine_ids ? CJS_Inv_Demands::missing_at_default_by_item($fine_ids) : [];
        foreach ($items as &$it) {
            $it['missing_at_default'] = in_array($it['id'], $fine_ids, true) ? ($fine[$it['id']] ?? 0.0) : $it['missing'];
        }
        unset($it);
        $per_piece_ids = array_map(function ($it) { return $it['id']; }, array_filter($items, function ($it) { return $it['per_piece']; }));
        if ($per_piece_ids) {
            $counts = [];
            foreach ($wpdb->get_results('SELECT item_id, status, COUNT(*) AS n FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id IN (' . CJS_Inv_DB::ids_in($per_piece_ids) . ') GROUP BY item_id, status', ARRAY_A) ?: [] as $c) {
                $counts[(int) $c['item_id']][$c['status']] = (int) $c['n'];
            }
            foreach ($items as &$it) {
                if ($it['per_piece']) {
                    $out = [];
                    foreach (self::PIECE_STATES as $key => $status) {
                        $out[$key] = $counts[$it['id']][$status] ?? 0;
                    }
                    $it['piece_counts'] = $out;
                }
            }
            unset($it);
        }
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    public static function format_item_row($row) {
        $on_hand = (float) $row['qty_on_hand'];
        $reserved = (float) $row['qty_reserved'];
        $free = $on_hand - $reserved;
        $reorder = $row['reorder_level'] !== null ? (float) $row['reorder_level'] : null;
        $missing = isset($row['qty_missing']) ? (float) $row['qty_missing'] : 0.0;
        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['category_id'],
            'name' => $row['name'],
            'name_custom' => (bool) $row['name_custom'],
            'code' => $row['code'],
            'unit_id' => (int) $row['unit_id'],
            'unit' => self::unit_label($row['unit_id']),
            'returnable' => (bool) $row['returnable'],
            'per_piece' => (bool) $row['per_piece'],
            'default_cost' => $row['default_cost'] !== null ? (float) $row['default_cost'] : null,
            'default_price' => $row['default_price'] !== null ? (float) $row['default_price'] : null,
            'reorder_level' => $reorder,
            'praba' => CJS_Inv_Flow::clean_praba($row['praba'] ?? null),
            'metal' => ($m = CJS_Inv_Metals::of_item($row)) ? $m['metal'] : null,
            'on_hand' => $on_hand,
            'reserved' => $reserved,
            'free' => $free,
            'missing' => $missing,
            'stock_value' => isset($row['stock_value']) ? (float) $row['stock_value'] : null,
            'low' => $reorder !== null && $free <= $reorder,
            'archived' => (bool) $row['archived'],
            'updated_at' => $row['updated_at'],
        ];
    }

    public static function item_summary($item_id) {
        $row = self::item_row($item_id);
        if (!$row) {
            return null;
        }
        $summary = self::format_item_row($row);
        $summary['values'] = self::item_values($item_id);
        return $summary;
    }

    public static function items_by_ids(array $ids) {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . CJS_Inv_DB::t('items') . ' WHERE id IN (' . CJS_Inv_DB::ids_in($ids) . ')', ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = self::format_item_row($r);
        }
        return $out;
    }

    public static function group_metal_items(array $items) {
        $groups = [];
        $order = [];
        foreach ($items as $it) {
            $k = $it['metal'] !== null ? 'm' . $it['category_id'] . '|' . CJS_Inv_Metals::key($it['metal']) : 'i' . $it['id'];
            if (!isset($groups[$k])) {
                $groups[$k] = [];
                $order[] = $k;
            }
            $groups[$k][] = $it;
        }
        $out = [];
        foreach ($order as $k) {
            $list = $groups[$k];
            if (count($list) > 1) {
                usort($list, function ($a, $b) {
                    $pa = $a['praba'] ?? -1;
                    $pb = $b['praba'] ?? -1;
                    return $pa === $pb ? $a['id'] <=> $b['id'] : $pb <=> $pa;
                });
            }
            foreach ($list as $it) {
                $out[] = $it;
            }
        }
        return $out;
    }

    public static function next_identifier($item_id, $offset = 0) {
        global $wpdb;
        $item = self::item_row($item_id);
        if (!$item || $item['code'] === null || $item['code'] === '') {
            return null;
        }
        $prefix = $item['code'] . '-';
        $rows = $wpdb->get_col($wpdb->prepare('SELECT identifier FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id = %d AND identifier LIKE %s', $item_id, $wpdb->esc_like($prefix) . '%'));
        $max = 0;
        foreach ($rows as $identifier) {
            $rest = substr($identifier, strlen($prefix));
            if (ctype_digit($rest)) {
                $max = max($max, (int) $rest);
            }
        }
        return self::format_identifier($item['code'], $max + 1 + $offset);
    }

    public static function format_identifier($code, $number) {
        return $code . '-' . str_pad((string) (int) $number, 2, '0', STR_PAD_LEFT);
    }

    private static function field_filter_where(array $filters) {
        global $wpdb;
        $out = [];
        $n = 0;
        foreach ($filters as $field_id => $filter) {
            $field_id = absint($field_id);
            if (!$field_id || !is_array($filter)) {
                continue;
            }
            $alias = 'fv' . (++$n);
            if (isset($filter['ranges']) && is_array($filter['ranges']) && $filter['ranges']) {
                $ors = [];
                foreach (array_slice($filter['ranges'], 0, 30) as $rg) {
                    if (!is_array($rg) || !isset($rg['min'], $rg['max']) || !is_numeric($rg['min']) || !is_numeric($rg['max'])) {
                        continue;
                    }
                    $c = $wpdb->prepare("($alias.value_number >= %f AND $alias.value_number <= %f", (float) $rg['min'], (float) $rg['max']);
                    if (!empty($rg['unit_id'])) {
                        $c .= ' AND ' . $alias . '.unit_id = ' . absint($rg['unit_id']);
                    }
                    $ors[] = $c . ')';
                }
                if ($ors) {
                    $out[] = "EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('item_values') . " $alias WHERE $alias.item_id = i.id AND $alias.field_id = $field_id AND (" . implode(' OR ', $ors) . '))';
                }
                continue;
            }
            if (isset($filter['choice_ids']) && is_array($filter['choice_ids']) && $filter['choice_ids']) {
                $out[] = "EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('item_values') . " $alias WHERE $alias.item_id = i.id AND $alias.field_id = $field_id AND $alias.choice_id IN (" . CJS_Inv_DB::ids_in($filter['choice_ids']) . "))";
            } elseif (isset($filter['min']) || isset($filter['max'])) {
                $cond = "$alias.item_id = i.id AND $alias.field_id = $field_id";
                if (isset($filter['unit_id']) && $filter['unit_id']) {
                    $cond .= ' AND ' . $alias . '.unit_id = ' . absint($filter['unit_id']);
                }
                if (isset($filter['min']) && $filter['min'] !== '') {
                    $cond .= $wpdb->prepare(" AND $alias.value_number >= %f", (float) $filter['min']);
                }
                if (isset($filter['max']) && $filter['max'] !== '') {
                    $cond .= $wpdb->prepare(" AND $alias.value_number <= %f", (float) $filter['max']);
                }
                $out[] = "EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('item_values') . " $alias WHERE $cond)";
            } elseif (isset($filter['text']) && $filter['text'] !== '') {
                $out[] = $wpdb->prepare("EXISTS (SELECT 1 FROM " . CJS_Inv_DB::t('item_values') . " $alias WHERE $alias.item_id = i.id AND $alias.field_id = $field_id AND $alias.value_text LIKE %s)", '%' . $wpdb->esc_like($filter['text']) . '%');
            }
        }
        return $out;
    }

    public static function number_facets($category_id) {
        global $wpdb;
        $category_id = absint($category_id);
        if (!$category_id) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT v.field_id, v.unit_id, ROUND(v.value_number, 2) AS val, COUNT(DISTINCT v.item_id) AS n
             FROM ' . CJS_Inv_DB::t('item_values') . ' v
             INNER JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = v.item_id AND i.archived = 0
             INNER JOIN ' . CJS_Inv_DB::t('fields') . " f ON f.id = v.field_id AND f.type = 'number'
             WHERE f.category_id = %d AND i.category_id = %d AND v.value_number IS NOT NULL
             GROUP BY v.field_id, v.unit_id, val
             ORDER BY v.field_id, v.unit_id, val",
            $category_id,
            $category_id
        ), ARRAY_A) ?: [];
        $groups = [];
        foreach ($rows as $r) {
            $groups[(int) $r['field_id']][(int) $r['unit_id']][] = ['v' => (float) $r['val'], 'n' => (int) $r['n']];
        }
        $out = [];
        foreach ($groups as $field_id => $units) {
            $labels = [];
            foreach (array_keys($units) as $unit_id) {
                $labels[$unit_id] = self::unit_label($unit_id);
            }
            uksort($units, function ($a, $b) use ($labels) {
                return strcmp((string) $labels[$a], (string) $labels[$b]);
            });
            $chips = [];
            foreach ($units as $unit_id => $vals) {
                foreach (self::size_chips($vals) as $c) {
                    $fmt = function ($x) {
                        return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
                    };
                    $text = $c['lo'] === $c['hi'] ? $fmt($c['lo']) : $fmt($c['lo']) . ' - ' . $fmt($c['hi']);
                    $chips[] = [
                        'key' => $unit_id . ':' . $fmt($c['lo']) . ':' . $fmt($c['hi']),
                        'label' => trim($text . ' ' . $labels[$unit_id]),
                        'unit_id' => (int) $unit_id,
                        'min' => round($c['lo'] - 0.005, 4),
                        'max' => round($c['hi'] + 0.0049, 4),
                        'items' => $c['n'],
                    ];
                }
            }
            if (count($chips) > 1) {
                $out[(string) $field_id] = $chips;
            }
        }
        return $out;
    }

    public static function size_chips(array $vals, $cap = 10, $gain = 0.1) {
        $m = count($vals);
        if ($m === 0) {
            return [];
        }
        $pre = [0];
        foreach ($vals as $v) {
            $pre[] = $pre[count($pre) - 1] + $v['n'];
        }
        $max_k = min($cap, $m);
        $inf = PHP_FLOAT_MAX;
        $dp = [array_fill(0, $m + 1, $inf)];
        $dp[0][0] = 0.0;
        $cut = [];
        for ($b = 1; $b <= $max_k; $b++) {
            $dp[$b] = array_fill(0, $m + 1, $inf);
            $cut[$b] = array_fill(0, $m + 1, 0);
            for ($j = $b; $j <= $m; $j++) {
                for ($i = $b - 1; $i < $j; $i++) {
                    if ($dp[$b - 1][$i] >= $inf) {
                        continue;
                    }
                    $s = $pre[$j] - $pre[$i];
                    $c = $dp[$b - 1][$i] + $s * $s;
                    if ($c < $dp[$b][$j]) {
                        $dp[$b][$j] = $c;
                        $cut[$b][$j] = $i;
                    }
                }
            }
        }
        $best = $dp[$max_k][$m];
        $k = $max_k;
        for ($x = 1; $x <= $max_k; $x++) {
            if ($dp[$x][$m] <= $best * (1 + $gain)) {
                $k = $x;
                break;
            }
        }
        $parts = [];
        $j = $m;
        for ($b = $k; $b >= 1; $b--) {
            $i = $cut[$b][$j];
            array_unshift($parts, ['lo' => $vals[$i]['v'], 'hi' => $vals[$j - 1]['v'], 'n' => $pre[$j] - $pre[$i]]);
            $j = $i;
        }
        return $parts;
    }

    public static function suggestions(array $args = []) {
        global $wpdb;
        $i = CJS_Inv_DB::t('items');
        $c = CJS_Inv_DB::t('categories');
        $v = CJS_Inv_DB::t('item_values');
        $ch = CJS_Inv_DB::t('field_choices');
        $d = CJS_Inv_DB::t('demands');
        $l = CJS_Inv_DB::t('stock_order_lines');
        $search = trim((string) ($args['search'] ?? ''));
        $category_id = absint($args['category_id'] ?? 0);
        $per_category = absint($args['per_category'] ?? 0) ?: ($category_id ? 40 : 6);
        $per_category = min(100, max(1, $per_category));
        $limit = min(100, max(1, absint($args['limit'] ?? 0) ?: 40));
        $since = wp_date('Y-m-d H:i:s', time() - YEAR_IN_SECONDS);
        $browse = $category_id > 0;
        $offset = $browse ? max(0, absint($args['offset'] ?? 0)) : 0;

        $where = ['i.archived = 0', 'c.archived = 0'];
        $params = [];
        if ($category_id) {
            $where[] = 'i.category_id = %d';
            $params[] = $category_id;
        }
        foreach (['per_piece', 'returnable'] as $flag) {
            $raw = $args[$flag] ?? '';
            if ($raw === '' || $raw === null) {
                continue;
            }
            $where[] = 'i.' . $flag . ' = ' . (filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if (!empty($args['in_stock'])) {
            $where[] = 'i.qty_on_hand - i.qty_reserved > 0';
        }
        if ($browse && !empty($args['filters']) && is_array($args['filters'])) {
            foreach (self::field_filter_where($args['filters']) as $w) {
                $where[] = $w;
            }
        }
        $tokens = $search !== '' ? array_slice(array_values(array_filter(preg_split('/\s+/u', $search), 'strlen')), 0, 6) : [];
        foreach ($tokens as $tok) {
            $like = '%' . $wpdb->esc_like($tok) . '%';
            $num_like = '%' . $wpdb->esc_like(str_replace(',', '.', $tok)) . '%';
            $where[] = "(i.name LIKE %s OR i.code LIKE %s OR c.name LIKE %s OR EXISTS (SELECT 1 FROM $v v LEFT JOIN $ch ch ON ch.id = v.choice_id WHERE v.item_id = i.id AND (ch.label LIKE %s OR v.value_text LIKE %s OR CAST(v.value_number AS CHAR) LIKE %s)))";
            array_push($params, $like, $like, $like, $like, $like, $num_like);
        }
        $where_sql = implode(' AND ', $where);
        $usage_sql = "SELECT u.item_id, SUM(u.n) AS n FROM (
                SELECT item_id, COUNT(*) AS n FROM $d WHERE state <> 'cancelled' AND created_at >= %s GROUP BY item_id
                UNION ALL
                SELECT item_id, COUNT(*) AS n FROM $l WHERE created_at >= %s GROUP BY item_id
            ) u GROUP BY u.item_id";
        $max_rows = $browse ? $per_category : ($tokens ? $limit : 2000);
        $cap = $browse ? $per_category : ($tokens ? $limit : $per_category);
        $sql = "SELECT i.*, c.name AS category_name, c.sort_order AS category_sort, COALESCE(us.n, 0) AS usage_count
                FROM $i i
                INNER JOIN $c c ON c.id = i.category_id
                LEFT JOIN ($usage_sql) us ON us.item_id = i.id
                WHERE $where_sql
                ORDER BY usage_count DESC, (i.qty_on_hand - i.qty_reserved) DESC, i.name ASC, i.id ASC
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$since, $since], $params, [$max_rows, $offset])), ARRAY_A) ?: [];

        $count_sql = "SELECT i.category_id, COUNT(*) AS n FROM $i i INNER JOIN $c c ON c.id = i.category_id WHERE $where_sql GROUP BY i.category_id";
        $counts = $wpdb->get_results($params ? $wpdb->prepare($count_sql, $params) : $count_sql, ARRAY_A) ?: [];
        $count_map = [];
        $total = 0;
        foreach ($counts as $row) {
            $count_map[(int) $row['category_id']] = (int) $row['n'];
            $total += (int) $row['n'];
        }

        $sections = [];
        foreach ($rows as $row) {
            $cid = (int) $row['category_id'];
            if (!isset($sections[$cid])) {
                $sections[$cid] = [
                    'category_id' => $cid,
                    'name' => $row['category_name'],
                    'sort' => (int) $row['category_sort'],
                    'usage' => 0,
                    'total' => $count_map[$cid] ?? 0,
                    'items' => [],
                ];
            }
            $usage = (int) $row['usage_count'];
            $sections[$cid]['usage'] += $usage;
            if (count($sections[$cid]['items']) < $cap) {
                $item = self::format_item_row($row);
                $item['usage'] = $usage;
                $item['category'] = $row['category_name'];
                $sections[$cid]['items'][] = $item;
            }
        }
        $sections = array_values($sections);
        usort($sections, function ($a, $b) {
            if ($a['usage'] !== $b['usage']) {
                return $b['usage'] <=> $a['usage'];
            }
            if ($a['sort'] !== $b['sort']) {
                return $a['sort'] <=> $b['sort'];
            }
            return $a['category_id'] <=> $b['category_id'];
        });
        $metal_free = [];
        foreach ($sections as &$section) {
            unset($section['sort']);
            $section['items'] = self::group_metal_items($section['items']);
            foreach ($section['items'] as &$it) {
                if ($it['metal'] === null) {
                    continue;
                }
                $mk = $it['category_id'] . '|' . CJS_Inv_Metals::key($it['metal']) . '|' . $it['unit_id'];
                if (!isset($metal_free[$mk])) {
                    $metal_free[$mk] = CJS_Inv_Metals::free_at_ref($it['category_id'], $it['metal'], $it['unit_id']);
                }
                $it['metal_free'] = $metal_free[$mk];
            }
            unset($it);
        }
        unset($section);
        return [
            'search' => $search,
            'total' => $total,
            'offset' => $offset,
            'has_more' => $browse && ($offset + count($rows)) < $total,
            'sections' => $sections,
        ];
    }
}
