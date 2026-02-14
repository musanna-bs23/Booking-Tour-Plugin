<?php
if (!defined('ABSPATH')) {
    exit;
}

function bt_create_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $bt_booking_types = $wpdb->prefix . 'bt_booking_types';
    $bt_hall_types = $wpdb->prefix . 'bt_hall_types';
    $bt_staircase_types = $wpdb->prefix . 'bt_staircase_types';
    $bt_individual_tour_types = $wpdb->prefix . 'bt_individual_tour_types';
    $bt_event_tour_types = $wpdb->prefix . 'bt_event_tour_types';
    $bt_slots = $wpdb->prefix . 'bt_slots';
    $bt_bookings = $wpdb->prefix . 'bt_bookings';
    $bt_holidays = $wpdb->prefix . 'bt_holidays';
    $bt_addons = $wpdb->prefix . 'bt_addons';
    $bt_booking_addons = $wpdb->prefix . 'bt_booking_addons';

    $sql = array();

    $sql[] = "CREATE TABLE {$bt_booking_types} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_hall_types} (
        type_id BIGINT UNSIGNED NOT NULL,
        type_slug VARCHAR(191) NOT NULL,
        type_category VARCHAR(50) NOT NULL,
        weekend_days VARCHAR(50) NOT NULL DEFAULT '',
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_staircase_types} (
        type_id BIGINT UNSIGNED NOT NULL,
        type_slug VARCHAR(191) NOT NULL,
        type_category VARCHAR(50) NOT NULL,
        weekend_days VARCHAR(50) NOT NULL DEFAULT '',
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_individual_tour_types} (
        type_id BIGINT UNSIGNED NOT NULL,
        type_slug VARCHAR(191) NOT NULL,
        type_category VARCHAR(50) NOT NULL,
        weekend_days VARCHAR(50) NOT NULL DEFAULT '',
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        tour_start_time TIME NOT NULL DEFAULT '09:00:00',
        tour_end_time TIME NOT NULL DEFAULT '17:00:00',
        max_tickets INT NOT NULL DEFAULT 50,
        ticket_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        booking_window_mode VARCHAR(10) NOT NULL DEFAULT 'limit',
        booking_window_days INT NOT NULL DEFAULT 1,
        PRIMARY KEY (type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_event_tour_types} (
        type_id BIGINT UNSIGNED NOT NULL,
        type_slug VARCHAR(191) NOT NULL,
        type_category VARCHAR(50) NOT NULL,
        weekend_days VARCHAR(50) NOT NULL DEFAULT '',
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        tour_start_time TIME NOT NULL DEFAULT '09:00:00',
        tour_end_time TIME NOT NULL DEFAULT '17:00:00',
        max_clusters INT NOT NULL DEFAULT 0,
        members_per_cluster INT NOT NULL DEFAULT 1,
        price_per_cluster DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_hours_per_cluster INT NOT NULL DEFAULT 1,
        PRIMARY KEY (type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_slots} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_type_id BIGINT UNSIGNED NOT NULL,
        slot_name VARCHAR(191) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY booking_type_id (booking_type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_bookings} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_type_id BIGINT UNSIGNED NOT NULL,
        booking_date DATE NOT NULL,
        slot_ids TEXT,
        cluster_hours TEXT,
        cluster_time_ranges TEXT,
        ticket_count INT NOT NULL DEFAULT 1,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        customer_name VARCHAR(191) NOT NULL,
        customer_email VARCHAR(191) NOT NULL,
        customer_phone VARCHAR(50) NOT NULL,
        transaction_id VARCHAR(191) DEFAULT '',
        payment_image TEXT,
        notes TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY booking_type_id (booking_type_id),
        KEY booking_date (booking_date),
        KEY status (status)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_holidays} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        holiday_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY holiday_date (holiday_date)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_addons} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_type_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(191) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_quantity INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY booking_type_id (booking_type_id)
    ) {$charset_collate};";

    $sql[] = "CREATE TABLE {$bt_booking_addons} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id BIGINT UNSIGNED NOT NULL,
        addon_id BIGINT UNSIGNED NOT NULL,
        addon_name VARCHAR(191) NOT NULL,
        addon_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY booking_id (booking_id),
        KEY addon_id (addon_id)
    ) {$charset_collate};";

    foreach ($sql as $statement) {
        dbDelta($statement);
    }
}

function bt_drop_schema() {
    // Table dropping is handled in uninstall.php
}
