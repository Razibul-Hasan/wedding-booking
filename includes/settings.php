<?php

/**
 * Wedding Booking settings registry.
 *
 * Every option added from 1.5.0 on is declared here once — type, default and
 * limits — so the settings screens, the save handlers and the code reading
 * the values can't disagree. Older options keep their existing, hand-written
 * handling.
 *
 * Loaded unconditionally (with or without WooCommerce).
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

/**
 * Option definitions: key => [type, default, (min, max)].
 *
 * Types: bool, int, text, strlist (array of keys), intlist (array of ints),
 * times (list of HH:MM, entered as comma/space separated text).
 */
function wedding_booking_setting_defs()
{
    return [
        // Deposit & fees.
        'wedding_booking_deposit_pct'                  => ['type' => 'int', 'default' => 50, 'min' => 1, 'max' => 99],
        'wedding_booking_payment_fee_label'            => ['type' => 'text', 'default' => __('Payment fee', 'wedding-booking')],
        'wedding_booking_payment_fee_exempt_gateways'  => ['type' => 'strlist', 'default' => ['bacs', 'cheque', 'cod']],
        'wedding_booking_balance_due_enable'           => ['type' => 'bool', 'default' => 0],
        'wedding_booking_balance_due_days'             => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 365],
        'wedding_booking_coupons_enable'               => ['type' => 'bool', 'default' => 0],

        // Offline payments (bank transfer, cheque, cash on delivery) and holds.
        'wedding_booking_offline_hold_days'            => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 90],
        'wedding_booking_hold_minutes'                 => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 1440],

        // Availability.
        'wedding_booking_daily_capacity'               => ['type' => 'int', 'default' => 1, 'min' => 1, 'max' => 50],
        'wedding_booking_time_slots'                   => ['type' => 'times', 'default' => []],
        'wedding_booking_min_notice_days'              => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 365],
        'wedding_booking_max_advance_days'             => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1095],
        'wedding_booking_closed_weekdays'              => ['type' => 'intlist', 'default' => [], 'min' => 0, 'max' => 6],

        // Contract (Wedding Booking → Booking Form → Contract step).
        'wedding_booking_fe_contract_signature'        => ['type' => 'bool', 'default' => 0],

        // Customer self-service.
        'wedding_booking_account_bookings_enable'      => ['type' => 'bool', 'default' => 1],
        'wedding_booking_customer_requests_enable'     => ['type' => 'bool', 'default' => 1],

        // Plugin removal.
        'wedding_booking_delete_data_on_uninstall'     => ['type' => 'bool', 'default' => 0],
    ];
}

/**
 * Typed value of a registered option, falling back to its default.
 */
function wedding_booking_opt($key)
{
    $defs = wedding_booking_setting_defs();
    if (! isset($defs[$key])) {
        return get_option($key);
    }
    $def = $defs[$key];
    $raw = get_option($key, null);
    if (null === $raw) {
        return $def['default'];
    }

    return wedding_booking_sanitize_setting_value($def, $raw);
}

/**
 * Clamp/clean a raw value to its definition.
 */
function wedding_booking_sanitize_setting_value(array $def, $raw)
{
    switch ($def['type']) {
        case 'bool':
            return absint(is_array($raw) ? 0 : $raw) === 1 ? 1 : 0;

        case 'int':
            $v = (int) (is_array($raw) ? 0 : $raw);
            if (isset($def['min'])) {
                $v = max((int) $def['min'], $v);
            }
            if (isset($def['max'])) {
                $v = min((int) $def['max'], $v);
            }
            return $v;

        case 'strlist':
            $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
            return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $list))));

        case 'intlist':
            $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
            $out  = [];
            foreach ((array) $list as $item) {
                if ($item === '' || ! is_numeric($item)) {
                    continue;
                }
                $n = (int) $item;
                if ((isset($def['min']) && $n < $def['min']) || (isset($def['max']) && $n > $def['max'])) {
                    continue;
                }
                $out[] = $n;
            }
            $out = array_values(array_unique($out));
            sort($out);
            return $out;

        case 'times':
            return wedding_booking_parse_time_list($raw);

        case 'text':
        default:
            return sanitize_text_field(is_array($raw) ? '' : (string) $raw);
    }
}

/**
 * "9:00, 13:30 17:00" (or an array) → ['09:00', '13:30', '17:00'], sorted,
 * de-duplicated. Also accepts 12-hour input like "2pm" / "2:30 PM".
 */
function wedding_booking_parse_time_list($raw)
{
    $parts = is_array($raw) ? $raw : preg_split('/[,;\n]+/', (string) $raw);
    $out   = [];
    foreach ((array) $parts as $part) {
        $t = wedding_booking_normalize_time((string) $part);
        if ($t !== '') {
            $out[$t] = true;
        }
    }
    $out = array_keys($out);
    sort($out);

    return $out;
}

/**
 * One clock time → "HH:MM" (24h), or '' when it isn't a time.
 */
function wedding_booking_normalize_time($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === '' || ! preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/', $value, $m)) {
        return '';
    }
    $h   = (int) $m[1];
    $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
    $ap  = isset($m[3]) ? str_replace('.', '', $m[3]) : '';
    if ($ap === 'pm' && $h < 12) {
        $h += 12;
    } elseif ($ap === 'am' && $h === 12) {
        $h = 0;
    }
    if ($h > 23 || $min > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $h, $min);
}

/**
 * Save every registered option present in a submitted settings form.
 *
 * Keys absent from $post are left alone, so each screen (Settings, Frontend)
 * only writes its own fields. Checkboxes must be rendered with a hidden "0"
 * input and lists with a hidden empty "key[]" entry, so an unticked box or an
 * emptied list still arrives.
 *
 * @param array $post Raw $_POST (slashed).
 */
