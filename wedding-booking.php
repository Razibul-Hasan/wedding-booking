<?php

/**
 * Plugin Name:  Wedding Booking
 * Plugin URI:   https://bestwebexpert.com
 * Description:  Multi-step wedding booking with backend management and WooCommerce checkout. Shortcode: [wedding_booking]
 * Version:      1.6.1
 * Author:       Razibul Hasan
 * Author URI:   https://bestwebexpert.com
 * Text Domain:  wedding-booking
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('WEDDING_BOOKING_VER', '1.6.1');
define('WEDDING_BOOKING_URL', plugin_dir_url(__FILE__));
define('WEDDING_BOOKING_DIR', plugin_dir_path(__FILE__));

// install.php required early so activation callback exists at hook-time
require_once WEDDING_BOOKING_DIR . 'includes/install.php';
// Settings registry + capabilities: needed by activation and DB upgrades.
require_once WEDDING_BOOKING_DIR . 'includes/settings.php';

register_activation_hook(__FILE__, 'wedding_booking_activate');
register_deactivation_hook(__FILE__, 'wedding_booking_deactivate');

function wedding_booking_get_currency_symbol()
{
    if (class_exists('WooCommerce') && function_exists('get_woocommerce_currency')) {
        $code = get_woocommerce_currency();
        if (function_exists('get_woocommerce_currency_symbol')) {
            $symbol = get_woocommerce_currency_symbol($code);
            if (! empty($symbol)) {
                return $symbol;
            }
        }
    }

    return get_option('wedding_booking_currency_sym', '€');
}

/**
 * Payment (PayPal) fee percentage added on top of the booking total
 * (package + add-ons). 0 disables the fee entirely. Configured in
 * Wedding Booking → Settings → Payment Controls.
 */
function wedding_booking_get_payment_fee_pct()
{
    $pct = (float) get_option('wedding_booking_payment_fee_pct', 0);
    return min(100, max(0, $pct));
}

/**
 * URL of the icon-font stylesheet loaded on the booking form and the
 * Wedding Booking admin pages, so "fa-solid fa-*" icon values render. Defaults
 * to the Font Awesome 6 CDN. Filter 'wedding_booking_icon_library_url' to point
 * at a locally hosted copy instead, or return '' to disable it (emoji and
 * Dashicons still work out of the box).
 */
function wedding_booking_icon_library_url()
{
    $default = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css';
    return (string) apply_filters('wedding_booking_icon_library_url', $default);
}

/**
 * Render an icon value that may be an emoji or an icon-font class
 * (e.g. "dashicons dashicons-camera" or "fa-solid fa-camera").
 */
function wedding_booking_icon_html($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[a-z0-9 _-]+$/i', $value) && preg_match('/(^|\s)(fa-|dashicons)/', $value)) {
        return '<i class="' . esc_attr($value) . '" aria-hidden="true"></i>';
    }

    return esc_html($value);
}

/**
 * Plain-text variant of wedding_booking_icon_html() for contexts that cannot
 * render HTML (<option> elements, emails): emoji pass through unchanged,
 * icon classes are dropped so class names never show as literal text.
 */
function wedding_booking_icon_text($value)
{
    $value = trim((string) $value);
    if ($value !== '' && preg_match('/^[a-z0-9 _-]+$/i', $value) && preg_match('/(^|\s)(fa-|dashicons)/', $value)) {
        return '';
    }

    return $value;
}

/**
 * Find the page (or post) that hosts the booking form: shortcode in the
 * content, or the shortcode inside Elementor-built content.
 */
function wedding_booking_detect_booking_page_id()
{
    global $wpdb;

    $id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT ID FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type IN ('page','post')
		   AND (post_content LIKE '%[wedding_booking%' OR post_content LIKE '%[wedding_booking_form%')
		 ORDER BY post_type = 'page' DESC, ID ASC LIMIT 1"
    );
    if ($id) {
        return $id;
    }

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		 WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
		   AND (m.meta_value LIKE '%[wedding_booking%' OR m.meta_value LIKE '%[wedding_booking_form%')
		 ORDER BY p.post_type = 'page' DESC, p.ID ASC LIMIT 1"
    );
}

/**
 * URL of the booking page used to build shareable package links.
 * Admin choice (wedding_booking_booking_page_id) wins; otherwise auto-detect.
 * Returns '' when no booking page could be found.
 */
