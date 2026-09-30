<?php

/**
 * Wedding Booking → All Bookings.
 *
 * The list (search, filters, sort, pagination), the month calendar, the CSV
 * export, adding a booking by hand, editing / rescheduling, recording a
 * payment, status changes and re-syncing Google Calendar.
 *
 * Required at the end of includes/admin.php. Everything that touches orders
 * needs WooCommerce and checks for it.
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   Small helpers
═══════════════════════════════════════════════════════════════ */

/**
 * An amount as plain text in the store currency ("৳1,234.00"), for labels,
 * confirm dialogs and emails. Escape it where it is output.
 */
function wedding_booking_admin_money($amount)
{
    $amount = (float) $amount;
    if (function_exists('wc_price')) {
        return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8'));
    }
    $symbol = function_exists('wedding_booking_get_currency_symbol') ? wedding_booking_get_currency_symbol() : '';

    return html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8') . number_format($amount, 2);
}

/**
 * "2026-10-03" → the site's date format. Anything else comes back as is.
 */
function wedding_booking_admin_date_label($date, $format = '')
{
    $date = (string) $date;
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    $format = $format !== '' ? $format : (string) get_option('date_format', 'F j, Y');

    return (string) wp_date($format, (int) strtotime($date . ' 00:00:00 UTC'), new DateTimeZone('UTC'));
}

/**
 * A start time in the site's time format ("13:00" → "1:00 pm"); free text
 * that isn't a clock time comes back trimmed.
 */
function wedding_booking_admin_time_label($time)
{
    $t = wedding_booking_normalize_time((string) $time);
    if ($t === '') {
        return trim((string) $time);
    }

    return (string) wp_date((string) get_option('time_format', 'H:i'), (int) strtotime('1970-01-01 ' . $t . ':00 UTC'), new DateTimeZone('UTC'));
}

/**
 * "October 3, 2026, 1:00 pm" (or just the date).
 */
function wedding_booking_admin_when_label($date, $time = '')
{
    $label = wedding_booking_admin_date_label($date);
    $t     = wedding_booking_admin_time_label($time);
    if ($label === '') {
        $label = __('no date', 'wedding-booking');
    }

    /* translators: 1: session date, 2: start time */
    return $t !== '' ? sprintf(__('%1$s, %2$s', 'wedding-booking'), $label, $t) : $label;
}

/**
 * A start time as stored: "HH:MM" with start times set up, else the clock
 * time when it parses as one, else the (short) free text.
 */
function wedding_booking_admin_clean_time($time)
{
    $time = trim(sanitize_text_field((string) $time));
    $norm = wedding_booking_normalize_time($time);
    if (wedding_booking_slots_enabled() || $norm !== '') {
        return $norm;
    }

    return mb_substr($time, 0, 100);
}

/**
 * How the studio says a payment arrived. Keys are stored in order notes only.
 */
function wedding_booking_admin_payment_methods()
{
    return [
        'cash'  => __('Cash', 'wedding-booking'),
        'bank'  => __('Bank transfer', 'wedding-booking'),
        'card'  => __('Card terminal', 'wedding-booking'),
        'other' => __('Other', 'wedding-booking'),
    ];
}

/**
 * Who is doing this, for order notes.
 */
function wedding_booking_admin_user_label()
{
    $user = wp_get_current_user();

    return ($user && $user->exists()) ? $user->display_name : __('an administrator', 'wedding-booking');
}

/**
 * Booking status key, with the legacy 'pending' read as 'pending_payment'.
 */
function wedding_booking_admin_status_key($status)
{
    $status = (string) $status;

    return $status === 'pending' ? 'pending_payment' : $status;
}

function wedding_booking_admin_status_label($status)
{
    $status   = wedding_booking_admin_status_key($status);
    $statuses = wedding_booking_booking_statuses();

    return $statuses[$status] ?? ucwords(str_replace('_', ' ', $status));
}

/**
 * Whether a WooCommerce email is switched on (by its id, e.g.
 * 'customer_completed_order'), so confirm dialogs say what really happens.
 */
function wedding_booking_admin_wc_email_on($id)
{
    if (! function_exists('WC') || ! WC() || ! method_exists(WC(), 'mailer')) {
        return false;
    }
    foreach ((array) WC()->mailer()->get_emails() as $email) {
        if (is_object($email) && isset($email->id) && $email->id === $id) {
            return $email->is_enabled();
        }
    }

    return false;
}

/**
 * Stop WooCommerce's customer emails for one order for the rest of this
 * request (the studio unticked "Email the customer"). Left in place on
 * purpose: WooCommerce may send queued emails at the end of the request.
 */
function wedding_booking_admin_quiet_customer_emails($order_id)
{
    $GLOBALS['wedding_booking_quiet_orders'][(int) $order_id] = true;
    foreach (['customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice'] as $email_id) {
        if (! has_filter('woocommerce_email_enabled_' . $email_id, 'wedding_booking_admin_filter_quiet_email')) {
            add_filter('woocommerce_email_enabled_' . $email_id, 'wedding_booking_admin_filter_quiet_email', 99, 2);
        }
    }
}

function wedding_booking_admin_filter_quiet_email($enabled, $object = null)
{
    if ($enabled && is_a($object, 'WC_Order') && ! empty($GLOBALS['wedding_booking_quiet_orders'][(int) $object->get_id()])) {
        return false;
    }

    return $enabled;
}

/* ═══════════════════════════════════════════════════════════════
   Filters, queries, stats
═══════════════════════════════════════════════════════════════ */

/**
 * The list's filters, from the query string (or any array with the same keys).
 *
 * @return array s, status, pkg, from, to, sort, view, month, paged
 */
function wedding_booking_admin_booking_filters($src = null)
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
    $src = is_array($src) ? $src : $_GET;
    $get = static function ($key) use ($src) {
        return isset($src[$key]) && ! is_array($src[$key]) ? trim(sanitize_text_field(wp_unslash($src[$key]))) : '';
    };

    $status = sanitize_key($get('status'));
    if ($status === 'pending') {
        $status = 'pending_payment';
    }
    if ($status !== '' && ! array_key_exists($status, wedding_booking_booking_statuses())) {
        $status = '';
    }

    $is_date = static function ($d) {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    };
    $from = $is_date($get('from')) ? $get('from') : '';
    $to   = $is_date($get('to')) ? $get('to') : '';
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $sort = sanitize_key($get('sort'));
    if (! in_array($sort, ['booked_desc', 'booked_asc', 'session_asc', 'session_desc'], true)) {
        $sort = 'booked_desc';
    }

    $month = $get('month');
    if (! preg_match('/^(\d{4})-(\d{2})$/', $month, $mm) || (int) $mm[2] < 1 || (int) $mm[2] > 12) {
        $month = wp_date('Y-m');
    }

    return [
        's'      => mb_substr($get('s'), 0, 100),
        'status' => $status,
        'pkg'    => mb_substr($get('pkg'), 0, 100),
        'from'   => $from,
        'to'     => $to,
        'sort'   => $sort,
        'view'   => $get('view') === 'calendar' ? 'calendar' : 'list',
        'month'  => $month,
        'paged'  => max(1, absint($get('paged'))),
    ];
}

/**
 * SQL WHERE for the filters (already prepared — never run it through
 * $wpdb->prepare() again: search patterns contain "%").
 */
