<?php

/**
 * Wedding Booking pricing: every amount a booking costs is worked out here, from
 * the database, never from numbers posted by the browser.
 *
 * wedding_booking_quote_booking() is the single source for the live preview, the
 * direct order (wedding_booking_place_order) and the classic cart flow
 * (wedding_booking_add_to_cart).
 *
 * Loaded unconditionally; the coupon helpers need WooCommerce and say so.
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

/**
 * Price a booking from the database. Names and amounts posted by the booking
 * form are never trusted: anyone can post any total, so the package and
 * add-ons are looked up by id and priced here.
 *
 * @param int         $package_id
 * @param int[]|null  $addon_ids   Selected add-on ids; null when the browser
 *                                 didn't send them (a page cached before 1.4.0),
 *                                 in which case $addon_names is matched instead.
 * @param string      $addon_names Comma-separated add-on names (old pages only).
 * @return array|WP_Error package_id, package_name, package_price, session_type,
 *                        addon_ids, addons_label, addons_total, total.
 */
function wedding_booking_price_booking_selection($package_id, $addon_ids = null, $addon_names = '')
{
    global $wpdb;
    $pfx = $wpdb->prefix . 'wedding_booking_';

    $package = $package_id > 0
        ? $wpdb->get_row($wpdb->prepare("SELECT id, session_id, name, price FROM {$pfx}packages WHERE id = %d AND active = 1", (int) $package_id)) // phpcs:ignore
        : null;
    if (! $package) {
        return new WP_Error('wedding_booking_package', __('The selected package is no longer available. Please refresh the page and choose again.', 'wedding-booking'));
    }
    $session_type = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$pfx}sessions WHERE id = %d", (int) $package->session_id)); // phpcs:ignore

    // Add-ons offered with this package: global ones plus those assigned to it
    // (package_ids is a CSV; package_id covers legacy rows) — the same rule
    // the booking form uses to show them.
    $offered = [];
    foreach ((array) $wpdb->get_results("SELECT id, name, price, package_id, package_ids FROM {$pfx}addons WHERE active = 1 ORDER BY sort_order, id") as $addon) { // phpcs:ignore
        $scope = array_filter(array_map('intval', explode(',', (string) $addon->package_ids)));
        if (! $scope && (int) $addon->package_id > 0) {
            $scope = [(int) $addon->package_id];
        }
        if (! $scope || in_array((int) $package->id, $scope, true)) {
            $offered[(int) $addon->id] = $addon;
        }
    }

    $unavailable = new WP_Error('wedding_booking_addon', __('One of the selected extras is no longer available for this package. Please refresh the page and choose again.', 'wedding-booking'));

    if (null === $addon_ids) {
        $addon_ids = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $addon_names))) as $name) {
            $match = 0;
            foreach ($offered as $id => $addon) {
                if (strcasecmp(trim((string) $addon->name), $name) === 0) {
                    $match = $id;
                    break;
                }
            }
            if (! $match) {
                return $unavailable;
            }
            $addon_ids[] = $match;
        }
    }

    $chosen = [];
    foreach (array_unique(array_map('absint', (array) $addon_ids)) as $id) {
        if ($id < 1) {
            continue;
        }
        if (! isset($offered[$id])) {
            return $unavailable;
        }
        $chosen[] = $offered[$id];
    }

    $package_price = round((float) $package->price, 2);
    $addons_total  = 0.0;
    $names         = [];
    $ids           = [];
    foreach ($chosen as $addon) {
        $addons_total += (float) $addon->price;
        $names[]       = (string) $addon->name;
        $ids[]         = (int) $addon->id;
    }
    $addons_total = round($addons_total, 2);

    return [
        'package_id'    => (int) $package->id,
        'package_name'  => (string) $package->name,
        'package_price' => $package_price,
        'session_type'  => $session_type,
        'addon_ids'     => $ids,
        'addons_label'  => implode(', ', $names),
        'addons_total'  => $addons_total,
        'total'         => round($package_price + $addons_total, 2),
    ];
}

/**
 * Add-on ids posted by the booking form as a CSV ("3,7"), or null when the
 * field is absent (an old cached page), for wedding_booking_price_booking_selection().
 */
function wedding_booking_posted_addon_ids()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
    if (! isset($_POST['addon_ids'])) {
        return null;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
    $raw = sanitize_text_field(wp_unslash($_POST['addon_ids']));

    return array_values(array_filter(array_map('absint', explode(',', $raw))));
}

/* ─────────────────────────────────────────────────────────────
   Deposit
───────────────────────────────────────────────────────────── */

/**
 * Whether customers may pay a deposit instead of the full price.
 */
function wedding_booking_deposit_enabled()
{
    return (int) get_option('wedding_booking_enable_partial_payment', 1) === 1;
}

/**
 * Deposit percentage for a package: its own value when set (1–99), else the
 * global Wedding Booking → Settings value (default 50).
 */
