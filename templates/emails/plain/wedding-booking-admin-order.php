<?php

/**
 * Wedding Booking admin "New booking" notification (plain text).
 *
 * Plain-text counterpart of templates/emails/wedding-booking-admin-order.php, laid
 * out section for section. Nothing is escaped here: this is not an HTML
 * context, and escaping would leave the admin reading "&amp;" instead of "&".
 *
 * @package Wedding Booking
 */

if (! defined('ABSPATH')) {
    exit;
}

echo "= " . wp_strip_all_tags($email_heading) . " =\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$wedding_booking_intro = wedding_booking_email_plain_from_html(wedding_booking_admin_email_intro_html($order));
if ($wedding_booking_intro !== '') {
    echo $wedding_booking_intro . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$wedding_booking_sections = [
    __('Booking', 'wedding-booking')  => wedding_booking_email_plain_facts(wedding_booking_email_booking_facts_rows($order)),
    __('Customer', 'wedding-booking') => wedding_booking_email_plain_facts(wedding_booking_email_admin_contact_rows($order)),
    __('Payment', 'wedding-booking')  => wedding_booking_email_plain_facts(wedding_booking_email_admin_payment_facts_rows($order)),
];

foreach ($wedding_booking_sections as $wedding_booking_label => $wedding_booking_facts) {
    if (trim($wedding_booking_facts) === '') {
        continue;
    }
    echo wedding_booking_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags($wedding_booking_label)) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $wedding_booking_facts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$wedding_booking_note = wedding_booking_admin_email_customer_note($order);
if ($wedding_booking_note !== '') {
    echo wedding_booking_email_plain_rule(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo strtoupper(wp_strip_all_tags(__('Customer note', 'wedding-booking'))) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $wedding_booking_note . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "\n\n" . wedding_booking_email_plain_from_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
