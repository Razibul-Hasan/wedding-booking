<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   Enqueue checkout styles
═══════════════════════════════════════════════════════════════ */
add_action('wp_enqueue_scripts', 'wedding_booking_enqueue_checkout_styles', 99);
function wedding_booking_enqueue_checkout_styles()
{
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_style(
            'wedding-booking-checkout',
            WEDDING_BOOKING_URL . 'assets/css/checkout.css',
            [],
            WEDDING_BOOKING_VER
        );
    }
}

function wedding_booking_get_booking_from_cart()
{
    if (! function_exists('WC') || ! WC()->cart) return [];
    foreach (WC()->cart->get_cart() as $item) {
        if (! empty($item['wedding_booking_booking']) && is_array($item['wedding_booking_booking'])) {
            return $item['wedding_booking_booking'];
        }
    }
    return [];
}

function wedding_booking_checkout_has_booking()
{
    return ! empty(wedding_booking_get_booking_from_cart());
}

function wedding_booking_partial_payment_enabled()
{
    return (int) get_option('wedding_booking_enable_partial_payment', 1) === 1;
}

function wedding_booking_account_required_for_booking()
{
    return (int) get_option('wedding_booking_require_account_booking', 0) === 1;
}

add_filter('woocommerce_checkout_registration_required', 'wedding_booking_force_checkout_registration_for_booking');
function wedding_booking_force_checkout_registration_for_booking($required)
{
    if (wedding_booking_checkout_has_booking() && wedding_booking_account_required_for_booking()) {
        return true;
    }

    return $required;
}

function wedding_booking_should_force_classic_checkout()
{
    if (is_admin() || ! function_exists('is_checkout') || ! is_checkout()) {
        return false;
    }

    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return false;
    }

    return wedding_booking_checkout_has_booking();
}

/* ═══════════════════════════════════════════════════════════════
   Checkout block fallback — render classic checkout for Wedding Booking
═══════════════════════════════════════════════════════════════ */
add_filter('the_content', 'wedding_booking_force_classic_checkout_for_booking', 20);
function wedding_booking_force_classic_checkout_for_booking($content)
{
    if (! wedding_booking_should_force_classic_checkout()) {
        return $content;
    }

    global $post;
    if (! $post || ! has_block('woocommerce/checkout', $post)) {
        return $content;
    }

    static $rendering = false;
    if ($rendering) {
        return $content;
    }

    $rendering = true;
    $classic_checkout = do_shortcode('[woocommerce_checkout]');
    $rendering = false;

    return ! empty($classic_checkout) ? $classic_checkout : $content;
}

add_filter('render_block', 'wedding_booking_replace_checkout_block_for_booking', 20, 2);
function wedding_booking_replace_checkout_block_for_booking($block_content, $block)
{
    if (! wedding_booking_should_force_classic_checkout()) {
        return $block_content;
    }

    if (empty($block['blockName']) || $block['blockName'] !== 'woocommerce/checkout') {
        return $block_content;
    }

    static $rendering = false;
    if ($rendering) {
        return $block_content;
    }

    $rendering = true;
    $classic_checkout = do_shortcode('[woocommerce_checkout]');
    $rendering = false;

    return ! empty($classic_checkout) ? $classic_checkout : $block_content;
}

/* ═══════════════════════════════════════════════════════════════
   Checkout fields — add booking-specific billing details
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_checkout_fields', 'wedding_booking_customize_checkout_fields', 20);
function wedding_booking_customize_checkout_fields($fields)
{
    if (! wedding_booking_checkout_has_booking()) return $fields;

    if (isset($fields['billing']['billing_first_name'])) {
        $fields['billing']['billing_first_name']['label'] = __('First name', 'wedding-booking');
        $fields['billing']['billing_first_name']['placeholder'] = __('Surname', 'wedding-booking');
        $fields['billing']['billing_first_name']['priority'] = 10;
        $fields['billing']['billing_first_name']['required'] = true;
    }

    if (isset($fields['billing']['billing_last_name'])) {
        $fields['billing']['billing_last_name']['label'] = __('Last name', 'wedding-booking');
        $fields['billing']['billing_last_name']['placeholder'] = __('Name', 'wedding-booking');
        $fields['billing']['billing_last_name']['priority'] = 20;
        $fields['billing']['billing_last_name']['required'] = true;
    }

    $fields['billing']['billing_event_date'] = [
        'type'        => 'date',
        'label'       => __('Date', 'wedding-booking'),
        'placeholder' => __('Date of the event', 'wedding-booking'),
        'required'    => true,
        'class'       => ['form-row-first'],
        'priority'    => 30,
    ];

    $fields['billing']['billing_event_time'] = [
        'type'        => 'time',
        'label'       => __('Start Time', 'wedding-booking'),
        'placeholder' => __('Time of the event', 'wedding-booking'),
        'required'    => true,
        'class'       => ['form-row-last'],
        'priority'    => 40,
    ];

    $fields['billing']['billing_hotel_place'] = [
        'type'        => 'text',
        'label'       => __('Hotel Name / Bungalow / Place Residence', 'wedding-booking'),
        'placeholder' => __('Hotel / Bungalow / Place', 'wedding-booking'),
        'required'    => true,
        'class'       => ['form-row-wide'],
        'priority'    => 50,
    ];

    $fields['billing']['billing_participants'] = [
        'type'        => 'number',
        'label'       => __('Participants', 'wedding-booking'),
        'placeholder' => __('Number of People', 'wedding-booking'),
        'required'    => true,
        'class'       => ['form-row-first'],
        'priority'    => 60,
        'custom_attributes' => [
            'min' => '1',
            'step' => '1',
        ],
    ];

    // Text, not number: rooms are often "B12" or "Villa 3".
    $fields['billing']['billing_room_number'] = [
        'type'        => 'text',
        'label'       => __('Room Number', 'wedding-booking'),
        'placeholder' => __('Room number', 'wedding-booking'),
        'required'    => false,
        'class'       => ['form-row-last'],
        'priority'    => 70,
    ];

    $fields['billing']['billing_stay_period'] = [
        'type'        => 'text',
        'label'       => __('Period of stay in Mauritius', 'wedding-booking'),
        'placeholder' => __('From - To', 'wedding-booking'),
        'required'    => true,
        'class'       => ['form-row-wide'],
        'priority'    => 80,
    ];

    if (isset($fields['billing']['billing_country'])) {
        $fields['billing']['billing_country']['label'] = __('Country / Region', 'wedding-booking');
        $fields['billing']['billing_country']['class'] = ['form-row-wide'];
        $fields['billing']['billing_country']['priority'] = 90;
        $fields['billing']['billing_country']['required'] = true;
    }

    if (isset($fields['billing']['billing_address_1'])) {
        $fields['billing']['billing_address_1']['label'] = __('Street address', 'wedding-booking');
        $fields['billing']['billing_address_1']['placeholder'] = __('House number and street name', 'wedding-booking');
        $fields['billing']['billing_address_1']['class'] = ['form-row-wide'];
        $fields['billing']['billing_address_1']['priority'] = 100;
        $fields['billing']['billing_address_1']['required'] = true;
    }

    if (isset($fields['billing']['billing_city'])) {
        $fields['billing']['billing_city']['label'] = __('Town / City', 'wedding-booking');
        $fields['billing']['billing_city']['class'] = ['form-row-first'];
        $fields['billing']['billing_city']['priority'] = 110;
        $fields['billing']['billing_city']['required'] = true;
    }

    if (isset($fields['billing']['billing_postcode'])) {
        $fields['billing']['billing_postcode']['label'] = __('Postcode / ZIP', 'wedding-booking');
        $fields['billing']['billing_postcode']['class'] = ['form-row-last'];
        $fields['billing']['billing_postcode']['priority'] = 120;
        $fields['billing']['billing_postcode']['required'] = true;
    }

    if (isset($fields['billing']['billing_phone'])) {
        $fields['billing']['billing_phone']['label'] = __('Whatsapp', 'wedding-booking');
        $fields['billing']['billing_phone']['class'] = ['form-row-first'];
        $fields['billing']['billing_phone']['priority'] = 130;
        $fields['billing']['billing_phone']['required'] = true;
    }

    if (isset($fields['billing']['billing_email'])) {
        $fields['billing']['billing_email']['label'] = __('Email address', 'wedding-booking');
        $fields['billing']['billing_email']['class'] = ['form-row-last'];
        $fields['billing']['billing_email']['priority'] = 140;
        $fields['billing']['billing_email']['required'] = true;
    }

    // Apply the admin-configured checkout form (labels / required / enabled)
    // so the same field manager drives both the booking form and this page.
    if (function_exists('wedding_booking_get_checkout_form_fields')) {
        $cfg = wedding_booking_get_checkout_form_fields();

        $core_map = [
            'first_name' => 'billing_first_name',
            'last_name'  => 'billing_last_name',
            'email'      => 'billing_email',
            'phone'      => 'billing_phone',
            'country'    => 'billing_country',
            'address_1'  => 'billing_address_1',
            'city'       => 'billing_city',
            'postcode'   => 'billing_postcode',
        ];
        foreach ($core_map as $key => $billing_key) {
            if (! isset($cfg[$key], $fields['billing'][$billing_key])) {
                continue;
            }
            $fields['billing'][$billing_key]['label']    = $cfg[$key]['label'];
            $fields['billing'][$billing_key]['required'] = ! empty($cfg[$key]['enabled']) && ! empty($cfg[$key]['required']);
        }

        $custom_map = [
            'event_time'   => 'billing_event_time',
            'hotel_place'  => 'billing_hotel_place',
            'participants' => 'billing_participants',
            'room_number'  => 'billing_room_number',
            'stay_period'  => 'billing_stay_period',
        ];
        foreach ($custom_map as $key => $billing_key) {
            if (! isset($cfg[$key])) {
                continue;
            }
            if (empty($cfg[$key]['enabled'])) {
                unset($fields['billing'][$billing_key]);
                continue;
            }
            if (isset($fields['billing'][$billing_key])) {
                $fields['billing'][$billing_key]['label']    = $cfg[$key]['label'];
                $fields['billing'][$billing_key]['required'] = ! empty($cfg[$key]['required']);
            }
        }
    }

    // Admin-created custom fields appear after the built-in ones.
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        $priority = 150;
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $cf) {
            $fields['billing']['billing_wedding_booking_cf_' . $key] = [
                'type'        => $cf['type'] === 'textarea' ? 'textarea' : $cf['type'],
                'label'       => $cf['label'],
                'placeholder' => $cf['label'],
                'required'    => ! empty($cf['required']),
                'class'       => ['form-row-wide'],
                'priority'    => $priority,
            ];
            $priority += 10;
        }
    }

    return $fields;
}

add_filter('woocommerce_checkout_get_value', 'wedding_booking_prefill_checkout_values', 10, 2);
function wedding_booking_prefill_checkout_values($value, $input)
{
    if (! wedding_booking_checkout_has_booking()) return $value;
    if (! empty($value)) return $value;

    $booking = wedding_booking_get_booking_from_cart();
    if (empty($booking)) return $value;

    if ($input === 'billing_event_date' && ! empty($booking['session_date'])) {
        return sanitize_text_field($booking['session_date']);
    }

    if ($input === 'billing_event_time' && ! empty($booking['session_time'])) {
        return sanitize_text_field($booking['session_time']);
    }

    if ($input === 'billing_hotel_place' && ! empty($booking['location_pref'])) {
        return sanitize_text_field($booking['location_pref']);
    }

    if ($input === 'billing_phone' && ! empty($booking['client_phone'])) {
        return sanitize_text_field($booking['client_phone']);
    }

    if ($input === 'billing_email' && ! empty($booking['client_email'])) {
        return sanitize_email($booking['client_email']);
    }

    if ($input === 'billing_country' && ! empty($booking['client_country'])) {
        $country = strtoupper(sanitize_text_field($booking['client_country']));
        if (strlen($country) === 2) {
            return $country;
        }
    }

    if ($input === 'billing_first_name' && ! empty($booking['client_name'])) {
        $name_parts = preg_split('/\s+/', trim((string) $booking['client_name']));
        return sanitize_text_field($name_parts[0] ?? '');
    }

    if ($input === 'billing_last_name' && ! empty($booking['client_name'])) {
        $name_parts = preg_split('/\s+/', trim((string) $booking['client_name']));
        array_shift($name_parts);
        return sanitize_text_field(implode(' ', $name_parts));
    }

    $direct_map = [
        'billing_address_1'    => 'address_1',
        'billing_city'         => 'city',
        'billing_postcode'     => 'postcode',
        'billing_participants' => 'participants',
        'billing_room_number'  => 'room_number',
        'billing_stay_period'  => 'stay_period',
        'order_comments'       => 'notes',
    ];
    if (isset($direct_map[$input]) && ! empty($booking[$direct_map[$input]])) {
        return sanitize_text_field($booking[$direct_map[$input]]);
    }

    return $value;
}

add_action('woocommerce_after_checkout_validation', 'wedding_booking_validate_checkout_fields', 10, 2);
function wedding_booking_validate_checkout_fields($data, $errors)
{
    if (! wedding_booking_checkout_has_booking()) return;

    $cfg = function_exists('wedding_booking_get_checkout_form_fields') ? wedding_booking_get_checkout_form_fields() : [];

    $required = [
        'billing_event_date' => __('Date of the event is required.', 'wedding-booking'),
    ];
    $custom_map = [
        'event_time'   => 'billing_event_time',
        'hotel_place'  => 'billing_hotel_place',
        'participants' => 'billing_participants',
        'room_number'  => 'billing_room_number',
        'stay_period'  => 'billing_stay_period',
    ];
    foreach ($custom_map as $key => $billing_key) {
        if (! empty($cfg[$key]['enabled']) && ! empty($cfg[$key]['required'])) {
            /* translators: %s: field label */
            $required[$billing_key] = sprintf(__('%s is required.', 'wedding-booking'), $cfg[$key]['label']);
        }
    }
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $cf) {
            if (! empty($cf['required'])) {
                /* translators: %s: field label */
                $required['billing_wedding_booking_cf_' . $key] = sprintf(__('%s is required.', 'wedding-booking'), $cf['label']);
            }
        }
    }

    foreach ($required as $key => $message) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        if ($value === '') {
            $errors->add('validation', $message);
        }
    }

    // The date may have been taken since the booking went into the cart.
    foreach ((WC()->cart ? WC()->cart->get_cart() : []) as $cart_item) {
        if (! empty($cart_item['wedding_booking_booking']['session_date'])) {
            $date_ok = wedding_booking_validate_booking_date($cart_item['wedding_booking_booking']['session_date']);
            if (is_wp_error($date_ok)) {
                $errors->add('validation', $date_ok->get_error_message());
            }
            break;
        }
    }

    if (! empty($cfg['participants']['enabled'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
        $participants = isset($_POST['billing_participants']) ? absint(wp_unslash($_POST['billing_participants'])) : 0;
        if ($participants < 1 && ! empty($cfg['participants']['required'])) {
            $errors->add('validation', __('Participants must be at least 1.', 'wedding-booking'));
        }
    }
}

add_action('woocommerce_checkout_create_order', 'wedding_booking_store_checkout_fields_on_order', 10, 2);
function wedding_booking_store_checkout_fields_on_order($order, $data)
{
    if (! wedding_booking_checkout_has_booking()) return;

    $map = [
        'billing_event_date'   => '_wedding_booking_billing_event_date',
        'billing_event_time'   => '_wedding_booking_billing_event_time',
        'billing_hotel_place'  => '_wedding_booking_billing_hotel_place',
        'billing_participants' => '_wedding_booking_billing_participants',
        'billing_room_number'  => '_wedding_booking_billing_room_number',
        'billing_stay_period'  => '_wedding_booking_billing_stay_period',
    ];
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $cf) {
            $map['billing_wedding_booking_cf_' . $key] = '_wedding_booking_cf_' . $key;
        }
    }

    foreach ($map as $field => $meta_key) {
        if ($field === 'billing_participants') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
            $value = isset($_POST[$field]) ? absint(wp_unslash($_POST[$field])) : 0;
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }
        $order->update_meta_data($meta_key, $value);
    }
}

