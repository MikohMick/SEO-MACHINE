/**
 * SEO Auto Content Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Tab functionality
    function initTabs() {
        $('.tab-button').on('click', function(e) {
            e.preventDefault();
            
            var $this = $(this);
            var tab = $this.data('tab');
            
            // Remove active class from all buttons and content
            $('.tab-button').removeClass('active');
            $('.tab-content').removeClass('active');
            
            // Add active class to clicked button and corresponding content
            $this.addClass('active');
            $('#' + tab + '-tab').addClass('active');
            
            // Store active tab in localStorage
            localStorage.setItem('sacActiveTab', tab);
        });
        
        // Restore active tab from localStorage
        var activeTab = localStorage.getItem('sacActiveTab');
        if (activeTab) {
            $('.tab-button[data-tab="' + activeTab + '"]').trigger('click');
        }
    }
    
    // Manual cron job execution
    $('.run-cron-job').click(function(e) {
        e.preventDefault();
        
        var button = $(this);
        var job = button.data('job');
        
        button.prop('disabled', true)
              .find('.dashicons-update')
              .addClass('spin');
        
        $.post(ajaxurl, {
            action: 'sac_run_cron_manually',
            job_type: job,
            nonce: sacAjax.nonce
        }, function(response) {
            if (response.success) {
                button.after('<span class="success-message">✓ ' + response.data + '</span>');
                setTimeout(function() {
                    $('.success-message').fadeOut(function() {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                button.after('<span class="error-message">✗ ' + response.data + '</span>');
                setTimeout(function() {
                    $('.error-message').fadeOut(function() {
                        $(this).remove();
                    });
                }, 3000);
            }
        }).always(function() {
            button.prop('disabled', false)
                  .find('.dashicons-update')
                  .removeClass('spin');
        });
    });
    
    // Process remaining products
    $('#process-products').click(function(e) {
        e.preventDefault();
        
        var button = $(this);
        button.prop('disabled', true)
              .text('Processing...');
        
        $.post(ajaxurl, {
            action: 'sac_process_products',
            nonce: sacAjax.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.data);
                button.prop('disabled', false)
                      .text('Process Remaining Products');
            }
        });
    });
    
    // Manual content generation
    function initContentGeneration() {
        $('.generate-content-manual').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var keywordId = $button.data('keyword-id');
            var originalText = $button.text();
            
            // Confirm action
            if (!confirm('Generate content for this keyword? This will use API credits.')) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true)
                   .addClass('loading')
                   .text('Generating...');
            
            $.ajax({
                url: sacAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sac_manual_content_generation',
                    keyword_id: keywordId,
                    nonce: sacAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $button.removeClass('loading')
                               .addClass('success')
                               .text('✓ Generated!');
                        
                        showNotification('Content generated successfully!', 'success');
                        
                        // Reload page after 2 seconds
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        $button.removeClass('loading')
                               .addClass('error')
                               .text('✗ Failed');
                        
                        showNotification('Error: ' + response.data, 'error');
                        
                        setTimeout(function() {
                            $button.removeClass('error')
                                   .prop('disabled', false)
                                   .text(originalText);
                        }, 3000);
                    }
                },
                error: function() {
                    $button.removeClass('loading')
                           .addClass('error')
                           .text('✗ Error');
                    
                    showNotification('Network error occurred', 'error');
                    
                    setTimeout(function() {
                        $button.removeClass('error')
                               .prop('disabled', false)
                               .text(originalText);
                    }, 3000);
                }
            });
        });
    }
    
    // Keyword extraction tester
    function initExtractionTester() {
        $('#test-extraction').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var title = $('#test-title').val().trim();
            
            if (!title) {
                showNotification('Please enter a product title', 'warning');
                $('#test-title').focus();
                return;
            }
            
            var originalText = $button.text();
            
            $button.prop('disabled', true)
                   .addClass('loading')
                   .text('Testing...');
            
            $.ajax({
                url: sacAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sac_test_extraction',
                    title: title,
                    nonce: sacAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        $('#result-original').text(data.original_title);
                        $('#result-cleaned').text(data.cleaned_title);
                        $('#result-keyword').text(data.extracted_keyword || 'No keyword extracted');
                        
                        $('#extraction-results').slideDown();
                        
                        // Highlight the keyword if extracted
                        if (data.extracted_keyword) {
                            $('#result-keyword').addClass('extracted-success');
                        } else {
                            $('#result-keyword').addClass('extracted-warning');
                        }
                        
                        showNotification('Extraction test completed', 'success');
                    } else {
                        showNotification('Error: ' + response.data, 'error');
                    }
                    
                    $button.removeClass('loading')
                           .prop('disabled', false)
                           .text(originalText);
                },
                error: function() {
                    showNotification('Network error occurred', 'error');
                    
                    $button.removeClass('loading')
                           .prop('disabled', false)
                           .text(originalText);
                }
            });
        });
        
        // Allow Enter key to trigger test
        $('#test-title').on('keypress', function(e) {
            if (e.which === 13) {
                $('#test-extraction').trigger('click');
            }
        });
    }
    
    // API testing
    function initApiTesting() {
        $('#test-apis').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.prop('disabled', true)
                   .addClass('loading')
                   .text('Testing APIs...');
            
            $.ajax({
                url: sacAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sac_test_apis',
                    nonce: sacAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var results = '<div class="api-test-results">';
                        
                        // Keyword API result
                        if (response.data.keyword.success) {
                            results += '<div class="api-test-result api-test-success">✓ Keyword API: Connected successfully</div>';
                        } else {
                            results += '<div class="api-test-result api-test-error">✗ Keyword API: ' + response.data.keyword.error + '</div>';
                        }
                        
                        // Content API result
                        if (response.data.content.success) {
                            results += '<div class="api-test-result api-test-success">✓ Content API: Connected successfully</div>';
                        } else {
                            results += '<div class="api-test-result api-test-error">✗ Content API: ' + response.data.content.error + '</div>';
                        }
                        
                        results += '</div>';
                        
                        $('#api-status-results').html(results);
                        
                        var successCount = (response.data.keyword.success ? 1 : 0) + (response.data.content.success ? 1 : 0);
                        if (successCount === 2) {
                            showNotification('All APIs connected successfully!', 'success');
                        } else if (successCount === 1) {
                            showNotification('Some APIs failed to connect', 'warning');
                        } else {
                            showNotification('All API connections failed', 'error');
                        }
                    } else {
                        showNotification('API test failed: ' + response.data, 'error');
                    }
                    
                    $button.removeClass('loading')
                           .prop('disabled', false)
                           .text(originalText);
                },
                error: function() {
                    showNotification('Network error during API test', 'error');
                    
                    $button.removeClass('loading')
                           .prop('disabled', false)
                           .text(originalText);
                }
            });
        });
    }
    
    // Debug tools
    function initDebugTools() {
        $('#export-logs').on('click', function(e) {
            e.preventDefault();
            
            var exportUrl = sacAjax.ajaxurl + '?action=sac_export_logs&nonce=' + sacAjax.nonce;
            window.open(exportUrl, '_blank');
            
            showNotification('Debug logs export started', 'info');
        });
        
        $('#clear-cache').on('click', function(e) {
            e.preventDefault();
            
            if (confirm('Clear plugin cache? This will reset temporary data but won\'t affect your content.')) {
                var $button = $(this);
                var originalText = $button.text();
                
                $button.prop('disabled', true).text('Clearing...');
                
                $.ajax({
                    url: sacAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sac_clear_cache',
                        nonce: sacAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification('Cache cleared successfully', 'success');
                        } else {
                            showNotification('Error clearing cache: ' + response.data, 'error');
                        }
                        
                        $button.prop('disabled', false).text(originalText);
                    },
                    error: function() {
                        showNotification('Network error occurred', 'error');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            }
        });
        
        $('#reset-crons').on('click', function(e) {
            e.preventDefault();
            
            if (confirm('Reset all cron jobs? This will reschedule all automated tasks.')) {
                var $button = $(this);
                var originalText = $button.text();
                
                $button.prop('disabled', true).text('Resetting...');
                
                $.ajax({
                    url: sacAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sac_reset_crons',
                        nonce: sacAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification('Cron jobs reset successfully', 'success');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showNotification('Error resetting crons: ' + response.data, 'error');
                        }
                        
                        $button.prop('disabled', false).text(originalText);
                    },
                    error: function() {
                        showNotification('Network error occurred', 'error');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            }
        });
    }
    
    // Duplicate resolution
    function initDuplicateControls() {
        $('.resolve-duplicate').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var duplicateId = $button.data('duplicate-id');
            var originalText = $button.text();
            
            if (confirm('Mark this duplicate as resolved?')) {
                $button.prop('disabled', true).text('Resolving...');
                
                $.ajax({
                    url: sacAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sac_resolve_duplicate',
                        duplicate_id: duplicateId,
                        nonce: sacAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $button.closest('tr').fadeOut();
                            showNotification('Duplicate marked as resolved', 'success');
                        } else {
                            showNotification('Error: ' + response.data, 'error');
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        showNotification('Network error occurred', 'error');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            }
        });
    }
    
    // Real-time updates
    function initRealTimeUpdates() {
        // Update API usage counters every 30 seconds
        setInterval(function() {
            updateApiUsage();
        }, 30000);
        
        // Update health status every 60 seconds
        setInterval(function() {
            updateHealthStatus();
        }, 60000);
    }
    
    function updateApiUsage() {
        $.ajax({
            url: sacAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'sac_get_api_usage',
                nonce: sacAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update API usage displays
                    $('.api-usage-keyword').text(data.keyword.used_today);
                    $('.api-usage-content').text(data.content.used_today);
                    $('.api-remaining-keyword').text(data.keyword.remaining_today);
                    $('.api-remaining-content').text(data.content.remaining_today);
                    
                    // Update progress bars if they exist
                    var keywordPercentage = (data.keyword.used_today / data.keyword.daily_limit) * 100;
                    var contentPercentage = (data.content.used_today / data.content.daily_limit) * 100;
                    
                    $('.keyword-usage-bar').css('width', keywordPercentage + '%');
                    $('.content-usage-bar').css('width', contentPercentage + '%');
                }
            }
        });
    }
    
    function updateHealthStatus() {
        $.ajax({
            url: sacAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'sac_get_health_status',
                nonce: sacAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var health = response.data;
                    var $healthStatus = $('.sac-health-status');
                    
                    // Update health status class
                    $healthStatus.removeClass('healthy warning unhealthy')
                                .addClass(health.status);
                    
                    // Update health status text
                    $healthStatus.find('h2').text('System Health: ' + health.status.charAt(0).toUpperCase() + health.status.slice(1));
                    
                    // Flash if status changed to unhealthy
                    if (health.status === 'unhealthy') {
                        $healthStatus.addClass('flash-warning');
                        setTimeout(function() {
                            $healthStatus.removeClass('flash-warning');
                        }, 2000);
                    }
                }
            }
        });
    }
    
    // Show notification
    function showNotification(message, type) {
        type = type || 'info';
        
        // Remove existing notifications
        $('.sac-notification').remove();
        
        var notification = $('<div class="sac-notification sac-notification-' + type + '">' +
            '<span class="notification-text">' + message + '</span>' +
            '<button class="notification-close">&times;</button>' +
            '</div>');
        
        $('body').append(notification);
        
        // Show notification
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            hideNotification(notification);
        }, 5000);
        
        // Close button
        notification.find('.notification-close').on('click', function() {
            hideNotification(notification);
        });
    }
    
    function hideNotification($notification) {
        $notification.removeClass('show');
        setTimeout(function() {
            $notification.remove();
        }, 300);
    }
    
    // Form validation
    function initFormValidation() {
        // Settings form validation
        $('form').on('submit', function(e) {
            var $form = $(this);
            var isValid = true;
            
            // Validate API key
            var apiKey = $form.find('input[name="api_key"]').val();
            if (apiKey && apiKey.length < 10) {
                showNotification('API key seems too short. Please check your key.', 'warning');
                isValid = false;
            }
            
            // Validate surge threshold
            var threshold = parseInt($form.find('input[name="surge_threshold"]').val());
            if (threshold && (threshold < 5 || threshold > 100)) {
                showNotification('Surge threshold must be between 5 and 100', 'error');
                isValid = false;
            }
            
            // Validate daily limit
            var dailyLimit = parseInt($form.find('input[name="daily_limit"]').val());
            if (dailyLimit && (dailyLimit < 1 || dailyLimit > 50)) {
                showNotification('Daily limit must be between 1 and 50', 'error');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
    }
    
    // Tooltip functionality
    function initTooltips() {
        // Add tooltips to elements with data-tooltip attribute
        $('[data-tooltip]').each(function() {
            var $element = $(this);
            var tooltipText = $element.data('tooltip');
            
            $element.on('mouseenter', function(e) {
                var tooltip = $('<div class="sac-tooltip">' + tooltipText + '</div>');
                $('body').append(tooltip);
                
                var offset = $element.offset();
                tooltip.css({
                    top: offset.top - tooltip.outerHeight() - 10,
                    left: offset.left + ($element.outerWidth() / 2) - (tooltip.outerWidth() / 2)
                });
                
                tooltip.fadeIn(200);
            }).on('mouseleave', function() {
                $('.sac-tooltip').fadeOut(200, function() {
                    $(this).remove();
                });
            });
        });
    }
    
    // Search and filter functionality
    function initSearchFilters() {
        // Keywords table search
        $('#keywords-search').on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();
            
            $('.wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var text = $row.text().toLowerCase();
                
                if (text.indexOf(searchTerm) === -1) {
                    $row.hide();
                } else {
                    $row.show();
                }
            });
        });
        
        // Filter by surge status
        $('#surge-filter').on('change', function() {
            var filter = $(this).val();
            
            $('.wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var surgeText = $row.find('.surge-percentage').text();
                var surgeValue = parseFloat(surgeText.replace(/[^0-9.-]/g, ''));
                
                var show = true;
                
                if (filter === 'surge' && surgeValue < 25) {
                    show = false;
                } else if (filter === 'no-surge' && surgeValue >= 25) {
                    show = false;
                }
                
                if (show) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        });
    }
    
    // Progress animation
    function animateProgressBars() {
        $('.progress-fill').each(function() {
            var $bar = $(this);
            var width = $bar.css('width');
            
            $bar.css('width', '0%');
            
            setTimeout(function() {
                $bar.css('width', width);
            }, 500);
        });
    }
    
    // Chart functionality (if Chart.js is available)
    function initCharts() {
        if (typeof Chart !== 'undefined') {
            // API Usage Chart
            var apiCtx = document.getElementById('api-usage-chart');
            if (apiCtx) {
                new Chart(apiCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Keyword API', 'Content API', 'Remaining'],
                        datasets: [{
                            data: [
                                $('.api-usage-keyword').text() || 0,
                                $('.api-usage-content').text() || 0,
                                $('.api-remaining-keyword').text() || 0
                            ],
                            backgroundColor: ['#007cba', '#28a745', '#f8f9fa']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
            
            // Content Generation Trend Chart
            var trendCtx = document.getElementById('content-trend-chart');
            if (trendCtx) {
                // This would be populated with actual data from the server
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: [], // Days
                        datasets: [{
                            label: 'Content Generated',
                            data: [], // Content counts
                            borderColor: '#007cba',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        }
    }
    
    // Keyboard shortcuts
    function initKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + R: Refresh current section
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 82) {
                e.preventDefault();
                window.location.reload();
            }
            
            // Ctrl/Cmd + T: Test APIs
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 84) {
                e.preventDefault();
                $('#test-apis').trigger('click');
            }
            
            // Escape: Close notifications
            if (e.keyCode === 27) {
                $('.sac-notification').each(function() {
                    hideNotification($(this));
                });
            }
        });
    }
    
    // Auto-save functionality for settings
    function initAutoSave() {
        var saveTimer;
        
        $('input, select, textarea').on('input change', function() {
            var $input = $(this);
            
            // Clear existing timer
            clearTimeout(saveTimer);
            
            // Set new timer
            saveTimer = setTimeout(function() {
                // Auto-save logic here
                $input.addClass('auto-saved');
                setTimeout(function() {
                    $input.removeClass('auto-saved');
                }, 2000);
            }, 3000);
        });
    }
    
    // Initialize all functionality
    function init() {
        initTabs();
        initContentGeneration();
        initExtractionTester();
        initApiTesting();
        initDebugTools();
        initDuplicateControls();
        initRealTimeUpdates();
        initFormValidation();
        initTooltips();
        initSearchFilters();
        initKeyboardShortcuts();
        initAutoSave();
        
        // Animate progress bars on load
        setTimeout(animateProgressBars, 500);
        
        // Initialize charts if available
        setTimeout(initCharts, 1000);
        
        // Show welcome message on first load
        if (localStorage.getItem('sacFirstLoad') !== 'false') {
            setTimeout(function() {
                showNotification('Welcome to SEO Auto Content! Check the Dashboard for system status.', 'info');
                localStorage.setItem('sacFirstLoad', 'false');
            }, 1000);
        }
    }
    
    // Run initialization
    init();
    
    // Add notification styles dynamically
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
                transform: translateX(400px);
                transition: transform 0.3s ease;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                display: flex;
                align-items: center;
                max-width: 350px;
            }
            
            .sac-notification.show {
                transform: translateX(0);
            }
            
            .sac-notification-success {
                background: linear-gradient(135deg, #28a745, #20c997);
            }
            
            .sac-notification-error {
                background: linear-gradient(135deg, #dc3545, #c82333);
            }
            
            .sac-notification-warning {
                background: linear-gradient(135deg, #ffc107, #e0a800);
                color: #856404;
            }
            
            .sac-notification-info {
                background: linear-gradient(135deg, #17a2b8, #138496);
            }
            
            .notification-close {
                background: none;
                border: none;
                color: inherit;
                font-size: 18px;
                margin-left: 10px;
                cursor: pointer;
                opacity: 0.8;
            }
            
            .notification-close:hover {
                opacity: 1;
            }
            
            .sac-tooltip {
                position: absolute;
                background: #333;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 999999;
                white-space: nowrap;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            }
            
            .sac-tooltip:before {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                margin-left: -5px;
                border: 5px solid transparent;
                border-top-color: #333;
            }
            
            .flash-warning {
                animation: flash-red 0.5s ease-in-out 3;
            }
            
            @keyframes flash-red {
                0%, 100% { background-color: inherit; }
                50% { background-color: rgba(220, 53, 69, 0.2); }
            }
            
            .auto-saved {
                border-color: #28a745 !important;
                box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2) !important;
            }
            
            .extracted-success {
                background: rgba(40, 167, 69, 0.1);
                color: #155724;
                padding: 5px;
                border-radius: 3px;
            }
            
            .extracted-warning {
                background: rgba(220, 53, 69, 0.1);
                color: #721c24;
                padding: 5px;
                border-radius: 3px;
            }
        </style>
    `;
    
    $('head').append(notificationStyles);
});