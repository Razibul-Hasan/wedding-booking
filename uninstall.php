<?php

/**
 * Wedding Booking uninstall.
 *
 * Runs when the plugin is deleted from the Plugins screen. Nothing is removed
 * unless the site opted in under Wedding Booking → Settings → Plugin data
 * ("Delete all Wedding Booking data when the plugin is deleted", option
 * wedding_booking_delete_data_on_uninstall). WooCommerce orders — and the payments on
 * them — are never deleted.
 *
 * @package Wedding Booking
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Remove Wedding Booking's data from the current site, if the site asked for it.
 *
 * @return bool Whether anything was removed.
 */
function wedding_booking_uninstall_site()
{
    global $wpdb;

    if ((int) get_option('wedding_booking_delete_data_on_uninstall', 0) !== 1) {
        return false;
    }

    // The hidden booking product (read before the options go).
    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    if ($product_id > 0 && 'product' === get_post_type($product_id)) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product) {
            $product->delete(true);
        } else {
            wp_delete_post($product_id, true);
        }
    }

    // Tables.
    foreach (['bookings', 'dates', 'addons', 'packages', 'sessions'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'wedding_booking_' . $table) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table names; removing the plugin's own tables.
    }

    // Scheduled jobs (every instance, whatever its arguments).
    foreach (['wedding_booking_balance_reminder_sweep', 'wedding_booking_maintenance', 'wedding_booking_gcal_sync_event', 'wedding_booking_gcal_create_event', 'wedding_booking_send_balance_reminder_event'] as $hook) {
        wp_unschedule_hook($hook);
    }

    // Options (wedding_booking_*) and Wedding Booking transients. Never
    // fpb_* / snapbook_*: those belong to SnapBook, which may share the site.
    $patterns = [
        'wedding\_booking\_%',
        '\_transient\_wedding\_booking\_%',
        '\_transient\_timeout\_wedding\_booking\_%',
    ];
    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
    wp_cache_flush();

    // The booking-management capability.
    $roles = function_exists('wp_roles') ? wp_roles() : null;
    if ($roles) {
        foreach (array_keys($roles->roles) as $role_name) {
            $role = get_role($role_name);
            if ($role && $role->has_cap('manage_wedding_booking')) {
                $role->remove_cap('manage_wedding_booking');
            }
        }
    }

    return true;
}

if (is_multisite()) {
    $wedding_booking_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($wedding_booking_site_ids as $wedding_booking_site_id) {
        switch_to_blog((int) $wedding_booking_site_id);
        wedding_booking_uninstall_site();
        restore_current_blog();
    }
} else {
    wedding_booking_uninstall_site();
}
