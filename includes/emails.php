<?php

/**
 * Wedding Booking email design system.
 *
 * One branded, table-based HTML shell shared by every message the plugin
 * sends — the WooCommerce booking confirmation (templates/emails/), the
 * balance reminder, and the WooCommerce-less enquiry emails. Everything is
 * inline-styled: email clients strip <style> blocks, and WooCommerce runs
 * its own CSS inliner over our markup (inline attributes win there, so the
 * design survives untouched).
 *
 * Colors follow Wedding Booking → Settings → Appearance, so the emails match the
 * booking form without a second setting to maintain.
 *
 * @package Wedding Booking
 */

defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Tokens
───────────────────────────────────────────────────────────── */

/**
 * Readable text color for a filled brand-colored surface.
 */
function wedding_booking_email_contrast_color($hex)
{
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '#ffffff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Perceived luminance (ITU-R BT.601).
    $luma = ($r * 299 + $g * 587 + $b * 114) / 1000;

    return $luma > 165 ? '#1c1916' : '#ffffff';
}

/**
 * The palette every email component draws from. Filterable so a site can
 * re-skin the emails without touching templates.
 */
function wedding_booking_email_palette()
{
    $colors = function_exists('wedding_booking_get_theme_colors')
        ? wedding_booking_get_theme_colors()
        : ['primary' => '#b8956a', 'accent' => '#3d6b78'];

    $mix = static function ($hex, $towards, $ratio) {
        return function_exists('wedding_booking_hex_mix') ? wedding_booking_hex_mix($hex, $towards, $ratio) : $hex;
    };

    $primary = $colors['primary'];
    $accent  = $colors['accent'];

    return (array) apply_filters('wedding_booking_email_palette', [
        'primary'     => $primary,
        'primary_dk'  => $mix($primary, '#000000', 0.20),
        'primary_lt'  => $mix($primary, '#ffffff', 0.88),
        'accent'      => $accent,
        'accent_dk'   => $mix($accent, '#000000', 0.18),
        'accent_lt'   => $mix($accent, '#ffffff', 0.90),
        'on_primary'  => wedding_booking_email_contrast_color($primary),
        'on_accent'   => wedding_booking_email_contrast_color($accent),
        'bg'          => '#f4f1ec',
        'card'        => '#ffffff',
        'panel'       => '#faf9f7',
        'border'      => '#e8e3dc',
        'rule'        => '#efeae3',
        'text'        => '#1c1916',
        'sub'         => '#6b6259',
        'muted'       => '#a09690',
        'serif'       => "Georgia, 'Times New Roman', Times, serif",
        'sans'        => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif",
    ]);
}

/* ─────────────────────────────────────────────────────────────
   Shell
───────────────────────────────────────────────────────────── */

/**
 * Masthead: the WooCommerce email header image if the merchant set one,
 * then the site logo, then a typographic wordmark.
 */
function wedding_booking_email_masthead_html($palette)
{
    $image = '';
    if (function_exists('get_option')) {
        $image = (string) get_option('woocommerce_email_header_image', '');
    }
    if ($image === '' && function_exists('get_theme_mod')) {
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id > 0) {
            $image = (string) wp_get_attachment_image_url($logo_id, 'medium');
        }
    }

    $name = get_bloginfo('name', 'display');

    if ($image !== '') {
        return '<img src="' . esc_url($image) . '" alt="' . esc_attr($name) . '" width="180" style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;width:auto;max-width:180px;height:auto;max-height:56px;">';
    }

    return '<span style="font-family:' . esc_attr($palette['serif']) . ';font-size:24px;line-height:1.2;letter-spacing:0.06em;color:' . esc_attr($palette['text']) . ';">'
        . esc_html($name) . '</span>';
}

/**
 * Wrap body markup in the branded document shell.
 *
 * @param string $content Inner HTML (already escaped by the component helpers).
 * @param array  $args    preheader, eyebrow, footer.
 */
