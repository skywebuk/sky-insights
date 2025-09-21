<?php
/**
 * E-commerce Queries Class - Handles Payment Methods, Countries, and Designations queries
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsQueriesEcommerce extends SkyInsightsQueriesBase {
    
    /**
     * Get payment methods data
     */
    public function get_payment_methods_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        $query = $wpdb->prepare("
            SELECT 
                pm1.meta_value as payment_method,
                pm2.meta_value as payment_title,
                COUNT(DISTINCT p.ID) as order_count,
                SUM(pm3.meta_value) as total_amount,
                DATE(p.post_date) as order_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_payment_method'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_payment_method_title'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_order_total'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            {$filter_conditions['where']}
            GROUP BY pm1.meta_value, pm2.meta_value, DATE(p.post_date)
        ", $date_conditions['start'], $date_conditions['end']);
        
        $this->log_query($query, 'Payment Methods');
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get countries data
     */
    public function get_countries_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        $query = $wpdb->prepare("
            SELECT 
                pm.meta_value as country_code,
                COUNT(DISTINCT p.ID) as order_count,
                SUM(pm2.meta_value) as total_amount
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            {$filter_conditions['where']}
            GROUP BY pm.meta_value
        ", $date_conditions['start'], $date_conditions['end']);
        
        $this->log_query($query, 'Countries');
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get designations (categories) data - FIXED to exclude Uncategorized completely
     */
    public function get_designations_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_where = '';
        
        // Apply campaign filter if set
        if (!empty($filters['campaign'])) {
            $filter_where = $wpdb->prepare(" AND oim.meta_value = %d", $filters['campaign']);
        }
        
        // Get the default uncategorized category ID
        $uncategorized_id = get_option('default_product_cat');
        
        // Main query - exclude uncategorized products from the start
        $query = $wpdb->prepare("
            SELECT 
                CASE 
                    WHEN t.name IS NULL AND prod.post_parent > 0 THEN 'SKIP_VARIATION'
                    WHEN t.name IS NULL AND (prod.ID IS NULL OR prod.post_type != 'product') THEN 'SKIP_INVALID'
                    ELSE t.name
                END as designation,
                DATE(p.post_date) as order_date,
                COUNT(DISTINCT oi.order_item_id) as item_count,
                SUM(oim2.meta_value) as total_amount
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_line_total'
            LEFT JOIN {$wpdb->posts} prod ON oim.meta_value = prod.ID AND oim.meta_key = '_product_id'
            INNER JOIN {$wpdb->term_relationships} tr ON oim.meta_value = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND tt.term_id != %d
            AND LOWER(t.name) != 'uncategorized'
            {$filter_where}
            GROUP BY designation, DATE(p.post_date)
            HAVING designation NOT LIKE 'SKIP_%'
        ", $date_conditions['start'], $date_conditions['end'], $uncategorized_id);
        
        $this->log_query($query, 'Designations');
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Filter out any remaining uncategorized entries
        $results = array_filter($results, function($row) {
            return strtolower($row['designation']) !== 'uncategorized';
        });
        
        return array_values($results);
    }
    
    /**
     * Get payment method median values
     */
    public function get_payment_median_values($dates, $payment_method, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        // First normalize the payment method for the query
        $normalized_payment_method = $payment_method;
        
        $query = $wpdb->prepare("
            SELECT pm3.meta_value as order_total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_payment_method'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_payment_method_title'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_order_total'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND pm3.meta_value > 0
            AND (
                pm2.meta_value = %s
                OR (pm2.meta_value LIKE %s AND %s = 'Credit Card')
                OR (pm2.meta_value LIKE %s AND %s = 'PayPal')
                OR (pm2.meta_value LIKE %s AND %s = 'Apple Pay')
                OR (pm2.meta_value LIKE %s AND %s = 'Google Pay')
            )
            {$filter_conditions['where']}
            ORDER BY CAST(pm3.meta_value AS DECIMAL(10,2))
        ", 
        $date_conditions['start'], 
        $date_conditions['end'], 
        $normalized_payment_method,
        '%stripe%', $normalized_payment_method,
        '%paypal%', $normalized_payment_method,
        '%apple%', $normalized_payment_method,
        '%google%', $normalized_payment_method
        );
        
        $values = $wpdb->get_col($query);
        
        // Convert to floats and filter out any invalid values
        $values = array_map('floatval', $values);
        $values = array_filter($values, function($v) { return $v > 0; });
        
        return array_values($values); // Re-index array
    }
    
    /**
     * Get country median values
     */
    public function get_country_median_values($dates, $country_code, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_conditions = $this->apply_filters($filters);
        
        $query = $wpdb->prepare("
            SELECT pm2.meta_value as order_total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
            {$filter_conditions['join']}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND pm.meta_value = %s
            AND pm2.meta_value > 0
            {$filter_conditions['where']}
            ORDER BY CAST(pm2.meta_value AS DECIMAL(10,2))
        ", $date_conditions['start'], $date_conditions['end'], $country_code);
        
        $values = $wpdb->get_col($query);
        
        // Convert to floats and filter out any invalid values
        $values = array_map('floatval', $values);
        $values = array_filter($values, function($v) { return $v > 0; });
        
        return array_values($values); // Re-index array
    }
}