function wedding_booking_admin_bookings_where(array $f, $with_dates = true)
{
    global $wpdb;
    $where = ['1=1'];
    $args  = [];

    if ($f['status'] !== '') {
        if ($f['status'] === 'pending_payment') {
            $where[] = "status IN ('pending_payment','pending')";
        } else {
            $where[] = 'status = %s';
            $args[]  = $f['status'];
        }
    }

    if ($f['s'] !== '') {
        $like  = '%' . $wpdb->esc_like($f['s']) . '%';
        $parts = ['client_name LIKE %s', 'client_email LIKE %s', 'client_phone LIKE %s'];
        array_push($args, $like, $like, $like);

        // Phone numbers are typed every which way: also match on digits only.
        $digits = preg_replace('/\D/', '', $f['s']);
        if (strlen($digits) >= 4 && strlen($digits) >= strlen(preg_replace('/[\s+().-]/', '', $f['s']))) {
            $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(client_phone,' ',''),'-',''),'+',''),'(',''),')','') LIKE %s";
            $args[]  = '%' . $wpdb->esc_like($digits) . '%';
        }

        // Order number (the main order, or its balance order).
        $num = ltrim($f['s'], '#');
        if (ctype_digit($num) && (int) $num > 0) {
            $ids = [(int) $num];
            if (function_exists('wc_get_order')) {
                $order = wc_get_order((int) $num);
                if ($order && (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
                    $ids[] = (int) $order->get_meta('_wedding_booking_parent_order_id', true);
                }
            }
            foreach (array_filter($ids) as $id) {
                $parts[] = 'order_id = %d';
                $args[]  = $id;
            }
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    if ($f['pkg'] !== '') {
        $where[] = 'package_name = %s';
        $args[]  = $f['pkg'];
    }

    if ($with_dates) {
        if ($f['from'] !== '') {
            $where[] = 'session_date >= %s';
            $args[]  = $f['from'];
        }
        if ($f['to'] !== '') {
            $where[] = 'session_date <= %s';
            $args[]  = $f['to'];
        }
    }

    $sql = implode(' AND ', $where);

    return $args ? $wpdb->prepare($sql, $args) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments above.
}

function wedding_booking_admin_bookings_orderby($sort)
{
    switch ($sort) {
        case 'booked_asc':
            return 'created_at ASC, id ASC';
        case 'session_asc':
            return 'session_date IS NULL, session_date ASC, session_time ASC, id ASC';
        case 'session_desc':
            return 'session_date DESC, session_time DESC, id DESC';
        default:
            return 'created_at DESC, id DESC';
    }
}

/**
 * One page of bookings for the filters.
 *
 * @return array rows, total, pages, paged, per_page
 */
function wedding_booking_admin_query_bookings(array $f, $per_page = 25)
{
    global $wpdb;
    $table    = $wpdb->prefix . 'wedding_booking_bookings';
    $per_page = max(1, (int) $per_page);
    $where    = wedding_booking_admin_bookings_where($f);
    $orderby  = wedding_booking_admin_bookings_orderby($f['sort']);

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    $pages = max(1, (int) ceil($total / $per_page));
    $paged = min(max(1, (int) $f['paged']), $pages);
    $rows  = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT " . (int) $per_page . ' OFFSET ' . (int) (($paged - 1) * $per_page)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

    return [
        'rows'     => (array) $rows,
        'total'    => $total,
        'pages'    => $pages,
        'paged'    => $paged,
        'per_page' => $per_page,
    ];
}

/**
 * Counts per status and the booked value, over every booking.
 */
function wedding_booking_admin_booking_stats()
{
    global $wpdb;
    $table  = $wpdb->prefix . 'wedding_booking_bookings';
    $counts = [];
    foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status") as $row) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $key          = wedding_booking_admin_status_key($row->status);
        $counts[$key] = ($counts[$key] ?? 0) + (int) $row->c;
    }
    $value = (float) $wpdb->get_var("SELECT COALESCE(SUM(total),0) FROM {$table} WHERE status <> 'cancelled'"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

    return ['counts' => $counts, 'total' => (int) array_sum($counts), 'value' => $value];
}

/**
 * Package names used by bookings (the stored name, so renamed or deleted
 * packages still filter).
 */
function wedding_booking_admin_booking_package_names()
{
    global $wpdb;
    return array_map('strval', (array) $wpdb->get_col("SELECT DISTINCT package_name FROM {$wpdb->prefix}wedding_booking_bookings WHERE package_name <> '' ORDER BY package_name")); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * All Bookings URL with the given filters (empty ones dropped).
 */
function wedding_booking_admin_bookings_url(array $f, array $override = [])
{
    $f    = array_merge($f, $override);
    $args = ['page' => 'wedding-booking-bookings'];
    foreach (['view', 's', 'status', 'pkg', 'from', 'to', 'sort', 'month', 'paged'] as $key) {
        if (! isset($f[$key]) || $f[$key] === '' || $f[$key] === null) {
            continue;
        }
        if (($key === 'view' && $f[$key] === 'list') || ($key === 'sort' && $f[$key] === 'booked_desc') || ($key === 'paged' && (int) $f[$key] <= 1)) {
            continue;
        }
        if ($key === 'month' && ($f['view'] ?? '') !== 'calendar') {
            continue;
        }
        $args[$key] = $f[$key];
    }

    return add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php'));
}

/* ═══════════════════════════════════════════════════════════════
   One booking: its orders, what is due, and everything the screen needs
═══════════════════════════════════════════════════════════════ */

/**
 * @return array [WC_Order|null main, WC_Order|null balance]
 */
function wedding_booking_admin_booking_orders($b)
{
    if (empty($b->order_id) || ! function_exists('wc_get_order')) {
        return [null, null];
    }
    $main = wc_get_order((int) $b->order_id);
    if (! $main) {
        return [null, null];
    }
    $due    = null;
    $due_id = (int) $main->get_meta('_wedding_booking_due_order_id', true);
    if ($due_id < 1 && (float) $b->total - (float) $b->deposit > 0.01 && function_exists('wedding_booking_find_balance_order_id')) {
        $due_id = wedding_booking_find_balance_order_id($main->get_id());
    }
    if ($due_id > 0) {
        $due = wc_get_order($due_id);
    }

    return [$main, $due ? $due : null];
}

/**
 * What the customer still owes and on which order: the booking order while
 * it is unpaid, else an open balance order. Null when nothing is due.
 *
 * @return array|null kind ('main'|'balance'), order, amount
 */
function wedding_booking_admin_payment_due($main, $due)
{
    // Money the studio records itself carries no online-payment fee
    // (wedding_booking_admin_record_payment() removes it), so show it without.
    if ($main && $main->has_status(['pending', 'on-hold', 'failed']) && (float) $main->get_total() > 0) {
        $amount = (float) $main->get_total();
        $item   = function_exists('wedding_booking_get_booking_item') ? wedding_booking_get_booking_item($main) : null;
        $fee    = $item ? (float) $item->get_meta('_wedding_booking_fee_amount') : 0;
        if ($fee > 0) {
            $pct    = max(1, min(100, (int) $item->get_meta('_wedding_booking_deposit_pct')));
            $total  = max(0, round((float) $item->get_meta('_wedding_booking_total') - $fee, 2));
            $amount = $pct >= 100 ? $total : round($total * $pct / 100, 2);
        }
        return ['kind' => 'main', 'order' => $main, 'amount' => $amount];
    }
    if ($main && $main->is_paid() && $due && $due->has_status(['pending', 'on-hold', 'failed']) && (float) $due->get_total() > 0) {
        $amount = max(0, round((float) $due->get_total() - (float) $due->get_meta('_wedding_booking_fee_amount', true), 2));
        return ['kind' => 'balance', 'order' => $due, 'amount' => $amount];
    }

    return null;
}

/**
 * Add everything the list, the View window and the dialogs need to a
 * booking row. Customer data stays raw here: PHP output escapes it, and
 * admin.js escapes it (escHtml) before rendering.
 *
 * @param object $b         Row from {prefix}wedding_booking_bookings.
 * @param array  $contracts Collects the accepted contract wordings by version.
 */
/**
 * Why a balance reminder can't be emailed for this booking right now, or ''
 * when it can. Mirrors the checks in wedding_booking_send_balance_reminder_email()
 * so the View window only offers "Send reminder now" when it will work.
 *
 * @param string        $status Booking status key.
 * @param WC_Order|null $main   The booking (deposit) order.
 */
function wedding_booking_admin_reminder_block($status, $main)
{
    if ($status === 'cancelled') {
        return __('This booking is cancelled.', 'wedding-booking');
    }
    if (! $main) {
        return __('The booking order could not be found.', 'wedding-booking');
    }
    $due_id = (int) $main->get_meta('_wedding_booking_due_order_id', true);
    $due    = $due_id > 0 ? wc_get_order($due_id) : null;
    if (! $due) {
        return __('There is no balance order linked to this booking, so there is no payment link to send.', 'wedding-booking');
    }
    if (in_array($due->get_status(), ['processing', 'completed'], true)) {
        return __('The balance is already paid.', 'wedding-booking');
    }
    if (! $due->needs_payment()) {
        $state = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($due->get_status()) : $due->get_status();
        /* translators: %s: WooCommerce order status, e.g. "On hold" */
        return sprintf(__('The balance order is “%s”, so its payment link doesn\'t work. Set it back to Pending payment to send a reminder.', 'wedding-booking'), $state);
    }
    if (sanitize_email($main->get_billing_email()) === '') {
        return __('The customer has no email address on this booking.', 'wedding-booking');
    }

    return '';
}

function wedding_booking_admin_prepare_booking($b, &$contracts = [])
{
    [$main, $due] = wedding_booking_admin_booking_orders($b);
    $status     = wedding_booking_admin_status_key($b->status);
    $date_fmt   = (string) get_option('date_format');
    $dt_fmt     = $date_fmt . ' ' . get_option('time_format');
    $cur_disp   = html_entity_decode((string) wedding_booking_get_currency_symbol(), ENT_QUOTES, 'UTF-8');
    $item       = ($main && function_exists('wedding_booking_get_booking_item')) ? wedding_booking_get_booking_item($main) : null;

    $b->status_key      = $status;
    $b->checkout_fields = [];

    if ($main) {
        $country      = (string) $main->get_billing_country();
        $country_name = $country;
        if (function_exists('WC') && WC() && isset(WC()->countries->countries[$country])) {
            $country_name = (string) WC()->countries->countries[$country];
        }
        $b->checkout_fields = [
            'billing_first_name'   => (string) $main->get_billing_first_name(),
            'billing_last_name'    => (string) $main->get_billing_last_name(),
            'billing_company'      => (string) $main->get_billing_company(),
            'billing_country'      => $country,
            'billing_country_name' => $country_name,
            'billing_state'        => (string) $main->get_billing_state(),
            'billing_city'         => (string) $main->get_billing_city(),
            'billing_postcode'     => (string) $main->get_billing_postcode(),
            'billing_address_1'    => (string) $main->get_billing_address_1(),
            'billing_address_2'    => (string) $main->get_billing_address_2(),
            'billing_phone'        => (string) $main->get_billing_phone(),
            'billing_email'        => (string) $main->get_billing_email(),
            'billing_event_date'   => (string) $main->get_meta('_wedding_booking_billing_event_date', true),
            'billing_event_time'   => (string) $main->get_meta('_wedding_booking_billing_event_time', true),
            'billing_hotel_place'  => (string) $main->get_meta('_wedding_booking_billing_hotel_place', true),
            'billing_participants' => (string) $main->get_meta('_wedding_booking_billing_participants', true),
            'billing_room_number'  => (string) $main->get_meta('_wedding_booking_billing_room_number', true),
            'billing_stay_period'  => (string) $main->get_meta('_wedding_booking_billing_stay_period', true),
            'order_customer_note'  => (string) $main->get_customer_note(),
        ];
        if (function_exists('wedding_booking_get_custom_checkout_fields')) {
            foreach (wedding_booking_get_custom_checkout_fields() as $ckey => $cf) {
                $b->checkout_fields[$cf['label']] = (string) $main->get_meta('_wedding_booking_cf_' . $ckey, true);
            }
        }
    }

    // ── Money ──
    $total        = (float) $b->total;
    $deposit      = (float) $b->deposit;
    $balance      = max(0, round($total - $deposit, 2));
    $due_status   = $due ? (string) $due->get_status() : '';
    $balance_paid = in_array($due_status, ['processing', 'completed'], true);
    $main_paid    = $main ? $main->is_paid() : ! in_array($status, ['awaiting_payment'], true);
    $record       = wedding_booking_admin_payment_due($main, $due);

    $reminder_last  = '';
    $reminder_next  = '';
    $reminder_count = 0;
    $reminder_ago   = '';
    $reminder_fresh = false;
    if ($main && $balance > 0.01) {
        $last_ts = (int) $main->get_meta('_wedding_booking_reminder_last_ts', true);
        if ($last_ts < 1 && function_exists('wedding_booking_balance_reminder_legacy_ts')) {
            $last_ts = wedding_booking_balance_reminder_legacy_ts($main);
        }
        $reminder_last  = $last_ts > 0 ? wp_date($dt_fmt, $last_ts) : '';
        $reminder_ago   = $last_ts > 0 ? human_time_diff($last_ts, time()) : '';
        // Same 12-hour gap the automatic reminders keep between two emails.
        $reminder_fresh = $last_ts > 0 && (time() - $last_ts) < 12 * HOUR_IN_SECONDS;
        $reminder_count = (int) $main->get_meta('_wedding_booking_reminder_sent_count', true);
        if (! $balance_paid && $main_paid && function_exists('wedding_booking_balance_reminder_next')) {
            $next = wedding_booking_balance_reminder_next($main);
            if (! empty($next['ts'])) {
                $reminder_next  = $next['ts'] <= time() ? __('Due now, goes out with the next check', 'wedding-booking') : wp_date($dt_fmt, $next['ts']);
                $reminder_next .= ' · ' . ($next['type'] === 'before' ? __('before the photoshoot', 'wedding-booking') : __('repeating until paid', 'wedding-booking'));
            }
        }
    }

    $main_pay_link = ($main && $main->needs_payment()) ? (string) $main->get_checkout_payment_url() : '';
    $due_pay_link  = ($due && ! $balance_paid && $due->needs_payment()) ? (string) $due->get_checkout_payment_url() : '';

    $coupon   = $item ? (string) $item->get_meta('_wedding_booking_coupon_code') : '';
    $discount = $item ? (float) $item->get_meta('_wedding_booking_discount') : 0.0;
    $due_by   = $main ? (string) $main->get_meta('_wedding_booking_balance_due_date', true) : '';

    // "Send reminder now": only for a deposit booking whose balance is unpaid.
    $remind_block = ($balance > 0.01 && $main_paid && ! $balance_paid) ? wedding_booking_admin_reminder_block($status, $main) : '';
    $can_remind   = $balance > 0.01 && $main_paid && ! $balance_paid && $remind_block === '';

    $b->wedding_booking_payment = [
        'currency'         => $cur_disp,
        'total'            => $total,
        'deposit'          => $deposit,
        'balance'          => $balance,
        'pct'              => $total > 0 ? (int) round($deposit / $total * 100) : 100,
        'is_partial'       => $balance > 0.01 && $main_paid,
        'main_paid'        => $main_paid,
        'due_order_id'     => $due ? (int) $due->get_id() : 0,
        'due_status'       => $due_status,
        'due_status_label' => ($due_status !== '' && function_exists('wc_get_order_status_name')) ? wc_get_order_status_name($due_status) : '',
        'balance_paid'     => $balance_paid,
        'pay_link'         => $due_pay_link,
        'main_pay_link'    => $main_pay_link,
        'edit_link'        => $due ? (string) $due->get_edit_order_url() : '',
        'last_reminder'    => $reminder_last,
        'last_reminder_ago' => $reminder_ago,
        'last_reminder_recent' => $reminder_fresh,
        'reminder_count'   => $reminder_count,
        'next_reminder'    => $reminder_next,
        'can_remind'       => $can_remind,
        'remind_block'     => $remind_block,
        'remind_email'     => $main ? sanitize_email($main->get_billing_email()) : '',
        'total_label'      => wedding_booking_admin_money($total),
        'deposit_label'    => wedding_booking_admin_money($deposit),
        'balance_label'    => wedding_booking_admin_money($balance),
    ];

    // ── Contract record ──
    $contract = null;
    if ($main && (string) $main->get_meta('_wedding_booking_contract_accepted_at', true) !== '') {
        $version  = preg_replace('/[^a-f0-9]/', '', (string) $main->get_meta('_wedding_booking_contract_version', true));
        $contract = [
            'accepted_at' => (string) get_date_from_gmt((string) $main->get_meta('_wedding_booking_contract_accepted_at', true), $dt_fmt),
            'version'     => $version,
            'signature'   => (string) $main->get_meta('_wedding_booking_contract_signature', true),
            'ip'          => (string) $main->get_meta('_wedding_booking_contract_ip', true),
            'has_terms'   => false,
        ];
        if ($version !== '') {
            if (! array_key_exists($version, $contracts)) {
                $versions            = get_option('wedding_booking_contract_versions', []);
                $contracts[$version] = (is_array($versions) && isset($versions[$version]['text']))
                    ? ['title' => (string) ($versions[$version]['title'] ?? ''), 'html' => wp_kses_post((string) $versions[$version]['text']), 'saved' => (string) ($versions[$version]['saved'] ?? '')]
                    : null;
            }
            $contract['has_terms'] = ! empty($contracts[$version]);
        }
    }

    // ── Customer change request (My Account) ──
    $request = null;
    $raw_req = $main ? $main->get_meta('_wedding_booking_change_request', true) : null;
    if (is_array($raw_req) && ! empty($raw_req['at']) && empty($raw_req['handled'])) {
        $types   = [
            'reschedule' => __('Reschedule', 'wedding-booking'),
            'cancel'     => __('Cancellation', 'wedding-booking'),
            'other'      => __('Other change', 'wedding-booking'),
        ];
        $request = [
            'type'       => (string) ($raw_req['type'] ?? 'other'),
            'type_label' => $types[$raw_req['type'] ?? 'other'] ?? $types['other'],
            'date_label' => ! empty($raw_req['date']) ? wedding_booking_admin_date_label((string) $raw_req['date']) : '',
            'message'    => (string) ($raw_req['message'] ?? ''),
            'at_label'   => wp_date($dt_fmt, (int) $raw_req['at']),
        ];
    }

    // ── Edit form values ──
    $first = $main ? (string) $main->get_billing_first_name() : '';
    $last  = $main ? (string) $main->get_billing_last_name() : '';
    if ($first === '' && $last === '') {
        $parts = preg_split('/\s+/', trim((string) $b->client_name), 2);
        $first = (string) ($parts[0] ?? '');
        $last  = (string) ($parts[1] ?? '');
    }
    $notes = trim((string) $b->notes);
    if ($notes === '' && $main) {
        $notes = (string) $main->get_customer_note();
    }

    $booked = '';
    if ($main && $main->get_date_created()) {
        $booked = wp_date($date_fmt, $main->get_date_created()->getTimestamp());
    } elseif (! empty($b->created_at)) {
        $booked = date_i18n($date_fmt, strtotime((string) $b->created_at));
    }

    $gcal_connected = function_exists('wedding_booking_gcal_is_connected') && wedding_booking_gcal_is_connected();
    $gcal_retry     = get_option('wedding_booking_gcal_retry', []);

    $b->wedding_booking_view = [
        'status_label'        => wedding_booking_admin_status_label($status),
        'session_date_label'  => wedding_booking_admin_date_label((string) $b->session_date),
        'session_time_label'  => wedding_booking_admin_time_label((string) $b->session_time),
        'booked_label'        => $booked,
        'order_number'        => $main ? (string) $main->get_order_number() : '',
        'order_edit_url'      => $main ? (string) $main->get_edit_order_url() : '',
        'order_status_label'  => ($main && function_exists('wc_get_order_status_name')) ? wc_get_order_status_name($main->get_status()) : '',
        'due_order_number'    => $due ? (string) $due->get_order_number() : '',
        'coupon'              => $coupon,
        'discount_label'      => $discount > 0 ? wedding_booking_admin_money($discount) : '',
        'balance_due_label'   => ($due_by !== '' && $balance > 0.01) ? wedding_booking_admin_date_label($due_by) : '',
        'record'              => $record ? [
            'kind'         => $record['kind'],
            'amount'       => $record['amount'],
            'amount_label' => wedding_booking_admin_money($record['amount']),
            'order_id'     => (int) $record['order']->get_id(),
            'order_number' => (string) $record['order']->get_order_number(),
        ] : null,
        'contract'            => $contract,
        'request'             => $request,
        'gcal'                => [
            'connected' => $gcal_connected,
            'enabled'   => function_exists('wedding_booking_gcal_sync_enabled') && wedding_booking_gcal_sync_enabled(),
            'linked'    => trim((string) ($b->gcal_event_id ?? '')) !== '',
            'retrying'  => is_array($gcal_retry) && isset($gcal_retry[(int) $b->id]),
        ],
        'edit'                => [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) $b->client_email,
            'phone'      => (string) $b->client_phone,
            'notes'      => $notes,
            'date'       => (string) $b->session_date,
            'time'       => (string) $b->session_time,
        ],
        'can_edit'            => true,
    ];

    $b->wedding_booking_plan = wedding_booking_admin_status_plan($b, $main, $due);

    return $b;
}

/* ═══════════════════════════════════════════════════════════════
   Status changes
   ───────────────────────────────────────────────────────────────
   A status change never invents a payment. Money is logged with
   "Record payment" (or arrives through WooCommerce); the statuses
   that mean "paid" can only be chosen once it has been. The one
   exception is kept from 1.4: "Completed" settles an unpaid balance,
   and the confirm dialog says so in as many words.
═══════════════════════════════════════════════════════════════ */

/**
 * For every target status: may the studio choose it, what to say before
 * doing it, and why not.
 *
 * @return array status => [allowed, confirm, refuse, record]
 */
function wedding_booking_admin_status_plan($b, $main = null, $due = null)
{
    $current  = wedding_booking_admin_status_key($b->status);
    $name     = trim((string) $b->client_name) !== '' ? trim((string) $b->client_name) : __('this customer', 'wedding-booking');
    $when     = wedding_booking_admin_when_label((string) $b->session_date, (string) $b->session_time);
    $voided   = $main && $main->has_status(['cancelled', 'refunded']);
    $paid     = $main && $main->is_paid();
    $was_paid = $main && $main->get_date_paid();
    $due_open = $due && $due->has_status(['pending', 'failed', 'on-hold']);
    $due_paid = $due && $due->has_status(['processing', 'completed']);
    $balance  = max(0, round((float) $b->total - (float) $b->deposit, 2));

    $allow  = static function ($confirm = '', $record = false) {
        return ['allowed' => true, 'confirm' => $confirm, 'refuse' => '', 'record' => (bool) $record];
    };
    $refuse = static function ($message, $record = false) {
        return ['allowed' => false, 'confirm' => '', 'refuse' => $message, 'record' => (bool) $record];
    };

    /* translators: %s: customer name */
    $unpaid_msg    = sprintf(__('No payment has been recorded for %s\'s booking yet. Use “Record payment” to log the money you received: that marks it paid, emails the customer their confirmation and moves the booking on by itself.', 'wedding-booking'), $name);
    $cancelled_msg = __('This booking is cancelled. To bring it back together with its payment, set its WooCommerce order back to “Processing” under “WooCommerce orders (advanced)”, or add the booking again.', 'wedding-booking');

    $plan = [];
    foreach (array_keys(wedding_booking_booking_statuses()) as $target) {
        if ($target === $current) {
            $plan[$target] = $allow();
            continue;
        }

        // No WooCommerce order behind it (older enquiry data): a label only.
        if (! $main) {
            /* translators: 1: customer name, 2: session date */
            $plan[$target] = $target === 'cancelled' ? $allow(sprintf(__('Cancel %1$s\'s booking on %2$s? The date becomes free again.', 'wedding-booking'), $name, $when)) : $allow();
            continue;
        }

        switch ($target) {
            case 'cancelled':
                $plan[$target] = $allow(wedding_booking_admin_cancel_message($b, $main, $due, $name, $when));
                break;

            case 'completed':
                if ($voided || $current === 'cancelled') {
                    $plan[$target] = $refuse($cancelled_msg);
                } elseif (! $paid) {
                    $plan[$target] = $refuse($unpaid_msg, true);
                } else {
                    /* translators: 1: customer name, 2: session date */
                    $lines = [sprintf(__('Mark %1$s\'s booking on %2$s as completed?', 'wedding-booking'), $name, $when)];
                    if ($due_open) {
                        /* translators: 1: balance amount, 2: balance order number */
                        $lines[] = sprintf(__('The unpaid balance of %1$s (order #%2$s) will be recorded as collected. If you haven\'t received it, choose “Record payment” instead, or leave the booking as it is.', 'wedding-booking'), wedding_booking_admin_money($due->get_total()), $due->get_order_number());
                    }
                    $emails = (! $main->has_status('completed') || $due_open) && wedding_booking_admin_wc_email_on('customer_completed_order');
                    $lines[] = $emails ? __('WooCommerce emails the customer that their order is complete.', 'wedding-booking') : __('The customer is not emailed.', 'wedding-booking');
                    $plan[$target] = $allow(implode(' ', $lines), $due_open);
                }
                break;

            case 'confirmed':
                if ($voided || $current === 'cancelled') {
                    $plan[$target] = $refuse($cancelled_msg);
                } elseif (! $paid) {
                    $plan[$target] = $refuse($unpaid_msg, true);
                } elseif ($due_open) {
                    /* translators: 1: customer name, 2: balance amount, 3: balance order number */
                    $plan[$target] = $refuse(sprintf(__('%1$s still owes the balance of %2$s (order #%3$s). Use “Record payment” when you receive it: the booking then completes by itself.', 'wedding-booking'), $name, wedding_booking_admin_money($due->get_total()), $due->get_order_number()), true);
                } else {
                    $plan[$target] = $allow();
                }
                break;

            case 'pending_payment':
                if ($voided || $current === 'cancelled') {
                    $plan[$target] = $refuse($cancelled_msg);
                } elseif (! $paid) {
                    $plan[$target] = $refuse($unpaid_msg, true);
                } elseif ($balance <= 0.01) {
                    $plan[$target] = $refuse(__('This booking was paid in full, so there is no balance left to collect.', 'wedding-booking'));
                } elseif ($due_paid) {
                    /* translators: %s: balance order number */
                    $plan[$target] = $refuse(sprintf(__('The balance (order #%s) is already paid. If that was a mistake, change that order under “WooCommerce orders (advanced)” first.', 'wedding-booking'), $due->get_order_number()));
                } elseif ($due && $due->has_status('cancelled')) {
                    /* translators: 1: balance amount, 2: customer name, 3: balance order number */
                    $plan[$target] = $allow(sprintf(__('Reopen the balance of %1$s for %2$s? Balance order #%3$s becomes payable again, and the automatic balance reminders (when switched on) will email the customer.', 'wedding-booking'), wedding_booking_admin_money($due->get_total()), $name, $due->get_order_number()));
                } else {
                    $plan[$target] = $allow();
                }
                break;

            case 'awaiting_payment':
                if ($paid) {
                    /* translators: %s: customer name */
                    $plan[$target] = $refuse(sprintf(__('%s\'s booking has already been paid. To undo a payment, refund it in the WooCommerce order.', 'wedding-booking'), $name));
                } elseif ($voided || $current === 'cancelled') {
                    if ($was_paid) {
                        $plan[$target] = $refuse(__('This booking was paid before it was cancelled. To restore it, set its WooCommerce order back to “Processing” under “WooCommerce orders (advanced)”, so the payment is kept.', 'wedding-booking'));
                    } else {
                        /* translators: 1: customer name, 2: session date */
                        $plan[$target] = $allow(sprintf(__('Restore %1$s\'s booking on %2$s? The date is held again and the booking waits for payment; its payment link works again. The customer is not emailed.', 'wedding-booking'), $name, $when));
                    }
                } else {
                    $plan[$target] = $allow();
                }
                break;
        }
    }

    return $plan;
}

/**
 * The exact consequences of cancelling a booking, for its confirm dialog.
 */
function wedding_booking_admin_cancel_message($b, $main, $due, $name, $when)
{
    /* translators: 1: customer name, 2: session date */
    $lines   = [sprintf(__('Cancel %1$s\'s booking on %2$s?', 'wedding-booking'), $name, $when)];
    $lines[] = __('The date becomes free again.', 'wedding-booking');
    if ($due && $due->has_status(['pending', 'failed', 'on-hold'])) {
        /* translators: %s: balance order number */
        $lines[] = sprintf(__('The unpaid balance order #%s is cancelled.', 'wedding-booking'), $due->get_order_number());
    }
    if (trim((string) ($b->gcal_event_id ?? '')) !== '') {
        $lines[] = __('The Google Calendar event is removed.', 'wedding-booking');
    }
    $mailed  = $main->has_status(['processing', 'on-hold']) && wedding_booking_admin_wc_email_on('customer_cancelled_order');
    $lines[] = $mailed ? __('WooCommerce emails the customer that the order is cancelled.', 'wedding-booking') : __('The customer is not emailed.', 'wedding-booking');

    $paid = 0.0;
    if ($main->is_paid()) {
        $paid += (float) $main->get_total() - (float) $main->get_total_refunded();
    }
    if ($due && $due->has_status(['processing', 'completed'])) {
        $paid += (float) $due->get_total() - (float) $due->get_total_refunded();
    }
    if ($paid > 0.009) {
        /* translators: %s: amount already paid */
        $lines[] = sprintf(__('The %s already paid is NOT refunded automatically — refund it in the WooCommerce order if needed.', 'wedding-booking'), wedding_booking_admin_money($paid));
    }

    return implode(' ', $lines);
}

/**
 * Change a booking's status (Wedding Booking → Bookings) and carry it over to its
 * WooCommerce orders, following wedding_booking_admin_status_plan().
 *
 * @return array|WP_Error Statuses afterwards.
 */
function wedding_booking_admin_change_booking_status($booking_id, $status)
{
    global $wpdb;
    $table  = $wpdb->prefix . 'wedding_booking_bookings';
    $status = wedding_booking_admin_status_key(sanitize_key((string) $status));
    $b      = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $booking_id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    if (! $b) {
        return new WP_Error('wedding_booking_booking_missing', __('Booking not found. Reload the page and try again.', 'wedding-booking'));
    }
    $statuses = wedding_booking_booking_statuses();
    if (! isset($statuses[$status])) {
        return new WP_Error('wedding_booking_status', __('Unknown booking status.', 'wedding-booking'));
    }

    [$main, $due] = wedding_booking_admin_booking_orders($b);
    $plan = wedding_booking_admin_status_plan($b, $main, $due);
    if (empty($plan[$status]['allowed'])) {
        return new WP_Error('wedding_booking_status_refused', $plan[$status]['refuse'], ['record' => ! empty($plan[$status]['record'])]);
    }

    $current = wedding_booking_admin_status_key($b->status);
    $id      = (int) $b->id;
    /* translators: 1: new booking status, 2: user name */
    $note = sprintf(__('Booking status changed to “%1$s” by %2$s in Wedding Booking → Bookings.', 'wedding-booking'), $statuses[$status], wedding_booking_admin_user_label());

    if ($status !== $current) {
        if (! $main) {
            $wpdb->update($table, ['status' => $status], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($status === 'cancelled') {
                wedding_booking_release_booking_date((string) $b->session_date);
                do_action('wedding_booking_booking_cancelled', $id, 0); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Wedding Booking's own hook.
            } elseif ($current === 'cancelled') {
                wedding_booking_refresh_date_slot((string) $b->session_date);
                do_action('wedding_booking_booking_updated', $id, 0); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Wedding Booking's own hook.
            }
        } else {
            switch ($status) {
                case 'cancelled':
                    // The cancelled hook frees the date, voids the unpaid balance
                    // order and removes the calendar event.
                    if (! $main->has_status(['cancelled', 'refunded'])) {
                        $main->update_status('cancelled', $note);
                    } elseif (function_exists('wedding_booking_on_booking_order_voided')) {
                        wedding_booking_on_booking_order_voided($main->get_id());
                    }
                    $wpdb->update($table, ['status' => 'cancelled'], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    break;

                case 'completed':
                    // Shoot done and settled: an open balance counts as collected.
                    if ($due && $due->has_status(['pending', 'failed', 'on-hold'])) {
                        /* translators: %s: user name */
                        $due->update_status('completed', sprintf(__('Balance recorded as collected by %s when the booking was marked completed in Wedding Booking → Bookings.', 'wedding-booking'), wedding_booking_admin_user_label()));
                    }
                    $main = wc_get_order($main->get_id());
                    if ($main && ! $main->has_status('completed')) {
                        $main->update_status('completed', $note);
                    }
                    $wpdb->update($table, ['status' => 'completed'], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    break;

                case 'pending_payment':
                    // Reopen a cancelled balance for payment; paid orders stay paid.
                    if ($due && $due->has_status(['cancelled', 'failed'])) {
                        $due->update_status('pending', $note);
                    }
                    $wpdb->update($table, ['status' => 'pending_payment'], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $main->add_order_note($note);
                    break;

                case 'awaiting_payment':
                    if ($current === 'cancelled' || $main->has_status(['cancelled', 'refunded'])) {
                        // Restore an unpaid booking: its date must still be free.
                        $date = (string) $b->session_date;
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            $free = wedding_booking_validate_booking_date($date, (int) $main->get_id(), (string) $b->session_time, '', true);
                            if (is_wp_error($free)) {
                                return new WP_Error('wedding_booking_date_taken', sprintf(
                                    /* translators: 1: session date, 2: reason */
                                    __('%1$s isn\'t free any more (%2$s) Move the booking to another date with “Edit / reschedule” first, then restore it.', 'wedding-booking'),
                                    wedding_booking_admin_date_label($date),
                                    rtrim($free->get_error_message(), '.') . '.'
                                ));
                            }
                        }
                        if ($main->has_status('cancelled')) {
                            // A studio-managed order from now on: WooCommerce's unpaid-order
                            // clean-up must not cancel it again.
                            if (function_exists('wedding_booking_booking_order_created_via')) {
                                $main->set_created_via(wedding_booking_booking_order_created_via());
                                $main->save();
                            }
                            $main->update_status('pending', $note);
                        }
                        $wpdb->update($table, ['status' => 'awaiting_payment'], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        wedding_booking_refresh_date_slot($date);
                        do_action('wedding_booking_booking_updated', $id, (int) $main->get_id()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Wedding Booking's own hook.
                    } else {
                        $wpdb->update($table, ['status' => 'awaiting_payment'], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $main->add_order_note($note);
                    }
                    break;

                default: // confirmed
                    $wpdb->update($table, ['status' => $status], ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $main->add_order_note($note);
                    break;
            }
        }
    }

    $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    [$main, $due] = $after ? wedding_booking_admin_booking_orders($after) : [null, null];
    $final = $after ? wedding_booking_admin_status_key($after->status) : $status;

    return [
        'booking_id'        => $id,
        'booking_status'    => $final,
        'status_label'      => wedding_booking_admin_status_label($final),
        'main_order_id'     => $main ? (int) $main->get_id() : 0,
        'main_order_status' => $main ? (string) $main->get_status() : '',
        'due_order_id'      => $due ? (int) $due->get_id() : 0,
        'due_order_status'  => $due ? (string) $due->get_status() : '',
        /* translators: 1: booking id, 2: status label */
        'message'           => sprintf(__('Booking #%1$d is now “%2$s”.', 'wedding-booking'), $id, wedding_booking_admin_status_label($final)),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — All Bookings (list / calendar) and Add booking
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_bookings()
{
    if (! wedding_booking_can_manage()) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen to show.
    if (isset($_GET['view']) && 'add' === sanitize_key(wp_unslash($_GET['view']))) {
        wedding_booking_admin_render_add_booking_page();
        return;
    }

    $f     = wedding_booking_admin_booking_filters();
    $stats = wedding_booking_admin_booking_stats();

    wedding_booking_wrap_open(__('All Bookings', 'wedding-booking'), 'wedding-booking-bookings', __('Track and manage every session booking in one place.', 'wedding-booking'));
    wedding_booking_admin_render_bookings_notice();
    if (function_exists('wedding_booking_render_setup_nudge')) {
        wedding_booking_render_setup_nudge();
    }

    if (! class_exists('WooCommerce')) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('WooCommerce is not active, so payments, orders and adding bookings by hand are unavailable. The booking records below are still shown.', 'wedding-booking') . '</p></div>';
    }

    // ── At-a-glance stats (whole data set) ──
    $cur        = html_entity_decode((string) wedding_booking_get_currency_symbol(), ENT_QUOTES, 'UTF-8');
    $stat_cards = [
        ['icon' => 'dashicons-clipboard', 'tone' => 'teal', 'label' => __('Total bookings', 'wedding-booking'), 'value' => (string) $stats['total']],
        ['icon' => 'dashicons-hourglass', 'tone' => 'amber', 'label' => __('Awaiting payment', 'wedding-booking'), 'value' => (string) (int) ($stats['counts']['awaiting_payment'] ?? 0)],
        ['icon' => 'dashicons-clock', 'tone' => 'gold', 'label' => __('Deposit paid', 'wedding-booking'), 'value' => (string) (int) ($stats['counts']['pending_payment'] ?? 0)],
        ['icon' => 'dashicons-yes-alt', 'tone' => 'green', 'label' => __('Completed', 'wedding-booking'), 'value' => (string) (int) ($stats['counts']['completed'] ?? 0)],
        ['icon' => 'dashicons-chart-bar', 'tone' => 'ink', 'label' => __('Booked value', 'wedding-booking'), 'value' => $cur . number_format($stats['value'], 0)],
    ];
    echo '<div class="wbook-stats">';
    foreach ($stat_cards as $sc) {
        echo '<div class="wbook-stat wbook-stat-' . esc_attr($sc['tone']) . '">';
        echo '<span class="dashicons ' . esc_attr($sc['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="wbook-stat-body"><span class="wbook-stat-value">' . esc_html($sc['value']) . '</span><span class="wbook-stat-label">' . esc_html($sc['label']) . '</span></span>';
        echo '</div>';
    }
    echo '</div>';

    wedding_booking_admin_render_bookings_toolbar($f);
    wedding_booking_admin_render_bookings_filters($f, $stats);

    $contracts = [];
    $data      = [];
    if ($f['view'] === 'calendar') {
        $data = wedding_booking_admin_render_bookings_calendar($f, $contracts);
    } else {
        $data = wedding_booking_admin_render_bookings_list($f, $contracts);
    }

    wedding_booking_admin_render_booking_modals();

    $ctx = [
        'statuses'    => wedding_booking_booking_statuses(),
        'methods'     => wedding_booking_admin_payment_methods(),
        'slots'       => ['enabled' => wedding_booking_slots_enabled(), 'times' => array_values(wedding_booking_time_slots())],
        'contracts'   => $contracts,
        'gcal'        => [
            'connected' => function_exists('wedding_booking_gcal_is_connected') && wedding_booking_gcal_is_connected(),
            'enabled'   => function_exists('wedding_booking_gcal_sync_enabled') && wedding_booking_gcal_sync_enabled(),
        ],
        'settingsUrl' => current_user_can('manage_options') ? admin_url('admin.php?page=wedding-booking-settings#wedding-booking-gcal') : '',
        'wc'          => class_exists('WooCommerce'),
    ];
    // Booking data for the View window and the dialogs. Customer text is
    // escaped by admin.js before it is shown.
    echo '<script>var weddingBookingBookings=' . wp_json_encode(array_values($data), JSON_HEX_TAG | JSON_HEX_AMP) . ';var weddingBookingBookingsCtx=' . wp_json_encode($ctx, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML-significant characters hex-escaped.

    wedding_booking_wrap_close();
}

/**
 * Notice after an action that reloaded the page (?wedding_booking_msg=).
 */
function wedding_booking_admin_render_bookings_notice()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
    $msg = isset($_GET['wedding_booking_msg']) ? sanitize_key(wp_unslash($_GET['wedding_booking_msg'])) : '';
    $bid = isset($_GET['wedding_booking_bid']) ? absint(wp_unslash($_GET['wedding_booking_bid'])) : 0;
    $mail = isset($_GET['wedding_booking_mail']) && absint(wp_unslash($_GET['wedding_booking_mail'])) === 1;
    // phpcs:enable
    if ($msg === '') {
        return;
    }

    $text = '';
    switch ($msg) {
        case 'added':
            global $wpdb;
            $row = $bid ? $wpdb->get_row($wpdb->prepare("SELECT id, client_name, client_email, status FROM {$wpdb->prefix}wedding_booking_bookings WHERE id = %d", $bid)) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($row) {
                /* translators: 1: booking id, 2: customer name, 3: booking status */
                $text = sprintf(__('Booking #%1$d added for %2$s (%3$s).', 'wedding-booking'), (int) $row->id, (string) $row->client_name, wedding_booking_admin_status_label($row->status));
                if ($mail && $row->status === 'awaiting_payment') {
                    /* translators: %s: customer email */
                    $text .= ' ' . sprintf(__('The payment link was emailed to %s.', 'wedding-booking'), (string) $row->client_email);
                } elseif ($mail) {
                    /* translators: %s: customer email */
                    $text .= ' ' . sprintf(__('A confirmation was emailed to %s.', 'wedding-booking'), (string) $row->client_email);
                } elseif ($row->status === 'awaiting_payment') {
                    $text .= ' ' . __('Copy its payment link from the Actions menu to send it yourself.', 'wedding-booking');
                }
            } else {
                $text = __('Booking added.', 'wedding-booking');
            }
            break;
        case 'updated':
            /* translators: %d: booking id */
            $text = $bid ? sprintf(__('Booking #%d updated.', 'wedding-booking'), $bid) : __('Booking updated.', 'wedding-booking');
            break;
        case 'payment':
            /* translators: %d: booking id */
            $text = $bid ? sprintf(__('Payment recorded for booking #%d.', 'wedding-booking'), $bid) : __('Payment recorded.', 'wedding-booking');
            break;
        case 'status':
            /* translators: %d: booking id */
            $text = $bid ? sprintf(__('Booking #%d status updated.', 'wedding-booking'), $bid) : __('Booking status updated.', 'wedding-booking');
            break;
    }
    if ($text !== '') {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html($text) . '</p></div>';
    }
}

/**
 * List | Calendar switch, Add booking and Export CSV.
 */
function wedding_booking_admin_render_bookings_toolbar(array $f)
{
    $export_args = ['action' => 'wedding_booking_export_bookings'];
    foreach (['s', 'status', 'pkg', 'from', 'to', 'sort'] as $key) {
        if ($f[$key] !== '') {
            $export_args[$key] = $f[$key];
        }
    }
    if ($f['view'] === 'calendar') {
        // The calendar shows one month: export that month.
        $export_args['from'] = $f['month'] . '-01';
        $export_args['to']   = gmdate('Y-m-t', (int) strtotime($f['month'] . '-01 UTC'));
        $export_args['sort'] = 'session_asc';
    }
    $export_url = wp_nonce_url(add_query_arg(array_map('rawurlencode', $export_args), admin_url('admin-post.php')), 'wedding_booking_export_bookings');

    echo '<div class="wedding-booking-bk-toolbar">';
    echo '<div class="wedding-booking-viewswitch" role="group" aria-label="' . esc_attr__('Bookings view', 'wedding-booking') . '">';
    echo '<a class="wedding-booking-viewswitch-btn' . ($f['view'] === 'list' ? ' is-active' : '') . '" href="' . esc_url(wedding_booking_admin_bookings_url($f, ['view' => 'list', 'paged' => 1])) . '"' . ($f['view'] === 'list' ? ' aria-current="page"' : '') . '><span class="dashicons dashicons-list-view" aria-hidden="true"></span>' . esc_html__('List', 'wedding-booking') . '</a>';
    echo '<a class="wedding-booking-viewswitch-btn' . ($f['view'] === 'calendar' ? ' is-active' : '') . '" href="' . esc_url(wedding_booking_admin_bookings_url($f, ['view' => 'calendar', 'paged' => 1])) . '"' . ($f['view'] === 'calendar' ? ' aria-current="page"' : '') . '><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>' . esc_html__('Calendar', 'wedding-booking') . '</a>';
    echo '</div>';
    echo '<div class="wedding-booking-bk-toolbar-actions">';
    echo '<a class="button wedding-booking-export-btn" href="' . esc_url($export_url) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span>' . esc_html__('Export CSV', 'wedding-booking') . '</a>';
    if (class_exists('WooCommerce')) {
        echo '<a class="button button-primary wedding-booking-smart-add" href="' . esc_url(admin_url('admin.php?page=wedding-booking-bookings&view=add')) . '">+ ' . esc_html__('Add booking', 'wedding-booking') . '</a>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Status pills (with counts over every booking) and the filter form.
 */
function wedding_booking_admin_render_bookings_filters(array $f, array $stats)
{
    $pills = ['' => __('All', 'wedding-booking')] + wedding_booking_booking_statuses();
    echo '<ul class="subsubsub wbook-filter-bar">';
    foreach ($pills as $key => $label) {
        $url = wedding_booking_admin_bookings_url($f, ['status' => $key, 'paged' => 1]);
        $cls = ($key === $f['status']) ? ' current wedding-booking-active' : '';
        $n   = ($key === '') ? $stats['total'] : (int) ($stats['counts'][$key] ?? 0);
        echo '<li><a href="' . esc_url($url) . '" class="wedding-booking-filter-btn' . esc_attr($cls) . '"' . ($key === $f['status'] ? ' aria-current="page"' : '') . '>' . esc_html($label) . '<span class="wedding-booking-filter-count">' . (int) $n . '</span></a></li>';
    }
    echo '<li class="wedding-booking-filter-help">' . wedding_booking_help_tip(__('Awaiting payment: booked, nothing paid yet (e.g. bank transfer or a payment link) — the date is held. Deposit paid: the deposit is in, the balance is still due. Paid in full: everything is paid. Completed: the session has taken place. Cancelled: the date is free again.', 'wedding-booking')) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    echo '</ul>';

    $is_cal   = $f['view'] === 'calendar';
    $packages = wedding_booking_admin_booking_package_names();
    $active   = $f['s'] !== '' || $f['pkg'] !== '' || (! $is_cal && ($f['from'] !== '' || $f['to'] !== '' || $f['sort'] !== 'booked_desc'));

    echo '<form method="get" class="wedding-booking-bk-filters' . ($is_cal ? ' is-calendar' : '') . '" action="' . esc_url(admin_url('admin.php')) . '" role="search">';
    echo '<input type="hidden" name="page" value="wedding-booking-bookings">';
    if ($f['status'] !== '') {
        echo '<input type="hidden" name="status" value="' . esc_attr($f['status']) . '">';
    }
    if ($is_cal) {
        echo '<input type="hidden" name="view" value="calendar"><input type="hidden" name="month" value="' . esc_attr($f['month']) . '">';
    }

    echo '<div class="wedding-booking-bk-filter wedding-booking-bk-filter-search"><label for="wedding-booking-bk-s">' . esc_html__('Search', 'wedding-booking') . '</label>';
    echo '<input id="wedding-booking-bk-s" type="search" name="s" value="' . esc_attr($f['s']) . '" placeholder="' . esc_attr__('Name, email, phone or order #', 'wedding-booking') . '"></div>';

    echo '<div class="wedding-booking-bk-filter"><label for="wedding-booking-bk-pkg">' . esc_html__('Package', 'wedding-booking') . '</label><select id="wedding-booking-bk-pkg" name="pkg">';
    echo '<option value="">' . esc_html__('All packages', 'wedding-booking') . '</option>';
    foreach ($packages as $name) {
        echo '<option value="' . esc_attr($name) . '"' . selected($f['pkg'], $name, false) . '>' . esc_html($name) . '</option>';
    }
    echo '</select></div>';

    if (! $is_cal) {
        echo '<div class="wedding-booking-bk-filter wedding-booking-bk-filter-date"><label for="wedding-booking-bk-from">' . esc_html__('Session from', 'wedding-booking') . '</label><input id="wedding-booking-bk-from" type="date" name="from" value="' . esc_attr($f['from']) . '"></div>';
        echo '<div class="wedding-booking-bk-filter wedding-booking-bk-filter-date"><label for="wedding-booking-bk-to">' . esc_html__('to', 'wedding-booking') . '</label><input id="wedding-booking-bk-to" type="date" name="to" value="' . esc_attr($f['to']) . '"></div>';
        $sorts = [
            'booked_desc'  => __('Newest bookings first', 'wedding-booking'),
            'booked_asc'   => __('Oldest bookings first', 'wedding-booking'),
            'session_asc'  => __('Session date, soonest first', 'wedding-booking'),
            'session_desc' => __('Session date, latest first', 'wedding-booking'),
        ];
        echo '<div class="wedding-booking-bk-filter"><label for="wedding-booking-bk-sort">' . esc_html__('Sort', 'wedding-booking') . '</label><select id="wedding-booking-bk-sort" name="sort">';
        foreach ($sorts as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($f['sort'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
    }

    echo '<div class="wedding-booking-bk-filter wedding-booking-bk-filter-actions">';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'wedding-booking') . '</button>';
    if ($active) {
        $reset = wedding_booking_admin_bookings_url(['view' => $f['view'], 'month' => $f['month'], 'status' => $f['status']]);
        echo '<a class="button wedding-booking-btn-ghost" href="' . esc_url($reset) . '">' . esc_html__('Reset', 'wedding-booking') . '</a>';
    }
    echo '</div>';
    echo '</form>';
}

/**
 * The paginated table. Returns the prepared rows for the View window.
 */
function wedding_booking_admin_render_bookings_list(array $f, array &$contracts)
{
    $q = wedding_booking_admin_query_bookings($f, wedding_booking_admin_bookings_per_page());

    if (empty($q['rows'])) {
        $filtered = $f['s'] !== '' || $f['status'] !== '' || $f['pkg'] !== '' || $f['from'] !== '' || $f['to'] !== '';
        echo wedding_booking_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_empty_state.
            'dashicons-clipboard',
            $filtered ? __('No bookings match these filters', 'wedding-booking') : __('No bookings yet', 'wedding-booking'),
            $filtered ? __('Try another search, or reset the filters.', 'wedding-booking') : __('New bookings appear here as soon as customers complete the booking form, or add one by hand.', 'wedding-booking')
        );
        return [];
    }

    $first = ($q['paged'] - 1) * $q['per_page'] + 1;
    $last  = min($q['total'], $q['paged'] * $q['per_page']);
    echo '<div class="wedding-booking-bk-resultbar">';
    /* translators: 1: first row number, 2: last row number, 3: total bookings */
    echo '<span class="wedding-booking-bk-count">' . esc_html(sprintf(_n('Showing %1$d–%2$d of %3$d booking', 'Showing %1$d–%2$d of %3$d bookings', $q['total'], 'wedding-booking'), $first, $last, $q['total'])) . '</span>';
    wedding_booking_admin_render_pagination($f, $q);
    echo '</div>';

    $rows = [];
    echo '<div class="wedding-booking-table-wrap"><table class="wp-list-table widefat fixed striped wedding-booking-table wedding-booking-bk-table"><thead><tr>';
    echo '<th class="wedding-booking-col-id">#</th><th>' . esc_html__('Client', 'wedding-booking') . '</th><th>' . esc_html__('Session', 'wedding-booking') . '</th><th>' . esc_html__('Package', 'wedding-booking') . '</th><th>' . esc_html__('Total', 'wedding-booking') . '</th><th>' . esc_html__('Deposit', 'wedding-booking') . '</th><th>' . esc_html__('Status', 'wedding-booking') . '</th><th>' . esc_html__('Order', 'wedding-booking') . '</th><th>' . esc_html__('Actions', 'wedding-booking') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($q['rows'] as $b) {
        $b      = wedding_booking_admin_prepare_booking($b, $contracts);
        $rows[] = $b;
        wedding_booking_admin_render_booking_row($b);
    }
    echo '</tbody></table></div>';

    echo '<div class="wedding-booking-bk-resultbar is-bottom">';
    wedding_booking_admin_render_pagination($f, $q);
    echo '</div>';

    return $rows;
}

function wedding_booking_admin_bookings_per_page()
{
    return max(1, (int) apply_filters('wedding_booking_admin_bookings_per_page', 25));
}

function wedding_booking_admin_render_pagination(array $f, array $q)
{
    if ($q['pages'] < 2) {
        return;
    }
    $links = paginate_links([
        'base'      => str_replace('999999999', '%#%', esc_url(wedding_booking_admin_bookings_url($f, ['paged' => 999999999]))),
        'format'    => '',
        'current'   => $q['paged'],
        'total'     => $q['pages'],
        'prev_text' => '&lsaquo;<span class="screen-reader-text"> ' . esc_html__('Previous page', 'wedding-booking') . '</span>',
        'next_text' => '<span class="screen-reader-text">' . esc_html__('Next page', 'wedding-booking') . ' </span>&rsaquo;',
        'mid_size'  => 1,
        'type'      => 'plain',
    ]);
    if ($links) {
        echo '<nav class="wedding-booking-pagination" aria-label="' . esc_attr__('Bookings pages', 'wedding-booking') . '">' . wp_kses_post($links) . '</nav>';
    }
}

/**
 * One table row with its Actions menu.
 */
function wedding_booking_admin_render_booking_row($b)
{
    $pay     = $b->wedding_booking_payment;
    $view    = $b->wedding_booking_view;
    $status  = $b->status_key;
    $id      = (int) $b->id;
    $cur     = html_entity_decode((string) wedding_booking_get_currency_symbol(), ENT_QUOTES, 'UTF-8');
    $wc      = function_exists('wc_get_order_status_name');

    echo '<tr class="wedding-booking-brow" data-id="' . (int) $id . '" data-status="' . esc_attr($status) . '">';
    echo '<td class="wedding-booking-col-id">' . (int) $id . '</td>';

    // Client.
    echo '<td class="wedding-booking-col-client"><strong>' . esc_html($b->client_name !== '' ? $b->client_name : '—') . '</strong>';
    if ((string) $b->client_email !== '') {
        echo '<br><small>' . esc_html($b->client_email) . '</small>';
    }
    if ((string) $b->client_phone !== '') {
        echo '<br><small>' . esc_html($b->client_phone) . '</small>';
    }
    if (! empty($view['request'])) {
        echo '<br><span class="wedding-booking-req-pill" title="' . esc_attr($view['request']['message']) . '">' . esc_html(sprintf(
            /* translators: %s: request type, e.g. Reschedule */
            __('%s requested', 'wedding-booking'),
            $view['request']['type_label']
        )) . '</span>';
    }
    echo '</td>';

    // Session: shoot date + start time; when it was booked, smaller.
    echo '<td class="wedding-booking-col-session">';
    if ($view['session_date_label'] !== '') {
        echo '<strong class="wedding-booking-session-date">' . esc_html($view['session_date_label']) . '</strong>';
        if ($view['session_time_label'] !== '') {
            echo '<span class="wedding-booking-session-time"><span class="dashicons dashicons-clock" aria-hidden="true"></span>' . esc_html($view['session_time_label']) . '</span>';
        }
    } else {
        echo '<span class="wedding-booking-muted">' . esc_html__('No date', 'wedding-booking') . '</span>';
    }
    if ($view['booked_label'] !== '') {
        /* translators: %s: date the booking was made */
        echo '<small class="wedding-booking-booked-on">' . esc_html(sprintf(__('Booked %s', 'wedding-booking'), $view['booked_label'])) . '</small>';
    }
    echo '</td>';

    echo '<td>' . esc_html($b->session_type) . '<br><small>' . esc_html($b->package_name) . '</small></td>';
    echo '<td>' . esc_html($cur . number_format((float) $b->total, 2)) . '</td>';

    echo '<td>' . esc_html($cur . number_format((float) $b->deposit, 2));
    if ($status === 'awaiting_payment') {
        echo '<br><span class="wedding-booking-balpill wedding-booking-balpill-due">' . esc_html__('Not paid yet', 'wedding-booking') . '</span>';
    } elseif ($pay['balance'] > 0.01) {
        if ($pay['balance_paid']) {
            echo '<br><span class="wedding-booking-balpill wedding-booking-balpill-paid">' . esc_html__('Balance paid', 'wedding-booking') . '</span>';
        } elseif ($status !== 'cancelled') {
            /* translators: %s: remaining balance amount */
            echo '<br><span class="wedding-booking-balpill wedding-booking-balpill-due">' . esc_html(sprintf(__('Balance %s', 'wedding-booking'), $cur . number_format($pay['balance'], 2))) . '</span>';
        }
    }
    echo '</td>';

    echo '<td><span class="wbook-badge wbook-badge-' . esc_attr($status) . '">' . esc_html($view['status_label']) . '</span></td>';

    // Orders.
    echo '<td class="wedding-booking-col-order">';
    if (! empty($b->order_id)) {
        echo '<a href="' . esc_url($view['order_edit_url'] !== '' ? $view['order_edit_url'] : admin_url('post.php?post=' . (int) $b->order_id . '&action=edit')) . '">#' . esc_html($view['order_number'] !== '' ? $view['order_number'] : (string) (int) $b->order_id) . '</a>';
        if ($pay['due_order_id'] > 0) {
            echo '<br><small>' . esc_html__('Balance:', 'wedding-booking') . ' <a href="' . esc_url($pay['edit_link']) . '">#' . esc_html($view['due_order_number']) . '</a>';
            if ($pay['due_status_label'] !== '') {
                echo ' (' . esc_html($pay['due_status_label']) . ')';
            }
            echo '</small>';
        }
    } else {
        echo '—';
    }
    echo '</td>';

    // Actions.
    $menu_id = 'wedding-booking-actions-menu-' . $id;
    echo '<td class="wedding-booking-actions-cell">';
    echo '<div class="wedding-booking-row-actions">';
    echo '<button type="button" class="button button-secondary wbook-btn-sm wbook-btn-view" data-id="' . (int) $id . '">' . esc_html__('View', 'wedding-booking') . '</button>';
    echo '<button type="button" class="button button-secondary wbook-btn-sm wedding-booking-row-actions-toggle" aria-expanded="false" aria-controls="' . esc_attr($menu_id) . '">' . esc_html__('Actions', 'wedding-booking') . ' <span class="wedding-booking-row-actions-caret" aria-hidden="true">&#9662;</span></button>';
    echo '<div class="wedding-booking-row-actions-menu" id="' . esc_attr($menu_id) . '" hidden>';

    echo '<div class="wedding-booking-row-actions-section">';
    echo '<label class="wedding-booking-row-actions-label" for="wedding-booking-status-' . (int) $id . '">' . esc_html__('Booking status', 'wedding-booking') . '</label>';
    echo '<select id="wedding-booking-status-' . (int) $id . '" class="wbook-status-select" data-id="' . (int) $id . '">';
    foreach (wedding_booking_booking_statuses() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="wedding-booking-row-actions-section">';
    echo '<div class="wedding-booking-row-actions-label">' . esc_html__('Quick actions', 'wedding-booking') . '</div>';
    echo '<div class="wedding-booking-payment-quick">';
    if (! empty($view['record'])) {
        echo '<button type="button" class="button button-link wedding-booking-act-record" data-id="' . (int) $id . '"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span>' . esc_html__('Record payment', 'wedding-booking') . '</button>';
    }
    if (class_exists('WooCommerce')) {
        echo '<button type="button" class="button button-link wedding-booking-act-edit" data-id="' . (int) $id . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__('Edit / reschedule', 'wedding-booking') . '</button>';
    }
    if (! in_array($status, ['completed', 'cancelled'], true)) {
        echo '<button type="button" class="button button-link wedding-booking-quick-status wedding-booking-quick-complete" data-id="' . (int) $id . '" data-status="completed"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html__('Mark complete', 'wedding-booking') . '</button>';
    }
    if ($status !== 'cancelled') {
        echo '<button type="button" class="button button-link wedding-booking-quick-status wedding-booking-quick-cancel" data-id="' . (int) $id . '" data-status="cancelled"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' . esc_html__('Cancel booking', 'wedding-booking') . '</button>';
    }
    $link = $pay['main_pay_link'] !== '' ? $pay['main_pay_link'] : $pay['pay_link'];
    if ($link !== '' && $status !== 'cancelled') {
        echo '<button type="button" class="button button-link wedding-booking-copy-pay-link" data-link="' . esc_attr($link) . '"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>' . esc_html__('Copy payment link', 'wedding-booking') . '</button>';
    }
    if (! empty($pay['can_remind'])) {
        echo '<button type="button" class="button button-link wedding-booking-send-balance-reminder" data-id="' . (int) $id . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>' . esc_html__('Send balance reminder', 'wedding-booking') . '</button>';
    }
    echo '</div>';
    echo '</div>';

    // The raw WooCommerce order statuses, for the rare case they need
    // setting by hand. Collapsed so the booking status stays the one control.
    if (! empty($b->order_id) && $wc && $view['order_number'] !== '') {
        [$main, $due] = wedding_booking_admin_booking_orders($b);
        echo '<details class="wedding-booking-row-actions-section wedding-booking-row-actions-adv">';
        echo '<summary>' . esc_html__('WooCommerce orders (advanced)', 'wedding-booking') . '</summary>';
        echo '<p class="wedding-booking-adv-note">' . esc_html__('Changes the order itself. WooCommerce may email the customer, and paid or cancelled orders update the booking.', 'wedding-booking') . '</p>';
        $wc_statuses = ['pending', 'on-hold', 'processing', 'completed', 'cancelled'];
        if ($main) {
            /* translators: %s: order number */
            echo '<label class="wedding-booking-row-actions-label" for="wedding-booking-mainst-' . (int) $id . '">' . esc_html(sprintf(__('Booking order #%s', 'wedding-booking'), $main->get_order_number())) . '</label>';
            echo '<select id="wedding-booking-mainst-' . (int) $id . '" class="wedding-booking-order-status-select" data-order-id="' . (int) $main->get_id() . '">';
            foreach ($wc_statuses as $wc_st) {
                echo '<option value="' . esc_attr($wc_st) . '"' . selected($main->get_status(), $wc_st, false) . '>' . esc_html(wc_get_order_status_name($wc_st)) . '</option>';
            }
            echo '</select>';
        }
        if ($due) {
            /* translators: %s: order number */
            echo '<label class="wedding-booking-row-actions-label" for="wedding-booking-duest-' . (int) $id . '">' . esc_html(sprintf(__('Balance order #%s', 'wedding-booking'), $due->get_order_number())) . '</label>';
            echo '<select id="wedding-booking-duest-' . (int) $id . '" class="wedding-booking-order-status-select" data-order-id="' . (int) $due->get_id() . '">';
            foreach ($wc_statuses as $wc_st) {
                echo '<option value="' . esc_attr($wc_st) . '"' . selected($due->get_status(), $wc_st, false) . '>' . esc_html(wc_get_order_status_name($wc_st)) . '</option>';
            }
            echo '</select>';
        }
        echo '</details>';
    }

    echo '</div>';
    echo '</div>';
    echo '<div class="wedding-booking-status-hint" data-for-booking="' . (int) $id . '" aria-live="polite"></div>';
    echo '</td>';
    echo '</tr>';
}

/**
 * Month calendar of sessions. Fetches the whole month on its own (not the
 * list page) and returns the prepared rows for the View window.
 */
function wedding_booking_admin_render_bookings_calendar(array $f, array &$contracts)
{
    global $wpdb, $wp_locale;
    $first = $f['month'] . '-01';
    $ts    = (int) strtotime($first . ' 00:00:00 UTC');
    $last  = gmdate('Y-m-t', $ts);
    $days  = (int) gmdate('t', $ts);
    $today = wp_date('Y-m-d');
    $sow   = (int) get_option('start_of_week', 0);

    $mf           = $f;
    $mf['from']   = $first;
    $mf['to']     = $last;
    $where        = wedding_booking_admin_bookings_where($mf);
    $table        = $wpdb->prefix . 'wedding_booking_bookings';
    $rows         = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY session_date ASC, session_time ASC, id ASC LIMIT 1000"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    $marks        = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT date_str, status FROM {$wpdb->prefix}wedding_booking_dates WHERE date_str BETWEEN %s AND %s", $first, $last)) as $mark) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $marks[(string) $mark->date_str] = (string) $mark->status;
    }

    $by_date  = [];
    $prepared = [];
    foreach ($rows as $b) {
        $b          = wedding_booking_admin_prepare_booking($b, $contracts);
        $prepared[] = $b;
        $by_date[(string) $b->session_date][] = $b;
    }

    $prev = gmdate('Y-m', (int) strtotime($first . ' -1 month UTC'));
    $next = gmdate('Y-m', (int) strtotime($first . ' +1 month UTC'));
    $label = date_i18n('F Y', $ts + (int) (12 * HOUR_IN_SECONDS));

    echo '<div class="wedding-booking-bcal" data-month="' . esc_attr($f['month']) . '">';
    echo '<div class="wedding-booking-bcal-nav">';
    echo '<a class="button wedding-booking-cal-admin-nav" href="' . esc_url(wedding_booking_admin_bookings_url($f, ['month' => $prev])) . '">&lsaquo; ' . esc_html__('Prev', 'wedding-booking') . '</a>';
    echo '<h2 class="wedding-booking-bcal-title">' . esc_html($label) . '</h2>';
    echo '<a class="button wedding-booking-cal-admin-nav" href="' . esc_url(wedding_booking_admin_bookings_url($f, ['month' => $next])) . '">' . esc_html__('Next', 'wedding-booking') . ' &rsaquo;</a>';
    if (wp_date('Y-m') !== $f['month']) {
        echo '<a class="button wedding-booking-btn-ghost" href="' . esc_url(wedding_booking_admin_bookings_url($f, ['month' => wp_date('Y-m')])) . '">' . esc_html__('Today', 'wedding-booking') . '</a>';
    }
    /* translators: %d: number of bookings in the month */
    echo '<span class="wedding-booking-bcal-count">' . esc_html(sprintf(_n('%d booking this month', '%d bookings this month', count($prepared), 'wedding-booking'), count($prepared))) . '</span>';
    echo '</div>';

    echo '<div class="wedding-booking-bcal-grid" role="grid" aria-label="' . esc_attr($label) . '">';
    for ($i = 0; $i < 7; $i++) {
        $dow = ($sow + $i) % 7;
        $name = $wp_locale ? $wp_locale->get_weekday($dow) : gmdate('l', (int) strtotime('Sunday +' . $dow . ' days UTC'));
        $abbr = $wp_locale ? $wp_locale->get_weekday_abbrev($name) : substr($name, 0, 3);
        echo '<div class="wedding-booking-bcal-dh" role="columnheader"><abbr title="' . esc_attr($name) . '">' . esc_html($abbr) . '</abbr></div>';
    }

    $lead = ((int) gmdate('w', $ts) - $sow + 7) % 7;
    for ($i = 0; $i < $lead; $i++) {
        echo '<div class="wedding-booking-bcal-day is-out" aria-hidden="true"></div>';
    }
    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%s-%02d', $f['month'], $d);
        $list = $by_date[$date] ?? [];
        $mark = $marks[$date] ?? '';
        $cls  = 'wedding-booking-bcal-day';
        $cls .= $list ? ' has-bookings' : ' is-empty';
        $cls .= $date === $today ? ' is-today' : '';
        $cls .= $date < $today ? ' is-past' : '';
        $cls .= $mark === 'blocked' ? ' is-blocked' : '';
        $cls .= $mark === 'booked' ? ' is-full' : '';
        $dow_name = $wp_locale ? $wp_locale->get_weekday((int) gmdate('w', (int) strtotime($date . ' UTC'))) : '';

        echo '<div class="' . esc_attr($cls) . '" role="gridcell" data-date="' . esc_attr($date) . '">';
        echo '<div class="wedding-booking-bcal-dayhead"><span class="wedding-booking-bcal-dow">' . esc_html($dow_name) . '</span><span class="wedding-booking-bcal-num">' . (int) $d . '</span>';
        if ($mark === 'blocked') {
            echo '<span class="wedding-booking-bcal-tag is-blocked">' . esc_html__('Blocked', 'wedding-booking') . '</span>';
        } elseif ($mark === 'booked') {
            echo '<span class="wedding-booking-bcal-tag is-full">' . esc_html__('Full', 'wedding-booking') . '</span>';
        }
        echo '</div>';
        if ($list) {
            echo '<ul class="wedding-booking-bcal-list">';
            foreach ($list as $b) {
                $time = $b->wedding_booking_view['session_time_label'];
                $who  = trim((string) $b->client_name) !== '' ? (string) $b->client_name : __('(no name)', 'wedding-booking');
                /* translators: 1: customer name, 2: status */
                $title = sprintf(__('%1$s — %2$s', 'wedding-booking'), $who, $b->wedding_booking_view['status_label']);
                echo '<li><button type="button" class="wedding-booking-bcal-bk is-' . esc_attr($b->status_key) . '" data-id="' . (int) $b->id . '" title="' . esc_attr($title . ($b->package_name !== '' ? ' · ' . $b->package_name : '')) . '">';
                if ($time !== '') {
                    echo '<span class="wedding-booking-bcal-time">' . esc_html($time) . '</span> ';
                }
                echo '<span class="wedding-booking-bcal-name">' . esc_html($who) . '</span>';
                echo '<span class="screen-reader-text"> (' . esc_html($b->wedding_booking_view['status_label']) . ')</span>';
                echo '</button></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    $trail = (7 - (($lead + $days) % 7)) % 7;
    for ($i = 0; $i < $trail; $i++) {
        echo '<div class="wedding-booking-bcal-day is-out" aria-hidden="true"></div>';
    }
    echo '</div>';

    echo '<div class="wedding-booking-cal-legend wedding-booking-bcal-legend">';
    foreach (wedding_booking_booking_statuses() as $key => $status_label) {
        echo '<span class="wedding-booking-leg"><span class="wedding-booking-leg-dot is-' . esc_attr($key) . '"></span> ' . esc_html($status_label) . '</span>';
    }
    echo '<span class="wedding-booking-leg"><span class="wedding-booking-leg-dot wedding-booking-blocked"></span> ' . esc_html__('Blocked in Date Slots', 'wedding-booking') . '</span>';
    echo '</div>';
    if (empty($prepared)) {
        echo '<p class="wedding-booking-hint">' . esc_html__('No sessions this month for the current filters.', 'wedding-booking') . '</p>';
    }
    echo '</div>';

    return $prepared;
}

/**
 * The View window and the shared dialog (confirm / edit / record payment).
 */
function wedding_booking_admin_render_booking_modals()
{
    echo '<div id="wbook-booking-modal" class="wbook-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="wbook-booking-modal-title"><div class="wbook-modal-inner"><div class="wbook-modal-head"><span id="wbook-booking-modal-title">' . esc_html__('Booking', 'wedding-booking') . '</span><button type="button" class="wbook-modal-close" aria-label="' . esc_attr__('Close', 'wedding-booking') . '">✕</button></div><div class="wbook-modal-body"></div></div></div>';
    echo '<div id="wbook-dialog" class="wbook-modal wbook-dialog" style="display:none" role="dialog" aria-modal="true" aria-labelledby="wbook-dialog-title"><div class="wbook-modal-inner wbook-dialog-inner"><div class="wbook-modal-head"><span id="wbook-dialog-title"></span><button type="button" class="wbook-modal-close" aria-label="' . esc_attr__('Close', 'wedding-booking') . '">✕</button></div><div class="wbook-modal-body wbook-dialog-body"></div><div class="wbook-dialog-foot"></div></div></div>';
}

/* ═══════════════════════════════════════════════════════════════
   Add a booking by hand
═══════════════════════════════════════════════════════════════ */

/**
 * Active session types, packages (with their deposit %) and add-ons.
 */
function wedding_booking_admin_booking_catalog()
{
    global $wpdb;
    $pfx      = $wpdb->prefix . 'wedding_booking_';
    $sessions = [];
    foreach ((array) $wpdb->get_results("SELECT id, name FROM {$pfx}sessions WHERE active = 1 ORDER BY sort_order, id") as $s) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $sessions[] = ['id' => (int) $s->id, 'name' => (string) $s->name];
    }
    $packages = [];
    foreach ((array) $wpdb->get_results("SELECT id, session_id, name, price FROM {$pfx}packages WHERE active = 1 ORDER BY sort_order, id") as $p) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $packages[] = [
            'id'         => (int) $p->id,
            'session_id' => (int) $p->session_id,
            'name'       => (string) $p->name,
            'price'      => (float) $p->price,
            'priceLabel' => wedding_booking_admin_money($p->price),
            'depositPct' => wedding_booking_get_deposit_pct((int) $p->id),
        ];
    }
    $addons = [];
    foreach ((array) $wpdb->get_results("SELECT id, name, price, package_id, package_ids FROM {$pfx}addons WHERE active = 1 ORDER BY sort_order, id") as $a) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $scope = array_values(array_filter(array_map('intval', explode(',', (string) $a->package_ids))));
        if (! $scope && (int) $a->package_id > 0) {
            $scope = [(int) $a->package_id];
        }
        $addons[] = ['id' => (int) $a->id, 'name' => (string) $a->name, 'priceLabel' => wedding_booking_admin_money($a->price), 'scope' => $scope];
    }

    return ['sessions' => $sessions, 'packages' => $packages, 'addons' => $addons];
}

function wedding_booking_admin_render_add_booking_page()
{
    wedding_booking_wrap_open(__('Add a booking', 'wedding-booking'), 'wedding-booking-bookings', __('Book a session for a customer yourself — a phone or walk-in booking, or one paid outside the website.', 'wedding-booking'));
    echo '<p class="wedding-booking-backlink"><a href="' . esc_url(admin_url('admin.php?page=wedding-booking-bookings')) . '">&larr; ' . esc_html__('All bookings', 'wedding-booking') . '</a></p>';

    if (! class_exists('WooCommerce') || ! function_exists('wedding_booking_create_booking_order')) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('Adding bookings needs WooCommerce to be active.', 'wedding-booking') . '</p></div>';
        wedding_booking_wrap_close();
        return;
    }

    $catalog  = wedding_booking_admin_booking_catalog();
    $slots_on = wedding_booking_slots_enabled();
    $methods  = wedding_booking_admin_payment_methods();
    $countries = (function_exists('WC') && WC() && WC()->countries) ? (array) WC()->countries->get_countries() : [];
    $base      = (function_exists('WC') && WC() && WC()->countries) ? (string) WC()->countries->get_base_country() : '';
    $global_pct = (int) wedding_booking_opt('wedding_booking_deposit_pct');

    echo '<div class="postbox wedding-booking-form-card wedding-booking-addbk-card"><div class="inside">';
    if (empty($catalog['packages'])) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('There are no active packages yet. Add one under Wedding Booking → Packages first.', 'wedding-booking') . '</p></div>';
    }
    echo '<form id="wedding-booking-addbk-form" class="wedding-booking-addbk-form" novalidate>';

    // ── Session ──
    echo '<h3 class="wedding-booking-form-title">' . esc_html__('Session', 'wedding-booking') . '</h3>';
    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2">';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-session">' . esc_html__('Session type', 'wedding-booking') . ' <span class="wedding-booking-req">*</span></label><select id="wedding-booking-addbk-session" name="session_id">';
    foreach ($catalog['sessions'] as $s) {
        echo '<option value="' . (int) $s['id'] . '">' . esc_html($s['name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-package">' . esc_html__('Package', 'wedding-booking') . ' <span class="wedding-booking-req">*</span></label><select id="wedding-booking-addbk-package" name="package_id" required></select></div>';
    echo '<div class="wedding-booking-field wedding-booking-field-wide"><span class="wedding-booking-field-label">' . esc_html__('Add-ons', 'wedding-booking') . '</span><div class="wedding-booking-checklist" id="wedding-booking-addbk-addons"><span class="wedding-booking-muted">' . esc_html__('No add-ons for this package.', 'wedding-booking') . '</span></div></div>';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-date">' . esc_html__('Session date', 'wedding-booking') . ' <span class="wedding-booking-req">*</span></label><input id="wedding-booking-addbk-date" type="date" name="session_date" required></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Start time', 'wedding-booking'), $slots_on ? __('Pick one of your start times (Settings → Availability). Times already taken on that date are refused unless you tick “Allow even if the date is full”.', 'wedding-booking') : __('Optional. Just a note of the time, shown on the booking and in emails — you have not set fixed start times.', 'wedding-booking'), 'wedding-booking-addbk-time', $slots_on); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    if ($slots_on) {
        echo '<select id="wedding-booking-addbk-time" name="session_time"><option value="">' . esc_html__('— Choose a time —', 'wedding-booking') . '</option>';
        foreach (wedding_booking_time_slots() as $t) {
            echo '<option value="' . esc_attr($t) . '">' . esc_html(wedding_booking_admin_time_label($t)) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input id="wedding-booking-addbk-time" type="text" name="session_time" placeholder="' . esc_attr__('e.g. 10:00 (optional)', 'wedding-booking') . '" maxlength="100">';
    }
    echo '</div>';
    echo '<div class="wedding-booking-field wedding-booking-field-wide"><p class="wedding-booking-addbk-datemsg" id="wedding-booking-addbk-datemsg" aria-live="polite"></p></div>';
    echo '</div>';

    // ── Customer ──
    echo '<h3 class="wedding-booking-form-title">' . esc_html__('Customer', 'wedding-booking') . '</h3>';
    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2">';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-first">' . esc_html__('First name', 'wedding-booking') . ' <span class="wedding-booking-req">*</span></label><input id="wedding-booking-addbk-first" type="text" name="first_name" autocomplete="off" required></div>';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-last">' . esc_html__('Last name', 'wedding-booking') . '</label><input id="wedding-booking-addbk-last" type="text" name="last_name" autocomplete="off"></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Email', 'wedding-booking'), __('Where the confirmation or payment link is sent. If it matches a customer account, the booking also shows in their My Account.', 'wedding-booking'), 'wedding-booking-addbk-email') . '<input id="wedding-booking-addbk-email" type="email" name="email" autocomplete="off">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    echo '<p class="description">' . esc_html__('When it matches a customer account, the booking shows in their My Account.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-phone">' . esc_html__('Phone', 'wedding-booking') . '</label><input id="wedding-booking-addbk-phone" type="tel" name="phone" autocomplete="off"></div>';
    echo '<div class="wedding-booking-field"><label for="wedding-booking-addbk-country">' . esc_html__('Country', 'wedding-booking') . '</label><select id="wedding-booking-addbk-country" name="country"><option value="">' . esc_html__('—', 'wedding-booking') . '</option>';
    foreach ($countries as $code => $name) {
        echo '<option value="' . esc_attr($code) . '"' . selected($base, $code, false) . '>' . esc_html(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="wedding-booking-field wedding-booking-field-wide"><label for="wedding-booking-addbk-notes">' . esc_html__('Notes', 'wedding-booking') . '</label><textarea id="wedding-booking-addbk-notes" name="notes" rows="3"></textarea>';
    echo '<p class="description">' . esc_html__('Saved as the customer\'s note on the order (it appears in their emails).', 'wedding-booking') . '</p></div>';
    echo '</div>';

    // ── Payment ──
    echo '<h3 class="wedding-booking-form-title">' . esc_html__('Payment', 'wedding-booking') . '</h3>';
    echo '<div class="wedding-booking-addbk-pay">';
    echo '<fieldset class="wedding-booking-paystate"><legend class="screen-reader-text">' . esc_html__('Payment', 'wedding-booking') . '</legend>';
    echo '<label class="wedding-booking-paystate-opt"><input type="radio" name="pay_state" value="full" checked><span><strong>' . esc_html__('Paid in full', 'wedding-booking') . '</strong><small>' . esc_html__('You have the whole amount.', 'wedding-booking') . '</small></span></label>';
    /* translators: %s: deposit percentage (filled in by the page) */
    echo '<label class="wedding-booking-paystate-opt"><input type="radio" name="pay_state" value="deposit"><span><strong>' . sprintf(esc_html__('Deposit paid (%s)', 'wedding-booking'), '<span class="wedding-booking-addbk-pct">' . (int) $global_pct . '%</span>') . '</strong><small>' . esc_html__('The rest becomes a balance with a payment link and reminders.', 'wedding-booking') . '</small></span></label>';
    echo '<label class="wedding-booking-paystate-opt"><input type="radio" name="pay_state" value="unpaid"><span><strong>' . esc_html__('Not paid yet — send a payment link', 'wedding-booking') . '</strong><small>' . esc_html__('The date is held while the customer pays online.', 'wedding-booking') . '</small></span></label>';
    echo '</fieldset>';

    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2 wedding-booking-addbk-paidfields">';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Paid by', 'wedding-booking'), __('How the customer paid you. It is recorded on the order for your accounts; no money is taken.', 'wedding-booking'), 'wedding-booking-addbk-method') . '<select id="wedding-booking-addbk-method" name="method">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    foreach ($methods as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Payment note', 'wedding-booking'), __('Optional reference saved as a private order note, e.g. a receipt number or bank reference. The customer does not see it.', 'wedding-booking'), 'wedding-booking-addbk-methodnote') . '<input id="wedding-booking-addbk-methodnote" type="text" name="method_note" maxlength="200" placeholder="' . esc_attr__('e.g. receipt or transfer reference (optional)', 'wedding-booking') . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    echo '</div>';

    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2 wedding-booking-addbk-unpaidfields" hidden>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('The payment link asks for', 'wedding-booking'), __('The customer gets a link to pay online. Ask for the full amount, or just the deposit — the rest then becomes a balance with its own payment link and reminders.', 'wedding-booking'), 'wedding-booking-addbk-link') . '<select id="wedding-booking-addbk-link" name="link_amount">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    echo '<option value="full">' . esc_html__('The full amount', 'wedding-booking') . '</option>';
    echo '<option value="deposit">' . esc_html__('The deposit only', 'wedding-booking') . '</option>';
    echo '</select></div>';
    echo '</div>';

    if (wedding_booking_coupons_enabled()) {
        echo '<div class="wedding-booking-form-grid wedding-booking-cols-2"><div class="wedding-booking-field">' . wedding_booking_field_label(__('Promo code', 'wedding-booking'), __('Optional. A WooCommerce coupon code (Marketing → Coupons); its discount is applied to the price shown below.', 'wedding-booking'), 'wedding-booking-addbk-coupon') . '<input id="wedding-booking-addbk-coupon" type="text" name="coupon_code" autocomplete="off"><p class="wedding-booking-addbk-couponmsg" id="wedding-booking-addbk-couponmsg" aria-live="polite"></p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    }

    echo '<div class="wedding-booking-addbk-summary" id="wedding-booking-addbk-summary" aria-live="polite"><p class="wedding-booking-muted">' . esc_html__('Choose a package to see the price.', 'wedding-booking') . '</p></div>';
    echo '<p class="description">' . esc_html__('Prices come from your packages and add-ons. No payment fee is added to a booking you record as paid; a payment link includes it (it comes off for fee-free payment methods).', 'wedding-booking') . '</p>';
    echo '</div>';

    echo '<div class="wedding-booking-form-switches">';
    echo wedding_booking_toggle_field('notify', __('Email the customer', 'wedding-booking'), true, __('A booking confirmation when paid, or the payment link when not.', 'wedding-booking'), __('Untick if you will tell the customer yourself. For an unpaid booking you can still copy the payment link later from the booking\'s Actions menu.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo wedding_booking_toggle_field('override', __('Allow even if the date is full', 'wedding-booking'), false, __('Books it anyway, even on a full or blocked day.', 'wedding-booking'), __('Normally a booking is refused on a date that is full, blocked or closed. Tick this to book it anyway — for example a second shoot you know you can fit in.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '</div>';

    echo '<div class="wedding-booking-form-actions">';
    echo '<button type="submit" class="button button-primary wedding-booking-btn" id="wedding-booking-addbk-submit">' . esc_html__('Add booking', 'wedding-booking') . '</button>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-bookings')) . '" class="button wedding-booking-btn wedding-booking-btn-ghost">' . esc_html__('Cancel', 'wedding-booking') . '</a>';
    echo '</div><div class="wedding-booking-form-msg" id="wedding-booking-addbk-msg" aria-live="polite"></div>';
    echo '</form></div></div>';

    echo '<script>var weddingBookingAddBooking=' . wp_json_encode($catalog + ['slots' => $slots_on], JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML-significant characters hex-escaped.

    wedding_booking_wrap_close();
}

/**
 * Add-booking fields from the request.
 */
function wedding_booking_admin_booking_input_from_post()
{
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
    $p      = wp_unslash($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
    $addons = $p['addon_ids'] ?? '';
    // phpcs:enable
    $addons = is_array($addons) ? $addons : explode(',', (string) $addons);
    $str    = static function ($key) use ($p) {
        return isset($p[$key]) && ! is_array($p[$key]) ? trim(sanitize_text_field((string) $p[$key])) : '';
    };
    $state  = sanitize_key($str('pay_state'));
    $method = sanitize_key($str('method'));

    return [
        'package_id'   => absint($str('package_id')),
        'addon_ids'    => array_values(array_unique(array_filter(array_map('absint', $addons)))),
        'session_date' => $str('session_date'),
        'session_time' => $str('session_time'),
        'first_name'   => $str('first_name'),
        'last_name'    => $str('last_name'),
        'email'        => sanitize_email($str('email')),
        'email_raw'    => $str('email'),
        'phone'        => $str('phone'),
        'country'      => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $str('country')), 0, 2)),
        'notes'        => isset($p['notes']) && ! is_array($p['notes']) ? sanitize_textarea_field((string) $p['notes']) : '',
        'coupon_code'  => $str('coupon_code'),
        'pay_state'    => in_array($state, ['full', 'deposit', 'unpaid'], true) ? $state : 'full',
        'link_amount'  => $str('link_amount') === 'deposit' ? 'deposit' : 'full',
        'method'       => array_key_exists($method, wedding_booking_admin_payment_methods()) ? $method : 'cash',
        'method_note'  => mb_substr($str('method_note'), 0, 200),
        'notify'       => absint($str('notify')) === 1,
        'override'     => absint($str('override')) === 1,
    ];
}

/**
 * Price a hand-added booking. Built on wedding_booking_quote_booking(); a booking
 * recorded as paid carries no payment fee (the studio took the money), and
 * "Deposit paid" uses the package's deposit % whatever the booking form's
 * deposit rules say.
 *
 * @return array|WP_Error wedding_booking_quote_booking() keys.
 */
function wedding_booking_admin_price_booking(array $in)
{
    $q = wedding_booking_quote_booking([
        'package_id'     => (int) $in['package_id'],
        'addon_ids'      => (array) $in['addon_ids'],
        'session_date'   => (string) $in['session_date'],
        'use_deposit'    => false,
        'coupon_code'    => (string) $in['coupon_code'],
        'email'          => (string) $in['email'],
        'payment_method' => '',
    ]);
    if (is_wp_error($q)) {
        return $q;
    }

    if ($in['pay_state'] !== 'unpaid') {
        $q['fee_pct']    = 0;
        $q['fee_amount'] = 0.0;
        $q['payable']    = (float) $q['total'];
    }
    $use_deposit     = $in['pay_state'] === 'deposit' || ($in['pay_state'] === 'unpaid' && $in['link_amount'] === 'deposit');
    $pct             = $use_deposit ? wedding_booking_get_deposit_pct((int) $q['package_id']) : 100;
    $q['pay_pct']    = $pct;
    $q['due_now']    = round((float) $q['payable'] * $pct / 100, 2);
    $q['balance']    = max(0, round((float) $q['payable'] - $q['due_now'], 2));
    $q['balance_due_date'] = $q['balance'] > 0.01 ? wedding_booking_balance_due_date((string) $in['session_date']) : '';

    return $q;
}

/**
 * Summary lines for the Add booking price box.
 */
function wedding_booking_admin_quote_lines(array $q, array $in)
{
    $rows   = [];
    $rows[] = ['label' => $q['package_name'], 'value' => wedding_booking_admin_money($q['package_price'])];
    if ($q['addons_label'] !== '') {
        /* translators: %s: add-on names */
        $rows[] = ['label' => sprintf(__('Add-ons: %s', 'wedding-booking'), $q['addons_label']), 'value' => wedding_booking_admin_money($q['addons_total'])];
    }
    if ((float) $q['discount'] > 0) {
        /* translators: %s: promo code */
        $rows[] = ['label' => sprintf(__('Promo code %s', 'wedding-booking'), strtoupper($q['coupon_code'])), 'value' => '−' . wedding_booking_admin_money($q['discount'])];
    }
    if ((float) $q['fee_amount'] > 0) {
        $rows[] = ['label' => $q['fee_label'], 'value' => wedding_booking_admin_money($q['fee_amount'])];
    }
    $rows[] = ['label' => __('Booking total', 'wedding-booking'), 'value' => wedding_booking_admin_money($q['payable']), 'strong' => true];

    if ($in['pay_state'] === 'unpaid') {
        $rows[] = ['label' => __('Payment link asks for', 'wedding-booking'), 'value' => wedding_booking_admin_money($q['due_now']), 'strong' => true, 'tone' => 'due'];
    } else {
        /* translators: %d: deposit percentage */
        $label  = $q['pay_pct'] < 100 ? sprintf(__('Paid now (%d%% deposit)', 'wedding-booking'), $q['pay_pct']) : __('Paid now', 'wedding-booking');
        $rows[] = ['label' => $label, 'value' => wedding_booking_admin_money($q['due_now']), 'strong' => true, 'tone' => 'paid'];
    }
    if ($q['balance'] > 0.01) {
        $label = __('Balance still due', 'wedding-booking');
        if ($q['balance_due_date'] !== '') {
            /* translators: %s: date */
            $label = sprintf(__('Balance due by %s', 'wedding-booking'), wedding_booking_admin_date_label($q['balance_due_date']));
        }
        $rows[] = ['label' => $label, 'value' => wedding_booking_admin_money($q['balance'])];
    }

    return $rows;
}

add_action('wp_ajax_wedding_booking_admin_quote_booking', 'wedding_booking_admin_ajax_quote_booking');
function wedding_booking_admin_ajax_quote_booking()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    $in = wedding_booking_admin_booking_input_from_post();
    if ($in['package_id'] < 1) {
        wp_send_json_error(['message' => __('Choose a package.', 'wedding-booking')]);
    }

    $coupon_error = '';
    $q            = wedding_booking_admin_price_booking($in);
    if (is_wp_error($q) && $q->get_error_code() === 'wedding_booking_coupon_invalid') {
        $coupon_error      = $q->get_error_message();
        $in['coupon_code'] = '';
        $q                 = wedding_booking_admin_price_booking($in);
    }
    if (is_wp_error($q)) {
        wp_send_json_error(['message' => $q->get_error_message()]);
    }

    $date_note = '';
    if ($in['session_date'] !== '') {
        $ok = wedding_booking_validate_booking_date($in['session_date'], 0, wedding_booking_admin_clean_time($in['session_time']), '', true);
        if (is_wp_error($ok) && ! ($ok->get_error_code() === 'wedding_booking_slot_required' && $in['session_time'] === '')) {
            $date_note = $ok->get_error_message();
        }
    }

    wp_send_json_success([
        'rows'        => wedding_booking_admin_quote_lines($q, $in),
        'depositPct'  => wedding_booking_get_deposit_pct((int) $q['package_id']),
        'couponError' => $coupon_error,
        'dateError'   => $date_note,
    ]);
}

add_action('wp_ajax_wedding_booking_admin_create_booking', 'wedding_booking_admin_ajax_create_booking');
function wedding_booking_admin_ajax_create_booking()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    $result = wedding_booking_admin_create_booking(wedding_booking_admin_booking_input_from_post());
    if (is_wp_error($result)) {
        $data = (array) $result->get_error_data();
        wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'field' => (string) ($data['field'] ?? '')]);
    }
    wp_send_json_success($result);
}

/**
 * Create a booking the studio took itself: a WooCommerce order (created_via
 * wedding-booking-admin), then either paid (the usual payment hooks make the
 * booking row, the balance order and the calendar event) or held unpaid with
 * a payment link.
 *
 * @param array $in wedding_booking_admin_booking_input_from_post() shape.
 * @return array|WP_Error booking_id, order_id, status, pay_url, mailed, redirect.
 */
function wedding_booking_admin_create_booking(array $in)
{
    global $wpdb;
    if (! function_exists('wedding_booking_create_booking_order') || ! function_exists('wc_get_order')) {
        return new WP_Error('wedding_booking_no_wc', __('Adding bookings needs WooCommerce to be active.', 'wedding-booking'));
    }
    if ((int) $in['package_id'] < 1) {
        return new WP_Error('wedding_booking_package', __('Choose a package.', 'wedding-booking'), ['field' => 'package_id']);
    }
    if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $in['session_date'], $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return new WP_Error('wedding_booking_date', __('Choose the session date.', 'wedding-booking'), ['field' => 'session_date']);
    }
    if (trim($in['first_name'] . $in['last_name']) === '') {
        return new WP_Error('wedding_booking_name', __('Enter the customer\'s name.', 'wedding-booking'), ['field' => 'first_name']);
    }
    if (($in['email_raw'] ?? $in['email']) !== '' && ! is_email($in['email'])) {
        return new WP_Error('wedding_booking_email', __('That email address doesn\'t look right.', 'wedding-booking'), ['field' => 'email']);
    }
    if ($in['notify'] && $in['email'] === '') {
        return new WP_Error('wedding_booking_email', __('Add the customer\'s email address, or untick “Email the customer”.', 'wedding-booking'), ['field' => 'email']);
    }

    $time = wedding_booking_admin_clean_time($in['session_time']);
    if (! $in['override']) {
        $ok = wedding_booking_validate_booking_date($in['session_date'], 0, $time, '', true);
        if (is_wp_error($ok)) {
            $msg = $ok->get_error_message();
            if (in_array($ok->get_error_code(), ['wedding_booking_date_taken', 'wedding_booking_slot_taken'], true)) {
                $msg .= ' ' . __('Tick “Allow even if the date is full” to book it anyway.', 'wedding-booking');
            }
            return new WP_Error($ok->get_error_code(), $msg, ['field' => in_array($ok->get_error_code(), ['wedding_booking_slot_required', 'wedding_booking_slot_invalid', 'wedding_booking_slot_taken'], true) ? 'session_time' : 'session_date']);
        }
    }

    $q = wedding_booking_admin_price_booking($in);
    if (is_wp_error($q)) {
        return new WP_Error($q->get_error_code(), $q->get_error_message(), ['field' => $q->get_error_code() === 'wedding_booking_coupon_invalid' ? 'coupon_code' : 'package_id']);
    }

    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    if (! $product_id || get_post_status($product_id) === false) {
        wedding_booking_create_wc_product();
        $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    }
    if (! $product_id) {
        return new WP_Error('wedding_booking_product', __('The hidden booking product is missing. Deactivate and reactivate Wedding Booking to recreate it.', 'wedding-booking'));
    }

    $customer = $in['email'] !== '' ? get_user_by('email', $in['email']) : false;
    $paid     = $in['pay_state'] !== 'unpaid' || (float) $q['due_now'] <= 0;

    $booking = [
        'product_id'       => $product_id,
        'session_type'     => $q['session_type'],
        'package_name'     => $q['package_name'],
        'package_id'       => $q['package_id'],
        'addon_ids'        => $q['addon_ids'],
        'addons_label'     => $q['addons_label'],
        'addons_total'     => $q['addons_total'],
        'subtotal'         => $q['subtotal'],
        'coupon_code'      => $q['coupon_code'],
        'discount'         => $q['discount'],
        'total'            => $q['payable'],
        'fee_pct'          => $q['fee_pct'],
        'fee_amount'       => $q['fee_amount'],
        'deposit'          => $q['due_now'],
        'deposit_pct'      => $q['pay_pct'],
        'balance_due_date' => $q['balance_due_date'],
        'session_date'     => $in['session_date'],
        'session_time'     => $time,
        'hold_token'       => '',
        'contract'         => [],
        'currency'         => wedding_booking_get_currency_symbol(),
        'customer_id'      => $customer ? (int) $customer->ID : 0,
    ];
    $details = [
        'first_name' => $in['first_name'],
        'last_name'  => $in['last_name'],
        'email'      => $in['email'],
        'phone'      => $in['phone'],
        'country'    => $in['country'],
        'notes'      => $in['notes'],
    ];

    $order = wedding_booking_create_booking_order($booking, $details, '', wedding_booking_booking_order_created_via());
    if (! $order) {
        return new WP_Error('wedding_booking_order', __('The booking order could not be created. Please try again.', 'wedding-booking'));
    }
    $user = wedding_booking_admin_user_label();
    /* translators: %s: user name */
    $order->add_order_note(sprintf(__('Booking added by %s in Wedding Booking → Bookings.', 'wedding-booking'), $user));
    if ($in['override']) {
        $order->add_order_note(__('Added with “Allow even if the date is full”.', 'wedding-booking'));
    }

    $pay_url = '';
    $mailed  = false;
    if ($paid) {
        $methods = wedding_booking_admin_payment_methods();
        $label   = $methods[$in['method']];
        $order->set_payment_method_title($label);
        $order->save();
        if ((float) $q['due_now'] > 0) {
            $order->add_order_note(wedding_booking_admin_payment_note($q['due_now'], $label, $in['method_note']));
        }
        if (! $in['notify']) {
            wedding_booking_admin_quiet_customer_emails($order->get_id());
        }
        $order->payment_complete();
        $mailed = $in['notify'] && $in['email'] !== '';
    } else {
        wedding_booking_upsert_booking_from_order($order, 'awaiting_payment');
        $order   = wc_get_order($order->get_id());
        $pay_url = (string) $order->get_checkout_payment_url();
        if ($in['notify'] && $in['email'] !== '') {
            $mailed = wedding_booking_admin_send_pay_link_email($order);
            if ($mailed) {
                /* translators: %s: customer email */
                $order->add_order_note(sprintf(__('Payment link emailed to %s.', 'wedding-booking'), $in['email']));
            }
        }
    }

    $booking_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wedding_booking_bookings WHERE order_id = %d", (int) $order->get_id())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ($booking_id < 1) {
        $booking_id = (int) wedding_booking_upsert_booking_from_order(wc_get_order($order->get_id()));
    }
    $status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wedding_booking_bookings WHERE id = %d", $booking_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    $redirect = add_query_arg(array_filter([
        'page'    => 'wedding-booking-bookings',
        'wedding_booking_msg'  => 'added',
        'wedding_booking_bid'  => $booking_id,
        'wedding_booking_mail' => $mailed ? 1 : 0,
    ]), admin_url('admin.php'));

    return [
        'booking_id' => $booking_id,
        'order_id'   => (int) $order->get_id(),
        'status'     => $status,
        'pay_url'    => $pay_url,
        'mailed'     => (bool) $mailed,
        'redirect'   => $redirect,
    ];
}

/**
 * "Payment of ৳100.00 recorded by Jane (Cash). Note: receipt 12."
 */
function wedding_booking_admin_payment_note($amount, $method_label, $extra = '')
{
    /* translators: 1: amount, 2: user name, 3: payment method */
    $note = sprintf(__('Payment of %1$s recorded by %2$s (%3$s).', 'wedding-booking'), wedding_booking_admin_money($amount), wedding_booking_admin_user_label(), $method_label);
    if (trim((string) $extra) !== '') {
        /* translators: %s: the studio's note about the payment */
        $note .= ' ' . sprintf(__('Note: %s', 'wedding-booking'), trim((string) $extra));
    }

    return $note;
}

/* ═══════════════════════════════════════════════════════════════
   Edit / reschedule
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_wedding_booking_admin_edit_booking', 'wedding_booking_admin_ajax_edit_booking');
function wedding_booking_admin_ajax_edit_booking()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    $in     = wedding_booking_admin_booking_input_from_post();
    $result = wedding_booking_admin_update_booking(absint(wp_unslash($_POST['id'] ?? 0)), $in);
    if (is_wp_error($result)) {
        $data = (array) $result->get_error_data();
        wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'field' => (string) ($data['field'] ?? '')]);
    }
    wp_send_json_success($result);
}

/**
 * Change a booking's client details, date and start time, everywhere they
 * are kept: the booking row, the booking order (billing, order meta, booking
 * item meta), the balance order, the Date Slots marks and Google Calendar.
 *
 * @param array $in first_name, last_name, email, phone, notes, session_date,
 *                  session_time, notify, override.
 * @return array|WP_Error
 */
function wedding_booking_admin_update_booking($booking_id, array $in)
{
    global $wpdb;
    $table = $wpdb->prefix . 'wedding_booking_bookings';
    $b     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $booking_id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    if (! $b) {
        return new WP_Error('wedding_booking_booking_missing', __('Booking not found. Reload the page and try again.', 'wedding-booking'));
    }

    $date = (string) $in['session_date'];
    if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return new WP_Error('wedding_booking_date', __('Choose the session date.', 'wedding-booking'), ['field' => 'session_date']);
    }
    if (trim($in['first_name'] . $in['last_name']) === '') {
        return new WP_Error('wedding_booking_name', __('Enter the customer\'s name.', 'wedding-booking'), ['field' => 'first_name']);
    }
    if (($in['email_raw'] ?? $in['email']) !== '' && ! is_email($in['email'])) {
        return new WP_Error('wedding_booking_email', __('That email address doesn\'t look right.', 'wedding-booking'), ['field' => 'email']);
    }

    [$main, $due] = wedding_booking_admin_booking_orders($b);
    $order_id = $main ? (int) $main->get_id() : 0;
    $old_date = (string) $b->session_date;
    $old_time = (string) $b->session_time;
    $time     = wedding_booking_admin_clean_time($in['session_time']);
    $moved    = $date !== $old_date || wedding_booking_normalize_time($time) !== wedding_booking_normalize_time($old_time) || ($time !== $old_time && wedding_booking_normalize_time($time) === '');

    if ($moved && ! $in['override'] && wedding_booking_admin_status_key($b->status) !== 'cancelled') {
        $ok = wedding_booking_validate_booking_date($date, $order_id, $time, '', true);
        if (is_wp_error($ok)) {
            $msg = $ok->get_error_message();
            if (in_array($ok->get_error_code(), ['wedding_booking_date_taken', 'wedding_booking_slot_taken'], true)) {
                $msg .= ' ' . __('Tick “Allow even if the date is full” to move it anyway.', 'wedding-booking');
            }
            return new WP_Error($ok->get_error_code(), $msg, ['field' => in_array($ok->get_error_code(), ['wedding_booking_slot_required', 'wedding_booking_slot_invalid', 'wedding_booking_slot_taken'], true) ? 'session_time' : 'session_date']);
        }
    }

    $name    = trim($in['first_name'] . ' ' . $in['last_name']);
    $changes = [];
    if ($moved) {
        /* translators: 1: old date and time, 2: new date and time */
        $changes[] = sprintf(__('session moved from %1$s to %2$s', 'wedding-booking'), wedding_booking_admin_when_label($old_date, $old_time), wedding_booking_admin_when_label($date, $time));
    }
    if ($name !== (string) $b->client_name) {
        $changes[] = __('name', 'wedding-booking');
    }
    if ($in['email'] !== (string) $b->client_email) {
        $changes[] = __('email', 'wedding-booking');
    }
    if ($in['phone'] !== (string) $b->client_phone) {
        $changes[] = __('phone', 'wedding-booking');
    }
    if (trim($in['notes']) !== trim((string) $b->notes)) {
        $changes[] = __('notes', 'wedding-booking');
    }

    $wpdb->update($table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        'client_name'  => $name,
        'client_email' => $in['email'],
        'client_phone' => $in['phone'],
        'notes'        => $in['notes'],
        'session_date' => $date,
        'session_time' => $time,
    ], ['id' => (int) $b->id]);

    // A deadline set N days before the shoot moves with the shoot.
    $shift_due = static function ($due_by) use ($old_date, $date) {
        if ($due_by === '' || $old_date === '' || $old_date === $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $old_date)) {
            return $due_by;
        }
        $days = (int) round((strtotime($date . ' UTC') - strtotime($old_date . ' UTC')) / DAY_IN_SECONDS);
        return gmdate('Y-m-d', (int) strtotime($due_by . ' UTC') + $days * DAY_IN_SECONDS);
    };

    if ($main) {
        $main->set_billing_first_name($in['first_name']);
        $main->set_billing_last_name($in['last_name']);
        $main->set_billing_email($in['email']);
        $main->set_billing_phone($in['phone']);
        $main->set_customer_note($in['notes']);
        $main->update_meta_data('_wedding_booking_billing_event_date', $date);
        $main->update_meta_data('_wedding_booking_billing_event_time', $time);
        $due_by = (string) $main->get_meta('_wedding_booking_balance_due_date', true);
        if ($due_by !== '') {
            $main->update_meta_data('_wedding_booking_balance_due_date', $shift_due($due_by));
        }
        $item = function_exists('wedding_booking_get_booking_item') ? wedding_booking_get_booking_item($main) : null;
        if ($item) {
            $item->update_meta_data('_wedding_booking_session_date', $date);
            $item->update_meta_data('_wedding_booking_session_time', $time);
            $item->update_meta_data('_wedding_booking_client_name', $name);
            $item->update_meta_data('_wedding_booking_client_email', $in['email']);
            $item->update_meta_data('_wedding_booking_client_phone', $in['phone']);
            $item->update_meta_data('_wedding_booking_notes', $in['notes']);
            $item->save();
        }
        // A reschedule answers a pending change request.
        $request = $main->get_meta('_wedding_booking_change_request', true);
        if ($moved && is_array($request) && empty($request['handled'])) {
            $request['handled'] = time();
            $main->update_meta_data('_wedding_booking_change_request', $request);
        }
        if ($changes) {
            /* translators: 1: user name, 2: list of changes */
            $main->add_order_note(sprintf(__('Booking updated by %1$s in Wedding Booking → Bookings: %2$s.', 'wedding-booking'), wedding_booking_admin_user_label(), implode(', ', $changes)));
        }
        $main->save();
    }

    if ($due) {
        $due->set_billing_first_name($in['first_name']);
        $due->set_billing_last_name($in['last_name']);
        $due->set_billing_email($in['email']);
        $due->set_billing_phone($in['phone']);
        $due->update_meta_data('_wedding_booking_session_date', $date);
        $due_by = (string) $due->get_meta('_wedding_booking_balance_due_date', true);
        if ($due_by !== '') {
            $due->update_meta_data('_wedding_booking_balance_due_date', $shift_due($due_by));
        }
        foreach ($due->get_items() as $due_item) {
            if ($due_item->get_meta('_wedding_booking_session_date') !== '' || (int) $due_item->get_meta('_wedding_booking_is_balance_item') === 1) {
                $due_item->update_meta_data('_wedding_booking_session_date', $date);
                $due_item->save();
            }
        }
        if ($moved) {
            /* translators: %s: new date and time */
            $due->add_order_note(sprintf(__('Session moved to %s.', 'wedding-booking'), wedding_booking_admin_when_label($date, $time)));
        }
        $due->save();
    }

    if ($old_date !== $date) {
        wedding_booking_refresh_date_slot($old_date);
    }
    wedding_booking_refresh_date_slot($date);

    /**
     * A booking was edited or rescheduled (Google Calendar updates its event).
     *
     * @param int $booking_id
     * @param int $order_id
     */
    do_action('wedding_booking_booking_updated', (int) $b->id, $order_id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Wedding Booking's own hook.

    $mailed = false;
    if ($in['notify'] && $in['email'] !== '' && $changes) {
        $mailed = wedding_booking_admin_send_booking_updated_email($b, $in, $date, $time, $moved, $main);
        if ($mailed && $main) {
            /* translators: %s: customer email */
            $main->add_order_note(sprintf(__('Booking update emailed to %s.', 'wedding-booking'), $in['email']));
        }
    }

    return [
        'booking_id' => (int) $b->id,
        'moved'      => $moved,
        'changes'    => $changes,
        'mailed'     => (bool) $mailed,
        'message'    => $changes ? __('Booking updated.', 'wedding-booking') : __('Nothing changed.', 'wedding-booking'),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   Record a payment
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_wedding_booking_admin_record_payment', 'wedding_booking_admin_ajax_record_payment');
function wedding_booking_admin_ajax_record_payment()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    $result = wedding_booking_admin_record_payment(
        absint(wp_unslash($_POST['id'] ?? 0)),
        sanitize_key(wp_unslash($_POST['method'] ?? 'cash')),
        sanitize_text_field(wp_unslash($_POST['method_note'] ?? '')),
        absint(wp_unslash($_POST['notify'] ?? 1)) === 1
    );
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
    }
    wp_send_json_success($result);
}

