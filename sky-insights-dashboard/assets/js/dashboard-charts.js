// Sky Web Insights Dashboard Chart Functions

// Chart management
let chartInstances = {
    mainChart: null,
    installmentsChart: null,
    onetimeChart: null,
    frequenciesChart: null,
    paymentChart: null,
    countriesChart: null,
    customersChart: null,
    designationsChart: null,
    urlChart: null
};

// Lazy load Chart.js
function loadChartLibrary() {
    if (typeof Chart === 'undefined') {
        return new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js';
            script.onload = resolve;
            document.head.appendChild(script);
        });
    }
    return Promise.resolve();
}

// Initialize chart with lazy loading
async function initializeChart(canvasId, config) {
    await loadChartLibrary();
    
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    
    return new Chart(ctx.getContext('2d'), config);
}

// Main chart update
async function updateMainChart(chartData, currency, i18n) {
    const ctx = document.getElementById('sky-main-chart');
    if (!ctx) {
        console.error('Main chart canvas not found');
        return;
    }
    
    await loadChartLibrary();
    
    // Ensure we have valid chart data
    if (!chartData || Object.keys(chartData).length === 0) {
        console.warn('No chart data available');
        return;
    }
    
    const labels = Object.keys(chartData);
    const values = Object.values(chartData);
    
    if (chartInstances.mainChart) {
        chartInstances.mainChart.destroy();
    }
    
    try {
        chartInstances.mainChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels.map(date => SkyInsightsUtils.formatChartDate(date)),
                datasets: [{
                    label: i18n.raised || 'Raised',
                    data: values,
                    borderColor: '#007aff',
                    backgroundColor: 'rgba(0, 122, 255, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#007aff',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverBackgroundColor: '#007aff',
                    pointHoverBorderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        borderRadius: 4,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        callbacks: {
                            label: function(context) {
                                return currency + SkyInsightsUtils.formatNumber(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12
                            },
                            color: '#86868b',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [1, 3],
                            color: '#e5e5e7',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12
                            },
                            color: '#86868b',
                            callback: function(value) {
                                return currency + SkyInsightsUtils.formatNumber(value, 0, true);
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
    } catch (error) {
        console.error('Error creating main chart:', error);
    }
}

// Mini chart update
async function updateMiniChart(canvasId, chartData, existingChart) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    
    await loadChartLibrary();
    
    const labels = Object.keys(chartData);
    const values = Object.values(chartData);
    
    if (existingChart) {
        existingChart.destroy();
    }
    
    return new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                borderColor: '#007aff',
                backgroundColor: 'rgba(0, 122, 255, 0.05)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: false
                }
            },
            scales: {
                x: {
                    display: false
                },
                y: {
                    display: false
                }
            }
        }
    });
}

// Update customers chart
async function updateCustomersChart(dailyData) {
    const ctx = document.getElementById('sky-customers-chart');
    if (!ctx) return;
    
    await loadChartLibrary();
    
    const dates = Object.keys(dailyData).sort();
    const newCustomerData = dates.map(date => dailyData[date].new || 0);
    const returningCustomerData = dates.map(date => dailyData[date].returning || 0);
    
    if (chartInstances.customersChart) {
        chartInstances.customersChart.destroy();
    }
    
    chartInstances.customersChart = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: dates.map(date => SkyInsightsUtils.formatChartDate(date)),
            datasets: [
                {
                    label: 'New Donors',
                    data: newCustomerData,
                    borderColor: '#34c759',
                    backgroundColor: 'rgba(52, 199, 89, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Returning Donors',
                    data: returningCustomerData,
                    borderColor: '#007aff',
                    backgroundColor: 'rgba(0, 122, 255, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
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
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

// Export to global scope
window.SkyInsightsCharts = {
    chartInstances,
    loadChartLibrary,
    initializeChart,
    updateMainChart,
    updateMiniChart,
    updateCustomersChart
};