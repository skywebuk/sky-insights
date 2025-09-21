<?php
/**
 * Dashboard Queries Class - Handles main dashboard and raised tab queries
 * FIXED: Duplicate order counting when filters are applied
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsQueriesDashboard extends SkyInsightsQueriesBase {
    
    /**
     * Get main metrics (total raised, count, etc.)
     * FIXED: Properly handle duplicate orders when filters are applied
     */
    public function get_main_metrics($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // Log the date range for debugging
        error_log('Sky Insights: Getting main metrics for ' . $date_conditions['start'] . ' to ' . $date_conditions['end']);
        
        // If we have filters that join with order items, we need to handle duplicates
        if (!empty($filter_conditions['join'])) {
            // First, get unique order IDs that match our filters
            $order_ids_query = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                {$filter_conditions['join']}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                {$filter_conditions['where']}
            ", $date_conditions['start'], $date_conditions['end']);
            
            $order_ids = $wpdb->get_col($order_ids_query);
            
            if (empty($order_ids)) {
                return array(
                    'total_amount' => 0,
                    'total_count' => 0,
                    'chart_data' => array()
                );
            }
            
            // Now get the totals for these unique orders
            $order_ids_string = implode(',', array_map('intval', $order_ids));
            
            $query = "
                SELECT 
                    DATE(p.post_date) as order_date,
                    COUNT(DISTINCT p.ID) as order_count,
                    SUM(pm.meta_value) as total_amount
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.ID IN ({$order_ids_string})
                GROUP BY DATE(p.post_date)
            ";
        } else {
            // Simple query when no item-level filters are applied
            $query = $wpdb->prepare("
                SELECT 
                    DATE(p.post_date) as order_date,
                    COUNT(p.ID) as order_count,
                    SUM(pm.meta_value) as total_amount
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                GROUP BY DATE(p.post_date)
            ", $date_conditions['start'], $date_conditions['end']);
        }
        
        $this->log_query($query, 'Main Metrics');
        
        $daily_totals = $wpdb->get_results($query, ARRAY_A);
        
        // Also get the total count directly to ensure accuracy
        if (!empty($filter_conditions['join'])) {
            $total_count_query = $wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID) as total_count
                FROM {$wpdb->posts} p
                {$filter_conditions['join']}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                {$filter_conditions['where']}
            ", $date_conditions['start'], $date_conditions['end']);
        } else {
            $total_count_query = $wpdb->prepare("
                SELECT COUNT(p.ID) as total_count
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
            ", $date_conditions['start'], $date_conditions['end']);
        }
        
        $actual_total_count = $wpdb->get_var($total_count_query);
        
        // Log for debugging
        error_log('Sky Insights: Total orders found: ' . $actual_total_count);
        
        $result = array(
            'total_amount' => 0,
            'total_count' => 0,
            'chart_data' => array()
        );
        
        // Initialize all dates in the range
        $period = new DatePeriod(
            new DateTime($dates['start']),
            new DateInterval('P1D'),
            (new DateTime($dates['end']))->modify('+1 day')
        );
        
        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $result['chart_data'][$key] = 0;
        }
        
        // Process daily totals
        foreach ($daily_totals as $day) {
            $order_date = $day['order_date'];
            $daily_amount = floatval($day['total_amount']);
            $daily_count = intval($day['order_count']);
            
            $result['total_amount'] += $daily_amount;
            $result['total_count'] += $daily_count;
            $result['chart_data'][$order_date] = $daily_amount;
        }
        
        // Use the actual total count from the direct query
        $result['total_count'] = intval($actual_total_count);
        
        // Log final results
        error_log('Sky Insights: Final metrics - Count: ' . $result['total_count'] . ', Amount: ' . $result['total_amount']);
        
        return $result;
    }
    
    /**
     * Get subscription data (first installments)
     * FIXED: Handle duplicates in subscription queries
     */
    public function get_subscription_data($dates, $filters) {
    global $wpdb;
    
    $date_conditions = $this->get_date_conditions($dates);
    $filter_conditions = $this->apply_filters($filters);
    
    $result = array(
        'installments_amount' => 0,
        'installments_count' => 0,
        'installments_chart' => array()
    );
    
    // Check if WooCommerce Subscriptions is active
    if (!function_exists('wcs_get_subscriptions')) {
        return $result;
    }
    
    // Initialize chart data for all dates
    $period = new DatePeriod(
        new DateTime($dates['start']),
        new DateInterval('P1D'),
        (new DateTime($dates['end']))->modify('+1 day')
    );
    
    foreach ($period as $date) {
        $key = $date->format('Y-m-d');
        $result['installments_chart'][$key] = 0;
    }
    
    // Get ALL subscription-related orders (parent + renewals)
    if (!empty($filter_conditions['join'])) {
        // Get unique subscription-related order IDs
        $order_ids_query = $wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND (
                EXISTS (SELECT 1 FROM {$wpdb->posts} p2 WHERE p.ID = p2.post_parent AND p2.post_type = 'shop_subscription')
                OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE p.ID = pm2.post_id AND pm2.meta_key = '_subscription_renewal' AND pm2.meta_value != '')
            )
            {$filter_conditions['where']}
        ", $date_conditions['start'], $date_conditions['end']);
        
        $order_ids = $wpdb->get_col($order_ids_query);
        
        if (!empty($order_ids)) {
            $order_ids_string = implode(',', array_map('intval', $order_ids));
            
            $query = "
                SELECT 
                    DATE(p.post_date) as order_date,
                    COUNT(DISTINCT p.ID) as order_count,
                    SUM(pm.meta_value) as total_amount
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.ID IN ({$order_ids_string})
                GROUP BY DATE(p.post_date)
            ";
            
            $subscription_totals = $wpdb->get_results($query, ARRAY_A);
            
            // Also get total count directly
            $total_sub_count = count($order_ids);
            $result['installments_count'] = $total_sub_count;
        }
    } else {
        // Get both parent subscription orders AND renewal orders
        $query = $wpdb->prepare("
            SELECT 
                DATE(p.post_date) as order_date,
                COUNT(p.ID) as order_count,
                SUM(pm.meta_value) as total_amount
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND (
                EXISTS (SELECT 1 FROM {$wpdb->posts} p2 WHERE p.ID = p2.post_parent AND p2.post_type = 'shop_subscription')
                OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE p.ID = pm2.post_id AND pm2.meta_key = '_subscription_renewal' AND pm2.meta_value != '')
            )
            GROUP BY DATE(p.post_date)
        ", $date_conditions['start'], $date_conditions['end']);
        
        $subscription_totals = $wpdb->get_results($query, ARRAY_A);
        
        // Get total subscription-related orders count
        $count_query = $wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID) as total_count
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND (
                EXISTS (SELECT 1 FROM {$wpdb->posts} p2 WHERE p.ID = p2.post_parent AND p2.post_type = 'shop_subscription')
                OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE p.ID = pm2.post_id AND pm2.meta_key = '_subscription_renewal' AND pm2.meta_value != '')
            )
        ", $date_conditions['start'], $date_conditions['end']);
        
        $result['installments_count'] = intval($wpdb->get_var($count_query));
    }
    
    // Process the totals
    $calculated_total = 0;
    foreach ($subscription_totals as $day) {
        $order_date = $day['order_date'];
        $daily_amount = floatval($day['total_amount']);
        
        $calculated_total += $daily_amount;
        $result['installments_chart'][$order_date] = $daily_amount;
    }
    
    $result['installments_amount'] = $calculated_total;
    
    // Log subscription data
    error_log('Sky Insights: Subscription orders - Count: ' . $result['installments_count'] . ', Amount: ' . $result['installments_amount']);
    
    return $result;
}
    
    /**
     * Get new donors count
     * FIXED: Properly count new donors with filters
     */
    public function get_new_donors_count($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        if (!empty($filter_conditions['join'])) {
            // First get unique customer emails from filtered orders
            $emails_query = $wpdb->prepare("
                SELECT DISTINCT pm.meta_value as email
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
                {$filter_conditions['join']}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                AND pm.meta_value IS NOT NULL
                AND pm.meta_value != ''
                {$filter_conditions['where']}
            ", $date_conditions['start'], $date_conditions['end']);
            
            $filtered_emails = $wpdb->get_col($emails_query);
            
            if (empty($filtered_emails)) {
                return 0;
            }
            
            // Now check which are truly new (first order ever is in the date range)
            $new_donors = 0;
            
            // Process in batches to avoid memory issues
            $batch_size = 100;
            $email_batches = array_chunk($filtered_emails, $batch_size);
            
            foreach ($email_batches as $email_batch) {
                $placeholders = array_fill(0, count($email_batch), '%s');
                $placeholders_str = implode(',', $placeholders);
                
                $query = $wpdb->prepare("
                    SELECT 
                        pm.meta_value as email,
                        MIN(p.post_date) as first_order_date
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
                    WHERE p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed', 'wc-processing')
                    AND pm.meta_value IN ($placeholders_str)
                    GROUP BY pm.meta_value
                    HAVING first_order_date >= %s AND first_order_date <= %s
                ", array_merge($email_batch, array($date_conditions['start'], $date_conditions['end'])));
                
                $new_in_batch = $wpdb->get_results($query);
                $new_donors += count($new_in_batch);
            }
            
            return $new_donors;
        } else {
            // Original query when no filters
            $query = $wpdb->prepare("
                SELECT COUNT(DISTINCT email) as new_donors
                FROM (
                    SELECT 
                        pm.meta_value as email,
                        MIN(p.post_date) as first_order_date
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
                    WHERE p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed', 'wc-processing')
                    AND pm.meta_value IS NOT NULL
                    AND pm.meta_value != ''
                    GROUP BY pm.meta_value
                    HAVING first_order_date >= %s AND first_order_date <= %s
                ) as new_customers
            ", $date_conditions['start'], $date_conditions['end']);
            
            $count = intval($wpdb->get_var($query));
            
            // Log new donors count
            error_log('Sky Insights: New donors found: ' . $count);
            
            return $count;
        }
    }
    
    /**
     * Get all orders for frequency analysis
     * FIXED: Return unique orders only
     */
    public function get_all_orders($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // For large date ranges, use pagination
        $start_date = new DateTime($dates['start']);
        $end_date = new DateTime($dates['end']);
        $days_diff = $start_date->diff($end_date)->days;
        
        if ($days_diff > 90) {
            // Return limited data for frequency analysis
            return $this->get_orders_summary($dates, $filters);
        }
        
        if (!empty($filter_conditions['join'])) {
            // Get unique order IDs first
            $order_ids_query = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                {$filter_conditions['join']}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                {$filter_conditions['where']}
                LIMIT 5000
            ", $date_conditions['start'], $date_conditions['end']);
            
            $order_ids = $wpdb->get_col($order_ids_query);
            
            if (empty($order_ids)) {
                return array();
            }
            
            $order_ids_string = implode(',', array_map('intval', $order_ids));
            
            $query = "
                SELECT 
                    p.ID as order_id,
                    pm.meta_value as total
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.ID IN ({$order_ids_string})
            ";
        } else {
            $query = $wpdb->prepare("
                SELECT 
                    p.ID as order_id,
                    pm.meta_value as total
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                LIMIT 5000
            ", $date_conditions['start'], $date_conditions['end']);
        }
        
        $this->log_query($query, 'All Orders');
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get orders summary for large date ranges
     */
    private function get_orders_summary($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        
        // Just return a sample of orders for frequency analysis
        $query = $wpdb->prepare("
            SELECT 
                p.ID as order_id,
                pm.meta_value as total
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            ORDER BY RAND()
            LIMIT 1000
        ", $date_conditions['start'], $date_conditions['end']);
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get subscription order IDs
     * FIXED: Return unique subscription orders
     */
    public function get_subscription_orders($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        if (!empty($filter_conditions['join'])) {
            $query = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->posts} p2 ON p.ID = p2.post_parent AND p2.post_type = 'shop_subscription'
                {$filter_conditions['join']}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                {$filter_conditions['where']}
                LIMIT 1000
            ", $date_conditions['start'], $date_conditions['end']);
        } else {
            $query = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->posts} p2 ON p.ID = p2.post_parent AND p2.post_type = 'shop_subscription'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                LIMIT 1000
            ", $date_conditions['start'], $date_conditions['end']);
        }
        
        $this->log_query($query, 'Subscription Orders');
        
        return $wpdb->get_col($query);
    }
    
   
}