function wedding_booking_email_wrap($content, $args = [])
{
    $palette = wedding_booking_email_palette();
    $args    = wp_parse_args($args, [
        'preheader' => '',
        'eyebrow'   => '',
        'footer'    => '',
    ]);

    $site_name = get_bloginfo('name', 'display');
    $site_url  = home_url('/');

    $footer = trim((string) $args['footer']);
    if ($footer === '') {
        $wc_footer = (string) get_option('woocommerce_email_footer_text', '');
        if (trim(wp_strip_all_tags($wc_footer)) !== '') {
            // WooCommerce owns this string's placeholders ({site_title},
            // {store_address}, …) — let it resolve them.
            if (function_exists('WC') && WC()->mailer()) {
                $wc_footer = WC()->mailer()->replace_placeholders($wc_footer);
            }
            $footer = wp_kses_post($wc_footer);
        } else {
            $footer = '<a href="' . esc_url($site_url) . '" style="color:' . esc_attr($palette['sub']) . ';text-decoration:none;">' . esc_html($site_name) . '</a>';
        }
    }

    $preheader = '';
    if ($args['preheader'] !== '') {
        // Inbox preview text. Deliberately avoids display:none — WooCommerce's
        // CSS inliner prunes display:none nodes out of the document.
        $preheader = '<div style="font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:transparent;mso-hide:all;">'
            . esc_html($args['preheader'])
            . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>';
    }

    $eyebrow = '';
    if ($args['eyebrow'] !== '') {
        $eyebrow = '<div style="margin:10px 0 0;font-family:' . esc_attr($palette['sans']) . ';font-size:10px;line-height:1.4;letter-spacing:0.22em;text-transform:uppercase;color:' . esc_attr($palette['muted']) . ';">'
            . esc_html($args['eyebrow']) . '</div>';
    }

    $html  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="' . esc_attr(get_bloginfo('language')) . '">' . "\n";
    $html .= '<head>' . "\n";
    $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\n";
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
    $html .= '<meta name="x-apple-disable-message-reformatting" />' . "\n";
    $html .= '<title>' . esc_html($site_name) . '</title>' . "\n";
    $html .= '<style type="text/css">
        @media only screen and (max-width: 620px) {
            .wbook-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .wbook-h1 { font-size: 23px !important; }
            .wbook-stack { display: block !important; width: 100% !important; text-align: left !important; padding-left: 0 !important; }
            .wbook-stack-v { padding-top: 2px !important; padding-bottom: 10px !important; }
        }
    </style>' . "\n";
    $html .= '</head>' . "\n";
    $html .= '<body style="margin:0;padding:0;width:100%;background-color:' . esc_attr($palette['bg']) . ';-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;">' . "\n";
    $html .= $preheader;
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background-color:' . esc_attr($palette['bg']) . ';">';
    $html .= '<tr><td align="center" style="padding:32px 14px;">';

    // Fluid card with an Outlook-only fixed-width wrapper: max-width alone
    // keeps it 600px everywhere except Word-rendered Outlook, which ignores it.
    $html .= '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td><![endif]-->';
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background-color:' . esc_attr($palette['card']) . ';border:1px solid ' . esc_attr($palette['border']) . ';border-radius:14px;overflow:hidden;">';

    // Brand edge.
    $html .= '<tr><td style="height:5px;line-height:5px;font-size:0;background-color:' . esc_attr($palette['primary']) . ';">&nbsp;</td></tr>';

    // Masthead.
    $html .= '<tr><td class="wbook-pad" align="center" style="padding:30px 40px 24px;border-bottom:1px solid ' . esc_attr($palette['rule']) . ';">';
    $html .= wedding_booking_email_masthead_html($palette) . $eyebrow;
    $html .= '</td></tr>';

    // Body.
    $html .= '<tr><td class="wbook-pad" style="padding:32px 40px 36px;">' . $content . '</td></tr>';

    // Footer.
    $html .= '<tr><td class="wbook-pad" align="center" style="padding:22px 40px 26px;background-color:' . esc_attr($palette['panel']) . ';border-top:1px solid ' . esc_attr($palette['rule']) . ';">';
    $html .= '<div style="font-family:' . esc_attr($palette['sans']) . ';font-size:12px;line-height:1.7;color:' . esc_attr($palette['sub']) . ';">' . $footer . '</div>';
    $html .= '</td></tr>';

    $html .= '</table>';
    $html .= '<!--[if mso]></td></tr></table><![endif]-->';

    $html .= '<div style="max-width:600px;margin:16px auto 0;font-family:' . esc_attr($palette['sans']) . ';font-size:11px;line-height:1.6;color:' . esc_attr($palette['muted']) . ';text-align:center;">';
    /* translators: %s: site name */
    $html .= esc_html(sprintf(__('This message was sent by %s regarding your booking.', 'wedding-booking'), $site_name));
    $html .= '</div>';

    $html .= '</td></tr></table>' . "\n";
    $html .= '</body></html>';

    return $html;
}

/* ─────────────────────────────────────────────────────────────
   Components
───────────────────────────────────────────────────────────── */

/**
 * Small uppercase status pill, e.g. "Booking confirmed".
 */
function wedding_booking_email_pill($label, $tone = 'accent')
{
    $p    = wedding_booking_email_palette();
    $bg   = $tone === 'primary' ? $p['primary_lt'] : $p['accent_lt'];
    $text = $tone === 'primary' ? $p['primary_dk'] : $p['accent_dk'];

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0 0 14px;"><tr>'
        . '<td style="padding:6px 13px;background-color:' . esc_attr($bg) . ';border-radius:100px;font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1;letter-spacing:0.12em;text-transform:uppercase;color:' . esc_attr($text) . ';">'
        . esc_html($label) . '</td></tr></table>';
}

/**
 * Page title.
 */
function wedding_booking_email_title($text)
{
    $p = wedding_booking_email_palette();

    return '<h1 class="wbook-h1" style="margin:0 0 14px;font-family:' . esc_attr($p['serif']) . ';font-size:27px;line-height:1.25;font-weight:400;color:' . esc_attr($p['text']) . ';">'
        . esc_html($text) . '</h1>';
}

/**
 * Centered "confirmed" emblem — a filled brand-colored disc with a checkmark.
 * The eye-catching opening for the booking confirmation. Table + fixed sizing
 * so it survives Outlook (which ignores border-radius, degrading to a square).
 */
function wedding_booking_email_hero_badge($tone = 'accent')
{
    $p  = wedding_booking_email_palette();
    $bg = $tone === 'primary' ? $p['primary'] : $p['accent'];
    $fg = $tone === 'primary' ? $p['on_primary'] : $p['on_accent'];

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:2px auto 18px;"><tr>'
        . '<td align="center" valign="middle" width="66" height="66" style="width:66px;height:66px;background-color:' . esc_attr($bg) . ';border-radius:50%;text-align:center;">'
        . '<span style="font-family:Arial,Helvetica,sans-serif;font-size:33px;line-height:66px;color:' . esc_attr($fg) . ';">&#10004;</span>'
        . '</td></tr></table>';
}

