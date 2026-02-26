<?php
/**
 * Admin Orders List Page - UPDATED with stone size units
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

class CJS_Admin_Orders {
    
    /**
     * Render the orders list page
     */
    public static function render_page() {
        // Get filters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        ?>
        <div class="wrap">
            <h1><?php _e('Užsakymų sąrašas', 'custom-jewelry-system'); ?></h1>
            
            <div class="cjs-filters">
                <form method="get" class="cjs-search-form">
                    <input type="hidden" name="page" value="cjs-orders-list" />
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php esc_attr_e('Search orders...', 'custom-jewelry-system'); ?>" />
                    <select name="status">
                        <option value=""><?php _e('All Statuses', 'custom-jewelry-system'); ?></option>
                        <?php
                        $statuses = CJS_Order_Extension::get_ordered_options('manufacturing_statuses');
                        foreach ($statuses as $status) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($status),
                                selected($status_filter, $status, false),
                                esc_html($status)
                            );
                        }
                        ?>
                    </select>
                    <button type="submit" class="button"><?php _e('Filter', 'custom-jewelry-system'); ?></button>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped cjs-orders-table">
                <thead>
                    <tr>
                        <th><?php _e('Order', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Customer', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Užprabuoti iki', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Pristatyti iki', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Dienos liko', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Užsakyti modelį', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Užsakyti gamybą', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Liejimas', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Reikalingi akmenys', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Akmenų užsakymas', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Statusas', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Spauda', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $orders = self::get_orders($page, $per_page, $search, $status_filter);
                    
                    if (empty($orders['items'])) {
                        echo '<tr><td colspan="12">' . __('No orders found', 'custom-jewelry-system') . '</td></tr>';
                    } else {
                        foreach ($orders['items'] as $order_data) {
                            self::render_order_row($order_data);
                        }
                    }
                    ?>
                </tbody>
            </table>
            
            <?php
            // Pagination
            if ($orders['total_pages'] > 1) {
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $orders['total_pages']
                ];
                
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links($pagination_args);
                echo '</div></div>';
            }
            ?>
        </div>
        
        <?php 
        // Render all modals from centralized location
        CJS_Modals::render_modals(); 
        ?>
        
        <?php
    }
    
    /**
     * Get orders with extended data (HPOS Compatible) - FIXED to exclude completed orders
     */
    private static function get_orders($page, $per_page, $search = '', $status_filter = '') {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        
        // Use WooCommerce's order query for HPOS compatibility
        $args = [
            'type' => 'shop_order',
            'exclude' => [], // We'll populate this with completed order IDs
            'limit' => -1,
            'return' => 'ids'
        ];

        // Get all order IDs with excluded statuses
        $excluded_ids = [];
        
        // Define statuses that should be excluded from manufacturing queue
        $excluded_statuses = [
            'wc-completed',    // Completed orders
            'wc-cancelled',    // Cancelled orders
            'wc-refunded',     // Refunded orders
            'wc-failed',       // Failed orders
        ];
        
        // Get order IDs for each excluded status
        foreach ($excluded_statuses as $status) {
            $status_args = [
                'type' => 'shop_order',
                'status' => $status,
                'limit' => -1,
                'return' => 'ids'
            ];
            $status_query = new WC_Order_Query($status_args);
            $status_ids = $status_query->get_orders();
            $excluded_ids = array_merge($excluded_ids, $status_ids);
        }

        // Remove duplicates and add to exclude list
        $excluded_ids = array_unique($excluded_ids);
        $args['exclude'] = $excluded_ids;
        
        // Add search if provided
        if ($search) {
            // For HPOS, we need to handle search differently
            if (is_numeric($search)) {
                // Search by order ID
                $args['include'] = [$search];
            } else {
                // Search by customer name/email - this requires a custom query
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => '_billing_first_name',
                        'value' => $search,
                        'compare' => 'LIKE'
                    ],
                    [
                        'key' => '_billing_last_name',
                        'value' => $search,
                        'compare' => 'LIKE'
                    ],
                    [
                        'key' => '_billing_email',
                        'value' => $search,
                        'compare' => 'LIKE'
                    ]
                ];
            }
        }
        
        // Get all order IDs (not paginated yet)
        $order_query = new WC_Order_Query($args);
        $all_order_ids = $order_query->get_orders();
        
        // Filter by manufacturing status if provided
        if ($status_filter) {
            $filtered_ids = [];
            foreach ($all_order_ids as $order_id) {
                $ext_data = CJS_Order_Extension::get_order_extension($order_id);
                if ($ext_data['manufacturing_status'] === $status_filter) {
                    $filtered_ids[] = $order_id;
                }
            }
            $all_order_ids = $filtered_ids;
        }
        
        // Total count
        $total_orders = count($all_order_ids);
        
        // Get order extensions and sort by delivery date
        $order_data = [];
        foreach ($all_order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $ext_data = CJS_Order_Extension::get_order_extension($order_id);
                $order_data[] = [
                    'order' => $order,
                    'extension' => (object) $ext_data,
                    'stones' => CJS_Stone::get_by_order($order_id),
                    'items' => $order->get_items(),
                    'sort_key' => self::get_sort_key($ext_data['deliver_by_date'], $order_id)
                ];
            }
        }
        
        // Sort by delivery date (overdue first, then by date)
        usort($order_data, function($a, $b) {
            $date_comparison = strcmp($a['sort_key'], $b['sort_key']);
            if ($date_comparison === 0) {
                // If delivery dates are the same, sort by order ID
                return $a['order']->get_id() - $b['order']->get_id();
            }
            return $date_comparison;
        });
        
        // Apply pagination
        $order_data = array_slice($order_data, $offset, $per_page);
        
        return [
            'items' => $order_data,
            'total' => $total_orders,
            'total_pages' => ceil($total_orders / $per_page)
        ];
    }
    
    /**
    * Generate sort key for delivery date sorting
    */
    private static function get_sort_key($deliver_by_date, $order_id) {
        $padded_id = str_pad($order_id, 10, '0', STR_PAD_LEFT);
        
        // Check for various empty/null conditions
        if (is_null($deliver_by_date) || 
            empty($deliver_by_date) || 
            trim($deliver_by_date) === '') {
            // No dates - sort last by ID descending (newest first)
            return '9_' . str_pad(999999999 - $order_id, 10, '0', STR_PAD_LEFT);
        }
        
        try {
            $date = new DateTime($deliver_by_date);
            $today = new DateTime();
            
            if ($date < $today) {
                // Overdue - sort first, then by date, then by ID
                return '0_' . $deliver_by_date . '_' . $padded_id;
            } else {
                // Future dates - sort second, then by date, then by ID  
                return '1_' . $deliver_by_date . '_' . $padded_id;
            }
        } catch (Exception $e) {
            // If date parsing fails, treat as empty
            return '9_' . str_pad(999999999 - $order_id, 10, '0', STR_PAD_LEFT);
        }
    }
    
    /**
     * Render order row with product-based stones - stones now show formatted size
     */
    private static function render_order_row($order_data) {
        $order = $order_data['order'];
        $ext = $order_data['extension'];
        $stones = $order_data['stones'];
        $items = $order_data['items'];
        
        $order_id = $order->get_id();
        
        // Calculate days left
        $days_left = null;
        $days_color = '#6c757d';
        if ($ext->deliver_by_date) {
            $target = new DateTime($ext->deliver_by_date);
            $today = new DateTime();
            $diff = $today->diff($target);
            $days_left = $target >= $today ? $diff->days : -$diff->days;
            
            if ($days_left < 0) {
                $days_color = '#dc3545';
            } elseif ($days_left < 5) {
                $days_color = '#dc3545';
            } elseif ($days_left < 14) {
                $days_color = '#ffc107';
            } else {
                $days_color = '#28a745';
            }
        }
        
        // Get order edit URL (HPOS compatible)
        $edit_url = OrderUtil::custom_orders_table_usage_is_enabled() 
            ? $order->get_edit_order_url()
            : admin_url('post.php?post=' . $order_id . '&action=edit');
        ?>
        <tr data-order-id="<?php echo esc_attr($order_id); ?>">
            <td>
                <a href="<?php echo esc_url($edit_url); ?>">
                    #<?php echo esc_html($order->get_order_number()); ?>
                </a>
            </td>
            <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
            <td>
                <input type="date" class="cjs-inline-edit" 
                       data-field="finish_by_date" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       value="<?php echo esc_attr($ext->finish_by_date); ?>" />
            </td>
            <td>
                <input type="date" class="cjs-inline-edit" 
                       data-field="deliver_by_date" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       value="<?php echo esc_attr($ext->deliver_by_date); ?>" />
            </td>
            <td style="color: <?php echo esc_attr($days_color); ?>;">
                <?php
                if ($days_left !== null) {
                    if ($days_left >= 0) {
                        echo sprintf(__('%d days', 'custom-jewelry-system'), $days_left);
                    } else {
                        echo sprintf(__('Vėluojama %d d.', 'custom-jewelry-system'), abs($days_left));
                    }
                }
                ?>
            </td>
            <td>
                <input type="checkbox" class="cjs-inline-edit" 
                       data-field="order_model" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       <?php checked($ext->order_model, 1); ?> />
            </td>
            <td>
                <input type="checkbox" class="cjs-inline-edit" 
                       data-field="order_production" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       <?php checked($ext->order_production, 1); ?> />
            </td>
            <td>
                <textarea class="cjs-inline-edit cjs-small-textarea" 
                          data-field="casting_notes" 
                          data-order-id="<?php echo esc_attr($order_id); ?>"
                          rows="1"><?php echo esc_textarea($ext->casting_notes); ?></textarea>
            </td>
            <td class="cjs-stones-column">
                <?php
                // Group stones by order item
                $stones_by_item = [];
                foreach ($stones as $stone) {
                    $item_id = $stone->get('order_item_id') ?: 0;
                    if (!isset($stones_by_item[$item_id])) {
                        $stones_by_item[$item_id] = [];
                    }
                    $stones_by_item[$item_id][] = $stone;
                }
                
                // Display stones grouped by product - stones now show formatted size
                foreach ($items as $item_id => $item) {
                    if (isset($stones_by_item[$item_id]) && !empty($stones_by_item[$item_id])) {
                        echo '<div class="cjs-product-stone-group">';
                        echo '<small class="cjs-product-label">' . esc_html($item->get_name()) . ':</small><br>';
                        foreach ($stones_by_item[$item_id] as $stone) {
                            $stone_order = $stone->get_stone_order();
                            $bg_color = '#f8f9fa';
                            
                            if ($stone_order) {
                                $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                                $bg_color = $status_info['color'] . '20';
                            }
                            
                            echo '<div class="cjs-stone-pill cjs-clickable-stone" style="background-color: ' . esc_attr($bg_color) . ';" ';
                            echo 'data-stone-id="' . esc_attr($stone->get_id()) . '">';
                            echo esc_html($stone->get_display_string()); // Now includes formatted size
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                }
                
                // Show stones without item assignment (legacy)
                if (isset($stones_by_item[0]) && !empty($stones_by_item[0])) {
                    echo '<div class="cjs-product-stone-group">';
                    echo '<small class="cjs-product-label" style="color: #999;">' . __('Unassigned stones:', 'custom-jewelry-system') . '</small><br>';
                    foreach ($stones_by_item[0] as $stone) {
                        $stone_order = $stone->get_stone_order();
                        $bg_color = '#f8f9fa';
                        
                        if ($stone_order) {
                            $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                            $bg_color = $status_info['color'] . '20';
                        }
                        
                        echo '<div class="cjs-stone-pill cjs-clickable-stone" style="background-color: ' . esc_attr($bg_color) . ';" ';
                        echo 'data-stone-id="' . esc_attr($stone->get_id()) . '">';
                        echo esc_html($stone->get_display_string()); // Now includes formatted size
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
                <button type="button" class="button button-small cjs-add-stone-from-list" 
                        data-order-id="<?php echo esc_attr($order_id); ?>" style="margin-top: 5px;">
                    <?php _e('+ Add Stone', 'custom-jewelry-system'); ?>
                </button>
            </td>
            <td class="cjs-stone-orders-column">
                <?php
                $stone_orders_shown = [];
                foreach ($stones as $stone) {
                    $stone_order = $stone->get_stone_order();
                    if ($stone_order && !in_array($stone_order->id, $stone_orders_shown)) {
                        $stone_orders_shown[] = $stone_order->id;
                        $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                        
                        // UPDATED: Make stone order pills clickable
                        echo '<div class="cjs-stone-order-pill cjs-clickable-stone-order" style="background-color: ' . esc_attr($status_info['color'] . '20') . ';" ';
                        echo 'data-stone-order-id="' . esc_attr($stone_order->id) . '" ';
                        echo 'title="' . esc_attr('Click to view stone order #' . $stone_order->order_number) . '">';
                        echo esc_html($stone_order->order_date . ' - ' . $status_info['label']);
                        echo '</div>';
                    }
                }
                ?>
                <button type="button" class="button button-small cjs-create-stone-order" 
                        data-order-id="<?php echo esc_attr($order_id); ?>" style="margin-top: 5px;">
                    <?php _e('+ Stone Order', 'custom-jewelry-system'); ?>
                </button>
            </td>
            <td>
                <select class="cjs-inline-edit" 
                        data-field="manufacturing_status" 
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                    <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                    <?php
                    $statuses = CJS_Order_Extension::get_ordered_options('manufacturing_statuses');
                    foreach ($statuses as $status) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($status),
                            selected($ext->manufacturing_status, $status, false),
                            esc_html($status)
                        );
                    }
                    ?>
                </select>
            </td>
            <td>
                <input type="checkbox" class="cjs-inline-edit" 
                    data-field="order_printing" 
                    data-order-id="<?php echo esc_attr($order_id); ?>"
                    <?php checked($ext->order_printing, 1); ?> />
            </td>
            <td>
                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                    <?php _e('View', 'custom-jewelry-system'); ?>
                </a>
                <a href="#" 
                    class="cjs-autofill-liejimas" 
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>" 
                    title="Auto-fill Liejimas from product options">
                        
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" width="18px" height="18px" style="display: block;">
                            <!-- Define the gradient here -->
                            <defs>
                                <linearGradient id="bluePinkGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#6DD5FA; stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#F781C6; stop-opacity:1" />
                                </linearGradient>
                            </defs>
                            
                            <!-- Apply the gradient fill to the SVG path -->
                            <path fill="url(#bluePinkGradient)" d="M14.217 19.707l-1.112 2.547c-.427.979-1.782.979-2.21 0l-1.112-2.547c-.99-2.267-2.771-4.071-4.993-5.057L1.73 13.292c-.973-.432-.973-1.848 0-2.28l2.965-1.316C6.974 8.684 8.787 6.813 9.76 4.47l1.126-2.714c.418-1.007 1.81-1.007 2.228 0L14.24 4.47c.973 2.344 2.786 4.215 5.065 5.226l2.965 1.316c.973.432.973 1.848 0 2.28l-3.061 1.359C16.988 15.637 15.206 17.441 14.217 19.707zM24.481 27.796l-.339.777c-.248.569-1.036.569-1.284 0l-.339-.777c-.604-1.385-1.693-2.488-3.051-3.092l-1.044-.464c-.565-.251-.565-1.072 0-1.323l.986-.438c1.393-.619 2.501-1.763 3.095-3.195l.348-.84c.243-.585 1.052-.585 1.294 0l.348.84c.594 1.432 1.702 2.576 3.095 3.195l.986.438c.565-.251.565 1.072 0 1.323l-1.044.464C26.174 25.308 25.085 26.411 24.481 27.796z"/>
                        </svg>
                </a>
            </td>
        </tr>
        <?php
    }
}