<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Safe_Mode {

    const OPTION = 'cjs_outbound_block';
    const LOG_OPTION = 'cjs_outbound_block_log';
    const LOG_SIZE = 30;
    const ALLOW_HOSTS = ['challenges.cloudflare.com'];

    private static $logged = 0;

    public static function init() {
        if (!self::active()) {
            return;
        }
        add_filter('pre_wp_mail', [__CLASS__, 'block_wp_mail'], PHP_INT_MAX, 2);
        add_filter('woocommerce_mail_callback', [__CLASS__, 'woo_mail_callback'], PHP_INT_MAX);
        add_action('phpmailer_init', [__CLASS__, 'empty_phpmailer'], PHP_INT_MAX);
        add_filter('pre_http_request', [__CLASS__, 'block_http'], PHP_INT_MAX, 3);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    public static function handle_post() {
        if (!isset($_POST['cjs_safe_toggle']) || !current_user_can('manage_options') || !check_admin_referer('cjs_safe_toggle')) {
            return;
        }
        if ($_POST['cjs_safe_toggle'] === 'on') {
            self::enable();
        } else {
            self::disable();
        }
        wp_safe_redirect(admin_url('admin.php?page=cjs-migration-debug'));
        exit;
    }

    public static function host_of($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    public static function site_host() {
        return self::host_of(home_url());
    }

    public static function settings() {
        $s = get_option(self::OPTION, []);
        return is_array($s) ? $s : [];
    }

    public static function active() {
        $s = self::settings();
        return !empty($s['on']) && !empty($s['host']) && $s['host'] === self::site_host();
    }

    public static function enable() {
        $user = wp_get_current_user();
        update_option(self::OPTION, ['on' => 1, 'host' => self::site_host(), 'by' => $user && $user->exists() ? $user->display_name : '', 'at' => current_time('mysql')], true);
    }

    public static function disable() {
        delete_option(self::OPTION);
    }

    private static function mask_email($email) {
        $email = trim((string) $email);
        if (!is_email($email)) {
            return $email === '' ? '' : '(hidden)';
        }
        [$local, $domain] = explode('@', $email, 2);
        return substr($local, 0, 1) . str_repeat('*', max(2, min(6, strlen($local) - 1))) . '@' . $domain;
    }

    private static function record($type, $target, $detail = '') {
        if (self::$logged >= 50) {
            return;
        }
        self::$logged++;
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['at' => current_time('mysql'), 'type' => $type, 'target' => $target, 'detail' => function_exists('mb_substr') ? mb_substr(wp_strip_all_tags((string) $detail), 0, 120) : substr(wp_strip_all_tags((string) $detail), 0, 120)]);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
    }

    public static function log() {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    private static function recipients($to) {
        $list = is_array($to) ? $to : explode(',', (string) $to);
        return implode(', ', array_filter(array_map(function ($e) { return self::mask_email($e); }, $list)));
    }

    public static function block_wp_mail($return, $atts) {
        $atts = is_array($atts) ? $atts : [];
        self::record('email', self::recipients($atts['to'] ?? ''), $atts['subject'] ?? '');
        return false;
    }

    public static function woo_mail_callback($callback) {
        return [__CLASS__, 'blocked_woo_mail'];
    }

    public static function blocked_woo_mail($to = '', $subject = '') {
        self::record('woo email', self::recipients($to), $subject);
        return false;
    }

    public static function empty_phpmailer($phpmailer) {
        if (is_object($phpmailer) && method_exists($phpmailer, 'clearAllRecipients')) {
            $phpmailer->clearAllRecipients();
        }
    }

    public static function block_http($preempt, $args, $url) {
        if ($preempt !== false) {
            return $preempt;
        }
        $host = self::host_of($url);
        if ($host === '' || $host === self::site_host() || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || in_array($host, self::ALLOW_HOSTS, true)) {
            return $preempt;
        }
        self::record('connection', $host, strtoupper((string) ($args['method'] ?? 'GET')));
        return new WP_Error('cjs_outbound_blocked', __('Blocked by the CJS staging safety switch.', 'custom-jewelry-system'));
    }

    public static function notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $url = admin_url('admin.php?page=cjs-migration-debug');
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Staging safety is on.', 'custom-jewelry-system') . '</strong> ' . esc_html__('No emails are sent and no outside connections are made from this site.', 'custom-jewelry-system') . ' <a href="' . esc_url($url) . '">' . esc_html__('Migration and Debug', 'custom-jewelry-system') . '</a></p></div>';
    }

    public static function test_email() {
        $user = wp_get_current_user();
        $to = $user && $user->exists() ? (string) $user->user_email : '';
        if (!is_email($to)) {
            return ['ok' => false, 'text' => __('Your user has no valid email address.', 'custom-jewelry-system')];
        }
        $subject = sprintf(__('CJS test email from %s', 'custom-jewelry-system'), self::site_host());
        $body = '<p>' . esc_html__('This is a test email sent through the WooCommerce mailer from the Migration and Debug page.', 'custom-jewelry-system') . '</p>';
        $woo = function_exists('WC') && WC() && method_exists(WC(), 'mailer') ? (bool) WC()->mailer()->send($to, $subject, $body) : null;
        $wp = (bool) wp_mail($to, $subject . ' (WordPress)', wp_strip_all_tags($body));
        return ['ok' => true, 'to' => $to, 'woo' => $woo, 'wp' => $wp];
    }

    public static function test_connection() {
        $r = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', ['timeout' => 8]);
        if (is_wp_error($r)) {
            return ['blocked' => $r->get_error_code() === 'cjs_outbound_blocked', 'text' => $r->get_error_message()];
        }
        return ['blocked' => false, 'text' => sprintf(__('Reached api.wordpress.org (HTTP %d).', 'custom-jewelry-system'), (int) wp_remote_retrieve_response_code($r))];
    }
}
