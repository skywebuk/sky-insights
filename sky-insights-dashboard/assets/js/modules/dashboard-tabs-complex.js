/**
 * Sky Insights Dashboard - Complex Tabs Module - FIXED VERSION
 * Handles Raised, Day/Time, Customers, and URL tabs
 */

(function($) {
    'use strict';
    
    // Dependencies
    const { formatNumber, formatDate, formatChartDate, getChartColor, generateDateLabels } = window.SkyInsightsUtils;
    const { updateMainChart, updateMiniChart, updateCustomersChart, loadChartLibrary, chartInstances } = window.SkyInsightsCharts;
    
    // Create namespace
    window.SkyInsights = window.SkyInsights || {};
    window.SkyInsights.ComplexTabs = {};
    
    /**
     * Raised Tab Handler - FIXED
     */
    window.SkyInsights.ComplexTabs.Raised = {
        lastUpdateData: null,
        
        update: function(data) {
            try {
                // Validate data
                if (!data || typeof data !== 'object') {
                    console.error('Invalid data for raised tab:', data);
                    this.showErrorState();
                    return;
                }
                
                // Store last data for recovery
                this.lastUpdateData = data;
                
                // Update totals with proper currency symbol - FIXED XSS
                const totalAmount = parseFloat(data.total_amount) || 0;
                const totalCount = parseInt(data.total_count) || 0;
                const newDonors = parseInt(data.new_donors) || 0;
                
                $('.sky-total-amount .sky-amount-value').text(
                    this.formatCurrency(totalAmount)
                );
                
                // Add trend arrow based on comparison with previous period
                this.updateTrendArrow(data);
                
                // Update donation count - FIXED localization
                $('.sky-donations-count').text(
                    this.formatCount(totalCount) + ' ' + (skyInsights.i18n.donations || 'donations')
                );
                
                // Update new donors count
                $('.sky-new-donors-count').text(
                    this.formatCount(newDonors) + ' ' + (skyInsights.i18n.newDonors || 'new donors')
                );
                
                // Update mini boxes with safe defaults
                const installmentsAmount = parseFloat(data.installments_amount) || 0;
                const installmentsCount = parseInt(data.installments_count) || 0;
                const onetimeAmount = parseFloat(data.onetime_amount) || 0;
                const onetimeCount = parseInt(data.onetime_count) || 0;
                
                $('.sky-mini-box:first .sky-mini-amount').text(this.formatCurrency(installmentsAmount));
                $('.sky-mini-box:first .sky-mini-count').text(installmentsCount + ' recurring');
                
                $('.sky-mini-box:last .sky-mini-amount').text(this.formatCurrency(onetimeAmount));
                $('.sky-mini-box:last .sky-mini-count').text(onetimeCount + ' donations');
                
                // Update performance metrics
                this.updatePerformanceMetrics(data);
                
                // Restore chart canvas if it was replaced by skeleton
                this.ensureChartCanvas();
                
                // Update charts with error handling
                this.updateCharts(data);
                
            } catch (error) {
                console.error('Error updating raised tab:', error);
                this.showErrorState();
            }
        },
        
        formatCurrency: function(amount) {
            try {
                // Use Intl.NumberFormat if available
                if (window.Intl && Intl.NumberFormat && skyInsights.locale && skyInsights.currencyCode) {
                    return new Intl.NumberFormat(skyInsights.locale, {
                        style: 'currency',
                        currency: skyInsights.currencyCode,
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 2
                    }).format(amount);
                }
            } catch (e) {
                // Fallback to simple format
            }
            
            // Fallback formatting
            return (skyInsights.currency || '$') + formatNumber(amount);
        },
        
        formatCount: function(count) {
            try {
                // Use Intl.NumberFormat for thousands separators
                if (window.Intl && Intl.NumberFormat && skyInsights.locale) {
                    return new Intl.NumberFormat(skyInsights.locale).format(count);
                }
            } catch (e) {
                // Fallback to simple format
            }
            
            return formatNumber(count, 0);
        },
        
        updateTrendArrow: function(data) {
            const $trendArrow = $('.sky-total-amount .sky-trend-arrow');
            
            if (!$trendArrow.length) {
                // Create trend arrow if it doesn't exist
                $('.sky-total-amount').append('<span class="sky-trend-arrow" style="display:none;">▲</span>');
            }
            
            if (data.previous_total && data.total_amount !== data.previous_total) {
                const isUp = parseFloat(data.total_amount) > parseFloat(data.previous_total);
                $trendArrow
                    .text('▲')
                    .toggleClass('down', !isUp)
                    .attr('title', isUp ? 'Increase from previous period' : 'Decrease from previous period')
                    .show();
            } else {
                $trendArrow.hide();
            }
        },
        
        ensureChartCanvas: function() {
            // Main chart
            if ($('#sky-main-chart').length === 0) {
                const $container = $('.sky-chart-container').first();
                if ($container.length) {
                    $container.html('<canvas id="sky-main-chart"></canvas>');
                }
            }
            
            // Mini charts
            ['sky-installments-chart', 'sky-onetime-chart'].forEach(chartId => {
                if ($(`#${chartId}`).length === 0) {
                    const $container = $(`.sky-mini-chart-container`).filter(function() {
                        return $(this).closest('.sky-mini-box').find(`canvas#${chartId}`).length === 0;
                    }).first();
                    
                    if ($container.length) {
                        $container.html(`<canvas id="${chartId}"></canvas>`);
                    }
                }
            });
        },
        
        updateCharts: function(data) {
            // Update main chart
            if (data.chart_data && typeof data.chart_data === 'object') {
                updateMainChart(data.chart_data, skyInsights.currency || '$', skyInsights.i18n || {})
                    .catch(error => {
                        console.error('Error updating main chart:', error);
                    });
            }
            
            // Update mini charts
            Promise.all([
                updateMiniChart('sky-installments-chart', data.installments_chart || {}, chartInstances.installmentsChart),
                updateMiniChart('sky-onetime-chart', data.onetime_chart || {}, chartInstances.onetimeChart)
            ]).then(charts => {
                chartInstances.installmentsChart = charts[0];
                chartInstances.onetimeChart = charts[1];
            }).catch(error => {
                console.error('Error updating mini charts:', error);
            });
        },
        
        showErrorState: function() {
            $('.sky-total-amount .sky-amount-value').text('--');
            $('.sky-donations-count').text('Error loading data');
            $('.sky-new-donors-count').text('');
            
            // Show retry button
            if (!$('.sky-retry-button').length) {
                $('.sky-insights-header').after(
                    '<div class="sky-error-message" style="padding: 20px; text-align: center; background: #fff3cd; color: #856404; margin: 20px 0; border-radius: 8px;">' +
                    'Failed to load data. <button class="sky-retry-button" style="margin-left: 10px; padding: 5px 15px; background: #007aff; color: white; border: none; border-radius: 4px; cursor: pointer;">Retry</button>' +
                    '</div>'
                );
                
                $('.sky-retry-button').on('click', function() {
                    window.SkyInsights.Core.DataLoader.load();
                    $('.sky-error-message').remove();
                });
            }
        },
        
        /**
         * Update performance metrics boxes - FIXED
         */
        updatePerformanceMetrics: function(data) {
            try {
                // Calculate today's performance with timezone handling
                const today = new Date();
                const todayStr = this.formatDateISO(today);
                const yesterdayStr = this.formatDateISO(new Date(today.getTime() - 24 * 60 * 60 * 1000));
                
                // Get today's data from chart_data
                const todayAmount = (data.chart_data && data.chart_data[todayStr]) || 0;
                const yesterdayAmount = (data.chart_data && data.chart_data[yesterdayStr]) || 0;
                
                // Calculate today's donation count
                let todayCount = 0;
                if (data.total_amount > 0 && todayAmount > 0) {
                    const avgDonation = data.total_amount / data.total_count;
                    todayCount = Math.round(todayAmount / avgDonation);
                }
                
                // Calculate percentage change
                let percentageChange = 0;
                let changeClass = 'neutral';
                if (yesterdayAmount > 0) {
                    percentageChange = ((todayAmount - yesterdayAmount) / yesterdayAmount * 100);
                    changeClass = percentageChange > 0 ? 'positive' : percentageChange < 0 ? 'negative' : 'neutral';
                } else if (todayAmount > 0) {
                    percentageChange = 100;
                    changeClass = 'positive';
                }
                
                // Update Today's Performance box
                $('#sky-today-amount').text(this.formatCurrency(todayAmount));
                $('#sky-today-donations').text(todayCount + ' donations');
                
                const $percentage = $('#sky-today-percentage');
                $percentage.removeClass('positive negative neutral').addClass(changeClass);
                
                if (percentageChange !== 0) {
                    const arrow = percentageChange > 0 ? '↑' : '↓';
                    $percentage.html(
                        '<svg width="12" height="12" viewBox="0 0 12 12" fill="none">' +
                        `<path d="M6 ${percentageChange > 0 ? '2L3 5H5V10H7V5H9L6 2' : '10L9 7H7V2H5V7H3L6 10'}Z" fill="currentColor"/>` +
                        '</svg>' +
                        '<span>' + Math.abs(Math.round(percentageChange)) + '%</span>'
                    );
                } else {
                    $percentage.html('<span>0%</span>');
                }
                
                // Update comparison text safely
                $('#sky-today-comparison').empty().append(
                    $('<span class="sky-comparison-text">').text('vs'),
                    ' ',
                    $('<span class="sky-yesterday-amount">').text(this.formatCurrency(yesterdayAmount)),
                    ' ',
                    $('<span class="sky-comparison-divider">'),
                    ' ',
                    $('<span class="sky-comparison-text">').text('yesterday')
                );
                
                // Update Period Total box
                this.updatePeriodTotal(data);
                
                // Update Quick Insights
                this.updateQuickInsights(data);
                
            } catch (error) {
                console.error('Error updating performance metrics:', error);
            }
        },
        
        updatePeriodTotal: function(data) {
            const State = window.SkyInsights.Core.State;
            const dateRange = State.get('dateRange');
            const periodLabel = this.getPeriodLabel(dateRange);
            
            $('#sky-period-label').text(periodLabel);
            $('#sky-period-amount').text(this.formatCurrency(data.total_amount || 0));
            
            // Calculate average per day
            const dayCount = this.getDayCount(data.date_range);
            const avgPerDay = dayCount > 0 ? (data.total_amount || 0) / dayCount : 0;
            
            $('#sky-period-average').text('Avg per day: ' + this.formatCurrency(avgPerDay));
            $('#sky-period-new-donors').text('New donors: ' + this.formatCount(data.new_donors || 0));
        },
        
        updateQuickInsights: function(data) {
            // Calculate average donation
            const avgDonation = data.total_count > 0 ? (data.total_amount / data.total_count) : 0;
            $('#sky-peak-hour').text(this.formatCurrency(avgDonation));
            
            // Find best day from chart data
            if (data.chart_data && Object.keys(data.chart_data).length > 0) {
                let bestDay = null;
                let maxAmount = 0;
                
                Object.entries(data.chart_data).forEach(([date, amount]) => {
                    if (parseFloat(amount) > maxAmount) {
                        maxAmount = parseFloat(amount);
                        bestDay = date;
                    }
                });
                
                if (bestDay) {
                    try {
                        const dayName = new Date(bestDay + 'T00:00:00').toLocaleDateString('en-GB', { 
                            weekday: 'short', 
                            day: 'numeric' 
                        });
                        $('#sky-trend').text(dayName);
                    } catch (e) {
                        $('#sky-trend').text(bestDay);
                    }
                }
            } else {
                $('#sky-trend').text('No data');
            }
            
            // Calculate growth percentage
            this.calculateGrowth(data);
        },
        
        calculateGrowth: function(data) {
            const dates = Object.keys(data.chart_data || {}).sort();
            if (dates.length >= 7) {
                const recentTotal = dates.slice(-7).reduce((sum, date) => sum + (parseFloat(data.chart_data[date]) || 0), 0);
                const previousTotal = dates.slice(-14, -7).reduce((sum, date) => sum + (parseFloat(data.chart_data[date]) || 0), 0);
                
                if (previousTotal > 0) {
                    const growth = ((recentTotal - previousTotal) / previousTotal * 100).toFixed(0);
                    const growthText = growth > 0 ? `+${growth}%` : `${growth}%`;
                    const growthClass = growth > 0 ? 'positive' : growth < 0 ? 'negative' : 'neutral';
                    
                    $('#sky-top-source').empty().append(
                        $('<span>').addClass(growthClass).text(growthText)
                    );
                } else if (recentTotal > 0) {
                    $('#sky-top-source').html('<span class="positive">New</span>');
                } else {
                    $('#sky-top-source').text('0%');
                }
            } else {
                $('#sky-top-source').text('--');
            }
        },
        
        formatDateISO: function(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        
        getPeriodLabel: function(dateRange) {
            const labels = {
                'today': 'Today',
                'yesterday': 'Yesterday',
                'last7days': 'Last 7 days',
                'last14days': 'Last 14 days',
                'last30days': 'Last 30 days',
                'thisweek': 'This week',
                'thismonth': 'This month',
                'thisyear': 'This year',
                'lastweek': 'Last week',
                'lastmonth': 'Last month',
                'lastyear': 'Last year',
                'custom': 'Custom range'
            };
            return labels[dateRange] || 'Period';
        },
        
        getDayCount: function(dateRange) {
            if (!dateRange || !dateRange.start || !dateRange.end) return 1;
            
            try {
                const start = new Date(dateRange.start + 'T00:00:00');
                const end = new Date(dateRange.end + 'T00:00:00');
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                
                return diffDays;
            } catch (e) {
                return 1;
            }
        }
    };
    
    /**
     * Day and Time Tab Handler with AI Insights - FIXED
     */
    window.SkyInsights.ComplexTabs.Daytime = {
        currentView: 'count',
        heatmapData: null,
        heatmapAmounts: null,
        
        update: function(data) {
            try {
                console.log('Daytime tab update called with data:', data);
                
                // Check if we have filter_data
                if (!data || !data.filter_data) {
                    console.error('No filter_data in response for daytime tab');
                    this.showEmptyState();
                    return;
                }
                
                const heatmap = data.filter_data.heatmap || {};
                const heatmapAmounts = data.filter_data.heatmap_amounts || {};
                
                // Store data for view switching
                this.heatmapData = heatmap;
                this.heatmapAmounts = heatmapAmounts;
                
                // Check if heatmap has any data
                const hasData = Object.keys(heatmap).some(key => parseInt(heatmap[key]) > 0);
                
                if (!hasData) {
                    console.log('No heatmap data found');
                    this.showEmptyState();
                    return;
                }
                
                // Update heatmap
                this.renderHeatmap(heatmap, heatmapAmounts);
                
                // Analyze patterns for AI insights
                const insights = this.analyzeTimePatterns(heatmap, heatmapAmounts);
                
                // Update AI insight cards
                this.updateAIInsightCards(insights);
                
                // Generate AI recommendations
                this.generateAIRecommendations(insights, data.date_range);
                
                // Handle heatmap view toggle
                this.bindHeatmapToggle();
                
                // Update date range display if available
                if (data.date_range) {
                    const dateRangeText = this.formatDateRangeForDisplay(data.date_range);
                    $('.sky-box-description').text('Smart insights based on your donation patterns ' + dateRangeText);
                }
                
            } catch (error) {
                console.error('Error updating daytime tab:', error);
                this.showEmptyState();
            }
        },
        
        showEmptyState: function() {
            const $grid = $('#sky-heatmap');
            $grid.empty();
            
            // Create empty heatmap with proper structure
            const emptyHtml = '<div class="sky-heatmap-empty-state">' +
                '<p style="text-align: center; color: #86868b; padding: 40px;">' +
                'No donation data available for the selected period.' +
                '</p></div>';
            
            $grid.html(emptyHtml);
            
            // Update AI cards with empty state
            $('#sky-best-time-value').text('--');
            $('#sky-best-time-day').text('--');
            $('#sky-best-time-desc').text('No data available for this period');
            
            $('#sky-peak-day-value').text('--');
            $('#sky-peak-day-percent').text('--');
            $('#sky-peak-day-desc').text('No data available for this period');
            
            $('#sky-opportunity-value').text('--');
            $('#sky-opportunity-time').text('--');
            $('#sky-opportunity-desc').text('Select a date range with data');
            
            // Clear recommendations
            $('#sky-ai-recommendations').html(
                '<div class="sky-no-data" style="text-align: center; padding: 40px; color: #86868b;">' +
                '<p>No donation data available for the selected date range.</p>' +
                '<p style="font-size: 14px; margin-top: 10px;">Try selecting a different date range or wait for donation data to be collected.</p>' +
                '</div>'
            );
        },
        
        formatDateRangeForDisplay: function(dateRange) {
            if (!dateRange || !dateRange.start || !dateRange.end) return '';
            
            try {
                const start = new Date(dateRange.start + 'T00:00:00');
                const end = new Date(dateRange.end + 'T00:00:00');
                const options = { month: 'short', day: 'numeric', year: 'numeric' };
                
                return 'from ' + start.toLocaleDateString('en-GB', options) + ' to ' + end.toLocaleDateString('en-GB', options);
            } catch (e) {
                return '';
            }
        },
        
        renderHeatmap: function(heatmap, heatmapAmounts) {
            const $grid = $('#sky-heatmap');
            $grid.empty();
            
            const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            
            // Find max value for color scaling
            const maxValue = Math.max(...Object.values(heatmap).map(v => parseInt(v) || 0), 1);
            
            // Track peak times for highlighting
            const peakTimes = this.findPeakTimes(heatmap, 3);
            
            // Create heatmap cells
            for (let day = 0; day < 7; day++) {
                for (let hour = 0; hour < 24; hour++) {
                    const key = day + '-' + hour;
                    const value = parseInt(heatmap[key]) || 0;
                    const amount = parseFloat(heatmapAmounts[key]) || 0;
                    const intensity = maxValue > 0 ? value / maxValue : 0;
                    
                    // Format time
                    const hourDisplay = this.formatHour(hour);
                    const currency = skyInsights.currency || '$';
                    const tooltipText = `${days[day]}, ${hourDisplay}: ${value} donation${value !== 1 ? 's' : ''} (${currency}${formatNumber(amount)})`;
                    
                    // Calculate background color
                    let backgroundColor = '#ffffff';
                    if (value > 0) {
                        backgroundColor = `rgba(0, 122, 255, ${0.1 + intensity * 0.8})`;
                    }
                    
                    const $cell = $('<div>')
                        .addClass('sky-heatmap-cell')
                        .css('background-color', backgroundColor)
                        .attr('data-tooltip', tooltipText)
                        .attr('data-value', value)
                        .attr('data-amount', amount)
                        .attr('data-day', day)
                        .attr('data-hour', hour);
                    
                    // Add peak class for top performing times
                    if (peakTimes.includes(key)) {
                        $cell.addClass('peak');
                    }
                    
                    // Add hover tooltip
                    this.bindCellTooltip($cell, tooltipText);
                    
                    $grid.append($cell);
                }
            }
        },
        
        bindCellTooltip: function($cell, tooltipText) {
            let $tooltip = null;
            
            $cell.on('mouseenter', function(e) {
                // Remove any existing tooltip
                $('.sky-heatmap-tooltip').remove();
                
                $tooltip = $('<div class="sky-heatmap-tooltip">').text(tooltipText);
                $('body').append($tooltip);
                
                const updatePosition = () => {
                    const cellOffset = $(this).offset();
                    const cellWidth = $(this).outerWidth();
                    const cellHeight = $(this).outerHeight();
                    const tooltipHeight = $tooltip.outerHeight();
                    const tooltipWidth = $tooltip.outerWidth();
                    const windowWidth = $(window).width();
                    
                    let top = cellOffset.top - tooltipHeight - 10;
                    let left = cellOffset.left + (cellWidth / 2) - (tooltipWidth / 2);
                    
                    // Adjust if tooltip goes off screen
                    if (top < $(window).scrollTop()) {
                        top = cellOffset.top + cellHeight + 10;
                        $tooltip.addClass('bottom');
                    }
                    
                    if (left < 10) {
                        left = 10;
                    } else if (left + tooltipWidth > windowWidth - 10) {
                        left = windowWidth - tooltipWidth - 10;
                    }
                    
                    $tooltip.css({
                        top: top,
                        left: left,
                        display: 'block'
                    });
                };
                
                updatePosition();
                $(window).on('scroll.tooltip resize.tooltip', updatePosition);
            });
            
            $cell.on('mouseleave', function() {
                if ($tooltip) {
                    $tooltip.remove();
                    $tooltip = null;
                }
                $(window).off('.tooltip');
            });
        },
        
        analyzeTimePatterns: function(heatmap, amounts) {
            const insights = {
                bestHour: null,
                bestDay: null,
                peakValue: 0,
                totalDonations: 0,
                totalAmount: 0,
                dayTotals: {},
                hourTotals: {},
                patterns: []
            };
            
            // Initialize totals
            for (let i = 0; i < 7; i++) {
                insights.dayTotals[i] = { count: 0, amount: 0 };
            }
            for (let i = 0; i < 24; i++) {
                insights.hourTotals[i] = { count: 0, amount: 0 };
            }
            
            // Analyze all data points
            Object.entries(heatmap).forEach(([key, value]) => {
                const [day, hour] = key.split('-').map(Number);
                const count = parseInt(value) || 0;
                const amount = parseFloat(amounts[key]) || 0;
                
                insights.totalDonations += count;
                insights.totalAmount += amount;
                
                insights.dayTotals[day].count += count;
                insights.dayTotals[day].amount += amount;
                insights.hourTotals[hour].count += count;
                insights.hourTotals[hour].amount += amount;
                
                if (count > insights.peakValue) {
                    insights.peakValue = count;
                    insights.bestHour = hour;
                    insights.bestDay = day;
                }
            });
            
            // Find patterns
            insights.patterns = this.findDonationPatterns(insights);
            
            return insights;
        },
        
        findDonationPatterns: function(insights) {
            const patterns = [];
            
            // Find busiest day
            const busiestDay = Object.entries(insights.dayTotals)
                .sort((a, b) => b[1].count - a[1].count)[0];
            
            // Find busiest hour
            const busiestHour = Object.entries(insights.hourTotals)
                .sort((a, b) => b[1].count - a[1].count)[0];
            
            // Weekend vs weekday pattern
            const weekdayTotal = [0, 1, 2, 3, 4].reduce((sum, day) => sum + insights.dayTotals[day].count, 0);
            const weekendTotal = [5, 6].reduce((sum, day) => sum + insights.dayTotals[day].count, 0);
            
            // Morning vs evening pattern
            const morningTotal = Array.from({length: 4}, (_, i) => i + 6).reduce((sum, hour) => sum + (insights.hourTotals[hour]?.count || 0), 0);
            const eveningTotal = Array.from({length: 4}, (_, i) => i + 18).reduce((sum, hour) => sum + (insights.hourTotals[hour]?.count || 0), 0);
            
            patterns.push({
                type: 'busiest_day',
                day: parseInt(busiestDay[0]),
                value: busiestDay[1].count,
                percentage: insights.totalDonations > 0 ? (busiestDay[1].count / insights.totalDonations * 100).toFixed(1) : 0
            });
            
            patterns.push({
                type: 'busiest_hour',
                hour: parseInt(busiestHour[0]),
                value: busiestHour[1].count,
                percentage: insights.totalDonations > 0 ? (busiestHour[1].count / insights.totalDonations * 100).toFixed(1) : 0
            });
            
            patterns.push({
                type: 'weekend_weekday',
                weekdayTotal,
                weekendTotal,
                weekdayAvg: weekdayTotal / 5,
                weekendAvg: weekendTotal / 2
            });
            
            patterns.push({
                type: 'time_of_day',
                morningTotal,
                eveningTotal,
                morningPercentage: insights.totalDonations > 0 ? (morningTotal / insights.totalDonations * 100).toFixed(1) : 0,
                eveningPercentage: insights.totalDonations > 0 ? (eveningTotal / insights.totalDonations * 100).toFixed(1) : 0
            });
            
            return patterns;
        },
        
        updateAIInsightCards: function(insights) {
            const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            
            // Best time window
            if (insights.bestHour !== null && insights.bestDay !== null) {
                const hourDisplay = this.formatHour(insights.bestHour);
                const nextHour = this.formatHour((insights.bestHour + 1) % 24);
                
                $('#sky-best-time-value').text(`${hourDisplay} - ${nextHour}`);
                $('#sky-best-time-day').text(days[insights.bestDay]);
                $('#sky-best-time-desc').text(`${insights.peakValue} donations typically occur during this hour`);
            }
            
            // Peak day performance
            const peakDayPattern = insights.patterns.find(p => p.type === 'busiest_day');
            if (peakDayPattern) {
                $('#sky-peak-day-value').text(days[peakDayPattern.day]);
                $('#sky-peak-day-percent').text(`${peakDayPattern.percentage}% of weekly donations`);
                $('#sky-peak-day-desc').text(`Consistently your strongest performing day`);
            }
            
            // Hidden opportunity
            const opportunity = this.findHiddenOpportunity(insights);
            if (opportunity) {
                $('#sky-opportunity-value').text(opportunity.suggestion);
                $('#sky-opportunity-time').text(opportunity.timing);
                $('#sky-opportunity-desc').text(opportunity.reason);
            }
        },
        
        findHiddenOpportunity: function(insights) {
            const opportunities = [];
            const currency = skyInsights.currency || '$';
            
            // Find underutilized high-value times
            Object.entries(insights.hourTotals).forEach(([hour, data]) => {
                if (data.count > 0 && data.amount > 0) {
                    const avgDonation = data.amount / data.count;
                    const hourNum = parseInt(hour);
                    const overallAvg = insights.totalDonations > 0 ? insights.totalAmount / insights.totalDonations : 0;
                    
                    // If average donation is high but volume is low
                    if (avgDonation > overallAvg * 1.5 && data.count < insights.totalDonations / 24) {
                        opportunities.push({
                            type: 'high_value_low_volume',
                            hour: hourNum,
                            avgDonation,
                            count: data.count,
                            potential: avgDonation * (insights.totalDonations / 24)
                        });
                    }
                }
            });
            
            // Find gaps in coverage
            for (let hour = 9; hour <= 20; hour++) {
                const hourData = insights.hourTotals[hour] || { count: 0 };
                if (hourData.count < insights.totalDonations / 48) {
                    opportunities.push({
                        type: 'coverage_gap',
                        hour,
                        count: hourData.count
                    });
                }
            }
            
            // Return the best opportunity
            if (opportunities.length > 0) {
                const best = opportunities.sort((a, b) => (b.potential || 0) - (a.potential || 0))[0];
                
                if (best.type === 'high_value_low_volume') {
                    return {
                        suggestion: `Target ${this.formatHour(best.hour)}`,
                        timing: `${currency}${formatNumber(best.avgDonation, 0)} avg donation`,
                        reason: `High-value donors active but underutilized time slot`
                    };
                } else if (best.type === 'coverage_gap') {
                    return {
                        suggestion: `Expand to ${this.formatHour(best.hour)}`,
                        timing: 'Prime hours gap',
                        reason: 'Missing opportunity during typical active hours'
                    };
                }
            }
            
            return {
                suggestion: 'Optimize timing',
                timing: 'Analyze patterns',
                reason: 'Collect more data for personalized insights'
            };
        },
        
        generateAIRecommendations: function(insights, dateRange) {
            const $container = $('#sky-ai-recommendations');
            $container.empty();
            
            const recommendations = [];
            const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            
            // Pattern-based recommendations
            insights.patterns.forEach(pattern => {
                if (pattern.type === 'busiest_day' && pattern.value > 0) {
                    recommendations.push({
                        priority: 'high',
                        icon: 'success',
                        title: `Focus on ${days[pattern.day]}s`,
                        description: `Your data shows <strong>${pattern.percentage}%</strong> of donations occur on ${days[pattern.day]}s. Schedule major campaigns and email sends for this day to maximize engagement.`
                    });
                }
                
                if (pattern.type === 'weekend_weekday' && pattern.weekendAvg > pattern.weekdayAvg * 1.2) {
                    recommendations.push({
                        priority: 'high',
                        icon: 'info',
                        title: 'Weekend Warriors',
                        description: `Weekend donations average <strong>${formatNumber(pattern.weekendAvg, 0)}</strong> per day vs <strong>${formatNumber(pattern.weekdayAvg, 0)}</strong> on weekdays. Consider weekend-specific campaigns.`
                    });
                }
                
                if (pattern.type === 'time_of_day' && (parseFloat(pattern.morningPercentage) > 0 || parseFloat(pattern.eveningPercentage) > 0)) {
                    const dominant = parseFloat(pattern.eveningPercentage) > parseFloat(pattern.morningPercentage) ? 'evening' : 'morning';
                    const percentage = dominant === 'evening' ? pattern.eveningPercentage : pattern.morningPercentage;
                    recommendations.push({
                        priority: 'medium',
                        icon: 'info',
                        title: `${dominant.charAt(0).toUpperCase() + dominant.slice(1)} Preference`,
                        description: `<strong>${percentage}%</strong> of donations occur in the ${dominant} (${dominant === 'evening' ? '6PM-10PM' : '6AM-10AM'}). Time your communications accordingly.`
                    });
                }
            });
            
            // Time-specific recommendations
            const topHours = Object.entries(insights.hourTotals)
                .filter(([_, data]) => data.count > 0)
                .sort((a, b) => b[1].count - a[1].count)
                .slice(0, 3);
            
            if (topHours.length > 0) {
                const hourRanges = topHours.map(([hour]) => this.formatHour(parseInt(hour))).join(', ');
                recommendations.push({
                    priority: 'high',
                    icon: 'success',
                    title: 'Optimal Send Times',
                    description: `Your top performing hours are <strong>${hourRanges}</strong>. Schedule emails and social posts 30-60 minutes before these times.`
                });
            }
            
            // Low activity warnings
            const lowActivityDays = Object.entries(insights.dayTotals)
                .filter(([day, data]) => data.count < insights.totalDonations / 14)
                .map(([day]) => days[parseInt(day)]);
            
            if (lowActivityDays.length > 0 && insights.totalDonations > 0) {
                recommendations.push({
                    priority: 'low',
                    icon: 'warning',
                    title: 'Underperforming Days',
                    description: `${lowActivityDays.join(', ')} show significantly lower activity. Test new approaches or accept these as rest days for your audience.`
                });
            }
            
            // If no recommendations, show helpful message
            if (recommendations.length === 0) {
                recommendations.push({
                    priority: 'medium',
                    icon: 'info',
                    title: 'Building Insights',
                    description: 'As more donation data is collected, we\'ll provide personalized recommendations to optimize your fundraising timing.'
                });
            }
            
            // Render recommendations
            recommendations.forEach(rec => {
                const iconSvg = this.getRecommendationIcon(rec.icon);
                const $item = $('<div class="sky-recommendation-item">')
                    .append(
                        $('<div class="sky-recommendation-icon">').addClass(rec.icon).html(iconSvg),
                        $('<div class="sky-recommendation-content">')
                            .append(
                                $('<h4>').text(rec.title),
                                $('<p>').html(rec.description)
                            )
                    );
                $container.append($item);
            });
            
            // Add date range context
            const dateRangeText = this.formatDateRangeContext(dateRange);
            if (dateRangeText) {
                $container.append(
                    $('<div class="sky-recommendation-context">')
                        .append($('<p>').append($('<em>').text('These insights are based on ' + dateRangeText)))
                );
            }
        },
        
        bindHeatmapToggle: function() {
            const self = this;
            
            $('.sky-heatmap-toggle').off('click').on('click', function() {
                $('.sky-heatmap-toggle').removeClass('active');
                $(this).addClass('active');
                
                const view = $(this).data('view');
                self.currentView = view;
                self.updateHeatmapView(view);
            });
        },
        
        updateHeatmapView: function(view) {
            if (!this.heatmapData || !this.heatmapAmounts) {
                return;
            }
            
            const $cells = $('.sky-heatmap-cell');
            
            if (view === 'amount') {
                // Find max amount for scaling
                const maxAmount = Math.max(...Object.values(this.heatmapAmounts).map(v => parseFloat(v) || 0), 1);
                
                $cells.each(function() {
                    const amount = parseFloat($(this).attr('data-amount')) || 0;
                    const intensity = maxAmount > 0 ? amount / maxAmount : 0;
                    
                    let backgroundColor = '#ffffff';
                    if (amount > 0) {
                        backgroundColor = `rgba(0, 122, 255, ${0.1 + intensity * 0.8})`;
                    }
                    
                    $(this).css('background-color', backgroundColor);
                });
            } else {
                // Default to count view
                const maxValue = Math.max(...Object.values(this.heatmapData).map(v => parseInt(v) || 0), 1);
                
                $cells.each(function() {
                    const value = parseInt($(this).attr('data-value')) || 0;
                    const intensity = maxValue > 0 ? value / maxValue : 0;
                    
                    let backgroundColor = '#ffffff';
                    if (value > 0) {
                        backgroundColor = `rgba(0, 122, 255, ${0.1 + intensity * 0.8})`;
                    }
                    
                    $(this).css('background-color', backgroundColor);
                });
            }
        },
        
        findPeakTimes: function(heatmap, count) {
            return Object.entries(heatmap)
                .filter(([_, value]) => parseInt(value) > 0)
                .sort((a, b) => parseInt(b[1]) - parseInt(a[1]))
                .slice(0, count)
                .map(([key]) => key);
        },
        
        formatHour: function(hour) {
            if (hour === 0) return '12AM';
            if (hour < 12) return hour + 'AM';
            if (hour === 12) return '12PM';
            return (hour - 12) + 'PM';
        },
        
        getRecommendationIcon: function(type) {
            const icons = {
                success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7 10l2 2 4-4m5 2a8 8 0 11-16 0 8 8 0 0116 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                warning: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 6v4m0 4h.01M4.93 4.93l10.14 10.14m0-10.14L4.93 15.07" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                info: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 9v6m0-9h.01M19 10a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            };
            return icons[type] || icons.info;
        },
        
        formatDateRangeContext: function(dateRange) {
            if (!dateRange) return '';
            
            const State = window.SkyInsights.Core.State;
            const rangeType = State.get('dateRange');
            
            const rangeMap = {
                'today': "today's data",
                'yesterday': "yesterday's data",
                'last7days': 'the last 7 days',
                'last30days': 'the last 30 days',
                'thisweek': 'this week',
                'thismonth': 'this month',
                'thisyear': 'this year',
                'lastweek': 'last week',
                'lastmonth': 'last month',
                'lastyear': 'last year',
                'custom': 'your selected date range'
            };
            return rangeMap[rangeType] || 'the selected period';
        }
    };
    
    /**
     * Customers Tab Handler - FIXED
     */
    window.SkyInsights.ComplexTabs.Customers = {
        currentSort: { column: 'total_value', direction: 'desc' },
        
        update: function(data) {
            try {
                // Show loading state immediately for better UX
                if (!data || !data.filter_data) {
                    this.showLoadingState();
                    return;
                }
                
                const customerData = data.filter_data;
                
                if (!customerData || !customerData.customer_details || Object.keys(customerData.customer_details).length === 0) {
                    this.showEmptyState();
                    return;
                }
                
                // Calculate metrics
                const metrics = this.calculateMetrics(customerData);
                
                // Update metric cards
                this.updateMetricCards(metrics);
                
                // Update chart
                if (customerData.daily && Object.keys(customerData.daily).length > 0) {
                    updateCustomersChart(customerData.daily).catch(error => {
                        console.error('Error updating customers chart:', error);
                    });
                } else {
                    // Show empty chart state
                    this.showEmptyChart();
                }
                
                // Update top customers table
                this.updateTopCustomersTable(customerData.customer_details || {});
                
            } catch (error) {
                console.error('Error updating customers tab:', error);
                this.showEmptyState();
            }
        },
        
        showLoadingState: function() {
            // Show skeleton loaders for metrics
            $('.sky-new-customers').html('<div class="sky-skeleton sky-skeleton-number" style="width: 60px; height: 32px;"></div>');
            $('.sky-new-customers-percent').html('<div class="sky-skeleton sky-skeleton-text" style="width: 40px;"></div>');
            $('.sky-returning-customers').html('<div class="sky-skeleton sky-skeleton-number" style="width: 60px; height: 32px;"></div>');
            $('.sky-returning-customers-percent').html('<div class="sky-skeleton sky-skeleton-text" style="width: 40px;"></div>');
            $('.sky-retention-rate').html('<div class="sky-skeleton sky-skeleton-text" style="width: 40px;"></div>');
            $('.sky-avg-customer-value').html('<div class="sky-skeleton sky-skeleton-text" style="width: 80px;"></div>');
            
            // Show skeleton for chart
            const $chartContainer = $('#sky-customers-chart').parent();
            if ($chartContainer.find('.sky-skeleton').length === 0) {
                $chartContainer.html('<div class="sky-skeleton sky-skeleton-chart" style="height: 250px;"></div>');
            }
            
            // Show skeleton for table
            $('#sky-top-customers-table').html(`
                <tr>
                    <td colspan="6">
                        <div class="sky-skeleton sky-skeleton-text" style="width: 100%; margin: 10px 0;"></div>
                        <div class="sky-skeleton sky-skeleton-text" style="width: 100%; margin: 10px 0;"></div>
                        <div class="sky-skeleton sky-skeleton-text" style="width: 100%; margin: 10px 0;"></div>
                    </td>
                </tr>
            `);
        },
        
        showEmptyState: function() {
            $('.sky-new-customers').text('0');
            $('.sky-new-customers-percent').text('0%');
            $('.sky-returning-customers').text('0');
            $('.sky-returning-customers-percent').text('0%');
            $('.sky-retention-rate').text('0%');
            
            const currency = skyInsights.currency || '$';
            $('.sky-avg-customer-value').text(currency + '0');
            
            this.showEmptyChart();
            
            $('#sky-top-customers-table').html(
                '<tr><td colspan="6" style="text-align: center; padding: 24px; color: #86868b;">No donor data available for the selected period</td></tr>'
            );
        },
        
        showEmptyChart: function() {
            const $chartContainer = $('#sky-customers-chart').parent();
            if ($chartContainer.find('canvas').length === 0) {
                $chartContainer.html('<canvas id="sky-customers-chart"></canvas>');
            }
            
            // Draw empty state chart
            loadChartLibrary().then(() => {
                const ctx = document.getElementById('sky-customers-chart');
                if (ctx && chartInstances.customersChart) {
                    chartInstances.customersChart.destroy();
                    chartInstances.customersChart = null;
                }
            });
        },
        
        calculateMetrics: function(customerData) {
            const newCustomers = parseInt(customerData.new_customers) || 0;
            const returningCustomers = parseInt(customerData.returning_customers) || 0;
            const totalCustomers = parseInt(customerData.total_customers) || 0;
            const totalValue = parseFloat(customerData.total_customer_value) || 0;
            
            return {
                newCustomers,
                returningCustomers,
                totalCustomers,
                totalValue,
                newPercent: totalCustomers > 0 ? ((newCustomers / totalCustomers) * 100).toFixed(1) : 0,
                returningPercent: totalCustomers > 0 ? ((returningCustomers / totalCustomers) * 100).toFixed(1) : 0,
                retentionRate: totalCustomers > 0 ? ((returningCustomers / totalCustomers) * 100).toFixed(1) : 0,
                avgCustomerValue: totalCustomers > 0 ? (totalValue / totalCustomers) : 0
            };
        },
        
        updateMetricCards: function(metrics) {
            $('.sky-new-customers').text(formatNumber(metrics.newCustomers, 0));
            $('.sky-new-customers-percent').text(metrics.newPercent + '%');
            $('.sky-returning-customers').text(formatNumber(metrics.returningCustomers, 0));
            $('.sky-returning-customers-percent').text(metrics.returningPercent + '%');
            $('.sky-retention-rate').text(metrics.retentionRate + '%');
            
            const currency = skyInsights.currency || '$';
            $('.sky-avg-customer-value').text(currency + formatNumber(metrics.avgCustomerValue));
            
            // Restore chart canvas if it was replaced by skeleton
            const $chartContainer = $('#sky-customers-chart').parent();
            if ($chartContainer.find('canvas').length === 0) {
                $chartContainer.html('<canvas id="sky-customers-chart"></canvas>');
            }
        },
        
        updateTopCustomersTable: function(customers) {
            const $tbody = $('#sky-top-customers-table');
            $tbody.empty();
            
            // Convert to array and sort
            const sortedCustomers = Object.entries(customers)
                .map(([email, customer]) => ({ email, ...customer }))
                .sort((a, b) => {
                    const aVal = a[this.currentSort.column];
                    const bVal = b[this.currentSort.column];
                    
                    if (this.currentSort.direction === 'desc') {
                        return bVal > aVal ? 1 : bVal < aVal ? -1 : 0;
                    } else {
                        return aVal > bVal ? 1 : aVal < bVal ? -1 : 0;
                    }
                })
                .slice(0, 10);
            
            sortedCustomers.forEach(customer => {
                const avgDonation = customer.total_orders > 0 ? customer.total_value / customer.total_orders : 0;
                const { status, statusClass } = this.getCustomerStatus(customer);
                
                const $row = $('<tr>');
                
                // Name and email (safely)
                $row.append(
                    $('<td>').append(
                        $('<div>')
                            .append($('<strong>').text(customer.name || 'Anonymous'))
                            .append($('<br>'))
                            .append($('<small>').text(customer.email).css('color', '#86868b'))
                    )
                );
                
                // First order date
                $row.append($('<td>').text(formatDate(customer.first_order_date)));
                
                // Total orders
                $row.append($('<td>').text(customer.total_orders));
                
                // Total value
                const currency = skyInsights.currency || '$';
                $row.append($('<td>').text(currency + formatNumber(customer.total_value)));
                
                // Average donation
                $row.append($('<td>').text(currency + formatNumber(avgDonation)));
                
                // Status
                $row.append(
                    $('<td>').append(
                        $('<span>').addClass('customer-status ' + statusClass).text(status)
                    )
                );
                
                $tbody.append($row);
            });
            
            if (sortedCustomers.length === 0) {
                $tbody.append(
                    $('<tr>').append(
                        $('<td colspan="6" style="text-align: center; padding: 24px; color: #86868b;">')
                            .text('No donor data available for the selected period')
                    )
                );
            }
            
            // Add click handlers for sorting (optional)
            this.bindTableSorting();
        },
        
        getCustomerStatus: function(customer) {
            try {
                const lastOrderDate = new Date(customer.last_order_date + 'T00:00:00');
                const daysSinceLastOrder = Math.floor((new Date() - lastOrderDate) / (1000 * 60 * 60 * 24));
                
                if (daysSinceLastOrder < 90) {
                    return { status: 'Active', statusClass: 'active' };
                } else if (daysSinceLastOrder < 180) {
                    return { status: 'At Risk', statusClass: 'moderate' };
                } else {
                    return { status: 'Inactive', statusClass: 'inactive' };
                }
            } catch (e) {
                return { status: 'Unknown', statusClass: 'inactive' };
            }
        },
        
        bindTableSorting: function() {
            // Add sorting to table headers (optional enhancement)
            const self = this;
            
            $('.sky-data-table th').off('click.sorting').on('click.sorting', function() {
                const column = $(this).data('sort');
                if (!column) return;
                
                if (self.currentSort.column === column) {
                    self.currentSort.direction = self.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    self.currentSort.column = column;
                    self.currentSort.direction = 'desc';
                }
                
                // Re-render table with new sort
                const State = window.SkyInsights.Core.State;
                const cachedData = State.getCachedTabData('customers');
                if (cachedData && cachedData.filter_data && cachedData.filter_data.customer_details) {
                    self.updateTopCustomersTable(cachedData.filter_data.customer_details);
                }
            });
        }
    };
    
    /**
     * URL Tab Handler - FIXED
     */
    window.SkyInsights.ComplexTabs.Url = {
        currentPage: 1,
        itemsPerPage: 10,
        
        update: function(data) {
            try {
                if (!data || !data.filter_data) {
                    this.showEmptyState();
                    return;
                }
                
                const urls = data.filter_data;
                
                if (urls && Object.keys(urls).length > 0) {
                    this.updateChart(urls);
                    this.updateTable(urls);
                } else {
                    this.showEmptyState();
                }
            } catch (error) {
                console.error('Error updating URL tab:', error);
                this.showEmptyState();
            }
        },
        
        showEmptyState: function() {
            $('#sky-url-table').html(
                '<tr><td colspan="6" style="text-align: center; padding: 24px; color: #86868b;">' +
                'No URL data found. Make sure URL tracking is enabled and you have donation data for the selected period.' +
                '</td></tr>'
            );
            
            // Clear chart
            const ctx = document.getElementById('sky-url-chart');
            if (ctx && chartInstances.urlChart) {
                chartInstances.urlChart.destroy();
                chartInstances.urlChart = null;
            }
        },
        
        updateChart: async function(urls) {
            const ctx = document.getElementById('sky-url-chart');
            if (!ctx) return;
            
            await loadChartLibrary();
            
            const datasets = [];
            const allDates = new Set();
            
            // Sort URLs by total revenue and take top 10
            const sortedUrls = Object.entries(urls)
                .sort((a, b) => (b[1].total || 0) - (a[1].total || 0))
                .slice(0, 10);
            
            // Collect all dates
            sortedUrls.forEach(([url, data]) => {
                const chartData = data.chart_data || {};
                Object.keys(chartData).forEach(date => allDates.add(date));
            });
            
            const sortedDates = Array.from(allDates).sort();
            
            // Create datasets
            sortedUrls.forEach(([url, data], index) => {
                const color = getChartColor(index);
                const chartData = data.chart_data || {};
                
                // Truncate URL for display
                const displayUrl = url.length > 40 ? url.substring(0, 40) + '...' : url;
                
                datasets.push({
                    label: displayUrl,
                    data: sortedDates.map(date => chartData[date] || 0),
                    borderColor: color,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 0
                });
            });
            
            // Destroy existing chart
            if (chartInstances.urlChart) {
                chartInstances.urlChart.destroy();
            }
            
            try {
                chartInstances.urlChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: sortedDates.map(date => formatChartDate(date)),
                        datasets: datasets
                    },
                    options: window.SkyInsightsUtils.getMultiLineChartOptions(skyInsights.currency || '$')
                });
            } catch (error) {
                console.error('Error creating URL chart:', error);
            }
        },
        
        updateTable: function(urls) {
            const $tbody = $('#sky-url-table');
            $tbody.empty();
            
            const sortedUrls = Object.entries(urls).sort((a, b) => (b[1].total || 0) - (a[1].total || 0));
            
            // Reset page if needed
            const totalPages = Math.ceil(sortedUrls.length / this.itemsPerPage);
            if (this.currentPage > totalPages) {
                this.currentPage = 1;
            }
            
            this.renderPage(sortedUrls, this.currentPage);
            
            if (sortedUrls.length > this.itemsPerPage) {
                this.renderPagination(sortedUrls.length);
            }
        },
        
        renderPage: function(sortedUrls, page) {
            const $tbody = $('#sky-url-table');
            $tbody.empty();
            
            const start = (page - 1) * this.itemsPerPage;
            const end = start + this.itemsPerPage;
            const pageUrls = sortedUrls.slice(start, end);
            const currency = skyInsights.currency || '$';
            
            pageUrls.forEach(([url, stats]) => {
                const conversion = stats.visitors > 0 ? 
                    ((stats.donations / stats.visitors) * 100).toFixed(1) : 0;
                
                const $row = $('<tr>');
                
                // URL column
                const $urlCell = $('<td>');
                if (stats.product_name) {
                    $urlCell.append(
                        $('<a>')
                            .attr('href', 'https://' + url)
                            .attr('target', '_blank')
                            .attr('rel', 'noopener noreferrer')
                            .attr('title', stats.product_name)
                            .text(url)
                            .css({
                                'color': '#007aff',
                                'text-decoration': 'none'
                            })
                            .hover(
                                function() { $(this).css('text-decoration', 'underline'); },
                                function() { $(this).css('text-decoration', 'none'); }
                            )
                    );
                } else {
                    $urlCell.text(url);
                }
                $row.append($urlCell);
                
                // Other columns
                $row.append($('<td>').text(formatNumber(stats.visitors || 0, 0)));
                $row.append($('<td>').text(formatNumber(stats.checkout_opened || 0, 0)));
                $row.append($('<td>').text(formatNumber(stats.donations || 0, 0)));
                $row.append($('<td>').text(conversion + '%'));
                $row.append($('<td>').text(currency + formatNumber(stats.total || 0)));
                
                $tbody.append($row);
            });
            
            if (pageUrls.length === 0) {
                $tbody.append(
                    $('<tr>').append(
                        $('<td colspan="6" style="text-align: center; padding: 24px; color: #86868b;">')
                            .text('No URL data available')
                    )
                );
            }
        },
        
        renderPagination: function(totalItems) {
            const totalPages = Math.ceil(totalItems / this.itemsPerPage);
            
            // Remove existing pagination
            $('.sky-pagination').parent().parent().remove();
            
            const $paginationRow = $('<tr><td colspan="6" class="sky-pagination"></td></tr>');
            const $paginationCell = $paginationRow.find('.sky-pagination');
           
           // Previous button
           $paginationCell.append(
               $('<button>')
                   .text('← Prev')
                   .prop('disabled', this.currentPage === 1)
                   .on('click', () => {
                       if (this.currentPage > 1) {
                           this.currentPage--;
                           this.updateTable(this.getUrlsData());
                       }
                   })
           );
           
           // Page numbers
           const maxVisiblePages = 5;
           let startPage = Math.max(1, this.currentPage - Math.floor(maxVisiblePages / 2));
           let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
           
           if (endPage - startPage < maxVisiblePages - 1) {
               startPage = Math.max(1, endPage - maxVisiblePages + 1);
           }
           
           if (startPage > 1) {
               $paginationCell.append(
                   $('<button>').text('1').on('click', () => {
                       this.currentPage = 1;
                       this.updateTable(this.getUrlsData());
                   })
               );
               if (startPage > 2) {
                   $paginationCell.append($('<span>').text('...'));
               }
           }
           
           for (let i = startPage; i <= endPage; i++) {
               $paginationCell.append(
                   $('<button>')
                       .text(i)
                       .addClass(i === this.currentPage ? 'active' : '')
                       .on('click', () => {
                           this.currentPage = i;
                           this.updateTable(this.getUrlsData());
                       })
               );
           }
           
           if (endPage < totalPages) {
               if (endPage < totalPages - 1) {
                   $paginationCell.append($('<span>').text('...'));
               }
               $paginationCell.append(
                   $('<button>').text(totalPages).on('click', () => {
                       this.currentPage = totalPages;
                       this.updateTable(this.getUrlsData());
                   })
               );
           }
           
           // Next button
           $paginationCell.append(
               $('<button>')
                   .text('Next →')
                   .prop('disabled', this.currentPage === totalPages)
                   .on('click', () => {
                       if (this.currentPage < totalPages) {
                           this.currentPage++;
                           this.updateTable(this.getUrlsData());
                       }
                   })
           );
           
           // Record count
           $paginationCell.append(
               $('<span>')
                   .addClass('sky-record-count')
                   .text(totalItems + ' records')
           );
           
           // Add pagination to table
           $('#sky-url-table').parent().parent().after($paginationRow);
       },
       
       getUrlsData: function() {
           // Get cached data
           const State = window.SkyInsights.Core.State;
           const cachedData = State.getCachedTabData('url');
           
           if (cachedData && cachedData.filter_data) {
               return cachedData.filter_data;
           }
           
           return {};
       }
   };
   
   // Export hover state fix for all tabs
   $(document).on('mouseenter', 'a[target="_blank"]', function() {
       $(this).attr('rel', 'noopener noreferrer');
   });
   
})(jQuery);