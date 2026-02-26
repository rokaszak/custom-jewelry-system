<?php
/**
 * Centralized Modal Renderer for Custom Jewelry System
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Modals {
    
    /**
     * Render all modals needed for the current page context
     */
    public static function render_modals($context = 'all') {
        // Always render stone modal (used everywhere)
        self::render_stone_modal();
        
        // Always render stone order modals
        self::render_stone_order_modal();
        self::render_stone_order_edit_modal();
        self::render_stone_order_view_modal();
        
        // Always render utility modals
        self::render_stone_assignment_modal();
        self::render_stone_selection_modal();
        self::render_whatsapp_modal();
    }
    
    /**
     * Render stone modal (for add/edit stones)
     */
    public static function render_stone_modal() {
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
                    
                    <div id="cjs-stone-product-selector" style="display:none; margin-bottom: 15px;">
                        <label><strong><?php _e('Select Product:', 'custom-jewelry-system'); ?></strong></label>
                        <select id="stone-product-select" style="width: 100%;">
                            <option value=""><?php _e('Select a product...', 'custom-jewelry-system'); ?></option>
                        </select>
                    </div>
                    
                    <div id="cjs-stone-product-info" style="display:none; background: #f0f0f0; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
                        <strong><?php _e('Product:', 'custom-jewelry-system'); ?></strong> <span id="stone-product-name"></span>
                    </div>
                    
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
        <?php
    }
    
    /**
     * Render stone order modal (for creating stone orders)
     */
    public static function render_stone_order_modal() {
        ?>
        <div id="cjs-stone-order-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content">
                <span class="cjs-modal-close">&times;</span>
                <h2><span id="cjs-stone-order-modal-title"><?php _e('Create Stone Order', 'custom-jewelry-system'); ?></span></h2>
                <form id="cjs-stone-order-form">
                    <input type="hidden" id="stone-order-edit-id" />
                    <input type="hidden" id="stone-order-source-order-id" />
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Order Number', 'custom-jewelry-system'); ?></label>
                        <input type="text" id="stone-order-number" placeholder="<?php esc_attr_e('Leave empty for auto-generated number', 'custom-jewelry-system'); ?>" />
                        <small style="color: #666; display: block; margin-top: 5px;">
                            <?php _e('If left empty, order number will be automatically assigned', 'custom-jewelry-system'); ?>
                        </small>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Order Date', 'custom-jewelry-system'); ?> *</label>
                        <input type="date" id="stone-order-date" required value="<?php echo date('Y-m-d'); ?>" />
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Status', 'custom-jewelry-system'); ?> *</label>
                        <select id="stone-order-status" required>
                            <?php
                            $statuses = get_option('cjs_stone_order_statuses', []);
                            foreach ($statuses as $key => $status) {
                                echo '<option value="' . esc_attr($key) . '">' . esc_html($status['label']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Select Stones for this Order', 'custom-jewelry-system'); ?></label>
                        <div id="cjs-order-stones-info" style="background: #e7f3ff; padding: 10px; border-radius: 5px; margin-bottom: 10px; display: none;">
                            <strong><?php _e('From Order:', 'custom-jewelry-system'); ?></strong> <span id="cjs-source-order-info"></span>
                        </div>
                        <select id="stone-order-stones" multiple style="width: 100%; height: 200px;">
                            <!-- Populated via AJAX -->
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            <?php _e('Hold Ctrl/Cmd to select multiple stones. Only unassigned stones are shown.', 'custom-jewelry-system'); ?>
                        </small>
                        
                        <div id="cjs-quick-select-buttons" style="margin-top: 10px; display: none;">
                            <button type="button" class="button button-small" id="cjs-select-all-order-stones">
                                <?php _e('Select All from This Order', 'custom-jewelry-system'); ?>
                            </button>
                            <button type="button" class="button button-small" id="cjs-select-none-stones">
                                <?php _e('Select None', 'custom-jewelry-system'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="cjs-form-actions">
                        <button type="submit" class="button button-primary">
                            <span id="cjs-stone-order-submit-text"><?php _e('Create Order', 'custom-jewelry-system'); ?></span>
                        </button>
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Cancel', 'custom-jewelry-system'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render stone order edit modal
     */
    public static function render_stone_order_edit_modal() {
        ?>
        <div id="cjs-stone-order-edit-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content">
                <span class="cjs-modal-close">&times;</span>
                <h2><?php _e('Edit Stone Order', 'custom-jewelry-system'); ?></h2>
                <form id="cjs-stone-order-edit-form">
                    <input type="hidden" id="edit-stone-order-id" />
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Order Number', 'custom-jewelry-system'); ?></label>
                        <input type="text" id="edit-stone-order-number" />
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Order Date', 'custom-jewelry-system'); ?></label>
                        <input type="date" id="edit-stone-order-date" />
                    </div>
                    
                    <div class="cjs-form-row">
                        <label><?php _e('Status', 'custom-jewelry-system'); ?></label>
                        <select id="edit-stone-order-status">
                            <?php
                            $statuses = get_option('cjs_stone_order_statuses', []);
                            foreach ($statuses as $key => $status) {
                                echo '<option value="' . esc_attr($key) . '">' . esc_html($status['label']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="cjs-form-row">
                        <h4><?php _e('Stones in this Order', 'custom-jewelry-system'); ?></h4>
                        <div id="edit-stone-order-stones-list" style="background: #f0f0f0; padding: 10px; border-radius: 5px; margin-bottom: 10px; max-height: 200px; overflow-y: auto;">
                            <!-- Stones will be populated here -->
                        </div>
                    </div>
                    
                    <div class="cjs-form-actions">
                        <button type="submit" class="button button-primary"><?php _e('Save Changes', 'custom-jewelry-system'); ?></button>
                        <button type="button" class="button cjs-modal-cancel"><?php _e('Cancel', 'custom-jewelry-system'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render stone order view modal
     */
    public static function render_stone_order_view_modal() {
        ?>
        <div id="cjs-stone-order-view-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content" style="max-width: 800px;">
                <span class="cjs-modal-close">&times;</span>
                <h2><?php _e('Stone Order Details', 'custom-jewelry-system'); ?></h2>
                
                <div id="cjs-stone-order-details-content">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render stone assignment modal
     */
    public static function render_stone_assignment_modal() {
        ?>
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
    
    /**
     * Render stone selection modal
     */
    public static function render_stone_selection_modal() {
        ?>
        <div id="cjs-stone-selection-modal" class="cjs-modal" style="display:none;">
            <div class="cjs-modal-content">
                <span class="cjs-modal-close">&times;</span>
                <h2><?php _e('Add Stones to Order', 'custom-jewelry-system'); ?></h2>
                <input type="hidden" id="cjs-current-stone-order-id" />
                
                <div class="cjs-stone-selection-list">
                    <p><?php _e('Select stones to add to this order:', 'custom-jewelry-system'); ?></p>
                    <select id="cjs-available-stones-modal" multiple style="width: 100%; height: 300px;">
                        <!-- Populated via AJAX -->
                    </select>
                </div>
                
                <div class="cjs-form-actions">
                    <button type="button" class="button button-primary" id="cjs-add-selected-stones-modal">
                        <?php _e('Add Selected Stones', 'custom-jewelry-system'); ?>
                    </button>
                    <button type="button" class="button cjs-modal-cancel"><?php _e('Cancel', 'custom-jewelry-system'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render WhatsApp modal
     */
    public static function render_whatsapp_modal() {
        ?>
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
        <?php
    }
}