/**
 * Log money the studio received: the booking order while unpaid, else the
 * open balance order. The payment hooks move the booking on (awaiting →
 * deposit paid / paid in full; balance paid → completed).
 *
 * @return array|WP_Error
 */
function wedding_booking_admin_record_payment($booking_id, $method, $note = '', $notify = true)
{
    global $wpdb;
    $table = $wpdb->prefix . 'wedding_booking_bookings';
    $b     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $booking_id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    if (! $b) {
        return new WP_Error('wedding_booking_booking_missing', __('Booking not found. Reload the page and try again.', 'wedding-booking'));
    }
    [$main, $due] = wedding_booking_admin_booking_orders($b);
    $what = wedding_booking_admin_payment_due($main, $due);
    if (! $what) {
        return new WP_Error('wedding_booking_nothing_due', __('Nothing is due on this booking, so there is no payment to record.', 'wedding-booking'));
    }

    // The studio took this money itself, so the online-payment fee that was
    // added for the pay link doesn't apply.
    if (function_exists('wedding_booking_strip_fee_for_gateway') && wedding_booking_strip_fee_for_gateway($what['order'], '', true)) {
        [$main, $due] = wedding_booking_admin_booking_orders($b);
        $what         = wedding_booking_admin_payment_due($main, $due);
        if (! $what) {
            return new WP_Error('wedding_booking_nothing_due', __('Nothing is due on this booking, so there is no payment to record.', 'wedding-booking'));
        }
    }

    $methods = wedding_booking_admin_payment_methods();
    $label   = $methods[$method] ?? $methods['other'];
    $order   = $what['order'];
    $text    = wedding_booking_admin_payment_note($what['amount'], $label, mb_substr((string) $note, 0, 200));

    $order->set_payment_method_title($label);
    $order->add_order_note($text);
    $order->save();
    if ($what['kind'] === 'balance' && $main) {
        /* translators: 1: payment note, 2: balance order number */
        $main->add_order_note(sprintf(__('%1$s (balance order #%2$s)', 'wedding-booking'), $text, $due->get_order_number()));
    }
    if (! $notify) {
        wedding_booking_admin_quiet_customer_emails($order->get_id());
    }
    $order->payment_complete();

    $status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", (int) $b->id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

    return [
        'booking_id'     => (int) $b->id,
        'kind'           => $what['kind'],
        'order_id'       => (int) $order->get_id(),
        'amount'         => $what['amount'],
        'booking_status' => wedding_booking_admin_status_key($status),
        /* translators: 1: amount, 2: booking status */
        'message'        => sprintf(__('Payment of %1$s recorded. The booking is now “%2$s”.', 'wedding-booking'), wedding_booking_admin_money($what['amount']), wedding_booking_admin_status_label($status)),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   Google Calendar re-sync, change requests
═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_wedding_booking_admin_gcal_sync_booking', 'wedding_booking_admin_ajax_gcal_sync_booking');
function wedding_booking_admin_ajax_gcal_sync_booking()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    if (! function_exists('wedding_booking_gcal_sync_booking')) {
        wp_send_json_error(['message' => __('Google Calendar sync is unavailable.', 'wedding-booking')]);
    }
    $id     = absint(wp_unslash($_POST['id'] ?? 0));
    $result = wedding_booking_gcal_sync_booking($id);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT status, gcal_event_id FROM {$wpdb->prefix}wedding_booking_bookings WHERE id = %d", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $linked = $row && trim((string) $row->gcal_event_id) !== '';
    wp_send_json_success([
        'linked'  => $linked,
        'message' => ($row && $row->status === 'cancelled')
            ? __('Done: the booking is cancelled, so it has no calendar event.', 'wedding-booking')
            : __('Synced to Google Calendar.', 'wedding-booking'),
    ]);
}

add_action('wp_ajax_wedding_booking_admin_resolve_request', 'wedding_booking_admin_ajax_resolve_request');
function wedding_booking_admin_ajax_resolve_request()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! wedding_booking_can_manage() || ! function_exists('wc_get_order')) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    global $wpdb;
    $id  = absint(wp_unslash($_POST['id'] ?? 0));
    $oid = (int) $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}wedding_booking_bookings WHERE id = %d", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $order = $oid ? wc_get_order($oid) : null;
    $request = $order ? $order->get_meta('_wedding_booking_change_request', true) : null;
    if (! is_array($request)) {
        wp_send_json_error(['message' => __('There is no open request on this booking.', 'wedding-booking')]);
    }
    $request['handled'] = time();
    $order->update_meta_data('_wedding_booking_change_request', $request);
    /* translators: %s: user name */
    $order->add_order_note(sprintf(__('Customer change request marked as handled by %s.', 'wedding-booking'), wedding_booking_admin_user_label()));
    $order->save();
    wp_send_json_success(['message' => __('Request marked as handled.', 'wedding-booking')]);
}

