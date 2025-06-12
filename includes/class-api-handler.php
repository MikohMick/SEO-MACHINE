<?php
/**
 * API Handler Class
 * Manages API calls to Keyword Suggestion and GPT-4 Content Generation APIs
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_API_Handler {
    
    /**
     * API endpoints
     */
    private $endpoints = array(
        'keyword' => 'https://twinword-keyword-suggestion-v1.p.rapidapi.com/suggest/',
        'content' => 'https://api.openai.com/v1/chat/completions' // GPT-4 endpoint
    );
    
    /**
     * API limits per month
     */
    private $limits = array(
        'keyword' => 2000,  // RapidAPI limit
        'content' => 10000  // OpenAI limit (adjust based on your plan)
    );
    
    /**
     * Daily limits (calculated from monthly)
     */
    private $daily_limits = array(
        'keyword' => 67,   // 2000/30
        'content' => 333   // 10000/30 (much higher with GPT-4!)
    );
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Plugin options
     */
    private $options;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->options = get_option('sac_options', array());
    }

    /**
     * Get keyword suggestions from Twinword API
     */
    public function get_keyword_suggestions($phrase, $location = 'US', $language = 'en') {
        $start_time = microtime(true);
        
        // Check daily limits
        if (!$this->check_daily_limit('keyword')) {
            SAC()->log('Keyword API daily limit reached', 'warning');
            return new WP_Error('api_limit', 'Daily keyword API limit reached');
        }
        
        // Prepare request
        $url = add_query_arg(array(
            'phrase' => urlencode($phrase),
            'lang' => $language,
            'loc' => $location
        ), $this->endpoints['keyword']);
        
        $headers = array(
            'x-rapidapi-host' => 'twinword-keyword-suggestion-v1.p.rapidapi.com',
            'x-rapidapi-key' => $this->get_api_key()
        );
        
        // Make API call
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        $execution_time = microtime(true) - $start_time;
        
        // Handle response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            SAC()->log("Keyword API Error: {$error_message}", 'error');
            
            // Log API call
            $this->database->log_api_call(
                'keyword',
                $url,
                array('phrase' => $phrase, 'lang' => $language, 'loc' => $location),
                $error_message,
                0,
                $execution_time,
                $error_message
            );
            
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log API call
        $this->database->log_api_call(
            'keyword',
            $url,
            array('phrase' => $phrase, 'lang' => $language, 'loc' => $location),
            $response_body,
            $response_code,
            $execution_time,
            $response_code !== 200 ? 'HTTP Error: ' . $response_code : null
        );
        
        if ($response_code !== 200) {
            SAC()->log("Keyword API HTTP Error: {$response_code}", 'error');
            return new WP_Error('api_error', "API returned status code: {$response_code}");
        }
        
        // Parse JSON response
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            SAC()->log('Keyword API JSON decode error: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_error', 'Invalid JSON response from API');
        }
        
        // Update daily usage counter
        $this->increment_daily_usage('keyword');
        
        SAC()->log("Keyword API success for phrase: {$phrase}");
        return $data;
    }

    /**
     * Generate content using GPT-4 API
     */
    public function generate_content($keyword) {
        $start_time = microtime(true);
        
        // Check daily limits
        if (!$this->check_daily_limit('content')) {
            SAC()->log('Content API daily limit reached', 'warning');
            return new WP_Error('api_limit', 'Daily content API limit reached');
        }
        
        // Get OpenAI API key
        $openai_key = $this->get_openai_api_key();
        if (empty($openai_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }
        
        // Prepare GPT-4 request
        $url = $this->endpoints['content'];
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $openai_key
        );
        
        // Create optimized prompt for product content
        $prompt = $this->create_gpt4_prompt($keyword);
        
        $body = json_encode(array(
            'model' => 'gpt-4o-mini', // Cost-effective but high quality
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => 'You are an expert SEO content writer specializing in product descriptions and buying guides. Create engaging, informative content that helps customers make purchasing decisions.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 800,        // ~400-600 words
            'temperature' => 0.7,       // Creative but focused
            'top_p' => 0.9,
            'frequency_penalty' => 0.3,  // Reduce repetition
            'presence_penalty' => 0.1
        ));
        
        // Make API call
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        $execution_time = microtime(true) - $start_time;
        
        // Handle response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            SAC()->log("GPT-4 API Error: {$error_message}", 'error');
            
            // Log API call
            $this->database->log_api_call(
                'content',
                $url,
                json_decode($body, true),
                $error_message,
                0,
                $execution_time,
                $error_message
            );
            
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log API call
        $this->database->log_api_call(
            'content',
            $url,
            json_decode($body, true),
            $response_body,
            $response_code,
            $execution_time,
            $response_code !== 200 ? 'HTTP Error: ' . $response_code : null
        );
        
        if ($response_code !== 200) {
            SAC()->log("GPT-4 API HTTP Error: {$response_code}", 'error');
            return new WP_Error('api_error', "GPT-4 API returned status code: {$response_code}");
        }
        
        // Parse GPT-4 JSON response
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            SAC()->log('GPT-4 API JSON decode error: ' . json_last_error_msg(), 'error');
            return new WP_Error('json_error', 'Invalid JSON response from GPT-4 API');
        }
        
        // Extract content from GPT-4 response
        $content = $this->extract_gpt4_content($data);
        if (empty($content)) {
            SAC()->log('GPT-4 API returned empty content', 'error');
            return new WP_Error('empty_content', 'GPT-4 API returned empty content');
        }
        
        // Update daily usage counter
        $this->increment_daily_usage('content');
        
        SAC()->log("GPT-4 API success for keyword: {$keyword}");
        
        // Return in consistent format
        return array(
            'content' => $content,
            'model' => 'gpt-4o-mini',
            'tokens_used' => $data['usage']['total_tokens'] ?? 0
        );
    }

    /**
     * Create optimized GPT-4 prompt for product content
     */
    private function create_gpt4_prompt($keyword) {
        $templates = array(
            "Write a comprehensive buying guide for '{$keyword}'. Include key features, benefits, what to look for when purchasing, and why customers should consider this product. Make it informative and helpful for potential buyers.",
            
            "Create an expert review article about '{$keyword}'. Cover the main features, performance aspects, pros and cons, and provide useful insights for customers researching this product.",
            
            "Write an informative article about '{$keyword}' that helps customers understand the product better. Include specifications, use cases, benefits, and practical advice for potential buyers.",
            
            "Create a detailed product guide for '{$keyword}'. Explain what makes this product valuable, key features to consider, and helpful tips for customers looking to purchase.",
            
            "Write an engaging article about '{$keyword}' focusing on customer benefits, practical applications, and important considerations for buyers. Make it informative and decision-helpful."
        );
        
        // Select random template for variety
        $template = $templates[array_rand($templates)];
        
        // Add additional context
        $context = "\n\nGuidelines:\n";
        $context .= "- Write in a helpful, informative tone\n";
        $context .= "- Include practical advice for buyers\n";
        $context .= "- Use natural language with good SEO practices\n";
        $context .= "- Make it around 400-500 words\n";
        $context .= "- Focus on customer value and benefits\n";
        $context .= "- Include relevant technical details when helpful";
        
        return $template . $context;
    }

    /**
     * Extract content from GPT-4 response
     */
    private function extract_gpt4_content($response) {
        if (!isset($response['choices']) || empty($response['choices'])) {
            return '';
        }
        
        $choice = $response['choices'][0];
        
        if (isset($choice['message']['content'])) {
            return trim($choice['message']['content']);
        }
        
        return '';
    }

    /**
     * LEGACY: Create optimized topic for content generation (kept for backward compatibility)
     */
    private function create_content_topic($keyword) {
        // Create SEO-optimized topic that encourages product-focused content
        $templates = array(
            "Complete guide to {$keyword} - features, benefits and buying advice",
            "Everything you need to know about {$keyword} - expert review and comparison",
            "Best {$keyword} guide - top features, specifications and user experience",
            "{$keyword} comprehensive review - performance, quality and value analysis",
            "Ultimate {$keyword} buying guide - features, pros and cons explained"
        );
        
        // Select random template for variety
        $template = $templates[array_rand($templates)];
        
        return $template;
    }

    /**
     * Check if API daily limit is reached
     */
    public function check_daily_limit($api_type) {
        $today = current_time('Y-m-d');
        $usage_today = $this->database->get_daily_api_usage($api_type, $today);
        $limit = $this->daily_limits[$api_type];
        
        return $usage_today < $limit;
    }

    /**
     * Get remaining API calls for today
     */
    public function get_remaining_calls($api_type) {
        $today = current_time('Y-m-d');
        $usage_today = $this->database->get_daily_api_usage($api_type, $today);
        $limit = $this->daily_limits[$api_type];
        
        return max(0, $limit - $usage_today);
    }

    /**
     * Increment daily usage counter
     */
    private function increment_daily_usage($api_type) {
        $options = get_option('sac_options', array());
        $today = current_time('Y-m-d');
        
        // Reset counter if it's a new day
        if (!isset($options['api_calls_today']['date']) || $options['api_calls_today']['date'] !== $today) {
            $options['api_calls_today'] = array(
                'keyword' => 0,
                'content' => 0,
                'date' => $today
            );
        }
        
        // Increment counter
        $options['api_calls_today'][$api_type]++;
        
        // Save updated options
        update_option('sac_options', $options);
    }

    /**
     * Get RapidAPI key from options
     */
    private function get_api_key() {
        $api_key = SAC()->get_option('api_key');
        
        if (empty($api_key)) {
            // Fallback to hardcoded key (temporary for development)
            $api_key = '3927255b82mshb7dbcefe448534bp10f533jsn647f3044e764';
        }
        
        return $api_key;
    }

    /**
     * Get OpenAI API key
     */
    private function get_openai_api_key() {
        // First try from plugin options
        $api_key = SAC()->get_option('openai_api_key');
        
        if (empty($api_key)) {
            // Fallback: try from wp-config.php
            if (defined('OPENAI_API_KEY')) {
                $api_key = OPENAI_API_KEY;
            }
        }
        
        return $api_key;
    }

    /**
     * Test API connectivity
     */
    public function test_apis() {
        $results = array();
        
        // Test keyword API
        $keyword_test = $this->get_keyword_suggestions('test phone', 'US', 'en');
        $results['keyword'] = array(
            'success' => !is_wp_error($keyword_test),
            'error' => is_wp_error($keyword_test) ? $keyword_test->get_error_message() : null,
            'response' => is_wp_error($keyword_test) ? null : $keyword_test
        );
        
        // Test content API (GPT-4)
        $content_test = $this->generate_content('test product review');
        $results['content'] = array(
            'success' => !is_wp_error($content_test),
            'error' => is_wp_error($content_test) ? $content_test->get_error_message() : null,
            'response' => is_wp_error($content_test) ? null : $content_test
        );
        
        return $results;
    }

    /**
     * Get API usage statistics
     */
    public function get_usage_statistics() {
        $today = current_time('Y-m-d');
        
        return array(
            'keyword' => array(
                'used_today' => $this->database->get_daily_api_usage('keyword', $today),
                'remaining_today' => $this->get_remaining_calls('keyword'),
                'daily_limit' => $this->daily_limits['keyword'],
                'monthly_limit' => $this->limits['keyword']
            ),
            'content' => array(
                'used_today' => $this->database->get_daily_api_usage('content', $today),
                'remaining_today' => $this->get_remaining_calls('content'),
                'daily_limit' => $this->daily_limits['content'],
                'monthly_limit' => $this->limits['content']
            )
        );
    }

    /**
     * Test keyword API connection
     */
    public function test_keyword_api() {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return 'API key not configured';
        }
        
        try {
            // Use existing method to test with a simple keyword
            $test_result = $this->get_keyword_suggestions('test', 'US', 'en');
            
            if (is_wp_error($test_result)) {
                return $test_result->get_error_message();
            }
            
            // Check if response is valid
            if ($this->validate_keyword_response($test_result)) {
                return true;
            } else {
                return 'Invalid response format from keyword API';
            }
            
        } catch (Exception $e) {
            return 'Test failed: ' . $e->getMessage();
        }
    }

    /**
     * Test GPT-4 content API connection
     */
    public function test_content_api() {
        $api_key = $this->get_openai_api_key();
        
        if (empty($api_key)) {
            return 'OpenAI API key not configured';
        }
        
        try {
            // Test with a simple GPT-4 request
            $url = $this->endpoints['content'];
            
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            );
            
            $body = json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Say "API test successful" if you can respond.'
                    )
                ),
                'max_tokens' => 20,
                'temperature' => 0.1
            ));
            
            // Make API call with shorter timeout for testing
            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => $body,
                'timeout' => 15,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                return 'Connection failed: ' . $response->get_error_message();
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                
                if (json_last_error() === JSON_ERROR_NONE && isset($data['choices'][0]['message']['content'])) {
                    return true; // GPT-4 is working!
                } else {
                    return 'Invalid response format from GPT-4';
                }
            } elseif ($response_code === 401) {
                return 'Invalid OpenAI API key';
            } elseif ($response_code === 429) {
                return 'OpenAI API rate limit exceeded';
            } elseif ($response_code === 402) {
                return 'OpenAI account billing issue - check your account';
            } else {
                return "GPT-4 API returned error code: {$response_code}";
            }
            
        } catch (Exception $e) {
            return 'Test failed: ' . $e->getMessage();
        }
    }

    /**
     * Extract search volume from keyword API response
     */
    public function extract_search_volume($api_response, $specific_keyword = null) {
        if (!isset($api_response['keywords']) || !is_array($api_response['keywords'])) {
            return 0;
        }
        
        // If looking for a specific keyword
        if ($specific_keyword) {
            $specific_keyword = strtolower(trim($specific_keyword));
            foreach ($api_response['keywords'] as $keyword => $data) {
                if (strtolower(trim($keyword)) === $specific_keyword) {
                    return intval($data['search volume'] ?? 0);
                }
            }
            return 0;
        }
        
        // Return the highest search volume from the response
        $max_volume = 0;
        foreach ($api_response['keywords'] as $keyword => $data) {
            $volume = intval($data['search volume'] ?? 0);
            if ($volume > $max_volume) {
                $max_volume = $volume;
            }
        }
        
        return $max_volume;
    }

    /**
     * Get related keywords from API response
     */
    public function get_related_keywords($api_response, $min_volume = 100) {
        $related = array();
        
        if (!isset($api_response['keywords']) || !is_array($api_response['keywords'])) {
            return $related;
        }
        
        foreach ($api_response['keywords'] as $keyword => $data) {
            $volume = intval($data['search volume'] ?? 0);
            if ($volume >= $min_volume) {
                $related[] = array(
                    'keyword' => $keyword,
                    'volume' => $volume,
                    'cpc' => floatval($data['cpc'] ?? 0),
                    'competition' => floatval($data['paid competition'] ?? 0)
                );
            }
        }
        
        // Sort by search volume descending
        usort($related, function($a, $b) {
            return $b['volume'] - $a['volume'];
        });
        
        return $related;
    }

    /**
     * Validate keyword API response structure
     */
    public function validate_keyword_response($response) {
        return isset($response['keywords']) 
               && is_array($response['keywords']) 
               && isset($response['result_code']) 
               && $response['result_code'] === '200';
    }

    /**
     * Validate GPT-4 content response
     */
    public function validate_content_response($response) {
        if (is_array($response)) {
            return isset($response['content']) && !empty($response['content']);
        }
        
        return false;
    }

    /**
     * Extract content from API response
     */
    public function extract_content($api_response) {
        if (is_array($api_response) && isset($api_response['content'])) {
            return trim($api_response['content']);
        }
        
        return '';
    }

    /**
     * Handle API rate limiting with exponential backoff
     */
    public function handle_rate_limit($api_type, $retry_count = 0, $max_retries = 3) {
        if ($retry_count >= $max_retries) {
            return false;
        }
        
        // Exponential backoff: 1s, 2s, 4s, 8s
        $delay = pow(2, $retry_count);
        sleep($delay);
        
        return true;
    }
}