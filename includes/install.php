<?php
defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
═══════════════════════════════════════════════════════════════ */
function wedding_booking_activate()
{
    wedding_booking_create_tables();
    wedding_booking_create_wc_product();
    flush_rewrite_rules();
}

function wedding_booking_deactivate()
{
    wp_clear_scheduled_hook('wedding_booking_balance_reminder_sweep');
    flush_rewrite_rules();
}

/* ═══════════════════════════════════════════════════════════════
   PACKAGE SHARE-LINK SLUGS
═══════════════════════════════════════════════════════════════ */
/**
 * Unique, URL-safe slug for a package share link. Latin names become
 * "golden-hour"-style slugs; non-Latin names (sanitize_title would
 * percent-encode them) and purely numeric ones fall back to a
 * "package-N" form so a slug can never be mistaken for a package ID.
 * Generated once and kept on rename so shared links never break.
 */
function wedding_booking_unique_package_slug($name, $exclude_id = 0)
{
    global $wpdb;
    $pfx = $wpdb->prefix . 'wedding_booking_';

    $slug = sanitize_title(remove_accents((string) $name));
    if ($slug === '' || strpos($slug, '%') !== false) {
        $slug = 'package';
    } elseif (ctype_digit($slug)) {
        $slug = 'package-' . $slug;
    }

    $base = $slug;
    $i    = 2;
    while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pfx}packages WHERE slug = %s AND id != %d", $slug, $exclude_id)) > 0) { // phpcs:ignore
        $slug = $base . '-' . $i++;
    }

    return $slug;
}

/* ═══════════════════════════════════════════════════════════════
   CREATE DATABASE TABLES
═══════════════════════════════════════════════════════════════ */
function wedding_booking_create_tables()
{
    global $wpdb;
    $c   = $wpdb->get_charset_collate();
    $pfx = $wpdb->prefix . 'wedding_booking_';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Session types (e.g. "Holiday Photoshoot", "Wedding Photography")
    dbDelta("CREATE TABLE {$pfx}sessions (
		id       mediumint(9) NOT NULL AUTO_INCREMENT,
		name     varchar(100) NOT NULL,
		emoji    varchar(100) DEFAULT '',
		slug     varchar(50)  NOT NULL,
		active   tinyint(1)   DEFAULT 1,
		sort_order int        DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY slug (slug)
	) $c;");

    // Packages — belong to a session type. slug is the stable identifier
    // used in shareable ?wedding_booking_package= links; it never changes on rename.
    dbDelta("CREATE TABLE {$pfx}packages (
		id          mediumint(9) NOT NULL AUTO_INCREMENT,
		session_id  mediumint(9) NOT NULL,
		name        varchar(100) NOT NULL,
		slug        varchar(120) DEFAULT '',
		price       decimal(10,2) NOT NULL DEFAULT 0.00,
		duration    varchar(80)  DEFAULT '',
		description text,
		featured    tinyint(1)   DEFAULT 0,
		deposit_pct tinyint(3) unsigned NOT NULL DEFAULT 0,
		sort_order  int          DEFAULT 0,
		active      tinyint(1)   DEFAULT 1,
		PRIMARY KEY (id),
		KEY session_id (session_id),
		KEY slug (slug)
	) $c;");

    // Backfill share-link slugs for packages created before the slug column.
    $missing_slugs = $wpdb->get_results("SELECT id, name FROM {$pfx}packages WHERE slug IS NULL OR slug = ''"); // phpcs:ignore
    foreach ($missing_slugs as $pkg_row) {
        $wpdb->update("{$pfx}packages", ['slug' => wedding_booking_unique_package_slug($pkg_row->name, (int) $pkg_row->id)], ['id' => (int) $pkg_row->id]); // phpcs:ignore
    }

    // Add-ons — global (no package list) or tied to specific packages.
    // package_ids is a CSV of package ids; package_id is kept as a legacy
    // single-package column (first id of the list, 0 = global).
    dbDelta("CREATE TABLE {$pfx}addons (
		id          mediumint(9) NOT NULL AUTO_INCREMENT,
		name        varchar(100) NOT NULL,
		price       decimal(10,2) NOT NULL DEFAULT 0.00,
		emoji       varchar(100) DEFAULT '',
		description text,
		package_id  mediumint(9) NOT NULL DEFAULT 0,
		package_ids varchar(255) DEFAULT '',
		active      tinyint(1)   DEFAULT 1,
		sort_order  int          DEFAULT 0,
		PRIMARY KEY (id),
		KEY package_id (package_id)
	) $c;");

    // Migrate legacy single-package add-ons to the multi-package list.
    $wpdb->query("UPDATE {$pfx}addons SET package_ids = package_id WHERE (package_ids IS NULL OR package_ids = '') AND package_id > 0"); // phpcs:ignore

    // Date slots — dates marked booked/blocked by admin; all other future dates = available
    dbDelta("CREATE TABLE {$pfx}dates (
		id       mediumint(9) NOT NULL AUTO_INCREMENT,
		date_str date         NOT NULL,
		status   varchar(20)  DEFAULT 'booked',
		notes    varchar(200) DEFAULT '',
		PRIMARY KEY (id),
		UNIQUE KEY date_str (date_str)
	) $c;");

    // Bookings — created on WooCommerce payment complete (or fallback AJAX submit)
    dbDelta("CREATE TABLE {$pfx}bookings (
		id            mediumint(9)  NOT NULL AUTO_INCREMENT,
		order_id      bigint        DEFAULT NULL,
		session_type  varchar(100)  NOT NULL DEFAULT '',
		package_name  varchar(100)  NOT NULL DEFAULT '',
		package_price decimal(10,2) NOT NULL DEFAULT 0.00,
		addons_json   text,
		addons_total  decimal(10,2) DEFAULT 0.00,
		total         decimal(10,2) NOT NULL DEFAULT 0.00,
		deposit       decimal(10,2) NOT NULL DEFAULT 0.00,
		client_name   varchar(200)  NOT NULL DEFAULT '',
		client_email  varchar(200)  NOT NULL DEFAULT '',
		client_phone  varchar(100)  DEFAULT '',
		client_country varchar(100) DEFAULT '',
		session_date  date          DEFAULT NULL,
		session_time  varchar(100)  DEFAULT '',
		location_pref varchar(200)  DEFAULT '',
		notes         text,
		signer_name   varchar(200)  DEFAULT '',
		status        varchar(50)   DEFAULT 'pending',
		gcal_event_id varchar(120)  NOT NULL DEFAULT '',
		created_at    datetime      DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY order_id (order_id)
	) $c;");

    // Let Shop Managers run bookings (manage_wedding_booking), on install and upgrade.
    if (function_exists('wedding_booking_grant_capabilities')) {
        wedding_booking_grant_capabilities();
    }

    update_option('wedding_booking_db_version', WEDDING_BOOKING_VER);
}

