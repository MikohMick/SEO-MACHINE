<?php
/**
 * Content Generator Class
 * Generates SEO-optimized content and places it on products and blog posts
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Content_Generator {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * API handler instance
     */
    private $api_handler;
    
    /**
     * Surge detector instance
     */
    private $surge_detector;
    
    /**
     * Daily content limit
     */
    private $daily_limit;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->api_handler = new SAC_API_Handler();
        $this->surge_detector = new SAC_Surge_Detector();
        $this->daily_limit = SAC()->get_option('daily_limit', 5);
    }

    /**
     * Generate daily content (main function called by cron)
     */
    public function generate_daily_content() {
        $results = array(
            'generated' => 0,
            'failed' => 0,
            'errors' => array(),
            'generated_content' => array()
        );
        
        // Check how many articles we've already generated today
        $generated_today = $this->get_daily_generation_count();
        
        if ($generated_today >= $this->daily_limit) {
            SAC()->log('Daily content generation limit already reached', 'info');
            return $results;
        }
        
        $remaining_slots = $this->daily_limit - $generated_today;
        
        SAC()->log("Starting daily content generation. Remaining slots: {$remaining_slots}");
        
        // Get content generation queue
        $queue = $this->surge_detector->get_content_generation_queue($remaining_slots);
        
        if (empty($queue)) {
            SAC()->log('No keywords found for content generation', 'warning');
            return $results;
        }
        
        foreach ($queue as $keyword_record) {
            // Check API limits
            if (!$this->api_handler->check_daily_limit('content')) {
                $results['errors'][] = 'Daily content API limit reached';
                break;
            }
            
            try {
                $content_result = $this->generate_content_for_keyword($keyword_record);
                
                if ($content_result && !is_wp_error($content_result)) {
                    $results['generated']++;
                    $results['generated_content'][] = array(
                        'keyword' => $keyword_record->keyword_phrase,
                        'product_id' => $keyword_record->product_id,
                        'blog_post_id' => $content_result['blog_post_id'],
                        'reason' => $keyword_record->queue_type
                    );
                    
                    SAC()->log("Successfully generated content for keyword: {$keyword_record->keyword_phrase}");
                } else {
                    $results['failed']++;
                    $error_msg = is_wp_error($content_result) ? $content_result->get_error_message() : 'Unknown error';
                    $results['errors'][] = "Failed to generate content for '{$keyword_record->keyword_phrase}': {$error_msg}";
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Exception generating content for '{$keyword_record->keyword_phrase}': " . $e->getMessage();
                SAC()->log("Exception in content generation: " . $e->getMessage(), 'error');
            }
        }
        
        SAC()->log("Daily content generation completed: {$results['generated']} generated, {$results['failed']} failed");
        
        return $results;
    }

    /**
     * Generate content for a specific keyword
     */
    public function generate_content_for_keyword($keyword_record) {
        $keyword = $keyword_record->keyword_phrase;
        $product_id = $keyword_record->product_id;
        $generation_reason = $keyword_record->queue_type ?? 'manual';
        
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', "Product not found: {$product_id}");
        }
        
        // Generate content using AI
        $api_response = $this->api_handler->generate_content($keyword);
        
        if (is_wp_error($api_response)) {
            return $api_response;
        }
        
        // Extract content from API response
        $full_content = $this->api_handler->extract_content($api_response);
        
        if (empty($full_content)) {
            return new WP_Error('empty_content', 'API returned empty content');
        }
        
        // Process and optimize the content
        $processed_content = $this->process_generated_content($full_content, $keyword, $product);
        
        // Create blog post
        $blog_post_id = $this->create_blog_post($keyword, $processed_content['full'], $product);
        
        if (is_wp_error($blog_post_id)) {
            return $blog_post_id;
        }
        
        // Update product description
        $product_updated = $this->update_product_description($product_id, $processed_content['excerpt'], $blog_post_id);
        
        if (!$product_updated) {
            SAC()->log("Failed to update product description for product {$product_id}", 'warning');
        }
        
        // Log the content generation
        $this->database->log_content_generation(
            $keyword_record->id,
            $product_id,
            $generation_reason,
            $processed_content['full'],
            $blog_post_id
        );
        
        return array(
            'blog_post_id' => $blog_post_id,
            'product_updated' => $product_updated,
            'content_length' => strlen($processed_content['full']),
            'excerpt_length' => strlen($processed_content['excerpt'])
        );
    }

    /**
     * Process and optimize generated content
     */
    private function process_generated_content($raw_content, $keyword, $product) {
        $product_name = $product->get_name();
        $product_price = $product->get_price();
        
        // Clean up the content
        $content = $this->clean_generated_content($raw_content);
        
        // Optimize for SEO
        $content = $this->optimize_content_for_seo($content, $keyword, $product_name);
        
        // Create excerpt (first 300 words for product page)
        $excerpt = $this->create_content_excerpt($content, 300);
        
        // Add call-to-action to excerpt
        $excerpt = $this->add_call_to_action($excerpt, $product_name, $product_price);
        
        return array(
            'full' => $content,
            'excerpt' => $excerpt
        );
    }

    /**
     * Clean up generated content
     */
    private function clean_generated_content($content) {
        // Remove extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Ensure proper paragraph breaks
        $content = str_replace(['. ', '! ', '? '], [".\n\n", "!\n\n", "?\n\n"], $content);
        
        // Clean up multiple line breaks
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Trim
        $content = trim($content);
        
        return $content;
    }

    /**
     * Optimize content for SEO
     */
    private function optimize_content_for_seo($content, $keyword, $product_name) {
        // Ensure keyword appears in first paragraph
        if (stripos(substr($content, 0, 200), $keyword) === false) {
            $first_sentence = "When it comes to {$keyword}, understanding the key features and benefits is essential for making an informed decision. ";
            $content = $first_sentence . $content;
        }
        
        // Add product-specific mentions
        $count = 0;
        $content = str_ireplace($keyword, $product_name, $content, $count); // Replace first occurrence with full product name
        
        // Ensure keyword density is appropriate (2-3%)
        $word_count = str_word_count($content);
        $keyword_count = substr_count(strtolower($content), strtolower($keyword));
        $target_density = max(2, min(5, round($word_count * 0.025))); // 2.5% target density
        
        if ($keyword_count < $target_density) {
            // Add more keyword mentions naturally
            $additional_mentions = $target_density - $keyword_count;
            for ($i = 0; $i < $additional_mentions; $i++) {
                $content .= " The {$keyword} offers exceptional value and performance.";
            }
        }
        
        return $content;
    }

    /**
     * Create content excerpt for product page
     */
    private function create_content_excerpt($content, $word_limit = 300) {
        $words = explode(' ', $content);
        
        if (count($words) <= $word_limit) {
            return $content;
        }
        
        $excerpt_words = array_slice($words, 0, $word_limit);
        $excerpt = implode(' ', $excerpt_words);
        
        // Ensure we end with a complete sentence
        $last_sentence_end = max(
            strrpos($excerpt, '.'),
            strrpos($excerpt, '!'),
            strrpos($excerpt, '?')
        );
        
        if ($last_sentence_end !== false && $last_sentence_end > strlen($excerpt) * 0.8) {
            $excerpt = substr($excerpt, 0, $last_sentence_end + 1);
        } else {
            $excerpt .= '...';
        }
        
        return $excerpt;
    }

    /**
     * Add call-to-action to content excerpt
     */
    private function add_call_to_action($excerpt, $product_name, $price) {
        $cta_options = array(
            "Discover why the {$product_name} is the perfect choice for your needs.",
            "Learn more about the {$product_name} and its outstanding features.",
            "Find out what makes the {$product_name} stand out from the competition.",
            "Explore the complete {$product_name} experience and specifications.",
            "See why customers choose the {$product_name} for quality and value."
        );
        
        $cta = $cta_options[array_rand($cta_options)];
        
        if (!empty($price)) {
            $cta .= " Starting at " . wc_price($price) . ".";
        }
        
        return $excerpt . "\n\n" . $cta;
    }

    /**
     * Create blog post from generated content
     */
    private function create_blog_post($keyword, $content, $product) {
        $product_name = $product->get_name();
        
        // Create SEO-optimized title
        $title_templates = array(
            "Complete {$keyword} Guide - Everything You Need to Know",
            "{$keyword} Review - Features, Benefits & Expert Analysis",
            "Ultimate {$keyword} Buying Guide - 2025 Edition",
            "{$keyword} Explained - Comprehensive Overview & Tips",
            "Best {$keyword} Guide - Expert Recommendations & Reviews"
        );
        
        $title = $title_templates[array_rand($title_templates)];
        
        // Create post content with proper structure
        $post_content = $this->structure_blog_content($content, $keyword, $product);
        
        // Create the post
        $post_data = array(
            'post_title' => $title,
            'post_content' => $post_content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_category' => array($this->get_product_insights_category_id()),
            'meta_input' => array(
                '_sac_generated_content' => 1,
                '_sac_keyword' => $keyword,
                '_sac_product_id' => $product->get_id(),
                '_sac_generation_date' => current_time('mysql')
            )
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            SAC()->log("Failed to create blog post for keyword '{$keyword}': " . $post_id->get_error_message(), 'error');
            return $post_id;
        }
        
        // Set featured image if product has one
        $product_image_id = $product->get_image_id();
        if ($product_image_id) {
            set_post_thumbnail($post_id, $product_image_id);
        }
        
        SAC()->log("Created blog post (ID: {$post_id}) for keyword: {$keyword}");
        
        return $post_id;
    }

    /**
     * Structure blog content with proper headings and sections
     */
    private function structure_blog_content($content, $keyword, $product) {
        $product_name = $product->get_name();
        $product_price = $product->get_price();
        $product_url = $product->get_permalink();
        
        // Split content into paragraphs
        $paragraphs = explode("\n\n", $content);
        
        // Structure the content
        $structured_content = '';
        
        // Introduction
        $structured_content .= "<h2>Introduction to {$keyword}</h2>\n";
        $structured_content .= isset($paragraphs[0]) ? "<p>{$paragraphs[0]}</p>\n\n" : '';
        
        // Key Features
        $structured_content .= "<h2>Key Features and Benefits</h2>\n";
        $structured_content .= isset($paragraphs[1]) ? "<p>{$paragraphs[1]}</p>\n\n" : '';
        
        // Detailed Analysis
        $structured_content .= "<h2>Detailed Analysis</h2>\n";
        for ($i = 2; $i < min(count($paragraphs), 5); $i++) {
            $structured_content .= "<p>{$paragraphs[$i]}</p>\n\n";
        }
        
        // Product recommendation section
        $structured_content .= "<h2>Our Recommendation: {$product_name}</h2>\n";
        $structured_content .= "<p>Based on our analysis of {$keyword}, we highly recommend the {$product_name}. ";
        $structured_content .= "This product offers exceptional value and performance that meets the highest standards.</p>\n\n";
        
        if (!empty($product_price)) {
            $structured_content .= "<p><strong>Current Price:</strong> " . wc_price($product_price) . "</p>\n\n";
        }
        
        // Call to action
        $structured_content .= "<p><a href='{$product_url}' class='button' target='_blank'>View {$product_name} Details</a></p>\n\n";
        
        // Conclusion
        $structured_content .= "<h2>Conclusion</h2>\n";
        $structured_content .= "<p>In conclusion, {$keyword} represents an important consideration for anyone looking to make an informed purchase decision. ";
        $structured_content .= "The {$product_name} stands out as an excellent choice that delivers on both quality and value.</p>\n\n";
        
        return $structured_content;
    }

    /**
     * Update product description with generated content excerpt
     */
    private function update_product_description($product_id, $excerpt, $blog_post_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        $current_description = $product->get_description();
        $blog_post_url = get_permalink($blog_post_id);
        
        // Create the new content section
        $new_section = "<div class='sac-generated-content'>\n";
        $new_section .= "<h3>Product Overview</h3>\n";
        $new_section .= "<div class='sac-excerpt'>{$excerpt}</div>\n";
        $new_section .= "<p><a href='{$blog_post_url}' class='sac-read-more'>Read Full Article →</a></p>\n";
        $new_section .= "</div>\n\n";
        
        // Combine with existing description
        $updated_description = $new_section . $current_description;
        
        // Update the product
        $product->set_description($updated_description);
        $result = $product->save();
        
        if ($result) {
            // Store metadata
            update_post_meta($product_id, '_sac_last_updated', current_time('mysql'));
            update_post_meta($product_id, '_sac_blog_post_id', $blog_post_id);
            
            SAC()->log("Updated product description for product {$product_id}");
            return true;
        }
        
        return false;
    }

    /**
     * Get or create "Product Insights" category
     */
    private function get_product_insights_category_id() {
        $category_name = 'Product Insights';
        $category_slug = 'product-insights';
        
        // Check if category exists
        $category = get_category_by_slug($category_slug);
        
        if ($category) {
            return $category->term_id;
        }
        
        // Create the category
        $category_data = wp_insert_term(
            $category_name,
            'category',
            array(
                'slug' => $category_slug,
                'description' => 'AI-generated product insights and reviews'
            )
        );
        
        if (is_wp_error($category_data)) {
            SAC()->log('Failed to create Product Insights category: ' . $category_data->get_error_message(), 'error');
            return 1; // Default to uncategorized
        }
        
        return $category_data['term_id'];
    }

    /**
     * Get daily generation count
     */
    public function get_daily_generation_count($date = null) {
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->database->get_table('content_log')} 
                 WHERE DATE(generation_date) = %s",
                $date
            )
        );
    }

    /**
     * Get remaining daily generation slots
     */
    public function get_remaining_daily_slots() {
        $generated_today = $this->get_daily_generation_count();
        return max(0, $this->daily_limit - $generated_today);
    }

    /**
     * Manual content generation for specific product
     */
    public function generate_content_manually($product_id, $keyword = null) {
        // Check daily limits
        if ($this->get_remaining_daily_slots() <= 0) {
            return new WP_Error('daily_limit', 'Daily content generation limit reached');
        }
        
        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found');
        }
        
        // Get keyword (from meta or provided)
        if (!$keyword) {
            $keyword = get_post_meta($product_id, '_sac_keyword', true);
        }
        
        if (empty($keyword)) {
            return new WP_Error('no_keyword', 'No keyword found for this product');
        }
        
        // Create mock keyword record for generation
        $keyword_record = (object) array(
            'id' => 0,
            'keyword_phrase' => $keyword,
            'product_id' => $product_id,
            'queue_type' => 'manual',
            'current_search_volume' => 0,
            'surge_percentage' => 0
        );
        
        return $this->generate_content_for_keyword($keyword_record);
    }

    /**
     * Get content generation statistics
     */
    public function get_generation_statistics($days = 30) {
        global $wpdb;
        
        $stats = array();
        
        // Total content generated
        $stats['total_generated'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->database->get_table('content_log')}"
        );
        
        // Generated today
        $stats['generated_today'] = $this->get_daily_generation_count();
        
        // Remaining today
        $stats['remaining_today'] = $this->get_remaining_daily_slots();
        
        // Generated this period
        $stats['generated_period'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->database->get_table('content_log')}
                 WHERE generation_date >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        
        // Breakdown by reason
        $stats['by_reason'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT generation_reason, COUNT(*) as count
                 FROM {$this->database->get_table('content_log')}
                 WHERE generation_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY generation_reason",
                $days
            ),
            OBJECT_K
        );
        
        // Recent generations
        $stats['recent_generations'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cl.*, k.keyword_phrase, p.post_title as product_name
                 FROM {$this->database->get_table('content_log')} cl
                 LEFT JOIN {$this->database->get_table('keywords')} k ON cl.keyword_id = k.id
                 LEFT JOIN {$wpdb->posts} p ON cl.product_id = p.ID
                 ORDER BY cl.generation_date DESC
                 LIMIT %d",
                10
            )
        );
        
        return $stats;
    }

    /**
     * Clean up old generated content
     */
    public function cleanup_old_content($days = 90) {
        global $wpdb;
        
        // Get old blog posts
        $old_posts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_sac_generated_content'
                 AND p.post_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        
        $deleted = 0;
        
        foreach ($old_posts as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted++;
            }
        }
        
        SAC()->log("Cleaned up {$deleted} old generated blog posts");
        
        return $deleted;
    }

    /**
     * Get content performance data
     */
    public function get_content_performance() {
        global $wpdb;
        
        // Get blog post view data (if analytics plugin available)
        $performance = array();
        
        // Generated content posts
        $generated_posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, pm.meta_value as keyword
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sac_keyword'
             AND p.post_type = 'post'
             AND p.post_status = 'publish'
             ORDER BY p.post_date DESC
             LIMIT 20"
        );
        
        foreach ($generated_posts as $post) {
            $performance[] = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'keyword' => $post->keyword,
                'date' => $post->post_date,
                'views' => get_post_meta($post->ID, 'post_views_count', true) ?: 0,
                'url' => get_permalink($post->ID)
            );
        }
        
        return $performance;
    }

    /**
     * Regenerate content for existing keyword
     */
    public function regenerate_content($keyword_id) {
        global $wpdb;
        
        // Get keyword record
        $keyword_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT k.*, p.post_title as product_name
                 FROM {$this->database->get_table('keywords')} k
                 LEFT JOIN {$wpdb->posts} p ON k.product_id = p.ID
                 WHERE k.id = %d",
                $keyword_id
            )
        );
        
        if (!$keyword_record) {
            return new WP_Error('keyword_not_found', 'Keyword not found');
        }
        
        // Check daily limits
        if ($this->get_remaining_daily_slots() <= 0) {
            return new WP_Error('daily_limit', 'Daily content generation limit reached');
        }
        
        $keyword_record->queue_type = 'regenerate';
        
        return $this->generate_content_for_keyword($keyword_record);
    }

    /**
     * Update daily content limit
     */
    public function update_daily_limit($new_limit) {
        if ($new_limit >= 1 && $new_limit <= 50) {
            $this->daily_limit = $new_limit;
            SAC()->update_option('daily_limit', $new_limit);
            
            SAC()->log("Daily content limit updated to {$new_limit}");
            return true;
        }
        
        return false;
    }

    /**
     * Get content generation queue preview
     */
    public function get_queue_preview($limit = 10) {
        return $this->surge_detector->get_content_generation_queue($limit);
    }
}