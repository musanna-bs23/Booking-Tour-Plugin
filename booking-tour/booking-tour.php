<?php
/**
 * Plugin Name: Booking Tour
 * Description: A comprehensive booking system for Multipurpose Hall and Knowledge Hub Tours
 * Version: 1.0.0
 * Author: Hasan Al Musanna
 */

if (!defined('ABSPATH')) {
    exit;
}

// Database tables are assumed to already exist - no creation logic needed

class BookingTour {
    
    private $items_per_page = 10;
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
        
        // Shortcodes
        add_shortcode('book_tour', array($this, 'render_book_tour_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_bt_save_type_settings', array($this, 'save_type_settings'));
        add_action('wp_ajax_bt_get_type_settings', array($this, 'get_type_settings'));
        add_action('wp_ajax_bt_submit_booking', array($this, 'submit_booking'));
        add_action('wp_ajax_nopriv_bt_submit_booking', array($this, 'submit_booking'));
        add_action('wp_ajax_bt_get_bookings', array($this, 'get_bookings'));
        add_action('wp_ajax_bt_update_booking_status', array($this, 'update_booking_status'));
        add_action('wp_ajax_bt_delete_booking', array($this, 'delete_booking'));
        add_action('wp_ajax_bt_get_booking_data', array($this, 'get_booking_data'));
        add_action('wp_ajax_nopriv_bt_get_booking_data', array($this, 'get_booking_data'));
        
        // Slot management (Hall only)
        add_action('wp_ajax_bt_save_slot', array($this, 'save_slot'));
        add_action('wp_ajax_bt_delete_slot', array($this, 'delete_slot'));
        add_action('wp_ajax_bt_get_slots', array($this, 'get_slots'));
        
        // Holiday management
        add_action('wp_ajax_bt_save_holiday', array($this, 'save_holiday'));
        add_action('wp_ajax_bt_delete_holiday', array($this, 'delete_holiday'));
        add_action('wp_ajax_bt_get_holidays', array($this, 'get_holidays'));
        add_action('wp_ajax_nopriv_bt_get_holidays', array($this, 'get_holidays'));

        // Real-time availability check
        add_action('wp_ajax_bt_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_bt_check_availability', array($this, 'check_availability'));
        
        // Individual tour capacity
        add_action('wp_ajax_bt_get_remaining_capacity', array($this, 'get_remaining_capacity'));
        add_action('wp_ajax_nopriv_bt_get_remaining_capacity', array($this, 'get_remaining_capacity'));
    }