/* ═══════════════════════════════════════════════════════════════
   Set full booking price on cart item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_before_calculate_totals', 'wedding_booking_set_cart_item_price', 20);
function wedding_booking_set_cart_item_price($cart)
{
    if (is_admin() && ! defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;
    foreach ($cart->get_cart() as $item) {
        if (isset($item['wedding_booking_booking']['deposit'])) {
            $item['data']->set_price((float) $item['wedding_booking_booking']['deposit']);
        } elseif (isset($item['wedding_booking_booking']['total'])) {
            $item['data']->set_price((float) $item['wedding_booking_booking']['total']);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   Rename cart item line
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_cart_item_name', 'wedding_booking_cart_item_name', 10, 2);
function wedding_booking_cart_item_name($name, $cart_item)
{
    if (isset($cart_item['wedding_booking_booking']['package_name'])) {
        return esc_html__('Photography Session', 'wedding-booking')
            . ' — ' . esc_html($cart_item['wedding_booking_booking']['package_name']);
    }
    return $name;
}

/* ═══════════════════════════════════════════════════════════════
   Show booking summary in cart / checkout
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_get_item_data', 'wedding_booking_get_item_data', 10, 2);
function wedding_booking_get_item_data($data, $cart_item)
{
    if (! isset($cart_item['wedding_booking_booking'])) return $data;
    $b = $cart_item['wedding_booking_booking'];
    $cur = $b['currency'] ?? wedding_booking_get_currency_symbol();

    if (! empty($b['session_type']))  $data[] = ['name' => __('Session',      'wedding-booking'), 'value' => esc_html($b['session_type'])];
    if (! empty($b['session_date']))  $data[] = ['name' => __('Date',         'wedding-booking'), 'value' => esc_html($b['session_date'])];
    if (! empty($b['client_name']))   $data[] = ['name' => __('Client',       'wedding-booking'), 'value' => esc_html($b['client_name'])];
    if (! empty($b['addons_label']))  $data[] = ['name' => __('Add-ons',      'wedding-booking'), 'value' => esc_html($b['addons_label'])];
    $due_amount = (float) ($b['total'] ?? 0);
    $due_today_raw = (float) ($b['deposit'] ?? $due_amount);
    $due_today = number_format($due_today_raw, 2);
    $data[] = ['name' => __('Due today', 'wedding-booking'), 'value' => esc_html($cur) . $due_today];
    if ($due_amount > $due_today_raw) {
        $data[] = ['name' => __('Remaining balance', 'wedding-booking'), 'value' => esc_html($cur) . number_format($due_amount - $due_today_raw, 2)];
    }

    return $data;
}

/* ═══════════════════════════════════════════════════════════════
   Save booking meta to order item
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_checkout_create_order_line_item', 'wedding_booking_save_order_item_meta', 10, 4);
function wedding_booking_save_order_item_meta($item, $cart_item_key, $values, $order)
{
    if (! isset($values['wedding_booking_booking'])) return;
    $b = $values['wedding_booking_booking'];
    $cur = $b['currency'] ?? wedding_booking_get_currency_symbol();

    $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $client_name = ! empty($b['client_name']) ? $b['client_name'] : $billing_name;
    $client_email = ! empty($b['client_email']) ? $b['client_email'] : $order->get_billing_email();
    $client_phone = ! empty($b['client_phone']) ? $b['client_phone'] : $order->get_billing_phone();
    $client_country = ! empty($b['client_country']) ? $b['client_country'] : $order->get_billing_country();
    $session_date = ! empty($b['session_date']) ? $b['session_date'] : $order->get_meta('_wedding_booking_billing_event_date', true);
    $session_time = ! empty($b['session_time']) ? $b['session_time'] : $order->get_meta('_wedding_booking_billing_event_time', true);
    $location_pref = ! empty($b['location_pref']) ? $b['location_pref'] : $order->get_meta('_wedding_booking_billing_hotel_place', true);

    $meta_map = [
        '_wedding_booking_session_type'  => $b['session_type']  ?? '',
        '_wedding_booking_package_name'  => $b['package_name']  ?? '',
        '_wedding_booking_package_id'    => (int) ($b['package_id'] ?? 0),
        '_wedding_booking_addon_ids'     => implode(',', array_map('intval', (array) ($b['addon_ids'] ?? []))),
        '_wedding_booking_total'         => $b['total']         ?? '',
        '_wedding_booking_fee_pct'       => $b['fee_pct']       ?? 0,
        '_wedding_booking_fee_amount'    => $b['fee_amount']    ?? 0,
        '_wedding_booking_deposit'       => $b['deposit']       ?? '',
        '_wedding_booking_deposit_pct'   => $b['deposit_pct']   ?? (wedding_booking_partial_payment_enabled() ? wedding_booking_get_deposit_pct((int) ($b['package_id'] ?? 0)) : 100),
        '_wedding_booking_balance_due'   => max(0, (float) ($b['total'] ?? 0) - (float) ($b['deposit'] ?? 0)),
        '_wedding_booking_addons_label'  => $b['addons_label']  ?? '',
        '_wedding_booking_addons_total'  => $b['addons_total']  ?? '',
        '_wedding_booking_client_name'   => $client_name,
        '_wedding_booking_client_email'  => $client_email,
        '_wedding_booking_client_phone'  => $client_phone,
        '_wedding_booking_client_country' => $client_country,
        '_wedding_booking_session_date'  => $session_date,
        '_wedding_booking_session_time'  => $session_time,
        '_wedding_booking_location_pref' => $location_pref,
        '_wedding_booking_notes'         => $b['notes']         ?? '',
        '_wedding_booking_signer_name'   => $b['signer_name']   ?? '',
        '_wedding_booking_billing_event_date'   => $order->get_meta('_wedding_booking_billing_event_date', true),
        '_wedding_booking_billing_event_time'   => $order->get_meta('_wedding_booking_billing_event_time', true),
        '_wedding_booking_billing_hotel_place'  => $order->get_meta('_wedding_booking_billing_hotel_place', true),
        '_wedding_booking_billing_participants' => $order->get_meta('_wedding_booking_billing_participants', true),
        '_wedding_booking_billing_room_number'  => $order->get_meta('_wedding_booking_billing_room_number', true),
        '_wedding_booking_billing_stay_period'  => $order->get_meta('_wedding_booking_billing_stay_period', true),
        '_wedding_booking_currency'      => $cur,
        '_wedding_booking_subtotal'      => $b['subtotal']      ?? '',
        '_wedding_booking_coupon_code'   => $b['coupon_code']   ?? '',
        '_wedding_booking_discount'      => $b['discount']      ?? 0,
    ];
    if (! empty($b['contract']['signature'])) {
        $meta_map['_wedding_booking_signer_name'] = (string) $b['contract']['signature'];
    }
    foreach ($meta_map as $key => $val) {
        $item->add_meta_data($key, $val, true);
    }

    // Same order-level records as the direct flow (wedding_booking_create_booking_order()).
    if (! empty($b['balance_due_date'])) {
        $order->update_meta_data('_wedding_booking_balance_due_date', (string) $b['balance_due_date']);
    }
    if (! empty($b['contract']) && is_array($b['contract'])) {
        $order->update_meta_data('_wedding_booking_contract_accepted_at', (string) $b['contract']['accepted_at']);
        $order->update_meta_data('_wedding_booking_contract_version', (string) $b['contract']['version']);
        $order->update_meta_data('_wedding_booking_contract_signature', (string) $b['contract']['signature']);
        $order->update_meta_data('_wedding_booking_contract_ip', (string) $b['contract']['ip']);
        $order->update_meta_data('_wedding_booking_contract_ua', (string) $b['contract']['ua']);
        wedding_booking_remember_contract_version($b['contract']['version']);
    }
}

/* ═══════════════════════════════════════════════════════════════
   Booking orders → booking rows
   ───────────────────────────────────────────────────────────────
   Paid (processing/completed): the booking is confirmed (deposit paid or
   paid in full). Placed but unpaid (on-hold: bank transfer, cheque, cash on
   delivery; or added by the studio as unpaid): the booking is "awaiting
   payment" and already holds its date, so nobody else can take it while
   the money is on its way.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_payment_complete', 'wedding_booking_on_paid_order', 10, 1);
add_action('woocommerce_order_status_processing', 'wedding_booking_on_paid_order', 10, 1);
add_action('woocommerce_order_status_completed', 'wedding_booking_on_paid_order', 10, 1);
function wedding_booking_on_paid_order($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return;
    }

    wedding_booking_cleanup_checkout_draft_orders($order);
    wedding_booking_upsert_booking_from_order($order);
}

add_action('woocommerce_order_status_on-hold', 'wedding_booking_on_booking_order_on_hold', 10, 1);
function wedding_booking_on_booking_order_on_hold($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1 || ! wedding_booking_is_booking_order($order)) {
        return;
    }
    wedding_booking_upsert_booking_from_order($order);
}

/**
 * created_via for orders the studio adds by hand. Never 'wedding_booking': that value
 * marks public-form orders, which expire when left unpaid and count as holds.
 */
function wedding_booking_booking_order_created_via()
{
    return 'wedding-booking-admin';
}

/**
 * The order's booking line item (the one carrying the hidden booking
 * product), or null.
 */
function wedding_booking_get_booking_item($order)
{
    if (! $order) {
        return null;
    }
    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    foreach ($order->get_items() as $item) {
        if ((int) $item->get_product_id() === $product_id) {
            return $item;
        }
    }

    return null;
}

/**
 * Create or bring up to date the wp_wedding_booking_bookings row behind a booking order.
 *
 * - New row: status from the payment state ('awaiting_payment' while unpaid,
 *   'pending_payment' once a deposit is paid, 'confirmed' when paid in full)
 *   unless $status is given; the date is held (and a clash is flagged to the
 *   studio); wedding_booking_booking_created fires (Google Calendar listens).
 * - Existing row: an 'awaiting_payment' booking moves on once the order is
 *   paid; a 'cancelled' booking whose order became active again is
 *   re-activated and holds its date again.
 * - Once paid with a balance left, the balance order is created (idempotent).
 *
 * @param WC_Order|int $order
 * @param string       $status Force a status for a NEW row (e.g. 'awaiting_payment').
 * @return int Booking row id, 0 when the order carries no booking.
 */
function wedding_booking_upsert_booking_from_order($order, $status = '')
{
    $order = is_a($order, 'WC_Order') ? $order : wc_get_order((int) $order);
    if (! $order || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return 0;
    }
    $item = wedding_booking_get_booking_item($order);
    if (! $item) {
        return 0;
    }

    $order_id     = (int) $order->get_id();
    $package_name = (string) $item->get_meta('_wedding_booking_package_name');
    $session_date = (string) $item->get_meta('_wedding_booking_session_date');
    // The hidden booking product bought directly (?add-to-cart=) carries no
    // booking at all; don't record an empty booking for it.
    if ($package_name === '' && $session_date === '') {
        return 0;
    }

    $deposit_pct = (int) $item->get_meta('_wedding_booking_deposit_pct');
    if ($deposit_pct < 1) {
        $deposit_pct = wedding_booking_partial_payment_enabled() ? wedding_booking_get_deposit_pct() : 100;
    }
    $deposit = (float) $item->get_meta('_wedding_booking_deposit');
    $total   = (float) $item->get_meta('_wedding_booking_total');
    if ($total <= 0 && $deposit > 0) {
        $total = round($deposit * 100 / max($deposit_pct, 1), 2);
    }
    if ($deposit <= 0) {
        $deposit = $total;
    }
    $balance_due = max(0, round($total - $deposit, 2));

    $paid    = $order->is_paid();
    $derived = ! $paid ? 'awaiting_payment' : ($balance_due > 0.01 ? 'pending_payment' : 'confirmed');
    if ($status === '' || ! array_key_exists($status, wedding_booking_booking_statuses())) {
        $status = $derived;
    }

    // Gateways can report one payment twice at the same moment (webhook plus
    // the customer's return), so check-then-insert runs under a lock.
    global $wpdb;
    $pfx  = $wpdb->prefix . 'wedding_booking_';
    $lock = 'wedding_booking_booking_' . $order_id;
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    $row         = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$pfx}bookings WHERE order_id = %d LIMIT 1", $order_id)); // phpcs:ignore
    $booking_id  = $row ? (int) $row->id : 0;
    $holds_date  = false; // the date needs (re)checking and holding
    $newly_paid  = false;

    if (! $row) {
        $wpdb->insert("{$pfx}bookings", [ // phpcs:ignore
            'order_id'       => $order_id,
            'session_type'   => sanitize_text_field((string) $item->get_meta('_wedding_booking_session_type')),
            'package_name'   => sanitize_text_field($package_name),
            // _wedding_booking_total includes the payment fee; the package price doesn't.
            'package_price'  => max(0, (float) $item->get_meta('_wedding_booking_total') - (float) $item->get_meta('_wedding_booking_addons_total') - (float) $item->get_meta('_wedding_booking_fee_amount')),
            'addons_json'    => sanitize_text_field((string) $item->get_meta('_wedding_booking_addons_label')),
            'addons_total'   => (float) $item->get_meta('_wedding_booking_addons_total'),
            'total'          => $total,
            'deposit'        => $deposit,
            'client_name'    => sanitize_text_field((string) $item->get_meta('_wedding_booking_client_name')),
            'client_email'   => sanitize_email((string) $item->get_meta('_wedding_booking_client_email')),
            'client_phone'   => sanitize_text_field((string) $item->get_meta('_wedding_booking_client_phone')),
            'client_country' => sanitize_text_field((string) $item->get_meta('_wedding_booking_client_country')),
            'session_date'   => $session_date !== '' ? $session_date : null,
            'session_time'   => sanitize_text_field((string) $item->get_meta('_wedding_booking_session_time')),
            'location_pref'  => sanitize_text_field((string) $item->get_meta('_wedding_booking_location_pref')),
            'notes'          => sanitize_textarea_field((string) $item->get_meta('_wedding_booking_notes')),
            'signer_name'    => sanitize_text_field((string) $item->get_meta('_wedding_booking_signer_name')),
            'status'         => $status,
        ]);
        $booking_id = (int) $wpdb->insert_id;
        $holds_date = true;
        $newly_paid = $paid;
    } elseif ($row->status === 'awaiting_payment' && $paid) {
        $wpdb->update("{$pfx}bookings", ['status' => $derived], ['id' => $booking_id]); // phpcs:ignore
        $newly_paid = true;
    } elseif ($row->status === 'cancelled' && $order->has_status(['processing', 'completed', 'on-hold'])) {
        // Brought back from a cancellation: it needs its date again.
        $wpdb->update("{$pfx}bookings", ['status' => $derived], ['id' => $booking_id]); // phpcs:ignore
        $holds_date = true;
        $newly_paid = $paid;
        $order->add_order_note(__('Booking re-activated; its date is held again.', 'wedding-booking'));
    }

    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if ($booking_id < 1) {
        return 0;
    }

    // Hold the date. It was checked when the order was created, but two
    // customers can still end up on one date (an old tab, a pay link used
    // late, a booking re-activated). The money is taken either way, so the
    // booking stays; the studio is told so it can move one of them.
    if ($holds_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
        $clash = wedding_booking_validate_booking_date($session_date, $order_id, (string) $item->get_meta('_wedding_booking_session_time'), '', true);
        if (is_wp_error($clash) && in_array($clash->get_error_code(), ['wedding_booking_date_taken', 'wedding_booking_slot_taken'], true)) {
            $slot = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$pfx}dates WHERE date_str = %s", $session_date)); // phpcs:ignore
            wedding_booking_flag_date_conflict($order, $session_date, $slot === 'blocked' ? 'blocked' : 'booked');
        }
        wedding_booking_refresh_date_slot($session_date);
    }

    // Balance reminders need no scheduling: the hourly sweep picks up every
    // booking with an unpaid balance order.
    if ($paid && $balance_due > 0.01) {
        wedding_booking_create_balance_order($order, $item, $balance_due);
    }

    if ($holds_date) {
        /**
         * A booking now holds its date (new, or re-activated). Google Calendar
         * sync listens here; fired last so the row and date are fully written.
         *
         * @param int $booking_id Row id in the {prefix}wedding_booking_bookings table.
         * @param int $order_id   Main booking order id.
         */
        do_action('wedding_booking_booking_created', $booking_id, $order_id);
    }
    if ($newly_paid) {
        /**
         * A booking's first payment (deposit or full) has come in.
         *
         * @param int $booking_id
         * @param int $order_id
         */
        do_action('wedding_booking_booking_paid', $booking_id, $order_id);
    }

    return $booking_id;
}

