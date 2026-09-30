<?php

/**
 * Google Calendar integration — one-click connect for a single site.
 *
 * There is no broker/server to run. You create one Google OAuth app once and
 * save its Client ID + Secret in Wedding Booking → Settings → Google Calendar (no file
 * editing). From then on the admin just clicks "Connect with Google" and
 * approves. "Connect" goes straight to accounts.google.com.
 *
 * Register the site's redirect URI (shown on the settings screen) under the
 * Google app's "Authorized redirect URIs".
 *
 * Flow:
 *   1. Admin clicks "Connect with Google" (wedding_booking_gcal_connect) → redirect
 *      to Google's consent screen with the site's own credentials.
 *   2. Google redirects back to our callback (wedding_booking_gcal_callback) with an
 *      authorization code, which we swap for tokens directly at Google.
 *   3. On every paid booking we schedule a background job that pushes an event
 *      to the connected calendar (package + order number, client, notes).
 */

defined('ABSPATH') || exit;

/* ═══════════════════════════════════════════════════════════════
   GOOGLE APP CREDENTIALS
   ───────────────────────────────────────────────────────────────
   Saved from the plugin backend (Wedding Booking → Settings → Google
   Calendar) into the wedding_booking_gcal_client_id / wedding_booking_gcal_client_secret
   options — nothing to edit in any file. Advanced installs may
   instead define WEDDING_BOOKING_GCAL_CLIENT_ID / _SECRET in wp-config.php,
   which then take precedence over the saved fields.
═══════════════════════════════════════════════════════════════ */

function wedding_booking_gcal_client_id()
{
    if (defined('WEDDING_BOOKING_GCAL_CLIENT_ID') && WEDDING_BOOKING_GCAL_CLIENT_ID) {
        return (string) WEDDING_BOOKING_GCAL_CLIENT_ID;
    }
    return trim((string) get_option('wedding_booking_gcal_client_id', ''));
}

function wedding_booking_gcal_client_secret()
{
    if (defined('WEDDING_BOOKING_GCAL_CLIENT_SECRET') && WEDDING_BOOKING_GCAL_CLIENT_SECRET) {
        return (string) WEDDING_BOOKING_GCAL_CLIENT_SECRET;
    }
    return trim((string) get_option('wedding_booking_gcal_client_secret', ''));
}

function wedding_booking_gcal_has_credentials()
{
    return wedding_booking_gcal_client_id() !== '' && wedding_booking_gcal_client_secret() !== '';
}

/**
 * True only when the credentials come from wp-config constants — then the
 * settings screen shows a short note in place of the editable fields.
 */
function wedding_booking_gcal_creds_from_constant()
{
    return (defined('WEDDING_BOOKING_GCAL_CLIENT_ID') && WEDDING_BOOKING_GCAL_CLIENT_ID)
        && (defined('WEDDING_BOOKING_GCAL_CLIENT_SECRET') && WEDDING_BOOKING_GCAL_CLIENT_SECRET);
}

/**
 * The exact redirect URI to register on the Google OAuth client for this site.
 * Google matches it byte-for-byte, so it is always built the same way.
 */
function wedding_booking_gcal_redirect_uri()
{
    return admin_url('admin-post.php?action=wedding_booking_gcal_callback');
}

/**
 * Requested scopes: create/manage calendar events, plus openid+email so we can
 * show which account is connected. Least privilege — no read of other calendars.
 */
function wedding_booking_gcal_scopes()
{
    return (string) apply_filters(
        'wedding_booking_gcal_scopes',
        'openid email https://www.googleapis.com/auth/calendar.events'
    );
}

/* ═══════════════════════════════════════════════════════════════
   CONNECTION STATE
═══════════════════════════════════════════════════════════════ */

/**
 * Stored connection: access_token, refresh_token, expires_at (unix),
 * email, scope, calendar_id, connected_at. Empty array when not connected.
 */