/**
 * Centered highlight card — a small eyebrow, a large serif headline and an
 * optional sub-line, on a soft brand tint. Used to feature the single most
 * important fact of a booking (its date) so it pops out of the message.
 */
function wedding_booking_email_highlight($eyebrow, $headline, $sub = '')
{
    $p = wedding_booking_email_palette();

    $html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;background-color:' . esc_attr($p['primary_lt']) . ';border:1px solid ' . esc_attr($p['primary_lt']) . ';border-radius:12px;">';
    $html .= '<tr><td align="center" style="padding:24px 26px;">';
    if ($eyebrow !== '') {
        $html .= '<div style="margin:0 0 8px;font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1;letter-spacing:0.18em;text-transform:uppercase;color:' . esc_attr($p['primary_dk']) . ';">' . esc_html($eyebrow) . '</div>';
    }
    $html .= '<div style="font-family:' . esc_attr($p['serif']) . ';font-size:23px;line-height:1.3;color:' . esc_attr($p['text']) . ';">' . esc_html($headline) . '</div>';
    if ($sub !== '') {
        $html .= '<div style="margin:9px 0 0;font-family:' . esc_attr($p['sans']) . ';font-size:14px;line-height:1.5;color:' . esc_attr($p['sub']) . ';">' . esc_html($sub) . '</div>';
    }
    $html .= '</td></tr></table>';

    return $html;
}

/**
 * Format a stored booking date string ("2026-08-14") into a friendly, localized
 * label ("Friday, 14 August 2026"). Falls back to the raw value if it can't be
 * parsed, so an unusual format is shown rather than dropped.
 */
function wedding_booking_email_pretty_date($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    $format = function_exists('get_option') ? (string) get_option('date_format', 'F j, Y') : 'F j, Y';
    return function_exists('date_i18n') ? date_i18n($format, $ts) : gmdate('F j, Y', $ts);
}

/**
 * Body paragraph. Pass pre-sanitised rich text as $is_html.
 */
function wedding_booking_email_text($text, $is_html = false)
{
    $p    = wedding_booking_email_palette();
    $body = $is_html ? $text : esc_html($text);

    return '<div style="margin:0 0 18px;font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.7;color:' . esc_attr($p['sub']) . ';">'
        . $body . '</div>';
}

/**
 * Normalise admin-authored rich text for email: paragraphs and links carry
 * no styling of their own in the editor, so give them ours.
 */
function wedding_booking_email_rich_text($html)
{
    $p = wedding_booking_email_palette();

    $html = str_replace(
        ['<p>', '<a ', '<h2>', '<h3>', '<ul>', '<ol>', '<li>', '<strong>', '<blockquote>'],
        [
            '<p style="margin:0 0 14px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<a style="color:' . $p['accent'] . ';text-decoration:underline;" ',
            '<h2 style="margin:24px 0 10px;font-family:' . $p['serif'] . ';font-size:19px;line-height:1.3;font-weight:400;color:' . $p['text'] . ';">',
            '<h3 style="margin:20px 0 8px;font-family:' . $p['sans'] . ';font-size:14px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:' . $p['text'] . ';">',
            '<ul style="margin:0 0 16px;padding-left:20px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<ol style="margin:0 0 16px;padding-left:20px;font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
            '<li style="margin:0 0 6px;">',
            '<strong style="color:' . $p['text'] . ';font-weight:700;">',
            '<blockquote style="margin:0 0 16px;padding:2px 0 2px 16px;border-left:3px solid ' . $p['primary_lt'] . ';font-family:' . $p['sans'] . ';font-size:15px;line-height:1.7;color:' . $p['sub'] . ';">',
        ],
        $html
    );

    return '<div style="font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.7;color:' . esc_attr($p['sub']) . ';">' . $html . '</div>';
}

/**
 * Bulletproof call-to-action button.
 */
function wedding_booking_email_button($url, $label, $variant = 'primary')
{
    $p   = wedding_booking_email_palette();
    $bg  = $variant === 'accent' ? $p['accent'] : $p['primary'];
    $fg  = $variant === 'accent' ? $p['on_accent'] : $p['on_primary'];

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:4px 0 6px;"><tr>'
        . '<td align="center" bgcolor="' . esc_attr($bg) . '" style="border-radius:8px;">'
        . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="display:inline-block;padding:14px 30px;font-family:' . esc_attr($p['sans']) . ';font-size:15px;font-weight:700;line-height:1;letter-spacing:0.01em;color:' . esc_attr($fg) . ';text-decoration:none;border-radius:8px;">'
        . esc_html($label) . '</a></td></tr></table>';
}

/**
 * Hairline separator.
 */
function wedding_booking_email_divider($space = 26)
{
    $p     = wedding_booking_email_palette();
    $space = (int) $space;

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;"><tr>'
        . '<td style="padding:' . $space . 'px 0 0;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-size:0;line-height:0;">&nbsp;</td>'
        . '</tr></table><div style="height:' . $space . 'px;line-height:' . $space . 'px;font-size:0;">&nbsp;</div>';
}

