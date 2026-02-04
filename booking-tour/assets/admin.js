jQuery(document).ready(function($) {
    let bookingsData = [];
    let currentPage = 1;
    let holidayDate = new Date();
    let holidays = [];
    let holidayPage = 1;
    let deleteConfirmShown = false;

    // Load bookings on main page
    if ($('#bt-bookings-body').length) {
        loadBookings();
    }

    // Load type-specific bookings and slots
    if ($('#bt-type-bookings-body').length) {
        const typeId = $('#bt-type-bookings-body').data('type-id');
        const category = $('#bt-type-bookings-body').data('category');
        loadTypeBookings(typeId);
        if (category === 'hall' || category === 'staircase') loadSlots(typeId);
        if (category === 'hall') loadAddons(typeId);
    }

    // Load holiday calendar
    if ($('#bt-holiday-calendar').length) {
        initHolidayCalendar();
    }

    // Filter button
    $('#bt-filter-btn').on('click', function() {
        currentPage = 1;
        loadBookings();
    });

    $('#bt-report-doc').on('click', function() {
        generateReport('doc');
    });
    $('#bt-report-pdf').on('click', function() {
        generateReport('pdf');
    });

    // Type settings form
    $('#bt-type-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        var weekendDays = [];
        $('input[name="weekend_days[]"]:checked').each(function() {
            weekendDays.push($(this).val());
        });
        
        // Build form data - explicitly handle empty weekend_days
        var formData = {
            action: 'bt_save_type_settings',
            nonce: btAdmin.nonce,
            type_id: $(this).data('type-id')
        };
        
        // If no days selected, send empty string; otherwise send the array
        if (weekendDays.length === 0) {
            formData['weekend_days'] = '';
        } else {
            formData['weekend_days[]'] = weekendDays;
        }
        
        $.post(btAdmin.ajaxUrl, formData, function(response) {
            if (response.success) showMessage('Settings saved successfully!', 'success');
            else showMessage('Error saving settings', 'error');
        });
    });

    // Save tour settings
    $('#bt-save-tour').on('click', function() {
        const typeId = $(this).data('type-id');
        const data = {
            action: 'bt_save_type_settings',
            nonce: btAdmin.nonce,
            type_id: typeId,
            tour_start_time: $('#bt-tour-start').val(),
            tour_end_time: $('#bt-tour-end').val()
        };
        if ($('#bt-tour-price').length) data.tour_price = $('#bt-tour-price').val();
        if ($('#bt-max-capacity').length) data.max_daily_capacity = $('#bt-max-capacity').val();
        if ($('#bt-ticket-price').length) data.ticket_price = $('#bt-ticket-price').val();
        if ($('#bt-booking-window-mode').length) data.booking_window_mode = $('#bt-booking-window-mode').val();
        if ($('#bt-booking-window-days').length) data.booking_window_days = $('#bt-booking-window-days').val();

        $.post(btAdmin.ajaxUrl, data, function(response) {
            if (response.success) showMessage('Tour settings saved!', 'success');
            else showMessage('Error saving settings', 'error');
        });
    });

    // Add slot
    $('#bt-add-slot').on('click', function() {
        const typeId = $(this).data('type-id');
        const slotName = $('#bt-slot-name').val().trim();
        const startTime = $('#bt-slot-start').val();
        const endTime = $('#bt-slot-end').val();
        const price = $('#bt-slot-price').val();

        if (!slotName || !startTime || !endTime) {
            showMessage('Please fill in slot name and time', 'error');
            return;
        }

        $.post(btAdmin.ajaxUrl, {
            action: 'bt_save_slot',
            nonce: btAdmin.nonce,
            type_id: typeId,
            slot_name: slotName,
            start_time: startTime,
            end_time: endTime,
            price: price || 0
        }, function(response) {
            if (response.success) {
                showMessage('Slot added successfully!', 'success');
                $('#bt-slot-name, #bt-slot-start, #bt-slot-end, #bt-slot-price').val('');
                loadSlots(typeId);
            } else {
                showMessage(response.data || 'Error adding slot', 'error');
            }
        });
    });

    // Add add-on
    $('#bt-add-addon').on('click', function() {
        const typeId = $(this).data('type-id');
        const name = $('#bt-addon-name').val().trim();
        const price = $('#bt-addon-price').val();
        const maxQty = $('#bt-addon-max').val();
        if (!name) {
            showMessage('Please enter add-on name', 'error');
            return;
        }
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_save_addon',
            nonce: btAdmin.nonce,
            type_id: typeId,
            name: name,
            price: price || 0,
            max_quantity: maxQty || 0
        }, function(response) {
            if (response.success) {
                showMessage('Add-on saved', 'success');
                $('#bt-addon-name, #bt-addon-price, #bt-addon-max').val('');
                loadAddons(typeId);
            } else {
                showMessage(response.data || 'Error saving add-on', 'error');
            }
        });
    });

    $(document).on('click', '.bt-edit-addon', function() {
        const addonId = $(this).data('id');
        const $row = $(this).closest('tr');
        const name = $row.find('[data-field="name"]').text().trim();
        const price = $row.find('[data-field="price"]').data('value');
        const maxQty = $row.find('[data-field="max"]').data('value');
        const newName = prompt('Edit add-on name', name);
        if (newName === null) return;
        const newPrice = prompt('Edit price', price);
        if (newPrice === null) return;
        const newMax = prompt('Edit max quantity', maxQty);
        if (newMax === null) return;
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_update_addon',
            nonce: btAdmin.nonce,
            addon_id: addonId,
            name: newName.trim(),
            price: newPrice,
            max_quantity: newMax
        }, function(response) {
            if (response.success) {
                showMessage('Add-on updated', 'success');
                const typeId = $('#bt-addons-body').data('type-id');
                loadAddons(typeId);
            } else {
                showMessage(response.data || 'Error updating add-on', 'error');
            }
        });
    });

    $(document).on('click', '.bt-delete-addon', function() {
        const addonId = $(this).data('id');
        if (!confirm('Delete this add-on?')) return;
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_delete_addon',
            nonce: btAdmin.nonce,
            addon_id: addonId
        }, function(response) {
            if (response.success) {
                showMessage('Add-on deleted', 'success');
                const typeId = $('#bt-addons-body').data('type-id');
                loadAddons(typeId);
            }
        });
    });

    // Delete slot
    $(document).on('click', '.bt-delete-slot', function(e) {
        e.stopPropagation();
        if (deleteConfirmShown) return;
        deleteConfirmShown = true;
        
        if (!confirm('Are you sure you want to delete this slot?')) {
            deleteConfirmShown = false;
            return;
        }
        
        const slotId = $(this).data('id');
        const typeId = $('#bt-slots-body').data('type-id');

        $.post(btAdmin.ajaxUrl, {
            action: 'bt_delete_slot',
            nonce: btAdmin.nonce,
            slot_id: slotId
        }, function(response) {
            deleteConfirmShown = false;
            if (response.success) {
                showMessage('Slot deleted', 'success');
                loadSlots(typeId);
            }
        });
    });

    function loadSlots(typeId) {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_slots',
            nonce: btAdmin.nonce,
            type_id: typeId
        }, function(response) {
            if (response.success) renderSlots(response.data);
        });
    }

    function loadAddons(typeId) {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_addons',
            nonce: btAdmin.nonce,
            type_id: typeId
        }, function(response) {
            if (response.success) renderAddons(response.data);
        });
    }

    function renderSlots(slots) {
        let html = '';
        if (slots.length === 0) {
            html = '<tr><td colspan="4" class="bt-empty">No slots created yet</td></tr>';
        } else {
            slots.forEach(function(slot) {
                html += '<tr>';
                html += '<td><strong>' + escapeHtml(slot.slot_name) + '</strong></td>';
                html += '<td>' + formatTime(slot.start_time) + ' - ' + formatTime(slot.end_time) + '</td>';
                html += '<td>BDT ' + parseFloat(slot.price).toFixed(2) + '</td>';
                html += '<td><button class="bt-btn bt-btn-delete bt-delete-slot" data-id="' + slot.id + '">Delete</button></td>';
                html += '</tr>';
            });
        }
        $('#bt-slots-body').html(html);
    }

    function renderAddons(addons) {
        let html = '';
        if (!addons.length) {
            html = '<tr><td colspan="4" class="bt-empty">No add-ons created yet</td></tr>';
        } else {
            addons.forEach(function(addon) {
                html += '<tr>';
                html += '<td data-field="name">' + escapeHtml(addon.name) + '</td>';
                html += '<td data-field="price" data-value="' + addon.price + '">BDT ' + parseFloat(addon.price).toFixed(2) + '</td>';
                html += '<td data-field="max" data-value="' + addon.max_quantity + '">' + addon.max_quantity + '</td>';
                html += '<td>';
                html += '<button class="bt-btn bt-edit-addon" data-id="' + addon.id + '">Edit</button> ';
                html += '<button class="bt-btn bt-btn-delete bt-delete-addon" data-id="' + addon.id + '">Delete</button>';
                html += '</td>';
                html += '</tr>';
            });
        }
        $('#bt-addons-body').html(html);
    }

    function loadBookings() {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_bookings',
            nonce: btAdmin.nonce,
            type_id: $('#bt-filter-type').val(),
            status: $('#bt-filter-status').val(),
            start_date: $('#bt-filter-start-date').val(),
            end_date: $('#bt-filter-end-date').val(),
            page: currentPage
        }, function(response) {
            if (response.success) {
                bookingsData = response.data.bookings;
                renderBookingsTable(response.data.bookings, '#bt-bookings-body', true);
                renderPagination(response.data.pages, currentPage, '#bt-pagination');
            }
        });
    }

    function loadTypeBookings(typeId) {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_bookings',
            nonce: btAdmin.nonce,
            type_id: typeId,
            page: currentPage
        }, function(response) {
            if (response.success) {
                bookingsData = response.data.bookings;
                renderBookingsTable(response.data.bookings, '#bt-type-bookings-body', false);
                renderPagination(response.data.pages, currentPage, '#bt-type-pagination');
            }
        });
    }

    function renderBookingsTable(bookings, targetSelector, showType) {
        let html = '';
        const colCount = showType ? 8 : 7;
        
        if (bookings.length === 0) {
            html = '<tr><td colspan="' + colCount + '" class="bt-empty"><p>No bookings found</p></td></tr>';
        } else {
            bookings.forEach(function(booking) {
                html += '<tr>';
                html += '<td><span class="bt-id-badge">#' + booking.id + '</span></td>';
                if (showType) {
                    html += '<td><span class="bt-type-badge">' + escapeHtml(booking.type_name || 'N/A') + '</span></td>';
                }
                html += '<td>' + formatDate(booking.booking_date) + '</td>';
                html += '<td><strong>' + escapeHtml(booking.customer_name) + '</strong></td>';
                
                if (booking.type_category === 'individual_tour') {
                    html += '<td>' + booking.ticket_count + ' tickets</td>';
                } else {
                    html += '<td><span class="bt-price">BDT ' + parseFloat(booking.total_price).toFixed(2) + '</span></td>';
                }
                
                html += '<td><span class="bt-badge bt-badge-' + booking.status + '">' + capitalize(booking.status) + '</span></td>';
                html += '<td><button class="bt-btn bt-btn-view" data-id="' + booking.id + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></td>';
                
                html += '<td class="bt-actions">';
                if (booking.status === 'pending') {
                    html += '<button class="bt-btn bt-btn-approve" data-id="' + booking.id + '" data-action="approved">Approve</button>';
                    html += '<button class="bt-btn bt-btn-reject" data-id="' + booking.id + '" data-action="rejected">Reject</button>';
                }
                html += '<button class="bt-btn bt-btn-delete" data-id="' + booking.id + '">Delete</button>';
                html += '</td>';
                html += '</tr>';
            });
        }
        $(targetSelector).html(html);
    }

    function generateReport(format) {
        const params = {
            action: 'bt_generate_report',
            nonce: btAdmin.nonce,
            type_id: $('#bt-filter-type').val(),
            status: $('#bt-filter-status').val(),
            start_date: $('#bt-filter-start-date').val(),
            end_date: $('#bt-filter-end-date').val(),
            format: format
        };
        const query = $.param(params);
        window.open(btAdmin.ajaxUrl + '?' + query, '_blank');
    }

    function renderPagination(totalPages, current, targetSelector) {
        if (totalPages <= 1) {
            $(targetSelector).html('');
            return;
        }
        let html = '<div class="bt-pagination-controls">';
        for (let i = 1; i <= totalPages; i++) {
            html += '<button class="bt-page-btn' + (i === current ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        html += '</div>';
        $(targetSelector).html(html);
    }

    $(document).on('click', '.bt-page-btn', function() {
        currentPage = parseInt($(this).data('page'));
        if ($('#bt-bookings-body').length) loadBookings();
        else if ($('#bt-type-bookings-body').length) loadTypeBookings($('#bt-type-bookings-body').data('type-id'));
    });

    $(document).on('click', '.bt-btn-view', function() {
        const bookingId = parseInt($(this).data('id'));
        const booking = bookingsData.find(b => parseInt(b.id) === bookingId);
        if (booking) showBookingDetails(booking);
    });

    function showBookingDetails(booking) {
        let html = '<div class="bt-detail-grid">';
        html += '<div class="bt-detail-section"><h4>Customer Information</h4>';
        html += '<div class="bt-detail-row"><span>Full Name:</span><strong>' + escapeHtml(booking.customer_name) + '</strong></div>';
        html += '<div class="bt-detail-row"><span>Phone:</span><strong>' + escapeHtml(booking.customer_phone) + '</strong></div>';
        html += '<div class="bt-detail-row"><span>Email:</span><a href="mailto:' + escapeHtml(booking.customer_email) + '">' + escapeHtml(booking.customer_email) + '</a></div>';
        html += '</div>';
        
        html += '<div class="bt-detail-section"><h4>Booking Details</h4>';
        html += '<div class="bt-detail-row"><span>Type:</span><strong>' + escapeHtml(booking.type_name || 'N/A') + '</strong></div>';
        html += '<div class="bt-detail-row"><span>Date:</span><strong>' + formatDateLong(booking.booking_date) + '</strong></div>';
        if ((booking.type_category === 'hall' || booking.type_category === 'staircase') && booking.slot_details && booking.slot_details.length) {
            const slotLines = booking.slot_details.map(function(slot) {
                return escapeHtml(slot.slot_name) + ' (' + formatTime(slot.start_time) + ' - ' + formatTime(slot.end_time) + ')';
            });
            html += '<div class="bt-detail-row"><span>Slots:</span><strong>' + slotLines.join(', ') + '</strong></div>';
            if (typeof booking.slot_total !== 'undefined') {
                html += '<div class="bt-detail-row"><span>Slots Total:</span><strong>BDT ' + parseFloat(booking.slot_total).toFixed(2) + '</strong></div>';
            }
        }
        if (booking.type_category === 'individual_tour') {
            html += '<div class="bt-detail-row"><span>Tickets:</span><strong>' + booking.ticket_count + ' person(s)</strong></div>';
        }
        if (booking.type_category === 'hall' && booking.addon_details && booking.addon_details.length) {
            html += '<div class="bt-detail-row"><span>Add-ons:</span><strong></strong></div>';
            booking.addon_details.forEach(function(addon) {
                html += '<div class="bt-detail-row"><span>' + escapeHtml(addon.name) + ' × ' + addon.quantity + '</span><strong>BDT ' + parseFloat(addon.line_total).toFixed(2) + ' <span class="bt-text-muted">(BDT ' + parseFloat(addon.price).toFixed(2) + ')</span></strong></div>';
            });
            html += '<div class="bt-detail-row"><span>Add-ons Subtotal:</span><strong>BDT ' + parseFloat(booking.addons_subtotal || 0).toFixed(2) + '</strong></div>';
        }
        html += '<div class="bt-detail-row"><span>Total:</span><strong class="bt-price-lg">BDT ' + parseFloat(booking.total_price).toFixed(2) + '</strong></div>';
        html += '</div>';
        
        html += '<div class="bt-detail-section bt-detail-full"><h4>Payment Information</h4>';
        if (booking.transaction_id) {
            html += '<div class="bt-detail-row"><span>Transaction ID:</span><code>' + escapeHtml(booking.transaction_id) + '</code></div>';
        }
        if (booking.payment_image) {
            html += '<div class="bt-detail-row"><span>Payment Screenshot:</span><a href="' + booking.payment_image + '" target="_blank" class="bt-image-link">View Image</a></div>';
        }
        html += '</div>';
        
        if (booking.notes) {
            html += '<div class="bt-detail-section bt-detail-full"><h4>Notes</h4><p>' + escapeHtml(booking.notes) + '</p></div>';
        }
        html += '</div>';
        
        $('#bt-modal-body').html(html);
        $('#bt-details-modal').fadeIn(200);
    }

    $(document).on('click', '.bt-modal-close, .bt-modal-overlay', function() {
        $('.bt-modal').fadeOut(200);
    });

    $(document).on('click', '.bt-btn-approve, .bt-btn-reject', function() {
        const $btn = $(this);
        const action = $btn.data('action');
        
        if (action === 'rejected' && !confirm('Reject this booking?')) return;
        
        $btn.prop('disabled', true).text('...');
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_update_booking_status',
            nonce: btAdmin.nonce,
            booking_id: $btn.data('id'),
            status: action
        }, function(response) {
            if (response.success) {
                showMessage(response.data, 'success');
                reloadBookings();
            }
        });
    });

    $(document).on('click', '.bt-btn-delete:not(.bt-delete-slot)', function(e) {
        e.stopPropagation();
        if (deleteConfirmShown) return;
        deleteConfirmShown = true;
        
        if (!confirm('Permanently delete this booking?')) {
            deleteConfirmShown = false;
            return;
        }
        
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_delete_booking',
            nonce: btAdmin.nonce,
            booking_id: $(this).data('id')
        }, function(response) {
            deleteConfirmShown = false;
            if (response.success) {
                showMessage('Booking deleted', 'success');
                reloadBookings();
            }
        });
    });

    function reloadBookings() {
        if ($('#bt-bookings-body').length) loadBookings();
        if ($('#bt-type-bookings-body').length) loadTypeBookings($('#bt-type-bookings-body').data('type-id'));
    }

    // Holiday Calendar
    function initHolidayCalendar() {
        loadHolidays();
        
        $('#bt-holiday-prev').on('click', function() {
            holidayDate.setMonth(holidayDate.getMonth() - 1);
            renderHolidayCalendar();
        });
        
        $('#bt-holiday-next').on('click', function() {
            holidayDate.setMonth(holidayDate.getMonth() + 1);
            renderHolidayCalendar();
        });
        
        $(document).on('click', '.bt-holiday-day:not(.bt-day-empty)', function() {
            const dateStr = $(this).data('date');
            const isHoliday = holidays.includes(dateStr);
            
            $('#bt-modal-date').text(formatDateLong(dateStr));
            $('#bt-holiday-modal').data('date', dateStr).fadeIn(200);
            
            if (isHoliday) {
                $('#bt-set-holiday').hide();
                $('#bt-remove-holiday').show();
            } else {
                $('#bt-set-holiday').show();
                $('#bt-remove-holiday').hide();
            }
        });
        
        $('#bt-set-holiday').on('click', function() {
            saveHoliday($('#bt-holiday-modal').data('date'), true);
        });
        
        $('#bt-remove-holiday').on('click', function() {
            saveHoliday($('#bt-holiday-modal').data('date'), false);
        });
    }

    function loadHolidays() {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_holidays',
            nonce: btAdmin.nonce,
            get_all: 'true'
        }, function(response) {
            if (response.success) {
                holidays = response.data;
                renderHolidayCalendar();
                loadHolidayList();
            }
        });
    }

    function loadHolidayList() {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_get_holidays',
            nonce: btAdmin.nonce,
            page: holidayPage
        }, function(response) {
            if (response.success) {
                renderHolidayList(response.data.holidays);
                renderPagination(response.data.pages, holidayPage, '#bt-holiday-pagination');
            }
        });
    }

    function saveHoliday(dateStr, isHoliday) {
        $.post(btAdmin.ajaxUrl, {
            action: 'bt_save_holiday',
            nonce: btAdmin.nonce,
            date: dateStr,
            is_holiday: isHoliday ? 'true' : 'false'
        }, function(response) {
            if (response.success) {
                $('#bt-holiday-modal').fadeOut(200);
                loadHolidays();
                showMessage(isHoliday ? 'Holiday added' : 'Holiday removed', 'success');
            }
        });
    }

    function renderHolidayCalendar() {
        const year = holidayDate.getFullYear();
        const month = holidayDate.getMonth();
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        
        $('#bt-holiday-month-year').text(monthNames[month] + ' ' + year);
        
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        let html = '';
        for (let i = 0; i < firstDay; i++) {
            html += '<div class="bt-holiday-day bt-day-empty"></div>';
        }
        
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateStr = formatDateISO(date);
            const isHoliday = holidays.includes(dateStr);
            
            let classes = 'bt-holiday-day';
            if (isHoliday) classes += ' bt-day-holiday';
            
            html += '<div class="' + classes + '" data-date="' + dateStr + '">' + day + '</div>';
        }
        
        $('#bt-holiday-calendar').html(html);
    }

    function renderHolidayList(holidayList) {
        if (holidayList.length === 0) {
            $('#bt-holiday-list').html('<p class="bt-empty-small">No holidays set</p>');
            return;
        }
        
        let html = '<ul class="bt-holiday-items">';
        holidayList.forEach(function(date) {
            html += '<li><span>' + formatDateLong(date) + '</span><button class="bt-remove-holiday-btn" data-date="' + date + '">×</button></li>';
        });
        html += '</ul>';
        $('#bt-holiday-list').html(html);
    }

    $(document).on('click', '.bt-remove-holiday-btn', function(e) {
        e.stopPropagation();
        saveHoliday($(this).data('date'), false);
    });

    // Helper functions
    function showMessage(text, type) {
        const $msg = $('<div class="bt-message bt-message-' + type + '">' + text + '</div>');
        $('.bt-admin-wrap').prepend($msg);
        setTimeout(function() { $msg.fadeOut(300, function() { $(this).remove(); }); }, 3000);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(time) {
        if (!time) return '';
        const [h, m] = time.split(':');
        const hour = parseInt(h);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return hour12 + ':' + m + ' ' + ampm;
    }

    function formatDate(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatDateLong(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }

    function formatDateISO(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function capitalize(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
});
