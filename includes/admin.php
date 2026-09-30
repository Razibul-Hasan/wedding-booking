<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', 'wedding_booking_admin_menu');
function wedding_booking_admin_menu()
{
    // Day-to-day booking screens need manage_wedding_booking (Administrators and
    // Shop Managers). An administrator whose role somehow lacks it still
    // gets the menu through manage_options. Settings stays manage_options,
    // and WordPress hides that submenu from everyone else.
    $cap = wedding_booking_admin_menu_cap();

    add_menu_page(
        __('Wedding Booking', 'wedding-booking'),
        __('Wedding Booking', 'wedding-booking'),
        $cap,
        'wedding-booking-bookings',
        'wedding_booking_page_bookings',
        'dashicons-camera',
        30
    );
    add_submenu_page('wedding-booking-bookings', __('All Bookings', 'wedding-booking'),    __('All Bookings', 'wedding-booking'),    $cap,             'wedding-booking-bookings',       'wedding_booking_page_bookings');
    add_submenu_page('wedding-booking-bookings', __('Session Types', 'wedding-booking'),   __('Session Types', 'wedding-booking'),   $cap,             'wedding-booking-sessions',       'wedding_booking_page_sessions');
    add_submenu_page('wedding-booking-bookings', __('Packages', 'wedding-booking'),        __('Packages', 'wedding-booking'),        $cap,             'wedding-booking-packages',       'wedding_booking_page_packages');
    add_submenu_page('wedding-booking-bookings', __('Add-ons', 'wedding-booking'),         __('Add-ons', 'wedding-booking'),         $cap,             'wedding-booking-addons',         'wedding_booking_page_addons');
    add_submenu_page('wedding-booking-bookings', __('Date Slots', 'wedding-booking'),      __('Date Slots', 'wedding-booking'),      $cap,             'wedding-booking-dates',          'wedding_booking_page_dates');
    add_submenu_page('wedding-booking-bookings', __('Booking Form', 'wedding-booking'),    __('Booking Form', 'wedding-booking'),    $cap,             'wedding-booking-frontend',       'wedding_booking_page_frontend');
    add_submenu_page('wedding-booking-bookings', __('Settings', 'wedding-booking'),        __('Settings', 'wedding-booking'),        'manage_options', 'wedding-booking-settings',       'wedding_booking_page_settings');
}

/**
 * Capability the Wedding Booking booking screens are registered with for the
 * current user: manage_wedding_booking when they have it, else manage_options.
 */
function wedding_booking_admin_menu_cap()
{
    $cap = function_exists('wedding_booking_manage_cap') ? wedding_booking_manage_cap() : 'manage_options';

    return current_user_can($cap) ? $cap : 'manage_options';
}

/* ─── Admin assets ─────────────────────────────────────────── */
add_action('admin_enqueue_scripts', 'wedding_booking_admin_assets');
function wedding_booking_admin_assets($hook)
{
    if (strpos($hook, 'wedding-booking-') === false) return;
    wp_enqueue_style('wedding-booking-admin', WEDDING_BOOKING_URL . 'assets/css/admin.css', [], WEDDING_BOOKING_VER);
    $icon_lib = wedding_booking_icon_library_url();
    if ($icon_lib !== '') {
        wp_enqueue_style('wedding-booking-icons', $icon_lib, [], WEDDING_BOOKING_VER);
    }
    // Media library — only the Settings screen opens wp.media (the Order
    // Email attachment picker). It pulls in Backbone, Underscore and the
    // media templates, so it must not load on every Wedding Booking page.
    if (strpos($hook, 'wedding-booking-settings') !== false) {
        wp_enqueue_media();
    }
    wp_enqueue_script('wedding-booking-admin',    WEDDING_BOOKING_URL . 'assets/js/admin.js',   [], WEDDING_BOOKING_VER, true);
    wp_localize_script('wedding-booking-admin', 'weddingBookingAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('wedding_booking_admin_nonce'),
        'bookingsUrl' => admin_url('admin.php?page=wedding-booking-bookings'),
        'i18n'    => [
            // Same helper the PHP side renders with, so the "no file" wording
            // cannot drift between the initial render and the Remove button.
            'noFile'           => function_exists('wedding_booking_order_email_attachment_label')
                ? wedding_booking_order_email_attachment_label(0)
                : __('No file selected.', 'wedding-booking'),
            'mediaUnavailable' => __('Media library unavailable.', 'wedding-booking'),
            'pickTitle'        => __('Select or upload the order email attachment', 'wedding-booking'),
            'pickButton'       => __('Use this file', 'wedding-booking'),
        ],
    ]);
}

