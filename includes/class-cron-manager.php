<?php
/**
 * Cron Manager Class
 * Handles all scheduled tasks for keyword monitoring and content generation
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Cron_Manager {
    
    /**
     * Cron hooks
     */
    private $hooks = array(
        'daily_monitoring' => 'sac_daily_keyword_monitoring',
        'content_generation' => 'sac_content_generation',
        'priority_update' => 'sac_update_priority_scores',
        'cleanup' => 'sac_cleanup_old_data',
        'duplicate_detection' => 'sac_detect_duplicates'
    );
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Surge detector instance
     */
    private $surge_detector;
    
    /**
     * Content generator instance
     */
    private $content_generator;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->surge_detector = new SAC_Surge_Detector();
        $this->content_generator = new SAC_Content_Generator();
        
        $this->init_hooks();
    }

    /**
     * Initialize cron hooks
     */
    private function init_hooks() {
        // Hook cron functions
        add_action($this->hooks['daily_monitoring'], array($this, 'run_daily_monitoring'));
        add_action($this->hooks['content_generation'], array($this, 'run_content_generation'));
        add_action($this->hooks['priority_update'], array($this, 'run_priority_update'));
        add_action($this->hooks['cleanup'], array($this, 'run_cleanup'));
        add_action($this->hooks['duplicate_detection'], array($this, 'run_duplicate_detection'));
    }

    /**
     * Schedule all cron jobs
     */
    public function schedule_jobs() {
        $this->clear_jobs(); // Clear existing jobs first
        
        // Daily keyword monitoring - staggered throughout the day
        if (!wp_next_scheduled($this->hooks['daily_monitoring'])) {
            wp_schedule_event(
                strtotime('06:00:00'), // 6 AM
                'daily',
                $this->hooks['daily_monitoring']
            );
        }
        
        // Content generation - 5 times per day (every 3 hours starting at 9 AM)
        $content_times = array('09:00:00', '12:00:00', '15:00:00', '18:00:00', '21:00:00');
        
        foreach ($content_times as $index => $time) {
            $hook_name = $this->hooks['content_generation'] . '_' . $index;
            
            if (!wp_next_scheduled($hook_name)) {
                wp_schedule_event(
                    strtotime($time),
                    'daily',
                    $hook_name
                );
                
                // Add hook for each time slot
                add_action($hook_name, array($this, 'run_single_content_generation'));
            }
        }
        
        // Priority score updates - daily at 2 AM
        if (!wp_next_scheduled($this->hooks['priority_update'])) {
            wp_schedule_event(
                strtotime('02:00:00'),
                'daily',
                $this->hooks['priority_update']
            );
        }
        
        // Weekly cleanup - Sundays at 3 AM
        if (!wp_next_scheduled($this->hooks['cleanup'])) {
            wp_schedule_event(
                strtotime('next sunday 03:00:00'),
                'weekly',
                $this->hooks['cleanup']
            );
        }
        
        // Duplicate detection - daily at 4 AM
        if (!wp_next_scheduled($this->hooks['duplicate_detection'])) {
            wp_schedule_event(
                strtotime('04:00:00'),
                'daily',
                $this->hooks['duplicate_detection']
            );
        }
        
        SAC()->log('All cron jobs scheduled successfully');
    }

    /**
     * Clear all scheduled jobs
     */
    public function clear_jobs() {
        // Clear main hooks
        foreach ($this->hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
        
        // Clear content generation time slots
        for ($i = 0; $i < 5; $i++) {
            $hook_name = $this->hooks['content_generation'] . '_' . $i;
            $timestamp = wp_next_scheduled($hook_name);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook_name);
            }
        }
        
        SAC()->log('All cron jobs cleared');
    }

    /**
     * Run daily keyword monitoring
     */
    public function run_daily_monitoring() {
        SAC()->log('Starting daily keyword monitoring cron job');
        
        try {
            $start_time = microtime(true);
            
            // Monitor keywords in batches (86 keywords per day = 600/7)
            $results = $this->surge_detector->monitor_keywords_daily(86);
            
            $execution_time = microtime(true) - $start_time;
            
            // Log results
            SAC()->log("Daily monitoring completed in {$execution_time}s: {$results['monitored']} monitored, {$results['surges_detected']} surges detected");
            
            // Update plugin options with results
            $daily_stats = SAC()->get_option('daily_monitoring_stats', array());
            $daily_stats[current_time('Y-m-d')] = array(
                'monitored' => $results['monitored'],
                'surges' => $results['surges_detected'],
                'execution_time' => round($execution_time, 2),
                'api_calls' => $results['api_calls_used']
            );
            
            // Keep only last 30 days of stats
            $daily_stats = array_slice($daily_stats, -30, 30, true);
            SAC()->update_option('daily_monitoring_stats', $daily_stats);
            
            // Send notification if many surges detected
            if ($results['surges_detected'] >= 5) {
                $this->send_surge_notification($results['surge_keywords']);
            }
            
        } catch (Exception $e) {
            SAC()->log('Error in daily monitoring cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Run content generation (called multiple times per day)
     */
    public function run_content_generation() {
        SAC()->log('Starting content generation cron job');
        
        try {
            $start_time = microtime(true);
            
            // Generate content (limit to 1 per cron run to spread throughout day)
            $results = $this->content_generator->generate_daily_content();
            
            $execution_time = microtime(true) - $start_time;
            
            SAC()->log("Content generation completed in {$execution_time}s: {$results['generated']} generated, {$results['failed']} failed");
            
            // Update stats
            $generation_stats = SAC()->get_option('content_generation_stats', array());
            $today = current_time('Y-m-d');
            
            if (!isset($generation_stats[$today])) {
                $generation_stats[$today] = array(
                    'generated' => 0,
                    'failed' => 0,
                    'runs' => 0
                );
            }
            
            $generation_stats[$today]['generated'] += $results['generated'];
            $generation_stats[$today]['failed'] += $results['failed'];
            $generation_stats[$today]['runs']++;
            
            // Keep only last 30 days
            $generation_stats = array_slice($generation_stats, -30, 30, true);
            SAC()->update_option('content_generation_stats', $generation_stats);
            
        } catch (Exception $e) {
            SAC()->log('Error in content generation cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Run single content generation (for staggered execution)
     */
    public function run_single_content_generation() {
        // Check if we've already hit the daily limit
        $remaining_slots = $this->content_generator->get_remaining_daily_slots();
        
        if ($remaining_slots <= 0) {
            SAC()->log('Daily content limit reached, skipping content generation');
            return;
        }
        
        // Generate only 1 piece of content per time slot
        $this->run_content_generation();
    }

    /**
     * Run priority score updates
     */
    public function run_priority_update() {
        SAC()->log('Starting priority score update cron job');
        
        try {
            $start_time = microtime(true);
            
            $updated_count = $this->surge_detector->update_all_priority_scores();
            
            $execution_time = microtime(true) - $start_time;
            
            SAC()->log("Priority scores updated in {$execution_time}s: {$updated_count} keywords updated");
            
        } catch (Exception $e) {
            SAC()->log('Error in priority update cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Run cleanup tasks
     */
    public function run_cleanup() {
        SAC()->log('Starting cleanup cron job');
        
        try {
            $start_time = microtime(true);
            
            // Clean up old API logs (keep 30 days)
            $deleted_logs = $this->database->cleanup_old_logs();
            
            // Clean up old generated content (keep 90 days)
            $deleted_content = $this->content_generator->cleanup_old_content(90);
            
            $execution_time = microtime(true) - $start_time;
            
            SAC()->log("Cleanup completed in {$execution_time}s: {$deleted_logs} logs deleted, {$deleted_content} old content removed");
            
        } catch (Exception $e) {
            SAC()->log('Error in cleanup cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Run duplicate detection
     */
    public function run_duplicate_detection() {
        SAC()->log('Starting duplicate detection cron job');
        
        try {
            $start_time = microtime(true);
            
            $duplicates_found = $this->database->detect_duplicates();
            
            $execution_time = microtime(true) - $start_time;
            
            SAC()->log("Duplicate detection completed in {$execution_time}s: {$duplicates_found} duplicate groups found");
            
            // Send notification if duplicates found
            if ($duplicates_found > 0) {
                $this->send_duplicate_notification($duplicates_found);
            }
            
        } catch (Exception $e) {
            SAC()->log('Error in duplicate detection cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Send surge notification to admin
     */
    private function send_surge_notification($surge_keywords) {
        $admin_email = get_option('admin_email');
        $subject = 'SEO Auto Content: Multiple Keyword Surges Detected';
        
        $message = "Multiple keyword surges have been detected today:\n\n";
        
        foreach ($surge_keywords as $surge) {
            $message .= "• {$surge['keyword']}: {$surge['surge_percentage']}% increase (from {$surge['previous_volume']} to {$surge['current_volume']})\n";
        }
        
        $message .= "\nContent generation has been automatically triggered for these keywords.";
        $message .= "\nView details in your WordPress admin: " . admin_url('admin.php?page=sac-dashboard');
        
        wp_mail($admin_email, $subject, $message);
        
        SAC()->log('Surge notification sent to admin');
    }

    /**
     * Send duplicate content notification
     */
    private function send_duplicate_notification($duplicate_count) {
        $admin_email = get_option('admin_email');
        $subject = 'SEO Auto Content: Duplicate Keywords Detected';
        
        $message = "Duplicate keywords have been detected in your product catalog.\n\n";
        $message .= "Total duplicate groups found: {$duplicate_count}\n\n";
        $message .= "Please review these duplicates in your admin panel to avoid SEO issues.\n";
        $message .= "View duplicates: " . admin_url('admin.php?page=sac-duplicates');
        
        wp_mail($admin_email, $subject, $message);
        
        SAC()->log('Duplicate notification sent to admin');
    }

    /**
     * Get cron status
     */
    public function get_cron_status() {
        $status = array();
        
        // Check each cron job
        foreach ($this->hooks as $name => $hook) {
            $next_run = wp_next_scheduled($hook);
            $status[$name] = array(
                'hook' => $hook,
                'scheduled' => (bool) $next_run,
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
                'next_run_human' => $next_run ? human_time_diff($next_run) : null
            );
        }
        
        // Check content generation slots
        $status['content_generation_slots'] = array();
        for ($i = 0; $i < 5; $i++) {
            $hook_name = $this->hooks['content_generation'] . '_' . $i;
            $next_run = wp_next_scheduled($hook_name);
            
            $status['content_generation_slots'][$i] = array(
                'hook' => $hook_name,
                'scheduled' => (bool) $next_run,
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null
            );
        }
        
        return $status;
    }

    /**
     * Test cron functionality
     */
    public function test_cron() {
        $results = array(
            'wp_cron_enabled' => defined('DISABLE_WP_CRON') ? !DISABLE_WP_CRON : true,
            'cron_jobs_scheduled' => 0,
            'last_cron_run' => get_option('_transient_doing_cron'),
            'test_results' => array()
        );
        
        // Count scheduled jobs
        foreach ($this->hooks as $hook) {
            if (wp_next_scheduled($hook)) {
                $results['cron_jobs_scheduled']++;
            }
        }
        
        // Test basic cron functionality
        $test_hook = 'sac_cron_test';
        $test_time = time() + 60; // 1 minute from now
        
        wp_schedule_single_event($test_time, $test_hook);
        
        if (wp_next_scheduled($test_hook)) {
            $results['test_results']['schedule'] = 'PASS';
            wp_unschedule_event($test_time, $test_hook);
        } else {
            $results['test_results']['schedule'] = 'FAIL';
        }
        
        return $results;
    }

    /**
     * Manual trigger for cron jobs (for testing)
     */
    public function trigger_manual_run($job_type) {
        switch ($job_type) {
            case 'monitoring':
                return $this->run_daily_monitoring();
                
            case 'content':
                return $this->run_content_generation();
                
            case 'priority':
                return $this->run_priority_update();
                
            case 'cleanup':
                return $this->run_cleanup();
                
            case 'duplicates':
                return $this->run_duplicate_detection();
                
            default:
                return new WP_Error('invalid_job', 'Invalid job type specified');
        }
    }

    /**
     * Get cron execution history
     */
    public function get_execution_history($days = 7) {
        $history = array();
        
        // Get monitoring stats
        $monitoring_stats = SAC()->get_option('daily_monitoring_stats', array());
        $content_stats = SAC()->get_option('content_generation_stats', array());
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        $current_date = $start_date;
        while ($current_date <= $end_date) {
            $history[$current_date] = array(
                'date' => $current_date,
                'monitoring' => isset($monitoring_stats[$current_date]) ? $monitoring_stats[$current_date] : null,
                'content' => isset($content_stats[$current_date]) ? $content_stats[$current_date] : null
            );
            
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
        
        return $history;
    }

    /**
     * Reschedule all jobs (useful for timezone changes)
     */
    public function reschedule_all_jobs() {
        $this->clear_jobs();
        $this->schedule_jobs();
        
        SAC()->log('All cron jobs rescheduled');
        return true;
    }

    /**
     * Check if cron jobs are running properly
     */
public function health_check() {
    $health = array(
        'status' => 'healthy',
        'issues' => array(),
        'last_monitoring' => null,
        'last_content_generation' => null,
        'recommendations' => array()
    );
    
    // Check if WP Cron is disabled
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        $health['issues'][] = 'WP Cron is disabled. You need to set up a system cron job.';
        $health['status'] = 'warning';
    }
    
    // Check last monitoring run
    $monitoring_stats = SAC()->get_option('daily_monitoring_stats', array());
    if (!empty($monitoring_stats)) {
        $last_monitoring_date = max(array_keys($monitoring_stats));
        $health['last_monitoring'] = $last_monitoring_date;
        
        $days_since_monitoring = (strtotime('today') - strtotime($last_monitoring_date)) / DAY_IN_SECONDS;
        
        if ($days_since_monitoring > 2) {
            $health['issues'][] = "Keyword monitoring hasn't run for {$days_since_monitoring} days";
            $health['status'] = 'unhealthy';
        }
    } else {
        $health['issues'][] = 'No monitoring data found - cron jobs may not be running';
        $health['status'] = 'unhealthy';
    }
    
    // Check last content generation
    $content_stats = SAC()->get_option('content_generation_stats', array());
    if (!empty($content_stats)) {
        $last_content_date = max(array_keys($content_stats));
        $health['last_content_generation'] = $last_content_date;
        
        $days_since_content = (strtotime('today') - strtotime($last_content_date)) / DAY_IN_SECONDS;
        
        if ($days_since_content > 1) {
            $health['issues'][] = "Content generation hasn't run for {$days_since_content} days";
            $health['status'] = 'warning';
        }
    }
    
    // Check if jobs are scheduled
    $scheduled_count = 0;
    foreach ($this->hooks as $hook) {
        if (wp_next_scheduled($hook)) {
            $scheduled_count++;
        }
    }
    
    if ($scheduled_count === 0) {
        $health['issues'][] = 'No cron jobs are scheduled';
        $health['status'] = 'unhealthy';
        $health['recommendations'][] = 'Try deactivating and reactivating the plugin to reschedule cron jobs';
    }
    
    // Check API limits - FIXED VERSION (avoid property access error)
    try {
        // Get API handler safely through the main plugin instance
        $api_handler = SAC()->api_handler;
        if ($api_handler && method_exists($api_handler, 'get_usage_statistics')) {
            $api_stats = $api_handler->get_usage_statistics();
            
            if (isset($api_stats['keyword']['remaining_today']) && $api_stats['keyword']['remaining_today'] <= 10) {
                $health['issues'][] = 'Keyword API limit nearly reached today';
                $health['recommendations'][] = 'Consider upgrading your API plan or reducing monitoring frequency';
            }
            
            if (isset($api_stats['content']['remaining_today']) && $api_stats['content']['remaining_today'] <= 5) {
                $health['issues'][] = 'Content API limit nearly reached today';
                $health['recommendations'][] = 'Consider reducing daily content generation limit';
            }
        }
    } catch (Exception $e) {
        // If API check fails, just skip it - don't crash the health check
        $health['recommendations'][] = 'API status check temporarily unavailable';
    }
    
    return $health;
}
    /**
     * Get performance metrics
     */
    public function get_performance_metrics($days = 30) {
        $metrics = array(
            'monitoring_performance' => array(),
            'content_performance' => array(),
            'api_efficiency' => array(),
            'success_rates' => array()
        );
        
        // Get monitoring stats
        $monitoring_stats = SAC()->get_option('daily_monitoring_stats', array());
        $content_stats = SAC()->get_option('content_generation_stats', array());
        
        // Calculate monitoring performance
        $total_monitored = 0;
        $total_surges = 0;
        $total_execution_time = 0;
        $monitoring_days = 0;
        
        foreach ($monitoring_stats as $date => $stats) {
            if (strtotime($date) >= strtotime("-{$days} days")) {
                $total_monitored += $stats['monitored'];
                $total_surges += $stats['surges'];
                $total_execution_time += $stats['execution_time'];
                $monitoring_days++;
            }
        }
        
        if ($monitoring_days > 0) {
            $metrics['monitoring_performance'] = array(
                'avg_keywords_per_day' => round($total_monitored / $monitoring_days, 2),
                'avg_surges_per_day' => round($total_surges / $monitoring_days, 2),
                'avg_execution_time' => round($total_execution_time / $monitoring_days, 2),
                'surge_detection_rate' => $total_monitored > 0 ? round(($total_surges / $total_monitored) * 100, 2) : 0
            );
        }
        
        // Calculate content performance
        $total_generated = 0;
        $total_failed = 0;
        $content_days = 0;
        
        foreach ($content_stats as $date => $stats) {
            if (strtotime($date) >= strtotime("-{$days} days")) {
                $total_generated += $stats['generated'];
                $total_failed += $stats['failed'];
                $content_days++;
            }
        }
        
        if ($content_days > 0) {
            $metrics['content_performance'] = array(
                'avg_content_per_day' => round($total_generated / $content_days, 2),
                'success_rate' => ($total_generated + $total_failed) > 0 ? round(($total_generated / ($total_generated + $total_failed)) * 100, 2) : 0,
                'total_generated' => $total_generated,
                'total_failed' => $total_failed
            );
        }
        
        // API efficiency metrics
        $today_api_usage = $this->surge_detector->get_api_handler()->get_usage_statistics();
        
        $metrics['api_efficiency'] = array(
            'keyword_api_usage_today' => $today_api_usage['keyword']['used_today'],
            'content_api_usage_today' => $today_api_usage['content']['used_today'],
            'keyword_api_efficiency' => $today_api_usage['keyword']['used_today'] > 0 ? round($total_monitored / $today_api_usage['keyword']['used_today'], 2) : 0,
            'content_api_efficiency' => $today_api_usage['content']['used_today'] > 0 ? round($total_generated / $today_api_usage['content']['used_today'], 2) : 0
        );
        
        return $metrics;
    }

    /**
     * Emergency stop all cron jobs
     */
    public function emergency_stop() {
        $this->clear_jobs();
        
        // Set emergency flag
        SAC()->update_option('emergency_stop_active', true);
        SAC()->update_option('emergency_stop_time', current_time('mysql'));
        
        SAC()->log('EMERGENCY STOP: All cron jobs stopped', 'warning');
        
        return true;
    }

    /**
     * Resume after emergency stop
     */
    public function resume_after_emergency() {
        // Remove emergency flag
        SAC()->update_option('emergency_stop_active', false);
        
        // Reschedule all jobs
        $this->schedule_jobs();
        
        SAC()->log('Cron jobs resumed after emergency stop');
        
        return true;
    }

    /**
     * Check if emergency stop is active
     */
    public function is_emergency_stopped() {
        return SAC()->get_option('emergency_stop_active', false);
    }

    /**
     * Get next content generation time
     */
    public function get_next_content_generation_time() {
        $next_times = array();
        
        for ($i = 0; $i < 5; $i++) {
            $hook_name = $this->hooks['content_generation'] . '_' . $i;
            $next_run = wp_next_scheduled($hook_name);
            
            if ($next_run) {
                $next_times[] = $next_run;
            }
        }
        
        return !empty($next_times) ? min($next_times) : null;
    }

    /**
     * Adjust cron frequency based on API usage
     */
    public function auto_adjust_frequency() {
        $api_stats = $this->surge_detector->api_handler->get_usage_statistics();
        $monitoring_stats = SAC()->get_option('daily_monitoring_stats', array());
        
        // Get recent monitoring data
        $recent_days = array_slice($monitoring_stats, -7, 7, true);
        $avg_daily_usage = 0;
        
        if (!empty($recent_days)) {
            $total_usage = array_sum(array_column($recent_days, 'api_calls'));
            $avg_daily_usage = $total_usage / count($recent_days);
        }
        
        $recommendations = array();
        
        // If we're consistently hitting API limits
        if ($avg_daily_usage > ($api_stats['keyword']['daily_limit'] * 0.9)) {
            $recommendations[] = 'Consider reducing monitoring frequency or upgrading API plan';
        }
        
        // If we're using very little of our API quota
        if ($avg_daily_usage < ($api_stats['keyword']['daily_limit'] * 0.5)) {
            $recommendations[] = 'You could increase monitoring frequency or add more products';
        }
        
        return array(
            'current_avg_usage' => round($avg_daily_usage, 2),
            'daily_limit' => $api_stats['keyword']['daily_limit'],
            'usage_percentage' => round(($avg_daily_usage / $api_stats['keyword']['daily_limit']) * 100, 2),
            'recommendations' => $recommendations
        );
    }

    /**
     * Export cron logs for debugging
     */
    public function export_cron_logs($days = 7) {
        $logs = array(
            'export_date' => current_time('mysql'),
            'period_days' => $days,
            'monitoring_stats' => SAC()->get_option('daily_monitoring_stats', array()),
            'content_stats' => SAC()->get_option('content_generation_stats', array()),
            'cron_status' => $this->get_cron_status(),
            'health_check' => $this->health_check(),
            'performance_metrics' => $this->get_performance_metrics($days)
        );
        
        // Filter to requested period
        $cutoff_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $logs['monitoring_stats'] = array_filter($logs['monitoring_stats'], function($key) use ($cutoff_date) {
            return $key >= $cutoff_date;
        }, ARRAY_FILTER_USE_KEY);
        
        $logs['content_stats'] = array_filter($logs['content_stats'], function($key) use ($cutoff_date) {
            return $key >= $cutoff_date;
        }, ARRAY_FILTER_USE_KEY);
        
        return $logs;
    }
}