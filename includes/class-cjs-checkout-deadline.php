<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Checkout_Deadline {

    const META_KEY = 'cjs_custom_deadline';

    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_action('woocommerce_after_checkout_billing_form', [__CLASS__, 'checkout_display'], 20, 1);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate'], 10, 2);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_order_meta'], 20, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_notice'], 10, 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
    }

    private static function posted_choice() {
        $raw = isset($_POST['cjs_custom_deadline']) ? sanitize_text_field(wp_unslash($_POST['cjs_custom_deadline'])) : 'no';
        return $raw === 'yes' ? 'yes' : 'no';
    }

    private static function posted_date() {
        return isset($_POST['cjs_custom_deadline_date']) ? sanitize_text_field(wp_unslash($_POST['cjs_custom_deadline_date'])) : '';
    }

    private static function validation_error($date) {
        if (!CJS_Deadlines::is_valid_ymd($date)) {
            return __('Pasirinkite sutartą gamybos terminą kalendoriuje.', 'custom-jewelry-system');
        }
        $earliest = CJS_Deadlines::earliest_custom_date();
        if ($date < $earliest) {
            return sprintf(
                __('Sutartas gamybos terminas negali būti ankstesnis nei %1$s (mažiausiai %2$d d. nuo užsakymo).', 'custom-jewelry-system'),
                date_i18n(get_option('date_format'), strtotime($earliest . ' 00:00:00')),
                CJS_Deadlines::min_days()
            );
        }
        $latest = CJS_Deadlines::latest_custom_date();
        if ($latest !== '' && $date > $latest) {
            return sprintf(
                __('Sutartas gamybos terminas negali būti vėlesnis nei %1$s (daugiausiai %2$d d. nuo užsakymo).', 'custom-jewelry-system'),
                date_i18n(get_option('date_format'), strtotime($latest . ' 00:00:00')),
                CJS_Deadlines::max_days()
            );
        }
        return '';
    }

    public static function checkout_display($checkout) {
        if (!CJS_Deadlines::is_checkout_enabled()) {
            return;
        }

        $choice = self::posted_choice();
        $date = self::posted_date();
        $earliest = CJS_Deadlines::earliest_custom_date();
        $latest = CJS_Deadlines::latest_custom_date();
        ?>
        <div id="cjs_custom_deadline_field" class="cjs-custom-deadline">
            <p class="form-row form-row-wide cjs-custom-deadline-question">
                <span class="cjs-custom-deadline-label"><?php esc_html_e('Ar buvome sutarę individualų gamybos terminą?', 'custom-jewelry-system'); ?></span>
                <span class="cjs-custom-deadline-options">
                    <label class="cjs-custom-deadline-option">
                        <input type="radio" name="cjs_custom_deadline" value="no" <?php checked($choice, 'no'); ?> />
                        <span><?php esc_html_e('Ne', 'custom-jewelry-system'); ?></span>
                    </label>
                    <label class="cjs-custom-deadline-option">
                        <input type="radio" name="cjs_custom_deadline" value="yes" <?php checked($choice, 'yes'); ?> />
                        <span><?php esc_html_e('Taip', 'custom-jewelry-system'); ?></span>
                    </label>
                </span>
            </p>
            <p class="form-row form-row-wide cjs-custom-deadline-date-row<?php echo $choice === 'yes' ? '' : ' cjs-hidden'; ?>">
                <label for="cjs_custom_deadline_date"><?php esc_html_e('Sutarta pagaminimo data', 'custom-jewelry-system'); ?> <abbr class="required" title="<?php esc_attr_e('privaloma', 'custom-jewelry-system'); ?>">*</abbr></label>
                <span class="woocommerce-input-wrapper">
                    <input type="text" id="cjs_custom_deadline_date" name="cjs_custom_deadline_date" class="input-text cjs-custom-deadline-input"
                           value="<?php echo esc_attr($date); ?>"
                           data-min-date="<?php echo esc_attr($earliest); ?>"
                           data-max-date="<?php echo esc_attr($latest); ?>"
                           placeholder="<?php esc_attr_e('Pasirinkite datą', 'custom-jewelry-system'); ?>"
                           autocomplete="off" readonly />
                </span>
            </p>
        </div>
        <?php
    }

    public static function validate($data, $errors) {
        if (!CJS_Deadlines::is_checkout_enabled()) {
            return;
        }
        if (self::posted_choice() !== 'yes') {
            return;
        }
        $message = self::validation_error(self::posted_date());
        if ($message !== '') {
            $errors->add('cjs_custom_deadline', $message);
        }
    }

    public static function save_order_meta($order_id, $data = null) {
        if (!CJS_Deadlines::is_checkout_enabled()) {
            return;
        }
        if (self::posted_choice() !== 'yes') {
            return;
        }
        $date = self::posted_date();
        if (self::validation_error($date) !== '') {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order->update_meta_data(self::META_KEY, $date);
        $order->save();

        CJS_Order_Extension::update_order_extension($order_id, CJS_Deadlines::dates_from_deliver($date));

        if (class_exists('CJS_Logger')) {
            CJS_Logger::log('Klientas nurodė sutartą gamybos terminą', 'info', 'order', $order_id, ['deliver_by_date' => $date]);
        }
    }

    public static function display_admin_notice($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $date = $order->get_meta(self::META_KEY);
        if (!CJS_Deadlines::is_valid_ymd($date)) {
            return;
        }
        echo '<p><strong>' . esc_html(sprintf(__('Klientas nurodė sutartą gamybos terminą: %s', 'custom-jewelry-system'), $date)) . '</strong></p>';
    }

    public static function enqueue_assets() {
        if (is_admin()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return;
        }
        if (!CJS_Deadlines::is_checkout_enabled()) {
            return;
        }

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style(
            'cjs-checkout-deadline',
            CJS_PLUGIN_URL . 'assets/css/checkout-deadline.css',
            [],
            CJS_VERSION
        );
        wp_enqueue_script(
            'cjs-checkout-deadline',
            CJS_PLUGIN_URL . 'assets/js/checkout-deadline.js',
            ['jquery', 'jquery-ui-datepicker'],
            CJS_VERSION,
            true
        );
        wp_localize_script('cjs-checkout-deadline', 'cjs_checkout_deadline', [
            'min_date' => CJS_Deadlines::earliest_custom_date(),
            'max_date' => CJS_Deadlines::latest_custom_date(),
            'month_names' => ['Sausis', 'Vasaris', 'Kovas', 'Balandis', 'Gegužė', 'Birželis', 'Liepa', 'Rugpjūtis', 'Rugsėjis', 'Spalis', 'Lapkritis', 'Gruodis'],
            'day_names_min' => ['Sk', 'Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št'],
            'prev_text' => __('Ankstesnis', 'custom-jewelry-system'),
            'next_text' => __('Kitas', 'custom-jewelry-system')
        ]);
    }
}
