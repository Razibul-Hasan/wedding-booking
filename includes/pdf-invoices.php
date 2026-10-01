<?php

/**
 * Compatibility with "PDF Invoices & Packing Slips for WooCommerce"
 * (WP Overnight).
 *
 * The invoice already picks up two things from woocommerce.php without help:
 * the add-ons under the line item (woocommerce_display_item_meta) and the
 * booking total / deposit / balance rows (woocommerce_get_order_item_totals).
 * What it lacks is the booking itself, because Wedding Booking keeps it in
 * hidden (underscore) meta. This file adds:
 *
 * - "Session date" and "Session time" beside the invoice number and date, and
 *   for a balance order, the booking it settles.
 * - A "Booking details" table above the items: session, package, location,
 *   guests, room, stay period, custom checkout fields and the Terms &
 *   Conditions record.
 *
 * Every hook here belongs to the PDF plugin, so nothing runs without it.
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

/**
 * PDF document types that get the booking details. Packing slips are left
 * out, since nothing is shipped for a wedding booking.
 */
function wedding_booking_pdf_document_types()
{
    return (array) apply_filters('wedding_booking_pdf_document_types', ['invoice', 'proforma']);
}

/**
 * The booking order behind a PDF document's order, or null when the order
 * carries no wedding booking.
 *
 * A balance order holds only a copy of the package name and date, so its
 * parent (the order that created the booking) supplies the full details.
 * Credit notes are refunds; their parent order is the booking.
 *
 * @return WC_Order|null
 */
function wedding_booking_pdf_booking_order($order)
{
    if (! is_a($order, 'WC_Abstract_Order')) {
        return null;
    }
    if (is_a($order, 'WC_Order_Refund')) {
        $order = wc_get_order($order->get_parent_id());
        if (! $order) {
            return null;
        }
    }
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        $parent = wc_get_order((int) $order->get_meta('_wedding_booking_parent_order_id', true));
        return $parent ? $parent : null;
    }

    return wedding_booking_is_booking_order($order) ? $order : null;
}

/**
 * Whether a PDF document should show booking details. On success, $booking
 * is set to the booking order.
 */
function wedding_booking_pdf_applies($document_type, $order, &$booking = null)
{
    if (! in_array($document_type, wedding_booking_pdf_document_types(), true)) {
        return false;
    }
    $booking = wedding_booking_pdf_booking_order($order);

    return $booking !== null;
}

/**
 * Session date and time for a booking order. An admin edit updates the line
 * item, so the item wins over the checkout fields stored on the order.
 *
 * @return array{date:string,time:string}
 */
function wedding_booking_pdf_session_when($booking)
{
    $meta = wedding_booking_get_order_booking_meta($booking);
    $item = wedding_booking_get_booking_item($booking);
    $time = $item ? (string) $item->get_meta('_wedding_booking_session_time') : '';
    if ($time === '') {
        $time = (string) $booking->get_meta('_wedding_booking_billing_event_time', true);
    }

    return [
        'date' => wedding_booking_email_pretty_date($meta['session_date']),
        'time' => $time,
    ];
}

/**
 * Label/value rows for the "Booking details" table. Empty values are
 * dropped when the table is drawn.
 *
 * @param WC_Order $booking  The booking order.
 * @param WC_Order $order    The order the document is for (may be a balance order).
 * @return array<int,array{label:string,value:string}>
 */
function wedding_booking_pdf_booking_rows($booking, $order)
{
    $meta = wedding_booking_get_order_booking_meta($booking);
    $item = wedding_booking_get_booking_item($booking);

    $location = $item ? (string) $item->get_meta('_wedding_booking_location_pref') : '';
    if ($location === '') {
        $location = (string) $booking->get_meta('_wedding_booking_billing_hotel_place', true);
    }

    $rows = [
        ['label' => __('Session', 'wedding-booking'), 'value' => $meta['session_type']],
        ['label' => __('Package', 'wedding-booking'), 'value' => $meta['package_name']],
    ];
    // On the booking order the add-ons already sit under the line item. A
    // balance order's line item has none, so list them here instead.
    if ($booking !== $order) {
        $rows[] = ['label' => __('Add-ons', 'wedding-booking'), 'value' => $meta['addons']];
    }
    // Session date and time sit beside the invoice number instead
    // (wedding_booking_pdf_order_data_rows()), so they aren't repeated here.
    $rows = array_merge($rows, [
        ['label' => __('Location', 'wedding-booking'), 'value' => $location],
        ['label' => __('Guests', 'wedding-booking'), 'value' => (string) $booking->get_meta('_wedding_booking_billing_participants', true)],
        ['label' => __('Room', 'wedding-booking'), 'value' => (string) $booking->get_meta('_wedding_booking_billing_room_number', true)],
        ['label' => __('Stay period', 'wedding-booking'), 'value' => (string) $booking->get_meta('_wedding_booking_billing_stay_period', true)],
    ]);

    // Admin-defined custom checkout fields.
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $field) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => (string) $booking->get_meta('_wedding_booking_cf_' . $key, true),
            ];
        }
    }

    // Record of the Terms & Conditions the customer accepted, if any.
    $accepted_at = (string) $booking->get_meta('_wedding_booking_contract_accepted_at', true);
    if ($accepted_at !== '') {
        $terms     = get_date_from_gmt($accepted_at, get_option('date_format') . ' ' . get_option('time_format'));
        $signature = (string) $booking->get_meta('_wedding_booking_contract_signature', true);
        if ($signature !== '') {
            /* translators: 1: date and time, 2: typed signature */
            $terms = sprintf(__('%1$s, signed "%2$s"', 'wedding-booking'), $terms, $signature);
        }
        $rows[] = ['label' => __('Terms accepted', 'wedding-booking'), 'value' => $terms];
    }

    $rows = (array) apply_filters('wedding_booking_pdf_booking_rows', $rows, $booking, $order);

    return array_values(array_filter($rows, static function ($row) {
        return isset($row['value']) && trim((string) $row['value']) !== '';
    }));
}

