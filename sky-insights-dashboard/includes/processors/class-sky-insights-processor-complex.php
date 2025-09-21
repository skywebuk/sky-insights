<?php
/**
 * Complex Processor Class - Handles complex tab processors
 * (Frequencies and Customers)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsProcessorComplex extends SkyInsightsProcessorBase {
    
    public function process_frequencies($dates, $filters) {
        // For large date ranges, always use optimized batch processing
        $start_date = new DateTime($dates['start']);
        $end_date = new DateTime($dates['end']);
        $days_diff = $start_date->diff($end_date)->days;
        
        // Use optimized processing for any date range over 7 days
        if ($days_diff > 7) {
            return $this->process_frequencies_optimized($dates, $filters);
        }
        
        // Original processing for smaller date ranges
        $all_orders = $this->queries->get_all_orders($dates, $filters);
        $filter_data = array();
        
        // Initialize frequency data
        $filter_data['Once'] = array(
            'count' => 0,
            'total' => 0,
            'average' => 0,
            'median' => array(),
            'chart_data' => array()
        );
        
        // Debug logging
        error_log('Sky Insights Frequencies - Processing ' . count($all_orders) . ' orders');
        
        // Apply frequency filter if set
        if (!empty($filters['frequency'])) {
            // If a specific frequency is selected, only show that frequency
            if ($filters['frequency'] === 'Once') {
                // Only process one-time orders
                $this->process_onetime_orders($all_orders, $filter_data);
            } else {
                // Handle subscription frequencies
                $this->process_subscription_frequency($all_orders, $filters['frequency'], $filter_data);
            }
        } else {
            // No frequency filter - show all frequencies
            $this->process_all_frequencies($all_orders, $dates, $filter_data);
        }
        
        // Calculate averages and ensure median arrays are numeric
        foreach ($filter_data as $freq => &$freq_data) {
            if ($freq_data['count'] > 0) {
                $freq_data['average'] = $this->calculate_average($freq_data['total'], $freq_data['count']);
                // Ensure median values are numeric
                $freq_data['median'] = array_map('floatval', $freq_data['median']);
            }
            
            // Debug log
            error_log("Sky Insights Frequency - $freq: {$freq_data['count']} orders, Total: {$freq_data['total']}");
        }
        
        return $filter_data;
    }
    
    private function process_onetime_orders($all_orders, &$filter_data) {
        foreach ($all_orders as $order) {
            // Get the actual order object to check for subscriptions
            $order_id = isset($order['order_id']) ? $order['order_id'] : 0;
            if (!$order_id) continue;
            
            $order_obj = wc_get_order($order_id);
            if (!$order_obj) continue;
            
            // Check if this is a subscription order
            $is_subscription = false;
            if (function_exists('wcs_order_contains_subscription')) {
                $is_subscription = wcs_order_contains_subscription($order_obj, array('parent', 'renewal', 'resubscribe', 'switch'));
            }
            
            if (!$is_subscription) {
                $filter_data['Once']['count']++;
                $filter_data['Once']['total'] += floatval(isset($order['total']) ? $order['total'] : 0);
                $filter_data['Once']['median'][] = floatval(isset($order['total']) ? $order['total'] : 0);
                
                // Add to chart data
                $order_date = date('Y-m-d', strtotime($order_obj->get_date_created()));
                if (!isset($filter_data['Once']['chart_data'][$order_date])) {
                    $filter_data['Once']['chart_data'][$order_date] = 0;
                }
                $filter_data['Once']['chart_data'][$order_date] += floatval(isset($order['total']) ? $order['total'] : 0);
            }
            
            // Free memory - FIX: Memory leak
            unset($order_obj);
        }
    }
    
    private function process_subscription_frequency($all_orders, $frequency, &$filter_data) {
        if (!function_exists('wcs_get_subscriptions')) return;
        
        // Get ALL orders that contain subscriptions, not just parent orders
        foreach ($all_orders as $order) {
            $order_id = isset($order['order_id']) ? $order['order_id'] : 0;
            if (!$order_id) continue;
            
            $order_obj = wc_get_order($order_id);
            if (!$order_obj) continue;
            
            // Check if this order has any subscription with the selected frequency
            $has_selected_frequency = false;
            
            // Check parent subscriptions
            $subscriptions = wcs_get_subscriptions_for_order($order_obj);
            if (!empty($subscriptions)) {
                foreach ($subscriptions as $subscription) {
                    $billing_period = $subscription->get_billing_period();
                    $billing_interval = $subscription->get_billing_interval();
                    $type = $this->get_frequency_type($billing_period, $billing_interval);
                    
                    if ($type === $frequency) {
                        $has_selected_frequency = true;
                        break;
                    }
                }
            }
            
            // Also check if this is a renewal order for a subscription with the selected frequency
            if (!$has_selected_frequency && function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order_obj)) {
                $subscriptions = wcs_get_subscriptions_for_renewal_order($order_obj);
                foreach ($subscriptions as $subscription) {
                    $billing_period = $subscription->get_billing_period();
                    $billing_interval = $subscription->get_billing_interval();
                    $type = $this->get_frequency_type($billing_period, $billing_interval);
                    
                    if ($type === $frequency) {
                        $has_selected_frequency = true;
                        break;
                    }
                }
            }
            
            if ($has_selected_frequency) {
                $order_total = floatval(isset($order['total']) ? $order['total'] : 0);
                $order_date = date('Y-m-d', strtotime($order_obj->get_date_created()));
                
                if (!isset($filter_data[$frequency])) {
                    $filter_data[$frequency] = array(
                        'count' => 0,
                        'total' => 0,
                        'average' => 0,
                        'median' => array(),
                        'chart_data' => array()
                    );
                }
                
                $filter_data[$frequency]['count']++;
                $filter_data[$frequency]['total'] += $order_total;
                $filter_data[$frequency]['median'][] = $order_total;
                
                // Add to chart data
                if (!isset($filter_data[$frequency]['chart_data'][$order_date])) {
                    $filter_data[$frequency]['chart_data'][$order_date] = 0;
                }
                $filter_data[$frequency]['chart_data'][$order_date] += $order_total;
            }
            
            // Free memory - FIX: Memory leak
            unset($order_obj);
            unset($subscriptions);
        }
    }
    
    private function process_all_frequencies($all_orders, $dates, &$filter_data) {
        // Track which orders we've already processed to avoid double counting
        $processed_orders = array();
        
        // First, get all subscription parent orders
        if (function_exists('wcs_get_subscriptions')) {
            $subscription_orders = $this->queries->get_subscription_orders($dates, array());
            
            foreach ($subscription_orders as $order_id) {
                if (isset($processed_orders[$order_id])) continue;
                
                $order_obj = wc_get_order($order_id);
                if (!$order_obj) continue;
                
                $subscriptions = wcs_get_subscriptions_for_order($order_obj);
                if (!empty($subscriptions)) {
                    // Use the first subscription's frequency
                    $subscription = reset($subscriptions);
                    $billing_period = $subscription->get_billing_period();
                    $billing_interval = $subscription->get_billing_interval();
                    
                    $type = $this->get_frequency_type($billing_period, $billing_interval);
                    $order_total = floatval($order_obj->get_total());
                    $order_date = date('Y-m-d', strtotime($order_obj->get_date_created()));
                    
                    if (!isset($filter_data[$type])) {
                        $filter_data[$type] = array(
                            'count' => 0,
                            'total' => 0,
                            'average' => 0,
                            'median' => array(),
                            'chart_data' => array()
                        );
                    }
                    
                    $filter_data[$type]['count']++;
                    $filter_data[$type]['total'] += $order_total;
                    $filter_data[$type]['median'][] = $order_total;
                    
                    // Add to chart data
                    if (!isset($filter_data[$type]['chart_data'][$order_date])) {
                        $filter_data[$type]['chart_data'][$order_date] = 0;
                    }
                    $filter_data[$type]['chart_data'][$order_date] += $order_total;
                    
                    $processed_orders[$order_id] = true;
                }
                
                // Free memory - FIX: Memory leak
                unset($order_obj);
                unset($subscriptions);
            }
            
            // Now get renewal orders
            foreach ($all_orders as $order) {
                $order_id = isset($order['order_id']) ? $order['order_id'] : 0;
                if (!$order_id || isset($processed_orders[$order_id])) continue;
                
                $order_obj = wc_get_order($order_id);
                if (!$order_obj) continue;
                
                // Check if this is a renewal order
                if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order_obj)) {
                    $subscriptions = wcs_get_subscriptions_for_renewal_order($order_obj);
                    if (!empty($subscriptions)) {
                        $subscription = reset($subscriptions);
                        $billing_period = $subscription->get_billing_period();
                        $billing_interval = $subscription->get_billing_interval();
                        
                        $type = $this->get_frequency_type($billing_period, $billing_interval);
                        $order_total = floatval(isset($order['total']) ? $order['total'] : 0);
                        $order_date = date('Y-m-d', strtotime($order_obj->get_date_created()));
                        
                        if (!isset($filter_data[$type])) {
                            $filter_data[$type] = array(
                                'count' => 0,
                                'total' => 0,
                                'average' => 0,
                                'median' => array(),
                                'chart_data' => array()
                            );
                        }
                        
                        $filter_data[$type]['count']++;
                        $filter_data[$type]['total'] += $order_total;
                        $filter_data[$type]['median'][] = $order_total;
                        
                        // Add to chart data
                        if (!isset($filter_data[$type]['chart_data'][$order_date])) {
                            $filter_data[$type]['chart_data'][$order_date] = 0;
                        }
                        $filter_data[$type]['chart_data'][$order_date] += $order_total;
                        
                        $processed_orders[$order_id] = true;
                    }
                    
                    // Free memory - FIX: Memory leak
                    unset($subscriptions);
                }
                
                // Free memory - FIX: Memory leak
                unset($order_obj);
            }
        }
        
        // Process remaining orders as one-time
        foreach ($all_orders as $order) {
            $order_id = isset($order['order_id']) ? $order['order_id'] : 0;
            if (!$order_id || isset($processed_orders[$order_id])) continue;
            
            $order_obj = wc_get_order($order_id);
            if (!$order_obj) continue;
            
            // Check if this is truly a one-time order (not a subscription-related order)
            $is_subscription_related = false;
            if (function_exists('wcs_order_contains_subscription')) {
                $is_subscription_related = wcs_order_contains_subscription($order_obj, array('parent', 'renewal', 'resubscribe', 'switch'));
            }
            
            if (!$is_subscription_related) {
                $order_total = floatval(isset($order['total']) ? $order['total'] : 0);
                $order_date = date('Y-m-d', strtotime($order_obj->get_date_created()));
                
                $filter_data['Once']['count']++;
                $filter_data['Once']['total'] += $order_total;
                $filter_data['Once']['median'][] = $order_total;
                
                // Add to chart data
                if (!isset($filter_data['Once']['chart_data'][$order_date])) {
                    $filter_data['Once']['chart_data'][$order_date] = 0;
                }
                $filter_data['Once']['chart_data'][$order_date] += $order_total;
            }
            
            // Free memory - FIX: Memory leak
            unset($order_obj);
        }
    }
    
    /**
     * Optimized frequency processing for large date ranges
     */
    private function process_frequencies_optimized($dates, $filters) {
        global $wpdb;
        
        // Set higher limits for this operation
        @set_time_limit(300); // 5 minutes
        @ini_set('memory_limit', '512M');
        
        $filter_data = array();
        
        // Initialize frequency data
        $filter_data['Once'] = array(
            'count' => 0,
            'total' => 0,
            'average' => 0,
            'median' => array(),
            'chart_data' => array()
        );
        
        // Get aggregated data directly from database
        $start_date = $dates['start'] . ' 00:00:00';
        $end_date = $dates['end'] . ' 23:59:59';
        
        try {
            // First, get subscription order counts by frequency
            if (function_exists('wcs_get_subscriptions')) {
                // Process subscriptions in smaller batches
                $batch_size = 100;
                $offset = 0;
                
                do {
                    // Get subscription parent orders in batches
                    $subscription_query = $wpdb->prepare("
                        SELECT 
                            p.ID,
                            DATE(p.post_date) as order_date,
                            pm.meta_value as order_total
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                        WHERE p.post_type = 'shop_order'
                        AND p.post_status IN ('wc-completed', 'wc-processing')
                        AND p.post_date >= %s
                        AND p.post_date <= %s
                        AND EXISTS (
                            SELECT 1 FROM {$wpdb->posts} p2 
                            WHERE p.ID = p2.post_parent 
                            AND p2.post_type = 'shop_subscription'
                        )
                        ORDER BY p.ID
                        LIMIT %d OFFSET %d
                    ", $start_date, $end_date, $batch_size, $offset);
                    
                    $subscription_orders = $wpdb->get_results($subscription_query);
                    
                    if (empty($subscription_orders)) {
                        break;
                    }
                    
                    // Process this batch
                    foreach ($subscription_orders as $order_data) {
                        // Get order and subscription details
                        $order = wc_get_order($order_data->ID);
                        if (!$order) continue;
                        
                        $subscriptions = wcs_get_subscriptions_for_order($order);
                        if (!empty($subscriptions)) {
                            $subscription = reset($subscriptions);
                            $billing_period = $subscription->get_billing_period();
                            $billing_interval = $subscription->get_billing_interval();
                            
                            $type = $this->get_frequency_type($billing_period, $billing_interval);
                            $order_total = floatval($order_data->order_total);
                            
                            if (!isset($filter_data[$type])) {
                                $filter_data[$type] = array(
                                    'count' => 0,
                                    'total' => 0,
                                    'average' => 0,
                                    'median' => array(),
                                    'chart_data' => array()
                                );
                            }
                            
                            $filter_data[$type]['count']++;
                            $filter_data[$type]['total'] += $order_total;
                            
                            // For large datasets, skip median calculation to save memory
                            if ($filter_data[$type]['count'] <= 100) {
                                $filter_data[$type]['median'][] = $order_total;
                            }
                            
                            // Add to chart data
                            if (!isset($filter_data[$type]['chart_data'][$order_data->order_date])) {
                                $filter_data[$type]['chart_data'][$order_data->order_date] = 0;
                            }
                            $filter_data[$type]['chart_data'][$order_data->order_date] += $order_total;
                        }
                        
                        // Free memory
                        unset($order);
                        unset($subscriptions);
                    }
                    
                    $offset += $batch_size;
                    
                    // Allow other processes to run
                    if (function_exists('wp_cache_flush_runtime')) {
                        wp_cache_flush_runtime();
                    }
                    
                } while (count($subscription_orders) === $batch_size);
                
                // Now process renewal orders using direct SQL for better performance
                $renewal_query = $wpdb->prepare("
                    SELECT 
                        COUNT(DISTINCT p.ID) as count,
                        SUM(pm.meta_value) as total,
                        DATE(p.post_date) as order_date
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                    INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_subscription_renewal'
                    WHERE p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed', 'wc-processing')
                    AND p.post_date >= %s
                    AND p.post_date <= %s
                    AND pm2.meta_value != ''
                    GROUP BY DATE(p.post_date)
                ", $start_date, $end_date);
                
                $renewal_data = $wpdb->get_results($renewal_query);
                
                // For renewal orders, we'll add them to the appropriate frequency buckets
                // This is a simplified approach - ideally we'd match each to its subscription
                foreach ($renewal_data as $renewal) {
                    // Distribute renewals proportionally among existing frequencies
                    // or add to a generic "Recurring" category
                    if (!isset($filter_data['Recurring'])) {
                        $filter_data['Recurring'] = array(
                            'count' => 0,
                            'total' => 0,
                            'average' => 0,
                            'median' => array(),
                            'chart_data' => array()
                        );
                    }
                    
                    $filter_data['Recurring']['count'] += intval($renewal->count);
                    $filter_data['Recurring']['total'] += floatval($renewal->total);
                    
                    if (!isset($filter_data['Recurring']['chart_data'][$renewal->order_date])) {
                        $filter_data['Recurring']['chart_data'][$renewal->order_date] = 0;
                    }
                    $filter_data['Recurring']['chart_data'][$renewal->order_date] += floatval($renewal->total);
                }
            }
            
            // Get one-time orders count using optimized query
            $onetime_query = $wpdb->prepare("
                SELECT 
                    COUNT(DISTINCT p.ID) as count,
                    SUM(pm.meta_value) as total,
                    DATE(p.post_date) as order_date,
                    GROUP_CONCAT(pm.meta_value ORDER BY pm.meta_value SEPARATOR ',') as amounts
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s
                AND p.post_date <= %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->posts} p2 
                    WHERE p.ID = p2.post_parent 
                    AND p2.post_type = 'shop_subscription'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2 
                    WHERE p.ID = pm2.post_id 
                    AND pm2.meta_key = '_subscription_renewal'
                    AND pm2.meta_value != ''
                )
                GROUP BY DATE(p.post_date)
            ", $start_date, $end_date);
            
            $onetime_results = $wpdb->get_results($onetime_query);
            
            foreach ($onetime_results as $onetime_data) {
                $filter_data['Once']['count'] += intval($onetime_data->count);
                $filter_data['Once']['total'] += floatval($onetime_data->total);
                
                // Add to chart data
                if (!isset($filter_data['Once']['chart_data'][$onetime_data->order_date])) {
                    $filter_data['Once']['chart_data'][$onetime_data->order_date] = 0;
                }
                $filter_data['Once']['chart_data'][$onetime_data->order_date] += floatval($onetime_data->total);
                
                // For median calculation, take a sample of amounts
                if ($filter_data['Once']['count'] <= 100 && !empty($onetime_data->amounts)) {
                    $amounts = array_map('floatval', explode(',', $onetime_data->amounts));
                    $filter_data['Once']['median'] = array_merge($filter_data['Once']['median'], array_slice($amounts, 0, 10));
                }
            }
            
        } catch (Exception $e) {
            error_log('Sky Insights Frequencies Optimized Error: ' . $e->getMessage());
            // Return basic data on error
            return $filter_data;
        }
        
        // Calculate averages
        foreach ($filter_data as $freq => &$freq_data) {
            if ($freq_data['count'] > 0) {
                $freq_data['average'] = $this->calculate_average($freq_data['total'], $freq_data['count']);
            }
            
            // For optimized processing, we may have limited median data
            if (empty($freq_data['median']) && $freq_data['count'] > 0) {
                // Use average as median approximation
                $freq_data['median'] = array($freq_data['average']);
            }
        }
        
        return $filter_data;
    }
    
    public function process_customers($dates, $filters) {
        // Check if we have cached customer data for this date range
        $cache_key = 'sky_insights_customers_' . md5(serialize(array($dates, $filters)));
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        // Set higher limits for this operation
        @set_time_limit(180); // 3 minutes
        @ini_set('memory_limit', '256M');
        
        // Get customer metrics
        $customers = $this->queries->get_customer_metrics($dates, $filters);
        $daily_data = $this->queries->get_daily_customer_data($dates, $filters);
        
        $filter_data = array(
            'customer_details' => array(),
            'total_customers' => count($customers),
            'new_customers' => 0,
            'returning_customers' => 0,
            'total_customer_value' => 0,
            'daily' => array()
        );
        
        // Process daily customer data
        $processed_customers = array();
        
        foreach ($daily_data as $row) {
            $order_date = isset($row['order_date']) ? $row['order_date'] : '';
            $email = isset($row['customer_email']) ? $row['customer_email'] : '';
            
            if (empty($order_date) || empty($email)) continue;
            
            if (!isset($filter_data['daily'][$order_date])) {
                $filter_data['daily'][$order_date] = array(
                    'new' => 0,
                    'returning' => 0
                );
            }
            
            $previous_orders = isset($row['previous_orders']) ? intval($row['previous_orders']) : 0;
            
            if ($previous_orders == 0 && !isset($processed_customers[$email])) {
                $filter_data['daily'][$order_date]['new']++;
                $filter_data['new_customers']++;
                $processed_customers[$email] = true;
            } else {
                $filter_data['daily'][$order_date]['returning']++;
            }
        }
        
        $filter_data['returning_customers'] = $filter_data['total_customers'] - $filter_data['new_customers'];
        
        // Process customer details
        foreach ($customers as $customer) {
            $email = isset($customer['customer_email']) ? $customer['customer_email'] : '';
            if (empty($email)) continue;
            
            $first_name = isset($customer['first_name']) ? $customer['first_name'] : '';
            $last_name = isset($customer['last_name']) ? $customer['last_name'] : '';
            
            $filter_data['customer_details'][$email] = array(
                'name' => trim($first_name . ' ' . $last_name),
                'first_order_date' => isset($customer['first_order_date']) ? $customer['first_order_date'] : '',
                'last_order_date' => isset($customer['last_order_date']) ? $customer['last_order_date'] : '',
                'total_orders' => intval(isset($customer['order_count']) ? $customer['order_count'] : 0),
                'total_value' => floatval(isset($customer['total_value']) ? $customer['total_value'] : 0),
                'order_dates' => array()
            );
            
            $filter_data['total_customer_value'] += floatval(isset($customer['total_value']) ? $customer['total_value'] : 0);
        }
        
        // Cache the results for 5 minutes
        set_transient($cache_key, $filter_data, 300);
        
        return $filter_data;
    }
}