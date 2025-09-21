<?php
/**
 * Base Query Class - Common functionality for all query classes
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

abstract class SkyInsightsQueriesBase {
    
    /**
     * Apply common filters to queries
     */
    protected function apply_filters($filters) {
        $filter_join = '';
        $filter_where = '';
        
        // Handle campaign filter
        if (!empty($filters['campaign'])) {
            $filter_join = $this->get_campaign_filter_join();
            $filter_where = $this->get_campaign_filter_where($filters['campaign']);
        }
        
        // Handle designation filter
        if (!empty($filters['designation'])) {
            if (empty($filter_join)) {
                $filter_join = $this->get_campaign_filter_join();
            }
            
            $designation_filters = $this->get_designation_filter($filters['designation']);
            $filter_join .= $designation_filters['join'];
            $filter_where .= $designation_filters['where'];
        }
        
        return array(
            'join' => $filter_join,
            'where' => $filter_where
        );
    }
    
    /**
     * Get campaign filter join
     */
    protected function get_campaign_filter_join() {
        global $wpdb;
        return "
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        ";
    }
    
    /**
     * Get campaign filter where clause
     */
    protected function get_campaign_filter_where($campaign_id) {
        global $wpdb;
        return $wpdb->prepare("
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim.meta_value = %d
        ", $campaign_id);
    }
    
    /**
     * Get designation filter
     */
    protected function get_designation_filter($designation) {
        global $wpdb;
        $join = '';
        $where = '';
        
        if (strpos($designation, ' (Tag)') !== false) {
            // Handle tag filter
            $tag_name = str_replace(' (Tag)', '', $designation);
            $join = "
                INNER JOIN {$wpdb->term_relationships} tr ON oim.meta_value = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            ";
            $where = $wpdb->prepare("
                AND oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND tt.taxonomy = 'product_tag'
                AND t.name = %s
            ", $tag_name);
        } elseif ($designation === 'Uncategorized') {
            // Handle uncategorized
            $join = "
                LEFT JOIN {$wpdb->term_relationships} tr ON oim.meta_value = tr.object_id
                LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            ";
            $where = "
                AND oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND tt.term_taxonomy_id IS NULL
            ";
        } else {
            // Regular category
            $join = "
                INNER JOIN {$wpdb->term_relationships} tr ON oim.meta_value = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            ";
            $where = $wpdb->prepare("
                AND oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND tt.taxonomy = 'product_cat'
                AND t.name = %s
            ", $designation);
        }
        
        return array('join' => $join, 'where' => $where);
    }
    
    /**
     * Get date range SQL conditions
     */
    protected function get_date_conditions($dates) {
        $start_date = $dates['start'] . ' 00:00:00';
        $end_date = $dates['end'] . ' 23:59:59';
        
        return array(
            'start' => $start_date,
            'end' => $end_date
        );
    }
    
    /**
     * Log query for debugging
     */
    protected function log_query($query, $context = '') {
        if (defined('SKY_INSIGHTS_DEBUG') && SKY_INSIGHTS_DEBUG) {
            error_log('Sky Insights Query' . ($context ? " ({$context})" : '') . ': ' . $query);
        }
    }
}