/**
 * Vertical space between blocks that carry no margin of their own (tables).
 */
function wedding_booking_email_spacer($height = 20)
{
    $height = (int) $height;

    return '<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:0;">&nbsp;</div>';
}

/**
 * Section label above a block — a short brand-colored rule, then the label,
 * echoing the accent tick the booking form uses on its section headings.
 */
function wedding_booking_email_section_label($label)
{
    $p = wedding_booking_email_palette();

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 13px;"><tr>'
        . '<td valign="middle" style="padding:0 9px 0 0;">'
        . '<div style="width:18px;height:2px;line-height:2px;font-size:0;background-color:' . esc_attr($p['primary']) . ';border-radius:2px;">&nbsp;</div>'
        . '</td>'
        . '<td valign="middle" style="font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1;letter-spacing:0.16em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">'
        . esc_html($label) . '</td>'
        . '</tr></table>';
}

/**
 * Label/value panel — the workhorse for booking details.
 *
 * @param array $rows  [ ['label' => …, 'value' => …, 'strong' => bool, 'url' => …], … ]
 *                     A row with 'url' renders its value as a link (e.g. a
 *                     mailto: or wa.me contact the admin can act on).
 * @param array $args  tone: 'panel'|'brand'
 */
function wedding_booking_email_facts($rows, $args = [])
{
    $rows = array_values(array_filter((array) $rows, static function ($row) {
        return isset($row['value']) && trim((string) $row['value']) !== '';
    }));
    if (empty($rows)) {
        return '';
    }

    $p    = wedding_booking_email_palette();
    $args = wp_parse_args($args, ['tone' => 'panel']);
    $bg     = $args['tone'] === 'brand' ? $p['accent_lt'] : $p['panel'];
    $border = $args['tone'] === 'brand' ? $p['accent_lt'] : $p['border'];

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;background-color:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';border-radius:10px;">';
    $html .= '<tr><td style="padding:6px 20px 8px;">';
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">';

    $last = count($rows) - 1;
    foreach ($rows as $i => $row) {
        $strong = ! empty($row['strong']);
        $rule   = $i === $last ? 'none' : '1px solid ' . $p['rule'];

        $value_html = esc_html($row['value']);
        if (! empty($row['url'])) {
            $value_html = '<a href="' . esc_url($row['url']) . '" style="color:' . esc_attr($p['accent_dk']) . ';text-decoration:underline;word-break:break-word;">' . $value_html . '</a>';
        }

        $html .= '<tr>';
        $html .= '<td class="wbook-stack" width="42%" valign="top" style="padding:12px 12px 12px 0;border-bottom:' . esc_attr($rule) . ';font-family:' . esc_attr($p['sans']) . ';font-size:11px;font-weight:700;line-height:1.5;letter-spacing:0.11em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">'
            . esc_html($row['label']) . '</td>';
        $html .= '<td class="wbook-stack wbook-stack-v" align="right" valign="top" style="padding:12px 0;border-bottom:' . esc_attr($rule) . ';font-family:' . esc_attr($p['sans']) . ';font-size:' . ($strong ? '17px' : '15px') . ';font-weight:' . ($strong ? '700' : '500') . ';line-height:1.5;color:' . esc_attr($p['text']) . ';">'
            . $value_html . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table></td></tr></table>';

    return $html;
}

/**
 * Highlighted callout box (used for the remaining-balance CTA).
 *
 * @param array $args title, text, amount, button_url, button_label, note.
 */
function wedding_booking_email_callout($args = [])
{
    $p    = wedding_booking_email_palette();
    $args = wp_parse_args($args, [
        'title'        => '',
        'text'         => '',
        'amount'       => '',
        'button_url'   => '',
        'button_label' => '',
        'note'         => '',
    ]);

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;background-color:' . esc_attr($p['accent_lt']) . ';border:1px solid ' . esc_attr($p['accent']) . ';border-radius:12px;">';
    $html .= '<tr><td style="padding:24px;">';

    if ($args['title'] !== '') {
        $html .= '<div style="margin:0 0 8px;font-family:' . esc_attr($p['serif']) . ';font-size:20px;line-height:1.3;color:' . esc_attr($p['accent_dk']) . ';">' . esc_html($args['title']) . '</div>';
    }
    if ($args['text'] !== '') {
        $html .= '<div style="margin:0 0 14px;font-family:' . esc_attr($p['sans']) . ';font-size:14px;line-height:1.65;color:' . esc_attr($p['sub']) . ';">' . esc_html($args['text']) . '</div>';
    }
    if ($args['amount'] !== '') {
        $html .= '<div style="margin:0 0 18px;font-family:' . esc_attr($p['sans']) . ';font-size:30px;font-weight:700;line-height:1.1;letter-spacing:-0.01em;color:' . esc_attr($p['accent_dk']) . ';">' . esc_html($args['amount']) . '</div>';
    }
    if ($args['button_url'] !== '' && $args['button_label'] !== '') {
        $html .= wedding_booking_email_button($args['button_url'], $args['button_label'], 'accent');
    }
    if ($args['note'] !== '') {
        $html .= '<div style="margin:14px 0 0;font-family:' . esc_attr($p['sans']) . ';font-size:11px;line-height:1.6;color:' . esc_attr($p['sub']) . ';word-break:break-all;">' . $args['note'] . '</div>';
    }

    $html .= '</td></tr></table>';

    return $html;
}

/* ─────────────────────────────────────────────────────────────
   Order rendering (WooCommerce)
───────────────────────────────────────────────────────────── */

/**
 * Booking facts rows for an order — what the customer actually booked.
 * Shared by the HTML and plain-text builders.
 */
function wedding_booking_email_booking_facts_rows($order)
{
    if (! $order || ! function_exists('wedding_booking_get_order_booking_meta')) {
        return [];
    }

    $meta = wedding_booking_get_order_booking_meta($order);
    $time = (string) $order->get_meta('_wedding_booking_billing_event_time', true);

    // Record of the Terms & Conditions the customer accepted, if any.
    $terms       = '';
    $accepted_at = (string) $order->get_meta('_wedding_booking_contract_accepted_at', true);
    if ($accepted_at !== '') {
        $terms     = get_date_from_gmt($accepted_at, get_option('date_format') . ' ' . get_option('time_format'));
        $signature = (string) $order->get_meta('_wedding_booking_contract_signature', true);
        if ($signature !== '') {
            /* translators: 1: date and time, 2: typed signature */
            $terms = sprintf(__('%1$s, signed "%2$s"', 'wedding-booking'), $terms, $signature);
        }
    }

    return [
        ['label' => __('Session', 'wedding-booking'), 'value' => $meta['session_type']],
        ['label' => __('Package', 'wedding-booking'), 'value' => $meta['package_name']],
        // Empty values are dropped by wedding_booking_email_facts(), so a booking
        // without extras simply shows no Add-ons row.
        ['label' => __('Add-ons', 'wedding-booking'), 'value' => $meta['addons']],
        ['label' => __('Date', 'wedding-booking'), 'value' => $meta['session_date'], 'strong' => true],
        ['label' => __('Time', 'wedding-booking'), 'value' => $time],
        ['label' => __('Booking reference', 'wedding-booking'), 'value' => '#' . $order->get_order_number()],
        ['label' => __('Terms accepted', 'wedding-booking'), 'value' => $terms],
    ];
}

/**
 * Booking facts for an order — what the customer actually booked.
 */
function wedding_booking_email_booking_facts_html($order)
{
    return wedding_booking_email_facts(wedding_booking_email_booking_facts_rows($order));
}

/**
 * The money rows for an order: the full booking price, and — for a partial
 * (deposit) booking — the deposit taken and the balance still due. Shared by
 * the customer confirmation's "Order summary" and the admin payment breakdown
 * so both read the same figures in the same branded label/value style.
 */
function wedding_booking_email_money_facts_rows($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return [];
    }

    $symbol = function_exists('get_woocommerce_currency_symbol')
        ? get_woocommerce_currency_symbol($order->get_currency())
        : '';
    // WooCommerce returns the symbol as an HTML entity (e.g. &#2547;); decode
    // it so the value is correct in both HTML and plain text after escaping.
    $symbol = html_entity_decode((string) $symbol, ENT_QUOTES, 'UTF-8');
    $money  = static function ($amount) use ($symbol) {
        return $symbol . number_format((float) $amount, 2);
    };

    $rows    = [];
    $figures = function_exists('wedding_booking_get_booking_figures') ? wedding_booking_get_booking_figures($order) : null;

    if ($figures && ! empty($figures['total'])) {
        $rows[] = ['label' => __('Booking total', 'wedding-booking'), 'value' => $money($figures['total']), 'strong' => true];
        // Only a partial (deposit) booking has a balance worth breaking out.
        if ($figures['balance'] > 0.01) {
            $pct = function_exists('wedding_booking_format_pct') ? wedding_booking_format_pct($figures['pct']) : (string) $figures['pct'];
            $rows[] = [
                'label' => sprintf(/* translators: %s: deposit percentage */ __('Deposit paid (%s%%)', 'wedding-booking'), $pct),
                'value' => $money($figures['deposit']),
            ];
            // The completed-order email goes out after the balance is paid too.
            $settled = function_exists('wedding_booking_booking_balance_paid') && wedding_booking_booking_balance_paid($order);
            $rows[]  = ['label' => $settled ? __('Balance paid', 'wedding-booking') : __('Balance due', 'wedding-booking'), 'value' => $money($figures['balance']), 'strong' => true];
            $due_by  = (string) $order->get_meta('_wedding_booking_balance_due_date', true);
            if (! $settled && $due_by !== '') {
                $rows[] = ['label' => __('Balance due by', 'wedding-booking'), 'value' => wedding_booking_email_pretty_date($due_by)];
            }
        }
    } else {
        $rows[] = ['label' => __('Order total', 'wedding-booking'), 'value' => $money($order->get_total()), 'strong' => true];
    }

    return $rows;
}