function wedding_booking_save_extra_settings($post)
{
    foreach (wedding_booking_setting_defs() as $key => $def) {
        if (! array_key_exists($key, (array) $post)) {
            continue;
        }
        $raw = wp_unslash($post[$key]);
        if (is_array($raw)) {
            $raw = array_map('sanitize_text_field', $raw);
        } else {
            $raw = sanitize_textarea_field((string) $raw);
        }
        update_option($key, wedding_booking_sanitize_setting_value($def, $raw));
    }
}

/* ─────────────────────────────────────────────────────────────
   Capabilities
───────────────────────────────────────────────────────────── */

/**
 * Capability for day-to-day booking management (bookings, catalog, dates,
 * frontend text). Granted to Administrators and Shop Managers. Plugin-wide
 * settings (payments, emails, Google) still need manage_options.
 */
function wedding_booking_manage_cap()
{
    return 'manage_wedding_booking';
}

function wedding_booking_can_manage()
{
    return current_user_can(wedding_booking_manage_cap()) || current_user_can('manage_options');
}

/**
 * Grant manage_wedding_booking to the roles that should run the studio's bookings.
 * Runs on activation and on every version upgrade (wedding_booking_create_tables).
 */
function wedding_booking_grant_capabilities()
{
    foreach ((array) apply_filters('wedding_booking_manager_roles', ['administrator', 'shop_manager']) as $role_name) {
        $role = get_role($role_name);
        if ($role && ! $role->has_cap(wedding_booking_manage_cap())) {
            $role->add_cap(wedding_booking_manage_cap());
        }
    }
}

/* ─────────────────────────────────────────────────────────────
   Booking statuses
───────────────────────────────────────────────────────────── */

/**
 * Booking statuses (wp_wedding_booking_bookings.status) and their admin labels.
 *
 * awaiting_payment: order placed but not paid yet (bank transfer, cheque,
 *                   cash on delivery, or a booking added by the studio as
 *                   unpaid) — the date is held.
 * pending_payment:  deposit paid, remaining balance due.
 * confirmed:        paid in full.
 * completed:        shoot done / everything settled.
 * cancelled:        cancelled or refunded — the date is free again.
 */
function wedding_booking_booking_statuses()
{
    return [
        'awaiting_payment' => __('Awaiting payment', 'wedding-booking'),
        'pending_payment'  => __('Deposit paid', 'wedding-booking'),
        'confirmed'        => __('Paid in full', 'wedding-booking'),
        'completed'        => __('Completed', 'wedding-booking'),
        'cancelled'        => __('Cancelled', 'wedding-booking'),
    ];
}

/**
 * Statuses that hold a date on the calendar. ('pending' is a legacy value.)
 */
function wedding_booking_active_booking_statuses()
{
    return ['awaiting_payment', 'pending_payment', 'confirmed', 'completed', 'pending'];
}

/* ─────────────────────────────────────────────────────────────
   Contract versions
───────────────────────────────────────────────────────────── */

/**
 * Short fingerprint of the current contract text. Stored on each order that
 * accepted it, so it is always clear which wording the customer agreed to.
 */
function wedding_booking_contract_version()
{
    $settings = function_exists('wedding_booking_get_contract_settings') ? wedding_booking_get_contract_settings() : ['text' => ''];

    return substr(md5((string) ($settings['text'] ?? '')), 0, 12);
}

/**
 * The terms acceptance posted with a booking. When the Contract step is on,
 * the box must be ticked, for the wording currently published, and — when a
 * signature is required — with the customer's typed full name.
 *
 * @return array|WP_Error [] when the Contract step is off; else accepted_at
 *                        (UTC), version, signature, ip, ua.
 */
function wedding_booking_contract_from_post()
{
    if (! function_exists('wedding_booking_contract_step_enabled') || ! wedding_booking_contract_step_enabled()) {
        return [];
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
    if (absint(wp_unslash($_POST['contract_accepted'] ?? 0)) !== 1) {
        return new WP_Error('wedding_booking_contract_required', __('Please accept the Terms & Conditions to continue.', 'wedding-booking'));
    }
    $current = wedding_booking_contract_version();
    $posted  = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_POST['contract_version'] ?? '')));
    if ($posted !== '' && $posted !== $current) {
        return new WP_Error('wedding_booking_contract_changed', __('Our terms were just updated. Please refresh the page and review them again.', 'wedding-booking'));
    }
    $signature = trim(sanitize_text_field(wp_unslash($_POST['contract_signature'] ?? '')));
    // phpcs:enable
    if ((int) wedding_booking_opt('wedding_booking_fe_contract_signature') === 1 && mb_strlen($signature) < 2) {
        return new WP_Error('wedding_booking_signature_required', __('Please type your full name to sign the agreement.', 'wedding-booking'));
    }

    $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    $ua = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    return [
        'accepted_at' => current_time('mysql', true),
        'version'     => $current,
        'signature'   => $signature,
        'ip'          => (string) $ip,
        'ua'          => $ua,
    ];
}

/**
 * Keep a copy of each contract wording customers accepted, keyed by version.
 */
function wedding_booking_remember_contract_version($version)
{
    $version = preg_replace('/[^a-f0-9]/', '', (string) $version);
    if ($version === '' || $version !== wedding_booking_contract_version()) {
        return;
    }
    $versions = get_option('wedding_booking_contract_versions', []);
    if (! is_array($versions)) {
        $versions = [];
    }
    if (isset($versions[$version])) {
        return;
    }
    $settings           = wedding_booking_get_contract_settings();
    $versions[$version] = [
        'text'  => (string) $settings['text'],
        'title' => (string) $settings['title'],
        'saved' => current_time('mysql'),
    ];
    update_option('wedding_booking_contract_versions', $versions, false);
}
