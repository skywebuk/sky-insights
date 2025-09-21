<?php
/**
 * Plugin Name: Sky Web Insights Dashboard
 * Plugin URI: https://skywebdesign.co.uk/
 * Description: Custom analytics dashboard for WooCommerce donations and appeals
 * Version: 1.0.1
 * Author: Sky Web
 * License: GPL v2 or later
 * Text Domain: sky-insights
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SKY_INSIGHTS_VERSION', '1.0.1');
define('SKY_INSIGHTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SKY_INSIGHTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SKY_INSIGHTS_DB_VERSION', '1.0.1');

// Include required files
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-dashboard.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-ajax.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-data-queries.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-data-processor.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-data.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-export.php';
require_once SKY_INSIGHTS_PLUGIN_DIR . 'includes/class-sky-insights-utils.php';

// Initialize the plugin
function sky_insights_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('Sky Web Insights Dashboard requires WooCommerce to be active.', 'sky-insights') . '</p></div>';
        });
        return;
    }
    
    // Check and create/update tables if missing or outdated
    sky_insights_check_and_create_tables();
    
    // Initialize main class
    SkyWebInsightsDashboard::get_instance();
    
    // Track product views for visitor counting
    add_action('template_redirect', 'sky_insights_track_product_views');
    
    // Track checkout page visits
    add_action('template_redirect', 'sky_insights_track_checkout_opened');
    
    // Track add to cart events
    add_action('woocommerce_add_to_cart', 'sky_insights_track_add_to_cart', 10, 6);
    
    // Track order completion for donation data
    add_action('woocommerce_order_status_completed', 'sky_insights_track_order_completed');
    add_action('woocommerce_order_status_processing', 'sky_insights_track_order_completed');
    
    // Track cart abandonment
    add_action('woocommerce_cart_updated', 'sky_insights_track_cart_updated');
    
    // Schedule cleanup tasks
    add_action('init', 'sky_insights_schedule_tasks');
    
    // Check for database updates
    sky_insights_check_db_updates();
}
add_action('plugins_loaded', 'sky_insights_init');

/**
 * Enhanced activation hook with comprehensive table creation
 */
register_activation_hook(__FILE__, 'sky_insights_activate');
function sky_insights_activate() {
    // Update version
    update_option('sky_insights_version', SKY_INSIGHTS_VERSION);
    update_option('sky_insights_db_version', SKY_INSIGHTS_DB_VERSION);
    
    // Create all required tables
    sky_insights_create_all_tables();
    
    // Add database indexes
    sky_insights_add_indexes();
    
    // Schedule tasks
    sky_insights_schedule_tasks();
    
    // Clear cache
    sky_insights_clear_all_cache();
    
    // Set activation flag
    update_option('sky_insights_activation_time', current_time('mysql'));
    set_transient('sky_insights_just_activated', true, 60);
}

/**
 * Create all required tables with proper error handling
 */
function sky_insights_create_all_tables() {
    global $wpdb;
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Create cache table
    $table_name = $wpdb->prefix . 'sky_insights_cache';
    
    // Check if table exists before creating
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if ($table_exists !== $table_name) {
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            cache_value longtext NOT NULL,
            expiration datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expiration (expiration)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Log table creation
        sky_insights_log('Cache table created successfully');
    }
}

/**
 * Add indexes to improve query performance
 */
function sky_insights_add_indexes() {
    global $wpdb;
    
    // Only add indexes if they don't exist
    $existing = $wpdb->get_results("SHOW INDEX FROM {$wpdb->posts} WHERE Key_name = 'sky_order_date'");
    
    if (empty($existing)) {
        // Suppress errors as user might not have ALTER privileges
        $wpdb->hide_errors();
        $wpdb->query("ALTER TABLE {$wpdb->posts} ADD INDEX sky_order_date (post_type, post_status, post_date)");
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX sky_order_meta (meta_key(20), post_id)");
        $wpdb->show_errors();
        
        // Log index creation attempt
        sky_insights_log('Attempted to create database indexes');
    }
}

/**
 * Check for database updates
 */
