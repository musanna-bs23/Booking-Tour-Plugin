<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'bt_booking_addons',
    $wpdb->prefix . 'bt_addons',
    $wpdb->prefix . 'bt_holidays',
    $wpdb->prefix . 'bt_bookings',
    $wpdb->prefix . 'bt_slots',
    $wpdb->prefix . 'bt_event_tour_types',
    $wpdb->prefix . 'bt_individual_tour_types',
    $wpdb->prefix . 'bt_staircase_types',
    $wpdb->prefix . 'bt_hall_types',
    $wpdb->prefix . 'bt_booking_types',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
