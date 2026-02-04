jQuery(document).ready(function($) {
    // Check if booking container exists
    if (!$('.bt-booking-container').length) return;
    
    // Ensure btFrontend is available
    if (typeof btFrontend === 'undefined') {
        console.error('btFrontend not defined. Please ensure the script is properly enqueued.');
        return;
    }

    let currentDate = new Date();
    let selectedDate = null;
    let selectedSlots = [];
    let typeData = null;
    let slots = [];
    let holidays = [];
    let bookedSlots = {};
    let bookedDates = [];
    let ticketsByDate = {};
    let eventBlockedDates = [];
    let individualBlockedDates = [];
    let addons = [];
    let addonsAvailability = {};
    let selectedAddons = {};
    let serverTime = btFrontend.serverTime || '00:00';
    let serverDate = btFrontend.serverDate || '';
    let sharedTourStartTime = '';
    let sharedTourEndTime = '';
    let pollInterval = null;
    let ticketCount = 1;
    let mode = $('.bt-booking-container').data('mode');

    // Initialize
    const initialTypeId = $('#bt-type-id').val();
    if (initialTypeId) loadTypeData(initialTypeId);
    toggleSections();

    // Tour type selection
    $('.bt-tour-btn').on('click', function() {
        $('.bt-tour-btn').removeClass('active');
        $(this).addClass('active');
        
        const typeId = $(this).data('type-id');
        const category = $(this).data('category');
        $('#bt-type-id').val(typeId);
        $('#bt-type-category').val(category);
        
        selectedDate = null;
        selectedSlots = [];
        ticketCount = 1;
        selectedAddons = {};
        toggleSections();
        updateUI();
        loadTypeData(typeId);
    });

    // Add-ons controls
    $('#bt-addons-list').on('click', '.bt-addon-plus', function() {
        const $item = $(this).closest('.bt-addon-item');
        const addonId = $item.data('addon-id');
        const remaining = getAddonRemaining(addonId);
        const current = parseInt(selectedAddons[addonId]) || 0;
        if (current < remaining) {
            selectedAddons[addonId] = current + 1;
            renderAddonsPanel();
            updateUI();
        } else {
            showToast('No more stock available for this add-on', 'error');
        }
    });
    $('#bt-addons-list').on('click', '.bt-addon-minus', function() {
        const $item = $(this).closest('.bt-addon-item');
        const addonId = $item.data('addon-id');
        const current = parseInt(selectedAddons[addonId]) || 0;
        if (current > 0) {
            selectedAddons[addonId] = current - 1;
            if (selectedAddons[addonId] <= 0) delete selectedAddons[addonId];
            renderAddonsPanel();
            updateUI();
        }
    });

    // Calendar navigation
    $('#bt-prev-month').on('click', function() {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    $('#bt-next-month').on('click', function() {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

    // Form submission
    $('#bt-booking-form').on('submit', function(e) {
        e.preventDefault();
        
        const category = $('#bt-type-category').val();
        
        if (!selectedDate) {
            showToast('Please select a date', 'error');
            return;
        }
        
        if (isHallCategory(category) && selectedSlots.length === 0) {
            showToast('Please select at least one slot', 'error');
            return;
        }

        const name = $('#bt-name').val().trim();
        const phone = $('#bt-phone').val().trim();
        const email = $('#bt-email').val().trim();
        const transactionId = $('#bt-transaction-id').val().trim();
        const paymentImage = $('#bt-payment-image')[0].files[0];

        if (!name || !phone || !email) {
            showToast('Please fill in all required fields', 'error');
            return;
        }

        if (!transactionId && !paymentImage) {
            showToast('Please provide Transaction ID or Payment Screenshot', 'error');
            return;
        }

        if (paymentImage && paymentImage.size > btFrontend.maxUploadSize) {
            showToast('Payment image must be less than 1MB', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'bt_submit_booking');
        formData.append('type_id', $('#bt-type-id').val());
        formData.append('booking_date', formatDate(selectedDate));
        formData.append('slot_ids', selectedSlots.join(','));
        formData.append('ticket_count', ticketCount);
        formData.append('total_price', $('#bt-total-price').val());
        formData.append('addons', $('#bt-selected-addons').val());
        formData.append('customer_name', name);
        formData.append('customer_email', email);
        formData.append('customer_phone', phone);
        formData.append('transaction_id', transactionId);
        formData.append('notes', $('#bt-notes').val());
        
        if (paymentImage) formData.append('payment_image', paymentImage);

        $('#bt-submit-btn').prop('disabled', true).html('<span class="bt-spinner"></span> Submitting...');

        $.ajax({
            url: btFrontend.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showToast(response.data, 'success');
                    resetForm();
                } else {
                    showToast(response.data || 'Error submitting booking', 'error');
                }
                resetSubmitBtn();
            },
            error: function() {
                showToast('Network error. Please try again.', 'error');
                resetSubmitBtn();
            }
        });
    });

    function resetSubmitBtn() {
        $('#bt-submit-btn').prop('disabled', false).html('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg> Submit Booking Request');
    }

    function loadTypeData(typeId) {
        if (!typeId) {
            console.error('No type ID provided');
            return;
        }
        
        $.ajax({
            url: btFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bt_get_booking_data',
                type_id: typeId
            },
            success: function(response) {
                if (response.success && response.data) {
                    typeData = response.data.type;
                    slots = response.data.slots || [];
                    holidays = response.data.holidays || [];
                    bookedSlots = response.data.bookedSlots || {};
                    bookedDates = response.data.bookedDates || [];
                    ticketsByDate = response.data.ticketsByDate || {};
                    eventBlockedDates = response.data.eventBlockedDates || [];
                    individualBlockedDates = response.data.individualBlockedDates || [];
                    addons = response.data.addons || [];
                    serverTime = response.data.serverTime || '00:00';
                    serverDate = response.data.serverDate || '';
                    sharedTourStartTime = response.data.sharedTourStartTime || '';
                    sharedTourEndTime = response.data.sharedTourEndTime || '';
                    
                    toggleSections();
                    renderCalendar();
                    if (typeData && isHallCategory(typeData.type_category)) renderSlots();
                    else renderTourInfo();
                    
                    startPolling();
                } else {
                    console.error('Failed to load booking data:', response);
                    // Still render calendar with defaults
                    const fallbackCategory = $('#bt-type-category').val() || 'individual_tour';
                    typeData = { type_category: fallbackCategory, weekend_days: '' };
                    renderCalendar();
                    if (isHallCategory(typeData.type_category)) renderSlots();
                    else renderTourInfo();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error loading booking data:', error);
                // Still render calendar with defaults on error
                const fallbackCategory = $('#bt-type-category').val() || 'individual_tour';
                typeData = { type_category: fallbackCategory, weekend_days: '' };
                renderCalendar();
                if (isHallCategory(typeData.type_category)) renderSlots();
                else renderTourInfo();
            }
        });
    }

    function startPolling() {
        stopPolling();
        pollInterval = setInterval(checkAvailability, 5000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    function checkAvailability() {
        const typeId = $('#bt-type-id').val();
        if (!typeId) return;
        
        $.post(btFrontend.ajaxUrl, {
            action: 'bt_check_availability',
            type_id: typeId,
            date: selectedDate ? formatDate(selectedDate) : ''
        }, function(response) {
            if (response.success) {
                // Update server time
                serverTime = response.data.serverTime || serverTime;
                serverDate = response.data.serverDate || serverDate;
                
                // Track if data changed
                let hasChanges = false;
                
                if (isHallCategory(typeData.type_category)) {
                    if (selectedDate) {
                        const dateStr = formatDate(selectedDate);
                        const newBooked = response.data.bookedSlots || [];
                        const currentBooked = bookedSlots[dateStr] || [];
                        if (JSON.stringify(newBooked.sort()) !== JSON.stringify(currentBooked.sort())) {
                            bookedSlots[dateStr] = newBooked;
                            selectedSlots = selectedSlots.filter(id => !newBooked.includes(id));
                            hasChanges = true;
                            if (selectedSlots.length < newBooked.length) {
                                showToast('Some slots were just booked', 'error');
                            }
                        }
                    }
                    if (typeData.type_category === 'hall') {
                        const newAddonsAvailability = response.data.addonsAvailability || {};
                        if (JSON.stringify(newAddonsAvailability) !== JSON.stringify(addonsAvailability)) {
                            addonsAvailability = newAddonsAvailability;
                            hasChanges = true;
                            clampSelectedAddons();
                        }
                    }
                } else if (typeData.type_category === 'individual_tour') {
                    // Update ticketsByDate from response
                    const newTicketsByDate = response.data.ticketsByDate || {};
                    if (JSON.stringify(newTicketsByDate) !== JSON.stringify(ticketsByDate)) {
                        ticketsByDate = newTicketsByDate;
                        hasChanges = true;
                        
                        if (selectedDate) {
                            const dateStr = formatDate(selectedDate);
                            const booked = ticketsByDate[dateStr] || 0;
                            const totalCapacity = typeData.max_daily_capacity || 50;
                            const maxAvailable = Math.max(0, totalCapacity - booked);
                            
                            if (ticketCount > maxAvailable) {
                                ticketCount = Math.max(1, maxAvailable);
                                showToast('Capacity updated. Tickets adjusted.', 'error');
                            }
                        }
                    }
                    
                    // Update individual blocked dates
                    const newIndividualBlocked = response.data.individualBlockedDates || [];
                    if (JSON.stringify(newIndividualBlocked) !== JSON.stringify(individualBlockedDates)) {
                        individualBlockedDates = newIndividualBlocked;
                        hasChanges = true;
                    }
                } else if (typeData.type_category === 'event_tour') {
                    // Update booked dates for event tour
                    const newBookedDates = response.data.bookedDates || [];
                    if (JSON.stringify(newBookedDates) !== JSON.stringify(bookedDates)) {
                        bookedDates = newBookedDates;
                        hasChanges = true;
                        
                        // If currently selected date got booked, clear selection
                        if (selectedDate) {
                            const dateStr = formatDate(selectedDate);
                            if (newBookedDates.includes(dateStr)) {
                                selectedDate = null;
                                showToast('Selected date is now booked. Please choose another.', 'error');
                            }
                        }
                    }
                    
                    // Update event blocked dates
                    const newEventBlocked = response.data.eventBlockedDates || [];
                    if (JSON.stringify(newEventBlocked) !== JSON.stringify(eventBlockedDates)) {
                        eventBlockedDates = newEventBlocked;
                        hasChanges = true;
                    }
                }
                
                // Re-render if data changed
                if (hasChanges) {
                    renderCalendar();
                    if (isHallCategory(typeData.type_category)) {
                        renderSlots();
                    } else {
                        renderTourInfo();
                    }
                    updateUI();
                }
            }
        });
    }

    function renderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $('#bt-month-year').text(monthNames[month] + ' ' + year);

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        const weekendDays = typeData && typeData.weekend_days && typeData.weekend_days.trim() !== '' 
            ? typeData.weekend_days.split(',').map(Number) 
            : [];
        const category = typeData ? typeData.type_category : 'hall';

        let html = '';
        for (let i = 0; i < firstDay; i++) {
            html += '<div class="bt-day bt-day-empty"></div>';
        }

        const localNow = new Date();
        const localTimeMinutes = localNow.getHours() * 60 + localNow.getMinutes();

        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dayOfWeek = date.getDay();
            const dateStr = formatDate(date);
            const isPast = date < today;
            const isWeekend = weekendDays.includes(dayOfWeek);
            const isHoliday = holidays.includes(dateStr);
            const isToday = date.toDateString() === today.toDateString();
            const isSelected = selectedDate && date.toDateString() === selectedDate.toDateString();
            
            // Check cross-calendar blocking
            let isBlockedByCross = false;
            if (category === 'event_tour') {
                isBlockedByCross = individualBlockedDates.includes(dateStr) || bookedDates.includes(dateStr);
            } else if (category === 'individual_tour') {
                isBlockedByCross = eventBlockedDates.includes(dateStr);
                // Also check if capacity is full
                const booked = ticketsByDate[dateStr] || 0;
                if (typeData && booked >= typeData.max_daily_capacity) {
                    isBlockedByCross = true;
                }
            }
            
            // Individual tour booking window (admin configurable)
            let isBeyondWindow = false;
            if (category === 'individual_tour') {
                const mode = (typeData && typeData.booking_window_mode) ? typeData.booking_window_mode : 'limit';
                const days = (typeData && typeof typeData.booking_window_days !== 'undefined') ? parseInt(typeData.booking_window_days) : 1;
                if (mode === 'limit') {
                    const windowEnd = new Date(today);
                    windowEnd.setDate(windowEnd.getDate() + Math.max(0, days));
                    if (date > windowEnd) isBeyondWindow = true;
                }
            }

            // Time-based availability check for Knowledge Hub tours (today only)
            let isTimePassed = false;
            if (isToday && typeData && (category === 'individual_tour' || category === 'event_tour')) {
                const tourStartTime = getSharedTourStartTime() || typeData.tour_start_time || '00:00';
                const startParts = tourStartTime.split(':');
                const tourStartMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1] || 0);

                // If current time has reached tour start time, mark as unavailable
                if (localTimeMinutes >= tourStartMinutes) {
                    isTimePassed = true;
                }
            }

            let isNoHallAvailabilityToday = false;
            if (isHallCategory(category) && isToday) {
                isNoHallAvailabilityToday = !hasBookableHallSlots(dateStr, localTimeMinutes);
            }

            const isDisabled = isPast || isWeekend || isHoliday || isBlockedByCross || isBeyondWindow || isTimePassed || isNoHallAvailabilityToday;

            let classes = 'bt-day';
            if (isDisabled) classes += ' bt-day-disabled';
            if (isTimePassed && !isPast) classes += ' bt-day-time-passed';
            if (isHoliday && !isPast) classes += ' bt-day-holiday';
            if (isWeekend && !isPast && !isHoliday) classes += ' bt-day-weekend';
            if (isToday && !isDisabled) classes += ' bt-day-today';
            if (isSelected) classes += ' bt-day-selected';

            html += '<div class="' + classes + '" data-date="' + dateStr + '">' + day + '</div>';
        }

        $('#bt-calendar-days').html(html);

        $('#bt-calendar-days').off('click', '.bt-day').on('click', '.bt-day', function() {
            if ($(this).hasClass('bt-day-disabled')) return;
            
            $('.bt-day').removeClass('bt-day-selected');
            $(this).addClass('bt-day-selected');
            
            selectedDate = new Date($(this).data('date') + 'T00:00:00');
            selectedSlots = [];
            ticketCount = 1;
            selectedAddons = {};
            
            if (isHallCategory(typeData.type_category)) renderSlots();
            else renderTourInfo();
            
            updateUI();
            checkAvailability();
        });
    }

    function renderSlots() {
        const $container = $('#bt-slots-container');
        
        if (!selectedDate) {
            $container.html('<div class="bt-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><p>Select a date to view available slots</p></div>');
            return;
        }

        if (!slots.length) {
            $container.html('<div class="bt-placeholder"><p>No slots available</p></div>');
            return;
        }

        const dateStr = formatDate(selectedDate);
        const dateBookedSlots = bookedSlots[dateStr] || [];
        const localNow = new Date();
        const isTodayLocal = dateStr === formatDate(localNow);
        const localTimeMinutes = localNow.getHours() * 60 + localNow.getMinutes();

        const bookedIntervals = dateBookedSlots.map(function(slotId) {
            const slot = slots.find(s => parseInt(s.id) === parseInt(slotId));
            if (!slot) return null;
            return {
                start: timeToMinutes(slot.start_time),
                end: timeToMinutes(slot.end_time)
            };
        }).filter(Boolean);

        let html = '<div class="bt-slots-list">';
        let selectionChanged = false;
        slots.forEach(function(slot) {
            const isBooked = dateBookedSlots.includes(parseInt(slot.id));
            const isSelected = selectedSlots.includes(parseInt(slot.id));
            
            const slotStart = timeToMinutes(slot.start_time);
            const slotEnd = timeToMinutes(slot.end_time);

            // Overlap check against any booked slot on the same date
            let isOverlapBooked = false;
            if (!isBooked && bookedIntervals.length) {
                for (let i = 0; i < bookedIntervals.length; i++) {
                    const interval = bookedIntervals[i];
                    if (intervalsOverlap(slotStart, slotEnd, interval.start, interval.end)) {
                        isOverlapBooked = true;
                        break;
                    }
                }
            }

            // Live time restriction: disable if current time >= slot start time
            let isPastSlot = false;
            if (isTodayLocal) {
                isPastSlot = localTimeMinutes >= slotStart;
            }
            
            const isDisabled = isBooked || isOverlapBooked || isPastSlot;
            
            let classes = 'bt-slot-card';
            if (isDisabled) classes += ' bt-slot-disabled';
            if (isSelected && !isDisabled) classes += ' bt-slot-selected';

            if (isSelected && isDisabled) {
                selectedSlots = selectedSlots.filter(id => id !== parseInt(slot.id));
                selectionChanged = true;
            }
            
            html += '<div class="' + classes + '" data-id="' + slot.id + '" data-price="' + slot.price + '">';
            html += '<div class="bt-slot-header"><span class="bt-slot-name">' + escapeHtml(slot.slot_name) + '</span></div>';
            html += '<div class="bt-slot-time">' + formatTime12h(slot.start_time) + ' — ' + formatTime12h(slot.end_time) + '</div>';
            if (isDisabled) {
                html += '<div class="bt-slot-status">' + (isPastSlot ? 'Time Passed' : 'Unavailable') + '</div>';
            }
            html += '</div>';
        });
        html += '</div>';

        $container.html(html);
        if (selectionChanged) updateUI();

        $container.off('click', '.bt-slot-card').on('click', '.bt-slot-card', function() {
            if ($(this).hasClass('bt-slot-disabled')) return;
            
            const slotId = parseInt($(this).data('id'));
            const index = selectedSlots.indexOf(slotId);
            
            if (index > -1) {
                selectedSlots.splice(index, 1);
                $(this).removeClass('bt-slot-selected');
            } else {
                selectedSlots.push(slotId);
                $(this).addClass('bt-slot-selected');
            }
            
            updateUI();
        });
    }

    function renderTourInfo() {
        const $container = $('#bt-tour-details');
        
        if (!selectedDate) {
            $container.html('<div class="bt-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><p>Select a date to view tour details</p></div>');
            return;
        }

        const dateStr = formatDate(selectedDate);
        const category = typeData.type_category;
        
        // Check if tour time has passed for today
        let isTimePassed = false;
        const localNow = new Date();
        const isToday = dateStr === formatDate(localNow);
        if (isToday && typeData) {
            const localTimeMinutes = localNow.getHours() * 60 + localNow.getMinutes();
            
            const tourStartTime = getSharedTourStartTime() || typeData.tour_start_time || '00:00';
            const startParts = tourStartTime.split(':');
            const tourStartMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1] || 0);
            
            if (localTimeMinutes >= tourStartMinutes) {
                isTimePassed = true;
            }
        }
        
        // If tour time has passed, show unavailable message
        if (isTimePassed) {
            let html = '<div class="bt-tour-info-card bt-tour-unavailable">';
            html += '<div class="bt-tour-time"><strong>Tour Schedule</strong><span>' + formatTime12h(typeData.tour_start_time) + ' — ' + formatTime12h(typeData.tour_end_time) + '</span></div>';
            html += '<div class="bt-unavailable-notice">';
            html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
            html += '<p>Tour time has passed for today. Please select another date.</p>';
            html += '</div>';
            html += '</div>';
            $container.html(html);
            return;
        }
        
        let html = '<div class="bt-tour-info-card">';
        html += '<div class="bt-tour-time"><strong>Tour Schedule</strong><span>' + formatTime12h(typeData.tour_start_time) + ' — ' + formatTime12h(typeData.tour_end_time) + '</span></div>';
        
        if (category === 'individual_tour') {
            const booked = ticketsByDate[dateStr] || 0;
            const totalCapacity = typeData.max_daily_capacity || 50;
            const alreadyBooked = booked;
            const maxAvailable = Math.max(0, totalCapacity - alreadyBooked);
            
            // Check if fully booked
            if (maxAvailable <= 0) {
                html += '<div class="bt-unavailable-notice">';
                html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
                html += '<p>This date is fully booked. Please select another date.</p>';
                html += '</div>';
                html += '</div>';
                $container.html(html);
                return;
            }
            
            // Calculate: Remaining = Total capacity - Already booked - Currently selected
            const displayRemaining = Math.max(0, totalCapacity - alreadyBooked - ticketCount);
            html += '<div class="bt-ticket-selector">';
            html += '<label>Number of Tickets</label>';
            html += '<div class="bt-ticket-controls">';
            html += '<button type="button" class="bt-ticket-btn" id="bt-ticket-minus">−</button>';
            html += '<span class="bt-ticket-count" id="bt-ticket-display">' + ticketCount + '</span>';
            html += '<button type="button" class="bt-ticket-btn" id="bt-ticket-plus">+</button>';
            html += '</div>';
            html += '</div>';
			html += '<div class="bt-remaining-capacity"><strong>Available Tickets</strong><span class="bt-capacity-badge" id="bt-remaining-display">' + displayRemaining + ' remaining</span></div>';
        }
        
        html += '</div>';
        $container.html(html);
        
        // Ticket controls with real-time remaining update (only for individual tour)
        if (category === 'individual_tour') {
            const booked = ticketsByDate[dateStr] || 0;
            const totalCapacity = typeData.max_daily_capacity || 50;
            const alreadyBooked = booked;
            const maxAvailable = Math.max(0, totalCapacity - alreadyBooked);
            
            function updateRemainingDisplay() {
                // Remaining = Total - Already booked - Currently selected
                const displayRemaining = Math.max(0, totalCapacity - alreadyBooked - ticketCount);
                $('#bt-remaining-display').text(displayRemaining + ' remaining');
            }
            
            $('#bt-ticket-minus').on('click', function() {
                if (ticketCount > 1) {
                    ticketCount--;
                    $('#bt-ticket-display').text(ticketCount);
                    updateRemainingDisplay();
                    updateUI();
                }
            });
            
            $('#bt-ticket-plus').on('click', function() {
                if (ticketCount < maxAvailable) {
                    ticketCount++;
                    $('#bt-ticket-display').text(ticketCount);
                    updateRemainingDisplay();
                    updateUI();
                } else {
                    showToast('Maximum ' + maxAvailable + ' tickets available', 'error');
                }
            });
        }
        
        updateUI();
    }

    function updateUI() {
        const category = $('#bt-type-category').val();
        let showSummary = false;
        let totalPrice = 0;
        let addonsTotal = 0;
        
        if (isHallCategory(category) && selectedDate && selectedSlots.length > 0) {
            showSummary = true;
            let slotsHtml = '';
            selectedSlots.forEach(function(slotId) {
                const slot = slots.find(s => parseInt(s.id) === slotId);
                if (slot) {
                    const price = parseFloat(slot.price);
                    totalPrice += price;
                    slotsHtml += '<div class="bt-summary-slot"><span>' + escapeHtml(slot.slot_name) + '</span><span>BDT ' + price.toFixed(2) + '</span></div>';
                }
            });
            
            const addonsHtml = (category === 'hall') ? renderAddonsSummary() : '';
            $('#bt-summary-content').html('<div class="bt-summary-date">' + formatDateLong(selectedDate) + '</div><div class="bt-summary-slots">' + slotsHtml + '</div>');
            $('#bt-summary-addons').html(addonsHtml);
            if (category === 'hall') {
                addonsTotal = calculateAddonsTotal();
                totalPrice += addonsTotal;
            }
        } else if ((category === 'event_tour' || category === 'individual_tour') && selectedDate) {
            showSummary = true;
            
            if (category === 'event_tour') {
                totalPrice = parseFloat(typeData.tour_price);
                $('#bt-summary-content').html('<div class="bt-summary-date">' + formatDateLong(selectedDate) + '</div><div class="bt-summary-tour">Event Tour Booking</div>');
            } else {
                totalPrice = parseFloat(typeData.ticket_price) * ticketCount;
                $('#bt-summary-content').html('<div class="bt-summary-date">' + formatDateLong(selectedDate) + '</div><div class="bt-summary-tour">' + ticketCount + ' ticket(s) × BDT ' + parseFloat(typeData.ticket_price).toFixed(2) + '</div>');
            }
        }
        
        if (showSummary) {
            $('#bt-summary-total').html('<span>Total Amount</span><strong>BDT ' + totalPrice.toFixed(2) + '</strong>');
            $('#bt-total-price').val(totalPrice);
            $('#bt-ticket-count').val(ticketCount);
            $('#bt-selected-date').val(formatDate(selectedDate));
            $('#bt-selected-slots').val(selectedSlots.join(','));
            $('#bt-selected-addons').val(JSON.stringify(selectedAddons));
            $('#bt-summary').slideDown(300);
            $('#bt-form-section').slideDown(300);
        } else {
            $('#bt-summary').slideUp(200);
            $('#bt-form-section').slideUp(200);
        }

        toggleAddonsSection();
    }

    function resetForm() {
        selectedDate = null;
        selectedSlots = [];
        ticketCount = 1;
        selectedAddons = {};
        $('#bt-booking-form')[0].reset();
        $('.bt-file-label span').text('Choose file (max 1MB)');
        toggleSections();
        updateUI();
        renderCalendar();
        if (isHallCategory(typeData.type_category)) renderSlots();
        else renderTourInfo();
        loadTypeData($('#bt-type-id').val());
    }

    function toggleSections() {
        const category = $('#bt-type-category').val();
        const showHall = isHallCategory(category);
        $('.bt-slots-section').toggle(showHall);
        $('.bt-tour-info-section').toggle(!showHall);
    }

    function toggleAddonsSection() {
        const category = $('#bt-type-category').val();
        const showAddons = category === 'hall' && selectedDate && selectedSlots.length > 0 && addons.length > 0;
        $('#bt-addons-section').toggle(showAddons);
        if (showAddons) renderAddonsPanel();
    }

    function calculateAddonsTotal() {
        let total = 0;
        Object.keys(selectedAddons).forEach(function(addonId) {
            const qty = parseInt(selectedAddons[addonId]) || 0;
            if (qty <= 0) return;
            const addon = addons.find(a => parseInt(a.id) === parseInt(addonId));
            if (addon) {
                total += parseFloat(addon.price) * qty;
            }
        });
        return total;
    }

    function renderAddonsSummary() {
        if (!Object.keys(selectedAddons).length) return '';
        let html = '';
        Object.keys(selectedAddons).forEach(function(addonId) {
            const qty = parseInt(selectedAddons[addonId]) || 0;
            if (qty <= 0) return;
            const addon = addons.find(a => parseInt(a.id) === parseInt(addonId));
            if (!addon) return;
            const lineTotal = parseFloat(addon.price) * qty;
            html += '<div class="bt-addon-line"><span>' + escapeHtml(addon.name) + ' × ' + qty + '</span><span>BDT ' + lineTotal.toFixed(2) + '</span></div>';
        });
        if (!html) return '';
        html += '<div class="bt-addon-line"><span>Add-ons Total</span><span>BDT ' + calculateAddonsTotal().toFixed(2) + '</span></div>';
        return html;
    }

    function clampSelectedAddons() {
        let changed = false;
        Object.keys(selectedAddons).forEach(function(addonId) {
            const qty = parseInt(selectedAddons[addonId]) || 0;
            const remaining = getAddonRemaining(addonId);
            if (qty > remaining) {
                selectedAddons[addonId] = Math.max(0, remaining);
                changed = true;
            }
        });
        if (changed) {
            updateUI();
        }
    }

    function getAddonRemaining(addonId) {
        if (typeof addonsAvailability[addonId] !== 'undefined') {
            return parseInt(addonsAvailability[addonId]) || 0;
        }
        const addon = addons.find(a => parseInt(a.id) === parseInt(addonId));
        return addon ? parseInt(addon.max_quantity) || 0 : 0;
    }

    function getAddonRemainingForUser(addonId) {
        const remaining = getAddonRemaining(addonId);
        const selectedQty = parseInt(selectedAddons[addonId]) || 0;
        return Math.max(0, remaining - selectedQty);
    }

    function renderAddonsPanel() {
        if (!addons.length) {
            $('#bt-addons-list').html('<p class="bt-text-muted">No add-ons available.</p>');
            return;
        }
        let html = '';
        addons.forEach(function(addon) {
            const remaining = getAddonRemaining(addon.id);
            const remainingForUser = getAddonRemainingForUser(addon.id);
            const selectedQty = parseInt(selectedAddons[addon.id]) || 0;
            const disabled = remaining === 0 && selectedQty === 0;
            html += '<div class="bt-addon-item' + (disabled ? ' bt-addon-disabled' : '') + '" data-addon-id="' + addon.id + '">';
            html += '<div class="bt-addon-info">';
            html += '<div class="bt-addon-name">' + escapeHtml(addon.name) + '</div>';
            html += '<div class="bt-addon-meta">BDT ' + parseFloat(addon.price).toFixed(2) + ' · Available: ' + remainingForUser + '</div>';
            html += '</div>';
            html += '<div class="bt-addon-controls">';
            html += '<button type="button" class="bt-addon-btn bt-addon-minus">−</button>';
            html += '<span class="bt-addon-qty">' + selectedQty + '</span>';
            html += '<button type="button" class="bt-addon-btn bt-addon-plus">+</button>';
            html += '</div>';
            html += '</div>';
        });
        $('#bt-addons-list').html(html);
    }

    // File upload label
    $('#bt-payment-image').on('change', function() {
        const fileName = this.files[0] ? this.files[0].name : 'Choose file (max 1MB)';
        $('.bt-file-label span').text(fileName);
    });

    // Helper functions
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function formatDateLong(date) {
        if (typeof date === 'string') date = new Date(date + 'T00:00:00');
        return date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }

    function formatTime12h(time) {
        if (!time) return '';
        const [h, m] = time.split(':');
        const hour = parseInt(h);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return hour12 + ':' + m + ' ' + ampm;
    }

    function getSharedTourStartTime() {
        return sharedTourStartTime;
    }

    function isHallCategory(category) {
        return category === 'hall' || category === 'staircase';
    }

    function hasBookableHallSlots(dateStr, serverTimeMinutes) {
        if (!slots.length) return false;
        const dateBookedSlots = bookedSlots[dateStr] || [];
        const bookedIntervals = dateBookedSlots.map(function(slotId) {
            const slot = slots.find(s => parseInt(s.id) === parseInt(slotId));
            if (!slot) return null;
            return {
                start: timeToMinutes(slot.start_time),
                end: timeToMinutes(slot.end_time)
            };
        }).filter(Boolean);

        return slots.some(function(slot) {
            const slotId = parseInt(slot.id);
            const slotStart = timeToMinutes(slot.start_time);
            const slotEnd = timeToMinutes(slot.end_time);
            const isBooked = dateBookedSlots.includes(slotId);
            let isOverlapBooked = false;
            if (!isBooked && bookedIntervals.length) {
                for (let i = 0; i < bookedIntervals.length; i++) {
                    const interval = bookedIntervals[i];
                    if (intervalsOverlap(slotStart, slotEnd, interval.start, interval.end)) {
                        isOverlapBooked = true;
                        break;
                    }
                }
            }
            const isPastSlot = serverTimeMinutes >= slotStart;
            return !(isBooked || isOverlapBooked || isPastSlot);
        });
    }

    function timeToMinutes(time) {
        if (!time) return 0;
        const parts = time.split(':');
        const hours = parseInt(parts[0]) || 0;
        const minutes = parseInt(parts[1]) || 0;
        return (hours * 60) + minutes;
    }

    function intervalsOverlap(startA, endA, startB, endB) {
        return startA < endB && endA > startB;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type) {
        const $toast = $('#bt-toast');
        $toast.removeClass('bt-toast-success bt-toast-error bt-toast-show').addClass('bt-toast-' + type + ' bt-toast-show').text(message);
        setTimeout(function() { $toast.removeClass('bt-toast-show'); }, 4000);
    }
});