/**
 * A booking was paid for a date that was already taken (or blocked by the
 * studio). Leave a note on the order and email the studio so one of the two
 * bookings can be moved.
 *
 * @param WC_Order $order
 * @param string   $date   Y-m-d.
 * @param string   $reason 'booked' | 'blocked'.
 */
function wedding_booking_flag_date_conflict($order, $date, $reason)
{
    $pretty = function_exists('wedding_booking_email_pretty_date') ? wedding_booking_email_pretty_date($date) : $date;
    $note   = $reason === 'blocked'
        /* translators: %s: session date */
        ? sprintf(__('Date conflict: %s is blocked in Wedding Booking → Date Slots, but this booking was paid for it. Please contact the customer to confirm or reschedule.', 'wedding-booking'), $pretty)
        /* translators: %s: session date */
        : sprintf(__('Date conflict: %s already has another booking, but this booking was paid for it too. Please contact the customer to reschedule.', 'wedding-booking'), $pretty);
    $order->add_order_note($note);

    $to = sanitize_email((string) get_option('wedding_booking_admin_email', get_option('admin_email')));
    if ($to !== '' && function_exists('wedding_booking_email_send')) {
        $content  = wedding_booking_email_title(__('Two bookings for one date', 'wedding-booking'));
        $content .= wedding_booking_email_text(esc_html($note));
        $content .= wedding_booking_email_button($order->get_edit_order_url(), __('Open the order', 'wedding-booking'));
        /* translators: 1: session date, 2: order number */
        wedding_booking_email_send($to, sprintf(__('Booking conflict on %1$s (order #%2$s)', 'wedding-booking'), $pretty, $order->get_order_number()), $content);
    }
}

add_action('woocommerce_order_status_processing', 'wedding_booking_on_balance_order_paid', 20, 1);
add_action('woocommerce_order_status_completed', 'wedding_booking_on_balance_order_paid', 20, 1);
function wedding_booking_on_balance_order_paid($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) !== 1) {
        return;
    }

    $parent_order_id = (int) $order->get_meta('_wedding_booking_parent_order_id', true);
    if ($parent_order_id < 1) {
        return;
    }

    $parent_order = wc_get_order($parent_order_id);
    // The booking was cancelled or refunded before the balance came in: the
    // money needs handling by hand, not a "completed" booking.
    if ($parent_order && in_array($parent_order->get_status(), ['cancelled', 'refunded'], true)) {
        /* translators: %d: main booking order id */
        $order->add_order_note(sprintf(__('Balance paid, but booking order #%d is cancelled or refunded. Please review and refund if needed.', 'wedding-booking'), $parent_order_id));
        return;
    }
    if ($parent_order && ! in_array($parent_order->get_status(), ['completed', 'cancelled', 'refunded'], true)) {
        $parent_order->set_status('completed');
        $parent_order->save();
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom bookings table, no core API available.
    $wpdb->update(
        $wpdb->prefix . 'wedding_booking_bookings',
        ['status' => 'completed'],
        ['order_id' => $parent_order_id],
        ['%s'],
        ['%d']
    );
}

/* ═══════════════════════════════════════════════════════════════
   Cancelled / refunded bookings: free the date, void the balance
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_order_status_cancelled', 'wedding_booking_on_booking_order_voided', 10, 1);
add_action('woocommerce_order_status_refunded', 'wedding_booking_on_booking_order_voided', 10, 1);
function wedding_booking_on_booking_order_voided($order_id)
{
    $order = wc_get_order($order_id);
    if (! $order || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return;
    }

    global $wpdb;
    $pfx     = $wpdb->prefix . 'wedding_booking_';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT id, session_date, status FROM {$pfx}bookings WHERE order_id = %d LIMIT 1", (int) $order_id)); // phpcs:ignore
    if (! $booking) {
        return; // Never paid, so it never became a booking.
    }

    if ($booking->status !== 'cancelled') {
        $wpdb->update("{$pfx}bookings", ['status' => 'cancelled'], ['id' => (int) $booking->id]); // phpcs:ignore
    }

    // Nothing left to collect: close the unpaid balance order, which also
    // stops its reminders and kills its pay link.
    $due = wedding_booking_get_balance_order_for($order, false);
    if ($due && $due->has_status(['pending', 'failed', 'on-hold'])) {
        $due->update_status('cancelled', __('Booking cancelled, balance no longer due.', 'wedding-booking'));
    }

    if (wedding_booking_release_booking_date((string) $booking->session_date, (int) $order_id)) {
        /* translators: %s: session date */
        $order->add_order_note(sprintf(__('Booking cancelled; %s is available again on the booking calendar.', 'wedding-booking'), wedding_booking_email_pretty_date((string) $booking->session_date)));
    }

    /**
     * A booking was cancelled or refunded (Google Calendar removes its event).
     *
     * @param int $booking_id
     * @param int $order_id
     */
    do_action('wedding_booking_booking_cancelled', (int) $booking->id, (int) $order_id);
}

/**
 * WooCommerce expires unpaid orders after "Hold stock (minutes)", but only
 * for orders its own checkout created. Booking orders left unpaid on the
 * payment step would otherwise stay payable forever, for a date someone
 * else may have booked since. Balance orders are never expired.
 */
add_filter('woocommerce_cancel_unpaid_order', 'wedding_booking_cancel_unpaid_booking_orders', 10, 2);
function wedding_booking_cancel_unpaid_booking_orders($cancel, $order)
{
    if ($cancel || ! $order) {
        return $cancel;
    }

    return $order->get_created_via() === 'wedding_booking' && (int) $order->get_meta('_wedding_booking_is_balance_order', true) !== 1;
}

// An abandoned booking form being tidied away isn't news for the studio:
// no "order cancelled" email for booking orders that were never paid.
add_filter('woocommerce_email_enabled_cancelled_order', 'wedding_booking_skip_abandoned_cancel_email', 10, 2);
function wedding_booking_skip_abandoned_cancel_email($enabled, $order)
{
    if ($enabled && $order && is_a($order, 'WC_Order') && $order->get_created_via() === 'wedding_booking' && ! $order->get_date_paid()) {
        return false;
    }

    return $enabled;
}

// The hidden booking product only makes sense with a booking attached,
// which the booking form adds; block ?add-to-cart=<id> and the like.
add_filter('woocommerce_add_to_cart_validation', 'wedding_booking_block_direct_booking_product', 10, 2);
function wedding_booking_block_direct_booking_product($passed, $product_id)
{
    if ((int) $product_id > 0 && (int) $product_id === (int) get_option('wedding_booking_wc_product_id', 0)) {
        wc_add_notice(__('Please use the booking form to book a session.', 'wedding-booking'), 'error');
        return false;
    }

    return $passed;
}

/* ═══════════════════════════════════════════════════════════════
   Offline payments (bank transfer, cheque, cash on delivery)
═══════════════════════════════════════════════════════════════ */

// WooCommerce marks cash-on-delivery orders "processing" (paid) because goods
// ship before the money arrives. For a booking nothing has been received yet:
// treat it like a bank transfer — "awaiting payment", date held.
add_filter('woocommerce_cod_process_payment_order_status', 'wedding_booking_cod_booking_status', 10, 2);
function wedding_booking_cod_booking_status($status, $order = null)
{
    if ($order && is_a($order, 'WC_Order') && wedding_booking_is_booking_order($order)) {
        return 'on-hold';
    }

    return $status;
}

/**
 * Hourly housekeeping: expire unpaid offline bookings (Wedding Booking → Settings →
 * "Cancel unpaid bank-transfer bookings after N days") and retry failed
 * Google Calendar syncs. Always scheduled while the plugin is active.
 */
add_action('init', 'wedding_booking_schedule_maintenance');
function wedding_booking_schedule_maintenance()
{
    if (! wp_next_scheduled('wedding_booking_maintenance')) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wedding_booking_maintenance');
    }
}

add_action('wedding_booking_maintenance', 'wedding_booking_run_maintenance');
function wedding_booking_run_maintenance()
{
    wedding_booking_expire_unpaid_offline_bookings();
    if (function_exists('wedding_booking_gcal_run_retries')) {
        wedding_booking_gcal_run_retries();
    }
}

/**
 * Cancel booking orders still on hold (unpaid bank transfer / cheque / cash)
 * N days after they were placed. Cancelling frees the date
 * (wedding_booking_on_booking_order_voided).
 *
 * @return int Orders cancelled.
 */
function wedding_booking_expire_unpaid_offline_bookings()
{
    $days = (int) wedding_booking_opt('wedding_booking_offline_hold_days');
    if ($days < 1) {
        return 0;
    }

    $orders = wc_get_orders([
        'limit'        => 50,
        'status'       => ['on-hold'],
        'date_created' => '<' . (time() - $days * DAY_IN_SECONDS),
        'return'       => 'objects',
    ]);

    $count = 0;
    foreach ((array) $orders as $order) {
        if (! $order || (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1 || ! wedding_booking_is_booking_order($order)) {
            continue;
        }
        $order->update_status('cancelled', sprintf(
            /* translators: %d: number of days */
            _n('Booking cancelled automatically: no payment received within %d day.', 'Booking cancelled automatically: no payment received within %d days.', $days, 'wedding-booking'),
            $days
        ));
        $count++;
    }

    return $count;
}

/* ═══════════════════════════════════════════════════════════════
   Payment fee: none for fee-free methods
   ───────────────────────────────────────────────────────────────
   The booking form places its order before the customer picks how to pay,
   so the fee is included up front. When they then pay with a method the
   studio exempted (bank transfer by default), the fee comes off before the
   payment is processed — on the order-pay page, and on the classic checkout.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_before_pay_action', 'wedding_booking_fee_on_pay_action', 5, 1);
function wedding_booking_fee_on_pay_action($order)
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the woocommerce-pay nonce before this hook.
    $gateway = isset($_POST['payment_method']) ? sanitize_key(wp_unslash($_POST['payment_method'])) : '';
    wedding_booking_strip_fee_for_gateway($order, $gateway);
}

add_action('woocommerce_checkout_order_processed', 'wedding_booking_fee_on_checkout', 5, 3);
function wedding_booking_fee_on_checkout($order_id, $posted_data = [], $order = null)
{
    $order = $order && is_a($order, 'WC_Order') ? $order : wc_get_order($order_id);
    if ($order) {
        wedding_booking_strip_fee_for_gateway($order, (string) $order->get_payment_method());
    }
}

/**
 * Remove the payment fee from an unpaid booking or balance order when the
 * chosen gateway is fee-free. The deposit is re-split from the fee-free
 * total. Returns whether anything changed.
 *
 * @param WC_Order $order
 * @param string   $gateway_id
 * @param bool     $force      Remove it whatever the gateway: the studio is
 *                             recording money it received directly.
 */
function wedding_booking_strip_fee_for_gateway($order, $gateway_id, $force = false)
{
    if (! $order || ! is_a($order, 'WC_Order') || $order->is_paid() || (! $force && wedding_booking_fee_applies_to_gateway($gateway_id))) {
        return false;
    }
    $gateway_id = sanitize_key((string) $gateway_id);

    // Balance order: its share of the fee comes off it, and off the booking.
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        $share = round((float) $order->get_meta('_wedding_booking_fee_amount', true), 2);
        $item  = current($order->get_items());
        if ($share <= 0 || ! $item) {
            return false;
        }
        $item->set_subtotal(max(0, (float) $item->get_subtotal() - $share));
        $item->set_total(max(0, (float) $item->get_total() - $share));
        $item->save();
        $order->update_meta_data('_wedding_booking_balance_due', max(0, round((float) $order->get_meta('_wedding_booking_balance_due', true) - $share, 2)));
        $order->update_meta_data('_wedding_booking_fee_amount', 0);
        $order->calculate_totals(false);
        /* translators: 1: fee amount, 2: payment method id */
        $order->add_order_note(sprintf(__('Payment fee of %1$s removed (paid with %2$s).', 'wedding-booking'), wc_format_decimal($share, 2), $gateway_id));
        $order->save();

        $parent      = wc_get_order((int) $order->get_meta('_wedding_booking_parent_order_id', true));
        $parent_item = $parent ? wedding_booking_get_booking_item($parent) : null;
        if ($parent_item) {
            $parent_item->update_meta_data('_wedding_booking_total', round((float) $parent_item->get_meta('_wedding_booking_total') - $share, 2));
            $parent_item->update_meta_data('_wedding_booking_balance_due', max(0, round((float) $parent_item->get_meta('_wedding_booking_balance_due') - $share, 2)));
            $parent_item->update_meta_data('_wedding_booking_fee_amount', max(0, round((float) $parent_item->get_meta('_wedding_booking_fee_amount') - $share, 2)));
            $parent_item->save();
            global $wpdb;
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wedding_booking_bookings SET total = GREATEST(0, total - %f) WHERE order_id = %d", $share, (int) $parent->get_id())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }
        return true;
    }

    // Main booking order, before its first payment.
    $item = wedding_booking_get_booking_item($order);
    $fee  = $item ? round((float) $item->get_meta('_wedding_booking_fee_amount'), 2) : 0;
    if (! $item || $fee <= 0) {
        return false;
    }
    $pct         = max(1, min(100, (int) $item->get_meta('_wedding_booking_deposit_pct')));
    $total       = max(0, round((float) $item->get_meta('_wedding_booking_total') - $fee, 2));
    $deposit     = $pct >= 100 ? $total : round($total * $pct / 100, 2);
    $discount_in = max(0, (float) $item->get_subtotal() - (float) $item->get_total());

    $item->set_subtotal($deposit + $discount_in);
    $item->set_total($deposit);
    $item->update_meta_data('_wedding_booking_total', $total);
    $item->update_meta_data('_wedding_booking_deposit', $deposit);
    $item->update_meta_data('_wedding_booking_balance_due', max(0, round($total - $deposit, 2)));
    $item->update_meta_data('_wedding_booking_fee_amount', 0);
    $item->update_meta_data('_wedding_booking_fee_pct', 0);
    $item->save();
    $order->calculate_totals();
    /* translators: 1: fee amount, 2: payment method id */
    $order->add_order_note(sprintf(__('Payment fee of %1$s removed (paid with %2$s).', 'wedding-booking'), wc_format_decimal($fee, 2), $gateway_id));
    $order->save();

    return true;
}

function wedding_booking_cleanup_checkout_draft_orders($paid_order)
{
    if (! $paid_order || ! function_exists('wc_get_orders')) {
        return;
    }

    $wedding_booking_product = (int) get_option('wedding_booking_wc_product_id', 0);
    if ($wedding_booking_product < 1) {
        return;
    }

    $query = [
        'limit'   => 30,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
        'status'  => ['checkout-draft', 'auto-draft', 'draft'],
    ];

    $customer_id = (int) $paid_order->get_customer_id();
    if ($customer_id > 0) {
        $query['customer_id'] = $customer_id;
    } else {
        $billing_email = sanitize_email($paid_order->get_billing_email());
        if ($billing_email === '') {
            return;
        }

        $query['billing_email'] = $billing_email;
    }

    $draft_orders = wc_get_orders($query);
    if (empty($draft_orders)) {
        return;
    }

    $paid_order_id = (int) $paid_order->get_id();
    foreach ($draft_orders as $draft_order) {
        if (! $draft_order) {
            continue;
        }

        $draft_order_id = (int) $draft_order->get_id();
        if ($draft_order_id === $paid_order_id) {
            continue;
        }

        if ((int) $draft_order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
            continue;
        }

        $has_wedding_booking_item = false;
        foreach ($draft_order->get_items() as $draft_item) {
            if ((int) $draft_item->get_product_id() === $wedding_booking_product) {
                $has_wedding_booking_item = true;
                break;
            }
        }

        if (! $has_wedding_booking_item) {
            continue;
        }

        $draft_order->delete(true);
    }
}

/**
 * Id of the balance order already created for a booking, or 0.
 *
 * Reads the database, not the caller's order object: WooCommerce renders
 * the confirmation email with its own copy of the order, loaded before
 * wedding_booking_on_paid_order() stored _wedding_booking_due_order_id, and trusting that
 * copy used to create a second balance order for every deposit.
 */
function wedding_booking_find_balance_order_id($parent_id)
{
    $parent_id = (int) $parent_id;
    $parent    = $parent_id > 0 ? wc_get_order($parent_id) : null;
    $due_id    = $parent ? (int) $parent->get_meta('_wedding_booking_due_order_id', true) : 0;
    if ($due_id > 0 && wc_get_order($due_id)) {
        return $due_id;
    }

    $ids = wc_get_orders([
        'limit'      => 1,
        'return'     => 'ids',
        'orderby'    => 'ID',
        'order'      => 'ASC',
        'status'     => array_keys(wc_get_order_statuses()),
        'meta_key'   => '_wedding_booking_parent_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value' => $parent_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    ]);

    return $ids ? (int) $ids[0] : 0;
}

function wedding_booking_create_balance_order($parent_order, $parent_item, $balance_due)
{
    if (! $parent_order || $balance_due <= 0) {
        return 0;
    }

    $wedding_booking_product = (int) get_option('wedding_booking_wc_product_id', 0);
    $product     = $wedding_booking_product > 0 ? wc_get_product($wedding_booking_product) : null;
    if (! $product) {
        return 0;
    }

    // Payment gateways can report the same payment twice at once (IPN or
    // webhook plus the customer's return). A named lock makes the
    // check-then-create below atomic per booking.
    global $wpdb;
    $parent_id = (int) $parent_order->get_id();
    $lock      = 'wedding_booking_balance_' . $parent_id;
    $locked    = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) === 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    try {
        $existing = wedding_booking_find_balance_order_id($parent_id);
        if ($existing > 0) {
            wedding_booking_store_balance_order_id($parent_id, $existing);
            return $existing;
        }

        $due_order = wc_create_order(['customer_id' => (int) $parent_order->get_customer_id()]);
        if (! $due_order || is_wp_error($due_order)) {
            return 0;
        }

        $line_item = new WC_Order_Item_Product();
        $line_item->set_product($product);
        $line_item->set_quantity(1);
        $line_item->set_subtotal($balance_due);
        $line_item->set_total($balance_due);
        $line_item->add_meta_data('_wedding_booking_is_balance_item', 1, true);
        $line_item->add_meta_data('_wedding_booking_parent_order_id', $parent_id, true);
        $line_item->add_meta_data('_wedding_booking_package_name', (string) $parent_item->get_meta('_wedding_booking_package_name'), true);
        $line_item->add_meta_data('_wedding_booking_session_date', (string) $parent_item->get_meta('_wedding_booking_session_date'), true);
        $due_order->add_item($line_item);

        $due_order->set_currency($parent_order->get_currency());
        $due_order->set_address($parent_order->get_address('billing'), 'billing');
        $due_order->set_address($parent_order->get_address('shipping'), 'shipping');
        $due_order->update_meta_data('_wedding_booking_is_balance_order', 1);
        $due_order->update_meta_data('_wedding_booking_parent_order_id', $parent_id);
        $due_order->update_meta_data('_wedding_booking_balance_due', $balance_due);
        $due_order->update_meta_data('_wedding_booking_package_name', (string) $parent_item->get_meta('_wedding_booking_package_name'));
        $due_order->update_meta_data('_wedding_booking_session_date', (string) $parent_item->get_meta('_wedding_booking_session_date'));
        // This order's share of the payment fee, so it can come off if the
        // balance is paid with a fee-free method (wedding_booking_strip_fee_for_gateway()).
        $parent_total = (float) $parent_item->get_meta('_wedding_booking_total');
        $parent_fee   = (float) $parent_item->get_meta('_wedding_booking_fee_amount');
        if ($parent_fee > 0 && $parent_total > 0) {
            $due_order->update_meta_data('_wedding_booking_fee_amount', round($parent_fee * $balance_due / $parent_total, 2));
        }
        $due_date = (string) $parent_order->get_meta('_wedding_booking_balance_due_date', true);
        if ($due_date === '' && function_exists('wedding_booking_balance_due_date')) {
            $due_date = wedding_booking_balance_due_date((string) $parent_item->get_meta('_wedding_booking_session_date'));
        }
        if ($due_date !== '') {
            $due_order->update_meta_data('_wedding_booking_balance_due_date', $due_date);
        }
        $due_order->calculate_totals(false);
        $due_order->set_status('pending');
        $due_order->save();

        wedding_booking_store_balance_order_id($parent_id, (int) $due_order->get_id());

        return (int) $due_order->get_id();
    } finally {
        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }
    }
}