/* ═══════════════════════════════════════════════════════════════
   Emails to the customer
═══════════════════════════════════════════════════════════════ */

/**
 * "Your session is reserved — pay here" for a booking added as unpaid.
 */
function wedding_booking_admin_send_pay_link_email($order)
{
    if (! $order || ! function_exists('wedding_booking_email_send')) {
        return false;
    }
    $email = sanitize_email((string) $order->get_billing_email());
    if ($email === '') {
        return false;
    }

    $meta    = function_exists('wedding_booking_get_order_booking_meta') ? wedding_booking_get_order_booking_meta($order) : ['session_type' => '', 'package_name' => '', 'session_date' => '', 'addons' => ''];
    $time    = (string) $order->get_meta('_wedding_booking_billing_event_time', true);
    $item    = function_exists('wedding_booking_get_booking_item') ? wedding_booking_get_booking_item($order) : null;
    $total   = $item ? (float) $item->get_meta('_wedding_booking_total') : (float) $order->get_total();
    $balance = $item ? (float) $item->get_meta('_wedding_booking_balance_due') : 0.0;
    $first   = trim((string) $order->get_billing_first_name());
    $url     = (string) $order->get_checkout_payment_url();
    $amount  = wedding_booking_admin_money($order->get_total());
    $date    = wedding_booking_email_pretty_date($meta['session_date']);
    $when    = $date . ($time !== '' ? ' · ' . wedding_booking_admin_time_label($time) : '');

    $content  = wedding_booking_email_pill(__('Payment needed', 'wedding-booking'), 'primary');
    /* translators: %s: customer first name */
    $content .= wedding_booking_email_title(sprintf(__('Hi %s, your session is reserved', 'wedding-booking'), $first !== '' ? $first : __('there', 'wedding-booking')));
    $content .= wedding_booking_email_text(__('We\'ve reserved your session. Please pay using the button below to confirm your booking.', 'wedding-booking'));
    $content .= wedding_booking_email_highlight(__('Your session', 'wedding-booking'), $when, trim($meta['session_type'] . ($meta['session_type'] !== '' && $meta['package_name'] !== '' ? ' · ' : '') . $meta['package_name']));
    $content .= wedding_booking_email_spacer(20);
    $content .= wedding_booking_email_facts([
        ['label' => __('Package', 'wedding-booking'), 'value' => $meta['package_name']],
        ['label' => __('Add-ons', 'wedding-booking'), 'value' => $meta['addons']],
        ['label' => __('Booking total', 'wedding-booking'), 'value' => wedding_booking_admin_money($total)],
        ['label' => __('To pay now', 'wedding-booking'), 'value' => $amount, 'strong' => true],
        ['label' => __('Balance later', 'wedding-booking'), 'value' => $balance > 0.01 ? wedding_booking_admin_money($balance) : ''],
    ], ['tone' => 'brand']);
    $content .= wedding_booking_email_spacer(22);
    /* translators: %s: amount */
    $content .= wedding_booking_email_button($url, sprintf(__('Pay %s now', 'wedding-booking'), $amount));
    $content .= wedding_booking_email_spacer(12);
    $content .= wedding_booking_email_text(__('If the button doesn\'t work, copy this link into your browser:', 'wedding-booking') . '<br><span style="word-break:break-all;">' . esc_html($url) . '</span>', true);

    $studio = sanitize_email((string) get_option('wedding_booking_admin_email', get_option('admin_email')));

    return (bool) wedding_booking_email_send(
        $email,
        /* translators: %s: site name */
        sprintf(__('Complete your booking — %s', 'wedding-booking'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)),
        $content,
        [
            'eyebrow'   => __('Your booking', 'wedding-booking'),
            /* translators: 1: amount, 2: session date */
            'preheader' => sprintf(__('Pay %1$s to confirm your session on %2$s.', 'wedding-booking'), $amount, $date),
            'headers'   => $studio !== '' ? ['Reply-To: ' . $studio] : [],
        ]
    );
}

