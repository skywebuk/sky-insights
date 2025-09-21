<?php
/**
 * Main Dashboard Class
 * Fixed version with proper currency handling, error management, and optimizations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyWebInsightsDashboard {
    
    private static $instance = null;
    private $currency_symbol = null;
    private $debug_mode = false;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize currency symbol
        $this->currency_symbol = get_woocommerce_currency_symbol();
        
        // Check debug mode
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        
        // Hook into WordPress
        add_action('admin_menu', array($this, 'modify_admin_menu'), 999);
        add_action('admin_init', array($this, 'redirect_dashboard'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('gettext', array($this, 'change_woocommerce_labels'), 20, 3);
        add_filter('ngettext', array($this, 'change_woocommerce_labels_plural'), 20, 5);
        
        // Schedule cache preloading
        add_action('admin_init', array($this, 'schedule_cache_preload'));
        
        // Handle index status display
        add_action('admin_notices', array($this, 'display_index_status'));
        
        // Add dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
        
        // Initialize database tables if needed
        add_action('admin_init', array($this, 'maybe_create_tables'));
    }
    
    /**
     * Custom logging function
     */
    private function log($message, $type = 'info') {
        if (!$this->debug_mode) {
            return;
        }
        
        $log_message = sprintf('[Sky Insights][%s] %s', strtoupper($type), $message);
        error_log($log_message);
    }
    
    /**
     * Check and create database tables if needed
     */
    public function maybe_create_tables() {
        // Check if tables need to be created
        $db_version = get_option('sky_insights_db_version', '0');
        
        if (version_compare($db_version, SKY_INSIGHTS_DB_VERSION, '<')) {
            $this->log('Database tables need updating from version ' . $db_version . ' to ' . SKY_INSIGHTS_DB_VERSION);
            
            // Run table creation/update
            if (function_exists('sky_insights_create_all_tables')) {
                sky_insights_create_all_tables();
            }
            
            update_option('sky_insights_db_version', SKY_INSIGHTS_DB_VERSION);
        }
    }
    
    public function modify_admin_menu() {
        // Check if user has proper permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('view_woocommerce_reports')) {
            return;
        }
        
        // Add our custom dashboard menu
        add_menu_page(
            __('Insights', 'sky-insights'),
            __('Insights', 'sky-insights'),
            'manage_woocommerce',
            'sky-insights-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-chart-area',
            2
        );
        
        // Add submenu items
        add_submenu_page(
            'sky-insights-dashboard',
            __('Dashboard', 'sky-insights'),
            __('Dashboard', 'sky-insights'),
            'manage_woocommerce',
            'sky-insights-dashboard',
            array($this, 'render_dashboard')
        );
        
        // Only remove default dashboard if user explicitly has permission
        if (current_user_can('manage_options') && apply_filters('sky_insights_remove_default_dashboard', true)) {
            remove_menu_page('index.php');
        }
    }
    
    public function redirect_dashboard() {
        global $pagenow;
        
        // Only redirect if enabled via filter
        $enable_redirect = apply_filters('sky_insights_enable_dashboard_redirect', true);
        
        if ($enable_redirect && $pagenow == 'index.php' && !isset($_GET['page']) && current_user_can('manage_woocommerce')) {
            wp_redirect(admin_url('admin.php?page=sky-insights-dashboard'));
            exit;
        }
    }
    
    public function enqueue_assets($hook) {
        // Only load on our pages
        if (strpos($hook, 'sky-insights') === false) {
            return;
        }
        
        // Get version for cache busting
        $version = defined('WP_DEBUG') && WP_DEBUG ? time() : SKY_INSIGHTS_VERSION;
        
        // Enqueue custom styles
        wp_enqueue_style(
            'sky-insights-style', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/css/dashboard.css', 
            array(), 
            $version
        );
        
        // Only load scripts on dashboard page
        if ($hook === 'toplevel_page_sky-insights-dashboard') {
            $this->enqueue_dashboard_scripts($version);
        }
    }
    
    private function enqueue_dashboard_scripts($version) {
        // Dependencies array for better management
        $deps = array('jquery');
        
        // Enqueue utility scripts
        wp_enqueue_script(
            'sky-insights-utils', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/dashboard-utils.js', 
            $deps, 
            $version, 
            true
        );
        
        $deps[] = 'sky-insights-utils';
        
        wp_enqueue_script(
            'sky-insights-charts', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/dashboard-charts.js', 
            $deps, 
            $version, 
            true
        );
        
        $deps[] = 'sky-insights-charts';
        
        // Enqueue modular scripts
        wp_enqueue_script(
            'sky-insights-core', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/modules/dashboard-core.js', 
            array('jquery'), 
            $version, 
            true
        );
        
        wp_enqueue_script(
            'sky-insights-ui', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/modules/dashboard-ui.js', 
            array('jquery', 'sky-insights-core', 'sky-insights-utils'), 
            $version, 
            true
        );
        
        wp_enqueue_script(
            'sky-insights-tabs-simple', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/modules/dashboard-tabs-simple.js', 
            $deps, 
            $version, 
            true
        );
        
        wp_enqueue_script(
            'sky-insights-tabs-complex', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/modules/dashboard-tabs-complex.js', 
            $deps, 
            $version, 
            true
        );
        
        // Main dashboard script
        wp_enqueue_script(
            'sky-insights-script', 
            SKY_INSIGHTS_PLUGIN_URL . 'assets/js/dashboard.js', 
            array(
                'jquery', 
                'sky-insights-utils', 
                'sky-insights-charts', 
                'sky-insights-core',
                'sky-insights-ui',
                'sky-insights-tabs-simple',
                'sky-insights-tabs-complex'
            ), 
            $version, 
            true
        );
        
        // Localize script with all necessary data - FIXED: Properly escaped
        $localization_data = $this->get_localization_data();
        wp_localize_script('sky-insights-script', 'skyInsights', $localization_data);
        
        // Add inline initialization script
        $this->add_inline_scripts();
    }
    
    /**
     * Get all localization data in one place
     */
    private function get_localization_data() {
        // Get WooCommerce settings
        $wc_settings = array(
            'currency' => get_woocommerce_currency_symbol(),
            'currency_position' => get_option('woocommerce_currency_pos', 'left'),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'decimals' => wc_get_price_decimals()
        );
        
        // Check subscriptions status
        $subscriptions_active = class_exists('WC_Subscriptions');
        $subscriptions_version = $subscriptions_active && isset(WC_Subscriptions::$version) ? WC_Subscriptions::$version : null;
        
        // Get user locale for date formatting
        $user_locale = get_user_locale();
        
        return array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sky_insights_nonce'),
            'pluginUrl' => SKY_INSIGHTS_PLUGIN_URL,
            'adminUrl' => admin_url(),
            'siteUrl' => site_url(),
            'currency' => $wc_settings['currency'],
            'currencyPosition' => $wc_settings['currency_position'],
            'thousandSeparator' => $wc_settings['thousand_separator'],
            'decimalSeparator' => $wc_settings['decimal_separator'],
            'decimals' => $wc_settings['decimals'],
            'dateFormat' => get_option('date_format', 'Y-m-d'),
            'timeFormat' => get_option('time_format', 'H:i'),
            'startOfWeek' => get_option('start_of_week', 1),
            'timezone' => wp_timezone_string(),
            'locale' => $user_locale,
            'subscriptionsActive' => $subscriptions_active,
            'subscriptionsVersion' => $subscriptions_version,
            'maxDateRange' => apply_filters('sky_insights_max_date_range', 730), // 2 years default
            'autoRefreshInterval' => apply_filters('sky_insights_auto_refresh_interval', 300000), // 5 minutes default
            'enableAutoRefresh' => apply_filters('sky_insights_enable_auto_refresh', true),
            'debug' => $this->debug_mode,
            'i18n' => $this->get_i18n_strings()
        );
    }
    
    /**
     * Get all translatable strings
     */
    private function get_i18n_strings() {
        return array(
            'raised' => __('Raised', 'sky-insights'),
            'donations' => __('donations', 'sky-insights'),
            'donation' => __('donation', 'sky-insights'),
            'firstInstallments' => __('First Installments', 'sky-insights'),
            'recurringDonations' => __('Recurring Donations', 'sky-insights'),
            'oneTimeDonations' => __('One-time Donations', 'sky-insights'),
            'today' => __('Today', 'sky-insights'),
            'yesterday' => __('Yesterday', 'sky-insights'),
            'last7Days' => __('Last 7 days', 'sky-insights'),
            'last14Days' => __('Last 14 days', 'sky-insights'),
            'last30Days' => __('Last 30 days', 'sky-insights'),
            'thisWeek' => __('This week', 'sky-insights'),
            'thisMonth' => __('This month', 'sky-insights'),
            'thisYear' => __('This year', 'sky-insights'),
            'lastWeek' => __('Last week', 'sky-insights'),
            'lastMonth' => __('Last month', 'sky-insights'),
            'lastYear' => __('Last year', 'sky-insights'),
            'customRange' => __('Custom range', 'sky-insights'),
            'daily' => __('Daily', 'sky-insights'),
            'weekly' => __('Weekly', 'sky-insights'),
            'loading' => __('Loading...', 'sky-insights'),
            'error' => __('Error', 'sky-insights'),
            'noData' => __('No data available', 'sky-insights'),
            'noDataForPeriod' => __('No data available for this period', 'sky-insights'),
            'tryDifferentRange' => __('Try selecting a different date range', 'sky-insights'),
            'exportSuccess' => __('Export completed successfully', 'sky-insights'),
            'exportError' => __('Export failed. Please try again.', 'sky-insights'),
            'confirmLargeExport' => __('This export may take some time. Continue?', 'sky-insights'),
            'subscriptionsRequired' => __('WooCommerce Subscriptions is required for this feature', 'sky-insights'),
            'selectDates' => __('Please select both start and end dates', 'sky-insights'),
            'invalidDateRange' => __('Start date must be before end date', 'sky-insights'),
            'futureDate' => __('End date cannot be in the future', 'sky-insights'),
            'rangeTooLarge' => __('Date range cannot exceed 2 years', 'sky-insights'),
            'sessionExpired' => __('Your session has expired. Please refresh the page.', 'sky-insights'),
            'networkError' => __('Network error. Please check your connection.', 'sky-insights'),
            'serverError' => __('Server error. Please try again later.', 'sky-insights'),
            'permissionDenied' => __('You do not have permission to perform this action.', 'sky-insights')
        );
    }
    
    /**
     * Add inline JavaScript initialization
     */
    private function add_inline_scripts() {
        $inline_script = '
            jQuery(document).ready(function($) {
                // Initialize tooltips with proper cleanup
                var tooltipTimeout;
                
                $(document).on("mouseenter", "[data-tooltip]", function() {
                    var $this = $(this);
                    var tooltip = $this.attr("data-tooltip");
                    
                    if (tooltip) {
                        // Clear any existing timeout
                        clearTimeout(tooltipTimeout);
                        
                        // Remove any existing tooltips
                        $(".sky-tooltip").remove();
                        
                        // Create new tooltip
                        var $tooltip = $("<div>").addClass("sky-tooltip").text(tooltip);
                        $("body").append($tooltip);
                        
                        // Position tooltip
                        var offset = $this.offset();
                        var tooltipWidth = $tooltip.outerWidth();
                        var tooltipHeight = $tooltip.outerHeight();
                        
                        $tooltip.css({
                            top: offset.top - tooltipHeight - 5,
                            left: offset.left + ($this.outerWidth() / 2) - (tooltipWidth / 2),
                            opacity: 1
                        });
                    }
                });
                
                $(document).on("mouseleave", "[data-tooltip]", function() {
                    // Delay removal for smooth transition
                    tooltipTimeout = setTimeout(function() {
                        $(".sky-tooltip").fadeOut(200, function() {
                            $(this).remove();
                        });
                    }, 100);
                });
                
                // Debug mode indicator
                if (skyInsights.debug) {
                    console.log("Sky Insights Debug Mode: ON");
                    $("body").addClass("sky-insights-debug");
                }
                
                // Check WooCommerce dependency
                if (typeof wc_add_to_cart_params === "undefined") {
                    console.warn("Sky Insights: WooCommerce JavaScript params not found");
                }
            });
        ';
        
        wp_add_inline_script('sky-insights-script', $inline_script, 'after');
    }
    
    public function render_dashboard() {
        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('view_woocommerce_reports')) {
            wp_die(
                '<div class="error"><p>' . 
                esc_html__('You do not have sufficient permissions to access this page.', 'sky-insights') . 
                '</p></div>'
            );
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            $this->render_woocommerce_missing();
            return;
        }
        
        // Check WooCommerce version
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '3.0', '<')) {
            $this->render_woocommerce_outdated();
            return;
        }
        
        // Include dashboard template with security check
        $template_path = SKY_INSIGHTS_PLUGIN_DIR . 'templates/dashboard.php';
        
        // Validate template path - FIXED: Proper path validation
        $real_template_path = realpath($template_path);
        $real_plugin_dir = realpath(SKY_INSIGHTS_PLUGIN_DIR);
        
        if (!$real_template_path || !$real_plugin_dir || strpos($real_template_path, $real_plugin_dir) !== 0) {
            $this->log('Invalid dashboard template path: ' . $template_path, 'error');
            wp_die('Invalid template path');
        }
        
        if (file_exists($real_template_path)) {
            include $real_template_path;
        } else {
            $this->log('Dashboard template not found: ' . $template_path, 'error');
            echo '<div class="error"><p>' . esc_html__('Dashboard template not found.', 'sky-insights') . '</p></div>';
        }
    }
    
    /**
     * Render WooCommerce missing notice
     */
    private function render_woocommerce_missing() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sky Insights', 'sky-insights'); ?></h1>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('WooCommerce Required', 'sky-insights'); ?></strong><br>
                    <?php esc_html_e('Sky Insights Dashboard requires WooCommerce to be installed and activated.', 'sky-insights'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="button button-primary">
                        <?php esc_html_e('Install WooCommerce', 'sky-insights'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render WooCommerce outdated notice
     */
    private function render_woocommerce_outdated() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sky Insights', 'sky-insights'); ?></h1>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('WooCommerce Update Required', 'sky-insights'); ?></strong><br>
                    <?php 
                    printf(
                        esc_html__('Sky Insights Dashboard requires WooCommerce version 3.0 or higher. You are currently running version %s.', 'sky-insights'),
                        esc_html(WC_VERSION)
                    ); 
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-primary">
                        <?php esc_html_e('Update WooCommerce', 'sky-insights'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function change_woocommerce_labels($translated_text, $text, $domain) {
        // Only change labels if enabled
        if (!apply_filters('sky_insights_change_wc_labels', true)) {
            return $translated_text;
        }
        
        if ($domain === 'woocommerce' && is_admin() && current_user_can('manage_woocommerce')) {
            $label_map = array(
                'Product' => __('Appeal', 'sky-insights'),
                'Products' => __('Appeals', 'sky-insights'),
                'product' => __('appeal', 'sky-insights'),
                'products' => __('appeals', 'sky-insights'),
                'Order' => __('Donation', 'sky-insights'),
                'Orders' => __('Donations', 'sky-insights'),
                'order' => __('donation', 'sky-insights'),
                'orders' => __('donations', 'sky-insights'),
                'Customer' => __('Donor', 'sky-insights'),
                'Customers' => __('Donors', 'sky-insights'),
                'customer' => __('donor', 'sky-insights'),
                'customers' => __('donors', 'sky-insights')
            );
            
            if (isset($label_map[$text])) {
                return $label_map[$text];
            }
        }
        
        return $translated_text;
    }
    
    public function change_woocommerce_labels_plural($translated_text, $single, $plural, $number, $domain) {
        if (!apply_filters('sky_insights_change_wc_labels', true)) {
            return $translated_text;
        }
        
        if ($domain === 'woocommerce' && is_admin() && current_user_can('manage_woocommerce')) {
            // Product variations
            if (strpos($single, 'Product') !== false || strpos($plural, 'Products') !== false) {
                $translated_text = _n('Appeal', 'Appeals', $number, 'sky-insights');
            } elseif (strpos($single, 'product') !== false || strpos($plural, 'products') !== false) {
                $translated_text = _n('appeal', 'appeals', $number, 'sky-insights');
            } 
            // Order variations
            elseif (strpos($single, 'Order') !== false || strpos($plural, 'Orders') !== false) {
                $translated_text = _n('Donation', 'Donations', $number, 'sky-insights');
            } elseif (strpos($single, 'order') !== false || strpos($plural, 'orders') !== false) {
                $translated_text = _n('donation', 'donations', $number, 'sky-insights');
            } 
            // Customer variations
            elseif (strpos($single, 'Customer') !== false || strpos($plural, 'Customers') !== false) {
                $translated_text = _n('Donor', 'Donors', $number, 'sky-insights');
            } elseif (strpos($single, 'customer') !== false || strpos($plural, 'customers') !== false) {
                $translated_text = _n('donor', 'donors', $number, 'sky-insights');
            }
        }
        
        return $translated_text;
    }
    
    public function schedule_cache_preload() {
        if (!wp_next_scheduled('sky_insights_preload_cache')) {
            $schedule_time = apply_filters('sky_insights_cache_schedule', 'hourly');
            wp_schedule_event(time(), $schedule_time, 'sky_insights_preload_cache');
            $this->log('Cache preload scheduled: ' . $schedule_time);
        }
    }
    
    public function display_index_status() {
        // Only show on Sky Insights pages to admins
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sky-insights') === false || !current_user_can('manage_options')) {
            return;
        }
        
        // Check if we should display detailed index status
        if (isset($_GET['show_index_status'])) {
            $this->display_detailed_index_status();
        }
        
        // Check for activation notice
        if (get_transient('sky_insights_just_activated')) {
            delete_transient('sky_insights_just_activated');
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Sky Insights Dashboard activated successfully!', 'sky-insights'); ?></strong><br>
                    <?php esc_html_e('Thank you for installing Sky Insights. Your analytics dashboard is ready to use.', 'sky-insights'); ?>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Display detailed index status
     */
    private function display_detailed_index_status() {
        $index_status = get_option('sky_insights_index_status', array());
        
        if (empty($index_status)) {
            return;
        }
        ?>
        <div class="notice notice-info">
            <h3><?php esc_html_e('Sky Insights Database Index Status', 'sky-insights'); ?></h3>
            <p>
                <strong><?php esc_html_e('Last checked:', 'sky-insights'); ?></strong> 
                <?php echo esc_html($index_status['checked'] ?? __('Never', 'sky-insights')); ?>
            </p>
            
            <?php if (!empty($index_status['created'])): ?>
                <p><strong><?php esc_html_e('Successfully created indexes:', 'sky-insights'); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ($index_status['created'] as $index): ?>
                        <li><?php echo esc_html($index); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <?php if (!empty($index_status['failed'])): ?>
                <p><strong style="color: #d63638;"><?php esc_html_e('Failed indexes:', 'sky-insights'); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ($index_status['failed'] as $failure): ?>
                        <li>
                            <strong><?php echo esc_html($failure['index']); ?>:</strong> 
                            <?php echo esc_html($failure['reason']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p><em><?php esc_html_e('Contact your hosting provider if indexes cannot be created. This may affect performance.', 'sky-insights'); ?></em></p>
            <?php endif; ?>
            
            <p>
                <a href="<?php echo esc_url(remove_query_arg('show_index_status')); ?>" class="button">
                    <?php esc_html_e('Hide Details', 'sky-insights'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    public function add_dashboard_widgets() {
        // Add a quick stats widget to WordPress dashboard
        if (current_user_can('manage_woocommerce') || current_user_can('view_woocommerce_reports')) {
            wp_add_dashboard_widget(
                'sky_insights_quick_stats',
                __('Sky Insights - Quick Stats', 'sky-insights'),
                array($this, 'render_dashboard_widget'),
                null,
                null,
                'normal',
                'high'
            );
        }
    }
    
    public function render_dashboard_widget() {
        // Use transient for widget data to reduce database queries
        $cache_key = 'sky_insights_widget_stats_' . get_current_user_id();
        $stats = get_transient($cache_key);
        
        if (false === $stats) {
            global $wpdb;
            
            $today = current_time('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
            
            // Today's donations with proper error handling
            $today_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(DISTINCT p.ID) as count, 
                    COALESCE(SUM(pm.meta_value), 0) as total
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND DATE(p.post_date) = %s
            ", $today));
            
            // Yesterday's donations
            $yesterday_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(DISTINCT p.ID) as count, 
                    COALESCE(SUM(pm.meta_value), 0) as total
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND DATE(p.post_date) = %s
            ", $yesterday));
            
            // This week's stats
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(DISTINCT p.ID) as count, 
                    COALESCE(SUM(pm.meta_value), 0) as total
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND DATE(p.post_date) >= %s
                AND DATE(p.post_date) <= %s
            ", $week_start, $today));
            
            $stats = array(
                'today' => $today_stats,
                'yesterday' => $yesterday_stats,
                'week' => $week_stats,
                'currency' => $this->currency_symbol
            );
            
            // Cache for 5 minutes
            set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        }
        
        $this->render_widget_html($stats);
    }
    
    /**
     * Render the dashboard widget HTML
     */
    private function render_widget_html($stats) {
        $currency = $stats['currency'];
        ?>
        <div class="sky-insights-widget">
            <style>
                .sky-insights-widget { padding: 10px 0; }
                .sky-widget-row { display: flex; gap: 20px; margin-bottom: 20px; }
                .sky-widget-stat { flex: 1; }
                .sky-widget-stat h4 { 
                    margin: 0 0 8px 0; 
                    color: #666; 
                    font-size: 13px; 
                    font-weight: 600; 
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .sky-widget-amount { 
                    margin: 0 0 4px 0; 
                    font-size: 24px; 
                    font-weight: 600; 
                    color: #2271b1; 
                    line-height: 1.2;
                }
                .sky-widget-count { 
                    margin: 0; 
                    color: #666; 
                    font-size: 12px; 
                }
                .sky-widget-trend {
                    display: inline-block;
                    font-size: 12px;
                    margin-left: 8px;
                    font-weight: normal;
                }
                .sky-widget-trend.up { color: #00a32a; }
                .sky-widget-trend.down { color: #d63638; }
                .sky-widget-week {
                    background: #f0f0f1;
                    padding: 12px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                }
                .sky-widget-link { 
                    margin: 0; 
                    text-align: center; 
                    padding-top: 10px;
                    border-top: 1px solid #e0e0e0;
                }
                .sky-widget-link .button {
                    font-weight: 500;
                }
            </style>
            
            <div class="sky-widget-row">
                <div class="sky-widget-stat">
                    <h4><?php esc_html_e('Today', 'sky-insights'); ?></h4>
                    <p class="sky-widget-amount">
                        <?php echo esc_html($currency . number_format($stats['today']->total ?: 0, 2)); ?>
                        <?php 
                        if ($stats['yesterday']->total > 0) {
                            $change = (($stats['today']->total - $stats['yesterday']->total) / $stats['yesterday']->total) * 100;
                            $class = $change >= 0 ? 'up' : 'down';
                            $symbol = $change >= 0 ? '↑' : '↓';
                            echo '<span class="sky-widget-trend ' . esc_attr($class) . '">' . esc_html($symbol . ' ' . abs(round($change)) . '%') . '</span>';
                        }
                        ?>
                    </p>
                    <p class="sky-widget-count">
                        <?php 
                        echo esc_html(sprintf(
                            _n('%d donation', '%d donations', $stats['today']->count ?: 0, 'sky-insights'), 
                            $stats['today']->count ?: 0
                        )); 
                        ?>
                    </p>
                </div>
                
                <div class="sky-widget-stat">
                    <h4><?php esc_html_e('Yesterday', 'sky-insights'); ?></h4>
                    <p class="sky-widget-amount">
                        <?php echo esc_html($currency . number_format($stats['yesterday']->total ?: 0, 2)); ?>
                    </p>
                    <p class="sky-widget-count">
                        <?php 
                        echo esc_html(sprintf(
                            _n('%d donation', '%d donations', $stats['yesterday']->count ?: 0, 'sky-insights'), 
                            $stats['yesterday']->count ?: 0
                        )); 
                        ?>
                    </p>
                </div>
            </div>
            
            <div class="sky-widget-week">
                <h4><?php esc_html_e('This Week', 'sky-insights'); ?></h4>
                <p class="sky-widget-amount">
                    <?php echo esc_html($currency . number_format($stats['week']->total ?: 0, 2)); ?>
                </p>
                <p class="sky-widget-count">
                    <?php 
                    echo esc_html(sprintf(
                        _n('%d donation', '%d donations', $stats['week']->count ?: 0, 'sky-insights'), 
                        $stats['week']->count ?: 0
                    )); 
                    ?>
                </p>
            </div>
            
            <p class="sky-widget-link">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sky-insights-dashboard')); ?>" class="button button-primary">
                    <?php esc_html_e('View Full Dashboard', 'sky-insights'); ?> →
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Clear all plugin caches
     */
    public function clear_all_caches() {
        // Clear transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sky_insights_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sky_insights_%'");
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        $this->log('All caches cleared');
        
        // Trigger action for other components
        do_action('sky_insights_caches_cleared');
    }
    
    /**
     * Handle AJAX cache clearing
     */
    public function ajax_clear_cache() {
        // Check nonce
        if (!check_ajax_referer('sky_insights_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'sky-insights'));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Insufficient permissions', 'sky-insights'));
        }
        
        $this->clear_all_caches();
        
        wp_send_json_success(array(
            'message' => __('Cache cleared successfully', 'sky-insights')
        ));
    }
}

// Initialize the dashboard
add_action('init', function() {
    SkyWebInsightsDashboard::get_instance();
});