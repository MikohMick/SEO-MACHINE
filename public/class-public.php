<?php
/**
 * Public Frontend Class
 * Handles frontend display of generated content
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAC_Public {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new SAC_Database();
        $this->init();
    }

    /**
     * Initialize public hooks
     */
    private function init() {
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add custom CSS for generated content
        add_action('wp_head', array($this, 'add_custom_css'));
        
        // Modify WooCommerce product tabs
        add_filter('woocommerce_product_tabs', array($this, 'modify_product_tabs'), 20);
        
        // Add body classes for styling
        add_filter('body_class', array($this, 'add_body_classes'));
        
        // Add schema markup for SEO
        add_action('wp_head', array($this, 'add_schema_markup'));
        
        // Track content performance
        add_action('wp_footer', array($this, 'track_content_views'));
        
        // Add "Read More" functionality
        add_action('wp_footer', array($this, 'add_read_more_script'));
        
        // Modify excerpt for generated blog posts
        add_filter('the_excerpt', array($this, 'modify_generated_post_excerpt'));
        
        // Add related products section to generated posts
        add_action('the_content', array($this, 'add_related_products_section'));
        
        // Enhance breadcrumbs for generated content
        add_filter('woocommerce_breadcrumb', array($this, 'enhance_breadcrumbs'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on relevant pages
        if (!$this->should_load_assets()) {
            return;
        }
        
        wp_enqueue_style(
            'sac-public-style',
            SAC_PLUGIN_URL . 'public/assets/public.css',
            array(),
            SAC_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'sac-public-script',
            SAC_PLUGIN_URL . 'public/assets/public.js',
            array('jquery'),
            SAC_PLUGIN_VERSION,
            true
        );
        
        // Localize script for frontend interactions
        wp_localize_script('sac-public-script', 'sacPublic', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sac_public_nonce'),
            'product_id' => is_product() ? get_the_ID() : 0,
            'is_generated_post' => $this->is_generated_content_post(),
            'tracking_enabled' => $this->is_tracking_enabled()
        ));
    }

    /**
     * Check if we should load SAC assets
     */
    private function should_load_assets() {
        // Load on product pages
        if (is_product()) {
            return true;
        }
        
        // Load on generated blog posts
        if ($this->is_generated_content_post()) {
            return true;
        }
        
        // Load on blog category pages that might show generated posts
        if (is_category('product-insights')) {
            return true;
        }
        
        return false;
    }

    /**
     * Add custom CSS for generated content styling
     */
    public function add_custom_css() {
        if (!$this->should_load_assets()) {
            return;
        }
        
        ?>
        <style type="text/css">
            /* Generated Content Styles */
            .sac-generated-content {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                border: 1px solid #dee2e6;
                border-radius: 12px;
                padding: 25px;
                margin: 25px 0;
                position: relative;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .sac-generated-content:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            }
            
            .sac-generated-content h3 {
                color: #495057;
                font-size: 1.3em;
                margin: 0 0 18px 0;
                padding-bottom: 12px;
                border-bottom: 3px solid #007cba;
                font-weight: 600;
                position: relative;
            }
            
            .sac-generated-content h3:before {
                content: "💡";
                margin-right: 10px;
                font-size: 1.1em;
            }
            
            .sac-excerpt {
                line-height: 1.7;
                color: #212529;
                font-size: 15px;
                margin-bottom: 20px;
            }
            
            .sac-excerpt p {
                margin-bottom: 16px;
            }
            
            .sac-excerpt p:last-child {
                margin-bottom: 0;
            }
            
            .sac-read-more {
                display: inline-flex;
                align-items: center;
                background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
                color: white !important;
                padding: 14px 28px;
                text-decoration: none !important;
                border-radius: 50px;
                font-weight: 600;
                font-size: 14px;
                transition: all 0.4s ease;
                box-shadow: 0 4px 15px rgba(0, 124, 186, 0.3);
                text-transform: uppercase;
                letter-spacing: 0.8px;
                border: none;
                cursor: pointer;
            }
            
            .sac-read-more:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 25px rgba(0, 124, 186, 0.4);
                background: linear-gradient(135deg, #005a87 0%, #007cba 100%);
                color: white !important;
            }
            
            .sac-read-more:before {
                content: "📖";
                margin-right: 8px;
                font-size: 1.1em;
            }
            
            .sac-badge {
                position: absolute;
                top: -10px;
                right: 20px;
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                padding: 6px 15px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
            }
            
            .sac-badge:before {
                content: "🤖";
                margin-right: 5px;
            }
            
            /* Mobile Responsive */
            @media (max-width: 768px) {
                .sac-generated-content {
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 8px;
                }
                
                .sac-read-more {
                    padding: 12px 24px;
                    font-size: 13px;
                    border-radius: 25px;
                }
            }
            
            /* Dark Theme Support */
            @media (prefers-color-scheme: dark) {
                .sac-generated-content {
                    background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
                    border-color: #4a5568;
                    color: #e2e8f0;
                }
                
                .sac-generated-content h3 {
                    color: #e2e8f0;
                    border-color: #4299e1;
                }
                
                .sac-excerpt {
                    color: #cbd5e0;
                }
            }
        </style>
        <?php
    }

    /**
     * Modify WooCommerce product tabs to add analysis tab
     */
    public function modify_product_tabs($tabs) {
        global $product;
        
        if (!$product) {
            return $tabs;
        }
        
        // Check if this product has generated content
        $blog_post_id = get_post_meta($product->get_id(), '_sac_blog_post_id', true);
        $keyword = get_post_meta($product->get_id(), '_sac_keyword', true);
        
        if ($blog_post_id && $keyword) {
            // Add analysis tab
            $tabs['sac_analysis'] = array(
                'title' => __('Expert Analysis', 'sac'),
                'priority' => 25,
                'callback' => array($this, 'render_analysis_tab')
            );
        }
        
        return $tabs;
    }

    /**
     * Render the analysis tab content
     */
    public function render_analysis_tab() {
        global $product;
        
        $blog_post_id = get_post_meta($product->get_id(), '_sac_blog_post_id', true);
        $keyword = get_post_meta($product->get_id(), '_sac_keyword', true);
        $last_updated = get_post_meta($product->get_id(), '_sac_last_updated', true);
        
        if (!$blog_post_id) {
            return;
        }
        
        $blog_post = get_post($blog_post_id);
        $blog_url = get_permalink($blog_post_id);
        
        ?>
        <div class="sac-analysis-tab">
            <div class="analysis-header">
                <h3><?php printf(__('Expert Analysis: %s', 'sac'), esc_html($keyword)); ?></h3>
                <p class="analysis-meta">
                    <?php 
                    if ($last_updated) {
                        printf(__('Last updated: %s ago', 'sac'), human_time_diff(strtotime($last_updated)));
                    } else {
                        _e('Recently updated', 'sac');
                    }
                    ?>
                </p>
            </div>
            
            <div class="analysis-preview">
                <?php 
                // Show first 100 words of the blog post
                $content = $blog_post->post_content;
                $content = wp_strip_all_tags($content);
                $content = wp_trim_words($content, 100, '...');
                echo '<p>' . esc_html($content) . '</p>';
                ?>
            </div>
            
            <div class="analysis-cta">
                <a href="<?php echo esc_url($blog_url); ?>" class="sac-analysis-link" target="_blank">
                    <?php _e('Read Complete Analysis', 'sac'); ?> →
                </a>
            </div>
            
            <div class="analysis-features">
                <h4><?php _e('What You\'ll Learn:', 'sac'); ?></h4>
                <ul>
                    <li><?php _e('Comprehensive feature breakdown', 'sac'); ?></li>
                    <li><?php _e('Expert recommendations', 'sac'); ?></li>
                    <li><?php _e('Performance analysis', 'sac'); ?></li>
                    <li><?php _e('Value comparison', 'sac'); ?></li>
                </ul>
            </div>
        </div>
        
        <style>
            .sac-analysis-tab {
                padding: 25px 0;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .analysis-header h3 {
                color: #2c3e50;
                margin-bottom: 8px;
                font-size: 1.4em;
                font-weight: 600;
            }
            
            .analysis-meta {
                color: #7f8c8d;
                font-size: 14px;
                margin-bottom: 25px;
                font-style: italic;
            }
            
            .analysis-preview {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                padding: 25px;
                border-radius: 8px;
                margin: 25px 0;
                border-left: 5px solid #007cba;
                position: relative;
            }
            
            .analysis-preview:before {
                content: '"';
                position: absolute;
                top: 10px;
                left: 15px;
                font-size: 3em;
                color: #007cba;
                opacity: 0.3;
                font-family: serif;
            }
            
            .analysis-preview p {
                margin: 0;
                line-height: 1.6;
                color: #495057;
                padding-left: 40px;
            }
            
            .analysis-cta {
                text-align: center;
                margin: 30px 0;
            }
            
            .sac-analysis-link {
                display: inline-flex;
                align-items: center;
                background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
                color: white !important;
                padding: 15px 30px;
                text-decoration: none !important;
                border-radius: 8px;
                font-weight: 600;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(0, 124, 186, 0.3);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .sac-analysis-link:hover {
                background: linear-gradient(135deg, #005a87 0%, #007cba 100%);
                color: white !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0, 124, 186, 0.4);
            }
            
            .analysis-features {
                margin-top: 35px;
                padding-top: 25px;
                border-top: 2px solid #ecf0f1;
            }
            
            .analysis-features h4 {
                color: #2c3e50;
                margin-bottom: 18px;
                font-size: 1.1em;
                font-weight: 600;
            }
            
            .analysis-features ul {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            
            .analysis-features li {
                padding: 8px 0;
                color: #555;
                font-size: 15px;
                display: flex;
                align-items: center;
            }
            
            .analysis-features li:before {
                content: "✓";
                color: #28a745;
                font-weight: bold;
                margin-right: 12px;
                font-size: 1.1em;
            }
        </style>
        <?php
    }

    /**
     * Add body classes for styling
     */
    public function add_body_classes($classes) {
        if (is_product()) {
            $product_id = get_the_ID();
            $has_generated_content = get_post_meta($product_id, '_sac_blog_post_id', true);
            
            if ($has_generated_content) {
                $classes[] = 'has-sac-content';
            }
        }
        
        if ($this->is_generated_content_post()) {
            $classes[] = 'sac-generated-post';
        }
        
        return $classes;
    }

    /**
     * Add schema markup for SEO
     */
    public function add_schema_markup() {
        if (!is_single() || !$this->is_generated_content_post()) {
            return;
        }
        
        global $post;
        
        $keyword = get_post_meta($post->ID, '_sac_keyword', true);
        $product_id = get_post_meta($post->ID, '_sac_product_id', true);
        
        if (!$keyword || !$product_id) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Review',
            'itemReviewed' => array(
                '@type' => 'Product',
                'name' => $product->get_name(),
                'description' => $product->get_short_description(),
                'sku' => $product->get_sku(),
                'offers' => array(
                    '@type' => 'Offer',
                    'price' => $product->get_price(),
                    'priceCurrency' => get_woocommerce_currency(),
                    'availability' => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => $product->get_permalink()
                )
            ),
            'author' => array(
                '@type' => 'Organization',
                'name' => get_bloginfo('name')
            ),
            'datePublished' => get_the_date('c', $post->ID),
            'description' => get_the_excerpt($post->ID),
            'name' => get_the_title($post->ID)
        );
        
        ?>
        <script type="application/ld+json">
        <?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
        </script>
        <?php
    }

    /**
     * Track content views for analytics
     */
    public function track_content_views() {
        if (!is_single() || !$this->is_generated_content_post()) {
            return;
        }
        
        global $post;
        
        // Simple view counter
        $views = get_post_meta($post->ID, 'post_views_count', true);
        $views = $views ? intval($views) + 1 : 1;
        update_post_meta($post->ID, 'post_views_count', $views);
        
        // Track in database for analytics
        $keyword_id = $this->get_keyword_id_for_post($post->ID);
        
        if ($keyword_id && $this->is_tracking_enabled()) {
            ?>
            <script>
                // Google Analytics tracking
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_content_view', {
                        'keyword_id': <?php echo $keyword_id; ?>,
                        'post_id': <?php echo $post->ID; ?>,
                        'keyword': '<?php echo esc_js(get_post_meta($post->ID, '_sac_keyword', true)); ?>'
                    });
                }
                
                // Facebook Pixel tracking
                if (typeof fbq !== 'undefined') {
                    fbq('trackCustom', 'SAC_ContentView', {
                        keyword_id: <?php echo $keyword_id; ?>,
                        post_id: <?php echo $post->ID; ?>
                    });
                }
            </script>
            <?php
        }
    }

    /**
     * Add "Read More" functionality with analytics
     */
    public function add_read_more_script() {
        if (!is_product()) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Track "Read More" clicks
            $('.sac-read-more').on('click', function(e) {
                var link = $(this);
                var originalText = link.text();
                
                // Add loading state
                link.addClass('loading').text('Loading...');
                
                // Track click
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_read_more_click', {
                        'product_id': <?php echo is_product() ? get_the_ID() : 0; ?>,
                        'source': 'product_page'
                    });
                }
                
                // Restore text after delay
                setTimeout(function() {
                    link.removeClass('loading').text(originalText);
                }, 500);
            });
            
            // Track analysis tab clicks
            $('.sac-analysis-link').on('click', function(e) {
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_analysis_click', {
                        'product_id': <?php echo is_product() ? get_the_ID() : 0; ?>,
                        'source': 'analysis_tab'
                    });
                }
            });
            
            // Fade-in animation for generated content
            $('.sac-generated-content').each(function() {
                $(this).css('opacity', '0').animate({opacity: 1}, 800);
            });
        });
        </script>
        <?php
    }

    /**
     * Modify excerpt for generated blog posts
     */
    public function modify_generated_post_excerpt($excerpt) {
        if (!$this->is_generated_content_post()) {
            return $excerpt;
        }
        
        global $post;
        
        $keyword = get_post_meta($post->ID, '_sac_keyword', true);
        $product_id = get_post_meta($post->ID, '_sac_product_id', true);
        
        if ($keyword && $product_id) {
            $product_url = get_permalink($product_id);
            $excerpt .= sprintf(
                ' <a href="%s" class="excerpt-product-link">%s →</a>',
                esc_url($product_url),
                sprintf(__('View %s Product', 'sac'), esc_html($keyword))
            );
        }
        
        return $excerpt;
    }

    /**
     * Add related products section to generated blog posts
     */
    public function add_related_products_section($content) {
        if (!is_single() || !$this->is_generated_content_post()) {
            return $content;
        }
        
        global $post;
        
        $product_id = get_post_meta($post->ID, '_sac_product_id', true);
        $keyword = get_post_meta($post->ID, '_sac_keyword', true);
        
        if (!$product_id || !$keyword) {
            return $content;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return $content;
        }
        
        // Get related products based on category
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        if (!empty($categories)) {
            $related_products = wc_get_products(array(
                'category' => $categories,
                'exclude' => array($product_id),
                'limit' => 3,
                'orderby' => 'popularity'
            ));
            
            if (!empty($related_products)) {
                $related_section = $this->render_related_products($related_products);
                $content .= $related_section;
            }
        }
        
        return $content;
    }

    /**
     * Render related products section
     */
    private function render_related_products($products) {
        ob_start();
        ?>
        <div class="sac-related-products">
            <h3><?php _e('Related Products', 'sac'); ?></h3>
            <div class="related-products-grid">
                <?php foreach ($products as $product): ?>
                    <div class="related-product-item">
                        <a href="<?php echo $product->get_permalink(); ?>">
                            <?php echo $product->get_image('thumbnail'); ?>
                            <h4><?php echo $product->get_name(); ?></h4>
                            <span class="price"><?php echo $product->get_price_html(); ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
            .sac-related-products {
                margin: 40px 0;
                padding: 30px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 1px solid #e9ecef;
            }
            
            .sac-related-products h3 {
                text-align: center;
                margin-bottom: 25px;
                color: #495057;
                font-size: 1.5em;
            }
            
            .related-products-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
            }
            
            .related-product-item {
                text-align: center;
                background: white;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                transition: transform 0.3s ease;
            }
            
            .related-product-item:hover {
                transform: translateY(-5px);
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            
            .related-product-item a {
                text-decoration: none;
                color: inherit;
            }
            
            .related-product-item h4 {
                margin: 15px 0 10px 0;
                font-size: 1.1em;
                color: #333;
            }
            
            .related-product-item .price {
                font-weight: bold;
                color: #007cba;
                font-size: 1.1em;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Enhance breadcrumbs for generated content
     */
    public function enhance_breadcrumbs($breadcrumbs) {
        if (!$this->is_generated_content_post()) {
            return $breadcrumbs;
        }
        
        global $post;
        
        $product_id = get_post_meta($post->ID, '_sac_product_id', true);
        
        if ($product_id) {
            $product = get_post($product_id);
            if ($product) {
                // Add product link to breadcrumbs
                $product_crumb = sprintf(
                    '<a href="%s">%s</a>',
                    get_permalink($product_id),
                    esc_html($product->post_title)
                );
                
                // Insert before the current page
                $breadcrumbs = str_replace(
                    '<span>' . get_the_title() . '</span>',
                    $product_crumb . ' → <span>' . get_the_title() . '</span>',
                    $breadcrumbs
                );
            }
        }
        
        return $breadcrumbs;
    }

    /**
     * Check if current post is generated content
     */
    private function is_generated_content_post() {
        if (!is_single()) {
            return false;
        }
        
        global $post;
        return get_post_meta($post->ID, '_sac_generated_content', true) ? true : false;
    }

    /**
     * Get keyword ID for a generated post
     */
    private function get_keyword_id_for_post($post_id) {
        global $wpdb;
        
        $keyword_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->database->get_table('keywords')} 
                 WHERE product_id = %d 
                 AND keyword_phrase = %s
                 LIMIT 1",
                get_post_meta($post_id, '_sac_product_id', true),
                get_post_meta($post_id, '_sac_keyword', true)
            )
        );
        
        return $keyword_id;
    }

    /**
     * Check if tracking is enabled
     */
    private function is_tracking_enabled() {
        // You can add a setting to enable/disable tracking
        return apply_filters('sac_tracking_enabled', true);
    }
}

// Initialize the public class
new SAC_Public();