/**
 * Sky Insights Dashboard - Simple Tabs Module - FIXED VERSION
 * Handles Frequencies, Payment Methods, Countries, and Designations tabs
 */

(function($) {
    'use strict';
    
    // Dependencies
    const { formatNumber, calculateMedian, getChartColor, generateDateLabels, generateRandomData, 
            getFlagEmoji, getMultiLineChartOptions, formatChartDate } = window.SkyInsightsUtils;
    const { loadChartLibrary, chartInstances } = window.SkyInsightsCharts;
    
    // Create namespace
    window.SkyInsights = window.SkyInsights || {};
    window.SkyInsights.SimpleTabs = {};
    
    /**
     * Frequencies Tab Handler - FIXED
     */
    window.SkyInsights.SimpleTabs.Frequencies = {
        currentSort: { column: 'total', direction: 'desc' },
        
        update: function(data) {
            try {
                console.log('Frequencies tab update called with data:', data);
                
                if (!data || !data.filter_data) {
                    console.error('No filter_data in frequencies response');
                    this.showEmptyState();
                    return;
                }
                
                const frequencies = data.filter_data;
                
                if (frequencies && typeof frequencies === 'object' && Object.keys(frequencies).length > 0) {
                    this.updateChart(frequencies);
                    this.updateTable(frequencies);
                } else {
                    this.showEmptyState();
                }
            } catch (error) {
                console.error('Error updating frequencies tab:', error);
                this.showEmptyState();
            }
        },
        
        showEmptyState: function() {
            // Clear chart
            const ctx = document.getElementById('sky-frequencies-chart');
            if (ctx && chartInstances.frequenciesChart) {
                chartInstances.frequenciesChart.destroy();
                chartInstances.frequenciesChart = null;
            }
            
            $('#sky-frequencies-table').html(
                '<tr><td colspan="5" style="text-align: center; padding: 24px; color: #86868b;">' +
                'No frequency data available for the selected period. ' +
                (this.checkSubscriptionsPlugin() ? '' : 'WooCommerce Subscriptions plugin may be required for full functionality.') +
                '</td></tr>'
            );
        },
        
        checkSubscriptionsPlugin: function() {
            // Check if subscriptions plugin indicator exists
            return window.skyInsights && window.skyInsights.hasSubscriptions;
        },
        
        updateChart: async function(frequencies) {
            const ctx = document.getElementById('sky-frequencies-chart');
            if (!ctx) {
                console.error('Frequencies chart canvas not found');
                return;
            }
            
            try {
                await loadChartLibrary();
                
                const datasets = [];
                const allDates = new Set();
                
                // Sort frequencies by total
                const sortedFreqs = Object.entries(frequencies)
                    .sort((a, b) => (b[1].total || 0) - (a[1].total || 0))
                    .slice(0, 10); // Limit to top 10
                
                // Collect all dates from chart data
                sortedFreqs.forEach(([freq, data]) => {
                    if (data.chart_data && typeof data.chart_data === 'object') {
                        Object.keys(data.chart_data).forEach(date => allDates.add(date));
                    }
                });
                
                const sortedDates = Array.from(allDates).sort();
                const hasRealData = sortedDates.length > 0;
                
                // Create datasets
                sortedFreqs.forEach(([freq, data], index) => {
                    const color = getChartColor(index);
                    let dataPoints = [];
                    
                    if (hasRealData && data.chart_data) {
                        dataPoints = sortedDates.map(date => parseFloat(data.chart_data[date]) || 0);
                    } else {
                        // Use placeholder data if no real data
                        dataPoints = generateRandomData(30);
                    }
                    
                    datasets.push({
                        label: freq,
                        data: dataPoints,
                        borderColor: color,
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    });
                });
                
                // Destroy existing chart
                if (chartInstances.frequenciesChart) {
                    chartInstances.frequenciesChart.destroy();
                }
                
                const chartLabels = hasRealData ? 
                    sortedDates.map(date => formatChartDate(date)) : 
                    generateDateLabels(30);
                
                chartInstances.frequenciesChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: datasets
                    },
                    options: this.getChartOptions()
                });
                
            } catch (error) {
                console.error('Error creating frequencies chart:', error);
            }
        },
        
        getChartOptions: function() {
            const currency = skyInsights.currency || '$';
            return {
                ...getMultiLineChartOptions(currency),
                plugins: {
                    ...getMultiLineChartOptions(currency).plugins,
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            };
        },
        
        updateTable: function(frequencies) {
            const $tbody = $('#sky-frequencies-table');
            $tbody.empty();
            
            const currency = skyInsights.currency || '$';
            const sortedFreqs = this.sortFrequencies(frequencies);
            
            sortedFreqs.forEach(([freq, data]) => {
                // Validate data
                const count = parseInt(data.count) || 0;
                const total = parseFloat(data.total) || 0;
                const average = count > 0 ? total / count : 0;
                
                // Calculate median safely
                let median = 0;
                if (data.median && Array.isArray(data.median) && data.median.length > 0) {
                    median = calculateMedian(data.median);
                }
                
                const $row = $('<tr>')
                    .append($('<td>').text(freq))
                    .append($('<td>').text(formatNumber(count, 0)))
                    .append($('<td>').text(currency + formatNumber(average)))
                    .append($('<td>').text(currency + formatNumber(median)))
                    .append($('<td>').text(currency + formatNumber(total)));
                
                $tbody.append($row);
            });
            
            if (sortedFreqs.length === 0) {
                this.showEmptyState();
            }
        },
        
        sortFrequencies: function(frequencies) {
            return Object.entries(frequencies).sort((a, b) => {
                const aData = a[1];
                const bData = b[1];
                
                let aVal, bVal;
                switch (this.currentSort.column) {
                    case 'count':
                        aVal = parseInt(aData.count) || 0;
                        bVal = parseInt(bData.count) || 0;
                        break;
                    case 'average':
                        aVal = aData.count > 0 ? (aData.total / aData.count) : 0;
                        bVal = bData.count > 0 ? (bData.total / bData.count) : 0;
                        break;
                    case 'median':
                        aVal = calculateMedian(aData.median || []);
                        bVal = calculateMedian(bData.median || []);
                        break;
                    default: // total
                        aVal = parseFloat(aData.total) || 0;
                        bVal = parseFloat(bData.total) || 0;
                }
                
                if (this.currentSort.direction === 'desc') {
                    return bVal - aVal;
                } else {
                    return aVal - bVal;
                }
            });
        }
    };
    
    /**
     * Payment Methods Tab Handler - FIXED VERSION
     */
    window.SkyInsights.SimpleTabs.Payment = {
        currentSort: { column: 'total', direction: 'desc' },
        
        update: function(data) {
            try {
                console.log('Payment tab update called with data:', data);
                
                // CRITICAL FIX: Access filter_data directly from data object
                const payments = data.filter_data;
                
                console.log('Payments filter_data:', payments);
                console.log('Has payments data:', payments && typeof payments === 'object');
                console.log('Payment keys:', payments ? Object.keys(payments) : 'none');
                
                if (payments && typeof payments === 'object' && Object.keys(payments).length > 0) {
                    console.log('Updating payment chart and table with', Object.keys(payments).length, 'payment methods');
                    this.updateChart(payments);
                    this.updateTable(payments);
                } else {
                    console.log('No payment data available, showing empty state');
                    this.showEmptyState();
                }
            } catch (error) {
                console.error('Error updating payment tab:', error);
                this.showEmptyState();
            }
        },
        
        showEmptyState: function() {
            $('#sky-payment-table').html(
                '<tr><td colspan="4" style="text-align: center; padding: 24px; color: #86868b;">' +
                'No payment data available for the selected period' +
                '</td></tr>'
            );
            
            // Clear the chart
            const ctx = document.getElementById('sky-payment-chart');
            if (ctx && chartInstances.paymentChart) {
                chartInstances.paymentChart.destroy();
                chartInstances.paymentChart = null;
            }
        },
        
        updateChart: async function(payments) {
            console.log('Updating payment chart with:', payments);
            
            const ctx = document.getElementById('sky-payment-chart');
            if (!ctx) {
                console.error('Payment chart canvas not found');
                return;
            }
            
            try {
                await loadChartLibrary();
                
                // Process payment data
                const datasets = [];
                const allDates = new Set();
                
                // Sort payments by total
                const sortedPayments = Object.entries(payments)
                    .sort((a, b) => (b[1].total || 0) - (a[1].total || 0))
                    .slice(0, 10); // Limit to top 10
                
                // First, collect all dates from all payment methods
                sortedPayments.forEach(([method, data]) => {
                    if (data.chart_data && typeof data.chart_data === 'object') {
                        Object.keys(data.chart_data).forEach(date => allDates.add(date));
                    }
                });
                
                const sortedDates = Array.from(allDates).sort();
                const hasRealData = sortedDates.length > 0;
                
                // Create datasets for each payment method
                sortedPayments.forEach(([method, data], index) => {
                    const color = getChartColor(index);
                    let dataPoints = [];
                    
                    if (hasRealData && data.chart_data) {
                        dataPoints = sortedDates.map(date => parseFloat(data.chart_data[date]) || 0);
                    } else {
                        // Use placeholder data if no real data
                        dataPoints = generateRandomData(30);
                    }
                    
                    datasets.push({
                        label: method,
                        data: dataPoints,
                        borderColor: color,
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    });
                });
                
                // Destroy existing chart
                if (chartInstances.paymentChart) {
                    chartInstances.paymentChart.destroy();
                }
                
                const chartLabels = hasRealData ? 
                    sortedDates.map(date => formatChartDate(date)) : 
                    generateDateLabels(30);
                
                chartInstances.paymentChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: datasets
                    },
                    options: this.getChartOptions()
                });
                
                console.log('Payment chart updated successfully');
                
            } catch (error) {
                console.error('Error creating payment chart:', error);
            }
        },
        
        getChartOptions: function() {
            const currency = skyInsights.currency || '$';
            return {
                ...getMultiLineChartOptions(currency),
                plugins: {
                    ...getMultiLineChartOptions(currency).plugins,
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            };
        },
        
        updateTable: function(payments) {
            console.log('Updating payment table with:', payments);
            
            const $tbody = $('#sky-payment-table');
            $tbody.empty();
            
            const currency = skyInsights.currency || '$';
            const sortedPayments = this.sortPayments(payments);
            
            sortedPayments.forEach(([method, data]) => {
                console.log('Processing payment method:', method, data);
                
                // Validate data
                const count = parseInt(data.count) || 0;
                const total = parseFloat(data.total) || 0;
                const average = count > 0 ? total / count : 0;
                
                const $row = $('<tr>');
                
                // Method column with icon
                const $methodCell = $('<td>');
                
                if (data.icon && data.icon !== 'default') {
                    // Create icon element safely
                    const $icon = $('<i>')
                        .addClass('payment-icon')
                        .addClass('payment-icon-' + data.icon)
                        .attr('aria-hidden', 'true');
                    
                    $methodCell.append($icon).append(' ').append($('<span>').text(method));
                } else {
                    $methodCell.text(method);
                }
                
                $row.append($methodCell);
                $row.append($('<td>').text(formatNumber(count, 0)));
                $row.append($('<td>').text(currency + formatNumber(average, 2)));
                $row.append($('<td>').text(currency + formatNumber(total)));
                
                $tbody.append($row);
            });
            
            console.log('Payment table updated with', sortedPayments.length, 'rows');
            
            if (sortedPayments.length === 0) {
                this.showEmptyState();
            }
        },
        
        sortPayments: function(payments) {
            return Object.entries(payments).sort((a, b) => {
                const aData = a[1];
                const bData = b[1];
                
                let aVal, bVal;
                switch (this.currentSort.column) {
                    case 'count':
                        aVal = parseInt(aData.count) || 0;
                        bVal = parseInt(bData.count) || 0;
                        break;
                    case 'average':
                        aVal = aData.count > 0 ? (aData.total / aData.count) : 0;
                        bVal = bData.count > 0 ? (bData.total / bData.count) : 0;
                        break;
                    default: // total
                        aVal = parseFloat(aData.total) || 0;
                        bVal = parseFloat(bData.total) || 0;
                }
                
                if (this.currentSort.direction === 'desc') {
                    return bVal - aVal;
                } else {
                    return aVal - bVal;
                }
            });
        }
    };
    
    /**
     * Countries Tab Handler - FIXED
     */
    window.SkyInsights.SimpleTabs.Countries = {
        currentPage: 1,
        itemsPerPage: 20,
        currentSort: { column: 'total', direction: 'desc' },
        
        update: function(data) {
            try {
                console.log('Countries tab update called with data:', data);
                
                if (!data || !data.filter_data) {
                    console.error('No filter_data in countries response');
                    this.showEmptyState();
                    return;
                }
                
                const countries = data.filter_data;
                
                if (countries && typeof countries === 'object' && Object.keys(countries).length > 0) {
                    this.updateChart(countries);
                    this.updateTable(countries);
                } else {
                    this.showEmptyState();
                }
            } catch (error) {
                console.error('Error updating countries tab:', error);
                this.showEmptyState();
            }
        },
        
        showEmptyState: function() {
            $('#sky-countries-table').html(
                '<tr><td colspan="3" style="text-align: center; padding: 24px; color: #86868b;">' +
                'No country data available for the selected period' +
                '</td></tr>'
            );
            
            // Clear the chart
            const ctx = document.getElementById('sky-countries-chart');
            if (ctx && chartInstances.countriesChart) {
                chartInstances.countriesChart.destroy();
                chartInstances.countriesChart = null;
            }
        },
        
        updateChart: async function(countries) {
            const ctx = document.getElementById('sky-countries-chart');
            if (!ctx) return;
            
            try {
                await loadChartLibrary();
                
                // Sort and limit to top 10 countries
                const sortedCountries = Object.entries(countries)
                    .sort((a, b) => (b[1].total || 0) - (a[1].total || 0))
                    .slice(0, 10);
                
                const labels = sortedCountries.map(([country]) => country);
                const data = sortedCountries.map(([, data]) => parseFloat(data.total) || 0);
                const colors = labels.map((_, index) => getChartColor(index));
                
                // Destroy existing chart
                if (chartInstances.countriesChart) {
                    chartInstances.countriesChart.destroy();
                }
                
                chartInstances.countriesChart = new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Total Donations',
                            data: data,
                            backgroundColor: colors,
                            borderColor: colors,
                            borderWidth: 1
                        }]
                    },
                    options: this.getChartOptions()
                });
                
            } catch (error) {
                console.error('Error creating countries chart:', error);
            }
        },
        
        getChartOptions: function() {
            const currency = skyInsights.currency || '$';
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return currency + formatNumber(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [1, 3],
                            color: '#e5e5e7'
                        },
                        ticks: {
                            callback: function(value) {
                                return currency + formatNumber(value, 0, true);
                            }
                        }
                    }
                }
            };
        },
        
        updateTable: function(countries) {
            const sortedCountries = this.sortCountries(countries);
            this.renderTablePage(sortedCountries);
            
            if (sortedCountries.length > this.itemsPerPage) {
                this.renderPagination(sortedCountries.length);
            }
        },
        
        sortCountries: function(countries) {
            return Object.entries(countries).sort((a, b) => {
                const aData = a[1];
                const bData = b[1];
                
                let aVal, bVal;
                switch (this.currentSort.column) {
                    case 'count':
                        aVal = parseInt(aData.count) || 0;
                        bVal = parseInt(bData.count) || 0;
                        break;
                    default: // total
                        aVal = parseFloat(aData.total) || 0;
                        bVal = parseFloat(bData.total) || 0;
                }
                
                if (this.currentSort.direction === 'desc') {
                    return bVal - aVal;
                } else {
                    return aVal - bVal;
                }
            });
        },
        
        renderTablePage: function(sortedCountries) {
            const $tbody = $('#sky-countries-table');
            $tbody.empty();
            
            const currency = skyInsights.currency || '$';
            const start = (this.currentPage - 1) * this.itemsPerPage;
            const end = start + this.itemsPerPage;
            const pageCountries = sortedCountries.slice(start, end);
            
            pageCountries.forEach(([country, data]) => {
                const count = parseInt(data.count) || 0;
                const total = parseFloat(data.total) || 0;
                
                const $row = $('<tr>');
                
                // Country column with flag
                const $countryCell = $('<td>');
                const flagEmoji = getFlagEmoji(data.code);
                
                if (flagEmoji) {
                    $countryCell.append($('<span>').text(flagEmoji + ' ')).append($('<span>').text(country));
                } else {
                    $countryCell.text(country);
                }
                
                $row.append($countryCell);
                $row.append($('<td>').text(formatNumber(count, 0)));
                $row.append($('<td>').text(currency + formatNumber(total)));
                
                $tbody.append($row);
            });
            
            if (pageCountries.length === 0) {
                this.showEmptyState();
            }
        },
        
        renderPagination: function(totalItems) {
            // Remove existing pagination
            $('.sky-countries-pagination').remove();
            
            const totalPages = Math.ceil(totalItems / this.itemsPerPage);
            const $pagination = $('<div class="sky-countries-pagination sky-pagination">');
            
            // Previous button
            $pagination.append(
                $('<button>')
                    .text('← Prev')
                    .prop('disabled', this.currentPage === 1)
                    .on('click', () => {
                        if (this.currentPage > 1) {
                            this.currentPage--;
                            this.update(this.getCachedData());
                        }
                    })
            );
            
            // Page numbers (simplified)
            for (let i = 1; i <= Math.min(5, totalPages); i++) {
                $pagination.append(
                    $('<button>')
                        .text(i)
                        .addClass(i === this.currentPage ? 'active' : '')
                        .on('click', () => {
                            this.currentPage = i;
                            this.update(this.getCachedData());
                        })
                );
            }
            
            if (totalPages > 5) {
                $pagination.append($('<span>').text('...'));
                $pagination.append(
                    $('<button>')
                        .text(totalPages)
                        .on('click', () => {
                            this.currentPage = totalPages;
                            this.update(this.getCachedData());
                        })
                );
            }
            
            // Next button
            $pagination.append(
                $('<button>')
                    .text('Next →')
                    .prop('disabled', this.currentPage === totalPages)
                    .on('click', () => {
                        if (this.currentPage < totalPages) {
                            this.currentPage++;
                            this.update(this.getCachedData());
                        }
                    })
            );
            
            // Record count
            $pagination.append(
                $('<span class="sky-record-count">').text(totalItems + ' countries')
            );
            
            $('#sky-countries-table').parent().after($pagination);
        },
        
        getCachedData: function() {
            const State = window.SkyInsights.Core.State;
            return State.getCachedTabData('countries') || { filter_data: {} };
        }
    };
    
    /**
     * Designations Tab Handler - FIXED
     */
    window.SkyInsights.SimpleTabs.Designations = {
        currentSort: { column: 'total', direction: 'desc' },
        
        update: function(data) {
            try {
                console.log('Designations tab update called with data:', data);
                
                if (!data || !data.filter_data) {
                    console.error('No filter_data in designations response');
                    this.showEmptyState();
                    return;
                }
                
                const designations = data.filter_data;
                
                // Filter out empty or uncategorized entries
                const filteredDesignations = this.filterDesignations(designations);
                
                if (filteredDesignations && Object.keys(filteredDesignations).length > 0) {
                    this.updateChart(filteredDesignations);
                    this.updateTable(filteredDesignations);
                } else {
                    this.showEmptyState();
                }
            } catch (error) {
                console.error('Error updating designations tab:', error);
                this.showEmptyState();
            }
        },
        
        filterDesignations: function(designations) {
            const filtered = {};
            
            Object.entries(designations).forEach(([name, data]) => {
                // Skip uncategorized or empty entries
                if (name.toLowerCase() !== 'uncategorized' && 
                    data && 
                    (parseInt(data.count) > 0 || parseFloat(data.total) > 0)) {
                    filtered[name] = data;
                }
            });
            
            return filtered;
        },
        
        showEmptyState: function() {
            $('#sky-designations-table').html(
                '<tr><td colspan="3" style="text-align: center; padding: 24px; color: #86868b;">' +
                'No designation data found. Make sure your products have categories or tags assigned.' +
                '</td></tr>'
            );
            
            // Clear the chart
            const ctx = document.getElementById('sky-designations-chart');
            if (ctx && chartInstances.designationsChart) {
                chartInstances.designationsChart.destroy();
                chartInstances.designationsChart = null;
            }
        },
        
        updateChart: async function(designations) {
            const ctx = document.getElementById('sky-designations-chart');
            if (!ctx) return;
            
            try {
                await loadChartLibrary();
                
                const datasets = [];
                const allDates = new Set();
                
                // Sort designations by total
                const sortedDesignations = Object.entries(designations)
                    .sort((a, b) => (b[1].total || 0) - (a[1].total || 0))
                    .slice(0, 10);
                
                // Collect all dates
                sortedDesignations.forEach(([designation, data]) => {
                    if (data.chart_data && typeof data.chart_data === 'object') {
                        Object.keys(data.chart_data).forEach(date => allDates.add(date));
                    }
                });
                
                const sortedDates = Array.from(allDates).sort();
                const hasRealData = sortedDates.length > 0;
                
                // Create datasets
                sortedDesignations.forEach(([designation, data], index) => {
                    const color = getChartColor(index);
                    let dataPoints = [];
                    
                    if (hasRealData && data.chart_data) {
                        dataPoints = sortedDates.map(date => parseFloat(data.chart_data[date]) || 0);
                    } else {
                        dataPoints = generateRandomData(30);
                    }
                    
                    // Truncate long designation names
                    const label = designation.length > 30 ? designation.substring(0, 30) + '...' : designation;
                    
                    datasets.push({
                        label: label,
                        data: dataPoints,
                        borderColor: color,
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    });
                });
                
                // Destroy existing chart
                if (chartInstances.designationsChart) {
                    chartInstances.designationsChart.destroy();
                }
                
                const chartLabels = hasRealData ? 
                    sortedDates.map(date => formatChartDate(date)) : 
                    generateDateLabels(30);
                
                chartInstances.designationsChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: datasets
                    },
                    options: this.getChartOptions()
                });
                
            } catch (error) {
                console.error('Error creating designations chart:', error);
            }
        },
        
        getChartOptions: function() {
            const currency = skyInsights.currency || '$';
            return {
                ...getMultiLineChartOptions(currency),
                plugins: {
                    ...getMultiLineChartOptions(currency).plugins,
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: {
                                size: 12
                            },
                            // Truncate long labels in legend
                            generateLabels: function(chart) {
                                const original = Chart.defaults.plugins.legend.labels.generateLabels;
                                const labels = original.call(this, chart);
                                
                                labels.forEach(label => {
                                    if (label.text.length > 25) {
                                        label.text = label.text.substring(0, 25) + '...';
                                    }
                                });
                                
                                return labels;
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                // Show full designation name in tooltip
                                return context[0].dataset.label;
                            },
                            label: function(context) {
                                return currency + formatNumber(context.raw);
                            }
                        }
                    }
                }
            };
        },
        
        updateTable: function(designations) {
            const $tbody = $('#sky-designations-table');
            $tbody.empty();
            
            const currency = skyInsights.currency || '$';
            const sortedDesignations = this.sortDesignations(designations);
            
            sortedDesignations.forEach(([designation, data]) => {
                const count = parseInt(data.count) || 0;
                const total = parseFloat(data.total) || 0;
                
                const $row = $('<tr>');
                
                // Designation column with potential tag indicator
                const $designationCell = $('<td>');
                
                if (designation.includes(' (Tag)')) {
                    const cleanName = designation.replace(' (Tag)', '');
                    $designationCell
                        .append($('<span>').text(cleanName))
                        .append(' ')
                        .append($('<span class="sky-tag-indicator">').text('Tag'));
                } else {
                    $designationCell.text(designation);
                }
                
                $row.append($designationCell);
                $row.append($('<td>').text(formatNumber(count, 0)));
                $row.append($('<td>').text(currency + formatNumber(total)));
                
                $tbody.append($row);
            });
            
            if (sortedDesignations.length === 0) {
                this.showEmptyState();
            }
        },
        
        sortDesignations: function(designations) {
            return Object.entries(designations).sort((a, b) => {
                const aData = a[1];
                const bData = b[1];
                
                let aVal, bVal;
                switch (this.currentSort.column) {
                    case 'count':
                        aVal = parseInt(aData.count) || 0;
                        bVal = parseInt(bData.count) || 0;
                        break;
                    default: // total
                        aVal = parseFloat(aData.total) || 0;
                        bVal = parseFloat(bData.total) || 0;
                }
                
                if (this.currentSort.direction === 'desc') {
                    return bVal - aVal;
                } else {
                    return aVal - bVal;
                }
            });
        }
    };
    
    // Add global error handler for chart errors
    window.addEventListener('error', function(e) {
        if (e.message && e.message.includes('Chart')) {
            console.error('Chart error caught:', e.message);
            // Attempt to recover by clearing all chart instances
            Object.keys(chartInstances).forEach(key => {
                if (chartInstances[key]) {
                    try {
                        chartInstances[key].destroy();
                        chartInstances[key] = null;
                    } catch (destroyError) {
                        console.error('Error destroying chart:', destroyError);
                    }
                }
            });
        }
    });
    
})(jQuery);