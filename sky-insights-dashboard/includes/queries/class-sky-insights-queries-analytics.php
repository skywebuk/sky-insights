<?php
/**
 * Analytics Queries Class - Handles Day/Time, Customers, and Frequencies queries
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsQueriesAnalytics extends SkyInsightsQueriesBase {
    
    /**
     * Get day and time heatmap data
     */
    public function get_daytime_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // Log the date range for debugging
        error_log('Sky Insights: Getting daytime data for ' . $date_conditions['start'] . ' to ' . $date_conditions['end']);
        
        // Ensure we handle the day of week correctly
        // MySQL DAYOFWEEK returns 1=Sunday, 2=Monday, etc.
        // But JavaScript expects 0=Sunday, 1=Monday, etc.
        // So we subtract 1 to align with JavaScript
        
        $query = $wpdb->prepare("
            SELECT 
                DAYOFWEEK(p.post_date) - 1 as day_of_week,
                HOUR(p.post_date) as hour,
                COUNT(DISTINCT p.ID) as order_count,
                SUM(pm.meta_value) as total_amount
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            {$filter_conditions['where']}
            GROUP BY DAYOFWEEK(p.post_date), HOUR(p.post_date)
            ORDER BY day_of_week, hour
        ", $date_conditions['start'], $date_conditions['end']);
        
        $this->log_query($query, 'Day/Time Data');
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Log results count
        error_log('Sky Insights: Found ' . count($results) . ' daytime data points');
        
        // Validate and fix results
        $valid_results = array();
        foreach ($results as $row) {
            // Fix MySQL's DAYOFWEEK which can return 0 for Sunday after subtraction
            if ($row['day_of_week'] == -1) {
                $row['day_of_week'] = 6; // Convert -1 (Sunday) to 6
            }
            
            // Ensure values are within valid ranges
            if ($row['day_of_week'] >= 0 && $row['day_of_week'] <= 6 && 
                $row['hour'] >= 0 && $row['hour'] <= 23) {
                $valid_results[] = $row;
            } else {
                error_log('Sky Insights: Invalid daytime data - day: ' . $row['day_of_week'] . ', hour: ' . $row['hour']);
            }
        }
        
        return $valid_results;
    }
    
    /**
     * Get customer metrics (top customers, lifetime value, etc.)
     */
    public function get_customer_metrics($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // First get customer summary data
        $query = $wpdb->prepare("
            SELECT 
                pm.meta_value as customer_email,
                COUNT(DISTINCT p.ID) as order_count,
                SUM(DISTINCT pm2.meta_value) as total_value,
                MAX(pm3.meta_value) as first_name,
                MAX(pm4.meta_value) as last_name
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_billing_first_name'
            LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_billing_last_name'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND pm.meta_value IS NOT NULL
            AND pm.meta_value != ''
            {$filter_conditions['where']}
            GROUP BY pm.meta_value
            ORDER BY total_value DESC
            LIMIT 100
        ", $date_conditions['start'], $date_conditions['end']);
        
        $this->log_query($query, 'Customer Metrics');
        
        $customers = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($customers)) {
            return array();
        }
        
        // Get customer emails for batch processing
        $customer_emails = array_map(function($customer) {
            return "'" . esc_sql($customer['customer_email']) . "'";
        }, $customers);
        $customer_emails_list = implode(',', $customer_emails);
        
        // Get first and last order dates for these customers
        $order_dates_query = "
            SELECT 
                pm.meta_value as customer_email,
                MIN(p.post_date) as first_order_date,
                MAX(p.post_date) as last_order_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND pm.meta_value IN ($customer_emails_list)
            GROUP BY pm.meta_value
        ";
        
        $order_dates = $wpdb->get_results($order_dates_query, ARRAY_A);
        
        // Create a map of order dates
        $customer_dates = array();
        foreach ($order_dates as $dates_row) {
            $customer_dates[$dates_row['customer_email']] = array(
                'first_order_date' => $dates_row['first_order_date'],
                'last_order_date' => $dates_row['last_order_date']
            );
        }
        
        // Merge the data
        foreach ($customers as &$customer) {
            $email = $customer['customer_email'];
            if (isset($customer_dates[$email])) {
                $customer['first_order_date'] = $customer_dates[$email]['first_order_date'];
                $customer['last_order_date'] = $customer_dates[$email]['last_order_date'];
            } else {
                $customer['first_order_date'] = $date_conditions['start'];
                $customer['last_order_date'] = $date_conditions['end'];
            }
        }
        
        return $customers;
    }
    
    /**
     * Get daily customer data (new vs returning)
     */
    public function get_daily_customer_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // First, get all customers who placed orders in the date range
        $customers_query = $wpdb->prepare("
            SELECT DISTINCT 
                pm.meta_value as customer_email,
                MIN(p.post_date) as first_order_in_range
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND pm.meta_value IS NOT NULL
            AND pm.meta_value != ''
            {$filter_conditions['where']}
            GROUP BY pm.meta_value
        ", $date_conditions['start'], $date_conditions['end']);
        
        $customers_in_range = $wpdb->get_results($customers_query, ARRAY_A);
        
        if (empty($customers_in_range)) {
            return array();
        }
        
        // Get list of customer emails
        $customer_emails = array_map(function($customer) {
            return "'" . esc_sql($customer['customer_email']) . "'";
        }, $customers_in_range);
        $customer_emails_list = implode(',', $customer_emails);
        
        // Get first order date for these customers (before the date range)
        $first_orders_query = "
            SELECT 
                pm.meta_value as customer_email,
                MIN(p.post_date) as first_order_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND pm.meta_value IN ($customer_emails_list)
            GROUP BY pm.meta_value
        ";
        
        $first_orders = $wpdb->get_results($first_orders_query, ARRAY_A);
        
        // Create a map of customer first order dates
        $customer_first_orders = array();
        foreach ($first_orders as $order) {
            $customer_first_orders[$order['customer_email']] = $order['first_order_date'];
        }
        
        // Get daily customer data
        $daily_query = $wpdb->prepare("
            SELECT 
                DATE(p.post_date) as order_date,
                pm.meta_value as customer_email
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND pm.meta_value IS NOT NULL
            AND pm.meta_value != ''
            {$filter_conditions['where']}
            GROUP BY DATE(p.post_date), pm.meta_value
        ", $date_conditions['start'], $date_conditions['end']);
        
        $daily_results = $wpdb->get_results($daily_query, ARRAY_A);
        
        // Process results to determine new vs returning
        $processed_results = array();
        foreach ($daily_results as $row) {
            $email = $row['customer_email'];
            $order_date = $row['order_date'];
            
            // Check if this is their first order ever
            $first_order_date = isset($customer_first_orders[$email]) ? $customer_first_orders[$email] : $order_date;
            $is_new_customer = (strtotime($first_order_date) >= strtotime($date_conditions['start']));
            
            $processed_results[] = array(
                'order_date' => $order_date,
                'customer_email' => $email,
                'previous_orders' => $is_new_customer ? 0 : 1
            );
        }
        
        return $processed_results;
    }
}