/* ═══════════════════════════════════════════════════════════════
   MIGRATE EMOJI — fill blank emoji columns on existing DB rows
   Runs once; gated by option flag so it never re-runs.
═══════════════════════════════════════════════════════════════ */
function wedding_booking_migrate_emoji()
{
    if (get_option('wedding_booking_emoji_migrated')) {
        return;
    }
    global $wpdb;
    $pfx = $wpdb->prefix . 'wedding_booking_';

    // Sessions: match by slug (slug never changes)
    $session_emoji = [
        'photo'   => wp_encode_emoji('📷'),
        'wedding' => wp_encode_emoji('💍'),
        'video'   => wp_encode_emoji('🎬'),
        'booth'   => wp_encode_emoji('📸'),
    ];
    foreach ($session_emoji as $slug => $emoji) {
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "UPDATE {$pfx}sessions SET emoji = %s WHERE slug = %s AND (emoji = '' OR emoji IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $emoji,
                $slug
            )
        );
    }

    // Add-ons: match by name
    $addon_emoji = [
        'Drone aerial session'   => wp_encode_emoji('🚁'),
        'Printed album 20×30cm' => wp_encode_emoji('🖨️'),
        'Rush delivery'          => wp_encode_emoji('⚡'),
    ];
    foreach ($addon_emoji as $name => $emoji) {
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "UPDATE {$pfx}addons SET emoji = %s WHERE name = %s AND (emoji = '' OR emoji IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $emoji,
                $name
            )
        );
    }

    update_option('wedding_booking_emoji_migrated', '1');
}

/* ═══════════════════════════════════════════════════════════════
   CREATE HIDDEN WOOCOMMERCE PRODUCT (deposit placeholder)
═══════════════════════════════════════════════════════════════ */
function wedding_booking_create_wc_product()
{
    if (! class_exists('WooCommerce')) {
        return;
    }
    $existing_id = (int) get_option('wedding_booking_wc_product_id', 0);
    if ($existing_id && 'publish' === get_post_status($existing_id)) {
        return; // product already exists
    }

    $product = new WC_Product_Simple();
    $product->set_name(__('Photography Session Booking', 'wedding-booking'));
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_sold_individually(true);
    $product->set_price(1);
    $product->set_regular_price(1);
    $id = $product->save();

    update_option('wedding_booking_wc_product_id', $id);
}