function wedding_booking_get_deposit_pct($package_id = 0)
{
    static $cache = [];
    $pct = (int) wedding_booking_opt('wedding_booking_deposit_pct');

    $package_id = (int) $package_id;
    if ($package_id > 0) {
        if (! array_key_exists($package_id, $cache)) {
            global $wpdb;
            $cache[$package_id] = (int) $wpdb->get_var($wpdb->prepare("SELECT deposit_pct FROM {$wpdb->prefix}wedding_booking_packages WHERE id = %d", $package_id)); // phpcs:ignore
        }
        if ($cache[$package_id] > 0 && $cache[$package_id] < 100) {
            $pct = $cache[$package_id];
        }
    }

    return max(1, min(99, $pct));
}

/* ─────────────────────────────────────────────────────────────
   Payment fee
───────────────────────────────────────────────────────────── */

function wedding_booking_payment_fee_label()
{
    $label = trim((string) wedding_booking_opt('wedding_booking_payment_fee_label'));

    return $label !== '' ? $label : __('Payment fee', 'wedding-booking');
}

/**
 * Whether the payment fee applies to a gateway. Unknown ('') counts as
 * "applies": the booking form places its order before the customer picks a
 * method, and the fee comes off at payment time for exempt methods
 * (wedding_booking_strip_fee_for_gateway()).
 */
function wedding_booking_fee_applies_to_gateway($gateway_id)
{
    $gateway_id = sanitize_key((string) $gateway_id);
    if ($gateway_id === '') {
        return true;
    }

    return ! in_array($gateway_id, (array) wedding_booking_opt('wedding_booking_payment_fee_exempt_gateways'), true);
}

/* ─────────────────────────────────────────────────────────────
   Coupons
───────────────────────────────────────────────────────────── */

/**
 * Promo codes on the booking form: switched on in Wedding Booking → Settings and
 * allowed by WooCommerce (WooCommerce → Settings → "Enable coupons").
 */
function wedding_booking_coupons_enabled()
{
    return (int) wedding_booking_opt('wedding_booking_coupons_enable') === 1
        && class_exists('WC_Coupon')
        && function_exists('wc_coupons_enabled') && wc_coupons_enabled();
}

/**
 * Validate a WooCommerce coupon against a booking and work out its discount.
 * WooCommerce's own rules apply (expiry, usage limits, minimum spend,
 * allowed emails, product restrictions against the booking product).
 *
 * @return array|WP_Error ['code' => string, 'discount' => float]
 */
function wedding_booking_booking_coupon_discount($code, $subtotal, $email = '')
{
    $invalid = static function ($message) {
        return new WP_Error('wedding_booking_coupon_invalid', $message);
    };

    if (! wedding_booking_coupons_enabled()) {
        return $invalid(__('Promo codes are not accepted for bookings.', 'wedding-booking'));
    }

    $code   = wc_format_coupon_code((string) $code);
    $coupon = new WC_Coupon($code);
    if (! $coupon->get_id()) {
        /* translators: %s: coupon code */
        return $invalid(sprintf(__('The promo code "%s" doesn\'t exist.', 'wedding-booking'), $code));
    }

    $product = wc_get_product((int) get_option('wedding_booking_wc_product_id', 0));
    $item    = new WC_Order_Item_Product();
    if ($product) {
        $item->set_product($product);
    }
    $item->set_quantity(1);
    $item->set_subtotal((float) $subtotal);
    $item->set_total((float) $subtotal);

    $probe = new WC_Order();
    $probe->add_item($item);
    if ($email !== '' && is_email($email)) {
        $probe->set_billing_email($email);
    }
    if (is_user_logged_in()) {
        $probe->set_customer_id(get_current_user_id());
    }

    $discounts = new WC_Discounts($probe);
    $valid     = $discounts->is_coupon_valid($coupon);
    if (is_wp_error($valid)) {
        return $invalid(wp_strip_all_tags($valid->get_error_message()));
    }
    $applied = $discounts->apply_coupon($coupon, false);
    if (is_wp_error($applied)) {
        return $invalid(wp_strip_all_tags($applied->get_error_message()));
    }

    $amount = round((float) array_sum($discounts->get_discounts_by_coupon()), 2);
    if ($amount <= 0) {
        /* translators: %s: coupon code */
        return $invalid(sprintf(__('The promo code "%s" doesn\'t apply to bookings.', 'wedding-booking'), $code));
    }

    return ['code' => $code, 'discount' => min((float) $subtotal, $amount)];
}

/* ─────────────────────────────────────────────────────────────
   Quote
───────────────────────────────────────────────────────────── */

/**
 * Every amount for a booking, from the database.
 *
 * @param array $args package_id, addon_ids (int[]|null), addon_names,
 *                    session_date, use_deposit (bool), coupon_code, email,
 *                    payment_method ('' = not chosen yet).
 * @return array|WP_Error Keys:
 *   package_id, package_name, package_price, session_type, addon_ids,
 *   addons_label, addons_total,
 *   subtotal     package + add-ons,
 *   coupon_code, discount,
 *   total        subtotal − discount (before the fee),
 *   fee_pct, fee_label, fee_amount,
 *   payable      total + fee,
 *   deposit_pct  deposit % offered for this package,
 *   deposit_eligible,
 *   pay_pct      % charged now (deposit_pct or 100),
 *   due_now, balance,
 *   balance_due_date  Y-m-d or ''.
 */
