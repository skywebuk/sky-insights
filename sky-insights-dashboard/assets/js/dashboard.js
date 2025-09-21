/**
 * Sky Web Insights Dashboard - Main Orchestrator
 * Coordinates all modules and handles initialization
 */
jQuery(document).ready(function($) {
    'use strict';
    
    // Import core modules
    const { State, API, Cache, Init, DataLoader, Helpers } = window.SkyInsights.Core;
    const { DateRangePicker, Filters, Loading, Tabs, ViewToggle, Common } = window.SkyInsights.UI;
    const { SimpleTabs, ComplexTabs } = window.SkyInsights;
    
    // Auto-refresh variables
    let autoRefreshInterval = null;
    
    // Initialize dashboard
    init();
    
    /**
     * Main initialization function
     */
    function init() {
        // Ensure DOM is fully ready before initializing
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeComponents);
        } else {
            // DOM is already ready
            initializeComponents();
        }
    }
    
    /**
     * Initialize all components
     */
    function initializeComponents() {
        // Initialize UI components with a slight delay to ensure DOM is stable
        setTimeout(() => {
            DateRangePicker.init();
            Tabs.bindEvents();
            ViewToggle.bindEvents();
            bindFilterEvents();
            bindExportEvents();
            
            // Set up event listeners
            bindEventListeners();
            
            // Load initial data after UI is ready
            Init.loadInitialData();
            
            // Initialize auto-refresh
            initAutoRefresh();
            
            // Preload other tabs after initial load
            setTimeout(() => Cache.preloadTabs(), 2000);
        }, 100);
    }
    
    /**
     * Bind filter events
     */
    function bindFilterEvents() {
        // Debounced load function
        const debouncedLoad = window.SkyInsightsUtils.debounce(() => {
            State.clearCache();
            DataLoader.load();
        }, 300);
        
        // Filter changes
        $('.sky-header-filters select').on('change', debouncedLoad);
    }
    
    /**
     * Bind export events
     */
    function bindExportEvents() {
        // Export button click
        $('#sky-export-csv').on('click', function(e) {
            e.stopPropagation();
            $('.sky-export-dropdown').toggle();
        });
        
        // Confirm export
        $('#sky-confirm-export-csv').on('click', function() {
            const exportType = $('input[name="export_type"]:checked').val();
            DataLoader.exportData(exportType);
            $('.sky-export-dropdown').hide();
        });
        
        // Cancel export
        $('#sky-cancel-export').on('click', function() {
            $('.sky-export-dropdown').hide();
        });
        
        // Close export dropdown on outside click
        $(document).on('click', function() {
            $('.sky-export-dropdown').hide();
        });
        
        $('.sky-export-controls').on('click', function(e) {
            e.stopPropagation();
        });
    }
    
    /**
     * Auto-refresh handler
     */
    function initAutoRefresh() {
        // Clear any existing interval
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
        
        // Check if auto-refresh should be enabled
        const currentRange = State.get('dateRange');
        const autoRefreshRanges = ['today', 'yesterday'];
        
        if (autoRefreshRanges.includes(currentRange)) {
            // Auto-refresh every 5 minutes for today/yesterday views
            const refreshInterval = 5 * 60 * 1000; // 5 minutes
            
            autoRefreshInterval = setInterval(() => {
                // Only refresh if page is visible
                if (!document.hidden) {
                    console.log('Auto-refreshing dashboard data...');
                    
                    // Show subtle loading indicator
                    const $refreshIndicator = $('<div class="sky-auto-refresh-indicator">Refreshing...</div>')
                        .css({
                            position: 'fixed',
                            top: '10px',
                            right: '10px',
                            background: '#007aff',
                            color: 'white',
                            padding: '5px 15px',
                            borderRadius: '20px',
                            fontSize: '12px',
                            zIndex: 9999,
                            opacity: 0,
                            transition: 'opacity 0.3s'
                        })
                        .appendTo('body');
                    
                    setTimeout(() => $refreshIndicator.css('opacity', 1), 10);
                    
                    // Load fresh data
                    DataLoader.load(false).always(() => {
                        // Update last refresh time
                        updateLastRefreshTime();
                        
                        // Hide and remove indicator
                        $refreshIndicator.css('opacity', 0);
                        setTimeout(() => $refreshIndicator.remove(), 300);
                    });
                }
            }, refreshInterval);
            
            // Add visual indicator that auto-refresh is active
            showAutoRefreshStatus(true);
        } else {
            // Disable auto-refresh for other date ranges
            showAutoRefreshStatus(false);
        }
    }
    
    function showAutoRefreshStatus(enabled) {
        // Remove existing status
        $('.sky-auto-refresh-status').remove();
        
        if (enabled) {
            const $status = $('<div class="sky-auto-refresh-status">')
                .html('<span style="color: #34c759;">●</span> Auto-refresh enabled (5 min)')
                .css({
                    fontSize: '12px',
                    color: '#86868b',
                    marginLeft: '10px',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '5px'
                });
            
            $('.sky-header-controls').append($status);
        }
    }
    
    function updateLastRefreshTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        
        // Update or create last refresh indicator
        let $lastRefresh = $('.sky-last-refresh');
        if ($lastRefresh.length === 0) {
            $lastRefresh = $('<div class="sky-last-refresh">')
                .css({
                    fontSize: '11px',
                    color: '#86868b',
                    marginTop: '5px'
                });
            $('.sky-date-range-display').after($lastRefresh);
        }
        
        $lastRefresh.text('Last updated: ' + timeString);
    }
    
    /**
     * Bind global event listeners
     */
    function bindEventListeners() {
        // Listen for data loaded event
        $(document).on('skyinsights:data:loaded', function(e, data) {
            console.log('Data loaded event received:', data);
            updateDashboard(data);
        });
        
        // Listen for tab change event - Clear cache on tab change
        $(document).on('skyinsights:tab:change', function(e, tab) {
            console.log('Tab change event:', tab);
            State.clearCache(); // Add this line to ensure fresh data
            const cachedData = State.getCachedTabData(tab);
            if (cachedData) {
                updateTabContent(tab, cachedData);
            } else {
                DataLoader.load();
            }
        });
        
        // Listen for view change event
        $(document).on('skyinsights:view:change', function() {
            DataLoader.load();
        });
        
        // Listen for state changes
        $(document).on('skyinsights:statechange', function(e, data) {
            if (data.key === 'dateRange') {
                State.clearCache();
                DataLoader.load();
                // Reinitialize auto-refresh when date range changes
                initAutoRefresh();
            }
        });
        
        // Listen for custom date range change
        $(document).on('skyinsights:daterange:changed', function() {
            State.clearCache();
            DataLoader.load();
        });
        
        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('Page hidden, pausing auto-refresh');
            } else {
                console.log('Page visible, resuming auto-refresh');
                // Optionally refresh immediately when page becomes visible
                if (autoRefreshInterval && State.get('dateRange') === 'today') {
                    DataLoader.load(false);
                }
            }
        });
    }
    
    /**
     * Update dashboard with new data
     */
    function updateDashboard(data) {
        console.log('Updating dashboard with data:', data);
        console.log('Current tab:', State.get('currentTab'));
        console.log('Data has filter_data:', !!data.filter_data);
        
        // Update date range display
        DateRangePicker.updateDisplay(data.date_range);
        
        // Update filter dropdowns if first load
        if (data.filter_options) {
            Filters.updateDropdowns(data.filter_options);
        }
        
        // Update current tab content
        updateTabContent(State.get('currentTab'), data);
        
        // Update last refresh time if auto-refresh is active
        if (autoRefreshInterval) {
            updateLastRefreshTime();
        }
    }
    
    /**
     * Update tab content based on active tab
     */
    function updateTabContent(tab, data) {
        console.log('=== Update Tab Content ===');
        console.log('Tab:', tab);
        console.log('Data received:', data);
        console.log('Has filter_data:', !!data.filter_data);
        console.log('Filter data type:', typeof data.filter_data);
        
        if (data.filter_data) {
            console.log('Filter data keys:', Object.keys(data.filter_data));
            console.log('Filter data sample:', data.filter_data);
        }
        
        // Add fade in animation
        $(`#sky-tab-${tab}`).addClass('sky-fade-in');
        
        // Route to appropriate tab handler
        switch (tab) {
            case 'raised':
                ComplexTabs.Raised.update(data);
                break;
                
            case 'daytime':
                ComplexTabs.Daytime.update(data);
                break;
                
            case 'customers':
                ComplexTabs.Customers.update(data);
                break;
                
            case 'url':
                ComplexTabs.Url.update(data);
                break;
                
            case 'frequencies':
                SimpleTabs.Frequencies.update(data);
                break;
                
            case 'payment':
            case 'payment_methods': // Handle both possible tab names
                console.log('Routing to Payment Methods tab handler');
                SimpleTabs.Payment.update(data);
                break;
                
            case 'countries':
                SimpleTabs.Countries.update(data);
                break;
                
            case 'designations':
                SimpleTabs.Designations.update(data);
                break;
                
            default:
                console.warn('Unknown tab:', tab);
        }
    }
    
    // Debug helper - expose to global scope for debugging
    window.SkyInsightsDashboard = {
        State: State,
        DataLoader: DataLoader,
        getCurrentData: function() {
            return State.getCachedTabData(State.get('currentTab'));
        },
        reloadCurrentTab: function() {
            State.clearCache();
            DataLoader.load();
        },
        debugPaymentMethods: function() {
            const currentTab = State.get('currentTab');
            console.log('Current tab:', currentTab);
            
            const cachedData = State.getCachedTabData('payment');
            console.log('Cached payment data:', cachedData);
            
            if (cachedData && cachedData.filter_data) {
                console.log('Payment methods found:', Object.keys(cachedData.filter_data));
                Object.entries(cachedData.filter_data).forEach(([method, data]) => {
                    console.log(`${method}:`, data);
                });
            } else {
                console.log('No payment data in cache');
            }
        }
    };
});