/**
 * Branded "Order summary" panel for the customer confirmation — the money
 * breakdown in the same label/value design as the rest of the email.
 */
function wedding_booking_email_money_facts_html($order)
{
    return wedding_booking_email_facts(wedding_booking_email_money_facts_rows($order), ['tone' => 'brand']);
}

/**
 * Admin-facing payment breakdown: the money rows above, plus the payment
 * method, status and when the order was placed — the operational detail the
 * customer's confirmation deliberately hides but the studio needs.
 */
function wedding_booking_email_admin_payment_facts_rows($order)
{
    $rows = wedding_booking_email_money_facts_rows($order);
    if (empty($rows) || ! is_a($order, 'WC_Order')) {
        return $rows;
    }

    $rows[] = ['label' => __('Payment method', 'wedding-booking'), 'value' => trim((string) $order->get_payment_method_title())];

    $status = function_exists('wc_get_order_status_name')
        ? wc_get_order_status_name($order->get_status())
        : ucfirst((string) $order->get_status());
    $rows[] = ['label' => __('Payment status', 'wedding-booking'), 'value' => $status];

    $created = $order->get_date_created();
    if ($created && function_exists('wc_format_datetime')) {
        $rows[] = ['label' => __('Placed', 'wedding-booking'), 'value' => wc_format_datetime($created, get_option('date_format') . ', ' . get_option('time_format'))];
    }

    return $rows;
}

