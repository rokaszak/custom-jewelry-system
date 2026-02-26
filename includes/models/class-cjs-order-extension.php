<?php
/**
 * Order Extension Model - UPDATED with size units support in meta boxes
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

class CJS_Order_Extension {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // HPOS-compatible meta box hooks
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes'], 10, 2);
        
        // Both legacy and HPOS save hooks
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_order_meta']);
        add_action('woocommerce_update_order', [__CLASS__, 'save_order_meta_hpos']);
        
        // HPOS-compatible column management
        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS columns
            add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_order_columns']);
            add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_order_columns'], 10, 2);
        } else {
            // Legacy columns
            add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_order_columns']);
            add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_order_columns'], 10, 2);
        }
        
        // Order creation hooks (both HPOS and legacy)
        add_action('woocommerce_new_order', [__CLASS__, 'create_order_extension']);
        add_action('woocommerce_update_order', [__CLASS__, 'update_order_extension']);
        
        // Add meta box for stones in order items
        add_action('woocommerce_order_item_add_action_buttons', [__CLASS__, 'add_stone_button_to_items'], 10, 1);
        add_action('admin_footer', [__CLASS__, 'render_order_modals']);
    }
    
    /**
     * Add meta boxes (HPOS compatible)
     */
    public static function add_meta_boxes($post_type_or_screen_id, $post_or_order_object) {
        // Check if this is an order screen (both HPOS and legacy)
        $screen = null;
        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            $screen = wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
                ? wc_get_page_screen_id('shop-order')
                : null;
        } else {
            $screen = 'shop_order';
        }
        
        if ($post_type_or_screen_id !== $screen && $post_type_or_screen_id !== 'shop_order' && $post_type_or_screen_id !== 'woocommerce_page_wc-orders') {
            return;
        }
        
        add_meta_box(
            'cjs_order_details',
            __('Jewelry Manufacturing Details', 'custom-jewelry-system'),
            [__CLASS__, 'render_meta_box'],
            $post_type_or_screen_id,
            'normal',
            'high'
        );
        
        add_meta_box(
            'cjs_order_stones',
            __('Reikalingi akmenys - Required Stones', 'custom-jewelry-system'),
            [__CLASS__, 'render_stones_meta_box'],
            $post_type_or_screen_id,
            'normal',
            'default'
        );
        
        add_meta_box(
            'cjs_order_files',
            __('Spauda - Digital Files', 'custom-jewelry-system'),
            [__CLASS__, 'render_files_meta_box'],
            $post_type_or_screen_id,
            'normal',
            'default'
        );
    }
    
    /**
     * Render main meta box
     */
    public static function render_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        $order_id = $order->get_id();
        $data = self::get_order_extension($order_id);
        
        wp_nonce_field('cjs_order_meta', 'cjs_order_meta_nonce');
        ?>
        <div class="cjs-order-meta-box">
            <p>
                <label for="cjs_finish_by_date"><?php _e('Užprabuoti iki', 'custom-jewelry-system'); ?></label>
                <input type="date" id="cjs_finish_by_date" name="cjs_finish_by_date" 
                       value="<?php echo esc_attr($data['finish_by_date'] ?? ''); ?>" />
            </p>
            
            <p>
                <label for="cjs_deliver_by_date"><?php _e('Pristatyti iki', 'custom-jewelry-system'); ?></label>
                <input type="date" id="cjs_deliver_by_date" name="cjs_deliver_by_date" 
                       value="<?php echo esc_attr($data['deliver_by_date'] ?? ''); ?>" />
            </p>
            
            <p>
                <label for="cjs_order_model">
                    <input type="checkbox" id="cjs_order_model" name="cjs_order_model" 
                           value="1" <?php checked($data['order_model'] ?? 0, 1); ?> />
                    <?php _e('Užsakyti modelį', 'custom-jewelry-system'); ?>
                </label>
            </p>
            
            <p>
                <label for="cjs_order_production">
                    <input type="checkbox" id="cjs_order_production" name="cjs_order_production" 
                           value="1" <?php checked($data['order_production'] ?? 0, 1); ?> />
                    <?php _e('Užsakyti gamybą', 'custom-jewelry-system'); ?>
                </label>
            </p>
            
            <p>
                <label for="cjs_casting_notes"><?php _e('Liejimas', 'custom-jewelry-system'); ?></label>
                <textarea id="cjs_casting_notes" name="cjs_casting_notes" rows="3" style="width: 100%;"><?php 
                    echo esc_textarea($data['casting_notes'] ?? ''); 
                ?></textarea>
            </p>
            
            <p>
                <label for="cjs_manufacturing_status"><?php _e('Statusas', 'custom-jewelry-system'); ?></label>
                <select id="cjs_manufacturing_status" name="cjs_manufacturing_status" style="width: 100%;">
                    <option value=""><?php _e('Select Status', 'custom-jewelry-system'); ?></option>
                    <?php
                    $statuses = self::get_ordered_manufacturing_statuses();
                    foreach ($statuses as $status) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($status),
                            selected($data['manufacturing_status'], $status, false),
                            esc_html($status)
                        );
                    }
                    ?>
                </select>
                <button type="button" class="button" onclick="CJS.addNewStatus('manufacturing')" style="margin-top: 5px;">
                    <?php _e('Add New Status', 'custom-jewelry-system'); ?>
                </button>
            </p>

            <p>
                <label for="cjs_order_printing">
                    <input type="checkbox" id="cjs_order_printing" name="cjs_order_printing" 
                        value="1" <?php checked($data['order_printing'] ?? 0, 1); ?> />
                    <?php _e('Užsakyti spaudą', 'custom-jewelry-system'); ?>
                </label>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render stones meta box - UPDATED to show formatted size
     */
    public static function render_stones_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        $order_id = $order->get_id();
        ?>
        <div class="cjs-stones-meta-box">
            <div class="cjs-stones-by-product">
                <?php
                $items = $order->get_items();
                foreach ($items as $item_id => $item) {
                    $product_id = $item->get_product_id();
                    $product = $item->get_product();
                    $stones = CJS_Stone::get_by_order_item($item_id);
                    ?>
                    <div class="cjs-product-stones-section" data-item-id="<?php echo esc_attr($item_id); ?>">
                        <h4>
                            <?php echo esc_html($item->get_name()); ?>
                            <small>(<?php echo sprintf(__('Quantity: %d', 'custom-jewelry-system'), $item->get_quantity()); ?>)</small>
                        </h4>
                        
                        <div class="cjs-stones-list">
                            <?php if (empty($stones)): ?>
                                <p class="cjs-no-stones"><em><?php _e('No stones added for this product', 'custom-jewelry-system'); ?></em></p>
                            <?php else: ?>
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Stone Details', 'custom-jewelry-system'); ?></th>
                                            <th><?php _e('Quantity', 'custom-jewelry-system'); ?></th>
                                            <th><?php _e('Size', 'custom-jewelry-system'); ?></th>
                                            <th><?php _e('Stone Order', 'custom-jewelry-system'); ?></th>
                                            <th><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stones as $stone): ?>
                                            <tr data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                <td>
                                                    <a href="#" class="cjs-edit-stone" data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                        <strong><?php echo esc_html($stone->get('stone_type') . ' ' . $stone->get('stone_origin')); ?></strong>
                                                        <?php if ($stone->get('stone_shape')): ?>
                                                            <br><small><?php echo esc_html($stone->get('stone_shape')); ?></small>
                                                        <?php endif; ?>
                                                    </a>
                                                    <?php if ($stone->get('custom_comment')): ?>
                                                        <br><small style="color: #666;"><?php echo esc_html($stone->get('custom_comment')); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html($stone->get('stone_quantity')); ?></td>
                                                <td>
                                                    <!-- UPDATED: Show formatted size -->
                                                    <strong style="color: #0073aa;"><?php echo esc_html($stone->get_formatted_size()); ?></strong>
                                                    <?php if ($stone->get('stone_color')): ?>
                                                        <br><small><?php echo esc_html($stone->get('stone_color')); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($stone->get('stone_clarity')): ?>
                                                        <br><small><?php echo esc_html($stone->get('stone_clarity')); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stone_order = $stone->get_stone_order();
                                                    if ($stone_order) {
                                                        $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                                                        echo '<span class="cjs-status-badge" style="background-color: ' . esc_attr($status_info['color'] . '20') . '; color: ' . esc_attr($status_info['color']) . ';">';
                                                        echo esc_html($stone_order->order_number . ' - ' . $status_info['label']);
                                                        echo '</span>';
                                                    } else {
                                                        echo '<em>' . __('Not ordered', 'custom-jewelry-system') . '</em>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="button button-small cjs-edit-stone" 
                                                            data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                        <?php _e('Edit', 'custom-jewelry-system'); ?>
                                                    </button>
                                                    <button type="button" class="button button-small cjs-delete-stone" 
                                                            data-stone-id="<?php echo esc_attr($stone->get_id()); ?>">
                                                        <?php _e('Delete', 'custom-jewelry-system'); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <div class="cjs-add-stone-section">
                            <button type="button" class="button cjs-add-stone" 
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    data-order-item-id="<?php echo esc_attr($item_id); ?>">
                                <?php _e('+ Add Stone for this Product', 'custom-jewelry-system'); ?>
                            </button>
                        </div>
                    </div>
                <?php } ?>
            </div>
            
            <div class="cjs-stone-orders-section">
                <h3><?php _e('Stone Orders for this Order', 'custom-jewelry-system'); ?></h3>
                <?php
                $all_stones = CJS_Stone::get_by_order($order_id);
                $stone_orders_shown = [];
                
                foreach ($all_stones as $stone) {
                    $stone_order = $stone->get_stone_order();
                    if ($stone_order && !in_array($stone_order->id, $stone_orders_shown)) {
                        $stone_orders_shown[] = $stone_order->id;
                    }
                }
                
                if (empty($stone_orders_shown)) {
                    echo '<p><em>' . __('No stone orders created yet', 'custom-jewelry-system') . '</em></p>';
                } else {
                    echo '<div class="cjs-stone-orders-list">';
                    foreach ($stone_orders_shown as $stone_order_id) {
                        $stone_order = new CJS_Stone_Order($stone_order_id);
                        $status_info = $stone_order->get_status_info();
                        echo '<div class="cjs-stone-order-item" style="margin-bottom: 10px;">';
                        
                        // Make the whole pill clickable
                        echo '<div class="cjs-stone-order-pill cjs-clickable-stone-order" ';
                        echo 'style="background-color: ' . esc_attr($status_info['color'] . '20') . '; ';
                        echo 'border: 1px solid ' . esc_attr($status_info['color']) . '; ';
                        echo 'padding: 5px 10px; border-radius: 5px; cursor: pointer; display: inline-block;" ';
                        echo 'data-stone-order-id="' . esc_attr($stone_order_id) . '">';
                        echo '#' . esc_html($stone_order->get('order_number'));
                        echo ' - ' . esc_html($stone_order->get('order_date'));
                        echo ' - <span style="color: ' . esc_attr($status_info['color']) . ';">' . esc_html($status_info['label']) . '</span>';
                        echo '</div>';
                        
                        // Add action buttons
                        echo ' <button type="button" class="button button-small cjs-view-stone-order" ';
                        echo 'data-stone-order-id="' . esc_attr($stone_order_id) . '" style="margin-left: 10px;">';
                        echo __('View Details', 'custom-jewelry-system');
                        echo '</button>';
                        
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
                <button type="button" class="button" id="cjs-create-stone-order-for-order" 
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                    <?php _e('Create Stone Order', 'custom-jewelry-system'); ?>
                </button>
            </div>
        </div>
        
        <style>
        .cjs-product-stones-section {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 15px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .cjs-product-stones-section h4 {
            margin-top: 0;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .cjs-stones-list {
            margin-bottom: 10px;
        }
        .cjs-stone-orders-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
        }
        </style>
        <?php
    }
    
    /**
     * Render files meta box (unchanged)
     */
    public static function render_files_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        $order_id = $order->get_id();
        $files = self::get_order_files($order_id);
        
        // Get actual PHP upload limits
        $limits = CJS_File_Handler::get_upload_limits();
        $max_size_display = CJS_File_Handler::format_bytes($limits['effective_limit']);
        ?>
        <div class="cjs-files-meta-box">
            <div id="cjs-files-list">
                <?php foreach ($files as $file): ?>
                    <div class="cjs-file-item" data-file-id="<?php echo esc_attr($file->id); ?>">
                        <div class="cjs-file-preview">
                            <?php if ($file->thumbnail_path): ?>
                                <img src="<?php echo esc_url(self::get_file_url($file->thumbnail_path)); ?>" 
                                     alt="<?php echo esc_attr($file->file_name); ?>"
                                     class="cjs-thumbnail-preview"
                                     data-lightbox-src="<?php echo esc_url(self::get_file_url($file->thumbnail_path)); ?>"
                                     style="cursor: pointer;">
                            <?php else: ?>
                                <span class="dashicons <?php echo esc_attr(CJS_File_Handler::get_file_icon($file->file_type)); ?>"></span>
                            <?php endif; ?>
                        </div>
                        <div class="cjs-file-details">
                            <strong><?php echo esc_html($file->file_name); ?></strong>
                            <?php if ($file->custom_comment): ?>
                                <p><?php echo esc_html($file->custom_comment); ?></p>
                            <?php endif; ?>
                            <small><?php echo size_format($file->file_size); ?></small>
                        </div>
                        <div class="cjs-file-actions">
                            <a href="<?php echo esc_url(self::get_file_url($file->file_path)); ?>" 
                               class="button" target="_blank"><?php _e('Download', 'custom-jewelry-system'); ?></a>
                            <button type="button" class="button cjs-delete-file" 
                                    data-file-id="<?php echo esc_attr($file->id); ?>">
                                <?php _e('Delete', 'custom-jewelry-system'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- NO FORM TAGS - just a div wrapper -->
            <div class="cjs-file-upload">
                <h4><?php _e('Add New File', 'custom-jewelry-system'); ?></h4>
                <div class="cjs-upload-fields">
                    <p>
                        <label><?php _e('Name', 'custom-jewelry-system'); ?></label>
                        <input type="text" id="cjs_file_name" placeholder="<?php esc_attr_e('File name (optional)', 'custom-jewelry-system'); ?>" />
                    </p>
                    <p>
                        <label><?php _e('Comment', 'custom-jewelry-system'); ?></label>
                        <textarea id="cjs_file_comment" rows="2" style="width: 100%;" placeholder="<?php esc_attr_e('File description (optional)', 'custom-jewelry-system'); ?>"></textarea>
                    </p>
                    <p>
                        <label><?php _e('File', 'custom-jewelry-system'); ?> * (Max: <?php echo esc_html($max_size_display); ?>)</label>
                        <input type="file" id="cjs_file_upload" />
                        <small style="display: block; margin-top: 5px; color: #666;">
                            <?php 
                            printf(
                                __('Allowed: Almost all file types except executable files. Current PHP limits: Upload: %s, POST: %s', 'custom-jewelry-system'),
                                CJS_File_Handler::format_bytes($limits['upload_max_filesize']),
                                CJS_File_Handler::format_bytes($limits['post_max_size'])
                            ); 
                            ?>
                        </small>
                    </p>
                    <p>
                        <label><?php _e('Thumbnail (optional)', 'custom-jewelry-system'); ?></label>
                        <input type="file" id="cjs_file_thumbnail" accept="image/*" />
                        <small><?php _e('Upload a thumbnail image for this file (images only)', 'custom-jewelry-system'); ?></small>
                    </p>
                    <div class="cjs-upload-button-wrapper">
                        <button type="button" class="button button-primary" id="cjs-upload-file" 
                                data-order-id="<?php echo esc_attr($order_id); ?>"
                                data-max-size="<?php echo esc_attr($limits['effective_limit']); ?>">
                            <?php _e('Upload File', 'custom-jewelry-system'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Simple Lightbox Modal -->
            <div id="cjs-simple-lightbox" class="cjs-modal" style="display: none;">
                <div class="cjs-modal-content" style="max-width: 90%; max-height: 90%; margin: 5% auto; padding: 20px; text-align: center; background: transparent; border: none; box-shadow: none;">
                    <span class="cjs-modal-close" style="position: absolute; right: 15px; top: 10px; font-size: 28px; cursor: pointer; color: white; background: rgba(0,0,0,0.5); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">&times;</span>
                    <img id="cjs-lightbox-image" src="" alt="" style="max-width: 100%; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); cursor: zoom-in;">
                    <div class="cjs-lightbox-controls" style="margin-top: 15px;">
                        <button type="button" class="button" id="cjs-zoom-in" title="Zoom In">+</button>
                        <button type="button" class="button" id="cjs-zoom-out" title="Zoom Out">−</button>
                        <button type="button" class="button" id="cjs-zoom-reset" title="Reset Zoom">Reset</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save order meta (legacy hook)
     */
    public static function save_order_meta($order_id) {
        if (!isset($_POST['cjs_order_meta_nonce']) || 
            !wp_verify_nonce($_POST['cjs_order_meta_nonce'], 'cjs_order_meta')) {
            return;
        }
        
        self::save_order_data($order_id);
    }
    
    /**
     * Save order meta (HPOS hook)
     */
    public static function save_order_meta_hpos($order) {
        if (!isset($_POST['cjs_order_meta_nonce']) || 
            !wp_verify_nonce($_POST['cjs_order_meta_nonce'], 'cjs_order_meta')) {
            return;
        }
        
        $order_id = is_object($order) ? $order->get_id() : $order;
        self::save_order_data($order_id);
    }
    
    /**
     * Common save method for both legacy and HPOS
     */
    private static function save_order_data($order_id) {
        $data = [
            'finish_by_date' => sanitize_text_field($_POST['cjs_finish_by_date'] ?? ''),
            'deliver_by_date' => sanitize_text_field($_POST['cjs_deliver_by_date'] ?? ''),
            'order_model' => isset($_POST['cjs_order_model']) ? 1 : 0,
            'order_production' => isset($_POST['cjs_order_production']) ? 1 : 0,
            'casting_notes' => sanitize_textarea_field($_POST['cjs_casting_notes'] ?? ''),
            'order_printing' => isset($_POST['cjs_order_printing']) ? 1 : 0,
            'manufacturing_status' => sanitize_text_field($_POST['cjs_manufacturing_status'] ?? '')
        ];
        
        self::update_order_extension($order_id, $data);
        
        CJS_Logger::log('Order details updated', 'info', 'order', $order_id, $data);
    }
    
    /**
     * Get order extension data - FIXED VERSION (handles NULL values and new field)
     */
    public static function get_order_extension($order_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
            $order_id
        ), ARRAY_A);
        
        if (!$data) {
            return [
                'finish_by_date' => '',
                'deliver_by_date' => '',
                'order_model' => 0,
                'order_production' => 0,
                'casting_notes' => '',
                'order_printing' => 0,
                'manufacturing_status' => ''
            ];
        }
        
        // Get order date to calculate default dates if needed
        $order = wc_get_order($order_id);
        $order_date = $order ? $order->get_date_created() : new DateTime();
        
        if (!$order_date) {
            $order_date = new DateTime();
        }
        
        // Check if dates are empty or invalid and set defaults
        $finish_by_date = $data['finish_by_date'] ?? '';
        $deliver_by_date = $data['deliver_by_date'] ?? '';
        
        // If dates are empty, null, or invalid (0000-00-00), set defaults
        if (empty($finish_by_date) || $finish_by_date === '0000-00-00' || $finish_by_date === 'null') {
            $finish_date = clone $order_date;
            $finish_date->add(new DateInterval('P8W'));
            $finish_by_date = $finish_date->format('Y-m-d');
            
            // Update the database with the default date
            self::update_order_extension($order_id, ['finish_by_date' => $finish_by_date]);
        }
        
        if (empty($deliver_by_date) || $deliver_by_date === '0000-00-00' || $deliver_by_date === 'null') {
            $deliver_date = clone $order_date;
            $deliver_date->add(new DateInterval('P10W'));
            $deliver_by_date = $deliver_date->format('Y-m-d');
            
            // Update the database with the default date
            self::update_order_extension($order_id, ['deliver_by_date' => $deliver_by_date]);
        }
        
        // Ensure no NULL values
        return [
            'finish_by_date' => $finish_by_date,
            'deliver_by_date' => $deliver_by_date,
            'order_model' => $data['order_model'] ?? 0,
            'order_production' => $data['order_production'] ?? 0,
            'casting_notes' => $data['casting_notes'] ?? '',
            'order_printing' => $data['order_printing'] ?? 0,
            'manufacturing_status' => $data['manufacturing_status'] ?? ''
        ];
    }
    
    /**
     * Create order extension (ensure it exists)
     */
    public static function create_order_extension($order_id) {
        global $wpdb;
        
        if (!$order_id) {
            return false;
        }
        
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cjs_order_extensions WHERE order_id = %d",
            $order_id
        ));
        
        if (!$exists) {
            // Get order date to calculate default dates
            $order = wc_get_order($order_id);
            $order_date = $order ? $order->get_date_created() : new DateTime();
            
            if (!$order_date) {
                $order_date = new DateTime();
            }
            
            // Calculate default dates: 8 weeks for finish, 10 weeks for deliver
            $finish_date = clone $order_date;
            $finish_date->add(new DateInterval('P8W'));
            
            $deliver_date = clone $order_date;
            $deliver_date->add(new DateInterval('P10W'));
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'cjs_order_extensions',
                [
                    'order_id' => $order_id,
                    'finish_by_date' => $finish_date->format('Y-m-d'),
                    'deliver_by_date' => $deliver_date->format('Y-m-d'),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
            
            if ($result === false) {
                error_log('CJS: Failed to create order extension for order ' . $order_id . '. Error: ' . $wpdb->last_error);
                return false;
            }
            
            return $wpdb->insert_id;
        }
        
        return $exists;
    }
    /**
     * Render modals for order extension pages
     */
    public static function render_order_modals() {
        // Only render on order edit pages
        global $pagenow;
        $is_order_page = false;
        
        // Check for legacy order edit
        if (in_array($pagenow, ['post.php', 'post-new.php'])) {
            global $post_type;
            if ($post_type === 'shop_order') {
                $is_order_page = true;
            }
        }
        
        // Check for HPOS order edit
        if (isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action'])) {
            $is_order_page = true;
        }
        
        if ($is_order_page) {
            CJS_Modals::render_modals();
        }
    }
    /**
     * Update order extension
     */
    public static function update_order_extension($order_id, $data = null) {
        global $wpdb;
        
        // Ensure extension exists
        self::create_order_extension($order_id);
        
        if ($data && !empty($data)) {
            // Add updated timestamp
            $data['updated_at'] = current_time('mysql');
            
            $result = $wpdb->update(
                $wpdb->prefix . 'cjs_order_extensions',
                $data,
                ['order_id' => $order_id],
                null, // Let wpdb determine format
                ['%d']
            );
            
            if ($result === false) {
                error_log('CJS: Failed to update order extension for order ' . $order_id . '. Error: ' . $wpdb->last_error);
                return false;
            }
            
            return true;
        }
        
        return true;
    }
    
    /**
     * Get order files
     */
    public static function get_order_files($order_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cjs_order_files WHERE order_id = %d ORDER BY uploaded_at DESC",
            $order_id
        ));
    }
    
    /**
     * Get secure file URL
     */
    public static function get_file_url($file_path) {
        // Use a more robust encoding that handles special characters better
        $encoded_path = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($file_path));
        return admin_url('admin-ajax.php?action=cjs_download_file&file=' . 
                        $encoded_path . '&nonce=' . wp_create_nonce('cjs_file_download'));
    }
    
    /**
     * Add custom columns to orders list (HPOS compatible)
     */
    public static function add_order_columns($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            if ('order_status' === $key) {
                $new_columns['cjs_finish_by'] = __('Užprabuoti iki', 'custom-jewelry-system');
                $new_columns['cjs_deliver_by'] = __('Pristatyti iki', 'custom-jewelry-system');
                $new_columns['cjs_manufacturing_status'] = __('Gamybos statusas', 'custom-jewelry-system');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render custom columns (HPOS compatible)
     */
    public static function render_order_columns($column, $order) {
        $order_id = is_object($order) ? $order->get_id() : $order;
        $data = self::get_order_extension($order_id);
        
        switch ($column) {
            case 'cjs_finish_by':
                if ($data['finish_by_date']) {
                    echo esc_html(date_i18n('Y-m-d', strtotime($data['finish_by_date'])));
                }
                break;
                
            case 'cjs_deliver_by':
                if ($data['deliver_by_date']) {
                    $days_left = self::calculate_days_left($data['deliver_by_date']);
                    $color = self::get_date_color($days_left);
                    
                    echo sprintf(
                        '<span style="color: %s;">%s (%s)</span>',
                        $color,
                        date_i18n('Y-m-d', strtotime($data['deliver_by_date'])),
                        $days_left >= 0 ? sprintf(__('%d days', 'custom-jewelry-system'), $days_left) : 
                                         sprintf(__('Overdue %d days', 'custom-jewelry-system'), abs($days_left))
                    );
                }
                break;
                
            case 'cjs_manufacturing_status':
                echo esc_html($data['manufacturing_status']);
                break;
        }
    }
    
    /**
     * Calculate days left
     */
    private static function calculate_days_left($date) {
        $target = new DateTime($date);
        $today = new DateTime();
        $diff = $today->diff($target);
        
        return $target >= $today ? $diff->days : -$diff->days;
    }
    
    /**
     * Get color based on days left
     */
    private static function get_date_color($days) {
        if ($days < 0) return '#dc3545'; // Red for overdue
        if ($days < 5) return '#dc3545'; // Red
        if ($days < 14) return '#ffc107'; // Yellow
        return '#28a745'; // Green
    }
    
    /**
     * Get ordered manufacturing statuses for dropdown
     */
    public static function get_ordered_manufacturing_statuses() {
        return self::get_ordered_options('manufacturing_statuses');
    }
    
    /**
     * Get ordered options from database
     */
    public static function get_ordered_options($option_type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cjs_options_sort_order';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$table_name} WHERE option_type = %s ORDER BY sort_order ASC",
                $option_type
            ),
            ARRAY_A
        );
        
        if (empty($results)) {
            // Fallback to option if table is empty
            return get_option('cjs_' . $option_type, []);
        }
        
        return array_column($results, 'option_value');
    }
    
    /**
     * Add stone button to order items (for WooCommerce order items table)
     */
    public static function add_stone_button_to_items($item) {
        if ($item->get_type() !== 'line_item') {
            return;
        }
        ?>
        <button type="button" class="button cjs-add-stone-to-item" 
                data-order-id="<?php echo esc_attr($item->get_order_id()); ?>"
                data-order-item-id="<?php echo esc_attr($item->get_id()); ?>">
            <?php _e('Manage Stones', 'custom-jewelry-system'); ?>
        </button>
        <?php
    }
}