/**
 * Sky Insights Dashboard - Core Module - FIXED VERSION
 * Handles state management, API calls, and core initialization
 */

(function($) {
    'use strict';
    
    // Create namespace
    window.SkyInsights = window.SkyInsights || {};
    
    /**
     * State Management - FIXED
     */
    const State = {
        data: {
            currentView: 'daily',
            currentTab: 'raised',
            dateRange: 'last7days',
            isInitialLoad: true,
            tabDataCache: {},
            filters: {
                campaign: '',
                designation: '',
                source: '',
                frequency: ''
            },
            customDateFrom: '',
            customDateTo: '',
            isLoading: false,
            loadingTab: null,
            currentRequestId: 0,
            lastError: null,
            connectionStatus: 'online'
        },
        
        listeners: {},
        
        get: function(key) {
            return key ? this.data[key] : this.data;
        },
        
        set: function(key, value) {
            if (this.data.hasOwnProperty(key)) {
                const oldValue = this.data[key];
                this.data[key] = value;
                
                // Trigger change event only if value actually changed
                if (JSON.stringify(oldValue) !== JSON.stringify(value)) {
                    $(document).trigger('skyinsights:statechange', {key: key, value: value, oldValue: oldValue});
                    
                    // Call registered listeners
                    if (this.listeners[key]) {
                        this.listeners[key].forEach(callback => {
                            try {
                                callback(value, oldValue);
                            } catch (error) {
                                console.error('State listener error:', error);
                            }
                        });
                    }
                }
            } else {
                console.warn('Attempting to set unknown state key:', key);
            }
        },
        
        onChange: function(key, callback) {
            if (!this.listeners[key]) {
                this.listeners[key] = [];
            }
            this.listeners[key].push(callback);
        },
        
        updateFilters: function(filters) {
            const oldFilters = {...this.data.filters};
            this.data.filters = {...this.data.filters, ...filters};
            
            // Only trigger if actually changed
            if (JSON.stringify(oldFilters) !== JSON.stringify(this.data.filters)) {
                $(document).trigger('skyinsights:filterschange', this.data.filters);
            }
        },
        
        clearCache: function(tabOnly = null) {
            if (tabOnly) {
                delete this.data.tabDataCache[tabOnly];
            } else {
                this.data.tabDataCache = {};
            }
            console.log('Cache cleared:', tabOnly || 'all');
        },
        
        cacheTabData: function(tab, data) {
            // Don't cache if there was an error
            if (!data || data.error) {
                return;
            }
            
            // Create cache key including filters
            const cacheKey = this.getCacheKey(tab);
            
            this.data.tabDataCache[cacheKey] = {
                data: data,
                timestamp: Date.now()
            };
        },
        
        getCachedTabData: function(tab, maxAge = 5 * 60 * 1000) {
            const cacheKey = this.getCacheKey(tab);
            const cached = this.data.tabDataCache[cacheKey];
            
            if (cached) {
                const cacheAge = Date.now() - cached.timestamp;
                if (cacheAge < maxAge) {
                    console.log('Using cached data for tab:', tab, 'Age:', Math.round(cacheAge / 1000), 's');
                    return cached.data;
                } else {
                    // Cache expired
                    delete this.data.tabDataCache[cacheKey];
                    console.log('Cache expired for tab:', tab);
                }
            }
            return null;
        },
        
        getCacheKey: function(tab) {
            // Include all relevant state in cache key
            return `${tab}_${this.data.dateRange}_${this.data.customDateFrom}_${this.data.customDateTo}_${JSON.stringify(this.data.filters)}`;
        },
        
        reset: function() {
            this.data = {
                currentView: 'daily',
                currentTab: 'raised',
                dateRange: 'last7days',
                isInitialLoad: true,
                tabDataCache: {},
                filters: {
                    campaign: '',
                    designation: '',
                    source: '',
                    frequency: ''
                },
                customDateFrom: '',
                customDateTo: '',
                isLoading: false,
                loadingTab: null,
                currentRequestId: 0,
                lastError: null,
                connectionStatus: 'online'
            };
        }
    };
    
    /**
     * API Handler - FIXED
     */
    const API = {
        // Track active requests to prevent duplicates
        activeRequests: {},
        requestQueue: [],
        isProcessingQueue: false,
        currentRequestId: 0,
        maxRetries: 3,
        retryDelay: 1000,
        
        makeRequest: function(data, retryCount = 0) {
            // Generate unique request ID
            const requestId = ++this.currentRequestId;
            const requestKey = this.getRequestKey(data);
            
            // Cancel any existing request for the same data
            if (this.activeRequests[requestKey]) {
                console.log('Cancelling duplicate request:', requestKey);
                this.activeRequests[requestKey].abort();
                delete this.activeRequests[requestKey];
            }
            
            // Calculate timeout based on date range
            const timeout = this.calculateTimeout(data);
            
            // Store request ID in state
            State.set('currentRequestId', requestId);
            
            // Add CSRF token validation
            if (!skyInsights.nonce) {
                console.error('Security nonce missing');
                return $.Deferred().reject('Security validation failed');
            }
            
            // Sanitize request data
            const sanitizedData = this.sanitizeRequestData(data);
            
            // Create the AJAX request
            const request = $.ajax({
                url: skyInsights.ajaxurl,
                type: 'POST',
                timeout: timeout,
                data: {
                    ...sanitizedData,
                    nonce: skyInsights.nonce,
                    request_id: requestId
                },
                beforeSend: function(xhr) {
                    // Add custom headers for better debugging
                    xhr.setRequestHeader('X-Sky-Insights-Request-ID', requestId);
                    xhr.setRequestHeader('X-Sky-Insights-Version', skyInsights.version || '1.0.0');
                    
                    State.set('isLoading', true);
                    State.set('loadingTab', data.filter);
                },
                success: function(response) {
                    // Only process if this is still the current request
                    if (State.get('currentRequestId') !== requestId) {
                        console.log('Ignoring outdated response for request:', requestId);
                        return;
                    }
                    
                    // Validate response
                    if (!API.validateResponse(response)) {
                        throw new Error('Invalid response format');
                    }
                    
                    // Process successful response
                    if (response && response.success) {
                        State.set('lastError', null);
                        State.set('connectionStatus', 'online');
                        return response;
                    } else {
                        throw new Error(response?.data?.message || 'Unknown error occurred');
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    // Don't process if this is not the current request
                    if (State.get('currentRequestId') !== requestId) {
                        return;
                    }
                    
                    // Don't show error for aborted requests
                    if (textStatus === 'abort') {
                        console.log('Request aborted:', requestKey);
                        return;
                    }
                    
                    // Handle retry logic
                    if (retryCount < API.maxRetries && API.shouldRetry(xhr, textStatus)) {
                        console.log(`Retrying request (${retryCount + 1}/${API.maxRetries}):`, requestKey);
                        
                        setTimeout(() => {
                            API.makeRequest(data, retryCount + 1);
                        }, API.retryDelay * (retryCount + 1));
                        
                        return;
                    }
                    
                    // Log detailed error information
                    const errorDetails = {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        textStatus: textStatus,
                        errorThrown: errorThrown,
                        responseText: xhr.responseText,
                        requestData: data
                    };
                    
                    console.error('Sky Insights AJAX Error:', errorDetails);
                    
                    // Update connection status
                    if (xhr.status === 0 && navigator.onLine === false) {
                        State.set('connectionStatus', 'offline');
                    }
                    
                    // Handle specific error types
                    const errorMessage = API.getErrorMessage(xhr, textStatus, errorThrown);
                    
                    State.set('lastError', {
                        message: errorMessage,
                        details: errorDetails,
                        timestamp: Date.now()
                    });
                    
                    // Show error notification
                    API.showErrorNotification(errorMessage);
                },
                complete: function() {
                    // Clean up request tracking
                    delete API.activeRequests[requestKey];
                    
                    // Only update loading state if this was the current request
                    if (State.get('currentRequestId') === requestId) {
                        State.set('isLoading', false);
                        State.set('loadingTab', null);
                    }
                }
            });
            
            // Store the request
            this.activeRequests[requestKey] = request;
            
            return request;
        },
        
        sanitizeRequestData: function(data) {
            const sanitized = {};
            
            // Whitelist allowed keys
            const allowedKeys = [
                'action', 'filter', 'date_range', 'date_from', 'date_to',
                'view_type', 'filters', 'minimal', 'nonce', 'request_id',
                'export_type', 'current_tab'
            ];
            
            for (const key of allowedKeys) {
                if (data.hasOwnProperty(key)) {
                    if (typeof data[key] === 'object') {
                        // Recursively sanitize objects
                        sanitized[key] = this.sanitizeObject(data[key]);
                    } else {
                        // Sanitize strings
                        sanitized[key] = this.sanitizeValue(data[key]);
                    }
                }
            }
            
            return sanitized;
        },
        
        sanitizeObject: function(obj) {
            const sanitized = {};
            for (const key in obj) {
                if (obj.hasOwnProperty(key)) {
                    sanitized[key] = this.sanitizeValue(obj[key]);
                }
            }
            return sanitized;
        },
        
        sanitizeValue: function(value) {
            if (typeof value === 'string') {
                // Remove any HTML tags and trim
                return value.replace(/<[^>]*>/g, '').trim();
            }
            return value;
        },
        
        validateResponse: function(response) {
            // Check basic structure
            if (!response || typeof response !== 'object') {
                return false;
            }
            
            // Check for success flag
            if (!response.hasOwnProperty('success')) {
                return false;
            }
            
            // If successful, check for data
            if (response.success && !response.hasOwnProperty('data')) {
                return false;
            }
            
            return true;
        },
        
        shouldRetry: function(xhr, textStatus) {
            // Retry on timeout or server errors
            if (textStatus === 'timeout') return true;
            if (xhr.status >= 500 && xhr.status < 600) return true;
            if (xhr.status === 0 && navigator.onLine) return true; // Network hiccup
            
            return false;
        },
        
        getRequestKey: function(data) {
            return `${data.action}_${data.filter}_${data.date_range}_${JSON.stringify(data.filters || {})}`;
        },
        
        calculateTimeout: function(data) {
            let timeout = 30000; // Default 30 seconds
            
            if (data.date_range === 'thisyear' || data.date_range === 'lastyear') {
                timeout = 120000; // 2 minutes for year data
            } else if (data.date_range === 'last30days' || data.date_range === 'thismonth' || data.date_range === 'lastmonth') {
                timeout = 60000; // 1 minute for month data
            } else if (data.date_range === 'custom') {
                // Calculate timeout based on date range span
                if (data.date_from && data.date_to) {
                    const daysDiff = Math.ceil((new Date(data.date_to) - new Date(data.date_from)) / (1000 * 60 * 60 * 24));
                    if (daysDiff > 180) {
                        timeout = 120000; // 2 minutes for 6+ months
                    } else if (daysDiff > 60) {
                        timeout = 90000; // 1.5 minutes for 2+ months
                    } else if (daysDiff > 30) {
                        timeout = 60000; // 1 minute for 1+ month
                    }
                }
            }
            
            return timeout;
        },
        
        getErrorMessage: function(xhr, textStatus, errorThrown) {
            let errorMessage = 'An error occurred while loading data.';
            
            if (textStatus === 'timeout') {
                errorMessage = 'The request is taking too long. Please try a smaller date range or refresh the page.';
            } else if (xhr.status === 0) {
                if (navigator.onLine === false) {
                    errorMessage = 'No internet connection. Please check your connection and try again.';
                } else {
                    errorMessage = 'Connection lost. Please check your internet and try again.';
                }
            } else if (xhr.status === 403) {
                errorMessage = 'Session expired. Please refresh the page and log in again.';
            } else if (xhr.status === 404) {
                errorMessage = 'The requested endpoint was not found. Please refresh the page.';
            } else if (xhr.status === 500) {
                errorMessage = 'Server error occurred. Please refresh the page and try again.';
            } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            }
            
            return errorMessage;
        },
        
        showErrorNotification: function(message) {
            // Use UI module if available
            if (window.SkyInsights.UI && window.SkyInsights.UI.Common) {
                window.SkyInsights.UI.Common.showToast(message, 'error');
            } else {
                // Fallback notification
                this.showFallbackError(message);
            }
        },
        
        showFallbackError: function(message) {
            // Remove any existing error notifications
            $('.sky-error-notification').remove();
            
            // Create error notification with safe HTML
            const $notification = $('<div class="sky-error-notification">')
                .append(
                    $('<div class="sky-error-content">')
                        .append($('<span class="sky-error-icon">').text('⚠️'))
                        .append($('<span class="sky-error-message">').text(message))
                        .append($('<button class="sky-error-close">').html('&times;'))
                )
                .css({
                    position: 'fixed',
                    top: '20px',
                    right: '20px',
                    background: '#ff3b30',
                    color: 'white',
                    padding: '15px 20px',
                    borderRadius: '8px',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    zIndex: 10000,
                    maxWidth: '400px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px'
                });
            
            // Add close functionality
            $notification.find('.sky-error-close').on('click', function() {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Append to body and auto-hide after 5 seconds
            $('body').append($notification);
            setTimeout(() => {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        loadDashboardData: function(params) {
            // Validate required parameters
            if (!params.filter) {
                console.error('No filter specified for dashboard data');
                return $.Deferred().reject('No filter specified');
            }
            
            // CRITICAL FIX: Map 'payment' tab to 'payment_methods' filter
            let filterName = params.filter;
            if (filterName === 'payment') {
                filterName = 'payment_methods';
                console.log('Mapping payment tab to payment_methods filter');
            }
            
            const requestData = {
                action: 'sky_insights_get_data',
                date_range: params.date_range || State.get('dateRange'),
                date_from: params.date_from || State.get('customDateFrom'),
                date_to: params.date_to || State.get('customDateTo'),
                view_type: params.view_type || State.get('currentView'),
                filter: filterName,
                filters: params.filters || State.get('filters'),
                minimal: params.minimal || false
            };
            
            // Validate custom dates
            if (requestData.date_range === 'custom') {
                const validation = this.validateCustomDates(requestData.date_from, requestData.date_to);
                if (!validation.valid) {
                    console.error('Custom date validation failed:', validation.error);
                    this.showErrorNotification(validation.error);
                    return $.Deferred().reject(validation.error);
                }
            }
            
            // Check for large date ranges
            if (this.isLargeDataRange(requestData) && !params.confirmed) {
                this.showLargeDataWarning(requestData);
            }
            
            // Log the request for debugging
            console.log('Loading dashboard data:', requestData);
            
            return this.makeRequest(requestData);
        },
        
        loadTabData: function(tab, dateRange) {
            // Validate tab parameter
            if (!tab) {
                console.error('No tab specified for tab data');
                return $.Deferred().reject('No tab specified');
            }
            
            // Check if we're already loading this tab
            if (State.get('loadingTab') === tab) {
                console.log('Already loading tab:', tab);
                return $.Deferred().resolve({ fromCache: true });
            }
            
            // Check cache first
            const cachedData = State.getCachedTabData(tab);
            if (cachedData && !this.shouldRefreshCache(tab)) {
                console.log('Using cached data for tab:', tab);
                return $.Deferred().resolve({ success: true, data: cachedData, fromCache: true });
            }
            
            // CRITICAL FIX: Map 'payment' tab to 'payment_methods' filter
            let filterName = tab;
            if (tab === 'payment') {
                filterName = 'payment_methods';
                console.log('Mapping payment tab to payment_methods filter for tab data');
            }
            
            const requestData = {
                action: 'sky_insights_get_tab_data',
                filter: filterName,
                date_range: dateRange || State.get('dateRange'),
                date_from: State.get('customDateFrom'),
                date_to: State.get('customDateTo'),
                filters: State.get('filters')
            };
            
            // Validate custom dates
            if (requestData.date_range === 'custom') {
                const validation = this.validateCustomDates(requestData.date_from, requestData.date_to);
                if (!validation.valid) {
                    return $.Deferred().reject(validation.error);
                }
            }
            
            // Log the request for debugging
            console.log('Loading tab data:', requestData);
            
            return this.makeRequest(requestData);
        },
        
        validateCustomDates: function(dateFrom, dateTo) {
            if (!dateFrom || !dateTo) {
                return { valid: false, error: 'Please select both start and end dates' };
            }
            
            // Ensure dates are in correct format (YYYY-MM-DD)
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (!dateRegex.test(dateFrom) || !dateRegex.test(dateTo)) {
                return { valid: false, error: 'Invalid date format. Use YYYY-MM-DD' };
            }
            
            const from = new Date(dateFrom);
            const to = new Date(dateTo);
            
            if (from > to) {
                return { valid: false, error: 'Start date must be before end date' };
            }
            
            if (to > new Date()) {
                return { valid: false, error: 'End date cannot be in the future' };
            }
            
            return { valid: true };
        },
        
        isLargeDataRange: function(requestData) {
            const largeRanges = ['thisyear', 'lastyear'];
            if (largeRanges.includes(requestData.date_range)) {
                return true;
            }
            
            if (requestData.date_range === 'custom' && requestData.date_from && requestData.date_to) {
                const daysDiff = (new Date(requestData.date_to) - new Date(requestData.date_from)) / (1000 * 60 * 60 * 24);
                return daysDiff > 180;
            }
            
            return false;
        },
        
        showLargeDataWarning: function(requestData) {
            $('.sky-insights-loading').show();
            $('.sky-insights-loading p').html(
                'Loading large dataset...<br>' +
                '<small>This may take a few minutes</small><br>' +
                '<div class="sky-loading-progress" style="margin-top: 10px;">' +
                '<div class="sky-progress-bar" style="width: 200px; height: 4px; background: #e5e5e7; border-radius: 2px; margin: 0 auto;">' +
                '<div class="sky-progress-fill" style="width: 0%; height: 100%; background: #007aff; border-radius: 2px; transition: width 0.3s;"></div>' +
                '</div>' +
                '<span class="sky-progress-text" style="font-size: 12px; color: #86868b; margin-top: 5px; display: block;">Initializing...</span>' +
                '</div>'
            );
            
            // Simulate progress
            let progress = 0;
            requestData.progressInterval = setInterval(() => {
                progress += Math.random() * 15;
                if (progress > 90) progress = 90;
                $('.sky-progress-fill').css('width', progress + '%');
                $('.sky-progress-text').text('Processing... ' + Math.round(progress) + '%');
            }, 1000);
        },
        
        shouldRefreshCache: function(tab) {
            // Force refresh if filters are active
            const filters = State.get('filters');
            const hasActiveFilters = Object.values(filters).some(value => value !== '');
            
            return hasActiveFilters;
        },
        
        cancelAllRequests: function() {
            for (const key in this.activeRequests) {
                if (this.activeRequests[key] && this.activeRequests[key].abort) {
                    this.activeRequests[key].abort();
                }
            }
            this.activeRequests = {};
        }
    };
    
    /**
     * Cache Manager - FIXED
     */
    const Cache = {
        preloadQueue: [],
        isPreloading: false,
        preloadTimeout: null,
        
        preloadTabs: function() {
            // Don't preload on mobile or slow connections
            if (this.shouldSkipPreload()) {
                return;
            }
            
            const tabs = ['daytime', 'frequencies', 'payment', 'countries'];
            
            // Clear any existing preload queue
            this.preloadQueue = [];
            clearTimeout(this.preloadTimeout);
            
            // Add tabs to queue
            tabs.forEach(tab => {
                // Don't preload if already cached
                if (!State.getCachedTabData(tab)) {
                    this.preloadQueue.push(tab);
                }
            });
            
            // Start preloading after delay
            this.preloadTimeout = setTimeout(() => {
                this.processPreloadQueue();
            }, 2000);
        },
        
        processPreloadQueue: function() {
            if (this.isPreloading || this.preloadQueue.length === 0) {
                return;
            }
            
            // Don't preload while user is actively loading
            if (State.get('isLoading')) {
                setTimeout(() => this.processPreloadQueue(), 1000);
                return;
            }
            
            this.isPreloading = true;
            const tab = this.preloadQueue.shift();
            
            API.loadTabData(tab)
                .done(function(response) {
                    if (response && response.success && !response.fromCache) {
                        State.cacheTabData(tab, response.data);
                        console.log('Preloaded tab:', tab);
                    }
                })
                .fail(function() {
                    console.log('Failed to preload tab:', tab);
                })
                .always(() => {
                    Cache.isPreloading = false;
                    // Process next item after a delay
                    setTimeout(() => Cache.processPreloadQueue(), 500);
                });
        },
        
        shouldSkipPreload: function() {
            // Skip on mobile devices
            if (window.matchMedia('(max-width: 768px)').matches) {
                return true;
            }
            
            // Skip on slow connections
            if ('connection' in navigator) {
                const connection = navigator.connection;
                if (connection.saveData || connection.effectiveType === 'slow-2g' || connection.effectiveType === '2g') {
                    return true;
                }
            }
            
            return false;
        },
        
        shouldUseCache: function(tab) {
            const cached = State.getCachedTabData(tab);
            const filters = State.get('filters');
            const hasActiveFilters = Object.values(filters).some(value => value !== '');
            
            return cached && !hasActiveFilters;
        },
        
        clearPreloadQueue: function() {
            this.preloadQueue = [];
            clearTimeout(this.preloadTimeout);
            this.isPreloading = false;
        }
    };
    
    /**
     * Initialization Handler - FIXED
     */
    const Init = {
        retryCount: 0,
        maxRetries: 3,
        
        loadInitialData: function() {
            // Show loading state
            this.showInitialLoading();
            
            // Load only the raised tab data initially
            API.loadDashboardData({
                filter: 'raised',
                minimal: true,
                confirmed: true
            })
            .done((response) => {
                if (response && response.success) {
                    this.hideInitialLoading();
                    $(document).trigger('skyinsights:data:loaded', response.data);
                    
                    // Cache the data
                    State.cacheTabData('raised', response.data);
                    
                    // Reset retry count on success
                    this.retryCount = 0;
                }
                State.set('isInitialLoad', false);
            })
            .fail((xhr, textStatus, errorThrown) => {
                console.error('Error loading initial data:', textStatus, errorThrown);
                this.hideInitialLoading();
                State.set('isInitialLoad', false);
                
                // Retry logic for initial load
                if (textStatus !== 'abort' && this.retryCount < this.maxRetries) {
                    this.retryCount++;
                    console.log(`Retrying initial load (${this.retryCount}/${this.maxRetries})...`);
                    
                    setTimeout(() => {
                        this.loadInitialData();
                    }, 1000 * this.retryCount);
                } else {
                    // Show error state
                    this.showInitialError();
                }
            });
        },
        
        showInitialLoading: function() {
            $('.sky-total-amount .sky-amount-value').html('<div class="sky-skeleton sky-skeleton-number"></div>');
            $('.sky-donations-count').html('<div class="sky-skeleton sky-skeleton-text" style="width: 150px;"></div>');
            $('.sky-new-donors-count').html('<div class="sky-skeleton sky-skeleton-text" style="width: 120px;"></div>');
            
            if ($('#sky-main-chart').length > 0) {
                const $chartContainer = $('#sky-main-chart').parent();
                if (!$chartContainer.find('.sky-chart-loading').length) {
                    $chartContainer.css('position', 'relative');
                    $chartContainer.append(
                        '<div class="sky-chart-loading" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; ' +
                        'background: rgba(255,255,255,0.8); display: flex; align-items: center; justify-content: center; z-index: 10;">' +
                        '<div class="spinner is-active"></div></div>'
                    );
                }
            }
        },
        
        hideInitialLoading: function() {
            $('.sky-skeleton').parent().each(function() {
                const $parent = $(this);
                $parent.find('.sky-skeleton').remove();
                
                // Restore default values if still empty
                if ($parent.hasClass('sky-amount-value') && $parent.is(':empty')) {
                    $parent.text(skyInsights.currency + '0.00');
                } else if ($parent.hasClass('sky-donations-count') && $parent.is(':empty')) {
                    $parent.text('0 donations');
                } else if ($parent.hasClass('sky-new-donors-count') && $parent.is(':empty')) {
                    $parent.text('0 new donors');
                }
            });
            
            $('.sky-chart-loading').fadeOut(300, function() {
                $(this).remove();
            });
        },
        
        showInitialError: function() {
            $('.sky-total-amount .sky-amount-value').text('Error loading data');
            $('.sky-donations-count').html(
                '<a href="#" onclick="location.reload(); return false;" style="color: #007aff;">Click to refresh</a>'
            );
            $('.sky-new-donors-count').text('');
            
            if (window.SkyInsights.UI && window.SkyInsights.UI.Common) {
                window.SkyInsights.UI.Common.showToast(
                    'Failed to load dashboard data. Please refresh the page.',
                    'error'
                );
            }
        }
    };
    
    /**
     * Data Loader - FIXED
     */
    const DataLoader = {
        _loadDebounced: null,
        _lastLoadParams: null,
        _loadPromise: null,
        
        load: function(showLoading = true) {
            // Cancel any pending load
            if (this._loadDebounced) {
                clearTimeout(this._loadDebounced);
            }
            
            // Get current parameters
            const currentParams = JSON.stringify({
                tab: State.get('currentTab'),
                dateRange: State.get('dateRange'),
                filters: State.get('filters'),
                customDateFrom: State.get('customDateFrom'),
                customDateTo: State.get('customDateTo')
            });
            
            // Check if we're trying to load the same data
            if (this._lastLoadParams === currentParams && State.get('isLoading')) {
                console.log('Ignoring duplicate load request');
                return this._loadPromise || $.Deferred().resolve();
            }
            
            this._lastLoadParams = currentParams;
            
            // Don't load if already loading
            if (State.get('isLoading')) {
                console.log('Already loading, queueing request');
                this._loadDebounced = setTimeout(() => {
                    this._doLoad(showLoading);
                }, 500);
                return this._loadPromise || $.Deferred().resolve();
            }
            
            // Debounce the actual load
            this._loadDebounced = setTimeout(() => {
                this._loadPromise = this._doLoad(showLoading);
            }, 100);
            
            return this._loadPromise || $.Deferred().resolve();
        },
        
        _doLoad: function(showLoading) {
            // Get current filters
            const filters = {
                campaign: $('#sky-campaign-filter').val() || '',
                designation: $('#sky-designation-filter').val() || '',
                source: $('#sky-source-filter').val() || '',
                frequency: $('#sky-frequency-filter').val() || ''
            };
            
            State.updateFilters(filters);
            
            if (showLoading && !State.get('isInitialLoad')) {
                $('.sky-insights-loading').show();
            }
            
            const params = {
                filter: State.get('currentTab'),
                date_range: State.get('dateRange'),
                date_from: State.get('customDateFrom'),
                date_to: State.get('customDateTo'),
                filters: filters,
                confirmed: true
            };
            
            return API.loadDashboardData(params)
                .done((response) => {
                    if (response && response.success) {
                        $(document).trigger('skyinsights:data:loaded', response.data);
                        State.cacheTabData(State.get('currentTab'), response.data);
                    }
                })
                .fail((xhr, textStatus) => {
                    // Error is already handled in makeRequest
                    if (textStatus !== 'abort') {
                        console.error('Load failed:', textStatus);
                    }
                })
                .always(() => {
                    $('.sky-insights-loading').hide();
                    
                    // Clear progress interval if exists
                    if (params.progressInterval) {
                        clearInterval(params.progressInterval);
                    }
                });
        },
        
        loadTabData: function(tab) {
            // Check if we're already loading this tab
            if (State.get('loadingTab') === tab) {
                console.log('Tab is already loading:', tab);
                return $.Deferred().resolve();
            }
            
            const cachedData = State.getCachedTabData(tab);
            
            if (cachedData && Cache.shouldUseCache(tab)) {
                console.log('Using cached data for tab:', tab);
                $(document).trigger('skyinsights:tab:loaded', {tab: tab, data: cachedData});
                return $.Deferred().resolve();
            } else {
                // Show tab-specific loading state
                this.showTabLoading(tab);
                return this.load();
            }
        },
        
        showTabLoading: function(tab) {
            const $tabContent = $(`#sky-tab-${tab}`);
            
            // Add loading class to tab content
            $tabContent.addClass('loading');
            
            // Show skeleton loaders based on tab type
            switch(tab) {
                case 'daytime':
                    $('#sky-heatmap').html('<div class="sky-skeleton sky-skeleton-chart" style="height: 280px;"></div>');
                    break;
                    
                case 'frequencies':
                case 'payment':
                case 'countries':
                case 'designations':
                    $tabContent.find('canvas').each(function() {
                        $(this).parent().html('<div class="sky-skeleton sky-skeleton-chart" style="height: 250px;"></div>');
                    });
                    $tabContent.find('tbody').html(
                        '<tr><td colspan="10"><div class="sky-skeleton sky-skeleton-text" style="width: 100%; margin: 10px 0;"></div></td></tr>'
                    );
                    break;
                    
                case 'customers':
                    $('.sky-metric-card').each(function() {
                        $(this).find('.sky-metric-value').html(
                            '<div class="sky-skeleton sky-skeleton-number" style="width: 60px; height: 32px;"></div>'
                        );
                    });
                    break;
                    
                case 'url':
                    $tabContent.find('canvas').parent().html(
                        '<div class="sky-skeleton sky-skeleton-chart" style="height: 250px;"></div>'
                    );
                    $('#sky-url-table').html(
                        '<tr><td colspan="6"><div class="sky-skeleton sky-skeleton-text" style="width: 100%; margin: 10px 0;"></div></td></tr>'
                    );
                    break;
            }
        },
        
        exportData: function(exportType) {
            const params = {
                action: 'sky_insights_export_csv',
                date_range: State.get('dateRange'),
                date_from: State.get('customDateFrom'),
                date_to: State.get('customDateTo'),
                current_tab: State.get('currentTab'),
                export_type: exportType,
                nonce: skyInsights.nonce
            };
            
            // Validate parameters
            if (!params.nonce) {
                API.showErrorNotification('Security validation failed. Please refresh the page.');
                return;
            }
            
            // Create form and submit
            const form = $('<form>', {
                method: 'POST',
                action: skyInsights.ajaxurl,
                style: 'display: none;'
            });
            
            $.each(params, function(key, value) {
                form.append($('<input>', {
                    type: 'hidden',
                    name: key,
                    value: value
                }));
            });
            
            form.appendTo('body').submit().remove();
            
            // Show success message
            if (window.SkyInsights.UI && window.SkyInsights.UI.Common) {
                window.SkyInsights.UI.Common.showToast('Export started. Download will begin shortly.', 'success');
            }
        },
        
        cancelCurrentLoad: function() {
            if (this._loadDebounced) {
                clearTimeout(this._loadDebounced);
                this._loadDebounced = null;
            }
            
            API.cancelAllRequests();
            State.set('isLoading', false);
            State.set('loadingTab', null);
            $('.sky-insights-loading').hide();
        }
    };
    
    /**
     * Common Helper Functions - FIXED
     */
    const Helpers = {
        formatDateRange: function(dateRange) {
            if (!dateRange || !dateRange.start || !dateRange.end) return '';
            
            try {
                const startDate = new Date(dateRange.start + 'T00:00:00');
                const endDate = new Date(dateRange.end + 'T00:00:00');
                const dateOptions = { month: 'short', day: 'numeric', year: 'numeric' };
                
                return startDate.toLocaleDateString('en-GB', dateOptions) + ' – ' + 
                       endDate.toLocaleDateString('en-GB', dateOptions);
            } catch (error) {
                console.error('Error formatting date range:', error);
                return dateRange.start + ' – ' + dateRange.end;
            }
        },
        
        updateFilterOptions: function(options) {
            if (!options) return;
            
            try {
                // Delegate to UI module if available
                if (window.SkyInsights.UI && window.SkyInsights.UI.Filters) {
                    window.SkyInsights.UI.Filters.updateDropdowns(options);
                }
            } catch (error) {
                console.error('Error updating filter options:', error);
            }
        },
        
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        throttle: function(func, limit) {
            let inThrottle;
            return function(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        }
    };
    
    // Connection monitoring
    window.addEventListener('online', function() {
        State.set('connectionStatus', 'online');
        if (State.get('lastError') && State.get('lastError').message.includes('connection')) {
            // Retry loading data
            DataLoader.load();
        }
    });
    
    window.addEventListener('offline', function() {
        State.set('connectionStatus', 'offline');
        API.showErrorNotification('Internet connection lost. Some features may not work.');
    });
    
    // Export to global namespace
    window.SkyInsights.Core = {
        State: State,
        API: API,
        Cache: Cache,
        Init: Init,
        DataLoader: DataLoader,
        Helpers: Helpers
    };
    
})(jQuery);