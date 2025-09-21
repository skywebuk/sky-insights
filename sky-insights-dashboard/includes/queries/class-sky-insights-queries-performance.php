<?php
/**
 * Performance Queries Class - Handles URL and UTM tracking queries
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SkyInsightsQueriesPerformance extends SkyInsightsQueriesBase {
    
    /**
     * Get URL performance data
     */
    public function get_url_data($dates, $filters) {
        global $wpdb;
        
        $date_conditions = $this->get_date_conditions($dates);
        $filter_join = '';
        $filter_where = '';
        
        // Apply filters
        if (!empty($filters['campaign'])) {
            $filter_where = $wpdb->prepare(" AND oim.meta_value = %d", $filters['campaign']);
        }
        
        if (!empty($filters['designation'])) {
            if (strpos($filters['designation'], ' (Tag)') !== false) {
                $tag_name = str_replace(' (Tag)', '', $filters['designation']);
                $filter_join .= "
                    INNER JOIN {$wpdb->term_relationships} tr2 ON oim.meta_value = tr2.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
                ";
                $filter_where .= $wpdb->prepare("
                    AND tt2.taxonomy = 'product_tag'
                    AND t2.name = %s
                ", $tag_name);
            } elseif ($filters['designation'] === 'Uncategorized') {
                $filter_join .= "
                    LEFT JOIN {$wpdb->term_relationships} tr2 ON oim.meta_value = tr2.object_id
                    LEFT JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id AND tt2.taxonomy = 'product_cat'
                ";
                $filter_where .= " AND tt2.term_taxonomy_id IS NULL";
            } else {
                $filter_join .= "
                    INNER JOIN {$wpdb->term_relationships} tr2 ON oim.meta_value = tr2.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
                ";
                $filter_where .= $wpdb->prepare("
                    AND tt2.taxonomy = 'product_cat'
                    AND t2.name = %s
                ", $filters['designation']);
            }
        }
        
        $query = $wpdb->prepare("
            SELECT 
                oim.meta_value as product_id,
                DATE(p.post_date) as order_date,
                COUNT(DISTINCT oi.order_item_id) as item_count,
                SUM(oim2.meta_value) as total_amount,
                p2.post_title as product_name
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_line_total'
            LEFT JOIN {$wpdb->posts} p2 ON oim.meta_value = p2.ID
            {$filter_join}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= %s
            AND p.post_date <= %s
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            {$filter_where}
            GROUP BY oim.meta_value, DATE(p.post_date)
        ", $date_conditions['start'], $date_conditions['end']);
        
        $this->log_query($query, 'URL Data');
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    
    /**
     * Get visitor and conversion data for products
     */
    public function get_product_performance_metrics($product_ids, $dates) {
        global $wpdb;
        
        if (empty($product_ids)) {
            return array();
        }
        
        $date_conditions = $this->get_date_conditions($dates);
        $product_ids_list = implode(',', array_map('intval', $product_ids));
        
        // Get view counts
        $view_query = "
            SELECT 
                post_id as product_id,
                meta_value as view_count
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_views_count'
            AND post_id IN ($product_ids_list)
        ";
        
        $views = $wpdb->get_results($view_query, ARRAY_A);
        
        // Get checkout counts
        $checkout_query = "
            SELECT 
                post_id as product_id,
                meta_value as checkout_count
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_checkouts_count'
            AND post_id IN ($product_ids_list)
        ";
        
        $checkouts = $wpdb->get_results($checkout_query, ARRAY_A);
        
        // Get add to cart counts
        $cart_query = "
            SELECT 
                post_id as product_id,
                meta_value as cart_count
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_add_to_cart_count'
            AND post_id IN ($product_ids_list)
        ";
        
        $carts = $wpdb->get_results($cart_query, ARRAY_A);
        
        // Combine results
        $metrics = array();
        
        foreach ($views as $view) {
            $product_id = $view['product_id'];
            $metrics[$product_id]['views'] = intval($view['view_count']);
        }
        
        foreach ($checkouts as $checkout) {
            $product_id = $checkout['product_id'];
            $metrics[$product_id]['checkouts'] = intval($checkout['checkout_count']);
        }
        
        foreach ($carts as $cart) {
            $product_id = $cart['product_id'];
            $metrics[$product_id]['add_to_cart'] = intval($cart['cart_count']);
        }
        
        return $metrics;
    }
    
    /**
     * Get traffic source breakdown for products
     */
    public function get_product_traffic_sources($product_id, $dates) {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        $source_pattern = '_product_source_%_' . $today;
        
        $query = $wpdb->prepare("
            SELECT 
                meta_key,
                meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id = %d
            AND meta_key LIKE %s
        ", $product_id, $source_pattern);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $sources = array();
        foreach ($results as $result) {
            // Extract source name from meta_key
            preg_match('/_product_source_(.+)_\d{4}-\d{2}-\d{2}/', $result['meta_key'], $matches);
            if (isset($matches[1])) {
                $source = $matches[1];
                $sources[$source] = intval($result['meta_value']);
            }
        }
        
        return $sources;
    }
}