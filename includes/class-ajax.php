/**
 * Register AJAX actions
 */
public function register_actions() {
    add_action('wp_ajax_sac_test_extraction', array($this, 'test_extraction'));
    add_action('wp_ajax_sac_export_logs', array($this, 'export_logs'));
    add_action('wp_ajax_sac_clear_cache', array($this, 'clear_cache'));
    add_action('wp_ajax_sac_reset_crons', array($this, 'reset_crons'));
    add_action('wp_ajax_sac_test_apis', array($this, 'test_apis'));
}

/**
 * Test keyword extraction
 */
public function test_extraction() {
    check_ajax_referer('sac_ajax_nonce', 'nonce');
    
    $title = sanitize_text_field($_POST['title']);
    if (empty($title)) {
        wp_send_json_error('No title provided');
    }
    
    $extractor = new SAC_Keyword_Extractor();
    $cleaned_title = $extractor->clean_product_title($title);
    $keyword = $extractor->extract_main_keyword($cleaned_title);
    
    wp_send_json_success(array(
        'original_title' => $title,
        'cleaned_title' => $cleaned_title,
        'extracted_keyword' => $keyword
    ));
}

/**
 * Export debug logs
 */
public function export_logs() {
    check_ajax_referer('sac_ajax_nonce', 'nonce');
    
    $logs = array();
    
    // Get API logs
    global $wpdb;
    $api_logs = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}sac_api_logs 
         ORDER BY created_at DESC 
         LIMIT 1000"
    );
    
    if ($api_logs) {
        $logs['api_logs'] = $api_logs;
    }
    
    // Get WordPress debug log if exists
    $wp_debug_log = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($wp_debug_log)) {
        $logs['wp_debug'] = file_get_contents($wp_debug_log);
    }
    
    // Get plugin specific logs
    $plugin_log = WP_CONTENT_DIR . '/sac-debug.log';
    if (file_exists($plugin_log)) {
        $logs['plugin_log'] = file_get_contents($plugin_log);
    }
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename=sac-debug-logs.json');
    echo json_encode($logs, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Clear plugin cache
 */
public function clear_cache() {
    check_ajax_referer('sac_ajax_nonce', 'nonce');
    
    // Clear transients
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_sac_%' 
         OR option_name LIKE '_transient_timeout_sac_%'"
    );
    
    // Clear cached stats
    delete_option('sac_daily_monitoring_stats');
    delete_option('sac_content_generation_stats');
    
    wp_send_json_success('Cache cleared successfully');
}

/**
 * Reset cron jobs
 */
public function reset_crons() {
    check_ajax_referer('sac_ajax_nonce', 'nonce');
    
    // Clear all plugin cron jobs
    $crons = _get_cron_array();
    $plugin_crons = array();
    
    foreach ($crons as $timestamp => $cron) {
        foreach ($cron as $hook => $events) {
            if (strpos($hook, 'sac_') === 0) {
                $plugin_crons[] = $hook;
                wp_unschedule_hook($hook);
            }
        }
    }
    
    // Reschedule cron jobs
    SAC()->cron_manager->schedule_tasks();
    
    wp_send_json_success('Reset cron jobs: ' . implode(', ', $plugin_crons));
}

/**
 * Test API connections
 */
public function test_apis() {
    check_ajax_referer('sac_ajax_nonce', 'nonce');
    
    $results = array(
        'keywords' => array(
            'success' => false,
            'error' => null
        ),
        'content' => array(
            'success' => false,
            'error' => null
        )
    );
    
    // Test RapidAPI connection
    try {
        $api_key = SAC()->get_option('sac_api_key');
        if (empty($api_key)) {
            throw new Exception('RapidAPI key not configured');
        }
        
        $response = wp_remote_get('https://keywords-explorer.p.rapidapi.com/api/test', array(
            'headers' => array(
                'X-RapidAPI-Key' => $api_key,
                'X-RapidAPI-Host' => 'keywords-explorer.p.rapidapi.com'
            )
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $results['keywords'] = array(
            'success' => true,
            'error' => null
        );
    } catch (Exception $e) {
        $results['keywords'] = array(
            'success' => false,
            'error' => $e->getMessage()
        );
    }
    
    // Test OpenAI GPT-4 connection
    try {
        $api_key = SAC()->get_option('sac_openai_api_key');
        if (empty($api_key)) {
            throw new Exception('OpenAI API key not configured');
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4',
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Test connection'
                    )
                ),
                'max_tokens' => 10
            ))
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $results['content'] = array(
            'success' => true,
            'error' => null
        );
    } catch (Exception $e) {
        $results['content'] = array(
            'success' => false,
            'error' => $e->getMessage()
        );
    }
    
    wp_send_json_success($results);
} 