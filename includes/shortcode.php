<?php
defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Checkout form — field catalog & saved configuration
   The catalog is the fixed list of known fields; the admin
   "Checkout Form" settings store per-field enabled/required/label
   overrides in the wedding_booking_checkout_form_fields option.
───────────────────────────────────────────────────────────── */
function wedding_booking_checkout_field_catalog()
{
    return [
        'first_name'   => ['label' => __('First name', 'wedding-booking'),                              'type' => 'text',     'ph' => __('First name', 'wedding-booking'),                     'required' => 1, 'locked' => 1],
        'last_name'    => ['label' => __('Last name', 'wedding-booking'),                               'type' => 'text',     'ph' => __('Last name', 'wedding-booking'),                      'required' => 1],
        'email'        => ['label' => __('Email address', 'wedding-booking'),                           'type' => 'email',    'ph' => 'you@example.com',                                'required' => 1, 'locked' => 1],
        'phone'        => ['label' => __('Whatsapp', 'wedding-booking'),                                'type' => 'tel',      'ph' => '+230 5xxx xxxx',                                 'required' => 1],
        'country'      => ['label' => __('Country / Region', 'wedding-booking'),                        'type' => 'country',  'ph' => __('Country', 'wedding-booking'),                        'required' => 1],
        'address_1'    => ['label' => __('Street address', 'wedding-booking'),                          'type' => 'text',     'ph' => __('House number and street name', 'wedding-booking'),  'required' => 1, 'wide' => 1],
        'city'         => ['label' => __('Town / City', 'wedding-booking'),                             'type' => 'text',     'ph' => __('City', 'wedding-booking'),                           'required' => 1],
        'postcode'     => ['label' => __('Postcode / ZIP', 'wedding-booking'),                          'type' => 'text',     'ph' => __('Postcode', 'wedding-booking'),                       'required' => 1],
        'event_time'   => ['label' => __('Start Time', 'wedding-booking'),                              'type' => 'time',     'ph' => __('Time of the event', 'wedding-booking'),              'required' => 1],
        'hotel_place'  => ['label' => __('Hotel Name / Bungalow / Place Residence', 'wedding-booking'), 'type' => 'text',     'ph' => __('Hotel / Bungalow / Place', 'wedding-booking'),       'required' => 1, 'wide' => 1],
        'participants' => ['label' => __('Participants', 'wedding-booking'),                            'type' => 'number',   'ph' => __('Number of People', 'wedding-booking'),               'required' => 1],
        // Text, not number: room "numbers" are often "B12" or "Villa 3".
        'room_number'  => ['label' => __('Room Number', 'wedding-booking'),                             'type' => 'text',     'ph' => __('Room number', 'wedding-booking'),                    'required' => 0],
        'stay_period'  => ['label' => __('Period of stay in Mauritius', 'wedding-booking'),             'type' => 'text',     'ph' => __('From - To', 'wedding-booking'),                      'required' => 1, 'wide' => 1],
        'notes'        => ['label' => __('Notes', 'wedding-booking'),                                   'type' => 'textarea', 'ph' => __('Anything else we should know?', 'wedding-booking'), 'required' => 0, 'wide' => 1],
    ];
}

function wedding_booking_get_checkout_form_fields()
{
    $catalog = wedding_booking_checkout_field_catalog();
    $saved   = get_option('wedding_booking_checkout_form_fields', []);
    if (! is_array($saved)) {
        $saved = [];
    }

    $out = [];
    foreach ($catalog as $key => $def) {
        $row    = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
        $locked = ! empty($def['locked']);

        $enabled  = $locked ? 1 : (array_key_exists('enabled', $row) ? (int) ! empty($row['enabled']) : 1);
        $required = $locked ? 1 : (array_key_exists('required', $row) ? (int) ! empty($row['required']) : (int) ! empty($def['required']));
        $label    = isset($row['label']) && $row['label'] !== '' ? (string) $row['label'] : $def['label'];

        $out[$key] = [
            'enabled'  => $enabled,
            'required' => $enabled ? $required : 0,
            'label'    => $label,
        ];
    }

    // When the studio offers start times, the customer picks one under the
    // calendar, so the free "Start time" detail field is left out of the
    // booking form and isn't required (the server reads session_time then).
    // The admin screen that configures the form still sees the saved setting.
    $configuring = is_admin() && ! wp_doing_ajax();
    if (isset($out['event_time']) && ! $configuring && function_exists('wedding_booking_slots_enabled') && wedding_booking_slots_enabled()) {
        $out['event_time']['enabled']  = 0;
        $out['event_time']['required'] = 0;
    }

    return $out;
}

function wedding_booking_sanitize_checkout_mode($mode)
{
    $mode = sanitize_key((string) $mode);
    return in_array($mode, ['direct', 'redirect'], true) ? $mode : 'direct';
}

function wedding_booking_get_checkout_mode()
{
    return wedding_booking_sanitize_checkout_mode(get_option('wedding_booking_checkout_mode', 'direct'));
}

/**
 * Build the wedding_booking_checkout_form_fields option value from a settings-form POST.
 * Expects wedding_booking_cf_enabled[key], wedding_booking_cf_required[key], wedding_booking_cf_label[key].
 */