function wedding_booking_gcal_get_connection()
{
    $conn = get_option('wedding_booking_gcal_connection', []);
    return is_array($conn) ? $conn : [];
}

function wedding_booking_gcal_is_connected()
{
    $conn = wedding_booking_gcal_get_connection();
    return ! empty($conn['refresh_token']);
}

/**
 * Whether new bookings should be pushed to Google Calendar right now:
 * connected AND the sync toggle is on.
 */
function wedding_booking_gcal_sync_enabled()
{
    return wedding_booking_gcal_is_connected() && (int) get_option('wedding_booking_gcal_enabled', 1) === 1;
}

/**
 * Calendar the events are written to. 'primary' is the connected account's
 * own calendar; a filter lets integrators target a shared calendar.
 */
function wedding_booking_gcal_calendar_id()
{
    $conn = wedding_booking_gcal_get_connection();
    $id   = ! empty($conn['calendar_id']) ? $conn['calendar_id'] : 'primary';
    return (string) apply_filters('wedding_booking_gcal_calendar_id', $id);
}

function wedding_booking_gcal_store_connection(array $conn)
{
    // autoload=no: tokens are only needed on admin + booking events, not on
    // every front-end page load.
    update_option('wedding_booking_gcal_connection', $conn, false);
}

function wedding_booking_gcal_forget_connection()
{
    delete_option('wedding_booking_gcal_connection');
    delete_option('wedding_booking_gcal_last_error');
}

function wedding_booking_gcal_set_error($message)
{
    update_option('wedding_booking_gcal_last_error', (string) $message, false);
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — CONNECT (straight to Google)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_wedding_booking_gcal_connect', 'wedding_booking_gcal_handle_connect');
function wedding_booking_gcal_handle_connect()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'wedding-booking'));
    }
    check_admin_referer('wedding_booking_gcal_connect');

    if (! wedding_booking_gcal_has_credentials()) {
        wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'error', 'reason' => 'nocreds']);
    }

    // CSRF: a random state we check when Google sends the browser back.
    $state = wp_generate_password(32, false, false);
    set_transient('wedding_booking_gcal_oauth', [
        'state' => $state,
        'user'  => get_current_user_id(),
    ], 15 * MINUTE_IN_SECONDS);

    // add_query_arg() does not encode values it adds, so encode them here.
    $auth = add_query_arg(
        [
            'client_id'              => rawurlencode(wedding_booking_gcal_client_id()),
            'redirect_uri'           => rawurlencode(wedding_booking_gcal_redirect_uri()),
            'response_type'          => 'code',
            'scope'                  => rawurlencode(wedding_booking_gcal_scopes()),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ],
        'https://accounts.google.com/o/oauth2/v2/auth'
    );

    // External host → wp_redirect (wp_safe_redirect would block Google).
    wp_redirect($auth); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberate off-site redirect to Google's consent screen.
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — CALLBACK (Google → us, swap code for tokens)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_wedding_booking_gcal_callback', 'wedding_booking_gcal_handle_callback');
function wedding_booking_gcal_handle_callback()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'wedding-booking'));
    }

    $stored = get_transient('wedding_booking_gcal_oauth');
    delete_transient('wedding_booking_gcal_oauth');

    // This is Google's OAuth redirect, not a WP form: the CSRF proof is the
    // `state` value checked against the stored transient below, so the standard
    // WP nonce sniff does not apply to these reads.
    $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($error !== '') {
        // Keep Google's own wording — it names the real cause (unverified app,
        // tester-only access, disabled API) far better than we can guess.
        $desc = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        wedding_booking_gcal_set_error('Google returned "' . $error . '"' . ($desc !== '' ? ': ' . $desc : '.'));

        // access_denied is either "user pressed Cancel" or "the app is still in
        // Testing and this account is not a test user" — the latter needs a
        // different fix, so it gets its own notice.
        wedding_booking_gcal_redirect_settings([
            'wedding_booking_gcal' => 'error',
            'reason'  => ($error === 'access_denied') ? 'access_denied' : 'denied',
        ]);
    }

    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';    // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if (! is_array($stored) || empty($stored['state']) || ! hash_equals($stored['state'], $state) || $code === '') {
        wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'error', 'reason' => 'state']);
    }

    if (! wedding_booking_gcal_has_credentials()) {
        wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'error', 'reason' => 'nocreds']);
    }

    // Exchange the authorization code for tokens, directly with Google.
    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 25,
        'body'    => [
            'code'          => $code,
            'client_id'     => wedding_booking_gcal_client_id(),
            'client_secret' => wedding_booking_gcal_client_secret(),
            'redirect_uri'  => wedding_booking_gcal_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ],
    ]);

    if (is_wp_error($res)) {
        wedding_booking_gcal_set_error($res->get_error_message());
        wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'error', 'reason' => 'network']);
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);

    if (empty($data['access_token']) || empty($data['refresh_token'])) {
        // Google returns { error, error_description } on failure — surface it so
        // a redirect-URI mismatch or a wrong secret is diagnosable.
        $detail = isset($data['error_description']) ? $data['error_description']
            : (isset($data['error']) ? $data['error'] : ('HTTP ' . (int) wp_remote_retrieve_response_code($res)));
        wedding_booking_gcal_set_error('Token exchange failed: ' . $detail);
        wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'error', 'reason' => 'exchange']);
    }

    wedding_booking_gcal_store_connection([
        'access_token'  => sanitize_text_field($data['access_token']),
        'refresh_token' => sanitize_text_field($data['refresh_token']),
        'expires_at'    => time() + (int) ($data['expires_in'] ?? 3500),
        'email'         => wedding_booking_gcal_email_from_id_token($data['id_token'] ?? ''),
        'scope'         => isset($data['scope']) ? sanitize_text_field($data['scope']) : '',
        'calendar_id'   => 'primary',
        'connected_at'  => time(),
    ]);
    delete_option('wedding_booking_gcal_last_error');
    update_option('wedding_booking_gcal_enabled', 1);

    wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'connected']);
}