function sky_insights_check_db_updates() {
    $current_db_version = get_option('sky_insights_db_version', '1.0.0');
    
    if (version_compare($current_db_version, SKY_INSIGHTS_DB_VERSION, '<')) {
        // Run any necessary updates here
        sky_insights_create_all_tables();
        sky_insights_add_indexes();
        
        // Update version
        update_option('sky_insights_db_version', SKY_INSIGHTS_DB_VERSION);
        
        sky_insights_log('Database updated to version ' . SKY_INSIGHTS_DB_VERSION);
    }
}

/**
 * Check and create tables if missing (runs on every page load)
 */
function sky_insights_check_and_create_tables() {
    // Only run checks periodically to avoid performance impact
    $last_check = get_option('sky_insights_tables_checked');
    $check_interval = 24 * HOUR_IN_SECONDS; // Check once per day
    
    // Force check if just activated
    if (get_transient('sky_insights_just_activated')) {
        delete_transient('sky_insights_just_activated');
        $last_check = false;
    }
    
    if ($last_check && (strtotime($last_check) + $check_interval > current_time('timestamp'))) {
        return; // Skip check if recently checked
    }
    
    // Perform table check
    sky_insights_create_all_tables();
    
    // Update last check time
    update_option('sky_insights_tables_checked', current_time('mysql'));
}

/**
 * Bot detection with comprehensive patterns
 */
function sky_insights_is_bot() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    
    $bot_patterns = array(
        // Search engine bots
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'applebot',
        // Social media bots
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'skypeuripreview',
        // SEO and analysis bots
        'ahrefsbot', 'semrushbot', 'dotbot', 'mj12bot', 'seokicks', 'seznambot',
        'exabot', 'zoominfobot', 'screaming frog', 'metauri', 'qwantify',
        // Monitoring and testing bots
        'pingdombot', 'monitorus', 'gtmetrix', 'uptimerobot', 'statuscake',
        'newrelicpinger', 'blackbox', 'nagios', 'zabbix', 'pingdom', 'newrelic',
        // Development tools
        'postman', 'insomnia', 'curl', 'wget', 'python', 'java', 'go-http-client',
        // Generic bot patterns
        'bot', 'crawler', 'spider', 'scraper', 'archiver', 'analyzer', 'fetcher',
        // Headless browsers
        'headless', 'phantomjs', 'selenium', 'puppeteer', 'playwright',
        // Performance testing
        'lighthouse', 'chrome-lighthouse', 'pagespeed', 'mail.ru'
    );
    
    $user_agent_lower = strtolower($user_agent);
    
    foreach ($bot_patterns as $pattern) {
        if (stripos($user_agent_lower, $pattern) !== false) {
            return true;
        }
    }
    
    // Additional checks
    if (empty($user_agent)) {
        return true;
    }
    
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    if (empty($accept) || stripos($accept, 'text/html') === false) {
        return true;
    }
    
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return true;
    }
    
    if (isset($_SERVER['HTTP_SEC_CH_UA']) && stripos($_SERVER['HTTP_SEC_CH_UA'], 'headless') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Product view tracking with comprehensive checks
 */
function sky_insights_track_product_views() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Early return for non-product pages
    if (!is_product()) {
        return;
    }
    
    // Skip tracking for bots, admin area, and admin users (unless filtered)
    if (sky_insights_is_bot() || is_admin()) {
        return;
    }
    
    // Allow filtering of admin tracking
    if (current_user_can('manage_options') && !apply_filters('sky_insights_track_admin_views', false)) {
        return;
    }
    
    global $post;
    
    // Ensure we have a valid post object
    if (!$post || !isset($post->ID)) {
        return;
    }
    
    $product_id = absint($post->ID);
    
    // Verify this is actually a product
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    
    try {
        $visitor_id = sky_insights_get_visitor_id();
        
        // Skip system processes
        if ($visitor_id === 'system_process') {
            return;
        }
        
        // Create unique view key
        $view_key = 'sky_insights_viewed_' . $product_id . '_' . md5($visitor_id);
        
        // Check if already viewed recently
        if (get_transient($view_key)) {
            return;
        }
        
        // Use atomic increment to prevent race conditions
        $views = absint(get_post_meta($product_id, '_product_views_count', true));
        update_post_meta($product_id, '_product_views_count', $views + 1);
        
        // Track daily views
        $today = current_time('Y-m-d');
        $daily_views_key = '_product_views_' . $today;
        $daily_views = absint(get_post_meta($product_id, $daily_views_key, true));
        update_post_meta($product_id, $daily_views_key, $daily_views + 1);
        
        // Set transient to prevent duplicate tracking
        set_transient($view_key, true, 30 * MINUTE_IN_SECONDS);
        
        // Track traffic source if available
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referrer = esc_url_raw($_SERVER['HTTP_REFERER']);
            sky_insights_track_traffic_source($product_id, $referrer);
        } else {
            sky_insights_track_traffic_source($product_id, 'direct');
        }
        
        // Allow other plugins to hook into view tracking
        do_action('sky_insights_product_viewed', $product_id, $visitor_id);
        
    } catch (Exception $e) {
        // Log error if debugging is enabled
        sky_insights_log('View tracking error: ' . $e->getMessage(), 'error');
    }
}