/* ═══════════════════════════════════════════════════════════════
   Session date beside the invoice number and date
═══════════════════════════════════════════════════════════════ */
add_action('wpo_wcpdf_after_order_data', 'wedding_booking_pdf_order_data_rows', 10, 2);
function wedding_booking_pdf_order_data_rows($document_type, $order)
{
    $booking = null;
    if (! wedding_booking_pdf_applies($document_type, $order, $booking)) {
        return;
    }

    $rows = [];
    if ($booking !== $order && (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        $rows['wedding-booking-balance-for'] = [
            __('Balance for booking:', 'wedding-booking'),
            '#' . $booking->get_order_number(),
        ];
    }
    $when = wedding_booking_pdf_session_when($booking);
    $rows['wedding-booking-session-date'] = [__('Session date:', 'wedding-booking'), $when['date']];
    $rows['wedding-booking-session-time'] = [__('Session time:', 'wedding-booking'), $when['time']];

    foreach ($rows as $class => $row) {
        if (trim((string) $row[1]) === '') {
            continue;
        }
        printf(
            '<tr class="%1$s"><th>%2$s</th><td>%3$s</td></tr>',
            esc_attr($class),
            esc_html($row[0]),
            esc_html($row[1])
        );
    }
}

/* ═══════════════════════════════════════════════════════════════
   "Booking details" table above the line items
═══════════════════════════════════════════════════════════════ */
add_action('wpo_wcpdf_before_order_details', 'wedding_booking_pdf_booking_details', 10, 2);
function wedding_booking_pdf_booking_details($document_type, $order)
{
    $booking = null;
    if (! wedding_booking_pdf_applies($document_type, $order, $booking)) {
        return;
    }
    $rows = wedding_booking_pdf_booking_rows($booking, $order);
    if (empty($rows)) {
        return;
    }

    // Two label/value pairs per row keeps the table short on the page.
    echo '<h3 class="wedding-booking-booking-title">' . esc_html__('Booking details', 'wedding-booking') . '</h3>';
    echo '<table class="wedding-booking-booking-details"><tbody>';
    foreach (array_chunk($rows, 2) as $pair) {
        echo '<tr>';
        foreach ($pair as $row) {
            echo '<th>' . esc_html($row['label']) . '</th><td>' . esc_html($row['value']) . '</td>';
        }
        if (count($pair) === 1) {
            echo '<th></th><td></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/* ═══════════════════════════════════════════════════════════════
   Styles. Kept neutral (greys, the template's own type) so the
   table sits naturally in the Simple template and its derivatives.
═══════════════════════════════════════════════════════════════ */
add_action('wpo_wcpdf_custom_styles', 'wedding_booking_pdf_styles', 10, 2);
function wedding_booking_pdf_styles($document_type, $document = null)
{
    if (! in_array($document_type, wedding_booking_pdf_document_types(), true)) {
        return;
    }
    ?>
    h3.wedding-booking-booking-title {
        margin: 0 0 2mm;
    }
    table.wedding-booking-booking-details {
        width: 100%;
        margin-bottom: 8mm;
        border-collapse: collapse;
    }
    table.wedding-booking-booking-details th,
    table.wedding-booking-booking-details td {
        padding: 0.375em;
        border-bottom: 1px solid #ccc;
        text-align: left;
        vertical-align: top;
        overflow-wrap: anywhere;
    }
    table.wedding-booking-booking-details th {
        width: 18%;
        font-weight: bold;
    }
    table.wedding-booking-booking-details td {
        width: 32%;
    }
    <?php
}
