<?php
/**
 * Installation class - handles database setup with stone size units
 */

if (!defined('ABSPATH')) {
    exit;
}

class CJS_Install {
    
    public static function activate() {
        // Ensure we can run database operations
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        // Create tables with error handling
        $table_results = self::create_tables();
        
        
        // Log results
        if ($table_results) {
            error_log('CJS: Database tables created successfully');
        } else {
            error_log('CJS: Failed to create some database tables');
        }
        
        self::create_upload_directory();
        self::create_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation flag
        update_option('cjs_plugin_activated', true);
    }
    
    private static function create_tables() {
        global $wpdb;
        
        // Ensure we have the dbDelta function
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        $success = true;
        
        // Required stones table - UPDATED with stone size fields
        $sql_stones = "CREATE TABLE {$wpdb->prefix}cjs_stones (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned DEFAULT NULL,
            order_item_id bigint(20) unsigned DEFAULT NULL,
            stone_type varchar(100) DEFAULT NULL,
            stone_origin varchar(100) DEFAULT 'Natural',
            stone_shape varchar(100) DEFAULT NULL,
            stone_quantity int(11) NOT NULL DEFAULT 1,
            stone_size_value decimal(10,3) DEFAULT NULL,
            stone_size_unit varchar(10) DEFAULT 'carats',
            stone_color varchar(100) DEFAULT NULL,
            stone_setting varchar(100) DEFAULT NULL,
            stone_clarity varchar(100) DEFAULT NULL,
            stone_cut_grade varchar(100) DEFAULT NULL,
            origin_country varchar(100) DEFAULT NULL,
            certificate varchar(255) DEFAULT NULL,
            custom_comment text,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        // Stone orders table
        $sql_stone_orders = "CREATE TABLE {$wpdb->prefix}cjs_stone_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_number varchar(100) NOT NULL,
            order_date date NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'reikia_apmoketi',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY order_date (order_date),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        // Stone to stone order relationship table
        $sql_stone_order_items = "CREATE TABLE {$wpdb->prefix}cjs_stone_order_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stone_id bigint(20) unsigned NOT NULL,
            stone_order_id bigint(20) unsigned NOT NULL,
            in_cart tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stone_order (stone_id, stone_order_id),
            KEY stone_order_id (stone_order_id),
            KEY in_cart (in_cart)
        ) $charset_collate;";
        
