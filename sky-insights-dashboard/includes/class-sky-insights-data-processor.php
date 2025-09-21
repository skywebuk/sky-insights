<?php
/**
 * Data Processing Class - Main Coordinator
 * Delegates to specialized processor classes for better organization
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include processor classes
require_once dirname(__FILE__) . '/processors/class-sky-insights-processor-base.php';
require_once dirname(__FILE__) . '/processors/class-sky-insights-processor-basic.php';
require_once dirname(__FILE__) . '/processors/class-sky-insights-processor-complex.php';

class SkyInsightsDataProcessor {
    
    private $queries;
    private $basic_processor;
    private $complex_processor;
    
    public function __construct() {
        $this->queries = new SkyInsightsDataQueries();
        $this->basic_processor = new SkyInsightsProcessorBasic();
        $this->complex_processor = new SkyInsightsProcessorComplex();
    }
    
    /**
     * Main routing method - delegates to appropriate processor
     */
    public function process_filter_data($filter, $dates, $filters = array()) {
        switch ($filter) {
            // Basic processors
            case 'payment_methods':
                return $this->basic_processor->process_payment_methods($dates, $filters);
                
            case 'countries':
                return $this->basic_processor->process_countries($dates, $filters);
                
            case 'daytime':
                return $this->basic_processor->process_daytime($dates, $filters);
                
            case 'designations':
                return $this->basic_processor->process_designations($dates, $filters);
                
            case 'url':
                return $this->basic_processor->process_url($dates, $filters);
                
            // Complex processors
            case 'frequencies':
                return $this->complex_processor->process_frequencies($dates, $filters);
                
            case 'customers':
                return $this->complex_processor->process_customers($dates, $filters);
                
            default:
                return array();
        }
    }
}