/**
 * Checkout tracking with WooCommerce check
 */
function sky_insights_track_checkout_opened() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    if (is_checkout() && !is_order_received_page() && WC()->cart && !WC()->cart->is_empty() && !sky_insights_is_bot()) {
        $visitor_id = sky_insights_get_visitor_id();
        $checkout_session_key = 'sky_insights_checkout_' . md5($visitor_id);
        $already_tracked = get_transient($checkout_session_key);
        
        if (!$already_tracked) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = absint($cart_item['product_id']);
                
                $checkouts = absint(get_post_meta($product_id, '_product_checkouts_count', true));
                update_post_meta($product_id, '_product_checkouts_count', $checkouts + 1);
                
                $today = current_time('Y-m-d');
                $daily_checkouts_key = '_product_checkouts_' . $today;
                $daily_checkouts = absint(get_post_meta($product_id, $daily_checkouts_key, true));
                update_post_meta($product_id, $daily_checkouts_key, $daily_checkouts + 1);
            }
            
            set_transient($checkout_session_key, true, HOUR_IN_SECONDS);
            
            // Log checkout tracking
            sky_insights_log('Checkout tracked for visitor: ' . $visitor_id);
        }
    }
}

/**
 * Track add to cart with WooCommerce check
 */
function sky_insights_track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    if (!sky_insights_is_bot()) {
        $product_id = absint($product_id);
        
        $add_to_cart_count = absint(get_post_meta($product_id, '_product_add_to_cart_count', true));
        update_post_meta($product_id, '_product_add_to_cart_count', $add_to_cart_count + 1);
        
        $today = current_time('Y-m-d');
        $daily_key = '_product_add_to_cart_' . $today;
        $daily_count = absint(get_post_meta($product_id, $daily_key, true));
        update_post_meta($product_id, $daily_key, $daily_count + 1);
        
        // Allow other plugins to hook into add to cart tracking
        do_action('sky_insights_product_added_to_cart', $product_id, $quantity);
    }
}

/**
 * Track order completion with WooCommerce check
 */
function sky_insights_track_order_completed($order_id) {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    try {
        foreach ($order->get_items() as $item) {
            $product_id = absint($item->get_product_id());
            
            // Update donation count
            $donations = absint(get_post_meta($product_id, '_product_donations_count', true));
            update_post_meta($product_id, '_product_donations_count', $donations + 1);
            
            // Update revenue
            $revenue = floatval(get_post_meta($product_id, '_product_total_revenue', true));
            update_post_meta($product_id, '_product_total_revenue', $revenue + $item->get_total());
            
            // Track daily stats
            $today = current_time('Y-m-d');
            
            $daily_donations_key = '_product_donations_' . $today;
            $daily_donations = absint(get_post_meta($product_id, $daily_donations_key, true));
            update_post_meta($product_id, $daily_donations_key, $daily_donations + 1);
            
            $daily_revenue_key = '_product_revenue_' . $today;
            $daily_revenue = floatval(get_post_meta($product_id, $daily_revenue_key, true));
            update_post_meta($product_id, $daily_revenue_key, $daily_revenue + $item->get_total());
        }
        
        // Log order tracking
        sky_insights_log('Order tracked: #' . $order_id);
        
        // Allow other plugins to hook into order tracking
        do_action('sky_insights_order_tracked', $order_id, $order);
        
    } catch (Exception $e) {
        sky_insights_log('Order tracking error: ' . $e->getMessage(), 'error');
    }
}

