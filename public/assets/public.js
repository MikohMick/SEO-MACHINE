/**
 * SEO Auto Content Public JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Initialize all public functionality
    function init() {
        initContentAnimations();
        initReadMoreTracking();
        initAnalyticsTracking();
        initAccessibility();
        initLazyLoading();
    }
    
    // Animate content on scroll
    function initContentAnimations() {
        // Add fade-in animation to generated content
        $('.sac-generated-content').each(function() {
            var $content = $(this);
            
            // Set initial state
            $content.css({
                'opacity': '0',
                'transform': 'translateY(30px)'
            });
            
            // Animate when in viewport
            if (isElementInViewport($content[0])) {
                animateContent($content);
            }
        });
        
        // Scroll-triggered animations
        $(window).on('scroll', function() {
            $('.sac-generated-content').each(function() {
                var $content = $(this);
                
                if (!$content.hasClass('animated') && isElementInViewport($content[0])) {
                    animateContent($content);
                }
            });
        });
    }
    
    function animateContent($element) {
        $element.addClass('animated').animate({
            'opacity': 1,
            'transform': 'translateY(0)'
        }, 800, 'ease-out');
    }
    
    function isElementInViewport(el) {
        var rect = el.getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    }
    
    // Track "Read More" clicks
    function initReadMoreTracking() {
        $('.sac-read-more').on('click', function(e) {
            var $link = $(this);
            var productId = $('body').hasClass('single-product') ? 
                $('article.product').attr('id')?.replace('product-', '') : null;
            
            // Add loading state
            $link.addClass('sac-loading');
            
            // Track the click
            trackEvent('sac_read_more_click', {
                'source': 'product_page',
                'product_id': productId,
                'timestamp': new Date().toISOString()
            });
            
            // Remove loading state after a moment
            setTimeout(function() {
                $link.removeClass('sac-loading');
            }, 500);
        });
        
        // Track analysis tab clicks
        $('.sac-analysis-link').on('click', function(e) {
            var $link = $(this);
            var productId = $('body').hasClass('single-product') ? 
                $('article.product').attr('id')?.replace('product-', '') : null;
            
            trackEvent('sac_analysis_click', {
                'source': 'analysis_tab',
                'product_id': productId,
                'timestamp': new Date().toISOString()
            });
        });
    }
    
    // Enhanced analytics tracking
    function initAnalyticsTracking() {
        // Track content visibility
        trackContentVisibility();
        
        // Track scroll depth on generated content
        trackScrollDepth();
        
        // Track time spent reading
        trackReadingTime();
    }
    
    function trackContentVisibility() {
        $('.sac-generated-content').each(function() {
            var $content = $(this);
            var tracked = false;
            
            $(window).on('scroll', function() {
                if (!tracked && isElementInViewport($content[0])) {
                    tracked = true;
                    
                    trackEvent('sac_content_viewed', {
                        'content_type': 'generated_excerpt',
                        'page_type': getPageType(),
                        'timestamp': new Date().toISOString()
                    });
                }
            });
        });
    }
    
    function trackScrollDepth() {
        if (!$('.sac-generated-content').length) return;
        
        var scrollDepths = [25, 50, 75, 100];
        var trackedDepths = [];
        
        $(window).on('scroll', function() {
            var scrollPercent = Math.round(
                ($(window).scrollTop() / ($(document).height() - $(window).height())) * 100
            );
            
            scrollDepths.forEach(function(depth) {
                if (scrollPercent >= depth && trackedDepths.indexOf(depth) === -1) {
                    trackedDepths.push(depth);
                    
                    trackEvent('sac_scroll_depth', {
                        'depth': depth,
                        'page_type': getPageType(),
                        'timestamp': new Date().toISOString()
                    });
                }
            });
        });
    }
    
    function trackReadingTime() {
        if (!$('.sac-generated-content').length) return;
        
        var startTime = new Date();
        var tracked = false;
        
        $(window).on('beforeunload', function() {
            if (!tracked) {
                tracked = true;
                var readingTime = Math.round((new Date() - startTime) / 1000);
                
                trackEvent('sac_reading_time', {
                    'time_seconds': readingTime,
                    'page_type': getPageType(),
                    'timestamp': new Date().toISOString()
                });
            }
        });
        
        // Also track when user becomes inactive
        var inactivityTimer;
        
        $(document).on('mousemove keypress scroll', function() {
            clearTimeout(inactivityTimer);
            
            inactivityTimer = setTimeout(function() {
                if (!tracked) {
                    tracked = true;
                    var readingTime = Math.round((new Date() - startTime) / 1000);
                    
                    trackEvent('sac_reading_time', {
                        'time_seconds': readingTime,
                        'page_type': getPageType(),
                        'engagement_type': 'inactive',
                        'timestamp': new Date().toISOString()
                    });
                }
            }, 30000); // 30 seconds of inactivity
        });
    }
    
    function trackEvent(eventName, data) {
        // Google Analytics 4
        if (typeof gtag !== 'undefined') {
            gtag('event', eventName, data);
        }
        
        // Google Analytics Universal
        if (typeof ga !== 'undefined') {
            ga('send', 'event', 'SEO Auto Content', eventName, JSON.stringify(data));
        }
        
        // Facebook Pixel
        if (typeof fbq !== 'undefined') {
            fbq('trackCustom', eventName, data);
        }
        
        // Custom tracking (you can add your own analytics)
        if (window.customAnalytics && typeof window.customAnalytics.track === 'function') {
            window.customAnalytics.track(eventName, data);
        }
        
        // Console log for debugging (remove in production)
        if (window.location.hostname === 'localhost' || window.location.hostname.includes('dev')) {
            console.log('SAC Event:', eventName, data);
        }
    }
    
    function getPageType() {
        if ($('body').hasClass('single-product')) {
            return 'product';
        } else if ($('body').hasClass('single-post')) {
            return 'blog_post';
        } else if ($('body').hasClass('archive') || $('body').hasClass('category')) {
            return 'archive';
        } else {
            return 'other';
        }
    }
    
    // Accessibility enhancements
    function initAccessibility() {
        // Add ARIA labels to generated content
        $('.sac-generated-content').attr({
            'role': 'complementary',
            'aria-label': 'AI-generated product information'
        });
        
        $('.sac-read-more').attr({
            'aria-label': 'Read full article about this product',
            'role': 'button'
        });
        
        $('.sac-analysis-link').attr({
            'aria-label': 'View detailed product analysis',
            'role': 'button'
        });
        
        // Keyboard navigation support
        $('.sac-read-more, .sac-analysis-link').on('keydown', function(e) {
            if (e.keyCode === 13 || e.keyCode === 32) { // Enter or Space
                e.preventDefault();
                $(this)[0].click();
            }
        });
        
        // Focus management
        $('.sac-read-more, .sac-analysis-link').on('focus', function() {
            $(this).addClass('keyboard-focused');
        }).on('blur', function() {
            $(this).removeClass('keyboard-focused');
        });
        
        // High contrast mode detection
        if (window.matchMedia && window.matchMedia('(prefers-contrast: high)').matches) {
            $('body').addClass('high-contrast-mode');
        }
        
        // Reduced motion detection
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            $('body').addClass('reduced-motion');
            
            // Disable animations
            $('.sac-generated-content').css({
                'transition': 'none',
                'animation': 'none'
            });
        }
    }
    
    // Lazy loading for related products
    function initLazyLoading() {
        if ('IntersectionObserver' in window) {
            var imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            $('.related-product-item img[data-src]').each(function() {
                imageObserver.observe(this);
            });
        } else {
            // Fallback for older browsers
            $('.related-product-item img[data-src]').each(function() {
                this.src = this.dataset.src;
            });
        }
    }
    
    // Product tabs enhancement (WooCommerce integration)
    function enhanceProductTabs() {
        // Add click tracking to product tabs
        $('.woocommerce-tabs .tabs li a').on('click', function() {
            var tabName = $(this).attr('href').replace('#', '');
            
            if (tabName === 'tab-sac_analysis') {
                trackEvent('sac_analysis_tab_viewed', {
                    'source': 'product_tabs',
                    'timestamp': new Date().toISOString()
                });
            }
        });
        
        // Auto-scroll to analysis tab if hash is present
        if (window.location.hash === '#analysis') {
            setTimeout(function() {
                $('a[href="#tab-sac_analysis"]').trigger('click');
                $('html, body').animate({
                    scrollTop: $('.woocommerce-tabs').offset().top - 100
                }, 500);
            }, 500);
        }
    }
    
    // Error handling and fallbacks
    function initErrorHandling() {
        // Handle image loading errors
        $('.related-product-item img').on('error', function() {
            $(this).attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPk5vIEltYWdlPC90ZXh0Pjwvc3ZnPg==');
        });
        
        // Handle AJAX errors gracefully
        $(document).ajaxError(function(event, xhr, settings) {
            if (settings.url && settings.url.includes('sac_')) {
                console.warn('SAC AJAX Error:', xhr.status, xhr.statusText);
                
                // Show user-friendly message if needed
                if (xhr.status >= 500) {
                    showNotification('Service temporarily unavailable. Please try again later.', 'warning');
                }
            }
        });
    }
    
    // Simple notification system
    function showNotification(message, type) {
        type = type || 'info';
        
        var notification = $('<div class="sac-notification sac-notification-' + type + '">' + message + '</div>');
        
        $('body').append(notification);
        
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        setTimeout(function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 4000);
    }
    
    // Content performance optimization
    function optimizeContent() {
        // Preload critical resources
        if ($('.sac-read-more').length) {
            var link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = $('.sac-read-more').attr('href');
            document.head.appendChild(link);
        }
        
        // Optimize images
        $('.related-product-item img').each(function() {
            if (this.naturalWidth > 300) {
                $(this).css('max-width', '300px');
            }
        });
    }
    
    // Initialize everything
    init();
    enhanceProductTabs();
    initErrorHandling();
    optimizeContent();
    
    // Add notification styles
    var notificationStyles = `
        <style>
            .sac-notification {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 5px;
                color: white;
                font-weight: 600;
                z-index: 999999;
                transform: translateX(300px);
                transition: transform 0.3s ease;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                max-width: 300px;
            }
            
            .sac-notification.show {
                transform: translateX(0);
            }
            
            .sac-notification-info {
                background: linear-gradient(135deg, #17a2b8, #138496);
            }
            
            .sac-notification-warning {
                background: linear-gradient(135deg, #ffc107, #e0a800);
                color: #856404;
            }
            
            .sac-notification-success {
                background: linear-gradient(135deg, #28a745, #20c997);
            }
            
            .sac-notification-error {
                background: linear-gradient(135deg, #dc3545, #c82333);
            }
            
            .keyboard-focused {
                outline: 3px solid rgba(0, 124, 186, 0.5) !important;
                outline-offset: 2px !important;
            }
            
            .high-contrast-mode .sac-generated-content {
                border: 2px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
            }
            
            .reduced-motion * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        </style>
    `;
    
    $('head').append(notificationStyles);
    
    // Debug mode for development
    if (window.location.hostname === 'localhost' || window.location.search.includes('sac_debug=1')) {
        window.sacDebug = {
            trackEvent: trackEvent,
            getPageType: getPageType,
            isElementInViewport: isElementInViewport,
            showNotification: showNotification
        };
        
        console.log('SAC Debug mode enabled. Access functions via window.sacDebug');
    }
});