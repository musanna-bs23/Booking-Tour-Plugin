<?php
/**
 * Plugin Name: Booking Tour
 * Description: A comprehensive booking system for Multipurpose Hall and Knowledge Hub Tours
 * Version: 1.1.0
 * Author: Hasan Al Musanna
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/db-schema.php';

class BookingTour {
    
    private $items_per_page = 10;
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        $this->ensure_default_types();
        $this->ensure_event_hours_column();
        $this->ensure_booking_cluster_hours_column();
        $this->ensure_booking_cluster_time_ranges_column();
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
        add_action('wp_ajax_bt_save_cluster_times', array($this, 'save_cluster_times'));
        add_action('wp_ajax_bt_delete_booking', array($this, 'delete_booking'));
        add_action('wp_ajax_bt_get_booking_data', array($this, 'get_booking_data'));
        add_action('wp_ajax_nopriv_bt_get_booking_data', array($this, 'get_booking_data'));
        add_action('wp_ajax_bt_generate_report', array($this, 'generate_report'));
        add_action('admin_post_bt_save_hide_tour', array($this, 'save_hide_tour'));
        
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

        // Add-ons management
        add_action('wp_ajax_bt_get_addons', array($this, 'get_addons'));
        add_action('wp_ajax_bt_save_addon', array($this, 'save_addon'));
        add_action('wp_ajax_bt_update_addon', array($this, 'update_addon'));
        add_action('wp_ajax_bt_delete_addon', array($this, 'delete_addon'));
    }

    public function activate() {
        bt_create_schema();

        $upload_dir = wp_upload_dir();
        $bt_dir = $upload_dir['basedir'] . '/booking-tour-payments';
        if (!file_exists($bt_dir)) {
            wp_mkdir_p($bt_dir);
        }
        $this->ensure_default_types();
        $this->ensure_event_hours_column();
        $this->ensure_booking_cluster_hours_column();
        $this->ensure_booking_cluster_time_ranges_column();
    }

    private function ensure_default_types() {
        global $wpdb;
        $types = array(
            array(
                'name' => 'Multipurpose Hall',
                'slug' => 'multipurpose-hall',
                'category' => 'hall'
            ),
            array(
                'name' => 'Staircase Book',
                'slug' => 'staircase-book',
                'category' => 'staircase'
            ),
            array(
                'name' => 'Individual Tour',
                'slug' => 'knowledge-hub-individual',
                'category' => 'individual_tour'
            ),
            array(
                'name' => 'Guide Tour',
                'slug' => 'knowledge-hub-event',
                'category' => 'event_tour'
            )
        );

        foreach ($types as $type) {
            $table = $this->get_type_table_by_category($type['category']);
            if (!$table) {
                continue;
            }
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT type_id FROM {$table} WHERE type_slug = %s LIMIT 1",
                $type['slug']
            ));
            if ($exists) {
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'bt_booking_types',
                array('name' => $type['name']),
                array('%s')
            );
            $type_id = intval($wpdb->insert_id);
            if ($type_id <= 0) {
                continue;
            }

            $data = array(
                'type_id' => $type_id,
                'type_slug' => $type['slug'],
                'type_category' => $type['category'],
                'weekend_days' => '',
                'is_hidden' => 0
            );
            $format = array('%d', '%s', '%s', '%s', '%d');

            if ($type['category'] === 'individual_tour') {
                $data['tour_start_time'] = '09:00:00';
                $data['tour_end_time'] = '17:00:00';
                $data['max_tickets'] = 50;
                $data['ticket_price'] = 0;
                $data['booking_window_mode'] = 'limit';
                $data['booking_window_days'] = 1;
                $format = array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%d');
            }

            if ($type['category'] === 'event_tour') {
                $data['tour_start_time'] = '09:00:00';
                $data['tour_end_time'] = '17:00:00';
                $data['max_clusters'] = 0;
                $data['members_per_cluster'] = 1;
                $data['price_per_cluster'] = 0;
                $data['max_hours_per_cluster'] = 1;
                $format = array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%f', '%d');
            }

            $wpdb->insert($table, $data, $format);
        }
    }

    private function get_type_table_by_category($category) {
        global $wpdb;
        switch ($category) {
            case 'hall':
                return $wpdb->prefix . 'bt_hall_types';
            case 'staircase':
                return $wpdb->prefix . 'bt_staircase_types';
            case 'individual_tour':
                return $wpdb->prefix . 'bt_individual_tour_types';
            case 'event_tour':
                return $wpdb->prefix . 'bt_event_tour_types';
            default:
                return '';
        }
    }

    private function ensure_event_hours_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'bt_event_tour_types';
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'max_hours_per_cluster'
        ));
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN max_hours_per_cluster INT NOT NULL DEFAULT 1");
        }
    }

    private function ensure_booking_cluster_hours_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'bt_bookings';
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'cluster_hours'
        ));
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN cluster_hours TEXT AFTER slot_ids");
        }
    }

    private function ensure_booking_cluster_time_ranges_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'bt_bookings';
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'cluster_time_ranges'
        ));
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN cluster_time_ranges TEXT AFTER cluster_hours");
        }
    }

    private function get_types_select_sql() {
        global $wpdb;
        $types = $wpdb->prefix . 'bt_booking_types';
        $hall = $wpdb->prefix . 'bt_hall_types';
        $stair = $wpdb->prefix . 'bt_staircase_types';
        $individual = $wpdb->prefix . 'bt_individual_tour_types';
        $event = $wpdb->prefix . 'bt_event_tour_types';

        return "SELECT
                t.id,
                t.name AS type_name,
                COALESCE(h.type_slug, s.type_slug, i.type_slug, e.type_slug) AS type_slug,
                COALESCE(h.type_category, s.type_category, i.type_category, e.type_category) AS type_category,
                COALESCE(h.weekend_days, s.weekend_days, i.weekend_days, e.weekend_days) AS weekend_days,
                COALESCE(h.is_hidden, s.is_hidden, i.is_hidden, e.is_hidden) AS is_hidden,
                COALESCE(i.tour_start_time, e.tour_start_time) AS tour_start_time,
                COALESCE(i.tour_end_time, e.tour_end_time) AS tour_end_time,
                i.max_tickets AS max_daily_capacity,
                i.ticket_price AS ticket_price,
                i.booking_window_mode AS booking_window_mode,
                i.booking_window_days AS booking_window_days,
                e.max_clusters AS event_max_clusters,
                e.members_per_cluster AS event_members_per_cluster,
                e.price_per_cluster AS event_cluster_price,
                e.max_hours_per_cluster AS event_max_hours_per_cluster
            FROM {$types} t
            LEFT JOIN {$hall} h ON h.type_id = t.id
            LEFT JOIN {$stair} s ON s.type_id = t.id
            LEFT JOIN {$individual} i ON i.type_id = t.id
            LEFT JOIN {$event} e ON e.type_id = t.id";
    }

    private function get_all_types() {
        global $wpdb;
        $sql = $this->get_types_select_sql() . " ORDER BY t.id";
        return $wpdb->get_results($sql);
    }

    private function get_type_by_id($type_id) {
        global $wpdb;
        $sql = $this->get_types_select_sql() . " WHERE t.id = %d";
        return $wpdb->get_row($wpdb->prepare($sql, $type_id));
    }

    private function get_type_by_slug($slug) {
        global $wpdb;
        $sql = $this->get_types_select_sql() .
            " WHERE h.type_slug = %s OR s.type_slug = %s OR i.type_slug = %s OR e.type_slug = %s";
        return $wpdb->get_row($wpdb->prepare($sql, $slug, $slug, $slug, $slug));
    }

    private function get_type_by_category($category) {
        global $wpdb;
        $sql = $this->get_types_select_sql() .
            " WHERE h.type_category = %s OR s.type_category = %s OR i.type_category = %s OR e.type_category = %s";
        return $wpdb->get_row($wpdb->prepare($sql, $category, $category, $category, $category));
    }

    private function get_type_joins_sql() {
        global $wpdb;
        $types = $wpdb->prefix . 'bt_booking_types';
        $hall = $wpdb->prefix . 'bt_hall_types';
        $stair = $wpdb->prefix . 'bt_staircase_types';
        $individual = $wpdb->prefix . 'bt_individual_tour_types';
        $event = $wpdb->prefix . 'bt_event_tour_types';

        return "LEFT JOIN {$types} t ON b.booking_type_id = t.id
            LEFT JOIN {$hall} h ON h.type_id = t.id
            LEFT JOIN {$stair} s ON s.type_id = t.id
            LEFT JOIN {$individual} i ON i.type_id = t.id
            LEFT JOIN {$event} e ON e.type_id = t.id";
    }

    private function get_type_category_sql() {
        return "COALESCE(h.type_category, s.type_category, i.type_category, e.type_category)";
    }

    private function time_string_to_minutes($time_string) {
        $parts = explode(':', $time_string);
        $hours = isset($parts[0]) ? intval($parts[0]) : 0;
        $minutes = isset($parts[1]) ? intval($parts[1]) : 0;
        return ($hours * 60) + $minutes;
    }

    private function minutes_to_time_string($minutes) {
        $minutes = max(0, min((24 * 60) - 1, intval($minutes)));
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    private function build_default_cluster_time_ranges($tour_start_time, $tour_end_time, $cluster_hours) {
        $start_minutes = $this->time_string_to_minutes($tour_start_time);
        $end_minutes = $this->time_string_to_minutes($tour_end_time);
        if ($end_minutes <= $start_minutes) {
            $end_minutes = $start_minutes + (24 * 60);
        }
        $cursor = $start_minutes;
        $ranges = array();
        foreach ($cluster_hours as $hours) {
            $duration = max(1, intval($hours)) * 60;
            $cluster_start = $cursor;
            $cluster_end = min($cursor + $duration, $end_minutes);
            $ranges[] = array(
                'start' => $this->minutes_to_time_string($cluster_start % (24 * 60)),
                'end' => $this->minutes_to_time_string($cluster_end % (24 * 60))
            );
            $cursor = $cluster_end;
        }
        return $ranges;
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

        $types = $this->get_all_types();
        
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
        $types = $this->get_all_types();
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
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="bt-filter-group">
                    <svg class="bt-filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <input type="date" id="bt-filter-start-date" placeholder="Start date">
                </div>
                <div class="bt-filter-group">
                    <svg class="bt-filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <input type="date" id="bt-filter-end-date" placeholder="End date">
                </div>
                <button class="button bt-filter-btn" id="bt-filter-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    Filter
                </button>
                <button class="button bt-filter-btn" id="bt-report-doc">
                    Generate Report (DOC)
                </button>
                <button class="button bt-filter-btn" id="bt-report-pdf">
                    Generate Report (PDF)
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
        
        $type = $this->get_type_by_slug($type_slug);

        if (!$type) {
            echo '<div class="wrap"><h1>Booking type not found</h1></div>';
            return;
        }
        ?>
        <div class="wrap bt-admin-wrap">
            <?php if (isset($_GET['bt_hide_updated']) && $_GET['bt_hide_updated'] === '1'): ?>
                <div class="bt-message bt-message-success bt-hide-tour-message">Settings saved successfully!</div>
            <?php endif; ?>
            <h1>
                <svg class="bt-icon-title" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <?php if ($type->type_category === 'hall' || $type->type_category === 'staircase'): ?>
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

            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    Hide Tour
                </h2>
                <form id="bt-hide-tour-form" class="bt-hide-tour-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bt_hide_tour_save', 'bt_hide_tour_nonce'); ?>
                    <input type="hidden" name="action" value="bt_save_hide_tour">
                    <input type="hidden" name="type_id" value="<?php echo esc_attr($type->id); ?>">
                    <div class="bt-input-row">
                        <div class="bt-input-group">
                            <label>Hide this tour?</label>
                            <select id="bt-hide-tour" name="hide_tour">
                                <option value="0" <?php selected(intval($type->is_hidden), 0); ?>>No</option>
                                <option value="1" <?php selected(intval($type->is_hidden), 1); ?>>Yes</option>
                            </select>
                        </div>
                    </div>
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
            
            <?php if ($type->type_category === 'hall' || $type->type_category === 'staircase'): ?>
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

        <?php if ($type->type_category === 'hall'): ?>
        <!-- Add-ons Management -->
        <div class="bt-settings-card">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M20 12H4"></path>
                    <path d="M14 6h6v6"></path>
                    <path d="M10 18H4v-6"></path>
                </svg>
                Add-ons Management
            </h2>
            <p class="bt-hint">Create add-ons users can select with their Hall booking.</p>

            <div class="bt-input-row bt-addons-row-admin">
                <div class="bt-input-group">
                    <label>Add-on Name</label>
                    <input type="text" id="bt-addon-name" placeholder="e.g., Microphone">
                </div>
                <div class="bt-input-group">
                    <label>Price (BDT)</label>
                    <input type="number" id="bt-addon-price" placeholder="0.00" min="0" step="0.01">
                </div>
                <div class="bt-input-group">
                    <label>Max Quantity</label>
                    <input type="number" id="bt-addon-max" placeholder="0" min="0" step="1">
                </div>
                <div class="bt-input-group">
                    <label>&nbsp;</label>
                    <button class="button button-primary bt-add-addon-btn" id="bt-add-addon" data-type-id="<?php echo esc_attr($type->id); ?>">
                        Add Add-on
                    </button>
                </div>
            </div>

            <table class="bt-slots-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Max Qty</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody id="bt-addons-body" data-type-id="<?php echo esc_attr($type->id); ?>"></tbody>
            </table>
        </div>
        <?php endif; ?>
            
            <?php elseif ($type->type_category === 'event_tour'): ?>
            <!-- Event Tour Settings -->
            <?php
                $event_max_clusters = intval($type->event_max_clusters);
                $event_members_per_cluster = intval($type->event_members_per_cluster);
                $event_cluster_price = floatval($type->event_cluster_price);
                $event_max_hours_per_cluster = max(1, intval($type->event_max_hours_per_cluster));
            ?>
            <div class="bt-settings-card">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    Guide Tour Settings
                </h2>
                <p class="bt-hint">Set the tour timing and cluster availability for guide bookings</p>
                
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
                            <label>Max Clusters</label>
                            <input type="number" id="bt-event-max-clusters" value="<?php echo esc_attr($event_max_clusters); ?>" min="0">
                        </div>
                        <div class="bt-input-group">
                            <label>Members per Cluster</label>
                            <input type="number" id="bt-event-members-per-cluster" value="<?php echo esc_attr($event_members_per_cluster); ?>" min="1">
                        </div>
                        <div class="bt-input-group">
                            <label>Price per Cluster per Hour (BDT)</label>
                            <input type="number" id="bt-event-cluster-price" value="<?php echo esc_attr($event_cluster_price); ?>" min="0" step="0.01">
                        </div>
                        <div class="bt-input-group">
                            <label>Max Hours per Cluster</label>
                            <input type="number" id="bt-event-max-hours-per-cluster" value="<?php echo esc_attr($event_max_hours_per_cluster); ?>" min="1" readonly>
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
            <?php
                $booking_window_mode = $type->booking_window_mode ?: 'limit';
                $booking_window_days = intval($type->booking_window_days);
                if ($booking_window_days < 0) $booking_window_days = 0;
            ?>
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
                    <div class="bt-input-row">
                        <div class="bt-input-group">
                            <label>Booking Window</label>
                            <select id="bt-booking-window-mode">
                                <option value="none" <?php selected($booking_window_mode, 'none'); ?>>No limit (book any future date)</option>
                                <option value="limit" <?php selected($booking_window_mode, 'limit'); ?>>Limit to X days ahead</option>
                            </select>
                        </div>
                        <div class="bt-input-group">
                            <label>Days Ahead (X)</label>
                            <input type="number" id="bt-booking-window-days" value="<?php echo esc_attr($booking_window_days); ?>" min="0">
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
        $type = $this->get_type_by_id($type_id);
        if (!$type) {
            wp_send_json_error('Invalid booking type');
        }
        $table = $this->get_type_table_by_category($type->type_category);
        if (empty($table)) {
            wp_send_json_error('Invalid booking type');
        }
        
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

        if (isset($_POST['hide_tour'])) {
            $data['is_hidden'] = intval($_POST['hide_tour']) ? 1 : 0;
            $format[] = '%d';
        }
        
        // Tour settings
        if (isset($_POST['tour_start_time'])) {
            if ($type->type_category === 'individual_tour' || $type->type_category === 'event_tour') {
                $data['tour_start_time'] = sanitize_text_field($_POST['tour_start_time']);
                $format[] = '%s';
            }
        }
        if (isset($_POST['tour_end_time'])) {
            if ($type->type_category === 'individual_tour' || $type->type_category === 'event_tour') {
                $data['tour_end_time'] = sanitize_text_field($_POST['tour_end_time']);
                $format[] = '%s';
            }
        }
        if (isset($_POST['max_daily_capacity'])) {
            if ($type->type_category === 'individual_tour') {
                $data['max_tickets'] = intval($_POST['max_daily_capacity']);
                $format[] = '%d';
            }
        }
        if (isset($_POST['ticket_price'])) {
            if ($type->type_category === 'individual_tour') {
                $data['ticket_price'] = floatval($_POST['ticket_price']);
                $format[] = '%f';
            }
        }
        if (isset($_POST['event_max_clusters'])) {
            if ($type->type_category === 'event_tour') {
                $data['max_clusters'] = max(0, intval($_POST['event_max_clusters']));
                $format[] = '%d';
            }
        }
        if (isset($_POST['event_members_per_cluster'])) {
            if ($type->type_category === 'event_tour') {
                $data['members_per_cluster'] = max(1, intval($_POST['event_members_per_cluster']));
                $format[] = '%d';
            }
        }
        if (isset($_POST['event_cluster_price'])) {
            if ($type->type_category === 'event_tour') {
                $data['price_per_cluster'] = floatval($_POST['event_cluster_price']);
                $format[] = '%f';
            }
        }
        if ($type->type_category === 'event_tour' && isset($_POST['tour_start_time']) && isset($_POST['tour_end_time'])) {
            $start_time = sanitize_text_field($_POST['tour_start_time']);
            $end_time = sanitize_text_field($_POST['tour_end_time']);
            $start_minutes = $this->time_string_to_minutes($start_time);
            $end_minutes = $this->time_string_to_minutes($end_time);
            $duration_minutes = $end_minutes - $start_minutes;
            if ($duration_minutes < 0) {
                $duration_minutes += (24 * 60);
            }
            $max_hours = max(1, (int) ceil($duration_minutes / 60));
            $data['max_hours_per_cluster'] = $max_hours;
            $format[] = '%d';
        }

        if (isset($_POST['booking_window_mode'])) {
            if ($type->type_category === 'individual_tour') {
                $mode = sanitize_text_field($_POST['booking_window_mode']);
                if ($mode !== 'none') $mode = 'limit';
                $data['booking_window_mode'] = $mode;
                $format[] = '%s';
            }
        }
        if (isset($_POST['booking_window_days'])) {
            if ($type->type_category === 'individual_tour') {
                $days = max(0, intval($_POST['booking_window_days']));
                $data['booking_window_days'] = $days;
                $format[] = '%d';
            }
        }

        if (!empty($data)) {
            $wpdb->update(
                $table,
                $data,
                array('type_id' => $type_id),
                $format,
                array('%d')
            );
        }

        wp_send_json_success('Settings saved successfully');
    }

    public function save_hide_tour() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bt_hide_tour_save', 'bt_hide_tour_nonce');

        $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
        $hide_tour = isset($_POST['hide_tour']) ? intval($_POST['hide_tour']) : 0;

        $type = $this->get_type_by_id($type_id);
        if (!$type) {
            wp_die('Invalid booking type');
        }
        $table = $this->get_type_table_by_category($type->type_category);
        if (empty($table)) {
            wp_die('Invalid booking type');
        }

        global $wpdb;
        $wpdb->update(
            $table,
            array('is_hidden' => $hide_tour ? 1 : 0),
            array('type_id' => $type_id),
            array('%d'),
            array('%d')
        );

        $redirect = admin_url('admin.php?page=booking-tour-' . $type->type_slug);
        $redirect = add_query_arg('bt_hide_updated', '1', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public function get_addons() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $addons = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = %d ORDER BY id DESC",
            $type_id
        ));
        wp_send_json_success($addons);
    }

    public function save_addon() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $name = sanitize_text_field($_POST['name']);
        $price = floatval($_POST['price']);
        $max_quantity = intval($_POST['max_quantity']);
        if (empty($name)) {
            wp_send_json_error('Name is required');
        }
        $wpdb->insert(
            $wpdb->prefix . 'bt_addons',
            array(
                'booking_type_id' => $type_id,
                'name' => $name,
                'price' => $price,
                'max_quantity' => $max_quantity
            ),
            array('%d', '%s', '%f', '%d')
        );
        wp_send_json_success('Add-on saved');
    }

    public function update_addon() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $addon_id = intval($_POST['addon_id']);
        $name = sanitize_text_field($_POST['name']);
        $price = floatval($_POST['price']);
        $max_quantity = intval($_POST['max_quantity']);
        if (empty($name)) {
            wp_send_json_error('Name is required');
        }
        $wpdb->update(
            $wpdb->prefix . 'bt_addons',
            array('name' => $name, 'price' => $price, 'max_quantity' => $max_quantity),
            array('id' => $addon_id),
            array('%s', '%f', '%d'),
            array('%d')
        );
        wp_send_json_success('Add-on updated');
    }

    public function delete_addon() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $addon_id = intval($_POST['addon_id']);
        $wpdb->delete($wpdb->prefix . 'bt_addons', array('id' => $addon_id), array('%d'));
        wp_send_json_success('Add-on deleted');
    }

    public function get_booking_data() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        
        $type = $this->get_type_by_id($type_id);

        // Get slots (hall and staircase)
        $slots = array();
        if ($type && ($type->type_category === 'hall' || $type->type_category === 'staircase')) {
            $slots = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bt_slots WHERE booking_type_id = %d ORDER BY start_time",
                $type_id
            ));
        }
        $addons = array();
        if ($type && $type->type_category === 'hall') {
            $addons = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = %d ORDER BY id ASC",
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
        $eventClustersByDate = array();
        
        foreach ($bookings as $booking) {
            $date = $booking->booking_date;
            
            if ($type->type_category === 'hall' || $type->type_category === 'staircase') {
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
                } elseif ($type->type_category === 'event_tour') {
                    if (!isset($eventClustersByDate[$date])) {
                        $eventClustersByDate[$date] = 0;
                    }
                    $eventClustersByDate[$date] += intval($booking->ticket_count);
                }
            }
        }

        // Also get event tour bookings to check for date conflicts
        $event_type = $this->get_type_by_category('event_tour');
        $individual_type = $this->get_type_by_category('individual_tour');
        
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

        $fullyBookedDates = array();
        if ($type && ($type->type_category === 'hall' || $type->type_category === 'staircase')) {
            $fullyBookedDates = $this->get_fully_booked_dates($type_id);
        }

        wp_send_json_success(array(
            'type' => $type,
            'slots' => $slots,
            'addons' => $addons,
            'holidays' => $holidays,
            'bookedSlots' => $bookedSlots,
            'fullyBookedDates' => $fullyBookedDates,
            'bookedDates' => $bookedDates,
            'ticketsByDate' => $ticketsByDate,
            'eventClustersByDate' => $eventClustersByDate,
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
        
        $type = $this->get_type_by_id($type_id);
        
        // Get bookings for this date
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT slot_ids, ticket_count FROM {$wpdb->prefix}bt_bookings 
             WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
            $type_id, $date
        ));

        $bookedSlots = array();
        $totalTickets = 0;
        $addonsAvailability = array();
        $fullyBookedDates = array();
        
        foreach ($bookings as $booking) {
            if (($type->type_category === 'hall' || $type->type_category === 'staircase') && $booking->slot_ids) {
                $slot_ids = array_map('intval', explode(',', $booking->slot_ids));
                $bookedSlots = array_merge($bookedSlots, $slot_ids);
            }
            $totalTickets += intval($booking->ticket_count);
        }

        if ($type && ($type->type_category === 'hall' || $type->type_category === 'staircase')) {
            $fullyBookedDates = $this->get_fully_booked_dates($type_id);
        }

        if ($type && $type->type_category === 'hall' && !empty($date)) {
            $addons = $wpdb->get_results($wpdb->prepare(
                "SELECT id, max_quantity FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = %d",
                $type_id
            ));
            if ($addons) {
                $addon_ids = array_map(function($a) { return intval($a->id); }, $addons);
                $placeholders = implode(',', array_fill(0, count($addon_ids), '%d'));
                $used_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT ba.addon_id, SUM(ba.quantity) AS total_qty
                     FROM {$wpdb->prefix}bt_booking_addons ba
                     INNER JOIN {$wpdb->prefix}bt_bookings b ON b.id = ba.booking_id
                     WHERE b.booking_type_id = %d AND b.booking_date = %s AND b.status IN ('pending','approved')
                     AND ba.addon_id IN ($placeholders)
                     GROUP BY ba.addon_id",
                    array_merge(array($type_id, $date), $addon_ids)
                ));
                $used_map = array();
                foreach ($used_rows as $row) {
                    $used_map[intval($row->addon_id)] = intval($row->total_qty);
                }
                foreach ($addons as $addon) {
                    $used = isset($used_map[$addon->id]) ? $used_map[$addon->id] : 0;
                    $remaining = max(0, intval($addon->max_quantity) - $used);
                    $addonsAvailability[intval($addon->id)] = $remaining;
                }
            }
        }
        
        // Independent tour availability
        $dateBlockedByEvent = false;
        $dateBlockedByIndividual = false;
        $eventBlockedDates = array();
        $individualBlockedDates = array();
        $bookedDates = array();
        $ticketsByDate = array();
        $eventClustersByDate = array();

        if ($type && $type->type_category === 'event_tour') {
            $cluster_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT booking_date, SUM(ticket_count) AS total_clusters FROM {$wpdb->prefix}bt_bookings
                 WHERE booking_type_id = %d AND status IN ('pending', 'approved') AND booking_date >= CURDATE()
                 GROUP BY booking_date",
                $type_id
            ));
            foreach ($cluster_rows as $row) {
                $eventClustersByDate[$row->booking_date] = intval($row->total_clusters);
            }
        }

        if ($type && $type->type_category === 'individual_tour') {
            $ticket_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT booking_date, SUM(ticket_count) AS total_tickets FROM {$wpdb->prefix}bt_bookings
                 WHERE booking_type_id = %d AND status IN ('pending', 'approved') AND booking_date >= CURDATE()
                 GROUP BY booking_date",
                $type_id
            ));
            foreach ($ticket_rows as $row) {
                $ticketsByDate[$row->booking_date] = intval($row->total_tickets);
            }
        }

        wp_send_json_success(array(
            'bookedSlots' => array_values(array_unique($bookedSlots)),
            'totalTickets' => $totalTickets,
            'remainingCapacity' => $type ? (intval($type->max_daily_capacity) - $totalTickets) : 0,
            'addonsAvailability' => $addonsAvailability,
            'fullyBookedDates' => $fullyBookedDates,
            'dateBlockedByEvent' => $dateBlockedByEvent,
            'dateBlockedByIndividual' => $dateBlockedByIndividual,
            'eventBlockedDates' => $eventBlockedDates,
            'individualBlockedDates' => $individualBlockedDates,
            'bookedDates' => $bookedDates,
            'ticketsByDate' => $ticketsByDate,
            'eventClustersByDate' => $eventClustersByDate,
            'serverTime' => current_time('H:i'),
            'serverDate' => current_time('Y-m-d')
        ));
    }

    public function get_remaining_capacity() {
        global $wpdb;
        $type_id = intval($_POST['type_id']);
        $date = sanitize_text_field($_POST['date']);
        
        $type = $this->get_type_by_id($type_id);
        if (!$type) {
            wp_send_json_error('Invalid booking type');
        }
        
        $total_booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ticket_count), 0) FROM {$wpdb->prefix}bt_bookings 
             WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
            $type_id, $date
        ));
        
        $remaining = max(0, intval($type->max_daily_capacity) - $total_booked);
        
        wp_send_json_success(array(
            'remaining' => $remaining,
            'maxCapacity' => intval($type->max_daily_capacity),
            'booked' => intval($total_booked)
        ));
    }

    public function submit_booking() {
        global $wpdb;
        
        $type_id = intval($_POST['type_id']);
        $booking_date = sanitize_text_field($_POST['booking_date']);
        $slot_ids = isset($_POST['slot_ids']) ? sanitize_text_field($_POST['slot_ids']) : '';
        $ticket_count = isset($_POST['ticket_count']) ? intval($_POST['ticket_count']) : 1;
        $cluster_hours_json = isset($_POST['cluster_hours']) ? wp_unslash($_POST['cluster_hours']) : '';
        $addons_json = isset($_POST['addons']) ? wp_unslash($_POST['addons']) : '';
        $total_price = floatval($_POST['total_price']);
        $customer_name = sanitize_text_field($_POST['customer_name']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $customer_phone = sanitize_text_field($_POST['customer_phone']);
        $transaction_id = sanitize_text_field($_POST['transaction_id']);
        $notes = sanitize_textarea_field($_POST['notes']);
        $cluster_hours_for_save = '';
        $cluster_time_ranges_for_save = '';

        // Validate required fields
        if (empty($type_id) || empty($booking_date) || 
            empty($customer_name) || empty($customer_email) || empty($customer_phone)) {
            wp_send_json_error('Name, Email and Phone are required');
        }

        // Get booking type
        $type = $this->get_type_by_id($type_id);

        if (!$type) {
            wp_send_json_error('Invalid booking type');
        }

        // Validate slots for hall/staircase booking
        if (($type->type_category === 'hall' || $type->type_category === 'staircase') && empty($slot_ids)) {
            wp_send_json_error('Please select at least one slot');
        }

        if ($type->type_category === 'individual_tour') {
            $mode = $type->booking_window_mode ?: 'limit';
            $days = intval($type->booking_window_days);
            if ($mode === 'limit') {
                $today = current_time('Y-m-d');
                $max_date = date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'));
                if ($booking_date > $max_date) {
                    wp_send_json_error('Booking is only available up to ' . $days . ' day(s) in advance.');
                }
            }
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
        if ($type->type_category === 'hall' || $type->type_category === 'staircase') {
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
            if ($type->type_category === 'hall' && !empty($addons_json)) {
                $addons_payload = json_decode($addons_json, true);
                if (is_array($addons_payload) && !empty($addons_payload)) {
                    $availability = $this->get_addons_availability($type_id, $booking_date);
                    foreach ($addons_payload as $addon_id => $qty) {
                        $addon_id = intval($addon_id);
                        $qty = intval($qty);
                        if ($qty <= 0) continue;
                        $remaining = isset($availability[$addon_id]) ? intval($availability[$addon_id]) : 0;
                        if ($qty > $remaining) {
                            wp_send_json_error('Add-on availability changed. Please refresh and try again.');
                        }
                    }
                }
            }
            // Recalculate total price for hall/staircase based on slots + add-ons snapshot
            $slot_total = 0;
            if (!empty($slot_ids)) {
                $slot_id_arr = array_map('intval', explode(',', $slot_ids));
                if (!empty($slot_id_arr)) {
                    $slots = $wpdb->get_results(
                        "SELECT price FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_id_arr) . ")"
                    );
                    foreach ($slots as $slot) {
                        $slot_total += floatval($slot->price);
                    }
                }
            }
            $addons_total = 0;
            if ($type->type_category === 'hall' && !empty($addons_json)) {
                $addons_payload = json_decode($addons_json, true);
                if (is_array($addons_payload) && !empty($addons_payload)) {
                    $addon_ids = array_map('intval', array_keys($addons_payload));
                    if (!empty($addon_ids)) {
                        $addon_rows = $wpdb->get_results(
                            "SELECT id, price FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = " . intval($type_id) .
                            " AND id IN (" . implode(',', $addon_ids) . ")"
                        );
                        $price_map = array();
                        foreach ($addon_rows as $row) {
                            $price_map[intval($row->id)] = floatval($row->price);
                        }
                        foreach ($addons_payload as $addon_id => $qty) {
                            $addon_id = intval($addon_id);
                            $qty = intval($qty);
                            if ($qty > 0 && isset($price_map[$addon_id])) {
                                $addons_total += $price_map[$addon_id] * $qty;
                            }
                        }
                    }
                }
            }
            $total_price = $slot_total + $addons_total;
        } elseif ($type->type_category === 'event_tour') {
            $max_clusters = intval($type->event_max_clusters);
            $max_hours_per_cluster = max(1, intval($type->event_max_hours_per_cluster));
            $total_booked = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(ticket_count), 0) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $type_id, $booking_date
            ));
            if (($total_booked + $ticket_count) > $max_clusters) {
                wp_send_json_error('Not enough clusters available. Only ' . max(0, ($max_clusters - $total_booked)) . ' remaining.');
            }
            $cluster_hours = json_decode($cluster_hours_json, true);
            if (!is_array($cluster_hours)) {
                $cluster_hours = array_fill(0, max(1, $ticket_count), 1);
            }
            $cluster_hours = array_values($cluster_hours);
            if (count($cluster_hours) < $ticket_count) {
                $cluster_hours = array_pad($cluster_hours, $ticket_count, 1);
            } elseif (count($cluster_hours) > $ticket_count) {
                $cluster_hours = array_slice($cluster_hours, 0, $ticket_count);
            }
            $hour_units = 0;
            foreach ($cluster_hours as $hours) {
                $normalized = max(1, min($max_hours_per_cluster, intval($hours)));
                $hour_units += $normalized;
                $cluster_hours_for_save .= ($cluster_hours_for_save === '' ? '' : ',') . strval($normalized);
            }
            $cluster_time_ranges = $this->build_default_cluster_time_ranges($type->tour_start_time, $type->tour_end_time, $cluster_hours);
            $cluster_time_ranges_for_save = wp_json_encode($cluster_time_ranges);
            $total_price = floatval($type->event_cluster_price) * $hour_units;
        } elseif ($type->type_category === 'individual_tour') {
            // Check capacity
            $total_booked = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(ticket_count), 0) FROM {$wpdb->prefix}bt_bookings 
                 WHERE booking_type_id = %d AND booking_date = %s AND status IN ('pending', 'approved')",
                $type_id, $booking_date
            ));
            
            if (($total_booked + $ticket_count) > intval($type->max_daily_capacity)) {
                wp_send_json_error('Not enough tickets available. Only ' . (intval($type->max_daily_capacity) - $total_booked) . ' remaining.');
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'bt_bookings',
            array(
                'booking_type_id' => $type_id,
                'booking_date' => $booking_date,
                'slot_ids' => $slot_ids,
                'cluster_hours' => $cluster_hours_for_save,
                'cluster_time_ranges' => $cluster_time_ranges_for_save,
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
            array('%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($wpdb->insert_id) {
            if ($type->type_category === 'hall' && !empty($addons_json)) {
                $addons_payload = json_decode($addons_json, true);
                if (is_array($addons_payload)) {
                    $error = $this->save_booking_addons($wpdb->insert_id, $type_id, $booking_date, $addons_payload);
                    if ($error) {
                        $wpdb->delete(
                            $wpdb->prefix . 'bt_bookings',
                            array('id' => $wpdb->insert_id),
                            array('%d')
                        );
                        wp_send_json_error($error);
                    }
                }
            }
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
        
        if (($type->type_category === 'hall' || $type->type_category === 'staircase') && $slot_ids) {
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

    private function send_confirmation_email($customer_email, $customer_name, $type, $date, $slot_ids = '', $ticket_count = 1, $total_price = null, $cluster_hours_csv = '', $cluster_time_ranges_json = '', $event_cluster_rate = null, $booking_id = 0, $customer_phone = '', $notes = '') {
        global $wpdb;
        
        $subject = 'Booking Confirmed - ' . $type->type_name;
        
        $message = "Dear $customer_name,\n\n";
        $message .= "Great news! Your booking has been confirmed.\n\n";
        $message .= "Customer Details:\n";
        $message .= "Name: {$customer_name}\n";
        $message .= "Email: {$customer_email}\n";
        if (!empty($customer_phone)) {
            $message .= "Phone: {$customer_phone}\n";
        }
        $message .= "\n";
        $message .= $this->build_booking_breakdown_text($type, $date, $slot_ids, $ticket_count, $total_price, $booking_id, $cluster_hours_csv, $cluster_time_ranges_json, $event_cluster_rate);
        if (!empty($notes)) {
            $message .= "Notes: " . $notes . "\n";
        }
        
        $message .= "\nWe look forward to seeing you!\n\n";
        $message .= "Best regards,\nKnowledge Hub Team";
        
        $headers = array(
            'From: Knowledge Hub <knowledgehub@brac.net>',
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($customer_email, $subject, $message, $headers);
    }

    private function send_rejection_email($customer_email, $customer_name, $type, $date, $slot_ids = '', $ticket_count = 1, $total_price = null, $cluster_hours_csv = '', $cluster_time_ranges_json = '', $event_cluster_rate = null, $booking_id = 0, $customer_phone = '', $notes = '') {
        global $wpdb;
        
        $subject = 'Booking Rejected - ' . $type->type_name;
        
        $message = "Dear $customer_name,\n\n";
        $message .= "We’re sorry to inform you that your booking has been rejected.\n\n";
        $message .= "Customer Details:\n";
        $message .= "Name: {$customer_name}\n";
        $message .= "Email: {$customer_email}\n";
        if (!empty($customer_phone)) {
            $message .= "Phone: {$customer_phone}\n";
        }
        $message .= "\n";
        $message .= $this->build_booking_breakdown_text($type, $date, $slot_ids, $ticket_count, $total_price, $booking_id, $cluster_hours_csv, $cluster_time_ranges_json, $event_cluster_rate);
        if (!empty($notes)) {
            $message .= "Notes: " . $notes . "\n";
        }
        
        $message .= "\nIf you have any questions, please contact us.\n\n";
        $message .= "Best regards,\nKnowledge Hub Team";
        
        $headers = array(
            'From: Knowledge Hub <knowledgehub@brac.net>',
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($customer_email, $subject, $message, $headers);
    }

    private function build_booking_breakdown_text($type, $date, $slot_ids, $ticket_count, $total_price, $booking_id = 0, $cluster_hours_csv = '', $cluster_time_ranges_json = '', $event_cluster_rate = null) {
        global $wpdb;
        $message = "Booking Details:\n";
        $message .= "Booking Type: {$type->type_name}\n";
        $message .= "Date: " . date('F j, Y', strtotime($date)) . "\n";

        if (($type->type_category === 'hall' || $type->type_category === 'staircase') && $slot_ids) {
            $slot_id_arr = array_map('intval', explode(',', $slot_ids));
            $slot_rows = array();
            if (!empty($slot_id_arr)) {
                $slot_rows = $wpdb->get_results(
                    "SELECT slot_name, start_time, end_time, price FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_id_arr) . ")"
                );
            }
            $slot_total = 0;
            $message .= "Slots:\n";
            foreach ($slot_rows as $slot) {
                $line_total = floatval($slot->price);
                $slot_total += $line_total;
                $time_text = '';
                if (!empty($slot->start_time) && !empty($slot->end_time)) {
                    $time_text = ' (' . date('g:i A', strtotime($slot->start_time)) . ' - ' . date('g:i A', strtotime($slot->end_time)) . ')';
                }
                $message .= "- {$slot->slot_name}{$time_text}: BDT " . number_format($line_total, 2) . "\n";
            }
            $message .= "Slots Total: BDT " . number_format($slot_total, 2) . "\n";

            if ($type->type_category === 'hall' && intval($booking_id) > 0) {
                $addon_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT addon_name, addon_price, quantity FROM {$wpdb->prefix}bt_booking_addons WHERE booking_id = %d",
                    $booking_id
                ));
                if (!empty($addon_rows)) {
                    $addons_subtotal = 0;
                    $message .= "Add-ons:\n";
                    foreach ($addon_rows as $addon) {
                        $line_total = floatval($addon->addon_price) * intval($addon->quantity);
                        $addons_subtotal += $line_total;
                        $message .= "- {$addon->addon_name} × {$addon->quantity}: BDT " . number_format($line_total, 2) . " (BDT " . number_format($addon->addon_price, 2) . ")\n";
                    }
                    $message .= "Add-ons Subtotal: BDT " . number_format($addons_subtotal, 2) . "\n";
                }
            }
        } else {
            $message .= "Tour Time: " . date('g:i A', strtotime($type->tour_start_time)) . " - " . date('g:i A', strtotime($type->tour_end_time)) . "\n";
            if ($type->type_category === 'individual_tour') {
                $message .= "Tickets: " . intval($ticket_count) . " person(s)\n";
            } elseif ($type->type_category === 'event_tour') {
                $message .= "Clusters: " . intval($ticket_count) . " cluster(s)\n";
                $hours = array();
                if (!empty($cluster_hours_csv)) {
                    $hours = array_map('intval', explode(',', $cluster_hours_csv));
                }
                $hours = array_values(array_filter($hours, function($v) {
                    return intval($v) > 0;
                }));
                if (count($hours) < intval($ticket_count)) {
                    $hours = array_pad($hours, intval($ticket_count), 1);
                } elseif (count($hours) > intval($ticket_count)) {
                    $hours = array_slice($hours, 0, intval($ticket_count));
                }
                $ranges = json_decode($cluster_time_ranges_json, true);
                if (!is_array($ranges)) {
                    $ranges = array();
                }
                $rate = is_null($event_cluster_rate) ? 0 : floatval($event_cluster_rate);
                if ($rate <= 0) {
                    $sum_hours = array_sum($hours);
                    if ($sum_hours > 0 && !is_null($total_price)) {
                        $rate = floatval($total_price) / $sum_hours;
                    }
                }
                foreach ($hours as $idx => $hour_count) {
                    $line_total = $rate * $hour_count;
                    $time_text = '';
                    if (isset($ranges[$idx]) && is_array($ranges[$idx]) && !empty($ranges[$idx]['start']) && !empty($ranges[$idx]['end'])) {
                        $time_text = ' [' . date('g:i A', strtotime($ranges[$idx]['start'])) . ' - ' . date('g:i A', strtotime($ranges[$idx]['end'])) . ']';
                    }
                    $message .= "Cluster " . ($idx + 1) . ": {$hour_count} hour(s), BDT " . number_format($line_total, 2) . $time_text . "\n";
                }
            }
        }

        if (!is_null($total_price)) {
            $message .= "Total Amount: BDT " . number_format($total_price, 2) . "\n";
        }
        return $message;
    }

    private function save_booking_addons($booking_id, $type_id, $booking_date, $addons_payload) {
        global $wpdb;
        if (empty($addons_payload)) return '';

        $addon_ids = array_map('intval', array_keys($addons_payload));
        if (empty($addon_ids)) return '';

        $placeholders = implode(',', array_fill(0, count($addon_ids), '%d'));
        $addons = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, price, max_quantity FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = %d AND id IN ($placeholders)",
            array_merge(array($type_id), $addon_ids)
        ));
        $addon_map = array();
        foreach ($addons as $addon) {
            $addon_map[intval($addon->id)] = $addon;
        }

        foreach ($addons_payload as $addon_id => $qty) {
            $addon_id = intval($addon_id);
            $qty = intval($qty);
            if ($qty <= 0 || !isset($addon_map[$addon_id])) continue;

            $availability = $this->get_addons_availability($type_id, $booking_date);
            $remaining = isset($availability[$addon_id]) ? intval($availability[$addon_id]) : 0;
            if ($qty > $remaining) {
                return 'Add-on availability changed. Please refresh and try again.';
            }

            $wpdb->insert(
                $wpdb->prefix . 'bt_booking_addons',
                array(
                    'booking_id' => $booking_id,
                    'addon_id' => $addon_id,
                    'addon_name' => $addon_map[$addon_id]->name,
                    'addon_price' => $addon_map[$addon_id]->price,
                    'quantity' => $qty
                ),
                array('%d', '%d', '%s', '%f', '%d')
            );
        }
        return '';
    }

    private function get_addons_availability($type_id, $booking_date) {
        global $wpdb;
        $addons = $wpdb->get_results($wpdb->prepare(
            "SELECT id, max_quantity FROM {$wpdb->prefix}bt_addons WHERE booking_type_id = %d",
            $type_id
        ));
        $availability = array();
        if (!$addons) return $availability;
        $addon_ids = array_map(function($a) { return intval($a->id); }, $addons);
        $placeholders = implode(',', array_fill(0, count($addon_ids), '%d'));
        $used_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ba.addon_id, SUM(ba.quantity) AS total_qty
             FROM {$wpdb->prefix}bt_booking_addons ba
             INNER JOIN {$wpdb->prefix}bt_bookings b ON b.id = ba.booking_id
             WHERE b.booking_type_id = %d AND b.booking_date = %s AND b.status IN ('pending','approved')
             AND ba.addon_id IN ($placeholders)
             GROUP BY ba.addon_id",
            array_merge(array($type_id, $booking_date), $addon_ids)
        ));
        $used_map = array();
        foreach ($used_rows as $row) {
            $used_map[intval($row->addon_id)] = intval($row->total_qty);
        }
        foreach ($addons as $addon) {
            $used = isset($used_map[$addon->id]) ? $used_map[$addon->id] : 0;
            $availability[intval($addon->id)] = max(0, intval($addon->max_quantity) - $used);
        }
        return $availability;
    }

    public function get_bookings() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
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
        if (!empty($start_date)) {
            $where .= " AND b.booking_date >= %s";
            $params[] = $start_date;
        }
        if (!empty($end_date)) {
            $where .= " AND b.booking_date <= %s";
            $params[] = $end_date;
        }
        
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bt_bookings b WHERE $where";
        $total = empty($params) ? $wpdb->get_var($count_sql) : $wpdb->get_var($wpdb->prepare($count_sql, $params));
        
        $type_category_sql = $this->get_type_category_sql();
        $joins = $this->get_type_joins_sql();
        $sql = "SELECT b.*, t.name AS type_name, {$type_category_sql} AS type_category, e.price_per_cluster AS event_cluster_price
                FROM {$wpdb->prefix}bt_bookings b
                {$joins}
                WHERE $where ORDER BY b.created_at DESC LIMIT %d OFFSET %d";
        
        $params[] = $this->items_per_page;
        $params[] = $offset;
        
        $bookings = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Get slot names and add-ons for hall/staircase bookings
        foreach ($bookings as &$booking) {
            if (($booking->type_category === 'hall' || $booking->type_category === 'staircase') && $booking->slot_ids) {
                $slot_ids = array_map('intval', explode(',', $booking->slot_ids));
                if (!empty($slot_ids)) {
                    $slots = $wpdb->get_results(
                        "SELECT slot_name, start_time, end_time, price FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_ids) . ")"
                    );
                    $booking->slot_details = $slots;
                    $slot_total = 0;
                    foreach ($slots as $slot) {
                        $slot_total += floatval($slot->price);
                    }
                    $booking->slot_total = $slot_total;
                }
            }
            if ($booking->type_category === 'hall') {
                $addon_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT addon_name, addon_price, quantity FROM {$wpdb->prefix}bt_booking_addons WHERE booking_id = %d",
                    $booking->id
                ));
                $addons_subtotal = 0;
                $addon_details = array();
                foreach ($addon_rows as $addon) {
                    $line_total = floatval($addon->addon_price) * intval($addon->quantity);
                    $addons_subtotal += $line_total;
                    $addon_details[] = array(
                        'name' => $addon->addon_name,
                        'price' => floatval($addon->addon_price),
                        'quantity' => intval($addon->quantity),
                        'line_total' => $line_total
                    );
                }
                $booking->addon_details = $addon_details;
                $booking->addons_subtotal = $addons_subtotal;
            }
        }
        
        wp_send_json_success(array(
            'bookings' => $bookings,
            'total' => intval($total),
            'pages' => ceil($total / $this->items_per_page),
            'currentPage' => $page
        ));
    }

    public function save_cluster_times() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $ranges_json = isset($_POST['cluster_time_ranges']) ? wp_unslash($_POST['cluster_time_ranges']) : '';
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT id, ticket_count, booking_type_id FROM {$wpdb->prefix}bt_bookings WHERE id = %d",
            $booking_id
        ));
        if (!$booking) {
            wp_send_json_error('Booking not found');
        }

        $type = $this->get_type_by_id(intval($booking->booking_type_id));
        if (!$type || $type->type_category !== 'event_tour') {
            wp_send_json_error('Invalid booking type');
        }

        $ranges = json_decode($ranges_json, true);
        if (!is_array($ranges)) {
            wp_send_json_error('Invalid cluster time data');
        }

        $cluster_count = max(0, intval($booking->ticket_count));
        if (count($ranges) !== $cluster_count) {
            wp_send_json_error('Cluster count mismatch');
        }

        foreach ($ranges as $item) {
            $start = isset($item['start']) ? sanitize_text_field($item['start']) : '';
            $end = isset($item['end']) ? sanitize_text_field($item['end']) : '';
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
                wp_send_json_error('Invalid time format');
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'bt_bookings',
            array('cluster_time_ranges' => wp_json_encode($ranges)),
            array('id' => $booking_id),
            array('%s'),
            array('%d')
        );

        wp_send_json_success('Cluster times saved');
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

        // If approving or rejecting, send email
        if ($status === 'approved' || $status === 'rejected') {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.name AS type_name,
                        {$this->get_type_category_sql()} AS type_category,
                        COALESCE(i.tour_start_time, e.tour_start_time) AS tour_start_time,
                        COALESCE(i.tour_end_time, e.tour_end_time) AS tour_end_time,
                        e.price_per_cluster AS event_cluster_price
                 FROM {$wpdb->prefix}bt_bookings b
                 {$this->get_type_joins_sql()}
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
                
                if ($status === 'approved') {
                    $this->send_confirmation_email(
                        $booking->customer_email,
                        $booking->customer_name,
                        $type,
                        $booking->booking_date,
                        $booking->slot_ids,
                        $booking->ticket_count,
                        $booking->total_price,
                        $booking->cluster_hours,
                        $booking->cluster_time_ranges,
                        $booking->event_cluster_price,
                        $booking->id,
                        $booking->customer_phone,
                        $booking->notes
                    );
                } else {
                    $this->send_rejection_email(
                        $booking->customer_email,
                        $booking->customer_name,
                        $type,
                        $booking->booking_date,
                        $booking->slot_ids,
                        $booking->ticket_count,
                        $booking->total_price,
                        $booking->cluster_hours,
                        $booking->cluster_time_ranges,
                        $booking->event_cluster_price,
                        $booking->id,
                        $booking->customer_phone,
                        $booking->notes
                    );
                }
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
        $wpdb->delete(
            $wpdb->prefix . 'bt_booking_addons',
            array('booking_id' => $booking_id),
            array('%d')
        );
        
        wp_send_json_success('Booking deleted');
    }

    private function get_fully_booked_dates($type_id) {
        global $wpdb;
        $slot_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bt_slots WHERE booking_type_id = %d",
            $type_id
        )));
        if ($slot_count === 0) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_date, slot_ids FROM {$wpdb->prefix}bt_bookings
             WHERE booking_type_id = %d AND status IN ('pending','approved') AND booking_date >= CURDATE()",
            $type_id
        ));
        $slot_ids_all = array();
        foreach ($rows as $row) {
            if ($row->slot_ids) {
                $slot_ids_all = array_merge($slot_ids_all, array_map('intval', explode(',', $row->slot_ids)));
            }
        }
        if (empty($slot_ids_all)) {
            return array();
        }

        $slot_rows = $wpdb->get_results(
            "SELECT id, start_time, end_time FROM {$wpdb->prefix}bt_slots WHERE booking_type_id = " . intval($type_id)
        );
        $slot_map = array();
        foreach ($slot_rows as $slot) {
            $slot_map[intval($slot->id)] = array(
                'start' => $slot->start_time,
                'end' => $slot->end_time
            );
        }

        $dateBooked = array();
        foreach ($rows as $row) {
            if (!$row->slot_ids) continue;
            $date = $row->booking_date;
            if (!isset($dateBooked[$date])) $dateBooked[$date] = array();
            $dateBooked[$date] = array_merge($dateBooked[$date], array_map('intval', explode(',', $row->slot_ids)));
        }

        $fullyBooked = array();
        foreach ($dateBooked as $date => $slot_ids) {
            $slot_ids = array_values(array_unique($slot_ids));
            $bookedIntervals = array();
            foreach ($slot_ids as $sid) {
                if (!isset($slot_map[$sid])) continue;
                $bookedIntervals[] = $slot_map[$sid];
            }
            $isFull = true;
            foreach ($slot_map as $sid => $interval) {
                $slotStart = $interval['start'];
                $slotEnd = $interval['end'];
                $blocked = in_array($sid, $slot_ids, true);
                if (!$blocked) {
                    foreach ($bookedIntervals as $b) {
                        if ($slotStart < $b['end'] && $slotEnd > $b['start']) {
                            $blocked = true;
                            break;
                        }
                    }
                }
                if (!$blocked) {
                    $isFull = false;
                    break;
                }
            }
            if ($isFull) $fullyBooked[] = $date;
        }

        return array_values(array_unique($fullyBooked));
    }

    public function generate_report() {
        check_ajax_referer('bt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $type_id = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'doc';

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
        if (!empty($start_date)) {
            $where .= " AND b.booking_date >= %s";
            $params[] = $start_date;
        }
        if (!empty($end_date)) {
            $where .= " AND b.booking_date <= %s";
            $params[] = $end_date;
        }

        $type_category_sql = $this->get_type_category_sql();
        $joins = $this->get_type_joins_sql();
        $sql = "SELECT b.id, b.booking_type_id, b.booking_date, b.slot_ids, b.cluster_hours, b.cluster_time_ranges, b.ticket_count, b.total_price,
                       b.customer_name, b.customer_email, b.customer_phone, b.notes, b.status,
                       t.name AS type_name, {$type_category_sql} AS type_category,
                       i.ticket_price, e.price_per_cluster AS event_cluster_price
                FROM {$wpdb->prefix}bt_bookings b
                {$joins}
                WHERE $where ORDER BY b.booking_date DESC, b.id DESC LIMIT 500";
        $bookings = empty($params) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, $params));

        $booking_ids = array();
        $slot_ids_all = array();
        $addon_rows = array();
        foreach ($bookings as $booking) {
            $booking_ids[] = intval($booking->id);
            if (!empty($booking->slot_ids)) {
                $slot_ids_all = array_merge($slot_ids_all, array_map('intval', explode(',', $booking->slot_ids)));
            }
        }
        $slot_ids_all = array_values(array_unique(array_filter($slot_ids_all)));

        $slot_map = array();
        if (!empty($slot_ids_all)) {
            $slots = $wpdb->get_results("SELECT id, slot_name, start_time, end_time, price FROM {$wpdb->prefix}bt_slots WHERE id IN (" . implode(',', $slot_ids_all) . ")");
            foreach ($slots as $slot) {
                $slot_map[intval($slot->id)] = $slot;
            }
        }

        $addons_map = array();
        if (!empty($booking_ids)) {
            $addon_rows = $wpdb->get_results("SELECT booking_id, addon_name, addon_price, quantity FROM {$wpdb->prefix}bt_booking_addons WHERE booking_id IN (" . implode(',', $booking_ids) . ")");
            foreach ($addon_rows as $row) {
                $bid = intval($row->booking_id);
                if (!isset($addons_map[$bid])) $addons_map[$bid] = array();
                $addons_map[$bid][] = $row;
            }
        }

        $filters = array(
            'Type' => $type_id > 0 ? $this->get_type_name($type_id) : 'All',
            'Status' => $status ? ucfirst($status) : 'All',
            'Start Date' => $start_date ?: 'Any',
            'End Date' => $end_date ?: 'Any'
        );

        $html = $this->build_report_html($bookings, $slot_map, $addons_map, $filters);
        $filename_base = 'booking-tour-report-' . date('Ymd-His');

        if ($format === 'pdf') {
            if (class_exists('\\Dompdf\\Dompdf')) {
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename_base . '.pdf"');
                echo $dompdf->output();
                exit;
            }
            wp_die('PDF generation is not available. Please install dompdf.');
        }

        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.doc"');
        echo $html;
        exit;
    }

    private function build_report_html($bookings, $slot_map, $addons_map, $filters) {
        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:Arial,sans-serif;color:#111827;font-size:12px}
            h1{font-size:20px;margin:0 0 10px}
            h2{font-size:14px;margin:18px 0 8px}
            table{width:100%;border-collapse:collapse;margin-bottom:14px}
            th,td{border:1px solid #e5e7eb;padding:6px 8px;text-align:left;vertical-align:top}
            th{background:#f3f4f6}
            .muted{color:#6b7280}
            .section{margin-bottom:12px}
        </style></head><body>';
        $html .= '<h1>Booking Tour Report</h1>';
        $html .= '<div class="section"><strong>Generated:</strong> ' . esc_html(date('Y-m-d H:i:s')) . '</div>';
        $html .= '<div class="section"><strong>Applied Filters:</strong><br>';
        foreach ($filters as $key => $val) {
            $html .= esc_html($key) . ': ' . esc_html($val) . '<br>';
        }
        $html .= '</div>';
        $html .= '<div class="section"><em>Report includes the first 500 records only for performance reasons.</em></div>';

        foreach ($bookings as $booking) {
            $html .= '<h2>Booking #' . intval($booking->id) . '</h2>';
            $html .= '<table>';
            $html .= '<tr><th colspan="2">Booked By</th></tr>';
            $html .= '<tr><td>Name</td><td>' . esc_html($booking->customer_name) . '</td></tr>';
            $html .= '<tr><td>Email</td><td>' . esc_html($booking->customer_email) . '</td></tr>';
            $html .= '<tr><td>Phone</td><td>' . esc_html($booking->customer_phone) . '</td></tr>';
            $html .= '<tr><th colspan="2">Booking Information</th></tr>';
            $html .= '<tr><td>Type</td><td>' . esc_html($booking->type_name) . '</td></tr>';
            $html .= '<tr><td>Date</td><td>' . esc_html($booking->booking_date) . '</td></tr>';
            if ($booking->type_category === 'individual_tour') {
                $html .= '<tr><td>Tickets</td><td>' . intval($booking->ticket_count) . '</td></tr>';
            }
            if ($booking->type_category === 'event_tour') {
                $html .= '<tr><td>Clusters</td><td>' . intval($booking->ticket_count) . '</td></tr>';
            }
            if (!empty($booking->slot_ids)) {
                $slot_index = 1;
                $slot_total = 0;
                foreach (array_map('intval', explode(',', $booking->slot_ids)) as $sid) {
                    if (isset($slot_map[$sid])) {
                        $slot = $slot_map[$sid];
                        $line_total = floatval($slot->price);
                        $slot_total += $line_total;
                        $time_text = '';
                        if (!empty($slot->start_time) && !empty($slot->end_time)) {
                            $time_text = ' (' . date('g:i A', strtotime($slot->start_time)) . ' - ' . date('g:i A', strtotime($slot->end_time)) . ')';
                        }
                        $html .= '<tr><td>Slot ' . $slot_index . '</td><td>' . esc_html($slot->slot_name . $time_text) . ' - BDT ' . number_format($line_total, 2) . '</td></tr>';
                        $slot_index++;
                    }
                }
                $html .= '<tr><td>Slots Total</td><td>BDT ' . number_format($slot_total, 2) . '</td></tr>';
            } elseif ($booking->type_category === 'event_tour') {
                $cluster_price = floatval($booking->event_cluster_price);
                $cluster_hours = array();
                if (!empty($booking->cluster_hours)) {
                    $cluster_hours = array_map('intval', explode(',', $booking->cluster_hours));
                }
                $cluster_hours = array_values(array_filter($cluster_hours, function($v) {
                    return intval($v) > 0;
                }));
                if (count($cluster_hours) < intval($booking->ticket_count)) {
                    $cluster_hours = array_pad($cluster_hours, intval($booking->ticket_count), 1);
                } elseif (count($cluster_hours) > intval($booking->ticket_count)) {
                    $cluster_hours = array_slice($cluster_hours, 0, intval($booking->ticket_count));
                }
                $ranges = json_decode($booking->cluster_time_ranges, true);
                if (!is_array($ranges)) {
                    $ranges = array();
                }
                foreach ($cluster_hours as $idx => $hours) {
                    $line_total = $cluster_price * $hours;
                    $time_text = '';
                    if (isset($ranges[$idx]) && is_array($ranges[$idx]) && !empty($ranges[$idx]['start']) && !empty($ranges[$idx]['end'])) {
                        $time_text = ' [' . date('g:i A', strtotime($ranges[$idx]['start'])) . ' - ' . date('g:i A', strtotime($ranges[$idx]['end'])) . ']';
                    }
                    $html .= '<tr><td>Cluster ' . ($idx + 1) . '</td><td>' . intval($hours) . ' hour(s), BDT ' . number_format($line_total, 2) . esc_html($time_text) . '</td></tr>';
                }
            } elseif ($booking->type_category === 'individual_tour') {
                $html .= '<tr><td>Total</td><td>BDT ' . number_format($booking->total_price, 2) . '</td></tr>';
            }

            $addons_subtotal = 0;
            if (isset($addons_map[intval($booking->id)]) && $booking->type_category === 'hall') {
                $addon_lines = '';
                foreach ($addons_map[intval($booking->id)] as $addon) {
                    $line = floatval($addon->addon_price) * intval($addon->quantity);
                    $addons_subtotal += $line;
                    $addon_lines .= esc_html($addon->addon_name) . ' × ' . intval($addon->quantity) .
                        ' @ BDT ' . number_format($addon->addon_price, 2) .
                        ' = BDT ' . number_format($line, 2) . '<br>';
                }
                $html .= '<tr><td>Add-ons</td><td>' . $addon_lines . '</td></tr>';
                $html .= '<tr><td>Add-ons Subtotal</td><td>BDT ' . number_format($addons_subtotal, 2) . '</td></tr>';
            }
            $html .= '<tr><td>Final Total</td><td>BDT ' . number_format($booking->total_price, 2) . '</td></tr>';
            if (!empty($booking->notes)) {
                $html .= '<tr><td>Notes</td><td>' . esc_html($booking->notes) . '</td></tr>';
            }
            $html .= '<tr><td>Status</td><td>' . esc_html(ucfirst($booking->status)) . '</td></tr>';
            $html .= '</table>';
        }

        $html .= '</body></html>';
        return $html;
    }

    private function get_type_name($type_id) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}bt_booking_types WHERE id = %d",
            $type_id
        ));
        return $name ?: 'Unknown';
    }

    // Shortcode: Combined Booking (Hall + Tours)
    public function render_book_tour_shortcode($atts) {
        $hall_type = $this->get_type_by_category('hall');
        $staircase_type = $this->get_type_by_category('staircase');
        $event_type = $this->get_type_by_category('event_tour');
        $individual_type = $this->get_type_by_category('individual_tour');
        
        if (!$hall_type || !$staircase_type || !$event_type || !$individual_type) {
            return '<p>Booking system is not configured properly.</p>';
        }

        $visible_types = array();
        foreach (array($hall_type, $staircase_type, $individual_type, $event_type) as $type) {
            if (!$type || intval($type->is_hidden) === 1) {
                continue;
            }
            $visible_types[] = $type;
        }
        if (empty($visible_types)) {
            return '<p>No booking types are currently available.</p>';
        }
        $default_type = $visible_types[0];

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
                <?php if (intval($hall_type->is_hidden) !== 1): ?>
                <button class="bt-tour-btn <?php echo $default_type->id === $hall_type->id ? 'active' : ''; ?>" data-type-id="<?php echo esc_attr($hall_type->id); ?>" data-category="hall">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>Multipurpose Hall</span>
                </button>
                <?php endif; ?>
                <?php if (intval($staircase_type->is_hidden) !== 1): ?>
                <button class="bt-tour-btn <?php echo $default_type->id === $staircase_type->id ? 'active' : ''; ?>" data-type-id="<?php echo esc_attr($staircase_type->id); ?>" data-category="staircase">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M3 21h4v-4h4v-4h4v-4h6"></path>
                        <path d="M3 7h4v4H3z"></path>
                    </svg>
                    <span>Staircase Book</span>
                </button>
                <?php endif; ?>
                <?php if (intval($individual_type->is_hidden) !== 1): ?>
                <button class="bt-tour-btn <?php echo $default_type->id === $individual_type->id ? 'active' : ''; ?>" data-type-id="<?php echo esc_attr($individual_type->id); ?>" data-category="individual_tour">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span>Individual Booking</span>
                </button>
                <?php endif; ?>
                <?php if (intval($event_type->is_hidden) !== 1): ?>
                <button class="bt-tour-btn <?php echo $default_type->id === $event_type->id ? 'active' : ''; ?>" data-type-id="<?php echo esc_attr($event_type->id); ?>" data-category="event_tour">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span>Event Booking</span>
                </button>
                <?php endif; ?>
            </div>

            <input type="hidden" id="bt-type-id" value="<?php echo esc_attr($default_type->id); ?>">
            <input type="hidden" id="bt-type-category" value="<?php echo esc_attr($default_type->type_category); ?>">
            <input type="hidden" id="bt-hall-type-id" value="<?php echo esc_attr($hall_type->id); ?>">
            <input type="hidden" id="bt-staircase-type-id" value="<?php echo esc_attr($staircase_type->id); ?>">
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
            <input type="hidden" id="bt-selected-addons" value="">

            <div class="bt-addons-section" id="bt-addons-section" style="display: none;">
                <div class="bt-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M20 12H4"></path>
                        <path d="M14 6h6v6"></path>
                        <path d="M10 18H4v-6"></path>
                    </svg>
                    <span class="bt-section-title">Choose Add-ons</span>
                </div>
                <div class="bt-addons-list" id="bt-addons-list"></div>
            </div>
            
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
                <div class="bt-summary-addons" id="bt-summary-addons"></div>
                <div class="bt-summary-total" id="bt-summary-total"></div>
                
                <div class="bt-terms-section">
                    <p class="bt-terms-text">
                        Please take a moment to review our
                        <a href="http://35.240.207.116/knowledgehub/wordpress/?page_id=1031" target="_blank" class="bt-terms-link">terms and conditions</a>
                        and
                        <a href="http://35.240.207.116/knowledgehub/wordpress/?page_id=1607" target="_blank" class="bt-terms-link">Payment Rules & Regulations</a>
                        before proceeding to your booking. Your understanding and agreement are appreciated.
                    </p>
                    <div class="bt-rules-panel">
                        <h4>Some more important rules & regulation</h4>
                        <p>Smoking and vaping are prohibited at all times within the Knowledge Hub premises.</p>
                        <p>Consumption of food and beverages is restricted to the Kitchen and Terrace area only.</p>
                        <p>Food and beverages are strictly prohibited inside the Knowledge Hub.</p>
                    </div>
                    <label class="bt-agree">
                        <input type="checkbox" id="bt-agree-terms">
                        <span>I agree to the Terms &amp; Conditions and confirm that I understand and accept the Payment Rules &amp; Regulations.</span>
                    </label>
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