/**
 * "Your booking has been updated" after an edit or a reschedule.
 */
function wedding_booking_admin_send_booking_updated_email($before, array $in, $date, $time, $moved, $main)
{
    if (! function_exists('wedding_booking_email_send')) {
        return false;
    }
    $email = sanitize_email((string) $in['email']);
    if ($email === '') {
        return false;
    }
    $first    = trim((string) $in['first_name']);
    $old_when = wedding_booking_email_pretty_date((string) $before->session_date) . ((string) $before->session_time !== '' ? ' · ' . wedding_booking_admin_time_label((string) $before->session_time) : '');
    $new_when = wedding_booking_email_pretty_date($date) . ($time !== '' ? ' · ' . wedding_booking_admin_time_label($time) : '');

    $content  = wedding_booking_email_pill(__('Booking updated', 'wedding-booking'));
    $content .= wedding_booking_email_title(__('Your booking has been updated', 'wedding-booking'));
    /* translators: %s: customer first name */
    $content .= wedding_booking_email_text(sprintf(__('Hi %s, we\'ve made a change to your booking. Here are the details as they stand now.', 'wedding-booking'), $first !== '' ? $first : __('there', 'wedding-booking')));
    if ($moved) {
        $content .= wedding_booking_email_highlight(__('Your new session date', 'wedding-booking'), $new_when, trim((string) $before->session_type . ((string) $before->session_type !== '' && (string) $before->package_name !== '' ? ' · ' : '') . (string) $before->package_name));
        $content .= wedding_booking_email_spacer(20);
        $content .= wedding_booking_email_facts([
            ['label' => __('Was', 'wedding-booking'), 'value' => $old_when],
            ['label' => __('Now', 'wedding-booking'), 'value' => $new_when, 'strong' => true],
        ], ['tone' => 'brand']);
        $content .= wedding_booking_email_spacer(20);
    }
    $content .= wedding_booking_email_section_label(__('Your booking', 'wedding-booking'));
    $content .= wedding_booking_email_facts([
        ['label' => __('Package', 'wedding-booking'), 'value' => (string) $before->package_name],
        ['label' => __('Session', 'wedding-booking'), 'value' => $new_when, 'strong' => ! $moved],
        ['label' => __('Name', 'wedding-booking'), 'value' => trim($in['first_name'] . ' ' . $in['last_name'])],
        ['label' => __('Email', 'wedding-booking'), 'value' => $email],
        ['label' => __('Phone', 'wedding-booking'), 'value' => (string) $in['phone']],
        ['label' => __('Booking', 'wedding-booking'), 'value' => $main ? '#' . $main->get_order_number() : ''],
    ]);
    $content .= wedding_booking_email_spacer(20);
    $content .= wedding_booking_email_text(__('If anything looks wrong, just reply to this email.', 'wedding-booking'));

    $studio = sanitize_email((string) get_option('wedding_booking_admin_email', get_option('admin_email')));

    return (bool) wedding_booking_email_send(
        $email,
        /* translators: %s: site name */
        sprintf(__('Your booking has been updated — %s', 'wedding-booking'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)),
        $content,
        [
            'eyebrow'   => __('Your booking', 'wedding-booking'),
            'preheader' => $moved ? sprintf(
                /* translators: %s: new session date */
                __('Your session is now on %s.', 'wedding-booking'),
                $new_when
            ) : __('We\'ve updated your booking details.', 'wedding-booking'),
            'headers'   => $studio !== '' ? ['Reply-To: ' . $studio] : [],
        ]
    );
}