    public function activate() {
        // Database tables are assumed to already exist
        // Only create upload directory if needed
        $upload_dir = wp_upload_dir();
        $bt_dir = $upload_dir['basedir'] . '/booking-tour-payments';
        if (!file_exists($bt_dir)) {
            wp_mkdir_p($bt_dir);
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Booking Tour',
            'Booking Tour',
            'manage_options',
            'booking-tour',
            array($this, 'render_bookings_page'),
            'dashicons-calendar-alt',
            30
        );

        // Holidays submenu
        add_submenu_page(
            'booking-tour',
            'Holiday Management',
            'Holidays',
            'manage_options',
            'booking-tour-holidays',
            array($this, 'render_holidays_page')
        );

        global $wpdb;
        $types = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bt_booking_types ORDER BY id");
        
        foreach ($types as $type) {
            add_submenu_page(
                'booking-tour',
                $type->type_name,
                $type->type_name,
                'manage_options',
                'booking-tour-' . $type->type_slug,
                array($this, 'render_type_settings_page')
            );
        }
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'booking-tour') === false) return;
        
        wp_enqueue_style('bt-admin-css', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '5.0.0');
        wp_enqueue_script('bt-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '5.0.0', true);
        wp_localize_script('bt-admin-js', 'btAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bt_admin_nonce'),
            'itemsPerPage' => $this->items_per_page
        ));
    }

    public function frontend_scripts() {
        wp_enqueue_style('bt-frontend-css', plugin_dir_url(__FILE__) . 'assets/frontend.css', array(), '5.0.0');
        wp_enqueue_script('bt-frontend-js', plugin_dir_url(__FILE__) . 'assets/frontend.js', array('jquery'), '5.0.0', true);
        wp_localize_script('bt-frontend-js', 'btFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bt_frontend_nonce'),
            'maxUploadSize' => 1 * 1024 * 1024,
            'serverTime' => current_time('H:i'),
            'serverDate' => current_time('Y-m-d'),
            'termsUrl' => 'http://35.240.207.116/knowledgehub/wordpress/?page_id=1031',
            'paymentRulesUrl' => 'http://35.240.207.116/knowledgehub/wordpress/?page_id=1607'
        ));
    }

    public function render_bookings_page() {
        global $wpdb;
        $types = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bt_booking_types ORDER BY id");
        ?>
        <div class="wrap bt-admin-wrap">
            <h1>
                <svg class="bt-icon-title" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                Booking Management
            </h1>
            
            <div class="bt-filters">
                <div class="bt-filter-group">
                    <svg class="bt-filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                    <select id="bt-filter-type">
                        <option value="">All Booking Types</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>"><?php echo esc_html($type->type_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bt-filter-group">
                    <svg class="bt-filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <select id="bt-filter-status">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                    </select>
                </div>
                <button class="button bt-filter-btn" id="bt-filter-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    Filter
                </button>
            </div>

            <div class="bt-bookings-card">
                <table class="bt-bookings-table" id="bt-bookings-table">
                    <thead>
                        <tr>
                            <th width="60">ID</th>
                            <th>Booking Type</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th width="80">Details</th>
                            <th width="200">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bt-bookings-body">
                    </tbody>
                </table>
                <div class="bt-pagination" id="bt-pagination"></div>
            </div>
        </div>

        <!-- Booking Details Modal -->
        <div class="bt-modal" id="bt-details-modal" style="display:none;">
            <div class="bt-modal-overlay"></div>
            <div class="bt-modal-container">
                <div class="bt-modal-header">
                    <h3>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        Booking Details
                    </h3>
                    <button class="bt-modal-close">&times;</button>
                </div>
                <div class="bt-modal-body" id="bt-modal-body">
                </div>
            </div>
        </div>
        <?php
    }

    public function render_holidays_page() {
        ?>
        <div class="wrap bt-admin-wrap">
            <h1>
                <svg class="bt-icon-title" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <line x1="9" y1="16" x2="15" y2="16"></line>
                </svg>
                Holiday Management
            </h1>
            <p class="bt-page-description">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                Mark dates as holidays. Holiday dates will be disabled for all booking types.
            </p>
            
            <div class="bt-holiday-container">
                <div class="bt-holiday-calendar-section">
                    <div class="bt-calendar-nav">
                        <button class="bt-nav-btn" id="bt-holiday-prev">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                        </button>
                        <span id="bt-holiday-month-year"></span>
                        <button class="bt-nav-btn" id="bt-holiday-next">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </button>
                    </div>
                    <div class="bt-calendar-weekdays">
                        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                    </div>
                    <div class="bt-calendar-days" id="bt-holiday-calendar"></div>
                </div>
                
                <div class="bt-holiday-list-section">
                    <h3>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                            <path d="M2 17l10 5 10-5"></path>
                            <path d="M2 12l10 5 10-5"></path>
                        </svg>
                        Holidays List
                    </h3>
                    <div id="bt-holiday-list"></div>
                    <div class="bt-pagination" id="bt-holiday-pagination"></div>
                </div>
            </div>
            
            <div class="bt-modal" id="bt-holiday-modal" style="display:none;">
                <div class="bt-modal-overlay"></div>
                <div class="bt-modal-container bt-modal-sm">
                    <div class="bt-modal-header">
                        <h3>Set Holiday</h3>
                        <button class="bt-modal-close">&times;</button>
                    </div>
                    <div class="bt-modal-body">
                        <p class="bt-modal-date" id="bt-modal-date"></p>
                        <div class="bt-modal-actions">
                            <button class="button button-primary bt-brand-btn" id="bt-set-holiday">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                Mark as Holiday
                            </button>
                            <button class="button" id="bt-remove-holiday">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                                Remove Holiday
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_type_settings_page() {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $type_slug = str_replace('booking-tour-', '', $page);
        
        global $wpdb;
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE type_slug = %s",
            $type_slug
        ));

        if (!$type) {
            echo '<div class="wrap"><h1>Booking type not found</h1></div>';
            return;
        }
        ?>
        <div class="wrap bt-admin-wrap">
            <h1>
                <svg class="bt-icon-title" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <?php if ($type->type_category === 'hall'): ?>
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    <?php else: ?>
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                    <?php endif; ?>
                </svg>
                <?php echo esc_html($type->type_name); ?> Settings
            </h1>
            
            <!-- Blocked Days Settings -->
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                    </svg>
                    Blocked Days
                </h2>
                <form id="bt-type-settings-form" data-type-id="<?php echo esc_attr($type->id); ?>" data-category="<?php echo esc_attr($type->type_category); ?>">
                    <div class="bt-checkbox-group">
                        <?php
                        $days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
                        $weekend_days = explode(',', $type->weekend_days);
                        foreach ($days as $index => $day):
                        ?>
                        <label class="bt-day-checkbox">
                            <input type="checkbox" name="weekend_days[]" value="<?php echo $index; ?>" 
                                <?php echo in_array($index, $weekend_days) ? 'checked' : ''; ?>>
                            <span><?php echo $day; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="bt-hint">Select recurring days to block from booking</p>
                    <button type="submit" class="button button-primary bt-save-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Save Settings
                    </button>
                </form>
            </div>
            
            <?php if ($type->type_category === 'hall'): ?>
            <!-- Slot Management (Multipurpose Hall Only) -->
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Tour Slots
                </h2>
                <p class="bt-hint">Create custom time slots with individual pricing</p>
                
                <div class="bt-slot-form">
                    <div class="bt-slot-input-group">
                        <label>Slot Name</label>
                        <input type="text" id="bt-slot-name" placeholder="e.g., Morning Session">
                    </div>
                    <div class="bt-slot-input-group">
                        <label>Start Time</label>
                        <input type="time" id="bt-slot-start">
                    </div>
                    <div class="bt-slot-input-group">
                        <label>End Time</label>
                        <input type="time" id="bt-slot-end">
                    </div>
                    <div class="bt-slot-input-group">
                        <label>Price (BDT)</label>
                        <input type="number" id="bt-slot-price" placeholder="0.00" min="0" step="0.01">
                    </div>
                    <button class="button button-primary bt-add-slot-btn" id="bt-add-slot" data-type-id="<?php echo esc_attr($type->id); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add Slot
                    </button>
                </div>
                
                <table class="bt-slots-table">
                    <thead>
                        <tr>
                            <th>Slot Name</th>
                            <th>Time</th>
                            <th>Price</th>
                            <th width="100">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bt-slots-body" data-type-id="<?php echo esc_attr($type->id); ?>">
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($type->type_category === 'event_tour'): ?>
            <!-- Event Tour Settings -->
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Tour Schedule
                </h2>
                <p class="bt-hint">Set the tour timing and price for event bookings</p>
                
                <div class="bt-tour-settings-form">
                    <div class="bt-input-row">
                        <div class="bt-input-group">
                            <label>Tour Start Time</label>
                            <input type="time" id="bt-tour-start" value="<?php echo esc_attr(substr($type->tour_start_time, 0, 5)); ?>">
                        </div>
                        <div class="bt-input-group">
                            <label>Tour End Time</label>
                            <input type="time" id="bt-tour-end" value="<?php echo esc_attr(substr($type->tour_end_time, 0, 5)); ?>">
                        </div>
                        <div class="bt-input-group">
                            <label>Price</label>
                            <input type="number" id="bt-tour-price" value="<?php echo esc_attr($type->tour_price); ?>" min="0" step="0.01">
                        </div>
                    </div>
                    <button class="button button-primary bt-save-tour-btn" id="bt-save-tour" data-type-id="<?php echo esc_attr($type->id); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        </svg>
                        Save Tour Settings
                    </button>
                </div>
            </div>
            
            <?php elseif ($type->type_category === 'individual_tour'): ?>
            <!-- Individual Tour Settings -->
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    Tour Schedule & Capacity
                </h2>
                <p class="bt-hint">Set the tour timing, daily capacity, and ticket price</p>
                
                <div class="bt-tour-settings-form">
                    <div class="bt-input-row">
                        <div class="bt-input-group">
                            <label>Tour Start Time</label>
                            <input type="time" id="bt-tour-start" value="<?php echo esc_attr(substr($type->tour_start_time, 0, 5)); ?>">
                        </div>
                        <div class="bt-input-group">
                            <label>Tour End Time</label>
                            <input type="time" id="bt-tour-end" value="<?php echo esc_attr(substr($type->tour_end_time, 0, 5)); ?>">
                        </div>
                    </div>
                    <div class="bt-input-row">
                        <div class="bt-input-group">
                            <label>Max Daily Capacity</label>
                            <input type="number" id="bt-max-capacity" value="<?php echo esc_attr($type->max_daily_capacity); ?>" min="1">
                        </div>
                        <div class="bt-input-group">
                            <label>Ticket Price (BDT / per unit)</label>
                            <input type="number" id="bt-ticket-price" value="<?php echo esc_attr($type->ticket_price); ?>" min="0" step="0.01">
                        </div>
                    </div>
                    <button class="button button-primary bt-save-tour-btn" id="bt-save-tour" data-type-id="<?php echo esc_attr($type->id); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        </svg>
                        Save Tour Settings
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Bookings for this type -->
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <?php echo esc_html($type->type_name); ?> Bookings
                </h2>
                <table class="bt-bookings-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th><?php echo $type->type_category === 'individual_tour' ? 'Tickets' : 'Total'; ?></th>
                            <th>Status</th>
                            <th>Details</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bt-type-bookings-body" data-type-id="<?php echo esc_attr($type->id); ?>" data-category="<?php echo esc_attr($type->type_category); ?>">
                    </tbody>
                </table>
                <div class="bt-pagination" id="bt-type-pagination"></div>
            </div>
        </div>

        <!-- Booking Details Modal -->
        <div class="bt-modal" id="bt-details-modal" style="display:none;">
            <div class="bt-modal-overlay"></div>
            <div class="bt-modal-container">
                <div class="bt-modal-header">
                    <h3>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        Booking Details
                    </h3>
                    <button class="bt-modal-close">&times;</button>
                </div>
                <div class="bt-modal-body" id="bt-modal-body">
                </div>
            </div>
        </div>
        <?php
    }

    // Slot Management (Hall only)
    public function save_slot() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $slot_name = sanitize_text_field($_POST['slot_name']);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = sanitize_text_field($_POST['end_time']);
        $price = floatval($_POST['price']);

        if (empty($slot_name) || empty($start_time) || empty($end_time)) {
            wp_send_json_error('All fields are required');
        }

        $wpdb->insert(
            $wpdb->prefix . 'bt_slots',
            array(
                'booking_type_id' => $type_id,
                'slot_name' => $slot_name,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'price' => $price
            ),
            array('%d', '%s', '%s', '%s', '%f')
        );

        wp_send_json_success('Slot added successfully');
    }

    public function delete_slot() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $slot_id = intval($_POST['slot_id']);
        
        $wpdb->delete($wpdb->prefix . 'bt_slots', array('id' => $slot_id), array('%d'));
        wp_send_json_success('Slot deleted');
    }

    public function get_slots() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        
        $slots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_slots WHERE booking_type_id = %d ORDER BY start_time",
            $type_id
        ));

        wp_send_json_success($slots);
    }

    // Holiday Management
    public function save_holiday() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $date = sanitize_text_field($_POST['date']);
        $is_holiday = $_POST['is_holiday'] === 'true';

        if ($is_holiday) {
            $wpdb->replace(
                $wpdb->prefix . 'bt_holidays',
                array('holiday_date' => $date),
                array('%s')
            );
        } else {
            $wpdb->delete(
                $wpdb->prefix . 'bt_holidays',
                array('holiday_date' => $date),
                array('%s')
            );
        }

        wp_send_json_success('Holiday updated');
    }

    public function delete_holiday() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $date = sanitize_text_field($_POST['date']);
        
        $wpdb->delete(
            $wpdb->prefix . 'bt_holidays',
            array('holiday_date' => $date),
            array('%s')
        );

        wp_send_json_success('Holiday removed');
    }

    public function get_holidays() {
        global $wpdb;
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $get_all = isset($_POST['get_all']) && $_POST['get_all'] === 'true';
        
        if ($get_all) {
            $holidays = $wpdb->get_col("SELECT holiday_date FROM {$wpdb->prefix}bt_holidays ORDER BY holiday_date DESC");
            wp_send_json_success($holidays);
        } else {
            $offset = ($page - 1) * $this->items_per_page;
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_holidays");
            $holidays = $wpdb->get_col($wpdb->prepare(
                "SELECT holiday_date FROM {$wpdb->prefix}bt_holidays ORDER BY holiday_date DESC LIMIT %d OFFSET %d",
                $this->items_per_page, $offset
            ));
            
            wp_send_json_success(array(
                'holidays' => $holidays,
                'total' => intval($total),
                'pages' => ceil($total / $this->items_per_page)
            ));
        }
    }

    public function save_type_settings() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $type_id = intval($_POST['type_id']);
        
        $data = array();
        $format = array();
        
        // Weekend days - handle both array and empty string cases
        if (isset($_POST['weekend_days'])) {
            if (is_array($_POST['weekend_days'])) {
                $weekend_days = array_map('intval', $_POST['weekend_days']);
                $data['weekend_days'] = implode(',', $weekend_days);
            } else {
                // Empty string means no blocked days
                $data['weekend_days'] = '';
            }
            $format[] = '%s';
        }
        
        // Tour settings
        if (isset($_POST['tour_start_time'])) {
            $data['tour_start_time'] = sanitize_text_field($_POST['tour_start_time']);
            $format[] = '%s';
        }
        if (isset($_POST['tour_end_time'])) {
            $data['tour_end_time'] = sanitize_text_field($_POST['tour_end_time']);
            $format[] = '%s';
        }
        if (isset($_POST['tour_price'])) {
            $data['tour_price'] = floatval($_POST['tour_price']);
            $format[] = '%f';
        }
        if (isset($_POST['max_daily_capacity'])) {
            $data['max_daily_capacity'] = intval($_POST['max_daily_capacity']);
            $format[] = '%d';
        }
        if (isset($_POST['ticket_price'])) {
            $data['ticket_price'] = floatval($_POST['ticket_price']);
            $format[] = '%f';
        }

        if (!empty($data)) {
            $wpdb->update(
                $wpdb->prefix . 'bt_booking_types',
                $data,
                array('id' => $type_id),
                $format,
                array('%d')
            );
        }

        wp_send_json_success('Settings saved successfully');
    }

    public function get_booking_data() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE id = %d",
            $type_id
        ));

        // Get slots (only for hall)
        $slots = array();
        if ($type && $type->type_category === 'hall') {
            $slots = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bt_slots WHERE booking_type_id = %d ORDER BY start_time",
                $type_id
            ));
        }

        // Get holidays
        $holidays = $wpdb->get_col("SELECT holiday_date FROM {$wpdb->prefix}bt_holidays");

        // Get booked slots/dates
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_date, slot_ids, ticket_count, status FROM {$wpdb->prefix}bt_bookings 
             WHERE booking_type_id = %d AND status IN ('pending', 'approved') AND booking_date >= CURDATE()",
            $type_id
        ));

        // Build booked data
        $bookedSlots = array();
        $bookedDates = array();
        $ticketsByDate = array();
        
        foreach ($bookings as $booking) {
            $date = $booking->booking_date;
            
            if ($type->type_category === 'hall') {
                if (!isset($bookedSlots[$date])) {
                    $bookedSlots[$date] = array();
                }
                if ($booking->slot_ids) {
                    $slot_ids = array_map('intval', explode(',', $booking->slot_ids));
                    $bookedSlots[$date] = array_merge($bookedSlots[$date], $slot_ids);
                }
            } else {
                // For tours, track booked dates
                if (!in_array($date, $bookedDates)) {
                    $bookedDates[] = $date;
                }
                // Track tickets for individual tours
                if ($type->type_category === 'individual_tour') {
                    if (!isset($ticketsByDate[$date])) {
                        $ticketsByDate[$date] = 0;
                    }
                    $ticketsByDate[$date] += intval($booking->ticket_count);
                }
            }
        }

        // Also get event tour bookings to check for date conflicts
        $event_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'event_tour'");
        $individual_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'individual_tour'");
        
        $eventBlockedDates = array();
        $individualBlockedDates = array();
        
        if ($event_type) {
            $event_bookings = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT booking_date FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND status IN ('pending', 'approved') AND booking_date >= CURDATE()",
                $event_type->id
            ));
            $eventBlockedDates = $event_bookings;
        }
        
        if ($individual_type) {
            $individual_bookings = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT booking_date FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND status IN ('pending', 'approved') AND booking_date >= CURDATE()",
                $individual_type->id
            ));
            $individualBlockedDates = $individual_bookings;
        }

        // Remove duplicates from booked slots
        foreach ($bookedSlots as $date => $slots_arr) {
            $bookedSlots[$date] = array_values(array_unique($slots_arr));
        }

        wp_send_json_success(array(
            'type' => $type,
            'slots' => $slots,
            'holidays' => $holidays,
            'bookedSlots' => $bookedSlots,
            'bookedDates' => $bookedDates,
            'ticketsByDate' => $ticketsByDate,
            'eventBlockedDates' => $eventBlockedDates,
            'individualBlockedDates' => $individualBlockedDates,
            'serverTime' => current_time('H:i'),
            'serverDate' => current_time('Y-m-d')
        ));
    }

    public function check_availability() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $date = sanitize_text_field($_POST['date']);
        
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE id = %d",
            $type_id
        ));
        
        // Get bookings for this date
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT slot_ids, ticket_count FROM {$wpdb->prefix}bt_bookings 
             WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
            $type_id, $date
        ));

        $bookedSlots = array();
        $totalTickets = 0;
        
        foreach ($bookings as $booking) {
            if ($type->type_category === 'hall' && $booking->slot_ids) {
                $slot_ids = array_map('intval', explode(',', $booking->slot_ids));
                $bookedSlots = array_merge($bookedSlots, $slot_ids);
            }
            $totalTickets += intval($booking->ticket_count);
        }
        
        // Check cross-calendar blocking
        $event_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'event_tour'");
        $individual_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'individual_tour'");
        
        $dateBlockedByEvent = false;
        $dateBlockedByIndividual = false;
        
        if ($event_type) {
            $event_booking = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $event_type->id, $date
            ));
            $dateBlockedByEvent = $event_booking > 0;
        }
        
        if ($individual_type) {
            $individual_booking = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $individual_type->id, $date
            ));
            $dateBlockedByIndividual = $individual_booking > 0;
        }

        wp_send_json_success(array(
            'bookedSlots' => array_values(array_unique($bookedSlots)),
            'totalTickets' => $totalTickets,
            'remainingCapacity' => $type->max_daily_capacity - $totalTickets,
            'dateBlockedByEvent' => $dateBlockedByEvent,
            'dateBlockedByIndividual' => $dateBlockedByIndividual,
            'serverTime' => current_time('H:i'),
            'serverDate' => current_time('Y-m-d')
        ));
    }

    public function get_remaining_capacity() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $date = sanitize_text_field($_POST['date']);
        
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT max_daily_capacity FROM {$wpdb->prefix}bt_booking_types WHERE id = %d",
            $type_id
        ));
        
        $total_booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ticket_count), 0) FROM {$wpdb->prefix}bt_bookings 
             WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
            $type_id, $date
        ));
        
        $remaining = max(0, $type->max_daily_capacity - $total_booked);
        
        wp_send_json_success(array(
            'remaining' => $remaining,
            'maxCapacity' => $type->max_daily_capacity,
            'booked' => intval($total_booked)
        ));
    }

    public function submit_booking() {
        global $wpdb;
        
        $type_id = intval($_POST['type_id']);
        $booking_date = sanitize_text_field($_POST['booking_date']);
        $slot_ids = isset($_POST['slot_ids']) ? sanitize_text_field($_POST['slot_ids']) : '';
        $ticket_count = isset($_POST['ticket_count']) ? intval($_POST['ticket_count']) : 1;
        $total_price = floatval($_POST['total_price']);
        $customer_name = sanitize_text_field($_POST['customer_name']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $customer_phone = sanitize_text_field($_POST['customer_phone']);
        $transaction_id = sanitize_text_field($_POST['transaction_id']);
        $notes = sanitize_textarea_field($_POST['notes']);

        // Validate required fields
        if (empty($type_id) || empty($booking_date) || 
            empty($customer_name) || empty($customer_email) || empty($customer_phone)) {
            wp_send_json_error('Name, Email and Phone are required');
        }

        // Get booking type
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE id = %d",
            $type_id
        ));

        if (!$type) {
            wp_send_json_error('Invalid booking type');
        }

        // Validate slots for hall booking
        if ($type->type_category === 'hall' && empty($slot_ids)) {
            wp_send_json_error('Please select at least one slot');
        }

        // Handle file upload
        $payment_image = '';
        if (!empty($_FILES['payment_image']) && $_FILES['payment_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['payment_image'];
            
            if ($file['size'] > 1 * 1024 * 1024) {
                wp_send_json_error('Payment image must be less than 1MB');
            }
            
            $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
            if (!in_array($file['type'], $allowed_types)) {
                wp_send_json_error('Invalid image type. Allowed: JPG, PNG, GIF, WebP');
            }
            
            $upload_dir = wp_upload_dir();
            $bt_dir = $upload_dir['basedir'] . '/booking-tour-payments';
            $filename = uniqid('payment_') . '_' . sanitize_file_name($file['name']);
            $filepath = $bt_dir . '/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $payment_image = $upload_dir['baseurl'] . '/booking-tour-payments/' . $filename;
            }
        }

        // Validate payment
        if (empty($transaction_id) && empty($payment_image)) {
            wp_send_json_error('Either Transaction ID or Payment Screenshot is required');
        }

        // Check availability based on type
        if ($type->type_category === 'hall') {
            // Check slot availability
            $existing = $wpdb->get_results($wpdb->prepare(
                "SELECT slot_ids FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $type_id, $booking_date
            ));

            $requested_slots = array_map('intval', explode(',', $slot_ids));
            foreach ($existing as $booking) {
                if ($booking->slot_ids) {
                    $booked = array_map('intval', explode(',', $booking->slot_ids));
                    $conflicts = array_intersect($requested_slots, $booked);
                    if (!empty($conflicts)) {
                        wp_send_json_error('Some slots are already booked. Please refresh and try again.');
                    }
                }
            }
        } elseif ($type->type_category === 'event_tour') {
            // Check if date is available (not booked by individual tour)
            $individual_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'individual_tour'");
            if ($individual_type) {
                $blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings 
                     WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                    $individual_type->id, $booking_date
                ));
                if ($blocked > 0) {
                    wp_send_json_error('This date is not available for event booking.');
                }
            }
            
            // Check if date already has event booking
            $event_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $type_id, $booking_date
            ));
            if ($event_exists > 0) {
                wp_send_json_error('This date already has an event booking.');
            }
        } elseif ($type->type_category === 'individual_tour') {
            // Check capacity
            $total_booked = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(ticket_count), 0) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $type_id, $booking_date
            ));
            
            if (($total_booked + $ticket_count) > $type->max_daily_capacity) {
                wp_send_json_error('Not enough tickets available. Only ' . ($type->max_daily_capacity - $total_booked) . ' remaining.');
            }
            
            // Check if date is blocked by event tour
            $event_type = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'event_tour'");
            if ($event_type) {
                $blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings 
                     WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                    $event_type->id, $booking_date
                ));
                if ($blocked > 0) {
                    wp_send_json_error('This date is not available due to an event booking.');
                }
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'bt_bookings',
            array(
                'booking_type_id' => $type_id,
                'booking_date' => $booking_date,
                'slot_ids' => $slot_ids,
                'ticket_count' => $ticket_count,
                'total_price' => $total_price,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,
                'transaction_id' => $transaction_id,
                'payment_image' => $payment_image,
                'notes' => $notes,
                'status' => 'pending'
            ),
            array('%d', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($wpdb->insert_id) {
            // Send notification email
            $this->send_booking_notification_email(
                $customer_name, $customer_email, $customer_phone, 
                $type, $booking_date, $slot_ids, $ticket_count,
                $total_price, $transaction_id, $payment_image, $notes
            );
            
            wp_send_json_success('Booking submitted successfully! Awaiting approval.');
        } else {
            wp_send_json_error('Failed to submit booking');
        }
    }

    private function send_booking_notification_email($name, $email, $phone, $type, $date, $slot_ids, $ticket_count, $total, $transaction_id, $payment_image, $notes) {
        global $wpdb;
        
        $admin_email = 'knowledgehub@brac.net';
        $subject = 'New Booking Request - ' . $type->type_name;
        
        $message = "A new booking request has been submitted.\n\n";
        $message .= "Customer Details:\n";
        $message .= "Name: $name\n";
        $message .= "Email: $email\n";
        $message .= "Phone: $phone\n\n";
        $message .= "Booking Details:\n";
        $message .= "Booking Type: {$type->type_name}\n";
        $message .= "Date: " . date('F j, Y', strtotime($date)) . "\n";
        
        if ($type->type_category === 'hall' && $slot_ids) {
            $slot_list = '';
            $slot_id_arr = array_map('intval', explode(',', $slot_ids));
            $slots = $wpdb->get_results(
                "SELECT slot_name, start_time, end_time, price FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_id_arr) . ")"
            );
            foreach ($slots as $slot) {
                $slot_list .= sprintf(
                    "- %s (%s - %s) - BDT %s\n",
                    $slot->slot_name,
                    date('g:i A', strtotime($slot->start_time)),
                    date('g:i A', strtotime($slot->end_time)),
                    number_format($slot->price, 2)
                );
            }
            $message .= "Slots:\n$slot_list\n";
        } elseif ($type->type_category === 'event_tour') {
            $message .= "Tour Time: " . date('g:i A', strtotime($type->tour_start_time)) . " - " . date('g:i A', strtotime($type->tour_end_time)) . "\n\n";
        } elseif ($type->type_category === 'individual_tour') {
            $message .= "Tour Time: " . date('g:i A', strtotime($type->tour_start_time)) . " - " . date('g:i A', strtotime($type->tour_end_time)) . "\n";
            $message .= "Number of Tickets: $ticket_count\n\n";
        }
        
        $message .= "Total Price: BDT " . number_format($total, 2) . "\n\n";
        $message .= "Payment Information:\n";
        $message .= "Transaction ID: " . ($transaction_id ?: 'Not provided') . "\n";
        $message .= "Payment Screenshot: " . ($payment_image ?: 'Not provided') . "\n\n";
        if ($notes) {
            $message .= "Additional Notes:\n$notes\n";
        }
        
        $headers = array(
            'From: Knowledge Hub <knowledgehub@brac.net>',
            'Reply-To: ' . $email,
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($admin_email, $subject, $message, $headers);
    }

    private function send_confirmation_email($customer_email, $customer_name, $type, $date, $slot_ids = '', $ticket_count = 1) {
        global $wpdb;
        
        $subject = 'Booking Confirmed - ' . $type->type_name;
        
        $message = "Dear $customer_name,\n\n";
        $message .= "Great news! Your booking has been confirmed.\n\n";
        $message .= "Booking Details:\n";
        $message .= "Booking Type: {$type->type_name}\n";
        $message .= "Date: " . date('F j, Y', strtotime($date)) . "\n";
        
        if ($type->type_category === 'hall' && $slot_ids) {
            $slot_id_arr = array_map('intval', explode(',', $slot_ids));
            $slots = $wpdb->get_results(
                "SELECT slot_name, start_time, end_time FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_id_arr) . ")"
            );
            $message .= "Time Slots:\n";
            foreach ($slots as $slot) {
                $message .= sprintf(
                    "- %s (%s - %s)\n",
                    $slot->slot_name,
                    date('g:i A', strtotime($slot->start_time)),
                    date('g:i A', strtotime($slot->end_time))
                );
            }
        } else {
            $message .= "Tour Time: " . date('g:i A', strtotime($type->tour_start_time)) . " - " . date('g:i A', strtotime($type->tour_end_time)) . "\n";
            if ($type->type_category === 'individual_tour') {
                $message .= "Number of Tickets: $ticket_count\n";
            }
        }
        
        $message .= "\nWe look forward to seeing you!\n\n";
        $message .= "Best regards,\nKnowledge Hub Team";
        
        $headers = array(
            'From: Knowledge Hub <knowledgehub@brac.net>',
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($customer_email, $subject, $message, $headers);
    }

    public function get_bookings() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $offset = ($page - 1) * $this->items_per_page;

        $where = "1=1";
        $params = array();
        
        if ($type_id > 0) {
            $where .= " AND b.booking_type_id = %d";
            $params[] = $type_id;
        }
        if (!empty($status)) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings b WHERE $where";
        $total = empty($params) ? $wpdb->get_var($count_sql) : $wpdb->get_var($wpdb->prepare($count_sql, $params));
        
        $sql = "SELECT b.*, t.type_name, t.type_category FROM {$wpdb->prefix}bt_bookings b 
                LEFT JOIN {$wpdb->prefix}bt_booking_types t ON b.booking_type_id = t.id 
                WHERE $where ORDER BY b.created_at DESC LIMIT %d OFFSET %d";
        
        $params[] = $this->items_per_page;
        $params[] = $offset;
        
        $bookings = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Get slot names for hall bookings
        foreach ($bookings as &$booking) {
            if ($booking->type_category === 'hall' && $booking->slot_ids) {
                $slot_ids = array_map('intval', explode(',', $booking->slot_ids));
                if (!empty($slot_ids)) {
                    $slots = $wpdb->get_results(
                        "SELECT slot_name, start_time, end_time FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_ids) . ")"
                    );
                    $booking->slot_details = $slots;
                }
            }
        }
        
        wp_send_json_success(array(
            'bookings' => $bookings,
            'total' => intval($total),
            'pages' => ceil($total / $this->items_per_page),
            'currentPage' => $page
        ));
    }

    public function update_booking_status() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $booking_id = intval($_POST['booking_id']);
        $status = sanitize_text_field($_POST['status']);

        if (!in_array($status, array('approved', 'rejected', 'pending'))) {
            wp_send_json_error('Invalid status');
        }

        // If rejecting, delete the booking
        if ($status === 'rejected') {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT payment_image FROM {$wpdb->prefix}bt_bookings WHERE id = %d",
                $booking_id
            ));
            
            if ($booking && $booking->payment_image) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $booking->payment_image);
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            
            $wpdb->delete(
                $wpdb->prefix . 'bt_bookings',
                array('id' => $booking_id),
                array('%d')
            );
            wp_send_json_success('Booking rejected and deleted');
        } else {
            // If approving, send confirmation email
            if ($status === 'approved') {
                $booking = $wpdb->get_row($wpdb->prepare(
                    "SELECT b.*, t.type_name, t.type_category, t.tour_start_time, t.tour_end_time 
                     FROM {$wpdb->prefix}bt_bookings b 
                     LEFT JOIN {$wpdb->prefix}bt_booking_types t ON b.booking_type_id = t.id 
                     WHERE b.id = %d",
                    $booking_id
                ));
                
                if ($booking) {
                    $type = (object) array(
                        'type_name' => $booking->type_name,
                        'type_category' => $booking->type_category,
                        'tour_start_time' => $booking->tour_start_time,
                        'tour_end_time' => $booking->tour_end_time
                    );
                    
                    $this->send_confirmation_email(
                        $booking->customer_email,
                        $booking->customer_name,
                        $type,
                        $booking->booking_date,
                        $booking->slot_ids,
                        $booking->ticket_count
                    );
                }
            }
            
            $wpdb->update(
                $wpdb->prefix . 'bt_bookings',
                array('status' => $status),
                array('id' => $booking_id),
                array('%s'),
                array('%d')
            );
            wp_send_json_success('Status updated successfully');
        }
    }

    public function delete_booking() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $booking_id = intval($_POST['booking_id']);
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_image FROM {$wpdb->prefix}bt_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($booking && $booking->payment_image) {
            $upload_dir = wp_upload_dir();
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $booking->payment_image);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        $wpdb->delete(
            $wpdb->prefix . 'bt_bookings',
            array('id' => $booking_id),
            array('%d')
        );
        
        wp_send_json_success('Booking deleted');
    }

    // Shortcode: Combined Booking (Hall + Tours)
    public function render_book_tour_shortcode($atts) {
        global $wpdb;
        $hall_type = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'hall'");
        $event_type = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'event_tour'");
        $individual_type = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bt_booking_types WHERE type_category = 'individual_tour'");

        if (!$hall_type || !$event_type || !$individual_type) {
            return '<p>Booking system is not configured properly.</p>';
        }

        ob_start();
        ?>
        <div class="bt-booking-container" data-mode="merged">
            <div class="bt-header">
                <div class="bt-header-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>
                <h2 class="bt-title">Book Your Visit</h2>
                <p class="bt-subtitle">Choose a booking type, select a date, and complete your request</p>
            </div>

            <div class="bt-tour-selector bt-tour-selector-merged">
                <button class="bt-tour-btn active" data-type-id="<?php echo esc_attr($hall_type->id); ?>" data-category="hall">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>Multipurpose Hall</span>
                </button>
                <button class="bt-tour-btn" data-type-id="<?php echo esc_attr($individual_type->id); ?>" data-category="individual_tour">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span>Individual Booking</span>
                </button>
                <button class="bt-tour-btn" data-type-id="<?php echo esc_attr($event_type->id); ?>" data-category="event_tour">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span>Event Booking</span>
                </button>
            </div>

            <input type="hidden" id="bt-type-id" value="<?php echo esc_attr($hall_type->id); ?>">
            <input type="hidden" id="bt-type-category" value="hall">
            <input type="hidden" id="bt-hall-type-id" value="<?php echo esc_attr($hall_type->id); ?>">
            <input type="hidden" id="bt-event-type-id" value="<?php echo esc_attr($event_type->id); ?>">
            <input type="hidden" id="bt-individual-type-id" value="<?php echo esc_attr($individual_type->id); ?>">

            <div class="bt-main-grid bt-merged-grid">
                <div class="bt-calendar-section">
                    <div class="bt-section-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <span class="bt-section-title">Select Date</span>
                    </div>
                    <div class="bt-calendar-header">
                        <button class="bt-nav-btn" id="bt-prev-month" type="button">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                        </button>
                        <span id="bt-month-year"></span>
                        <button class="bt-nav-btn" id="bt-next-month" type="button">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </button>
                    </div>
                    <div class="bt-calendar-weekdays">
                        <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                    </div>
                    <div class="bt-calendar-days" id="bt-calendar-days"></div>
                </div>

                <div class="bt-slots-section">
                    <div class="bt-section-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span class="bt-section-title">Available Slots</span>
                    </div>
                    <div class="bt-slots-container" id="bt-slots-container">
                        <div class="bt-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <p>Select a date to view available slots</p>
                        </div>
                    </div>
                </div>

                <div class="bt-tour-info-section" style="display: none;">
                    <div class="bt-section-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <span class="bt-section-title">Tour Information</span>
                    </div>
                    <div class="bt-tour-details" id="bt-tour-details">
                        <div class="bt-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <p>Select a date to view tour details</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php echo $this->render_booking_form(true); ?>
        </div>
        
        <div class="bt-toast" id="bt-toast"></div>
        <?php
        return ob_get_clean();
    }

    private function render_booking_form($is_tour = false) {
        ob_start();
        ?>
        <form id="bt-booking-form" enctype="multipart/form-data">
            <input type="hidden" id="bt-selected-date" value="">
            <input type="hidden" id="bt-selected-slots" value="">
            <input type="hidden" id="bt-total-price" value="0">
            <input type="hidden" id="bt-ticket-count" value="1">
            
            <div class="bt-summary" id="bt-summary" style="display: none;">
                <div class="bt-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <span class="bt-section-title">Booking Summary</span>
                </div>
                <div id="bt-summary-content"></div>
                <div class="bt-summary-total" id="bt-summary-total"></div>
                
                <div class="bt-terms-section">
                    <a href="http://35.240.207.116/knowledgehub/wordpress/?page_id=1031" target="_blank" class="bt-terms-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                        </svg>
                        Terms & Conditions
                    </a>
                    <a href="http://35.240.207.116/knowledgehub/wordpress/?page_id=1607" target="_blank" class="bt-terms-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        Payment Rules & Regulations
                    </a>
                </div>
            </div>

            <div class="bt-form-section" id="bt-form-section" style="display: none;">
                <div class="bt-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span class="bt-section-title">Your Information</span>
                </div>
                
                <div class="bt-form-grid">
                    <div class="bt-form-group">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            Full Name <span class="required">*</span>
                        </label>
                        <input type="text" id="bt-name" placeholder="Enter your full name" required>
                    </div>
                    <div class="bt-form-group">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                            Phone Number <span class="required">*</span>
                        </label>
                        <input type="tel" id="bt-phone" placeholder="Enter your phone number" required>
                    </div>
                    <div class="bt-form-group bt-full">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                <polyline points="22,6 12,13 2,6"></polyline>
                            </svg>
                            Email Address <span class="required">*</span>
                        </label>
                        <input type="email" id="bt-email" placeholder="Enter your email address" required>
                    </div>
                </div>
                
                <div class="bt-section-header bt-payment-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                    <span class="bt-section-title">Payment Information</span>
                </div>
                <p class="bt-payment-hint">Provide either Transaction ID or upload payment screenshot</p>
                
                <div class="bt-form-grid">
                    <div class="bt-form-group">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <line x1="4" y1="9" x2="20" y2="9"></line>
                                <line x1="4" y1="15" x2="20" y2="15"></line>
                                <line x1="10" y1="3" x2="8" y2="21"></line>
                                <line x1="16" y1="3" x2="14" y2="21"></line>
                            </svg>
                            Transaction ID / Billing Code
                        </label>
                        <input type="text" id="bt-transaction-id" placeholder="Enter transaction ID">
                    </div>
                    <div class="bt-form-group">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            Payment Screenshot
                        </label>
                        <div class="bt-file-upload">
                            <input type="file" id="bt-payment-image" accept="image/*">
                            <div class="bt-file-label">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="17 8 12 3 7 8"></polyline>
                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                </svg>
                                <span>Choose file (max 1MB)</span>
                            </div>
                        </div>
                    </div>
                    <div class="bt-form-group bt-full">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                            Additional Notes
                        </label>
                        <textarea id="bt-notes" rows="2" placeholder="Any special requests or notes"></textarea>
                    </div>
                </div>
                
                <button type="submit" class="bt-submit-btn" id="bt-submit-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    Submit Booking Request
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }
}

new BookingTour();
