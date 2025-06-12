<?php
/**
 * Plugin Name: SEO Auto Content Generator
 * Plugin URI: https://yourwebsite.com
 * Description: Automatically generates SEO-optimized content based on keyword search volume trends for WooCommerce products.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: seo-auto-content
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SAC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SAC_PLUGIN_VERSION', '1.0.0');
define('SAC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Database activation hook - Create tables on activation
 */
function sac_create_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Keywords table
    $keywords_table = $wpdb->prefix . 'sac_keywords';
    $keywords_sql = "CREATE TABLE $keywords_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        keyword_phrase varchar(255) NOT NULL,
        product_id bigint(20) NOT NULL,
        current_search_volume int(11) DEFAULT 0,
        previous_search_volume int(11) DEFAULT 0,
        surge_percentage decimal(5,2) DEFAULT 0.00,
        priority_score decimal(5,2) DEFAULT 0.00,
        last_monitored datetime DEFAULT NULL,
        last_article_date datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_keyword (keyword_phrase),
        KEY idx_product (product_id),
        KEY idx_priority (priority_score),
        KEY idx_monitored (last_monitored)
    ) $charset_collate;";
    
    // Content log table
    $content_log_table = $wpdb->prefix . 'sac_content_log';
    $content_log_sql = "CREATE TABLE $content_log_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        keyword_id mediumint(9) NOT NULL,
        product_id bigint(20) NOT NULL,
        blog_post_id bigint(20) DEFAULT NULL,
        generation_reason enum('surge','fallback','manual') NOT NULL,
        surge_percentage decimal(5,2) DEFAULT 0.00,
        search_volume_at_time int(11) DEFAULT 0,
        generation_date datetime DEFAULT CURRENT_TIMESTAMP,
        status enum('pending','completed','failed') DEFAULT 'pending',
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_keyword_id (keyword_id),
        KEY idx_product_id (product_id),
        KEY idx_generation_date (generation_date),
        KEY idx_status (status)
    ) $charset_collate;";
    
    // API logs table
    $api_logs_table = $wpdb->prefix . 'sac_api_logs';
    $api_logs_sql = "CREATE TABLE $api_logs_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        api_type varchar(50) NOT NULL,
        endpoint varchar(255) NOT NULL,
        request_data longtext NOT NULL,
        response_data longtext NOT NULL,
        response_code int(11) NOT NULL,
        error_message text DEFAULT NULL,
        execution_time decimal(10,6) NOT NULL,
        response_time decimal(10,6) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_api_type (api_type),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    
    // Duplicates table
    $duplicates_table = $wpdb->prefix . 'sac_duplicates';
    $duplicates_sql = "CREATE TABLE $duplicates_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        keyword_phrase varchar(255) NOT NULL,
        product_ids text NOT NULL,
        similarity_score decimal(3,2) DEFAULT 0.00,
        detected_at datetime DEFAULT CURRENT_TIMESTAMP,
        resolved_at datetime DEFAULT NULL,
        resolved tinyint(1) DEFAULT 0,
        status enum('pending','resolved','ignored') DEFAULT 'pending',
        PRIMARY KEY (id),
        KEY idx_keyword_phrase (keyword_phrase),
        KEY idx_status (status),
        KEY idx_detected_at (detected_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    dbDelta($keywords_sql);
    dbDelta($content_log_sql);
    dbDelta($api_logs_sql);
    dbDelta($duplicates_sql);
    
    // Set initial plugin options
    update_option('sac_db_version', '1.0');
    update_option('sac_activation_date', current_time('mysql'));
}

// Register activation hook
register_activation_hook(__FILE__, 'sac_create_database_tables');

/**
 * Deactivation hook - Clean up if enabled
 */
