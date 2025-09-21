// Sky Web Insights Dashboard Utility Functions

// Helper functions
function formatNumber(num, decimals = 2, abbreviated = false) {
    // Handle invalid inputs
    if (num === undefined || num === null || isNaN(num)) {
        return '0' + (decimals > 0 ? '.' + '0'.repeat(decimals) : '');
    }
    
    // Convert to number if it's a string
    num = parseFloat(num);
    
    // Check again after conversion
    if (isNaN(num)) {
        return '0' + (decimals > 0 ? '.' + '0'.repeat(decimals) : '');
    }
    
    if (abbreviated && num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function formatChartDate(dateStr, viewType = 'daily') {
    const date = new Date(dateStr);
    const options = viewType === 'weekly' ? 
        { month: 'short', day: 'numeric' } : 
        { month: 'short', day: 'numeric' };
    return date.toLocaleDateString('en-GB', options);
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

function calculateMedian(values) {
    if (!values || values.length === 0) return 0;
    // Filter out any non-numeric values
    const numericValues = values.filter(v => !isNaN(v) && v !== null && v !== undefined);
    if (numericValues.length === 0) return 0;
    
    const sorted = numericValues.slice().sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

function getChartColor(index) {
    const colors = [
        '#007aff', '#5856d6', '#ff3b30', '#ff9500', '#ffcc00',
        '#34c759', '#00c7be', '#30b0c7', '#32ade6', '#5ac8fa'
    ];
    return colors[index % colors.length];
}

function generateDateLabels(days) {
    const labels = [];
    for (let i = days - 1; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        labels.push(date.toLocaleDateString('en-GB', { month: 'short', day: 'numeric' }));
    }
    return labels;
}

function generateRandomData(points) {
    const data = [];
    for (let i = 0; i < points; i++) {
        data.push(Math.floor(Math.random() * 1000) + 100);
    }
    return data;
}

function getFlagEmoji(countryCode) {
    if (!countryCode) return '';
    const codePoints = countryCode
        .toUpperCase()
        .split('')
        .map(char => 127397 + char.charCodeAt());
    return String.fromCodePoint(...codePoints);
}

function getMultiLineChartOptions(currency) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    maxTicksLimit: 8,
                    color: '#86868b'
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    borderDash: [1, 3],
                    color: '#e5e5e7'
                },
                ticks: {
                    color: '#86868b',
                    callback: function(value) {
                        return currency + formatNumber(value, 0, true);
                    }
                }
            }
        }
    };
}

// Debounce function for performance
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Export to global scope
window.SkyInsightsUtils = {
    formatNumber,
    formatChartDate,
    formatDate,
    calculateMedian,
    getChartColor,
    generateDateLabels,
    generateRandomData,
    getFlagEmoji,
    getMultiLineChartOptions,
    debounce
};