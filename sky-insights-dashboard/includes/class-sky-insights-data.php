<?php
/**
 * Data Processing Class - Main Coordinator with Performance Optimizations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsData {
    
    /**
     * Class properties
     */
    private $cache_key_prefix = 'sky_insights_';
    private $cache_expiration = 3600; // 1 hour
    private $batch_size = 500; // Process orders in batches
    private $processor;
    private $queries;
    private $use_persistent_cache = true;
    private $memory_limit_bytes = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize sub-classes
        $this->processor = new SkyInsightsDataProcessor();
        $this->queries = new SkyInsightsDataQueries();
        
        // Check if we should use persistent cache
        $this->use_persistent_cache = !defined('SKY_INSIGHTS_DISABLE_CACHE') || !SKY_INSIGHTS_DISABLE_CACHE;
        
        // Calculate memory limit in bytes
        $this->memory_limit_bytes = $this->parse_memory_limit(ini_get('memory_limit'));
    }
    
    /**
     * Parse memory limit to bytes
     */
    private function parse_memory_limit($memory_limit) {
        if ($memory_limit == -1) {
            return PHP_INT_MAX;
        }
        
        $memory_limit = strtolower($memory_limit);
        $max = (int) $memory_limit;
        
        switch (substr($memory_limit, -1)) {
            case 'g':
                $max *= 1024;
            case 'm':
                $max *= 1024;
            case 'k':
                $max *= 1024;
        }
        
        return $max;
    }
    
    /**
     * Check memory usage and clean if needed
     */
    private function check_memory_usage() {
        $current_usage = memory_get_usage(true);
        $threshold = $this->memory_limit_bytes * 0.8; // 80% threshold
        
        if ($current_usage > $threshold) {
            // Log memory warning
            SkyInsightsUtils::log('Memory usage high: ' . round($current_usage / 1048576, 2) . 'MB', 'warning');
            
            // Try to free memory
            gc_collect_cycles();
            
            // Clear runtime cache
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Main public method to get insights data
     */
    public function get_insights_data($date_range, $custom_from, $custom_to, $view_type, $filter, $filters = array()) {
        // Log the request
        SkyInsightsUtils::log('Getting data for range: ' . $date_range . ', filter: ' . $filter);
        
        // Generate cache key
        $cache_key = $this->generate_cache_key($date_range, $custom_from, $custom_to, $view_type, $filter, $filters);
        
        // Calculate dates
        $dates = SkyInsightsUtils::calculate_date_range($date_range, $custom_from, $custom_to);
        
        // Check if it's a large date range
        $is_large_range = SkyInsightsUtils::is_large_date_range($dates);
        
        // Try cache for non-large ranges or empty filters
        if (!$is_large_range || empty($filters)) {
            $cached_data = $this->get_cached_data($cache_key);
            if ($cached_data !== false && empty($filters)) {
                SkyInsightsUtils::log('Using cached data');
                return $cached_data;
            }
        }
        
        // Check memory before processing
        $this->check_memory_usage();
        
        // Process the data
        $data = $is_large_range ? 
            $this->process_large_date_range($dates, $view_type, $filter, $filters) :
            $this->process_standard_date_range($dates, $view_type, $filter, $filters);
        
        // Log results
        SkyInsightsUtils::log('Final data - Total count: ' . $data['total_count'] . ', Total amount: ' . $data['total_amount']);
        
        // Cache results for non-large ranges
        if (!$is_large_range && empty($filters)) {
            $this->set_cached_data($cache_key, $data);
        }
        
        return $data;
    }
    
    /**
     * Get tab-specific data (simplified wrapper)
     */
    public function get_tab_specific_data($date_range, $filter) {
        return $this->get_insights_data($date_range, '', '', 'daily', $filter, array());
    }
    
    /**
     * Process standard date ranges
     */
    private function process_standard_date_range($dates, $view_type, $filter, $filters) {
        // Initialize data structure
        $data = $this->initialize_data_structure($dates);
        
        // Get main metrics
        $this->populate_main_metrics($data, $dates, $filters);
        
        // Process filter-specific data
        if ($filter !== 'raised') {
            $data['filter_data'] = $this->processor->process_filter_data($filter, $dates, $filters);
        }
        
        // Get subscription data
        $this->populate_subscription_data($data, $dates, $filters);
        
        // Calculate one-time donations
        $this->calculate_onetime_donations($data);
        
        // Convert to weekly if needed
        if ($view_type === 'weekly') {
            $this->convert_charts_to_weekly($data);
        }
    
        return $data;
    }
    
    /**
     * Process large date ranges with chunking
     */
    private function process_large_date_range($dates, $view_type, $filter, $filters) {
        SkyInsightsUtils::log('Starting chunked processing from ' . $dates['start'] . ' to ' . $dates['end']);
        
        // For main metrics, get all data at once for accuracy
        if ($filter === 'raised' || $filter === 'daytime') {
            return $this->process_large_range_optimized($dates, $view_type, $filter, $filters);
        }
        
        // For other tabs, use chunking
        return $this->process_large_range_chunked($dates, $view_type, $filter, $filters);
    }
    
    /**
     * Optimized processing for large ranges (main metrics)
     */
    private function process_large_range_optimized($dates, $view_type, $filter, $filters) {
        $data = $this->initialize_data_structure($dates);
        
        // Check memory before large query
        if ($this->check_memory_usage()) {
            SkyInsightsUtils::log('Memory limit approaching, using minimal processing');
        }
        
        // Get all main metrics at once
        $main_metrics = $this->queries->get_main_metrics($dates, $filters);
        
        $data['total_amount'] = $main_metrics['total_amount'];
        $data['total_count'] = $main_metrics['total_count'];
        $data['chart_data'] = $main_metrics['chart_data'];
        
        // Get subscription data
        $this->populate_subscription_data($data, $dates, $filters);
        
        // Calculate one-time donations
        $this->calculate_onetime_donations($data);
        
        // Get new donors
        $data['new_donors'] = $this->queries->get_new_donors_count($dates, $filters);
        
        // Process filter data if needed
        if ($filter !== 'raised') {
            $data['filter_data'] = $this->processor->process_filter_data($filter, $dates, $filters);
        }
        
        // Convert to weekly if needed
        if ($view_type === 'weekly') {
            $this->convert_charts_to_weekly($data);
        }
        
        return $data;
    }
    
    /**
     * Chunked processing for very large date ranges
     */
    private function process_large_range_chunked($dates, $view_type, $filter, $filters) {
        $data = $this->initialize_data_structure($dates);
        
        // Calculate chunks
        $chunk_days = 30;
        $start_date = new DateTime($dates['start']);
        $end_date = new DateTime($dates['end']);
        
        $current_start = clone $start_date;
        
        while ($current_start <= $end_date) {
            // Check memory before each chunk
            if ($this->check_memory_usage()) {
                SkyInsightsUtils::log('Memory limit reached during chunked processing');
                break;
            }
            
            $current_end = clone $current_start;
            $current_end->modify("+{$chunk_days} days");
            
            if ($current_end > $end_date) {
                $current_end = clone $end_date;
            }
            
            $chunk_dates = array(
                'start' => $current_start->format('Y-m-d'),
                'end' => $current_end->format('Y-m-d')
            );
            
            // Process chunk
            $chunk_data = $this->process_single_chunk($chunk_dates, $filter, $filters);
            
            // Merge chunk data
            $this->merge_chunk_data($data, $chunk_data);
            
            // Move to next chunk
            $current_start->modify("+{$chunk_days} days");
            $current_start->modify("+1 day");
            
            // Clear runtime cache
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }
        
        // Convert to weekly if needed
        if ($view_type === 'weekly') {
            $this->convert_charts_to_weekly($data);
        }
        
        return $data;
    }
    
    /**
     * Process a single date chunk
     */
    private function process_single_chunk($dates, $filter, $filters) {
        $chunk_data = $this->initialize_data_structure($dates);
        
        // Get metrics for this chunk
        $this->populate_main_metrics($chunk_data, $dates, $filters);
        $this->populate_subscription_data($chunk_data, $dates, $filters);
        $this->calculate_onetime_donations($chunk_data);
        
        // Get new donors for chunk
        $chunk_data['new_donors'] = $this->queries->get_new_donors_count($dates, $filters);
        
        // Process filter data if needed
        if ($filter !== 'raised') {
            $chunk_data['filter_data'] = $this->processor->process_filter_data($filter, $dates, $filters);
        }
        
        return $chunk_data;
    }
    
    /**
     * Initialize empty data structure
     */
    private function initialize_data_structure($dates) {
        $data = array(
            'total_amount' => 0,
            'total_count' => 0,
            'installments_amount' => 0,
            'installments_count' => 0,
            'onetime_amount' => 0,
            'onetime_count' => 0,
            'chart_data' => array(),
            'installments_chart' => array(),
            'onetime_chart' => array(),
            'date_range' => $dates,
            'filter_data' => array(),
            'new_donors' => 0
        );
        
        // Initialize chart data for all dates with error handling
        try {
            $period = new DatePeriod(
                new DateTime($dates['start']),
                new DateInterval('P1D'),
                (new DateTime($dates['end']))->modify('+1 day')
            );
            
            foreach ($period as $date) {
                $key = $date->format('Y-m-d');
                $data['chart_data'][$key] = 0;
                $data['installments_chart'][$key] = 0;
                $data['onetime_chart'][$key] = 0;
            }
        } catch (Exception $e) {
            SkyInsightsUtils::log('Error creating date period: ' . $e->getMessage(), 'error');
            // Return data with empty charts
            return $data;
        }
        
        return $data;
    }
    
    /**
     * Populate main metrics
     */
    private function populate_main_metrics(&$data, $dates, $filters) {
        $main_metrics = $this->queries->get_main_metrics($dates, $filters);
        
        $data['total_amount'] = $main_metrics['total_amount'];
        $data['total_count'] = $main_metrics['total_count'];
        $data['chart_data'] = array_merge($data['chart_data'], $main_metrics['chart_data']);
    }
    
    /**
     * Populate subscription data
     */
    private function populate_subscription_data(&$data, $dates, $filters) {
        $subscription_data = $this->queries->get_subscription_data($dates, $filters);
        
        $data['installments_amount'] = $subscription_data['installments_amount'];
        $data['installments_count'] = $subscription_data['installments_count'];
        $data['installments_chart'] = array_merge($data['installments_chart'], $subscription_data['installments_chart']);
    }
    
    /**
     * Calculate one-time donations
     */
    private function calculate_onetime_donations(&$data) {
        $data['onetime_amount'] = $data['total_amount'] - $data['installments_amount'];
        $data['onetime_count'] = $data['total_count'] - $data['installments_count'];
        
        foreach ($data['chart_data'] as $date => $total) {
            $subscription_amount = isset($data['installments_chart'][$date]) ? $data['installments_chart'][$date] : 0;
            $data['onetime_chart'][$date] = $total - $subscription_amount;
        }
    }
    
    /**
     * Convert charts to weekly view
     */
    private function convert_charts_to_weekly(&$data) {
        $data['chart_data'] = $this->convert_to_weekly($data['chart_data']);
        $data['installments_chart'] = $this->convert_to_weekly($data['installments_chart']);
        $data['onetime_chart'] = $this->convert_to_weekly($data['onetime_chart']);
    }
    
    /**
     * Convert daily data to weekly
     */
    private function convert_to_weekly($daily_data) {
        $weekly_data = array();
        $week_total = 0;
        $week_start = null;
        
        foreach ($daily_data as $date => $value) {
            $day_of_week = date('w', strtotime($date));
            
            if ($day_of_week == 1 && $week_start !== null) {
                // Monday - save previous week
                $weekly_data[$week_start] = $week_total;
                $week_total = 0;
            }
            
            if ($week_start === null || $day_of_week == 1) {
                $week_start = $date;
            }
            
            $week_total += $value;
        }
        
        // Save last week
        if ($week_start !== null) {
            $weekly_data[$week_start] = $week_total;
        }
        
        return $weekly_data;
    }
    
    /**
     * Merge chunk data into main data
     */
    private function merge_chunk_data(&$main_data, $chunk_data) {
        // Merge totals
        $main_data['total_amount'] += $chunk_data['total_amount'];
        $main_data['total_count'] += $chunk_data['total_count'];
        $main_data['installments_amount'] += $chunk_data['installments_amount'];
        $main_data['installments_count'] += $chunk_data['installments_count'];
        $main_data['onetime_amount'] += $chunk_data['onetime_amount'];
        $main_data['onetime_count'] += $chunk_data['onetime_count'];
        $main_data['new_donors'] += $chunk_data['new_donors'];
        
        // Merge chart data
        foreach ($chunk_data['chart_data'] as $date => $value) {
            $main_data['chart_data'][$date] = $value;
        }
        
        foreach ($chunk_data['installments_chart'] as $date => $value) {
            $main_data['installments_chart'][$date] = $value;
        }
        
        foreach ($chunk_data['onetime_chart'] as $date => $value) {
            $main_data['onetime_chart'][$date] = $value;
        }
        
        // Merge filter data
        $this->merge_filter_data($main_data, $chunk_data);
    }
    
    /**
     * Merge filter data from chunks
     */
    private function merge_filter_data(&$main_data, $chunk_data) {
        if (!isset($chunk_data['filter_data'])) {
            return;
        }
        
        if (!isset($main_data['filter_data'])) {
            $main_data['filter_data'] = array();
        }
        
        foreach ($chunk_data['filter_data'] as $key => $data) {
            if (!isset($main_data['filter_data'][$key])) {
                $main_data['filter_data'][$key] = $data;
            } else {
                // Merge the data
                if (isset($data['count'])) {
                    $main_data['filter_data'][$key]['count'] += $data['count'];
                }
                if (isset($data['total'])) {
                    $main_data['filter_data'][$key]['total'] += $data['total'];
                }
                
                // Merge chart data if exists
                if (isset($data['chart_data'])) {
                    foreach ($data['chart_data'] as $date => $value) {
                        if (!isset($main_data['filter_data'][$key]['chart_data'][$date])) {
                            $main_data['filter_data'][$key]['chart_data'][$date] = 0;
                        }
                        $main_data['filter_data'][$key]['chart_data'][$date] += $value;
                    }
                }
            }
        }
    }
    
    /**
     * Generate cache key
     */
    public function generate_cache_key($date_range, $custom_from, $custom_to, $view_type, $filter, $filters) {
        return SkyInsightsUtils::generate_cache_key($this->cache_key_prefix, array(
            'v3',
            $date_range,
            $custom_from,
            $custom_to,
            $view_type,
            $filter,
            $filters
        ));
    }
    
    /**
     * Get cached data
     */
    private function get_cached_data($cache_key) {
        if (!$this->use_persistent_cache) {
            return false;
        }
        
        // Try transient first
        $data = get_transient($cache_key);
        
        // Try object cache if available
        if ($data === false && function_exists('wp_cache_get')) {
            $data = wp_cache_get($cache_key, 'sky_insights');
        }
        
        return $data;
    }
    
    /**
     * Set cached data
     */
    private function set_cached_data($cache_key, $data) {
        if (!$this->use_persistent_cache) {
            return;
        }
        
        // Set transient
        set_transient($cache_key, $data, $this->cache_expiration);
        
        // Set object cache if available
        if (function_exists('wp_cache_set')) {
            wp_cache_set($cache_key, $data, 'sky_insights', $this->cache_expiration);
        }
    }
}