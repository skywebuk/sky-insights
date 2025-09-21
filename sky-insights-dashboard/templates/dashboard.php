<?php
/**
 * Sky Web Insights Dashboard Template
 * UPDATED: Added performance metrics boxes
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap sky-insights-dashboard">
    <header class="sky-insights-header">
        <!-- Top row with title and powered by -->
        <div class="sky-header-top">
            <h1><?php _e('Sky Insights', 'sky-insights'); ?></h1>
            
            <div class="sky-powered-by">
                <a href="https://skywebdesign.co.uk/" target="_blank">
                    Powered by Sky Web
                    <img src="<?php echo SKY_INSIGHTS_PLUGIN_URL; ?>assets/img/logo.svg" alt="Sky Web">
                </a>
            </div>
        </div>
        
        <!-- Controls row with all filters -->
        <div class="sky-header-controls">
            <!-- Date filter -->
            <div class="sky-date-filter">
                <input type="text" class="sky-date-input" id="sky-date-range-picker" readonly>
                <div class="sky-date-presets" style="display: none;">
                    <button data-range="today"><?php _e('Today', 'sky-insights'); ?></button>
                    <button data-range="yesterday"><?php _e('Yesterday', 'sky-insights'); ?></button>
                    <button data-range="last7days" class="active"><?php _e('Last 7 days', 'sky-insights'); ?></button>
                    <button data-range="last14days"><?php _e('Last 14 days', 'sky-insights'); ?></button>
                    <button data-range="last30days"><?php _e('Last 30 days', 'sky-insights'); ?></button>
                    <button data-range="thisweek"><?php _e('This week', 'sky-insights'); ?></button>
                    <button data-range="thismonth"><?php _e('This month', 'sky-insights'); ?></button>
                    <button data-range="thisyear"><?php _e('This year', 'sky-insights'); ?></button>
                    <button data-range="lastweek"><?php _e('Last week', 'sky-insights'); ?></button>
                    <button data-range="lastmonth"><?php _e('Last month', 'sky-insights'); ?></button>
                    <button data-range="lastyear"><?php _e('Last year', 'sky-insights'); ?></button>
                    <button data-range="custom"><?php _e('Custom range', 'sky-insights'); ?></button>
                    <div class="sky-custom-range" style="display: none;">
                        <input type="text" id="sky-date-from" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}">
<input type="text" id="sky-date-to" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}">
                        <button class="sky-apply-range"><?php _e('Apply', 'sky-insights'); ?></button>
                    </div>
                </div>
            </div>
            
            <!-- Export controls -->
            <div class="sky-export-controls">
                <button class="sky-export-btn" id="sky-export-csv" title="<?php _e('Export to CSV', 'sky-insights'); ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 1v9m0 0l3-3m-3 3L5 7m7 7H4a1 1 0 01-1-1v-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php _e('Export', 'sky-insights'); ?></span>
                </button>
                
                <!-- Export options dropdown -->
                <div class="sky-export-dropdown" style="display: none;">
                    <h4><?php _e('Export Options', 'sky-insights'); ?></h4>
                    <label>
                        <input type="radio" name="export_type" value="summary" checked>
                        <?php _e('Summary Only', 'sky-insights'); ?>
                    </label>
                    <label>
                        <input type="radio" name="export_type" value="detailed">
                        <?php _e('Detailed Report', 'sky-insights'); ?>
                    </label>
                    <div class="sky-export-actions">
                        <button class="sky-btn-primary" id="sky-confirm-export-csv">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M8 1v9m0 0l3-3m-3 3L5 7m7 7H4a1 1 0 01-1-1v-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php _e('Export CSV', 'sky-insights'); ?>
                        </button>
                        <button class="sky-btn-secondary" id="sky-cancel-export">
                            <?php _e('Cancel', 'sky-insights'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Other filters -->
            <div class="sky-header-filters">
                <select class="sky-select" id="sky-campaign-filter">
                    <option value=""><?php _e('Campaign', 'sky-insights'); ?></option>
                </select>
                
                <select class="sky-select" id="sky-designation-filter">
                    <option value=""><?php _e('Designation', 'sky-insights'); ?></option>
                </select>
            </div>
        </div>
    </header>
    
    <nav class="sky-navigation-tabs">
        <button class="sky-tab active" data-tab="raised">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M1 15L1 5M5.5 15V1M10 15V8M14.5 15V3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('Raised', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="daytime">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                <path d="M8 4V8L10.5 10.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('Day and time', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="frequencies">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M2 8H6M10 8H14M8 2V6M8 10V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('Frequencies', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="payment">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <rect x="1" y="4" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/>
                <path d="M1 7H15" stroke="currentColor" stroke-width="1.5"/>
            </svg>
            <?php _e('Payment Methods', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="countries">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                <path d="M2 8H14M8 1C8 1 5 4 5 8C5 12 8 15 8 15C8 15 11 12 11 8C11 4 8 1 8 1Z" stroke="currentColor" stroke-width="1.5"/>
            </svg>
            <?php _e('Countries', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="customers">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/>
                <path d="M2 15C2 12 5 10 8 10C11 10 14 12 14 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('Donors', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="designations">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M2 4H7M9 4H14M2 8H5M7 8H14M2 12H10M12 12H14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('Designations', 'sky-insights'); ?>
        </button>
        
        <button class="sky-tab" data-tab="url">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M7 9L9 7M9 7L11 9M9 7V13M5 3H11C12.5 3 14 4.5 14 6C14 7.5 12.5 9 11 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M5 3C3.5 3 2 4.5 2 6C2 7.5 3.5 9 5 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php _e('URL', 'sky-insights'); ?>
        </button>
        
       
    </nav>
    
    <!-- Tab Contents -->
    <div id="sky-tab-raised" class="sky-tab-content active">
        <div class="sky-insights-box">
            <div class="sky-box-header">
                <div>
                    <h2><?php _e('Total Amount Raised', 'sky-insights'); ?></h2>
                    <div class="sky-date-range-display"></div>
                </div>
                <div class="sky-box-controls">
                    <div class="sky-toggle-view">
                        <button class="sky-toggle active" data-view="daily"><?php _e('Daily', 'sky-insights'); ?></button>
                        <button class="sky-toggle" data-view="weekly"><?php _e('Weekly', 'sky-insights'); ?></button>
                    </div>
                </div>
            </div>
            
            <!-- NEW: Performance Metrics Boxes -->
            <div class="sky-performance-metrics">
                <!-- Today's Performance Box -->
                <div class="sky-metric-box sky-todays-performance">
                    <div class="sky-metric-header">
                        <h3><?php _e("Today's Performance", 'sky-insights'); ?></h3>
                        <div class="sky-live-indicator">
                            <span class="sky-live-dot"></span>
                            <span><?php _e('Live', 'sky-insights'); ?></span>
                        </div>
                    </div>
                    <div class="sky-metric-content">
                        <div class="sky-metric-main">
                            <span class="sky-metric-value" id="sky-today-amount">£0</span>
                            <span class="sky-metric-percentage" id="sky-today-percentage">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                    <path d="M6 10L3 7H5V2H7V7H9L6 10Z" fill="currentColor"/>
                                </svg>
                                <span>+0%</span>
                            </span>
                        </div>
                        <div class="sky-metric-details">
                            <span id="sky-today-donations">0 donations</span>
                        </div>
                        <div class="sky-metric-comparison">
                            <span id="sky-today-comparison">vs yesterday</span>
                        </div>
                    </div>
                </div>

                <!-- Period Total Box -->
                <div class="sky-metric-box sky-period-total">
                    <div class="sky-metric-header">
                        <h3><?php _e('Period Total', 'sky-insights'); ?></h3>
                        <button class="sky-metric-info" data-tooltip="Total for selected date range">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 11V7M8 5V5.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                    <div class="sky-metric-content">
                        <div class="sky-metric-period-label" id="sky-period-label">Last 7 days</div>
                        <div class="sky-metric-main">
                            <span class="sky-metric-value" id="sky-period-amount">£0</span>
                        </div>
                        <div class="sky-metric-average">
                            <span id="sky-period-average">Avg per day: £0</span>
                            <span id="sky-period-new-donors">New donors: -</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Insights Box -->
                <div class="sky-metric-box sky-quick-insights">
                    <div class="sky-metric-header">
                        <h3><?php _e('Quick Insights', 'sky-insights'); ?></h3>
                        <div class="sky-insights-icons">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <path d="M10 1L12 7H19L14 10L16 17L10 13L4 17L6 10L1 7H8L10 1Z" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </div>
                    </div>
                    <div class="sky-metric-content">
                        <div class="sky-insight-item">
                            <div class="sky-insight-icon">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M12 2H4C3.44772 2 3 2.44772 3 3V13C3 13.5523 3.44772 14 4 14H12C12.5523 14 13 13.5523 13 13V3C13 2.44772 12.5523 2 12 2Z" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M8 6V10M6 8H10" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                            </div>
                            <div class="sky-insight-text">
                                <span class="sky-insight-label"><?php _e('Avg donation:', 'sky-insights'); ?></span>
                                <span class="sky-insight-value" id="sky-peak-hour">--</span>
                            </div>
                        </div>
                        <div class="sky-insight-item">
                            <div class="sky-insight-icon">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <rect x="1" y="4" width="14" height="10" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M5 1V4M11 1V4M1 7H15" stroke="currentColor" stroke-width="1.5"/>
                                    <circle cx="8" cy="10.5" r="1.5" fill="currentColor"/>
                                </svg>
                            </div>
                            <div class="sky-insight-text">
                                <span class="sky-insight-label"><?php _e('Best day:', 'sky-insights'); ?></span>
                                <span class="sky-insight-value" id="sky-trend">--</span>
                            </div>
                        </div>
                        <div class="sky-insight-item">
                            <div class="sky-insight-icon">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M2 12L6 8L9 10L14 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M10 4H14V8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div class="sky-insight-text">
                                <span class="sky-insight-label"><?php _e('Week growth:', 'sky-insights'); ?></span>
                                <span class="sky-insight-value" id="sky-top-source">--</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="sky-chart-container">
                <canvas id="sky-main-chart"></canvas>
            </div>
        </div>
        
        <div class="sky-insights-row">
            <div class="sky-insights-box sky-mini-box">
                <div class="sky-mini-header">
                    <h3><?php _e('Recurring Donations', 'sky-insights'); ?></h3>
<span class="sky-info-icon" title="All subscription and recurring payments">?</span>
                </div>
                <div class="sky-mini-totals">
                    <div class="sky-mini-amount">£0.00</div>
                    <div class="sky-mini-count">0 instalments</div>
                </div>
                <div class="sky-mini-chart-container">
                    <canvas id="sky-installments-chart"></canvas>
                </div>
            </div>
            
            <div class="sky-insights-box sky-mini-box">
                <div class="sky-mini-header">
                    <h3><?php _e('One-time Donations', 'sky-insights'); ?></h3>
                    <span class="sky-info-icon" title="Single donations without recurring payments">?</span>
                </div>
                <div class="sky-mini-totals">
                    <div class="sky-mini-amount">£0.00</div>
                    <div class="sky-mini-count">0 donations</div>
                </div>
                <div class="sky-mini-chart-container">
                    <canvas id="sky-onetime-chart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Rest of the tab contents remain the same -->
    <div id="sky-tab-daytime" class="sky-tab-content">
        <div class="sky-insights-box">
            <div class="sky-box-header">
                <h2><?php _e('Day and time Analysis', 'sky-insights'); ?></h2>
                <div class="sky-ai-indicator">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 1L10 7H14L11 9L12 15L8 12L4 15L5 9L2 7H6L8 1Z" fill="url(#ai-gradient)" stroke="url(#ai-gradient)" stroke-width="1"/>
                        <defs>
                            <linearGradient id="ai-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#5856d6;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#007aff;stop-opacity:1" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <span><?php _e('AI-Powered Insights', 'sky-insights'); ?></span>
                </div>
            </div>
            <p class="sky-box-description"><?php _e('Smart insights based on your donation patterns', 'sky-insights'); ?></p>
            
            <!-- AI Insights Cards -->
            <div class="sky-ai-insights-container">
                <div class="sky-ai-insight-card sky-best-time">
                    <div class="sky-ai-card-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                            <path d="M12 6V12L16 16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="sky-ai-card-content">
                        <h3><?php _e('Optimal Time Window', 'sky-insights'); ?></h3>
                        <div class="sky-ai-metric">
                            <span class="sky-ai-value" id="sky-best-time-value">--</span>
                            <span class="sky-ai-label" id="sky-best-time-day">--</span>
                        </div>
                        <p class="sky-ai-description" id="sky-best-time-desc">Analyzing patterns...</p>
                    </div>
                </div>
                
                <div class="sky-ai-insight-card sky-peak-day">
                    <div class="sky-ai-card-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path d="M3 12L7 4L11 8L15 3L21 12V20H3V12Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="sky-ai-card-content">
                        <h3><?php _e('Peak Performance Day', 'sky-insights'); ?></h3>
                        <div class="sky-ai-metric">
                            <span class="sky-ai-value" id="sky-peak-day-value">--</span>
                            <span class="sky-ai-label" id="sky-peak-day-percent">--</span>
                        </div>
                        <p class="sky-ai-description" id="sky-peak-day-desc">Calculating optimal day...</p>
                    </div>
                </div>
                
                <div class="sky-ai-insight-card sky-opportunity">
                    <div class="sky-ai-card-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2L15 8L22 9L17 14L18 21L12 18L6 21L7 14L2 9L9 8L12 2Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="sky-ai-card-content">
                        <h3><?php _e('Hidden Opportunity', 'sky-insights'); ?></h3>
                        <div class="sky-ai-metric">
                            <span class="sky-ai-value" id="sky-opportunity-value">--</span>
                            <span class="sky-ai-label" id="sky-opportunity-time">--</span>
                        </div>
                        <p class="sky-ai-description" id="sky-opportunity-desc">Discovering patterns...</p>
                    </div>
                </div>
            </div>
            
            <!-- Heatmap with enhanced design -->
            <div class="sky-heatmap-section">
                <div class="sky-heatmap-header">
                    <h3><?php _e('Donation Activity Heatmap', 'sky-insights'); ?></h3>
                    <div class="sky-heatmap-controls">
                        <button class="sky-heatmap-toggle active" data-view="count"><?php _e('Volume', 'sky-insights'); ?></button>
                        <button class="sky-heatmap-toggle" data-view="amount"><?php _e('Value', 'sky-insights'); ?></button>
                    </div>
                </div>
                
                <div class="sky-heatmap-wrapper">
                    <div class="sky-heatmap-container">
                        <div class="sky-heatmap-days">
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div>Sat</div>
                            <div>Sun</div>
                        </div>
                        <div class="sky-heatmap-content">
                            <div class="sky-heatmap-grid" id="sky-heatmap"></div>
                            <div class="sky-heatmap-hours">
                                <span>12AM</span>
                                <span>3AM</span>
                                <span>6AM</span>
                                <span>9AM</span>
                                <span>12PM</span>
                                <span>3PM</span>
                                <span>6PM</span>
                                <span>9PM</span>
                                <span>11PM</span>
                            </div>
                        </div>
                    </div>
                    <div class="sky-heatmap-legend">
                        <span>Less</span>
                        <div class="sky-heatmap-legend-scale">
                            <div class="sky-heatmap-legend-item" style="background: #ffffff;"></div>
                            <div class="sky-heatmap-legend-item" style="background: rgba(0, 122, 255, 0.2);"></div>
                            <div class="sky-heatmap-legend-item" style="background: rgba(0, 122, 255, 0.4);"></div>
                            <div class="sky-heatmap-legend-item" style="background: rgba(0, 122, 255, 0.6);"></div>
                            <div class="sky-heatmap-legend-item" style="background: rgba(0, 122, 255, 0.8);"></div>
                            <div class="sky-heatmap-legend-item" style="background: #007aff;"></div>
                        </div>
                        <span>More</span>
                    </div>
                </div>
            </div>
            
            <!-- AI Recommendations -->
            <div class="sky-ai-recommendations">
                <h3><?php _e('AI Recommendations', 'sky-insights'); ?></h3>
                <div class="sky-recommendations-grid" id="sky-ai-recommendations">
                    <div class="sky-recommendation-skeleton">
                        <div class="sky-skeleton sky-skeleton-text"></div>
                        <div class="sky-skeleton sky-skeleton-text" style="width: 80%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-frequencies" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('Donation Frequencies', 'sky-insights'); ?></h2>
            <div class="sky-chart-container" style="height: 250px;">
                <canvas id="sky-frequencies-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('Frequency', 'sky-insights'); ?></th>
                            <th><?php _e('Count', 'sky-insights'); ?></th>
                            <th><?php _e('Average', 'sky-insights'); ?></th>
                            <th><?php _e('Median', 'sky-insights'); ?></th>
                            <th><?php _e('Total', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-frequencies-table">
                        <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-payment" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('Payment Methods', 'sky-insights'); ?></h2>
            <div class="sky-chart-container" style="height: 250px;">
                <canvas id="sky-payment-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('Method', 'sky-insights'); ?></th>
                            <th><?php _e('Count', 'sky-insights'); ?></th>
                            <th><?php _e('Average', 'sky-insights'); ?></th>
                            <th><?php _e('Total', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-payment-table">
                        <tr><td colspan="4" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-countries" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('Countries', 'sky-insights'); ?></h2>
            <div class="sky-chart-container" style="height: 250px;">
                <canvas id="sky-countries-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('Country', 'sky-insights'); ?></th>
                            <th><?php _e('Count', 'sky-insights'); ?></th>
                            <th><?php _e('Total', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-countries-table">
                        <tr><td colspan="3" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-customers" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('Donor Analytics', 'sky-insights'); ?></h2>
            
            <div class="sky-customer-overview">
                <div class="sky-metric-card">
                    <h4><?php _e('New Donors', 'sky-insights'); ?></h4>
                    <div class="sky-metric-value sky-new-customers">0</div>
                    <div class="sky-metric-percent sky-new-customers-percent">0%</div>
                    <div class="sky-metric-info"><?php _e('of total donors', 'sky-insights'); ?></div>
                </div>
                
                <div class="sky-metric-card">
                    <h4><?php _e('Returning Donors', 'sky-insights'); ?></h4>
                    <div class="sky-metric-value sky-returning-customers">0</div>
                    <div class="sky-metric-percent sky-returning-customers-percent">0%</div>
                    <div class="sky-metric-info"><?php _e('of total donors', 'sky-insights'); ?></div>
                </div>
                
                <div class="sky-metric-card">
                    <h4><?php _e('Retention Rate', 'sky-insights'); ?></h4>
                    <div class="sky-metric-percent sky-retention-rate">0%</div>
                    <div class="sky-metric-info"><?php _e('donors who gave again', 'sky-insights'); ?></div>
                </div>
                
                <div class="sky-metric-card">
                    <h4><?php _e('Avg Donor Value', 'sky-insights'); ?></h4>
                    <div class="sky-metric-value sky-avg-customer-value">£0</div>
                    <div class="sky-metric-info"><?php _e('lifetime value', 'sky-insights'); ?></div>
                </div>
            </div>
            
            <div class="sky-chart-container" style="height: 250px; margin: 32px 0;">
                <canvas id="sky-customers-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <h3><?php _e('Top Donors', 'sky-insights'); ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('Donor', 'sky-insights'); ?></th>
                            <th><?php _e('First Donation', 'sky-insights'); ?></th>
                            <th><?php _e('Total Donations', 'sky-insights'); ?></th>
                            <th><?php _e('Total Value', 'sky-insights'); ?></th>
                            <th><?php _e('Avg Donation', 'sky-insights'); ?></th>
                            <th><?php _e('Status', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-top-customers-table">
                        <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-designations" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('Designations', 'sky-insights'); ?></h2>
            <div class="sky-chart-container" style="height: 250px;">
                <canvas id="sky-designations-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('Designation', 'sky-insights'); ?></th>
                            <th><?php _e('Count', 'sky-insights'); ?></th>
                            <th><?php _e('Total', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-designations-table">
                        <tr><td colspan="3" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="sky-tab-url" class="sky-tab-content">
        <div class="sky-insights-box">
            <h2><?php _e('URL Performance', 'sky-insights'); ?></h2>
            <div class="sky-chart-container" style="height: 250px;">
                <canvas id="sky-url-chart"></canvas>
            </div>
            
            <div class="sky-data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php _e('URL', 'sky-insights'); ?></th>
                            <th><?php _e('Visitors', 'sky-insights'); ?></th>
                            <th><?php _e('Checkout Opened', 'sky-insights'); ?></th>
                            <th><?php _e('Donations', 'sky-insights'); ?></th>
                            <th><?php _e('Conversion', 'sky-insights'); ?></th>
                            <th><?php _e('Total', 'sky-insights'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sky-url-table">
                        <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="<?php echo SKY_INSIGHTS_PLUGIN_URL; ?>assets/js/dashboard-datepicker.js?ver=<?php echo SKY_INSIGHTS_VERSION; ?>"></script>
</div> <!-- This closing div was missing - closes .wrap -->