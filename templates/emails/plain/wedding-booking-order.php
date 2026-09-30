<?php

/**
 * Wedding Booking custom booking confirmation email (plain text).
 *
 * Plain-text counterpart of templates/emails/wedding-booking-order.php, laid out to
 * mirror it section for section. Nothing is escaped here: this is not an HTML
 * context, and escaping would leave the customer reading "&amp;" instead of "&".
 *
 * @package Wedding Booking
 */

if (! defined('ABSPATH')) {
    exit;
}

$wedding_booking_settings = wedding_booking_get_order_email_settings();

echo "= " . wp_strip_all_tags($email_heading) . " =\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

echo wedding_booking_order_email_body_plain($order) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$wedding_booking_meta = wedding_booking_get_order_booking_meta($order);

// Feature the session date up top, mirroring the HTML highlight card.
$wedding_booking_pretty_date = wedding_booking_email_pretty_date($wedding_booking_meta['session_date']);
if ($wedding_booking_pretty_date !== '') {
    echo "\n" . strtoupper(wp_strip_all_tags(__('Your session date', 'wedding-booking'))) . ': ' . $wedding_booking_pretty_date . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$wedding_booking_facts = wedding_booking_email_plain_facts([
    ['label' => __('Session', 'wedding-booking'), 'value' => $wedding_booking_meta['session_type']],
    ['label' => __('Package', 'wedding-booking'), 'value' => $wedding_booking_meta['package_name']],
    ['label' => __('Add-ons', 'wedding-booking'), 'value' => $wedding_booking_meta['addons']],
    ['label' => __('Date', 'wedding-booking'), 'value' => $wedding_booking_meta['session_date']],
    ['label' => __('Time', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_event_time', true)],
    ['label' => __('Booking reference', 'wedding-booking'), 'value' => '#' . $order->get_order_number()],
]);

if ($wedding_booking_facts !== '') {
    echo wedding_booking_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags(__('Your session', 'wedding-booking'))) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $wedding_booking_facts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

ob_start();

// Payment instructions from offline gateways (bank transfer account details,
// cheque, cash on delivery), mirroring the HTML template.
do_action('woocommerce_email_before_order_table', $order, $sent_to_admin, true, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.

if (! empty($wedding_booking_settings['order_table'])) {
    // Order summary — the branded money breakdown, mirroring the HTML panel.
    $wedding_booking_money = wedding_booking_email_plain_facts(wedding_booking_email_money_facts_rows($order));
    if ($wedding_booking_money !== '') {
        echo wedding_booking_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo strtoupper(wp_strip_all_tags(__('Order summary', 'wedding-booking'))) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $wedding_booking_money; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

// The remaining-balance CTA (and anything third parties append to the order
// table). Fired once, regardless of the order-summary toggle, so a deposit
// booking always tells the customer how to settle the rest.
echo "\n";
do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, true, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.

if (function_exists('wedding_booking_booking_manage_url') && ! $sent_to_admin) {
    echo "
" . esc_html__('Manage your booking', 'wedding-booking') . ': ' . esc_url_raw(wedding_booking_booking_manage_url($order)) . "
"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

// WooCommerce's plain templates leave prices as HTML entities (&#2547;);
// decode them so the customer reads the currency symbol itself.
echo html_entity_decode((string) ob_get_clean(), ENT_QUOTES, 'UTF-8'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if (! empty($additional_content)) {
    echo "\n\n" . wedding_booking_email_plain_from_html(wptexturize($additional_content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "\n\n" . wedding_booking_email_plain_from_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