function wedding_booking_quote_booking(array $args)
{
    $args = wp_parse_args($args, [
        'package_id'     => 0,
        'addon_ids'      => null,
        'addon_names'    => '',
        'session_date'   => '',
        'use_deposit'    => false,
        'coupon_code'    => '',
        'email'          => '',
        'payment_method' => '',
    ]);

    $priced = wedding_booking_price_booking_selection((int) $args['package_id'], $args['addon_ids'], (string) $args['addon_names']);
    if (is_wp_error($priced)) {
        return $priced;
    }

    $subtotal    = (float) $priced['total'];
    $discount    = 0.0;
    $coupon_code = '';
    if (trim((string) $args['coupon_code']) !== '') {
        $coupon = wedding_booking_booking_coupon_discount($args['coupon_code'], $subtotal, (string) $args['email']);
        if (is_wp_error($coupon)) {
            return $coupon;
        }
        $discount    = (float) $coupon['discount'];
        $coupon_code = $coupon['code'];
    }

    $total   = round(max(0, $subtotal - $discount), 2);
    $fee_pct = wedding_booking_fee_applies_to_gateway($args['payment_method']) ? wedding_booking_get_payment_fee_pct() : 0;
    $fee     = round($total * $fee_pct / 100, 2);
    $payable = round($total + $fee, 2);

    $deposit_pct = wedding_booking_get_deposit_pct($priced['package_id']);
    $eligible    = wedding_booking_deposit_enabled() && $payable > 0
        && function_exists('wedding_booking_can_use_partial_payment_for_date')
        && wedding_booking_can_use_partial_payment_for_date((string) $args['session_date']);
    $pay_pct = ($eligible && ! empty($args['use_deposit'])) ? $deposit_pct : 100;
    $due_now = round($payable * $pay_pct / 100, 2);
    $balance = max(0, round($payable - $due_now, 2));

    $priced['subtotal'] = $subtotal;
    unset($priced['total']);

    return $priced + [
        'coupon_code'      => $coupon_code,
        'discount'         => round($discount, 2),
        'total'            => $total,
        'fee_pct'          => $fee_pct,
        'fee_label'        => wedding_booking_payment_fee_label(),
        'fee_amount'       => $fee,
        'payable'          => $payable,
        'deposit_pct'      => $deposit_pct,
        'deposit_eligible' => $eligible,
        'pay_pct'          => $pay_pct,
        'due_now'          => $due_now,
        'balance'          => $balance,
        'balance_due_date' => $balance > 0.01 ? wedding_booking_balance_due_date((string) $args['session_date']) : '',
    ];
}

/**
 * Quote arguments posted by the booking form (the preview, place-order and
 * add-to-cart requests all send the same fields).
 */
function wedding_booking_quote_args_from_post($payment_method = '')
{
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
    $details = isset($_POST['details']) && is_array($_POST['details']) ? wp_unslash($_POST['details']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $email   = sanitize_email((string) ($details['email'] ?? wp_unslash($_POST['client_email'] ?? '')));
    $args    = [
        'package_id'     => absint(wp_unslash($_POST['package_id'] ?? 0)),
        'addon_ids'      => wedding_booking_posted_addon_ids(),
        'addon_names'    => sanitize_text_field(wp_unslash($_POST['addons_label'] ?? '')),
        'session_date'   => sanitize_text_field(wp_unslash($_POST['session_date'] ?? '')),
        'use_deposit'    => absint(wp_unslash($_POST['use_deposit'] ?? 0)) === 1,
        'coupon_code'    => sanitize_text_field(wp_unslash($_POST['coupon_code'] ?? '')),
        'email'          => $email,
        'payment_method' => sanitize_key((string) $payment_method),
    ];
    // phpcs:enable

    return $args;
}

/* ─────────────────────────────────────────────────────────────
   Balance due date
───────────────────────────────────────────────────────────── */

/**
 * When the remaining balance is due (Wedding Booking → Settings → "Balance due"):
 * N days before the session, as Y-m-d. '' when no deadline is set or the
 * date is unknown.
 */
function wedding_booking_balance_due_date($session_date)
{
    if ((int) wedding_booking_opt('wedding_booking_balance_due_enable') !== 1) {
        return '';
    }
    $session_date = trim((string) $session_date);
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
        return '';
    }
    try {
        $due = new DateTimeImmutable($session_date, wp_timezone());
        return $due->modify('-' . (int) wedding_booking_opt('wedding_booking_balance_due_days') . ' days')->format('Y-m-d');
    } catch (Exception $e) {
        return '';
    }
}
