<?php
/**
 * AJAX Handler Class with Enhanced Security
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsAjax {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->register_ajax_handlers();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Dashboard data handlers
        add_action('wp_ajax_sky_insights_get_data', array($this, 'get_dashboard_data'));
        add_action('wp_ajax_nopriv_sky_insights_get_data', array($this, 'permission_denied'));
        
        // Tab data handlers
        add_action('wp_ajax_sky_insights_get_tab_data', array($this, 'get_tab_data'));
        add_action('wp_ajax_nopriv_sky_insights_get_tab_data', array($this, 'permission_denied'));
    }
    
    /**
     * Handle permission denied for non-logged in users
     */
    public function permission_denied() {
        wp_send_json_error(array(
            'message' => __('You do not have permission to access this data.', 'sky-insights')
        ));
    }
    
    /**
     * Main dashboard data handler
     */
    public function get_dashboard_data() {
        // Security checks
        if (!$this->verify_request()) {
            return;
        }
        
        // Get and validate parameters
        $params = $this->get_validated_params();
        if (is_wp_error($params)) {
            wp_send_json_error(array('message' => $params->get_error_message()));
            return;
        }
        
        // Set execution limits based on date range
        $this->set_execution_limits($params['date_range'], $params['date_from'], $params['date_to']);
        
        try {
            // Get data from data handler
            $data_handler = new SkyInsightsData();
            $data = $data_handler->get_insights_data(
                $params['date_range'],
                $params['date_from'],
                $params['date_to'],
                $params['view_type'],
                $params['filter'],
                $params['filters']
            );
            
            // Add filter options
            $data['filter_options'] = $this->get_filter_options();
            
            // Add metadata
            $data['timestamp'] = current_time('timestamp');
            
            // CRITICAL FIX: Ensure filter_data is at the root level for payment methods
            // The JavaScript expects filter_data to be directly accessible
            if ($params['filter'] === 'payment_methods' && isset($data['filter_data'])) {
                // Log for debugging
                error_log('Sky Insights Payment Methods - Filter data exists: ' . print_r(array_keys($data['filter_data']), true));
            }
            
            // Add debug info if enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $data['debug'] = array(
                    'params' => $params,
                    'memory_usage' => memory_get_usage(true),
                    'execution_time' => timer_stop(),
                    'filter' => $params['filter'],
                    'has_filter_data' => isset($data['filter_data']),
                    'filter_data_count' => isset($data['filter_data']) ? count($data['filter_data']) : 0
                );
            }
            
            // CRITICAL: Ensure we're sending the complete data structure
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            SkyInsightsUtils::log('Dashboard data error: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => __('Error processing data. Please try again.', 'sky-insights'),
                'error' => WP_DEBUG ? $e->getMessage() : ''
            ));
        }
    }
    
    /**
     * Tab data handler
     */
    public function get_tab_data() {
        // Security checks
        if (!$this->verify_request()) {
            return;
        }
        
        // Get parameters with trimming applied first
        $date_range = isset($_POST['date_range']) ? sanitize_text_field(trim($_POST['date_range'])) : 'last7days';
        $filter = isset($_POST['filter']) ? sanitize_text_field(trim($_POST['filter'])) : 'raised';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field(trim($_POST['date_from'])) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field(trim($_POST['date_to'])) : '';
        
        // Validate custom dates
        if ($date_range === 'custom') {
            // Debug log
            error_log('Sky Insights - Custom date validation: ' . $date_from . ' to ' . $date_to);
            
            $date_validation = $this->validate_custom_dates($date_from, $date_to);
            if (is_wp_error($date_validation)) {
                error_log('Sky Insights - Custom date validation failed: ' . $date_validation->get_error_message());
                wp_send_json_error(array('message' => $date_validation->get_error_message()));
                return;
            }
        }
        
        // Process filters
        $filters = $this->process_filters($_POST);
        
        try {
            // Get data
            $data_handler = new SkyInsightsData();
            $data = $data_handler->get_insights_data(
                $date_range, 
                $date_from, 
                $date_to, 
                'daily', 
                $filter, 
                $filters
            );
            
            // Log payment methods data specifically
            if ($filter === 'payment_methods') {
                error_log('Sky Insights Tab Data - Payment Methods Response:');
                error_log('Has filter_data: ' . (isset($data['filter_data']) ? 'YES' : 'NO'));
                if (isset($data['filter_data'])) {
                    error_log('Payment methods count: ' . count($data['filter_data']));
                    error_log('Payment methods: ' . print_r(array_keys($data['filter_data']), true));
                }
            }
            
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            SkyInsightsUtils::log('Tab data error: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => __('Error processing tab data.', 'sky-insights'),
                'error' => WP_DEBUG ? $e->getMessage() : ''
            ));
        }
    }
    
    /**
     * Verify AJAX request
     */
    private function verify_request() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sky_insights_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'sky-insights')
            ));
            return false;
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('view_woocommerce_reports')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions to view analytics.', 'sky-insights')
            ));
            return false;
        }
        
        return true;
    }
    
    /**
     * Get and validate parameters
     */
    private function get_validated_params() {
        // Get parameters with trimming
        $date_range = isset($_POST['date_range']) ? sanitize_text_field(trim($_POST['date_range'])) : 'last7days';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field(trim($_POST['date_from'])) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field(trim($_POST['date_to'])) : '';
        $view_type = isset($_POST['view_type']) ? sanitize_text_field(trim($_POST['view_type'])) : 'daily';
        $filter = isset($_POST['filter']) ? sanitize_text_field(trim($_POST['filter'])) : 'raised';
        $minimal = isset($_POST['minimal']) ? filter_var($_POST['minimal'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Validate date range
        $allowed_ranges = array(
            'today', 'yesterday', 'last7days', 'last14days', 'last30days',
            'thisweek', 'thismonth', 'thisyear', 'lastweek', 'lastmonth',
            'lastyear', 'custom'
        );
        
        if (!in_array($date_range, $allowed_ranges)) {
            $date_range = 'last7days';
        }
        
        // Validate view type
        if (!in_array($view_type, array('daily', 'weekly'))) {
            $view_type = 'daily';
        }
        
        // Validate filter - IMPORTANT: Allow 'payment_methods'
        $allowed_filters = array(
            'raised', 'daytime', 'frequencies', 'payment_methods',
            'countries', 'customers', 'designations', 'url'
        );
        
        if (!in_array($filter, $allowed_filters)) {
            $filter = 'raised';
        }
        
        // Validate custom dates
        if ($date_range === 'custom') {
            $date_validation = $this->validate_custom_dates($date_from, $date_to);
            if (is_wp_error($date_validation)) {
                return $date_validation;
            }
        }
        
        // Process filters
        $filters = $this->process_filters($_POST);
        
        return array(
            'date_range' => $date_range,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'view_type' => $view_type,
            'filter' => $filter,
            'filters' => $filters,
            'minimal' => $minimal
        );
    }
    
    /**
     * Validate custom date range
     */
    private function validate_custom_dates($date_from, $date_to) {
        // Check if dates are provided
        if (empty($date_from) || empty($date_to)) {
            return new WP_Error('missing_dates', 
                __('Please select both start and end dates for custom range.', 'sky-insights')
            );
        }
        
        // Validate date formats
        if (!SkyInsightsUtils::validate_date($date_from) || !SkyInsightsUtils::validate_date($date_to)) {
            return new WP_Error('invalid_format', 
                __('Invalid date format provided. Please use YYYY-MM-DD format.', 'sky-insights')
            );
        }
        
        // Ensure date_from is before date_to
        if (strtotime($date_from) > strtotime($date_to)) {
            return new WP_Error('invalid_range', 
                __('Start date must be before end date.', 'sky-insights')
            );
        }
        
        // Ensure dates are not in the future
        if (strtotime($date_to) > current_time('timestamp')) {
            return new WP_Error('future_date', 
                __('End date cannot be in the future.', 'sky-insights')
            );
        }
        
        // Check reasonable date range (max 2 years)
        $days_diff = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24);
        if ($days_diff > 730) {
            return new WP_Error('range_too_large', 
                __('Date range cannot exceed 2 years.', 'sky-insights')
            );
        }
        
        return true;
    }
    
    /**
     * Set execution limits based on date range
     */
    private function set_execution_limits($date_range, $date_from, $date_to) {
        $time_limit = SkyInsightsUtils::get_execution_time_limit($date_range, $date_from, $date_to);
        
        @set_time_limit($time_limit);
        
        // Set memory limit based on time limit
        if ($time_limit >= 300) {
            @ini_set('memory_limit', '512M');
        } elseif ($time_limit >= 180) {
            @ini_set('memory_limit', '256M');
        }
    }
    
    /**
     * Process and validate filters
     */
    private function process_filters($post_data) {
        $filters = array();
        
        // Check if filters are sent as an array
        if (isset($post_data['filters']) && is_array($post_data['filters'])) {
            $filters = SkyInsightsUtils::sanitize_filters($post_data['filters']);
        } else {
            // Check for individual filter parameters
            $filter_keys = array('campaign', 'designation', 'source', 'frequency');
            
            foreach ($filter_keys as $key) {
                if (isset($post_data['filters[' . $key . ']'])) {
                    $filters[$key] = sanitize_text_field(trim($post_data['filters[' . $key . ']']));
                }
            }
        }
        
        // Validate campaign ID
        if (!empty($filters['campaign']) && !is_numeric($filters['campaign'])) {
            unset($filters['campaign']);
        }
        
        // Remove empty filters
        return array_filter($filters);
    }
    
    /**
     * Get filter options for dropdowns
     */
    private function get_filter_options() {
        // Check user permissions for viewing products
        if (!current_user_can('edit_products')) {
            return array(
                'campaigns' => array(),
                'designations' => array(),
                'sources' => array(),
                'frequencies' => array()
            );
        }
        
        return array(
            'campaigns' => $this->get_campaigns(),
            'designations' => $this->get_designations(),
            'sources' => $this->get_sources(),
            'frequencies' => $this->get_frequencies()
        );
    }
    
    /**
     * Get campaigns (products) excluding variations
     */
    private function get_campaigns() {
        // Check cache first
        $cache_key = 'sky_insights_campaigns_list';
        $cached_campaigns = get_transient($cache_key);
        
        if ($cached_campaigns !== false) {
            return $cached_campaigns;
        }
        
        global $wpdb;
        
        // Get only parent products with proper sanitization
        $products = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.menu_order
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND p.post_parent = %d
            ORDER BY p.menu_order ASC, p.post_title ASC
        ", 'product', 'publish', 0));
        
        $campaigns = array();
        
        foreach ($products as $product) {
            // Verify it's not a variation
            $product_obj = wc_get_product($product->ID);
            if ($product_obj && !$product_obj->is_type('variation')) {
                $campaigns[] = array(
                    'id' => $product->ID,
                    'name' => $product->post_title
                );
            }
        }
        
        // Cache for 1 hour
        set_transient($cache_key, $campaigns, HOUR_IN_SECONDS);
        
        return $campaigns;
    }
    
    /**
     * Get designations (categories and tags)
     */
    private function get_designations() {
        $designations = array();
        
        // Get product categories
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
            'exclude' => get_option('default_product_cat')
        ));
        
        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $category) {
                // Skip uncategorized
                if (strtolower($category->slug) === 'uncategorized' || 
                    strtolower($category->name) === 'uncategorized') {
                    continue;
                }
                
                $designations[] = array(
                    'value' => $category->name,
                    'label' => $category->name . ' (' . $category->count . ')'
                );
            }
        }
        
        // Get product tags
        $tags = get_terms(array(
            'taxonomy' => 'product_tag',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (!is_wp_error($tags) && !empty($tags)) {
            foreach ($tags as $tag) {
                $designations[] = array(
                    'value' => $tag->name . ' (Tag)',
                    'label' => $tag->name . ' (Tag) (' . $tag->count . ')'
                );
            }
        }
        
        return $designations;
    }
    
    /**
     * Get UTM sources
     */
    private function get_sources() {
        // Check cache first
        $cache_key = 'sky_insights_utm_sources';
        $cached_sources = get_transient($cache_key);
        
        if ($cached_sources !== false) {
            return $cached_sources;
        }
        
        global $wpdb;
        
        $utm_sources = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT meta_value 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IS NOT NULL 
            AND meta_value != %s
            ORDER BY meta_value
            LIMIT %d
        ", '_utm_source', '', 50));
        
        $sources = array_map('esc_html', $utm_sources);
        
        // Cache for 1 hour
        set_transient($cache_key, $sources, HOUR_IN_SECONDS);
        
        return $sources;
    }
    
    /**
     * Get frequency options
     */
    private function get_frequencies() {
        $frequencies = array('Once');
        
        // Add subscription frequencies if WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions')) {
            $frequencies = array_merge($frequencies, array(
                'Daily',
                'Weekly',
                'Monthly',
                'Quarterly',
                'Semi-annually',
                'Annually'
            ));
        }
        
        return $frequencies;
    }
}

// Initialize AJAX handler
new SkyInsightsAjax();