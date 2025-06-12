<?php
/**
 * Admin Dashboard Class
 * Handles all admin interface functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Admin {
    
    /**
     * Plugin components
     */
    private $database;
    private $api_handler;
    private $keyword_extractor;
    private $surge_detector;
    private $content_generator;
    private $cron_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->api_handler = new SAC_API_Handler();
        $this->keyword_extractor = new SAC_Keyword_Extractor();
        $this->surge_detector = new SAC_Surge_Detector();
        $this->content_generator = new SAC_Content_Generator();
        $this->cron_manager = new SAC_Cron_Manager();
        
        $this->init();
    }

    /**
     * Initialize admin
     */
    private function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('wp_ajax_sac_test_extraction', array($this, 'ajax_test_extraction'));
        add_action('wp_ajax_sac_manual_content_generation', array($this, 'ajax_manual_content_generation'));
        add_action('wp_ajax_sac_run_cron_manually', array($this, 'ajax_run_cron_manually'));
        add_action('wp_ajax_sac_reset_crons', array($this, 'ajax_reset_crons'));
        add_action('wp_ajax_sac_test_apis', array($this, 'ajax_test_apis'));
        add_action('wp_ajax_sac_process_products', array($this, 'ajax_process_products'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            'SEO Auto Content',
            'SEO Auto Content',
            'manage_options',
            'sac-dashboard',
            array($this, 'dashboard_page'),
            'dashicons-chart-line',
            30
        );
        
        // Dashboard (same as main)
        add_submenu_page(
            'sac-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'sac-dashboard',
            array($this, 'dashboard_page')
        );
        
        // Keywords Management
        add_submenu_page(
            'sac-dashboard',
            'Keywords',
            'Keywords',
            'manage_options',
            'sac-keywords',
            array($this, 'keywords_page')
        );
        
        // Content Reports
        add_submenu_page(
            'sac-dashboard',
            'Content Reports',
            'Content Reports',
            'manage_options',
            'sac-reports',
            array($this, 'reports_page')
        );
        
        // Duplicate Detection
        add_submenu_page(
            'sac-dashboard',
            'Duplicates',
            'Duplicates',
            'manage_options',
            'sac-duplicates',
            array($this, 'duplicates_page')
        );
        
        // Settings
        add_submenu_page(
            'sac-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'sac-settings',
            array($this, 'settings_page')
        );
        
        // Tools & Testing
        add_submenu_page(
            'sac-dashboard',
            'Tools',
            'Tools',
            'manage_options',
            'sac-tools',
            array($this, 'tools_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'sac-') === false) {
            return;
        }
        
        wp_enqueue_style(
            'sac-admin-style',
            SAC_PLUGIN_URL . 'admin/assets/admin.css',
            array(),
            SAC_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'sac-admin-script',
            SAC_PLUGIN_URL . 'admin/assets/admin.js',
            array('jquery'),
            SAC_PLUGIN_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('sac-admin-script', 'sacAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sac_ajax_nonce')
        ));
    }

    /**
     * Handle admin form submissions
     */
    public function handle_admin_actions() {
        if (!isset($_POST['sac_action']) || !wp_verify_nonce($_POST['sac_nonce'], 'sac_ajax_nonce')) {
            return;
        }
        
        $action = sanitize_text_field($_POST['sac_action']);
        
        switch ($action) {
            case 'save_settings':
                $this->save_settings();
                break;
                
            case 'run_initial_scan':
                $this->run_initial_scan();
                break;
                
            case 'emergency_stop':
                $this->cron_manager->emergency_stop();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning"><p>Emergency stop activated. All cron jobs stopped.</p></div>';
                });
                break;
                
            case 'resume_crons':
                $this->cron_manager->resume_after_emergency();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Cron jobs resumed successfully.</p></div>';
                });
                break;
        }
    }

    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $stats = $this->database->get_statistics();
        $cron_status = $this->cron_manager->get_cron_status();
        $api_stats = $this->api_handler->get_usage_statistics();
        $health = $this->cron_manager->health_check();
        $recent_content = $this->content_generator->get_generation_statistics(7);
        $surge_stats = $this->surge_detector->get_surge_statistics(7);
        
        ?>
        <div class="wrap">
            <h1>SEO Auto Content Dashboard</h1>
            
            <!-- Health Status -->
            <div class="sac-health-status <?php echo $health['status']; ?>">
                <h2>System Health: <?php echo ucfirst($health['status']); ?></h2>
                <?php if (!empty($health['issues'])): ?>
                    <div class="health-issues">
                        <h4>Issues:</h4>
                        <ul>
                            <?php foreach ($health['issues'] as $issue): ?>
                                <li><?php echo esc_html($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($health['recommendations'])): ?>
                    <div class="health-recommendations">
                        <h4>Recommendations:</h4>
                        <ul>
                            <?php foreach ($health['recommendations'] as $rec): ?>
                                <li><?php echo esc_html($rec); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Stats Cards -->
            <div class="sac-stats-grid">
                <div class="stat-card">
                    <h3>Products Monitored</h3>
                    <div class="stat-number"><?php echo number_format($stats['total_products_monitored']); ?></div>
                    <div class="stat-label">out of <?php echo number_format($stats['total_keywords']); ?> keywords</div>
                </div>
                
                <div class="stat-card">
                    <h3>Articles Today</h3>
                    <div class="stat-number"><?php echo $stats['articles_generated_today']; ?></div>
                    <div class="stat-label">of 5 daily limit</div>
                </div>
                
                <div class="stat-card">
                    <h3>Surges Detected</h3>
                    <div class="stat-number"><?php echo $surge_stats['total_surges']; ?></div>
                    <div class="stat-label">in last 7 days</div>
                </div>
                
                <div class="stat-card">
                    <h3>API Usage Today</h3>
                    <div class="stat-number"><?php echo $api_stats['keyword']['used_today']; ?> / <?php echo $api_stats['content']['used_today']; ?></div>
                    <div class="stat-label">Keywords / Content calls</div>
                </div>
            </div>
            
            <!-- API Usage Section -->
            <div class="usage-section">
                <h2>Today's API Usage</h2>
                <div class="api-usage-grid">
                    <div class="usage-card">
                        <h4>Keyword API (RapidAPI)</h4>
                        <div class="usage-meter">
                            <div class="usage-bar" style="width: <?php echo ($api_stats['keyword']['used_today'] / $api_stats['keyword']['daily_limit']) * 100; ?>%"></div>
                        </div>
                        <p><?php echo $api_stats['keyword']['used_today']; ?> / <?php echo $api_stats['keyword']['daily_limit']; ?> calls</p>
                    </div>
                    
                    <div class="usage-card">
                        <h4>Content API (OpenAI GPT-4)</h4>
                        <div class="usage-meter">
                            <div class="usage-bar" style="width: <?php echo ($api_stats['content']['used_today'] / $api_stats['content']['daily_limit']) * 100; ?>%"></div>
                        </div>
                        <p><?php echo $api_stats['content']['used_today']; ?> / <?php echo $api_stats['content']['daily_limit']; ?> calls</p>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="sac-activity-section">
                <h2>Recent Activity</h2>
                
                <div class="activity-tabs">
                    <button class="tab-button active" data-tab="content">Recent Content</button>
                    <button class="tab-button" data-tab="surges">Recent Surges</button>
                    <button class="tab-button" data-tab="cron">Cron Status</button>
                </div>
                
                <!-- Recent Content Tab -->
                <div class="tab-content active" id="content-tab">
                    <?php if (!empty($recent_content['recent_generations'])): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Product</th>
                                    <th>Reason</th>
                                    <th>Generated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_content['recent_generations'] as $item): ?>
                                    <tr>
                                        <td><?php echo esc_html($item->keyword_phrase); ?></td>
                                        <td>
                                            <a href="<?php echo get_edit_post_link($item->product_id); ?>">
                                                <?php echo esc_html($item->product_name); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="reason-badge <?php echo $item->generation_reason; ?>">
                                                <?php echo ucfirst($item->generation_reason); ?>
                                            </span>
                                        </td>
                                        <td><?php echo human_time_diff(strtotime($item->generation_date)); ?> ago</td>
                                        <td>
                                            <?php if ($item->blog_post_id): ?>
                                                <a href="<?php echo get_permalink($item->blog_post_id); ?>" target="_blank">View Post</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No content generated yet.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Surges Tab -->
                <div class="tab-content" id="surges-tab">
                    <?php 
                    $recent_surges = $this->surge_detector->get_recent_surges(7);
                    if (!empty($recent_surges)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Product</th>
                                    <th>Surge %</th>
                                    <th>Volume Change</th>
                                    <th>Last Checked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_surges as $surge): ?>
                                    <tr>
                                        <td><?php echo esc_html($surge->keyword_phrase); ?></td>
                                        <td><?php echo esc_html($surge->product_name); ?></td>
                                        <td>
                                            <span class="surge-percentage positive">
                                                +<?php echo number_format($surge->surge_percentage, 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo number_format($surge->previous_search_volume); ?> → 
                                            <?php echo number_format($surge->current_search_volume); ?>
                                        </td>
                                        <td><?php echo human_time_diff(strtotime($surge->last_checked)); ?> ago</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No surges detected in the last 7 days.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Cron Status Tab -->
                <div class="tab-content" id="cron-tab">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Status</th>
                                <th>Next Run</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cron_status as $job_name => $job_info): ?>
                                <?php if ($job_name !== 'content_generation_slots'): ?>
                                    <tr>
                                        <td><?php echo ucwords(str_replace('_', ' ', $job_name)); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $job_info['scheduled'] ? 'scheduled' : 'not-scheduled'; ?>">
                                                <?php echo $job_info['scheduled'] ? 'Scheduled' : 'Not Scheduled'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $job_info['next_run_human'] ? 'In ' . $job_info['next_run_human'] : 'Not scheduled'; ?>
                                        </td>
                                        <td>
                                            <button class="button button-small run-cron-manual" 
                                                    data-job="<?php echo esc_attr($job_name); ?>">
                                                Run Now
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Emergency Controls -->
            <div class="sac-emergency-controls">
                <h3>Emergency Controls</h3>
                <form method="post">
                    <?php wp_nonce_field('sac_admin_action', 'sac_nonce'); ?>
                    
                    <?php if ($this->cron_manager->is_emergency_stopped()): ?>
                        <input type="hidden" name="sac_action" value="resume_crons">
                        <button type="submit" class="button button-primary">
                            Resume All Cron Jobs
                        </button>
                        <p class="description">All automated processes are currently stopped.</p>
                    <?php else: ?>
                        <input type="hidden" name="sac_action" value="emergency_stop">
                        <button type="submit" class="button button-secondary" 
                                onclick="return confirm('This will stop all automated processes. Continue?')">
                            Emergency Stop
                        </button>
                        <p class="description">Stop all automated keyword monitoring and content generation.</p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <style>
            .sac-health-status {
                padding: 20px;
                margin: 20px 0;
                border-radius: 5px;
                border-left: 4px solid;
            }
            .sac-health-status.healthy {
                background: #d4edda;
                border-color: #28a745;
                color: #155724;
            }
            .sac-health-status.warning {
                background: #fff3cd;
                border-color: #ffc107;
                color: #856404;
            }
            .sac-health-status.unhealthy {
                background: #f8d7da;
                border-color: #dc3545;
                color: #721c24;
            }
            
            .sac-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                text-align: center;
            }
            
            .stat-number {
                font-size: 2em;
                font-weight: bold;
                color: #0073aa;
            }
            
            .stat-label {
                color: #666;
                font-size: 0.9em;
            }
            
            .api-usage-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            
            .usage-card {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                border: 1px solid #dee2e6;
            }
            
            .usage-card h4 {
                margin: 0 0 10px 0;
                color: #495057;
                font-size: 14px;
            }
            
            .usage-meter {
                width: 100%;
                height: 8px;
                background: #e9ecef;
                border-radius: 4px;
                overflow: hidden;
                margin: 8px 0;
            }
            
            .usage-bar {
                height: 100%;
                background: linear-gradient(90deg, #28a745 0%, #ffc107 70%, #dc3545 90%);
                border-radius: 4px;
                transition: width 0.3s ease;
            }
            
            .usage-card p {
                margin: 5px 0 0 0;
                font-size: 12px;
                color: #6c757d;
            }
            
            .usage-section {
                background: white;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
            
            .activity-tabs {
                margin: 20px 0 0 0;
            }
            
            .tab-button {
                background: #f1f1f1;
                border: 1px solid #ccc;
                padding: 10px 20px;
                cursor: pointer;
                border-bottom: none;
            }
            
            .tab-button.active {
                background: white;
                border-bottom: 1px solid white;
                margin-bottom: -1px;
            }
            
            .tab-content {
                display: none;
                background: white;
                border: 1px solid #ccc;
                padding: 20px;
            }
            
            .tab-content.active {
                display: block;
            }
            
            .reason-badge {
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 0.85em;
                font-weight: bold;
            }
            
            .reason-badge.surge {
                background: #ff6b6b;
                color: white;
            }
            
            .reason-badge.fallback {
                background: #4ecdc4;
                color: white;
            }
            
            .surge-percentage.positive {
                color: #28a745;
                font-weight: bold;
            }
            
            .status-badge {
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 0.85em;
                font-weight: bold;
            }
            
            .status-badge.scheduled {
                background: #28a745;
                color: white;
            }
            
            .status-badge.not-scheduled {
                background: #dc3545;
                color: white;
            }
            
            .sac-emergency-controls {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                padding: 20px;
                margin: 20px 0;
                border-radius: 5px;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // Tab switching
                $('.tab-button').click(function() {
                    var tab = $(this).data('tab');
                    
                    $('.tab-button').removeClass('active');
                    $('.tab-content').removeClass('active');
                    
                    $(this).addClass('active');
                    $('#' + tab + '-tab').addClass('active');
                });
                
                // Manual cron execution
                $('.run-cron-manual').click(function() {
                    var job = $(this).data('job');
                    var button = $(this);
                    
                    button.prop('disabled', true).text('Running...');
                    
                    $.post(sacAjax.ajaxurl, {
                        action: 'sac_run_cron_manually',
                        job_type: job,
                        nonce: sacAjax.nonce
                    }, function(response) {
                        if (response.success) {
                            button.text('Success!');
                            setTimeout(function() {
                                button.prop('disabled', false).text('Run Now');
                            }, 2000);
                        } else {
                            button.text('Failed');
                            setTimeout(function() {
                                button.prop('disabled', false).text('Run Now');
                            }, 2000);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Keywords management page
     */
    public function keywords_page() {
        $extraction_stats = $this->keyword_extractor->get_extraction_statistics();
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get keywords with pagination
        global $wpdb;
        $keywords = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT k.*, p.post_title as product_name 
                 FROM {$this->database->get_table('keywords')} k
                 LEFT JOIN {$wpdb->posts} p ON k.product_id = p.ID
                 ORDER BY k.current_search_volume DESC, k.surge_percentage DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        
        $total_keywords = $wpdb->get_var("SELECT COUNT(*) FROM {$this->database->get_table('keywords')}");
        $total_pages = ceil($total_keywords / $per_page);
        
        ?>
        <div class="wrap">
            <h1>Keywords Management</h1>
            
            <!-- Extraction Overview -->
            <div class="extraction-overview">
                <h2>Product Processing Status</h2>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $extraction_stats['completion_percentage']; ?>%"></div>
                </div>
                <p>
                    <?php echo number_format($extraction_stats['processed_products']); ?> of 
                    <?php echo number_format($extraction_stats['total_products']); ?> products processed 
                    (<?php echo $extraction_stats['completion_percentage']; ?>%)
                </p>
                
                <?php if ($extraction_stats['unprocessed_products'] > 0): ?>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('sac_admin_action', 'sac_nonce'); ?>
                        <input type="hidden" name="sac_action" value="run_initial_scan">
                        <button type="submit" class="button button-primary">
                            Process Remaining <?php echo number_format($extraction_stats['unprocessed_products']); ?> Products
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <!-- Keywords Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Keyword</th>
                        <th>Product</th>
                        <th>Search Volume</th>
                        <th>Surge %</th>
                        <th>Last Checked</th>
                        <th>Articles</th>
                        <th>Priority Score</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keywords as $keyword): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($keyword->keyword_phrase); ?></strong>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($keyword->product_id); ?>">
                                    <?php echo esc_html($keyword->product_name); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo number_format($keyword->current_search_volume); ?>
                                <?php if ($keyword->previous_search_volume > 0): ?>
                                    <span class="volume-change">
                                        (was <?php echo number_format($keyword->previous_search_volume); ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($keyword->surge_percentage >= 25): ?>
                                    <span class="surge-high">+<?php echo number_format($keyword->surge_percentage, 1); ?>%</span>
                                <?php elseif ($keyword->surge_percentage > 0): ?>
                                    <span class="surge-low">+<?php echo number_format($keyword->surge_percentage, 1); ?>%</span>
                                <?php else: ?>
                                    <span class="no-surge"><?php echo number_format($keyword->surge_percentage, 1); ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $keyword->last_checked ? human_time_diff(strtotime($keyword->last_checked)) . ' ago' : 'Never'; ?>
                            </td>
                            <td><?php echo $keyword->total_articles_generated; ?></td>
                            <td><?php echo number_format($keyword->priority_score, 2); ?></td>
                            <td>
                                <button class="button button-small generate-content-manual" 
                                        data-keyword-id="<?php echo $keyword->id; ?>">
                                    Generate Content
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .extraction-overview {
                background: white;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
            
            .progress-bar {
                width: 100%;
                height: 20px;
                background: #f1f1f1;
                border-radius: 10px;
                overflow: hidden;
                margin: 10px 0;
            }
            
            .progress-fill {
                height: 100%;
                background: #0073aa;
                transition: width 0.3s ease;
            }
            
            .surge-high {
                color: #dc3545;
                font-weight: bold;
            }
            
            .surge-low {
                color: #ffc107;
                font-weight: bold;
            }
            
            .no-surge {
                color: #6c757d;
            }
            
            .volume-change {
                font-size: 0.85em;
                color: #666;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                $('.generate-content-manual').click(function() {
                    var keywordId = $(this).data('keyword-id');
                    var button = $(this);
                    
                    button.prop('disabled', true).text('Generating...');
                    
                    $.post(sacAjax.ajaxurl, {
                        action: 'sac_manual_content_generation',
                        keyword_id: keywordId,
                        nonce: sacAjax.nonce
                    }, function(response) {
                        if (response.success) {
                            button.text('Generated!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            button.text('Failed');
                            alert('Error: ' + response.data);
                            setTimeout(function() {
                                button.prop('disabled', false).text('Generate Content');
                            }, 2000);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Reports page
     */
    public function reports_page() {
        $weekly_report = $this->database->get_weekly_report();
        $content_stats = $this->content_generator->get_generation_statistics(30);
        $performance = $this->cron_manager->get_performance_metrics(30);
        
        ?>
        <div class="wrap">
            <h1>Content Reports</h1>
            
            <!-- Weekly Summary -->
            <div class="report-section">
                <h2>Weekly Summary</h2>
                <div class="summary-cards">
                    <div class="summary-card">
                        <h3>Products Updated</h3>
                        <div class="card-number"><?php echo count($weekly_report['products_updated']); ?></div>
                    </div>
                    <div class="summary-card">
                        <h3>Blog Posts Created</h3>
                        <div class="card-number">
                            <?php echo count(array_filter($weekly_report['products_updated'], function($item) {
                                return !empty($item->blog_post_id);
                            })); ?>
                        </div>
                    </div>
                    <div class="summary-card">
                        <h3>Surge-Based Content</h3>
                        <div class="card-number">
                            <?php echo count(array_filter($weekly_report['products_updated'], function($item) {
                                return $item->generation_reason === 'surge';
                            })); ?>
                        </div>
                    </div>
                    <div class="summary-card">
                        <h3>GPT-4 Generated</h3>
                        <div class="card-number">
                            <?php echo count(array_filter($weekly_report['products_updated'], function($item) {
                                return $item->generation_reason === 'fallback';
                            })); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Content Generated -->
            <div class="report-section">
                <h2>Recent Content Generated</h2>
                <?php if (!empty($weekly_report['products_updated'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Keyword</th>
                                <th>Product</th>
                                <th>Reason</th>
                                <th>Search Volume</th>
                                <th>Blog Post</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($weekly_report['products_updated'] as $item): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($item->generation_date)); ?></td>
                                    <td><?php echo esc_html($item->keyword_phrase); ?></td>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($item->product_id); ?>">
                                            <?php echo esc_html($item->product_name); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="reason-badge <?php echo $item->generation_reason; ?>">
                                            <?php echo $item->generation_reason === 'surge' ? 'Surge' : 'GPT-4'; ?>
                                        </span>
                                        <?php if ($item->surge_percentage > 0): ?>
                                            (+<?php echo number_format($item->surge_percentage, 1); ?>%)
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($item->search_volume_at_time); ?></td>
                                    <td>
                                        <?php if ($item->blog_post_id): ?>
                                            <a href="<?php echo get_permalink($item->blog_post_id); ?>" target="_blank">
                                                View Post
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No content generated in the last 7 days.</p>
                <?php endif; ?>
            </div>
            
            <!-- Performance Metrics -->
            <?php if (!empty($performance['content_performance'])): ?>
                <div class="report-section">
                    <h2>Performance Metrics (Last 30 Days)</h2>
                    <div class="metrics-grid">
                        <div class="metric-item">
                            <h4>Average Content per Day</h4>
                            <div class="metric-value"><?php echo $performance['content_performance']['avg_content_per_day']; ?></div>
                        </div>
                        <div class="metric-item">
                            <h4>Success Rate</h4>
                            <div class="metric-value"><?php echo $performance['content_performance']['success_rate']; ?>%</div>
                        </div>
                        <div class="metric-item">
                            <h4>Total Generated</h4>
                            <div class="metric-value"><?php echo $performance['content_performance']['total_generated']; ?></div>
                        </div>
                        <div class="metric-item">
                            <h4>Total Failed</h4>
                            <div class="metric-value"><?php echo $performance['content_performance']['total_failed']; ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- API Usage Report -->
            <div class="report-section">
                <h2>API Usage Report</h2>
                <?php if (!empty($weekly_report['api_usage'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>API Service</th>
                                <th>Total Calls</th>
                                <th>Average Response Time</th>
                                <th>Error Count</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($weekly_report['api_usage'] as $api): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        echo $api->api_type === 'keyword' ? 'Keyword API (RapidAPI)' : 'Content API (OpenAI GPT-4)';
                                        ?>
                                    </td>
                                    <td><?php echo number_format($api->calls); ?></td>
                                    <td><?php echo number_format($api->avg_time, 3); ?>s</td>
                                    <td><?php echo number_format($api->errors); ?></td>
                                    <td>
                                        <?php 
                                        $success_rate = $api->calls > 0 ? (($api->calls - $api->errors) / $api->calls) * 100 : 0;
                                        echo number_format($success_rate, 1); ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No API usage data available for the last 7 days.</p>
                <?php endif; ?>
            </div>
            
            <!-- Export Options -->
            <div class="report-section">
                <h2>Export Reports</h2>
                <p>Download detailed reports for further analysis:</p>
                <div class="export-buttons">
                    <a href="<?php echo admin_url('admin.php?page=sac-reports&export=weekly'); ?>" 
                       class="button">Export Weekly Report (CSV)</a>
                    <a href="<?php echo admin_url('admin.php?page=sac-reports&export=performance'); ?>" 
                       class="button">Export Performance Data (JSON)</a>
                </div>
            </div>
        </div>
        
        <style>
            .report-section {
                background: white;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
            
            .summary-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            
            .summary-card {
                text-align: center;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 5px;
                border: 1px solid #dee2e6;
            }
            
            .summary-card h3 {
                margin: 0 0 10px 0;
                color: #495057;
                font-size: 14px;
            }
            
            .card-number {
                font-size: 2em;
                font-weight: bold;
                color: #007cba;
            }
            
            .metrics-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
                margin: 15px 0;
            }
            
            .metric-item {
                text-align: center;
                padding: 15px;
                background: #f1f3f4;
                border-radius: 4px;
            }
            
            .metric-item h4 {
                margin: 0 0 8px 0;
                font-size: 13px;
                color: #5f6368;
            }
            
            .metric-value {
                font-size: 1.5em;
                font-weight: bold;
                color: #1a73e8;
            }
            
            .export-buttons {
                margin: 15px 0;
            }
            
            .export-buttons .button {
                margin-right: 10px;
            }
        </style>
        <?php
    }

    /**
     * Duplicates page
     */
    public function duplicates_page() {
        $duplicates = $this->database->get_unresolved_duplicates();
        
        ?>
        <div class="wrap">
            <h1>Duplicate Keywords Detection</h1>
            
            <div class="duplicates-info">
                <p>This page shows keywords that appear across multiple products. 
                   Duplicate keywords can cause SEO conflicts and content cannibalization.</p>
                
                <form method="post">
                    <?php wp_nonce_field('sac_admin_action', 'sac_nonce'); ?>
                    <input type="hidden" name="sac_action" value="run_duplicate_scan">
                    <button type="submit" class="button">Run Duplicate Scan</button>
                </form>
            </div>
            
            <?php if (!empty($duplicates)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Products Using This Keyword</th>
                            <th>Similarity Score</th>
                            <th>Detected</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicates as $duplicate): ?>
                            <tr>
                                <td><strong><?php echo esc_html($duplicate->keyword_phrase); ?></strong></td>
                                <td>
                                    <?php 
                                    $product_ids = explode(',', $duplicate->product_ids);
                                    foreach ($product_ids as $product_id): 
                                        $product = get_post($product_id);
                                        if ($product):
                                    ?>
                                        <div class="duplicate-product">
                                            <a href="<?php echo get_edit_post_link($product_id); ?>">
                                                <?php echo esc_html($product->post_title); ?>
                                            </a>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </td>
                                <td>
                                    <span class="similarity-score">
                                        <?php echo number_format($duplicate->similarity_score * 100, 0); ?>%
                                    </span>
                                </td>
                                <td><?php echo human_time_diff(strtotime($duplicate->detected_at)); ?> ago</td>
                                <td>
                                    <button class="button button-small resolve-duplicate" 
                                            data-duplicate-id="<?php echo $duplicate->id; ?>">
                                        Mark Resolved
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-duplicates">
                    <h3>No Duplicate Keywords Found</h3>
                    <p>Great! Your product keywords are unique.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .duplicates-info {
                background: #e7f3ff;
                padding: 20px;
                margin: 20px 0;
                border-radius: 5px;
                border-left: 4px solid #007cba;
            }
            
            .duplicate-product {
                margin: 2px 0;
                padding: 2px 0;
            }
            
            .similarity-score {
                font-weight: bold;
                color: #dc3545;
            }
            
            .no-duplicates {
                text-align: center;
                padding: 40px;
                background: #d4edda;
                border: 1px solid #c3e6cb;
                border-radius: 5px;
                color: #155724;
            }
        </style>
        <?php
    }

    /**
     * Plugin settings page
     */
    public function settings_page() {
        if (isset($_POST['sac_settings']) && check_admin_referer('sac_settings_nonce')) {
            $this->save_settings();
        }
        
        $settings = SAC()->get_option('sac_settings', array());
        $api_key = SAC()->get_option('sac_api_key', '');
        $openai_api_key = SAC()->get_option('sac_openai_api_key', '');
        $surge_threshold = SAC()->get_option('surge_threshold', 25);
        $delete_data = SAC()->get_option('sac_delete_data_on_deactivate', false);
        
        ?>
        <div class="wrap">
            <h1>SEO Auto Content Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('sac_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="sac_api_key">Keyword Research API Key</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="sac_api_key" 
                                   name="sac_settings[api_key]" 
                                   value="<?php echo esc_attr($api_key); ?>" 
                                   class="regular-text">
                            <p class="description">Enter your keyword research API key</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="sac_openai_api_key">OpenAI API Key</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="sac_openai_api_key" 
                                   name="sac_settings[openai_api_key]" 
                                   value="<?php echo esc_attr($openai_api_key); ?>" 
                                   class="regular-text">
                            <p class="description">Enter your OpenAI API key for content generation</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="sac_surge_threshold">Surge Detection Threshold (%)</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="sac_surge_threshold" 
                                   name="sac_settings[surge_threshold]" 
                                   value="<?php echo esc_attr($surge_threshold); ?>" 
                                   min="5" 
                                   max="100" 
                                   step="5" 
                                   class="small-text">
                            <p class="description">Minimum percentage increase in search volume to trigger content generation</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sac_delete_data">Data Cleanup</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="sac_delete_data" 
                                       name="sac_settings[delete_data_on_deactivate]" 
                                       value="1" 
                                       <?php checked($delete_data); ?>>
                                Delete all plugin data when deactivating (including database tables and generated content)
                            </label>
                            <p class="description" style="color: #d63638;">Warning: This will permanently delete all keywords, logs, and generated content when you deactivate the plugin.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save plugin settings
     */
    private function save_settings() {
        $settings = array(
            'api_key' => sanitize_text_field($_POST['sac_settings']['api_key']),
            'openai_api_key' => sanitize_text_field($_POST['sac_settings']['openai_api_key']),
            'surge_threshold' => intval($_POST['sac_settings']['surge_threshold']),
            'delete_data_on_deactivate' => isset($_POST['sac_settings']['delete_data_on_deactivate']) ? 1 : 0
        );
        
        // Update settings
        SAC()->update_option('sac_api_key', $settings['api_key']);
        SAC()->update_option('sac_openai_api_key', $settings['openai_api_key']);
        SAC()->update_option('surge_threshold', $settings['surge_threshold']);
        SAC()->update_option('sac_delete_data_on_deactivate', $settings['delete_data_on_deactivate']);
        
        // Show success message
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        });
    }

    /**
     * Run initial product scan
     */
    private function run_initial_scan() {
        $batch_size = 50; // Process 50 products at a time
        
        $result = $this->keyword_extractor->batch_process($batch_size);
        
        $message = "Initial scan completed: {$result['processed']} products processed, {$result['failed']} failed.";
        
        if ($result['processed'] > 0) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
            });
        }
    }

    /**
     * AJAX handler for manual content generation
     */
    public function ajax_manual_content_generation() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $keyword_id = intval($_POST['keyword_id']);
        
        $result = $this->content_generator->regenerate_content($keyword_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Content generated successfully using GPT-4');
        }
    }

    /**
     * Run cron job manually
     */
    public function ajax_run_cron_manually() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $job_type = sanitize_text_field($_POST['job_type']);
        
        // Map job types to internal names
        $job_map = array(
            'keyword_monitoring' => 'monitoring',
            'content_generation' => 'content',
            'priority_update' => 'priority',
            'cleanup' => 'cleanup',
            'duplicate_detection' => 'duplicates'
        );
        
        if (!isset($job_map[$job_type])) {
            wp_send_json_error('Invalid job type');
        }
        
        $result = SAC()->cron_manager->trigger_manual_run($job_map[$job_type]);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Job completed successfully');
    }

    /**
     * AJAX handler for testing keyword extraction
     */
    public function ajax_test_extraction() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $title = sanitize_text_field($_POST['title']);
        
        $result = $this->keyword_extractor->test_extraction($title);
        
        wp_send_json_success($result);
    }

    /**
     * AJAX handler for resetting cron jobs
     */
    public function ajax_reset_crons() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            // Clear existing cron jobs
            if ($this->cron_manager && method_exists($this->cron_manager, 'clear_jobs')) {
                $this->cron_manager->clear_jobs();
            }
            
            // Schedule new cron jobs
            if ($this->cron_manager && method_exists($this->cron_manager, 'schedule_jobs')) {
                $this->cron_manager->schedule_jobs();
            }
            
            // Verify jobs were scheduled
            $scheduled_count = 0;
            $hooks = array(
                'sac_daily_keyword_monitoring',
                'sac_content_generation',
                'sac_weekly_cleanup'
            );
            
            foreach ($hooks as $hook) {
                if (wp_next_scheduled($hook)) {
                    $scheduled_count++;
                }
            }
            
            if ($scheduled_count > 0) {
                wp_send_json_success("Successfully scheduled {$scheduled_count} cron jobs");
            } else {
                wp_send_json_error('Cron jobs cleared but failed to reschedule');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error resetting cron jobs: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for testing API connections
     */
    public function ajax_test_apis() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $results = array(
            'keyword' => array('success' => false, 'error' => ''),
            'content' => array('success' => false, 'error' => '')
        );
        
        try {
            // Test keyword API (RapidAPI)
            if ($this->api_handler && method_exists($this->api_handler, 'test_keyword_api')) {
                $keyword_test = $this->api_handler->test_keyword_api();
                if ($keyword_test === true) {
                    $results['keyword']['success'] = true;
                } else {
                    $results['keyword']['error'] = is_string($keyword_test) ? $keyword_test : 'Connection failed';
                }
            } else {
                $results['keyword']['error'] = 'API handler not available';
            }
            
            // Test content API (OpenAI GPT-4)
            if ($this->api_handler && method_exists($this->api_handler, 'test_content_api')) {
                $content_test = $this->api_handler->test_content_api();
                if ($content_test === true) {
                    $results['content']['success'] = true;
                } else {
                    $results['content']['error'] = is_string($content_test) ? $content_test : 'Connection failed';
                }
            } else {
                $results['content']['error'] = 'API handler not available';
            }
            
        } catch (Exception $e) {
            $results['keyword']['error'] = 'Test failed: ' . $e->getMessage();
            $results['content']['error'] = 'Test failed: ' . $e->getMessage();
        }
        
        wp_send_json_success($results);
    }

    /**
     * Process remaining products
     */
    public function ajax_process_products() {
        check_ajax_referer('sac_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            // Get unprocessed products
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => '_sac_processed',
                        'compare' => 'NOT EXISTS'
                    )
                )
            );
            
            $products = get_posts($args);
            
            if (empty($products)) {
                wp_send_json_error('No unprocessed products found');
            }
            
            $processed = 0;
            $keywords_extracted = 0;
            
            foreach ($products as $product) {
                // Extract keywords
                $result = $this->keyword_extractor->process_product($product->ID);
                
                if (!is_wp_error($result)) {
                    $processed++;
                    $keywords_extracted += count($result);
                    
                    // Mark product as processed
                    update_post_meta($product->ID, '_sac_processed', current_time('mysql'));
                }
            }
            
            wp_send_json_success(sprintf(
                'Processed %d products, extracted %d keywords',
                $processed,
                $keywords_extracted
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Tools page for testing and debugging
     */
    public function tools_page() {
        ?>
        <div class="wrap">
            <h1>Tools & Testing</h1>
            
            <!-- Keyword Extraction Tester -->
            <div class="tool-section">
                <h2>Keyword Extraction Tester</h2>
                <p>Test how keywords are extracted from product titles:</p>
                
                <form id="extraction-tester">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Product Title</th>
                            <td>
                                <input type="text" id="test-title" class="large-text" 
                                       placeholder="e.g., Samsung Galaxy S25 Edge (Pre-order on Whatsapp)">
                                <button type="button" class="button" id="test-extraction">Test Extraction</button>
                            </td>
                        </tr>
                    </table>
                </form>
                
                <div id="extraction-results" style="display: none;">
                    <h3>Extraction Results:</h3>
                    <table class="widefat">
                        <tr>
                            <th>Original Title:</th>
                            <td id="result-original"></td>
                        </tr>
                        <tr>
                            <th>Cleaned Title:</th>
                            <td id="result-cleaned"></td>
                        </tr>
                        <tr>
                            <th>Extracted Keyword:</th>
                            <td id="result-keyword"></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- System Information -->
            <div class="tool-section">
                <h2>System Information</h2>
                <?php 
                $cron_health = $this->cron_manager->health_check();
                $extraction_stats = $this->keyword_extractor->get_extraction_statistics();
                ?>
                
                <table class="widefat">
                    <tr>
                        <th>WordPress Version:</th>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <th>WooCommerce Version:</th>
                        <td><?php echo defined('WC_VERSION') ? WC_VERSION : 'Not installed'; ?></td>
                    </tr>
                    <tr>
                        <th>Plugin Version:</th>
                        <td><?php echo SAC_PLUGIN_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>WP Cron Status:</th>
                        <td>
                            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                                <span style="color: red;">Disabled</span>
                            <?php else: ?>
                                <span style="color: green;">Enabled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Products Processed:</th>
                        <td>
                            <?php echo $extraction_stats['processed_products']; ?> / 
                            <?php echo $extraction_stats['total_products']; ?>
                            (<?php echo $extraction_stats['completion_percentage']; ?>%)
                        </td>
                    </tr>
                    <tr>
                        <th>Total Keywords:</th>
                        <td><?php echo number_format($extraction_stats['total_keywords']); ?></td>
                    </tr>
                    <tr>
                        <th>System Health:</th>
                        <td>
                            <span style="color: <?php echo $cron_health['status'] === 'healthy' ? 'green' : ($cron_health['status'] === 'warning' ? 'orange' : 'red'); ?>;">
                                <?php echo ucfirst($cron_health['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>API Configuration:</th>
                        <td>
                            <?php 
                            $rapidapi_configured = !empty(SAC()->get_option('sac_api_key'));
                            $openai_configured = !empty(SAC()->get_option('sac_openai_api_key'));
                            ?>
                            <div>
                                <span style="color: <?php echo $rapidapi_configured ? 'green' : 'red'; ?>;">
                                    <?php echo $rapidapi_configured ? '✓' : '✗'; ?> RapidAPI (Keywords)
                                </span>
                            </div>
                            <div>
                                <span style="color: <?php echo $openai_configured ? 'green' : 'red'; ?>;">
                                    <?php echo $openai_configured ? '✓' : '✗'; ?> OpenAI GPT-4 (Content)
                                </span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Debug Tools -->
            <div class="tool-section">
                <h2>Debug Tools</h2>
                <div class="debug-actions">
                    <button class="button" id="export-logs">Export Debug Logs</button>
                    <button class="button" id="clear-cache">Clear Plugin Cache</button>
                    <button class="button" id="reset-crons">Reset Cron Jobs</button>
                    <button class="button" id="test-gpt4">Test GPT-4 Connection</button>
                </div>
            </div>
        </div>
        
        <style>
            .tool-section {
                background: white;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
            
            .debug-actions .button {
                margin-right: 10px;
                margin-bottom: 10px;
            }
            
            #extraction-results {
                background: #f9f9f9;
                padding: 15px;
                margin: 15px 0;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // Extraction tester
                $('#test-extraction').click(function() {
                    var title = $('#test-title').val();
                    if (!title) {
                        alert('Please enter a product title');
                        return;
                    }
                    
                    $(this).prop('disabled', true).text('Testing...');
                    
                    $.post(sacAjax.ajaxurl, {
                        action: 'sac_test_extraction',
                        title: title,
                        nonce: sacAjax.nonce
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#result-original').text(data.original_title);
                            $('#result-cleaned').text(data.cleaned_title);
                            $('#result-keyword').text(data.extracted_keyword);
                            $('#extraction-results').show();
                        } else {
                            alert('Error: ' + response.data);
                        }
                        
                        $('#test-extraction').prop('disabled', false).text('Test Extraction');
                    });
                });
                
                // Debug tools
                $('#export-logs').click(function() {
                    window.open(ajaxurl + '?action=sac_export_logs&nonce=' + sacAjax.nonce);
                });
                
                $('#clear-cache').click(function() {
                    if (confirm('Clear plugin cache? This will reset temporary data.')) {
                        $.post(sacAjax.ajaxurl, {
                            action: 'sac_clear_cache',
                            nonce: sacAjax.nonce
                        }, function(response) {
                            if (response.success) {
                                alert('Cache cleared successfully');
                            } else {
                                alert('Error: ' + response.data);
                            }
                        });
                    }
                });
                
                $('#reset-crons').click(function() {
                    if (confirm('Reset all cron jobs? This will reschedule all automated tasks.')) {
                        $(this).prop('disabled', true).text('Resetting...');
                        
                        $.post(sacAjax.ajaxurl, {
                            action: 'sac_reset_crons',
                            nonce: sacAjax.nonce
                        }, function(response) {
                            if (response.success) {
                                alert('Cron jobs reset successfully: ' + response.data);
                            } else {
                                alert('Error: ' + response.data);
                            }
                            $('#reset-crons').prop('disabled', false).text('Reset Cron Jobs');
                        });
                    }
                });
                
                $('#test-gpt4').click(function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Testing GPT-4...');
                    
                    $.post(sacAjax.ajaxurl, {
                        action: 'sac_test_apis',
                        nonce: sacAjax.nonce
                    }, function(response) {
                        if (response.data.content.success) {
                            alert('✓ GPT-4 API connection successful!');
                        } else {
                            alert('✗ GPT-4 API connection failed: ' + response.data.content.error);
                        }
                        button.prop('disabled', false).text('Test GPT-4 Connection');
                    });
                });
            });
        </script>
        <?php
    }
}