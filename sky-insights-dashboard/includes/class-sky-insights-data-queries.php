<?php
/**
 * Data Queries Class - Main coordinator for all query operations
 * This class delegates to specialized query classes for better organization
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include specialized query classes
require_once dirname(__FILE__) . '/queries/class-sky-insights-queries-base.php';
require_once dirname(__FILE__) . '/queries/class-sky-insights-queries-dashboard.php';
require_once dirname(__FILE__) . '/queries/class-sky-insights-queries-analytics.php';
require_once dirname(__FILE__) . '/queries/class-sky-insights-queries-ecommerce.php';
require_once dirname(__FILE__) . '/queries/class-sky-insights-queries-performance.php';

class SkyInsightsDataQueries {
    
    private $dashboard_queries;
    private $analytics_queries;
    private $ecommerce_queries;
    private $performance_queries;
    
    public function __construct() {
        // Initialize specialized query classes
        $this->dashboard_queries = new SkyInsightsQueriesDashboard();
        $this->analytics_queries = new SkyInsightsQueriesAnalytics();
        $this->ecommerce_queries = new SkyInsightsQueriesEcommerce();
        $this->performance_queries = new SkyInsightsQueriesPerformance();
    }
    
    // Dashboard Tab Methods
    public function get_main_metrics($dates, $filters) {
        return $this->dashboard_queries->get_main_metrics($dates, $filters);
    }
    
    public function get_subscription_data($dates, $filters) {
        return $this->dashboard_queries->get_subscription_data($dates, $filters);
    }
    
    public function get_new_donors_count($dates, $filters) {
        return $this->dashboard_queries->get_new_donors_count($dates, $filters);
    }
    
    public function get_all_orders($dates, $filters) {
        return $this->dashboard_queries->get_all_orders($dates, $filters);
    }
    
    public function get_subscription_orders($dates, $filters) {
        return $this->dashboard_queries->get_subscription_orders($dates, $filters);
    }
    
    // Analytics Tab Methods
    public function get_daytime_data($dates, $filters) {
        return $this->analytics_queries->get_daytime_data($dates, $filters);
    }
    
    public function get_customer_metrics($dates, $filters) {
        return $this->analytics_queries->get_customer_metrics($dates, $filters);
    }
    
    public function get_daily_customer_data($dates, $filters) {
        return $this->analytics_queries->get_daily_customer_data($dates, $filters);
    }
    
    // E-commerce Tab Methods
    public function get_payment_methods_data($dates, $filters) {
        return $this->ecommerce_queries->get_payment_methods_data($dates, $filters);
    }
    
    public function get_countries_data($dates, $filters) {
        return $this->ecommerce_queries->get_countries_data($dates, $filters);
    }
    
    public function get_designations_data($dates, $filters) {
        return $this->ecommerce_queries->get_designations_data($dates, $filters);
    }
    
    public function get_payment_median_values($dates, $payment_method, $filters) {
        return $this->ecommerce_queries->get_payment_median_values($dates, $payment_method, $filters);
    }
    
    public function get_country_median_values($dates, $country_code, $filters) {
        return $this->ecommerce_queries->get_country_median_values($dates, $country_code, $filters);
    }
    
    // Performance Tab Methods
    public function get_url_data($dates, $filters) {
        return $this->performance_queries->get_url_data($dates, $filters);
    }
    
    public function get_product_performance_metrics($product_ids, $dates) {
        return $this->performance_queries->get_product_performance_metrics($product_ids, $dates);
    }
    
    public function get_product_traffic_sources($product_id, $dates) {
        return $this->performance_queries->get_product_traffic_sources($product_id, $dates);
    }
}