<?php
/**
 * Admin Stone Orders List Page - UPDATED to display new size format
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Stone_Orders {
    
    /**
     * Render the stone orders list page
     */
    public static function render_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        // Handle single stone order view
        if (isset($_GET['stone_order_id'])) {
            self::render_single_order(intval($_GET['stone_order_id']));
            return;
        }
        
        // Get stone orders
        $result = CJS_Stone_Order::get_stone_orders([
            'page' => $page,
            'per_page' => 20,
            'search' => $search,
            'status' => $status_filter
        ]);
        
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Akmenų užsakymai', 'custom-jewelry-system'); ?>
                <a href="#" class="page-title-action" id="cjs-add-new-stone-order">
                    <?php _e('Add New', 'custom-jewelry-system'); ?>
                </a>
            </h1>
            
            <div class="cjs-filters">
                <form method="get" class="cjs-search-form">
                    <input type="hidden" name="page" value="cjs-stone-orders" />
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php esc_attr_e('Search orders...', 'custom-jewelry-system'); ?>" />
                    <select name="status">
                        <option value=""><?php _e('All Statuses', 'custom-jewelry-system'); ?></option>
                        <?php
                        $statuses = get_option('cjs_stone_order_statuses', []);
                        foreach ($statuses as $key => $status) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($key),
                                selected($status_filter, $key, false),
                                esc_html($status['label'])
                            );
                        }
                        ?>
                    </select>
                    <button type="submit" class="button"><?php _e('Filter', 'custom-jewelry-system'); ?></button>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped cjs-stone-orders-table">
                <thead>
                    <tr>
                        <th><?php _e('Order Number', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Date', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Status', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Stones', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Related Orders', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Created By', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($result['orders'])) {
                        echo '<tr><td colspan="7">' . __('No stone orders found', 'custom-jewelry-system') . '</td></tr>';
                    } else {
                        foreach ($result['orders'] as $stone_order) {
                            self::render_order_row($stone_order);
                        }
                    }
                    ?>
                </tbody>
            </table>
            
            <?php
            // Pagination
            if ($result['pages'] > 1) {
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $result['pages']
                ];
                
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links($pagination_args);
                echo '</div></div>';
            }
            ?>
        </div>

        <?php CJS_Modals::render_modals(); ?>

        <?php
    }
    
    /**
     * Render stone order row with inline editing - stones now show formatted size
     */
    private static function render_order_row($stone_order) {
        $status_info = $stone_order->get_status_info();
        $stones = $stone_order->get_stones();
        $related_orders = $stone_order->get_related_orders();
        $stone_order_id = $stone_order->get('id');
        ?>
        <tr data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>">
            <td>
                <input type="text" class="cjs-inline-edit" 
                       data-field="order_number" 
                       data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>"
                       value="<?php echo esc_attr($stone_order->get('order_number')); ?>" />
            </td>
            <td>
                <input type="date" class="cjs-inline-edit" 
                       data-field="order_date" 
                       data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>"
                       value="<?php echo esc_attr($stone_order->get('order_date')); ?>" />
            </td>
            <td>
                <select class="cjs-inline-edit" 
                        data-field="status" 
                        data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>">
                    <?php
                    $statuses = get_option('cjs_stone_order_statuses', []);
                    foreach ($statuses as $key => $status) {
                        printf(
                            '<option value="%s" %s style="color: %s;">%s</option>',
                            esc_attr($key),
                            selected($stone_order->get('status'), $key, false),
                            esc_attr($status['color']),
                            esc_html($status['label'])
                        );
                    }
                    ?>
                </select>
            </td>
            <td class="cjs-stones-column">
                <?php 
                echo count($stones); 
                echo ' ' . __('stones', 'custom-jewelry-system');
                
                if (!empty($stones)) {
                    $in_cart_count = 0;
                    foreach ($stones as $stone) {
                        if ($stone->get('in_cart')) {
                            $in_cart_count++;
                        }
                    }
                    
                    if ($in_cart_count > 0) {
                        echo '<br><small style="color: #0073aa;">' . sprintf(__('%d in cart', 'custom-jewelry-system'), $in_cart_count) . '</small>';
                    }
                    
                    echo '<div class="cjs-stone-summary">';
                    foreach ($stones as $stone) {
                        $bg_color = $stone->get('in_cart') ? '#0073aa20' : '#f8f9fa';
                        echo '<div class="cjs-stone-pill cjs-clickable-stone" data-stone-id="' . esc_attr($stone->get_id()) . '" style="background-color: ' . esc_attr($bg_color) . ';">';
                        echo esc_html($stone->get_display_string()); // Now includes formatted size
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
                <button type="button" class="button button-small cjs-add-stones-to-order-modal" 
                        data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>" 
                        style="margin-top: 5px;">
                    <?php _e('+ Add Stones', 'custom-jewelry-system'); ?>
                </button>
            </td>
            <td>
                <?php
                if (!empty($related_orders)) {
                    $order_links = [];
                    foreach ($related_orders as $order) {
                        $order_links[] = '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . 
                                       esc_html($order->get_order_number()) . '</a>';
                    }
                    echo implode(', ', $order_links);
                } else {
                    echo '<em>' . __('None', 'custom-jewelry-system') . '</em>';
                }
                ?>
            </td>
            <td><?php echo esc_html($stone_order->get('created_by_name')); ?></td>
            <td>
                <a href="<?php echo esc_url(add_query_arg(['stone_order_id' => $stone_order_id])); ?>" 
                   class="button button-small"><?php _e('View', 'custom-jewelry-system'); ?></a>
                <button type="button" class="button button-small cjs-generate-whatsapp" 
                        data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>">
                    <?php _e('WhatsApp', 'custom-jewelry-system'); ?>
                </button>
                <button type="button" class="button button-small cjs-delete-stone-order" 
                        data-stone-order-id="<?php echo esc_attr($stone_order_id); ?>">
                    <?php _e('Delete', 'custom-jewelry-system'); ?>
                </button>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render single stone order view with full editing - stones display formatted size
     */
    private static function render_single_order($order_id) {
        $stone_order = new CJS_Stone_Order($order_id);
        
        if (!$stone_order->get('id')) {
            wp_die(__('Stone order not found', 'custom-jewelry-system'));
        }
        
        $stones = $stone_order->get_stones();
        $related_orders = $stone_order->get_related_orders();
        
        ?>
        <div class="wrap">
            <h1>
                <?php echo sprintf(__('Stone Order #%s', 'custom-jewelry-system'), esc_html($stone_order->get('order_number'))); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cjs-stone-orders')); ?>" 
                   class="page-title-action"><?php _e('Back to List', 'custom-jewelry-system'); ?></a>
            </h1>
            
            <div class="cjs-stone-order-details">
                <div class="cjs-detail-section">
                    <h2><?php _e('Order Information', 'custom-jewelry-system'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Order Number', 'custom-jewelry-system'); ?></th>
                            <td>
                                <input type="text" class="cjs-inline-edit" 
                                       data-field="order_number" 
                                       data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>"
                                       value="<?php echo esc_attr($stone_order->get('order_number')); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Order Date', 'custom-jewelry-system'); ?></th>
                            <td>
                                <input type="date" class="cjs-inline-edit" 
                                       data-field="order_date" 
                                       data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>"
                                       value="<?php echo esc_attr($stone_order->get('order_date')); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Status', 'custom-jewelry-system'); ?></th>
                            <td>
                                <select class="cjs-inline-edit" 
                                        data-field="status" 
                                        data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>">
                                    <?php
                                    $statuses = get_option('cjs_stone_order_statuses', []);
                                    foreach ($statuses as $key => $status) {
                                        printf(
                                            '<option value="%s" %s>%s</option>',
                                            esc_attr($key),
                                            selected($stone_order->get('status'), $key, false),
                                            esc_html($status['label'])
                                        );
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Created By', 'custom-jewelry-system'); ?></th>
                            <td><?php echo esc_html($stone_order->get('created_by_name')); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Created At', 'custom-jewelry-system'); ?></th>
                            <td><?php echo esc_html($stone_order->get('created_at')); ?></td>
                        </tr>
                    </table>
                    
                    <button type="button" class="button button-primary cjs-generate-whatsapp" 
                        data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>">
                        <?php _e('Generate WhatsApp Message', 'custom-jewelry-system'); ?>
                    </button>
                </div>
                
                <div class="cjs-detail-section">
                    <h2><?php _e('Stones in this Order', 'custom-jewelry-system'); ?></h2>
                    <?php if (empty($stones)): ?>
                        <p><em><?php _e('No stones added yet.', 'custom-jewelry-system'); ?></em></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Stone Details', 'custom-jewelry-system'); ?></th>
                                    <th><?php _e('Quantity', 'custom-jewelry-system'); ?></th>
                                    <th><?php _e('Size', 'custom-jewelry-system'); ?></th>
                                    <th><?php _e('Related Order', 'custom-jewelry-system'); ?></th>
                                    <th><?php _e('In Cart', 'custom-jewelry-system'); ?></th>
                                    <th><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stones as $stone): ?>
                                    <tr>
                                        <td>
                                            <a href="#" class="cjs-edit-stone cjs-clickable-stone" data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                <strong><?php echo esc_html($stone->get_display_string()); ?></strong>
                                                <?php if ($stone->get('in_cart')): ?>
                                                    <span style="color: #0073aa; font-size: 11px; margin-left: 5px;">● In Cart</span>
                                                <?php endif; ?>
                                            </a>
                                            <?php if ($stone->get('custom_comment')): ?>
                                                <br><small><?php echo esc_html($stone->get('custom_comment')); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($stone->get('stone_quantity')); ?></td>
                                        <td>
                                            <strong><?php echo esc_html($stone->get_formatted_size()); ?></strong>
                                            <?php if ($stone->get('stone_color')): ?>
                                                <br><small><?php echo esc_html($stone->get('stone_color')); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if ($stone->get('order_id')) {
                                                $order = wc_get_order($stone->get('order_id'));
                                                if ($order) {
                                                    echo '<a href="' . esc_url($order->get_edit_order_url()) . '">';
                                                    echo '#' . esc_html($order->get_order_number());
                                                    echo '</a>';
                                                    
                                                    if ($stone->get('order_item_id')) {
                                                        $item = $order->get_item($stone->get('order_item_id'));
                                                        if ($item) {
                                                            echo '<br><small>' . esc_html($item->get_name()) . '</small>';
                                                        }
                                                    }
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <input type="checkbox" class="cjs-inline-edit" 
                                                   data-field="in_cart" 
                                                   data-stone-id="<?php echo esc_attr($stone->get_id()); ?>"
                                                   data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>"
                                                   <?php checked($stone->get('in_cart'), 1); ?> />
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small cjs-edit-stone" 
                                                    data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                <?php _e('Edit', 'custom-jewelry-system'); ?>
                                            </button>
                                            <button type="button" class="button button-small cjs-remove-stone-from-order" 
                                                    data-stone-id="<?php echo esc_attr($stone->get('id')); ?>"
                                                    data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>">
                                                <?php _e('Remove', 'custom-jewelry-system'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    
                    <h3><?php _e('Add Stones', 'custom-jewelry-system'); ?></h3>
                    <p><?php _e('Select stones to add to this order:', 'custom-jewelry-system'); ?></p>
                    <select id="cjs-available-stones" multiple style="width: 100%; height: 150px;">
                        <?php
                        // Get unassigned stones - display will now use formatted size
                        $all_stones = CJS_Stone::get_stones(['per_page' => 100])['stones'];
                        foreach ($all_stones as $stone) {
                            if (!$stone->get_stone_order()) {
                                echo '<option value="' . esc_attr($stone->get('id')) . '">';
                                echo esc_html($stone->get_display_string()); // Now includes formatted size
                                if ($stone->get('order_id')) {
                                    $order = wc_get_order($stone->get('order_id'));
                                    if ($order) {
                                        echo ' (Order #' . esc_html($order->get_order_number()) . ')';
                                    }
                                }
                                echo '</option>';
                            }
                        }
                        ?>
                    </select>
                    <button type="button" class="button button-primary" id="cjs-add-stones-to-order" 
                            data-stone-order-id="<?php echo esc_attr($stone_order->get('id')); ?>" 
                            style="margin-top: 10px;">
                        <?php _e('Add Selected Stones', 'custom-jewelry-system'); ?>
                    </button>
                </div>
                
                <div class="cjs-detail-section">
                    <h2><?php _e('Related WooCommerce Orders', 'custom-jewelry-system'); ?></h2>
                    <?php if (empty($related_orders)): ?>
                        <p><em><?php _e('No related orders.', 'custom-jewelry-system'); ?></em></p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($related_orders as $order): ?>
                                <li>
                                    <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                    </a> - 
                                    <?php echo esc_html($order->get_formatted_billing_full_name()); ?> - 
                                    <?php echo wc_format_datetime($order->get_date_created()); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Also add the WhatsApp Message Modal -->
        <div id="cjs-whatsapp-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content" style="max-width: 600px;">
                <span class="cjs-modal-close">&times;</span>
                <h2><?php _e('WhatsApp Message', 'custom-jewelry-system'); ?></h2>
                <div id="cjs-whatsapp-content">
                    <textarea id="cjs-whatsapp-text" rows="10" style="width: 100%; font-family: monospace;"></textarea>
                    <div class="cjs-form-actions" style="margin-top: 15px;">
                        <button type="button" class="button button-primary" id="cjs-copy-whatsapp">
                            <?php _e('Copy to Clipboard', 'custom-jewelry-system'); ?>
                        </button>
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Close', 'custom-jewelry-system'); ?></button>
                    </div>
                </div>
            </div>
        </div>  
        <?php CJS_Modals::render_modals(); ?>
        <!-- Add JavaScript for stone order specific actions -->
        <script>
        jQuery(document).ready(function($) {
            // Add stones to order modal
            $(document).on('click', '.cjs-add-stones-to-order-modal', function(e) {
                e.preventDefault();
                var stoneOrderId = $(this).data('stone-order-id');
                $('#cjs-current-stone-order-id').val(stoneOrderId);
                
                // Refresh available stones list
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_available_stones',
                    nonce: cjs_ajax.nonce
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var $select = $('#cjs-available-stones-modal');
                        $select.empty();
                        
                        response.data.forEach(function(stone) {
                            var option = '<option value="' + stone.id + '">';
                            option += stone.display_string; // Now includes formatted size
                            if (stone.order_number) {
                                option += ' (Order #' + stone.order_number + ')';
                            }
                            option += '</option>';
                            $select.append(option);
                        });
                    }
                });
                
                $('#cjs-stone-selection-modal').show();
            });
            
            // Add selected stones from modal
            $(document).on('click', '#cjs-add-selected-stones-modal', function(e) {
                e.preventDefault();
                
                var stoneOrderId = $('#cjs-current-stone-order-id').val();
                var selectedStones = $('#cjs-available-stones-modal').val();
                
                if (!selectedStones || selectedStones.length === 0) {
                    CJS.showNotice('Please select stones to add', 'warning');
                    return;
                }
                
                var promises = selectedStones.map(function(stoneId) {
                    return $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_manage_stone_order',
                        nonce: cjs_ajax.nonce,
                        stone_order_id: stoneOrderId,
                        stone_action: 'add',
                        stone_id: stoneId
                    });
                });
                
                $.when.apply($, promises)
                    .done(function() {
                        CJS.showNotice('Stones added to order successfully', 'success');
                        $('#cjs-stone-selection-modal').hide();
                        location.reload();
                    })
                    .fail(function() {
                        CJS.showNotice('Error adding stones to order', 'error');
                    });
            });
        });
        </script>
        
        <?php
    }
}