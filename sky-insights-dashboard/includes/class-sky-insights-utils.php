<?php
/**
 * Utility functions for Sky Insights
 * Centralizes common functions to eliminate code duplication
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Prevent duplicate loading
if (defined('SKY_INSIGHTS_UTILS_LOADED')) {
    return;
}
define('SKY_INSIGHTS_UTILS_LOADED', true);

// Also check if class exists
if (class_exists('SkyInsightsUtils')) {
    return;
}

class SkyInsightsUtils {
    
    /**
     * Calculate date range based on preset or custom values
     * Replaces duplicate functions in multiple files
     */
    public static function calculate_date_range($range, $custom_from = '', $custom_to = '') {
        $today = current_time('Y-m-d');
        $dates = array();
        
        switch ($range) {
            case 'today':
                $dates['start'] = $dates['end'] = $today;
                break;
                
            case 'yesterday':
                $dates['start'] = $dates['end'] = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
                break;
                
            case 'last7days':
                $dates['start'] = date('Y-m-d', strtotime('-6 days', current_time('timestamp')));
                $dates['end'] = $today;
                break;
                
            case 'last14days':
                $dates['start'] = date('Y-m-d', strtotime('-13 days', current_time('timestamp')));
                $dates['end'] = $today;
                break;
                
            case 'last30days':
                $dates['start'] = date('Y-m-d', strtotime('-29 days', current_time('timestamp')));
                $dates['end'] = $today;
                break;
                
            case 'thisweek':
                $dates['start'] = date('Y-m-d', strtotime('monday this week', current_time('timestamp')));
                $dates['end'] = $today;
                break;
                
            case 'thismonth':
                $dates['start'] = date('Y-m-01', current_time('timestamp'));
                $dates['end'] = $today;
                break;
                
            case 'thisyear':
                $dates['start'] = date('Y-01-01', current_time('timestamp'));
                $dates['end'] = $today;
                break;
                
            case 'lastweek':
                $dates['start'] = date('Y-m-d', strtotime('monday last week', current_time('timestamp')));
                $dates['end'] = date('Y-m-d', strtotime('sunday last week', current_time('timestamp')));
                break;
                
            case 'lastmonth':
                $dates['start'] = date('Y-m-01', strtotime('first day of last month', current_time('timestamp')));
                $dates['end'] = date('Y-m-t', strtotime('last day of last month', current_time('timestamp')));
                break;
                
            case 'lastyear':
                $dates['start'] = date('Y-01-01', strtotime('last year', current_time('timestamp')));
                $dates['end'] = date('Y-12-31', strtotime('last year', current_time('timestamp')));
                break;
                
            case 'custom':
                $dates['start'] = $custom_from ?: $today;
                $dates['end'] = $custom_to ?: $today;
                break;
                
            default:
                $dates['start'] = date('Y-m-01', current_time('timestamp'));
                $dates['end'] = $today;
        }
        
        return $dates;
    }
    
    /**
     * Validate date format
     */
    public static function validate_date($date) {
        if (empty($date)) {
            return false;
        }
        
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Get visitor IP address
     */
    public static function get_visitor_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Generate cache key for consistent caching
     */
    public static function generate_cache_key($prefix, $params = array()) {
        $key_parts = array($prefix);
        
        foreach ($params as $param) {
            if (is_array($param)) {
                $key_parts[] = md5(serialize($param));
            } else {
                $key_parts[] = sanitize_key($param);
            }
        }
        
        return implode('_', array_filter($key_parts));
    }
    
    /**
     * Get date conditions for SQL queries
     */
    public static function get_date_conditions($dates) {
        return array(
            'start' => $dates['start'] . ' 00:00:00',
            'end' => $dates['end'] . ' 23:59:59'
        );
    }
    
    /**
     * Format currency value
     */
    public static function format_currency($amount, $decimals = 2) {
        $currency = get_woocommerce_currency_symbol();
        $position = get_option('woocommerce_currency_pos');
        $formatted = number_format($amount, $decimals, 
            wc_get_price_decimal_separator(), 
            wc_get_price_thousand_separator()
        );
        
        switch ($position) {
            case 'left':
                return $currency . $formatted;
            case 'right':
                return $formatted . $currency;
            case 'left_space':
                return $currency . ' ' . $formatted;
            case 'right_space':
                return $formatted . ' ' . $currency;
            default:
                return $currency . $formatted;
        }
    }
    
    /**
     * Sanitize and validate filters
     */
    public static function sanitize_filters($filters) {
        $sanitized = array();
        
        $allowed_keys = array('campaign', 'designation', 'source', 'frequency');
        
        foreach ($filters as $key => $value) {
            if (in_array($key, $allowed_keys) && !empty($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get percentage change between two values
     */
    public static function get_percentage_change($old_value, $new_value) {
        if ($old_value == 0) {
            return $new_value > 0 ? 100 : 0;
        }
        
        return round((($new_value - $old_value) / $old_value) * 100, 1);
    }
    
    /**
     * Check if date range is large
     */
    public static function is_large_date_range($dates) {
        $start = new DateTime($dates['start']);
        $end = new DateTime($dates['end']);
        $diff = $start->diff($end);
        
        return $diff->days > 90;
    }
    
    /**
     * Log messages conditionally based on debug mode
     */
    public static function log($message, $level = 'info', $context = array()) {
        if (!defined('SKY_INSIGHTS_DEBUG') || !SKY_INSIGHTS_DEBUG) {
            return;
        }
        
        $prefix = '[Sky Insights][' . strtoupper($level) . ']';
        
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        
        error_log($prefix . ' ' . $message);
    }
    
    /**
     * Get execution time limit based on date range
     */
    public static function get_execution_time_limit($date_range, $custom_from = '', $custom_to = '') {
        $limits = array(
            'thisyear' => 300,  // 5 minutes
            'lastyear' => 300,  // 5 minutes
            'last30days' => 180, // 3 minutes
            'thismonth' => 180,  // 3 minutes
            'lastmonth' => 180,  // 3 minutes
            'default' => 120     // 2 minutes
        );
        
        // For custom ranges, calculate based on days
        if ($date_range === 'custom' && $custom_from && $custom_to) {
            $days = (strtotime($custom_to) - strtotime($custom_from)) / (60 * 60 * 24);
            
            if ($days > 180) return 300;      // 5 minutes for 6+ months
            if ($days > 60) return 180;       // 3 minutes for 2+ months
            if ($days > 30) return 120;       // 2 minutes for 1+ month
        }
        
        return isset($limits[$date_range]) ? $limits[$date_range] : $limits['default'];
    }
    
    /**
     * Safe array get with default value
     */
    public static function array_get($array, $key, $default = null) {
        return isset($array[$key]) ? $array[$key] : $default;
    }
    
    /**
     * Format date for display
     */
    public static function format_date($date, $format = null) {
        if (empty($date)) {
            return '';
        }
        
        if ($format === null) {
            $format = get_option('date_format');
        }
        
        return date_i18n($format, strtotime($date));
    }
    
    /**
     * Get WooCommerce order statuses for completed orders
     */
    public static function get_completed_order_statuses() {
        return apply_filters('sky_insights_completed_order_statuses', 
            array('wc-completed', 'wc-processing')
        );
    }
    
    /**
     * Check if a specific feature is enabled
     */
    public static function is_feature_enabled($feature) {
        $features = array(
            'visitor_tracking' => true,
            'abandoned_cart_tracking' => true,
            'auto_refresh' => true,
            'ai_insights' => true
        );
        
        return apply_filters('sky_insights_feature_enabled', 
            isset($features[$feature]) ? $features[$feature] : false, 
            $feature
        );
    }
    
    /**
     * Batch process large datasets
     */
    public static function process_in_batches($total_items, $batch_size, $callback) {
        $batches = ceil($total_items / $batch_size);
        $results = array();
        
        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * $batch_size;
            $batch_results = call_user_func($callback, $offset, $batch_size);
            
            if (is_array($batch_results)) {
                $results = array_merge($results, $batch_results);
            }
            
            // Allow other processes to run
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }
        
        return $results;
    }
    
    /**
     * Get human-readable time difference
     */
    public static function human_time_diff($from, $to = null) {
        if ($to === null) {
            $to = current_time('timestamp');
        }
        
        $diff = abs($to - $from);
        
        if ($diff < MINUTE_IN_SECONDS) {
            return sprintf(_n('%s second', '%s seconds', $diff, 'sky-insights'), $diff);
        } elseif ($diff < HOUR_IN_SECONDS) {
            $mins = round($diff / MINUTE_IN_SECONDS);
            return sprintf(_n('%s minute', '%s minutes', $mins, 'sky-insights'), $mins);
        } elseif ($diff < DAY_IN_SECONDS) {
            $hours = round($diff / HOUR_IN_SECONDS);
            return sprintf(_n('%s hour', '%s hours', $hours, 'sky-insights'), $hours);
        } else {
            $days = round($diff / DAY_IN_SECONDS);
            return sprintf(_n('%s day', '%s days', $days, 'sky-insights'), $days);
        }
    }
}