/**
 * Point the booking's main order at its balance order. Written through a
 * freshly loaded copy so a stale in-memory order can't add a second meta row.
 */
function wedding_booking_store_balance_order_id($parent_id, $due_id)
{
    $parent = wc_get_order((int) $parent_id);
    if ($parent && (int) $parent->get_meta('_wedding_booking_due_order_id', true) !== (int) $due_id) {
        $parent->update_meta_data('_wedding_booking_due_order_id', (int) $due_id);
        $parent->save();
    }
}

/* ═══════════════════════════════════════════════════════════════
   Remaining-balance reminders
   ───────────────────────────────────────────────────────────────
   Reminders used to be one WP-Cron event per order, queued when the
   deposit was paid. That missed every booking made while reminders were
   off, ignored later changes to the settings, and sent nothing at all on
   hosts where WP-Cron doesn't run.

   Now an hourly sweep looks at every booking with an unpaid balance and
   works out from the current settings, and from what has already been
   sent, whether a reminder is due (wedding_booking_balance_reminder_next()).
   If WP-Cron isn't running, the sweep runs at the end of an ordinary
   page request instead (wedding_booking_reminder_sweep_fallback()).
═══════════════════════════════════════════════════════════════ */
add_action('init', 'wedding_booking_schedule_reminder_sweep');
function wedding_booking_schedule_reminder_sweep()
{
    // Retire the old scheduler's per-order events, once.
    if ((int) get_option('wedding_booking_reminder_engine', 0) < 2) {
        wp_unschedule_hook('wedding_booking_send_balance_reminder_event');
        update_option('wedding_booking_reminder_engine', 2);
    }

    $next = wp_next_scheduled('wedding_booking_balance_reminder_sweep');
    if (wedding_booking_balance_reminders_active()) {
        if (! $next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'wedding_booking_balance_reminder_sweep');
        }
    } elseif ($next) {
        wp_clear_scheduled_hook('wedding_booking_balance_reminder_sweep');
    }
}

add_action('wedding_booking_balance_reminder_sweep', 'wedding_booking_run_scheduled_reminder_sweep');
function wedding_booking_run_scheduled_reminder_sweep()
{
    wedding_booking_run_balance_reminders('cron');
}

// An event queued by the old scheduler can still fire during the request
// that upgrades the plugin; hand it to the sweep, which applies the rules.
add_action('wedding_booking_send_balance_reminder_event', 'wedding_booking_send_scheduled_balance_reminder', 10, 1);
function wedding_booking_send_scheduled_balance_reminder($order_id)
{
    wedding_booking_run_balance_reminders('cron');
}

/**
 * Safety net for hosts where WP-Cron never fires (DISABLE_WP_CRON without a
 * server cron job, or blocked loopback requests). When the last sweep is
 * more than 90 minutes old, run it at the end of this request, after the
 * page has been sent to the visitor where the server supports that.
 */
add_action('shutdown', 'wedding_booking_reminder_sweep_fallback', 100);
function wedding_booking_reminder_sweep_fallback()
{
    if (
        wp_doing_cron() || wp_doing_ajax() || wp_installing()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        || (defined('WP_CLI') && WP_CLI)
    ) {
        return;
    }
    if (! wedding_booking_balance_reminders_active()) {
        return;
    }

    $last = wedding_booking_reminder_last_sweep();
    if (time() - $last['ts'] < 90 * MINUTE_IN_SECONDS) {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    ignore_user_abort(true);

    wedding_booking_run_balance_reminders('fallback');
}

/**
 * Summary of the most recent sweep (see wedding_booking_run_balance_reminders()).
 */
function wedding_booking_reminder_last_sweep()
{
    $last = get_option('wedding_booking_reminder_last_sweep', []);
    $last = wp_parse_args(is_array($last) ? $last : [], ['ts' => 0, 'trigger' => '', 'checked' => 0, 'sent' => 0, 'failed' => 0]);
    $last['ts'] = (int) $last['ts'];

    return $last;
}

/**
 * Cross-request lock so two sweeps (WP-Cron and the fallback, say) never run
 * at once and email the same customer twice. INSERT IGNORE on the options
 * table is atomic, unlike a transient. A lock older than 10 minutes belongs
 * to a request that died, and is taken over.
 */
function wedding_booking_reminder_lock_acquire()
{
    global $wpdb;
    $now = time();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock, must bypass the options cache.
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wedding_booking_reminder_sweep_lock', $now))) {
        return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
    $held = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wedding_booking_reminder_sweep_lock'));
    if ($held > $now - 10 * MINUTE_IN_SECONDS) {
        return false;
    }

    // Stale. Take it over, unless another request just did.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
    return (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, 'wedding_booking_reminder_sweep_lock', $held));
}

function wedding_booking_reminder_lock_release()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see wedding_booking_reminder_lock_acquire().
    $wpdb->delete($wpdb->options, ['option_name' => 'wedding_booking_reminder_sweep_lock']);
}

/**
 * Check every booking with an unpaid balance and send the reminders that are
 * due. The summary is returned and stored in wedding_booking_reminder_last_sweep for the
 * settings screen.
 *
 * @param string $trigger 'cron', 'fallback' or 'manual'.
 * @return array
 */
function wedding_booking_run_balance_reminders($trigger = 'cron')
{
    $result = ['ts' => time(), 'trigger' => (string) $trigger, 'checked' => 0, 'sent' => 0, 'failed' => 0];
    if (! wedding_booking_balance_reminders_active()) {
        $result['skipped'] = 'disabled';
        return $result;
    }
    if (! wedding_booking_reminder_lock_acquire()) {
        $result['skipped'] = 'locked';
        return $result;
    }

    // Record the run up front: a sweep that dies half-way must not be
    // retried by the fallback on every page view.
    update_option('wedding_booking_reminder_last_sweep', $result);

    $limit = max(1, (int) apply_filters('wedding_booking_balance_reminder_batch_size', 25));
    $now   = time();
    try {
        foreach (wedding_booking_balance_reminder_candidates() as $order_id) {
            $order = wc_get_order($order_id);
            if (! $order) {
                continue;
            }
            $result['checked']++;

            $next = wedding_booking_balance_reminder_next($order, $now);
            if ($next['ts'] < 1 || $next['ts'] > $now) {
                continue;
            }

            if (wedding_booking_send_balance_reminder_email($order_id, false, $next['type'])) {
                $result['sent']++;
            } else {
                // Back off instead of failing again every hour.
                $result['failed']++;
                $order->update_meta_data('_wedding_booking_reminder_fail_ts', $now);
                $order->save();
            }

            // The rest go out on the next sweep.
            if ($result['sent'] + $result['failed'] >= $limit) {
                break;
            }
        }
    } finally {
        wedding_booking_reminder_lock_release();
    }

    update_option('wedding_booking_reminder_last_sweep', $result);

    return $result;
}

/**
 * Main-order ids of bookings that still have a balance to pay.
 *
 * @return int[]
 */
function wedding_booking_balance_reminder_candidates()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom bookings table, no user input.
    $ids = $wpdb->get_col("SELECT DISTINCT order_id FROM {$wpdb->prefix}wedding_booking_bookings WHERE order_id > 0 AND status NOT IN ('cancelled', 'completed') AND total - deposit > 0.01 ORDER BY order_id ASC");

    return array_map('intval', (array) $ids);
}

/**
 * The balance order a reminder would chase, or null when there's nothing to
 * chase: the balance is paid, on hold or cancelled, or the booking's own
 * order is no longer active. "Awaiting payment" = WooCommerce's needs_payment()
 * (pending or failed), i.e. exactly when the pay link in the email works.
 */
function wedding_booking_balance_reminder_due_order($parent_order)
{
    if (! $parent_order || (int) $parent_order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return null;
    }
    if (! in_array($parent_order->get_status(), ['processing', 'completed'], true)) {
        return null;
    }

    $due = wedding_booking_get_balance_order_for($parent_order, false);

    return ($due && $due->needs_payment()) ? $due : null;
}

/**
 * Start of the photoshoot day in the site's timezone, or null without a date.
 */
