<?php
/**
 * Size Kit Checkout - modal consent and settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Size_Kit {

    /**
     * Initialize hooks (frontend + admin)
     */
    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_after_checkout_billing_form', [__CLASS__, 'checkout_display'], 10, 1);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_order_meta'], 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_notice'], 10, 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
    }

    /**
     * Check if the size kit feature is enabled
     */
    public static function is_enabled() {
        return (bool) get_option('cjs_size_kit_enabled', false);
    }

    /**
     * Get category IDs that qualify for the size kit
     */
    public static function get_qualifying_categories() {
        $ids = get_option('cjs_size_kit_categories', []);
        return is_array($ids) ? array_map('absint', $ids) : [];
    }

    /**
     * Check if cart contains a product in any qualifying category
     */
    private static function cart_has_qualifying_product() {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        $categories = self::get_qualifying_categories();
        if (empty($categories)) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            // Use parent product ID for variations (categories are on the parent)
            $product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
            foreach ($categories as $term_id) {
                if (has_term($term_id, 'product_cat', $product_id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Display size kit checkbox and modal on checkout (below billing)
     */
    public static function checkout_display($checkout) {
        if (!self::is_enabled()) {
            return;
        }
        if (!self::cart_has_qualifying_product()) {
            return;
        }

        $modal_text = get_option('cjs_size_kit_modal_text', __('Missing Text, Check Settings to add', 'custom-jewelry-system'));
        if (trim($modal_text) === '') {
            $modal_text = __('Missing Text, Check Settings to add', 'custom-jewelry-system');
        }

        echo '<div id="size_kit_checkout_field">';
        woocommerce_form_field('size_kit_checkbox', [
            'type'          => 'checkbox',
            'class'         => ['form-row-wide'],
            'label_class'   => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class'   => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'required'      => false,
            'label'         => 'Noriu gauti <strong>Žiedų dydžio matavimo rinkinį</strong> ir sutinku su <a href="https://juvelyrasjuozas.lt/siuntimo-grazinimo-taisykles/" target="_blank">5. Žiedų dydžio matavimo rinkinio naudojimo ir grąžinimo sąlygomis</a>.',
        ], $checkout->get_value('size_kit_checkbox'));
        echo '</div>';

        echo '<div id="cjs-size-kit-modal" class="cjs-size-kit-modal" role="dialog" aria-modal="true" aria-labelledby="cjs-size-kit-modal-title" hidden>';
        echo '<div class="cjs-size-kit-modal-backdrop"></div>';
        echo '<div class="cjs-size-kit-modal-panel">';
        echo '<h2 id="cjs-size-kit-modal-title" class="cjs-size-kit-modal-title">' . esc_html__('Matavimo rinkinio sąlygos', 'custom-jewelry-system') . '</h2>';
        echo '<div class="cjs-size-kit-modal-content">' . wp_kses_post($modal_text) . '</div>';
        echo '<div class="cjs-size-kit-modal-actions">';
        echo '<button type="button" class="button cjs-size-kit-btn-decline">' . esc_html__('Nesutinku', 'custom-jewelry-system') . '</button>';
        echo '<button type="button" class="button button-primary cjs-size-kit-btn-accept">' . esc_html__('Sutinku', 'custom-jewelry-system') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Save checkbox value to order meta (HPOS compatible)
     */
    public static function save_order_meta($order_id, $data = null) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (isset($_POST['size_kit_checkbox']) && $_POST['size_kit_checkbox']) {
            $order->update_meta_data('size_kit_requested', 'yes');
            $order->save();
        }
    }

    /**
     * Display size kit notice in admin order view (HPOS compatible)
     */
    public static function display_admin_notice($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $value = $order->get_meta('size_kit_requested');
        if ($value === 'yes') {
            echo '<p><strong>' . esc_html__('Klientas pasirinko gauti Žiedų dydžio matavimo rinkinį.', 'custom-jewelry-system') . '</strong></p>';
        }
    }

    /**
     * Enqueue checkout assets only when feature is enabled and on checkout
     */
    public static function enqueue_assets() {
        if (is_admin()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!self::is_enabled()) {
            return;
        }
        if (!self::cart_has_qualifying_product()) {
            return;
        }

        wp_enqueue_style(
            'cjs-size-kit-checkout',
            CJS_PLUGIN_URL . 'assets/css/size-kit-checkout.css',
            [],
            CJS_VERSION
        );
        wp_enqueue_script(
            'cjs-size-kit-checkout',
            CJS_PLUGIN_URL . 'assets/js/size-kit-checkout.js',
            ['jquery'],
            CJS_VERSION,
            true
        );
    }
}
