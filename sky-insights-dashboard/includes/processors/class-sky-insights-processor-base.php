<?php
/**
 * Base Processor Class - Common functionality for all processors
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

abstract class SkyInsightsProcessorBase {
    
    protected $queries;
    protected $subscriptions_active = false;
    
    public function __construct() {
        $this->queries = new SkyInsightsDataQueries();
        $this->subscriptions_active = $this->check_subscriptions_active();
    }
    
    /**
     * Check if WooCommerce Subscriptions is active and functioning
     */
    protected function check_subscriptions_active() {
        // Check if plugin is active
        if (!class_exists('WC_Subscriptions')) {
            return false;
        }
        
        // Check if required functions exist
        $required_functions = array(
            'wcs_order_contains_subscription',
            'wcs_order_contains_renewal',
            'wcs_get_subscriptions_for_order',
            'wcs_get_subscriptions_for_renewal_order',
            'wcs_get_subscriptions'
        );
        
        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                error_log("Sky Insights: WooCommerce Subscriptions function missing: $function");
                return false;
            }
        }
        
        // Check database tables exist
        global $wpdb;
        $subscription_table = $wpdb->prefix . 'posts';
        $subscription_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $subscription_table WHERE post_type = %s LIMIT 1",
            'shop_subscription'
        ));
        
        if (!$subscription_exists) {
            // No subscriptions found, but plugin might be newly installed
            return true;
        }
        
        return true;
    }
    
    /**
     * Get subscriptions active status
     */
    public function is_subscriptions_active() {
        return $this->subscriptions_active;
    }
    
    /**
     * Safe check for subscription order
     */
    protected function is_subscription_order($order) {
        if (!$this->subscriptions_active) {
            return false;
        }
        
        try {
            if (function_exists('wcs_order_contains_subscription')) {
                return wcs_order_contains_subscription($order, array('parent', 'renewal', 'resubscribe', 'switch'));
            }
        } catch (Exception $e) {
            error_log('Sky Insights: Error checking subscription order - ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Safe check for renewal order
     */
    protected function is_renewal_order($order) {
        if (!$this->subscriptions_active) {
            return false;
        }
        
        try {
            if (function_exists('wcs_order_contains_renewal')) {
                return wcs_order_contains_renewal($order);
            }
        } catch (Exception $e) {
            error_log('Sky Insights: Error checking renewal order - ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Safe get subscriptions for order
     */
    protected function get_subscriptions_for_order($order) {
        if (!$this->subscriptions_active) {
            return array();
        }
        
        try {
            if (function_exists('wcs_get_subscriptions_for_order')) {
                return wcs_get_subscriptions_for_order($order);
            }
        } catch (Exception $e) {
            error_log('Sky Insights: Error getting subscriptions for order - ' . $e->getMessage());
        }
        
        return array();
    }
    
    /**
     * Helper method to determine frequency type from billing period and interval
     */
    protected function get_frequency_type($billing_period, $billing_interval) {
        // If subscriptions not active, return 'Once'
        if (!$this->subscriptions_active) {
            return 'Once';
        }
        
        $billing_interval = intval($billing_interval);
        
        if ($billing_interval == 1) {
            switch ($billing_period) {
                case 'day':
                    return 'Daily';
                case 'week':
                    return 'Weekly';
                case 'month':
                    return 'Monthly';
                case 'year':
                    return 'Annually';
                default:
                    return 'Once';
            }
        } else {
            switch ($billing_period) {
                case 'day':
                    return 'Every ' . $billing_interval . ' days';
                case 'week':
                    return 'Every ' . $billing_interval . ' weeks';
                case 'month':
                    if ($billing_interval == 2) {
                        return 'Bimonthly';
                    } elseif ($billing_interval == 3) {
                        return 'Quarterly';
                    } elseif ($billing_interval == 6) {
                        return 'Semi-annually';
                    } else {
                        return 'Every ' . $billing_interval . ' months';
                    }
                case 'year':
                    return 'Every ' . $billing_interval . ' years';
                default:
                    return 'Custom';
            }
        }
    }
    
    /**
     * Add payment median values
     */
    protected function add_payment_median_values($dates, &$filter_data, $filters) {
        foreach ($filter_data as $payment_method => &$payment_data) {
            $amounts = $this->queries->get_payment_median_values($dates, $payment_method, $filters);
            
            if (!empty($amounts)) {
                // Calculate median
                $median_value = $this->calculate_median($amounts);
                
                // For now, put all in onetime_median (subscription check would go here)
                $payment_data['onetime_median'] = array_map('floatval', $amounts);
                $payment_data['recurring_median'] = array();
                
                // If you want to separate recurring payments, you'd need to check subscription status here
                if ($this->subscriptions_active) {
                    // TODO: Separate subscription vs one-time payment medians
                    // This would require additional logic to identify which orders are subscriptions
                }
            } else {
                // If no data, ensure arrays are empty
                $payment_data['onetime_median'] = array();
                $payment_data['recurring_median'] = array();
            }
        }
    }
    
    /**
     * Add country median values
     */
    protected function add_country_median_values($dates, &$filter_data, $filters) {
        foreach ($filter_data as $country => &$country_data) {
            if (!isset($country_data['code'])) continue;
            
            $amounts = $this->queries->get_country_median_values($dates, $country_data['code'], $filters);
            
            if (!empty($amounts)) {
                // For now, put all in onetime_median (subscription check would go here)
                $country_data['onetime_median'] = array_map('floatval', $amounts);
                $country_data['recurring_median'] = array();
            } else {
                // If no data, ensure arrays are empty
                $country_data['onetime_median'] = array();
                $country_data['recurring_median'] = array();
            }
        }
    }
    
    /**
     * Calculate median from array of values
     */
    protected function calculate_median($values) {
        if (empty($values)) {
            return 0;
        }
        
        // Remove any non-numeric values
        $values = array_filter($values, 'is_numeric');
        
        if (empty($values)) {
            return 0;
        }
        
        // Sort values
        sort($values);
        
        $count = count($values);
        $middle = floor(($count - 1) / 2);
        
        if ($count % 2) {
            // Odd number of values
            return $values[$middle];
        } else {
            // Even number of values
            return ($values[$middle] + $values[$middle + 1]) / 2;
        }
    }
    
    /**
     * Calculate average donation with division by zero check
     */
    protected function calculate_average($total, $count) {
        // FIX: Division by zero check
        if (!is_numeric($count) || $count <= 0) {
            return 0;
        }
        
        if (!is_numeric($total) || $total < 0) {
            return 0;
        }
        
        return $total / $count;
    }
    
    /**
     * Get subscription status for admin notice
     */
    public static function get_subscription_status() {
        $status = array(
            'active' => class_exists('WC_Subscriptions'),
            'version' => null,
            'required_version' => '2.0.0',
            'compatible' => true
        );
        
        if ($status['active']) {
            // Check for version property existence
            if (defined('WC_Subscriptions::VERSION')) {
                $status['version'] = WC_Subscriptions::VERSION;
            } elseif (isset(WC_Subscriptions::$version)) {
                $status['version'] = WC_Subscriptions::$version;
            } else {
                // Try to get version from plugin data
                if (function_exists('get_plugin_data')) {
                    $plugin_file = WP_PLUGIN_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
                    if (file_exists($plugin_file)) {
                        $plugin_data = get_plugin_data($plugin_file);
                        $status['version'] = isset($plugin_data['Version']) ? $plugin_data['Version'] : null;
                    }
                }
            }
        }
        
        if ($status['active'] && $status['version']) {
            $status['compatible'] = version_compare($status['version'], $status['required_version'], '>=');
        }
        
        return $status;
    }
}

// Add filter to hide frequencies tab if subscriptions not active
add_filter('sky_insights_tabs', function($tabs) {
    if (!class_exists('WC_Subscriptions')) {
        // Keep frequencies tab but show different content
        add_filter('sky_insights_frequencies_message', function() {
            return __('WooCommerce Subscriptions is required to view frequency data. <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">Learn more</a>', 'sky-insights');
        });
    }
    return $tabs;
});

// Add admin notice if subscriptions features are being used but plugin is missing
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Only show on Sky Insights pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'sky-insights') === false) {
        return;
    }
    
    $status = SkyInsightsProcessorBase::get_subscription_status();
    
    if (!$status['active']) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong><?php esc_html_e('Sky Insights:', 'sky-insights'); ?></strong> <?php esc_html_e('Some features require WooCommerce Subscriptions. Frequency analysis and recurring donation tracking are currently limited.', 'sky-insights'); ?> 
            <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank"><?php esc_html_e('Get WooCommerce Subscriptions', 'sky-insights'); ?></a></p>
        </div>
        <?php
    } elseif (!$status['compatible']) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong><?php esc_html_e('Sky Insights:', 'sky-insights'); ?></strong> <?php printf(
                esc_html__('Your WooCommerce Subscriptions version (%s) may not be fully compatible. Please update to version %s or higher for best results.', 'sky-insights'),
                esc_html($status['version']),
                esc_html($status['required_version'])
            ); ?></p>
        </div>
        <?php
    }
});