function wedding_booking_get_booking_page_url()
{
    $page_id = (int) get_option('wedding_booking_booking_page_id', 0);
    if ($page_id && 'publish' === get_post_status($page_id)) {
        return (string) get_permalink($page_id);
    }

    $detected = wedding_booking_detect_booking_page_id();
    return $detected ? (string) get_permalink($detected) : '';
}

/**
 * Shareable deep-link for a package row — slug preferred, ID as fallback,
 * so the link survives package renames.
 */
function wedding_booking_package_share_link($pkg, $booking_url = null)
{
    if (null === $booking_url) {
        $booking_url = wedding_booking_get_booking_page_url();
    }
    if ($booking_url === '') {
        $booking_url = home_url('/');
    }
    $ident = (isset($pkg->slug) && $pkg->slug !== '') ? $pkg->slug : (string) (int) $pkg->id;

    return add_query_arg('wedding_booking_package', rawurlencode($ident), $booking_url);
}

/**
 * Show an admin notice with an install/activate link when WooCommerce is missing.
 */
add_action('admin_notices', 'wedding_booking_woocommerce_missing_notice');
function wedding_booking_woocommerce_missing_notice()
{
    if (class_exists('WooCommerce')) {
        return;
    }

    $plugins      = get_plugins();
    $wc_installed = isset($plugins['woocommerce/woocommerce.php']);

    if ($wc_installed) {
        $action_url   = wp_nonce_url(
            add_query_arg(
                ['action' => 'activate', 'plugin' => 'woocommerce/woocommerce.php'],
                admin_url('plugins.php')
            ),
            'activate-plugin_woocommerce/woocommerce.php'
        );
        $action_label = __('Activate WooCommerce', 'wedding-booking');
    } else {
        $action_url   = wp_nonce_url(
            add_query_arg(
                ['action' => 'install-plugin', 'plugin' => 'woocommerce'],
                admin_url('update.php')
            ),
            'install-plugin_woocommerce'
        );
        $action_label = __('Install WooCommerce', 'wedding-booking');
    }

    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
        esc_html__('Wedding Booking requires WooCommerce.', 'wedding-booking'),
        esc_html__('Please install and activate WooCommerce to use this plugin.', 'wedding-booking'),
        esc_url($action_url),
        esc_html($action_label)
    );
}

// Declare WooCommerce HPOS (Custom Order Tables) compatibility
add_action('before_woocommerce_init', 'wedding_booking_declare_wc_compatibility');
function wedding_booking_declare_wc_compatibility()
{
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

// Remaining modules loaded after all plugins, so WC is available
add_action('plugins_loaded', 'wedding_booking_load');
function wedding_booking_load()
{
    // Auto-create / upgrade tables if the stored DB version doesn't match.
    // This handles cases where activation hook didn't fire (e.g. manual install)
    if (get_option('wedding_booking_db_version') !== WEDDING_BOOKING_VER) {
        wedding_booking_create_tables();
    }

    // One-time migration: fill blank emoji on existing rows
    wedding_booking_migrate_emoji();

    // Shared building blocks, loaded before anything that uses them.
    require_once WEDDING_BOOKING_DIR . 'includes/pricing.php';
    require_once WEDDING_BOOKING_DIR . 'includes/availability.php';
    require_once WEDDING_BOOKING_DIR . 'includes/admin.php';
    require_once WEDDING_BOOKING_DIR . 'includes/ajax.php';
    require_once WEDDING_BOOKING_DIR . 'includes/shortcode.php';
    // Shared email design system — needed with or without WooCommerce.
    require_once WEDDING_BOOKING_DIR . 'includes/emails.php';
    // Google Calendar sync — the OAuth screens live in wp-admin, so this loads
    // with or without WooCommerce.
    require_once WEDDING_BOOKING_DIR . 'includes/google-calendar.php';
    if (class_exists('WooCommerce')) {
        require_once WEDDING_BOOKING_DIR . 'includes/woocommerce.php';
        // Customer side: My Account bookings, order-page panel, calendar file.
        require_once WEDDING_BOOKING_DIR . 'includes/account.php';
        // Booking details on PDF invoices (PDF Invoices & Packing Slips).
        require_once WEDDING_BOOKING_DIR . 'includes/pdf-invoices.php';
    }
}
