<?php
/**
 * Admin Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Admin_Settings {
    
    /**
     * Render the settings page
     */
    public static function render_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'options';
        ?>
        <div class="wrap">
            <h1><?php _e('Nustatymai ir žurnalas', 'custom-jewelry-system'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=cjs-settings&tab=options" 
                   class="nav-tab <?php echo $active_tab === 'options' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Dropdown Options', 'custom-jewelry-system'); ?>
                </a>
                <a href="?page=cjs-settings&tab=log" 
                   class="nav-tab <?php echo $active_tab === 'log' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Activity Log', 'custom-jewelry-system'); ?>
                </a>
            </h2>
            
            <div class="cjs-settings-content">
                <?php
                if ($active_tab === 'options') {
                    self::render_options_tab();
                } else {
                    self::render_log_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render options tab
     */
    private static function render_options_tab() {
        $option_types = [
            'stone_types' => [
                'label' => __('Stone Types', 'custom-jewelry-system'),
                'format' => 'key_value',
                'sortable' => true
            ],
            'stone_origins' => [
                'label' => __('Stone Origins', 'custom-jewelry-system'),
                'format' => 'key_value',
                'sortable' => true
            ],
            'stone_shapes' => [
                'label' => __('Stone Shapes', 'custom-jewelry-system'),
                'format' => 'array',
                'sortable' => true
            ],
            'stone_colors' => [
                'label' => __('Stone Colors', 'custom-jewelry-system'),
                'format' => 'key_value',
                'sortable' => true
            ],
            'stone_settings' => [
                'label' => __('Stone Settings', 'custom-jewelry-system'),
                'format' => 'key_value',
                'sortable' => true
            ],
            'stone_clarities' => [
                'label' => __('Stone Clarities', 'custom-jewelry-system'),
                'format' => 'array',
                'sortable' => true
            ],
            'stone_cut_grades' => [
                'label' => __('Stone Cut Grades', 'custom-jewelry-system'),
                'format' => 'array',
                'sortable' => true
            ],
            'origin_countries' => [
                'label' => __('Origin Countries', 'custom-jewelry-system'),
                'format' => 'array',
                'sortable' => true
            ],
            'manufacturing_statuses' => [
                'label' => __('Manufacturing Statuses', 'custom-jewelry-system'),
                'format' => 'array',
                'sortable' => true
            ]
        ];
        ?>
        <div class="cjs-options-manager">
            <?php foreach ($option_types as $option_key => $option_info): ?>
                <div class="cjs-option-section">
                    <h3><?php echo esc_html($option_info['label']); ?></h3>
                    <div class="cjs-option-list" data-option-type="<?php echo esc_attr($option_key); ?>" 
                         data-format="<?php echo esc_attr($option_info['format']); ?>"
                         <?php if (isset($option_info['sortable']) && $option_info['sortable']): ?>
                         data-sortable="true"
                         <?php endif; ?>>
                        <?php
                        if (isset($option_info['sortable']) && $option_info['sortable']) {
                            // Get ordered options from the database
                            $options = self::get_ordered_options($option_key);
                        } else {
                            $options = get_option('cjs_' . $option_key, []);
                        }
                        
                        if ($option_info['format'] === 'key_value') {
                            foreach ($options as $value => $label) {
                                self::render_option_item($value, $label, $option_key);
                            }
                        } else {
                            foreach ($options as $value) {
                                self::render_option_item($value, $value, $option_key);
                            }
                        }
                        ?>
                    </div>
                    <div class="cjs-add-option">
                        <input type="text" class="cjs-new-option-value" 
                               placeholder="<?php esc_attr_e('Value (English)', 'custom-jewelry-system'); ?>" />
                        <?php if ($option_info['format'] === 'key_value'): ?>
                            <input type="text" class="cjs-new-option-label" 
                                   placeholder="<?php esc_attr_e('Label (Lithuanian)', 'custom-jewelry-system'); ?>" />
                        <?php endif; ?>
                        <button type="button" class="button cjs-add-option-btn" 
                                data-option-type="<?php echo esc_attr($option_key); ?>">
                            <?php _e('Add', 'custom-jewelry-system'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Stone Order Statuses (special handling) -->
            <div class="cjs-option-section">
                <h3><?php _e('Stone Order Statuses', 'custom-jewelry-system'); ?></h3>
                <div class="cjs-stone-order-statuses">
                    <?php
                    $statuses = get_option('cjs_stone_order_statuses', []);
                    foreach ($statuses as $key => $status):
                    ?>
                        <div class="cjs-status-item" data-status-key="<?php echo esc_attr($key); ?>">
                            <span class="cjs-status-color" 
                                  style="background-color: <?php echo esc_attr($status['color']); ?>;"></span>
                            <span class="cjs-status-label"><?php echo esc_html($status['label']); ?></span>
                            <span class="cjs-status-key">(<?php echo esc_html($key); ?>)</span>
                            <?php if (!in_array($key, ['reikia_apmoketi', 'apmoketa', 'siunčiama', 'gauta'])): ?>
                                <button type="button" class="button-small cjs-delete-status" 
                                        data-status-key="<?php echo esc_attr($key); ?>">
                                    <?php _e('Delete', 'custom-jewelry-system'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="description">
                    <?php _e('Default statuses cannot be deleted.', 'custom-jewelry-system'); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get ordered options from database
     */
    private static function get_ordered_options($option_type) {
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
        
        $ordered_values = array_column($results, 'option_value');
        
        // For key-value pairs, we need to reconstruct the original structure
        $original_options = get_option('cjs_' . $option_type, []);
        
        if (is_array($original_options) && !empty($original_options)) {
            // Check if this is a key-value array (associative array)
            $is_key_value = array_keys($original_options) !== range(0, count($original_options) - 1);
            
            if ($is_key_value) {
                // Reconstruct key-value pairs in the new order
                $reordered_options = [];
                foreach ($ordered_values as $value) {
                    if (isset($original_options[$value])) {
                        $reordered_options[$value] = $original_options[$value];
                    }
                }
                return $reordered_options;
            }
        }
        
        return $ordered_values;
    }
    
    /**
     * Render single option item
     */
    private static function render_option_item($value, $label, $option_type) {
        // Check if this option type is sortable
        $option_types = [
            'stone_types', 'stone_origins', 'stone_shapes', 'stone_colors',
            'stone_settings', 'stone_clarities', 'stone_cut_grades',
            'origin_countries', 'manufacturing_statuses'
        ];
        $is_sortable = in_array($option_type, $option_types);
        ?>
        <div class="cjs-option-item" data-value="<?php echo esc_attr($value); ?>"
             <?php if ($is_sortable): ?>
             data-sortable="true"
             <?php endif; ?>>
            <?php if ($is_sortable): ?>
                <span class="cjs-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e('Drag to reorder', 'custom-jewelry-system'); ?>"></span>
            <?php endif; ?>
            <span class="cjs-option-value"><?php echo esc_html($value); ?></span>
            <?php if ($value !== $label): ?>
                <span class="cjs-option-label">(<?php echo esc_html($label); ?>)</span>
            <?php endif; ?>
            <button type="button" class="button-small cjs-delete-option" 
                    data-option-type="<?php echo esc_attr($option_type); ?>" 
                    data-value="<?php echo esc_attr($value); ?>">
                <?php _e('Delete', 'custom-jewelry-system'); ?>
            </button>
        </div>
        <?php
    }
    
    /**
     * Render log tab
     */
    private static function render_log_tab() {
        $page = isset($_GET['log_page']) ? max(1, intval($_GET['log_page'])) : 1;
        $search = isset($_GET['log_search']) ? sanitize_text_field($_GET['log_search']) : '';
        $severity = isset($_GET['severity']) ? sanitize_text_field($_GET['severity']) : '';
        
        $args = [
            'page' => $page,
            'per_page' => 50,
            'search' => $search,
            'severity' => $severity
        ];
        
        $result = CJS_Logger::get_logs($args);
        ?>
        <div class="cjs-activity-log">
            <div class="cjs-log-filters">
                <form method="get">
                    <input type="hidden" name="page" value="cjs-settings" />
                    <input type="hidden" name="tab" value="log" />
                    
                    <input type="text" name="log_search" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php esc_attr_e('Search logs...', 'custom-jewelry-system'); ?>" />
                    
                    <select name="severity">
                        <option value=""><?php _e('All Severities', 'custom-jewelry-system'); ?></option>
                        <option value="info" <?php selected($severity, 'info'); ?>><?php _e('Info', 'custom-jewelry-system'); ?></option>
                        <option value="success" <?php selected($severity, 'success'); ?>><?php _e('Success', 'custom-jewelry-system'); ?></option>
                        <option value="warning" <?php selected($severity, 'warning'); ?>><?php _e('Warning', 'custom-jewelry-system'); ?></option>
                        <option value="error" <?php selected($severity, 'error'); ?>><?php _e('Error', 'custom-jewelry-system'); ?></option>
                    </select>
                    
                    <button type="submit" class="button"><?php _e('Filter', 'custom-jewelry-system'); ?></button>
                </form>
            </div>
            
            <div class="cjs-log-entries">
                <?php if (empty($result['logs'])): ?>
                    <p><?php _e('No log entries found.', 'custom-jewelry-system'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 150px;"><?php _e('Time', 'custom-jewelry-system'); ?></th>
                                <th style="width: 100px;"><?php _e('User', 'custom-jewelry-system'); ?></th>
                                <th><?php _e('Action', 'custom-jewelry-system'); ?></th>
                                <th style="width: 100px;"><?php _e('Type', 'custom-jewelry-system'); ?></th>
                                <th style="width: 300px;"><?php _e('Details', 'custom-jewelry-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['logs'] as $log): ?>
                                <tr>
                                    <td>
                                        <span style="color: <?php echo esc_attr(CJS_Logger::get_severity_color($log->severity)); ?>;">●</span>
                                        <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->created_at))); ?>
                                    </td>
                                    <td><?php echo esc_html($log->user_name); ?></td>
                                    <td><?php echo esc_html($log->action); ?></td>
                                    <td>
                                        <?php 
                                        echo esc_html($log->object_type);
                                        if ($log->object_id) {
                                            echo ' #' . esc_html($log->object_id);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($log->details) {
                                            $details = json_decode($log->details, true);
                                            if (is_array($details)) {
                                                echo '<pre style="margin: 0; font-size: 11px;">';
                                                echo esc_html(json_encode($details, JSON_PRETTY_PRINT));
                                                echo '</pre>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <?php
            // Pagination
            if ($result['pages'] > 1) {
                $pagination_args = [
                    'base' => add_query_arg('log_page', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $result['pages']
                ];
                
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links($pagination_args);
                echo '</div></div>';
            }
            ?>
            
            <p class="description">
                <?php _e('Logs are automatically deleted after 30 days.', 'custom-jewelry-system'); ?>
            </p>
        </div>
        <?php
    }
}