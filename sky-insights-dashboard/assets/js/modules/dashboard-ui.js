/**
 * Sky Insights Dashboard - UI Module - FIXED VERSION
 * Handles date picker, filters, loading states, and common UI functions
 */

(function($) {
    'use strict';
    
    // Dependencies
    const { State, Helpers, DataLoader } = window.SkyInsights.Core;
    const { debounce } = window.SkyInsightsUtils;
    
    // Create namespace
    window.SkyInsights = window.SkyInsights || {};
    window.SkyInsights.UI = {};
    
    // Track if events are already bound
    let eventsInitialized = false;
    let activeRequests = new Set();
    
    /**
     * Date Range Picker Handler - FIXED
     */
    window.SkyInsights.UI.DateRangePicker = {
        isProcessing: false,
        lastAppliedRange: null,
        
        init: function() {
            const self = this;
            const $picker = $('#sky-date-range-picker');
            const $presets = $('.sky-date-presets');
            
            // Set initial value - Changed to Last 7 days
            $picker.val(skyInsights.i18n.last7Days || 'Last 7 days');
            
            // Initialize custom date picker if available
            if (window.SkyInsights.DatePicker) {
                window.SkyInsights.DatePicker.init();
            }
            
            // Set default active button (for fallback)
            $('.sky-date-presets button[data-range="last7days"]').addClass('active');
            
            // Store initial state
            this.lastAppliedRange = 'last7days';
            
            // Only bind events once
            if (!eventsInitialized) {
                this.bindEvents();
                eventsInitialized = true;
            }
        },
        
        bindEvents: function() {
            const self = this;
            
            // Remove any existing handlers first
            $(document).off('.skyDateRangePicker');
            
            // Toggle presets dropdown or custom date picker
            $(document).on('click.skyDateRangePicker', '#sky-date-range-picker', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Prevent multiple clicks while processing
                if (self.isProcessing) {
                    return false;
                }
                
                // Use custom date picker if available
                if (window.SkyInsights.DatePicker) {
                    window.SkyInsights.DatePicker.show();
                } else {
                    // Fallback to old dropdown
                    const $presets = $('.sky-date-presets');
                    
                    // Toggle the dropdown
                    if ($presets.is(':visible')) {
                        $presets.hide();
                    } else {
                        // Hide any other open dropdowns first
                        $('.sky-export-dropdown').hide();
                        $presets.show();
                    }
                }
            });
            
            // Handle preset button clicks with debouncing
            const debouncedPresetHandler = debounce(function($button, dateRange) {
                if (dateRange === 'custom') {
                    self.handleCustomRange($button);
                } else {
                    self.handlePresetRange($button, dateRange);
                }
            }, 300);
            
            $(document).on('click.skyDateRangePicker', '.sky-date-presets button', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Don't process if already processing
                if ($(this).hasClass('processing') || self.isProcessing) {
                    return false;
                }
                
                const $button = $(this);
                const dateRange = $button.data('range');
                
                // Add processing class to prevent double clicks
                $button.addClass('processing');
                self.isProcessing = true;
                
                // Show immediate visual feedback
                $('.sky-date-presets button').removeClass('active');
                $button.addClass('active');
                
                // Call debounced handler
                debouncedPresetHandler($button, dateRange);
                
                // Remove processing class after delay
                setTimeout(() => {
                    $button.removeClass('processing');
                    self.isProcessing = false;
                }, 1000);
            });
            
            // Handle custom range apply button
            $(document).on('click.skyDateRangePicker', '.sky-apply-range', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if ($(this).hasClass('processing') || self.isProcessing) {
                    return false;
                }
                
                $(this).addClass('processing');
                self.isProcessing = true;
                
                self.applyCustomRange().always(() => {
                    $(this).removeClass('processing');
                    self.isProcessing = false;
                });
            });
            
            // Close dropdown when clicking outside
            $(document).on('click.skyDateRangePicker', function(e) {
                if (!$(e.target).closest('.sky-date-filter, .sky-custom-datepicker').length) {
                    $('.sky-date-presets').hide();
                    if (window.SkyInsights.DatePicker) {
                        window.SkyInsights.DatePicker.hide();
                    }
                }
            });
            
            // Prevent dropdown from closing when clicking inside
            $(document).on('click.skyDateRangePicker', '.sky-date-presets', function(e) {
                if (!$(e.target).is('button') && !$(e.target).closest('button').length) {
                    e.stopPropagation();
                }
            });
            
            // Handle Enter key in date inputs with validation
            $(document).on('keypress.skyDateRangePicker', '#sky-date-from, #sky-date-to', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    if (!self.isProcessing) {
                        $('.sky-apply-range').click();
                    }
                }
            });
            
            // Add date input validation
            $(document).on('change.skyDateRangePicker', '#sky-date-from, #sky-date-to', function() {
                self.validateDateInput($(this));
            });
        },
        
        validateDateInput: function($input) {
            const value = $input.val();
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            
            if (!value) {
                return true; // Empty is ok, will be caught later
            }
            
            if (!dateRegex.test(value)) {
                $input.addClass('error');
                this.showError('Please use YYYY-MM-DD format');
                return false;
            }
            
            const date = new Date(value + 'T00:00:00');
            const today = new Date();
            today.setHours(23, 59, 59, 999);
            
            if (isNaN(date.getTime())) {
                $input.addClass('error');
                this.showError('Invalid date');
                return false;
            }
            
            if (date > today) {
                $input.addClass('error');
                this.showError('Date cannot be in the future');
                return false;
            }
            
            $input.removeClass('error');
            return true;
        },
        
        handleCustomRange: function($button) {
            // Show custom range inputs
            $('.sky-custom-range').show();
            
            // Set default dates if empty
            const today = new Date();
            const lastWeek = new Date(today);
            lastWeek.setDate(lastWeek.getDate() - 7);
            
            if (!$('#sky-date-from').val()) {
                $('#sky-date-from').val(this.formatDateISO(lastWeek));
            }
            if (!$('#sky-date-to').val()) {
                $('#sky-date-to').val(this.formatDateISO(today));
            }
            
            // Update active state
            $('.sky-date-presets button').removeClass('active');
            $button.addClass('active');
            
            // Focus on first input
            $('#sky-date-from').focus();
        },
        
        handlePresetRange: function($button, dateRange) {
            const $picker = $('#sky-date-range-picker');
            const $presets = $('.sky-date-presets');
            
            // Check if this is the same range as currently applied
            if (dateRange === this.lastAppliedRange) {
                console.log('Same date range already applied, skipping reload');
                $presets.hide();
                return;
            }
            
            // Regular preset selected
            $('.sky-date-presets button').removeClass('active');
            $button.addClass('active');
            
            // Update state
            State.set('dateRange', dateRange);
            State.set('customDateFrom', '');
            State.set('customDateTo', '');
            
            // Update picker display
            $picker.val($button.text());
            
            // Hide custom range inputs
            $('.sky-custom-range').hide();
            
            // Hide dropdown
            $presets.hide();
            
            // Store last applied range
            this.lastAppliedRange = dateRange;
            
            // Cancel any pending requests
            this.cancelPendingRequests();
            
            // Show loading immediately for better UX
            $('.sky-insights-loading').show();
            
            // Clear cache and reload data with a slight delay to ensure UI updates first
            setTimeout(() => {
                State.clearCache();
                const request = DataLoader.load();
                if (request && request.abort) {
                    activeRequests.add(request);
                    request.always(() => {
                        activeRequests.delete(request);
                    });
                }
            }, 100);
        },
        
        applyCustomRange: function() {
            const self = this;
            const $picker = $('#sky-date-range-picker');
            const $presets = $('.sky-date-presets');
            const dateFrom = $('#sky-date-from').val();
            const dateTo = $('#sky-date-to').val();
            
            console.log('Applying custom range:', dateFrom, 'to', dateTo);
            
            // Create deferred for async validation
            const deferred = $.Deferred();
            
            // Comprehensive validation
            const validation = this.validateDateRange(dateFrom, dateTo);
            if (!validation.valid) {
                this.showError(validation.error);
                deferred.reject(validation.error);
                return deferred.promise();
            }
            
            // Check if this is the same range as currently applied
            const rangeKey = `custom_${dateFrom}_${dateTo}`;
            if (rangeKey === this.lastAppliedRange) {
                console.log('Same custom range already applied, skipping reload');
                $presets.hide();
                deferred.resolve();
                return deferred.promise();
            }
            
            // Update state
            State.set('dateRange', 'custom');
            State.set('customDateFrom', dateFrom);
            State.set('customDateTo', dateTo);
            
            // Format and display date range
            const dateOptions = { month: 'short', day: 'numeric', year: 'numeric' };
            const fromDate = new Date(dateFrom + 'T00:00:00');
            const toDate = new Date(dateTo + 'T00:00:00');
            const formattedRange = fromDate.toLocaleDateString('en-GB', dateOptions) + ' – ' + 
                                 toDate.toLocaleDateString('en-GB', dateOptions);
            
            $picker.val(formattedRange);
            
            // Update active state
            $('.sky-date-presets button').removeClass('active');
            $('.sky-date-presets button[data-range="custom"]').addClass('active');
            
            // Hide dropdown
            $presets.hide();
            
            // Store last applied range
            this.lastAppliedRange = rangeKey;
            
            // Cancel any pending requests
            this.cancelPendingRequests();
            
            // Show loading
            $('.sky-insights-loading').show();
            
            // Clear cache and reload data
            setTimeout(() => {
                State.clearCache();
                const request = DataLoader.load();
                if (request && request.abort) {
                    activeRequests.add(request);
                    request.always(() => {
                        activeRequests.delete(request);
                        deferred.resolve();
                    });
                } else {
                    deferred.resolve();
                }
            }, 100);
            
            return deferred.promise();
        },
        
        validateDateRange: function(dateFrom, dateTo) {
            if (!dateFrom || !dateTo) {
                return { valid: false, error: 'Please select both start and end dates' };
            }
            
            // Validate date format
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (!dateRegex.test(dateFrom) || !dateRegex.test(dateTo)) {
                return { valid: false, error: 'Invalid date format. Use YYYY-MM-DD' };
            }
            
            // Parse dates
            const fromDate = new Date(dateFrom + 'T00:00:00');
            const toDate = new Date(dateTo + 'T00:00:00');
            const today = new Date();
            today.setHours(23, 59, 59, 999);
            
            // Check valid dates
            if (isNaN(fromDate.getTime()) || isNaN(toDate.getTime())) {
                return { valid: false, error: 'Invalid date values' };
            }
            
            // Check date order
            if (fromDate > toDate) {
                return { valid: false, error: 'Start date must be before end date' };
            }
            
            // Check future dates
            if (toDate > today) {
                return { valid: false, error: 'End date cannot be in the future' };
            }
            
            // Check date range not too large (e.g., max 2 years)
            const daysDiff = Math.floor((toDate - fromDate) / (1000 * 60 * 60 * 24));
            if (daysDiff > 730) { // 2 years
                return { valid: false, error: 'Date range cannot exceed 2 years' };
            }
            
            return { valid: true };
        },
        
        updateDisplay: function(dateRange) {
            if (!dateRange || !dateRange.start || !dateRange.end) return;
            
            const startDate = new Date(dateRange.start + 'T00:00:00');
            const endDate = new Date(dateRange.end + 'T00:00:00');
            const dateOptions = { month: 'short', day: 'numeric', year: 'numeric' };
            
            const formattedRange = startDate.toLocaleDateString('en-GB', dateOptions) + ' – ' + 
                                 endDate.toLocaleDateString('en-GB', dateOptions);
            
            $('.sky-date-range-display').text(formattedRange);
            
            // Update the picker input if it's a custom range
            if (State.get('dateRange') === 'custom') {
                $('#sky-date-range-picker').val(formattedRange);
            }
        },
        
        showError: function(message) {
            // Remove existing error
            $('.sky-date-error').remove();
            
            // Create error element
            const $error = $('<div class="sky-date-error">')
                .text(message)
                .css({
                    position: 'absolute',
                    background: '#ff3b30',
                    color: 'white',
                    padding: '5px 10px',
                    borderRadius: '4px',
                    fontSize: '12px',
                    zIndex: 10001,
                    top: '100%',
                    left: '0',
                    marginTop: '5px',
                    whiteSpace: 'nowrap'
                });
            
            // Add to custom range container
            $('.sky-custom-range').css('position', 'relative').append($error);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                $error.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },
        
        formatDateISO: function(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        
        cancelPendingRequests: function() {
            activeRequests.forEach(request => {
                if (request && request.abort) {
                    request.abort();
                }
            });
            activeRequests.clear();
        }
    };
    
    /**
     * Filter Handler - FIXED
     */
    window.SkyInsights.UI.Filters = {
        currentFilters: {},
        isUpdating: false,
        
        updateDropdowns: function(options) {
            if (!options || this.isUpdating) return;
            
            this.isUpdating = true;
            
            try {
                // Update campaign filter
                this.updateCampaignFilter(options.campaigns);
                
                // Update designation filter
                this.updateDesignationFilter(options.designations);
                
                // Update source filter
                if (options.sources && options.sources.length > 0) {
                    this.updateSourceFilter(options.sources);
                }
                
                // Update frequency filter
                if (options.frequencies && options.frequencies.length > 0) {
                    this.updateFrequencyFilter(options.frequencies);
                }
                
                // Restore previously selected values
                this.restoreFilterValues();
                
            } catch (error) {
                console.error('Error updating filter dropdowns:', error);
            } finally {
                this.isUpdating = false;
            }
        },
        
        updateCampaignFilter: function(campaigns) {
            if (!campaigns || !Array.isArray(campaigns)) return;
            
            const $filter = $('#sky-campaign-filter');
            if (!$filter.length) return;
            
            const currentValue = $filter.val() || this.currentFilters.campaign;
            
            $filter.empty().append('<option value="">Campaign</option>');
            
            campaigns.forEach(function(campaign) {
                if (campaign && campaign.id && campaign.name) {
                    $filter.append(
                        $('<option>').val(campaign.id).text(campaign.name)
                    );
                }
            });
            
            if (currentValue) {
                $filter.val(currentValue);
                if ($filter.val() !== currentValue) {
                    // Value no longer exists, clear it
                    this.currentFilters.campaign = '';
                }
            }
        },
        
        updateDesignationFilter: function(designations) {
            if (!designations || !Array.isArray(designations)) return;
            
            const $filter = $('#sky-designation-filter');
            if (!$filter.length) return;
            
            const currentValue = $filter.val() || this.currentFilters.designation;
            
            $filter.empty().append('<option value="">Designation</option>');
            
            designations.forEach(function(designation) {
                if (designation) {
                    if (typeof designation === 'object' && designation.value && designation.label) {
                        $filter.append(
                            $('<option>').val(designation.value).text(designation.label)
                        );
                    } else if (typeof designation === 'string') {
                        $filter.append(
                            $('<option>').val(designation).text(designation)
                        );
                    }
                }
            });
            
            if (currentValue) {
                $filter.val(currentValue);
                if ($filter.val() !== currentValue) {
                    this.currentFilters.designation = '';
                }
            }
        },
        
        updateSourceFilter: function(sources) {
            if (!sources || !Array.isArray(sources)) return;
            
            const $filter = $('#sky-source-filter');
            if (!$filter.length) return;
            
            const currentValue = $filter.val() || this.currentFilters.source;
            
            $filter.empty().append('<option value="">Source</option>');
            
            sources.forEach(function(source) {
                if (source) {
                    $filter.append(
                        $('<option>').val(source).text(source)
                    );
                }
            });
            
            if (currentValue) {
                $filter.val(currentValue);
                if ($filter.val() !== currentValue) {
                    this.currentFilters.source = '';
                }
            }
        },
        
        updateFrequencyFilter: function(frequencies) {
            if (!frequencies || !Array.isArray(frequencies)) return;
            
            const $filter = $('#sky-frequency-filter');
            if (!$filter.length) return;
            
            const currentValue = $filter.val() || this.currentFilters.frequency;
            
            $filter.empty().append('<option value="">Frequency</option>');
            
            frequencies.forEach(function(frequency) {
                if (frequency) {
                    $filter.append(
                        $('<option>').val(frequency).text(frequency)
                    );
                }
            });
            
            if (currentValue) {
                $filter.val(currentValue);
                if ($filter.val() !== currentValue) {
                    this.currentFilters.frequency = '';
                }
            }
        },
        
        saveCurrentValues: function() {
            this.currentFilters = {
                campaign: $('#sky-campaign-filter').val() || '',
                designation: $('#sky-designation-filter').val() || '',
                source: $('#sky-source-filter').val() || '',
                frequency: $('#sky-frequency-filter').val() || ''
            };
        },
        
        restoreFilterValues: function() {
            if (this.currentFilters.campaign) {
                $('#sky-campaign-filter').val(this.currentFilters.campaign);
            }
            if (this.currentFilters.designation) {
                $('#sky-designation-filter').val(this.currentFilters.designation);
            }
            if (this.currentFilters.source) {
                $('#sky-source-filter').val(this.currentFilters.source);
            }
            if (this.currentFilters.frequency) {
                $('#sky-frequency-filter').val(this.currentFilters.frequency);
            }
        },
        
        clearAll: function() {
            this.currentFilters = {};
            $('.sky-header-filters select').val('');
            State.updateFilters({});
        }
    };
    
    /**
     * Loading States Handler - FIXED
     */
    window.SkyInsights.UI.Loading = {
        activeLoadings: new Set(),
        
        showMain: function(message = 'Loading...') {
            const $loading = $('.sky-insights-loading');
            
            if (message !== 'Loading...') {
                $loading.find('p').html(message);
            }
            
            $loading.show();
            this.activeLoadings.add('main');
        },
        
        hideMain: function() {
            $('.sky-insights-loading').hide();
            $('.sky-insights-loading p').html('Loading...');
            this.activeLoadings.delete('main');
        },
        
        showSkeleton: function(selector) {
            const $element = $(selector);
            if ($element.length) {
                $element.data('original-content', $element.html());
                $element.html('<div class="sky-skeleton sky-skeleton-text"></div>');
            }
        },
        
        hideSkeleton: function(selector) {
            const $element = $(selector);
            const originalContent = $element.data('original-content');
            if (originalContent) {
                $element.html(originalContent);
                $element.removeData('original-content');
            }
        },
        
        showChartLoading: function(chartId) {
            const $chart = $('#' + chartId);
            if ($chart.length > 0) {
                const $container = $chart.parent();
                
                // Remove any existing loading
                $container.find('.sky-chart-loading').remove();
                
                if (!$container.find('.sky-chart-loading').length) {
                    $container.css('position', 'relative');
                    const $loading = $(`
                        <div class="sky-chart-loading" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; 
                        background: rgba(255,255,255,0.8); display: flex; align-items: center; justify-content: center; z-index: 10;">
                            <div class="spinner is-active"></div>
                        </div>
                    `);
                    $container.append($loading);
                    this.activeLoadings.add(chartId);
                }
            }
        },
        
        hideChartLoading: function(chartId) {
            $('#' + chartId).parent().find('.sky-chart-loading').fadeOut(300, function() {
                $(this).remove();
            });
            this.activeLoadings.delete(chartId);
        },
        
        hideAll: function() {
            this.hideMain();
            $('.sky-chart-loading').remove();
            $('.sky-skeleton').each(function() {
                const $this = $(this);
                const originalContent = $this.parent().data('original-content');
                if (originalContent) {
                    $this.parent().html(originalContent);
                }
            });
            this.activeLoadings.clear();
        }
    };
    
    /**
     * Tab Navigation Handler - FIXED
     */
    window.SkyInsights.UI.Tabs = {
        currentTab: 'raised',
        isChanging: false,
        
        activate: function(tabName) {
            if (this.isChanging || tabName === this.currentTab) {
                return;
            }
            
            this.isChanging = true;
            
            // Update UI
            $('.sky-tab').removeClass('active');
            $('.sky-tab-content').removeClass('active').removeClass('sky-fade-in');
            
            $(`.sky-tab[data-tab="${tabName}"]`).addClass('active');
            const $tabContent = $(`#sky-tab-${tabName}`);
            $tabContent.addClass('active');
            
            // Add fade in animation after a brief delay
            setTimeout(() => {
                $tabContent.addClass('sky-fade-in');
                this.isChanging = false;
            }, 50);
            
            this.currentTab = tabName;
        },
        
        bindEvents: function() {
            const self = this;
            
            // Remove existing handlers
            $(document).off('.skyTabs');
            
            $(document).on('click.skyTabs', '.sky-tab', function(e) {
                e.preventDefault();
                
                if (self.isChanging) {
                    return;
                }
                
                const tab = $(this).data('tab');
                
                // Don't reload if same tab
                if (tab === State.get('currentTab')) {
                    return;
                }
                
                self.activate(tab);
                State.set('currentTab', tab);
                
                // Save filter values before tab change
                window.SkyInsights.UI.Filters.saveCurrentValues();
                
                // Trigger tab change event
                $(document).trigger('skyinsights:tab:change', tab);
            });
        }
    };
    
    /**
     * View Toggle Handler - FIXED
     */
    window.SkyInsights.UI.ViewToggle = {
        isChanging: false,
        
        bindEvents: function() {
            const self = this;
            
            $(document).off('.skyViewToggle');
            
            $(document).on('click.skyViewToggle', '.sky-toggle', function(e) {
                e.preventDefault();
                
                if (self.isChanging) {
                    return;
                }
                
                const $this = $(this);
                const view = $this.data('view');
                
                // Don't reload if same view
                if (view === State.get('currentView')) {
                    return;
                }
                
                self.isChanging = true;
                
                $this.siblings().removeClass('active');
                $this.addClass('active');
                
                State.set('currentView', view);
                
                // Trigger view change event
                $(document).trigger('skyinsights:view:change', view);
                
                setTimeout(() => {
                    self.isChanging = false;
                }, 500);
            });
        }
    };
    
    /**
     * Common UI Functions - FIXED
     */
    window.SkyInsights.UI.Common = {
        showEmptyState: function(selector, message) {
            const $element = $(selector);
            if ($element.length) {
                $element.html(
                    '<tr><td colspan="10" style="text-align: center; padding: 24px; color: #86868b;">' + 
                    (message || 'No data available for the selected period') + 
                    '</td></tr>'
                );
            }
        },
        
        updateMetric: function(selector, value, prefix = '', suffix = '') {
            const $element = $(selector);
            if ($element.length) {
                // Sanitize value
                if (typeof value === 'number') {
                    value = isNaN(value) ? '0' : value;
                } else {
                    value = value || '0';
                }
                
                $element.text(prefix + value + suffix);
            }
        },
        
        showToast: function(message, type = 'info') {
            // Remove existing toasts
            $('.sky-toast').remove();
            
            const icons = {
                success: '✓',
                error: '✕',
                warning: '⚠',
                info: 'ℹ'
            };
            
            const colors = {
                success: '#34c759',
                error: '#ff3b30',
                warning: '#ff9500',
                info: '#007aff'
            };
            
            const $toast = $(`
                <div class="sky-toast sky-toast-${type}">
                    <span class="sky-toast-icon">${icons[type]}</span>
                    <span class="sky-toast-message">${message}</span>
                </div>
            `).css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                background: colors[type],
                color: 'white',
                padding: '12px 20px',
                borderRadius: '8px',
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                zIndex: 10000,
                display: 'flex',
                alignItems: 'center',
                gap: '10px',
                maxWidth: '400px',
                fontSize: '14px',
                opacity: 0,
                transform: 'translateY(-20px)',
                transition: 'all 0.3s ease'
            });
            
            $('body').append($toast);
            
            // Animate in
            setTimeout(() => {
                $toast.css({
                    opacity: 1,
                    transform: 'translateY(0)'
                });
            }, 10);
            
            // Auto remove
            setTimeout(() => {
                $toast.css({
                    opacity: 0,
                    transform: 'translateY(-20px)'
                });
                setTimeout(() => $toast.remove(), 300);
            }, 3000);
        }
    };
    
})(jQuery);