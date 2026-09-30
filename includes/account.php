<?php

/**
 * Wedding Booking for customers.
 *
 * - A "Bookings" tab in WooCommerce → My Account listing the customer's
 *   bookings (date, package, status, what's left to pay).
 * - A booking panel on the order pages (My Account → View order, and the
 *   order-received page guests reach from their email): pay the remaining
 *   balance, add the session to a calendar, and ask the studio for a
 *   reschedule or cancellation.
 * - A calendar file (.ics) per booking.
 *
 * Loaded only with WooCommerce.
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

function wedding_booking_account_endpoint()
{
    return 'wedding-bookings';
}

/**
 * My Account tab label. SnapBook adds its own "Bookings" tab when both
 * plugins share a site, so this one names itself.
 */
function wedding_booking_account_menu_label()
{
    return __('Wedding Bookings', 'wedding-booking');
}

/* ─────────────────────────────────────────────────────────────
   My Account → Bookings
───────────────────────────────────────────────────────────── */

add_action('init', 'wedding_booking_account_register_endpoint');
function wedding_booking_account_register_endpoint()
{
    add_rewrite_endpoint(wedding_booking_account_endpoint(), EP_ROOT | EP_PAGES);

    // A new endpoint needs the rewrite rules rebuilt — once per version.
    if (get_option('wedding_booking_rewrite_version') !== WEDDING_BOOKING_VER) {
        flush_rewrite_rules(false);
        update_option('wedding_booking_rewrite_version', WEDDING_BOOKING_VER);
    }
}

add_filter('woocommerce_get_query_vars', 'wedding_booking_account_query_vars');
function wedding_booking_account_query_vars($vars)
{
    $vars[wedding_booking_account_endpoint()] = wedding_booking_account_endpoint();
    return $vars;
}

add_filter('woocommerce_account_menu_items', 'wedding_booking_account_menu_item', 20);
function wedding_booking_account_menu_item($items)
{
    if ((int) wedding_booking_opt('wedding_booking_account_bookings_enable') !== 1) {
        return $items;
    }

    $out   = [];
    $added = false;
    foreach ($items as $key => $label) {
        if ($key === 'customer-logout' && ! $added) {
            $out[wedding_booking_account_endpoint()] = wedding_booking_account_menu_label();
            $added                           = true;
        }
        $out[$key] = $label;
        if ($key === 'orders' && ! $added) {
            $out[wedding_booking_account_endpoint()] = wedding_booking_account_menu_label();
            $added                           = true;
        }
    }
    if (! $added) {
        $out[wedding_booking_account_endpoint()] = wedding_booking_account_menu_label();
    }

    return $out;
}

add_filter('woocommerce_endpoint_wedding-bookings_title', 'wedding_booking_account_endpoint_title');
function wedding_booking_account_endpoint_title()
{
    return __('Your wedding bookings', 'wedding-booking');
}

