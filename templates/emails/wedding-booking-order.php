<?php

/**
 * Wedding Booking custom booking confirmation email (HTML).
 *
 * Replaces WooCommerce's customer order email body when the custom message
 * is enabled under Wedding Booking → Settings → Emails → Customer booking confirmation, so the customer sees
 * only the admin's wording instead of it plus WooCommerce's default copy.
 *
 * The whole document — shell, masthead, footer — comes from Wedding Booking's email
 * design system (includes/emails.php) rather than WooCommerce's header and
 * footer templates, so the layout is designed end to end. WooCommerce still
 * runs its CSS inliner over the result; every element here carries its own
 * inline styles, which win over anything the inliner adds.
 *
 * Available from wc_get_template(): $order, $email_heading, $sent_to_admin,
 * $plain_text, $email, $additional_content.
 *
 * @package Wedding Booking
 */

if (! defined('ABSPATH')) {
    exit;
}

$wedding_booking_settings = wedding_booking_get_order_email_settings();
$wedding_booking_content  = '';

$wedding_booking_meta = wedding_booking_get_order_booking_meta($order);

// Eye-catching hero — the heading, centered.
$wedding_booking_content .= '<div style="text-align:center;">';
$wedding_booking_content .= wedding_booking_email_title(wp_strip_all_tags($email_heading));
$wedding_booking_content .= '</div>';

// The admin's message. Sanitised with wp_kses_post on save and again when
// built, so it is safe rich text; the wrapper only adds email-safe styling.
$wedding_booking_content .= wedding_booking_email_rich_text(wedding_booking_order_email_body_html($order));

// Feature the session date — the one fact the customer looks for first.
$wedding_booking_pretty_date = wedding_booking_email_pretty_date($wedding_booking_meta['session_date']);
if ($wedding_booking_pretty_date !== '') {
    $wedding_booking_date_sub = trim(implode('  ·  ', array_filter([$wedding_booking_meta['session_type'], $wedding_booking_meta['package_name']])));
    $wedding_booking_content .= wedding_booking_email_highlight(__('Your session date', 'wedding-booking'), $wedding_booking_pretty_date, $wedding_booking_date_sub);
}

// What was booked — the finer detail beneath the headline date.
$wedding_booking_booking_facts = wedding_booking_email_booking_facts_html($order);
if ($wedding_booking_booking_facts !== '') {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_section_label(__('Your session', 'wedding-booking'));
    $wedding_booking_content .= $wedding_booking_booking_facts;
}

// Payment instructions from offline gateways (bank transfer account details,
// cheque, cash on delivery) and anything else hooked before the order table.
// Without this, a bank-transfer customer never learns where to pay.
ob_start();
do_action('woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
$wedding_booking_before_table = trim((string) ob_get_clean());
if ($wedding_booking_before_table !== '') {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= '<div style="font-size:14px;line-height:1.6;color:' . esc_attr(wedding_booking_email_palette()['text']) . ';">' . $wedding_booking_before_table . '</div>';
}

if (! empty($wedding_booking_settings['order_table'])) {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_section_label(__('Order summary', 'wedding-booking'));
    // Branded totals panel (booking total / deposit / balance) in the same
    // label/value design as the rest of the email, matching the admin notice.
    $wedding_booking_content .= wedding_booking_email_money_facts_html($order);
}

// The remaining-balance CTA and anything third parties append to the order
// table. Fired even without the order summary above, so a deposit booking
// always tells the customer how to settle the rest.
ob_start();
do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
$wedding_booking_after_table = trim((string) ob_get_clean());
if ($wedding_booking_after_table !== '') {
    $wedding_booking_content .= $wedding_booking_after_table;
}

// Where the customer can see the booking, add it to a calendar or ask for a change.
if (function_exists('wedding_booking_booking_manage_url') && ! $sent_to_admin) {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_text(
        '<a href="' . esc_url(wedding_booking_booking_manage_url($order)) . '" style="color:' . esc_attr(wedding_booking_email_palette()['accent_dk']) . ';font-weight:600;">' . esc_html__('Manage your booking', 'wedding-booking') . '</a> &mdash; '
        . esc_html__('add it to your calendar, pay what is left, or ask us for a change.', 'wedding-booking'),
        true
    );
}

if (! empty($additional_content)) {
    $wedding_booking_content .= wedding_booking_email_divider(24);
    $wedding_booking_content .= wedding_booking_email_rich_text(wp_kses_post(wpautop(wptexturize($additional_content))));
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Components escape their own data.
echo wedding_booking_email_wrap($wedding_booking_content, [
    'preheader' => trim(sprintf(
        /* translators: 1: package name, 2: session date */
        __('%1$s — %2$s', 'wedding-booking'),
        $wedding_booking_meta['package_name'] !== '' ? $wedding_booking_meta['package_name'] : get_bloginfo('name'),
        $wedding_booking_meta['session_date']
    ), " —\t\n"),
    'eyebrow'   => __('Booking confirmation', 'wedding-booking'),
]);
