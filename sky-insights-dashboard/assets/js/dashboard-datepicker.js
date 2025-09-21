// Sky Insights Custom Date Picker - Split View - FIXED VERSION
(function($) {
    'use strict';
    
    window.SkyInsights = window.SkyInsights || {};
    
    window.SkyInsights.DatePicker = {
        currentLeftMonth: new Date(),
        currentRightMonth: null,
        selectedStartDate: null,
        selectedEndDate: null,
        isSelectingEndDate: false,
        isInitialized: false,
        eventsBound: false,
        
        init: function() {
            // Prevent multiple initializations
            if (this.isInitialized) {
                this.destroy();
            }
            
            // Set right month to next month
            this.currentRightMonth = new Date(this.currentLeftMonth);
            this.currentRightMonth.setMonth(this.currentRightMonth.getMonth() + 1);
            
            this.createDatePicker();
            
            // Only bind events once
            if (!this.eventsBound) {
                this.bindEvents();
                this.eventsBound = true;
            }
            
            this.isInitialized = true;
        },
        
        destroy: function() {
            // Clean up existing date picker
            $('.sky-custom-datepicker').remove();
            
            // Remove document click handler
            $(document).off('click.skyDatePicker');
            
            this.isInitialized = false;
        },
        
        createDatePicker: function() {
            const html = `
                <div class="sky-custom-datepicker" style="display: none;">
                    <div class="sky-datepicker-wrapper">
                        <div class="sky-calendars-container">
                            <!-- Left Calendar -->
                            <div class="sky-calendar-month">
                                <div class="sky-calendar-nav">
                                    <button class="sky-nav-prev" type="button">&lt;</button>
                                    <div class="sky-month-year">
                                        <span class="sky-month-name-left"></span>
                                        <span class="sky-year-left"></span>
                                    </div>
                                </div>
                                <div class="sky-calendar-grid" id="sky-calendar-left"></div>
                            </div>
                            
                            <!-- Right Calendar -->
                            <div class="sky-calendar-month">
                                <div class="sky-calendar-nav">
                                    <div class="sky-month-year">
                                        <span class="sky-month-name-right"></span>
                                        <span class="sky-year-right"></span>
                                    </div>
                                    <button class="sky-nav-next" type="button">&gt;</button>
                                </div>
                                <div class="sky-calendar-grid" id="sky-calendar-right"></div>
                            </div>
                        </div>
                        
                        <!-- Quick Select Sidebar -->
                        <div class="sky-shortcuts-sidebar">
                            <div class="sky-shortcuts-list">
                                <button class="sky-shortcut" data-range="today" type="button">Today</button>
                                <button class="sky-shortcut" data-range="yesterday" type="button">Yesterday</button>
                                <button class="sky-shortcut" data-range="last7days" type="button">Last 7 days</button>
                                <button class="sky-shortcut" data-range="last14days" type="button">Last 14 days</button>
                                <button class="sky-shortcut" data-range="last30days" type="button">Last 30 days</button>
                                <button class="sky-shortcut" data-range="thisweek" type="button">This week</button>
                                <button class="sky-shortcut" data-range="thismonth" type="button">This month</button>
                                <button class="sky-shortcut" data-range="thisyear" type="button">This year</button>
                                <button class="sky-shortcut" data-range="lastweek" type="button">Last week</button>
                                <button class="sky-shortcut" data-range="lastmonth" type="button">Last month</button>
                                <button class="sky-shortcut" data-range="lastyear" type="button">Last year</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Footer with Apply/Cancel -->
                    <div class="sky-datepicker-footer">
                        <div class="sky-selected-dates">
                            <span class="sky-selected-start"></span>
                            <span class="sky-date-separator">–</span>
                            <span class="sky-selected-end"></span>
                        </div>
                        <div class="sky-datepicker-buttons">
                            <button class="sky-btn-cancel" type="button">Cancel</button>
                            <button class="sky-btn-apply" type="button">Apply</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove old datepicker if exists
            $('.sky-custom-datepicker').remove();
            
            // Add new datepicker to body
            $('body').append(html);
            
            // Update both calendars
            this.updateCalendars();
        },
        
        updateCalendars: function() {
            this.updateCalendar('left', this.currentLeftMonth);
            this.updateCalendar('right', this.currentRightMonth);
            this.updateMonthYearDisplay();
        },
        
        updateCalendar: function(side, month) {
            const year = month.getFullYear();
            const monthNum = month.getMonth();
            const firstDay = new Date(year, monthNum, 1).getDay();
            const daysInMonth = new Date(year, monthNum + 1, 0).getDate();
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Normalize to midnight
            
            let html = '<div class="sky-weekdays">';
            const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            weekdays.forEach(day => {
                html += `<div class="sky-weekday">${day}</div>`;
            });
            html += '</div><div class="sky-days">';
            
            // FIXED: Proper Monday start calculation
            // Convert Sunday (0) to 7, then subtract 1 for Monday start
            let adjustedFirstDay = firstDay === 0 ? 6 : firstDay - 1;
            
            // Empty cells for days before month starts
            for (let i = 0; i < adjustedFirstDay; i++) {
                html += '<div class="sky-day empty"></div>';
            }
            
            // Days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const currentDate = new Date(year, monthNum, day);
                currentDate.setHours(0, 0, 0, 0);
                const dateStr = this.formatDateISO(currentDate);
                const isToday = currentDate.getTime() === today.getTime();
                const isSelected = this.isInSelectedRange(dateStr);
                const isStart = this.isStartDate(dateStr);
                const isEnd = this.isEndDate(dateStr);
                const isPast = currentDate < today;
                const isFuture = currentDate > today;
                
                let classes = 'sky-day';
                if (isToday) classes += ' today';
                if (isSelected) classes += ' in-range';
                if (isStart) classes += ' range-start';
                if (isEnd) classes += ' range-end';
                if (isPast && !isToday) classes += ' past';
                if (isFuture) classes += ' future';
                
                html += `<div class="${classes}" data-date="${dateStr}" data-day="${day}">${day}</div>`;
            }
            
            html += '</div>';
            
            $(`#sky-calendar-${side}`).html(html);
        },
        
        updateMonthYearDisplay: function() {
            $('.sky-month-name-left').text(this.getMonthName(this.currentLeftMonth.getMonth()));
            $('.sky-year-left').text(this.currentLeftMonth.getFullYear());
            $('.sky-month-name-right').text(this.getMonthName(this.currentRightMonth.getMonth()));
            $('.sky-year-right').text(this.currentRightMonth.getFullYear());
        },
        
        bindEvents: function() {
            const self = this;
            
            // Use namespaced events to prevent duplicates
            $(document).off('.skyDatePicker');
            
            // Previous month navigation
            $(document).on('click.skyDatePicker', '.sky-nav-prev', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.currentLeftMonth.setMonth(self.currentLeftMonth.getMonth() - 1);
                self.currentRightMonth.setMonth(self.currentRightMonth.getMonth() - 1);
                self.updateCalendars();
            });
            
            // Next month navigation
            $(document).on('click.skyDatePicker', '.sky-nav-next', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.currentLeftMonth.setMonth(self.currentLeftMonth.getMonth() + 1);
                self.currentRightMonth.setMonth(self.currentRightMonth.getMonth() + 1);
                self.updateCalendars();
            });
            
            // Date selection with validation
            $(document).on('click.skyDatePicker', '.sky-day:not(.empty):not(.future)', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const date = $(this).data('date');
                const selectedDate = new Date(date + 'T00:00:00');
                const today = new Date();
                today.setHours(23, 59, 59, 999);
                
                // Prevent selecting future dates
                if (selectedDate > today) {
                    return;
                }
                
                if (!self.selectedStartDate || self.selectedEndDate) {
                    // Start new selection
                    self.selectedStartDate = date;
                    self.selectedEndDate = null;
                    $('.sky-selected-start').text(self.formatDisplayDate(date));
                    $('.sky-selected-end').text('');
                    
                    // Update state immediately
                    self.updateState();
                } else {
                    // Select end date
                    if (new Date(date) < new Date(self.selectedStartDate)) {
                        // Swap if end is before start
                        self.selectedEndDate = self.selectedStartDate;
                        self.selectedStartDate = date;
                    } else {
                        self.selectedEndDate = date;
                    }
                    $('.sky-selected-start').text(self.formatDisplayDate(self.selectedStartDate));
                    $('.sky-selected-end').text(self.formatDisplayDate(self.selectedEndDate));
                    
                    // Update state immediately
                    self.updateState();
                }
                
                self.updateCalendars();
            });
            
            // Quick select shortcuts
            $(document).on('click.skyDatePicker', '.sky-shortcut', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const range = $(this).data('range');
                const dates = self.getPresetDates(range);
                
                self.selectedStartDate = dates.start;
                self.selectedEndDate = dates.end;
                
                $('.sky-selected-start').text(self.formatDisplayDate(dates.start));
                $('.sky-selected-end').text(self.formatDisplayDate(dates.end));
                
                // Update calendar view to show selected range
                const startDate = new Date(dates.start + 'T00:00:00');
                self.currentLeftMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);
                self.currentRightMonth = new Date(self.currentLeftMonth);
                self.currentRightMonth.setMonth(self.currentRightMonth.getMonth() + 1);
                
                self.updateCalendars();
                
                // Highlight active shortcut
                $('.sky-shortcut').removeClass('active');
                $(this).addClass('active');
                
                // Update state
                self.updateState();
            });
            
            // Apply button
            $(document).on('click.skyDatePicker', '.sky-btn-apply', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (self.selectedStartDate && self.selectedEndDate) {
                    self.applyDateRange();
                } else if (self.selectedStartDate && !self.selectedEndDate) {
                    // If only one date selected, use it as both start and end
                    self.selectedEndDate = self.selectedStartDate;
                    self.applyDateRange();
                } else {
                    alert('Please select a date range');
                }
            });
            
            // Cancel button
            $(document).on('click.skyDatePicker', '.sky-btn-cancel', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.hide();
            });
            
            // Click outside to close
            $(document).on('click.skyDatePicker', function(e) {
                if (!$(e.target).closest('.sky-custom-datepicker, .sky-date-filter').length) {
                    self.hide();
                }
            });
            
            // Prevent clicks inside datepicker from closing it
            $(document).on('click.skyDatePicker', '.sky-custom-datepicker', function(e) {
                e.stopPropagation();
            });
        },
        
        updateState: function() {
            // Update global state if available
            if (window.SkyInsights && window.SkyInsights.Core && window.SkyInsights.Core.State) {
                const State = window.SkyInsights.Core.State;
                
                if (this.selectedStartDate) {
                    State.set('customDateFrom', this.selectedStartDate);
                }
                if (this.selectedEndDate) {
                    State.set('customDateTo', this.selectedEndDate);
                }
            }
        },
        
        show: function() {
            const $picker = $('.sky-custom-datepicker');
            const $trigger = $('#sky-date-range-picker');
            
            if (!$trigger.length || !$picker.length) {
                return;
            }
            
            // Calculate position
            const triggerOffset = $trigger.offset();
            const triggerHeight = $trigger.outerHeight();
            const pickerHeight = $picker.outerHeight();
            const pickerWidth = $picker.outerWidth();
            const windowHeight = $(window).height();
            const windowWidth = $(window).width();
            const scrollTop = $(window).scrollTop();
            
            let top = triggerOffset.top + triggerHeight + 5;
            let left = triggerOffset.left;
            
            // Check if picker would go off bottom of screen
            if (top + pickerHeight > scrollTop + windowHeight) {
                // Show above instead
                top = triggerOffset.top - pickerHeight - 5;
            }
            
            // Check if picker would go off right side of screen
            if (left + pickerWidth > windowWidth) {
                left = windowWidth - pickerWidth - 20;
            }
            
            // Ensure minimum left position
            if (left < 20) {
                left = 20;
            }
            
            $picker.css({
                top: top,
                left: left,
                zIndex: 10000
            }).fadeIn(200);
            
            $('body').addClass('sky-datepicker-open');
        },
        
        hide: function() {
            $('.sky-custom-datepicker').fadeOut(200);
            $('body').removeClass('sky-datepicker-open');
        },
        
        applyDateRange: function() {
            const State = window.SkyInsights.Core.State;
            const DataLoader = window.SkyInsights.Core.DataLoader;
            
            // Validate dates one more time
            if (!this.isValidDateRange(this.selectedStartDate, this.selectedEndDate)) {
                alert('Invalid date range selected');
                return;
            }
            
            State.set('dateRange', 'custom');
            State.set('customDateFrom', this.selectedStartDate);
            State.set('customDateTo', this.selectedEndDate);
            
            // Update display
            const formattedRange = this.formatDisplayDate(this.selectedStartDate) + ' – ' + 
                                 this.formatDisplayDate(this.selectedEndDate);
            $('#sky-date-range-picker').val(formattedRange);
            
            this.hide();
            
            State.clearCache();
            DataLoader.load();
        },
        
        // Helper functions
        getMonthName: function(month) {
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                          'July', 'August', 'September', 'October', 'November', 'December'];
            return months[month];
        },
        
        formatDateISO: function(date) {
            // Ensure we're working with a valid date object
            if (!(date instanceof Date) || isNaN(date)) {
                date = new Date();
            }
            
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            
            return `${year}-${month}-${day}`;
        },
        
        formatDate: function(date) {
            return this.formatDateISO(date);
        },
        
        formatDisplayDate: function(dateStr) {
            try {
                const date = new Date(dateStr + 'T00:00:00');
                return date.toLocaleDateString('en-GB', { 
                    day: '2-digit', 
                    month: '2-digit', 
                    year: 'numeric' 
                });
            } catch (e) {
                return dateStr;
            }
        },
        
        isToday: function(year, month, day) {
            const today = new Date();
            return year === today.getFullYear() && 
                   month === today.getMonth() && 
                   day === today.getDate();
        },
        
        isInSelectedRange: function(dateStr) {
            if (!this.selectedStartDate || !this.selectedEndDate) return false;
            
            const date = new Date(dateStr + 'T00:00:00');
            const start = new Date(this.selectedStartDate + 'T00:00:00');
            const end = new Date(this.selectedEndDate + 'T00:00:00');
            
            return date >= start && date <= end;
        },
        
        isStartDate: function(dateStr) {
            return dateStr === this.selectedStartDate;
        },
        
        isEndDate: function(dateStr) {
            return dateStr === this.selectedEndDate;
        },
        
        isValidDateRange: function(startStr, endStr) {
            try {
                const start = new Date(startStr + 'T00:00:00');
                const end = new Date(endStr + 'T00:00:00');
                const today = new Date();
                today.setHours(23, 59, 59, 999);
                
                return start <= end && end <= today;
            } catch (e) {
                return false;
            }
        },
        
        getPresetDates: function(range) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const dates = {};
            
            switch(range) {
                case 'today':
                    dates.start = dates.end = this.formatDateISO(today);
                    break;
                    
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    dates.start = dates.end = this.formatDateISO(yesterday);
                    break;
                    
                case 'last7days':
                    const last7 = new Date(today);
                    last7.setDate(last7.getDate() - 6);
                    dates.start = this.formatDateISO(last7);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'last14days':
                    const last14 = new Date(today);
                    last14.setDate(last14.getDate() - 13);
                    dates.start = this.formatDateISO(last14);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'last30days':
                    const last30 = new Date(today);
                    last30.setDate(last30.getDate() - 29);
                    dates.start = this.formatDateISO(last30);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'thisweek':
                    // FIXED: Proper week calculation
                    const weekStart = new Date(today);
                    const dayOfWeek = today.getDay();
                    const diff = dayOfWeek === 0 ? 6 : dayOfWeek - 1; // Monday = 0
                    weekStart.setDate(today.getDate() - diff);
                    dates.start = this.formatDateISO(weekStart);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'thismonth':
                    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                    dates.start = this.formatDateISO(monthStart);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'thisyear':
                    const yearStart = new Date(today.getFullYear(), 0, 1);
                    dates.start = this.formatDateISO(yearStart);
                    dates.end = this.formatDateISO(today);
                    break;
                    
                case 'lastweek':
                    // FIXED: Proper last week calculation
                    const lastWeekEnd = new Date(today);
                    const currentDay = today.getDay();
                    const daysToLastSunday = currentDay === 0 ? 7 : currentDay;
                    lastWeekEnd.setDate(today.getDate() - daysToLastSunday);
                    
                    const lastWeekStart = new Date(lastWeekEnd);
                    lastWeekStart.setDate(lastWeekEnd.getDate() - 6);
                    
                    dates.start = this.formatDateISO(lastWeekStart);
                    dates.end = this.formatDateISO(lastWeekEnd);
                    break;
                    
                case 'lastmonth':
                    const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
                    const lastMonthStart = new Date(lastMonthEnd.getFullYear(), lastMonthEnd.getMonth(), 1);
                    dates.start = this.formatDateISO(lastMonthStart);
                    dates.end = this.formatDateISO(lastMonthEnd);
                    break;
                    
                case 'lastyear':
                    const lastYearStart = new Date(today.getFullYear() - 1, 0, 1);
                    const lastYearEnd = new Date(today.getFullYear() - 1, 11, 31);
                    dates.start = this.formatDateISO(lastYearStart);
                    dates.end = this.formatDateISO(lastYearEnd);
                    break;
                    
                default:
                    // Default to last 7 days
                    const defaultStart = new Date(today);
                    defaultStart.setDate(defaultStart.getDate() - 6);
                    dates.start = this.formatDateISO(defaultStart);
                    dates.end = this.formatDateISO(today);
            }
            
            return dates;
        }
    };
    
})(jQuery);