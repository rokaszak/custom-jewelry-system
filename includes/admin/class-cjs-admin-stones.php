<?php
/**
 * Admin Required Stones List Page - UPDATED with size units support
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Stones {
    
    /**
     * Render the stones list page
     */
    public static function render_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Handle single stone view
        if (isset($_GET['stone_id'])) {
            self::render_single_stone(intval($_GET['stone_id']));
            return;
        }
        
        // Get stones
        $result = CJS_Stone::get_stones([
            'page' => $page,
            'per_page' => 20,
            'search' => $search
        ]);
        
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Reikalingi akmenys', 'custom-jewelry-system'); ?>
                <a href="#" class="page-title-action" id="cjs-add-new-stone">
                    <?php _e('Add New', 'custom-jewelry-system'); ?>
                </a>
            </h1>
            
            <div class="cjs-filters">
                <form method="get" class="cjs-search-form">
                    <input type="hidden" name="page" value="cjs-required-stones" />
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php esc_attr_e('Search stones...', 'custom-jewelry-system'); ?>" />
                    <button type="submit" class="button"><?php _e('Search', 'custom-jewelry-system'); ?></button>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped cjs-stones-table">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('ID', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Type', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Origin', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Shape', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Qty', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Size', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Color', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Order / Product', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Stone Order', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Comment', 'custom-jewelry-system'); ?></th>
                        <th><?php _e('Actions', 'custom-jewelry-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($result['stones'])) {
                        echo '<tr><td colspan="11">' . __('No stones found', 'custom-jewelry-system') . '</td></tr>';
                    } else {
                        foreach ($result['stones'] as $stone) {
                            self::render_stone_row($stone);
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
        
        <!-- Reuse stone modal from orders page -->
        <?php self::render_stone_modal(); ?>
        <?php
    }
    
    /**
     * Render stone row with inline editing - UPDATED for size units
     */
    private static function render_stone_row($stone) {
        $stone_order = $stone->get_stone_order();
        $stone_id = $stone->get('id');
        ?>
        <tr data-stone-id="<?php echo esc_attr($stone_id); ?>" class="cjs-stone-row">
            <td><?php echo esc_html($stone_id); ?></td>
            <td>
                <select class="cjs-inline-stone-edit" data-stone-id="<?php echo esc_attr($stone_id); ?>" data-field="stone_type">
                    <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                    <?php
                    $types = get_option('cjs_stone_types', []);
                    foreach ($types as $value => $label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($value),
                            selected($stone->get('stone_type'), $value, false),
                            esc_html($value)
                        );
                    }
                    ?>
                </select>
            </td>
            <td>
                <select class="cjs-inline-stone-edit" data-stone-id="<?php echo esc_attr($stone_id); ?>" data-field="stone_origin">
                    <?php
                    $origins = get_option('cjs_stone_origins', []);
                    foreach ($origins as $value => $label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($value),
                            selected($stone->get('stone_origin'), $value, false),
                            esc_html($value)
                        );
                    }
                    ?>
                </select>
            </td>
            <td>
                <select class="cjs-inline-stone-edit" data-stone-id="<?php echo esc_attr($stone_id); ?>" data-field="stone_shape">
                    <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                    <?php
                    $shapes = get_option('cjs_stone_shapes', []);
                    foreach ($shapes as $shape) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($shape),
                            selected($stone->get('stone_shape'), $shape, false),
                            esc_html($shape)
                        );
                    }
                    ?>
                </select>
            </td>
            <td>
                <input type="number" class="cjs-inline-stone-edit cjs-small-input" 
                       data-stone-id="<?php echo esc_attr($stone_id); ?>" 
                       data-field="stone_quantity" 
                       value="<?php echo esc_attr($stone->get('stone_quantity')); ?>" 
                       min="1" />
            </td>
            <td class="cjs-size-cell">
                <!-- UPDATED: Size with unit inline editing -->
                <div class="cjs-size-inputs" style="display: flex; gap: 5px; flex-wrap: wrap;">
                    <input type="number" class="cjs-inline-stone-edit cjs-size-value" 
                           data-stone-id="<?php echo esc_attr($stone_id); ?>" 
                           data-field="stone_size_value" 
                           value="<?php echo esc_attr($stone->get_size_value()); ?>" 
                           step="0.001" min="0" style="width: 60px;" 
                           placeholder="Size" />
                    <select class="cjs-inline-stone-edit cjs-size-unit" 
                            data-stone-id="<?php echo esc_attr($stone_id); ?>" 
                            data-field="stone_size_unit" style="width: 50px;">
                        <?php
                        $size_units = get_option('cjs_stone_size_units', []);
                        foreach ($size_units as $value => $label) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($value),
                                selected($stone->get_size_unit(), $value, false),
                                esc_html($value === 'carats' ? 'ct' : 'mm')
                            );
                        }
                        ?>
                    </select>
                </div>
                <div class="cjs-formatted-size" style="font-size: 11px; color: #666; margin-top: 2px;">
                    <?php echo esc_html($stone->get_formatted_size()); ?>
                </div>
            </td>
            <td>
                <select class="cjs-inline-stone-edit" data-stone-id="<?php echo esc_attr($stone_id); ?>" data-field="stone_color">
                    <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                    <?php
                    $colors = get_option('cjs_stone_colors', []);
                    foreach ($colors as $value => $label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($value),
                            selected($stone->get('stone_color'), $value, false),
                            esc_html($value)
                        );
                    }
                    ?>
                </select>
            </td>
            <td>
                <?php
                if ($stone->get('order_id')) {
                    $order = wc_get_order($stone->get('order_id'));
                    if ($order) {
                        echo '<a href="' . esc_url($order->get_edit_order_url()) . '" target="_blank">';
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
                    echo '<em>' . __('Not linked', 'custom-jewelry-system') . '</em>';
                }
                ?>
            </td>
            <td>
                <?php
                if ($stone_order) {
                    $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                    echo '<a href="' . esc_url(admin_url('admin.php?page=cjs-stone-orders&stone_order_id=' . $stone_order->id)) . '">';
                    echo '#' . esc_html($stone_order->order_number);
                    echo '</a>';
                    echo '<br><span class="cjs-status-badge" style="background-color: ' . esc_attr($status_info['color'] . '20') . '; color: ' . esc_attr($status_info['color']) . ';">';
                    echo esc_html($status_info['label']);
                    echo '</span>';
                } else {
                    echo '<button type="button" class="button button-small cjs-assign-stone-order" ';
                    echo 'data-stone-id="' . esc_attr($stone_id) . '">';
                    echo __('Assign', 'custom-jewelry-system');
                    echo '</button>';
                }
                ?>
            </td>
            <td>
                <textarea class="cjs-inline-stone-edit cjs-small-textarea" 
                          data-stone-id="<?php echo esc_attr($stone_id); ?>" 
                          data-field="custom_comment" 
                          rows="1"><?php echo esc_textarea($stone->get('custom_comment')); ?></textarea>
            </td>
            <td>
                <button type="button" class="button button-small cjs-edit-stone" 
                        data-stone-id="<?php echo esc_attr($stone_id); ?>">
                    <?php _e('Edit', 'custom-jewelry-system'); ?>
                </button>
                <button type="button" class="button button-small cjs-delete-stone" 
                        data-stone-id="<?php echo esc_attr($stone_id); ?>">
                    <?php _e('Delete', 'custom-jewelry-system'); ?>
                </button>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render single stone view with full editing - UPDATED for size units
     */
    private static function render_single_stone($stone_id) {
        $stone = new CJS_Stone($stone_id);
        
        if (!$stone->get('id')) {
            wp_die(__('Stone not found', 'custom-jewelry-system'));
        }
        
        $stone_order = $stone->get_stone_order();
        
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Stone Details', 'custom-jewelry-system'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cjs-required-stones')); ?>" 
                   class="page-title-action"><?php _e('Back to List', 'custom-jewelry-system'); ?></a>
            </h1>
            
            <form id="cjs-stone-single-form" class="cjs-single-form">
                <input type="hidden" id="stone-id" value="<?php echo esc_attr($stone_id); ?>" />
                
                <div class="cjs-stone-details">
                    <div class="cjs-detail-section">
                        <h2><?php _e('Stone Information', 'custom-jewelry-system'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php _e('ID', 'custom-jewelry-system'); ?></th>
                                <td><?php echo esc_html($stone->get('id')); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Type', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_type" id="single-stone-type">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $types = get_option('cjs_stone_types', []);
                                        foreach ($types as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($stone->get('stone_type'), $value, false),
                                                esc_html($value)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Origin', 'custom-jewelry-system'); ?> *</th>
                                <td>
                                    <select name="stone_origin" id="single-stone-origin" required>
                                        <?php
                                        $origins = get_option('cjs_stone_origins', []);
                                        foreach ($origins as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($stone->get('stone_origin'), $value, false),
                                                esc_html($value)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Shape', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_shape" id="single-stone-shape">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $shapes = get_option('cjs_stone_shapes', []);
                                        foreach ($shapes as $shape) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($shape),
                                                selected($stone->get('stone_shape'), $shape, false),
                                                esc_html($shape)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Quantity', 'custom-jewelry-system'); ?> *</th>
                                <td>
                                    <input type="number" name="stone_quantity" id="single-stone-quantity" 
                                           value="<?php echo esc_attr($stone->get('stone_quantity')); ?>" 
                                           min="1" required />
                                </td>
                            </tr>
                            <!-- UPDATED: Size with Unit -->
                            <tr>
                                <th><?php _e('Size', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="number" name="stone_size_value" id="single-stone-size-value" 
                                               value="<?php echo esc_attr($stone->get_size_value()); ?>" 
                                               step="0.001" min="0" style="width: 120px;" 
                                               placeholder="<?php esc_attr_e('Size value', 'custom-jewelry-system'); ?>" />
                                        <select name="stone_size_unit" id="single-stone-size-unit" style="width: 120px;">
                                            <?php
                                            $size_units = get_option('cjs_stone_size_units', []);
                                            foreach ($size_units as $value => $label) {
                                                printf(
                                                    '<option value="%s" %s>%s</option>',
                                                    esc_attr($value),
                                                    selected($stone->get_size_unit(), $value, false),
                                                    esc_html($label)
                                                );
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <small style="color: #666;"><?php _e('Choose between carats (ct) or millimeters (mm)', 'custom-jewelry-system'); ?></small>
                                    <?php if ($stone->get_formatted_size()): ?>
                                        <div style="margin-top: 5px; font-weight: 600; color: #0073aa;">
                                            <?php _e('Current:', 'custom-jewelry-system'); ?> <?php echo esc_html($stone->get_formatted_size()); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Color', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_color" id="single-stone-color">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $colors = get_option('cjs_stone_colors', []);
                                        foreach ($colors as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($stone->get('stone_color'), $value, false),
                                                esc_html($value)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Setting', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_setting" id="single-stone-setting">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $settings = get_option('cjs_stone_settings', []);
                                        foreach ($settings as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($stone->get('stone_setting'), $value, false),
                                                esc_html($value)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Clarity', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_clarity" id="single-stone-clarity">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $clarities = get_option('cjs_stone_clarities', []);
                                        foreach ($clarities as $clarity) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($clarity),
                                                selected($stone->get('stone_clarity'), $clarity, false),
                                                esc_html($clarity)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Cut Grade', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="stone_cut_grade" id="single-stone-cut-grade">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $cut_grades = get_option('cjs_stone_cut_grades', []);
                                        foreach ($cut_grades as $grade) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($grade),
                                                selected($stone->get('stone_cut_grade'), $grade, false),
                                                esc_html($grade)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Origin Country', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <select name="origin_country" id="single-stone-origin-country">
                                        <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                                        <?php
                                        $countries = get_option('cjs_origin_countries', []);
                                        foreach ($countries as $country) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($country),
                                                selected($stone->get('origin_country'), $country, false),
                                                esc_html($country)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Certificate', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <input type="text" name="certificate" id="single-stone-certificate" 
                                           value="<?php echo esc_attr($stone->get('certificate')); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Comment', 'custom-jewelry-system'); ?></th>
                                <td>
                                    <textarea name="custom_comment" id="single-stone-comment" rows="3"><?php 
                                        echo esc_textarea($stone->get('custom_comment')); 
                                    ?></textarea>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php _e('Save Changes', 'custom-jewelry-system'); ?>
                            </button>
                        </p>
                    </div>
                    
                    <div class="cjs-detail-section">
                        <h2><?php _e('Order Information', 'custom-jewelry-system'); ?></h2>
                        <?php if ($stone->get('order_id')): ?>
                            <?php
                            $order = wc_get_order($stone->get('order_id'));
                            if ($order):
                            ?>
                            <p>
                                <strong><?php _e('Order:', 'custom-jewelry-system'); ?></strong> 
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">
                                    #<?php echo esc_html($order->get_order_number()); ?>
                                </a>
                            </p>
                            <p>
                                <strong><?php _e('Customer:', 'custom-jewelry-system'); ?></strong> 
                                <?php echo esc_html($order->get_formatted_billing_full_name()); ?>
                            </p>
                            <?php
                            if ($stone->get('order_item_id')) {
                                $item = $order->get_item($stone->get('order_item_id'));
                                if ($item) {
                                    echo '<p><strong>' . __('Product:', 'custom-jewelry-system') . '</strong> ';
                                    echo esc_html($item->get_name()) . '</p>';
                                }
                            }
                            ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <p><em><?php _e('This stone is not linked to any order.', 'custom-jewelry-system'); ?></em></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cjs-detail-section">
                        <h2><?php _e('Stone Order', 'custom-jewelry-system'); ?></h2>
                        <?php if ($stone_order): ?>
                            <p>
                                <strong><?php _e('Order Number:', 'custom-jewelry-system'); ?></strong> 
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cjs-stone-orders&stone_order_id=' . $stone_order->id)); ?>">
                                    #<?php echo esc_html($stone_order->order_number); ?>
                                </a>
                            </p>
                            <p>
                                <strong><?php _e('Date:', 'custom-jewelry-system'); ?></strong> 
                                <?php echo esc_html($stone_order->order_date); ?>
                            </p>
                            <p>
                                <strong><?php _e('Status:', 'custom-jewelry-system'); ?></strong> 
                                <?php
                                $status_info = (new CJS_Stone_Order($stone_order->id))->get_status_info();
                                echo '<span class="cjs-status-badge" style="background-color: ' . esc_attr($status_info['color'] . '20') . '; color: ' . esc_attr($status_info['color']) . ';">';
                                echo esc_html($status_info['label']);
                                echo '</span>';
                                ?>
                            </p>
                        <?php else: ?>
                            <p><em><?php _e('This stone has not been ordered yet.', 'custom-jewelry-system'); ?></em></p>
                            <button type="button" class="button button-primary cjs-assign-stone-order" 
                                    data-stone-id="<?php echo esc_attr($stone->get('id')); ?>">
                                <?php _e('Assign to Stone Order', 'custom-jewelry-system'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#cjs-stone-single-form').on('submit', function(e) {
                e.preventDefault();
                
                var data = {
                    action: 'cjs_update_stone',
                    nonce: cjs_ajax.nonce,
                    stone_id: $('#stone-id').val()
                };
                
                // Collect all form fields
                $(this).find('input, select, textarea').each(function() {
                    var name = $(this).attr('name');
                    if (name) {
                        data[name] = $(this).val();
                    }
                });
                
                $.post(cjs_ajax.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('Stone updated successfully', 'success');
                        // Refresh the formatted size display if needed
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error updating stone', 'error');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render stone modal (shared) - UPDATED for size units
     */
    private static function render_stone_modal() {
        ?>
        <div id="cjs-stone-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content">
                <span class="cjs-modal-close">&times;</span>
                <h2><span id="cjs-stone-modal-title"><?php _e('Add Required Stone', 'custom-jewelry-system'); ?></span></h2>
                <form id="cjs-stone-form">
                    <input type="hidden" id="stone-id" />
                    <input type="hidden" id="stone-order-id" />
                    <input type="hidden" id="stone-order-item-id" />
                    <input type="hidden" id="stone-edit-mode" value="0" />
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Tipas', 'custom-jewelry-system'); ?></label>
                        <select id="stone-type">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $types = get_option('cjs_stone_types', []);
                            foreach ($types as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_types')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Kilmė', 'custom-jewelry-system'); ?> *</label>
                        <select id="stone-origin" required>
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $origins = get_option('cjs_stone_origins', []);
                            foreach ($origins as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_origins')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Forma', 'custom-jewelry-system'); ?></label>
                        <select id="stone-shape">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $shapes = get_option('cjs_stone_shapes', []);
                            foreach ($shapes as $shape) {
                                echo '<option value="' . esc_attr($shape) . '">' . esc_html($shape) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_shapes')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Kiekis', 'custom-jewelry-system'); ?> *</label>
                        <input type="number" id="stone-quantity" min="1" required />
                    </div>
                    
                    <!-- UPDATED: Stone Size with Unit Selector -->
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Dydis', 'custom-jewelry-system'); ?></label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" id="stone-size-value" step="0.001" min="0" style="flex: 2;" placeholder="<?php esc_attr_e('Size value', 'custom-jewelry-system'); ?>" />
                            <select id="stone-size-unit" style="flex: 1;">
                                <?php
                                $size_units = get_option('cjs_stone_size_units', []);
                                foreach ($size_units as $value => $label) {
                                    $selected = $value === 'carats' ? 'selected' : '';
                                    echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <small style="color: #666;"><?php _e('Choose carats (ct) or millimeters (mm)', 'custom-jewelry-system'); ?></small>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Spalva', 'custom-jewelry-system'); ?></label>
                        <select id="stone-color">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $colors = get_option('cjs_stone_colors', []);
                            foreach ($colors as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_colors')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Įtvirtinimas', 'custom-jewelry-system'); ?></label>
                        <select id="stone-setting">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $settings = get_option('cjs_stone_settings', []);
                            foreach ($settings as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_settings')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Skaidrumas', 'custom-jewelry-system'); ?></label>
                        <select id="stone-clarity">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $clarities = get_option('cjs_stone_clarities', []);
                            foreach ($clarities as $clarity) {
                                echo '<option value="' . esc_attr($clarity) . '">' . esc_html($clarity) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_clarities')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Akmens Pjūvio Klasė', 'custom-jewelry-system'); ?></label>
                        <select id="stone-cut-grade">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $cut_grades = get_option('cjs_stone_cut_grades', []);
                            foreach ($cut_grades as $grade) {
                                echo '<option value="' . esc_attr($grade) . '">' . esc_html($grade) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('stone_cut_grades')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Kilmės Šalis', 'custom-jewelry-system'); ?></label>
                        <select id="stone-origin-country">
                            <option value=""><?php _e('Select...', 'custom-jewelry-system'); ?></option>
                            <?php
                            $countries = get_option('cjs_origin_countries', []);
                            foreach ($countries as $country) {
                                echo '<option value="' . esc_attr($country) . '">' . esc_html($country) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button-small" onclick="CJS.addNewOption('origin_countries')"><?php _e('+', 'custom-jewelry-system'); ?></button>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Darbo Akmens Sertifikatas', 'custom-jewelry-system'); ?></label>
                        <input type="text" id="stone-certificate" />
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Custom Comment', 'custom-jewelry-system'); ?></label>
                        <textarea id="stone-comment" rows="3"></textarea>
                    </div>
                    
                    <div class="cjs-form-actions">
                        <button type="submit" class="button button-primary">
                            <span id="cjs-stone-submit-text"><?php _e('Add Stone', 'custom-jewelry-system'); ?></span>
                        </button>
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Cancel', 'custom-jewelry-system'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stone Assignment Modal -->
        <div id="cjs-stone-assignment-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content">
                <span class="cjs-modal-close">&times;</span>
                <h2><?php _e('Assign Stone to Order', 'custom-jewelry-system'); ?></h2>
                <form id="cjs-stone-assignment-form">
                    <input type="hidden" id="assign-stone-id" />
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Stone Information', 'custom-jewelry-system'); ?></label>
                        <div id="assign-stone-info" style="background: #f0f0f0; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                            <!-- Stone info will be populated here -->
                        </div>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Select Stone Order', 'custom-jewelry-system'); ?> *</label>
                        <select id="assign-stone-order-select" required style="width: 100%;">
                            <option value=""><?php _e('Loading stone orders...', 'custom-jewelry-system'); ?></option>
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            <?php _e('Select an existing stone order to assign this stone to', 'custom-jewelry-system'); ?>
                        </small>
                    </div>
                    
                    <div class="cjs-form-actions">
                        <button type="submit" class="button button-primary"><?php _e('Assign Stone', 'custom-jewelry-system'); ?></button>
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Cancel', 'custom-jewelry-system'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}