/* ═══════════════════════════════════════════════════════════════
   CSV export of the current filtered list
═══════════════════════════════════════════════════════════════ */
add_action('admin_post_wedding_booking_export_bookings', 'wedding_booking_admin_export_bookings');
function wedding_booking_admin_export_bookings()
{
    if (! wedding_booking_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to export bookings.', 'wedding-booking'), '', ['response' => 403]);
    }
    check_admin_referer('wedding_booking_export_bookings');

    $f = wedding_booking_admin_booking_filters($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wedding-booking-bookings-' . wp_date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    wedding_booking_admin_write_bookings_csv($out, $f);
    fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
    exit;
}

/**
 * Neutralise spreadsheet formulas: a cell starting with = + - @ (or a tab /
 * carriage return) is prefixed with an apostrophe so Excel shows it as text.
 */
function wedding_booking_admin_csv_cell($value)
{
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $value = "'" . $value;
    }

    return $value;
}

function wedding_booking_admin_csv_header()
{
    return [
        __('Booking ID', 'wedding-booking'),
        __('Order #', 'wedding-booking'),
        __('Status', 'wedding-booking'),
        __('Session date', 'wedding-booking'),
        __('Session time', 'wedding-booking'),
        __('Package', 'wedding-booking'),
        __('Session type', 'wedding-booking'),
        __('Add-ons', 'wedding-booking'),
        __('Client name', 'wedding-booking'),
        __('Email', 'wedding-booking'),
        __('Phone', 'wedding-booking'),
        __('Country', 'wedding-booking'),
        __('Total', 'wedding-booking'),
        __('Deposit', 'wedding-booking'),
        __('Balance', 'wedding-booking'),
        __('Balance status', 'wedding-booking'),
        __('Coupon', 'wedding-booking'),
        __('Created', 'wedding-booking'),
    ];
}