function wedding_booking_balance_reminder_shoot_day($order)
{
    $date = trim((string) wedding_booking_get_order_booking_meta($order)['session_date']);
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    try {
        return new DateTimeImmutable($date . ' 00:00:00', wp_timezone());
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Reminders sent before 1.4.0 only stored a site-time MySQL date.
 */
function wedding_booking_balance_reminder_legacy_ts($order)
{
    $legacy = (string) $order->get_meta('_wedding_booking_last_balance_reminder_sent', true);
    if ($legacy === '') {
        return 0;
    }

    try {
        return (new DateTimeImmutable($legacy, wp_timezone()))->getTimestamp();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * When the customer last heard about the balance: the latest reminder
 * (automatic or sent by hand), else when the deposit was paid, since the
 * booking confirmation already carries the pay link.
 */
function wedding_booking_balance_reminder_last_contact($order)
{
    $ts = (int) $order->get_meta('_wedding_booking_reminder_last_ts', true);
    if ($ts < 1) {
        $ts = wedding_booking_balance_reminder_legacy_ts($order);
    }
    $paid = $order->get_date_paid() ?: $order->get_date_created();

    return max($ts, $paid ? (int) $paid->getTimestamp() : 0);
}

/**
 * Move a time into the daytime window reminders go out in (09:00–21:00 site
 * time; filter wedding_booking_balance_reminder_window) so no one is emailed at 3 a.m.
 */
function wedding_booking_balance_reminder_in_window($ts)
{
    $window = (array) apply_filters('wedding_booking_balance_reminder_window', [9, 21]);
    $start  = max(0, min(23, (int) ($window[0] ?? 9)));
    $end    = max(1, min(24, (int) ($window[1] ?? 21)));
    if ($start >= $end) {
        return (int) $ts;
    }

    $local = (new DateTimeImmutable('@' . (int) $ts))->setTimezone(wp_timezone());
    $hour  = (int) $local->format('G');
    if ($hour < $start) {
        return $local->setTime($start, 0)->getTimestamp();
    }
    if ($hour >= $end) {
        return $local->modify('+1 day')->setTime($start, 0)->getTimestamp();
    }

    return (int) $ts;
}

/**
 * The next automatic reminder for a booking under the current settings:
 * ['ts' => unix time, 'type' => 'before'|'repeat']. ts 0 = none; a ts in the
 * past means it's due now. Read-only, so the admin screens use it too.
 */
function wedding_booking_balance_reminder_next($order, $now = 0)
{
    $none = ['ts' => 0, 'type' => ''];
    $now  = $now > 0 ? (int) $now : time();
    $cfg  = wedding_booking_get_balance_reminder_settings();
    if ((! $cfg['before_enable'] && ! $cfg['repeat_enable']) || ! wedding_booking_balance_reminder_due_order($order)) {
        return $none;
    }

    // Never two reminders within 12 hours (the deposit confirmation counts,
    // since it carries the pay link), and wait 6 hours after a failed send.
    $last     = wedding_booking_balance_reminder_last_contact($order);
    $earliest = $last + (int) apply_filters('wedding_booking_balance_reminder_min_gap', 12 * HOUR_IN_SECONDS);
    $failed   = (int) $order->get_meta('_wedding_booking_reminder_fail_ts', true);
    if ($failed > 0) {
        $earliest = max($earliest, $failed + 6 * HOUR_IN_SECONDS);
    }

    $shoot     = wedding_booking_balance_reminder_shoot_day($order);
    $shoot_end = $shoot ? $shoot->setTime(23, 59, 59)->getTimestamp() : 0;
    $options   = [];

    // One reminder N days before the shoot, while the shoot is still ahead.
    if ($cfg['before_enable'] && $shoot && $shoot_end >= $now) {
        $send_at = $shoot->modify('-' . $cfg['days_before'] . ' days')->setTime($cfg['hour'], 0)->getTimestamp();
        $sent    = (int) $order->get_meta('_wedding_booking_reminder_before_sent', true) > 0;
        // The old scheduler's single automatic reminder counts as this one
        // when it went out inside the window.
        if (! $sent && (int) $order->get_meta('_wedding_booking_reminder_last_ts', true) < 1 && $order->get_meta('_wedding_booking_last_balance_reminder_mode', true) === 'automatic') {
            $sent = wedding_booking_balance_reminder_legacy_ts($order) >= $send_at;
        }
        if (! $sent) {
            $ts = wedding_booking_balance_reminder_in_window(max($send_at, $earliest));
            if ($ts <= $shoot_end) {
                $options[] = ['ts' => $ts, 'type' => 'before'];
            }
        }
    }

    // Every N days until paid, counted from the last reminder or the deposit.
    // repeat_since keeps a freshly enabled schedule from emailing every old
    // booking at once.
    $repeat_count = (int) $order->get_meta('_wedding_booking_reminder_repeat_count', true);
    if ($cfg['repeat_enable'] && ($cfg['repeat_max'] < 1 || $repeat_count < $cfg['repeat_max'])) {
        $anchor = max($last, $cfg['repeat_since']);
        $ts     = wedding_booking_balance_reminder_in_window(max($anchor + $cfg['repeat_days'] * DAY_IN_SECONDS, $earliest));
        // "Stop once the shoot has passed": nothing once the day is over, and
        // nothing that would only go out after it.
        if (! ($cfg['repeat_stop_after_shoot'] && $shoot && max($ts, $now) > $shoot_end)) {
            $options[] = ['ts' => $ts, 'type' => 'repeat'];
        }
    }

    if (! $options) {
        return $none;
    }

    // Earliest wins; on a tie, the before-the-shoot one, so it's marked sent.
    usort($options, static function ($a, $b) {
        return ($a['ts'] <=> $b['ts']) ?: strcmp($a['type'], $b['type']);
    });

    return $options[0];
}

/**
 * Snapshot for the settings screen: the last sweep, WP-Cron health, and the
 * bookings with an unpaid balance.
 */
function wedding_booking_balance_reminder_status()
{
    $now         = time();
    $outstanding = 0;
    $scheduled   = 0;
    $upcoming    = null;

    foreach (wedding_booking_balance_reminder_candidates() as $order_id) {
        $order = wc_get_order($order_id);
        if (! $order || ! wedding_booking_balance_reminder_due_order($order)) {
            continue;
        }
        $outstanding++;

        $next = wedding_booking_balance_reminder_next($order, $now);
        if ($next['ts'] < 1) {
            continue;
        }
        $scheduled++;
        if (! $upcoming || $next['ts'] < $upcoming['ts']) {
            $upcoming = $next + [
                'order_id' => (int) $order_id,
                'name'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            ];
        }
    }

    return [
        'last'          => wedding_booking_reminder_last_sweep(),
        'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'next_sweep'    => (int) wp_next_scheduled('wedding_booking_balance_reminder_sweep'),
        'outstanding'   => $outstanding,
        'scheduled'     => $scheduled,
        'upcoming'      => $upcoming,
    ];
}

/**
 * Email the customer a reminder to pay their remaining balance.
 *
 * @param int    $parent_order_id The booking's main (deposit) order.
 * @param bool   $manual          Sent by the studio from the bookings screen.
 * @param string $type            Automatic schedule that sent it: 'before' | 'repeat'.
 */
function wedding_booking_send_balance_reminder_email($parent_order_id, $manual = false, $type = '')
{
    $parent_order = wc_get_order((int) $parent_order_id);
    if (! $parent_order) {
        return false;
    }

    $due_order_id = (int) $parent_order->get_meta('_wedding_booking_due_order_id', true);
    if ($due_order_id < 1) {
        return false;
    }

    $due_order = wc_get_order($due_order_id);
    // Only while the pay link actually works (pending/failed); not once the
    // balance is paid, on hold, cancelled or refunded.
    if (! $due_order || ! $due_order->needs_payment()) {
        return false;
    }

    $to_email = sanitize_email($parent_order->get_billing_email());
    if (empty($to_email)) {
        return false;
    }

    $name = trim($parent_order->get_billing_first_name() . ' ' . $parent_order->get_billing_last_name());
    if ($name === '') {
        $name = __('Customer', 'wedding-booking');
    }

    $reminder_cfg = wedding_booking_get_balance_reminder_settings();
    $subject      = $reminder_cfg['subject'];
    $template     = $reminder_cfg['template'];

    $currency = $parent_order->get_currency();
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : wedding_booking_get_currency_symbol();
    // WooCommerce returns the symbol as an HTML entity (e.g. &#2547;); decode
    // it so escaping the amount doesn't leave the entity visible as text.
    $symbol = html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8');
    $balance_amount = $symbol . number_format((float) $due_order->get_total(), 2);
    $package_name = (string) $due_order->get_meta('_wedding_booking_package_name', true);
    $session_date = (string) $due_order->get_meta('_wedding_booking_session_date', true);
    if ($session_date === '') {
        $session_date = (string) $parent_order->get_meta('_wedding_booking_billing_event_date', true);
    }
    // Same "September 25, 2026" form as the booking confirmation.
    $session_date = wedding_booking_email_pretty_date($session_date);
    // Balance orders copy the package and date across but not the add-ons,
    // so those come from the parent order's booking line item.
    $addons = (string) wedding_booking_get_order_booking_meta($parent_order)['addons'];

    $pay_link = $due_order->get_checkout_payment_url();
    $due_by   = (string) $due_order->get_meta('_wedding_booking_balance_due_date', true);
    if ($due_by === '') {
        $due_by = (string) $parent_order->get_meta('_wedding_booking_balance_due_date', true);
    }
    $due_by = $due_by !== '' ? wedding_booking_email_pretty_date($due_by) : '';
    // One map for the subject and the message, so every placeholder works in both.
    $placeholders = [
        '{balance_due_date}' => $due_by,
        '{customer_name}' => $name,
        '{balance_amount}' => $balance_amount,
        '{session_date}' => $session_date ?: __('N/A', 'wedding-booking'),
        '{package_name}' => $package_name,
        '{addons}' => $addons !== '' ? $addons : __('None', 'wedding-booking'),
        '{pay_link}' => $pay_link,
        '{order_id}' => '#' . $parent_order->get_order_number(),
    ];
    $subject = strtr((string) $subject, $placeholders);
    $message = strtr((string) $template, $placeholders);

    // Branded shell: the admin's wording, then the booking facts and a single
    // obvious way to pay.
    $content  = wedding_booking_email_pill(__('Payment due', 'wedding-booking'), 'primary');
    $content .= wedding_booking_email_title(__('Your booking balance', 'wedding-booking'));
    $content .= wedding_booking_email_text(nl2br(wp_kses_post($message)), true);

    $facts = wedding_booking_email_facts([
        ['label' => __('Package', 'wedding-booking'), 'value' => $package_name],
        ['label' => __('Add-ons', 'wedding-booking'), 'value' => $addons],
        ['label' => __('Date', 'wedding-booking'), 'value' => $session_date, 'strong' => true],
        ['label' => __('Balance due by', 'wedding-booking'), 'value' => $due_by],
        ['label' => __('Booking reference', 'wedding-booking'), 'value' => '#' . $parent_order->get_order_number()],
    ]);
    if ($facts !== '') {
        $content .= wedding_booking_email_divider(22) . wedding_booking_email_section_label(__('Your session', 'wedding-booking')) . $facts;
    }

    $content .= '<div style="margin:26px 0 0;">' . wedding_booking_email_callout([
        'title'        => __('Remaining balance', 'wedding-booking'),
        'amount'       => $balance_amount,
        'text'         => __('Settle it any time using the button below — it opens a secure payment page.', 'wedding-booking'),
        'button_url'   => $pay_link,
        'button_label' => __('Pay Remaining Balance', 'wedding-booking'),
        'note'         => esc_html__('Or copy and paste this link into your browser:', 'wedding-booking')
            . '<br><a href="' . esc_url($pay_link) . '" style="color:' . esc_attr(wedding_booking_email_palette()['accent_dk']) . ';">' . esc_html($pay_link) . '</a>',
    ]) . '</div>';

    $sent = wedding_booking_email_send($to_email, $subject, $content, [
        'eyebrow'   => __('Payment reminder', 'wedding-booking'),
        /* translators: %s: outstanding balance */
        'preheader' => sprintf(__('%s is still due for your booking.', 'wedding-booking'), $balance_amount),
    ]);
    if ($sent) {
        $now = time();
        $parent_order->update_meta_data('_wedding_booking_last_balance_reminder_sent', current_time('mysql'));
        $parent_order->update_meta_data('_wedding_booking_last_balance_reminder_mode', $manual ? 'manual' : 'automatic');
        // Unix time of the latest reminder of any kind; the automatic
        // schedules count their gaps from it (wedding_booking_balance_reminder_next()).
        $parent_order->update_meta_data('_wedding_booking_reminder_last_ts', $now);
        $parent_order->update_meta_data('_wedding_booking_reminder_sent_count', (int) $parent_order->get_meta('_wedding_booking_reminder_sent_count', true) + 1);
        $parent_order->delete_meta_data('_wedding_booking_reminder_fail_ts');
        if ($type === 'before') {
            $parent_order->update_meta_data('_wedding_booking_reminder_before_sent', $now);
        } elseif ($type === 'repeat') {
            $parent_order->update_meta_data('_wedding_booking_reminder_repeat_count', (int) $parent_order->get_meta('_wedding_booking_reminder_repeat_count', true) + 1);
        }

        if ($manual) {
            $how = __('sent manually', 'wedding-booking');
        } elseif ($type === 'before') {
            $how = __('automatic, before the photoshoot', 'wedding-booking');
        } else {
            $how = __('automatic, repeating until paid', 'wedding-booking');
        }
        /* translators: 1: outstanding balance, 2: customer email, 3: how it was sent */
        $parent_order->add_order_note(sprintf(__('Balance reminder for %1$s emailed to %2$s (%3$s).', 'wedding-booking'), $balance_amount, $to_email, $how));
        $parent_order->save();
    }

    return (bool) $sent;
}

/**
 * Whether an order is a Wedding Booking booking order (created by the plugin or
 * carrying the hidden booking product).
 */
function wedding_booking_is_booking_order($order)
{
    if (! $order) {
        return false;
    }
    if ($order->get_created_via() === 'wedding_booking') {
        return true;
    }
    $wedding_booking_product = (int) get_option('wedding_booking_wc_product_id', 0);
    if ($wedding_booking_product < 1) {
        return false;
    }
    foreach ($order->get_items() as $item) {
        if ((int) $item->get_product_id() === $wedding_booking_product) {
            return true;
        }
    }
    return false;
}

/**
 * Return the balance (remaining-payment) order for a parent booking order,
 * optionally creating it on demand from the booking line item. This lets the
 * "pay the rest" link appear in the very first confirmation email even if the
 * payment-complete hook that normally creates it hasn't run yet.
 *
 * @return WC_Order|null
 */
function wedding_booking_get_balance_order_for($parent_order, $create = false)
{
    if (! $parent_order || ! function_exists('wc_get_order')) {
        return null;
    }
    if ((int) $parent_order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return null; // never operate on a balance order itself
    }

    $due_order_id = (int) $parent_order->get_meta('_wedding_booking_due_order_id', true);
    if ($due_order_id < 1) {
        // The caller's copy of the order may predate _wedding_booking_due_order_id.
        $due_order_id = wedding_booking_find_balance_order_id($parent_order->get_id());
    }
    if ($due_order_id > 0) {
        $due_order = wc_get_order($due_order_id);
        if ($due_order) {
            return $due_order;
        }
    }

    if (! $create) {
        return null;
    }

    $wedding_booking_product = (int) get_option('wedding_booking_wc_product_id', 0);
    foreach ($parent_order->get_items() as $item) {
        if ($wedding_booking_product > 0 && (int) $item->get_product_id() !== $wedding_booking_product) {
            continue;
        }
        $total   = (float) $item->get_meta('_wedding_booking_total');
        $deposit = (float) $item->get_meta('_wedding_booking_deposit');
        $balance = (float) $item->get_meta('_wedding_booking_balance_due');
        if ($balance <= 0) {
            $balance = max(0, round($total - $deposit, 2));
        }
        if ($balance > 0.01) {
            $due_order_id = wedding_booking_create_balance_order($parent_order, $item, $balance);
            if ($due_order_id > 0) {
                return wc_get_order($due_order_id);
            }
        }
        break;
    }

    return null;
}

/* ═══════════════════════════════════════════════════════════════
   Show the booked add-ons under the product name in order item
   tables — order emails (incl. the customer invoice email),
   order-received / My Account pages, and PDF-invoice plugins that
   render item meta through wc_display_item_meta().
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_display_item_meta', 'wedding_booking_display_addons_item_meta', 10, 3);
function wedding_booking_display_addons_item_meta($html, $item, $args)
{
    if (! is_a($item, 'WC_Order_Item_Product')) {
        return $html;
    }
    $addons = (string) $item->get_meta('_wedding_booking_addons_label', true);
    if ($addons === '') {
        return $html;
    }

    $before       = (string) ($args['before'] ?? '');
    $after        = (string) ($args['after'] ?? '');
    $separator    = (string) ($args['separator'] ?? '');
    $label_before = (string) ($args['label_before'] ?? '');
    $label_after  = (string) ($args['label_after'] ?? '');

    $value = ! empty($args['autop']) ? wpautop(esc_html($addons)) : esc_html($addons);
    $entry = $label_before . esc_html__('Add-ons', 'wedding-booking') . $label_after . $value;

    if ($html === '') {
        return $before . $entry . $after;
    }
    // Append as one more meta row inside the existing list wrapper.
    if ($after !== '' && substr($html, -strlen($after)) === $after) {
        return substr($html, 0, -strlen($after)) . $separator . $entry . $after;
    }
    return $html . $separator . $entry;
}

/* ═══════════════════════════════════════════════════════════════
   Deposit breakdown in the order totals table.

   A 50% booking is stored as a WooCommerce order worth only the
   deposit, so on its own the totals table reads "Total: <deposit>"
   and the customer never sees what the session actually costs.
   These rows spell it out: full booking price, what was paid now,
   and what is still owed.

   Hooking woocommerce_get_order_item_totals covers every surface
   that renders order totals at once — Wedding Booking's own email template,
   WooCommerce's default emails, the order-received page, My Account
   and any invoice plugin that calls get_order_item_totals().
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_get_order_item_totals', 'wedding_booking_order_totals_deposit_rows', 10, 2);
function wedding_booking_order_totals_deposit_rows($total_rows, $order)
{
    if (! $order || ! is_a($order, 'WC_Order') || ! function_exists('wc_price')) {
        return $total_rows;
    }
    if (! wedding_booking_is_booking_order($order)) {
        return $total_rows;
    }

    $args    = ['currency' => $order->get_currency()];
    $is_bal  = (int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1;
    $is_paid = $order->is_paid();

    if ($is_bal) {
        // The balance invoice: show it as the second half of a booking.
        $parent = wc_get_order((int) $order->get_meta('_wedding_booking_parent_order_id', true));
        if (! $parent) {
            return $total_rows;
        }
        $figures = wedding_booking_get_booking_figures($parent);
        if ($figures === null) {
            return $total_rows;
        }

        $before = [
            'wedding_booking_booking_total' => [
                'label' => __('Booking total:', 'wedding-booking'),
                'value' => wc_price($figures['total'], $args),
            ],
            'wedding_booking_deposit_paid'  => [
                'label' => sprintf(
                    /* translators: %s: deposit percentage, e.g. 50 */
                    __('Deposit already paid (%s%%):', 'wedding-booking'),
                    wedding_booking_format_pct($figures['pct'])
                ),
                'value' => wc_price($figures['deposit'], $args),
            ],
        ];
        $relabel = $is_paid
            ? __('Balance paid:', 'wedding-booking')
            : __('Balance due now:', 'wedding-booking');

        return wedding_booking_splice_total_rows($total_rows, $before, $relabel, [], $order);
    }

    $figures = wedding_booking_get_booking_figures($order);
    if ($figures === null || $figures['balance'] <= 0.01) {
        return $total_rows; // paid in full — WooCommerce's own total is correct
    }

    $before = [
        'wedding_booking_booking_total' => [
            'label' => __('Booking total:', 'wedding-booking'),
            'value' => wc_price($figures['total'], $args),
        ],
    ];
    $relabel = sprintf(
        /* translators: %s: deposit percentage, e.g. 50 */
        $is_paid ? __('Paid now (%s%% deposit):', 'wedding-booking') : __('Due now (%s%% deposit):', 'wedding-booking'),
        wedding_booking_format_pct($figures['pct'])
    );
    $after = [
        'wedding_booking_balance_due' => [
            'label' => wedding_booking_booking_balance_paid($order) ? __('Balance paid:', 'wedding-booking') : __('Remaining balance:', 'wedding-booking'),
            'value' => wc_price($figures['balance'], $args),
        ],
    ];

    return wedding_booking_splice_total_rows($total_rows, $before, $relabel, $after, $order);
}