function wedding_booking_email_admin_payment_facts_html($order)
{
    return wedding_booking_email_facts(wedding_booking_email_admin_payment_facts_rows($order), ['tone' => 'brand']);
}

/**
 * Digits-only international number for a wa.me link, or '' when there's nothing
 * dial-able. Leading zeros are dropped so a stored "0…" local number doesn't
 * produce a dead wa.me link.
 */
function wedding_booking_email_whatsapp_number($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    $digits = ltrim((string) $digits, '0');
    return strlen($digits) >= 6 ? $digits : '';
}

/**
 * Admin-facing contact rows — the customer's details laid out for someone who
 * needs to reach them and find them: a clickable email and WhatsApp, the hotel
 * / place of stay, the street address, and the country on its own line. Used
 * by the admin New Booking email (the customer's own confirmation keeps the
 * lighter wedding_booking_email_customer_facts_rows list).
 */
function wedding_booking_email_admin_contact_rows($order)
{
    if (! $order || ! is_a($order, 'WC_Order')) {
        return [];
    }

    $name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $email = (string) $order->get_billing_email();
    $phone = (string) $order->get_billing_phone();
    $wa    = wedding_booking_email_whatsapp_number($phone);

    // Street address without the country (shown separately below).
    $address = trim(implode(', ', array_filter([
        $order->get_billing_address_1(),
        $order->get_billing_address_2(),
        $order->get_billing_city(),
        $order->get_billing_state(),
        $order->get_billing_postcode(),
    ], 'strlen')));

    // Country name, not the two-letter code.
    $country_code = (string) $order->get_billing_country();
    $country      = $country_code;
    if ($country_code !== '' && function_exists('WC') && WC() && WC()->countries) {
        $names = WC()->countries->get_countries();
        if (isset($names[$country_code])) {
            $country = $names[$country_code];
        }
    }

    $rows = [
        ['label' => __('Name', 'wedding-booking'), 'value' => $name],
        ['label' => __('Email', 'wedding-booking'), 'value' => $email, 'url' => $email !== '' ? 'mailto:' . $email : ''],
        // The booking form's phone field is the customer's WhatsApp number.
        ['label' => __('WhatsApp', 'wedding-booking'), 'value' => $phone, 'url' => $wa !== '' ? 'https://wa.me/' . $wa : ''],
        ['label' => __('Guests', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_participants', true)],
        ['label' => __('Hotel / place', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_hotel_place', true)],
        ['label' => __('Room', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_room_number', true)],
        ['label' => __('Stay period', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_stay_period', true)],
        ['label' => __('Address', 'wedding-booking'), 'value' => $address],
        ['label' => __('Country', 'wedding-booking'), 'value' => $country],
    ];

    // Admin-defined custom checkout fields, appended after the fixed rows.
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $field) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => (string) $order->get_meta('_wedding_booking_cf_' . $key, true),
            ];
        }
    }

    return $rows;
}

function wedding_booking_email_admin_contact_html($order)
{
    return wedding_booking_email_facts(wedding_booking_email_admin_contact_rows($order));
}

/**
 * Designed replacement for WooCommerce's order-details table: booking line
 * items with their add-on meta, then the order total rows.
 */
function wedding_booking_email_order_table_html($order)
{
    if (! $order) {
        return '';
    }
    $p = wedding_booking_email_palette();

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border:1px solid ' . esc_attr($p['border']) . ';border-radius:10px;">';

    // Head.
    $html .= '<tr>';
    $html .= '<th align="left" style="padding:13px 20px;background-color:' . esc_attr($p['panel']) . ';border-bottom:1px solid ' . esc_attr($p['border']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:10px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">' . esc_html__('Item', 'wedding-booking') . '</th>';
    $html .= '<th align="right" style="padding:13px 20px;background-color:' . esc_attr($p['panel']) . ';border-bottom:1px solid ' . esc_attr($p['border']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:10px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr($p['muted']) . ';">' . esc_html__('Amount', 'wedding-booking') . '</th>';
    $html .= '</tr>';

    foreach ($order->get_items() as $item_id => $item) {
        $meta_html = wc_display_item_meta($item, [
            'before'       => '<div style="margin:6px 0 0;font-family:' . $p['sans'] . ';font-size:13px;line-height:1.6;color:' . $p['sub'] . ';">',
            'after'        => '</div>',
            'separator'    => '<br>',
            'label_before' => '<span style="font-weight:700;color:' . $p['text'] . ';">',
            'label_after'  => ':</span> ',
            'echo'         => false,
            'autop'        => false,
        ]);

        $html .= '<tr>';
        $html .= '<td valign="top" style="padding:16px 20px;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.5;color:' . esc_attr($p['text']) . ';">';
        $html .= '<span style="font-weight:600;">' . wp_kses_post($item->get_name()) . '</span>';
        $html .= $meta_html; // Escaped by wc_display_item_meta / our own filter.
        $html .= '</td>';
        $html .= '<td valign="top" align="right" style="padding:16px 20px;border-bottom:1px solid ' . esc_attr($p['rule']) . ';font-family:' . esc_attr($p['sans']) . ';font-size:15px;line-height:1.5;white-space:nowrap;color:' . esc_attr($p['text']) . ';">';
        $html .= wp_kses_post($order->get_formatted_line_subtotal($item));
        $html .= '</td></tr>';
    }

    $totals = (array) $order->get_order_item_totals();
    // Emphasise the order total, not whatever row happens to come last
    // (WooCommerce puts "Payment method" after it).
    $emphasis = isset($totals['order_total']) ? 'order_total' : (string) array_key_last($totals);
    foreach ($totals as $key => $total) {
        $big = ($key === $emphasis);
        $html .= '<tr>';
        $html .= '<td align="right" style="padding:' . ($big ? '14px' : '10px') . ' 20px;font-family:' . esc_attr($p['sans']) . ';font-size:' . ($big ? '15px' : '13px') . ';font-weight:' . ($big ? '700' : '500') . ';line-height:1.5;color:' . esc_attr($big ? $p['text'] : $p['sub']) . ';">'
            . wp_kses_post($total['label']) . '</td>';
        $html .= '<td align="right" style="padding:' . ($big ? '14px' : '10px') . ' 20px;font-family:' . esc_attr($p['sans']) . ';font-size:' . ($big ? '18px' : '13px') . ';font-weight:' . ($big ? '700' : '500') . ';line-height:1.5;white-space:nowrap;color:' . esc_attr($big ? $p['text'] : $p['sub']) . ';">'
            . wp_kses_post($total['value']) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    return $html;
}

/**
 * The customer's own details rows, as submitted on the booking form.
 * Shared by the HTML and plain-text builders.
 */
function wedding_booking_email_customer_facts_rows($order)
{
    if (! $order) {
        return [];
    }

    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $rows = [
        ['label' => __('Name', 'wedding-booking'), 'value' => $name],
        ['label' => __('Email', 'wedding-booking'), 'value' => $order->get_billing_email()],
        ['label' => __('Phone', 'wedding-booking'), 'value' => $order->get_billing_phone()],
        ['label' => __('Guests', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_participants', true)],
        ['label' => __('Place', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_hotel_place', true)],
        ['label' => __('Room', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_room_number', true)],
        ['label' => __('Stay period', 'wedding-booking'), 'value' => (string) $order->get_meta('_wedding_booking_billing_stay_period', true)],
    ];

    // WooCommerce joins address lines with <br/>; turn those into commas
    // first, or stripping the tags would run the lines together.
    $address = (string) $order->get_formatted_billing_address();
    $address = preg_replace('#<br\s*/?>#i', ', ', $address);
    $address = trim(preg_replace('/\s*,\s*/', ', ', wp_strip_all_tags($address, true)), " ,\t\n");
    if ($address !== '') {
        $rows[] = ['label' => __('Address', 'wedding-booking'), 'value' => $address];
    }

    // Admin-defined custom checkout fields.
    if (function_exists('wedding_booking_get_custom_checkout_fields')) {
        foreach (wedding_booking_get_custom_checkout_fields() as $key => $field) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => (string) $order->get_meta('_wedding_booking_cf_' . $key, true),
            ];
        }
    }

    return $rows;
}

/**
 * The customer's own details, as submitted on the booking form.
 */
function wedding_booking_email_customer_facts_html($order)
{
    return wedding_booking_email_facts(wedding_booking_email_customer_facts_rows($order));
}

/* ─────────────────────────────────────────────────────────────
   Plain-text counterparts
───────────────────────────────────────────────────────────── */

function wedding_booking_email_plain_rule()
{
    return "\n" . str_repeat('-', 48) . "\n\n";
}

/**
 * HTML → readable plain text: line breaks survive as newlines and entities
 * become real characters, so a customer never reads "&#2547;" or finds two
 * address lines welded together.
 */
function wedding_booking_email_plain_from_html($html)
{
    $text = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
    $text = preg_replace('#</(p|div|tr|h[1-6])>#i', "\n", $text);
    $text = wp_strip_all_tags($text);

    return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
}

/**
 * Plain-text label/value list matching wedding_booking_email_facts().
 */
function wedding_booking_email_plain_facts($rows)
{
    $out = '';
    foreach ((array) $rows as $row) {
        if (trim((string) ($row['value'] ?? '')) === '') {
            continue;
        }
        $out .= $row['label'] . ': ' . $row['value'] . "\n";
    }

    return $out;
}

/**
 * Default wording of the balance reminder (Wedding Booking → Settings). The email
 * renders its own "Pay Remaining Balance" button, so the default copy no
 * longer spells the link out — {pay_link} still works for anyone who wants it.
 */
function wedding_booking_balance_reminder_default_template()
{
    return __("Hi {customer_name},\n\nThis is a friendly reminder that {balance_amount} is still due for your booking on {session_date}.\n\nThank you.", 'wedding-booking');
}

function wedding_booking_balance_reminder_default_subject()
{
    return __('Payment reminder for your booking', 'wedding-booking');
}

/**
 * Balance-reminder settings (Wedding Booking → Settings → Emails → Balance reminders).
 *
 * Two independent schedules:
 * - "before": one email N days before the photoshoot. Its switch is the
 *   original wedding_booking_enable_balance_reminders option, so sites that already had
 *   reminders on keep them on.
 * - "repeat": an email every N days until the balance is paid, optionally
 *   capped at a number of reminders or stopped once the shoot date passes.
 *
 * repeat_since is set when "repeat" is switched on, so turning it on doesn't
 * email every old unpaid booking in the same hour.
 */
function wedding_booking_get_balance_reminder_settings()
{
    $subject  = trim((string) get_option('wedding_booking_balance_reminder_subject', ''));
    $template = trim((string) get_option('wedding_booking_balance_reminder_template', ''));

    return [
        'before_enable'           => (int) get_option('wedding_booking_enable_balance_reminders', 0) === 1,
        'days_before'             => max(0, min(60, (int) get_option('wedding_booking_balance_reminder_days_before', 1))),
        'repeat_enable'           => (int) get_option('wedding_booking_balance_reminder_repeat_enable', 0) === 1,
        'repeat_days'             => max(1, min(60, (int) get_option('wedding_booking_balance_reminder_repeat_days', 3))),
        'repeat_max'              => max(0, min(100, (int) get_option('wedding_booking_balance_reminder_repeat_max', 0))),
        'repeat_stop_after_shoot' => (int) get_option('wedding_booking_balance_reminder_repeat_stop_after_shoot', 0) === 1,
        'repeat_since'            => (int) get_option('wedding_booking_balance_reminder_repeat_since', 0),
        // Hour (site time) the "before" reminder goes out.
        'hour'                    => max(0, min(23, (int) apply_filters('wedding_booking_balance_reminder_hour', 9))),
        'subject'                 => $subject !== '' ? $subject : wedding_booking_balance_reminder_default_subject(),
        'template'                => $template !== '' ? $template : wedding_booking_balance_reminder_default_template(),
    ];
}

/**
 * Whether any automatic reminder schedule is switched on.
 */
function wedding_booking_balance_reminders_active()
{
    $cfg = wedding_booking_get_balance_reminder_settings();
    return $cfg['before_enable'] || $cfg['repeat_enable'];
}

/**
 * Save the reminder card. Shared by the settings page's POST handler
 * (admin.php) and its AJAX handler (ajax.php) so the two can't drift apart.
 *
 * @param array $post Raw $_POST (slashed).
 */
function wedding_booking_save_balance_reminder_settings($post)
{
    $flag = static function ($key) use ($post) {
        return absint(wp_unslash($post[$key] ?? 0)) === 1 ? 1 : 0;
    };

    update_option('wedding_booking_enable_balance_reminders', $flag('wedding_booking_enable_balance_reminders'));
    update_option('wedding_booking_balance_reminder_days_before', min(60, absint(wp_unslash($post['wedding_booking_balance_reminder_days_before'] ?? 1))));

    $repeat_was_on = (int) get_option('wedding_booking_balance_reminder_repeat_enable', 0) === 1;
    $repeat_on     = $flag('wedding_booking_balance_reminder_repeat_enable');
    update_option('wedding_booking_balance_reminder_repeat_enable', $repeat_on);
    if ($repeat_on && ! $repeat_was_on) {
        update_option('wedding_booking_balance_reminder_repeat_since', time());
    }
    update_option('wedding_booking_balance_reminder_repeat_days', max(1, min(60, absint(wp_unslash($post['wedding_booking_balance_reminder_repeat_days'] ?? 3)))));
    update_option('wedding_booking_balance_reminder_repeat_max', min(100, absint(wp_unslash($post['wedding_booking_balance_reminder_repeat_max'] ?? 0))));
    update_option('wedding_booking_balance_reminder_repeat_stop_after_shoot', $flag('wedding_booking_balance_reminder_repeat_stop_after_shoot'));

    // A blank subject or message would send an empty email; fall back to the
    // defaults instead.
    $subject = sanitize_text_field(wp_unslash($post['wedding_booking_balance_reminder_subject'] ?? ''));
    update_option('wedding_booking_balance_reminder_subject', $subject !== '' ? $subject : wedding_booking_balance_reminder_default_subject());
    $template = trim(wp_kses_post(wp_unslash($post['wedding_booking_balance_reminder_template'] ?? '')));
    update_option('wedding_booking_balance_reminder_template', $template !== '' ? $template : wedding_booking_balance_reminder_default_template());
}

/**
 * Send a Wedding Booking system email (anything outside WooCommerce's own mailer)
 * through the branded shell.
 *
 * @param string $to
 * @param string $subject
 * @param string $content Inner HTML built with the component helpers.
 * @param array  $args    Passed to wedding_booking_email_wrap(), plus 'headers'.
 */
function wedding_booking_email_send($to, $subject, $content, $args = [])
{
    $headers = (array) ($args['headers'] ?? []);
    unset($args['headers']);
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $html = wedding_booking_email_wrap($content, $args);

    return wp_mail($to, wp_specialchars_decode(sanitize_text_field($subject)), $html, $headers);
}