/**
 * Track cart abandonment with WooCommerce check
 */
function sky_insights_track_cart_updated() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    if (WC()->cart && !WC()->cart->is_empty() && !sky_insights_is_bot()) {
        $visitor_id = sky_insights_get_visitor_id();
        
        // Skip system processes
        if ($visitor_id === 'system_process' || $visitor_id === 'admin_user') {
            return;
        }
        
        $cart_data = array(
            'visitor_id' => $visitor_id,
            'items' => array(),
            'total' => WC()->cart->get_cart_contents_total(),
            'timestamp' => current_time('timestamp')
        );
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_data['items'][] = array(
                'product_id' => absint($cart_item['product_id']),
                'quantity' => absint($cart_item['quantity']),
                'price' => floatval($cart_item['data']->get_price())
            );
        }
        
        set_transient('sky_insights_cart_' . md5($visitor_id), $cart_data, 7 * DAY_IN_SECONDS);
    }
}

/**
 * Track traffic sources with validation
 */
function sky_insights_track_traffic_source($product_id, $referrer) {
    $source = 'direct';
    
    if (!empty($referrer)) {
        $referrer_host = parse_url($referrer, PHP_URL_HOST);
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        
        if ($referrer_host && $referrer_host !== $site_host) {
            if (strpos($referrer_host, 'google') !== false) {
                $source = 'google';
            } elseif (strpos($referrer_host, 'facebook') !== false) {
                $source = 'facebook';
            } elseif (strpos($referrer_host, 'twitter') !== false || strpos($referrer_host, 'x.com') !== false) {
                $source = 'twitter';
            } elseif (strpos($referrer_host, 'instagram') !== false) {
                $source = 'instagram';
            } elseif (strpos($referrer_host, 'linkedin') !== false) {
                $source = 'linkedin';
            } elseif (strpos($referrer_host, 'youtube') !== false) {
                $source = 'youtube';
            } elseif (strpos($referrer_host, 'pinterest') !== false) {
                $source = 'pinterest';
            } elseif (strpos($referrer_host, 'reddit') !== false) {
                $source = 'reddit';
            } else {
                $source = 'referral';
            }
        }
    }
    
    $today = current_time('Y-m-d');
    $source_key = '_product_source_' . sanitize_key($source) . '_' . $today;
    $source_count = absint(get_post_meta($product_id, $source_key, true));
    update_post_meta($product_id, $source_key, $source_count + 1);
}

/**
 * Schedule cleanup tasks
 */
