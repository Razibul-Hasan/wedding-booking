<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   SETTINGS SCREENS
   Wedding Booking → Settings (plugin-wide, manage_options) and
   Wedding Booking → Booking Form (the form's steps and sidebar cards).

   Settings is ONE form split into sections by a side menu: admin.js
   shows one section at a time (without JS they all show) and its
   search box filters rows across every section. The whole form is
   still saved in one go by wedding_booking_admin_save_settings() (ajax.php),
   so a field can sit in any section as long as its name is unchanged.
═══════════════════════════════════════════════════════════════ */

/**
 * Sections of the Settings screen, in menu order.
 */
function wedding_booking_settings_sections()
{
    return [
        'general'      => [
            'label'  => __('General', 'wedding-booking'),
            'hint'   => __('Setup, contact, colors', 'wedding-booking'),
            'icon'   => 'dashicons-admin-home',
            'intro'  => __('Get the booking form live, tell Wedding Booking how to reach you, and match the form to your brand.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_general',
        ],
        'payments'     => [
            'label'  => __('Payments', 'wedding-booking'),
            'hint'   => __('Deposit, fees, promo codes', 'wedding-booking'),
            'icon'   => 'dashicons-money-alt',
            'intro'  => __('How much customers pay to book, when the rest is due, and any fee or promo codes.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_payments',
        ],
        'availability' => [
            'label'  => __('Availability', 'wedding-booking'),
            'hint'   => __('Bookable days and times', 'wedding-booking'),
            'icon'   => 'dashicons-calendar-alt',
            'intro'  => __('Which days and times customers can book, and how far ahead.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_availability',
        ],
        'checkout'     => [
            'label'  => __('Checkout', 'wedding-booking'),
            'hint'   => __('Form fields and messages', 'wedding-booking'),
            'icon'   => 'dashicons-cart',
            'intro'  => __('The details customers fill in, and what they see once their booking is placed.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_checkout',
        ],
        'customers'    => [
            'label'  => __('Customers', 'wedding-booking'),
            'hint'   => __('Accounts and requests', 'wedding-booking'),
            'icon'   => 'dashicons-admin-users',
            'intro'  => __('What customers can see and do with their bookings in their account.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_customers',
        ],
        'emails'       => [
            'label'  => __('Emails', 'wedding-booking'),
            'hint'   => __('Confirmation, alerts, reminders', 'wedding-booking'),
            'icon'   => 'dashicons-email-alt',
            'intro'  => __('The confirmation your customer gets, the alert you get for each new booking, and automatic balance reminders.', 'wedding-booking'),
            'render' => 'wedding_booking_render_settings_emails',
        ],
        'calendar'     => [
            'label'  => __('Google Calendar', 'wedding-booking'),
            'hint'   => __('Sync bookings', 'wedding-booking'),
            'icon'   => 'dashicons-google',
            'intro'  => __('Put every paid booking in your Google Calendar automatically.', 'wedding-booking'),
            'render' => 'wedding_booking_render_gcal_card',
        ],
        'advanced'     => [
            'label'  => __('Advanced', 'wedding-booking'),
            'hint'   => __('Plugin data', 'wedding-booking'),
            'icon'   => 'dashicons-admin-tools',
            'intro'  => __('Options you will rarely need.', 'wedding-booking'),
            'render' => 'wedding_booking_render_plugin_data_card',
        ],
    ];
}

/* ─── Shared building blocks ─────────────────────────────────── */

/**
 * Opens a settings card. Cards sit under a section heading (h2), so
 * their own title is an h3.
 */
function wedding_booking_settings_card_open($title, $desc = '', $id = '', $extra_class = '')
{
    echo '<div class="card wedding-booking-settings-card' . ($extra_class !== '' ? ' ' . esc_attr($extra_class) : '') . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
    echo '<h3>' . esc_html($title) . '</h3>';
    if ($desc !== '') {
        echo '<p class="description">' . esc_html($desc) . '</p>';
    }
}

function wedding_booking_settings_card_close()
{
    echo '</div>';
}

/**
 * Opens a settings-table row: the label (tied to the field $for when
 * given), its help tip, then the value cell.
 *
 * $requires names a control this row depends on: while that checkbox is
 * off (or that number is 0) the row is dimmed and $requires_note shows
 * under the label. Prefix the name with "!" to invert it (dimmed while
 * that field has a value). admin.js does the dimming; the fields stay
 * editable either way, so their values still save.
 */
function wedding_booking_setting_row_open($label, $tip = '', $for = '', $requires = '', $requires_note = '')
{
    echo '<tr' . ($requires !== '' ? ' data-wedding-booking-requires="' . esc_attr($requires) . '"' : '') . '>';
    echo '<th scope="row"><div class="wedding-booking-th">';
    if ($for !== '') {
        echo '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>';
    } else {
        echo '<span class="wedding-booking-th-label">' . esc_html($label) . '</span>';
    }
    if ($tip !== '') {
        echo wedding_booking_help_tip($tip); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    }
    echo '</div>';
    if ($requires_note !== '') {
        echo '<small class="wedding-booking-parked-note">' . esc_html($requires_note) . '</small>';
    }
    echo '</th><td>';
}

function wedding_booking_setting_row_close()
{
    echo '</td></tr>';
}

/**
 * Sticky bar with the save button. Shows "Unsaved changes" while the
 * form has edits (admin.js) and the save result in $msg_id.
 */
function wedding_booking_render_savebar($button_label, $msg_id = '', $note = '')
{
    echo '<div class="wedding-booking-savebar">';
    echo '<span class="wedding-booking-savebar-dirty"><span class="wedding-booking-savebar-dot" aria-hidden="true"></span>' . esc_html__('Unsaved changes', 'wedding-booking') . '</span>';
    if ($note !== '') {
        echo '<span class="wedding-booking-savebar-note">' . esc_html($note) . '</span>';
    }
    if ($msg_id !== '') {
        echo '<div id="' . esc_attr($msg_id) . '" class="wedding-booking-form-msg" aria-live="polite"></div>';
    }
    echo '<button type="submit" class="button button-primary wedding-booking-savebar-btn">' . esc_html($button_label) . '</button>';
    echo '</div>';
}

/**
 * One "number" input with text around it, e.g. "Send it [3] days before".
 */
function wedding_booking_settings_inline_number($name, $value, $min, $max, $before = '', $after = '', $id = '')
{
    $id  = $id !== '' ? $id : str_replace('_', '-', $name);
    $out = '<label class="wedding-booking-inline-num" for="' . esc_attr($id) . '">';
    if ($before !== '') {
        $out .= '<span>' . esc_html($before) . '</span> ';
    }
    $out .= '<input id="' . esc_attr($id) . '" class="small-text" type="number" name="' . esc_attr($name) . '" min="' . (int) $min . '" max="' . (int) $max . '" step="1" value="' . esc_attr($value) . '">';
    if ($after !== '') {
        $out .= ' <span>' . esc_html($after) . '</span>';
    }
    $out .= '</label>';

    return $out;
}

/* ─── Setup checklist ───────────────────────────────────────── */

/**
 * What still has to be done before the site can take bookings. Shown in
 * Settings → General and (while unfinished) as a nudge on All Bookings.
 * Each item: label, hint, done, url, action, optional.
 */
function wedding_booking_setup_checklist()
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }

    global $wpdb;
    $sessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wedding_booking_sessions WHERE active=1"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $packages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wedding_booking_packages WHERE active=1"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wc       = class_exists('WooCommerce');
    $page_url = function_exists('wedding_booking_get_booking_page_url') ? wedding_booking_get_booking_page_url() : '';

    $items = [];

    $items['woocommerce'] = [
        'label'  => __('WooCommerce is active', 'wedding-booking'),
        'hint'   => $wc
            ? __('Deposits and payments go through WooCommerce.', 'wedding-booking')
            : __('Needed to take deposits and payments. Without it, the booking form only sends you enquiry emails.', 'wedding-booking'),
        'done'   => $wc,
        'url'    => admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'),
        'action' => __('Get WooCommerce', 'wedding-booking'),
    ];

    $items['sessions'] = [
        'label'  => __('Add a session type', 'wedding-booking'),
        'hint'   => $sessions > 0
            /* translators: %d: number of active session types */
            ? sprintf(_n('%d active session type.', '%d active session types.', $sessions, 'wedding-booking'), $sessions)
            : __('For example Wedding, Portrait or Family. Each one is a tab on the booking form.', 'wedding-booking'),
        'done'   => $sessions > 0,
        'url'    => admin_url('admin.php?page=wedding-booking-sessions'),
        'action' => __('Add session type', 'wedding-booking'),
    ];

    $items['packages'] = [
        'label'  => __('Add a package', 'wedding-booking'),
        'hint'   => $packages > 0
            /* translators: %d: number of active packages */
            ? sprintf(_n('%d active package.', '%d active packages.', $packages, 'wedding-booking'), $packages)
            : __('What customers actually book and pay for, with a price.', 'wedding-booking'),
        'done'   => $packages > 0,
        'url'    => admin_url('admin.php?page=wedding-booking-packages'),
        'action' => __('Add package', 'wedding-booking'),
    ];

    $items['page'] = [
        'label'  => __('Put the booking form on a page', 'wedding-booking'),
        'hint'   => $page_url !== ''
            /* translators: %s: booking page URL */
            ? sprintf(__('Live at %s', 'wedding-booking'), $page_url)
            : __('Add the [wedding_booking] shortcode to any page.', 'wedding-booking'),
        'done'   => $page_url !== '',
        'url'    => admin_url('post-new.php?post_type=page'),
        'action' => __('Create a page', 'wedding-booking'),
    ];

    if ($wc) {
        $gateways = 0;
        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            foreach ((array) WC()->payment_gateways()->payment_gateways() as $gateway) {
                if (is_object($gateway) && isset($gateway->enabled) && 'yes' === $gateway->enabled) {
                    $gateways++;
                }
            }
        }
        $items['gateways'] = [
            'label'  => __('Turn on a payment method', 'wedding-booking'),
            'hint'   => $gateways > 0
                /* translators: %d: number of payment methods */
                ? sprintf(_n('%d payment method is on.', '%d payment methods are on.', $gateways, 'wedding-booking'), $gateways)
                : __('Card, PayPal, bank transfer… switch one on in WooCommerce → Settings → Payments.', 'wedding-booking'),
            'done'   => $gateways > 0,
            'url'    => admin_url('admin.php?page=wc-settings&tab=checkout'),
            'action' => __('Payment methods', 'wedding-booking'),
        ];
    }

    $items['gcal'] = [
        'label'    => __('Connect Google Calendar', 'wedding-booking'),
        'hint'     => __('Adds every paid booking to your calendar.', 'wedding-booking'),
        'done'     => function_exists('wedding_booking_gcal_is_connected') && wedding_booking_gcal_is_connected(),
        'url'      => admin_url('admin.php?page=wedding-booking-settings#calendar'),
        'action'   => __('Set up', 'wedding-booking'),
        'optional' => true,
    ];

    return $items;
}

/**
 * Required checklist steps still to do.
 */
function wedding_booking_setup_steps_left()
{
    $left = 0;
    foreach (wedding_booking_setup_checklist() as $item) {
        if (empty($item['optional']) && ! $item['done']) {
            $left++;
        }
    }
    return $left;
}

function wedding_booking_render_setup_checklist_card()
{
    $items = wedding_booking_setup_checklist();
    $total = 0;
    $done  = 0;
    foreach ($items as $item) {
        if (empty($item['optional'])) {
            $total++;
            $done += $item['done'] ? 1 : 0;
        }
    }
    $complete = $done === $total;

    echo '<div class="card wedding-booking-settings-card wedding-booking-setup-card' . ($complete ? ' is-complete' : '') . '" id="wedding-booking-setup">';
    echo '<h3>' . esc_html($complete ? __('Setup complete', 'wedding-booking') : __('Setup checklist', 'wedding-booking')) . '</h3>';
    echo '<p class="description">' . esc_html($complete ? __('Everything needed to take bookings is in place.', 'wedding-booking') : __('Finish these steps and you are ready to take bookings.', 'wedding-booking')) . '</p>';

    $pct = $total > 0 ? (int) round($done / $total * 100) : 100;
    echo '<div class="wedding-booking-setup-progress">';
    echo '<div class="wedding-booking-setup-bar" role="progressbar" aria-label="' . esc_attr__('Setup progress', 'wedding-booking') . '" aria-valuemin="0" aria-valuemax="' . (int) $total . '" aria-valuenow="' . (int) $done . '"><span style="width:' . (int) $pct . '%"></span></div>';
    /* translators: 1: steps done, 2: total steps */
    echo '<span class="wedding-booking-setup-count">' . esc_html(sprintf(__('%1$d of %2$d done', 'wedding-booking'), $done, $total)) . '</span>';
    echo '</div>';

    echo '<ul class="wedding-booking-setup-list">';
    foreach ($items as $item) {
        $cls = $item['done'] ? ' is-done' : '';
        $cls .= empty($item['optional']) ? '' : ' is-optional';
        echo '<li class="wedding-booking-setup-item' . esc_attr($cls) . '">';
        echo '<span class="wedding-booking-setup-icon dashicons ' . ($item['done'] ? 'dashicons-yes-alt' : 'dashicons-marker') . '" aria-hidden="true"></span>';
        echo '<span class="wedding-booking-setup-text"><span class="wedding-booking-setup-title"><strong>' . esc_html($item['label']) . '</strong>';
        if (! empty($item['optional'])) {
            echo ' <span class="wedding-booking-setup-tag">' . esc_html__('Optional', 'wedding-booking') . '</span>';
        }
        echo '<span class="screen-reader-text"> — ' . esc_html($item['done'] ? __('done', 'wedding-booking') : __('not done yet', 'wedding-booking')) . '</span></span>';
        echo '<small>' . esc_html($item['hint']) . '</small></span>';
        if (! $item['done'] && $item['url'] !== '') {
            echo '<a class="button button-small wedding-booking-setup-action" href="' . esc_url($item['url']) . '">' . esc_html($item['action']) . '</a>';
        }
        echo '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

/**
 * All Bookings: a one-line reminder while required setup steps are left.
 */
function wedding_booking_render_setup_nudge()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $left = wedding_booking_setup_steps_left();
    if ($left < 1) {
        return;
    }
    echo '<div class="notice notice-info inline wedding-booking-setup-nudge"><p><span class="dashicons dashicons-flag" aria-hidden="true"></span> ';
    echo '<strong>' . esc_html__('Finish setting up Wedding Booking', 'wedding-booking') . '</strong> — ';
    /* translators: %d: number of setup steps left */
    echo esc_html(sprintf(_n('%d step left before you can take bookings.', '%d steps left before you can take bookings.', $left, 'wedding-booking'), $left)) . ' ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#general')) . '">' . esc_html__('Open the setup checklist', 'wedding-booking') . '</a>';
    echo '</p></div>';
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — SETTINGS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_settings()
{
    if (! current_user_can('manage_options')) return;

    // No-JS fallback: admin.js normally saves this form over AJAX.
    $saved = false;
    if (isset($_POST['wedding_booking_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wedding_booking_settings_nonce'])), 'wedding_booking_settings')) {
        foreach (['wedding_booking_partial_option_label', 'wedding_booking_whatsapp', 'wedding_booking_success_title', 'wedding_booking_success_msg', 'wedding_booking_whatsapp_btn', 'wedding_booking_confirm_title', 'wedding_booking_confirm_msg', 'wedding_booking_confirm_pending_title', 'wedding_booking_confirm_pending_msg'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        update_option('wedding_booking_admin_email', sanitize_email(wp_unslash($_POST['wedding_booking_admin_email'] ?? '')) ?: get_option('admin_email'));
        update_option('wedding_booking_booking_page_id', absint(wp_unslash($_POST['wedding_booking_booking_page_id'] ?? 0)));
        if (function_exists('wedding_booking_sanitize_custom_checkout_fields')) {
            update_option('wedding_booking_checkout_custom_fields', wedding_booking_sanitize_custom_checkout_fields($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        update_option('wedding_booking_enable_partial_payment', absint(wp_unslash($_POST['wedding_booking_enable_partial_payment'] ?? 0)) === 1 ? 1 : 0);
        update_option('wedding_booking_partial_block_days', max(0, absint(wp_unslash($_POST['wedding_booking_partial_block_days'] ?? 0))));
        update_option('wedding_booking_payment_fee_pct', min(100, max(0, (float) sanitize_text_field(wp_unslash($_POST['wedding_booking_payment_fee_pct'] ?? 0)))));
        update_option('wedding_booking_require_account_booking', absint(wp_unslash($_POST['wedding_booking_require_account_booking'] ?? 0)) === 1 ? 1 : 0);
        wedding_booking_save_balance_reminder_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.
        wedding_booking_save_extra_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.
        update_option('wedding_booking_order_email_enable', absint(wp_unslash($_POST['wedding_booking_order_email_enable'] ?? 0)) === 1 ? 1 : 0);
        update_option('wedding_booking_order_email_order_table', absint(wp_unslash($_POST['wedding_booking_order_email_order_table'] ?? 0)) === 1 ? 1 : 0);
        update_option('wedding_booking_order_email_subject', sanitize_text_field(wp_unslash($_POST['wedding_booking_order_email_subject'] ?? '')));
        update_option('wedding_booking_order_email_heading', sanitize_text_field(wp_unslash($_POST['wedding_booking_order_email_heading'] ?? '')));
        update_option('wedding_booking_order_email_message', wp_kses_post(wp_unslash($_POST['wedding_booking_order_email_message'] ?? '')));
        update_option('wedding_booking_order_email_attachment_id', absint(wp_unslash($_POST['wedding_booking_order_email_attachment_id'] ?? 0)));
        update_option('wedding_booking_admin_email_enable', absint(wp_unslash($_POST['wedding_booking_admin_email_enable'] ?? 0)) === 1 ? 1 : 0);
        update_option('wedding_booking_admin_email_recipient', function_exists('wedding_booking_sanitize_email_list') ? wedding_booking_sanitize_email_list(wp_unslash($_POST['wedding_booking_admin_email_recipient'] ?? '')) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        update_option('wedding_booking_admin_email_subject', sanitize_text_field(wp_unslash($_POST['wedding_booking_admin_email_subject'] ?? '')));
        update_option('wedding_booking_admin_email_heading', sanitize_text_field(wp_unslash($_POST['wedding_booking_admin_email_heading'] ?? '')));
        update_option('wedding_booking_admin_email_intro', wp_kses_post(wp_unslash($_POST['wedding_booking_admin_email_intro'] ?? '')));
        if (function_exists('wedding_booking_sanitize_checkout_mode')) {
            update_option('wedding_booking_checkout_mode', wedding_booking_sanitize_checkout_mode(sanitize_key(wp_unslash($_POST['wedding_booking_checkout_mode'] ?? 'direct'))));
        }
        if (function_exists('wedding_booking_sanitize_checkout_field_config')) {
            update_option('wedding_booking_checkout_form_fields', wedding_booking_sanitize_checkout_field_config($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        // Each field is only written when the settings screen actually rendered
        // it: the sync toggle exists only once connected, and the key fields are
        // hidden while the credentials come from wp-config constants.
        if (isset($_POST['wedding_booking_gcal_enabled'])) {
            update_option('wedding_booking_gcal_enabled', absint(wp_unslash($_POST['wedding_booking_gcal_enabled'])) === 1 ? 1 : 0);
        }
        if (isset($_POST['wedding_booking_gcal_client_id'])) {
            update_option('wedding_booking_gcal_client_id', sanitize_text_field(wp_unslash($_POST['wedding_booking_gcal_client_id'])));
        }
        if (isset($_POST['wedding_booking_gcal_client_secret'])) {
            update_option('wedding_booking_gcal_client_secret', sanitize_text_field(wp_unslash($_POST['wedding_booking_gcal_client_secret'])));
        }
        if (function_exists('wedding_booking_default_theme_colors')) {
            $theme_defaults = wedding_booking_default_theme_colors();
            update_option('wedding_booking_theme_primary', sanitize_hex_color(wp_unslash($_POST['wedding_booking_theme_primary'] ?? '')) ?: $theme_defaults['primary']);
            update_option('wedding_booking_theme_accent', sanitize_hex_color(wp_unslash($_POST['wedding_booking_theme_accent'] ?? '')) ?: $theme_defaults['accent']);
        }
        $saved = true;
    }

    $sections = wedding_booking_settings_sections();

    // Menu badges: what is left to set up, and whether Google is connected.
    $left = wedding_booking_setup_steps_left();
    if ($left > 0) {
        /* translators: %d: setup steps left */
        $sections['general']['badge'] = ['tone' => 'warn', 'text' => sprintf(_n('%d to do', '%d to do', $left, 'wedding-booking'), $left)];
    }
    $gcal_on = function_exists('wedding_booking_gcal_is_connected') && wedding_booking_gcal_is_connected();
    $sections['calendar']['badge'] = $gcal_on
        ? ['tone' => 'ok', 'text' => __('On', 'wedding-booking')]
        : ['tone' => 'off', 'text' => __('Off', 'wedding-booking')];

    // Coming back from the Google connect flow lands on its section.
    $current = isset($_GET['wedding_booking_gcal']) ? 'calendar' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

    wedding_booking_wrap_open(__('Settings', 'wedding-booking'), 'wedding-booking-settings', __('Choose a section on the left. Hover or tap the ? beside any setting to see what it does.', 'wedding-booking'));

    if ($saved) {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Settings saved.', 'wedding-booking') . '</p></div>';
    }
    wedding_booking_render_gcal_return_notice();

    echo '<form method="post" id="wedding-booking-settings-form" class="wedding-booking-settings-page wedding-booking-set-layout">';
    wp_nonce_field('wedding_booking_settings', 'wedding_booking_settings_nonce');

    // ── Side menu ──
    echo '<nav class="wedding-booking-set-nav" aria-label="' . esc_attr__('Settings sections', 'wedding-booking') . '">';
    echo '<div class="wedding-booking-set-search"><span class="dashicons dashicons-search" aria-hidden="true"></span>';
    echo '<input type="search" id="wedding-booking-set-search" placeholder="' . esc_attr__('Find a setting…', 'wedding-booking') . '" aria-label="' . esc_attr__('Find a setting', 'wedding-booking') . '" autocomplete="off" spellcheck="false"></div>';
    echo '<ul class="wedding-booking-set-nav-list">';
    foreach ($sections as $key => $s) {
        $is = $key === $current;
        echo '<li><a class="wedding-booking-set-nav-link' . ($is ? ' is-active' : '') . '" href="#' . esc_attr($key) . '" data-section="' . esc_attr($key) . '"' . ($is ? ' aria-current="true"' : '') . '>';
        echo '<span class="dashicons ' . esc_attr($s['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="wedding-booking-set-nav-text"><span class="wedding-booking-set-nav-label">' . esc_html($s['label']) . '</span><span class="wedding-booking-set-nav-hint">' . esc_html($s['hint']) . '</span></span>';
        if (! empty($s['badge'])) {
            echo '<span class="wedding-booking-set-nav-badge is-' . esc_attr($s['badge']['tone']) . '">' . esc_html($s['badge']['text']) . '</span>';
        }
        echo '</a></li>';
    }
    echo '</ul>';
    echo '</nav>';

    // ── Sections ──
    echo '<div class="wedding-booking-set-main">';
    foreach ($sections as $key => $s) {
        echo '<section class="wedding-booking-set-panel' . ($key === $current ? ' is-active' : '') . '" id="wedding-booking-section-' . esc_attr($key) . '" data-section="' . esc_attr($key) . '" aria-labelledby="wedding-booking-section-' . esc_attr($key) . '-title">';
        echo '<header class="wedding-booking-set-panel-head"><span class="dashicons ' . esc_attr($s['icon']) . '" aria-hidden="true"></span><div>';
        echo '<h2 id="wedding-booking-section-' . esc_attr($key) . '-title">' . esc_html($s['label']) . '</h2>';
        echo '<p>' . esc_html($s['intro']) . '</p>';
        echo '</div></header>';
        call_user_func($s['render']);
        echo '</section>';
    }
    echo '<div class="wedding-booking-set-noresults" id="wedding-booking-set-noresults" hidden><span class="dashicons dashicons-search" aria-hidden="true"></span>';
    echo '<p>' . esc_html__('No settings match your search. Try a shorter or different word.', 'wedding-booking') . '</p></div>';
    wedding_booking_render_savebar(__('Save All Settings', 'wedding-booking'), 'wedding-booking-settings-msg', __('Saves every section at once.', 'wedding-booking'));
    echo '</div>';

    echo '</form>';

    wedding_booking_wrap_close();
}

/**
 * Result of the Google connect / disconnect round trip (?wedding_booking_gcal=).
 */
function wedding_booking_render_gcal_return_notice()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
    $gcal_notice = isset($_GET['wedding_booking_gcal']) ? sanitize_key(wp_unslash($_GET['wedding_booking_gcal'])) : '';
    if ($gcal_notice === 'connected') {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Google Calendar connected. New bookings will be added automatically.', 'wedding-booking') . '</p></div>';
    } elseif ($gcal_notice === 'disconnected') {
        echo '<div class="notice notice-info is-dismissible inline"><p>' . esc_html__('Google Calendar disconnected.', 'wedding-booking') . '</p></div>';
    } elseif ($gcal_notice === 'error') {
        $gcal_reason = isset($_GET['reason']) ? sanitize_key(wp_unslash($_GET['reason'])) : '';
        $gcal_reasons = [
            'denied'        => __('Connection cancelled on the Google consent screen.', 'wedding-booking'),
            'access_denied' => __('Google blocked the sign-in (Error 403: access_denied). Your Google app is still in “Testing”, so only approved testers can use it. In Google Cloud Console open Google Auth Platform → Audience and either click Publish app, or add this Google account under Test users. Then connect again.', 'wedding-booking'),
            'state'         => __('The connection could not be verified. Please try connecting again.', 'wedding-booking'),
            'network'       => __('Could not reach Google. Please try again in a moment.', 'wedding-booking'),
            'exchange'      => __('Google rejected the connection — check the redirect URI is registered and the Client Secret is correct, then try again.', 'wedding-booking'),
            'nocreds'       => __('Enter your Google Client ID and Client Secret below and click Save All Settings first, then Connect.', 'wedding-booking'),
        ];
        $gcal_reason_msg = $gcal_reasons[$gcal_reason] ?? __('Google Calendar connection failed. Please try again.', 'wedding-booking');
        echo '<div class="notice notice-error is-dismissible inline"><p>' . esc_html($gcal_reason_msg) . '</p></div>';
    }
    // phpcs:enable
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — GENERAL
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_general()
{
    wedding_booking_render_setup_checklist_card();

    // ── Booking form ─
    wedding_booking_settings_card_open(__('Booking form', 'wedding-booking'), __('Where the booking form appears on your site.', 'wedding-booking'), 'wedding-booking-booking-form-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Shortcode', 'wedding-booking'),
        __('Paste this into any page or post (in the block editor, use a Shortcode block) to show the booking form there. Optional extras: package="your-package-slug" opens the form with that package already picked, and primary="#hex" / accent="#hex" change the colors of that one form.', 'wedding-booking')
    );
    echo '<div class="wedding-booking-shortcode-copy-wrap">';
    echo '<code class="wedding-booking-shortcode-code" id="wedding-booking-sc-code">[wedding_booking]</code>';
    echo '<button type="button" class="button button-secondary wedding-booking-copy-btn" data-copy="[wedding_booking]">' . esc_html__('Copy', 'wedding-booking') . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__('Customers pick a package, enter their details and pay, choosing their date from the calendar beside the form.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Booking page', 'wedding-booking'),
        __('The page your booking form is on. Wedding Booking uses it for package share links (Packages → Copy Link) and the “Book a session” button in My Account. Leave it on Auto-detect unless the form is on more than one page.', 'wedding-booking'),
        'wedding-booking-booking-page'
    );
    wp_dropdown_pages([
        'id'                => 'wedding-booking-booking-page',
        'name'              => 'wedding_booking_booking_page_id',
        'selected'          => (int) get_option('wedding_booking_booking_page_id', 0),
        'show_option_none'  => esc_html__('Auto-detect (page containing the booking form)', 'wedding-booking'),
        'option_none_value' => '0',
        'post_status'       => 'publish',
    ]);
    $detected_url = wedding_booking_get_booking_page_url();
    if ($detected_url !== '') {
        /* translators: %s: booking page URL */
        echo '<p class="description">' . sprintf(esc_html__('Share links currently point to: %s', 'wedding-booking'), '<code>' . esc_html($detected_url) . '</code>') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__('No booking page found yet — add the [wedding_booking] shortcode to a page, then pick it here.', 'wedding-booking') . '</p>';
    }
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    // ── Contact ─
    $admin_email  = get_option('wedding_booking_admin_email', get_option('admin_email'));
    $whatsapp     = get_option('wedding_booking_whatsapp', '');
    $whatsapp_btn = get_option('wedding_booking_whatsapp_btn', 'Message us on WhatsApp');

    wedding_booking_settings_card_open(__('Contact', 'wedding-booking'), __('How Wedding Booking reaches you, and how customers can reach you.', 'wedding-booking'), 'wedding-booking-contact-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Notification email', 'wedding-booking'),
        __('Your studio address for Wedding Booking alerts: booking enquiries (when WooCommerce is off), customers asking to reschedule or cancel, and double-booking warnings. Customers who reply to Wedding Booking emails reach this address too. The WooCommerce “new order” alert has its own setting under Emails.', 'wedding-booking'),
        'wedding-booking-admin-email'
    );
    echo '<input id="wedding-booking-admin-email" class="regular-text" type="email" name="wedding_booking_admin_email" value="' . esc_attr($admin_email) . '">';
    echo '<p class="description">' . esc_html__('Leave empty to use the site admin email.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('WhatsApp number', 'wedding-booking'),
        __('Your WhatsApp number in international format, digits only — e.g. 447911123456 for a UK mobile (44 is the country code). When set, customers see a WhatsApp button after booking and on the order thank-you page. Leave empty to hide the button.', 'wedding-booking'),
        'wedding-booking-whatsapp'
    );
    echo '<input id="wedding-booking-whatsapp" class="regular-text" type="text" name="wedding_booking_whatsapp" value="' . esc_attr($whatsapp) . '" placeholder="23059355040" inputmode="numeric">';
    echo '<p class="description">' . esc_html__('Digits only, starting with the country code.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('WhatsApp button text', 'wedding-booking'),
        __('The wording on the WhatsApp button.', 'wedding-booking'),
        'wedding-booking-whatsapp-btn',
        'wedding_booking_whatsapp',
        __('Used only when a WhatsApp number is set.', 'wedding-booking')
    );
    echo '<input id="wedding-booking-whatsapp-btn" class="regular-text" type="text" name="wedding_booking_whatsapp_btn" value="' . esc_attr($whatsapp_btn) . '" placeholder="Message us on WhatsApp">';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    // ── Appearance ─
    $theme_defaults = function_exists('wedding_booking_default_theme_colors') ? wedding_booking_default_theme_colors() : ['primary' => '#b8956a', 'accent' => '#3d6b78'];
    $theme_primary  = get_option('wedding_booking_theme_primary', $theme_defaults['primary']);
    $theme_accent   = get_option('wedding_booking_theme_accent', $theme_defaults['accent']);

    wedding_booking_settings_card_open(__('Appearance', 'wedding-booking'), __('Match the booking form and emails to your brand. Lighter and darker shades are made from each color automatically.', 'wedding-booking'), 'wedding-booking-appearance-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Primary color', 'wedding-booking'),
        __('Your main brand color: buttons, the current step, selected dates and packages on the booking form, and the accents in Wedding Booking emails.', 'wedding-booking'),
        'wedding-booking-theme-primary'
    );
    echo '<input id="wedding-booking-theme-primary" type="color" name="wedding_booking_theme_primary" value="' . esc_attr($theme_primary) . '" class="wedding-booking-color-input">';
    echo '<span class="description wedding-booking-color-desc">' . esc_html__('Default:', 'wedding-booking') . ' ' . esc_html($theme_defaults['primary']) . '</span>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Accent color', 'wedding-booking'),
        __('Your second brand color: prices, totals, switches and highlights.', 'wedding-booking'),
        'wedding-booking-theme-accent'
    );
    echo '<input id="wedding-booking-theme-accent" type="color" name="wedding_booking_theme_accent" value="' . esc_attr($theme_accent) . '" class="wedding-booking-color-input">';
    echo '<span class="description wedding-booking-color-desc">' . esc_html__('Default:', 'wedding-booking') . ' ' . esc_html($theme_defaults['accent']) . '</span>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — PAYMENTS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_payments()
{
    if (class_exists('WooCommerce') && function_exists('get_woocommerce_currency')) {
        echo '<div class="notice notice-info inline"><p>';
        /* translators: %s: currency code and symbol, e.g. "USD ($)" */
        echo esc_html(sprintf(__('Prices use your WooCommerce store currency: %s. Change it under WooCommerce → Settings → General.', 'wedding-booking'), get_woocommerce_currency() . ' (' . html_entity_decode((string) wedding_booking_get_currency_symbol(), ENT_QUOTES, 'UTF-8') . ')'));
        echo '</p></div>';
    }

    wedding_booking_render_deposit_card();
    wedding_booking_render_payment_fee_card();
    wedding_booking_render_offline_payments_card();
    wedding_booking_render_promo_codes_card();
}

/**
 * Deposit on/off and %, when full payment is forced, the deposit option
 * text and the balance deadline.
 */
function wedding_booking_render_deposit_card()
{
    $enable_partial = (int) get_option('wedding_booking_enable_partial_payment', 1);
    $block_days     = (int) get_option('wedding_booking_partial_block_days', 0);
    // The saved text, or the default (which carries {deposit_pct}); the old
    // "50%" default text is upgraded by the helper too.
    $option_label   = function_exists('wedding_booking_partial_option_label') ? wedding_booking_partial_option_label() : (string) get_option('wedding_booking_partial_option_label', '');
    $deposit_pct    = (int) wedding_booking_opt('wedding_booking_deposit_pct');
    $due_enable     = (int) wedding_booking_opt('wedding_booking_balance_due_enable') === 1;
    $due_days       = (int) wedding_booking_opt('wedding_booking_balance_due_days');
    $needs_deposit  = __('Used only while the deposit option is on.', 'wedding-booking');

    wedding_booking_settings_card_open(__('Deposit', 'wedding-booking'), __('Let customers secure their date with part of the price and pay the rest later.', 'wedding-booking'), 'wedding-booking-deposit-card');
    echo '<input type="hidden" name="wedding_booking_enable_partial_payment" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Deposit', 'wedding-booking'),
        __('On: customers choose between paying a deposit now (the rest later) or paying everything up front. Off: every booking is paid in full at checkout.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_enable_partial_payment', __('Let customers pay a deposit now and the rest later', 'wedding-booking'), $enable_partial === 1, __('When off, every booking is paid in full up front.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Deposit amount', 'wedding-booking'),
        __('The part of the booking total paid up front. Example: 30 means a 1,000 booking takes 300 now and leaves 700 to pay later. A package can have its own deposit (Packages → Deposit %).', 'wedding-booking'),
        'wedding-booking-deposit-pct',
        'wedding_booking_enable_partial_payment',
        $needs_deposit
    );
    echo wedding_booking_settings_inline_number('wedding_booking_deposit_pct', $deposit_pct, 1, 99, '', __('% of the booking total', 'wedding-booking'), 'wedding-booking-deposit-pct'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('Between 1 and 99.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Full payment close to the date', 'wedding-booking'),
        __('Hides the deposit option for last-minute sessions, so they are paid in full. Example: with 7, a session less than 7 days away must be paid in full. 0 = always offer the deposit.', 'wedding-booking'),
        'wedding-booking-partial-block-days',
        'wedding_booking_enable_partial_payment',
        $needs_deposit
    );
    echo wedding_booking_settings_inline_number('wedding_booking_partial_block_days', $block_days, 0, 365, __('No deposit option when the session is fewer than', 'wedding-booking'), __('days away', 'wedding-booking'), 'wedding-booking-partial-block-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = always offer the deposit.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Deposit option text', 'wedding-booking'),
        __('The wording of the deposit choice on the booking form. {deposit_pct} is replaced with the deposit percentage.', 'wedding-booking'),
        'wedding-booking-partial-option-label',
        'wedding_booking_enable_partial_payment',
        $needs_deposit
    );
    echo '<input id="wedding-booking-partial-option-label" class="regular-text" type="text" name="wedding_booking_partial_option_label" value="' . esc_attr($option_label) . '">';
    echo '<p class="description">' . wp_kses(
        __('e.g. “Pay a <code>{deposit_pct}</code>% deposit to book”.', 'wedding-booking'),
        ['code' => []]
    ) . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Balance deadline', 'wedding-booking'),
        __('Tells customers when the rest is due, e.g. “Balance due 14 days before your shoot”. It shows on the booking form, in emails ({balance_due_date}) and in My Account. Nobody is charged automatically — balance reminders (Emails section) do the chasing.', 'wedding-booking'),
        '',
        'wedding_booking_enable_partial_payment',
        $needs_deposit
    );
    echo '<input type="hidden" name="wedding_booking_balance_due_enable" value="0">';
    echo wedding_booking_toggle_field('wedding_booking_balance_due_enable', __('Tell customers when the rest is due', 'wedding-booking'), $due_enable, __('Shown on the booking form, in emails and in My Account.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '<div class="wedding-booking-set-sub" data-wedding-booking-requires="wedding_booking_balance_due_enable">';
    echo wedding_booking_settings_inline_number('wedding_booking_balance_due_days', $due_days, 0, 365, __('Due', 'wedding-booking'), __('days before the shoot', 'wedding-booking'), 'wedding-booking-balance-due-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = on the day of the shoot.', 'wedding-booking') . '</p>';
    echo '</div>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/**
 * Payment fee: %, its name, and the payment methods that don't pay it.
 */
function wedding_booking_render_payment_fee_card()
{
    $fee_pct   = function_exists('wedding_booking_get_payment_fee_pct') ? wedding_booking_get_payment_fee_pct() : 0;
    $fee_label = (string) wedding_booking_opt('wedding_booking_payment_fee_label');
    $exempt    = (array) wedding_booking_opt('wedding_booking_payment_fee_exempt_gateways');
    $needs_fee = __('Used only when the fee is above 0.', 'wedding-booking');

    wedding_booking_settings_card_open(__('Payment fee', 'wedding-booking'), __('Pass card or PayPal processing costs on to the customer.', 'wedding-booking'), 'wedding-booking-fee-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Payment fee', 'wedding-booking'),
        __('An extra percentage added on top to cover card or PayPal charges. Example: with 3, a 100.00 booking costs 103.00. Customers see it as its own line before paying. 0 = no fee.', 'wedding-booking'),
        'wedding-booking-payment-fee-pct'
    );
    echo '<label class="wedding-booking-inline-num" for="wedding-booking-payment-fee-pct"><input id="wedding-booking-payment-fee-pct" class="small-text" type="number" min="0" max="100" step="0.01" inputmode="decimal" name="wedding_booking_payment_fee_pct" value="' . esc_attr(0 + $fee_pct) . '"> <span>' . esc_html__('% added on top of the booking total', 'wedding-booking') . '</span></label>';
    echo '<p class="description">' . esc_html__('0 turns the fee off.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Fee name', 'wedding-booking'),
        __('What customers see next to the fee, e.g. “Card processing fee”.', 'wedding-booking'),
        'wedding-booking-payment-fee-label',
        'wedding_booking_payment_fee_pct',
        $needs_fee
    );
    echo '<input id="wedding-booking-payment-fee-label" class="regular-text" type="text" name="wedding_booking_payment_fee_label" value="' . esc_attr($fee_label) . '" placeholder="' . esc_attr__('Payment fee', 'wedding-booking') . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('No fee for these payment methods', 'wedding-booking'),
        __('Customers who pay with a ticked method are not charged the fee — usually bank transfer, cheque and cash, which cost you nothing to accept. Only methods switched on in WooCommerce → Settings → Payments are listed.', 'wedding-booking'),
        '',
        'wedding_booking_payment_fee_pct',
        $needs_fee
    );
    echo '<input type="hidden" name="wedding_booking_payment_fee_exempt_gateways[]" value="">';
    $gateways = [];
    if (function_exists('WC') && WC() && WC()->payment_gateways()) {
        foreach ((array) WC()->payment_gateways()->payment_gateways() as $gid => $gateway) {
            if (is_object($gateway) && isset($gateway->enabled) && 'yes' === $gateway->enabled) {
                $gateways[(string) $gid] = wp_strip_all_tags((string) $gateway->get_title());
            }
        }
    }
    if ($gateways) {
        echo '<div class="wedding-booking-checklist">';
        foreach ($gateways as $gid => $title) {
            echo '<label class="wedding-booking-checklist-item"><input type="checkbox" name="wedding_booking_payment_fee_exempt_gateways[]" value="' . esc_attr($gid) . '"' . checked(in_array($gid, $exempt, true), true, false) . '> ' . esc_html($title !== '' ? $title : $gid) . ' <code>' . esc_html($gid) . '</code></label>';
        }
        echo '</div>';
    } else {
        echo '<p class="description">' . esc_html__('No payment methods are switched on in WooCommerce yet.', 'wedding-booking') . '</p>';
    }
    // Methods that are switched off right now keep their setting.
    foreach ($exempt as $gid) {
        if (! isset($gateways[$gid])) {
            echo '<input type="hidden" name="wedding_booking_payment_fee_exempt_gateways[]" value="' . esc_attr($gid) . '">';
        }
    }
    echo '<p class="description">' . esc_html__('When a customer pays with a ticked method, the fee comes off before they pay.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/**
 * Offline payments: how long an unpaid bank transfer / cheque / cash booking
 * keeps its date.
 */
function wedding_booking_render_offline_payments_card()
{
    $days = (int) wedding_booking_opt('wedding_booking_offline_hold_days');

    wedding_booking_settings_card_open(__('Offline payments', 'wedding-booking'), __('Bookings paid by bank transfer, cheque or cash hold their date as “Awaiting payment” until you record the payment.', 'wedding-booking'), 'wedding-booking-offline-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Unpaid bookings', 'wedding-booking'),
        __('A bank transfer, cheque or cash booking keeps its date while you wait for the money (record it from Bookings → Actions → Record payment). Set a number of days to cancel it automatically — freeing the date — if it is still unpaid by then. 0 = never cancel automatically.', 'wedding-booking'),
        'wedding-booking-offline-hold-days'
    );
    echo wedding_booking_settings_inline_number('wedding_booking_offline_hold_days', $days, 0, 90, __('Cancel unpaid bank-transfer, cheque and cash bookings after', 'wedding-booking'), __('days', 'wedding-booking'), 'wedding-booking-offline-hold-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = never. Bookings you add by hand as unpaid are never cancelled automatically.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

function wedding_booking_render_promo_codes_card()
{
    $coupons_on = (int) wedding_booking_opt('wedding_booking_coupons_enable') === 1;

    wedding_booking_settings_card_open(__('Promo codes', 'wedding-booking'), __('Discount codes customers can enter while booking.', 'wedding-booking'), 'wedding-booking-coupons-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Promo codes', 'wedding-booking'),
        __('Adds a “Promo code” box to the booking form. Codes are ordinary WooCommerce coupons — create them under Marketing → Coupons. Each code\'s discount and usage is recorded on the order.', 'wedding-booking')
    );
    echo '<input type="hidden" name="wedding_booking_coupons_enable" value="0">';
    echo wedding_booking_toggle_field('wedding_booking_coupons_enable', __('Show a promo code field on the booking form', 'wedding-booking'), $coupons_on, __('Codes are your WooCommerce coupons (Marketing → Coupons).', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    if (function_exists('wc_coupons_enabled') && ! wc_coupons_enabled()) {
        echo '<p class="wedding-booking-set-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <span>' . sprintf(
            /* translators: %s: link to WooCommerce → Settings → General */
            esc_html__('Coupons are switched off in WooCommerce, so no promo code field shows until you turn on “Enable the use of coupon codes” in %s.', 'wedding-booking'),
            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">' . esc_html__('WooCommerce → Settings → General', 'wedding-booking') . '</a>'
        ) . '</span></p>';
    }
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — AVAILABILITY
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_availability()
{
    global $wp_locale;
    $capacity   = (int) wedding_booking_opt('wedding_booking_daily_capacity');
    $slots      = (array) wedding_booking_opt('wedding_booking_time_slots');
    $min_notice = (int) wedding_booking_opt('wedding_booking_min_notice_days');
    $max_adv    = (int) wedding_booking_opt('wedding_booking_max_advance_days');
    $closed     = array_map('intval', (array) wedding_booking_opt('wedding_booking_closed_weekdays'));
    $hold       = (int) wedding_booking_opt('wedding_booking_hold_minutes');
    $week_start = (int) get_option('start_of_week', 0);

    echo '<p class="wedding-booking-set-pointer"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <span>' . sprintf(
        /* translators: %s: link to Wedding Booking → Date Slots */
        esc_html__('These rules apply every week. To close one particular date — a holiday, say — click it under %s.', 'wedding-booking'),
        '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-dates')) . '">' . esc_html__('Date Slots', 'wedding-booking') . '</a>'
    ) . '</span></p>';

    // ── Days and times ─
    wedding_booking_settings_card_open(__('Days and times', 'wedding-booking'), __('When you take sessions, and how many.', 'wedding-booking'), 'wedding-booking-availability-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Start times', 'wedding-booking'),
        __('Offer fixed start times, e.g. 09:00, 13:00, 17:30. Customers then choose a time, and each time can be booked once per day. Leave empty to book whole days (no time choice).', 'wedding-booking'),
        'wedding-booking-time-slots'
    );
    echo '<input id="wedding-booking-time-slots" class="regular-text" type="text" name="wedding_booking_time_slots" value="' . esc_attr(implode(', ', $slots)) . '" placeholder="09:00, 13:00, 17:30" autocomplete="off">';
    echo '<p class="description">' . esc_html__('Separate times with commas (2pm also works). Leave empty for whole-day bookings.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Bookings per day', 'wedding-booking'),
        __('How many sessions you accept on the same date. Once a date has this many bookings it shows as fully booked. Only used when you don\'t offer start times.', 'wedding-booking'),
        'wedding-booking-daily-capacity',
        '!wedding_booking_time_slots',
        __('Not used while start times are set — each start time is one booking.', 'wedding-booking')
    );
    echo wedding_booking_settings_inline_number('wedding_booking_daily_capacity', $capacity, 1, 50, '', __('booking(s) on the same date', 'wedding-booking'), 'wedding-booking-daily-capacity'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Closed weekdays', 'wedding-booking'),
        __('Days of the week you never work. Customers can\'t pick them on the calendar. You can still add a booking on them yourself.', 'wedding-booking')
    );
    echo '<input type="hidden" name="wedding_booking_closed_weekdays[]" value="">';
    echo '<div class="wedding-booking-checklist wedding-booking-checklist-inline">';
    for ($i = 0; $i < 7; $i++) {
        $day  = ($week_start + $i) % 7;
        $name = $wp_locale ? $wp_locale->get_weekday($day) : gmdate('l', strtotime('Sunday +' . $day . ' days'));
        echo '<label class="wedding-booking-checklist-item"><input type="checkbox" name="wedding_booking_closed_weekdays[]" value="' . (int) $day . '"' . checked(in_array($day, $closed, true), true, false) . '> ' . esc_html($name) . '</label>';
    }
    echo '</div>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    // ── Booking window ─
    wedding_booking_settings_card_open(__('Booking window', 'wedding-booking'), __('How soon and how far ahead customers can book.', 'wedding-booking'), 'wedding-booking-window-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Minimum notice', 'wedding-booking'),
        __('Stops last-minute bookings. Example: with 2, the earliest date a customer can pick is the day after tomorrow. 0 = same-day bookings allowed.', 'wedding-booking'),
        'wedding-booking-min-notice-days'
    );
    echo wedding_booking_settings_inline_number('wedding_booking_min_notice_days', $min_notice, 0, 365, __('Book at least', 'wedding-booking'), __('days ahead', 'wedding-booking'), 'wedding-booking-min-notice-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 allows bookings for today.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Furthest bookable day', 'wedding-booking'),
        __('How far into the future customers can book. Example: 365 = up to a year ahead; later dates are greyed out. 0 = no limit.', 'wedding-booking'),
        'wedding-booking-max-advance-days'
    );
    echo wedding_booking_settings_inline_number('wedding_booking_max_advance_days', $max_adv, 0, 1095, __('Up to', 'wedding-booking'), __('days ahead', 'wedding-booking'), 'wedding-booking-max-advance-days'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = no limit.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Hold a date while the customer pays', 'wedding-booking'),
        __('Reserves the date (or start time) while a customer is on the payment step, so two people can\'t pay for the same slot. The hold ends when they pay, or after this many minutes. 0 = no hold.', 'wedding-booking'),
        'wedding-booking-hold-minutes'
    );
    echo wedding_booking_settings_inline_number('wedding_booking_hold_minutes', $hold, 0, 1440, '', __('minutes', 'wedding-booking'), 'wedding-booking-hold-minutes'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
    echo '<p class="description">' . esc_html__('0 = no hold.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — CHECKOUT
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_checkout()
{
    $checkout_mode = function_exists('wedding_booking_get_checkout_mode') ? wedding_booking_get_checkout_mode() : 'direct';
    $cf_catalog    = function_exists('wedding_booking_checkout_field_catalog') ? wedding_booking_checkout_field_catalog() : [];
    $cf_fields     = function_exists('wedding_booking_get_checkout_form_fields') ? wedding_booking_get_checkout_form_fields() : [];

    // ── Checkout style ─
    wedding_booking_settings_card_open(__('Checkout style', 'wedding-booking'), __('Where customers enter their details and pay.', 'wedding-booking'), 'wedding-booking-checkout-card');
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Checkout mode', 'wedding-booking'),
        __('Multi-step form (recommended): customers type their details inside the booking form and only see WooCommerce\'s payment screen at the end. Classic: customers are sent to your normal WooCommerce checkout page to fill in their details and pay.', 'wedding-booking'),
        'wedding-booking-checkout-mode'
    );
    echo '<select id="wedding-booking-checkout-mode" name="wedding_booking_checkout_mode">';
    echo '<option value="direct"' . selected('direct', $checkout_mode, false) . '>' . esc_html__('Multi-step form — details collected in the booking form, customer pays on the WooCommerce payment page', 'wedding-booking') . '</option>';
    echo '<option value="redirect"' . selected('redirect', $checkout_mode, false) . '>' . esc_html__('Classic — send customers to the WooCommerce checkout page to fill details and pay', 'wedding-booking') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Multi-step is recommended: customers never leave the booking flow until they pay.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    // ── Details form fields ─
    wedding_booking_settings_card_open(__('Details form', 'wedding-booking'), __('The fields customers fill in on the Details step. The same choices apply to the WooCommerce checkout page.', 'wedding-booking'), 'wedding-booking-fields-card');

    if (! empty($cf_fields)) {
        echo '<table class="widefat striped wedding-booking-cf-table"><thead><tr>';
        echo '<th>' . esc_html__('Field', 'wedding-booking') . '</th>';
        echo '<th class="wedding-booking-cf-check-col"><span class="wedding-booking-th-inline">' . esc_html__('Show', 'wedding-booking') . wedding_booking_help_tip(__('Untick to remove the field from the form. Fields marked “always on” are needed to create the order.', 'wedding-booking')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
        echo '<th class="wedding-booking-cf-check-col"><span class="wedding-booking-th-inline">' . esc_html__('Required', 'wedding-booking') . wedding_booking_help_tip(__('Customers can\'t continue until a required field is filled in.', 'wedding-booking')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
        echo '<th><span class="wedding-booking-th-inline">' . esc_html__('Label', 'wedding-booking') . wedding_booking_help_tip(__('The field\'s name as customers see it. Rename it to suit you, e.g. “Phone” → “WhatsApp number”.', 'wedding-booking')) . '</span></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
        echo '</tr></thead><tbody>';
        foreach ($cf_fields as $key => $f) {
            $locked = ! empty($cf_catalog[$key]['locked']);
            echo '<tr>';
            echo '<td>' . esc_html($cf_catalog[$key]['label']) . ($locked ? ' <span class="description">(' . esc_html__('always on', 'wedding-booking') . ')</span>' : '') . '</td>';

            echo '<td class="wedding-booking-cf-check-col">';
            if ($locked) {
                echo '<input type="hidden" name="wedding_booking_cf_enabled[' . esc_attr($key) . ']" value="1"><input type="checkbox" checked disabled>';
            } else {
                echo '<input type="hidden" name="wedding_booking_cf_enabled[' . esc_attr($key) . ']" value="0">';
                echo '<input type="checkbox" name="wedding_booking_cf_enabled[' . esc_attr($key) . ']" value="1"' . checked(1, $f['enabled'], false) . ' aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('Show %s', 'wedding-booking'), $cf_catalog[$key]['label'])) . '">';
            }
            echo '</td>';

            echo '<td class="wedding-booking-cf-check-col">';
            if ($locked) {
                echo '<input type="hidden" name="wedding_booking_cf_required[' . esc_attr($key) . ']" value="1"><input type="checkbox" checked disabled>';
            } else {
                echo '<input type="hidden" name="wedding_booking_cf_required[' . esc_attr($key) . ']" value="0">';
                echo '<input type="checkbox" name="wedding_booking_cf_required[' . esc_attr($key) . ']" value="1"' . checked(1, $f['required'], false) . ' aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('%s is required', 'wedding-booking'), $cf_catalog[$key]['label'])) . '">';
            }
            echo '</td>';

            echo '<td><input type="text" class="regular-text wedding-booking-cf-label-input" name="wedding_booking_cf_label[' . esc_attr($key) . ']" value="' . esc_attr($f['label']) . '" aria-label="' . esc_attr(sprintf(/* translators: %s: field name */ __('Label for %s', 'wedding-booking'), $cf_catalog[$key]['label'])) . '"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── Custom fields (admin can add / remove) ─
    $ccf_types  = function_exists('wedding_booking_custom_checkout_field_types') ? wedding_booking_custom_checkout_field_types() : [];
    $ccf_fields = function_exists('wedding_booking_get_custom_checkout_fields') ? wedding_booking_get_custom_checkout_fields() : [];

    $ccf_type_options = '';
    foreach ($ccf_types as $type_key => $type_label) {
        $ccf_type_options .= '<option value="' . esc_attr($type_key) . '">' . esc_html($type_label) . '</option>';
    }

    echo '<h4 class="wedding-booking-ccf-heading">' . esc_html__('Your own fields', 'wedding-booking');
    echo wedding_booking_help_tip(__('Ask for anything extra, e.g. “Shoot location” or “How did you hear about us?”. Answers are saved with the order. Removing a field here deletes it when you save.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    echo '</h4>';
    echo '<p class="description">' . esc_html__('Add questions of your own to the Details step.', 'wedding-booking') . '</p>';
    echo '<table class="widefat striped wedding-booking-cf-table" id="wedding-booking-ccf-table"><thead><tr>';
    echo '<th>' . esc_html__('Label', 'wedding-booking') . '</th>';
    echo '<th class="wedding-booking-ccf-type-col">' . esc_html__('Type', 'wedding-booking') . '</th>';
    echo '<th class="wedding-booking-cf-check-col">' . esc_html__('Required', 'wedding-booking') . '</th>';
    echo '<th class="wedding-booking-ccf-action-col">' . esc_html__('Action', 'wedding-booking') . '</th>';
    echo '</tr></thead><tbody id="wedding-booking-ccf-rows">';
    foreach ($ccf_fields as $key => $f) {
        echo '<tr class="wedding-booking-ccf-row">';
        echo '<td><input type="text" class="regular-text wedding-booking-cf-label-input" name="wedding_booking_ccf_label[' . esc_attr($key) . ']" value="' . esc_attr($f['label']) . '"></td>';
        echo '<td><select name="wedding_booking_ccf_type[' . esc_attr($key) . ']">';
        foreach ($ccf_types as $type_key => $type_label) {
            echo '<option value="' . esc_attr($type_key) . '"' . selected($f['type'], $type_key, false) . '>' . esc_html($type_label) . '</option>';
        }
        echo '</select></td>';
        echo '<td class="wedding-booking-cf-check-col">';
        echo '<input type="hidden" name="wedding_booking_ccf_required[' . esc_attr($key) . ']" value="0">';
        echo '<input type="checkbox" name="wedding_booking_ccf_required[' . esc_attr($key) . ']" value="1"' . checked(1, $f['required'], false) . '>';
        echo '</td>';
        echo '<td class="wedding-booking-ccf-action-col"><button type="button" class="button button-link-delete wedding-booking-ccf-remove">' . esc_html__('Remove', 'wedding-booking') . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="wedding-booking-ccf-actions"><button type="button" class="button" id="wedding-booking-ccf-add">+ ' . esc_html__('Add Field', 'wedding-booking') . '</button></p>';

    // Row template for the Add Field button (admin.js replaces __KEY__).
    echo '<script type="text/template" id="wedding-booking-ccf-row-template">';
    echo '<tr class="wedding-booking-ccf-row">';
    echo '<td><input type="text" class="regular-text wedding-booking-cf-label-input" name="wedding_booking_ccf_label[__KEY__]" value="" placeholder="' . esc_attr__('Field label', 'wedding-booking') . '"></td>';
    echo '<td><select name="wedding_booking_ccf_type[__KEY__]">' . $ccf_type_options . '</select></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<td class="wedding-booking-cf-check-col">';
    echo '<input type="hidden" name="wedding_booking_ccf_required[__KEY__]" value="0">';
    echo '<input type="checkbox" name="wedding_booking_ccf_required[__KEY__]" value="1">';
    echo '</td>';
    echo '<td class="wedding-booking-ccf-action-col"><button type="button" class="button button-link-delete wedding-booking-ccf-remove">' . esc_html__('Remove', 'wedding-booking') . '</button></td>';
    echo '</tr>';
    echo '</script>';
    wedding_booking_settings_card_close();

    // ── Messages after booking ─
    $confirm_title         = get_option('wedding_booking_confirm_title', __('Booking Confirmed!', 'wedding-booking'));
    $confirm_msg           = get_option('wedding_booking_confirm_msg', __('Thank you for your booking! A confirmation email has been sent to {email}.', 'wedding-booking'));
    $confirm_pending_title = get_option('wedding_booking_confirm_pending_title', __('Booking Received!', 'wedding-booking'));
    $confirm_pending_msg   = get_option('wedding_booking_confirm_pending_msg', __('Thank you for your booking! Complete the payment below to confirm your slot.', 'wedding-booking'));
    $success_title         = get_option('wedding_booking_success_title', 'Booking Requested!');
    $success_msg           = get_option('wedding_booking_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to");

    wedding_booking_settings_card_open(__('Messages after booking', 'wedding-booking'), __('What customers read on screen once their booking is placed. {email} becomes the customer\'s email address.', 'wedding-booking'), 'wedding-booking-messages-card');
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Paid — heading', 'wedding-booking'),
        __('Headline customers see once their booking is paid, e.g. straight after a card payment.', 'wedding-booking'),
        'wedding-booking-confirm-title'
    );
    echo '<input id="wedding-booking-confirm-title" class="regular-text" type="text" name="wedding_booking_confirm_title" value="' . esc_attr($confirm_title) . '" placeholder="Booking Confirmed!">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Paid — message', 'wedding-booking'),
        __('The text under that headline. {email} becomes the customer\'s email address.', 'wedding-booking'),
        'wedding-booking-confirm-msg'
    );
    echo '<input id="wedding-booking-confirm-msg" class="large-text" type="text" name="wedding_booking_confirm_msg" value="' . esc_attr($confirm_msg) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Not paid yet — heading', 'wedding-booking'),
        __('Headline when the booking is saved but the money comes later — bank transfer, cheque or cash. Your payment instructions show underneath.', 'wedding-booking'),
        'wedding-booking-confirm-pending-title'
    );
    echo '<input id="wedding-booking-confirm-pending-title" class="regular-text" type="text" name="wedding_booking_confirm_pending_title" value="' . esc_attr($confirm_pending_title) . '" placeholder="Booking Received!">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Not paid yet — message', 'wedding-booking'),
        __('The text under that headline. {email} becomes the customer\'s email address.', 'wedding-booking'),
        'wedding-booking-confirm-pending-msg'
    );
    echo '<input id="wedding-booking-confirm-pending-msg" class="large-text" type="text" name="wedding_booking_confirm_pending_msg" value="' . esc_attr($confirm_pending_msg) . '">';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';

    echo '<h4 class="wedding-booking-ccf-heading">' . esc_html__('Enquiry mode', 'wedding-booking');
    echo wedding_booking_help_tip(__('Only used when WooCommerce is off: the booking form then sends you an enquiry email instead of taking a payment, and shows this screen.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    echo '</h4>';
    echo '<p class="description">' . esc_html__('Shown after an enquiry is sent (only when WooCommerce is off).', 'wedding-booking') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Enquiry sent — heading', 'wedding-booking'),
        __('Headline customers see after sending an enquiry.', 'wedding-booking'),
        'wedding-booking-success-title'
    );
    echo '<input id="wedding-booking-success-title" class="regular-text" type="text" name="wedding_booking_success_title" value="' . esc_attr($success_title) . '" placeholder="Booking Requested!">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Enquiry sent — message', 'wedding-booking'),
        __('The text under that headline. The customer\'s email address is added at the end automatically.', 'wedding-booking'),
        'wedding-booking-success-msg'
    );
    echo '<input id="wedding-booking-success-msg" class="large-text" type="text" name="wedding_booking_success_msg" value="' . esc_attr($success_msg) . '">';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — CUSTOMERS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_customers()
{
    $require_account = (int) get_option('wedding_booking_require_account_booking', 0);
    $tab_on          = (int) wedding_booking_opt('wedding_booking_account_bookings_enable') === 1;
    $req_on          = (int) wedding_booking_opt('wedding_booking_customer_requests_enable') === 1;

    wedding_booking_settings_card_open(__('Customer account', 'wedding-booking'), __('What customers can see and do with their bookings in WooCommerce → My Account.', 'wedding-booking'), 'wedding-booking-account-card');
    echo '<input type="hidden" name="wedding_booking_require_account_booking" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Account required', 'wedding-booking'),
        __('Customers must log in or create an account before they can book, so every booking is tied to an account. It adds a step, so leave it off for the quickest booking.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_require_account_booking', __('Customers must log in or register before they book', 'wedding-booking'), $require_account === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Bookings tab', 'wedding-booking'),
        __('Adds a “Bookings” tab to WooCommerce → My Account that lists the customer\'s sessions with what is paid and what is still due.', 'wedding-booking')
    );
    echo '<input type="hidden" name="wedding_booking_account_bookings_enable" value="0">';
    echo wedding_booking_toggle_field('wedding_booking_account_bookings_enable', __('Show a “Bookings” tab in My Account', 'wedding-booking'), $tab_on, __('Lists the customer\'s sessions with what is paid and what is still due.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Change requests', 'wedding-booking'),
        __('Customers can ask to reschedule or cancel from their booking. You get an email and the request shows on the booking. Nothing changes until you change it yourself.', 'wedding-booking')
    );
    echo '<input type="hidden" name="wedding_booking_customer_requests_enable" value="0">';
    echo wedding_booking_toggle_field('wedding_booking_customer_requests_enable', __('Let customers ask to reschedule or cancel', 'wedding-booking'), $req_on, __('You get an email and the request shows on the booking.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — EMAILS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_settings_emails()
{
    $placeholders = '<code>{customer_name}</code> <code>{first_name}</code> <code>{package_name}</code> <code>{addons}</code> <code>{session_type}</code> <code>{session_date}</code> <code>{order_id}</code> <code>{total}</code> <code>{site_name}</code>';

    // ── Customer booking confirmation ─
    $order_email      = wedding_booking_get_order_email_settings();
    $order_email_file = wedding_booking_order_email_attachment_label($order_email['attachment_id']);
    $needs_custom     = __('Used only while “Custom email” is on.', 'wedding-booking');

    wedding_booking_settings_card_open(__('Customer booking confirmation', 'wedding-booking'), __('The email your customer receives after booking. When your own email is on, it replaces WooCommerce\'s wording — the customer gets your email only, not both.', 'wedding-booking'), 'wedding-booking-order-email-card');
    echo '<input type="hidden" name="wedding_booking_order_email_enable" value="0">';
    echo '<input type="hidden" name="wedding_booking_order_email_order_table" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Custom email', 'wedding-booking'),
        __('On: replace WooCommerce\'s standard order-confirmation wording with your own message for booking orders. Leave the message below empty and the standard email is sent instead.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_order_email_enable', __('Use my own content for the booking confirmation email', 'wedding-booking'), (int) $order_email['enable'] === 1, __('Admin alerts and balance reminders are not affected.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Subject', 'wedding-booking'),
        __('The subject line. Placeholders such as {order_id} work here. Leave blank to keep WooCommerce\'s subject.', 'wedding-booking'),
        'wedding-booking-order-email-subject',
        'wedding_booking_order_email_enable',
        $needs_custom
    );
    echo '<input id="wedding-booking-order-email-subject" class="large-text" type="text" name="wedding_booking_order_email_subject" value="' . esc_attr($order_email['subject']) . '">';
    echo '<p class="description">' . esc_html__('Leave blank to keep the WooCommerce subject.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Heading', 'wedding-booking'),
        __('The large title at the top of the email. Leave blank to keep WooCommerce\'s heading.', 'wedding-booking'),
        'wedding-booking-order-email-heading',
        'wedding_booking_order_email_enable',
        $needs_custom
    );
    echo '<input id="wedding-booking-order-email-heading" class="regular-text" type="text" name="wedding_booking_order_email_heading" value="' . esc_attr($order_email['heading']) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Email content', 'wedding-booking'),
        __('Your message. Placeholders are swapped for the booking\'s details when it is sent, e.g. “Hi {first_name}, see you on {session_date}!”.', 'wedding-booking'),
        'wedding_booking_order_email_message',
        'wedding_booking_order_email_enable',
        $needs_custom
    );
    // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
    wp_editor(
        $order_email['message'],
        'wedding_booking_order_email_message',
        [
            'textarea_name' => 'wedding_booking_order_email_message',
            'textarea_rows' => 12,
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description"><strong>' . esc_html__('Placeholders', 'wedding-booking') . ':</strong> ' . $placeholders . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Order details table', 'wedding-booking'),
        __('Adds the standard summary under your message: package, add-ons, totals and customer details. Untick for a completely custom email. The “pay remaining balance” button shows either way.', 'wedding-booking'),
        '',
        'wedding_booking_order_email_enable',
        $needs_custom
    );
    echo '<label><input type="checkbox" name="wedding_booking_order_email_order_table" value="1" ' . checked(1, (int) $order_email['order_table'], false) . '> ' . esc_html__('Include the booking summary table (package, add-ons, totals, customer details)', 'wedding-booking') . '</label>';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Attached file', 'wedding-booking'),
        __('A file sent with every booking confirmation — your Terms of Service PDF or a “how to prepare” guide, say. It is attached whether or not “Custom email” is on, but never to admin alerts or reminders.', 'wedding-booking')
    );
    echo '<div class="wedding-booking-media-field">';
    echo '<input type="hidden" id="wedding-booking-order-email-attachment-id" name="wedding_booking_order_email_attachment_id" value="' . esc_attr($order_email['attachment_id']) . '">';
    echo '<button type="button" class="button" id="wedding-booking-order-email-attachment-pick">' . esc_html__('Choose or upload file', 'wedding-booking') . '</button> ';
    echo '<button type="button" class="button-link button-link-delete" id="wedding-booking-order-email-attachment-remove"' . ($order_email['attachment_id'] ? '' : ' style="display:none"') . '>' . esc_html__('Remove', 'wedding-booking') . '</button>';
    echo '<p id="wedding-booking-order-email-attachment-name" class="description"><strong>' . esc_html($order_email_file) . '</strong></p>';
    echo '</div>';
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    // ── Studio new-booking alert ─
    $admin_order_email = wedding_booking_get_admin_email_settings();
    $needs_admin       = __('Used only while “Branded alert” is on.', 'wedding-booking');

    wedding_booking_settings_card_open(__('New-booking alert for you', 'wedding-booking'), __('The email you (the studio) get for each new booking.', 'wedding-booking'), 'wedding-booking-admin-email-card');
    echo '<input type="hidden" name="wedding_booking_admin_email_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';

    wedding_booking_setting_row_open(
        __('Branded alert', 'wedding-booking'),
        __('Replaces WooCommerce\'s plain “New order” email for booking orders with a branded summary: session and add-ons, the customer\'s contact details, deposit taken vs balance due, their note, and a button to open the order. Other shop orders keep the normal email.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_admin_email_enable', __('Use Wedding Booking\'s branded email for the admin New Order notification', 'wedding-booking'), (int) $admin_order_email['enable'] === 1, __('Booking orders only. WooCommerce sends it once payment is placed.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Send to', 'wedding-booking'),
        __('Who receives the alert. Separate several addresses with commas. Leave blank to use WooCommerce\'s “New order” recipient (WooCommerce → Settings → Emails).', 'wedding-booking'),
        'wedding-booking-admin-email-recipient',
        'wedding_booking_admin_email_enable',
        $needs_admin
    );
    echo '<input id="wedding-booking-admin-email-recipient" class="large-text" type="text" name="wedding_booking_admin_email_recipient" value="' . esc_attr($admin_order_email['recipient']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Subject', 'wedding-booking'),
        __('The subject line. Placeholders such as {order_id} and {package_name} work here. Leave blank to keep WooCommerce\'s subject.', 'wedding-booking'),
        'wedding-booking-admin-email-subject',
        'wedding_booking_admin_email_enable',
        $needs_admin
    );
    echo '<input id="wedding-booking-admin-email-subject" class="large-text" type="text" name="wedding_booking_admin_email_subject" value="' . esc_attr($admin_order_email['subject']) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Heading', 'wedding-booking'),
        __('The large title at the top of the email. Leave blank to keep WooCommerce\'s heading.', 'wedding-booking'),
        'wedding-booking-admin-email-heading',
        'wedding_booking_admin_email_enable',
        $needs_admin
    );
    echo '<input id="wedding-booking-admin-email-heading" class="regular-text" type="text" name="wedding_booking_admin_email_heading" value="' . esc_attr($admin_order_email['heading']) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Intro note', 'wedding-booking'),
        __('A short note shown above the booking details, e.g. a reminder to confirm the date with the client. Leave blank for none.', 'wedding-booking'),
        'wedding_booking_admin_email_intro',
        'wedding_booking_admin_email_enable',
        $needs_admin
    );
    // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
    wp_editor(
        $admin_order_email['intro'],
        'wedding_booking_admin_email_intro',
        [
            'textarea_name' => 'wedding_booking_admin_email_intro',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'teeny'         => true,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description"><strong>' . esc_html__('Placeholders', 'wedding-booking') . ':</strong> ' . $placeholders . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
    wedding_booking_setting_row_close();

    echo '</tbody></table>';
    wedding_booking_settings_card_close();

    wedding_booking_render_balance_reminder_card();
}

/**
 * Balance reminders: live status, the two automatic schedules (before the
 * shoot / until paid), and the email wording. Saved by
 * wedding_booking_save_balance_reminder_settings() (emails.php).
 */
function wedding_booking_render_balance_reminder_card()
{
    $cfg      = wedding_booking_get_balance_reminder_settings();
    $tz_label = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';

    wedding_booking_settings_card_open(__('Balance reminders', 'wedding-booking'), __('Emails customers who paid a deposit a link to pay the rest. Reminders stop by themselves as soon as the balance is paid or the booking is cancelled.', 'wedding-booking'), 'wedding-booking-reminders', 'wedding-booking-rem-card');

    wedding_booking_render_balance_reminder_status($cfg);

    echo '<div class="wedding-booking-rem-rules">';

    // ── Before the photoshoot ──
    echo '<div class="wedding-booking-rem-rule' . ($cfg['before_enable'] ? '' : ' is-off') . '">';
    echo '<input type="hidden" name="wedding_booking_enable_balance_reminders" value="0">';
    echo '<div class="wedding-booking-rem-head">';
    echo '<label class="wedding-booking-toggle">';
    echo '<input type="checkbox" name="wedding_booking_enable_balance_reminders" value="1"' . checked($cfg['before_enable'], true, false) . '>';
    echo '<span class="wedding-booking-toggle-track" aria-hidden="true"></span>';
    echo '<span class="wedding-booking-toggle-text">' . esc_html__('Reminder before the photoshoot', 'wedding-booking') . '<small>' . esc_html__('One email a set number of days before the shoot date.', 'wedding-booking') . '</small></span>';
    echo '</label>';
    echo wedding_booking_help_tip(__('Sends one email, with a link to pay, to customers who still owe a balance a set number of days before their session. It goes out at 09:00 your time.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    echo '</div>';
    echo '<div class="wedding-booking-rem-fields">';
    echo '<label class="wedding-booking-rem-inline">' . esc_html__('Send it', 'wedding-booking') . ' <input class="small-text" type="number" min="0" max="60" step="1" name="wedding_booking_balance_reminder_days_before" value="' . esc_attr($cfg['days_before']) . '"> ' . esc_html__('day(s) before the photoshoot, at 09:00', 'wedding-booking') . '</label>';
    echo '<p class="description">' . esc_html__('0 sends it on the morning of the shoot. A customer who books later than that still gets it, at least 12 hours after paying the deposit, as long as the shoot is still ahead.', 'wedding-booking') . '</p>';
    echo '</div>';
    echo '</div>';

    // ── Until the balance is paid ──
    echo '<div class="wedding-booking-rem-rule' . ($cfg['repeat_enable'] ? '' : ' is-off') . '">';
    echo '<input type="hidden" name="wedding_booking_balance_reminder_repeat_enable" value="0">';
    echo '<div class="wedding-booking-rem-head">';
    echo '<label class="wedding-booking-toggle">';
    echo '<input type="checkbox" name="wedding_booking_balance_reminder_repeat_enable" value="1"' . checked($cfg['repeat_enable'], true, false) . '>';
    echo '<span class="wedding-booking-toggle-track" aria-hidden="true"></span>';
    echo '<span class="wedding-booking-toggle-text">' . esc_html__('Keep reminding until the balance is paid', 'wedding-booking') . '<small>' . esc_html__('Repeats on a fixed interval until the customer pays.', 'wedding-booking') . '</small></span>';
    echo '</label>';
    echo wedding_booking_help_tip(__('Keeps emailing customers who still owe a balance every few days until they pay — or until the limit you set is reached, or (if ticked) the shoot date has passed. Works alongside the reminder before the photoshoot.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
    echo '</div>';
    echo '<div class="wedding-booking-rem-fields">';
    echo '<label class="wedding-booking-rem-inline">' . esc_html__('Send a reminder every', 'wedding-booking') . ' <input class="small-text" type="number" min="1" max="60" step="1" name="wedding_booking_balance_reminder_repeat_days" value="' . esc_attr($cfg['repeat_days']) . '"> ' . esc_html__('day(s)', 'wedding-booking') . '</label>';
    echo '<label class="wedding-booking-rem-inline">' . esc_html__('Stop after', 'wedding-booking') . ' <input class="small-text" type="number" min="0" max="100" step="1" name="wedding_booking_balance_reminder_repeat_max" value="' . esc_attr($cfg['repeat_max']) . '"> ' . esc_html__('reminders', 'wedding-booking') . ' <span class="wedding-booking-rem-hint">' . esc_html__('(0 = no limit, keep going until paid)', 'wedding-booking') . '</span></label>';
    echo '<input type="hidden" name="wedding_booking_balance_reminder_repeat_stop_after_shoot" value="0">';
    echo '<label class="wedding-booking-rem-inline"><input type="checkbox" name="wedding_booking_balance_reminder_repeat_stop_after_shoot" value="1"' . checked($cfg['repeat_stop_after_shoot'], true, false) . '> ' . esc_html__('Stop once the photoshoot date has passed', 'wedding-booking') . '</label>';
    echo '<p class="description">' . esc_html__('The first one goes out that many days after the deposit, or after the last reminder. When you switch this on, customers who already owe a balance get their first one that many days from now, not all at once.', 'wedding-booking') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '</div>';

    echo '<p class="description wedding-booking-rem-note">' . sprintf(
        /* translators: %s: site timezone, linked to Settings → General */
        esc_html__('Reminders go out between 09:00 and 21:00 in your site timezone (%s) and never twice to the same customer within 12 hours. A reminder you send by hand from Bookings restarts the countdown.', 'wedding-booking'),
        '<a href="' . esc_url(admin_url('options-general.php')) . '">' . esc_html($tz_label) . '</a>'
    ) . '</p>';

    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Reminder email subject', 'wedding-booking'),
        __('Subject line of every reminder. The placeholders listed below work here too.', 'wedding-booking'),
        'wedding-booking-balance-reminder-subject'
    );
    echo '<input id="wedding-booking-balance-reminder-subject" class="regular-text" type="text" name="wedding_booking_balance_reminder_subject" value="' . esc_attr($cfg['subject']) . '">';
    wedding_booking_setting_row_close();

    wedding_booking_setting_row_open(
        __('Reminder email template', 'wedding-booking'),
        __('The reminder text. {pay_link} becomes the customer\'s personal payment link — keep it in so they can pay in one click. {balance_due_date} is empty unless a balance deadline is set (Payments → Deposit).', 'wedding-booking'),
        'wedding-booking-balance-reminder-template'
    );
    echo '<textarea id="wedding-booking-balance-reminder-template" class="large-text code" rows="7" name="wedding_booking_balance_reminder_template">' . esc_textarea($cfg['template']) . '</textarea>';
    echo '<p class="description">' . esc_html__('Placeholders (subject and message): {customer_name}, {balance_amount}, {balance_due_date}, {session_date}, {package_name}, {addons}, {pay_link}, {order_id}', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';

    wedding_booking_settings_card_close();
}

/**
 * Status strip at the top of the reminder card: is anything being chased,
 * what goes out next, and did the last automatic check actually run.
 * Needs WooCommerce (the engine lives in woocommerce.php).
 */
function wedding_booking_render_balance_reminder_status($cfg)
{
    if (! function_exists('wedding_booking_balance_reminder_status')) {
        return;
    }

    $st     = wedding_booking_balance_reminder_status();
    $now    = time();
    $active = $cfg['before_enable'] || $cfg['repeat_enable'];
    $last   = $st['last'];
    $stale  = $active && $last['ts'] > 0 && ($now - $last['ts']) > 3 * HOUR_IN_SECONDS;
    $fmt    = get_option('date_format') . ' ' . get_option('time_format');

    if (! $active) {
        $state = 'is-off';
        $title = __('Automatic reminders are off', 'wedding-booking');
    } elseif ($stale) {
        $state = 'is-warn';
        $title = __('Reminders may not be going out', 'wedding-booking');
    } else {
        $state = 'is-ok';
        $title = __('Automatic reminders are on', 'wedding-booking');
    }

    $lines = [];
    if ($st['outstanding'] > 0) {
        /* translators: %d: number of bookings */
        $lines[] = sprintf(_n('%d booking has an unpaid balance.', '%d bookings have an unpaid balance.', $st['outstanding'], 'wedding-booking'), $st['outstanding']);
    } else {
        $lines[] = __('No bookings have an unpaid balance right now.', 'wedding-booking');
    }

    if ($active) {
        if ($st['upcoming']) {
            $up   = $st['upcoming'];
            $when = $up['ts'] <= $now ? __('due now, goes out with the next check', 'wedding-booking') : wp_date($fmt, $up['ts']);
            $kind = $up['type'] === 'before' ? __('before the photoshoot', 'wedding-booking') : __('repeating until paid', 'wedding-booking');
            /* translators: 1: date/time, 2: customer name, 3: order number, 4: which schedule */
            $lines[] = sprintf(__('Next reminder: %1$s, to %2$s (order #%3$d, %4$s).', 'wedding-booking'), $when, $up['name'] !== '' ? $up['name'] : __('customer', 'wedding-booking'), $up['order_id'], $kind);
        } elseif ($st['outstanding'] > 0) {
            $lines[] = __('No reminder is scheduled: the shoot dates have passed or the reminder limit was reached.', 'wedding-booking');
        }

        if ($last['ts'] > 0) {
            $triggers = [
                'cron'     => __('by WP-Cron', 'wedding-booking'),
                'fallback' => __('during a page visit', 'wedding-booking'),
                'manual'   => __('run by hand', 'wedding-booking'),
            ];
            /* translators: 1: time since, 2: how it ran, 3: bookings checked, 4: reminders sent */
            $lines[] = sprintf(__('Last check: %1$s ago (%2$s). %3$d checked, %4$d sent.', 'wedding-booking'), human_time_diff($last['ts'], $now), $triggers[$last['trigger']] ?? $last['trigger'], (int) $last['checked'], (int) $last['sent']);
        } else {
            $lines[] = __('No check has run yet. The first runs within the hour, or use the button.', 'wedding-booking');
        }
    }

    $warn = '';
    if ($stale) {
        /* translators: %s: time since the last check */
        $warn = sprintf(__('The last check was %s ago. Checks run hourly when the site gets visits, so this can happen on a quiet site. If it keeps happening, WP-Cron is probably blocked: ask your host to call wp-cron.php every 5–15 minutes.', 'wedding-booking'), human_time_diff($last['ts'], $now));
    } elseif ($active && $st['cron_disabled']) {
        $warn = __('WP-Cron is switched off on this site (DISABLE_WP_CRON), so checks rely on your server\'s cron job. Without one, Wedding Booking still checks during normal page visits.', 'wedding-booking');
    }

    echo '<div class="wedding-booking-rem-status ' . esc_attr($state) . '">';
    echo '<div class="wedding-booking-rem-status-main">';
    echo '<strong class="wedding-booking-rem-status-title">' . esc_html($title) . '</strong>';
    echo '<ul class="wedding-booking-rem-status-list">';
    foreach ($lines as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul>';
    if ($warn !== '') {
        echo '<p class="wedding-booking-rem-status-warn">' . esc_html($warn) . '</p>';
    }
    echo '<p class="wedding-booking-rem-run-msg" id="wedding-booking-rem-run-msg" aria-live="polite"></p>';
    echo '</div>';
    if ($active) {
        echo '<div class="wedding-booking-rem-status-actions"><button type="button" class="button" id="wedding-booking-rem-run">' . esc_html__('Run reminder check now', 'wedding-booking') . '</button>';
        echo wedding_booking_help_tip(__('Checks right away for customers who are due a reminder and sends them, instead of waiting for the next hourly check. Nobody gets a duplicate.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_help_tip.
        echo '</div>';
    }
    echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — GOOGLE CALENDAR
═══════════════════════════════════════════════════════════════ */
function wedding_booking_render_gcal_card()
{
    $gcal_connected   = function_exists('wedding_booking_gcal_is_connected') && wedding_booking_gcal_is_connected();
    $gcal_conn        = function_exists('wedding_booking_gcal_get_connection') ? wedding_booking_gcal_get_connection() : [];
    $gcal_enabled     = (int) get_option('wedding_booking_gcal_enabled', 1);
    $gcal_error       = get_option('wedding_booking_gcal_last_error', '');
    $gcal_has_creds   = function_exists('wedding_booking_gcal_has_credentials') && wedding_booking_gcal_has_credentials();
    $gcal_creds_const = function_exists('wedding_booking_gcal_creds_from_constant') && wedding_booking_gcal_creds_from_constant();
    $gcal_client_id   = get_option('wedding_booking_gcal_client_id', '');
    $gcal_client_sec  = get_option('wedding_booking_gcal_client_secret', '');
    $gcal_redirect    = function_exists('wedding_booking_gcal_redirect_uri') ? wedding_booking_gcal_redirect_uri() : '';

    wedding_booking_settings_card_open(__('Google Calendar', 'wedding-booking'), __('Each paid booking becomes a calendar event: the package and order number as the title, the client invited as a guest, their location, an alert 2 hours before, and the session and contact details in the notes. Connect once with a single click — there are no access tokens to copy.', 'wedding-booking'), 'wedding-booking-gcal');

    if ($gcal_connected) {
        $gcal_email     = isset($gcal_conn['email']) ? $gcal_conn['email'] : '';
        $disconnect_url = wp_nonce_url(admin_url('admin-post.php?action=wedding_booking_gcal_disconnect'), 'wedding_booking_gcal_disconnect');

        echo '<div class="wedding-booking-gcal-panel is-connected">';
        echo '<div class="wedding-booking-gcal-status">';
        echo '<span class="wedding-booking-gcal-dot is-on" aria-hidden="true"></span>';
        echo '<div class="wedding-booking-gcal-status-text"><strong>' . esc_html__('Connected', 'wedding-booking') . '</strong>';
        if ($gcal_email !== '') {
            echo '<span>' . esc_html($gcal_email) . '</span>';
        }
        echo '</div>';
        echo '<a class="button wedding-booking-gcal-disconnect" href="' . esc_url($disconnect_url) . '">' . esc_html__('Disconnect', 'wedding-booking') . '</a>';
        echo '</div>';
        if ($gcal_error !== '') {
            echo '<p class="wedding-booking-gcal-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($gcal_error) . '</p>';
        }
        echo '</div>';

        echo '<table class="form-table" role="presentation"><tbody>';
        wedding_booking_setting_row_open(
            __('Sync new bookings', 'wedding-booking'),
            __('Pause or resume adding new paid bookings to your calendar, without disconnecting. Events already in the calendar stay put.', 'wedding-booking')
        );
        echo '<input type="hidden" name="wedding_booking_gcal_enabled" value="0">';
        echo wedding_booking_toggle_field('wedding_booking_gcal_enabled', __('Add new paid bookings to Google Calendar', 'wedding-booking'), $gcal_enabled === 1, __('Turn off to pause syncing without disconnecting.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Test connection', 'wedding-booking'),
            __('Adds a sample event to today in your calendar so you can check everything works. Delete it afterwards.', 'wedding-booking')
        );
        echo '<button type="button" class="button button-secondary" id="wedding-booking-gcal-test">' . esc_html__('Send a test event', 'wedding-booking') . '</button>';
        echo '<span id="wedding-booking-gcal-test-msg" class="wedding-booking-gcal-test-msg" aria-live="polite"></span>';
        wedding_booking_setting_row_close();
        echo '</tbody></table>';
    } else {
        $connect_url = wp_nonce_url(admin_url('admin-post.php?action=wedding_booking_gcal_connect'), 'wedding_booking_gcal_connect');
        echo '<div class="wedding-booking-gcal-panel is-disconnected">';
        echo '<div class="wedding-booking-gcal-status">';
        echo '<span class="wedding-booking-gcal-dot" aria-hidden="true"></span>';
        echo '<div class="wedding-booking-gcal-status-text"><strong>' . esc_html__('Not connected', 'wedding-booking') . '</strong><span>' . esc_html__('Bookings are not being added to Google Calendar yet.', 'wedding-booking') . '</span></div>';
        echo '</div>';
        // Static Google "G" mark (literal SVG — no dynamic data to escape).
        echo '<a class="wedding-booking-gcal-connect' . ($gcal_has_creds ? '' : ' is-disabled') . '" href="' . esc_url($connect_url) . '">';
        echo '<span class="wedding-booking-gcal-g" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg></span>';
        echo '<span>' . esc_html__('Connect with Google', 'wedding-booking') . '</span>';
        echo '</a>';
        echo '</div>';
        if ($gcal_error !== '') {
            echo '<p class="wedding-booking-gcal-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($gcal_error) . '</p>';
        }

        // Google app credentials, saved right here in the backend. Hidden when
        // set via wp-config constants (nothing to edit then).
        if ($gcal_creds_const) {
            echo '<p class="wedding-booking-gcal-hint"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__('Your Google app is set in wp-config.php. Just click Connect with Google.', 'wedding-booking') . '</p>';
        } else {
            echo '<table class="form-table wedding-booking-gcal-creds" role="presentation"><tbody>';
            wedding_booking_setting_row_open(
                __('Google Client ID', 'wedding-booking'),
                __('From your Google Cloud project: APIs & Services → Credentials → your OAuth client of type “Web application”. The setup steps below walk you through it.', 'wedding-booking'),
                'wedding-booking-gcal-client-id'
            );
            echo '<input id="wedding-booking-gcal-client-id" class="large-text code" type="text" name="wedding_booking_gcal_client_id" value="' . esc_attr($gcal_client_id) . '" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__('Paste your Client ID', 'wedding-booking') . '">';
            wedding_booking_setting_row_close();

            wedding_booking_setting_row_open(
                __('Google Client Secret', 'wedding-booking'),
                __('Shown next to the Client ID in Google Cloud Console; it starts with GOCSPX-. Keep it private.', 'wedding-booking'),
                'wedding-booking-gcal-client-secret'
            );
            echo '<input id="wedding-booking-gcal-client-secret" class="large-text code" type="password" name="wedding_booking_gcal_client_secret" value="' . esc_attr($gcal_client_sec) . '" autocomplete="off" spellcheck="false" placeholder="GOCSPX-…">';
            echo '<p class="description">' . esc_html__('Paste both, click Save All Settings, then Connect with Google. Nothing else to edit.', 'wedding-booking') . '</p>';
            wedding_booking_setting_row_close();
            echo '</tbody></table>';
        }

        // Setup checklist — shown whichever way the credentials are supplied,
        // because steps 3 and 4 are configured on the Google app itself and are
        // the usual cause of a refused connection.
        echo '<div class="wedding-booking-gcal-setupnote">';
        echo '<p><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . sprintf(
            /* translators: %s: Google Cloud Console link */
            esc_html__('Set up once in Google Cloud Console (about 5 minutes) — %s:', 'wedding-booking'),
            '<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('open Google Cloud Console', 'wedding-booking') . '</a>'
        ) . '</p>';
        echo '<ol class="wedding-booking-gcal-steps">';
        echo '<li>' . esc_html__('Create an OAuth client of type "Web application" and paste its Client ID and Client Secret above.', 'wedding-booking') . '</li>';
        echo '<li>' . esc_html__('Add this exact redirect URI to that client (click the box below to copy).', 'wedding-booking') . '</li>';
        echo '<li>' . esc_html__('In APIs & Services → Library, enable the Google Calendar API for the project.', 'wedding-booking') . '</li>';
        echo '<li>' . wp_kses(
            __('In <strong>Google Auth Platform → Audience</strong>, click <strong>Publish app</strong>. Left in "Testing", Google blocks sign-in with <em>Error 403: access_denied</em> for anyone not listed under Test users, and the connection expires every 7 days.', 'wedding-booking'),
            ['strong' => [], 'em' => []]
        ) . '</li>';
        echo '</ol>';
        echo '<p class="description">' . esc_html__('Redirect URI for this site (click to copy):', 'wedding-booking') . '</p>';
        echo '<input type="text" class="large-text code wedding-booking-gcal-redirect" readonly value="' . esc_attr($gcal_redirect) . '" onclick="this.select();document.execCommand(&quot;copy&quot;);">';
        if (0 !== strpos($gcal_redirect, 'https://') && ! preg_match('#^https?://(localhost|127\.0\.0\.1)#', $gcal_redirect)) {
            echo '<p class="description wedding-booking-gcal-httpsnote"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Google needs an https site (or localhost). On this plain-http address the connection will be refused — connect from the live https site.', 'wedding-booking') . '</p>';
        }
        echo '</div>';
    }
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   SECTION — ADVANCED
═══════════════════════════════════════════════════════════════ */

/**
 * Plugin data: whether uninstalling removes everything (uninstall.php).
 */
function wedding_booking_render_plugin_data_card()
{
    $on = (int) wedding_booking_opt('wedding_booking_delete_data_on_uninstall') === 1;

    wedding_booking_settings_card_open(__('Plugin data', 'wedding-booking'), __('What happens to Wedding Booking\'s data when you delete the plugin from the Plugins screen. Deactivating never deletes anything.', 'wedding-booking'), 'wedding-booking-data-card', 'wedding-booking-danger-card');
    echo '<input type="hidden" name="wedding_booking_delete_data_on_uninstall" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('On uninstall', 'wedding-booking'),
        __('Only matters when you delete Wedding Booking from the Plugins screen. On: all Wedding Booking data is erased for good. Off: your data stays, so reinstalling picks up where you left off.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_delete_data_on_uninstall', __('Delete all Wedding Booking data when the plugin is deleted', 'wedding-booking'), $on); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '<div class="wedding-booking-set-danger"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div>';
    echo '<strong>' . esc_html__('This cannot be undone.', 'wedding-booking') . '</strong> ';
    echo esc_html__('Deleting the plugin then permanently removes every booking record, session type, package, add-on, date slot and setting, the hidden booking product, and the Google Calendar connection. WooCommerce orders (and their payments) are kept. Leave this off if you might reinstall Wedding Booking.', 'wedding-booking');
    echo '</div></div>';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    wedding_booking_settings_card_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — BOOKING FORM (slug wedding-booking-frontend)
   The form's steps (optional Contract step) and the cards shown beside
   it (How it works / Booking date calendar / Deposit). Posts normally.
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_frontend()
{
    if (! wedding_booking_can_manage()) return;

    $defaults = function_exists('wedding_booking_frontend_sidebar_defaults') ? wedding_booking_frontend_sidebar_defaults() : [];
    $saved    = false;

    if (isset($_POST['wedding_booking_frontend_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wedding_booking_frontend_nonce'])), 'wedding_booking_frontend')) {
        // Checkbox toggles (absent = 0).
        foreach (['wedding_booking_fe_hiw_enable', 'wedding_booking_fe_deposit_enable', 'wedding_booking_fe_loader_enable', 'wedding_booking_fe_contract_enable'] as $key) {
            update_option($key, absint(wp_unslash($_POST[$key] ?? 0)) === 1 ? 1 : 0);
        }
        // Single-line text titles.
        foreach (['wedding_booking_fe_hiw_title', 'wedding_booking_fe_date_title', 'wedding_booking_fe_date_sub', 'wedding_booking_fe_deposit_title', 'wedding_booking_fe_contract_step_label', 'wedding_booking_fe_contract_title', 'wedding_booking_fe_contract_sub', 'wedding_booking_fe_contract_accept_label'] as $key) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
        }
        // Multi-line text areas.
        foreach (['wedding_booking_fe_hiw_steps', 'wedding_booking_fe_deposit_text'] as $key) {
            update_option($key, sanitize_textarea_field(wp_unslash($_POST[$key] ?? '')));
        }
        // Rich text — the contract body keeps its formatting.
        update_option('wedding_booking_fe_contract_text', wp_kses_post(wp_unslash($_POST['wedding_booking_fe_contract_text'] ?? '')));
        wedding_booking_save_extra_settings($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key inside.
        $saved = true;
    }

    $s = function_exists('wedding_booking_get_frontend_sidebar') ? wedding_booking_get_frontend_sidebar() : $defaults;
    $c = function_exists('wedding_booking_get_contract_settings') ? wedding_booking_get_contract_settings() : [];

    wedding_booking_wrap_open(__('Booking Form', 'wedding-booking'), 'wedding-booking-frontend', __('Change the steps customers go through and the cards shown beside the form. Hover or tap the ? beside any setting to see what it does.', 'wedding-booking'));

    if ($saved) {
        echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__('Booking form settings saved.', 'wedding-booking') . '</p></div>';
    }

    echo '<form method="post" id="wedding-booking-frontend-form" class="wedding-booking-settings-page wedding-booking-fe-page">';
    wp_nonce_field('wedding_booking_frontend', 'wedding_booking_frontend_nonce');

    // ── The flow at a glance ─
    $contract_on = $c && (int) $c['enable'] === 1;
    echo '<div class="card wedding-booking-settings-card wedding-booking-flow-card">';
    echo '<h2>' . esc_html__('What customers go through', 'wedding-booking') . '</h2>';
    echo '<ol class="wedding-booking-flow" aria-label="' . esc_attr__('Booking form steps', 'wedding-booking') . '">';
    echo '<li class="wedding-booking-flow-step"><span class="wedding-booking-flow-num">1</span><span class="wedding-booking-flow-name">' . esc_html__('Package', 'wedding-booking') . '</span><small>' . esc_html__('Package, add-ons, date', 'wedding-booking') . '</small></li>';
    echo '<li class="wedding-booking-flow-step"><span class="wedding-booking-flow-num">2</span><span class="wedding-booking-flow-name">' . esc_html__('Details', 'wedding-booking') . '</span><small>' . esc_html__('Name, email, phone…', 'wedding-booking') . '</small></li>';
    if ($c) {
        echo '<li class="wedding-booking-flow-step wedding-booking-flow-contract' . ($contract_on ? '' : ' is-off') . '"><span class="wedding-booking-flow-num">3</span><span class="wedding-booking-flow-name">' . esc_html($c['step_label'] !== '' ? $c['step_label'] : __('Contract', 'wedding-booking')) . '</span><small>' . esc_html__('Optional — see below', 'wedding-booking') . '</small></li>';
    }
    echo '<li class="wedding-booking-flow-step"><span class="wedding-booking-flow-num wedding-booking-flow-pay-num" data-on="4" data-off="3">' . ($contract_on ? '4' : '3') . '</span><span class="wedding-booking-flow-name">' . esc_html__('Payment', 'wedding-booking') . '</span><small>' . esc_html__('Deposit or full amount', 'wedding-booking') . '</small></li>';
    echo '</ol>';
    echo '<p class="description">' . esc_html__('Customers choose their date from the calendar card beside the form. The fields on the Details step, the messages after booking and the colors are in Settings.', 'wedding-booking');
    if (current_user_can('manage_options')) {
        echo ' <a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#checkout')) . '">' . esc_html__('Checkout settings', 'wedding-booking') . '</a> &middot; <a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#general')) . '">' . esc_html__('Colors', 'wedding-booking') . '</a>';
    }
    echo '</p>';
    echo '</div>';

    // ── Contract step ─
    if ($c) {
        $needs_contract = __('Used only while the contract step is on.', 'wedding-booking');

        echo '<h2 class="wedding-booking-group-title">' . esc_html__('Steps', 'wedding-booking') . '</h2>';
        echo '<div class="card wedding-booking-settings-card" id="wedding-booking-contract-card">';
        echo '<h3>' . esc_html__('Contract step', 'wedding-booking') . '</h3>';
        echo '<p class="description">' . esc_html__('A step between Details and Payment where the customer reads your Terms & Conditions and must accept them before paying.', 'wedding-booking') . '</p>';
        echo '<input type="hidden" name="wedding_booking_fe_contract_enable" value="0">';
        echo '<table class="form-table" role="presentation"><tbody>';

        wedding_booking_setting_row_open(
            __('Show step', 'wedding-booking'),
            __('Adds a step before payment where the customer reads your terms and ticks a box to accept them. They can\'t pay until they do. Off by default.', 'wedding-booking')
        );
        echo wedding_booking_toggle_field('wedding_booking_fe_contract_enable', __('Add the contract step to the booking form', 'wedding-booking'), (int) $c['enable'] === 1, __('When off, the form is Package → Details → Payment.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Step name', 'wedding-booking'),
            __('The short name in the step indicator at the top of the form, e.g. “Contract” or “Terms”.', 'wedding-booking'),
            'wedding-booking-fe-contract-step-label',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="wedding-booking-fe-contract-step-label" class="regular-text" type="text" name="wedding_booking_fe_contract_step_label" value="' . esc_attr($c['step_label']) . '">';
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Heading', 'wedding-booking'),
            __('The title at the top of the contract step.', 'wedding-booking'),
            'wedding-booking-fe-contract-title',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="wedding-booking-fe-contract-title" class="regular-text" type="text" name="wedding_booking_fe_contract_title" value="' . esc_attr($c['title']) . '">';
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Subtitle', 'wedding-booking'),
            __('A line under the heading. Leave blank to hide it.', 'wedding-booking'),
            'wedding-booking-fe-contract-sub',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="wedding-booking-fe-contract-sub" class="regular-text" type="text" name="wedding_booking_fe_contract_sub" value="' . esc_attr($c['sub']) . '">';
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Terms & Conditions', 'wedding-booking'),
            __('Your full agreement. It shows in a scrollable box on the booking form. If you change it later, each booking still records which version its customer accepted.', 'wedding-booking'),
            'wedding_booking_fe_contract_text',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        // Editor ID uses underscores — wp_editor/TinyMCE misbehave with hyphens.
        wp_editor(
            $c['text'],
            'wedding_booking_fe_contract_text',
            [
                'textarea_name' => 'wedding_booking_fe_contract_text',
                'textarea_rows' => 14,
                'media_buttons' => false,
                'quicktags'     => true,
            ]
        );
        wedding_booking_setting_row_close();

        wedding_booking_setting_row_open(
            __('Acceptance text', 'wedding-booking'),
            __('The label beside the box the customer must tick to continue.', 'wedding-booking'),
            'wedding-booking-fe-contract-accept',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        echo '<input id="wedding-booking-fe-contract-accept" class="large-text" type="text" name="wedding_booking_fe_contract_accept_label" value="' . esc_attr($c['accept_label']) . '">';
        wedding_booking_setting_row_close();

        $sig_on = function_exists('wedding_booking_opt') && (int) wedding_booking_opt('wedding_booking_fe_contract_signature') === 1;
        wedding_booking_setting_row_open(
            __('Signature', 'wedding-booking'),
            __('Also ask the customer to type their full name as a signature. Every booking keeps a record of the acceptance: date and time, the version of the wording, the signature and the IP address — see the booking\'s View window.', 'wedding-booking'),
            '',
            'wedding_booking_fe_contract_enable',
            $needs_contract
        );
        echo '<input type="hidden" name="wedding_booking_fe_contract_signature" value="0">';
        echo wedding_booking_toggle_field('wedding_booking_fe_contract_signature', __('Require a typed signature', 'wedding-booking'), $sig_on, __('The customer types their full name to sign, as well as ticking the box.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
        wedding_booking_setting_row_close();

        echo '</tbody></table>';
        echo '</div>';
    }

    echo '<h2 class="wedding-booking-group-title">' . esc_html__('Cards beside the form', 'wedding-booking') . '</h2>';

    // ── How it works ─
    echo '<div class="card wedding-booking-settings-card" id="wedding-booking-hiw-card">';
    echo '<h3>' . esc_html__('How it works', 'wedding-booking') . '</h3>';
    echo '<p class="description">' . esc_html__('A short numbered list explaining how booking works.', 'wedding-booking') . '</p>';
    echo '<input type="hidden" name="wedding_booking_fe_hiw_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Show card', 'wedding-booking'),
        __('Shows a numbered “How it works” list beside the booking form. Turn it off to hide the card.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_fe_hiw_enable', __('Show this card', 'wedding-booking'), (int) $s['hiw_enable'] === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();
    wedding_booking_setting_row_open(
        __('Title', 'wedding-booking'),
        __('The card\'s heading.', 'wedding-booking'),
        'wedding-booking-fe-hiw-title',
        'wedding_booking_fe_hiw_enable',
        __('Used only while this card is shown.', 'wedding-booking')
    );
    echo '<input id="wedding-booking-fe-hiw-title" class="regular-text" type="text" name="wedding_booking_fe_hiw_title" value="' . esc_attr($s['hiw_title']) . '">';
    wedding_booking_setting_row_close();
    wedding_booking_setting_row_open(
        __('Steps', 'wedding-booking'),
        __('Write one step per line — they are numbered automatically. {deposit_pct} becomes your deposit percentage.', 'wedding-booking'),
        'wedding-booking-fe-hiw-steps',
        'wedding_booking_fe_hiw_enable',
        __('Used only while this card is shown.', 'wedding-booking')
    );
    echo '<textarea id="wedding-booking-fe-hiw-steps" class="large-text code" rows="6" name="wedding_booking_fe_hiw_steps">' . esc_textarea($s['hiw_steps']) . '</textarea>';
    echo '<p class="description">' . esc_html__('One step per line.', 'wedding-booking') . '</p>';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Booking date (calendar card) ─
    echo '<div class="card wedding-booking-settings-card" id="wedding-booking-date-card">';
    echo '<h3>' . esc_html__('Booking date', 'wedding-booking') . '</h3>';
    echo '<p class="description">' . esc_html__('The calendar card where customers pick their session date. It is always shown; only its text can be changed.', 'wedding-booking') . '</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Title', 'wedding-booking'),
        __('Heading of the calendar card, e.g. “Choose your date”.', 'wedding-booking'),
        'wedding-booking-fe-date-title'
    );
    echo '<input id="wedding-booking-fe-date-title" class="regular-text" type="text" name="wedding_booking_fe_date_title" value="' . esc_attr($s['date_title']) . '">';
    wedding_booking_setting_row_close();
    wedding_booking_setting_row_open(
        __('Subtitle', 'wedding-booking'),
        __('A line under the heading. Leave blank to hide it.', 'wedding-booking'),
        'wedding-booking-fe-date-sub'
    );
    echo '<input id="wedding-booking-fe-date-sub" class="regular-text" type="text" name="wedding_booking_fe_date_sub" value="' . esc_attr($s['date_sub']) . '">';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Deposit card ─
    $needs_deposit_card = __('Used only while this card is shown.', 'wedding-booking');
    echo '<div class="card wedding-booking-settings-card" id="wedding-booking-deposit-info-card">';
    echo '<h3>' . esc_html__('Deposit card', 'wedding-booking') . '</h3>';
    echo '<p class="description">' . esc_html__('A highlighted card explaining your deposit.', 'wedding-booking');
    if (current_user_can('manage_options')) {
        echo ' ' . sprintf(
            /* translators: %s: link to Settings → Payments */
            esc_html__('The deposit amount itself is set in %s.', 'wedding-booking'),
            '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#payments')) . '">' . esc_html__('Settings → Payments', 'wedding-booking') . '</a>'
        );
    }
    echo '</p>';
    echo '<input type="hidden" name="wedding_booking_fe_deposit_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Show card', 'wedding-booking'),
        __('Shows a highlighted card beside the form that explains your deposit. Turn it off to hide the card.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_fe_deposit_enable', __('Show this card', 'wedding-booking'), (int) $s['deposit_enable'] === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();
    wedding_booking_setting_row_open(
        __('Title', 'wedding-booking'),
        __('The card\'s heading. {deposit_pct} becomes your deposit percentage, e.g. “{deposit_pct}% deposit to confirm”.', 'wedding-booking'),
        'wedding-booking-fe-deposit-title',
        'wedding_booking_fe_deposit_enable',
        $needs_deposit_card
    );
    echo '<input id="wedding-booking-fe-deposit-title" class="regular-text" type="text" name="wedding_booking_fe_deposit_title" value="' . esc_attr($s['deposit_title']) . '">';
    wedding_booking_setting_row_close();
    wedding_booking_setting_row_open(
        __('Text', 'wedding-booking'),
        __('A few lines explaining how the deposit works. {deposit_pct} works here too.', 'wedding-booking'),
        'wedding-booking-fe-deposit-text',
        'wedding_booking_fe_deposit_enable',
        $needs_deposit_card
    );
    echo '<textarea id="wedding-booking-fe-deposit-text" class="large-text" rows="4" name="wedding_booking_fe_deposit_text">' . esc_textarea($s['deposit_text']) . '</textarea>';
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    // ── Loading placeholders ─
    echo '<h2 class="wedding-booking-group-title">' . esc_html__('Loading', 'wedding-booking') . '</h2>';
    echo '<div class="card wedding-booking-settings-card" id="wedding-booking-loader-card">';
    echo '<h3>' . esc_html__('Loading placeholders', 'wedding-booking') . '</h3>';
    echo '<p class="description">' . esc_html__('What customers see for the moment the calendar and packages are loading.', 'wedding-booking') . '</p>';
    echo '<input type="hidden" name="wedding_booking_fe_loader_enable" value="0">';
    echo '<table class="form-table" role="presentation"><tbody>';
    wedding_booking_setting_row_open(
        __('Show placeholders', 'wedding-booking'),
        __('While dates and packages load, show grey animated shapes where they will appear. Off leaves the space blank until they load.', 'wedding-booking')
    );
    echo wedding_booking_toggle_field('wedding_booking_fe_loader_enable', __('Show a loading skeleton until the data arrives', 'wedding-booking'), (int) get_option('wedding_booking_fe_loader_enable', 1) === 1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    wedding_booking_setting_row_close();
    echo '</tbody></table>';
    echo '</div>';

    wedding_booking_render_savebar(__('Save Booking Form', 'wedding-booking'));
    echo '</form>';

    wedding_booking_wrap_close();
}