/**
 * Whether a deposit booking's remaining balance has been paid, i.e. its
 * balance order is processing or completed.
 */
function wedding_booking_booking_balance_paid($order)
{
    $due = wedding_booking_get_balance_order_for($order, false);

    return $due ? $due->is_paid() : false;
}

/**
 * Booking money figures from the order's booking line item.
 * Returns null when the order carries no Wedding Booking pricing meta.
 *
 * @return array{total:float,deposit:float,balance:float,pct:float}|null
 */
function wedding_booking_get_booking_figures($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return null;
    }

    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    foreach ($order->get_items() as $item) {
        if ($product_id && (int) $item->get_product_id() !== $product_id) {
            continue;
        }
        // _wedding_booking_total is fee-inclusive: package + add-ons + any payment fee.
        $total = (float) $item->get_meta('_wedding_booking_total');
        if ($total <= 0) {
            return null;
        }
        $deposit = (float) $item->get_meta('_wedding_booking_deposit');
        $balance = (float) $item->get_meta('_wedding_booking_balance_due');
        if ($balance <= 0) {
            $balance = max(0, round($total - $deposit, 2));
        }
        $pct = (float) $item->get_meta('_wedding_booking_deposit_pct');

        return [
            'total'   => $total,
            'deposit' => $deposit,
            'balance' => $balance,
            'pct'     => $pct > 0 ? $pct : 100,
        ];
    }

    return null;
}

/** "50" rather than "50.00" for deposit percentages. */
function wedding_booking_format_pct($pct)
{
    return rtrim(rtrim(number_format((float) $pct, 2, '.', ''), '0'), '.');
}

/**
 * Rebuild a totals array with rows inserted around WooCommerce's own
 * order_total row, which is relabelled to say what it actually covers.
 * WooCommerce's subtotal row is dropped when it merely repeats the total,
 * so the breakdown reads as one clean sequence.
 */
function wedding_booking_splice_total_rows($total_rows, $before, $relabel, $after, $order)
{
    $drop_subtotal = abs((float) $order->get_subtotal() - (float) $order->get_total()) < 0.01;

    $out = [];
    foreach ((array) $total_rows as $key => $row) {
        if ($key === 'cart_subtotal' && $drop_subtotal) {
            continue;
        }
        if ($key === 'order_total') {
            foreach ($before as $bk => $brow) {
                $out[$bk] = $brow;
            }
            $row['label'] = $relabel;
            $out[$key]    = $row;
            foreach ($after as $ak => $arow) {
                $out[$ak] = $arow;
            }
            continue;
        }
        $out[$key] = $row;
    }

    // No order_total row (unusual) — append rather than lose the breakdown.
    if (! isset($out['order_total'])) {
        $out = array_merge($out, $before, $after);
    }

    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   Include the remaining-balance payment link in the customer's
   first booking email (order confirmation), not just later reminders.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_email_after_order_table', 'wedding_booking_email_append_balance_link', 15, 4);
function wedding_booking_email_append_balance_link($order, $sent_to_admin = false, $plain_text = false, $email = null)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return;
    }
    // Customer emails only, and only once the deposit itself has been paid —
    // no point offering the balance link before the first payment lands.
    if ($sent_to_admin) {
        return;
    }
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return;
    }
    if (! wedding_booking_is_booking_order($order) || ! $order->is_paid()) {
        return;
    }

    $balance_order = wedding_booking_get_balance_order_for($order, true);
    if (! $balance_order) {
        return;
    }
    if (in_array($balance_order->get_status(), ['processing', 'completed', 'cancelled', 'refunded'], true)) {
        return; // balance already settled or void
    }

    $pay_url = $balance_order->get_checkout_payment_url();
    $currency = $order->get_currency();
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency) : wedding_booking_get_currency_symbol();
    // Decode the entity form (e.g. &#2547;) so esc_html() below doesn't
    // double-escape it into visible markup.
    $symbol = html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8');
    $balance_amount = $symbol . number_format((float) $balance_order->get_total(), 2);

    $session_date = (string) $balance_order->get_meta('_wedding_booking_session_date', true);
    if ($session_date === '') {
        $session_date = (string) $order->get_meta('_wedding_booking_billing_event_date', true);
    }

    if ($plain_text) {
        echo "\n----------------------------------------\n\n";
        echo esc_html__('Remaining balance to pay', 'wedding-booking') . ': ' . esc_html($balance_amount) . "\n";
        if ($session_date !== '') {
            /* translators: %s: session date */
            echo esc_html(sprintf(__('For your session on %s.', 'wedding-booking'), $session_date)) . "\n";
        }
        echo esc_html__('Pay the remaining balance here:', 'wedding-booking') . ' ' . esc_url_raw($pay_url) . "\n";
        return;
    }

    if ($session_date !== '') {
        /* translators: 1: balance amount, 2: session date */
        $intro = sprintf(__('A balance of %1$s is still due for your session on %2$s. You can settle it any time using the button below.', 'wedding-booking'), $balance_amount, wedding_booking_email_pretty_date($session_date));
    } else {
        /* translators: %s: balance amount */
        $intro = sprintf(__('A balance of %s is still due for your booking. You can settle it any time using the button below.', 'wedding-booking'), $balance_amount);
    }
    $due_by = (string) $order->get_meta('_wedding_booking_balance_due_date', true);
    if ($due_by !== '') {
        /* translators: %s: date the balance is due */
        $intro .= ' ' . sprintf(__('Please pay it by %s.', 'wedding-booking'), wedding_booking_email_pretty_date($due_by));
    }

    // Self-contained inline styles — email clients don't load the plugin CSS.
    $palette = wedding_booking_email_palette();
    $note    = esc_html__('Or copy and paste this link into your browser:', 'wedding-booking')
        . '<br><a href="' . esc_url($pay_url) . '" style="color:' . esc_attr($palette['accent_dk']) . ';">' . esc_html($pay_url) . '</a>';

    // Spacing lives here rather than in the component, so the callout sits
    // correctly whether it follows Wedding Booking's order table or WooCommerce's.
    echo '<div style="margin:26px 0 0;">';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The component escapes its own data.
    echo wedding_booking_email_callout([
        'title'        => __('Pay your remaining balance', 'wedding-booking'),
        'text'         => $intro,
        'amount'       => $balance_amount,
        'button_url'   => $pay_url,
        'button_label' => __('Pay Remaining Balance', 'wedding-booking'),
        'note'         => $note,
    ]);
    echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   Order confirmation email extras — an editable message block and
   a file attachment (e.g. a Terms of Service PDF), both managed in
   Wedding Booking → Settings → Emails → Customer booking confirmation.
═══════════════════════════════════════════════════════════════ */

/**
 * The customer-facing confirmation emails that carry the message block and
 * the attachment. Admin notifications and the balance invoice are excluded
 * on purpose, so the terms go out once, with the booking confirmation.
 */
function wedding_booking_order_confirmation_email_ids()
{
    return (array) apply_filters('wedding_booking_order_confirmation_email_ids', [
        'customer_processing_order',
        'customer_completed_order',
        'customer_on_hold_order',
    ]);
}

/**
 * Booking details for an order. The main order keeps them on the booking
 * line item; balance orders carry copies as order-level meta.
 */
function wedding_booking_get_order_booking_meta($order)
{
    $out = ['session_type' => '', 'package_name' => '', 'session_date' => '', 'addons' => ''];
    if (! $order || ! is_a($order, 'WC_Order')) {
        return $out;
    }

    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    foreach ($order->get_items() as $item) {
        if ($product_id && (int) $item->get_product_id() !== $product_id) {
            continue;
        }
        $out['session_type'] = (string) $item->get_meta('_wedding_booking_session_type');
        $out['package_name'] = (string) $item->get_meta('_wedding_booking_package_name');
        $out['session_date'] = (string) $item->get_meta('_wedding_booking_session_date');
        // Comma-separated add-on names, as chosen on the booking form.
        $out['addons']       = (string) $item->get_meta('_wedding_booking_addons_label');
        break;
    }

    foreach (['package_name', 'session_date'] as $key) {
        if ($out[$key] === '') {
            $out[$key] = (string) $order->get_meta('_wedding_booking_' . $key, true);
        }
    }
    if ($out['session_date'] === '') {
        $out['session_date'] = (string) $order->get_meta('_wedding_booking_billing_event_date', true);
    }

    return $out;
}

/**
 * {placeholder} => value map for the editable order email message.
 */
function wedding_booking_order_email_placeholders($order)
{
    $meta   = wedding_booking_get_order_booking_meta($order);
    $symbol = function_exists('get_woocommerce_currency_symbol')
        ? get_woocommerce_currency_symbol($order->get_currency())
        : wedding_booking_get_currency_symbol();
    // WooCommerce returns the symbol as an HTML entity (e.g. &#2547;).
    // Decode it to a real character so the value is correct in both the
    // HTML block and the plain-text email, and survives escaping.
    $symbol = html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8');

    $first = (string) $order->get_billing_first_name();
    $full  = trim($first . ' ' . $order->get_billing_last_name());

    return [
        '{customer_name}' => $full !== '' ? $full : __('there', 'wedding-booking'),
        '{first_name}'    => $first !== '' ? $first : __('there', 'wedding-booking'),
        '{session_type}'  => $meta['session_type'],
        '{package_name}'  => $meta['package_name'],
        '{session_date}'  => $meta['session_date'] !== '' ? $meta['session_date'] : __('N/A', 'wedding-booking'),
        // Reads as a sentence when the customer booked nothing extra, so a
        // template like "Add-ons: {addons}" never trails off blank.
        '{addons}'        => $meta['addons'] !== '' ? $meta['addons'] : __('None', 'wedding-booking'),
        '{order_id}'      => '#' . $order->get_order_number(),
        '{total}'         => $symbol . number_format((float) $order->get_total(), 2),
        '{site_name}'     => get_bloginfo('name'),
    ];
}

/**
 * Whether the admin's custom email replaces WooCommerce's default body for
 * this order. Confirmation emails for real bookings only — never the
 * balance invoice, and never admin notifications.
 */
function wedding_booking_order_email_applies($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return false;
    }
    if (! function_exists('wedding_booking_get_order_email_settings')) {
        return false;
    }
    $settings = wedding_booking_get_order_email_settings();
    if ((int) $settings['enable'] !== 1 || trim(wp_strip_all_tags($settings['message'])) === '') {
        return false;
    }
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return false;
    }
    return (bool) wedding_booking_is_booking_order($order);
}

/**
 * The admin's message with {placeholders} resolved, as email-ready HTML.
 * Content is stored through wp_kses_post, so it is safe rich text; wpautop
 * only runs when the editor left plain line breaks behind.
 */
function wedding_booking_order_email_body_html($order)
{
    $settings = wedding_booking_get_order_email_settings();
    $message  = strtr((string) $settings['message'], wedding_booking_order_email_placeholders($order));
    if (strpos($message, '<p') === false && strpos($message, '<div') === false) {
        $message = wpautop($message);
    }
    return wp_kses_post($message);
}

/**
 * Plain-text counterpart. Not escaped: this is not an HTML context, and
 * escaping would turn "&" into "&amp;" in the customer's message.
 */
function wedding_booking_order_email_body_plain($order)
{
    $html = wedding_booking_order_email_body_html($order);
    $text = wp_strip_all_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html));
    return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
}

/**
 * Swap WooCommerce's customer confirmation templates for Wedding Booking's, so the
 * email contains only the admin's content instead of it plus WooCommerce's
 * hardcoded "we've received your order" copy.
 */
add_filter('wc_get_template', 'wedding_booking_override_customer_email_template', 10, 5);
function wedding_booking_override_customer_email_template($template, $template_name, $args = [], $template_path = '', $default_path = '')
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (wedding_booking_order_confirmation_email_ids() as $email_id) {
            $file = str_replace('_', '-', $email_id) . '.php';
            $map['emails/' . $file]       = 'emails/wedding-booking-order.php';
            $map['emails/plain/' . $file] = 'emails/plain/wedding-booking-order.php';
        }
    }

    if (! isset($map[$template_name]) || empty($args['order'])) {
        return $template;
    }
    if (! wedding_booking_order_email_applies($args['order'])) {
        return $template;
    }

    $custom = WEDDING_BOOKING_DIR . 'templates/' . $map[$template_name];
    return file_exists($custom) ? $custom : $template;
}

/**
 * Let the admin own the subject line and heading of the same emails.
 */
add_action('init', 'wedding_booking_register_order_email_subject_filters');
function wedding_booking_register_order_email_subject_filters()
{
    foreach (wedding_booking_order_confirmation_email_ids() as $email_id) {
        add_filter('woocommerce_email_subject_' . $email_id, 'wedding_booking_filter_order_email_subject', 10, 3);
        add_filter('woocommerce_email_heading_' . $email_id, 'wedding_booking_filter_order_email_heading', 10, 3);
    }
}

function wedding_booking_filter_order_email_subject($subject, $order = null, $email = null)
{
    if (! wedding_booking_order_email_applies($order)) {
        return $subject;
    }
    $custom = trim((string) wedding_booking_get_order_email_settings()['subject']);
    if ($custom === '') {
        return $subject;
    }
    return strtr($custom, wedding_booking_order_email_placeholders($order));
}