function sky_insights_schedule_tasks() {
    if (!wp_next_scheduled('sky_insights_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'sky_insights_daily_cleanup');
        sky_insights_log('Daily cleanup task scheduled');
    }
}

/**
 * Daily cleanup with optimized batch processing
 */
add_action('sky_insights_daily_cleanup', 'sky_insights_perform_daily_cleanup');
function sky_insights_perform_daily_cleanup() {
    global $wpdb;
    
    // Log cleanup start
    sky_insights_log('Starting daily cleanup');
    
    // Limit cleanup to prevent timeout
    $cleanup_limit = 100;
    $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
    
    // Process products in batches to prevent memory issues
    $offset = 0;
    $processed = 0;
    
    do {
        // Get products in batches
        $products = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => $cleanup_limit,
            'offset' => $offset,
            'fields' => 'ids',
            'post_status' => 'any',
            'suppress_filters' => true,
            'no_found_rows' => true
        ));
        
        if (empty($products)) {
            break;
        }
        
        foreach ($products as $product_id) {
            // Delete old daily tracking meta
            $meta_keys = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->postmeta} 
                 WHERE post_id = %d 
                 AND (meta_key LIKE '_product_views_%%' 
                      OR meta_key LIKE '_product_checkouts_%%' 
                      OR meta_key LIKE '_product_donations_%%' 
                      OR meta_key LIKE '_product_revenue_%%'
                      OR meta_key LIKE '_product_add_to_cart_%%'
                      OR meta_key LIKE '_product_source_%%')
                 AND meta_key REGEXP '[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                 LIMIT 100",
                $product_id
            ));
            
            foreach ($meta_keys as $meta_key) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})$/', $meta_key, $matches)) {
                    $date = $matches[1];
                    if ($date < $thirty_days_ago) {
                        delete_post_meta($product_id, $meta_key);
                        $processed++;
                    }
                }
            }
            
            // Prevent timeout
            if ($processed >= 1000) {
                sky_insights_log('Cleanup limit reached, will continue in next run');
                break 2;
            }
        }
        
        $offset += $cleanup_limit;
        
        // Clear query cache
        wp_cache_flush();
        
    } while (count($products) === $cleanup_limit);
    
    // Clean up old cart abandonment data
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_sky_insights_cart_%' 
         AND option_name NOT LIKE '%_timeout_%'"
    );
    
    // Clean up expired transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_timeout_sky_insights_%' 
         AND option_value < " . time()
    );
    
    // Delete orphaned transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_sky_insights_%' 
         AND option_name NOT LIKE '%_timeout_%'
         AND NOT EXISTS (
             SELECT 1 FROM (SELECT * FROM {$wpdb->options}) AS t2 
             WHERE t2.option_name = CONCAT('_transient_timeout_', SUBSTRING({$wpdb->options}.option_name, 12))
         )"
    );
    
    sky_insights_log('Daily cleanup completed. Processed: ' . $processed . ' meta entries');
}

/**
 * Get unique visitor ID with enhanced security
 */
function sky_insights_get_visitor_id() {
    // Security check - don't track system processes
    if (defined('DOING_CRON') || defined('DOING_AJAX') || defined('REST_REQUEST')) {
        return 'system_process';
    }
    
    // Don't track admin users unless explicitly allowed
    if (current_user_can('manage_options') && !apply_filters('sky_insights_track_admins', false)) {
        return 'admin_user';
    }
    
    // For logged-in users
    if (is_user_logged_in()) {
        return 'user_' . get_current_user_id();
    }
    
    // For guests, try to get from cookie first
    $cookie_name = 'sky_insights_visitor';
    if (isset($_COOKIE[$cookie_name])) {
        $visitor_id = sanitize_text_field($_COOKIE[$cookie_name]);
        
        // Validate the visitor ID format
        if (preg_match('/^visitor_[a-zA-Z0-9]{32}$/', $visitor_id)) {
            return $visitor_id;
        }
    }
    
    // Generate new visitor ID
    $visitor_id = 'visitor_' . wp_generate_password(32, false);
    
    // Set cookie if headers not sent and not a bot
    if (!headers_sent() && !sky_insights_is_bot()) {
        $cookie_params = array(
            'expires' => time() + (30 * DAY_IN_SECONDS),
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        );
        
        // PHP 7.3+ supports SameSite
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie($cookie_name, $visitor_id, $cookie_params);
        } else {
            setcookie($cookie_name, $visitor_id, $cookie_params['expires'], $cookie_params['path'], 
                     $cookie_params['domain'], $cookie_params['secure'], $cookie_params['httponly']);
        }
    }
    
    return $visitor_id;
}

/**
 * Error logging function
 */
function sky_insights_log($message, $level = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Sky Insights][' . strtoupper($level) . '] ' . $message);
    }
}

/**
 * Clear all cache
 */
function sky_insights_clear_all_cache() {
    // Clear specific transients
    delete_transient('sky_insights_dashboard_data');
    delete_transient('sky_insights_overview_stats');
    
    // Clear all date-specific caches
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sky_insights_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sky_insights_%'");
    
    // Clear any object cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    sky_insights_log('All caches cleared');
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'sky_insights_deactivate');
function sky_insights_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('sky_insights_daily_cleanup');
    
    // Clear all transients
    sky_insights_clear_all_cache();
    
    // Log deactivation
    sky_insights_log('Plugin deactivated');
}