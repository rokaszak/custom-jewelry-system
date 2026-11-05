<?php

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Files {

    public static function init() {
        add_action('wp_ajax_cjs_download_file', [__CLASS__, 'download']);
    }

    public static function url($relative_path) {
        $encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($relative_path));
        return admin_url('admin-ajax.php?action=cjs_download_file&file=' . $encoded . '&nonce=' . wp_create_nonce('cjs_file_download'));
    }

    public static function kind($mime) {
        $mime = (string) $mime;
        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (strpos($mime, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mime, 'zip') !== false || strpos($mime, 'compressed') !== false || strpos($mime, 'rar') !== false) {
            return 'archive';
        }
        if (strpos($mime, 'model/') === 0 || preg_match('/(stl|obj|3dm|step|stp|iges)/', $mime)) {
            return 'model';
        }
        return 'file';
    }

    public static function list_for_order($order_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, u.display_name FROM {$wpdb->prefix}cjs_order_files f LEFT JOIN {$wpdb->users} u ON u.ID = f.uploaded_by WHERE f.order_id = %d ORDER BY f.uploaded_at DESC, f.id DESC",
            $order_id
        ), ARRAY_A) ?: [];
        $max = CJS_File_Handler::max_upload_size();
        return [
            'files' => array_map(function ($f) {
                $ext = strtolower(pathinfo((string) $f['file_path'], PATHINFO_EXTENSION));
                $kind = self::kind($f['file_type']);
                if ($kind === 'file' && in_array($ext, ['stl', 'obj', '3dm', 'step', 'stp', 'iges', 'igs', 'glb', 'gltf'], true)) {
                    $kind = 'model';
                }
                return [
                    'id' => (int) $f['id'],
                    'name' => $f['file_name'],
                    'comment' => $f['custom_comment'],
                    'size' => (int) $f['file_size'],
                    'type' => $f['file_type'],
                    'kind' => $kind,
                    'extension' => $ext,
                    'url' => self::url($f['file_path']),
                    'thumb_url' => $f['thumbnail_path'] ? self::url($f['thumbnail_path']) : ($kind === 'image' ? self::url($f['file_path']) : null),
                    'uploaded_by' => $f['display_name'],
                    'uploaded_at' => $f['uploaded_at'],
                ];
            }, $rows),
            'limits' => [
                'effective' => $max,
                'effective_label' => CJS_File_Handler::format_bytes($max),
                'types' => CJS_File_Handler::hint_types(),
                'blocked' => CJS_File_Handler::blocked_types(),
            ],
        ];
    }

    public static function upload($order_id, WP_REST_Request $request) {
        global $wpdb;
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('cjs_not_found', __('Order not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $files = $request->get_file_params();
        if (empty($files['file'])) {
            return new WP_Error('cjs_invalid', __('No file uploaded.', 'custom-jewelry-system'));
        }
        $handler = new CJS_File_Handler();
        $main = $handler->upload_file($files['file'], $order_id);
        if (is_wp_error($main)) {
            return $main;
        }
        $thumb_path = null;
        if (!empty($files['thumbnail']) && (int) $files['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $thumb = $handler->upload_file($files['thumbnail'], $order_id, 'thumb_');
            if (!is_wp_error($thumb)) {
                $thumb_path = $thumb['path'];
            }
        }
        $name = trim(sanitize_text_field((string) $request->get_param('file_name')));
        $comment = trim(sanitize_textarea_field((string) $request->get_param('comment')));
        $ok = $wpdb->insert($wpdb->prefix . 'cjs_order_files', [
            'order_id' => $order_id,
            'file_name' => $name !== '' ? $name : $main['name'],
            'file_path' => $main['path'],
            'file_type' => $main['type'],
            'file_size' => $main['size'],
            'thumbnail_path' => $thumb_path,
            'custom_comment' => $comment !== '' ? $comment : null,
            'uploaded_by' => get_current_user_id(),
            'uploaded_at' => current_time('mysql'),
        ]);
        if ($ok === false) {
            return new WP_Error('cjs_db', __('Failed to save the file record.', 'custom-jewelry-system'));
        }
        CJS_Logger::log('File uploaded', 'success', 'order', $order_id, ['file' => $name !== '' ? $name : $main['name']]);
        return self::list_for_order($order_id);
    }

    public static function update($file_id, array $data) {
        global $wpdb;
        $file = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cjs_order_files WHERE id = %d", $file_id), ARRAY_A);
        if (!$file) {
            return new WP_Error('cjs_not_found', __('File not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $row = [];
        if (isset($data['name']) && trim($data['name']) !== '') {
            $row['file_name'] = sanitize_text_field($data['name']);
        }
        if (array_key_exists('comment', $data)) {
            $row['custom_comment'] = trim(sanitize_textarea_field((string) $data['comment'])) ?: null;
        }
        if ($row) {
            $wpdb->update($wpdb->prefix . 'cjs_order_files', $row, ['id' => $file_id]);
        }
        return self::list_for_order((int) $file['order_id']);
    }

    public static function delete($file_id) {
        global $wpdb;
        $file = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cjs_order_files WHERE id = %d", $file_id));
        if (!$file) {
            return new WP_Error('cjs_not_found', __('File not found.', 'custom-jewelry-system'), ['status' => 404]);
        }
        $handler = new CJS_File_Handler();
        $handler->delete_file($file->file_path);
        if ($file->thumbnail_path) {
            $handler->delete_file($file->thumbnail_path);
        }
        $wpdb->delete($wpdb->prefix . 'cjs_order_files', ['id' => $file_id], ['%d']);
        CJS_Logger::log('File deleted', 'warning', 'order', (int) $file->order_id, ['file' => $file->file_name]);
        return self::list_for_order((int) $file->order_id);
    }

    public static function download() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'cjs_file_download')) {
            wp_die(esc_html__('This download link has expired. Reload the page and try again.', 'custom-jewelry-system'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'custom-jewelry-system'));
        }
        if (empty($_GET['file'])) {
            wp_die(esc_html__('No file specified', 'custom-jewelry-system'));
        }
        $encoded = str_replace(['-', '_'], ['+', '/'], sanitize_text_field(wp_unslash($_GET['file'])));
        $pad = strlen($encoded) % 4;
        if ($pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $path = base64_decode($encoded);
        if (empty($path)) {
            wp_die(esc_html__('Invalid file path', 'custom-jewelry-system'));
        }
        $handler = new CJS_File_Handler();
        $handler->serve_file($path);
    }
}