function wedding_booking_filter_order_email_heading($heading, $order = null, $email = null)
{
    if (! wedding_booking_order_email_applies($order)) {
        return $heading;
    }
    $custom = trim((string) wedding_booking_get_order_email_settings()['heading']);
    if ($custom === '') {
        return $heading;
    }
    return strtr($custom, wedding_booking_order_email_placeholders($order));
}

add_filter('woocommerce_email_attachments', 'wedding_booking_email_attach_order_file', 10, 4);
function wedding_booking_email_attach_order_file($attachments, $email_id = '', $object = null, $email = null)
{
    $attachments = (array) $attachments;

    if (! function_exists('wedding_booking_get_order_email_settings')) {
        return $attachments;
    }
    $attachment_id = (int) wedding_booking_get_order_email_settings()['attachment_id'];
    if ($attachment_id < 1) {
        return $attachments;
    }
    if (! in_array((string) $email_id, wedding_booking_order_confirmation_email_ids(), true)) {
        return $attachments;
    }
    if (! $object || ! is_a($object, 'WC_Order') || ! wedding_booking_is_booking_order($object)) {
        return $attachments;
    }
    if ((int) $object->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return $attachments;
    }

    // A deleted or unreadable media item must never break the email.
    $path = get_attached_file($attachment_id);
    if ($path && is_readable($path) && ! in_array($path, $attachments, true)) {
        $attachments[] = $path;
    }

    return $attachments;
}

/* ═══════════════════════════════════════════════════════════════
   Admin "New booking" notification — the same branded shell the
   customer gets, but with the full booking laid bare (payment
   breakdown, customer contact, a manage-order link). Replaces
   WooCommerce's plain New Order email for booking orders when the
   admin turns it on in Wedding Booking → Settings → Emails → New-booking alert.
═══════════════════════════════════════════════════════════════ */

/**
 * Whether Wedding Booking's branded admin email replaces WooCommerce's New Order
 * body for this order. Booking orders only — never the balance invoice.
 */
function wedding_booking_admin_order_email_applies($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return false;
    }
    if (! function_exists('wedding_booking_get_admin_email_settings')) {
        return false;
    }
    if ((int) wedding_booking_get_admin_email_settings()['enable'] !== 1) {
        return false;
    }
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return false;
    }
    return (bool) wedding_booking_is_booking_order($order);
}

/**
 * The admin's editable intro note with {placeholders} resolved, as
 * email-ready HTML. Shares the customer email's placeholder map.
 */
function wedding_booking_admin_email_intro_html($order)
{
    $intro = trim((string) wedding_booking_get_admin_email_settings()['intro']);
    if ($intro === '') {
        return '';
    }
    $intro = strtr($intro, wedding_booking_order_email_placeholders($order));
    if (strpos($intro, '<p') === false && strpos($intro, '<div') === false) {
        $intro = wpautop($intro);
    }
    return wp_kses_post($intro);
}

/**
 * The customer's note for this order, from the WooCommerce order comment with
 * the booking line item's stored copy as a fallback.
 */
function wedding_booking_admin_email_customer_note($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return '';
    }
    $note = trim((string) $order->get_customer_note());
    if ($note === '') {
        $note = trim((string) $order->get_meta('_wedding_booking_notes', true));
    }
    return $note;
}

/**
 * Swap WooCommerce's admin New Order template for Wedding Booking's branded one.
 */
add_filter('wc_get_template', 'wedding_booking_override_admin_email_template', 10, 5);
function wedding_booking_override_admin_email_template($template, $template_name, $args = [], $template_path = '', $default_path = '')
{
    $map = [
        'emails/admin-new-order.php'       => 'emails/wedding-booking-admin-order.php',
        'emails/plain/admin-new-order.php' => 'emails/plain/wedding-booking-admin-order.php',
    ];
    if (! isset($map[$template_name]) || empty($args['order'])) {
        return $template;
    }
    if (! wedding_booking_admin_order_email_applies($args['order'])) {
        return $template;
    }

    $custom = WEDDING_BOOKING_DIR . 'templates/' . $map[$template_name];
    return file_exists($custom) ? $custom : $template;
}

/**
 * Let the admin own the subject, heading and recipient of the New Order email.
 */
add_action('init', 'wedding_booking_register_admin_email_filters');
function wedding_booking_register_admin_email_filters()
{
    add_filter('woocommerce_email_subject_new_order', 'wedding_booking_filter_admin_email_subject', 10, 2);
    add_filter('woocommerce_email_heading_new_order', 'wedding_booking_filter_admin_email_heading', 10, 2);
    add_filter('woocommerce_email_recipient_new_order', 'wedding_booking_filter_admin_email_recipient', 10, 2);
}

function wedding_booking_filter_admin_email_subject($subject, $order = null)
{
    if (! wedding_booking_admin_order_email_applies($order)) {
        return $subject;
    }
    $custom = trim((string) wedding_booking_get_admin_email_settings()['subject']);
    if ($custom === '') {
        return $subject;
    }
    return strtr($custom, wedding_booking_order_email_placeholders($order));
}

function wedding_booking_filter_admin_email_heading($heading, $order = null)
{
    if (! wedding_booking_admin_order_email_applies($order)) {
        return $heading;
    }
    $custom = trim((string) wedding_booking_get_admin_email_settings()['heading']);
    if ($custom === '') {
        return $heading;
    }
    return strtr($custom, wedding_booking_order_email_placeholders($order));
}

function wedding_booking_filter_admin_email_recipient($recipient, $order = null)
{
    if (! wedding_booking_admin_order_email_applies($order)) {
        return $recipient;
    }
    $custom = trim((string) wedding_booking_get_admin_email_settings()['recipient']);
    return $custom !== '' ? $custom : $recipient;
}

/* ═══════════════════════════════════════════════════════════════
   Direct checkout — create the order straight from the booking
   form (Details step) and send the customer to the WooCommerce
   order-payment page, skipping the checkout form page entirely.
═══════════════════════════════════════════════════════════════ */
/**
 * admin-ajax.php requests don't load the frontend session/cart, so
 * session-dependent gateways (PayPal Payments, some card processors)
 * report themselves unavailable there. Boot the customer session and
 * cart before asking WooCommerce which gateways are available.
 */
function wedding_booking_ensure_wc_frontend_context()
{
    if (! function_exists('WC') || ! WC()) {
        return;
    }
    if (function_exists('wc_load_cart') && (null === WC()->cart || null === WC()->session)) {
        wc_load_cart();
    }
}

/**
 * Whether a gateway completes payment without any customer interaction
 * (no secure card fields, no external approval step). Only these may be
 * processed straight from the booking form; everything else — PayPal,
 * card processors, redirect gateways — must finish on the WooCommerce
 * payment page where the gateway renders its own fields/buttons.
 */
function wedding_booking_gateway_processes_offline($gateway)
{
    $offline = apply_filters('wedding_booking_offline_payment_gateways', ['bacs', 'cheque', 'cod']);
    return empty($gateway->has_fields) && in_array($gateway->id, $offline, true);
}

/**
 * Whether the gateway's order-pay page can be embedded in the booking
 * form's payment iframe. Gateways with on-site fields and the PayPal
 * Payments family (popup-based) work embedded; unknown redirect
 * gateways get a full-page redirect instead, since external processors
 * usually refuse to load inside frames.
 */
function wedding_booking_gateway_embeds_payment($gateway)
{
    if (wedding_booking_gateway_processes_offline($gateway)) {
        return false;
    }

    $embeddable = ! empty($gateway->has_fields) || strpos((string) $gateway->id, 'ppcp') === 0;
    return (bool) apply_filters('wedding_booking_embed_payment_gateway', $embeddable, $gateway);
}

/**
 * Cancel an unpaid booking order that was superseded because the customer
 * went back and changed the booking before paying. The order key acts as
 * the authorization token, so only the customer who created the order
 * (and holds its secret key) can cancel it.
 */