        // Order extensions table
        $sql_order_extensions = "CREATE TABLE {$wpdb->prefix}cjs_order_extensions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            finish_by_date date DEFAULT NULL,
            deliver_by_date date DEFAULT NULL,
            order_model tinyint(1) DEFAULT 0,
            order_production tinyint(1) DEFAULT 0,
            casting_notes text,
            order_printing tinyint(1) DEFAULT 0,
            manufacturing_status varchar(100) DEFAULT NULL,
            order_type varchar(100) DEFAULT 'Įprastas',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY deliver_by_date (deliver_by_date),
            KEY manufacturing_status (manufacturing_status)
        ) $charset_collate;";
        
        // Order files table
        $sql_order_files = "CREATE TABLE {$wpdb->prefix}cjs_order_files (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_type varchar(100) DEFAULT NULL,
            file_size bigint(20) DEFAULT NULL,
            thumbnail_path varchar(500) DEFAULT NULL,
            custom_comment text,
            uploaded_by bigint(20) unsigned NOT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY uploaded_by (uploaded_by)
        ) $charset_collate;";
        
        // Activity log table
        $sql_activity_log = "CREATE TABLE {$wpdb->prefix}cjs_activity_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            details text,
            severity varchar(20) DEFAULT 'info',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY object_type (object_type),
            KEY severity (severity)
        ) $charset_collate;";
        
        // Execute table creation with error checking
        $tables = [
            'cjs_stones' => $sql_stones,
            'cjs_stone_orders' => $sql_stone_orders,
            'cjs_stone_order_items' => $sql_stone_order_items,
            'cjs_order_extensions' => $sql_order_extensions,
            'cjs_order_files' => $sql_order_files,
            'cjs_activity_log' => $sql_activity_log
        ];
        
        foreach ($tables as $table_name => $sql) {
            $result = dbDelta($sql);
            
            // Check if table was created
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$table_name}'");
            
            if (!$table_exists) {
                error_log("CJS: Failed to create table {$wpdb->prefix}{$table_name}");
                error_log("CJS: SQL was: " . $sql);
                error_log("CJS: dbDelta result: " . print_r($result, true));
                $success = false;
            } else {
                error_log("CJS: Successfully created/updated table {$wpdb->prefix}{$table_name}");
            }
        }
        
        // Run migrations for existing installations (regardless of table creation status)
        error_log("CJS: Running migrations for existing installations");
        
        // Handle column updates for existing installations
        self::update_stones_table_columns();
        
        // Check if order_production column exists for existing installations
        $order_extensions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}cjs_order_extensions'");
        if ($order_extensions_exists) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cjs_order_extensions");
            if (!in_array('order_production', $columns)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}cjs_order_extensions ADD COLUMN order_production tinyint(1) DEFAULT 0 AFTER order_model");
                error_log("CJS: Added order_production column to existing table");
            }
            if (!in_array('order_type', $columns)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}cjs_order_extensions ADD COLUMN order_type varchar(100) DEFAULT 'Įprastas' AFTER manufacturing_status");
                error_log("CJS: Added order_type column to existing table");
            }
        }
        
        // Update stone order items table for existing installations
        self::update_stone_order_items_table();
        
        // Log any database errors
        if ($wpdb->last_error) {
            error_log("CJS: Database error during table creation: " . $wpdb->last_error);
            $success = false;
        }
        
        return $success;
    }
    
    /**
     * Update stones table to add new size fields
     */
    private static function update_stones_table_columns() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cjs_stones';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        
        // Add stone_size_value column if it doesn't exist
        if (!in_array('stone_size_value', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN stone_size_value decimal(10,3) DEFAULT NULL AFTER stone_quantity");
            error_log("CJS: Added stone_size_value column");
        }
        
        // Add stone_size_unit column if it doesn't exist
        if (!in_array('stone_size_unit', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN stone_size_unit varchar(10) DEFAULT 'carats' AFTER stone_size_value");
            error_log("CJS: Added stone_size_unit column");
        }
    }
    
    /**
     * Update stone order items table to add in_cart field
     */
    private static function update_stone_order_items_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cjs_stone_order_items';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if (!$table_exists) {
            error_log("CJS: Table {$table_name} does not exist, skipping migration");
            return;
        }
        
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        error_log("CJS: Current columns in {$table_name}: " . implode(', ', $columns));
        
        // Add in_cart column if it doesn't exist
        if (!in_array('in_cart', $columns)) {
            error_log("CJS: Adding in_cart column to {$table_name}");
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN in_cart tinyint(1) DEFAULT 0 AFTER stone_order_id");
            
            if ($result !== false) {
                error_log("CJS: Successfully added in_cart column to stone_order_items table");
            } else {
                error_log("CJS: Failed to add in_cart column. Error: " . $wpdb->last_error);
            }
        } else {
            error_log("CJS: in_cart column already exists in {$table_name}");
        }
    }

    
    private static function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $cjs_dir = $upload_dir['basedir'] . '/cjs-uploads';
        
        if (!file_exists($cjs_dir)) {
            $created = wp_mkdir_p($cjs_dir);
            
            if ($created) {
                // Add .htaccess for protection
                $htaccess_content = "Options -Indexes\n";
                $htaccess_content .= "Order Deny,Allow\n";
                $htaccess_content .= "Deny from all\n";
                
                $htaccess_file = $cjs_dir . '/.htaccess';
                file_put_contents($htaccess_file, $htaccess_content);
                
                // Add index.php for additional protection
                $index_content = "<?php\n// Silence is golden.\n";
                file_put_contents($cjs_dir . '/index.php', $index_content);
                
                error_log('CJS: Upload directory created successfully');
            } else {
                error_log('CJS: Failed to create upload directory');
            }
        }
    }
    
    private static function create_default_options() {
        // Default dropdown options
        $default_options = [
            'stone_types' => [
                'Diamond' => 'Deimantas',
                'Sapphire' => 'Safyras',
                'Emerald' => 'Smaragdas',
                'Ruby' => 'Rubinas',
                'Moissanite' => 'Moisanitas'
            ],
            'stone_origins' => [
                'Natural' => 'Natūralus',
                'Lab Grown' => 'Laboratorinis'
            ],
            'stone_shapes' => [
                'round', 'princess', 'cushion', 'oval', 'pear', 'emerald', 'heart', 
                'radiant', 'asscher', 'marquise', 'squareradiant', 'squareemerald', 
                'oldminer', 'star', 'rose', 'square', 'halfmoon', 'trapezoid', 
                'flanders', 'briolette', 'pentagonal', 'hexagonal', 'octagonal', 
                'triangular', 'trilliant', 'calf', 'taperedbaguette', 'shield', 
                'lozenge', 'kite', 'europeancut', 'baguette', 'bullet', 'taperedbullet'
            ],
            'stone_colors' => [
                'White' => 'Baltas',
                'Red' => 'Raudonas',
                'Green' => 'Žalias',
                'Blue' => 'Mėlynas'
            ],
            'stone_settings' => [
                'Four Prong' => 'Keturių kojelių',
                'Six Prong' => 'Šešių kojelių',
                'Channel' => 'Kanalo',
                'Pave' => 'Pave',
                'Bezel' => 'Bezel',
                'French Pave' => 'French-Pave'
            ],
            'stone_clarities' => [
                'FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'I1', 'I2', 'I3'
            ],
            'stone_cut_grades' => [
                'Rare Carat Ideal',
                'Excellent',
                'Very Good',
                'Good',
                'Fair',
                'Poor'
            ],
            'origin_countries' => [
                'Sri Lanka',
                'India',
                'Myanmar',
                'Thailand',
                'Madagascar',
                'Tanzania'
            ],
            'manufacturing_statuses' => [
                'Atspausdinti',
                'Įduoti lieti',
                'Pagaminti',
                'Išvežti prabuoti',
                'Užprabuoti',
                'DONE'
            ],
            'order_types' => [
                'Įprastas',
                'Vestuvinis'
            ],
            'stone_order_statuses' => [
                'ideta_i_krepseli' => ['label' => 'Įdėta į krepšelį', 'color' => '#bd0000'],
                'reikia_apmoketi' => ['label' => 'Reikia apmokėti', 'color' => '#dc3545'],
                'apmoketa' => ['label' => 'Apmokėta', 'color' => '#ffc107'],
                'uzsakyta' => ['label' => 'Užsakyta', 'color' => '#ffff07'],
                'siunčiama' => ['label' => 'Siunčiama', 'color' => '#90EE90'],
                'gauta' => ['label' => 'Gauta', 'color' => '#28a745']
            ],
            'stone_size_units' => [
                'carats' => 'Carats (ct)',
                'mm' => 'Millimeters (mm)'
            ]
        ];
        
        foreach ($default_options as $option_key => $option_value) {
            $option_name = 'cjs_' . $option_key;
            if (!get_option($option_name)) {
                add_option($option_name, $option_value);
                error_log("CJS: Created option {$option_name}");
            }
        }
        
        // Set version
        update_option('cjs_db_version', CJS_VERSION);
    }
    
    /**
     * Run database migrations if plugin was updated (stored DB version < current version).
     * Called on init so updates get migrations without requiring reactivation.
     */
    public static function maybe_upgrade() {
        $stored_version = get_option('cjs_db_version', '0');
        if (version_compare($stored_version, CJS_VERSION, '>=')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        error_log('CJS: Plugin version changed from ' . $stored_version . ' to ' . CJS_VERSION . ', running migrations.');
        self::create_tables();
        self::create_default_options();
    }

    /**
     * Deactivation cleanup
     */
    public static function deactivate() {
        // Clean up any temporary data, but don't remove tables or options
        flush_rewrite_rules();
        delete_option('cjs_plugin_activated');
    }
    
    /**
     * Uninstall cleanup (called from uninstall.php)
     */
    public static function uninstall() {
    }
    
    /**
     * Recursively remove directory
     */
    private static function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::remove_directory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * Check if tables exist and are up to date
     */
    public static function check_database() {
        global $wpdb;
        
        $tables = [
            'cjs_stones',
            'cjs_stone_orders', 
            'cjs_stone_order_items',
            'cjs_order_extensions',
            'cjs_order_files',
            'cjs_activity_log',
            'cjs_options_sort_order'
        ];
        
        $missing_tables = [];
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $missing_tables[] = $table;
            }
        }
        
        return $missing_tables;
    }
}