/* ═══════════════════════════════════════════════════════════════
   OAUTH — DISCONNECT (revoke at Google)
═══════════════════════════════════════════════════════════════ */

add_action('admin_post_wedding_booking_gcal_disconnect', 'wedding_booking_gcal_handle_disconnect');
function wedding_booking_gcal_handle_disconnect()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'wedding-booking'));
    }
    check_admin_referer('wedding_booking_gcal_disconnect');

    $conn  = wedding_booking_gcal_get_connection();
    $token = ! empty($conn['refresh_token']) ? $conn['refresh_token'] : ($conn['access_token'] ?? '');
    if ($token !== '') {
        // Best-effort revoke so the grant is dropped on Google's side too.
        wp_remote_post('https://oauth2.googleapis.com/revoke', [
            'timeout'  => 15,
            'blocking' => false,
            'body'     => ['token' => $token],
        ]);
    }

    wedding_booking_gcal_forget_connection();
    delete_option('wedding_booking_gcal_enabled');

    wedding_booking_gcal_redirect_settings(['wedding_booking_gcal' => 'disconnected']);
}

function wedding_booking_gcal_redirect_settings(array $args)
{
    wp_safe_redirect(add_query_arg(array_merge(['page' => 'wedding-booking-settings'], $args), admin_url('admin.php')) . '#wedding-booking-gcal');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACCESS TOKEN — cached, auto-refreshed directly with Google
═══════════════════════════════════════════════════════════════ */

/**
 * A valid access token, refreshing with Google when the cached one is near
 * expiry. Returns '' when not connected or the refresh failed.
 */
function wedding_booking_gcal_access_token()
{
    $conn = wedding_booking_gcal_get_connection();
    if (empty($conn['refresh_token']) || ! wedding_booking_gcal_has_credentials()) {
        return '';
    }

    $now = time();
    if (! empty($conn['access_token']) && ! empty($conn['expires_at']) && $conn['expires_at'] > ($now + 60)) {
        return $conn['access_token'];
    }

    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 25,
        'body'    => [
            'client_id'     => wedding_booking_gcal_client_id(),
            'client_secret' => wedding_booking_gcal_client_secret(),
            'refresh_token' => $conn['refresh_token'],
            'grant_type'    => 'refresh_token',
        ],
    ]);

    if (is_wp_error($res)) {
        wedding_booking_gcal_set_error($res->get_error_message());
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);

    // A revoked / expired grant comes back as invalid_grant. When that happens
    // the connection is dead — drop it so the UI prompts a reconnect. Note that
    // a Google app left in "Testing" expires its refresh tokens after 7 days,
    // which lands here too — hence the hint.
    if ($code === 400 && isset($data['error']) && $data['error'] === 'invalid_grant') {
        wedding_booking_gcal_forget_connection();
        wedding_booking_gcal_set_error('Google access expired or was revoked. Reconnect — and if your Google app is still in "Testing", publish it so the connection stops expiring every 7 days.');
        return '';
    }

    if (empty($data['access_token'])) {
        wedding_booking_gcal_set_error('Token refresh failed (HTTP ' . $code . ').');
        return '';
    }

    $conn['access_token'] = sanitize_text_field($data['access_token']);
    $conn['expires_at']   = $now + (int) ($data['expires_in'] ?? 3500);
    wedding_booking_gcal_store_connection($conn);

    return $conn['access_token'];
}

