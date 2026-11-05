<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Seed {

    const OPTION = 'cjs_inv_seeded';
    const VERSION_OPTION = 'cjs_inv_seed_version';
    const VERSION = 6;
    const STONE_LABELS = [
        'type' => ['Type', 'Akmens Tipas'],
        'origin' => ['Origin', 'Akmens Kilmė'],
        'shape' => ['Shape', 'Akmens Forma'],
        'size' => ['Size', 'Akmens Dydis'],
        'color' => ['Color', 'Akmens Spalva'],
        'clarity' => ['Clarity', 'Akmens Skaidrumas'],
        'cut-grade' => ['Cut grade', 'Akmens Pjūvio Klasė'],
        'origin-country' => ['Origin country', 'Kilmės Šalis'],
    ];

    const SHAPE_LABELS = [
        'squareradiant' => 'Square radiant',
        'squareemerald' => 'Square emerald',
        'oldminer' => 'Old miner',
        'halfmoon' => 'Half moon',
        'taperedbaguette' => 'Tapered baguette',
        'europeancut' => 'European cut',
        'taperedbullet' => 'Tapered bullet',
    ];

    public static function run() {
        self::units();
        if (get_option(self::OPTION) === 'yes') {
            self::upgrade();
            return;
        }
        CJS_Inv_DB::transaction(function () {
            self::stones();
            self::metals();
            self::packaging();
            self::sizing_kits();
        });
        update_option(self::OPTION, 'yes', false);
        self::upgrade();
    }

    public static function upgrade() {
        $version = (int) get_option(self::VERSION_OPTION, 1);
        if ($version >= self::VERSION) {
            return;
        }
        $cat = CJS_Inv_Catalog::category_by_slug('stones');
        if ($cat && $version < 2) {
            CJS_Inv_DB::transaction(function () use ($cat) {
                self::stone_grade_fields((int) $cat['id']);
            });
        }
        if ($version < 3) {
            CJS_Inv_DB::transaction(function () {
                self::category_settings();
                self::drop_metal_color();
            });
        }
        if ($version < 4) {
            CJS_Inv_DB::transaction(function () {
                self::drop_metal_purity();
            });
        }
        if ($version < 5) {
            try {
                CJS_Inv_DB::transaction(function () {
                    self::drop_metal_field();
                });
            } catch (Throwable $e) {
                error_log('CJS: removing the Metal field failed: ' . $e->getMessage());
                return;
            }
        }
        if ($cat && $version < 6) {
            CJS_Inv_DB::transaction(function () use ($cat) {
                self::stone_piece_fields((int) $cat['id']);
            });
        }
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function drop_metal_field() {
        global $wpdb;
        $cat = CJS_Inv_Catalog::category_by_slug('metals');
        if (!$cat) {
            return 0;
        }
        $cat_id = (int) $cat['id'];
        $field_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat_id, 'metal'));
        if (!$field_id) {
            return 0;
        }
        $tracked = (int) ($cat['has_fineness'] ?? 0) === 1;
        $item_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT DISTINCT item_id FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $field_id)) ?: []);
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('fields') . ' WHERE id = %d', $field_id));
        $kept = [];
        foreach ($item_ids as $item_id) {
            $item = CJS_Inv_Catalog::item_row($item_id);
            if (!$item) {
                continue;
            }
            $values = CJS_Inv_Catalog::item_values($item_id);
            $row = ['name_custom' => 1, 'updated_at' => CJS_Inv_DB::now()];
            if ($tracked) {
                $metal = CJS_Inv_Metals::item_metal($item);
                $row['metal'] = mb_substr($metal, 0, 191);
                $signature = CJS_Inv_Metals::signature($cat_id, $metal, $item['praba'] ?? null);
            } else {
                $signature = CJS_Inv_Catalog::signature($cat_id, $values, (string) $item['name']);
            }
            $dup = CJS_Inv_Catalog::find_by_signature($cat_id, $signature);
            if ($dup && (int) $dup['id'] !== $item_id) {
                $signature = sha1($signature . '|' . $item_id);
                $kept[] = sprintf('item %d (%s) has the same name and praba as item %d; both were kept', $item_id, (string) $item['name'], (int) $dup['id']);
            }
            $row['signature'] = $signature;
            CJS_Inv_DB::update('items', $row, ['id' => $item_id]);
        }
        if ($kept) {
            CJS_Logger::log('Metal field removed: items with the same name and praba', 'info', 'settings', null, ['items' => $kept]);
        }
        CJS_Inv_Catalog::flush_cache();
        return count($item_ids);
    }

    public static function drop_metal_purity() {
        global $wpdb;
        $cat = CJS_Inv_Catalog::category_by_slug('metals');
        if (!$cat) {
            return 0;
        }
        $cat_id = (int) $cat['id'];
        $field_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat_id, 'purity'));
        if (!$field_id) {
            return 0;
        }
        $tracked = (int) ($cat['has_fineness'] ?? 0) === 1;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT v.item_id, v.value_number, c.label FROM ' . CJS_Inv_DB::t('item_values') . ' v LEFT JOIN ' . CJS_Inv_DB::t('field_choices') . ' c ON c.id = v.choice_id WHERE v.field_id = %d', $field_id), ARRAY_A) ?: [];
        $purity = [];
        foreach ($rows as $r) {
            $purity[(int) $r['item_id']] = CJS_Inv_Flow::clean_praba($r['label'] ?? $r['value_number']);
        }
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('fields') . ' WHERE id = %d', $field_id));
        foreach ($purity as $item_id => $p) {
            $item = CJS_Inv_Catalog::item_row($item_id);
            if (!$item) {
                continue;
            }
            $praba = CJS_Inv_Flow::clean_praba($item['praba'] ?? null);
            $has_stock = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d', $item_id)) > 0;
            if ($tracked && $praba === null && $p !== null && !$has_stock) {
                $praba = $p;
            }
            $values = CJS_Inv_Catalog::item_values($item_id);
            $base = (int) $item['name_custom'] === 1 ? CJS_Inv_Catalog::strip_praba(CJS_Inv_Metals::item_metal($item), $praba) : CJS_Inv_Catalog::build_name($cat_id, $values, CJS_Inv_Metals::item_metal($item));
            $signature = CJS_Inv_Catalog::signature($cat_id, $values, $base);
            $row = ['name' => mb_substr($base, 0, 255), 'updated_at' => CJS_Inv_DB::now()];
            if ($tracked) {
                $row['metal'] = mb_substr(CJS_Inv_Metals::clean($base), 0, 191);
                $row['praba'] = $praba;
                if ($praba !== null) {
                    $row['name'] = mb_substr(CJS_Inv_Metals::display($base, $praba), 0, 255);
                    $signature = sha1($signature . '|praba=' . CJS_Inv_DB::fmt($praba));
                }
            }
            $dup = CJS_Inv_Catalog::find_by_signature($cat_id, $signature);
            if ($dup && (int) $dup['id'] !== $item_id) {
                $signature = sha1($signature . '|' . $item_id);
            }
            $row['signature'] = $signature;
            CJS_Inv_DB::update('items', $row, ['id' => $item_id]);
        }
        return count($purity);
    }

    public static function category_settings() {
        $map = [
            'metals' => ['auto_assign' => 1, 'returnable' => 0, 'used_by_status' => 'iduota_lieti', 'has_fineness' => 1],
            'stones' => ['auto_assign' => 1, 'returnable' => 0, 'used_by_status' => 'pagaminta', 'has_fineness' => 0],
            'packaging' => ['auto_assign' => 1, 'returnable' => 0, 'used_by_status' => 'done', 'has_fineness' => 0],
            'sizing-kits' => ['auto_assign' => 0, 'returnable' => 1, 'back_by_status' => 'done', 'has_fineness' => 0],
        ];
        foreach ($map as $slug => $row) {
            $cat = CJS_Inv_Catalog::category_by_slug($slug);
            if ($cat) {
                CJS_Inv_DB::update('categories', $row + ['updated_at' => CJS_Inv_DB::now()], ['id' => (int) $cat['id']]);
            }
        }
        CJS_Inv_Flow::flush();
    }

    public static function drop_metal_color() {
        global $wpdb;
        $cat = CJS_Inv_Catalog::category_by_slug('metals');
        if (!$cat) {
            return 0;
        }
        $cat_id = (int) $cat['id'];
        $field_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat_id, 'color'));
        if (!$field_id) {
            return 0;
        }
        $item_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT DISTINCT item_id FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $field_id)) ?: []);
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('item_values') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_choices') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('field_units') . ' WHERE field_id = %d', $field_id));
        CJS_Inv_DB::query($wpdb->prepare('DELETE FROM ' . CJS_Inv_DB::t('fields') . ' WHERE id = %d', $field_id));
        foreach ($item_ids as $item_id) {
            $item = CJS_Inv_Catalog::item_row($item_id);
            if (!$item) {
                continue;
            }
            $values = CJS_Inv_Catalog::item_values($item_id);
            $name = (int) $item['name_custom'] === 1 ? (string) $item['name'] : CJS_Inv_Catalog::build_name($cat_id, $values, (string) $item['name']);
            $signature = CJS_Inv_Catalog::signature($cat_id, $values, $name);
            $dup = CJS_Inv_Catalog::find_by_signature($cat_id, $signature);
            if ($dup && (int) $dup['id'] !== $item_id) {
                $signature = sha1($signature . '|' . $item_id);
            }
            CJS_Inv_DB::update('items', ['name' => mb_substr($name, 0, 255), 'signature' => $signature, 'updated_at' => CJS_Inv_DB::now()], ['id' => $item_id]);
        }
        return count($item_ids);
    }

    private static function stone_grade_fields($cat) {
        global $wpdb;
        $place_after = function ($field_id, $after_slug) use ($wpdb, $cat) {
            $after = $wpdb->get_var($wpdb->prepare('SELECT sort_order FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat, $after_slug));
            if ($after === null) {
                return;
            }
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('fields') . ' SET sort_order = sort_order + 1 WHERE category_id = %d AND sort_order > %d AND id <> %d', $cat, (int) $after, $field_id));
            CJS_Inv_DB::update('fields', ['sort_order' => (int) $after + 1], ['id' => $field_id]);
        };
        $exists = function ($slug) use ($wpdb, $cat) {
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat, $slug));
        };
        if (!$exists('color-grade')) {
            $id = self::field($cat, 'color-grade', 'Spalvos klasė', 'select', ['choices' => ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K']]);
            $place_after($id, 'color');
        }
        if (!$exists('fluorescence')) {
            $id = self::field($cat, 'fluorescence', 'Fluorescencija', 'select', ['choices' => ['Faint', 'Medium', 'Strong', 'Very strong']]);
            $place_after($id, 'cut-grade');
        }
        foreach (self::STONE_LABELS as $slug => $names) {
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('fields') . ' SET name = %s WHERE category_id = %d AND slug = %s AND name = %s', $names[1], $cat, $slug, $names[0]));
        }
        CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('fields') . " SET required = 1 WHERE category_id = %d AND slug = 'origin'", $cat));
    }

    private static function stone_piece_fields($cat) {
        global $wpdb;
        $id_of = function ($slug) use ($wpdb, $cat) {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $cat, $slug));
        };
        if (!$id_of('width')) {
            $id = self::field($cat, 'width', 'Plotis', 'number', ['units' => ['mm'], 'is_size' => 1]);
            $after = $wpdb->get_var($wpdb->prepare('SELECT sort_order FROM ' . CJS_Inv_DB::t('fields') . " WHERE category_id = %d AND slug = 'size'", $cat));
            if ($after !== null) {
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('fields') . ' SET sort_order = sort_order + 1 WHERE category_id = %d AND sort_order > %d AND id <> %d', $cat, (int) $after, $id));
                CJS_Inv_DB::update('fields', ['sort_order' => (int) $after + 1], ['id' => $id]);
            }
        }
        if (!$id_of('certified')) {
            self::field($cat, 'certified', 'Sertifikatas', 'select', ['choices' => ['Sertifikuotas']]);
        }
    }

    public static function units() {
        global $wpdb;
        $units = [
            ['pcs', 'Pieces', 0],
            ['g', 'Grams', 2],
            ['ct', 'Carats', 3],
            ['mm', 'Millimeters', 2],
            ['cm', 'Centimeters', 1],
        ];
        $pos = 1;
        foreach ($units as $u) {
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('units') . ' WHERE label = %s', $u[0]));
            if (!$exists) {
                $wpdb->insert(CJS_Inv_DB::t('units'), ['label' => $u[0], 'name' => $u[1], 'decimals' => $u[2], 'sort_order' => $pos]);
            }
            $pos++;
        }
        CJS_Inv_Catalog::flush_cache();
    }

    private static function category($slug, $name, $unit, $returnable = 0, $per_piece = 0) {
        $existing = CJS_Inv_Catalog::category_by_slug($slug);
        if ($existing) {
            return (int) $existing['id'];
        }
        return CJS_Inv_Catalog::save_category([
            'slug' => $slug,
            'name' => $name,
            'unit_id' => CJS_Inv_Catalog::unit_id_by_label($unit),
            'returnable' => $returnable,
            'per_piece' => $per_piece,
        ]);
    }

    private static function field($category_id, $slug, $name, $type, array $opts = []) {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d AND slug = %s', $category_id, $slug));
        if ($existing) {
            $field_id = (int) $existing;
        } else {
            $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ' . CJS_Inv_DB::t('fields') . ' WHERE category_id = %d', $category_id));
            $field_id = CJS_Inv_DB::insert('fields', [
                'category_id' => $category_id,
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
                'in_name' => isset($opts['in_name']) ? (int) $opts['in_name'] : 1,
                'is_size' => isset($opts['is_size']) ? (int) $opts['is_size'] : 0,
                'required' => 0,
                'sort_order' => $sort,
                'created_at' => CJS_Inv_DB::now(),
                'updated_at' => CJS_Inv_DB::now(),
            ]);
        }
        if (!empty($opts['choices'])) {
            foreach ($opts['choices'] as $label) {
                CJS_Inv_Catalog::add_choice($field_id, $label);
            }
        }
        if (!empty($opts['units'])) {
            $unit_ids = array_map([CJS_Inv_Catalog::class, 'unit_id_by_label'], $opts['units']);
            CJS_Inv_Catalog::set_field_units($field_id, $unit_ids);
        }
        return $field_id;
    }

    private static function option_keys($option, array $fallback, $key_value = true) {
        $value = get_option($option, null);
        if (!is_array($value) || !$value) {
            return $fallback;
        }
        $is_assoc = array_keys($value) !== range(0, count($value) - 1);
        if ($key_value && $is_assoc) {
            return array_map('strval', array_keys($value));
        }
        return array_map('strval', array_values($value));
    }

    public static function shape_label($value) {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
        if (isset(self::SHAPE_LABELS[$key])) {
            return self::SHAPE_LABELS[$key];
        }
        return ucfirst(trim((string) $value));
    }

    private static function stones() {
        $cat = self::category('stones', 'Stones', 'pcs');
        self::field($cat, 'type', 'Type', 'select', ['choices' => self::option_keys('cjs_stone_types', ['Diamond', 'Sapphire', 'Emerald', 'Ruby', 'Moissanite'])]);
        self::field($cat, 'origin', 'Origin', 'select', ['choices' => self::option_keys('cjs_stone_origins', ['Natural', 'Lab Grown'])]);
        $shapes = array_map([__CLASS__, 'shape_label'], self::option_keys('cjs_stone_shapes', ['round', 'princess', 'cushion', 'oval', 'pear', 'emerald', 'heart', 'radiant', 'asscher', 'marquise'], false));
        self::field($cat, 'shape', 'Shape', 'select', ['choices' => $shapes]);
        self::field($cat, 'size', 'Size', 'number', ['units' => ['ct', 'mm'], 'is_size' => 1]);
        self::field($cat, 'color', 'Color', 'select', ['choices' => self::option_keys('cjs_stone_colors', ['White', 'Red', 'Green', 'Blue'])]);
        self::field($cat, 'clarity', 'Clarity', 'select', ['choices' => self::option_keys('cjs_stone_clarities', ['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'I1', 'I2', 'I3'], false)]);
        self::field($cat, 'cut-grade', 'Cut grade', 'select', ['choices' => self::option_keys('cjs_stone_cut_grades', ['Excellent', 'Very Good', 'Good', 'Fair', 'Poor'], false)]);
        self::field($cat, 'origin-country', 'Origin country', 'select', ['in_name' => 1, 'choices' => self::option_keys('cjs_origin_countries', ['Sri Lanka', 'India', 'Myanmar', 'Thailand', 'Madagascar', 'Tanzania'], false)]);
    }

    private static function metals() {
        self::category('metals', 'Metals', 'g');
    }

    private static function packaging() {
        $cat = self::category('packaging', 'Packaging', 'pcs');
        self::field($cat, 'type', 'Type', 'select', ['choices' => ['Packaging box', 'Courier envelope', 'Engagement ring box', 'Wedding ring box', 'Pendant / earring / necklace box', 'Bracelet box', 'Watch box']]);
        self::field($cat, 'variant', 'Variant', 'text');
        self::field($cat, 'size', 'Size', 'select', ['choices' => ['S', 'M', 'L'], 'is_size' => 1]);
    }

    private static function sizing_kits() {
        self::category('sizing-kits', 'Sizing kits', 'pcs', 1, 1);
    }
}