add_action('woocommerce_account_wedding-bookings_endpoint', 'wedding_booking_account_bookings_page');
function wedding_booking_account_bookings_page()
{
    if ((int) wedding_booking_opt('wedding_booking_account_bookings_enable') !== 1) {
        echo '<p>' . esc_html__('Bookings are not shown here.', 'wedding-booking') . '</p>';
        return;
    }

    $rows = wedding_booking_customer_bookings(get_current_user_id());
    wedding_booking_account_styles();

    if (! $rows) {
        $url = function_exists('wedding_booking_get_booking_page_url') ? wedding_booking_get_booking_page_url() : '';
        echo '<div class="woocommerce-info">' . esc_html__('You have no bookings yet.', 'wedding-booking');
        if ($url !== '') {
            echo ' <a class="button" href="' . esc_url($url) . '">' . esc_html__('Book a session', 'wedding-booking') . '</a>';
        }
        echo '</div>';
        return;
    }

    $statuses = wedding_booking_booking_statuses();
    echo '<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders wedding-booking-acct-table"><thead><tr>';
    echo '<th>' . esc_html__('Session', 'wedding-booking') . '</th><th>' . esc_html__('Package', 'wedding-booking') . '</th><th>' . esc_html__('Status', 'wedding-booking') . '</th><th>' . esc_html__('Payment', 'wedding-booking') . '</th><th><span class="screen-reader-text">' . esc_html__('Actions', 'wedding-booking') . '</span></th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $order = wc_get_order((int) $row->order_id);
        if (! $order) {
            continue;
        }
        $money = wedding_booking_account_money_state($order);
        $when  = wedding_booking_email_pretty_date((string) $row->session_date);
        if ((string) $row->session_time !== '') {
            $when .= ' · ' . $row->session_time;
        }

        echo '<tr>';
        echo '<td data-title="' . esc_attr__('Session', 'wedding-booking') . '"><strong>' . esc_html($when) . '</strong></td>';
        echo '<td data-title="' . esc_attr__('Package', 'wedding-booking') . '">' . esc_html(trim($row->package_name . ($row->session_type !== '' ? ' — ' . $row->session_type : ''))) . '</td>';
        echo '<td data-title="' . esc_attr__('Status', 'wedding-booking') . '"><span class="wedding-booking-acct-status wedding-booking-acct-' . esc_attr($row->status) . '">' . esc_html($statuses[$row->status] ?? ucfirst((string) $row->status)) . '</span></td>';
        echo '<td data-title="' . esc_attr__('Payment', 'wedding-booking') . '">' . esc_html($money['summary']) . '</td>';
        echo '<td class="wedding-booking-acct-actions">';
        echo '<a class="woocommerce-button button" href="' . esc_url($order->get_view_order_url()) . '">' . esc_html__('View', 'wedding-booking') . '</a> ';
        if ($money['pay_url'] !== '') {
            echo '<a class="woocommerce-button button alt" href="' . esc_url($money['pay_url']) . '">' . esc_html__('Pay balance', 'wedding-booking') . '</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * A customer's booking rows (newest session first).
 *
 * @return object[]
 */
function wedding_booking_customer_bookings($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id < 1) {
        return [];
    }

    $order_ids = wc_get_orders([
        'customer_id' => $user_id,
        'limit'       => 200,
        'return'      => 'ids',
        'status'      => array_keys(wc_get_order_statuses()),
    ]);
    $order_ids = array_filter(array_map('intval', (array) $order_ids));
    if (! $order_ids) {
        return [];
    }

    global $wpdb;
    $in = implode(',', $order_ids);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids only, custom bookings table.
    return (array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wedding_booking_bookings WHERE order_id IN ({$in}) ORDER BY session_date DESC, id DESC");
}

/**
 * What the customer still owes on a booking, for display.
 *
 * @return array summary, pay_url ('' when nothing to pay), due, due_by.
 */
function wedding_booking_account_money_state($order)
{
    $symbol  = html_entity_decode((string) get_woocommerce_currency_symbol($order->get_currency()), ENT_QUOTES, 'UTF-8');
    $money   = static function ($v) use ($symbol) {
        return $symbol . number_format((float) $v, 2);
    };
    $figures = wedding_booking_get_booking_figures($order);
    $due     = wedding_booking_get_balance_order_for($order, false);
    $due_by  = (string) $order->get_meta('_wedding_booking_balance_due_date', true);

    if (! $order->is_paid()) {
        $pay = $order->needs_payment() ? $order->get_checkout_payment_url() : '';
        /* translators: %s: amount */
        return ['summary' => sprintf(__('%s to pay', 'wedding-booking'), $money($order->get_total())), 'pay_url' => $pay, 'due' => (float) $order->get_total(), 'due_by' => ''];
    }
    if ($due && $due->needs_payment()) {
        $summary = sprintf(
            /* translators: %s: balance amount */
            __('Balance %s due', 'wedding-booking'),
            $money($due->get_total())
        );
        if ($due_by !== '') {
            /* translators: %s: date */
            $summary .= ' ' . sprintf(__('by %s', 'wedding-booking'), wedding_booking_email_pretty_date($due_by));
        }
        return ['summary' => $summary, 'pay_url' => $due->get_checkout_payment_url(), 'due' => (float) $due->get_total(), 'due_by' => $due_by];
    }
    $total = $figures ? $figures['total'] : $order->get_total();
    /* translators: %s: amount */
    return ['summary' => sprintf(__('Paid · %s', 'wedding-booking'), $money($total)), 'pay_url' => '', 'due' => 0.0, 'due_by' => ''];
}

/* ─────────────────────────────────────────────────────────────
   Booking panel on the order pages
───────────────────────────────────────────────────────────── */

add_action('woocommerce_order_details_after_order_table', 'wedding_booking_account_order_panel', 20, 1);
function wedding_booking_account_order_panel($order)
{
    if (! $order || ! is_a($order, 'WC_Order') || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return;
    }
    // The embedded payment page shows its own confirmation.
    if (isset($_GET['wedding_booking_embed'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wedding_booking_bookings WHERE order_id = %d LIMIT 1", (int) $order->get_id())); // phpcs:ignore
    if (! $row) {
        return;
    }

    wedding_booking_account_styles();
    $statuses = wedding_booking_booking_statuses();
    $money    = wedding_booking_account_money_state($order);
    $active   = $row->status !== 'cancelled';
    $upcoming = (string) $row->session_date >= wp_date('Y-m-d');

    echo '<section class="wedding-booking-acct-panel" id="wedding-booking-booking">';
    echo '<h2>' . esc_html__('Your booking', 'wedding-booking') . '</h2>';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after our own redirect.
    if (isset($_GET['wedding_booking_request']) && sanitize_key(wp_unslash($_GET['wedding_booking_request'])) === 'sent') {
        echo '<div class="woocommerce-message" role="status">' . esc_html__('Thanks — your request has been sent. We\'ll get back to you soon.', 'wedding-booking') . '</div>';
    }

    echo '<dl class="wedding-booking-acct-facts">';
    $facts = [
        __('Date', 'wedding-booking')    => wedding_booking_email_pretty_date((string) $row->session_date),
        __('Start time', 'wedding-booking') => (string) $row->session_time,
        __('Package', 'wedding-booking') => trim($row->package_name . ($row->session_type !== '' ? ' — ' . $row->session_type : '')),
        __('Status', 'wedding-booking')  => $statuses[$row->status] ?? ucfirst((string) $row->status),
        __('Payment', 'wedding-booking') => $money['summary'],
    ];
    foreach ($facts as $label => $value) {
        if ((string) $value === '') {
            continue;
        }
        echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
    }
    echo '</dl>';

    echo '<p class="wedding-booking-acct-buttons">';
    if ($active && $money['pay_url'] !== '') {
        echo '<a class="button alt" href="' . esc_url($money['pay_url']) . '">' . esc_html__('Pay remaining balance', 'wedding-booking') . '</a> ';
    }
    if ($active) {
        echo '<a class="button" href="' . esc_url(wedding_booking_booking_ics_url($order)) . '">' . esc_html__('Add to my calendar', 'wedding-booking') . '</a>';
    }
    echo '</p>';

    if ($active && $upcoming && (int) wedding_booking_opt('wedding_booking_customer_requests_enable') === 1) {
        wedding_booking_account_request_form($order);
    }
    echo '</section>';
}

/**
 * "Need to change something?" — reschedule / cancel / other request.
 */
function wedding_booking_account_request_form($order)
{
    $last = $order->get_meta('_wedding_booking_change_request', true);
    if (is_array($last) && ! empty($last['at']) && (time() - (int) $last['at']) < DAY_IN_SECONDS) {
        echo '<p class="wedding-booking-acct-note">' . esc_html(sprintf(
            /* translators: %s: date and time */
            __('You sent us a change request on %s. We\'ll be in touch.', 'wedding-booking'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['at'])
        )) . '</p>';
        return;
    }

    $id = (int) $order->get_id();
    echo '<details class="wedding-booking-acct-request"><summary>' . esc_html__('Need to reschedule or cancel?', 'wedding-booking') . '</summary>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wedding_booking_booking_request">';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($id) . '">';
    echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '">';
    wp_nonce_field('wedding_booking_booking_request_' . $id, 'wedding_booking_request_nonce');
    echo '<p class="form-row"><label for="wedding-booking-req-type">' . esc_html__('What would you like?', 'wedding-booking') . '</label>';
    echo '<select id="wedding-booking-req-type" name="request_type">';
    echo '<option value="reschedule">' . esc_html__('Move my booking to another date', 'wedding-booking') . '</option>';
    echo '<option value="cancel">' . esc_html__('Cancel my booking', 'wedding-booking') . '</option>';
    echo '<option value="other">' . esc_html__('Something else', 'wedding-booking') . '</option>';
    echo '</select></p>';
    echo '<p class="form-row"><label for="wedding-booking-req-date">' . esc_html__('Preferred new date (if moving)', 'wedding-booking') . '</label>';
    echo '<input id="wedding-booking-req-date" type="date" name="preferred_date" min="' . esc_attr(wp_date('Y-m-d')) . '"></p>';
    echo '<p class="form-row"><label for="wedding-booking-req-msg">' . esc_html__('Message', 'wedding-booking') . ' <span class="required">*</span></label>';
    echo '<textarea id="wedding-booking-req-msg" name="message" rows="4" required maxlength="2000"></textarea></p>';
    echo '<p class="wedding-booking-acct-note">' . esc_html__('The studio will confirm by email. Cancelling doesn\'t refund payments automatically; our booking terms apply.', 'wedding-booking') . '</p>';
    echo '<p><button type="submit" class="button">' . esc_html__('Send request', 'wedding-booking') . '</button></p>';
    echo '</form></details>';
}

add_action('admin_post_wedding_booking_booking_request', 'wedding_booking_handle_booking_request');
add_action('admin_post_nopriv_wedding_booking_booking_request', 'wedding_booking_handle_booking_request');
function wedding_booking_handle_booking_request()
{
    $order_id = absint(wp_unslash($_POST['order_id'] ?? 0));
    if (! isset($_POST['wedding_booking_request_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wedding_booking_request_nonce'])), 'wedding_booking_booking_request_' . $order_id)) {
        wp_die(esc_html__('This form has expired. Please go back, reload the page and try again.', 'wedding-booking'), '', ['response' => 403, 'back_link' => true]);
    }

    $order = $order_id > 0 ? wc_get_order($order_id) : null;
    $key   = sanitize_text_field(wp_unslash($_POST['order_key'] ?? ''));
    $owner = $order && get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
    if (! $order || (! $owner && ($key === '' || ! hash_equals($order->get_order_key(), $key)))) {
        wp_die(esc_html__('We couldn\'t find that booking.', 'wedding-booking'), '', ['response' => 404, 'back_link' => true]);
    }
    if ((int) wedding_booking_opt('wedding_booking_customer_requests_enable') !== 1) {
        wp_die(esc_html__('Please contact the studio directly.', 'wedding-booking'), '', ['response' => 403, 'back_link' => true]);
    }

    $last = $order->get_meta('_wedding_booking_change_request', true);
    if (is_array($last) && ! empty($last['at']) && (time() - (int) $last['at']) < DAY_IN_SECONDS) {
        wp_safe_redirect(add_query_arg('wedding_booking_request', 'sent', wp_get_referer() ? wp_get_referer() : $order->get_view_order_url()) . '#wedding-booking-booking');
        exit;
    }

    $types   = [
        'reschedule' => __('Reschedule', 'wedding-booking'),
        'cancel'     => __('Cancellation', 'wedding-booking'),
        'other'      => __('Other change', 'wedding-booking'),
    ];
    $type    = sanitize_key(wp_unslash($_POST['request_type'] ?? 'other'));
    $type    = isset($types[$type]) ? $type : 'other';
    $date    = sanitize_text_field(wp_unslash($_POST['preferred_date'] ?? ''));
    $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
    if ($message === '') {
        wp_die(esc_html__('Please tell us what you would like to change.', 'wedding-booking'), '', ['response' => 400, 'back_link' => true]);
    }
    $message = mb_substr($message, 0, 2000);

    $order->update_meta_data('_wedding_booking_change_request', ['type' => $type, 'date' => $date, 'message' => $message, 'at' => time()]);
    $note = sprintf(
        /* translators: 1: request type, 2: preferred date or "—", 3: customer's message */
        __("Customer requested: %1\$s\nPreferred date: %2\$s\nMessage: %3\$s", 'wedding-booking'),
        $types[$type],
        $date !== '' ? wedding_booking_email_pretty_date($date) : '—',
        $message
    );
    $order->add_order_note($note);
    $order->save();

    $meta     = wedding_booking_get_order_booking_meta($order);
    $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $email    = sanitize_email($order->get_billing_email());

    // To the studio.
    $studio = sanitize_email((string) get_option('wedding_booking_admin_email', get_option('admin_email')));
    if ($studio !== '') {
        $content  = wedding_booking_email_title(sprintf(
            /* translators: 1: request type, 2: customer name */
            __('%1$s request from %2$s', 'wedding-booking'),
            $types[$type],
            $customer !== '' ? $customer : $email
        ));
        $content .= wedding_booking_email_facts([
            ['label' => __('Booking', 'wedding-booking'), 'value' => '#' . $order->get_order_number()],
            ['label' => __('Package', 'wedding-booking'), 'value' => $meta['package_name']],
            ['label' => __('Booked date', 'wedding-booking'), 'value' => wedding_booking_email_pretty_date($meta['session_date']), 'strong' => true],
            ['label' => __('Preferred new date', 'wedding-booking'), 'value' => $date !== '' ? wedding_booking_email_pretty_date($date) : ''],
            ['label' => __('Customer', 'wedding-booking'), 'value' => $email, 'url' => $email !== '' ? 'mailto:' . $email : ''],
        ]);
        $content .= wedding_booking_email_divider(20) . wedding_booking_email_text(nl2br(esc_html($message)), true);
        $content .= wedding_booking_email_button($order->get_edit_order_url(), __('Open the booking', 'wedding-booking'));
        wedding_booking_email_send(
            $studio,
            /* translators: 1: request type, 2: order number */
            sprintf(__('%1$s request for booking #%2$s', 'wedding-booking'), $types[$type], $order->get_order_number()),
            $content,
            ['eyebrow' => __('Booking change request', 'wedding-booking'), 'headers' => $email !== '' ? ['Reply-To: ' . $email] : []]
        );
    }

    // To the customer: a receipt.
    if ($email !== '') {
        $content  = wedding_booking_email_title(__('We\'ve received your request', 'wedding-booking'));
        $content .= wedding_booking_email_text(esc_html(sprintf(
            /* translators: %s: booked date */
            __('Thanks for letting us know. We\'ll look at your request about your booking on %s and reply by email soon. Your booking stays as it is until we confirm a change.', 'wedding-booking'),
            wedding_booking_email_pretty_date($meta['session_date'])
        )));
        $content .= wedding_booking_email_divider(20) . wedding_booking_email_text(nl2br(esc_html($message)), true);
        wedding_booking_email_send($email, __('Your booking change request', 'wedding-booking'), $content, ['eyebrow' => __('Booking change request', 'wedding-booking')]);
    }

    /**
     * A customer asked to reschedule / cancel / change a booking.
     *
     * @param WC_Order $order
     * @param array    $request type, date, message.
     */
    do_action('wedding_booking_booking_change_requested', $order, ['type' => $type, 'date' => $date, 'message' => $message]);

    $back = wp_get_referer() ? wp_get_referer() : ($owner ? $order->get_view_order_url() : $order->get_checkout_order_received_url());
    wp_safe_redirect(add_query_arg('wedding_booking_request', 'sent', remove_query_arg('wedding_booking_request', $back)) . '#wedding-booking-booking');
    exit;
}

/**
 * Where a customer manages a booking: My Account for registered customers,
 * the order-received page (secured by the order key) for guests.
 */
function wedding_booking_booking_manage_url($order)
{
    if (! $order) {
        return '';
    }
    $url = (int) $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url();

    return $url . '#wedding-booking-booking';
}

/* ─────────────────────────────────────────────────────────────
   Calendar file (.ics)
───────────────────────────────────────────────────────────── */

function wedding_booking_booking_ics_url($order)
{
    return add_query_arg(['wedding_booking_ics' => (int) $order->get_id(), 'key' => $order->get_order_key()], home_url('/'));
}

add_action('template_redirect', 'wedding_booking_serve_booking_ics');
function wedding_booking_serve_booking_ics()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- secured by the order key, like WooCommerce's order-received page.
    if (empty($_GET['wedding_booking_ics'])) {
        return;
    }
    $order = wc_get_order(absint(wp_unslash($_GET['wedding_booking_ics'])));
    $key   = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    // phpcs:enable
    if (! $order || $key === '' || ! hash_equals($order->get_order_key(), $key)) {
        wp_die(esc_html__('We couldn\'t find that booking.', 'wedding-booking'), '', ['response' => 404]);
    }

    $ics = wedding_booking_booking_ics($order);
    if ($ics === '') {
        wp_die(esc_html__('This booking has no date yet.', 'wedding-booking'), '', ['response' => 404]);
    }

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="booking-' . (int) $order->get_id() . '.ics"');
    echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545 in wedding_booking_booking_ics().
    exit;
}

/**
 * The booking as an iCalendar file. '' without a usable date.
 */
function wedding_booking_booking_ics($order)
{
    $meta = wedding_booking_get_order_booking_meta($order);
    $date = (string) $meta['session_date'];
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return '';
    }
    $time  = (string) $order->get_meta('_wedding_booking_billing_event_time', true);
    $site  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $esc   = static function ($text) {
        $text = str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $text);
        return $text;
    };
    $utc   = static function ($iso) {
        $d = new DateTime($iso);
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Ymd\THis\Z');
    };

    $times = function_exists('wedding_booking_gcal_event_times') ? wedding_booking_gcal_event_times($date, $time) : null;
    if ($times) {
        $start = 'DTSTART:' . $utc($times['start']);
        $end   = 'DTEND:' . $utc($times['end']);
    } else {
        $next  = (new DateTimeImmutable($date))->modify('+1 day')->format('Ymd');
        $start = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $date);
        $end   = 'DTEND;VALUE=DATE:' . $next;
    }

    $summary     = trim(($meta['package_name'] !== '' ? $meta['package_name'] : __('Photo session', 'wedding-booking')) . ' — ' . $site);
    $description = implode("\n", array_filter([
        $meta['session_type'],
        $meta['addons'] !== '' ? __('Add-ons:', 'wedding-booking') . ' ' . $meta['addons'] : '',
        /* translators: %s: order number */
        sprintf(__('Booking #%s', 'wedding-booking'), $order->get_order_number()),
        wedding_booking_booking_manage_url($order),
    ]));
    $location = (string) $order->get_meta('_wedding_booking_billing_hotel_place', true);

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Wedding Booking//Booking//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:wedding-booking-' . (int) $order->get_id() . '@' . wp_parse_url(home_url(), PHP_URL_HOST),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        $start,
        $end,
        'SUMMARY:' . $esc($summary),
        'DESCRIPTION:' . $esc($description),
    ];
    if ($location !== '') {
        $lines[] = 'LOCATION:' . $esc($location);
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    // Fold long lines at 75 octets (RFC 5545 §3.1).
    $out = '';
    foreach ($lines as $line) {
        while (strlen($line) > 75) {
            $cut  = 75;
            // Don't split a multi-byte character.
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n";
            $line = ' ' . substr($line, $cut);
        }
        $out .= $line . "\r\n";
    }

    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Styles for the panel and the table (theme-neutral)
───────────────────────────────────────────────────────────── */
function wedding_booking_account_styles()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<style id="wedding-booking-account-css">'
        . '.wedding-booking-acct-panel{margin:2em 0;padding:1.25em 1.5em;border:1px solid rgba(0,0,0,.1);border-radius:8px}'
        . '.wedding-booking-acct-panel h2{margin-top:0}'
        . '.wedding-booking-acct-facts{display:grid;grid-template-columns:max-content 1fr;gap:.35em 1.5em;margin:0 0 1em}'
        . '.wedding-booking-acct-facts dt{font-weight:600}.wedding-booking-acct-facts dd{margin:0}'
        . '.wedding-booking-acct-buttons .button{margin:0 .4em .4em 0}'
        . '.wedding-booking-acct-request{margin-top:1em}.wedding-booking-acct-request summary{cursor:pointer;font-weight:600}'
        . '.wedding-booking-acct-request select,.wedding-booking-acct-request input,.wedding-booking-acct-request textarea{width:100%;max-width:32em}'
        . '.wedding-booking-acct-note{font-size:.9em;opacity:.8}'
        . '.wedding-booking-acct-status{display:inline-block;padding:.15em .6em;border-radius:99px;font-size:.85em;background:rgba(0,0,0,.06)}'
        . '.wedding-booking-acct-awaiting_payment{background:#fcf0d0}.wedding-booking-acct-pending_payment{background:#e3f0f4}'
        . '.wedding-booking-acct-confirmed,.wedding-booking-acct-completed{background:#dff3e5}.wedding-booking-acct-cancelled{background:#f6dcdc}'
        . '.wedding-booking-acct-actions .button{margin:0 .3em .3em 0}'
        . '@media (max-width:600px){.wedding-booking-acct-facts{grid-template-columns:1fr}}'
        . '</style>';
}
