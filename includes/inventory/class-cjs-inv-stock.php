<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Inv_Stock {

    const ON_HAND_TYPES = ['receipt', 'opening', 'count', 'use', 'out', 'return'];
    const LIVE_ALLOCATION_STATES = ['reserved', 'used', 'out'];

    public static function log(string $type, int $item_id, float $qty, array $ctx = []) {
        $item = CJS_Inv_Catalog::item_row($item_id);
        $mark = [];
        if (!empty($ctx['link_mark']) && CJS_Inv_DB::has_column('movements', 'link_mark')) {
            $mark['link_mark'] = substr(sanitize_key((string) $ctx['link_mark']), 0, 20);
        }
        return CJS_Inv_DB::insert('movements', $mark + [
            'type' => $type,
            'item_id' => $item_id,
            'batch_id' => $ctx['batch_id'] ?? null,
            'piece_id' => $ctx['piece_id'] ?? null,
            'qty' => round($qty, 4),
            'unit_id' => $item ? (int) $item['unit_id'] : 0,
            'unit_cost' => $ctx['unit_cost'] ?? null,
            'order_id' => $ctx['order_id'] ?? null,
            'demand_id' => $ctx['demand_id'] ?? null,
            'allocation_id' => $ctx['allocation_id'] ?? null,
            'document_id' => $ctx['document_id'] ?? null,
            'stock_order_id' => $ctx['stock_order_id'] ?? null,
            'note' => isset($ctx['note']) ? mb_substr((string) $ctx['note'], 0, 255) : null,
            'user_id' => $ctx['user_id'] ?? get_current_user_id(),
            'created_at' => $ctx['created_at'] ?? CJS_Inv_DB::now(),
        ]);
    }

    public static function bump(int $batch_id, float $d_on_hand, float $d_reserved) {
        global $wpdb;
        $batch = self::batch($batch_id);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batch_id);
        }
        if ($d_on_hand == 0 && $d_reserved == 0) {
            return;
        }
        CJS_Inv_DB::query($wpdb->prepare(
            'UPDATE ' . CJS_Inv_DB::t('batches') . ' SET qty_on_hand = qty_on_hand + %f, qty_reserved = qty_reserved + %f, updated_at = %s WHERE id = %d',
            $d_on_hand, $d_reserved, CJS_Inv_DB::now(), $batch_id
        ));
        CJS_Inv_DB::query($wpdb->prepare(
            'UPDATE ' . CJS_Inv_DB::t('items') . ' SET qty_on_hand = qty_on_hand + %f, qty_reserved = qty_reserved + %f WHERE id = %d',
            $d_on_hand, $d_reserved, (int) $batch['item_id']
        ));
    }

    public static function batch($batch_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('batches') . ' WHERE id = %d', $batch_id), ARRAY_A);
    }

    public static function piece($piece_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE id = %d', $piece_id), ARRAY_A);
    }

    public static function allocation($allocation_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . ' WHERE id = %d', $allocation_id), ARRAY_A);
    }
    public static function create_document($type, $note = '', $created_at = null) {
        return CJS_Inv_DB::insert('documents', [
            'type' => $type,
            'note' => $note !== '' ? sanitize_textarea_field($note) : null,
            'user_id' => get_current_user_id(),
            'created_at' => $created_at ?: CJS_Inv_DB::now(),
        ]);
    }

    public static function receive(array $lines, array $opts = []) {
        $result = CJS_Inv_DB::transaction(function () use ($lines, $opts) {
            $document_id = $opts['document_id'] ?? self::create_document($opts['document_type'] ?? 'receipt', $opts['note'] ?? '', $opts['received_at'] ?? null);
            $batches = [];
            foreach ($lines as $line) {
                $batch_id = self::create_batch($line, $document_id, $opts);
                if (is_wp_error($batch_id)) {
                    throw new CJS_Inv_Exception($batch_id);
                }
                $batches[] = $batch_id;
            }
            return ['document_id' => $document_id, 'batch_ids' => $batches];
        });
        if (empty($opts['skip_reserve'])) {
            $item_ids = array_map(function ($l) { return absint($l['item_id'] ?? 0); }, $lines);
            CJS_Inv_Demands::reserve_pending($item_ids, $opts['prefer_po_line_ids'] ?? []);
        }
        return $result;
    }

    public static function create_batch(array $line, $document_id, array $opts = []) {
        $item_id = absint($line['item_id'] ?? 0);
        $item = CJS_Inv_Catalog::item_row($item_id);
        if (!$item) {
            return new WP_Error('cjs_invalid', __('Item not found.', 'custom-jewelry-system'));
        }
        $qty = CJS_Inv_DB::num($line['qty'] ?? 0);
        if ($qty === null || $qty <= 0) {
            return new WP_Error('cjs_invalid', sprintf(__('Quantity for %s must be greater than zero.', 'custom-jewelry-system'), $item['name']));
        }
        $per_piece = (int) $item['per_piece'] === 1;
        if ($per_piece && floor($qty) != $qty) {
            return new WP_Error('cjs_invalid', sprintf(__('%s is tracked per piece, so the quantity must be a whole number.', 'custom-jewelry-system'), $item['name']));
        }
        $unit_cost = array_key_exists('unit_cost', $line) ? CJS_Inv_DB::num($line['unit_cost']) : null;
        if ($unit_cost !== null && $unit_cost < 0) {
            return new WP_Error('cjs_invalid', sprintf(__('Unit cost for %s cannot be negative.', 'custom-jewelry-system'), $item['name']));
        }
        if ($unit_cost === null && empty($line['no_default_cost']) && $item['default_cost'] !== null && $item['default_cost'] !== '') {
            $unit_cost = CJS_Inv_DB::num($item['default_cost']);
        }
        $received_at = $line['received_at'] ?? ($opts['received_at'] ?? CJS_Inv_DB::now());
        $source_type = $line['source_type'] ?? ($opts['source_type'] ?? 'receipt');
        $batch_id = CJS_Inv_DB::insert('batches', [
            'item_id' => $item_id,
            'unit_id' => (int) $item['unit_id'],
            'qty_received' => $qty,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'unit_cost' => $unit_cost,
            'source_type' => $source_type,
            'source_id' => $line['source_id'] ?? ($opts['source_id'] ?? null),
            'document_id' => $document_id,
            'received_at' => $received_at,
            'created_by' => get_current_user_id(),
            'created_at' => CJS_Inv_DB::now(),
            'updated_at' => CJS_Inv_DB::now(),
        ]);
        self::bump($batch_id, $qty, 0);
        $movement_type = $source_type === 'opening' ? 'opening' : ($source_type === 'count' ? 'count' : 'receipt');
        self::log($movement_type, $item_id, $qty, [
            'batch_id' => $batch_id,
            'unit_cost' => $unit_cost,
            'document_id' => $document_id,
            'stock_order_id' => $line['stock_order_id'] ?? ($opts['stock_order_id'] ?? null),
            'note' => $line['note'] ?? null,
            'created_at' => $opts['movement_at'] ?? null,
        ]);
        if ($per_piece) {
            $identifiers = isset($line['identifiers']) && is_array($line['identifiers']) ? array_values($line['identifiers']) : [];
            $notes = isset($line['piece_notes']) && is_array($line['piece_notes']) ? array_values($line['piece_notes']) : [];
            $generated = 0;
            for ($k = 0; $k < (int) $qty; $k++) {
                $identifier = isset($identifiers[$k]) ? trim(sanitize_text_field($identifiers[$k])) : '';
                if ($identifier === '') {
                    $identifier = CJS_Inv_Catalog::next_identifier($item_id, 0);
                    $generated++;
                }
                if ($identifier !== null && $identifier !== '') {
                    global $wpdb;
                    $taken = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id = %d AND identifier = %s', $item_id, $identifier));
                    if ($taken) {
                        return new WP_Error('cjs_duplicate', sprintf(__('Piece identifier %s already exists.', 'custom-jewelry-system'), $identifier));
                    }
                }
                CJS_Inv_DB::insert('pieces', [
                    'item_id' => $item_id,
                    'batch_id' => $batch_id,
                    'identifier' => ($identifier !== null && $identifier !== '') ? $identifier : null,
                    'status' => 'in',
                    'note' => isset($notes[$k]) && $notes[$k] !== '' ? mb_substr(sanitize_text_field($notes[$k]), 0, 255) : null,
                    'created_at' => CJS_Inv_DB::now(),
                    'updated_at' => CJS_Inv_DB::now(),
                ]);
            }
        }
        return $batch_id;
    }

    public static function count(array $lines, $note = '') {
        $touched = [];
        $result = CJS_Inv_DB::transaction(function () use ($lines, $note, &$touched) {
            $document_id = self::create_document('count', $note);
            $changes = [];
            foreach ($lines as $line) {
                $item_id = absint($line['item_id'] ?? 0);
                $item = CJS_Inv_Catalog::item_row($item_id);
                if (!$item) {
                    continue;
                }
                $touched[] = $item_id;
                if ((int) $item['per_piece'] === 1) {
                    $changes[] = self::count_pieces($item, $line, $document_id);
                } else {
                    $changes[] = self::count_quantity($item, $line, $document_id);
                }
            }
            return ['document_id' => $document_id, 'changes' => $changes];
        });
        CJS_Inv_Demands::reserve_pending($touched);
        CJS_Inv_Discrepancies::auto_close($touched, [], true);
        return $result;
    }

    private static function count_quantity(array $item, array $line, $document_id) {
        global $wpdb;
        $item_id = (int) $item['id'];
        $counted = CJS_Inv_DB::num($line['counted'] ?? null);
        if ($counted === null || $counted < 0) {
            throw new CJS_Inv_Exception(new WP_Error('cjs_invalid', sprintf(__('Enter a counted quantity for %s.', 'custom-jewelry-system'), $item['name'])));
        }
        $before = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d FOR UPDATE', $item_id));
        $diff = round($counted - $before, 4);
        $released = 0.0;
        if ($diff > 0) {
            $unit_cost = array_key_exists('unit_cost', $line) ? CJS_Inv_DB::num($line['unit_cost']) : null;
            self::create_batch([
                'item_id' => $item_id,
                'qty' => $diff,
                'unit_cost' => $unit_cost,
                'source_type' => 'count',
            ], $document_id);
        } elseif ($diff < 0) {
            $remove = -$diff;
            $batches = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d AND qty_on_hand > 0 ORDER BY received_at ASC, id ASC FOR UPDATE', $item_id), ARRAY_A) ?: [];
            foreach ($batches as $batch) {
                if ($remove <= 0) {
                    break;
                }
                $free = (float) $batch['qty_on_hand'] - (float) $batch['qty_reserved'];
                $take = min($free, $remove);
                if ($take > 0) {
                    self::bump((int) $batch['id'], -$take, 0);
                    self::log('count', $item_id, -$take, ['batch_id' => (int) $batch['id'], 'document_id' => $document_id, 'unit_cost' => $batch['unit_cost']]);
                    $remove = round($remove - $take, 4);
                }
            }
            if ($remove > 0) {
                $allocs = $wpdb->get_results($wpdb->prepare(
                    'SELECT a.* FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = a.batch_id WHERE b.item_id = %d AND a.state = 'reserved' ORDER BY a.id DESC",
                    $item_id
                ), ARRAY_A) ?: [];
                foreach ($allocs as $alloc) {
                    if ($remove <= 0) {
                        break;
                    }
                    $take = min((float) $alloc['qty'], $remove);
                    self::release_allocation_qty($alloc, $take, __('Released by stock count', 'custom-jewelry-system'), $document_id);
                    $released += $take;
                    self::bump((int) $alloc['batch_id'], -$take, 0);
                    $batch = self::batch((int) $alloc['batch_id']);
                    self::log('count', $item_id, -$take, ['batch_id' => (int) $alloc['batch_id'], 'document_id' => $document_id, 'unit_cost' => $batch['unit_cost'] ?? null]);
                    $remove = round($remove - $take, 4);
                }
            }
        }
        return [
            'item_id' => $item_id,
            'before' => $before,
            'counted' => $counted,
            'difference' => $diff,
            'released' => $released,
        ];
    }

    private static function count_pieces(array $item, array $line, $document_id) {
        global $wpdb;
        $item_id = (int) $item['id'];
        $missing = array_map('absint', (array) ($line['missing_piece_ids'] ?? []));
        $found = array_map('absint', (array) ($line['found_piece_ids'] ?? []));
        $removed = 0;
        $restored = 0;
        foreach ($missing as $piece_id) {
            $piece = self::piece($piece_id);
            if (!$piece || (int) $piece['item_id'] !== $item_id) {
                continue;
            }
            if ($piece['status'] === 'in') {
                self::bump((int) $piece['batch_id'], -1, 0);
            } elseif ($piece['status'] === 'reserved' && $piece['allocation_id']) {
                $alloc = self::allocation((int) $piece['allocation_id']);
                if ($alloc) {
                    self::release_allocation_qty($alloc, 1, __('Released by stock count', 'custom-jewelry-system'), $document_id);
                }
                self::bump((int) $piece['batch_id'], -1, 0);
            } else {
                continue;
            }
            CJS_Inv_DB::update('pieces', ['status' => 'removed', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => $piece_id]);
            self::log('count', $item_id, -1, ['batch_id' => (int) $piece['batch_id'], 'piece_id' => $piece_id, 'document_id' => $document_id]);
            $removed++;
        }
        foreach ($found as $piece_id) {
            $piece = self::piece($piece_id);
            if (!$piece || (int) $piece['item_id'] !== $item_id || $piece['status'] !== 'removed') {
                continue;
            }
            CJS_Inv_DB::update('pieces', ['status' => 'in', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => $piece_id]);
            self::bump((int) $piece['batch_id'], 1, 0);
            self::log('count', $item_id, 1, ['batch_id' => (int) $piece['batch_id'], 'piece_id' => $piece_id, 'document_id' => $document_id]);
            $restored++;
        }
        return [
            'item_id' => $item_id,
            'removed' => $removed,
            'restored' => $restored,
            'difference' => $restored - $removed,
        ];
    }

    public static function allocate_demand($demand_id, array $opts = []) {
        global $wpdb;
        $demand = CJS_Inv_Demands::row($demand_id);
        if (!$demand || $demand['state'] !== 'open') {
            return 0.0;
        }
        $missing = round((float) $demand['qty'] - (float) $demand['qty_allocated'], 4);
        if ($missing <= 0) {
            return 0.0;
        }
        $item = CJS_Inv_Catalog::item_row((int) $demand['item_id']);
        $metal = CJS_Inv_Metals::of_line($demand);
        if (!$item && !$metal) {
            return 0.0;
        }
        $rules = CJS_Inv_Flow::line_rules($demand);
        $allocated = 0.0;
        $cap = !empty($opts['cap']) ? (float) $opts['cap'] : 0.0;
        $ctx = ['order_id' => (int) $demand['order_id'], 'demand_id' => (int) $demand['id']];
        if (!empty($opts['note'])) {
            $ctx['note'] = $opts['note'];
        }
        if (!$metal && (int) $item['per_piece'] === 1) {
            $piece_filter = '';
            if (!empty($opts['piece_id'])) {
                $piece_filter = $wpdb->prepare(' AND p.id = %d', $opts['piece_id']);
            } elseif (!empty($opts['batch_id'])) {
                $piece_filter = $wpdb->prepare(' AND p.batch_id = %d', $opts['batch_id']);
            }
            $pieces = $wpdb->get_results($wpdb->prepare(
                'SELECT p.*, b.unit_cost FROM ' . CJS_Inv_DB::t('pieces') . ' p INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = p.batch_id WHERE p.item_id = %d AND p.status = 'in'$piece_filter ORDER BY b.received_at ASC, b.id ASC, p.id ASC LIMIT %d FOR UPDATE",
                (int) $item['id'], (int) ceil($cap > 0 ? round(min($missing, $cap), 4) : $missing)
            ), ARRAY_A) ?: [];
            foreach ($pieces as $piece) {
                $alloc_id = CJS_Inv_DB::insert('allocations', [
                    'demand_id' => (int) $demand['id'],
                    'batch_id' => (int) $piece['batch_id'],
                    'piece_id' => (int) $piece['id'],
                    'qty' => 1,
                    'state' => 'reserved',
                    'created_at' => CJS_Inv_DB::now(),
                    'updated_at' => CJS_Inv_DB::now(),
                ]);
                CJS_Inv_DB::update('pieces', ['status' => 'reserved', 'allocation_id' => $alloc_id, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $piece['id']]);
                self::bump((int) $piece['batch_id'], 0, 1);
                self::log('reserve', (int) $item['id'], 1, $ctx + ['batch_id' => (int) $piece['batch_id'], 'piece_id' => (int) $piece['id'], 'allocation_id' => $alloc_id, 'unit_cost' => $piece['unit_cost']]);
                $allocated += 1;
            }
        } else {
            $batch_filter = !empty($opts['batch_id']) ? $wpdb->prepare(' AND b.id = %d', $opts['batch_id']) : '';
            $scope = $metal ? $wpdb->prepare('i.category_id = %d AND ' . CJS_Inv_Metals::sql('i') . ' = %s AND i.archived = 0 AND i.unit_id = %d', $metal['category_id'], $metal['metal'], (int) $demand['unit_id']) : $wpdb->prepare('b.item_id = %d', (int) $item['id']);
            $batches = $wpdb->get_results(
                'SELECT b.*, i.praba AS item_praba FROM ' . CJS_Inv_DB::t('batches') . ' b INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = b.item_id WHERE $scope AND b.qty_on_hand - b.qty_reserved > 0$batch_filter ORDER BY " . ($metal ? 'COALESCE(i.praba, ' . CJS_Inv_Flow::PRABA_REF . ') DESC, ' : '') . "b.received_at ASC, b.id ASC FOR UPDATE",
                ARRAY_A
            ) ?: [];
            $want = $missing;
            if (!empty($opts['qty'])) {
                $want = min($want, (float) $opts['qty']);
            }
            if ($cap > 0) {
                $want = min($want, $cap);
            }
            foreach ($batches as $batch) {
                if ($want <= 0) {
                    break;
                }
                $free = round((float) $batch['qty_on_hand'] - (float) $batch['qty_reserved'], 4);
                if ($free <= 0) {
                    continue;
                }
                $fineness = $rules['has_fineness'] ? (CJS_Inv_Flow::clean_praba($batch['item_praba']) ?? CJS_Inv_Flow::PRABA_REF) : null;
                if ($fineness) {
                    $need = CJS_Inv_Flow::physical_for($want, $fineness);
                    if ($free >= $need) {
                        $take = $need;
                        $cover = $want;
                    } else {
                        $take = $free;
                        $cover = min($want, CJS_Inv_Flow::fine_for($free, $fineness));
                    }
                } else {
                    $take = min($free, $want);
                    $cover = $take;
                }
                if ($take <= 0 || $cover <= 0) {
                    continue;
                }
                $alloc_id = CJS_Inv_DB::insert('allocations', [
                    'demand_id' => (int) $demand['id'],
                    'batch_id' => (int) $batch['id'],
                    'qty' => $take,
                    'qty_fine' => $fineness ? $cover : null,
                    'state' => 'reserved',
                    'created_at' => CJS_Inv_DB::now(),
                    'updated_at' => CJS_Inv_DB::now(),
                ]);
                self::bump((int) $batch['id'], 0, $take);
                self::log('reserve', (int) $batch['item_id'], $take, $ctx + ['batch_id' => (int) $batch['id'], 'allocation_id' => $alloc_id, 'unit_cost' => $batch['unit_cost']]);
                $allocated += $cover;
                $want = round($want - $cover, 4);
            }
        }
        if ($allocated > 0) {
            CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET qty_allocated = qty_allocated + %f, updated_at = %s WHERE id = %d', $allocated, CJS_Inv_DB::now(), (int) $demand['id']));
            if ($allocated >= $missing - CJS_Inv_Flow::EPS) {
                CJS_Inv_Stock_Orders::drop_covered_links((int) $demand['id']);
            }
        }
        return $allocated;
    }

    public static function cover(array $alloc) {
        return isset($alloc['qty_fine']) && $alloc['qty_fine'] !== null && $alloc['qty_fine'] !== '' ? (float) $alloc['qty_fine'] : (float) $alloc['qty'];
    }

    private static function alloc_ctx(array $alloc, $note = '', $document_id = null) {
        $batch = self::batch((int) $alloc['batch_id']);
        $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
        return [
            'batch_id' => (int) $alloc['batch_id'],
            'piece_id' => $alloc['piece_id'] ? (int) $alloc['piece_id'] : null,
            'order_id' => $demand ? (int) $demand['order_id'] : null,
            'demand_id' => (int) $alloc['demand_id'],
            'allocation_id' => (int) $alloc['id'],
            'document_id' => $document_id,
            'unit_cost' => $batch ? $batch['unit_cost'] : null,
            'note' => $note !== '' ? $note : null,
        ];
    }

    private static function lower_allocated($demand_id, $fine) {
        global $wpdb;
        if ($fine <= 0) {
            return;
        }
        CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('demands') . ' SET qty_allocated = GREATEST(0, qty_allocated - %f), updated_at = %s WHERE id = %d', $fine, CJS_Inv_DB::now(), (int) $demand_id));
        CJS_Inv_Stock_Orders::queue_relink((int) $demand_id);
    }

    public static function release_allocation_qty(array $alloc, float $qty, $note = '', $document_id = null) {
        $batch = self::batch((int) $alloc['batch_id']);
        $item_id = $batch ? (int) $batch['item_id'] : 0;
        $qty = min($qty, (float) $alloc['qty']);
        if ($qty <= 0 || !$batch) {
            return;
        }
        $ctx = self::alloc_ctx($alloc, $note, $document_id);
        if ($alloc['state'] === 'reserved') {
            self::bump((int) $alloc['batch_id'], 0, -$qty);
            self::log('release', $item_id, -$qty, $ctx);
        } elseif ($alloc['state'] === 'out') {
            self::bump((int) $alloc['batch_id'], $qty, 0);
            self::log('return', $item_id, $qty, $ctx);
        } elseif ($alloc['state'] === 'used') {
            if (self::is_lost_allocation($alloc)) {
                return;
            }
            self::bump((int) $alloc['batch_id'], $qty, 0);
            self::log('return', $item_id, $qty, $ctx);
        } else {
            return;
        }
        $cover = self::cover($alloc);
        $fine_column = isset($alloc['qty_fine']) && $alloc['qty_fine'] !== null && $alloc['qty_fine'] !== '';
        $full = $qty >= (float) $alloc['qty'] - 0.00005;
        $fine = $full ? $cover : ($fine_column ? max(0.0, round($cover - min($cover, CJS_Inv_Flow::ceil3($cover * ((float) $alloc['qty'] - $qty) / (float) $alloc['qty'])), 4)) : $qty);
        if ($full) {
            CJS_Inv_DB::update('allocations', ['state' => 'released', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        } else {
            CJS_Inv_DB::update('allocations', ['qty' => round((float) $alloc['qty'] - $qty, 4), 'qty_fine' => $fine_column ? round($cover - $fine, 4) : null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
            CJS_Inv_DB::insert('allocations', [
                'demand_id' => (int) $alloc['demand_id'],
                'batch_id' => (int) $alloc['batch_id'],
                'piece_id' => $alloc['piece_id'],
                'qty' => $qty,
                'qty_fine' => $fine_column ? $fine : null,
                'state' => 'released',
                'created_at' => CJS_Inv_DB::now(),
                'updated_at' => CJS_Inv_DB::now(),
            ]);
        }
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'in', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        self::lower_allocated((int) $alloc['demand_id'], $fine);
    }

    public static function release_demand($demand_id, $include_out = false, $note = '') {
        global $wpdb;
        $states = $include_out ? "('reserved','used','out')" : "('reserved','used')";
        $allocs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('allocations') . " WHERE demand_id = %d AND state IN $states ORDER BY id DESC", $demand_id), ARRAY_A) ?: [];
        $released = 0.0;
        foreach ($allocs as $alloc) {
            if ($alloc['state'] === 'used' && self::is_lost_allocation($alloc)) {
                continue;
            }
            self::release_allocation_qty($alloc, (float) $alloc['qty'], $note);
            $released += (float) $alloc['qty'];
        }
        return $released;
    }

    public static function is_lost_allocation(array $alloc) {
        $batch = self::batch((int) $alloc['batch_id']);
        $item = $batch ? CJS_Inv_Catalog::item_row((int) $batch['item_id']) : null;
        if (!$item || (int) $item['returnable'] === 1) {
            return true;
        }
        if (!$alloc['piece_id']) {
            return false;
        }
        $piece = self::piece((int) $alloc['piece_id']);
        return !$piece || $piece['status'] !== 'used' || (int) $piece['allocation_id'] !== (int) $alloc['id'];
    }

    public static function consume_reserved($demand_id, $note = '') {
        global $wpdb;
        $allocs = $wpdb->get_results($wpdb->prepare(
            'SELECT a.*, b.unit_cost, b.item_id, i.returnable FROM ' . CJS_Inv_DB::t('allocations') . ' a INNER JOIN ' . CJS_Inv_DB::t('batches') . ' b ON b.id = a.batch_id INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = b.item_id WHERE a.demand_id = %d AND a.state = 'reserved' AND i.returnable = 0",
            $demand_id
        ), ARRAY_A) ?: [];
        $demand = CJS_Inv_Demands::row($demand_id);
        $done = 0.0;
        foreach ($allocs as $alloc) {
            $q = (float) $alloc['qty'];
            self::bump((int) $alloc['batch_id'], -$q, -$q);
            CJS_Inv_DB::update('allocations', ['state' => 'used', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
            if ($alloc['piece_id']) {
                CJS_Inv_DB::update('pieces', ['status' => 'used', 'allocation_id' => (int) $alloc['id'], 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
            }
            self::log('use', (int) $alloc['item_id'], -$q, [
                'batch_id' => (int) $alloc['batch_id'],
                'piece_id' => $alloc['piece_id'] ? (int) $alloc['piece_id'] : null,
                'order_id' => $demand ? (int) $demand['order_id'] : null,
                'demand_id' => (int) $demand_id,
                'allocation_id' => (int) $alloc['id'],
                'unit_cost' => $alloc['unit_cost'],
                'note' => $note !== '' ? $note : null,
            ]);
            $done += $q;
        }
        return $done;
    }

    public static function unuse_allocation(array $alloc, $note = '') {
        if ($alloc['state'] !== 'used' || self::is_lost_allocation($alloc)) {
            return 0.0;
        }
        $batch = self::batch((int) $alloc['batch_id']);
        if (!$batch) {
            return 0.0;
        }
        $q = (float) $alloc['qty'];
        $ctx = self::alloc_ctx($alloc, $note);
        self::bump((int) $alloc['batch_id'], $q, $q);
        self::log('return', (int) $batch['item_id'], $q, $ctx);
        self::log('reserve', (int) $batch['item_id'], $q, $ctx);
        CJS_Inv_DB::update('allocations', ['state' => 'reserved', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'reserved', 'allocation_id' => (int) $alloc['id'], 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        return $q;
    }

    public static function write_off_allocation(array $alloc, $document_id, $note = '') {
        if (!in_array($alloc['state'], ['reserved', 'used', 'out'], true)) {
            return 0.0;
        }
        if ($alloc['state'] === 'used' && self::is_lost_allocation($alloc)) {
            return 0.0;
        }
        $batch = self::batch((int) $alloc['batch_id']);
        if (!$batch) {
            return 0.0;
        }
        $q = (float) $alloc['qty'];
        $item_id = (int) $batch['item_id'];
        $ctx = self::alloc_ctx($alloc, $note);
        if ($alloc['state'] === 'reserved') {
            self::bump((int) $alloc['batch_id'], 0, -$q);
            self::log('release', $item_id, -$q, $ctx);
        } else {
            self::bump((int) $alloc['batch_id'], $q, 0);
            self::log('return', $item_id, $q, $ctx);
        }
        self::bump((int) $alloc['batch_id'], -$q, 0);
        self::log('count', $item_id, -$q, array_merge($ctx, ['document_id' => $document_id ? (int) $document_id : null]));
        CJS_Inv_DB::update('allocations', ['state' => 'writeoff', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'removed', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        $cover = self::cover($alloc);
        self::lower_allocated((int) $alloc['demand_id'], $cover);
        return $cover;
    }

    public static function send_core(array $alloc, $note = '') {
        $batch = self::batch((int) $alloc['batch_id']);
        if (!$batch || $alloc['state'] !== 'reserved') {
            return false;
        }
        $q = (float) $alloc['qty'];
        self::bump((int) $alloc['batch_id'], -$q, -$q);
        CJS_Inv_DB::update('allocations', ['state' => 'out', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'out', 'allocation_id' => (int) $alloc['id'], 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        self::log('out', (int) $batch['item_id'], -$q, self::alloc_ctx($alloc, $note));
        return true;
    }

    public static function return_out(array $alloc, $note = '') {
        $batch = self::batch((int) $alloc['batch_id']);
        if (!$batch || $alloc['state'] !== 'out') {
            return false;
        }
        $q = (float) $alloc['qty'];
        self::bump((int) $alloc['batch_id'], $q, 0);
        CJS_Inv_DB::update('allocations', ['state' => 'returned', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'in', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        self::log('return', (int) $batch['item_id'], $q, self::alloc_ctx($alloc, $note));
        self::lower_allocated((int) $alloc['demand_id'], self::cover($alloc));
        return true;
    }

    public static function reverse_send(array $alloc, $note = '') {
        $batch = self::batch((int) $alloc['batch_id']);
        if (!$batch || $alloc['state'] !== 'out') {
            return false;
        }
        $q = (float) $alloc['qty'];
        self::bump((int) $alloc['batch_id'], $q, 0);
        CJS_Inv_DB::update('allocations', ['state' => 'released', 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['id']]);
        if ($alloc['piece_id']) {
            CJS_Inv_DB::update('pieces', ['status' => 'in', 'allocation_id' => null, 'updated_at' => CJS_Inv_DB::now()], ['id' => (int) $alloc['piece_id']]);
        }
        self::log('return', (int) $batch['item_id'], $q, self::alloc_ctx($alloc, $note));
        self::lower_allocated((int) $alloc['demand_id'], self::cover($alloc));
        return true;
    }

    public static function send_allocation($allocation_id, $note = '') {
        $alloc = self::allocation($allocation_id);
        if (!$alloc || $alloc['state'] !== 'reserved') {
            return new WP_Error('cjs_invalid', __('This piece is not waiting to be sent.', 'custom-jewelry-system'));
        }
        $batch = self::batch((int) $alloc['batch_id']);
        $item = $batch ? CJS_Inv_Catalog::item_row((int) $batch['item_id']) : null;
        if (!$item || (int) $item['returnable'] !== 1) {
            return new WP_Error('cjs_invalid', __('Only returnable items can be sent.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($alloc, $note) {
            self::send_core($alloc, $note);
        });
        $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
        if ($demand) {
            CJS_Inv_Demands::close_order_discrepancy((int) $demand['order_id']);
        }
        return true;
    }

    public static function return_allocation($allocation_id, $note = '') {
        $alloc = self::allocation($allocation_id);
        if (!$alloc || $alloc['state'] !== 'out') {
            return new WP_Error('cjs_invalid', __('This piece is not out.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($alloc, $note) {
            self::return_out($alloc, $note);
        });
        $batch = self::batch((int) $alloc['batch_id']);
        if ($batch) {
            CJS_Inv_Demands::reserve_pending([(int) $batch['item_id']], [], [(int) $alloc['demand_id']]);
        }
        self::settle_after_return((int) $alloc['demand_id']);
        $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
        if ($demand) {
            CJS_Inv_Demands::close_order_discrepancy((int) $demand['order_id']);
        }
        return true;
    }

    private static function settle_after_return($demand_id) {
        $demand = CJS_Inv_Demands::row($demand_id);
        if ($demand && $demand['state'] === 'open' && (int) $demand['order_id'] > 0) {
            CJS_Inv_Flow::settle_line($demand, CJS_Inv_Flow::order_status_key((int) $demand['order_id']));
            CJS_Inv_Discrepancies::auto_close([], [(int) $demand['order_id']], false, get_current_user_id());
        }
    }

    public static function can_unassign(array $alloc, $demand = null, array $ctx = []) {
        $state = (string) ($alloc['state'] ?? '');
        $demand = $demand ?: CJS_Inv_Demands::row((int) $alloc['demand_id']);
        if (!$demand) {
            return false;
        }
        $order_id = (int) $demand['order_id'];
        $status = array_key_exists('order_status', $ctx) ? (string) $ctx['order_status'] : ($order_id > 0 ? CJS_Inv_Demands::order_status_fast($order_id) : null);
        if ($order_id > 0 && CJS_Inv_Flow::order_closed($order_id, $status)) {
            if (!in_array($demand['state'], ['open', 'done'], true)) {
                return false;
            }
            if (in_array($state, ['reserved', 'out'], true)) {
                return true;
            }
            return $state === 'used' && $status === '' && !empty($alloc['batch_id']) && !self::is_lost_allocation($alloc);
        }
        if (!in_array($state, ['reserved', 'out'], true) || $demand['state'] !== 'open') {
            return false;
        }
        $rules = CJS_Inv_Flow::line_rules($demand);
        if (!$rules['auto_assign']) {
            return true;
        }
        if ($state !== 'reserved') {
            return false;
        }
        if (array_key_exists('waiting_ids', $ctx)) {
            return (bool) array_diff((array) $ctx['waiting_ids'], [(int) $demand['id']]);
        }
        return CJS_Inv_Flow::waiting_others((int) $demand['item_id'], (int) $demand['id'], $demand);
    }

    public static function unassign_allocation($allocation_id) {
        $alloc = self::allocation($allocation_id);
        if (!$alloc || !in_array($alloc['state'], ['reserved', 'out', 'used'], true)) {
            return new WP_Error('cjs_invalid', __('Nothing to release.', 'custom-jewelry-system'));
        }
        $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
        if (!$demand) {
            return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
        }
        $rules = CJS_Inv_Flow::line_rules($demand);
        if (!self::can_unassign($alloc, $demand)) {
            if (!in_array($alloc['state'], ['reserved', 'out'], true)) {
                return new WP_Error('cjs_invalid', __('Nothing to release.', 'custom-jewelry-system'));
            }
            if ($demand['state'] !== 'open') {
                return new WP_Error('cjs_invalid', __('This line is not open.', 'custom-jewelry-system'));
            }
            return new WP_Error('cjs_invalid', __('Unassign is only available when another line is waiting for this item.', 'custom-jewelry-system'));
        }
        $returned = false;
        CJS_Inv_DB::transaction(function () use ($alloc, &$returned) {
            if ($alloc['state'] === 'out') {
                $returned = self::return_out($alloc, __('Returned', 'custom-jewelry-system'));
            } else {
                self::release_allocation_qty($alloc, (float) $alloc['qty'], __('Unassigned manually', 'custom-jewelry-system'));
            }
        });
        if ($rules['auto_assign']) {
            CJS_Inv_Stock_Orders::hold_relink();
            try {
                CJS_Inv_Demands::reserve_pending([(int) $demand['item_id']], [], [(int) $demand['id']]);
                CJS_Inv_Demands::reserve_pending([(int) $demand['item_id']]);
            } finally {
                CJS_Inv_Stock_Orders::release_relink();
            }
        }
        CJS_Inv_Stock_Orders::relink_pending();
        if ($returned) {
            self::settle_after_return((int) $demand['id']);
        }
        CJS_Inv_Demands::close_order_discrepancy((int) $demand['order_id']);
        $fresh = CJS_Inv_Demands::row((int) $demand['id']);
        return ['returned' => (bool) $returned, 'ask_remove' => (bool) $returned && $rules['returnable'] && $fresh && $fresh['state'] === 'open'];
    }

    public static function mark_lost($allocation_id, $note = '') {
        $alloc = self::allocation($allocation_id);
        if (!$alloc || $alloc['state'] !== 'out') {
            return new WP_Error('cjs_invalid', __('Only pieces that are out can be marked as lost.', 'custom-jewelry-system'));
        }
        CJS_Inv_DB::transaction(function () use ($alloc, $note) {
            $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
            $number = $demand ? (string) $demand['order_id'] : '';
            $order = $demand && function_exists('wc_get_order') ? wc_get_order((int) $demand['order_id']) : null;
            if ($order) {
                $number = (string) $order->get_order_number();
            }
            $doc = self::create_document('count', sprintf(__('Written off: lost on order #%s', 'custom-jewelry-system'), $number));
            self::write_off_allocation($alloc, $doc, $note !== '' ? $note : __('Marked as lost', 'custom-jewelry-system'));
            if ($demand && in_array($demand['state'], ['open', 'done'], true) && !CJS_Inv_Flow::live_allocs((int) $demand['id'])) {
                CJS_Inv_DB::update('demands', ['state' => 'cancelled', 'cancelled_by_order' => 0, 'updated_at' => CJS_Inv_DB::now()] + CJS_Inv_Demands::ended_row('lost'), ['id' => (int) $demand['id']]);
                self::log('demand', (int) $demand['item_id'], 0, ['order_id' => (int) $demand['order_id'], 'demand_id' => (int) $demand['id'], 'note' => __('Lost, written off and removed from the order\'s inventory', 'custom-jewelry-system')]);
            }
        });
        $demand = CJS_Inv_Demands::row((int) $alloc['demand_id']);
        if ($demand) {
            CJS_Inv_Demands::close_order_discrepancy((int) $demand['order_id']);
        }
        return true;
    }

    public static function set_batch_cost($batch_id, $unit_cost) {
        global $wpdb;
        $batch = self::batch((int) $batch_id);
        if (!$batch) {
            return new WP_Error('cjs_not_found', __('Batch not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $new = ($unit_cost === null || $unit_cost === '') ? null : CJS_Inv_DB::num($unit_cost);
        if ($unit_cost !== null && $unit_cost !== '' && ($new === null || $new < 0)) {
            return new WP_Error('cjs_invalid', __('Enter a unit cost of zero or more.', 'custom-jewelry-system'));
        }
        $old = $batch['unit_cost'] !== null ? (float) $batch['unit_cost'] : null;
        if ($old === $new || ($old !== null && $new !== null && abs($old - $new) < 0.00005)) {
            return ['batch_id' => (int) $batch_id, 'unit_cost' => $new, 'changed' => 0];
        }
        $changed = CJS_Inv_DB::transaction(function () use ($wpdb, $batch, $new, $old) {
            $bid = (int) $batch['id'];
            $now = CJS_Inv_DB::now();
            if ($new === null) {
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('batches') . ' SET unit_cost = NULL, updated_at = %s WHERE id = %d', $now, $bid));
                $n = CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('movements') . " SET unit_cost = NULL WHERE batch_id = %d AND type IN ('receipt','opening','count','use','out','return','reserve','release','lost')", $bid));
            } else {
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('batches') . ' SET unit_cost = %f, updated_at = %s WHERE id = %d', $new, $now, $bid));
                $n = CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('movements') . " SET unit_cost = %f WHERE batch_id = %d AND type IN ('receipt','opening','count','use','out','return','reserve','release','lost')", $new, $bid));
            }
            if ($batch['source_type'] === 'stock_order' && $batch['source_id'] && $new !== null) {
                CJS_Inv_DB::query($wpdb->prepare('UPDATE ' . CJS_Inv_DB::t('stock_order_lines') . ' SET unit_cost = %f, updated_at = %s WHERE id = %d AND unit_cost IS NULL', $new, $now, (int) $batch['source_id']));
            }
            self::log('cost', (int) $batch['item_id'], 0, [
                'batch_id' => $bid,
                'unit_cost' => $new,
                'document_id' => $batch['document_id'] !== null ? (int) $batch['document_id'] : null,
                'note' => $old === null
                    ? sprintf(__('Unit cost set to %s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($new))
                    : ($new === null
                        ? sprintf(__('Unit cost %s removed', 'custom-jewelry-system'), CJS_Inv_DB::fmt($old))
                        : sprintf(__('Unit cost changed from %1$s to %2$s', 'custom-jewelry-system'), CJS_Inv_DB::fmt($old), CJS_Inv_DB::fmt($new))),
            ]);
            return (int) $n;
        });
        CJS_Logger::log('Batch unit cost changed', 'info', 'inventory', (int) $batch_id, ['old' => $old, 'new' => $new, 'movements' => $changed]);
        return ['batch_id' => (int) $batch_id, 'unit_cost' => $new, 'old_unit_cost' => $old, 'changed' => $changed];
    }

    public static function item_detail($item_id) {
        global $wpdb;
        $summary = CJS_Inv_Catalog::item_summary($item_id);
        if (!$summary) {
            return null;
        }
        $missing = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(qty - qty_allocated), 0) FROM ' . CJS_Inv_DB::t('demands') . " WHERE item_id = %d AND state = 'open' AND qty_allocated < qty", $item_id));
        $summary['missing'] = $missing;
        $rules = CJS_Inv_Flow::rules((int) $item_id);
        $summary['fineness'] = $rules['has_fineness'];
        $summary['missing_at_default'] = $missing;
        if ($rules['has_fineness']) {
            $at = 0.0;
            foreach ($wpdb->get_col($wpdb->prepare('SELECT qty - qty_allocated FROM ' . CJS_Inv_DB::t('demands') . " WHERE item_id = %d AND state = 'open' AND qty_allocated < qty", $item_id)) ?: [] as $m) {
                $at += CJS_Inv_Flow::missing_at_default(round((float) $m, 4), $rules);
            }
            $summary['missing_at_default'] = round($at, 4);
        }
        $summary['stock_value'] = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(qty_on_hand * unit_cost), 0) FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d AND unit_cost IS NOT NULL AND qty_on_hand > 0', $item_id));
        $batches = $wpdb->get_results($wpdb->prepare('SELECT b.*, (SELECT COALESCE(SUM(a.qty), 0) FROM ' . CJS_Inv_DB::t('allocations') . " a WHERE a.batch_id = b.id AND a.state = 'used') AS qty_used FROM " . CJS_Inv_DB::t('batches') . ' b WHERE b.item_id = %d ORDER BY b.received_at DESC, b.id DESC LIMIT 200', $item_id), ARRAY_A) ?: [];
        $summary['batches'] = array_map(function ($b) {
            return [
                'id' => (int) $b['id'],
                'received' => (float) $b['qty_received'],
                'on_hand' => (float) $b['qty_on_hand'],
                'reserved' => (float) $b['qty_reserved'],
                'free' => (float) $b['qty_on_hand'] - (float) $b['qty_reserved'],
                'used' => (float) $b['qty_used'],
                'unit_cost' => $b['unit_cost'] !== null ? (float) $b['unit_cost'] : null,
                'fineness' => null,
                'source_type' => $b['source_type'],
                'source_id' => $b['source_id'] !== null ? (int) $b['source_id'] : null,
                'document_id' => $b['document_id'] !== null ? (int) $b['document_id'] : null,
                'received_at' => $b['received_at'],
            ];
        }, $batches);
        $summary['pieces'] = [];
        if ($summary['per_piece']) {
            $summary['pieces'] = self::pieces_for_item($item_id);
        }
        $summary['demands'] = CJS_Inv_Demands::for_item($item_id);
        $summary['stats'] = [
            'used_total' => (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(-SUM(qty), 0) FROM ' . CJS_Inv_DB::t('movements') . " WHERE item_id = %d AND (type = 'use' OR (type = 'return' AND %d = 0 AND allocation_id IS NOT NULL))", $item_id, $summary['returnable'] ? 1 : 0)),
            'received_total' => (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(qty), 0) FROM ' . CJS_Inv_DB::t('movements') . " WHERE item_id = %d AND type IN ('receipt','opening')", $item_id)),
            'last_cost' => ($lc = $wpdb->get_var($wpdb->prepare('SELECT unit_cost FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d AND unit_cost IS NOT NULL ORDER BY received_at DESC, id DESC LIMIT 1', $item_id))) !== null ? (float) $lc : null,
        ];
        return $summary;
    }

    public static function piece_options($item_id) {
        global $wpdb;
        $next = (int) $wpdb->get_var($wpdb->prepare('SELECT p.id FROM ' . CJS_Inv_DB::t('pieces') . ' p INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = p.batch_id WHERE p.item_id = %d AND p.status = 'in' ORDER BY b.received_at ASC, b.id ASC, p.id ASC LIMIT 1", (int) $item_id));
        $pieces = array_values(array_filter(self::pieces_for_item((int) $item_id), function ($p) { return in_array($p['status'], ['in', 'reserved', 'out'], true); }));
        $free_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT p.id FROM ' . CJS_Inv_DB::t('pieces') . ' p INNER JOIN ' . CJS_Inv_DB::t('batches') . " b ON b.id = p.batch_id WHERE p.item_id = %d AND p.status = 'in' ORDER BY b.received_at ASC, b.id ASC, p.id ASC", (int) $item_id)) ?: []);
        return ['pieces' => $pieces, 'next_id' => $next ?: null, 'free_ids' => $free_ids];
    }

    public static function pieces_for_item($item_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT p.*, a.demand_id, a.state AS alloc_state, d.order_id, d.state AS demand_state FROM ' . CJS_Inv_DB::t('pieces') . ' p
             LEFT JOIN ' . CJS_Inv_DB::t('allocations') . ' a ON a.id = p.allocation_id
             LEFT JOIN ' . CJS_Inv_DB::t('demands') . ' d ON d.id = a.demand_id
             WHERE p.item_id = %d ORDER BY p.status = \'removed\', p.identifier IS NULL, LENGTH(p.identifier), p.identifier, p.id',
            $item_id
        ), ARRAY_A) ?: [];
        $order_ids = array_filter(array_map(function ($r) { return (int) ($r['order_id'] ?? 0); }, $rows));
        $orders = CJS_Orders::order_labels($order_ids);
        $statuses = CJS_Inv_Demands::order_statuses_fast($order_ids);
        $waiting = CJS_Inv_Flow::rules((int) $item_id)['auto_assign'] ? CJS_Inv_Flow::waiting_ids((int) $item_id) : [];
        return array_map(function ($r) use ($orders, $statuses, $waiting) {
            $oid = (int) ($r['order_id'] ?? 0);
            return [
                'id' => (int) $r['id'],
                'batch_id' => (int) $r['batch_id'],
                'identifier' => $r['identifier'],
                'status' => $r['status'],
                'note' => $r['note'],
                'allocation_id' => $r['allocation_id'] ? (int) $r['allocation_id'] : null,
                'demand_id' => !empty($r['demand_id']) ? (int) $r['demand_id'] : null,
                'can_unassign' => !empty($r['allocation_id']) && !empty($r['demand_id']) && self::can_unassign(
                    ['id' => (int) $r['allocation_id'], 'state' => (string) $r['alloc_state'], 'demand_id' => (int) $r['demand_id'], 'batch_id' => (int) $r['batch_id'], 'piece_id' => (int) $r['id']],
                    ['id' => (int) $r['demand_id'], 'order_id' => $oid, 'item_id' => (int) $r['item_id'], 'state' => (string) $r['demand_state']],
                    ['order_status' => $statuses[$oid] ?? '', 'waiting_ids' => $waiting]
                ),
                'order' => $oid && isset($orders[$oid]) ? $orders[$oid] : null,
                'updated_at' => $r['updated_at'],
            ];
        }, $rows);
    }

    public static function movements(array $args = []) {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        foreach (['item_id', 'order_id', 'batch_id', 'piece_id', 'document_id', 'stock_order_id'] as $key) {
            if (!empty($args[$key])) {
                $where[] = "m.$key = %d";
                $params[] = absint($args[$key]);
            }
        }
        if (!empty($args['types']) && is_array($args['types'])) {
            $types = array_map(function ($t) use ($wpdb) { return $wpdb->prepare('%s', sanitize_key($t)); }, $args['types']);
            $where[] = 'm.type IN (' . implode(',', $types) . ')';
        }
        $per_page = min(500, max(1, absint($args['per_page'] ?? 100)));
        $page = max(1, absint($args['page'] ?? 1));
        $where_sql = implode(' AND ', $where);
        $total_sql = 'SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('movements') . " m WHERE $where_sql";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($total_sql, $params) : $total_sql);
        $sql = 'SELECT m.*, i.name AS item_name, i.category_id, p.identifier, u.display_name, so.number AS stock_order_number, doc.type AS document_type, doc.note AS document_note FROM ' . CJS_Inv_DB::t('movements') . ' m
                LEFT JOIN ' . CJS_Inv_DB::t('items') . ' i ON i.id = m.item_id
                LEFT JOIN ' . CJS_Inv_DB::t('pieces') . ' p ON p.id = m.piece_id
                LEFT JOIN ' . CJS_Inv_DB::t('stock_orders') . ' so ON so.id = m.stock_order_id
                LEFT JOIN ' . CJS_Inv_DB::t('documents') . ' doc ON doc.id = m.document_id
                LEFT JOIN ' . $wpdb->users . " u ON u.ID = m.user_id
                WHERE $where_sql ORDER BY m.created_at DESC, m.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page, ($page - 1) * $per_page])), ARRAY_A) ?: [];
        $avatars = [];
        $orders = CJS_Orders::order_labels(array_map(function ($m) { return (int) $m['order_id']; }, $rows));
        return [
            'movements' => array_map(function ($m) use (&$avatars, $orders) {
                return [
                    'id' => (int) $m['id'],
                    'type' => $m['type'],
                    'item_id' => (int) $m['item_id'],
                    'item_name' => $m['item_name'],
                    'category_id' => (int) ($m['category_id'] ?? 0),
                    'avatar' => self::avatar((int) $m['user_id'], $avatars),
                    'batch_id' => $m['batch_id'] !== null ? (int) $m['batch_id'] : null,
                    'piece_id' => $m['piece_id'] !== null ? (int) $m['piece_id'] : null,
                    'identifier' => $m['identifier'],
                    'qty' => (float) $m['qty'],
                    'unit' => CJS_Inv_Catalog::unit_label($m['unit_id']),
                    'unit_cost' => $m['unit_cost'] !== null ? (float) $m['unit_cost'] : null,
                    'order_id' => $m['order_id'] !== null ? (int) $m['order_id'] : null,
                    'order' => !empty($m['order_id']) ? ($orders[(int) $m['order_id']] ?? null) : null,
                    'stock_order_number' => $m['stock_order_number'],
                    'document_type' => $m['document_type'],
                    'document_note' => $m['document_note'],
                    'demand_id' => $m['demand_id'] !== null ? (int) $m['demand_id'] : null,
                    'document_id' => $m['document_id'] !== null ? (int) $m['document_id'] : null,
                    'stock_order_id' => $m['stock_order_id'] !== null ? (int) $m['stock_order_id'] : null,
                    'note' => $m['note'],
                    'user' => $m['display_name'] ?: ($m['user_id'] ? '#' . $m['user_id'] : __('System', 'custom-jewelry-system')),
                    'created_at' => $m['created_at'],
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    private static function avatar($user_id, array &$cache) {
        if (!$user_id) {
            return null;
        }
        if (!array_key_exists($user_id, $cache)) {
            $url = get_avatar_url($user_id, ['size' => 64, 'default' => '404']);
            $cache[$user_id] = is_string($url) && $url !== '' ? $url : null;
        }
        return $cache[$user_id];
    }

    private static function document_summaries(array $ids) {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $types = "'" . implode("','", self::ON_HAND_TYPES) . "'";
        $rows = $wpdb->get_results('SELECT m.document_id, i.category_id, m.item_id, m.unit_id, SUM(m.qty) AS qty FROM ' . CJS_Inv_DB::t('movements') . ' m INNER JOIN ' . CJS_Inv_DB::t('items') . " i ON i.id = m.item_id WHERE m.document_id IN (" . CJS_Inv_DB::ids_in($ids) . ") AND m.type IN ($types) GROUP BY m.document_id, i.category_id, m.item_id, m.unit_id", ARRAY_A) ?: [];
        $out = [];
        $cats = [];
        foreach ($rows as $r) {
            $doc = (int) $r['document_id'];
            $cid = (int) $r['category_id'];
            if (!array_key_exists($cid, $cats)) {
                $cats[$cid] = CJS_Inv_Catalog::category($cid);
            }
            if (!isset($out[$doc][$cid])) {
                $out[$doc][$cid] = [
                    'category_id' => $cid,
                    'category' => $cats[$cid]['name'] ?? __('Other', 'custom-jewelry-system'),
                    'icon' => $cats[$cid] ? CJS_Inv_Catalog::category_icon($cats[$cid]) : '',
                    'sort' => (int) ($cats[$cid]['sort_order'] ?? 0),
                    'item_ids' => [],
                    'totals' => [],
                ];
            }
            $out[$doc][$cid]['item_ids'][(int) $r['item_id']] = true;
            $unit = CJS_Inv_Catalog::unit_label($r['unit_id']);
            $q = (float) $r['qty'];
            if (!isset($out[$doc][$cid]['totals'][$unit])) {
                $out[$doc][$cid]['totals'][$unit] = ['unit' => $unit, 'plus' => 0.0, 'minus' => 0.0];
            }
            if ($q > 0) {
                $out[$doc][$cid]['totals'][$unit]['plus'] += $q;
            } elseif ($q < 0) {
                $out[$doc][$cid]['totals'][$unit]['minus'] += $q;
            }
        }
        foreach ($out as $doc => $groups) {
            usort($groups, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
            $out[$doc] = array_map(function ($g) {
                return [
                    'category_id' => $g['category_id'],
                    'category' => $g['category'],
                    'icon' => $g['icon'],
                    'items' => count($g['item_ids']),
                    'totals' => array_values(array_map(function ($t) {
                        return ['unit' => $t['unit'], 'plus' => round($t['plus'], 4), 'minus' => round($t['minus'], 4)];
                    }, $g['totals'])),
                ];
            }, $groups);
        }
        return $out;
    }

    public static function documents(array $args = []) {
        global $wpdb;
        $where = '1=1';
        if (!empty($args['type'])) {
            $where .= $wpdb->prepare(' AND d.type = %s', sanitize_key($args['type']));
        }
        $per_page = min(200, max(1, absint($args['per_page'] ?? 50)));
        $page = max(1, absint($args['page'] ?? 1));
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('documents') . " d WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT d.*, u.display_name,
                (SELECT COUNT(*) FROM ' . CJS_Inv_DB::t('movements') . ' m WHERE m.document_id = d.id) AS movement_count,
                (SELECT COUNT(DISTINCT m.item_id) FROM ' . CJS_Inv_DB::t('movements') . ' m WHERE m.document_id = d.id) AS item_count
             FROM ' . CJS_Inv_DB::t('documents') . ' d LEFT JOIN ' . $wpdb->users . " u ON u.ID = d.user_id
             WHERE $where ORDER BY d.created_at DESC, d.id DESC LIMIT %d OFFSET %d",
            $per_page, ($page - 1) * $per_page
        ), ARRAY_A) ?: [];
        $ids = array_map(function ($d) { return (int) $d['id']; }, $rows);
        $summaries = self::document_summaries($ids);
        $stock_orders = [];
        if ($ids) {
            $so_rows = $wpdb->get_results('SELECT m.document_id, MAX(m.stock_order_id) AS stock_order_id, MAX(s.number) AS number FROM ' . CJS_Inv_DB::t('movements') . ' m INNER JOIN ' . CJS_Inv_DB::t('stock_orders') . ' s ON s.id = m.stock_order_id WHERE m.document_id IN (' . CJS_Inv_DB::ids_in($ids) . ') GROUP BY m.document_id', ARRAY_A) ?: [];
            foreach ($so_rows as $r) {
                $stock_orders[(int) $r['document_id']] = ['id' => (int) $r['stock_order_id'], 'number' => (string) $r['number']];
            }
        }
        $avatars = [];
        return [
            'documents' => array_map(function ($d) use ($summaries, $stock_orders, &$avatars) {
                $id = (int) $d['id'];
                return [
                    'id' => $id,
                    'type' => $d['type'],
                    'note' => $d['note'],
                    'stock_order' => $stock_orders[$id] ?? null,
                    'summary' => $summaries[$id] ?? [],
                    'avatar' => self::avatar((int) $d['user_id'], $avatars),
                    'user' => $d['display_name'] ?: __('System', 'custom-jewelry-system'),
                    'created_at' => $d['created_at'],
                    'movement_count' => (int) $d['movement_count'],
                    'item_count' => (int) $d['item_count'],
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    public static function integrity_report($limit = 200) {
        global $wpdb;
        $m = CJS_Inv_DB::t('movements');
        $b = CJS_Inv_DB::t('batches');
        $i = CJS_Inv_DB::t('items');
        $a = CJS_Inv_DB::t('allocations');
        $d = CJS_Inv_DB::t('demands');
        $on_hand_types = "'" . implode("','", self::ON_HAND_TYPES) . "'";
        $live_states = "'" . implode("','", self::LIVE_ALLOCATION_STATES) . "'";
        $issues = [];
        $rows = $wpdb->get_results("SELECT b.id, b.item_id, b.qty_on_hand, b.qty_reserved,
                COALESCE((SELECT SUM(qty) FROM $m WHERE batch_id = b.id AND type IN ($on_hand_types)), 0) AS mv_on_hand,
                COALESCE((SELECT SUM(qty) FROM $a WHERE batch_id = b.id AND state = 'reserved'), 0) AS alloc_reserved
            FROM $b b", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $problems = [];
            if (abs((float) $r['qty_on_hand'] - (float) $r['mv_on_hand']) > 0.0001) {
                $problems[] = 'on_hand ' . CJS_Inv_DB::fmt($r['qty_on_hand']) . ' != movements ' . CJS_Inv_DB::fmt($r['mv_on_hand']);
            }
            if (abs((float) $r['qty_reserved'] - (float) $r['alloc_reserved']) > 0.0001) {
                $problems[] = 'reserved ' . CJS_Inv_DB::fmt($r['qty_reserved']) . ' != allocations ' . CJS_Inv_DB::fmt($r['alloc_reserved']);
            }
            if ((float) $r['qty_on_hand'] < -0.0001) {
                $problems[] = 'negative on_hand';
            }
            if ((float) $r['qty_reserved'] > (float) $r['qty_on_hand'] + 0.0001) {
                $problems[] = 'reserved exceeds on_hand';
            }
            if ($problems) {
                $issues[] = ['type' => 'batch', 'id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'problems' => $problems];
            }
        }
        $rows = $wpdb->get_results("SELECT i.id, i.qty_on_hand, i.qty_reserved,
                COALESCE((SELECT SUM(qty_on_hand) FROM $b WHERE item_id = i.id), 0) AS b_on_hand,
                COALESCE((SELECT SUM(qty_reserved) FROM $b WHERE item_id = i.id), 0) AS b_reserved
            FROM $i i", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if (abs((float) $r['qty_on_hand'] - (float) $r['b_on_hand']) > 0.0001 || abs((float) $r['qty_reserved'] - (float) $r['b_reserved']) > 0.0001) {
                $issues[] = ['type' => 'item', 'id' => (int) $r['id'], 'problems' => ['item counters ' . CJS_Inv_DB::fmt($r['qty_on_hand']) . '/' . CJS_Inv_DB::fmt($r['qty_reserved']) . ' != batches ' . CJS_Inv_DB::fmt($r['b_on_hand']) . '/' . CJS_Inv_DB::fmt($r['b_reserved'])]];
            }
        }
        $rows = $wpdb->get_results("SELECT d.id, d.qty, d.qty_allocated,
                COALESCE((SELECT SUM(COALESCE(qty_fine, qty)) FROM $a WHERE demand_id = d.id AND state IN ($live_states)), 0) AS alloc_sum
            FROM $d d", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $problems = [];
            if (abs((float) $r['qty_allocated'] - (float) $r['alloc_sum']) > 0.0001) {
                $problems[] = 'qty_allocated ' . CJS_Inv_DB::fmt($r['qty_allocated']) . ' != allocations ' . CJS_Inv_DB::fmt($r['alloc_sum']);
            }
            if ((float) $r['qty_allocated'] > (float) $r['qty'] + 0.0001) {
                $problems[] = 'over-allocated';
            }
            if ($problems) {
                $issues[] = ['type' => 'demand', 'id' => (int) $r['id'], 'problems' => $problems];
            }
        }
        $piece_rows = $wpdb->get_results("SELECT b.id,
                b.qty_on_hand,
                (SELECT COUNT(*) FROM " . CJS_Inv_DB::t('pieces') . " p WHERE p.batch_id = b.id AND p.status IN ('in','reserved')) AS pieces_here
            FROM $b b INNER JOIN $i i ON i.id = b.item_id WHERE i.per_piece = 1", ARRAY_A) ?: [];
        foreach ($piece_rows as $r) {
            if (abs((float) $r['qty_on_hand'] - (float) $r['pieces_here']) > 0.0001) {
                $issues[] = ['type' => 'pieces', 'id' => (int) $r['id'], 'problems' => ['batch on_hand ' . CJS_Inv_DB::fmt($r['qty_on_hand']) . ' != pieces on shelf ' . (int) $r['pieces_here']]];
            }
        }
        $p = CJS_Inv_DB::t('pieces');
        $piece_rows = $wpdb->get_results("SELECT p.id, p.status, a.state AS alloc_state FROM $p p LEFT JOIN $a a ON a.id = p.allocation_id
            WHERE (p.status IN ('reserved','out') AND (a.id IS NULL OR a.state <> p.status OR a.piece_id <> p.id))
               OR (p.status NOT IN ('reserved','out') AND a.state IN ('reserved','out'))", ARRAY_A) ?: [];
        foreach ($piece_rows as $r) {
            $issues[] = ['type' => 'piece', 'id' => (int) $r['id'], 'problems' => ['piece is ' . $r['status'] . ' but its allocation is ' . ($r['alloc_state'] ?: 'missing')]];
        }
        $piece_rows = $wpdb->get_results("SELECT a.id, a.state, p.status FROM $a a INNER JOIN $p p ON p.id = a.piece_id
            WHERE a.state IN ('reserved','out') AND (p.status <> a.state OR p.allocation_id IS NULL OR p.allocation_id <> a.id)", ARRAY_A) ?: [];
        foreach ($piece_rows as $r) {
            $issues[] = ['type' => 'allocation', 'id' => (int) $r['id'], 'problems' => ['allocation is ' . $r['state'] . ' but its piece is ' . $r['status']]];
        }
        $unit_rows = $wpdb->get_results("SELECT d.id FROM $d d INNER JOIN $i i ON i.id = d.item_id WHERE d.state = 'open' AND d.unit_id <> i.unit_id", ARRAY_A) ?: [];
        foreach ($unit_rows as $r) {
            $issues[] = ['type' => 'demand', 'id' => (int) $r['id'], 'problems' => ['line unit differs from the item unit']];
        }
        foreach (CJS_Inv_Metals::not_in_grams_items() as $r) {
            $issues[] = ['type' => 'item', 'id' => (int) $r['id'], 'problems' => [sprintf('metal item is kept in %s, not in grams; archive it and create it again in grams', CJS_Inv_Catalog::unit_label((int) $r['unit_id']) ?: 'another unit')]];
        }
        $model_rows = $wpdb->get_results("SELECT a.id, i.id AS item_id FROM $a a INNER JOIN $b b ON b.id = a.batch_id INNER JOIN $i i ON i.id = b.item_id INNER JOIN $d d ON d.id = a.demand_id WHERE a.state = 'reserved' AND d.state = 'cancelled'", ARRAY_A) ?: [];
        foreach ($model_rows as $r) {
            $issues[] = ['type' => 'allocation', 'id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'problems' => ['stock held for a cancelled line']];
        }
        $model_rows = $wpdb->get_results("SELECT p.id, p.item_id FROM $p p LEFT JOIN $a a ON a.id = p.allocation_id WHERE p.status = 'used' AND (a.id IS NULL OR a.state <> 'used' OR a.piece_id <> p.id)", ARRAY_A) ?: [];
        foreach ($model_rows as $r) {
            $issues[] = ['type' => 'piece', 'id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'problems' => ['piece is used but has no used allocation']];
        }
        return [
            'checked' => [
                'batches' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $b"),
                'items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $i"),
                'demands' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $d"),
            ],
            'issue_count' => count($issues),
            'issues' => array_slice($issues, 0, $limit),
        ];
    }
}

class CJS_Inv_Exception extends RuntimeException {

    public $error;

    public function __construct(WP_Error $error) {
        $this->error = $error;
        parent::__construct($error->get_error_message());
    }
}