function sac_deactivate() {
    // Check if cleanup is enabled
    $delete_data = get_option('sac_delete_data_on_deactivate', false);
    
    if ($delete_data) {
        global $wpdb;
        
        // Drop custom tables
        $tables = array(
            $wpdb->prefix . 'sac_keywords',
            $wpdb->prefix . 'sac_content_log',
            $wpdb->prefix . 'sac_api_logs',
            $wpdb->prefix . 'sac_duplicates'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        // Delete plugin options
        $options = array(
            'sac_options',
            'sac_db_version',
            'sac_activation_date',
            'sac_daily_monitoring_stats',
            'sac_content_generation_stats',
            'sac_emergency_stop_active',
            'sac_emergency_stop_time',
            'sac_api_key',
            'sac_openai_api_key',
            'surge_threshold',
            'sac_delete_data_on_deactivate'
        );
        
        foreach ($options as $option) {
            delete_option($option);
        }
        
        // Delete post meta for all products
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_sac_keyword'));
        
        // Get all posts with generated content
        $generated_posts = get_posts(array(
            'post_type' => 'post',
            'meta_key' => '_sac_generated_content',
            'meta_value' => '1',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        // Delete all generated posts
        foreach ($generated_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        
        // Clear any scheduled cron jobs
        wp_clear_scheduled_hook('sac_daily_monitoring');
        wp_clear_scheduled_hook('sac_content_generation');
        wp_clear_scheduled_hook('sac_cleanup');
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'sac_deactivate');

/**
 * Notice when WooCommerce is not active
 */
function sac_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo __('SEO Auto Content Generator requires WooCommerce to be installed and active.', 'seo-auto-content');
    echo '</p></div>';
}

/**
 * Main Plugin Class
 */
class SEO_Auto_Content {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    public $options;
    
    /**
     * Component instances
     */
    public $database;
    public $api_handler;
    public $keyword_extractor;
    public $surge_detector;
    public $content_generator;
    public $cron_manager;
    public $admin;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook into plugins_loaded to ensure WordPress is fully loaded
        add_action('plugins_loaded', array($this, 'init'), 10);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active first
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', 'sac_woocommerce_missing_notice');
            return;
        }

        // Load plugin options
        $this->options = get_option('sac_options', array());
        
        // Include required files with error handling
        if (!$this->include_files()) {
            return;
        }
        
        // Initialize components with error handling
        if (!$this->init_components()) {
            return;
        }
        
        // Setup hooks
        $this->setup_hooks();
        
        // Add success notice for testing
        add_action('admin_notices', array($this, 'show_activation_notice'));
    }

    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce') || 
               in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
    }

    /**
     * Include all required files with error handling
     */
    private function include_files() {
        $required_files = array(
            'includes/class-database.php',
            'includes/class-api-handler.php',
            'includes/class-keyword-extractor.php',
            'includes/class-surge-detector.php',
            'includes/class-content-generator.php',
            'includes/class-cron-manager.php'
        );

        foreach ($required_files as $file) {
            $file_path = SAC_PLUGIN_PATH . $file;
            if (!file_exists($file_path)) {
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="notice notice-error"><p>SEO Auto Content: Missing required file: ' . esc_html($file) . '</p></div>';
                });
                return false;
            }
            require_once $file_path;
        }

        // Load admin files only in admin
        if (is_admin()) {
            $admin_file = SAC_PLUGIN_PATH . 'admin/class-admin.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            }
        }
        
        // Load public files only on frontend
        if (!is_admin()) {
            $public_file = SAC_PLUGIN_PATH . 'public/class-public.php';
            if (file_exists($public_file)) {
                require_once $public_file;
            }
        }

        return true;
    }

    /**
     * Initialize plugin components with error handling
     */
    private function init_components() {
        try {
            // Initialize core components
            if (class_exists('SAC_Database')) {
                $this->database = new SAC_Database();
            } else {
                throw new Exception('SAC_Database class not found');
            }

            if (class_exists('SAC_API_Handler')) {
                $this->api_handler = new SAC_API_Handler();
            }

            if (class_exists('SAC_Keyword_Extractor')) {
                $this->keyword_extractor = new SAC_Keyword_Extractor();
            }

            if (class_exists('SAC_Surge_Detector')) {
                $this->surge_detector = new SAC_Surge_Detector();
            }

            if (class_exists('SAC_Content_Generator')) {
                $this->content_generator = new SAC_Content_Generator();
            }

            if (class_exists('SAC_Cron_Manager')) {
                $this->cron_manager = new SAC_Cron_Manager();
            }
            
            // Initialize admin component
            if (is_admin() && class_exists('SAC_Admin')) {
                $this->admin = new SAC_Admin();
            }
            
            // Initialize public component
            if (!is_admin() && class_exists('SAC_Public')) {
                new SAC_Public();
            }

            return true;

        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>SEO Auto Content initialization error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
            return false;
        }
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize plugin after WordPress is loaded
        add_action('init', array($this, 'on_init'));
        
        // Add custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
    }

    /**
     * Show activation notice
     */
    public function show_activation_notice() {
        if (get_transient('sac_activation_notice')) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>SEO Auto Content Generator</strong> has been activated successfully!</p></div>';
            delete_transient('sac_activation_notice');
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Check PHP version
            if (version_compare(PHP_VERSION, '7.4', '<')) {
                wp_die('SEO Auto Content requires PHP 7.4 or higher. You are running PHP ' . PHP_VERSION);
            }

            // Create database tables (will be called by activation hook)
            sac_create_database_tables();
            
            // Set default options
            $default_options = array(
                'api_key' => '',
                'location' => 'US',
                'language' => 'en',
                'surge_threshold' => 25,
                'daily_limit' => 5,
                'content_length' => 300,
                'last_product_scan' => 0,
                'api_calls_today' => array(
                    'keyword' => 0,
                    'content' => 0,
                    'date' => date('Y-m-d')
                )
            );
            
            update_option('sac_options', $default_options);
            
            // Schedule cron jobs if cron manager exists
            if ($this->cron_manager && method_exists($this->cron_manager, 'schedule_jobs')) {
                $this->cron_manager->schedule_jobs();
            }
            
            // Set activation notice
            set_transient('sac_activation_notice', true, 30);
            
            // Flush rewrite rules
            flush_rewrite_rules();

        } catch (Exception $e) {
            wp_die('SEO Auto Content activation failed: ' . $e->getMessage());
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        try {
            // Clear scheduled cron jobs
            if ($this->cron_manager && method_exists($this->cron_manager, 'clear_jobs')) {
                $this->cron_manager->clear_jobs();
            }
            
            // Flush rewrite rules
            flush_rewrite_rules();

        } catch (Exception $e) {
            error_log('SEO Auto Content deactivation error: ' . $e->getMessage());
        }
    }

    /**
     * On WordPress init
     */
    public function on_init() {
        // Register custom post meta for generated content
        register_meta('post', '_sac_generated_content', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ));
        
        // Register custom product meta
        register_meta('post', '_sac_keyword', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ));
        
        register_meta('post', '_sac_last_updated', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ));

        // Load text domain for translations
        load_plugin_textdomain('seo-auto-content', false, dirname(SAC_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        // Daily staggered monitoring
        $schedules['sac_daily_monitoring'] = array(
            'interval' => DAY_IN_SECONDS,
            'display' => __('Daily Keyword Monitoring', 'seo-auto-content')
        );
        
        // Content generation (5 times per day)
        $schedules['sac_content_generation'] = array(
            'interval' => 3 * HOUR_IN_SECONDS, // Every 3 hours
            'display' => __('Content Generation', 'seo-auto-content')
        );
        
        return $schedules;
    }

    /**
     * Get plugin option
     */
    public function get_option($key, $default = null) {
        if (!is_array($this->options)) {
            $this->options = get_option('sac_options', array());
        }
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * Update plugin option
     */
    public function update_option($key, $value) {
        if (!is_array($this->options)) {
            $this->options = get_option('sac_options', array());
        }
        $this->options[$key] = $value;
        update_option('sac_options', $this->options);
    }

    /**
     * Log message to WordPress debug log
     */
    public function log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[SEO Auto Content - {$type}] " . $message);
        }
    }
}

/**
 * Get plugin instance
 */
function SAC() {
    return SEO_Auto_Content::get_instance();
}

/**
 * Manual database table creation function
 * Call this if activation hook doesn't work
 */
function sac_manual_create_tables() {
    sac_create_database_tables();
    
    // Verify tables were created
    global $wpdb;
    $tables = array(
        'sac_keywords',
        'sac_content_log', 
        'sac_api_logs',
        'sac_duplicates'
    );
    
    $created = array();
    $failed = array();
    
    foreach ($tables as $table) {
        $full_table = $wpdb->prefix . $table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'")) {
            $created[] = $table;
        } else {
            $failed[] = $table;
        }
    }
    
    return array(
        'created' => $created,
        'failed' => $failed,
        'success' => empty($failed)
    );
}

// Initialize plugin
SAC();