function wedding_booking_sanitize_checkout_field_config($post)
{
    $catalog  = wedding_booking_checkout_field_catalog();
    $enabled  = isset($post['wedding_booking_cf_enabled']) && is_array($post['wedding_booking_cf_enabled']) ? $post['wedding_booking_cf_enabled'] : [];
    $required = isset($post['wedding_booking_cf_required']) && is_array($post['wedding_booking_cf_required']) ? $post['wedding_booking_cf_required'] : [];
    $labels   = isset($post['wedding_booking_cf_label']) && is_array($post['wedding_booking_cf_label']) ? $post['wedding_booking_cf_label'] : [];

    $out = [];
    foreach ($catalog as $key => $def) {
        $locked = ! empty($def['locked']);
        $on     = $locked ? 1 : ((int) ($enabled[$key] ?? 0) === 1 ? 1 : 0);
        $req    = $locked ? 1 : ($on && (int) ($required[$key] ?? 0) === 1 ? 1 : 0);
        $label  = sanitize_text_field(wp_unslash($labels[$key] ?? ''));
        if ($label === '') {
            $label = $def['label'];
        }
        $out[$key] = ['enabled' => $on, 'required' => $req, 'label' => $label];
    }

    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Custom checkout fields — admin-created fields (add/remove)
   stored in the wedding_booking_checkout_custom_fields option as
   [key => ['label','type','required']].
───────────────────────────────────────────────────────────── */
function wedding_booking_custom_checkout_field_types()
{
    return [
        'text'     => __('Text', 'wedding-booking'),
        'textarea' => __('Textarea', 'wedding-booking'),
        'number'   => __('Number', 'wedding-booking'),
        'email'    => __('Email', 'wedding-booking'),
        'tel'      => __('Phone', 'wedding-booking'),
        'date'     => __('Date', 'wedding-booking'),
        'time'     => __('Time', 'wedding-booking'),
    ];
}

function wedding_booking_get_custom_checkout_fields()
{
    $saved = get_option('wedding_booking_checkout_custom_fields', []);
    if (! is_array($saved)) {
        return [];
    }

    $types = wedding_booking_custom_checkout_field_types();
    $out   = [];
    foreach ($saved as $key => $row) {
        if (! is_array($row)) {
            continue;
        }
        $key   = sanitize_key($key);
        $label = isset($row['label']) ? (string) $row['label'] : '';
        if ($key === '' || $label === '') {
            continue;
        }
        $out[$key] = [
            'label'    => $label,
            'type'     => isset($row['type'], $types[$row['type']]) ? $row['type'] : 'text',
            'required' => ! empty($row['required']) ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * Build the wedding_booking_checkout_custom_fields option value from a settings POST.
 * Rows arrive as wedding_booking_ccf_label[key], wedding_booking_ccf_type[key], wedding_booking_ccf_required[key];
 * rows removed in the UI are simply absent, so they get dropped here.
 */
function wedding_booking_sanitize_custom_checkout_fields($post)
{
    $labels   = isset($post['wedding_booking_ccf_label']) && is_array($post['wedding_booking_ccf_label']) ? $post['wedding_booking_ccf_label'] : [];
    $types_in = isset($post['wedding_booking_ccf_type']) && is_array($post['wedding_booking_ccf_type']) ? $post['wedding_booking_ccf_type'] : [];
    $reqs     = isset($post['wedding_booking_ccf_required']) && is_array($post['wedding_booking_ccf_required']) ? $post['wedding_booking_ccf_required'] : [];
    $types    = wedding_booking_custom_checkout_field_types();

    $out = [];
    foreach ($labels as $orig_key => $label) {
        $label = sanitize_text_field(wp_unslash($label));
        if ($label === '') {
            continue;
        }

        $key = sanitize_key($orig_key);
        if ($key === '' || strpos($key, 'new_') === 0) {
            // Newly added row: derive a stable key from the label.
            $key = sanitize_key(str_replace('-', '_', sanitize_title($label)));
            if ($key === '') {
                $key = 'field';
            }
        }
        $base = $key;
        $i    = 2;
        while (isset($out[$key])) {
            $key = $base . '_' . $i++;
        }

        $type = isset($types_in[$orig_key], $types[$types_in[$orig_key]]) ? $types_in[$orig_key] : 'text';
        $out[$key] = [
            'label'    => $label,
            'type'     => $type,
            'required' => (int) ($reqs[$orig_key] ?? 0) === 1 ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * Sanitize the details[] array posted by the booking form,
 * keyed and typed according to the field catalog.
 */
function wedding_booking_sanitize_checkout_details($raw)
{
    if (! is_array($raw)) {
        return [];
    }

    $catalog = wedding_booking_checkout_field_catalog();
    $fields  = wedding_booking_get_checkout_form_fields();
    $out     = [];

    foreach ($fields as $key => $f) {
        if (empty($f['enabled'])) {
            continue;
        }
        $value = isset($raw[$key]) ? wp_unslash($raw[$key]) : '';
        switch ($catalog[$key]['type']) {
            case 'email':
                $value = sanitize_email($value);
                break;
            case 'textarea':
                $value = sanitize_textarea_field($value);
                break;
            case 'number':
                $value = ($value === '' ? '' : (string) absint($value));
                break;
            default:
                // Text fields, including the room number ("B12", "Villa 3").
                $value = sanitize_text_field($value);
        }
        $out[$key] = $value;
    }

    // Admin-created custom fields travel namespaced as cf_{key}.
    foreach (wedding_booking_get_custom_checkout_fields() as $key => $f) {
        $pkey  = 'cf_' . $key;
        $value = isset($raw[$pkey]) ? wp_unslash($raw[$pkey]) : '';
        switch ($f['type']) {
            case 'email':
                $value = sanitize_email($value);
                break;
            case 'textarea':
                $value = sanitize_textarea_field($value);
                break;
            default:
                $value = sanitize_text_field($value);
        }
        $out[$pkey] = $value;
    }

    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Theme colors — admin-selected brand colors for the booking
   form, injected as CSS-variable overrides on .wedding-booking-wrap.
───────────────────────────────────────────────────────────── */
function wedding_booking_default_theme_colors()
{
    return ['primary' => '#b8956a', 'accent' => '#3d6b78'];
}

function wedding_booking_get_theme_colors()
{
    $defaults = wedding_booking_default_theme_colors();
    $primary  = sanitize_hex_color(get_option('wedding_booking_theme_primary', $defaults['primary']));
    $accent   = sanitize_hex_color(get_option('wedding_booking_theme_accent', $defaults['accent']));
    return [
        'primary' => $primary ?: $defaults['primary'],
        'accent'  => $accent ?: $defaults['accent'],
    ];
}

/**
 * Mix a hex color toward another (e.g. white to lighten, black to darken).
 * $ratio is the weight of $mix_hex (0..1).
 */
function wedding_booking_hex_mix($hex, $mix_hex, $ratio)
{
    $h = ltrim($hex, '#');
    $m = ltrim($mix_hex, '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (strlen($m) === 3) {
        $m = $m[0] . $m[0] . $m[1] . $m[1] . $m[2] . $m[2];
    }
    if (strlen($h) !== 6 || strlen($m) !== 6) {
        return $hex;
    }
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $c1   = hexdec(substr($h, $i * 2, 2));
        $c2   = hexdec(substr($m, $i * 2, 2));
        $out .= str_pad(dechex((int) round($c1 * (1 - $ratio) + $c2 * $ratio)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Build a CSS-variable override block for a given primary/accent pair,
 * scoped to $selector. The derived shades match wedding_booking_theme_css() exactly,
 * so per-instance shortcode overrides and the global Appearance setting
 * stay visually consistent. Returns '' when the colors are empty or invalid.
 */
function wedding_booking_theme_vars_css($primary, $accent, $selector = '.wedding-booking-wrap')
{
    $primary = trim((string) $primary);
    $accent  = trim((string) $accent);
    $valid   = static function ($hex) {
        return (bool) preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $hex);
    };
    if (! $valid($primary) && ! $valid($accent)) {
        return '';
    }

    $vars = '';
    if ($valid($primary)) {
        $vars .= sprintf(
            '--wedding-booking-gold:%s;--wedding-booking-gold-dk:%s;--wedding-booking-gold-lt:%s;',
            $primary,
            wedding_booking_hex_mix($primary, '#000000', 0.18),
            wedding_booking_hex_mix($primary, '#ffffff', 0.72)
        );
    }
    if ($valid($accent)) {
        $vars .= sprintf(
            '--wedding-booking-teal:%s;--wedding-booking-teal-lt:%s;',
            $accent,
            wedding_booking_hex_mix($accent, '#ffffff', 0.86)
        );
    }

    return sprintf('%s{%s}', $selector, $vars);
}

/**
 * Build the global CSS-variable override block. Returns '' when the admin
 * hasn't customized anything, so the stylesheet's hand-tuned palette
 * stays byte-identical by default.
 */
function wedding_booking_theme_css()
{
    $defaults = wedding_booking_default_theme_colors();
    $colors   = wedding_booking_get_theme_colors();
    if (strtolower($colors['primary']) === $defaults['primary'] && strtolower($colors['accent']) === $defaults['accent']) {
        return '';
    }

    return wedding_booking_theme_vars_css($colors['primary'], $colors['accent'], '.wedding-booking-wrap');
}

/* ─────────────────────────────────────────────────────────────
   Booking catalog
───────────────────────────────────────────────────────────── */
/**
 * Session types, packages and add-ons offered on the booking form.
 *
 * Shared by the shortcode (which inlines this into the page so the form
 * paints without waiting on admin-ajax) and by wedding_booking_ajax_get_data()
 * (the fallback for renders that never got the inline copy). Availability
 * dates are deliberately NOT here: they must stay a live request, or a
 * cached page would offer dates that have since been booked.
 */
function wedding_booking_get_catalog_data()
{
    global $wpdb;
    $pfx = $wpdb->prefix . 'wedding_booking_';

    return [
        'sessions' => $wpdb->get_results("SELECT id, name, emoji, slug FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
        'packages' => $wpdb->get_results("SELECT id, session_id, name, slug, price, duration, description, featured, deposit_pct FROM {$pfx}packages WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
        'addons'   => $wpdb->get_results("SELECT id, name, price, emoji, description, package_id, package_ids FROM {$pfx}addons WHERE active=1 ORDER BY sort_order, id"), // phpcs:ignore
    ];
}

/* ─────────────────────────────────────────────────────────────
   Register assets & shortcode
───────────────────────────────────────────────────────────── */
add_action('init', 'wedding_booking_register_assets');
function wedding_booking_register_assets()
{
    wp_register_style(
        'wedding-booking-booking',
        WEDDING_BOOKING_URL . 'assets/css/booking.css',
        [],
        WEDDING_BOOKING_VER
    );
    $icon_lib = wedding_booking_icon_library_url();
    if ($icon_lib !== '') {
        wp_register_style('wedding-booking-icons', $icon_lib, [], WEDDING_BOOKING_VER);
    }
    // Deferred so the script never blocks first paint. On WP < 6.3 the
    // array argument gracefully degrades to a plain footer script.
    wp_register_script(
        'wedding-booking-booking',
        WEDDING_BOOKING_URL . 'assets/js/booking.js',
        [],
        WEDDING_BOOKING_VER,
        ['in_footer' => true, 'strategy' => 'defer']
    );
}

function wedding_booking_enqueue_booking_assets()
{
    wp_enqueue_style('wedding-booking-booking');
    // Dashicons render the optional "dashicons dashicons-*" icon values.
    wp_enqueue_style('dashicons');
    if (wp_style_is('wedding-booking-icons', 'registered')) {
        wp_enqueue_style('wedding-booking-icons');
    }
    wp_enqueue_script('wedding-booking-booking');

    // Attach the admin's Appearance color override here, as the stylesheet is
    // enqueued, so it prints together with the stylesheet in <head>. Adding it
    // later (during the shortcode render, in the page body) is too late — by
    // then wedding_booking_maybe_enqueue_assets() has already printed the stylesheet
    // in <head>, so the inline override would be silently dropped. The static
    // guard keeps a second enqueue from stacking a duplicate block.
    static $theme_added = false;
    if (! $theme_added) {
        $theme_css = wedding_booking_theme_css();
        if ($theme_css !== '') {
            wp_add_inline_style('wedding-booking-booking', $theme_css);
        }
        $theme_added = true;
    }
}

/**
 * Lazy, conditional loading: assets are enqueued only on pages that
 * actually contain the booking form. Enqueueing here (instead of only
 * inside the shortcode callback) lets the stylesheet print in <head>
 * on the first page load, avoiding a flash of unstyled form. Pages that
 * render the shortcode another way (e.g. a page-builder widget) are
 * still covered by the enqueue inside the shortcode callback itself.
 */
add_action('wp_enqueue_scripts', 'wedding_booking_maybe_enqueue_assets');
function wedding_booking_maybe_enqueue_assets()
{
    if (! is_singular()) {
        return;
    }
    $post = get_post();
    if (! $post) {
        return;
    }
    $content = (string) $post->post_content;
    if (has_shortcode($content, 'wedding_booking') || has_shortcode($content, 'wedding_booking_form')) {
        wedding_booking_enqueue_booking_assets();
    }
}

add_shortcode('wedding_booking', 'wedding_booking_shortcode');
add_shortcode('wedding_booking_form', 'wedding_booking_shortcode');
function wedding_booking_shortcode($atts)
{
    $atts = shortcode_atts([
        'package' => '',
        'primary' => '',
        'accent'  => '',
    ], $atts, 'wedding-booking');

    wedding_booking_enqueue_booking_assets();

    // Per-instance color overrides from the shortcode's primary/accent
    // attributes. Emitted inline in the output below (not via
    // wp_add_inline_style) because the stylesheet is usually already printed
    // in <head> by the time the shortcode runs, so an enqueued inline style
    // would be dropped. Shortcodes run after wpautop, so the <style> tag is
    // left untouched.
    $instance_css = wedding_booking_theme_vars_css($atts['primary'], $atts['accent'], '.wedding-booking-wrap');

    wp_localize_script('wedding-booking-booking', 'weddingBookingData', wedding_booking_booking_script_data());

    ob_start();
    if ($instance_css !== '') {
        // CSS built from validated hex colors in wedding_booking_theme_vars_css().
        echo '<style id="wedding-booking-instance-theme">' . $instance_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    wedding_booking_render_shortcode(['package' => $atts['package']]);
    return ob_get_clean();
}

/**
 * Everything booking.js needs, localized as weddingBookingData.
 *
 * Note wp_localize_script() only entity-decodes top-level strings, so nested
 * values (money symbol, i18n) are decoded/plain here.
 */
function wedding_booking_booking_script_data()
{
    $has_wc          = class_exists('WooCommerce');
    $deposit_enabled = function_exists('wedding_booking_deposit_enabled') ? wedding_booking_deposit_enabled() : ((int) get_option('wedding_booking_enable_partial_payment', 1) === 1);
    $deposit_pct     = function_exists('wedding_booking_get_deposit_pct') ? wedding_booking_get_deposit_pct() : 50;
    $fee_label       = function_exists('wedding_booking_payment_fee_label') ? wedding_booking_payment_fee_label() : __('Payment fee', 'wedding-booking');
    $exempt_methods  = wedding_booking_fee_exempt_method_titles();
    $login           = wedding_booking_booking_login_urls();

    return [
        'ajaxUrl'               => admin_url('admin-ajax.php'),
        'nonce'                 => wp_create_nonce('wedding_booking_nonce'),
        'hasWC'                 => $has_wc,
        'checkoutMode'          => wedding_booking_get_checkout_mode(),
        'currency'              => wedding_booking_money_format()['symbol'],
        'money'                 => wedding_booking_money_format(),
        // Global deposit % (1–99); a package's own deposit_pct (catalog) wins.
        'depositPct'            => $deposit_pct,
        'partialPaymentEnabled' => $deposit_enabled,
        'partialBlockDays'      => max(0, (int) get_option('wedding_booking_partial_block_days', 0)),
        // May hold a {deposit_pct} placeholder, filled per package in the browser.
        'partialOptionLabel'    => wedding_booking_partial_option_label(),
        'paymentFeePct'         => wedding_booking_get_payment_fee_pct(),
        'paymentFeeLabel'       => $fee_label,
        // Enabled gateways that carry no payment fee, as a readable list ('' = none).
        'feeExemptMethods'      => $exempt_methods ? wp_sprintf('%l', $exempt_methods) : '',
        'subtotalLabel'         => __('Subtotal', 'wedding-booking'),
        'couponsEnabled'        => $has_wc && function_exists('wedding_booking_coupons_enabled') && wedding_booking_coupons_enabled(),
        'slotsEnabled'          => function_exists('wedding_booking_slots_enabled') && wedding_booking_slots_enabled(),
        'whatsapp'              => get_option('wedding_booking_whatsapp', ''),
        'confirmTitle'          => get_option('wedding_booking_confirm_title', __('Booking Confirmed!', 'wedding-booking')),
        'confirmMsg'            => get_option('wedding_booking_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'wedding-booking')),
        'confirmPendingTitle'   => get_option('wedding_booking_confirm_pending_title', __('Booking Received!', 'wedding-booking')),
        'confirmPendingMsg'     => get_option('wedding_booking_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'wedding-booking')),
        // Packages/sessions/add-ons travel with the page, so the form renders
        // on first paint instead of after an admin-ajax round trip. Dates are
        // still fetched live (wedding_booking_get_availability).
        'catalog'               => wedding_booking_get_catalog_data(),
        'showLoader'            => ((int) get_option('wedding_booking_fe_loader_enable', 1) === 1),
        // Optional Contract step between Details and Payment — shifts the
        // payment step from 3 to 4 when on (see bkGo/PAY_STEP in booking.js).
        'contractEnabled'       => wedding_booking_contract_step_enabled(),
        'contractRequiredMsg'   => __('Please accept the Terms & Conditions to continue.', 'wedding-booking'),
        'contract'              => [
            'version'   => function_exists('wedding_booking_contract_version') ? wedding_booking_contract_version() : '',
            'signature' => wedding_booking_contract_signature_required(),
        ],
        // Accounts: the form can be browsed logged out, but continuing past
        // the Package step needs an account when the studio requires one.
        'loginRequired'         => $login['required'] && ! is_user_logged_in(),
        'login'                 => [
            'url'         => $login['base'],
            'registerUrl' => $login['register_base'],
            'param'       => $login['param'],
        ],
        'locale'                => wedding_booking_booking_locale_data(),
        'i18n'                  => wedding_booking_booking_i18n(),
    ];
}

/**
 * WooCommerce's price format (decimals, separators, symbol position), so the
 * booking form shows amounts exactly like the shop. Sensible defaults without
 * WooCommerce.
 */
function wedding_booking_money_format()
{
    static $format = null;
    if (null !== $format) {
        return $format;
    }

    $trim = static function ($symbol) {
        // The decoded symbol may carry a (non-breaking) space, e.g. BDT's
        // "&#2547;&nbsp;" — the position setting adds the spacing instead.
        return (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8'));
    };

    if (function_exists('get_woocommerce_currency_symbol') && function_exists('wc_get_price_decimals')) {
        $position = (string) get_option('woocommerce_currency_pos', 'left');
        $format   = [
            'symbol'      => $trim(get_woocommerce_currency_symbol()),
            'decimals'    => (int) wc_get_price_decimals(),
            'decimalSep'  => (string) wc_get_price_decimal_separator(),
            'thousandSep' => (string) wc_get_price_thousand_separator(),
            'position'    => in_array($position, ['left', 'right', 'left_space', 'right_space'], true) ? $position : 'left',
        ];
    } else {
        $format = [
            'symbol'      => $trim(wedding_booking_get_currency_symbol()),
            'decimals'    => 2,
            'decimalSep'  => '.',
            'thousandSep' => ',',
            'position'    => 'left',
        ];
    }

    return $format;
}

/**
 * Titles of the enabled payment methods the payment fee doesn't apply to
 * (Wedding Booking → Settings → fee-free gateways), for the "no fee for …" hint.
 */
function wedding_booking_fee_exempt_method_titles()
{
    if (wedding_booking_get_payment_fee_pct() <= 0 || ! function_exists('WC') || ! function_exists('wedding_booking_opt')) {
        return [];
    }
    $exempt = (array) wedding_booking_opt('wedding_booking_payment_fee_exempt_gateways');
    if (! $exempt || ! WC()->payment_gateways()) {
        return [];
    }

    $titles = [];
    foreach ((array) WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
        if (in_array((string) $id, $exempt, true) && isset($gateway->enabled) && 'yes' === $gateway->enabled) {
            $title = trim(wp_strip_all_tags((string) $gateway->get_title()));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
    }

    return $titles;
}

/**
 * Whether the Contract step asks for a typed full-name signature.
 */
function wedding_booking_contract_signature_required()
{
    return wedding_booking_contract_step_enabled() && function_exists('wedding_booking_opt') && (int) wedding_booking_opt('wedding_booking_fe_contract_signature') === 1;
}

/**
 * The page to come back to after logging in: this booking page, keeping a
 * ?wedding_booking_package= share link. (booking.js refreshes the links with the live URL
 * and the package the customer picked.)
 */
function wedding_booking_booking_return_url()
{
    $url = is_singular() ? (string) get_permalink() : home_url('/');
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: keeps a ?wedding_booking_package= share link in the return URL.
    $package = isset($_GET['wedding_booking_package']) ? sanitize_title(wp_unslash($_GET['wedding_booking_package'])) : '';

    return $package !== '' ? add_query_arg('wedding_booking_package', rawurlencode($package), $url) : $url;
}

/**
 * Log-in / create-account links for bookings that need an account: the
 * WooCommerce My Account page with ?redirect= back to the booking form, or
 * wp-login.php without WooCommerce.
 *
 * @return array required, base, register_base, param, login, register.
 */
function wedding_booking_booking_login_urls()
{
    $required = (int) get_option('wedding_booking_require_account_booking', 0) === 1;
    $current  = wedding_booking_booking_return_url();

    $account = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
    if ($account !== '') {
        $register = get_option('woocommerce_enable_myaccount_registration') === 'yes' ? $account : '';
        return [
            'required'      => $required,
            'base'          => $account,
            'register_base' => $register,
            'param'         => 'redirect',
            'login'         => add_query_arg('redirect', rawurlencode($current), $account),
            'register'      => $register !== '' ? add_query_arg('redirect', rawurlencode($current), $register) : '',
        ];
    }

    $register = get_option('users_can_register') ? wp_registration_url() : '';
    return [
        'required'      => $required,
        'base'          => wp_login_url(),
        'register_base' => $register,
        'param'         => 'redirect_to',
        'login'         => wp_login_url($current),
        'register'      => $register,
    ];
}

/*
 * WooCommerce's My Account login/registration forms ignore a ?redirect= in
 * the page URL (they only read a posted "redirect" field). Carry it into the
 * forms so the customer lands back on the booking form after logging in.
 * Same-site URLs only (wp_validate_redirect).
 */
add_action('woocommerce_login_form', 'wedding_booking_account_redirect_field');
add_action('woocommerce_register_form', 'wedding_booking_account_redirect_field');
function wedding_booking_account_redirect_field()
{
    // SnapBook prints the same field when both plugins share a site; one is enough.
    if (has_action(current_action(), 'snapbook_account_redirect_field')) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: echoes a validated same-site return URL.
    if (empty($_GET['redirect']) || ! function_exists('is_account_page') || ! is_account_page()) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
    $url = wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect'])), '');
    if ($url === '') {
        return;
    }
    echo '<input type="hidden" name="redirect" value="' . esc_url($url) . '">';
}

/**
 * Month/weekday names and date/time formats from the site's locale, so the
 * calendar and every date in the form match WordPress.
 */
function wedding_booking_booking_locale_data()
{
    global $wp_locale;

    $months = $months_short = $weekdays = $weekdays_short = [];
    if ($wp_locale instanceof WP_Locale) {
        for ($m = 1; $m <= 12; $m++) {
            $name           = $wp_locale->get_month($m);
            $months[]       = $name;
            $months_short[] = $wp_locale->get_month_abbrev($name);
        }
        for ($d = 0; $d <= 6; $d++) {
            $name             = $wp_locale->get_weekday($d);
            $weekdays[]       = $name;
            $weekdays_short[] = $wp_locale->get_weekday_abbrev($name);
        }
    }

    return [
        'months'        => $months,
        'monthsShort'   => $months_short,
        'weekdays'      => $weekdays,
        'weekdaysShort' => $weekdays_short,
        'dateFormat'    => (string) get_option('date_format', 'F j, Y'),
        'timeFormat'    => (string) get_option('time_format', 'g:i a'),
        'am'            => $wp_locale instanceof WP_Locale ? $wp_locale->get_meridiem('am') : 'am',
        'pm'            => $wp_locale instanceof WP_Locale ? $wp_locale->get_meridiem('pm') : 'pm',
        'AM'            => $wp_locale instanceof WP_Locale ? $wp_locale->get_meridiem('AM') : 'AM',
        'PM'            => $wp_locale instanceof WP_Locale ? $wp_locale->get_meridiem('PM') : 'PM',
        'weekStart'     => (int) get_option('start_of_week', 0),
    ];
}

/**
 * Every customer-facing string booking.js shows. {placeholders} are filled
 * in the browser and must be kept by translators.
 */
function wedding_booking_booking_i18n()
{
    return [
        'expired'            => __('This page has expired. Please refresh the page and try again.', 'wedding-booking'),
        'noSessions'         => __('No session types configured.', 'wedding-booking'),
        'noPackages'         => __('No packages for this session type yet.', 'wedding-booking'),
        'loadError'          => __('Could not load booking data. Please refresh.', 'wedding-booking'),
        'availabilityError'  => __("Availability couldn't be loaded.", 'wedding-booking'),
        'tryAgain'           => __('Try again', 'wedding-booking'),
        'popular'            => __('Popular', 'wedding-booking'),
        'packageOnly'        => __('This package only', 'wedding-booking'),
        /* translators: {date}: a calendar date */
        'dateUnavailable'    => __('{date} (unavailable)', 'wedding-booking'),
        /* translators: {time}: a start time */
        'timeUnavailable'    => __('{time} (unavailable)', 'wedding-booking'),
        'chooseTime'         => __('Choose a start time', 'wedding-booking'),
        'pickDateForTimes'   => __('Pick a date to see the available start times.', 'wedding-booking'),
        /* translators: {date}: the chosen session date */
        'selectedDate'       => __('Selected: {date}', 'wedding-booking'),
        /* translators: {date}: the chosen session date, {time}: its start time */
        'selectedDateTime'   => __('Selected: {date} at {time}', 'wedding-booking'),
        'selectPackageTitle' => __('Select a package to continue', 'wedding-booking'),
        'errSelectPackage'   => __('Please select a package.', 'wedding-booking'),
        'errPickDate'        => __('Please pick your session date from the calendar.', 'wedding-booking'),
        'errPickTime'        => __('Please choose a start time under the calendar.', 'wedding-booking'),
        /* translators: {label}: a form field label */
        'errRequired'        => __('{label} is required.', 'wedding-booking'),
        'errEmail'           => __('Please enter a valid email.', 'wedding-booking'),
        'errPhone'           => __('Please enter a valid phone number (digits, +, spaces, dashes only).', 'wedding-booking'),
        /* translators: {label}: a form field label */
        'errMin1'            => __('{label} must be at least 1.', 'wedding-booking'),
        'signatureRequired'  => __('Please type your full name to sign.', 'wedding-booking'),
        'acceptTerms'        => __('Accept the terms to continue', 'wedding-booking'),
        'acceptAndSign'      => __('Accept the terms and type your full name to continue', 'wedding-booking'),
        'loginRequired'      => __('Please log in or create an account to continue with your booking.', 'wedding-booking'),
        /* translators: {n}: step number, {label}: step name */
        'stepGoBack'         => __('Go back to step {n}: {label}', 'wedding-booking'),
        'payNow'             => __('Pay now', 'wedding-booking'),
        /* translators: {pct}: percentage charged now */
        'payNowPct'          => __('Pay now ({pct}%)', 'wedding-booking'),
        'paymentFee'         => __('Payment fee', 'wedding-booking'),
        /* translators: {label}: payment fee name, {pct}: fee percentage */
        'feeIncluded'        => __('{label} of {pct}% included', 'wedding-booking'),
        /* translators: {methods}: payment method names */
        'feeExempt'          => __('(no fee for {methods})', 'wedding-booking'),
        /* translators: {label}: payment fee name, {methods}: payment method names */
        'feeExemptPay'       => __('{label}: not charged when you pay by {methods}.', 'wedding-booking'),
        /* translators: {code}: promo code, {amount}: discount */
        'promoIncluded'      => __('Promo code {code} applied: {amount}', 'wedding-booking'),
        /* translators: {pct}: deposit percentage */
        'depositLabel'       => __('{pct}% booking deposit', 'wedding-booking'),
        'fullPayment'        => __('Full payment', 'wedding-booking'),
        /* translators: {amount}: remaining balance */
        'balanceNote'        => __('Remaining balance {amount} is due later — we will send you a payment link.', 'wedding-booking'),
        /* translators: {amount}: remaining balance, {date}: due date */
        'balanceNoteDate'    => __('Remaining balance {amount} is due by {date} — we will send you a payment link.', 'wedding-booking'),
        /* translators: {pct}: deposit percentage */
        'partialOn'          => __('Pay {pct}% now and settle the rest later.', 'wedding-booking'),
        /* translators: {pct}: deposit percentage */
        'partialOff'         => __("You'll pay the full amount now. Switch on to pay a {pct}% deposit instead.", 'wedding-booking'),
        'none'               => __('None', 'wedding-booking'),
        'promoEmpty'         => __('Please enter a promo code.', 'wedding-booking'),
        'promoChecking'      => __('Checking…', 'wedding-booking'),
        'promoApplied'       => __('Promo code applied.', 'wedding-booking'),
        'promoRemoved'       => __('Promo code removed.', 'wedding-booking'),
        'loadingPayment'     => __('Loading payment options…', 'wedding-booking'),
        'paymentLoadFailed'  => __('Could not load the payment options. Please try again.', 'wedding-booking'),
        'networkError'       => __('Network error. Check your connection and try again.', 'wedding-booking'),
        'noPackageSelected'  => __('No package selected. Please go back and choose a package.', 'wedding-booking'),
        'pleaseWait'         => __('Please wait…', 'wedding-booking'),
        'preparing'          => __('Preparing your booking…', 'wedding-booking'),
        'somethingWrong'     => __('Something went wrong. Please try again.', 'wedding-booking'),
        'genericError'       => __('Error. Please try again.', 'wedding-booking'),
        'refreshPage'        => __('Refresh page', 'wedding-booking'),
        'gatewaysLoadFailed' => __('Could not load payment methods. You can still continue — payment options will be shown on the payment page.', 'wedding-booking'),
        'gatewaysLoading'    => __('Loading payment methods…', 'wedding-booking'),
        'noGateways'         => __('No payment methods are enabled in WooCommerce yet. Enable one under WooCommerce → Settings → Payments.', 'wedding-booking'),
        'payNextNote'        => __('The secure payment form will open below once you place the booking.', 'wedding-booking'),
        'securePayment'      => __('Secure Payment', 'wedding-booking'),
        'securePaymentFrame' => __('Secure payment', 'wedding-booking'),
        'loadingSecurePay'   => __('Loading secure payment…', 'wedding-booking'),
        'processingPayment'  => __('Processing your payment…', 'wedding-booking'),
        'havingTrouble'      => __('Having trouble paying?', 'wedding-booking'),
        'openPayPage'        => __('Open the secure payment page', 'wedding-booking'),
        'yourEmail'          => __('your email address', 'wedding-booking'),
    ];
}

/* ─────────────────────────────────────────────────────────────
   Deposit % in admin-editable texts
───────────────────────────────────────────────────────────── */

/**
 * Replace the {deposit_pct} placeholder (texts on the Booking Form screen and the
 * partial-payment option label) with the deposit percentage.
 */
function wedding_booking_fill_deposit_pct($text, $pct = null)
{
    if (null === $pct) {
        $pct = function_exists('wedding_booking_get_deposit_pct') ? wedding_booking_get_deposit_pct() : 50;
    }

    return str_replace('{deposit_pct}', (string) (int) $pct, (string) $text);
}

/**
 * Built-in texts that shipped before 1.5.0 with a hard-coded "50%" or
 * "digitally sign". A saved value that still equals one of these was never
 * customised (the settings screens save every field), so it is upgraded to
 * the current default.
 */
function wedding_booking_legacy_default_texts()
{
    return [
        'wedding_booking_partial_option_label' => 'Book a slot to 50% Pay',
        'wedding_booking_fe_hiw_steps'         => "Choose your package & session type\nFill in your details\nReserve & digitally sign the contract\nPay 50% deposit securely online\nWe confirm within 24 hours",
        'wedding_booking_fe_deposit_title'     => '50% deposit to confirm',
        'wedding_booking_fe_contract_sub'      => 'Please read and digitally sign our Terms & Conditions to proceed.',
    ];
}

function wedding_booking_is_legacy_default_text($option, $value)
{
    $legacy = wedding_booking_legacy_default_texts();
    if (! isset($legacy[$option])) {
        return false;
    }
    $norm = static function ($s) {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
    };

    return $norm($value) === $norm($legacy[$option]);
}

/**
 * Label of the "pay a deposit" option on the Package step. May contain
 * {deposit_pct}, which the booking form fills with the package's deposit %.
 */
function wedding_booking_partial_option_label()
{
    /* translators: {deposit_pct} is replaced with the deposit percentage — keep it. */
    $default = __('Book your slot with a {deposit_pct}% deposit', 'wedding-booking');
    $label   = (string) get_option('wedding_booking_partial_option_label', $default);
    if (trim($label) === '' || wedding_booking_is_legacy_default_text('wedding_booking_partial_option_label', $label)) {
        $label = $default;
    }

    return $label;
}

/**
 * Default "How it works" steps, built from what the form actually does: the
 * contract line only with the Contract step on, the deposit line only when
 * deposits are offered.
 */
function wedding_booking_default_hiw_steps()
{
    $steps = [
        __('Choose your package & session type', 'wedding-booking'),
        __('Fill in your details', 'wedding-booking'),
    ];
    if (wedding_booking_contract_step_enabled()) {
        $steps[] = wedding_booking_contract_signature_required()
            ? __('Review & sign the contract', 'wedding-booking')
            : __('Review & accept the contract', 'wedding-booking');
    }
    $steps[] = (function_exists('wedding_booking_deposit_enabled') ? wedding_booking_deposit_enabled() : true)
        /* translators: {deposit_pct} is replaced with the deposit percentage — keep it. */
        ? __('Pay the {deposit_pct}% deposit securely online', 'wedding-booking')
        : __('Pay securely online', 'wedding-booking');
    $steps[] = __('We confirm within 24 hours', 'wedding-booking');

    return implode("\n", $steps);
}

/**
 * Step 3 field groups — the Details fields are reordered into labelled
 * sections (Contact / Event / Address / Additional). Each entry lists the
 * catalog keys that belong to it; the last group also carries the admin
 * custom fields. Filterable so integrations can re-map the grouping.
 */
function wedding_booking_checkout_field_groups()
{
    return apply_filters('wedding_booking_checkout_field_groups', [
        [
            'title' => __('Contact', 'wedding-booking'),
            'keys'  => ['first_name', 'last_name', 'email', 'phone'],
        ],
        [
            'title' => __('Event details', 'wedding-booking'),
            'keys'  => ['event_time', 'participants', 'hotel_place', 'room_number', 'stay_period'],
        ],
        [
            'title' => __('Address', 'wedding-booking'),
            'keys'  => ['country', 'address_1', 'city', 'postcode'],
        ],
        [
            'title'  => __('Additional details', 'wedding-booking'),
            'keys'   => ['notes'],
            'custom' => true, // admin-created custom fields append here
        ],
    ]);
}

function wedding_booking_render_checkout_form_fields()
{
    $catalog = wedding_booking_checkout_field_catalog();
    $fields  = wedding_booking_get_checkout_form_fields();
    $custom  = function_exists('wedding_booking_get_custom_checkout_fields') ? wedding_booking_get_custom_checkout_fields() : [];
    $groups  = wedding_booking_checkout_field_groups();

    // Safety net: any catalog field not named in a group still renders,
    // appended to the last group, so a future field is never dropped.
    $placed = [];
    foreach ($groups as $group) {
        $placed = array_merge($placed, $group['keys']);
    }
    $leftover = array_diff(array_keys($catalog), $placed);
    if (! empty($leftover)) {
        $last = count($groups) - 1;
        $groups[$last]['keys'] = array_merge($groups[$last]['keys'], array_values($leftover));
    }

    foreach ($groups as $group) {
        // Collect the visible fields for this section before printing anything,
        // so an all-disabled section renders no empty heading. Each item also
        // records whether it is a "wide" field that spans both columns.
        $items = [];
        foreach ($group['keys'] as $key) {
            if (isset($catalog[$key], $fields[$key]) && ! empty($fields[$key]['enabled'])) {
                $def  = $catalog[$key];
                $wide = ! empty($def['wide']) || $def['type'] === 'textarea';
                $items[] = ['builtin', $key, $wide];
            }
        }
        if (! empty($group['custom'])) {
            foreach ($custom as $ckey => $cf) {
                $items[] = ['custom', $ckey, $cf['type'] === 'textarea'];
            }
        }
        if (empty($items)) {
            continue;
        }

        // Smart mix (1 & 2 column): narrow fields pair up two-per-row, while a
        // narrow field left without a partner — interrupted by a wide field or
        // sitting at the section's end — is promoted to full-width so the grid
        // never renders an empty half-cell next to a full-width field.
        $span    = array_fill(0, count($items), false); // true = force full-width
        $pending = null;                                 // index of an unpaired narrow field
        foreach ($items as $i => $item) {
            if ($item[2]) { // wide
                if ($pending !== null) {
                    $span[$pending] = true; // lone narrow field before a wide one
                    $pending = null;
                }
                $span[$i] = true;
            } elseif ($pending === null) {
                $pending = $i; // first of a potential pair
            } else {
                $pending = null; // paired with the previous narrow field
            }
        }
        if ($pending !== null) {
            $span[$pending] = true; // trailing lone narrow field
        }

        echo '<div class="wedding-booking-details-group">';
        echo '<div class="wedding-booking-sec-label">' . esc_html($group['title']) . '</div>';
        echo '<div class="wedding-booking-grid2">';
        foreach ($items as $i => $item) {
            if ($item[0] === 'builtin') {
                wedding_booking_render_builtin_checkout_field($item[1], $catalog[$item[1]], $fields[$item[1]], $span[$i]);
            } else {
                wedding_booking_render_custom_checkout_field($item[1], $custom[$item[1]], $span[$i]);
            }
        }
        echo '</div>';
        echo '</div>';
    }
}

/**
 * Render a single built-in checkout field (catalog-driven).
 */
function wedding_booking_render_builtin_checkout_field($key, $def, $f, $force_span = false)
{
    $type = $def['type'];
    $wide = $force_span || ! empty($def['wide']) || $type === 'textarea';
    $fid  = 'wedding-booking-cf-' . $key;

    // Browser autofill hints for the standard contact/address fields.
    $autocomplete = [
        'first_name' => 'given-name',
        'last_name'  => 'family-name',
        'email'      => 'email',
        'phone'      => 'tel',
        'country'    => 'country',
        'address_1'  => 'address-line1',
        'city'       => 'address-level2',
        'postcode'   => 'postal-code',
    ];

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-wedding-booking-cf="' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ($f['required'] ? ' aria-required="true"' : '')
        . (isset($autocomplete[$key]) ? ' autocomplete="' . esc_attr($autocomplete[$key]) . '"' : '')
        . ' placeholder="' . esc_attr($def['ph'] ?? $f['label']) . '"';

    echo '<div class="wedding-booking-field' . ($wide ? ' wedding-booking-gridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="wedding-booking-req">*</span>' : '') . '</label>';

    if ($type === 'textarea') {
        echo '<textarea' . $common . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } elseif ($type === 'country' && class_exists('WooCommerce') && function_exists('WC') && WC()->countries) {
        $countries = WC()->countries->get_allowed_countries();
        echo '<select' . $common . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<option value="">' . esc_html__('Select a country / region…', 'wedding-booking') . '</option>';
        foreach ($countries as $code => $name) {
            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
        }
        echo '</select>';
    } else {
        $input_type = $type === 'country' ? 'text' : $type;
        $extra = '';
        if ($key === 'participants') {
            $extra = ' min="1" step="1" inputmode="numeric"';
        }
        echo '<input type="' . esc_attr($input_type) . '"' . $common . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    echo '</div>';
}

/**
 * Render a single admin-created custom checkout field (namespaced cf_{key}).
 */
function wedding_booking_render_custom_checkout_field($key, $f, $force_span = false)
{
    $fid  = 'wedding-booking-ccf-' . $key;
    $wide = $force_span || $f['type'] === 'textarea';

    $common = ' id="' . esc_attr($fid) . '"'
        . ' data-wedding-booking-cf="cf_' . esc_attr($key) . '"'
        . ' data-required="' . ($f['required'] ? '1' : '0') . '"'
        . ' data-label="' . esc_attr($f['label']) . '"'
        . ($f['required'] ? ' aria-required="true"' : '')
        . ' placeholder="' . esc_attr($f['label']) . '"';

    echo '<div class="wedding-booking-field' . ($wide ? ' wedding-booking-gridspan' : '') . '">';
    echo '<label for="' . esc_attr($fid) . '">' . esc_html($f['label']) . ($f['required'] ? ' <span class="wedding-booking-req">*</span>' : '') . '</label>';
    if ($f['type'] === 'textarea') {
        echo '<textarea' . $common . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        echo '<input type="' . esc_attr($f['type']) . '"' . $common . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
}

/* ─────────────────────────────────────────────────────────────
   Frontend sidebar — informational cards shown beside the booking
   form. Content is admin-editable in Wedding Booking → Booking Form and stored
   in wedding_booking_fe_* options.
───────────────────────────────────────────────────────────── */
/**
 * Defaults for the order confirmation email extras (Wedding Booking → Settings →
 * Order Email): an editable message block and a file attachment such as a
 * Terms of Service PDF. Lives here because woocommerce.php is only loaded
 * when WooCommerce is active, while the settings screen always needs these.
 * The message ships disabled so updating the plugin never silently changes
 * the email customers receive.
 */
function wedding_booking_order_email_defaults()
{
    return [
        'enable'        => 0,
        'subject'       => __('Your booking is confirmed — {site_name}', 'wedding-booking'),
        'heading'       => __('Your booking', 'wedding-booking'),
        'message'       => __("<p>Hi {first_name},</p>\n<p>Thank you for booking <strong>{package_name}</strong> with us on <strong>{session_date}</strong>.</p>\n<p>Our terms are attached to this email. If you have any questions, just reply and we'll be happy to help.</p>\n<p>{site_name}</p>", 'wedding-booking'),
        'order_table'   => 1,
        'attachment_id' => 0,
    ];
}

function wedding_booking_get_order_email_settings()
{
    $d = wedding_booking_order_email_defaults();
    return [
        'enable'        => (int) get_option('wedding_booking_order_email_enable', $d['enable']),
        'subject'       => (string) get_option('wedding_booking_order_email_subject', $d['subject']),
        'heading'       => (string) get_option('wedding_booking_order_email_heading', $d['heading']),
        'message'       => (string) get_option('wedding_booking_order_email_message', $d['message']),
        'order_table'   => (int) get_option('wedding_booking_order_email_order_table', $d['order_table']),
        'attachment_id' => (int) get_option('wedding_booking_order_email_attachment_id', $d['attachment_id']),
    ];
}

/**
 * Human label for the chosen attachment — the real filename when the file
 * still exists, so a deleted media item is obvious in the settings screen.
 */
function wedding_booking_order_email_attachment_label($attachment_id)
{
    $attachment_id = (int) $attachment_id;
    if ($attachment_id < 1) {
        return __('No file selected.', 'wedding-booking');
    }
    $path = get_attached_file($attachment_id);
    if (! $path || ! file_exists($path)) {
        return __('Selected file is missing — please choose another.', 'wedding-booking');
    }
    return basename($path);
}

/**
 * Defaults for the admin "New booking" notification (Wedding Booking → Settings →
 * Emails → New-booking alert). Like the customer order email, this replaces
 * WooCommerce's plain New Order email with the branded Wedding Booking shell — but
 * with the extra detail an admin needs to action a booking (payment
 * breakdown, customer contact, and a manage-order link). Ships disabled so
 * updating the plugin never changes the email a live studio receives.
 */
function wedding_booking_admin_email_defaults()
{
    return [
        'enable'      => 0,
        'recipient'   => '',
        'subject'     => __('New booking {order_id} — {package_name}', 'wedding-booking'),
        'heading'     => __('New booking received', 'wedding-booking'),
        'intro'       => __('<p>A new booking has just come in. The full details are below.</p>', 'wedding-booking'),
    ];
}

function wedding_booking_get_admin_email_settings()
{
    $d = wedding_booking_admin_email_defaults();
    return [
        'enable'    => (int) get_option('wedding_booking_admin_email_enable', $d['enable']),
        'recipient' => (string) get_option('wedding_booking_admin_email_recipient', $d['recipient']),
        'subject'   => (string) get_option('wedding_booking_admin_email_subject', $d['subject']),
        'heading'   => (string) get_option('wedding_booking_admin_email_heading', $d['heading']),
        'intro'     => (string) get_option('wedding_booking_admin_email_intro', $d['intro']),
    ];
}

/**
 * Sanitize a comma-separated list of recipient emails down to the valid ones,
 * rejoined with ", ". Empty when nothing valid was supplied, so the caller can
 * fall back to WooCommerce's own recipient.
 */
function wedding_booking_sanitize_email_list($raw)
{
    $out = [];
    foreach (explode(',', (string) $raw) as $email) {
        $email = sanitize_email(trim($email));
        if ($email !== '' && ! in_array($email, $out, true)) {
            $out[] = $email;
        }
    }
    return implode(', ', $out);
}

/**
 * Frontend sidebar defaults. Texts may use {deposit_pct}; the How-it-works
 * list is built from the form's actual steps (wedding_booking_default_hiw_steps()).
 */
function wedding_booking_frontend_sidebar_defaults()
{
    return [
        'hiw_enable'     => 1,
        'hiw_title'      => __('How it works', 'wedding-booking'),
        'hiw_steps'      => wedding_booking_default_hiw_steps(),
        'date_title'     => __('Choose your date', 'wedding-booking'),
        'date_sub'       => __('Select your preferred session date to begin.', 'wedding-booking'),
        'deposit_enable' => 1,
        /* translators: {deposit_pct} is replaced with the deposit percentage — keep it. */
        'deposit_title'  => __('{deposit_pct}% deposit to confirm', 'wedding-booking'),
        'deposit_text'   => __('Your date is fully reserved once the deposit is paid. We send a full confirmation with session details and location suggestions. The balance is due before your session.', 'wedding-booking'),
    ];
}

/**
 * Saved sidebar settings (raw — placeholders such as {deposit_pct} are
 * filled when the sidebar renders). Values still equal to a pre-1.5.0
 * built-in text are upgraded to the current default.
 */
function wedding_booking_get_frontend_sidebar()
{
    $d = wedding_booking_frontend_sidebar_defaults();
    $s = [
        'hiw_enable'     => (int) get_option('wedding_booking_fe_hiw_enable', $d['hiw_enable']),
        'hiw_title'      => get_option('wedding_booking_fe_hiw_title', $d['hiw_title']),
        'hiw_steps'      => get_option('wedding_booking_fe_hiw_steps', $d['hiw_steps']),
        'date_title'     => get_option('wedding_booking_fe_date_title', $d['date_title']),
        'date_sub'       => get_option('wedding_booking_fe_date_sub', $d['date_sub']),
        'deposit_enable' => (int) get_option('wedding_booking_fe_deposit_enable', $d['deposit_enable']),
        'deposit_title'  => get_option('wedding_booking_fe_deposit_title', $d['deposit_title']),
        'deposit_text'   => get_option('wedding_booking_fe_deposit_text', $d['deposit_text']),
    ];
    foreach (['hiw_steps' => 'wedding_booking_fe_hiw_steps', 'deposit_title' => 'wedding_booking_fe_deposit_title'] as $key => $option) {
        if (wedding_booking_is_legacy_default_text($option, $s[$key])) {
            $s[$key] = $d[$key];
        }
    }

    return $s;
}

/**
 * Contract step defaults — the optional "Contract" step between Details and
 * Payment, where the customer reads the studio's terms and accepts them.
 * Ships disabled so updating never adds a step to a live booking form.
 */
function wedding_booking_contract_defaults()
{
    // Only speak of signing when a typed signature is actually asked for.
    // (Reads the option directly: wedding_booking_contract_signature_required()
    // depends on these settings.)
    $sign = function_exists('wedding_booking_opt') && (int) wedding_booking_opt('wedding_booking_fe_contract_signature') === 1;

    return [
        'enable'       => 0,
        'step_label'   => __('Contract', 'wedding-booking'),
        'title'        => __('Review & Sign Contract', 'wedding-booking'),
        'sub'          => $sign
            ? __('Please read our Terms & Conditions, then accept and sign them to proceed.', 'wedding-booking')
            : __('Please read and accept our Terms & Conditions to proceed.', 'wedding-booking'),
        'text'         => '<h3>' . __('Service Agreement', 'wedding-booking') . '</h3>'
            . '<p>' . __('By accepting below, you agree to the following terms in full.', 'wedding-booking') . '</p>'
            . '<h4>' . __('1. Booking fee &amp; payments', 'wedding-booking') . '</h4>'
            . '<p>' . __('A non-refundable deposit is required to secure your date. The remaining balance is due before the session takes place.', 'wedding-booking') . '</p>'
            . '<h4>' . __('2. Delivery time', 'wedding-booking') . '</h4>'
            . '<p>' . __('Edited images are delivered within the timeframe stated for your package. Rush delivery may be available on request.', 'wedding-booking') . '</p>'
            . '<h4>' . __('3. Cancellation &amp; rescheduling', 'wedding-booking') . '</h4>'
            . '<p>' . __('Sessions may be rescheduled once, subject to availability. Deposits are not refundable on cancellation.', 'wedding-booking') . '</p>',
        'accept_label' => __('I have read and agree to the full Terms & Conditions.', 'wedding-booking'),
    ];
}

/**
 * Contract step settings, with blank labels falling back to the defaults so
 * the step can never render without a name or an acceptance line.
 */
function wedding_booking_get_contract_settings()
{
    $d = wedding_booking_contract_defaults();
    $s = [
        'enable'       => (int) get_option('wedding_booking_fe_contract_enable', $d['enable']),
        'step_label'   => (string) get_option('wedding_booking_fe_contract_step_label', $d['step_label']),
        'title'        => (string) get_option('wedding_booking_fe_contract_title', $d['title']),
        'sub'          => (string) get_option('wedding_booking_fe_contract_sub', $d['sub']),
        'text'         => (string) get_option('wedding_booking_fe_contract_text', $d['text']),
        'accept_label' => (string) get_option('wedding_booking_fe_contract_accept_label', $d['accept_label']),
    ];
    foreach (['step_label', 'title', 'accept_label'] as $key) {
        if (trim($s[$key]) === '') {
            $s[$key] = $d[$key];
        }
    }
    // The pre-1.5.0 default said "digitally sign" even with no signature.
    if (wedding_booking_is_legacy_default_text('wedding_booking_fe_contract_sub', $s['sub'])) {
        $s['sub'] = $d['sub'];
    }
    return $s;
}

/**
 * Whether the wizard runs Package → Details → Contract → Payment (true) or
 * the stock Package → Details → Payment (false).
 */
function wedding_booking_contract_step_enabled()
{
    $s = wedding_booking_get_contract_settings();
    return ! empty($s['enable']);
}

/**
 * The booking calendar card — the customer picks their session date here
 * (the wizard itself is now Package → Details → Payment). Rendered by both
 * the sidebar and the standalone fallback, so its markup lives in one place.
 * The element IDs match what booking.js binds (wedding-booking-calGrid etc.).
 */
function wedding_booking_render_calendar_card($s)
{
    global $wp_locale;

    ob_start();
    // tabindex=-1: the form moves focus here when a date/time is missing.
    echo '<div class="wedding-booking-side-card wedding-booking-side-cal" id="wedding-booking-calCard" tabindex="-1" role="group" aria-labelledby="wedding-booking-calTitle">';
    echo '<div class="wedding-booking-side-head"><span class="wedding-booking-side-ic dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="wedding-booking-side-title" id="wedding-booking-calTitle">' . esc_html($s['date_title']) . '</span></div>';
    if ($s['date_sub'] !== '') {
        echo '<p class="wedding-booking-side-text wedding-booking-side-cal-sub">' . esc_html($s['date_sub']) . '</p>';
    }
    echo '<div class="wedding-booking-cal">';
    echo '<div class="wedding-booking-cal-head">';
    echo '<button class="wedding-booking-cal-nav" id="wedding-booking-calPrev" type="button" aria-label="' . esc_attr__('Previous month', 'wedding-booking') . '">&#8249;</button>';
    echo '<span id="wedding-booking-calMonth" aria-live="polite"></span>';
    echo '<button class="wedding-booking-cal-nav" id="wedding-booking-calNext" type="button" aria-label="' . esc_attr__('Next month', 'wedding-booking') . '">&#8250;</button>';
    echo '</div>';
    // Weekday header in the site's language, starting on its first day of
    // the week (booking.js redraws it from the same data).
    echo '<div class="wedding-booking-cal-days" id="wedding-booking-calDays" aria-hidden="true">';
    $start = (int) get_option('start_of_week', 0);
    for ($i = 0; $i < 7; $i++) {
        $wd   = ($start + $i) % 7;
        $name = $wp_locale instanceof WP_Locale ? $wp_locale->get_weekday($wd) : '';
        $abbr = $wp_locale instanceof WP_Locale ? $wp_locale->get_weekday_abbrev($name) : '';
        echo '<span title="' . esc_attr($name) . '">' . esc_html($abbr) . '</span>';
    }
    echo '</div>';
    echo '<div class="wedding-booking-cal-grid" id="wedding-booking-calGrid"></div>';
    echo '</div>';
    // Start times for the chosen date (only when the studio offers them).
    echo '<div class="wedding-booking-slots" id="wedding-booking-slots" hidden></div>';
    echo '<p id="wedding-booking-selDate" class="wedding-booking-seldate" aria-live="polite"></p>';
    echo '<p id="wedding-booking-calErr" class="wedding-booking-cal-err" hidden></p>';
    echo '</div>';
    return ob_get_clean();
}

/**
 * Build the frontend sidebar markup. The calendar card always renders
 * (date selection lives here now); the informational cards each honour
 * their own enable toggle. Output is fully escaped.
 */
function wedding_booking_render_frontend_sidebar()
{
    $s = wedding_booking_get_frontend_sidebar();

    // {deposit_pct} in the admin-editable texts → the global deposit %.
    foreach (['hiw_title', 'hiw_steps', 'deposit_title', 'deposit_text'] as $key) {
        $s[$key] = wedding_booking_fill_deposit_pct($s[$key]);
    }
    $deposits_on = function_exists('wedding_booking_deposit_enabled') ? wedding_booking_deposit_enabled() : ((int) get_option('wedding_booking_enable_partial_payment', 1) === 1);

    ob_start();

    if (! empty($s['hiw_enable'])) {
        $steps = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $s['hiw_steps'])), 'strlen');
        if ($s['hiw_title'] !== '' || ! empty($steps)) {
            echo '<div class="wedding-booking-side-card wedding-booking-side-hiw">';
            echo '<div class="wedding-booking-side-head"><span class="wedding-booking-side-ic dashicons dashicons-list-view" aria-hidden="true"></span><span class="wedding-booking-side-title">' . esc_html($s['hiw_title']) . '</span></div>';
            if (! empty($steps)) {
                echo '<ol class="wedding-booking-side-steps">';
                foreach ($steps as $step) {
                    echo '<li>' . esc_html($step) . '</li>';
                }
                echo '</ol>';
            }
            echo '</div>';
        }
    }

    // Calendar card — always present, positioned right after "How it works".
    echo wedding_booking_render_calendar_card($s); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts

    // The deposit card only makes sense while deposits are offered at all.
    if ($deposits_on && ! empty($s['deposit_enable']) && ($s['deposit_title'] !== '' || $s['deposit_text'] !== '')) {
        echo '<div class="wedding-booking-side-card wedding-booking-side-card-dark">';
        echo '<div class="wedding-booking-side-head"><span class="wedding-booking-side-ic dashicons dashicons-shield-alt" aria-hidden="true"></span><span class="wedding-booking-side-title">' . esc_html($s['deposit_title']) . '</span></div>';
        if ($s['deposit_text'] !== '') {
            echo '<p class="wedding-booking-side-text">' . nl2br(esc_html($s['deposit_text'])) . '</p>';
        }
        echo '</div>';
    }

    $inner = trim(ob_get_clean());
    if ($inner === '') {
        return '';
    }

    return '<aside class="wedding-booking-sidebar">' . $inner . '</aside>';
}

function wedding_booking_render_shortcode($opts = [])
{
    $opts = wp_parse_args($opts, ['package' => '']);
    $preselect = trim((string) $opts['package']);

    $has_wc = class_exists('WooCommerce');
    $mode   = wedding_booking_get_checkout_mode();
    if (! $has_wc) {
        $checkout_label = __('Submit Booking Request', 'wedding-booking');
    } else {
        $checkout_label = ($mode === 'direct') ? __('Place Booking & Pay', 'wedding-booking') : __('Proceed to Payment', 'wedding-booking');
    }

    $sidebar_html = wedding_booking_render_frontend_sidebar();
    $wrap_class   = 'wedding-booking-wrap' . ($sidebar_html === '' ? ' wedding-booking-no-sidebar' : '');

    // The Contract step is optional, so the wizard is either 3 or 4 steps.
    // Everything downstream (indicator, panel ids, Back buttons) is derived
    // from $step_labels / $pay_step rather than hard-coded numbers.
    $contract     = wedding_booking_get_contract_settings();
    $has_contract = ! empty($contract['enable']);
    $step_labels  = [__('Package', 'wedding-booking'), __('Details', 'wedding-booking')];
    if ($has_contract) {
        $step_labels[] = $contract['step_label'];
    }
    $step_labels[] = __('Payment', 'wedding-booking');
    $pay_step      = count($step_labels);
    $has_signature = $has_contract && wedding_booking_contract_signature_required();

    // Deposit % shown before the browser takes over (it then uses the
    // chosen package's own %).
    $deposit_pct   = function_exists('wedding_booking_get_deposit_pct') ? wedding_booking_get_deposit_pct() : 50;
    $partial_label = wedding_booking_fill_deposit_pct(wedding_booking_partial_option_label(), $deposit_pct);
    $fee_label     = function_exists('wedding_booking_payment_fee_label') ? wedding_booking_payment_fee_label() : __('Payment fee', 'wedding-booking');
    $coupons_on    = $has_wc && function_exists('wedding_booking_coupons_enabled') && wedding_booking_coupons_enabled();

    // Bookings that need an account: a log-in card at the top of the form.
    // Rendered (hidden) for logged-in visitors too, so the form can show it
    // if their session ends mid-booking.
    $login      = wedding_booking_booking_login_urls();
    $show_login = $has_wc && $login['required'];
?>
    <div class="<?php echo esc_attr($wrap_class); ?>"<?php echo $preselect !== '' ? ' data-package="' . esc_attr($preselect) . '"' : ''; ?>>
      <div class="wedding-booking-layout">
        <div class="wedding-booking-main">
        <?php if ($show_login) : ?>
        <div class="wedding-booking-login-card" id="wedding-booking-loginCard" tabindex="-1" role="region" aria-labelledby="wedding-booking-loginTitle"<?php echo is_user_logged_in() ? ' hidden' : ''; ?>>
            <span class="wedding-booking-login-ic dashicons dashicons-admin-users" aria-hidden="true"></span>
            <div class="wedding-booking-login-body">
                <p class="wedding-booking-login-title" id="wedding-booking-loginTitle"><?php esc_html_e("You'll need an account to book", 'wedding-booking'); ?></p>
                <p class="wedding-booking-login-text"><?php esc_html_e('Look around and pick your package and date first — once you log in, you come straight back to your selection.', 'wedding-booking'); ?></p>
            </div>
            <div class="wedding-booking-login-actions">
                <a class="wedding-booking-btn wedding-booking-btn-gold" data-wedding-booking-login="login" href="<?php echo esc_url($login['login']); ?>"><?php esc_html_e('Log in', 'wedding-booking'); ?></a>
                <?php if ($login['register'] !== '') : ?>
                <a class="wedding-booking-btn wedding-booking-btn-outline" data-wedding-booking-login="register" href="<?php echo esc_url($login['register']); ?>"><?php esc_html_e('Create account', 'wedding-booking'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="wedding-booking-card">

        <!-- Step indicator -->
        <div class="wedding-booking-steps-bar" id="wedding-booking-steps">
            <?php foreach ($step_labels as $i => $step_label) : $n = $i + 1; ?>
            <div class="wedding-booking-stab<?php echo $n === 1 ? ' wedding-booking-active' : ''; ?>" id="wedding-booking-sp<?php echo (int) $n; ?>" data-label="<?php echo esc_attr($step_label); ?>"<?php echo $n === 1 ? ' aria-current="step"' : ''; ?>><span class="wedding-booking-sci"><?php echo (int) $n; ?></span><?php echo esc_html($step_label); ?></div>
            <?php endforeach; ?>
        </div>

        <!-- STEP 1 — Package & Add-ons (date is chosen in the sidebar calendar) -->
        <div class="wedding-booking-step wedding-booking-act" id="wedding-booking-s1">
            <div class="wedding-booking-step-inner">
                <h2 class="wedding-booking-title" tabindex="-1"><?php esc_html_e('Select Your Package', 'wedding-booking'); ?></h2>
                <p class="wedding-booking-sub"><?php esc_html_e("Choose a session type, package, and any add-ons you'd like.", 'wedding-booking'); ?></p>

                <!-- Shown when a ?wedding_booking_package= share link points at an unavailable package -->
                <p class="wedding-booking-pkg-notice" id="wedding-booking-pkgNotice" style="display:none"><?php esc_html_e("That package isn't available — please choose from the options below.", 'wedding-booking'); ?></p>

                <div class="wedding-booking-sec-label" id="wedding-booking-typeLabel"><?php esc_html_e('Session Type', 'wedding-booking'); ?></div>
                <div class="wedding-booking-stype-wrap" id="wedding-booking-typeTabs" role="group" aria-labelledby="wedding-booking-typeLabel">
                    <!-- JS populates one .wedding-booking-stype-btn per session -->
                    <span class="wedding-booking-stype-loading"><?php esc_html_e('Loading…', 'wedding-booking'); ?></span>
                </div>

                <div class="wedding-booking-sec-label" id="wedding-booking-pkgLabel" style="margin-top:1.6rem"><?php esc_html_e('Select Package', 'wedding-booking'); ?></div>
                <div class="wedding-booking-pkg-cards" id="wedding-booking-pkgGrid" role="group" aria-labelledby="wedding-booking-pkgLabel"></div>

                <div class="wedding-booking-addons-box" id="wedding-booking-addonsWrap" style="display:none">
                    <div class="wedding-booking-addon-title"><?php esc_html_e('Add-ons', 'wedding-booking'); ?> <span class="wedding-booking-addon-opt">(<?php esc_html_e('Optional', 'wedding-booking'); ?>)</span></div>
                    <div class="wedding-booking-addon-grid" id="wedding-booking-addonsGrid"></div>
                </div>

                <!-- Deposit toggle — shown right after the add-ons -->
                <div class="wedding-booking-addons-box" id="wedding-booking-partialWrap" style="display:none">
                    <div class="wedding-booking-addon-title"><?php esc_html_e('Payment Option', 'wedding-booking'); ?></div>
                    <label class="wedding-booking-addon-card wedding-booking-partial-card" for="wedding-booking-partialToggle">
                        <input class="wedding-booking-ac" type="checkbox" id="wedding-booking-partialToggle" checked aria-describedby="wedding-booking-partialNote">
                        <span class="wedding-booking-partial-em" id="wedding-booking-partialEm" aria-hidden="true"><?php echo (int) $deposit_pct; ?>%</span>
                        <span class="wedding-booking-addon-info">
                            <span class="wedding-booking-addon-name" id="wedding-booking-partialLabel"><?php echo esc_html($partial_label); ?></span>
                            <span class="wedding-booking-addon-desc" id="wedding-booking-partialNote"></span>
                        </span>
                        <span class="wedding-booking-partial-switch" aria-hidden="true"><span class="wedding-booking-partial-knob"></span></span>
                    </label>
                </div>

                <!-- Live price strip — updates as package / add-ons / toggle change -->
                <div class="wedding-booking-price-strip" id="wedding-booking-s2Price" style="display:none" aria-live="polite">
                    <div class="wedding-booking-price-cell">
                        <span class="wedding-booking-price-label"><?php esc_html_e('Total', 'wedding-booking'); ?></span>
                        <span class="wedding-booking-price-val" id="wedding-booking-s2Total">—</span>
                    </div>
                    <div class="wedding-booking-price-cell wedding-booking-is-due">
                        <span class="wedding-booking-price-label" id="wedding-booking-s2DueLabel"><?php esc_html_e('Pay now', 'wedding-booking'); ?></span>
                        <span class="wedding-booking-price-val" id="wedding-booking-s2Due">—</span>
                    </div>
                    <div class="wedding-booking-price-cell" id="wedding-booking-s2LaterCell" style="display:none">
                        <span class="wedding-booking-price-label"><?php esc_html_e('Pay later', 'wedding-booking'); ?></span>
                        <span class="wedding-booking-price-val" id="wedding-booking-s2Later">—</span>
                    </div>
                </div>
                <!-- Payment fee / promo note under the price strip -->
                <p class="wedding-booking-price-note" id="wedding-booking-s2PriceNote" hidden></p>

                <div class="wedding-booking-nav">
                    <div></div>
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-gold" id="wedding-booking-s1NextBtn" onclick="weddingBooking.s1Next()"><?php esc_html_e('Continue', 'wedding-booking'); ?> &#8594;</button>
                </div>
                <p id="wedding-booking-s1err" class="wedding-booking-error" role="alert"></p>
            </div>
        </div>

        <!-- STEP 2 — Details (checkout form, fields managed in Wedding Booking → Settings) -->
        <div class="wedding-booking-step" id="wedding-booking-s2">
            <div class="wedding-booking-step-inner">
                <h2 class="wedding-booking-title" tabindex="-1"><?php esc_html_e('Your Details', 'wedding-booking'); ?></h2>
                <p class="wedding-booking-sub"><?php esc_html_e('Fill in your booking and contact details.', 'wedding-booking'); ?></p>

                <div class="wedding-booking-details-groups" id="wedding-booking-detailsGrid">
                    <?php wedding_booking_render_checkout_form_fields(); ?>
                </div>

                <div class="wedding-booking-nav">
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-outline" onclick="weddingBooking.bkGo(1)">&#8592; <?php esc_html_e('Back', 'wedding-booking'); ?></button>
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-gold" id="wedding-booking-s2NextBtn" onclick="weddingBooking.s2Next()"><?php esc_html_e('Continue', 'wedding-booking'); ?> &#8594;</button>
                </div>
                <p id="wedding-booking-s2err" class="wedding-booking-error" role="alert"></p>
            </div>
        </div>

        <?php if ($has_contract) : ?>
        <!-- STEP 3 — Contract (optional, Wedding Booking → Booking Form → Contract step) -->
        <div class="wedding-booking-step" id="wedding-booking-s3">
            <div class="wedding-booking-step-inner">
                <h2 class="wedding-booking-title" tabindex="-1"><?php echo esc_html($contract['title']); ?></h2>
                <?php if (trim($contract['sub']) !== '') : ?>
                <p class="wedding-booking-sub"><?php echo esc_html($contract['sub']); ?></p>
                <?php endif; ?>

                <div class="wedding-booking-contract-doc" id="wedding-booking-contractDoc" tabindex="0" role="region" aria-label="<?php echo esc_attr($contract['title']); ?>">
                    <?php echo wp_kses_post(wpautop($contract['text'])); ?>
                </div>

                <label class="wedding-booking-contract-accept" for="wedding-booking-contractAccept">
                    <input type="checkbox" id="wedding-booking-contractAccept">
                    <span class="wedding-booking-contract-accept-text"><?php echo esc_html($contract['accept_label']); ?></span>
                </label>

                <?php if ($has_signature) : ?>
                <!-- Typed signature (Wedding Booking → Booking Form → Contract step) -->
                <div class="wedding-booking-field wedding-booking-contract-sign">
                    <label for="wedding-booking-contractSignature"><?php esc_html_e('Type your full name to sign', 'wedding-booking'); ?> <span class="wedding-booking-req">*</span></label>
                    <input type="text" id="wedding-booking-contractSignature" autocomplete="name" aria-required="true" aria-describedby="wedding-booking-contractSignHint" placeholder="<?php esc_attr_e('Your full name', 'wedding-booking'); ?>">
                    <p class="wedding-booking-sign-hint" id="wedding-booking-contractSignHint"><?php esc_html_e('Typing your name here counts as your signature on this agreement.', 'wedding-booking'); ?></p>
                </div>
                <?php endif; ?>

                <div class="wedding-booking-nav">
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-outline" onclick="weddingBooking.bkGo(2)">&#8592; <?php esc_html_e('Back', 'wedding-booking'); ?></button>
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-gold" id="wedding-booking-s3NextBtn" onclick="weddingBooking.s3Next()"><?php esc_html_e('Continue', 'wedding-booking'); ?> &#8594;</button>
                </div>
                <p id="wedding-booking-s3err" class="wedding-booking-error" role="alert"></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- FINAL STEP — Payment (step 3, or 4 when the contract step is on) -->
        <div class="wedding-booking-step" id="wedding-booking-s<?php echo (int) $pay_step; ?>">
            <div class="wedding-booking-step-inner" id="wedding-booking-payWrap">
                <h2 class="wedding-booking-title" tabindex="-1"><?php esc_html_e('Review & Payment', 'wedding-booking'); ?></h2>
                <p class="wedding-booking-sub"><?php esc_html_e("Review your booking, choose how you'd like to pay, and confirm.", 'wedding-booking'); ?></p>

                <!-- Live Booking Summary -->
                <div class="wedding-booking-bsum" id="wedding-booking-pay-summary">
                    <h5><?php esc_html_e('Booking Summary', 'wedding-booking'); ?></h5>
                    <div class="wedding-booking-sumr"><span><?php esc_html_e('Session', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-session">—</span></div>
                    <div class="wedding-booking-sumr"><span><?php esc_html_e('Package', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-pkg">—</span></div>
                    <div class="wedding-booking-sumr"><span><?php esc_html_e('Date', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-date">—</span></div>
                    <div class="wedding-booking-sumr" id="wedding-booking-sum-time-row" style="display:none"><span><?php esc_html_e('Start time', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-time">—</span></div>
                    <div class="wedding-booking-sumr"><span><?php esc_html_e('Add-ons', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-addons">—</span></div>
                    <div class="wedding-booking-sumr"><span id="wedding-booking-sum-price-label"><?php esc_html_e('Total price', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-price">—</span></div>
                    <?php if ($coupons_on) : ?>
                    <div class="wedding-booking-sumr wedding-booking-sum-discount-row" id="wedding-booking-sum-discount-row" style="display:none">
                        <span><?php esc_html_e('Promo code', 'wedding-booking'); ?> <code id="wedding-booking-sum-discount-code"></code> <button type="button" class="wedding-booking-link-btn" id="wedding-booking-promoRemove"><?php esc_html_e('Remove', 'wedding-booking'); ?></button></span>
                        <span class="wedding-booking-v" id="wedding-booking-sum-discount">—</span>
                    </div>
                    <?php endif; ?>
                    <div class="wedding-booking-sumr wedding-booking-sum-fee-row" id="wedding-booking-sum-fee-row" style="display:none"><span id="wedding-booking-sum-fee-label"><?php echo esc_html($fee_label); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-fee">—</span></div>
                    <div class="wedding-booking-sumr wedding-booking-sum-payable-row" id="wedding-booking-sum-payable-row" style="display:none"><span><?php esc_html_e('Total payable', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-sum-payable">—</span></div>
                    <?php if ($coupons_on) : ?>
                    <!-- Promo code (WooCommerce coupons; Wedding Booking → Settings) -->
                    <div class="wedding-booking-promo" id="wedding-booking-promo">
                        <button type="button" class="wedding-booking-link-btn wedding-booking-promo-toggle" id="wedding-booking-promoToggle" aria-expanded="false" aria-controls="wedding-booking-promoForm"><?php esc_html_e('Have a promo code?', 'wedding-booking'); ?></button>
                        <div class="wedding-booking-promo-form" id="wedding-booking-promoForm" hidden>
                            <label class="screen-reader-text wedding-booking-sr-only" for="wedding-booking-promoInput"><?php esc_html_e('Promo code', 'wedding-booking'); ?></label>
                            <input type="text" id="wedding-booking-promoInput" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php esc_attr_e('Promo code', 'wedding-booking'); ?>">
                            <button type="button" class="wedding-booking-btn wedding-booking-btn-outline wedding-booking-promo-apply" id="wedding-booking-promoApply"><?php esc_html_e('Apply', 'wedding-booking'); ?></button>
                        </div>
                        <p class="wedding-booking-promo-msg" id="wedding-booking-promoMsg" role="alert"></p>
                    </div>
                    <?php endif; ?>
                    <div class="wedding-booking-sumt">
                        <div>
                            <div class="wedding-booking-sumtl"><?php esc_html_e('Due Now', 'wedding-booking'); ?></div>
                            <div class="wedding-booking-sumtd" id="wedding-booking-sum-dep"></div>
                        </div>
                        <div class="wedding-booking-sumtn" id="wedding-booking-sum-total">—</div>
                    </div>
                    <!-- "Remaining balance … is due later" (built by booking.js) -->
                    <p class="wedding-booking-sum-note" id="wedding-booking-sum-balance-row" style="display:none"></p>
                    <!-- Fee-free payment methods, when the studio set any -->
                    <p class="wedding-booking-sum-note wedding-booking-sum-fee-note" id="wedding-booking-sum-fee-note" style="display:none"></p>
                </div>

                <!-- WooCommerce payment methods -->
                <div class="wedding-booking-gateway-box" id="wedding-booking-gatewayBox" style="display:none">
                    <div class="wedding-booking-gateway-title"><?php esc_html_e('Payment Method', 'wedding-booking'); ?></div>
                    <div class="wedding-booking-gateway-list" id="wedding-booking-gatewayList"></div>
                </div>

                <div class="wedding-booking-nav">
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-outline" onclick="weddingBooking.bkGo(<?php echo (int) ($pay_step - 1); ?>)">&#8592; <?php esc_html_e('Back', 'wedding-booking'); ?></button>
                    <button type="button" class="wedding-booking-btn wedding-booking-btn-gold wedding-booking-checkout-btn" id="wedding-booking-checkoutBtn" onclick="weddingBooking.proceedToCheckout()"><?php echo esc_html($checkout_label); ?> &#8594;</button>
                </div>
                <p id="wedding-booking-payErr" class="wedding-booking-error" role="alert"></p>
                <p id="wedding-booking-checkoutMsg" class="wedding-booking-checkout-msg" role="status" aria-live="polite"></p>
            </div>
            <!-- In-place booking confirmation (direct checkout mode) -->
            <div class="wedding-booking-suc" id="wedding-booking-confirmWrap" style="display:none">
                <div class="wedding-booking-step-inner wedding-booking-suc-inner">
                    <div class="wedding-booking-suc-icon" aria-hidden="true">✓</div>
                    <h2 class="wedding-booking-title" id="wedding-booking-confirmTitle" tabindex="-1"><?php echo esc_html(get_option('wedding_booking_confirm_title', __('Booking Confirmed!', 'wedding-booking'))); ?></h2>
                    <p class="wedding-booking-sub" id="wedding-booking-confirmNote"></p>
                    <!-- Bank details etc. for bank transfer / cheque / cash bookings -->
                    <div class="wedding-booking-confirm-instructions" id="wedding-booking-confirmInstructions" hidden></div>
                    <div class="wedding-booking-bsum wedding-booking-confirm-summary">
                        <div class="wedding-booking-sumr"><span><?php esc_html_e('Order', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-confirmOrder">—</span></div>
                        <div class="wedding-booking-sumr"><span><?php esc_html_e('Payment method', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-confirmMethod">—</span></div>
                        <div class="wedding-booking-sumr"><span><?php esc_html_e('Amount', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-confirmAmount">—</span></div>
                        <div class="wedding-booking-sumr"><span><?php esc_html_e('Status', 'wedding-booking'); ?></span><span class="wedding-booking-v" id="wedding-booking-confirmStatus">—</span></div>
                    </div>
                    <div class="wedding-booking-nav" style="justify-content:center;margin-top:1.6rem">
                        <a class="wedding-booking-btn wedding-booking-btn-gold" id="wedding-booking-confirmPayBtn" href="#" style="display:none"><?php esc_html_e('Complete Payment', 'wedding-booking'); ?> &#8594;</a>
                        <a class="wedding-booking-btn wedding-booking-btn-outline" id="wedding-booking-confirmViewBtn" href="#" style="display:none"><?php esc_html_e('View Order Details', 'wedding-booking'); ?></a>
                        <a class="wedding-booking-btn wedding-booking-btn-outline" id="wedding-booking-confirmWaBtn" href="#" target="_blank" rel="noopener" style="display:none"><?php echo esc_html(get_option('wedding_booking_whatsapp_btn', __('Message us on WhatsApp', 'wedding-booking'))); ?></a>
                    </div>
                </div>
            </div>
            <!-- Success state (fallback email flow) -->
            <div class="wedding-booking-suc" id="wedding-booking-sucWrap" style="display:none">
                <div class="wedding-booking-step-inner wedding-booking-suc-inner">
                    <div class="wedding-booking-suc-icon" aria-hidden="true">✓</div>
                    <h2 class="wedding-booking-title" id="wedding-booking-sucTitle" tabindex="-1"><?php echo esc_html(get_option('wedding_booking_success_title', __('Booking Requested!', 'wedding-booking'))); ?></h2>
                    <p><?php echo esc_html(get_option('wedding_booking_success_msg', __("We've received your request and will confirm availability within 24 hours. A confirmation will be sent to", 'wedding-booking'))); ?> <strong id="wedding-booking-sucEmail"></strong>.</p>
                    <div class="wedding-booking-nav" style="justify-content:center;margin-top:2rem">
                        <a class="wedding-booking-btn wedding-booking-btn-gold" id="wedding-booking-waLink" href="#" target="_blank" rel="noopener"><?php echo esc_html(get_option('wedding_booking_whatsapp_btn', __('Message us on WhatsApp', 'wedding-booking'))); ?></a>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- .wedding-booking-card -->
        </div><!-- .wedding-booking-main -->
        <?php echo $sidebar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in wedding_booking_render_frontend_sidebar() ?>
      </div><!-- .wedding-booking-layout -->
    </div>
<?php
}