/**
 * Pull the account email out of Google's id_token (a JWT). The token comes
 * straight from Google over the TLS token exchange, so the payload is trusted
 * without re-verifying the signature. Returns '' if absent.
 */
function wedding_booking_gcal_email_from_id_token($id_token)
{
    $parts = explode('.', (string) $id_token);
    if (count($parts) < 2) {
        return '';
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a JWT payload from Google, not obfuscation.
    return is_array($payload) && ! empty($payload['email']) ? sanitize_email($payload['email']) : '';
}

/* ═══════════════════════════════════════════════════════════════
   BOOKING → CALENDAR EVENT
═══════════════════════════════════════════════════════════════ */

/**
 * POST an event body to the connected calendar. Shared by the booking sync and
 * the admin test button so both send guests the same way.
 *
 * sendUpdates=all is what actually emails the invitation; without it Google
 * records the attendee silently and the client never hears about it.
 */
function wedding_booking_gcal_insert_event(array $event, $token)
{
    $calendar = rawurlencode(wedding_booking_gcal_calendar_id());
    $url      = add_query_arg(
        ['sendUpdates' => empty($event['attendees']) ? 'none' : 'all'],
        "https://www.googleapis.com/calendar/v3/calendars/{$calendar}/events"
    );

    return wp_remote_post($url, [
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($event),
    ]);
}

/**
 * Keep a booking's calendar event in step with the booking. The Google call
 * runs in a background job (+5s) so a slow API never delays a checkout or an
 * admin screen.
 */
add_action('wedding_booking_booking_created', 'wedding_booking_gcal_queue_sync', 10, 1);
add_action('wedding_booking_booking_cancelled', 'wedding_booking_gcal_queue_sync', 10, 1);
add_action('wedding_booking_booking_updated', 'wedding_booking_gcal_queue_sync', 10, 1);
function wedding_booking_gcal_queue_sync($booking_id)
{
    $booking_id = (int) $booking_id;
    if ($booking_id < 1 || ! wedding_booking_gcal_sync_enabled()) {
        return;
    }
    if (! wp_next_scheduled('wedding_booking_gcal_sync_event', [$booking_id])) {
        wp_schedule_single_event(time() + 5, 'wedding_booking_gcal_sync_event', [$booking_id]);
    }
}

add_action('wedding_booking_gcal_sync_event', 'wedding_booking_gcal_sync_booking', 10, 1);
// Jobs queued by 1.3.x/1.4.x under the old name still run.
add_action('wedding_booking_gcal_create_event', 'wedding_booking_gcal_sync_booking', 10, 1);

/**
 * Make the calendar match the booking: create the event, update it (after an
 * edit or a reschedule), or delete it (cancelled; Google tells the client).
 * Failures are queued for retry with back-off.
 *
 * @return true|WP_Error
 */
function wedding_booking_gcal_sync_booking($booking_id)
{
    $booking_id = (int) $booking_id;
    if (! wedding_booking_gcal_sync_enabled()) {
        return new WP_Error('wedding_booking_gcal_off', __('Google Calendar is not connected or sync is paused.', 'wedding-booking'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wedding_booking_bookings';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom bookings table; only the trusted table prefix is interpolated.
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $booking_id));
    if (! $booking) {
        wedding_booking_gcal_retry_done($booking_id);
        return new WP_Error('wedding_booking_gcal_missing', __('Booking not found.', 'wedding-booking'));
    }

    $token = wedding_booking_gcal_access_token();
    if ($token === '') {
        return wedding_booking_gcal_sync_failed($booking_id, (string) get_option('wedding_booking_gcal_last_error', __('Could not obtain a Google access token.', 'wedding-booking')));
    }

    $event_id = (string) ($booking->gcal_event_id ?? '');
    $base     = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(wedding_booking_gcal_calendar_id()) . '/events';
    $headers  = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

    // Cancelled: remove the event (if there is one).
    if ((string) $booking->status === 'cancelled') {
        if ($event_id === '') {
            wedding_booking_gcal_retry_done($booking_id);
            return true;
        }
        $res  = wp_remote_request(add_query_arg('sendUpdates', 'all', $base . '/' . rawurlencode($event_id)), ['method' => 'DELETE', 'timeout' => 25, 'headers' => $headers]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        // 404/410: already gone from Google, nothing left to do.
        if (in_array($code, [200, 204, 404, 410], true)) {
            $wpdb->update($table, ['gcal_event_id' => ''], ['id' => $booking_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wedding_booking_gcal_retry_done($booking_id);
            return true;
        }
        return wedding_booking_gcal_sync_failed($booking_id, is_wp_error($res) ? $res->get_error_message() : wedding_booking_gcal_api_error($res));
    }

    $event = wedding_booking_gcal_build_event($booking);
    if (empty($event)) {
        wedding_booking_gcal_retry_done($booking_id);
        return new WP_Error('wedding_booking_gcal_nodate', __('The booking has no usable date, so there is nothing to put in the calendar.', 'wedding-booking'));
    }

    // Update the existing event; if it was deleted in Google, create a new one.
    if ($event_id !== '') {
        $res  = wp_remote_request(add_query_arg('sendUpdates', empty($event['attendees']) ? 'none' : 'all', $base . '/' . rawurlencode($event_id)), [
            'method'  => 'PATCH',
            'timeout' => 25,
            'headers' => $headers,
            'body'    => wp_json_encode($event),
        ]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 300) {
            wedding_booking_gcal_retry_done($booking_id);
            delete_option('wedding_booking_gcal_last_error');
            return true;
        }
        if (! in_array($code, [404, 410], true)) {
            return wedding_booking_gcal_sync_failed($booking_id, is_wp_error($res) ? $res->get_error_message() : wedding_booking_gcal_api_error($res));
        }
    }

    $res = wedding_booking_gcal_insert_event($event, $token);
    if (is_wp_error($res)) {
        return wedding_booking_gcal_sync_failed($booking_id, $res->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($code >= 200 && $code < 300 && ! empty($data['id'])) {
        $wpdb->update($table, ['gcal_event_id' => sanitize_text_field($data['id'])], ['id' => $booking_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        wedding_booking_gcal_retry_done($booking_id);
        delete_option('wedding_booking_gcal_last_error');
        return true;
    }

    return wedding_booking_gcal_sync_failed($booking_id, wedding_booking_gcal_api_error($res));
}

function wedding_booking_gcal_api_error($res)
{
    $data = json_decode((string) wp_remote_retrieve_body($res), true);
    return isset($data['error']['message']) ? (string) $data['error']['message'] : ('HTTP ' . (int) wp_remote_retrieve_response_code($res));
}

/* ─────────────────────────────────────────────────────────────
   Retries: a failed sync is tried again 15 min, 30 min, 1 h, 2 h and
   4 h later (option wedding_booking_gcal_retry = [booking_id => [tries, next]]),
   then left for the studio to retry by hand from the booking.
───────────────────────────────────────────────────────────── */
function wedding_booking_gcal_sync_failed($booking_id, $message)
{
    /* translators: 1: booking id, 2: Google's error message */
    $text = sprintf(__('Booking #%1$d could not be synced to Google Calendar: %2$s', 'wedding-booking'), (int) $booking_id, $message);
    wedding_booking_gcal_set_error($text);

    $queue = get_option('wedding_booking_gcal_retry', []);
    $queue = is_array($queue) ? $queue : [];
    $tries = (int) ($queue[$booking_id]['tries'] ?? 0) + 1;
    if ($tries <= 5) {
        $queue[$booking_id] = ['tries' => $tries, 'next' => time() + (15 * MINUTE_IN_SECONDS) * (2 ** ($tries - 1))];
    } else {
        unset($queue[$booking_id]);
    }
    update_option('wedding_booking_gcal_retry', $queue, false);

    return new WP_Error('wedding_booking_gcal_failed', $text);
}

function wedding_booking_gcal_retry_done($booking_id)
{
    $queue = get_option('wedding_booking_gcal_retry', []);
    if (is_array($queue) && isset($queue[$booking_id])) {
        unset($queue[$booking_id]);
        update_option('wedding_booking_gcal_retry', $queue, false);
    }
}

/**
 * Run the retries that are due. Called from the hourly Wedding Booking maintenance
 * job (woocommerce.php).
 */
function wedding_booking_gcal_run_retries()
{
    if (! wedding_booking_gcal_sync_enabled()) {
        return;
    }
    $queue = get_option('wedding_booking_gcal_retry', []);
    foreach ((is_array($queue) ? $queue : []) as $booking_id => $entry) {
        if ((int) ($entry['next'] ?? 0) <= time()) {
            wedding_booking_gcal_sync_booking((int) $booking_id);
        }
    }
}

/**
 * Say so on the Wedding Booking screens when calendar sync is failing, instead of
 * only on the settings card.
 */
add_action('admin_notices', 'wedding_booking_gcal_admin_notice');
function wedding_booking_gcal_admin_notice()
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (strpos($page, 'wedding-booking-') !== 0 || ! function_exists('wedding_booking_can_manage') || ! wedding_booking_can_manage()) {
        return;
    }
    $error = (string) get_option('wedding_booking_gcal_last_error', '');
    if ($error === '' || ! wedding_booking_gcal_is_connected()) {
        return;
    }
    $queue   = get_option('wedding_booking_gcal_retry', []);
    $pending = is_array($queue) ? count($queue) : 0;
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=wedding_booking_gcal_dismiss_error'), 'wedding_booking_gcal_dismiss_error');

    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Google Calendar', 'wedding-booking') . ':</strong> ' . esc_html($error);
    if ($pending > 0) {
        /* translators: %d: bookings waiting to be synced */
        echo ' ' . esc_html(sprintf(_n('%d booking will be retried automatically.', '%d bookings will be retried automatically.', $pending, 'wedding-booking'), $pending));
    }
    echo ' <a href="' . esc_url(admin_url('admin.php?page=wedding-booking-settings#wedding-booking-gcal')) . '">' . esc_html__('Calendar settings', 'wedding-booking') . '</a> &middot; <a href="' . esc_url($dismiss) . '">' . esc_html__('Dismiss', 'wedding-booking') . '</a></p></div>';
}

add_action('admin_post_wedding_booking_gcal_dismiss_error', 'wedding_booking_gcal_dismiss_error');
function wedding_booking_gcal_dismiss_error()
{
    check_admin_referer('wedding_booking_gcal_dismiss_error');
    if (function_exists('wedding_booking_can_manage') && wedding_booking_can_manage()) {
        delete_option('wedding_booking_gcal_last_error');
    }
    wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=wedding-booking-bookings'));
    exit;
}

/**
 * Build the Google Calendar event body for a booking row. Pure (no network),
 * so it can be unit-tested. Returns [] when the booking has no usable date.
 *
 * Layout the studio asked for: package + order number as the title, the client's
 * chosen place as the event location, the client invited as a guest, a 2-hour
 * alert, and the session / contact details in the notes.
 */
function wedding_booking_gcal_build_event($booking)
{
    $package = trim((string) ($booking->package_name ?? ''));
    $session = trim((string) ($booking->session_type ?? ''));
    $addons  = trim((string) ($booking->addons_json ?? ''));
    $time    = trim((string) ($booking->session_time ?? ''));
    $date    = trim((string) ($booking->session_date ?? ''));
    $client  = trim((string) ($booking->client_name ?? ''));
    $email   = trim((string) ($booking->client_email ?? ''));
    $phone   = trim((string) ($booking->client_phone ?? ''));
    $place   = trim((string) ($booking->location_pref ?? ''));
    $id      = (int) $booking->id;
    $order   = (int) ($booking->order_id ?? 0);

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }

    // Title: package + order number — "Beach shooting #34182". Falls back to the
    // session type when a booking has no package, and to the booking id when it
    // was taken without a WooCommerce order (the no-Woo enquiry path).
    $shoot   = $package !== '' ? $package : ($session !== '' ? $session : __('Booking', 'wedding-booking'));
    $summary = $shoot . ' #' . ($order > 0 ? $order : $id);

    // Notes: the session, then everything needed to deal with the client
    // without opening wp-admin.
    $lines = [];
    if ($package !== '') {
        $lines[] = __('Package:', 'wedding-booking') . ' ' . $package;
    }
    $lines[] = __('Add-ons:', 'wedding-booking') . ' ' . ($addons !== '' ? $addons : __('None', 'wedding-booking'));
    if ($time !== '') {
        $lines[] = __('Time:', 'wedding-booking') . ' ' . $time;
    }
    $lines[] = '';
    if ($client !== '') {
        $lines[] = __('Client:', 'wedding-booking') . ' ' . $client;
    }
    if ($phone !== '') {
        $lines[] = __('Phone:', 'wedding-booking') . ' ' . $phone;
    }
    if ($email !== '') {
        $lines[] = __('Email:', 'wedding-booking') . ' ' . $email;
    }
    $lines[] = __('Location:', 'wedding-booking') . ' ' . ($place !== '' ? $place : __('Not given', 'wedding-booking'));

    // Google caps reminder overrides at 4 weeks (40320 minutes).
    $remind = (int) apply_filters('wedding_booking_gcal_reminder_minutes', 120);
    $remind = max(0, min(40320, $remind));

    $event = [
        'summary'     => $summary,
        'description' => implode("\n", $lines),
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'popup', 'minutes' => $remind],
            ],
        ],
        // Lets you find/filter Wedding Booking events in the calendar API later.
        'extendedProperties' => [
            'private' => ['wedding_booking_booking_id' => (string) $id],
        ],
    ];

    if ($place !== '') {
        $event['location'] = $place;
    }

    // Invite the client as a guest. wedding_booking_gcal_insert_event() switches
    // Google's sendUpdates on whenever this key is present, so they get the
    // invitation email and the shoot lands in their own calendar.
    if (is_email($email)) {
        $event['attendees'] = [
            ['email' => $email, 'displayName' => $client],
        ];
    }

    $times = wedding_booking_gcal_event_times($date, $time);
    if ($times) {
        $event['start'] = ['dateTime' => $times['start']];
        $event['end']   = ['dateTime' => $times['end']];
    } else {
        // All-day event: end date is exclusive in the Calendar API.
        $event['start'] = ['date' => $date];
        try {
            $end = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
        } catch (Exception $e) {
            $end = $date;
        }
        $event['end'] = ['date' => $end];
    }

    return apply_filters('wedding_booking_gcal_event', $event, $booking);
}

/**
 * Turn a booking's free-text time into ISO-8601 start/end (with the site's
 * UTC offset baked in, so no IANA zone name is needed). Returns null when no
 * clock time can be read out — the event then falls back to all-day.
 */
function wedding_booking_gcal_event_times($date, $time)
{
    if (! preg_match('/(\d{1,2})[:.](\d{2})\s*(am|pm)?|\b(\d{1,2})\s*(am|pm)\b/i', $time, $m)) {
        return null;
    }

    if (! empty($m[1])) {
        $hour = (int) $m[1];
        $min  = (int) $m[2];
        $mer  = strtolower($m[3] ?? '');
    } else {
        $hour = (int) $m[4];
        $min  = 0;
        $mer  = strtolower($m[5] ?? '');
    }

    if ($mer === 'pm' && $hour < 12) {
        $hour += 12;
    } elseif ($mer === 'am' && $hour === 12) {
        $hour = 0;
    }

    if ($hour > 23 || $min > 59) {
        return null;
    }

    $duration = (int) apply_filters('wedding_booking_gcal_event_duration', 60);
    $duration = max(15, $duration);

    try {
        $tz    = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $start = new DateTime($date, $tz);
        $start->setTime($hour, $min, 0);
        $end = (clone $start)->modify('+' . $duration . ' minutes');

        return [
            'start' => $start->format('c'),
            'end'   => $end->format('c'),
        ];
    } catch (Exception $e) {
        return null;
    }
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN — "Send test event" button
═══════════════════════════════════════════════════════════════ */

add_action('wp_ajax_wedding_booking_gcal_test_event', 'wedding_booking_gcal_ajax_test_event');
function wedding_booking_gcal_ajax_test_event()
{
    check_ajax_referer('wedding_booking_admin_nonce', 'nonce');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'wedding-booking')]);
    }
    if (! wedding_booking_gcal_is_connected()) {
        wp_send_json_error(['message' => __('Connect your Google account first.', 'wedding-booking')]);
    }

    $token = wedding_booking_gcal_access_token();
    if ($token === '') {
        wp_send_json_error(['message' => get_option('wedding_booking_gcal_last_error', __('Could not obtain an access token.', 'wedding-booking'))]);
    }

    // The guest is the connected account itself — the test exercises the invite
    // path without mailing a real client.
    $conn  = wedding_booking_gcal_get_connection();
    $today = current_time('Y-m-d');
    $event = wedding_booking_gcal_build_event((object) [
        'id'            => 0,
        'package_name'  => __('Sample package', 'wedding-booking'),
        'session_type'  => __('Wedding Booking test', 'wedding-booking'),
        'addons_json'   => __('Sample add-on', 'wedding-booking'),
        'session_time'  => '10:00',
        'session_date'  => $today,
        'client_name'   => __('Test booking', 'wedding-booking'),
        'client_email'  => isset($conn['email']) ? $conn['email'] : '',
        'client_phone'  => '+00 000 000 000',
        'location_pref' => __('Test location', 'wedding-booking'),
    ]);
    $event['summary'] = __('Wedding Booking · test event', 'wedding-booking');

    $res = wedding_booking_gcal_insert_event($event, $token);

    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()]);
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);

    if ($code >= 200 && $code < 300 && ! empty($data['id'])) {
        wp_send_json_success([
            'message' => __('A test event was added to your Google Calendar for today.', 'wedding-booking'),
            'link'    => isset($data['htmlLink']) ? esc_url_raw($data['htmlLink']) : '',
        ]);
    }

    $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
    wp_send_json_error(['message' => $api_msg]);
}
