<?php
/**
 * Export Handler Class - CSV and PDF exports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsExport {
    
    private $data_handler;
    private $max_export_rows = 10000;
    private $memory_limit_bytes = null;
    
    public function __construct() {
        $this->data_handler = new SkyInsightsData();
        
        // Calculate memory limit
        $this->memory_limit_bytes = $this->parse_memory_limit(ini_get('memory_limit'));
        
        // Add AJAX handlers for export
        add_action('wp_ajax_sky_insights_export_csv', array($this, 'export_csv'));
        add_action('wp_ajax_sky_insights_export_pdf', array($this, 'export_pdf'));
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
     * Check if export size is within limits
     */
    private function check_export_size($data) {
        // Check row count
        if (isset($data['total_count']) && $data['total_count'] > $this->max_export_rows) {
            return false;
        }
        
        // Check memory usage
        $current_memory = memory_get_usage(true);
        $threshold = $this->memory_limit_bytes * 0.5; // 50% threshold for exports
        
        if ($current_memory > $threshold) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Export data as CSV
     */
    public function export_csv() {
        // Clean any existing output
        ob_clean();
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sky_insights_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        // Get parameters
        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'last7days';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : 'summary';
        $current_tab = isset($_POST['current_tab']) ? sanitize_text_field($_POST['current_tab']) : 'raised';
        
        try {
            // Get data
            $data = $this->data_handler->get_insights_data($date_range, $date_from, $date_to, 'daily', $current_tab, array());
            
            // Check export size
            if (!$this->check_export_size($data)) {
                wp_die('Export too large. Please use a smaller date range or contact support for assistance.');
            }
            
            // Generate filename
            $filename = 'sky-insights-' . $current_tab . '-' . date('Y-m-d-His') . '.csv';
            
            // Set headers for download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Add BOM for Excel UTF-8 compatibility
            echo "\xEF\xBB\xBF";
            
            // Open output stream
            $output = fopen('php://output', 'w');
            
            if (!$output) {
                wp_die('Failed to open output stream');
            }
            
            // Generate CSV based on tab
            switch ($current_tab) {
                case 'raised':
                    $this->export_raised_csv($output, $data, $export_type);
                    break;
                case 'daytime':
                    $this->export_daytime_csv($output, $data);
                    break;
                case 'frequencies':
                    $this->export_frequencies_csv($output, $data);
                    break;
                case 'payment_methods':
                case 'payment':
                    $this->export_payment_methods_csv($output, $data);
                    break;
                case 'countries':
                    $this->export_countries_csv($output, $data);
                    break;
                case 'customers':
                    $this->export_customers_csv($output, $data);
                    break;
                case 'designations':
                    $this->export_designations_csv($output, $data);
                    break;
                case 'url':
                    $this->export_url_csv($output, $data);
                    break;
                default:
                    fclose($output);
                    wp_die('Invalid export tab');
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            // Log error
            error_log('Sky Insights Export Error: ' . $e->getMessage());
            wp_die('Export failed: ' . esc_html($e->getMessage()));
        }
    }
    
    /**
     * Export raised tab data
     */
    private function export_raised_csv($output, $data, $export_type) {
        $currency = get_woocommerce_currency_symbol();
        
        // Summary section
        fputcsv($output, array('Sky Insights - Raised Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('Generated:', date('Y-m-d H:i:s')));
        fputcsv($output, array('')); // Empty line
        
        // Summary data
        fputcsv($output, array('Summary'));
        fputcsv($output, array('Metric', 'Value'));
        fputcsv($output, array('Total Raised', $currency . number_format($data['total_amount'], 2)));
        fputcsv($output, array('Total Donations', number_format($data['total_count'])));
        fputcsv($output, array('First Installments', $currency . number_format($data['installments_amount'], 2)));
        fputcsv($output, array('First Installments Count', number_format($data['installments_count'])));
        fputcsv($output, array('One-time Donations', $currency . number_format($data['onetime_amount'], 2)));
        fputcsv($output, array('One-time Count', number_format($data['onetime_count'])));
        fputcsv($output, array('New Donors', number_format($data['new_donors'])));
        fputcsv($output, array('')); // Empty line
        
        // Daily breakdown if requested
        if ($export_type === 'detailed' && isset($data['chart_data'])) {
            fputcsv($output, array('Daily Breakdown'));
            fputcsv($output, array('Date', 'Total', 'First Installments', 'One-time'));
            
            $row_count = 0;
            foreach ($data['chart_data'] as $date => $total) {
                // Limit rows for memory
                if ($row_count++ > $this->max_export_rows) {
                    fputcsv($output, array('...truncated due to size limit...'));
                    break;
                }
                
                $installments = isset($data['installments_chart'][$date]) ? $data['installments_chart'][$date] : 0;
                $onetime = isset($data['onetime_chart'][$date]) ? $data['onetime_chart'][$date] : 0;
                
                fputcsv($output, array(
                    $date,
                    $currency . number_format($total, 2),
                    $currency . number_format($installments, 2),
                    $currency . number_format($onetime, 2)
                ));
            }
        }
    }
    
    /**
     * Export day/time data
     */
    private function export_daytime_csv($output, $data) {
        fputcsv($output, array('Sky Insights - Day and Time Analysis'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        // Headers
        $headers = array('Hour');
        $days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
        foreach ($days as $day) {
            $headers[] = $day;
        }
        fputcsv($output, $headers);
        
        // Data rows
        $heatmap = isset($data['filter_data']['heatmap']) ? $data['filter_data']['heatmap'] : array();
        
        for ($hour = 0; $hour < 24; $hour++) {
            $row = array($hour . ':00');
            for ($day = 0; $day < 7; $day++) {
                $key = $day . '-' . $hour;
                $value = isset($heatmap[$key]) ? $heatmap[$key] : 0;
                $row[] = $value;
            }
            fputcsv($output, $row);
        }
    }
    
    /**
     * Export frequencies data
     */
    private function export_frequencies_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - Frequencies Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        fputcsv($output, array('Frequency', 'Count', 'Total', 'Average'));
        
        if (isset($data['filter_data']) && is_array($data['filter_data'])) {
            $row_count = 0;
            foreach ($data['filter_data'] as $frequency => $freq_data) {
                // Limit rows
                if ($row_count++ > 100) {
                    fputcsv($output, array('...truncated...'));
                    break;
                }
                
                $average = isset($freq_data['count']) && $freq_data['count'] > 0 ? 
                    $freq_data['total'] / $freq_data['count'] : 0;
                
                fputcsv($output, array(
                    $frequency,
                    number_format(isset($freq_data['count']) ? $freq_data['count'] : 0),
                    $currency . number_format(isset($freq_data['total']) ? $freq_data['total'] : 0, 2),
                    $currency . number_format($average, 2)
                ));
            }
        }
    }
    
    /**
     * Export countries data
     */
    private function export_countries_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - Countries Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        fputcsv($output, array('Country', 'Count', 'Total'));
        
        if (isset($data['filter_data']) && is_array($data['filter_data'])) {
            $row_count = 0;
            foreach ($data['filter_data'] as $country => $country_data) {
                // Limit rows
                if ($row_count++ > 250) {
                    fputcsv($output, array('...truncated...'));
                    break;
                }
                
                fputcsv($output, array(
                    $country,
                    number_format(isset($country_data['count']) ? $country_data['count'] : 0),
                    $currency . number_format(isset($country_data['total']) ? $country_data['total'] : 0)
                ));
            }
        }
    }
    
    /**
     * Export payment methods data
     */
    private function export_payment_methods_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - Payment Methods Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        fputcsv($output, array('Payment Method', 'Count', 'Total', 'Average'));
        
        if (isset($data['filter_data']) && is_array($data['filter_data'])) {
            foreach ($data['filter_data'] as $method => $method_data) {
                $average = isset($method_data['count']) && $method_data['count'] > 0 ? 
                    $method_data['total'] / $method_data['count'] : 0;
                
                fputcsv($output, array(
                    $method,
                    number_format(isset($method_data['count']) ? $method_data['count'] : 0),
                    $currency . number_format(isset($method_data['total']) ? $method_data['total'] : 0),
                    $currency . number_format($average, 2)
                ));
            }
        }
    }
    
    /**
     * Export customers data
     */
    private function export_customers_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - Donors Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        // Summary
        if (isset($data['filter_data'])) {
            $customer_data = $data['filter_data'];
            
            fputcsv($output, array('Summary'));
            fputcsv($output, array('Total Donors', number_format(isset($customer_data['total_customers']) ? $customer_data['total_customers'] : 0)));
            fputcsv($output, array('New Donors', number_format(isset($customer_data['new_customers']) ? $customer_data['new_customers'] : 0)));
            fputcsv($output, array('Returning Donors', number_format(isset($customer_data['returning_customers']) ? $customer_data['returning_customers'] : 0)));
            fputcsv($output, array('Total Value', $currency . number_format(isset($customer_data['total_customer_value']) ? $customer_data['total_customer_value'] : 0, 2)));
            fputcsv($output, array('')); // Empty line
            
            // Customer details
            if (isset($customer_data['customer_details']) && is_array($customer_data['customer_details'])) {
                fputcsv($output, array('Donor Details'));
                fputcsv($output, array('Name', 'Email', 'First Donation', 'Last Donation', 'Total Donations', 'Total Value'));
                
                $row_count = 0;
                foreach ($customer_data['customer_details'] as $email => $customer) {
                    // Limit rows
                    if ($row_count++ > 1000) {
                        fputcsv($output, array('...truncated to top 1000 donors...'));
                        break;
                    }
                    
                    fputcsv($output, array(
                        isset($customer['name']) ? $customer['name'] : '',
                        $email,
                        isset($customer['first_order_date']) ? $customer['first_order_date'] : '',
                        isset($customer['last_order_date']) ? $customer['last_order_date'] : '',
                        number_format(isset($customer['total_orders']) ? $customer['total_orders'] : 0),
                        $currency . number_format(isset($customer['total_value']) ? $customer['total_value'] : 0, 2)
                    ));
                }
            }
        }
    }
    
    /**
     * Export designations data
     */
    private function export_designations_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - Designations Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        fputcsv($output, array('Designation', 'Count', 'Total'));
        
        if (isset($data['filter_data']) && is_array($data['filter_data'])) {
            foreach ($data['filter_data'] as $designation => $designation_data) {
                fputcsv($output, array(
                    $designation,
                    number_format(isset($designation_data['count']) ? $designation_data['count'] : 0),
                    $currency . number_format(isset($designation_data['total']) ? $designation_data['total'] : 0)
                ));
            }
        }
    }
    
    /**
     * Export URL data
     */
    private function export_url_csv($output, $data) {
        $currency = get_woocommerce_currency_symbol();
        
        fputcsv($output, array('Sky Insights - URL Performance Report'));
        fputcsv($output, array('Date Range:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']));
        fputcsv($output, array('')); // Empty line
        
        fputcsv($output, array('URL', 'Product', 'Visitors', 'Checkout Opened', 'Donations', 'Conversion %', 'Total'));
        
        if (isset($data['filter_data']) && is_array($data['filter_data'])) {
            $row_count = 0;
            foreach ($data['filter_data'] as $url => $url_data) {
                // Limit rows
                if ($row_count++ > 500) {
                    fputcsv($output, array('...truncated to top 500 URLs...'));
                    break;
                }
                
                $visitors = isset($url_data['visitors']) ? $url_data['visitors'] : 0;
                $donations = isset($url_data['donations']) ? $url_data['donations'] : 0;
                $conversion = $visitors > 0 ? ($donations / $visitors) * 100 : 0;
                
                fputcsv($output, array(
                    $url,
                    isset($url_data['product_name']) ? $url_data['product_name'] : '',
                    number_format($visitors),
                    number_format(isset($url_data['checkout_opened']) ? $url_data['checkout_opened'] : 0),
                    number_format($donations),
                    number_format($conversion, 1) . '%',
                    $currency . number_format(isset($url_data['total']) ? $url_data['total'] : 0)
                ));
            }
        }
    }
    
    /**
     * Export to PDF (placeholder for future implementation)
     */
    public function export_pdf() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sky_insights_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        wp_send_json_error('PDF export is not yet implemented');
    }
}

// Initialize export handler
new SkyInsightsExport();