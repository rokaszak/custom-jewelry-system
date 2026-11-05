<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_API {

    const NS = 'cjs/v2';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function can() {
        return current_user_can('manage_options');
    }

    private static function route($path, $methods, $callback, array $args = []) {
        register_rest_route(self::NS, $path, [
            'methods' => $methods,
            'callback' => function (WP_REST_Request $request) use ($callback) {
                return self::run(function () use ($callback, $request) {
                    return $callback($request);
                });
            },
            'permission_callback' => [__CLASS__, 'can'],
            'args' => $args,
        ]);
    }

    public static function run(callable $fn) {
        try {
            $result = $fn();
        } catch (CJS_Inv_Exception $e) {
            $result = $e->error;
        } catch (Throwable $e) {
            error_log('CJS API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $result = new WP_Error('cjs_error', $e->getMessage(), ['status' => 500]);
        }
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? $data['status'] : 400;
            return new WP_REST_Response([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data' => is_array($data) ? $data : null,
            ], $status);
        }
        return rest_ensure_response($result);
    }

    private static function p(WP_REST_Request $r) {
        $json = $r->get_json_params();
        return is_array($json) ? array_merge($r->get_query_params(), $r->get_body_params(), $json) : array_merge($r->get_query_params(), $r->get_body_params());
    }

    private static function arr($value) {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }
        return array_values((array) $value);
    }

    public static function routes() {
        self::route('/bootstrap', 'GET', [__CLASS__, 'bootstrap']);

        self::route('/orders', 'GET', function ($r) {
            $p = self::p($r);
            return CJS_Orders::query([
                'page' => $p['page'] ?? 1,
                'per_page' => $p['per_page'] ?? 20,
                'search' => $p['search'] ?? '',
                'status' => self::arr($p['status'] ?? []),
                'order_model' => $p['order_model'] ?? '',
                'order_production' => $p['order_production'] ?? '',
                'order_printing' => $p['order_printing'] ?? '',
                'order_type' => $p['order_type'] ?? '',
                'inventory' => $p['inventory'] ?? '',
                'stock_order_status' => self::arr($p['stock_order_status'] ?? []),
                'size_kit' => $p['size_kit'] ?? '',
                'wc_status' => self::arr($p['wc_status'] ?? []),
                'manufacture_from' => $p['manufacture_from'] ?? '',
                'manufacture_to' => $p['manufacture_to'] ?? '',
                'finish_from' => $p['finish_from'] ?? '',
                'finish_to' => $p['finish_to'] ?? '',
                'deliver_from' => $p['deliver_from'] ?? '',
                'deliver_to' => $p['deliver_to'] ?? '',
                'locate' => absint($p['locate'] ?? 0),
            ]);
        });
        self::route('/orders/bulk', 'POST', function ($r) {
            $p = self::p($r);
            return CJS_Orders::bulk_update(self::arr($p['ids'] ?? []), (array) ($p['fields'] ?? []));
        });
        self::route('/orders/search', 'GET', function ($r) {
            return CJS_Orders::search((string) $r->get_param('term'));
        });
        self::route('/orders/(?P<id>\d+)', 'GET', function ($r) {
            $panel = CJS_Orders::panel((int) $r['id']);
            return $panel ?: new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'), ['status' => 404]);
        });
        self::route('/orders/(?P<id>\d+)/summary', 'GET', function ($r) {
            $row = CJS_Orders::single((int) $r['id']);
            return $row ?: new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'), ['status' => 404]);
        });
        self::route('/orders/(?P<id>\d+)/field', 'POST', function ($r) {
            $p = self::p($r);
            return CJS_Orders::update_field((int) $r['id'], (string) ($p['field'] ?? ''), $p['value'] ?? '');
        });
        self::route('/orders/(?P<id>\d+)/fields', 'POST', function ($r) {
            $p = self::p($r);
            return CJS_Orders::update_fields((int) $r['id'], (array) ($p['fields'] ?? []));
        });
        self::route('/orders/(?P<id>\d+)/complete', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Orders::complete((int) $r['id'], ['confirmed' => !empty($p['confirmed']) && $p['confirmed'] !== 'false', 'decisions' => (array) ($p['decisions'] ?? [])]);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/orders/(?P<id>\d+)/variant-text', 'GET', function ($r) {
            return ['text' => CJS_Orders::variant_text((int) $r['id'])];
        });
        self::route('/orders/(?P<id>\d+)/demands', 'POST', function ($r) {
            $p = self::p($r);
            $id = isset($p['metal']) && is_string($p['metal']) && $p['metal'] !== ''
                ? CJS_Inv_Demands::add_metal_manual((int) $r['id'], absint($p['order_item_id'] ?? 0) ?: null, absint($p['category_id'] ?? 0), $p['metal'], $p['praba'] ?? null, $p['qty'] ?? 0)
                : CJS_Inv_Demands::add_manual((int) $r['id'], absint($p['order_item_id'] ?? 0) ?: null, absint($p['item_id'] ?? 0), $p['qty'] ?? 1, (string) ($p['note'] ?? ''), array_filter(['piece_id' => absint($p['piece_id'] ?? 0), 'batch_id' => absint($p['batch_id'] ?? 0)]) + (isset($p['piece_ids']) ? ['piece_ids' => self::arr($p['piece_ids'])] : []));
            return is_wp_error($id) ? $id : ['demand_id' => $id, 'demands' => CJS_Inv_Demands::for_order((int) $r['id']), 'returnable_history' => CJS_Inv_Demands::returnable_history((int) $r['id'])];
        });
        self::route('/orders/(?P<id>\d+)/demands', 'GET', function ($r) {
            return ['demands' => CJS_Inv_Demands::for_order((int) $r['id']), 'returnable_history' => CJS_Inv_Demands::returnable_history((int) $r['id']), 'warnings' => CJS_Inv_Demands::warnings_for_order((int) $r['id'])];
        });

        self::route('/demands/(?P<id>\d+)', 'PATCH', function ($r) {
            $p = self::p($r);
            $data = [];
            foreach (['qty', 'note', 'item_id'] as $k) {
                if (array_key_exists($k, $p)) {
                    $data[$k] = $p[$k];
                }
            }
            $result = CJS_Inv_Demands::update_demand((int) $r['id'], $data);
            return is_wp_error($result) ? $result : ['ok' => true, 'demand_id' => $result];
        });
        self::route('/demands/(?P<id>\d+)/note', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Inv_Demands::set_note((int) $r['id'], (string) ($p['note'] ?? ''));
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/demands/(?P<id>\d+)', 'DELETE', function ($r) {
            $result = CJS_Inv_Demands::remove_demand((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/demands/(?P<id>\d+)/assign', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Inv_Demands::assign((int) $r['id'], [
                'piece_id' => absint($p['piece_id'] ?? 0),
                'batch_id' => absint($p['batch_id'] ?? 0),
                'qty' => isset($p['qty']) ? (float) $p['qty'] : 0,
                'replace_allocation_id' => absint($p['replace_allocation_id'] ?? 0),
            ]);
            return is_wp_error($result) ? $result : ['ok' => true, 'allocated' => $result];
        });
        self::route('/demands/(?P<id>\d+)/options', 'GET', function ($r) {
            return self::assign_options((int) $r['id']);
        });
        self::route('/demands/(?P<id>\d+)/unlink-stock-order', 'POST', function ($r) {
            $result = CJS_Inv_Stock_Orders::unlink_demand((int) $r['id'], absint(self::p($r)['line_id'] ?? 0));
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/allocations/(?P<id>\d+)/return', 'POST', function ($r) {
            $alloc = CJS_Inv_Stock::allocation((int) $r['id']);
            $result = CJS_Inv_Stock::return_allocation((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true, 'returned' => true, 'ask_remove' => $alloc ? self::open_demand((int) $alloc['demand_id']) : false];
        });
        self::route('/demands/(?P<id>\d+)/pieces', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Inv_Demands::set_pieces((int) $r['id'], $p['qty'] ?? 0, isset($p['piece_ids']) ? self::arr($p['piece_ids']) : []);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/demands/(?P<id>\d+)/swap-piece', 'POST', function ($r) {
            $result = CJS_Inv_Demands::swap_piece((int) $r['id'], absint(self::p($r)['piece_id'] ?? 0));
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/items/(?P<id>\d+)/piece-options', 'GET', function ($r) {
            return CJS_Inv_Stock::piece_options((int) $r['id']);
        });
        self::route('/demands/(?P<id>\d+)/remove-returned', 'POST', function ($r) {
            $result = CJS_Inv_Demands::remove_returned((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/inventory/transition/preview', 'POST', function ($r) {
            $p = self::p($r);
            $ids = isset($p['order_ids']) ? self::arr($p['order_ids']) : [absint($p['order_id'] ?? 0)];
            $target = isset($p['target']) && $p['target'] !== '' ? (string) $p['target'] : null;
            return CJS_Inv_Flow::preview($ids, $target);
        });
        self::route('/inventory/transition/apply', 'POST', function ($r) {
            $p = self::p($r);
            $target = (string) ($p['target'] ?? '');
            if ($target === '') {
                return new WP_Error('cjs_invalid', __('Choose a status.', 'custom-jewelry-system'));
            }
            if (isset($p['order_ids'])) {
                $ids = self::arr($p['order_ids']);
                $decisions = (array) ($p['decisions'] ?? []);
            } else {
                $ids = [absint($p['order_id'] ?? 0)];
                $decisions = [$ids[0] => (array) ($p['decisions'] ?? [])];
            }
            $woo_status = '';
            if (isset($p['woo_status']) && $p['woo_status'] !== '' && !CJS_Inv_Flow::is_woo_target($target)) {
                $woo_status = CJS_Woo_Sync::woo_status_value($p['woo_status']);
                if ($woo_status === null) {
                    return new WP_Error('cjs_invalid', __('Unknown WooCommerce status. Use the cancel targets for cancelled, refunded or failed.', 'custom-jewelry-system'));
                }
            }
            $lock = CJS_Woo_Sync::check_apply_lock($ids, $target, $woo_status);
            if (is_wp_error($lock)) {
                return $lock;
            }
            $perform = $p['perform'] ?? true;
            $result = CJS_Inv_Flow::apply($ids, $target, $decisions, ['perform' => $perform]);
            if (!is_wp_error($result) && $woo_status !== '') {
                $result['woo'] = CJS_Woo_Sync::set_woo_status($ids, $woo_status, !empty($perform) && $perform !== 'false');
            }
            return $result;
        });
        self::route('/allocations/(?P<id>\d+)/send', 'POST', function ($r) {
            $result = CJS_Inv_Stock::send_allocation((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/batches/(?P<id>\d+)/cost', 'POST', function ($r) {
            $p = self::p($r);
            if (!array_key_exists('unit_cost', $p)) {
                return new WP_Error('cjs_invalid', __('Enter a unit cost.', 'custom-jewelry-system'));
            }
            $result = CJS_Inv_Stock::set_batch_cost((int) $r['id'], $p['unit_cost']);
            if (is_wp_error($result)) {
                return $result;
            }
            $batch = CJS_Inv_Stock::batch((int) $r['id']);
            return $result + ['item' => $batch ? CJS_Inv_Stock::item_detail((int) $batch['item_id']) : null];
        });
        self::route('/allocations/(?P<id>\d+)/unassign', 'POST', function ($r) {
            $result = CJS_Inv_Stock::unassign_allocation((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true] + $result;
        });
        self::route('/allocations/(?P<id>\d+)/lost', 'POST', function ($r) {
            $result = CJS_Inv_Stock::mark_lost((int) $r['id'], (string) ($r->get_param('note') ?? ''));
            return is_wp_error($result) ? $result : ['ok' => true];
        });

        self::route('/orders/(?P<id>\d+)/files', 'GET', function ($r) {
            return CJS_Files::list_for_order((int) $r['id']);
        });
        self::route('/orders/(?P<id>\d+)/files', 'POST', function ($r) {
            return CJS_Files::upload((int) $r['id'], $r);
        });
        self::route('/files/(?P<id>\d+)', 'DELETE', function ($r) {
            return CJS_Files::delete((int) $r['id']);
        });
        self::route('/files/(?P<id>\d+)', 'PATCH', function ($r) {
            return CJS_Files::update((int) $r['id'], self::p($r));
        });

        self::route('/catalog', 'GET', function ($r) {
            return CJS_Inv_Catalog::schema((bool) $r->get_param('archived'));
        });
        self::route('/categories', 'POST', function ($r) {
            $id = CJS_Inv_Catalog::save_category(self::p($r));
            return is_wp_error($id) ? $id : ['id' => $id, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/(?P<id>\d+)', 'PATCH', function ($r) {
            $data = self::p($r);
            $id = CJS_Inv_Catalog::save_category(array_merge($data, ['id' => (int) $r['id']]));
            if (!is_wp_error($id) && array_key_exists('auto_assign', $data)) {
                try {
                    global $wpdb;
                    $item_ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('items') . ' WHERE category_id = %d AND archived = 0', (int) $id)) ?: []);
                    if ($item_ids) {
                        CJS_Inv_Demands::reserve_pending($item_ids);
                    }
                } catch (Throwable $e) {
                    error_log('CJS category queue run failed: ' . $e->getMessage());
                }
            }
            return is_wp_error($id) ? $id : ['id' => $id, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/(?P<id>\d+)/checkout-offer', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Size_Kit::save_offer((int) $r['id'], $p['checkout_offer'] ?? $p);
            return is_wp_error($result) ? $result : ['id' => (int) $r['id'], 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/(?P<id>\d+)/archive', 'POST', function ($r) {
            CJS_Inv_Catalog::archive_category((int) $r['id'], (bool) ($r->get_param('archived') ?? true));
            return ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/reorder', 'POST', function ($r) {
            CJS_Inv_Catalog::reorder('categories', self::arr(self::p($r)['ids'] ?? []));
            return ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/(?P<id>\d+)/fields', 'POST', function ($r) {
            $id = CJS_Inv_Catalog::save_field((int) $r['id'], self::p($r));
            return is_wp_error($id) ? $id : ['id' => $id, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/categories/(?P<id>\d+)/fields/reorder', 'POST', function ($r) {
            CJS_Inv_Catalog::reorder('fields', self::arr(self::p($r)['ids'] ?? []), 'category_id', (int) $r['id']);
            $cat = (int) $r['id'];
            CJS_Inv_Catalog::rebuild_category_names($cat);
            return ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/fields/(?P<id>\d+)', 'PATCH', function ($r) {
            $field = CJS_Inv_Catalog::field((int) $r['id']);
            if (!$field) {
                return new WP_Error('cjs_not_found', __('Field not found.', 'custom-jewelry-system'), ['status' => 404]);
            }
            $id = CJS_Inv_Catalog::save_field((int) $field['category_id'], array_merge(self::p($r), ['id' => (int) $r['id']]));
            return is_wp_error($id) ? $id : ['id' => $id, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/fields/(?P<id>\d+)', 'DELETE', function ($r) {
            $result = CJS_Inv_Catalog::delete_field((int) $r['id']);
            return is_wp_error($result) ? $result : ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/fields/(?P<id>\d+)/choices', 'POST', function ($r) {
            $id = CJS_Inv_Catalog::add_choice((int) $r['id'], (string) (self::p($r)['label'] ?? ''));
            return is_wp_error($id) ? $id : ['id' => $id, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/fields/(?P<id>\d+)/choices/reorder', 'POST', function ($r) {
            CJS_Inv_Catalog::reorder('field_choices', self::arr(self::p($r)['ids'] ?? []), 'field_id', (int) $r['id']);
            return ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/choices/(?P<id>\d+)', 'PATCH', function ($r) {
            $id = CJS_Inv_Catalog::update_choice((int) $r['id'], self::p($r));
            return is_wp_error($id) ? $id : ['catalog' => CJS_Inv_Catalog::schema(true)];
        });
        self::route('/choices/(?P<id>\d+)', 'DELETE', function ($r) {
            $result = CJS_Inv_Catalog::delete_choice((int) $r['id']);
            return ['result' => $result, 'catalog' => CJS_Inv_Catalog::schema(true)];
        });

        self::route('/categories/(?P<id>\d+)/number-facets', 'GET', function ($r) {
            return ['facets' => (object) CJS_Inv_Catalog::number_facets((int) $r['id'])];
        });
        self::route('/items/suggestions', 'GET', function ($r) {
            $p = self::p($r);
            $filters = [];
            if (!empty($p['filters'])) {
                $decoded = is_string($p['filters']) ? json_decode(wp_unslash($p['filters']), true) : $p['filters'];
                $filters = is_array($decoded) ? $decoded : [];
            }
            return CJS_Inv_Catalog::suggestions([
                'filters' => $filters,
                'offset' => absint($p['offset'] ?? 0),
                'search' => (string) ($p['search'] ?? ''),
                'category_id' => absint($p['category_id'] ?? 0),
                'per_piece' => $p['per_piece'] ?? '',
                'returnable' => $p['returnable'] ?? '',
                'in_stock' => !empty($p['in_stock']),
                'per_category' => absint($p['per_category'] ?? 0),
                'limit' => absint($p['limit'] ?? 0),
            ]);
        });
        self::route('/items', 'GET', function ($r) {
            $p = self::p($r);
            $filters = [];
            if (!empty($p['filters'])) {
                $decoded = is_string($p['filters']) ? json_decode(wp_unslash($p['filters']), true) : $p['filters'];
                $filters = is_array($decoded) ? $decoded : [];
            }
            return CJS_Inv_Catalog::list_items([
                'category_id' => absint($p['category_id'] ?? 0),
                'search' => (string) ($p['search'] ?? ''),
                'archived' => (string) ($p['archived'] ?? 'active'),
                'stock' => (string) ($p['stock'] ?? ''),
                'piece_state' => self::arr($p['piece_state'] ?? []),
                'filters' => $filters,
                'ids' => self::arr($p['ids'] ?? []),
                'sort' => (string) ($p['sort'] ?? 'name'),
                'dir' => (string) ($p['dir'] ?? 'asc'),
                'page' => $p['page'] ?? 1,
                'per_page' => $p['per_page'] ?? 50,
            ]);
        });
        self::route('/items', 'POST', function ($r) {
            CJS_Inv_Catalog::take_restored();
            $id = CJS_Inv_Catalog::save_item(self::p($r));
            if (is_wp_error($id)) {
                return $id;
            }
            $restored = CJS_Inv_Catalog::take_restored();
            $out = ['id' => $id, 'item' => CJS_Inv_Stock::item_detail($id)];
            if ($restored && (int) $restored['id'] === (int) $id) {
                $out['restored'] = true;
                $out['notice'] = $restored['notice'];
            }
            return $out;
        });
        self::route('/items/(?P<id>\d+)', 'GET', function ($r) {
            $item = CJS_Inv_Stock::item_detail((int) $r['id']);
            return $item ?: new WP_Error('cjs_not_found', __('Item not found.', 'custom-jewelry-system'), ['status' => 404]);
        });
        self::route('/items/(?P<id>\d+)', 'PATCH', function ($r) {
            $id = CJS_Inv_Catalog::save_item(array_merge(self::p($r), ['id' => (int) $r['id']]));
            return is_wp_error($id) ? $id : ['id' => $id, 'item' => CJS_Inv_Stock::item_detail($id)];
        });
        self::route('/items/(?P<id>\d+)', 'DELETE', function ($r) {
            return ['result' => CJS_Inv_Catalog::delete_item((int) $r['id'])];
        });
        self::route('/items/(?P<id>\d+)/archive', 'POST', function ($r) {
            CJS_Inv_Catalog::set_archived((int) $r['id'], (bool) ($r->get_param('archived') ?? true));
            return ['item' => CJS_Inv_Stock::item_detail((int) $r['id'])];
        });
        self::route('/items/(?P<id>\d+)/next-identifiers', 'GET', function ($r) {
            $count = min(500, max(1, absint($r->get_param('count') ?? 1)));
            $out = [];
            for ($k = 0; $k < $count; $k++) {
                $next = CJS_Inv_Catalog::next_identifier((int) $r['id'], $k);
                if ($next === null) {
                    break;
                }
                $out[] = $next;
            }
            return ['identifiers' => $out];
        });
        self::route('/pieces/(?P<id>\d+)', 'PATCH', function ($r) {
            return self::update_piece((int) $r['id'], self::p($r));
        });

        self::route('/movements', 'GET', function ($r) {
            $p = self::p($r);
            return CJS_Inv_Stock::movements([
                'item_id' => $p['item_id'] ?? 0,
                'order_id' => $p['order_id'] ?? 0,
                'batch_id' => $p['batch_id'] ?? 0,
                'piece_id' => $p['piece_id'] ?? 0,
                'document_id' => $p['document_id'] ?? 0,
                'stock_order_id' => $p['stock_order_id'] ?? 0,
                'types' => self::arr($p['types'] ?? []),
                'page' => $p['page'] ?? 1,
                'per_page' => $p['per_page'] ?? 100,
            ]);
        });
        self::route('/documents', 'GET', function ($r) {
            return CJS_Inv_Stock::documents(self::p($r));
        });
        self::route('/receipts', 'POST', function ($r) {
            $p = self::p($r);
            $lines = [];
            foreach ((array) ($p['lines'] ?? []) as $line) {
                $lines[] = [
                    'item_id' => absint($line['item_id'] ?? 0),
                    'qty' => $line['qty'] ?? 0,
                    'unit_cost' => isset($line['unit_cost']) && $line['unit_cost'] !== '' ? $line['unit_cost'] : null,
                    'identifiers' => (array) ($line['identifiers'] ?? []),
                    'note' => (string) ($line['note'] ?? ''),
                ];
            }
            if (!$lines) {
                return new WP_Error('cjs_invalid', __('Add at least one line.', 'custom-jewelry-system'));
            }
            $result = CJS_Inv_Stock::receive($lines, ['note' => (string) ($p['note'] ?? '')]);
            CJS_Logger::log('Stock received', 'success', 'inventory', $result['document_id'], ['lines' => count($lines)]);
            return $result;
        });
        self::route('/counts', 'POST', function ($r) {
            $p = self::p($r);
            $lines = (array) ($p['lines'] ?? []);
            if (!$lines) {
                return new WP_Error('cjs_invalid', __('Add at least one item to count.', 'custom-jewelry-system'));
            }
            $result = CJS_Inv_Stock::count($lines, (string) ($p['note'] ?? ''));
            CJS_Logger::log('Stock count applied', 'info', 'inventory', $result['document_id'], ['lines' => count($lines)]);
            return $result;
        });

        self::route('/required', 'GET', function ($r) {
            $p = self::p($r);
            return [
                'groups' => CJS_Inv_Demands::required_list([
                    'category_id' => absint($p['category_id'] ?? 0),
                    'search' => (string) ($p['search'] ?? ''),
                    'ordered' => (string) ($p['ordered'] ?? ''),
                ]),
            ];
        });

        self::route('/stock-orders', 'GET', function ($r) {
            $p = self::p($r);
            return CJS_Inv_Stock_Orders::list([
                'status' => self::arr($p['status'] ?? []),
                'search' => (string) ($p['search'] ?? ''),
                'from' => (string) ($p['from'] ?? ''),
                'to' => (string) ($p['to'] ?? ''),
                'open' => (string) ($p['open'] ?? ''),
                'page' => $p['page'] ?? 1,
                'per_page' => $p['per_page'] ?? 25,
            ]);
        });
        self::route('/stock-orders', 'POST', function ($r) {
            $id = CJS_Inv_Stock_Orders::save(self::p($r));
            return is_wp_error($id) ? $id : CJS_Inv_Stock_Orders::get($id);
        });
        self::route('/stock-orders/from-demands', 'POST', function ($r) {
            $p = self::p($r);
            $header = [
                'stock_order_id' => absint($p['stock_order_id'] ?? 0),
                'number' => (string) ($p['number'] ?? ''),
                'order_date' => (string) ($p['order_date'] ?? ''),
                'status' => (string) ($p['status'] ?? ''),
                'supplier' => (string) ($p['supplier'] ?? ''),
            ];
            if (isset($p['rows']) && is_array($p['rows'])) {
                $id = CJS_Inv_Stock_Orders::create_from_rows(array_values($p['rows']), $header);
            } else {
                $id = CJS_Inv_Stock_Orders::create_from_demands(self::arr($p['demand_ids'] ?? []), $header);
            }
            $skipped = CJS_Inv_Stock_Orders::take_skipped();
            return is_wp_error($id) ? $id : array_merge(CJS_Inv_Stock_Orders::get($id) ?: [], ['skipped' => $skipped]);
        });
        self::route('/stock-orders/(?P<id>\d+)', 'GET', function ($r) {
            $so = CJS_Inv_Stock_Orders::get((int) $r['id']);
            return $so ?: new WP_Error('cjs_not_found', __('Stock order not found.', 'custom-jewelry-system'), ['status' => 404]);
        });
        self::route('/stock-orders/(?P<id>\d+)', 'PATCH', function ($r) {
            $id = CJS_Inv_Stock_Orders::save(array_merge(self::p($r), ['id' => (int) $r['id']]));
            return is_wp_error($id) ? $id : CJS_Inv_Stock_Orders::get($id);
        });
        self::route('/stock-orders/(?P<id>\d+)', 'DELETE', function ($r) {
            $result = CJS_Inv_Stock_Orders::delete((int) $r['id']);
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/stock-orders/(?P<id>\d+)/receive-all', 'POST', function ($r) {
            $result = CJS_Inv_Stock_Orders::receive_all((int) $r['id']);
            return is_wp_error($result) ? $result : CJS_Inv_Stock_Orders::get((int) $r['id']);
        });
        self::route('/stock-order-lines/(?P<id>\d+)/cart', 'POST', function ($r) {
            $result = CJS_Inv_Stock_Orders::set_in_cart((int) $r['id'], (bool) (self::p($r)['in_cart'] ?? false));
            return is_wp_error($result) ? $result : ['ok' => true];
        });
        self::route('/stock-order-lines/(?P<id>\d+)/receive', 'POST', function ($r) {
            $p = self::p($r);
            $line = CJS_Inv_Stock_Orders::line((int) $r['id']);
            $result = CJS_Inv_Stock_Orders::receive_line((int) $r['id'], $p['qty'] ?? null, $p['unit_cost'] ?? null, (array) ($p['identifiers'] ?? []));
            if (is_wp_error($result)) {
                return $result;
            }
            return $line ? CJS_Inv_Stock_Orders::get((int) $line['stock_order_id']) : ['ok' => true];
        });
        self::route('/stock-orders/from-order/(?P<order_id>\d+)', 'GET', function ($r) {
            return ['demand_ids' => CJS_Inv_Stock_Orders::missing_demand_ids_for_order((int) $r['order_id'])];
        });
        self::route('/stock-orders/from-order/(?P<order_id>\d+)', 'POST', function ($r) {
            $p = self::p($r);
            $id = CJS_Inv_Stock_Orders::create_from_order((int) $r['order_id'], [
                'stock_order_id' => absint($p['stock_order_id'] ?? 0),
                'number' => (string) ($p['number'] ?? ''),
                'order_date' => (string) ($p['order_date'] ?? ''),
                'supplier' => (string) ($p['supplier'] ?? ''),
                'ordered' => !empty($p['ordered']),
            ]);
            $skipped = CJS_Inv_Stock_Orders::take_skipped();
            return is_wp_error($id) ? $id : array_merge(CJS_Inv_Stock_Orders::get($id) ?: [], ['skipped' => $skipped]);
        });
        self::route('/stock-orders/(?P<id>\d+)/lines', 'POST', function ($r) {
            $result = CJS_Inv_Stock_Orders::add_line((int) $r['id'], self::p($r));
            return is_wp_error($result) ? $result : CJS_Inv_Stock_Orders::get((int) $r['id']);
        });
        self::route('/stock-orders/(?P<id>\d+)/bulk', 'POST', function ($r) {
            $p = self::p($r);
            $result = CJS_Inv_Stock_Orders::bulk((int) $r['id'], (string) ($p['action'] ?? ''), self::arr($p['line_ids'] ?? []));
            return is_wp_error($result) ? $result : array_merge(CJS_Inv_Stock_Orders::get((int) $r['id']) ?: [], ['bulk' => $result]);
        });
        self::route('/stock-order-lines/(?P<id>\d+)', 'PATCH', function ($r) {
            $result = CJS_Inv_Stock_Orders::update_line((int) $r['id'], self::p($r));
            return is_wp_error($result) ? $result : CJS_Inv_Stock_Orders::get((int) $result);
        });
        self::route('/stock-order-lines/(?P<id>\d+)', 'DELETE', function ($r) {
            $result = CJS_Inv_Stock_Orders::delete_line((int) $r['id']);
            return is_wp_error($result) ? $result : CJS_Inv_Stock_Orders::get((int) $result);
        });
        self::route('/stock-order-lines/(?P<id>\d+)/ordered', 'POST', function ($r) {
            $line = CJS_Inv_Stock_Orders::line((int) $r['id']);
            $value = self::p($r)['ordered'] ?? true;
            $result = CJS_Inv_Stock_Orders::set_ordered((int) $r['id'], !empty($value) && $value !== 'false');
            return is_wp_error($result) ? $result : CJS_Inv_Stock_Orders::get((int) $line['stock_order_id']);
        });

        self::route('/discrepancies', 'GET', function ($r) {
            return CJS_Inv_Discrepancies::list(self::p($r));
        });
        self::route('/discrepancies/orders', 'GET', function ($r) {
            return CJS_Woo_Sync::discrepancy_orders(self::p($r));
        });
        self::route('/discrepancies/(?P<id>\d+)/resolve', 'POST', function ($r) {
            CJS_Inv_Discrepancies::resolve((int) $r['id'], (bool) (self::p($r)['resolved'] ?? true));
            return ['ok' => true, 'open_count' => CJS_Inv_Discrepancies::open_count()];
        });
        self::route('/discrepancies/check', 'POST', function ($r) {
            return CJS_Inv_Discrepancies::run_integrity_check();
        });

        self::route('/timeline', 'GET', function ($r) {
            return [
                'orders' => CJS_Orders::timeline(),
                'statuses' => CJS_Statuses::label_list(),
                'status_keys' => CJS_Inv_Flow::STATUS_KEYS,
                'today' => current_time('Y-m-d'),
            ];
        });
        self::route('/calendar/config', 'GET', function ($r) {
            return [
                'settings' => CJS_Scheduler::get_settings(1),
                'types' => get_option('cjs_interval_types', []),
                'now' => current_time('Y-m-d\TH:i'),
            ];
        });

        self::route('/settings', 'GET', function ($r) {
            return CJS_Settings::all();
        });
        self::route('/settings/list/(?P<type>[a-z_]+)', 'POST', function ($r) {
            return CJS_Settings::save_list((string) $r['type'], (array) (self::p($r)['values'] ?? []));
        });
        self::route('/settings/stock-order-statuses', 'POST', function ($r) {
            $result = CJS_Inv_Stock_Orders::save_statuses((array) (self::p($r)['statuses'] ?? []));
            return is_wp_error($result) ? $result : CJS_Settings::all();
        });
        self::route('/settings/size-kit', 'POST', function ($r) {
            return CJS_Settings::save_size_kit(self::p($r));
        });
        self::route('/settings/statuses', 'POST', function ($r) {
            return CJS_Settings::save_statuses(self::p($r));
        });
        self::route('/settings/order-types', 'POST', function ($r) {
            return CJS_Settings::save_order_type_map(self::p($r));
        });
        self::route('/settings/deadlines', 'POST', function ($r) {
            return CJS_Settings::save_deadlines(self::p($r));
        });
        self::route('/log', 'GET', function ($r) {
            return CJS_Settings::log(self::p($r));
        });
        self::route('/debug', 'GET', function ($r) {
            return CJS_Debug::report();
        });
        self::route('/debug/rerun-migration', 'POST', function ($r) {
            CJS_Debug::rerun();
            return CJS_Debug::report();
        });
    }

    public static function bootstrap() {
        $user = wp_get_current_user();
        return [
            'version' => CJS_VERSION,
            'user' => ['id' => $user->ID, 'name' => $user->display_name],
            'catalog' => CJS_Inv_Catalog::schema(),
            'manufacturing_statuses' => CJS_Statuses::label_list(),
            'statuses' => CJS_Inv_Flow::statuses(),
            'order_types' => array_values(CJS_Order_Extension::get_ordered_options('order_types')),
            'stock_order_statuses' => CJS_Settings::statuses_list(),
            'counts' => [
                'required' => CJS_Inv_Demands::missing_count(),
                'discrepancies' => CJS_Inv_Discrepancies::open_count(),
            ],
            'deadlines' => CJS_Deadlines::get_settings(),
            'upload_limits' => CJS_File_Handler::get_upload_limits(),
            'today' => current_time('Y-m-d'),
            'urls' => [
                'admin' => admin_url(),
                'orders' => admin_url('admin.php?page=cjs-orders-list'),
            ],
            'size_kit_items' => CJS_Size_Kit::kit_offer()['items'],
            'wc_statuses' => CJS_Orders::wc_statuses(),
            'currency' => function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '€',
        ];
    }

    private static function open_demand(int $demand_id) {
        $demand = CJS_Inv_Demands::row($demand_id);
        return $demand && $demand['state'] === 'open' && CJS_Inv_Flow::rules((int) $demand['item_id'])['returnable'];
    }

    public static function assign_options(int $demand_id) {
        global $wpdb;
        $demand = CJS_Inv_Demands::row($demand_id);
        if (!$demand) {
            return new WP_Error('cjs_not_found', __('Line not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $item = CJS_Inv_Catalog::item_row((int) $demand['item_id']);
        $out = ['per_piece' => (bool) $item['per_piece'], 'pieces' => [], 'batches' => []];
        if ((int) $item['per_piece'] === 1) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, identifier, note, batch_id FROM ' . CJS_Inv_DB::t('pieces') . " WHERE item_id = %d AND status = 'in' ORDER BY LENGTH(identifier), identifier, id LIMIT 500", (int) $item['id']), ARRAY_A) ?: [];
            $out['pieces'] = array_map(function ($p) {
                return ['id' => (int) $p['id'], 'identifier' => $p['identifier'], 'note' => $p['note'], 'batch_id' => (int) $p['batch_id']];
            }, $rows);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CJS_Inv_DB::t('batches') . ' WHERE item_id = %d AND qty_on_hand - qty_reserved > 0 ORDER BY received_at ASC, id ASC', (int) $item['id']), ARRAY_A) ?: [];
            $out['batches'] = array_map(function ($b) {
                return [
                    'id' => (int) $b['id'],
                    'free' => (float) $b['qty_on_hand'] - (float) $b['qty_reserved'],
                    'unit_cost' => $b['unit_cost'] !== null ? (float) $b['unit_cost'] : null,
                    'received_at' => $b['received_at'],
                ];
            }, $rows);
        }
        return $out;
    }

    public static function update_piece(int $piece_id, array $data) {
        global $wpdb;
        $piece = CJS_Inv_Stock::piece($piece_id);
        if (!$piece) {
            return new WP_Error('cjs_not_found', __('Piece not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $row = ['updated_at' => CJS_Inv_DB::now()];
        if (array_key_exists('identifier', $data)) {
            $identifier = trim(sanitize_text_field((string) $data['identifier']));
            if ($identifier !== '') {
                $taken = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CJS_Inv_DB::t('pieces') . ' WHERE item_id = %d AND identifier = %s AND id <> %d', (int) $piece['item_id'], $identifier, $piece_id));
                if ($taken) {
                    return new WP_Error('cjs_duplicate', __('Another piece already uses this identifier.', 'custom-jewelry-system'));
                }
            }
            $row['identifier'] = $identifier !== '' ? $identifier : null;
        }
        if (array_key_exists('note', $data)) {
            $row['note'] = trim(sanitize_text_field((string) $data['note'])) ?: null;
        }
        CJS_Inv_DB::update('pieces', $row, ['id' => $piece_id]);
        if (isset($row['identifier']) && $row['identifier'] !== $piece['identifier']) {
            CJS_Inv_Stock::log('note', (int) $piece['item_id'], 0, [
                'batch_id' => (int) $piece['batch_id'],
                'piece_id' => $piece_id,
                'note' => sprintf('Identifier changed from "%s" to "%s"', (string) $piece['identifier'], (string) $row['identifier']),
            ]);
        }
        if (array_key_exists('note', $row) && (string) $row['note'] !== (string) $piece['note']) {
            CJS_Inv_Stock::log('note', (int) $piece['item_id'], 0, [
                'batch_id' => (int) $piece['batch_id'],
                'piece_id' => $piece_id,
                'note' => mb_substr($row['note'] === null ? sprintf('Note removed (was "%s")', (string) $piece['note']) : ((string) $piece['note'] === '' ? sprintf('Note set to "%s"', $row['note']) : sprintf('Note changed from "%s" to "%s"', (string) $piece['note'], $row['note'])), 0, 255),
            ]);
        }
        return ['ok' => true];
    }
}
