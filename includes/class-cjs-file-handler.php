<?php
/**
 * File Handler - manages secure file uploads and downloads
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_File_Handler {
    
    private $upload_dir;
    private $upload_url;
    
    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/cjs-uploads/';
        $this->upload_url = $upload_dir['baseurl'] . '/cjs-uploads/';
        
        // Ensure directory exists
        $this->ensure_upload_directory();
    }
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // Make sure this class is available
        add_action('init', function() {
            // Verify file handler is working
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('CJS: File handler initialized');
            }
        });
    }
    
    /**
     * Get actual PHP upload limits
     */
    public static function get_upload_limits() {
        // Get PHP limits
        $upload_max = wp_convert_hr_to_bytes(ini_get('upload_max_filesize'));
        $post_max = wp_convert_hr_to_bytes(ini_get('post_max_size'));
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        
        // Use the smallest limit as the effective limit
        $max_size = min($upload_max, $post_max);
        
        // If memory limit is set and smaller, consider it (but give some buffer)
        if ($memory_limit > 0 && $memory_limit < $max_size * 2) {
            $max_size = min($max_size, $memory_limit / 2);
        }
        
        return [
            'upload_max_filesize' => $upload_max,
            'post_max_size' => $post_max,
            'memory_limit' => $memory_limit,
            'effective_limit' => $max_size,
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time')
        ];
    }
    
    /**
     * Format bytes for display
     */
    public static function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Ensure upload directory exists and is secure
     */
    private function ensure_upload_directory() {
        if (!file_exists($this->upload_dir)) {
            $created = wp_mkdir_p($this->upload_dir);
            
            if (!$created) {
                error_log('CJS: Failed to create upload directory: ' . $this->upload_dir);
                return false;
            }
            
            // Add .htaccess for protection
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "Order Deny,Allow\n";
            $htaccess_content .= "Deny from all\n";
            
            $htaccess_file = $this->upload_dir . '.htaccess';
            if (!file_put_contents($htaccess_file, $htaccess_content)) {
                error_log('CJS: Failed to create .htaccess file: ' . $htaccess_file);
            }
            
            // Add index.php for additional protection
            $index_content = "<?php\n// Silence is golden.\n";
            $index_file = $this->upload_dir . 'index.php';
            file_put_contents($index_file, $index_content);
        }
        
        // Check if directory is writable
        if (!is_writable($this->upload_dir)) {
            error_log('CJS: Upload directory is not writable: ' . $this->upload_dir);
            return false;
        }
        
        return true;
    }
    
    /**
     * Upload file
     * 
     * @param array $file $_FILES array element
     * @param int $order_id
     * @param string $prefix Optional filename prefix
     * @return array|WP_Error
     */
    public function upload_file($file, $order_id, $prefix = '') {
        // Validate inputs
        if (!is_array($file) || !isset($file['error'])) {
            error_log('CJS: Invalid file array provided to upload_file');
            return new WP_Error('invalid_file', 'Invalid file data provided');
        }
        
        if (!$order_id || !is_numeric($order_id)) {
            error_log('CJS: Invalid order ID provided to upload_file: ' . $order_id);
            return new WP_Error('invalid_order', 'Invalid order ID provided');
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = $this->get_upload_error_message($file['error']);
            error_log('CJS: File upload error: ' . $error_message . ' (Code: ' . $file['error'] . ')');
            return new WP_Error('upload_error', $error_message);
        }
        
        // Get actual PHP limits and validate file size
        $limits = self::get_upload_limits();
        $max_size = apply_filters('cjs_max_file_size', $limits['effective_limit']);
        
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', sprintf(
                'File size (%s) exceeds maximum allowed size (%s)', 
                self::format_bytes($file['size']), 
                self::format_bytes($max_size)
            ));
        }
        
        // Get file extension
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validate file type - very permissive for main files, strict for thumbnails
        if (strpos($prefix, 'thumb_') === 0) {
            // Thumbnail - images only
            $allowed_types = apply_filters('cjs_allowed_thumbnail_types', [
                'jpg', 'jpeg', 'png', 'gif', 'webp'
            ]);
            
            if (!in_array($file_extension, $allowed_types)) {
                return new WP_Error('invalid_file_type', 'Thumbnail must be an image file (jpg, png, gif, webp)');
            }
        } else {
            // Main file - allow almost everything, only block potentially dangerous files
            $dangerous_types = apply_filters('cjs_dangerous_file_types', [
                'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi', 'pl', 'asp', 'aspx', 
                'shtml', 'shtm', 'fcgi', 'fpl', 'jsp', 'htm', 'html', 'js', 'swf', 'exe', 'msi',
                'com', 'scr', 'bat', 'cmd', 'pif', 'vbs', 'vbe', 'jse', 'wsf', 'wsc', 'wsh'
            ]);
            
            if (in_array($file_extension, $dangerous_types)) {
                return new WP_Error('invalid_file_type', 'File type not allowed for security reasons');
            }
        }
        
        // Ensure upload directory exists and is writable
        if (!$this->ensure_upload_directory()) {
            return new WP_Error('directory_error', 'Upload directory is not accessible');
        }
        
        // Create order subdirectory
        $order_dir = $this->upload_dir . 'order-' . $order_id . '/';
        if (!file_exists($order_dir)) {
            $created = wp_mkdir_p($order_dir);
            if (!$created) {
                error_log('CJS: Failed to create order directory: ' . $order_dir);
                return new WP_Error('directory_error', 'Failed to create order directory');
            }
        }
        
        // Sanitize filename - handle special characters better
        $original_name = $file['name'];
        $filename = sanitize_file_name($original_name);
        
        // If sanitization removes everything, create a safe filename
        if (empty($filename) || $filename === '.' || $filename === '..') {
            $filename = 'file_' . time() . '.' . $file_extension;
        }
        
        // Ensure we have a valid extension
        if (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename .= '.' . $file_extension;
        }
        
        // Add prefix and timestamp
        $filename = $prefix . time() . '_' . $filename;
        
        // Handle duplicate filenames
        $filepath = $order_dir . $filename;
        $counter = 1;
        while (file_exists($filepath)) {
            $parts = pathinfo($filename);
            $new_filename = $parts['filename'] . '-' . $counter . '.' . $parts['extension'];
            $filepath = $order_dir . $new_filename;
            $counter++;
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            error_log('CJS: Failed to move uploaded file from ' . $file['tmp_name'] . ' to ' . $filepath);
            return new WP_Error('move_failed', 'Failed to move uploaded file');
        }
        
        // Set proper permissions
        chmod($filepath, 0644);
        
        // Get relative path for storage
        $relative_path = str_replace($this->upload_dir, '', $filepath);
        
        $result = [
            'name' => basename($filepath),
            'path' => $relative_path,
            'type' => $file['type'],
            'size' => filesize($filepath)
        ];
        
        error_log('CJS: File uploaded successfully: ' . $relative_path);
        
        return $result;
    }
    
    /**
     * Delete file
     * 
     * @param string $relative_path
     * @return bool
     */
    public function delete_file($relative_path) {
        if (empty($relative_path)) {
            return false;
        }
        
        $filepath = $this->upload_dir . $relative_path;
        
        // Security check - ensure file is within our upload directory
        $real_upload_dir = realpath($this->upload_dir);
        $real_file_path = realpath($filepath);
        
        if (!$real_file_path || strpos($real_file_path, $real_upload_dir) !== 0) {
            error_log('CJS: Attempted to delete file outside upload directory: ' . $relative_path);
            return false;
        }
        
        if (file_exists($filepath)) {
            $result = unlink($filepath);
            if ($result) {
                error_log('CJS: File deleted successfully: ' . $relative_path);
            } else {
                error_log('CJS: Failed to delete file: ' . $relative_path);
            }
            return $result;
        }
        
        return false;
    }
    
    /**
     * Serve protected file
     * 
     * @param string $relative_path
     */
    public function serve_file($relative_path) {
        if (empty($relative_path)) {
            wp_die('No file specified');
        }
        
        // Clean the relative path to prevent directory traversal
        $relative_path = str_replace(['..', '\\'], ['', '/'], $relative_path);
        $relative_path = ltrim($relative_path, '/');
        
        $filepath = $this->upload_dir . $relative_path;
        
        // Security check - ensure file is within our upload directory
        $real_upload_dir = realpath($this->upload_dir);
        $real_file_path = realpath($filepath);
        
        if (!$real_file_path || strpos($real_file_path, $real_upload_dir) !== 0) {
            wp_die('Invalid file path');
        }
        
        if (!file_exists($filepath)) {
            wp_die('File not found');
        }
        
        // Get file info
        $filename = basename($filepath);
        
        // Try multiple methods to get MIME type
        $mime_type = null;
        
        // Method 1: mime_content_type
        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($filepath);
        }
        
        // Method 2: finfo
        if (!$mime_type && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime_type = finfo_file($finfo, $filepath);
                finfo_close($finfo);
            }
        }
        
        // Method 3: File extension fallback
        if (!$mime_type) {
            $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            $mime_types = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'zip' => 'application/zip',
                'txt' => 'text/plain',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            $mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
        }
        
        // Set headers
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output file
        readfile($filepath);
        exit;
    }
    
    /**
     * Get upload error message
     * 
     * @param int $error_code
     * @return string
     */
    private function get_upload_error_message($error_code) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        
        return isset($errors[$error_code]) ? $errors[$error_code] : 'Unknown upload error';
    }
    
    /**
     * Create thumbnail for image
     * 
     * @param string $source_path
     * @param string $dest_path
     * @param int $max_width
     * @param int $max_height
     * @return bool
     */
    public function create_thumbnail($source_path, $dest_path, $max_width = 450, $max_height = 450) {
        if (!file_exists($source_path)) {
            return false;
        }
        
        $image_info = getimagesize($source_path);
        if (!$image_info) {
            return false;
        }
        
        list($width, $height, $type) = $image_info;
        
        // Calculate new dimensions
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        
        // Create new image
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        // Load source image
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($source_path);
                // Preserve transparency
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($source_path);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // Resize
        imagecopyresampled($new_image, $source, 0, 0, 0, 0, 
                          $new_width, $new_height, $width, $height);
        
        // Save
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($new_image, $dest_path, 85);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($new_image, $dest_path, 8);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($new_image, $dest_path);
                break;
        }
        
        // Clean up
        imagedestroy($source);
        imagedestroy($new_image);
        
        return $result;
    }
    
    /**
     * Get file icon based on type
     * 
     * @param string $mime_type
     * @return string Dashicon class
     */
    public static function get_file_icon($mime_type) {
        $icons = [
            'image' => 'dashicons-format-image',
            'video' => 'dashicons-format-video',
            'audio' => 'dashicons-format-audio',
            'pdf' => 'dashicons-pdf',
            'zip' => 'dashicons-archive',
            'text' => 'dashicons-text',
            'code' => 'dashicons-editor-code',
            'spreadsheet' => 'dashicons-media-spreadsheet',
            'document' => 'dashicons-media-document',
            'default' => 'dashicons-media-default'
        ];
        
        // Determine file type
        if (strpos($mime_type, 'image/') === 0) {
            return $icons['image'];
        } elseif (strpos($mime_type, 'video/') === 0) {
            return $icons['video'];
        } elseif (strpos($mime_type, 'audio/') === 0) {
            return $icons['audio'];
        } elseif ($mime_type === 'application/pdf') {
            return $icons['pdf'];
        } elseif (in_array($mime_type, ['application/zip', 'application/x-zip-compressed', 'application/x-rar'])) {
            return $icons['zip'];
        } elseif (strpos($mime_type, 'text/') === 0) {
            return $icons['text'];
        } elseif (in_array($mime_type, ['application/javascript', 'application/json', 'application/xml'])) {
            return $icons['code'];
        } elseif (in_array($mime_type, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])) {
            return $icons['spreadsheet'];
        } elseif (in_array($mime_type, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
            return $icons['document'];
        }
        
        return $icons['default'];
    }
    
    /**
     * Get upload directory info
     */
    public function get_upload_info() {
        return [
            'upload_dir' => $this->upload_dir,
            'upload_url' => $this->upload_url,
            'exists' => file_exists($this->upload_dir),
            'writable' => is_writable($this->upload_dir)
        ];
    }
}