function wedding_booking_is_plugin_admin_page()
{
    $page = sanitize_key(wp_unslash($_GET['page'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return strpos($page, 'wedding-booking-') === 0;
}

/**
 * Toggle-switch checkbox used by the add/edit forms. Same input name and
 * semantics as a plain checkbox, so form serialization stays unchanged.
 * A $tip adds a help button beside the switch (outside the <label>, so
 * clicking it never flips the switch).
 */
function wedding_booking_toggle_field($name, $label, $checked, $hint = '', $tip = '')
{
    $out  = '<label class="wedding-booking-toggle">';
    $out .= '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
    $out .= '<span class="wedding-booking-toggle-track" aria-hidden="true"></span>';
    $out .= '<span class="wedding-booking-toggle-text">' . esc_html($label);
    if ($hint !== '') {
        $out .= '<small>' . esc_html($hint) . '</small>';
    }
    $out .= '</span></label>';

    if ($tip !== '') {
        $out = '<span class="wedding-booking-toggle-row">' . $out . wedding_booking_help_tip($tip) . '</span>';
    }

    return $out;
}

/**
 * "?" help button whose explanation shows on hover, keyboard focus or tap
 * (admin.js positions one shared bubble). The text also lives in a hidden
 * element the button points to with aria-describedby, so screen readers
 * announce it without the bubble. Never place it inside a <label>.
 */
function wedding_booking_help_tip($text)
{
    static $n = 0;
    $n++;
    $id = 'wedding-booking-tip-' . $n;

    return '<span class="wedding-booking-tip-wrap">'
        . '<button type="button" class="wedding-booking-tip" aria-label="' . esc_attr__('More information', 'wedding-booking') . '" aria-describedby="' . esc_attr($id) . '">'
        . '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button>'
        . '<span class="wedding-booking-tip-text" id="' . esc_attr($id) . '" hidden>' . esc_html($text) . '</span>'
        . '</span>';
}

/**
 * Label row for the add/edit form grids: the label, a required marker and
 * an optional help tip beside it.
 */
function wedding_booking_field_label($text, $tip = '', $for = '', $required = false)
{
    $out  = '<div class="wedding-booking-label-row">';
    $out .= '<label' . ($for !== '' ? ' for="' . esc_attr($for) . '"' : '') . '>' . esc_html($text);
    if ($required) {
        $out .= ' <span class="wedding-booking-req">*</span>';
    }
    $out .= '</label>';
    if ($tip !== '') {
        $out .= wedding_booking_help_tip($tip);
    }
    $out .= '</div>';

    return $out;
}

/**
 * Consistent empty-state panel for the list cards.
 */
function wedding_booking_empty_state($dashicon, $title, $hint = '')
{
    $out  = '<div class="wedding-booking-empty">';
    $out .= '<span class="wedding-booking-empty-icon dashicons ' . esc_attr($dashicon) . '" aria-hidden="true"></span>';
    $out .= '<span class="wedding-booking-empty-title">' . esc_html($title) . '</span>';
    if ($hint !== '') {
        $out .= '<span class="wedding-booking-empty-hint">' . esc_html($hint) . '</span>';
    }
    $out .= '</div>';

    return $out;
}

add_filter('admin_footer_text', 'wedding_booking_hide_admin_footer_text');
function wedding_booking_hide_admin_footer_text($footer_text)
{
    if (! wedding_booking_is_plugin_admin_page()) {
        return $footer_text;
    }
    return '';
}

add_filter('update_footer', 'wedding_booking_hide_admin_version_text', 999);
function wedding_booking_hide_admin_version_text($version_text)
{
    if (! wedding_booking_is_plugin_admin_page()) {
        return $version_text;
    }
    return '';
}

/* ═══════════════════════════════════════════════════════════════
   HELPER — shared page wrapper
═══════════════════════════════════════════════════════════════ */
function wedding_booking_wrap_open($title, $active_tab = '', $subtitle = '')
{
    // Grouped by job: day-to-day bookings, what you sell, when you are
    // open, then how the form looks and works. Settings stays last — it is
    // the configuration screen, not a day-to-day one.
    $groups = [
        'manage'  => [
            'label' => __('Manage', 'wedding-booking'),
            'tabs'  => [
                'wedding-booking-bookings' => ['label' => __('Bookings', 'wedding-booking'), 'icon' => 'dashicons-clipboard'],
            ],
        ],
        'catalog' => [
            'label' => __('What you sell', 'wedding-booking'),
            'tabs'  => [
                'wedding-booking-sessions' => ['label' => __('Session Types', 'wedding-booking'), 'icon' => 'dashicons-category'],
                'wedding-booking-packages' => ['label' => __('Packages', 'wedding-booking'),      'icon' => 'dashicons-archive'],
                'wedding-booking-addons'   => ['label' => __('Add-ons', 'wedding-booking'),       'icon' => 'dashicons-star-filled'],
            ],
        ],
        'dates'   => [
            'label' => __('When you are open', 'wedding-booking'),
            'tabs'  => [
                'wedding-booking-dates' => ['label' => __('Date Slots', 'wedding-booking'), 'icon' => 'dashicons-calendar-alt'],
            ],
        ],
        'setup'   => [
            'label' => __('Set up', 'wedding-booking'),
            'tabs'  => [
                'wedding-booking-frontend' => ['label' => __('Booking Form', 'wedding-booking'), 'icon' => 'dashicons-feedback'],
                'wedding-booking-settings' => ['label' => __('Settings', 'wedding-booking'),     'icon' => 'dashicons-admin-generic'],
            ],
        ],
    ];
    // Shop Managers run bookings but not the plugin-wide settings.
    if (! current_user_can('manage_options')) {
        unset($groups['setup']['tabs']['wedding-booking-settings']);
    }
    echo '<div class="wrap wedding-booking-admin-wrap">';
    echo '<div class="wbook-topbar">';
    echo '<div class="wbook-topbar-brand">';
    echo '<span class="wbook-topbar-logo"><span class="dashicons dashicons-camera" aria-hidden="true"></span></span>';
    echo '<span class="wbook-topbar-title">Wedding Booking</span>';
    echo '<span class="wbook-topbar-ver">v' . esc_html(WEDDING_BOOKING_VER) . '</span>';
    echo '</div>';
    echo '<nav class="wbook-tabs" aria-label="' . esc_attr__('Wedding Booking sections', 'wedding-booking') . '">';
    $first = true;
    foreach ($groups as $group_key => $group) {
        if (! $first) {
            echo '<span class="wbook-tab-sep" aria-hidden="true"></span>';
        }
        $first = false;
        echo '<span class="wbook-tab-group wbook-tab-group-' . esc_attr($group_key) . '" role="group" aria-label="' . esc_attr($group['label']) . '">';
        foreach ($group['tabs'] as $slug => $tab) {
            $url     = admin_url('admin.php?page=' . $slug);
            $current = $slug === $active_tab;
            echo '<a href="' . esc_url($url) . '" class="wbook-tab' . ($current ? ' wedding-booking-active' : '') . '"' . ($current ? ' aria-current="page"' : '') . '>';
            echo '<span class="dashicons ' . esc_attr($tab['icon']) . '" aria-hidden="true"></span>';
            echo '<span class="wbook-tab-label">' . esc_html($tab['label']) . '</span>';
            echo '</a>';
        }
        echo '</span>';
    }
    echo '</nav>';
    echo '</div>';
    echo '<h1 class="wbook-page-title">' . esc_html($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="wbook-page-sub">' . esc_html($subtitle) . '</p>';
    }
    echo '<hr class="wp-header-end">';
    echo '<div class="wedding-booking-admin-body">';
}
function wedding_booking_wrap_close()
{
    echo '</div></div>';
}

function wedding_booking_render_smart_layout_bar($base_url)
{
    echo '<div class="wedding-booking-smart-bar">';
    echo '<a class="button button-primary wedding-booking-smart-add" href="' . esc_url($base_url) . '">+ ' . esc_html__('Add New', 'wedding-booking') . '</a>';
    echo '</div>';
}

/* All Bookings (wedding_booking_page_bookings) lives in includes/admin-bookings.php. */

/* ═══════════════════════════════════════════════════════════════
   PAGE — SESSION TYPES
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_sessions()
{
    if (! wedding_booking_can_manage()) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'wedding_booking_';
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions ORDER BY sort_order, id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    wedding_booking_wrap_open('Session Types', 'wedding-booking-sessions', __('Define the photography session types customers can choose.', 'wedding-booking'));
    wedding_booking_render_smart_layout_bar(admin_url('admin.php?page=wedding-booking-sessions'));

    // ── Add / Edit form ─
    echo '<div class="postbox wedding-booking-form-card" id="wedding-booking-sessions-add"><div class="inside">';
    echo '<h3 class="wedding-booking-form-title">' . ($edit_row ? 'Edit Session Type' : 'Add New Session Type') . '</h3>';
    echo '<form id="wedding-booking-session-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2">';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_field_label() escapes its parts.
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Name', 'wedding-booking'), __('What customers see as a tab at the top of the booking form, e.g. “Wedding”, “Portrait” or “Family”.', 'wedding-booking'), 'wedding-booking-session-name', true) . '<input class="regular-text" type="text" id="wedding-booking-session-name" name="name" required placeholder="Holiday / Couple Photoshoot" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown as a tab at the top of the booking form.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Slug', 'wedding-booking'), __('A short, web-friendly ID made from the name (lowercase letters, numbers and dashes). It must be unique. You rarely need to change it.', 'wedding-booking'), 'wedding-booking-session-slug', true) . '<input class="regular-text" type="text" id="wedding-booking-session-slug" name="slug" required placeholder="photo" value="' . esc_attr($edit_row->slug ?? '') . '">';
    echo '<p class="description">' . esc_html__('Filled in automatically from the name.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Emoji / Icon', 'wedding-booking'), __('Shown beside the name on the tab. Paste an emoji such as 📷, or a Dashicons class such as “dashicons dashicons-camera” (see developer.wordpress.org/resource/dashicons). Font Awesome classes also work if your theme loads Font Awesome.', 'wedding-booking'), 'wedding-booking-session-emoji') . '<input class="regular-text" type="text" id="wedding-booking-session-emoji" name="emoji" maxlength="100" placeholder="📷 or dashicons dashicons-camera" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji or a Dashicons class.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Sort Order', 'wedding-booking'), __('Controls the order of the tabs on the booking form: lower numbers come first. Use 10, 20, 30… to leave room for new ones.', 'wedding-booking'), 'wedding-booking-session-sort') . '<input class="small-text" type="number" id="wedding-booking-session-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'wedding-booking') . '</p></div>';
    // phpcs:enable
    echo '</div>';
    echo '<div class="wedding-booking-form-switches">';
    echo wedding_booking_toggle_field('active', __('Active', 'wedding-booking'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Visible on the booking form', 'wedding-booking'), __('Untick to hide this session type from the booking form without deleting it. Existing bookings are not affected.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '</div>';
    echo '<div class="wedding-booking-form-actions">';
    echo '<button type="submit" class="button button-primary wedding-booking-btn" id="wedding-booking-session-save">' . ($edit_row ? 'Update Session Type' : 'Add Session Type') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-sessions')) . '" class="button wedding-booking-btn wedding-booking-btn-ghost">Cancel</a>';
    echo '</div><div class="wedding-booking-form-msg" id="wedding-booking-session-msg"></div>';
    echo '</form></div></div>';

    // ── List ─
    echo '<div class="postbox wedding-booking-list-card" id="wedding-booking-sessions-list"><div class="inside">';
    if (empty($sessions)) {
        echo wedding_booking_empty_state('dashicons-category', __('No session types yet', 'wedding-booking'), __('Add your first session type with the form above.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_empty_state.
    } else {
        echo '<table class="wp-list-table widefat fixed striped wedding-booking-table"><thead><tr><th>Emoji</th><th>Name</th><th>Slug</th><th>Order</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($sessions as $row) {
            echo '<tr>';
            echo '<td>' . wedding_booking_icon_html($row->emoji) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_icon_html.
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td><code>' . esc_html($row->slug) . '</code></td>';
            echo '<td>' . (int) $row->sort_order . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-sessions&edit=' . (int) $row->id)) . '" class="button button-small wedding-booking-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete wedding-booking-btn-sm wedding-booking-btn-danger wedding-booking-del-session" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    wedding_booking_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — PACKAGES
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_packages()
{
    if (! wedding_booking_can_manage()) return;
    global $wpdb;
    $pfx      = $wpdb->prefix . 'wedding_booking_';
    $cur      = wedding_booking_get_currency_symbol();
    $sessions = $wpdb->get_results("SELECT * FROM {$pfx}sessions WHERE active=1 ORDER BY sort_order, id"); // phpcs:ignore
    $filter   = isset($_GET['session']) ? (int) $_GET['session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $where    = $filter ? $wpdb->prepare('AND p.session_id = %d', $filter) : '';
    // LEFT JOIN so a package whose session type no longer exists still shows
    // up here (and can be fixed or deleted) instead of silently disappearing.
    $packages = $wpdb->get_results("SELECT p.*, s.name AS sname, s.emoji AS semoji FROM {$pfx}packages p LEFT JOIN {$pfx}sessions s ON s.id=p.session_id WHERE 1=1 {$where} ORDER BY p.session_id, p.sort_order, p.id"); // phpcs:ignore
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}packages WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    // Session choices for the form: the active ones, plus the edited
    // package's current session even when inactive -- otherwise nothing is
    // selected and saving silently moves the package to the first session.
    $form_sessions = $edit_row
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$pfx}sessions WHERE active=1 OR id=%d ORDER BY sort_order, id", (int) $edit_row->session_id)) // phpcs:ignore
        : $sessions;
    $edit_session_found = false;
    if ($edit_row) {
        foreach ($form_sessions as $s) {
            if ((int) $s->id === (int) $edit_row->session_id) {
                $edit_session_found = true;
                break;
            }
        }
    }

    wedding_booking_wrap_open('Packages', 'wedding-booking-packages', __('Create the packages offered under each session type.', 'wedding-booking'));
    wedding_booking_render_smart_layout_bar(admin_url('admin.php?page=wedding-booking-packages'));

    // ── Form ─
    echo '<div class="postbox wedding-booking-form-card" id="wedding-booking-packages-add"><div class="inside">';
    echo '<h3 class="wedding-booking-form-title">' . ($edit_row ? 'Edit Package' : 'Add New Package') . '</h3>';
    echo '<form id="wedding-booking-package-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2">';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_field_label() escapes its parts.
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Session Type', 'wedding-booking'), __('Which tab of the booking form this package appears under.', 'wedding-booking'), 'wedding-booking-package-session', true) . '<select id="wedding-booking-package-session" name="session_id" required>';
    // phpcs:enable
    if ($edit_row && ! $edit_session_found) {
        // The package's session type was deleted: make the admin pick one
        // rather than quietly defaulting to the first in the list.
        echo '<option value="" selected="selected">' . esc_html__('— Select a session type —', 'wedding-booking') . '</option>';
    }
    foreach ($form_sessions as $s) {
        $sel = $edit_row ? selected((int) $edit_row->session_id, (int) $s->id, false) : '';
        $s_label = trim(wedding_booking_icon_text($s->emoji) . ' ' . $s->name);
        if (! (int) $s->active) {
            $s_label .= ' ' . __('(inactive)', 'wedding-booking');
        }
        echo '<option value="' . (int) $s->id . '"' . $sel . '>' . esc_html($s_label) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</select></div>';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_field_label() escapes its parts.
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Package Name', 'wedding-booking'), __('The name on the package card, e.g. “Golden Hour” or “Full Day”.', 'wedding-booking'), 'wedding-booking-package-name', true) . '<input class="regular-text" type="text" id="wedding-booking-package-name" name="name" required placeholder="Golden Hour" value="' . esc_attr($edit_row->name ?? '') . '"></div>';
    /* translators: %s: currency symbol */
    echo '<div class="wedding-booking-field wedding-booking-field-half">' . wedding_booking_field_label(sprintf(__('Price (%s)', 'wedding-booking'), html_entity_decode((string) $cur, ENT_QUOTES, 'UTF-8')), __('The full price of the package, before add-ons, any payment fee or promo code discount. The deposit is worked out from this.', 'wedding-booking'), 'wedding-booking-package-price', true) . '<input class="small-text" type="number" id="wedding-booking-package-price" name="price" required step="0.01" min="0" placeholder="199" value="' . esc_attr($edit_row->price ?? '') . '"></div>';
    echo '<div class="wedding-booking-field wedding-booking-field-half">' . wedding_booking_field_label(__('Duration', 'wedding-booking'), __('A short summary shown on the card, e.g. “1 hr · 30 photos”. It is just text — it does not block time in your calendar.', 'wedding-booking'), 'wedding-booking-package-duration') . '<input class="regular-text" type="text" id="wedding-booking-package-duration" name="duration" placeholder="1hr · 30 photos" value="' . esc_attr($edit_row->duration ?? '') . '"></div>';
    echo '<div class="wedding-booking-field wedding-booking-field-editor">' . wedding_booking_field_label(__('Description', 'wedding-booking'), __('What is included, shown on the package card. Bullet lists work well here.', 'wedding-booking'));
    // phpcs:enable
    wp_editor(
        (string) ($edit_row->description ?? ''),
        'wedding_booking_package_desc',
        [
            'textarea_name' => 'description',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description">' . esc_html__('Shown on the package card. Supports formatting, bullet and numbered lists.', 'wedding-booking') . '</p>';
    echo '</div>';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_field_label() escapes its parts.
    echo '<div class="wedding-booking-field wedding-booking-field-half">' . wedding_booking_field_label(__('Sort Order', 'wedding-booking'), __('Order of the packages within their session type: lower numbers come first.', 'wedding-booking'), 'wedding-booking-package-sort') . '<input class="small-text" type="number" id="wedding-booking-package-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'wedding-booking') . '</p></div>';
    $global_deposit = function_exists('wedding_booking_opt') ? (int) wedding_booking_opt('wedding_booking_deposit_pct') : 50;
    $pkg_deposit    = isset($edit_row->deposit_pct) ? (int) $edit_row->deposit_pct : 0;
    /* translators: %d: the global deposit percentage */
    echo '<div class="wedding-booking-field wedding-booking-field-half">' . wedding_booking_field_label(__('Deposit %', 'wedding-booking'), sprintf(__('How much of this package is paid up front when the customer picks the deposit option. Leave blank to use the usual deposit (%d%%, set in Settings → Payments), or enter 1–99 to give this package its own.', 'wedding-booking'), $global_deposit), 'wedding-booking-package-deposit');
    // phpcs:enable
    echo '<input id="wedding-booking-package-deposit" class="small-text" type="number" name="deposit_pct" min="0" max="99" step="1" value="' . esc_attr($pkg_deposit > 0 ? $pkg_deposit : '') . '" placeholder="' . esc_attr($global_deposit) . '">';
    /* translators: %d: the global deposit percentage */
    echo '<p class="description">' . esc_html(sprintf(__('Blank = the usual %d%% deposit (Settings → Payments).', 'wedding-booking'), $global_deposit)) . '</p></div>';
    echo '</div>';
    echo '<div class="wedding-booking-form-switches">';
    echo wedding_booking_toggle_field('featured', __('Featured', 'wedding-booking'), isset($edit_row->featured) && (int) $edit_row->featured === 1, __('Highlighted with a ★ Popular tag', 'wedding-booking'), __('Adds a “★ Popular” tag to the card so this package stands out. Use it on the one you most want to sell.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo wedding_booking_toggle_field('active', __('Active', 'wedding-booking'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'wedding-booking'), __('Untick to hide this package from the booking form without deleting it. Existing bookings are not affected.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '</div>';
    echo '<div class="wedding-booking-form-actions">';
    echo '<button type="submit" class="button button-primary wedding-booking-btn">' . ($edit_row ? 'Update Package' : 'Add Package') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-packages')) . '" class="button wedding-booking-btn wedding-booking-btn-ghost">Cancel</a>';
    echo '</div><div class="wedding-booking-form-msg" id="wedding-booking-package-msg"></div>';
    echo '</form></div></div>';

    // ── List with session filter ─
    $booking_url = wedding_booking_get_booking_page_url();

    echo '<div class="postbox wedding-booking-list-card" id="wedding-booking-packages-list"><div class="inside">';
    if ($booking_url === '' && ! empty($packages)) {
        echo '<div class="notice notice-warning inline"><p>';
        echo esc_html__('No booking page found, so "Copy Link" URLs point to your homepage. Add the [wedding_booking] shortcode to a page, or pick your booking page under Settings → General → Booking page.', 'wedding-booking');
        echo '</p></div>';
    }
    echo '<ul class="subsubsub wedding-booking-filter-bar">';
    echo '<li><a href="' . esc_url(admin_url('admin.php?page=wedding-booking-packages')) . '" class="wedding-booking-filter-btn' . (! $filter ? ' current wedding-booking-active' : '') . '">All</a></li>';
    foreach ($sessions as $s) {
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=wedding-booking-packages&session=' . (int) $s->id)) . '" class="wedding-booking-filter-btn' . ($filter === (int) $s->id ? ' current wedding-booking-active' : '') . '">' . wedding_booking_icon_html($s->emoji) . ' ' . esc_html($s->name) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_icon_html.
    }
    echo '</ul><br class="clear" />';
    if (empty($packages)) {
        echo wedding_booking_empty_state('dashicons-archive', __('No packages yet', 'wedding-booking'), __('Create your first package with the form above.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_empty_state.
    } else {
        echo '<table class="wp-list-table widefat fixed striped wedding-booking-table"><thead><tr><th>Session</th><th>Name</th><th>Price</th><th>Duration</th><th>Featured</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($packages as $row) {
            echo '<tr>';
            if ($row->sname === null) {
                echo '<td><em>' . esc_html__('— (no session)', 'wedding-booking') . '</em></td>';
            } else {
                echo '<td>' . wedding_booking_icon_html($row->semoji) . ' ' . esc_html($row->sname) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_icon_html.
            }
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 2));
            if (isset($row->deposit_pct) && (int) $row->deposit_pct > 0) {
                /* translators: %d: package deposit percentage */
                echo '<br><small class="wedding-booking-pkg-deposit">' . esc_html(sprintf(__('Deposit %d%%', 'wedding-booking'), (int) $row->deposit_pct)) . '</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html($row->duration) . '</td>';
            echo '<td>' . ($row->featured ? '⭐' : '—') . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            $share_url = wedding_booking_package_share_link($row, $booking_url);
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-packages&edit=' . (int) $row->id)) . '" class="button button-small wedding-booking-btn-sm">Edit</a> ';
            echo '<button type="button" class="button button-small wedding-booking-btn-sm wedding-booking-copy-link" data-link="' . esc_attr($share_url) . '" title="' . esc_attr__('Copy a link that opens the booking form with this package already picked — handy for emails, Instagram or ads', 'wedding-booking') . '">' . esc_html__('Copy Link', 'wedding-booking') . '</button> ';
            echo '<button class="button button-small button-link-delete wedding-booking-btn-sm wedding-booking-btn-danger wedding-booking-del-package" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '<div class="wedding-booking-share-link" hidden><input type="text" class="wedding-booking-share-link-field" readonly value="' . esc_attr($share_url) . '" onfocus="this.select()" aria-label="' . esc_attr__('Shareable package link', 'wedding-booking') . '"></div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    wedding_booking_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — ADD-ONS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_addons()
{
    if (! wedding_booking_can_manage()) return;
    global $wpdb;
    $pfx     = $wpdb->prefix . 'wedding_booking_';
    $cur     = wedding_booking_get_currency_symbol();
    $addons  = $wpdb->get_results("SELECT a.*, p.name AS pname FROM {$pfx}addons a LEFT JOIN {$pfx}packages p ON p.id=a.package_id ORDER BY a.sort_order, a.id"); // phpcs:ignore
    // Every package, inactive ones included, so the "Applies To" checklist can
    // show (and a save keeps) an add-on's scope to an inactive package.
    $all_packages = $wpdb->get_results("SELECT p.id, p.name, p.active, s.emoji AS semoji, s.name AS sname FROM {$pfx}packages p LEFT JOIN {$pfx}sessions s ON s.id=p.session_id ORDER BY s.id IS NULL, s.sort_order, p.sort_order, p.id"); // phpcs:ignore
    foreach ($all_packages as $pkg) {
        $pkg->label = ($pkg->sname === null ? __('— (no session)', 'wedding-booking') : $pkg->sname) . ' › ' . $pkg->name;
        if (! (int) $pkg->active) {
            $pkg->label .= ' ' . __('(inactive)', 'wedding-booking');
        }
    }
    $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}addons WHERE id=%d", $edit_id)) : null; // phpcs:ignore

    wedding_booking_wrap_open('Add-ons', 'wedding-booking-addons', __('Optional extras customers can add to any package.', 'wedding-booking'));
    wedding_booking_render_smart_layout_bar(admin_url('admin.php?page=wedding-booking-addons'));

    echo '<div class="postbox wedding-booking-form-card" id="wedding-booking-addons-add"><div class="inside">';
    echo '<h3 class="wedding-booking-form-title">' . ($edit_row ? 'Edit Add-on' : 'Add New Add-on') . '</h3>';
    echo '<form id="wedding-booking-addon-form">';
    echo '<input type="hidden" name="id" value="' . ($edit_row ? (int) $edit_row->id : 0) . '">';
    echo '<div class="wedding-booking-form-grid wedding-booking-cols-2">';
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_field_label() escapes its parts.
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Name', 'wedding-booking'), __('The extra\'s name on its card, e.g. “Drone footage” or “Extra hour”.', 'wedding-booking'), 'wedding-booking-addon-name', true) . '<input class="regular-text" type="text" id="wedding-booking-addon-name" name="name" required placeholder="Drone aerial session" value="' . esc_attr($edit_row->name ?? '') . '">';
    echo '<p class="description">' . esc_html__('Shown on the add-on card, under the packages.', 'wedding-booking') . '</p></div>';
    /* translators: %s: currency symbol */
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(sprintf(__('Price (%s)', 'wedding-booking'), html_entity_decode((string) $cur, ENT_QUOTES, 'UTF-8')), __('Added to the package price when the customer ticks this extra. Use 0 for a free extra.', 'wedding-booking'), 'wedding-booking-addon-price', true) . '<input class="small-text" type="number" id="wedding-booking-addon-price" name="price" required step="0.01" min="0" placeholder="150" value="' . esc_attr($edit_row->price ?? '') . '">';
    echo '<p class="description">' . esc_html__('Added on top of the package price.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Emoji / Icon', 'wedding-booking'), __('Shown on the add-on card. Paste an emoji such as 🚁, or a Dashicons class such as “dashicons dashicons-star-filled”.', 'wedding-booking'), 'wedding-booking-addon-emoji') . '<input class="regular-text" type="text" id="wedding-booking-addon-emoji" name="emoji" maxlength="100" placeholder="🚁 or dashicons dashicons-star-filled" value="' . esc_attr($edit_row->emoji ?? '') . '">';
    echo '<p class="description">' . esc_html__('An emoji or a Dashicons class.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field">' . wedding_booking_field_label(__('Sort Order', 'wedding-booking'), __('Order of the add-ons on the booking form: lower numbers come first.', 'wedding-booking'), 'wedding-booking-addon-sort') . '<input class="small-text" type="number" id="wedding-booking-addon-sort" name="sort_order" value="' . esc_attr($edit_row->sort_order ?? 0) . '" min="0">';
    echo '<p class="description">' . esc_html__('Lower numbers appear first.', 'wedding-booking') . '</p></div>';
    echo '<div class="wedding-booking-field wedding-booking-field-editor">' . wedding_booking_field_label(__('Description', 'wedding-booking'), __('A short explanation shown on the add-on card, e.g. what the customer gets and for how long.', 'wedding-booking'));
    // phpcs:enable
    wp_editor(
        (string) ($edit_row->description ?? ''),
        'wedding_booking_addon_desc',
        [
            'textarea_name' => 'description',
            'textarea_rows' => 5,
            'media_buttons' => false,
            'quicktags'     => true,
        ]
    );
    echo '<p class="description">' . esc_html__('Shown on the add-on card. Supports formatting, bullet and numbered lists.', 'wedding-booking') . '</p>';
    echo '</div>';

    // Applies-to checklist — "All Packages" ticked (or nothing ticked) =
    // global; otherwise the add-on is offered only with the ticked packages.
    $addon_pkg_ids = array_values(array_filter(array_map('absint', explode(',', (string) ($edit_row->package_ids ?? '')))));
    if (empty($addon_pkg_ids) && ! empty($edit_row->package_id)) {
        $addon_pkg_ids = [(int) $edit_row->package_id]; // legacy single-package rows
    }
    $is_global_addon = empty($addon_pkg_ids);
    echo '<div class="wedding-booking-field wedding-booking-field-wide">' . wedding_booking_field_label(__('Applies To', 'wedding-booking'), __('Which packages offer this extra. Tick “All Packages” to offer it with every package, or tick only the packages it suits.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_field_label.
    echo '<div class="wedding-booking-pkg-checklist" id="wedding-booking-addon-pkg-list">';
    echo '<label class="wedding-booking-pkg-check wedding-booking-pkg-check-all"><input type="checkbox" name="package_ids[]" value="0"' . checked($is_global_addon, true, false) . '> <strong>' . esc_html__('All Packages (global)', 'wedding-booking') . '</strong></label>';
    foreach ($all_packages as $pkg) {
        $chk = checked(in_array((int) $pkg->id, $addon_pkg_ids, true), true, false);
        echo '<label class="wedding-booking-pkg-check"><input type="checkbox" name="package_ids[]" value="' . (int) $pkg->id . '"' . $chk . '> ' . esc_html(trim(wedding_booking_icon_text($pkg->semoji) . ' ' . $pkg->label)) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
    echo '<p class="description">' . esc_html__('Tick the packages this add-on is offered with — one, several, or "All Packages" for every package.', 'wedding-booking') . '</p></div>';

    echo '</div>';
    echo '<div class="wedding-booking-form-switches">';
    echo wedding_booking_toggle_field('active', __('Active', 'wedding-booking'), isset($edit_row->active) ? (int) $edit_row->active === 1 : true, __('Available for booking', 'wedding-booking'), __('Untick to hide this extra from the booking form without deleting it. Existing bookings are not affected.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_toggle_field.
    echo '</div>';
    echo '<div class="wedding-booking-form-actions">';
    echo '<button type="submit" class="button button-primary wedding-booking-btn">' . ($edit_row ? 'Update Add-on' : 'Add Add-on') . '</button>';
    if ($edit_row) echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-addons')) . '" class="button wedding-booking-btn wedding-booking-btn-ghost">Cancel</a>';
    echo '</div><div class="wedding-booking-form-msg" id="wedding-booking-addon-msg"></div>';
    echo '</form></div></div>';

    echo '<div class="postbox wedding-booking-list-card" id="wedding-booking-addons-list"><div class="inside">';
    if (empty($addons)) {
        echo wedding_booking_empty_state('dashicons-star-filled', __('No add-ons yet', 'wedding-booking'), __('Create optional extras customers can add to their booking.', 'wedding-booking')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_empty_state.
    } else {
        $pkg_name_lookup = [];
        foreach ($all_packages as $pkg) {
            $pkg_name_lookup[(int) $pkg->id] = $pkg->label;
        }
        echo '<table class="wp-list-table widefat fixed striped wedding-booking-table"><thead><tr><th>Emoji</th><th>Name</th><th>Price</th><th>Applies To</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
        foreach ($addons as $row) {
            $scope_ids = array_values(array_filter(array_map('absint', explode(',', (string) ($row->package_ids ?? '')))));
            if (empty($scope_ids) && (int) $row->package_id > 0) {
                $scope_ids = [(int) $row->package_id]; // legacy single-package rows
            }
            if (empty($scope_ids)) {
                $scope = '<span class="wedding-booking-badge wedding-booking-badge-confirmed">All Packages</span>';
            } else {
                $scope_names = [];
                foreach ($scope_ids as $spid) {
                    $scope_names[] = esc_html($pkg_name_lookup[$spid] ?? ('#' . $spid));
                }
                $scope = implode(', ', $scope_names);
            }
            echo '<tr>';
            echo '<td>' . wedding_booking_icon_html($row->emoji) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wedding_booking_icon_html.
            echo '<td><strong>' . esc_html($row->name) . '</strong>' . ($row->description ? '<br><small>' . wp_kses_post($row->description) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($cur) . esc_html(number_format((float) $row->price, 2)) . '</td>';
            echo '<td>' . wp_kses_post($scope) . '</td>';
            echo '<td>' . ($row->active ? '✅' : '❌') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-addons&edit=' . (int) $row->id)) . '" class="button button-small wedding-booking-btn-sm">Edit</a> ';
            echo '<button class="button button-small button-link-delete wedding-booking-btn-sm wedding-booking-btn-danger wedding-booking-del-addon" data-id="' . (int) $row->id . '" data-name="' . esc_attr($row->name) . '">Delete</button>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    wedding_booking_wrap_close();
}

/* ═══════════════════════════════════════════════════════════════
   PAGE — DATE SLOTS
═══════════════════════════════════════════════════════════════ */
function wedding_booking_page_dates()
{
    if (! wedding_booking_can_manage()) return;
    wedding_booking_wrap_open(__('Date Slots', 'wedding-booking'), 'wedding-booking-dates', __('Open or close single dates for new bookings — holidays, days off, or days you are already busy.', 'wedding-booking'));

    echo '<div class="wedding-booking-howto">';
    echo '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div>';
    echo '<p><strong>' . esc_html__('Click a future date to change it:', 'wedding-booking') . '</strong> ';
    echo esc_html__('Available → Booked → Blocked → back to Available.', 'wedding-booking') . '</p>';
    echo '<p>' . esc_html__('Dates with real customer bookings are marked for you. Rules that repeat every week — closed weekdays, bookings per day, start times — are in', 'wedding-booking') . ' ';
    if (current_user_can('manage_options')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#availability')) . '">' . esc_html__('Settings → Availability', 'wedding-booking') . '</a>.';
    } else {
        echo esc_html__('Settings → Availability', 'wedding-booking') . '.';
    }
    echo '</p></div></div>';
?>
<div class="wedding-booking-dates-toolbar" id="wedding-booking-dates-toolbar">
    <button type="button" class="button wedding-booking-cal-admin-nav" id="wedding-booking-prev-month">‹ Prev</button>
    <span class="wedding-booking-cal-admin-month" id="wedding-booking-month-label">Loading…</span>
    <button type="button" class="button wedding-booking-cal-admin-nav" id="wedding-booking-next-month">Next ›</button>
</div>
<div class="wedding-booking-dates-calendar">
    <div class="wedding-booking-cal-admin-dh">
        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
    </div>
    <div class="wedding-booking-cal-admin-grid" id="wedding-booking-admin-calGrid"></div>
</div>
<div class="wedding-booking-cal-legend">
    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wedding_booking_help_tip() escapes its text. ?>
    <span class="wedding-booking-leg"><span class="wedding-booking-leg-dot wedding-booking-available"></span> <?php esc_html_e('Available', 'wedding-booking'); ?><?php echo wedding_booking_help_tip(__('Customers can book this date (unless a weekly rule in Settings → Availability closes it).', 'wedding-booking')); ?></span>
    <span class="wedding-booking-leg"><span class="wedding-booking-leg-dot wedding-booking-booked"></span> <?php esc_html_e('Booked', 'wedding-booking'); ?><?php echo wedding_booking_help_tip(__('The date is full. Wedding Booking marks it when bookings reach your daily limit; you can also mark it by hand, e.g. for a shoot booked outside the website.', 'wedding-booking')); ?></span>
    <span class="wedding-booking-leg"><span class="wedding-booking-leg-dot wedding-booking-blocked"></span> <?php esc_html_e('Blocked by Admin', 'wedding-booking'); ?><?php echo wedding_booking_help_tip(__('You closed this date — a holiday or day off. Customers can\'t pick it.', 'wedding-booking')); ?></span>
    <span class="wedding-booking-leg"><span class="wedding-booking-leg-dot" style="background:var(--wedding-booking-border);opacity:.5"></span> <?php esc_html_e('Past', 'wedding-booking'); ?></span>
    <?php // phpcs:enable ?>
</div>
<div id="wedding-booking-dates-msg" class="wedding-booking-dates-msg"></div>
<?php
    wedding_booking_wrap_close();
}

/* Settings and Booking Form screens live in includes/admin-settings.php. */
require_once WEDDING_BOOKING_DIR . 'includes/admin-settings.php';

// All Bookings: list, calendar, CSV export, add / edit / record payment.
require_once WEDDING_BOOKING_DIR . 'includes/admin-bookings.php';
