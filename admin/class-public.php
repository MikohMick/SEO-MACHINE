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
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize public hooks
     */
    private function init() {
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add custom CSS for generated content
        add_action('wp_head', array($this, 'add_custom_css'));
        
        // Modify product description display
        add_filter('woocommerce_product_tabs', array($this, 'modify_product_tabs'), 20);
        
        // Add schema markup for SEO
        add_action('wp_head', array($this, 'add_schema_markup'));
        
        // Track content performance (if analytics enabled)
        add_action('wp_footer', array($this, 'track_content_views'));
        
        // Add "Read More" functionality
        add_action('wp_footer', array($this, 'add_read_more_script'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on product pages and blog posts with generated content
        if (!is_product() && !$this->is_generated_content_post()) {
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
    }

    /**
     * Add custom CSS for generated content styling
     */
    public function add_custom_css() {
        if (!is_product() && !$this->is_generated_content_post()) {
            return;
        }
        
        ?>
        <style type="text/css">
            /* Generated Content Styles */
            .sac-generated-content {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                position: relative;
            }
            
            .sac-generated-content h3 {
                color: #495057;
                font-size: 1.2em;
                margin: 0 0 15px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #007cba;
            }
            
            .sac-excerpt {
                line-height: 1.6;
                color: #212529;
                font-size: 15px;
            }
            
            .sac-excerpt p {
                margin-bottom: 15px;
            }
            
            .sac-read-more {
                display: inline-block;
                background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
                color: white !important;
                padding: 12px 24px;
                text-decoration: none !important;
                border-radius: 25px;
                font-weight: 600;
                font-size: 14px;
                transition: all 0.3s ease;
                box-shadow: 0 2px 10px rgba(0, 124, 186, 0.3);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .sac-read-more:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 20px rgba(0, 124, 186, 0.4);
                background: linear-gradient(135deg, #005a87 0%, #007cba 100%);
                color: white !important;
            }
            
            .sac-read-more:before {
                content: "📖 ";
                margin-right: 5px;
            }
            
            .sac-badge {
                position: absolute;
                top: -8px;
                right: 15px;
                background: #28a745;
                color: white;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            /* Generated Blog Post Styles */
            .sac-generated-post {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
                text-align: center;
            }
            
            .sac-generated-post h4 {
                margin: 0 0 10px 0;
                font-size: 16px;
            }
            
            .sac-generated-post p {
                margin: 0;
                font-size: 14px;
                opacity: 0.9;
            }
            
            /* Mobile Responsive */
            @media (max-width: 768px) {
                .sac-generated-content {
                    padding: 15px;
                    margin: 15px 0;
                }
                
                .sac-read-more {
                    padding: 10px 20px;
                    font-size: 13px;
                }
            }
            
            /* Dark Theme Support */
            @media (prefers-color-scheme: dark) {
                .sac-generated-content {
                    background: #2d3748;
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
     * Modify WooCommerce product tabs to enhance generated content display
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
            // Add a new tab for detailed analysis
            $tabs['sac_analysis'] = array(
                'title' => 'Detailed Analysis',
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
                <h3>Expert Analysis: <?php echo esc_html($keyword); ?></h3>
                <p class="analysis-meta">
                    Last updated: <?php echo $last_updated ? human_time_diff(strtotime($last_updated)) . ' ago' : 'Recently'; ?>
                </p>
            </div>
            
            <div class="analysis-preview">
                <?php 
                // Show first 2 paragraphs of the blog post
                $content = $blog_post->post_content;
                $content = wp_strip_all_tags($content);
                $content = wp_trim_words($content, 100, '...');
                echo '<p>' . esc_html($content) . '</p>';
                ?>
            </div>
            
            <div class="analysis-cta">
                <a href="<?php echo esc_url($blog_url); ?>" class="sac-analysis-link" target="_blank">
                    Read Complete Analysis →
                </a>
            </div>
            
            <div class="analysis-features">
                <h4>What You'll Learn:</h4>
                <ul>
                    <li>✓ Comprehensive feature breakdown</li>
                    <li>✓ Expert recommendations</li>
                    <li>✓ Performance analysis</li>
                    <li>✓ Value comparison</li>
                </ul>
            </div>
        </div>
        
        <style>
            .sac-analysis-tab {
                padding: 20px 0;
            }
            
            .analysis-header h3 {
                color: #333;
                margin-bottom: 5px;
            }
            
            .analysis-meta {
                color: #666;
                font-size: 14px;
                margin-bottom: 20px;
            }
            
            .analysis-preview {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 5px;
                margin: 20px 0;
                border-left: 4px solid #007cba;
            }
            
            .sac-analysis-link {
                display: inline-block;
                background: #007cba;
                color: white !important;
                padding: 12px 24px;
                text-decoration: none !important;
                border-radius: 5px;
                font-weight: bold;
                transition: background 0.3s ease;
            }
            
            .sac-analysis-link:hover {
                background: #005a87;
                color: white !important;
            }
            
            .analysis-features {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            
            .analysis-features h4 {
                color: #333;
                margin-bottom: 15px;
            }
            
            .analysis-features ul {
                list-style: none;
                padding: 0;
            }
            
            .analysis-features li {
                padding: 5px 0;
                color: #555;
            }
        </style>
        <?php
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
        global $wpdb;
        
        $keyword_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . (new SAC_Database())->get_table('keywords') . " 
                 WHERE product_id = %d 
                 AND keyword_phrase = %s",
                get_post_meta($post->ID, '_sac_product_id', true),
                get_post_meta($post->ID, '_sac_keyword', true)
            )
        );
        
        if ($keyword_id) {
            // You could add more detailed analytics here
            // For now, we just track the view count
            ?>
            <script>
                // Optional: Add Google Analytics or other tracking
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_content_view', {
                        'keyword_id': <?php echo $keyword_id; ?>,
                        'post_id': <?php echo $post->ID; ?>,
                        'keyword': '<?php echo esc_js(get_post_meta($post->ID, '_sac_keyword', true)); ?>'
                    });
                }
            </script>
            <?php
        }
    }

    /**
     * Add "Read More" functionality with smooth scrolling
     */
    public function add_read_more_script() {
        if (!is_product()) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Smooth scroll to blog post link
            $('.sac-read-more').click(function(e) {
                // Add loading state
                var link = $(this);
                var originalText = link.text();
                link.text('Loading...');
                
                // Optional: Track click
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_read_more_click', {
                        'product_id': <?php echo is_product() ? get_the_ID() : 0; ?>,
                        'source': 'product_page'
                    });
                }
                
                // Restore text after a moment
                setTimeout(function() {
                    link.text(originalText);
                }, 500);
                
                // Let the link work normally (opens in same page)
            });
            
            // Enhance analysis tab
            $('.sac-analysis-link').click(function(e) {
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'sac_analysis_click', {
                        'product_id': <?php echo is_product() ? get_the_ID() : 0; ?>,
                        'source': 'analysis_tab'
                    });
                }
            });
            
            // Add fade-in animation for generated content
            $('.sac-generated-content').each(function() {
                $(this).css('opacity', '0').animate({opacity: 1}, 800);
            });
        });
        </script>
        <?php
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
     * Add custom body classes for styling
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
                ' <a href="%s" class="excerpt-product-link">View %s Product →</a>',
                esc_url($product_url),
                esc_html($keyword)
            );
        }
        
        return $excerpt;
    }

    /**
     * Add breadcrumb enhancement for generated content
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
     * Add related products section to generated blog posts
     */
    public function add_related_products_section() {
        if (!is_single() || !$this->is_generated_content_post()) {
            return;
        }
        
        global $post;
        
        $product_id = get_post_meta($post->ID, '_sac_product_id', true);
        $keyword = get_post_meta($post->ID, '_sac_keyword', true);
        
        if (!$product_id || !$keyword) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
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
                ?>
                <div class="sac-related-products">
                    <h3>Related Products</h3>
                    <div class="related-products-grid">
                        <?php foreach ($related_products as $related_product): ?>
                            <div class="related-product-item">
                                <a href="<?php echo $related_product->get_permalink(); ?>">
                                    <?php echo $related_product->get_image('thumbnail'); ?>
                                    <h4><?php echo $related_product->get_name(); ?></h4>
                                    <span class="price"><?php echo $related_product->get_price_html(); ?></span>
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
            }
        }
    }
}

// Initialize the public class
new SAC_Public();