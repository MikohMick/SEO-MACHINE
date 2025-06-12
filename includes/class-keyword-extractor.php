<?php
/**
 * Keyword Extractor Class
 * Extracts meaningful keywords from WooCommerce product titles
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Keyword_Extractor {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * API handler instance
     */
    private $api_handler;
    
    /**
     * Words to exclude from keywords (promotional terms, storage, etc.)
     */
    private $exclude_patterns = array(
        // Promotional terms
        '/\(.*?\)/',                    // Anything in parentheses
        '/\[.*?\]/',                    // Anything in brackets
        '/pre-order/i',
        '/lipa pole pole/i',
        '/on whatsapp/i',
        '/sale/i',
        '/discount/i',
        '/offer/i',
        '/deal/i',
        '/free shipping/i',
        '/limited time/i',
        
        // Storage and technical specs
        '/\d+gb/i',
        '/\d+tb/i',
        '/\d+mb/i',
        '/\d+\.?\d*gb/i',
        '/\d+\.?\d*tb/i',
        
        // Colors (unless they're part of official model names)
        '/\b(black|white|red|blue|green|yellow|pink|purple|gray|grey|silver|gold|rose gold|space gray|midnight|starlight|alpine green|sierra blue|graphite|pacific blue|coral|yellow|product red)\b/i',
        
        // Sizes
        '/size \d+/i',
        '/\d+mm/i',
        '/\d+inch/i',
        '/\d+"/',
        
        // Generic terms
        '/new/i',
        '/original/i',
        '/genuine/i',
        '/authentic/i',
        '/official/i',
        '/brand new/i',
        '/factory sealed/i'
    );
    
    /**
     * Brand mapping for consistent extraction
     */
    private $brand_patterns = array(
        'apple' => '/\b(apple|iphone|ipad|ipod|macbook|imac|apple watch)\b/i',
        'samsung' => '/\b(samsung|galaxy)\b/i',
        'google' => '/\b(google|pixel)\b/i',
        'huawei' => '/\b(huawei|honor)\b/i',
        'xiaomi' => '/\b(xiaomi|redmi|mi)\b/i',
        'oppo' => '/\b(oppo|realme|oneplus)\b/i',
        'vivo' => '/\b(vivo|iqoo)\b/i',
        'nokia' => '/\b(nokia|hmd)\b/i',
        'sony' => '/\b(sony|xperia)\b/i',
        'lg' => '/\b(lg)\b/i',
        'motorola' => '/\b(motorola|moto)\b/i',
        'techno' => '/\b(techno|infinix|itel)\b/i',
        'hp' => '/\b(hp|hewlett|packard)\b/i',
        'dell' => '/\b(dell|alienware)\b/i',
        'lenovo' => '/\b(lenovo|thinkpad|ideapad)\b/i',
        'asus' => '/\b(asus|rog)\b/i',
        'acer' => '/\b(acer|predator)\b/i',
        'microsoft' => '/\b(microsoft|surface)\b/i',
        'nintendo' => '/\b(nintendo|switch)\b/i',
        'playstation' => '/\b(playstation|ps\d|sony)\b/i',
        'xbox' => '/\b(xbox|microsoft)\b/i'
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->api_handler = new SAC_API_Handler();
    }

    /**
     * Process all WooCommerce products and extract keywords
     */
    public function process_all_products($limit = 100, $offset = 0) {
        $processed = 0;
        $errors = array();
        
        // Get WooCommerce products
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'meta_query' => array(
                array(
                    'key' => '_sac_keyword',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        
        $products = get_posts($args);
        
        foreach ($products as $product) {
            try {
                $result = $this->process_single_product($product->ID);
                if ($result) {
                    $processed++;
                } else {
                    $errors[] = "Failed to process product ID: {$product->ID}";
                }
            } catch (Exception $e) {
                $errors[] = "Error processing product ID {$product->ID}: " . $e->getMessage();
                SAC()->log("Error processing product {$product->ID}: " . $e->getMessage(), 'error');
            }
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'total_products' => count($products)
        );
    }

    /**
     * Process a single product
     */
    public function process_single_product($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            SAC()->log("Product not found: {$product_id}", 'error');
            return false;
        }
        
        $title = $product->get_name();
        if (empty($title)) {
            SAC()->log("Empty product title for ID: {$product_id}", 'warning');
            return false;
        }
        
        // Extract main keyword from title
        $keyword = $this->extract_main_keyword($title);
        
        if (empty($keyword)) {
            SAC()->log("Could not extract keyword from title: {$title}", 'warning');
            return false;
        }
        
        // Store extracted keyword as product meta
        update_post_meta($product_id, '_sac_keyword', $keyword);
        
        // Get keyword suggestions from API
        $api_response = $this->api_handler->get_keyword_suggestions($keyword);
        
        if (is_wp_error($api_response)) {
            SAC()->log("API error for keyword '{$keyword}': " . $api_response->get_error_message(), 'error');
            return false;
        }
        
        // Extract search volume
        $search_volume = $this->api_handler->extract_search_volume($api_response, $keyword);
        
        // Store main keyword in database
        $keyword_id = $this->database->insert_keyword($product_id, $keyword, $search_volume);
        
        if (!$keyword_id) {
            SAC()->log("Failed to insert keyword '{$keyword}' for product {$product_id}", 'error');
            return false;
        }
        
        // Get related keywords and store them too
        $related_keywords = $this->api_handler->get_related_keywords($api_response, 50); // Min 50 searches
        
        foreach ($related_keywords as $related) {
            // Avoid duplicates
            if (strtolower($related['keyword']) !== strtolower($keyword)) {
                $this->database->insert_keyword($product_id, $related['keyword'], $related['volume']);
            }
        }
        
        SAC()->log("Successfully processed product {$product_id} with keyword: {$keyword}");
        return true;
    }

    /**
     * Extract main keyword from product title
     */
    public function extract_main_keyword($title) {
        // Clean the title first
        $cleaned_title = $this->clean_title($title);
        
        // Try to extract Brand + Model pattern
        $keyword = $this->extract_brand_model($cleaned_title);
        
        // If no clear brand+model found, extract the most meaningful phrase
        if (empty($keyword)) {
            $keyword = $this->extract_meaningful_phrase($cleaned_title);
        }
        
        return trim($keyword);
    }

    /**
     * Clean title by removing promotional and unwanted terms
     */
    private function clean_title($title) {
        $cleaned = $title;
        
        // Apply exclude patterns
        foreach ($this->exclude_patterns as $pattern) {
            $cleaned = preg_replace($pattern, ' ', $cleaned);
        }
        
        // Clean up extra spaces
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }

    /**
     * Extract Brand + Model pattern
     */
    private function extract_brand_model($title) {
        $title_lower = strtolower($title);
        
        // Specific patterns for common products
        $patterns = array(
            // iPhone patterns
            '/\b(apple\s+)?(iphone\s+\d+[a-z]*(?:\s+pro(?:\s+max)?)?)/i',
            
            // Samsung Galaxy patterns
            '/\b(samsung\s+)?galaxy\s+[a-z]+\s*\d+[a-z]*/i',
            
            // Google Pixel patterns
            '/\b(google\s+)?pixel\s+\d+[a-z]*/i',
            
            // Generic Brand Model patterns
            '/\b([a-z]+)\s+([a-z0-9]+(?:\s+[a-z0-9]+)*)/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $match = trim($matches[0]);
                
                // Validate the match has both brand and model
                if ($this->validate_brand_model($match)) {
                    return $match;
                }
            }
        }
        
        return '';
    }

    /**
     * Validate if extracted phrase contains both brand and model
     */
    private function validate_brand_model($phrase) {
        $phrase_lower = strtolower($phrase);
        
        // Check if it contains a known brand
        $has_brand = false;
        foreach ($this->brand_patterns as $brand => $pattern) {
            if (preg_match($pattern, $phrase_lower)) {
                $has_brand = true;
                break;
            }
        }
        
        // Check if it has a model identifier (number or meaningful word)
        $has_model = preg_match('/\b\d+\b|\b[a-z]{2,}\b/i', $phrase);
        
        // Minimum length check
        $min_length = strlen($phrase) >= 5;
        
        return $has_brand && $has_model && $min_length;
    }

    /**
     * Extract meaningful phrase when brand+model pattern fails
     */
    private function extract_meaningful_phrase($title) {
        $words = explode(' ', $title);
        
        // Remove single characters and very short words
        $words = array_filter($words, function($word) {
            return strlen(trim($word)) >= 2;
        });
        
        if (count($words) >= 2) {
            // Take first 2-3 meaningful words
            $meaningful_count = min(3, count($words));
            $phrase = implode(' ', array_slice($words, 0, $meaningful_count));
            
            // Ensure minimum quality
            if (strlen($phrase) >= 5) {
                return $phrase;
            }
        }
        
        // Fallback: return first significant word if available
        foreach ($words as $word) {
            if (strlen($word) >= 4) {
                return $word;
            }
        }
        
        return '';
    }

    /**
     * Get products without extracted keywords
     */
    public function get_unprocessed_products($limit = 100) {
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => '_sac_keyword',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'fields' => 'ids'
        );
        
        return get_posts($args);
    }

    /**
     * Re-extract keyword for a specific product
     */
    public function reprocess_product($product_id) {
        // Remove existing keyword meta
        delete_post_meta($product_id, '_sac_keyword');
        
        // Remove existing keywords from database
        global $wpdb;
        $wpdb->delete(
            $this->database->get_table('keywords'),
            array('product_id' => $product_id),
            array('%d')
        );
        
        // Process again
        return $this->process_single_product($product_id);
    }

    /**
     * Batch process products with progress tracking
     */
    public function batch_process($batch_size = 50) {
        $unprocessed = $this->get_unprocessed_products($batch_size);
        $results = array(
            'total' => count($unprocessed),
            'processed' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($unprocessed as $product_id) {
            // Check API limits before processing
            if (!$this->api_handler->check_daily_limit('keyword')) {
                $results['errors'][] = 'Daily keyword API limit reached';
                break;
            }
            
            $success = $this->process_single_product($product_id);
            
            if ($success) {
                $results['processed']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to process product ID: {$product_id}";
            }
            
            // Small delay to avoid overwhelming the API
            usleep(500000); // 0.5 seconds
        }
        
        return $results;
    }

    /**
     * Get extraction statistics
     */
    public function get_extraction_statistics() {
        global $wpdb;
        
        $total_products = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        );
        
        $processed_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'product' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_sac_keyword'"
        );
        
        $total_keywords = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->database->get_table('keywords')}"
        );
        
        return array(
            'total_products' => intval($total_products),
            'processed_products' => intval($processed_products),
            'unprocessed_products' => intval($total_products - $processed_products),
            'total_keywords' => intval($total_keywords),
            'completion_percentage' => $total_products > 0 ? round(($processed_products / $total_products) * 100, 2) : 0
        );
    }

    /**
     * Manual keyword extraction for testing
     */
    public function test_extraction($title) {
        return array(
            'original_title' => $title,
            'cleaned_title' => $this->clean_title($title),
            'extracted_keyword' => $this->extract_main_keyword($title),
            'brand_model' => $this->extract_brand_model($this->clean_title($title)),
            'meaningful_phrase' => $this->extract_meaningful_phrase($this->clean_title($title))
        );
    }

    /**
     * Get recently extracted keywords
     */
    public function get_recent_extractions($limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID as product_id, p.post_title, pm.meta_value as keyword, k.current_search_volume
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 LEFT JOIN {$this->database->get_table('keywords')} k ON p.ID = k.product_id AND pm.meta_value = k.keyword_phrase
                 WHERE p.post_type = 'product' 
                 AND pm.meta_key = '_sac_keyword'
                 ORDER BY p.post_modified DESC
                 LIMIT %d",
                $limit
            )
        );
    }
}