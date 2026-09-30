<?php

/**
 * Wedding Booking admin "New booking" notification (HTML).
 *
 * Replaces WooCommerce's admin New Order email body when the branded admin
 * email is enabled under Wedding Booking → Settings → Emails → New-booking alert. It gives the
 * studio the full picture of a booking in one place: what was booked, who
 * booked it, the money breakdown (deposit taken vs. balance still due), any
 * note the customer left, and a one-click link to manage the order.
 *
 * The document shell, masthead and footer come from Wedding Booking's email design
 * system (includes/emails.php); every element is inline-styled so WooCommerce's
 * CSS inliner leaves the design untouched.
 *
 * Available from wc_get_template(): $order, $email_heading, $sent_to_admin,
 * $plain_text, $email, $additional_content.
 *
 * @package Wedding Booking
 */

if (! defined('ABSPATH')) {
    exit;
}

$wedding_booking_content = '';

$wedding_booking_status_label = $order->is_paid()
    ? __('New booking — paid', 'wedding-booking')
    : __('New booking — awaiting payment', 'wedding-booking');

$wedding_booking_content .= wedding_booking_email_pill($wedding_booking_status_label, 'primary');
$wedding_booking_content .= wedding_booking_email_title(wp_strip_all_tags($email_heading));

// The admin's own intro note (optional).
$wedding_booking_intro = wedding_booking_admin_email_intro_html($order);
if ($wedding_booking_intro !== '') {
    $wedding_booking_content .= wedding_booking_email_rich_text($wedding_booking_intro);
}

// What was booked.
$wedding_booking_booking = wedding_booking_email_booking_facts_html($order);
if ($wedding_booking_booking !== '') {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_section_label(__('Booking', 'wedding-booking'));
    $wedding_booking_content .= $wedding_booking_booking;
}

// Who booked it — actionable contact (clickable email + WhatsApp), place of
// stay, address, country and any custom fields.
$wedding_booking_customer = wedding_booking_email_admin_contact_html($order);
if ($wedding_booking_customer !== '') {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_section_label(__('Customer', 'wedding-booking'));
    $wedding_booking_content .= $wedding_booking_customer;
}

// The money — deposit taken vs. balance still to collect.
$wedding_booking_payment = wedding_booking_email_admin_payment_facts_html($order);
if ($wedding_booking_payment !== '') {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_section_label(__('Payment', 'wedding-booking'));
    $wedding_booking_content .= $wedding_booking_payment;
}

// Anything the customer typed in the notes field, highlighted so it is seen.
$wedding_booking_note = wedding_booking_admin_email_customer_note($order);
if ($wedding_booking_note !== '') {
    $wedding_booking_content .= wedding_booking_email_spacer(22);
    $wedding_booking_content .= wedding_booking_email_callout([
        'title' => __('Customer note', 'wedding-booking'),
        'text'  => $wedding_booking_note,
    ]);
}

$wedding_booking_meta = wedding_booking_get_order_booking_meta($order);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own data.
echo wedding_booking_email_wrap($wedding_booking_content, [
    'preheader' => trim(sprintf(
        /* translators: 1: package name or order number, 2: session date */
        __('%1$s — %2$s', 'wedding-booking'),
        $wedding_booking_meta['package_name'] !== '' ? $wedding_booking_meta['package_name'] : ('#' . $order->get_order_number()),
        $wedding_booking_meta['session_date']
    ), " —\t\n"),
    'eyebrow'   => __('New booking', 'wedding-booking'),
]);