/**
 * One booking as a CSV row (raw values; see wedding_booking_admin_csv_cell()).
 */
function wedding_booking_admin_csv_row($b)
{
    [$main, $due] = wedding_booking_admin_booking_orders($b);
    $status  = wedding_booking_admin_status_key($b->status);
    $total   = (float) $b->total;
    $deposit = (float) $b->deposit;
    $balance = max(0, round($total - $deposit, 2));

    if ($status === 'cancelled') {
        $bal_status = __('Cancelled', 'wedding-booking');
    } elseif ($status === 'awaiting_payment' || ($main && ! $main->is_paid() && ! $main->has_status(['cancelled', 'refunded']))) {
        $bal_status = __('Not paid yet', 'wedding-booking');
    } elseif ($balance <= 0.01) {
        $bal_status = __('Paid in full', 'wedding-booking');
    } elseif ($due && $due->has_status(['processing', 'completed'])) {
        $bal_status = __('Paid', 'wedding-booking');
    } elseif ($due && function_exists('wc_get_order_status_name')) {
        /* translators: %s: WooCommerce order status */
        $bal_status = sprintf(__('Due (%s)', 'wedding-booking'), wc_get_order_status_name($due->get_status()));
    } else {
        $bal_status = __('Due', 'wedding-booking');
    }

    $country = (string) $b->client_country;
    if (strlen($country) === 2 && function_exists('WC') && WC() && isset(WC()->countries->countries[strtoupper($country)])) {
        $country = html_entity_decode((string) WC()->countries->countries[strtoupper($country)], ENT_QUOTES, 'UTF-8');
    } elseif ($country === '' && $main) {
        $country = (string) $main->get_billing_country();
    }

    $item    = ($main && function_exists('wedding_booking_get_booking_item')) ? wedding_booking_get_booking_item($main) : null;
    $created = '';
    if ($main && $main->get_date_created()) {
        $created = wp_date('Y-m-d H:i', $main->get_date_created()->getTimestamp());
    } elseif (! empty($b->created_at)) {
        $created = (string) $b->created_at;
    }

    return [
        (int) $b->id,
        $main ? $main->get_order_number() : ((int) $b->order_id > 0 ? (int) $b->order_id : ''),
        wedding_booking_admin_status_label($status),
        (string) $b->session_date,
        (string) $b->session_time,
        (string) $b->package_name,
        (string) $b->session_type,
        (string) $b->addons_json,
        (string) $b->client_name,
        (string) $b->client_email,
        (string) $b->client_phone,
        $country,
        number_format($total, 2, '.', ''),
        number_format($deposit, 2, '.', ''),
        number_format($balance, 2, '.', ''),
        $bal_status,
        $item ? (string) $item->get_meta('_wedding_booking_coupon_code') : '',
        $created,
    ];
}

/**
 * Write the bookings matching $f (all pages) as CSV: UTF-8 with a BOM so
 * Excel reads the accents, formulas neutralised.
 *
 * @param resource $handle
 */
function wedding_booking_admin_write_bookings_csv($handle, array $f)
{
    global $wpdb;
    $table   = $wpdb->prefix . 'wedding_booking_bookings';
    $where   = wedding_booking_admin_bookings_where($f);
    $orderby = wedding_booking_admin_bookings_orderby($f['sort']);

    fwrite($handle, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming a download.
    fputcsv($handle, array_map('wedding_booking_admin_csv_cell', wedding_booking_admin_csv_header()), ',', '"', '');

    $batch  = 200;
    $offset = 0;
    do {
        $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT {$batch} OFFSET {$offset}"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        foreach ($rows as $b) {
            fputcsv($handle, array_map('wedding_booking_admin_csv_cell', wedding_booking_admin_csv_row($b)), ',', '"', '');
        }
        $offset += $batch;
    } while (count($rows) === $batch);
}