function wedding_booking_cancel_superseded_booking_order($order_id, $order_key)
{
    $order_id  = absint($order_id);
    $order_key = (string) $order_key;
    if ($order_id < 1 || $order_key === '') {
        return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    if (! hash_equals($order->get_order_key(), $order_key)) {
        return;
    }
    if ($order->get_created_via() !== 'wedding_booking') {
        return;
    }
    if ((int) $order->get_meta('_wedding_booking_is_balance_order', true) === 1) {
        return;
    }
    if (! in_array($order->get_status(), ['pending', 'failed'], true)) {
        return;
    }

    $order->update_status('cancelled', __('Superseded by a new booking attempt.', 'wedding-booking'));
}

/* ═══════════════════════════════════════════════════════════════
   WooCommerce PayPal Payments compatibility — the embedded pay page
   must always render the smart buttons / card fields, even when the
   merchant disabled the checkout button location in PayPal settings
   (the pay-order page reuses the "checkout" location internally).
═══════════════════════════════════════════════════════════════ */
add_filter('woocommerce_paypal_payments_selected_button_locations', 'wedding_booking_force_ppcp_buttons_in_embed');
function wedding_booking_force_ppcp_buttons_in_embed($locations)
{
    if (
        isset($_GET['wedding_booking_embed']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only tweak on the embedded pay page.
        && function_exists('is_wc_endpoint_url')
        && is_wc_endpoint_url('order-pay')
    ) {
        $locations = array_unique(array_merge((array) $locations, ['checkout']));
    }

    return $locations;
}

/* ═══════════════════════════════════════════════════════════════
   Chrome-less order-pay page for the booking form's payment iframe
═══════════════════════════════════════════════════════════════ */
add_filter('template_include', 'wedding_booking_embedded_pay_template', 99);
function wedding_booking_embedded_pay_template($template)
{
    if (
        isset($_GET['wedding_booking_embed']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only template switch; WooCommerce validates the order key.
        && function_exists('is_wc_endpoint_url')
        && is_wc_endpoint_url('order-pay')
    ) {
        $embed = WEDDING_BOOKING_DIR . 'templates/embed-pay.php';
        if (file_exists($embed)) {
            return $embed;
        }
    }

    return $template;
}

/**
 * Fresh order state for the in-form confirmation panel, shown after the
 * customer finishes paying inside the embedded payment frame. The order
 * key acts as the authorization token, like on the order-pay page.
 */
add_action('wp_ajax_wedding_booking_order_confirmation',        'wedding_booking_ajax_order_confirmation');
add_action('wp_ajax_nopriv_wedding_booking_order_confirmation', 'wedding_booking_ajax_order_confirmation');
function wedding_booking_ajax_order_confirmation()
{
    check_ajax_referer('wedding_booking_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_error();
    }

    $order_id  = absint(wp_unslash($_POST['order_id'] ?? 0));
    $order_key = sanitize_text_field(wp_unslash($_POST['order_key'] ?? ''));

    $order = $order_id > 0 ? wc_get_order($order_id) : false;
    if (! $order || $order_key === '' || ! hash_equals($order->get_order_key(), $order_key)) {
        wp_send_json_error();
    }
    if ($order->get_created_via() !== 'wedding_booking') {
        wp_send_json_error();
    }

    $status = $order->get_status();

    $instructions = '';
    if ($order->has_status('on-hold')) {
        ob_start();
        do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
        $instructions = wp_kses_post(trim((string) ob_get_clean()));
    }

    wp_send_json_success([
        'awaiting_payment'  => $order->has_status('on-hold'),
        'instructions_html' => $instructions,
        'order_id'          => (int) $order->get_id(),
        'order_number'      => (string) $order->get_order_number(),
        'status'            => $status,
        'status_label'      => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status,
        'due_now'           => (float) $order->get_total(),
        'currency'          => wedding_booking_get_currency_symbol(),
        'gateway_title'     => wp_strip_all_tags((string) $order->get_payment_method_title()),
        'payment_processed' => ! in_array($status, ['pending', 'failed', 'cancelled'], true),
        'client_email'      => (string) $order->get_billing_email(),
        'pay_url'           => $order->get_checkout_payment_url(),
        'received_url'      => $order->get_checkout_order_received_url(),
    ]);
}

add_action('wp_ajax_wedding_booking_place_order',        'wedding_booking_ajax_place_booking_order');
add_action('wp_ajax_nopriv_wedding_booking_place_order', 'wedding_booking_ajax_place_booking_order');
function wedding_booking_ajax_place_booking_order()
{
    check_ajax_referer('wedding_booking_nonce', 'nonce');

    if (! class_exists('WooCommerce')) {
        wp_send_json_error(['message' => __('WooCommerce is required for online checkout.', 'wedding-booking')]);
    }

    if ((int) get_option('wedding_booking_require_account_booking', 0) === 1 && ! is_user_logged_in()) {
        wp_send_json_error(['message' => __('Please log in or create an account before completing your booking.', 'wedding-booking'), 'code' => 'wedding_booking_login_required']);
    }

    $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    if (! $product_id || get_post_status($product_id) === false) {
        wedding_booking_create_wc_product();
        $product_id = (int) get_option('wedding_booking_wc_product_id', 0);
    }
    if (! $product_id) {
        wp_send_json_error(['message' => __('Booking product not configured. Please contact support.', 'wedding-booking')]);
    }

    $details = wedding_booking_sanitize_checkout_details(isset($_POST['details']) && is_array($_POST['details']) ? $_POST['details'] : []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    // Validate required fields against the admin-configured checkout form.
    $cf_fields = wedding_booking_get_checkout_form_fields();
    foreach ($cf_fields as $key => $f) {
        if (empty($f['enabled']) || empty($f['required'])) {
            continue;
        }
        if (! isset($details[$key]) || $details[$key] === '') {
            /* translators: %s: field label */
            wp_send_json_error(['message' => sprintf(__('%s is required.', 'wedding-booking'), $f['label'])]);
        }
    }
    foreach (wedding_booking_get_custom_checkout_fields() as $ckey => $cf) {
        if (empty($cf['required'])) {
            continue;
        }
        if (! isset($details['cf_' . $ckey]) || $details['cf_' . $ckey] === '') {
            /* translators: %s: field label */
            wp_send_json_error(['message' => sprintf(__('%s is required.', 'wedding-booking'), $cf['label'])]);
        }
    }
    if (! empty($details['email']) && ! is_email($details['email'])) {
        wp_send_json_error(['message' => __('Please enter a valid email address.', 'wedding-booking')]);
    }
    if (! empty($cf_fields['participants']['enabled']) && isset($details['participants']) && $details['participants'] !== '' && (int) $details['participants'] < 1) {
        wp_send_json_error(['message' => __('Participants must be at least 1.', 'wedding-booking')]);
    }

    $payment_method     = sanitize_key(wp_unslash($_POST['payment_method'] ?? ''));
    $previous_order_id  = absint(wp_unslash($_POST['previous_order_id'] ?? 0));
    $hold_token         = wedding_booking_clean_hold_token(sanitize_text_field(wp_unslash($_POST['hold_token'] ?? '')));
    // The customer's own earlier (superseded) order doesn't count against the
    // date — but only when they prove it's theirs with its order key.
    $previous_order = $previous_order_id > 0 ? wc_get_order($previous_order_id) : null;
    $prev_key       = sanitize_text_field(wp_unslash($_POST['previous_order_key'] ?? ''));
    $exclude_order  = ($previous_order && $prev_key !== '' && ! $previous_order->is_paid() && hash_equals($previous_order->get_order_key(), $prev_key)) ? $previous_order_id : 0;

    $session_date = sanitize_text_field(wp_unslash($_POST['session_date'] ?? ''));
    if ($session_date === '') {
        wp_send_json_error(['message' => __('Please choose a session date before checkout.', 'wedding-booking'), 'code' => 'wedding_booking_date']);
    }
    // The start time: the slot picked under the calendar when the studio
    // offers start times, else the free "Start time" detail field.
    $session_time = wedding_booking_slots_enabled()
        ? wedding_booking_normalize_time(sanitize_text_field(wp_unslash($_POST['session_time'] ?? '')))
        : (string) ($details['event_time'] ?? '');
    $date_ok = wedding_booking_validate_booking_date($session_date, $exclude_order, $session_time, $hold_token);
    if (is_wp_error($date_ok)) {
        wp_send_json_error(['message' => $date_ok->get_error_message(), 'code' => $date_ok->get_error_code()]);
    }

    // Terms: accepted in the Contract step, for the wording currently shown.
    $contract = wedding_booking_contract_from_post();
    if (is_wp_error($contract)) {
        wp_send_json_error(['message' => $contract->get_error_message(), 'code' => $contract->get_error_code()]);
    }

    // Price from the database, never from the browser.
    $quote = wedding_booking_quote_booking(wedding_booking_quote_args_from_post($payment_method));
    if (is_wp_error($quote)) {
        wp_send_json_error(['message' => $quote->get_error_message(), 'code' => $quote->get_error_code()]);
    }

    $booking = [
        'product_id'       => $product_id,
        'session_type'     => $quote['session_type'],
        'package_name'     => $quote['package_name'],
        'package_id'       => $quote['package_id'],
        'addon_ids'        => $quote['addon_ids'],
        'addons_label'     => $quote['addons_label'],
        'addons_total'     => $quote['addons_total'],
        'subtotal'         => $quote['subtotal'],
        'coupon_code'      => $quote['coupon_code'],
        'discount'         => $quote['discount'],
        'total'            => $quote['payable'],
        'fee_pct'          => $quote['fee_pct'],
        'fee_amount'       => $quote['fee_amount'],
        'deposit'          => $quote['due_now'],
        'deposit_pct'      => $quote['pay_pct'],
        'balance_due_date' => $quote['balance_due_date'],
        'session_date'     => $session_date,
        'session_time'     => $session_time,
        'hold_token'       => $hold_token,
        'contract'         => $contract,
        'currency'         => wedding_booking_get_currency_symbol(),
    ];

    // If the customer went back and changed the booking after an order was
    // already created, cancel that superseded order before creating the new one.
    $previous_order_key = sanitize_text_field(wp_unslash($_POST['previous_order_key'] ?? ''));
    if ($previous_order_id > 0 && $previous_order_key !== '') {
        wedding_booking_cancel_superseded_booking_order($previous_order_id, $previous_order_key);
    }

    $order = wedding_booking_create_booking_order($booking, $details, $payment_method);
    if (! $order) {
        wp_send_json_error(['message' => __('Could not create your booking order. Please try again.', 'wedding-booking')]);
    }

    // Try to complete payment right here so the customer never leaves the
    // booking form. Gateways without payment fields (bank transfer, cash on
    // delivery, cheque, …) can process immediately; gateways that render
    // their own secure card fields still need the WooCommerce payment page.
    $processed     = false;
    $redirect_url  = '';
    $embed_url     = '';
    $gateway_title = '';

    if ((float) $order->get_total() <= 0) {
        // Nothing to pay (a free package, or a 100% promo code): WooCommerce's
        // pay page refuses orders with no payment due, so confirm right here.
        $order->payment_complete();
        $order     = wc_get_order($order->get_id());
        $processed = true;
    } elseif ($payment_method !== '' && WC()->payment_gateways()) {
        wedding_booking_ensure_wc_frontend_context();
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($available[$payment_method])) {
            $gateway       = $available[$payment_method];
            $gateway_title = wp_strip_all_tags((string) $gateway->get_title());

            if (wedding_booking_gateway_processes_offline($gateway)) {
                try {
                    $result = $gateway->process_payment($order->get_id());
                    if (is_array($result) && ($result['result'] ?? '') === 'success') {
                        $processed = true;
                        $order     = wc_get_order($order->get_id()); // refresh status

                        $redirect = (string) ($result['redirect'] ?? '');
                        $received = $order->get_checkout_order_received_url();
                        if ($redirect !== '' && $redirect !== $received && strpos($redirect, 'order-received') === false) {
                            // Gateway hands off to an external processor after all —
                            // the customer has to finish payment there.
                            $processed    = false;
                            $redirect_url = $redirect;
                        }
                    }
                } catch (\Throwable $e) {
                    $processed = false; // fall through to in-place pending state with a pay link
                }
            } else {
                // Interactive gateway (PayPal, card fields, …): never call
                // process_payment() server-side — for PayPal it returns the
                // paypal.com approval link and skips the on-site card form.
                // The order-pay page renders the gateway's own secure
                // fields/buttons exactly like the checkout page does.
                $redirect_url = $order->get_checkout_payment_url();
                if (wedding_booking_gateway_embeds_payment($gateway)) {
                    $embed_url = add_query_arg('wedding_booking_embed', '1', $redirect_url);
                }
            }
        }
    } elseif (! $processed && $payment_method === '') {
        // No method chosen up front — the embedded pay page presents the
        // gateway list natively (icons, expanding card fields, Pay button),
        // exactly like the WooCommerce checkout payment section.
        $embed_url = add_query_arg('wedding_booking_embed', '1', $order->get_checkout_payment_url());
    }

    // Bank transfer / cheque / cash on delivery: the booking is placed but not
    // paid. The customer needs the gateway's instructions (bank details).
    $instructions = '';
    if ($processed && $order->has_status('on-hold')) {
        ob_start();
        do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
        $instructions = wp_kses_post(trim((string) ob_get_clean()));
    }

    wp_send_json_success([
        'awaiting_payment'  => $order->has_status('on-hold'),
        'instructions_html' => $instructions,
        'order_id'          => (int) $order->get_id(),
        'order_key'         => (string) $order->get_order_key(),
        'order_number'      => (string) $order->get_order_number(),
        'status'            => (string) $order->get_status(),
        'status_label'      => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status(),
        'due_now'           => (float) $order->get_total(),
        'currency'          => wedding_booking_get_currency_symbol(),
        'gateway_title'     => $gateway_title,
        'payment_processed' => $processed,
        'client_email'      => (string) $order->get_billing_email(),
        'pay_url'           => $order->get_checkout_payment_url(),
        'received_url'      => $order->get_checkout_order_received_url(),
        'redirect_url'      => $redirect_url,
        'embed_url'         => $embed_url,
    ]);
}

/**
 * Create a pending WooCommerce order carrying the same _wedding_booking_* item meta
 * the classic checkout flow writes, so payment-complete hooks
 * (booking row, balance order, reminders) work identically.
 *
 * @return WC_Order|false
 */
function wedding_booking_create_booking_order($booking, $details, $payment_method = '', $created_via = 'wedding_booking')
{
    $product = wc_get_product((int) $booking['product_id']);
    if (! $product) {
        return false;
    }

    // Studio-added bookings belong to the customer (by email), not the admin.
    $customer_id = 'wedding_booking' === $created_via ? get_current_user_id() : (int) ($booking['customer_id'] ?? 0);
    $order       = wc_create_order([
        'customer_id' => $customer_id,
        'created_via' => $created_via,
    ]);
    if (! $order || is_wp_error($order)) {
        return false;
    }

    $client_name  = trim(($details['first_name'] ?? '') . ' ' . ($details['last_name'] ?? ''));
    $client_email = $details['email'] ?? '';
    $client_phone = $details['phone'] ?? '';
    $country_raw  = (string) ($details['country'] ?? '');
    $country_code = strlen($country_raw) === 2 ? strtoupper($country_raw) : '';
    $balance_due  = max(0, (float) $booking['total'] - (float) $booking['deposit']);

    // A promo code comes off the booking price; this order carries its share
    // (the deposit % of the discount) as a normal WooCommerce discount, so
    // reports and coupon usage limits see it.
    $pay_pct        = max(1, (int) ($booking['deposit_pct'] ?? 100));
    $discount_share = ! empty($booking['coupon_code']) ? round((float) ($booking['discount'] ?? 0) * $pay_pct / 100, 2) : 0.0;
    $session_time   = (string) ($booking['session_time'] ?? ($details['event_time'] ?? ''));

    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(1);
    $item->set_subtotal((float) $booking['deposit'] + $discount_share);
    $item->set_total((float) $booking['deposit']);
    if (! empty($booking['package_name'])) {
        $item->set_name(__('Photography Session', 'wedding-booking') . ' — ' . $booking['package_name']);
    }

    $meta_map = [
        '_wedding_booking_session_type'  => $booking['session_type'],
        '_wedding_booking_package_name'  => $booking['package_name'],
        '_wedding_booking_package_id'    => (int) ($booking['package_id'] ?? 0),
        '_wedding_booking_addon_ids'     => implode(',', array_map('intval', (array) ($booking['addon_ids'] ?? []))),
        '_wedding_booking_total'         => $booking['total'],
        '_wedding_booking_fee_pct'       => $booking['fee_pct'] ?? 0,
        '_wedding_booking_fee_amount'    => $booking['fee_amount'] ?? 0,
        '_wedding_booking_deposit'       => $booking['deposit'],
        '_wedding_booking_deposit_pct'   => $booking['deposit_pct'],
        '_wedding_booking_balance_due'   => $balance_due,
        '_wedding_booking_addons_label'  => $booking['addons_label'],
        '_wedding_booking_addons_total'  => $booking['addons_total'],
        '_wedding_booking_subtotal'      => $booking['subtotal'] ?? '',
        '_wedding_booking_coupon_code'   => $booking['coupon_code'] ?? '',
        '_wedding_booking_discount'      => $booking['discount'] ?? 0,
        '_wedding_booking_client_name'   => $client_name,
        '_wedding_booking_client_email'  => $client_email,
        '_wedding_booking_client_phone'  => $client_phone,
        '_wedding_booking_client_country' => $country_raw,
        '_wedding_booking_session_date'  => $booking['session_date'],
        '_wedding_booking_session_time'  => $session_time,
        '_wedding_booking_location_pref' => $details['hotel_place'] ?? '',
        '_wedding_booking_notes'         => $details['notes'] ?? '',
        '_wedding_booking_signer_name'   => (string) ($booking['contract']['signature'] ?? ''),
        '_wedding_booking_billing_event_date'   => $booking['session_date'],
        '_wedding_booking_billing_event_time'   => $session_time,
        '_wedding_booking_billing_hotel_place'  => $details['hotel_place'] ?? '',
        '_wedding_booking_billing_participants' => $details['participants'] ?? '',
        '_wedding_booking_billing_room_number'  => $details['room_number'] ?? '',
        '_wedding_booking_billing_stay_period'  => $details['stay_period'] ?? '',
        '_wedding_booking_currency'      => $booking['currency'],
    ];
    foreach ($meta_map as $key => $val) {
        $item->add_meta_data($key, $val, true);
    }
    // Admin-created custom checkout fields (details keys namespaced cf_{key}).
    foreach ($details as $dkey => $dval) {
        if (strpos($dkey, 'cf_') === 0) {
            $item->add_meta_data('_wedding_booking_' . $dkey, $dval, true);
        }
    }
    $order->add_item($item);

    $order->set_address([
        'first_name' => $details['first_name'] ?? '',
        'last_name'  => $details['last_name'] ?? '',
        'email'      => $client_email,
        'phone'      => $client_phone,
        'country'    => $country_code,
        'address_1'  => $details['address_1'] ?? '',
        'city'       => $details['city'] ?? '',
        'postcode'   => $details['postcode'] ?? '',
    ], 'billing');

    $order->update_meta_data('_wedding_booking_billing_event_date',   $booking['session_date']);
    $order->update_meta_data('_wedding_booking_billing_event_time',   $session_time);
    // Lets this customer's own unpaid order not block their date (holds).
    if (! empty($booking['hold_token'])) {
        $order->update_meta_data('_wedding_booking_hold_token', (string) $booking['hold_token']);
    }
    if (! empty($booking['balance_due_date'])) {
        $order->update_meta_data('_wedding_booking_balance_due_date', (string) $booking['balance_due_date']);
    }
    // Record of the terms the customer accepted.
    if (! empty($booking['contract'])) {
        $order->update_meta_data('_wedding_booking_contract_accepted_at', (string) $booking['contract']['accepted_at']);
        $order->update_meta_data('_wedding_booking_contract_version', (string) $booking['contract']['version']);
        $order->update_meta_data('_wedding_booking_contract_signature', (string) $booking['contract']['signature']);
        $order->update_meta_data('_wedding_booking_contract_ip', (string) $booking['contract']['ip']);
        $order->update_meta_data('_wedding_booking_contract_ua', (string) $booking['contract']['ua']);
        wedding_booking_remember_contract_version($booking['contract']['version']);
    }
    $order->update_meta_data('_wedding_booking_billing_hotel_place',  $details['hotel_place'] ?? '');
    $order->update_meta_data('_wedding_booking_billing_participants', $details['participants'] ?? '');
    $order->update_meta_data('_wedding_booking_billing_room_number',  $details['room_number'] ?? '');
    $order->update_meta_data('_wedding_booking_billing_stay_period',  $details['stay_period'] ?? '');
    foreach ($details as $dkey => $dval) {
        if (strpos($dkey, 'cf_') === 0) {
            $order->update_meta_data('_wedding_booking_' . $dkey, $dval);
        }
    }

    if (! empty($details['notes'])) {
        $order->set_customer_note($details['notes']);
    }

    if ($payment_method !== '' && WC()->payment_gateways()) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($available[$payment_method])) {
            $order->set_payment_method($available[$payment_method]);
        }
    }

    if ($discount_share > 0) {
        $coupon_item = new WC_Order_Item_Coupon();
        $coupon_item->set_code((string) $booking['coupon_code']);
        $coupon_item->set_discount($discount_share);
        $order->add_item($coupon_item);
    }

    $order->calculate_totals();
    $order->update_status('pending');
    $order->add_order_note(__('Created via Wedding Booking booking form.', 'wedding-booking'));
    if (! empty($booking['contract'])) {
        $order->add_order_note(sprintf(
            /* translators: 1: terms version, 2: signature or "no signature", 3: IP address */
            __('Terms & Conditions accepted (version %1$s, signed: %2$s, IP %3$s).', 'wedding-booking'),
            $booking['contract']['version'],
            $booking['contract']['signature'] !== '' ? $booking['contract']['signature'] : __('no signature', 'wedding-booking'),
            $booking['contract']['ip']
        ));
    }
    $order->save();

    return $order;
}

/* ═══════════════════════════════════════════════════════════════
   WhatsApp contact button on the order-received (thank you) page
   for booking orders — same label/number settings as the booking
   form's success screens.
═══════════════════════════════════════════════════════════════ */
add_action('woocommerce_thankyou', 'wedding_booking_thankyou_whatsapp_button', 20);
function wedding_booking_thankyou_whatsapp_button($order_id)
{
    $number = preg_replace('/\D/', '', (string) get_option('wedding_booking_whatsapp', ''));
    if ($number === '') {
        return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    $is_booking_order = $order->get_created_via() === 'wedding_booking';
    if (! $is_booking_order) {
        $wedding_booking_product = (int) get_option('wedding_booking_wc_product_id', 0);
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            if ((int) $item->get_product_id() === $wedding_booking_product) {
                $is_booking_order = true;
                break;
            }
        }
    }
    if (! $is_booking_order) {
        return;
    }

    printf(
        '<p class="wbook-thankyou-whatsapp"><a class="button" href="%s" target="_blank" rel="noopener">%s</a></p>',
        esc_url('https://wa.me/' . $number),
        esc_html(get_option('wedding_booking_whatsapp_btn', 'Message us on WhatsApp'))
    );
}

/* ═══════════════════════════════════════════════════════════════
   Hide Wedding Booking product from shop / search
═══════════════════════════════════════════════════════════════ */
add_action('pre_get_posts', 'wedding_booking_hide_product_from_catalog');
function wedding_booking_hide_product_from_catalog($q)
{
    if (is_admin() || ! $q->is_main_query()) return;
    $wedding_booking_id = (int) get_option('wedding_booking_wc_product_id', 0);
    if (! $wedding_booking_id) return;
    $not_in   = (array) $q->get('post__not_in');
    $not_in[] = $wedding_booking_id;
    $q->set('post__not_in', $not_in);
}