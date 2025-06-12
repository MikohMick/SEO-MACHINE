<?php
/**
 * Surge Detector Class
 * Monitors keyword performance and detects search volume surges
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Surge_Detector {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * API handler instance
     */
    protected $api_handler;
    
    /**
     * Surge threshold percentage
     */
    private $surge_threshold;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->api_handler = new SAC_API_Handler();
        $this->surge_threshold = SAC()->get_option('surge_threshold', 25);
    }

    /**
     * Get API handler instance
     */
    public function get_api_handler() {
        return $this->api_handler;
    }

    /**
     * Monitor keywords for surges (daily staggered monitoring)
     */
    public function monitor_keywords_daily($batch_size = 86) {
        global $wpdb;
        
        $results = array(
            'monitored' => 0,
            'surges_detected' => 0,
            'api_calls_used' => 0,
            'surge_keywords' => array()
        );
        
        // Get keywords that need monitoring
        $keywords = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT k.*, p.post_title as product_name
                 FROM {$wpdb->prefix}sac_keywords k
                 LEFT JOIN {$wpdb->prefix}posts p ON k.product_id = p.ID
                 WHERE k.last_monitored IS NULL 
                 OR k.last_monitored <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY k.last_monitored ASC, k.priority_score DESC
                 LIMIT %d",
                $batch_size
            )
        );
        
        if (empty($keywords)) {
            SAC()->log('No keywords found for monitoring');
            return $results;
        }
        
        foreach ($keywords as $keyword) {
            try {
                // Get current search volume
                $api_result = $this->api_handler->get_keyword_data($keyword->keyword_phrase);
                $results['api_calls_used']++;
                
                if (is_wp_error($api_result)) {
                    SAC()->log('API error for keyword ' . $keyword->keyword_phrase . ': ' . $api_result->get_error_message(), 'error');
                    continue;
                }
                
                // Calculate surge percentage
                $previous_volume = $keyword->current_search_volume;
                $current_volume = $api_result['search_volume'];
                $surge_percentage = 0;
                
                if ($previous_volume > 0) {
                    $surge_percentage = (($current_volume - $previous_volume) / $previous_volume) * 100;
                }
                
                // Update keyword data
                $wpdb->update(
                    $wpdb->prefix . 'sac_keywords',
                    array(
                        'previous_search_volume' => $previous_volume,
                        'current_search_volume' => $current_volume,
                        'surge_percentage' => $surge_percentage,
                        'last_monitored' => current_time('mysql')
                    ),
                    array('id' => $keyword->id)
                );
                
                $results['monitored']++;
                
                // Check if this is a surge
                if ($surge_percentage >= 25) {
                    $results['surges_detected']++;
                    $results['surge_keywords'][] = array(
                        'keyword' => $keyword->keyword_phrase,
                        'product' => $keyword->product_name,
                        'surge' => $surge_percentage,
                        'volume' => $current_volume
                    );
                }
                
            } catch (Exception $e) {
                SAC()->log('Error monitoring keyword ' . $keyword->keyword_phrase . ': ' . $e->getMessage(), 'error');
            }
        }
        
        return $results;
    }

    /**
     * Monitor a single keyword for surge
     */
    public function monitor_single_keyword($keyword_record) {
        $keyword_phrase = $keyword_record->keyword_phrase;
        $keyword_id = $keyword_record->id;
        
        // Get fresh search volume from API
        $api_response = $this->api_handler->get_keyword_suggestions($keyword_phrase);
        
        if (is_wp_error($api_response)) {
            SAC()->log("API error monitoring keyword '{$keyword_phrase}': " . $api_response->get_error_message(), 'error');
            return false;
        }
        
        // Extract current search volume
        $current_volume = $this->api_handler->extract_search_volume($api_response, $keyword_phrase);
        
        // Update keyword record with new volume
        $this->database->update_keyword_volume($keyword_id, $current_volume);
        
        // Check for surge
        $previous_volume = $keyword_record->current_search_volume;
        
        if ($previous_volume > 0) {
            $surge_percentage = (($current_volume - $previous_volume) / $previous_volume) * 100;
            
            if ($surge_percentage >= $this->surge_threshold) {
                // Surge detected!
                return array(
                    'surge_percentage' => round($surge_percentage, 2),
                    'current_volume' => $current_volume,
                    'previous_volume' => $previous_volume,
                    'keyword_id' => $keyword_id,
                    'keyword_phrase' => $keyword_phrase
                );
            }
        }
        
        return false;
    }

    /**
     * Get all keywords with recent surges
     */
    public function get_recent_surges($days = 7) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT k.*, p.post_title as product_name, p.ID as product_id
                 FROM {$this->database->get_table('keywords')} k
                 LEFT JOIN {$wpdb->posts} p ON k.product_id = p.ID
                 WHERE k.surge_percentage >= %f
                 AND k.last_monitored >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 ORDER BY k.surge_percentage DESC, k.current_search_volume DESC",
                $this->surge_threshold,
                $days
            )
        );
    }

    /**
     * Get top performing keywords by search volume
     */
    public function get_top_keywords($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT k.*, p.post_title as product_name, p.ID as product_id,
                 COALESCE(DATEDIFF(NOW(), k.last_article_date), 999) as days_since_article
                 FROM {$this->database->get_table('keywords')} k
                 LEFT JOIN {$wpdb->posts} p ON k.product_id = p.ID
                 WHERE k.current_search_volume > 0
                 ORDER BY k.current_search_volume DESC
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Calculate priority score for content generation
     */
    public function calculate_priority_score($keyword_record) {
        $search_volume = floatval($keyword_record->current_search_volume);
        $surge_percentage = floatval($keyword_record->surge_percentage);
        
        // Days since last article (default to 999 if never generated)
        $days_since_article = 999;
        if (!empty($keyword_record->last_article_date)) {
            $last_article = new DateTime($keyword_record->last_article_date);
            $now = new DateTime();
            $days_since_article = $now->diff($last_article)->days;
        }
        
        // Priority formula: SearchVolume * 0.4 + SurgePercentage * 0.3 + DaysSinceArticle * 0.3
        $priority_score = ($search_volume * 0.4) + ($surge_percentage * 0.3) + ($days_since_article * 0.3);
        
        return round($priority_score, 2);
    }

    /**
     * Update priority scores for all keywords
     */
    public function update_all_priority_scores() {
        global $wpdb;
        
        $keywords = $wpdb->get_results(
            "SELECT id, current_search_volume, surge_percentage, last_article_date 
             FROM {$this->database->get_table('keywords')}"
        );
        
        $updated = 0;
        
        foreach ($keywords as $keyword) {
            $priority_score = $this->calculate_priority_score($keyword);
            
            $result = $wpdb->update(
                $this->database->get_table('keywords'),
                array('priority_score' => $priority_score),
                array('id' => $keyword->id),
                array('%f'),
                array('%d')
            );
            
            if ($result !== false) {
                $updated++;
            }
        }
        
        SAC()->log("Updated priority scores for {$updated} keywords");
        return $updated;
    }

    /**
     * Get content generation queue (surge + fallback keywords)
     */
    public function get_content_generation_queue($limit = 5) {
        $queue = array();
        
        // First, get surge keywords
        $surge_keywords = $this->get_recent_surges(1); // Last 24 hours
        
        foreach ($surge_keywords as $keyword) {
            if (count($queue) >= $limit) break;
            
            $keyword->queue_type = 'surge';
            $keyword->priority_score = $this->calculate_priority_score($keyword);
            $queue[] = $keyword;
        }
        
        // If we need more, add fallback keywords
        if (count($queue) < $limit) {
            $remaining_slots = $limit - count($queue);
            $fallback_keywords = $this->database->get_fallback_keywords($remaining_slots);
            
            foreach ($fallback_keywords as $keyword) {
                $keyword->queue_type = 'fallback';
                $keyword->priority_score = $this->calculate_priority_score($keyword);
                $queue[] = $keyword;
            }
        }
        
        // Sort by priority score
        usort($queue, function($a, $b) {
            return $b->priority_score <=> $a->priority_score;
        });
        
        return array_slice($queue, 0, $limit);
    }

    /**
     * Check if keyword qualifies for surge-based content generation
     */
    public function is_surge_worthy($keyword_record) {
        // Must have surge above threshold
        if ($keyword_record->surge_percentage < $this->surge_threshold) {
            return false;
        }
        
        // Must have been checked recently (within 24 hours)
        if (!empty($keyword_record->last_monitored)) {
            $last_check = new DateTime($keyword_record->last_monitored);
            $now = new DateTime();
            $hours_since_check = $now->diff($last_check)->h + ($now->diff($last_check)->days * 24);
            
            if ($hours_since_check > 24) {
                return false;
            }
        }
        
        // Must have meaningful search volume
        if ($keyword_record->current_search_volume < 50) {
            return false;
        }
        
        return true;
    }

    /**
     * Get surge detection statistics
     */
    public function get_surge_statistics() {
        global $wpdb;
        
        $stats = array(
            'total_surges' => 0,
            'avg_surge' => 0,
            'highest_surge' => null,
            'monitored_today' => 0,
            'pending_monitoring' => 0
        );
        
        // Total surges in last 7 days
        $stats['total_surges'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords
                 WHERE surge_percentage >= %f
                 AND last_monitored >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                25.0
            )
        );
        
        // Average surge percentage
        $stats['avg_surge'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(surge_percentage) FROM {$wpdb->prefix}sac_keywords
                 WHERE surge_percentage >= %f
                 AND last_monitored >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                25.0
            )
        );
        
        // Highest surge in last 7 days
        $highest = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT keyword_phrase, surge_percentage, current_search_volume
                 FROM {$wpdb->prefix}sac_keywords
                 WHERE surge_percentage >= %f
                 AND last_monitored >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY surge_percentage DESC
                 LIMIT 1",
                25.0
            )
        );
        
        if ($highest) {
            $stats['highest_surge'] = array(
                'keyword' => $highest->keyword_phrase,
                'percentage' => $highest->surge_percentage,
                'volume' => $highest->current_search_volume
            );
        }
        
        // Keywords monitored today
        $stats['monitored_today'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords
                 WHERE DATE(last_monitored) = %s",
                current_time('Y-m-d')
            )
        );
        
        // Keywords pending monitoring
        $stats['pending_monitoring'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sac_keywords
             WHERE last_monitored IS NULL 
             OR last_monitored <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        );
        
        return $stats;
    }

    /**
     * Reset surge threshold
     */
    public function update_surge_threshold($new_threshold) {
        if ($new_threshold >= 5 && $new_threshold <= 100) {
            $this->surge_threshold = $new_threshold;
            SAC()->update_option('surge_threshold', $new_threshold);
            
            SAC()->log("Surge threshold updated to {$new_threshold}%");
            return true;
        }
        
        return false;
    }

    /**
     * Get surge trend analysis
     */
    public function get_surge_trends($days = 30) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(last_monitored) as date,
                 COUNT(*) as total_keywords_checked,
                 SUM(CASE WHEN surge_percentage >= %f THEN 1 ELSE 0 END) as surges_detected,
                 AVG(surge_percentage) as avg_surge_percentage,
                 MAX(surge_percentage) as max_surge_percentage
                 FROM {$this->database->get_table('keywords')}
                 WHERE last_monitored >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(last_monitored)
                 ORDER BY date DESC",
                $this->surge_threshold,
                $days
            )
        );
    }

    /**
     * Manual surge check for specific keyword
     */
    public function check_keyword_surge($keyword_id) {
        global $wpdb;
        
        $keyword_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->database->get_table('keywords')} WHERE id = %d",
                $keyword_id
            )
        );
        
        if (!$keyword_record) {
            return new WP_Error('keyword_not_found', 'Keyword not found');
        }
        
        return $this->monitor_single_keyword($keyword_record);
    }

    /**
     * Bulk surge check for all keywords (emergency function)
     */
    public function emergency_surge_check($limit = 200) {
        if (!$this->api_handler->check_daily_limit('keyword')) {
            return new WP_Error('api_limit', 'Daily API limit reached');
        }
        
        $keywords = $this->database->get_keywords_for_monitoring($limit);
        $results = array(
            'checked' => 0,
            'surges' => 0,
            'errors' => 0
        );
        
        foreach ($keywords as $keyword) {
            if (!$this->api_handler->check_daily_limit('keyword')) {
                break;
            }
            
            try {
                $surge = $this->monitor_single_keyword($keyword);
                $results['checked']++;
                
                if ($surge) {
                    $results['surges']++;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
            }
            
            usleep(1000000); // 1 second delay
        }
        
        return $results;
    }
}