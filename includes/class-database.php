<?php
/**
 * Database Handler Class
 * Handles all database operations for the SEO Auto Content plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Database {
    
    /**
     * WordPress database instance
     */
    private $wpdb;
    
    /**
     * Table names
     */
    private $tables;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Define table names
        $this->tables = array(
            'keywords' => $wpdb->prefix . 'sac_keywords',
            'content_log' => $wpdb->prefix . 'sac_content_log',
            'api_logs' => $wpdb->prefix . 'sac_api_logs',
            'duplicates' => $wpdb->prefix . 'sac_duplicates',
            'queue' => $wpdb->prefix . 'sac_queue'
        );
    }

    /**
     * Check if table exists
     */
    public function table_exists($table_name) {
        global $wpdb;
        $full_table_name = $wpdb->prefix . $table_name;
        return $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'") === $full_table_name;
    }

    /**
     * Create all plugin tables
     */
    public function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Keywords table
        $sql_keywords = "CREATE TABLE {$this->tables['keywords']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id int(11) NOT NULL,
            keyword_phrase varchar(255) NOT NULL,
            current_search_volume int(11) DEFAULT 0,
            previous_search_volume int(11) DEFAULT 0,
            surge_percentage decimal(5,2) DEFAULT 0,
            priority_score decimal(8,2) DEFAULT 0,
            total_articles_generated int(11) DEFAULT 0,
            last_checked datetime DEFAULT NULL,
            last_article_date date DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_product_keyword (product_id, keyword_phrase),
            KEY idx_product_id (product_id),
            KEY idx_keyword (keyword_phrase),
            KEY idx_priority (priority_score DESC),
            KEY idx_last_checked (last_checked),
            KEY idx_surge_percentage (surge_percentage)
        ) $charset_collate;";
        
        // Content log table
        $sql_content_log = "CREATE TABLE {$this->tables['content_log']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            keyword_id int(11) NOT NULL,
            product_id int(11) NOT NULL,
            blog_post_id int(11) DEFAULT NULL,
            generation_reason enum('surge','fallback','manual') NOT NULL,
            surge_percentage decimal(5,2) DEFAULT 0,
            search_volume_at_time int(11) DEFAULT 0,
            article_content longtext,
            product_updated tinyint(1) DEFAULT 0,
            generation_date datetime DEFAULT CURRENT_TIMESTAMP,
            status enum('pending','completed','failed') DEFAULT 'pending',
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_keyword_id (keyword_id),
            KEY idx_product_id (product_id),
            KEY idx_generation_date (generation_date),
            KEY idx_reason (generation_reason),
            KEY idx_status (status)
        ) $charset_collate;";
        
        // API logs table
        $sql_api_logs = "CREATE TABLE {$this->tables['api_logs']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            api_type enum('keyword','content') NOT NULL,
            endpoint varchar(255) DEFAULT NULL,
            request_data text,
            response_data longtext,
            response_code int(11) DEFAULT NULL,
            response_time decimal(8,3) DEFAULT NULL,
            execution_time decimal(8,3) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_api_type (api_type),
            KEY idx_date (created_at),
            KEY idx_response_code (response_code)
        ) $charset_collate;";
        
        // Duplicates table
        $sql_duplicates = "CREATE TABLE {$this->tables['duplicates']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            keyword_phrase varchar(255) NOT NULL,
            product_ids text,
            similarity_score decimal(3,2) DEFAULT NULL,
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            resolved tinyint(1) DEFAULT 0,
            status enum('pending','resolved','ignored') DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY idx_keyword (keyword_phrase),
            KEY idx_resolved (resolved),
            KEY idx_detected (detected_at),
            KEY idx_status (status)
        ) $charset_collate;";
        
        // Queue table
        $sql_queue = "CREATE TABLE {$this->tables['queue']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            keyword_id int(11) NOT NULL,
            queue_type enum('surge','fallback') NOT NULL,
            priority_score decimal(8,2) DEFAULT NULL,
            scheduled_for datetime DEFAULT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_keyword_id (keyword_id),
            KEY idx_priority (priority_score DESC),
            KEY idx_status (status),
            KEY idx_scheduled (scheduled_for)
        ) $charset_collate;";
        
        // Execute table creation
        dbDelta($sql_keywords);
        dbDelta($sql_content_log);
        dbDelta($sql_api_logs);
        dbDelta($sql_duplicates);
        dbDelta($sql_queue);
        
        // Store database version
        update_option('sac_db_version', '1.0');
    }

    /**
     * Get table name
     */
    public function get_table($table_key) {
        return isset($this->tables[$table_key]) ? $this->tables[$table_key] : null;
    }

    /**
     * Insert keyword record
     */
    public function insert_keyword($product_id, $keyword_phrase, $search_volume = 0) {
        return $this->wpdb->insert(
            $this->tables['keywords'],
            array(
                'product_id' => $product_id,
                'keyword_phrase' => sanitize_text_field($keyword_phrase),
                'current_search_volume' => intval($search_volume),
                'last_checked' => current_time('mysql', true)
            ),
            array('%d', '%s', '%d', '%s')
        );
    }

    /**
     * Update keyword search volume
     */
    public function update_keyword_volume($keyword_id, $new_volume) {
        // Get current volume to store as previous
        $current = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT current_search_volume FROM {$this->tables['keywords']} WHERE id = %d",
                $keyword_id
            )
        );
        
        // Calculate surge percentage
        $surge_percentage = 0;
        if ($current > 0) {
            $surge_percentage = (($new_volume - $current) / $current) * 100;
        }
        
        return $this->wpdb->update(
            $this->tables['keywords'],
            array(
                'previous_search_volume' => intval($current),
                'current_search_volume' => intval($new_volume),
                'surge_percentage' => round($surge_percentage, 2),
                'last_checked' => current_time('mysql', true)
            ),
            array('id' => $keyword_id),
            array('%d', '%d', '%f', '%s'),
            array('%d')
        );
    }

    /**
     * Get keywords for monitoring (staggered daily batches)
     */
    public function get_keywords_for_monitoring($limit = 86) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['keywords']} 
                 WHERE last_checked IS NULL 
                 OR last_checked <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY priority_score DESC, last_checked ASC 
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Get surge keywords (25%+ increase)
     */
    public function get_surge_keywords($threshold = 25) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT k.*, p.post_title as product_name 
                 FROM {$this->tables['keywords']} k
                 LEFT JOIN {$this->wpdb->posts} p ON k.product_id = p.ID
                 WHERE k.surge_percentage >= %f
                 AND k.last_checked >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 ORDER BY k.surge_percentage DESC",
                $threshold
            )
        );
    }

    /**
     * Get fallback keywords for content generation
     */
    public function get_fallback_keywords($limit = 5) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT k.*, p.post_title as product_name,
                 COALESCE(DATEDIFF(NOW(), k.last_article_date), 999) as days_since_article
                 FROM {$this->tables['keywords']} k
                 LEFT JOIN {$this->wpdb->posts} p ON k.product_id = p.ID
                 WHERE k.current_search_volume > 0
                 ORDER BY (k.current_search_volume * 0.4) + 
                         (COALESCE(DATEDIFF(NOW(), k.last_article_date), 999) * 0.6) DESC
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Log content generation
     */
    public function log_content_generation($keyword_id, $product_id, $reason, $content, $blog_post_id = null) {
        $keyword = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT current_search_volume, surge_percentage FROM {$this->tables['keywords']} WHERE id = %d",
                $keyword_id
            )
        );
        
        $result = $this->wpdb->insert(
            $this->tables['content_log'],
            array(
                'keyword_id' => $keyword_id,
                'product_id' => $product_id,
                'generation_reason' => $reason,
                'search_volume_at_time' => $keyword ? $keyword->current_search_volume : 0,
                'surge_percentage' => $keyword ? $keyword->surge_percentage : 0,
                'article_content' => $content,
                'blog_post_id' => $blog_post_id,
                'product_updated' => 1
            ),
            array('%d', '%d', '%s', '%d', '%f', '%s', '%d', '%d')
        );
        
        // Update keyword last article date
        if ($result) {
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->tables['keywords']} 
                     SET last_article_date = %s,
                         total_articles_generated = total_articles_generated + 1 
                     WHERE id = %d",
                    current_time('mysql', true),
                    $keyword_id
                )
            );
        }
        
        return $result;
    }

    /**
     * Log API call
     */
    public function log_api_call($api_type, $endpoint, $request_data, $response_data, $response_code, $execution_time, $error = null) {
        return $this->wpdb->insert(
            $this->tables['api_logs'],
            array(
                'api_type' => $api_type,
                'endpoint' => $endpoint,
                'request_data' => maybe_serialize($request_data),
                'response_data' => is_string($response_data) ? $response_data : maybe_serialize($response_data),
                'response_code' => $response_code,
                'error_message' => $error,
                'execution_time' => $execution_time,
                'response_time' => $execution_time // For backward compatibility
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%f', '%f')
        );
    }

    /**
     * Get daily API usage count
     */
    public function get_daily_api_usage($api_type, $date = null) {
        if (!$this->table_exists('sac_api_logs')) {
            return 0;
        }
        
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['api_logs']} 
                 WHERE api_type = %s 
                 AND DATE(created_at) = %s 
                 AND response_code = 200",
                $api_type,
                $date
            )
        );
    }

    /**
     * Get weekly report data
     */
    public function get_weekly_report($start_date = null) {
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-7 days'));
        }
        
        $products_updated = array();
        $api_usage = array();
        
        // Get content log data if table exists
        if ($this->table_exists('sac_content_log')) {
            $products_updated = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT cl.*, k.keyword_phrase, p.post_title as product_name
                     FROM {$this->tables['content_log']} cl
                     LEFT JOIN {$this->tables['keywords']} k ON cl.keyword_id = k.id
                     LEFT JOIN {$this->wpdb->posts} p ON cl.product_id = p.ID
                     WHERE DATE(cl.generation_date) >= %s
                     ORDER BY cl.generation_date DESC",
                    $start_date
                )
            );
        }
        
        // Get API usage data if table exists
        if ($this->table_exists('sac_api_logs')) {
            $api_usage = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT api_type, COUNT(*) as calls, 
                     AVG(COALESCE(execution_time, response_time, 0)) as avg_time,
                     SUM(CASE WHEN response_code != 200 THEN 1 ELSE 0 END) as errors
                     FROM {$this->tables['api_logs']}
                     WHERE DATE(created_at) >= %s
                     GROUP BY api_type",
                    $start_date
                )
            );
        }
        
        return array(
            'products_updated' => $products_updated,
            'api_usage' => $api_usage
        );
    }

    /**
     * Detect and log duplicate content
     */
    public function detect_duplicates() {
        if (!$this->table_exists('sac_keywords') || !$this->table_exists('sac_duplicates')) {
            return 0;
        }
        
        $duplicates = $this->wpdb->get_results(
            "SELECT keyword_phrase, GROUP_CONCAT(product_id) as product_ids, COUNT(*) as count
             FROM {$this->tables['keywords']}
             GROUP BY keyword_phrase
             HAVING count > 1"
        );
        
        foreach ($duplicates as $duplicate) {
            $this->wpdb->replace(
                $this->tables['duplicates'],
                array(
                    'keyword_phrase' => $duplicate->keyword_phrase,
                    'product_ids' => $duplicate->product_ids,
                    'similarity_score' => 1.00, // Exact match
                    'detected_at' => current_time('mysql', true),
                    'resolved' => 0
                ),
                array('%s', '%s', '%f', '%s', '%d')
            );
        }
        
        return count($duplicates);
    }

    /**
     * Get unresolved duplicates
     */
    public function get_unresolved_duplicates() {
        if (!$this->table_exists('sac_duplicates')) {
            return array();
        }
        
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->tables['duplicates']} 
             WHERE resolved = 0 
             ORDER BY detected_at DESC"
        );
    }

    /**
     * Clean up old logs (keep last 30 days)
     */
    public function cleanup_old_logs() {
        if (!$this->table_exists('sac_api_logs')) {
            return 0;
        }
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $deleted_api_logs = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->tables['api_logs']} WHERE created_at < %s",
                $cutoff_date
            )
        );
        
        return $deleted_api_logs;
    }

    /**
     * Get plugin statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array(
            'total_keywords' => 0,
            'monitored_today' => 0,
            'surges_today' => 0,
            'content_generated' => 0,
            'avg_search_volume' => 0,
            'keywords_needing_monitoring' => 0
        );
        
        // Total keywords
        $stats['total_keywords'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords"
        );
        
        // Keywords monitored today
        $stats['monitored_today'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords 
                 WHERE DATE(last_monitored) = %s",
                current_time('Y-m-d')
            )
        );
        
        // Surges detected today
        $stats['surges_today'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords 
                 WHERE surge_percentage >= %f 
                 AND DATE(last_monitored) = %s",
                25.0,
                current_time('Y-m-d')
            )
        );
        
        // Content generated
        $stats['content_generated'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sac_content_log"
        );
        
        // Average search volume
        $avg_volume = $wpdb->get_var(
            "SELECT AVG(current_search_volume) 
             FROM {$wpdb->prefix}sac_keywords 
             WHERE current_search_volume > 0"
        );
        $stats['avg_search_volume'] = round($avg_volume);
        
        // Keywords needing monitoring
        $stats['keywords_needing_monitoring'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords 
                 WHERE last_monitored IS NULL 
                 OR last_monitored <= DATE_SUB(%s, INTERVAL 7 DAY)",
                current_time('Y-m-d')
            )
        );
        
        return $stats;
    }

    /**
     * Get content performance metrics
     */
    public function get_content_performance($days = 30) {
        if (!$this->table_exists('sac_content_log')) {
            return array(
                'avg_content_per_day' => 0,
                'success_rate' => 0,
                'total_generated' => 0,
                'total_failed' => 0
            );
        }
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $total = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['content_log']} 
                 WHERE DATE(generation_date) >= %s",
                $start_date
            )
        );
        
        $successful = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables['content_log']} 
                 WHERE DATE(generation_date) >= %s 
                 AND status = 'completed'",
                $start_date
            )
        );
        
        $failed = $total - $successful;
        $success_rate = $total > 0 ? round(($successful / $total) * 100, 1) : 0;
        $avg_per_day = round($total / $days, 1);
        
        return array(
            'avg_content_per_day' => $avg_per_day,
            'success_rate' => $success_rate,
            'total_generated' => $successful,
            'total_failed' => $failed
        );
    }
}