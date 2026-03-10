<?php
/**
 * Admin Inventory List Page (Inventorius)
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Inventory {

    public static function render_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

        $result = CJS_Inventory_Item::get_items([
            'page'     => $page,
            'per_page' => 20,
            'status'   => $status_filter,
            'category' => $category_filter
        ]);

        $statuses = get_option('cjs_inventory_statuses', ['Sandėlyje']);
        if (!is_array($statuses)) {
            $statuses = [];
        }
        $categories = get_option('cjs_inventory_categories', ['Matavimo Rinkinys']);
        if (!is_array($categories)) {
            $categories = [];
        }

        $inventory_page_url = admin_url('admin.php?page=cjs-inventory');
        ?>
        <div class="wrap">
            <h1><?php _e('Inventorius', 'custom-jewelry-system'); ?>
                <button type="button" class="page-title-action" id="cjs-inventory-add-item"><?php _e('+ Add Item', 'custom-jewelry-system'); ?></button>
            </h1>

            <div class="cjs-filters">
                <form method="get" class="cjs-search-form">
                    <input type="hidden" name="page" value="cjs-inventory" />
                    <select name="status">
                        <option value=""><?php _e('All statuses', 'custom-jewelry-system'); ?></option>
                        <?php foreach ($statuses as $s) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="category">
                        <option value=""><?php _e('All categories', 'custom-jewelry-system'); ?></option>
                        <?php foreach ($categories as $c) : ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($category_filter, $c); ?>><?php echo esc_html($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php _e('Filter', 'custom-jewelry-system'); ?></button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped cjs-inventory-table">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('ID', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Name', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Identifier', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Order', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Status', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Category', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Created', 'custom-jewelry-system'); ?></th>
                        <th style="width: 80px;"><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($result['items'])) {
                        echo '<tr><td colspan="8">' . __('No inventory items found', 'custom-jewelry-system') . '</td></tr>';
                    } else {
                        foreach ($result['items'] as $item) {
                            self::render_row($item, $inventory_page_url);
                        }
                    }
                    ?>
                </tbody>
            </table>

            <?php
            if ($result['pages'] > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $result['pages']
                ]);
                echo '</div></div>';
            }
            ?>
            <div id="cjs-inventory-events-modal" class="cjs-modal cjs-inventory-events-modal">
                <div class="cjs-inventory-events-modal-backdrop"></div>
                <div class="cjs-inventory-events-modal-panel">
                    <span class="cjs-modal-close">&times;</span>
                    <h2 class="cjs-inventory-events-modal-title"><?php esc_html_e('Event History', 'custom-jewelry-system'); ?></h2>
                    <div id="cjs-inventory-events-list" class="cjs-inventory-events-modal-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_row($item, $inventory_page_url) {
        $id = $item->get_id();
        $statuses = get_option('cjs_inventory_statuses', []);
        $categories = get_option('cjs_inventory_categories', []);
        if (!is_array($statuses)) $statuses = [];
        if (!is_array($categories)) $categories = [];

        $order_id = $item->get('order_id');
        $order_display = '';
        $orders_list_url = '';
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order_display = '#' . $order->get_order_number() . ' - ' . $order->get_formatted_billing_full_name();
                $orders_list_url = add_query_arg('highlight_order', $order_id, admin_url('admin.php?page=cjs-orders-list')) . '#order' . $order_id;
            }
        }

        $inv_anchor = $item->get('identifier') ? sanitize_html_class($item->get('identifier')) : 'id-' . $id;
        $created = $item->get('created_at');
        $created_display = $created ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($created)) : '';
        ?>
        <tr id="<?php echo esc_attr($inv_anchor); ?>" data-inventory-id="<?php echo esc_attr($id); ?>" class="cjs-inventory-row">
            <td><?php echo esc_html($id); ?></td>
            <td>
                <input type="text" class="cjs-inline-inventory-edit" data-item-id="<?php echo esc_attr($id); ?>" data-field="name"
                       value="<?php echo esc_attr($item->get('name')); ?>" placeholder="<?php esc_attr_e('Name', 'custom-jewelry-system'); ?>" />
            </td>
            <td>
                <input type="text" class="cjs-inline-inventory-edit" data-item-id="<?php echo esc_attr($id); ?>" data-field="identifier"
                       value="<?php echo esc_attr($item->get('identifier')); ?>" placeholder="<?php esc_attr_e('Identifier', 'custom-jewelry-system'); ?>" />
            </td>
            <td class="cjs-inventory-order-cell">
                <?php if ($order_id && $orders_list_url) : ?>
                    <div class="cjs-inventory-pill">
                        <a href="<?php echo esc_url($orders_list_url); ?>"><?php echo esc_html($order_display); ?></a>
                        <button type="button" class="cjs-inventory-unassign" data-item-id="<?php echo esc_attr($id); ?>" data-order-id="<?php echo esc_attr($order_id); ?>" title="<?php esc_attr_e('Detach from order', 'custom-jewelry-system'); ?>">&times;</button>
                    </div>
                <?php else : ?>
                    <input type="text" class="cjs-inventory-order-search" data-item-id="<?php echo esc_attr($id); ?>"
                           placeholder="<?php esc_attr_e('Search order...', 'custom-jewelry-system'); ?>" autocomplete="off" />
                    <input type="hidden" class="cjs-inventory-order-id" data-item-id="<?php echo esc_attr($id); ?>" value="" />
                <?php endif; ?>
            </td>
            <td>
                <select class="cjs-inline-inventory-edit" data-item-id="<?php echo esc_attr($id); ?>" data-field="item_status">
                    <?php foreach ($statuses as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($item->get('item_status'), $s); ?>><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select class="cjs-inline-inventory-edit" data-item-id="<?php echo esc_attr($id); ?>" data-field="item_category">
                    <option value=""><?php _e('—', 'custom-jewelry-system'); ?></option>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($item->get('item_category'), $c); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><?php echo esc_html($created_display); ?></td>
            <td>
                <button type="button" class="button button-small cjs-inventory-events-btn" data-item-id="<?php echo esc_attr($id); ?>" data-events="<?php echo esc_attr(wp_json_encode($item->get_events())); ?>"><?php _e('Events', 'custom-jewelry-system'); ?></button>
                <button type="button" class="button button-small cjs-inventory-delete" data-item-id="<?php echo esc_attr($id); ?>"><?php _e('Delete', 'custom-jewelry-system'); ?></button>
            </td>
        </tr>
        <?php
    }
}
