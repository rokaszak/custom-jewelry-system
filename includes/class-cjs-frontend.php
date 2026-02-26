<?php
/**
 * Frontend functionality for Custom Jewelry System
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Frontend {
    
    /**
     * Initialize frontend hooks
     */
    public static function init() {
        // Only load on frontend
        if (is_admin()) {
            return;
        }
        
        // Display order extensions BEFORE order details table
        add_action('woocommerce_order_details_before_order_table', [__CLASS__, 'display_order_extensions'], 10, 1);
        
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets']);
    }
    
    /**
     * Enqueue frontend assets
     */
    public static function enqueue_frontend_assets() {
        // Only load on WooCommerce order-related pages
        if (is_wc_endpoint_url('view-order') || is_wc_endpoint_url('order-received')) {
            wp_enqueue_style(
                'cjs-frontend',
                CJS_PLUGIN_URL . 'assets/css/frontend.css',
                [],
                CJS_VERSION
            );
        }
    }
    
    /**
     * Display order extensions on frontend
     */
    public static function display_order_extensions($order) {
        if (!$order || !$order->get_id()) {
            return;
        }
        
        $order_id = $order->get_id();
        $data = CJS_Order_Extension::get_order_extension($order_id);
        
        // Only display if we have delivery information
        if (empty($data['deliver_by_date'])) {
            return;
        }
        
        // Get shipping method
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method = '';
        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods)->get_name();
        }
        
        // Format the date nicely
        $formatted_date = date_i18n(get_option('date_format'), strtotime($data['deliver_by_date']));
        
        // Get order date
        $order_date = $order->get_date_created();
        $order_date_formatted = $order_date ? date_i18n(get_option('date_format'), $order_date->getTimestamp()) : '';
        
        // Calculate days and progress
        $progress_info = self::calculate_progress($order->get_date_created(), $data['deliver_by_date']);
        
        // Get urgency text (but always use normal styling)
        $days_left = $progress_info['days_left'];
        $urgency_info = [
            'class' => '',
            'text' => sprintf(__('Liko %d d.', 'custom-jewelry-system'), $days_left),
            'days_left' => $days_left
        ];
        
        // Get manufacturing status and convert to client-friendly label
        $raw_manufacturing_status = $data['manufacturing_status'] ?? '';
        $manufacturing_status = self::get_client_friendly_status($raw_manufacturing_status);
        
        // Check if order hasn't started yet
        $is_not_started = ($raw_manufacturing_status === 'Nepradėta');
        
        // Check if order is completed
        $is_completed = ($raw_manufacturing_status === 'DONE');
        
        if ($is_completed) {
            $middle_circle_status = 'Vykdoma';
            $middle_circle_class = 'cjs-completed';
        } else {
            $middle_circle_status = $manufacturing_status;
            $middle_circle_class = $is_not_started ? 'cjs-not-started' : 'cjs-pulsing';
        }
        
        // Always use normal status class
        $status_class = 'status-normal';

        $today = new DateTime();
        $today_formatted = date_i18n(get_option('date_format'), $today->getTimestamp());
        
        // For DONE status, check if completed early (before estimated delivery date)
        $is_completed_early = $is_completed && ($today < new DateTime($data['deliver_by_date']));

        
        ?>
        <div class="cjs-delivery-info-frontend">
            <?php if ($shipping_method): ?>
                <div class="cjs-shipping-method">Pristatymas <?php echo esc_html($shipping_method); ?></div>
            <?php endif; ?>
            
            <h3 class="cjs-status-title">Dirbu prie jūsų užsakymo</h3>
            
            <p class="cjs-delivery-disclaimer">
                <?php 
                printf(
                    __('Preliminari pagamino data iki %s. <br><span class="cjs-delivery-disclaimers-disclaimer">Jeigu buvome susitarę kitą gamybos terminą ir jo per kelias dienas nepakeičiau, prašau parašyti man žinutę.</span>', 'custom-jewelry-system'),
                    '<strong>' . esc_html($formatted_date) . '</strong>'
                );
                ?>
            </p>
            
            <div class="cjs-progress-container">
                <div class="cjs-progress-bar-wrapper">
                    <div class="cjs-progress-circle cjs-progress-circle-start"></div>
                    <div class="cjs-progress-bar cjs-progress-bar-first <?php echo $is_not_started ? 'cjs-not-started' : ''; ?>">
                        <div class="cjs-progress-fill"></div>
                    </div>
                    <div class="cjs-progress-circle cjs-progress-circle-middle <?php echo esc_attr($middle_circle_class); ?>">
                    </div>
                    <div class="cjs-progress-bar cjs-progress-bar-second">
                        <div class="cjs-progress-fill" 
                            style="width: <?php echo $is_not_started ? '0' : ($is_completed ? '100' : esc_attr($progress_info['percentage'])); ?>%"></div>
                    </div>
                    <div class="cjs-progress-circle cjs-progress-circle-end <?php echo $is_not_started ? 'cjs-not-started' : ($is_completed ? 'cjs-completed' : 'cjs-in-progress'); ?>"></div>
                </div>
                
                <div class="cjs-progress-labels">
                    <div class="cjs-progress-label-item left">
                        <span class="cjs-progress-label">Užsakyta</span>
                        <span class="cjs-progress-date"><?php echo esc_html($order_date_formatted); ?></span>
                    </div>
                    <div class="cjs-progress-label-item center">
                        <span class="cjs-progress-label">
                            <?php echo esc_html($middle_circle_status); ?>
                        </span>
                        <?php if (!$is_completed): ?>
                            <span class="cjs-progress-date"><?php echo esc_html($today_formatted); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cjs-progress-label-item right">
                        <span class="cjs-progress-label">Išsiųsta</span>
                        <?php if ($is_completed_early): ?>
                            <!-- Hide date if completed early -->
                        <?php else: ?>
                            <span class="cjs-progress-date"><?php echo esc_html($formatted_date); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$is_completed && $urgency_info['days_left'] !== null && $urgency_info['days_left'] >= 1): ?>
                    <div style="width: max-content;margin-top: 2rem;font-weight: 600;color: #bdbdbd;">
                        <?php echo esc_html($urgency_info['text']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Calculate progress percentage and days left
     */
    private static function calculate_progress($order_date, $deliver_by_date) {
        if (empty($deliver_by_date) || !$order_date) {
            return [
                'percentage' => 0,
                'days_left' => null,
                'days_total' => null
            ];
        }
        
        try {
            $start = new DateTime($order_date->format('Y-m-d'));
            $end = new DateTime($deliver_by_date);
            $today = new DateTime();
            
            $total_days = $start->diff($end)->days;
            $elapsed_days = $start->diff($today)->days;
            $days_left = $today->diff($end)->days;
            
            // Calculate percentage (cap at 100%)
            $percentage = $total_days > 0 ? min(100, ($elapsed_days / $total_days) * 100) : 0;
            
            // Determine if overdue
            if ($today > $end) {
                $days_left = -$days_left;
                $percentage = 100;
            }
            
            return [
                'percentage' => round($percentage),
                'days_left' => $days_left,
                'days_total' => $total_days
            ];
        } catch (Exception $e) {
            return [
                'percentage' => 0,
                'days_left' => null,
                'days_total' => null
            ];
        }
    }
    
    /**
     * Calculate days left until delivery
     */
    private static function calculate_days_left($deliver_by_date) {
        if (empty($deliver_by_date)) {
            return null;
        }
        
        try {
            $target = new DateTime($deliver_by_date);
            $today = new DateTime();
            $diff = $today->diff($target);
            
            return $target >= $today ? $diff->days : -$diff->days;
        } catch (Exception $e) {
            return null;
        }
    }
    
    
    /**
     * Convert technical manufacturing status to client-friendly label
     */
    private static function get_client_friendly_status($raw_status) {
        $status_mapping = [
            'Užsakyti modelį' => 'Projektuojama',
            'Atspausdinta' => 'Projektuojama',
            'Įduota lieti' => 'Gaminama',
            'Atlieta' => 'Gaminama',
            'Pagaminta' => 'Prabuojama',
            'Išvežta prabuoti' => 'Prabuojama',
            'Užprabuota' => 'Paruošta Pristatymui',
            'DONE' => 'Išsiųsta'
        ];
        
        // Return mapped status or default
        return $status_mapping[$raw_status] ?? 'Vykdoma';
    }
}