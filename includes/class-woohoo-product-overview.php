<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Produktübersicht" - a native, toggleable replacement for the two
 * "DT konsolidierte Produktliste" n8n workflows. Auto-provisions a real WP
 * page at a fixed path (DEFAULT_PATH - not admin-configurable, see
 * sync_page()), gates it behind a shared password (staff without a WP
 * account use this; users with WC_PLZ_Filter::MANAGE_CAP skip it), and
 * serves the aggregated data - built by Woohoo_PO_Aggregator - via a REST
 * endpoint fetched client-side, never baked into the page's stored content,
 * so a page-cache plugin (WP Rocket is active site-wide, see
 * WC_PLZ_Filter::maybe_bust_rocket_cache()) can never serve one visitor's
 * order data to the next.
 */
final class Woohoo_Product_Overview {

    use WC_PLZ_Singleton;

    const OPTION_ENABLED  = 'woohoo_product_overview_enabled';
    const OPTION_SETTINGS = 'woohoo_product_overview_settings';
    const OPTION_PAGE_ID  = 'woohoo_product_overview_page_id';

    const DEFAULT_PATH = 'woohoo-product-overview';

    const COOKIE        = 'woohoo_po_auth';
    const NONCE_UNLOCK  = 'woohoo_po_unlock';

    /**
     * MUST be 'wp_rest', not a custom action: WordPress's core REST
     * cookie-auth layer (rest_cookie_collect_status()) reads the X-WP-Nonce
     * header and verifies it against the 'wp_rest' action specifically,
     * *before* our own permission_callback ever runs. Any other action name
     * here makes WordPress reject the whole request for logged-in users
     * with "Cookie check failed" (rest_cookie_invalid_nonce), regardless of
     * what our own nonce check would have decided.
     */
    const NONCE_QUERY   = 'wp_rest';
    const REST_NAMESPACE = 'woohoo/v1';

    const FAIL_TRANSIENT_PREFIX = 'woohoo_po_fails_';
    const MAX_FAILED_ATTEMPTS   = 8;
    const LOCKOUT_SECONDS       = 15 * MINUTE_IN_SECONDS;

    private ?array $settings_cache = null;

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Fires on both the very first save (add_option_*) and every
        // subsequent one (update_option_*) so the provisioned page always
        // reflects the latest enabled state.
        foreach ( [ self::OPTION_ENABLED, self::OPTION_SETTINGS ] as $option ) {
            add_action( "add_option_{$option}", [ $this, 'sync_page' ] );
            add_action( "update_option_{$option}", [ $this, 'sync_page' ] );
        }

        add_action( 'template_redirect', [ $this, 'guard_page' ], 0 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /* ── Settings ────────────────────────────────── */

    private static function defaults(): array {
        return [
            'password_hash' => '',
            'session_days'  => 7,
        ];
    }

    private function get_settings(): array {
        if ( $this->settings_cache !== null ) {
            return $this->settings_cache;
        }
        $this->settings_cache = wp_parse_args( get_option( self::OPTION_SETTINGS, [] ), self::defaults() );
        return $this->settings_cache;
    }

    public function is_enabled(): bool {
        return (int) get_option( self::OPTION_ENABLED, 0 ) === 1;
    }

    private function get_password_hash(): string {
        return (string) $this->get_settings()['password_hash'];
    }

    private function has_password_set(): bool {
        return $this->get_password_hash() !== '';
    }

    /**
     * Its own settings group + form on the Zusatz-Features tab (same
     * pattern as WC_PLZ_Reminder::register_settings()), not sharing
     * wc_plz_widgets_group with Merkliste/Cart-Indicator - keeps its
     * capability filter and array-option sanitize callback isolated from
     * the simpler boolean toggles living there.
     */
    const SETTINGS_GROUP = 'woohoo_product_overview_group';

    /**
     * Guards against register_setting() (and therefore add_filter() on
     * sanitize_option_{option}) running more than once in a request. If
     * register_settings() ever fired twice on 'admin_init', WordPress's
     * apply_filters() would chain BOTH registrations of the same
     * sanitize_callback on the same hook - the second invocation would
     * receive the first invocation's *return value* as its input, breaking
     * the password field specifically (it would see a 'password_hash' key -
     * its own prior output - instead of the raw 'password' field, and
     * conclude no password was submitted).
     *
     * Not relied on as the only guard, though: if this ever still fires
     * twice despite it (observed in practice - cause unconfirmed, possibly
     * a persistent/worker-mode PHP runtime not resetting statics between
     * requests), sanitize_settings() also recognizes its own re-entrant
     * input shape as a fallback - see the guard there.
     */
    private static bool $settings_registered = false;

    /**
     * The shop_manager role has MANAGE_CAP but not manage_options by
     * default, so this group needs its own option_page_capability_* filter
     * or options.php would require manage_options (administrators only) to
     * save it - even though the tab itself is already gated by MANAGE_CAP.
     *
     * Deliberately OR'd rather than hard-set to MANAGE_CAP: forcing it to
     * MANAGE_CAP unconditionally would silently break saving for *any*
     * account that (for whatever reason - e.g. the activation hook that
     * grants MANAGE_CAP never re-ran) doesn't actually hold that capability,
     * even a full administrator. Checking manage_options first preserves the
     * pre-existing behavior for admins and only relaxes it for MANAGE_CAP
     * holders that lack manage_options (shop_manager), per instruction.
     */
    public function register_settings(): void {
        if ( self::$settings_registered ) {
            return;
        }
        self::$settings_registered = true;

        add_filter( 'option_page_capability_' . self::SETTINGS_GROUP, function () {
            return current_user_can( 'manage_options' ) ? 'manage_options' : WC_PLZ_Filter::MANAGE_CAP;
        } );

        register_setting( self::SETTINGS_GROUP, self::OPTION_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => fn( $value ) => ! empty( $value ) ? 1 : 0,
            'default'           => 0,
        ] );

        register_setting( self::SETTINGS_GROUP, self::OPTION_SETTINGS, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => self::defaults(),
        ] );
    }

    public function sanitize_settings( $input ): array {
        $this->settings_cache = null;

        // Re-entrancy fallback (see the docblock on register_settings()'s
        // $settings_registered guard): if this still gets chained a second
        // time, $input at that point is THIS function's own prior output -
        // recognizable by having 'password_hash' but no raw 'password' key.
        // Pass it through as already-sanitized rather than mistaking the
        // hash for "no password submitted" and silently discarding a
        // genuinely-typed new password. Deliberately keyed off presence of
        // 'password_hash' + absence of 'password' (not just "no password
        // key") so a client can never submit 'password_hash' directly on a
        // normal request and have it stored verbatim, bypassing
        // wp_hash_password() entirely.
        if ( is_array( $input ) && array_key_exists( 'password_hash', $input ) && ! array_key_exists( 'password', $input ) ) {
            return [
                'password_hash' => (string) ( $input['password_hash'] ?? '' ),
                'session_days'  => max( 1, min( 90, (int) ( $input['session_days'] ?? 7 ) ) ),
            ];
        }

        $input   = is_array( $input ) ? $input : [];
        $current = wp_parse_args( get_option( self::OPTION_SETTINGS, [] ), self::defaults() );

        // Blank submission keeps the existing password - the field name is
        // deliberately "password" (never "password_hash") so a client can
        // only ever submit a new plaintext password, never inject a hash.
        $password_hash = (string) $current['password_hash'];
        $raw_password  = (string) ( $input['password'] ?? '' );
        if ( $raw_password !== '' ) {
            $password_hash = wp_hash_password( $raw_password );
        }

        return [
            'password_hash' => $password_hash,
            'session_days'  => max( 1, min( 90, (int) ( $input['session_days'] ?? 7 ) ) ),
        ];
    }

    /**
     * Reacts to settings changes: creates/renames/(un)publishes the
     * provisioned page. Non-destructive - disabling never deletes the page,
     * it's just set to draft (draft pages 404 on the front-end).
     */
    public function sync_page(): void {
        $this->settings_cache = null;

        $enabled = $this->is_enabled();
        $page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
        $page    = $page_id ? get_post( $page_id ) : null;

        if ( ! $enabled ) {
            if ( $page && $page->post_type === 'page' && $page->post_status !== 'trash' && $page->post_status !== 'draft' ) {
                wp_update_post( [ 'ID' => $page_id, 'post_status' => 'draft' ] );
            }
            return;
        }

        if ( ! $page || $page->post_type !== 'page' || $page->post_status === 'trash' ) {
            $new_id = wp_insert_post( [
                'post_title'     => 'Produktübersicht',
                'post_name'      => self::DEFAULT_PATH,
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_content'   => '',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true );

            if ( ! is_wp_error( $new_id ) ) {
                update_option( self::OPTION_PAGE_ID, $new_id, false );
            }
            return;
        }

        $update = [ 'ID' => $page_id ];
        if ( $page->post_status !== 'publish' ) {
            $update['post_status'] = 'publish';
        }
        if ( $page->post_name !== self::DEFAULT_PATH ) {
            $update['post_name'] = self::DEFAULT_PATH;
        }
        if ( count( $update ) > 1 ) {
            wp_update_post( $update );
        }
    }

    /**
     * Snapshot of the persisted state, for the admin status panel
     * (Woohoo_Module_Widgets) - lets an admin verify what's actually
     * configured without needing DB access.
     */
    public function get_status_summary(): array {
        $settings = $this->get_settings();
        $page_id  = $this->get_page_id();
        $page     = $page_id ? get_post( $page_id ) : null;

        // get_permalink() rather than a hard-coded path: wp_insert_post()
        // runs post_name through wp_unique_post_slug(), so if the default
        // slug was already taken by another post, the provisioned page's
        // real URL carries a "-2" suffix that the hard-coded guess would miss.
        $url = $page ? get_permalink( $page ) : home_url( '/' . self::DEFAULT_PATH . '/' );

        return [
            'enabled'       => $this->is_enabled(),
            'url'           => $url,
            'has_password'  => $settings['password_hash'] !== '',
            'session_days'  => (int) $settings['session_days'],
            'page_id'       => $page_id,
            'page_status'   => $page ? $page->post_status : null,
            'page_slug'     => $page ? $page->post_name : null,
        ];
    }

    private function get_page_id(): int {
        return (int) get_option( self::OPTION_PAGE_ID, 0 );
    }

    /* ── Auth cookie (stateless, self-verifying) ────────────────────
     * Token = "<expiry>:<hmac>", hmac over the current password hash + the
     * expiry, keyed by wp_salt(). No server-side session storage, and
     * changing the password invalidates every existing unlocked browser
     * immediately since the hash is part of the signed payload. */

    private function make_token( int $expires ): string {
        $sig = hash_hmac( 'sha256', $this->get_password_hash() . '|' . $expires, wp_salt( 'auth' ) );
        return $expires . ':' . $sig;
    }

    private function is_token_valid( string $token ): bool {
        if ( ! $this->has_password_set() ) {
            return false;
        }
        $parts = explode( ':', $token, 2 );
        if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
            return false;
        }
        [ $expires_str, $sig ] = $parts;
        $expires = (int) $expires_str;
        if ( $expires < time() ) {
            return false;
        }
        $expected = hash_hmac( 'sha256', $this->get_password_hash() . '|' . $expires, wp_salt( 'auth' ) );
        return hash_equals( $expected, $sig );
    }

    private function has_valid_auth_cookie(): bool {
        $raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
        return $raw !== '' && $this->is_token_valid( $raw );
    }

    private function set_auth_cookie(): void {
        $days    = (int) $this->get_settings()['session_days'];
        $expires = time() + max( 1, $days ) * DAY_IN_SECONDS;
        setcookie( self::COOKIE, $this->make_token( $expires ), [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    /* ── Brute-force throttle (same transient-as-lock idiom as
     * WC_PLZ_Reminder::LOCK_KEY) ────────────────────────────────── */

    private function client_ip(): string {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    private function fail_key(): string {
        return self::FAIL_TRANSIENT_PREFIX . md5( $this->client_ip() );
    }

    private function is_locked_out(): bool {
        return (int) get_transient( $this->fail_key() ) >= self::MAX_FAILED_ATTEMPTS;
    }

    private function register_failure(): void {
        set_transient( $this->fail_key(), (int) get_transient( $this->fail_key() ) + 1, self::LOCKOUT_SECONDS );
    }

    private function clear_failures(): void {
        delete_transient( $this->fail_key() );
    }

    /* ── Front-end gate ──────────────────────────── */

    /**
     * Renders entirely standalone (own doctype/head/body, exit - no
     * get_header()/get_footer(), no theme template, no WooCommerce nav) for
     * BOTH the locked and unlocked states, same as the password form always
     * did. This is deliberate: the page must never depend on (or be styled
     * by) the active theme - it's an internal staff tool, not a shop page.
     */
    public function guard_page(): void {
        $page_id = $this->get_page_id();
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }

        nocache_headers();

        // nocache_headers() only sets HTTP cache-control headers, which
        // WP Rocket's full-page disk cache ignores entirely - it caches
        // based on its own request inspection, not response headers, and
        // by default doesn't recognize this plugin's custom auth cookie as
        // a reason to skip caching. Without this, whichever response
        // (locked-out form or the authenticated tool) gets served first to
        // an anonymous request would be cached and served to every visitor
        // after that, regardless of their own auth state.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        if ( ! $this->is_enabled() ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        $authorized = current_user_can( WC_PLZ_Filter::MANAGE_CAP ) // staff already authenticated via wp-admin
            || $this->has_valid_auth_cookie();

        if ( $authorized ) {
            $this->render_overview_page();
            exit;
        }

        $error = '';

        if ( ! empty( $_POST['woohoo_po_password_submit'] ) ) {
            $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, self::NONCE_UNLOCK ) ) {
                $error = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            } elseif ( $this->is_locked_out() ) {
                $error = 'Zu viele Fehlversuche. Bitte in ein paar Minuten erneut versuchen.';
            } elseif ( ! $this->has_password_set() ) {
                $error = 'Für diese Seite ist noch kein Passwort hinterlegt.';
            } else {
                $submitted = isset( $_POST['woohoo_po_password'] ) ? (string) wp_unslash( $_POST['woohoo_po_password'] ) : '';
                if ( wp_check_password( $submitted, $this->get_password_hash() ) ) {
                    $this->clear_failures();
                    $this->set_auth_cookie();
                    wp_safe_redirect( esc_url_raw( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : home_url( '/' ) ) );
                    exit;
                }
                $this->register_failure();
                $error = 'Falsches Passwort.';
            }
        }

        $this->render_password_form( $error );
        exit;
    }

    private function render_password_form( string $error ): void {
        status_header( 200 );
        nocache_headers();
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Zugang erforderlich</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f5f5;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;}
.woohoo-po-lock{background:#fff;max-width:340px;width:100%;padding:28px 26px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.12);box-sizing:border-box;}
.woohoo-po-lock h1{font-size:16px;margin:0 0 16px;color:#222;}
.woohoo-po-lock input[type=password]{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;margin-bottom:14px;}
.woohoo-po-lock button{width:100%;padding:10px;background:#8B0000;color:#fff;border:0;border-radius:4px;font-size:14px;font-weight:700;cursor:pointer;}
.woohoo-po-lock .woohoo-po-lock__error{color:#c0392b;font-size:13px;margin:0 0 12px;}
</style>
</head>
<body>
<div class="woohoo-po-lock">
<h1>Zugang erforderlich</h1>
<?php if ( $error !== '' ) : ?><p class="woohoo-po-lock__error"><?php echo esc_html( $error ); ?></p><?php endif; ?>
<form method="post">
<?php wp_nonce_field( self::NONCE_UNLOCK ); ?>
<input type="hidden" name="woohoo_po_password_submit" value="1" />
<input type="password" name="woohoo_po_password" placeholder="Passwort" autofocus required />
<button type="submit">Öffnen</button>
</form>
</div>
</body>
</html>
        <?php
    }

    /**
     * The authenticated view: own doctype/head/body, no theme, no
     * WooCommerce nav/header/footer. CSS/JS are linked directly (not via
     * wp_enqueue_*) since this never goes through wp_head()/wp_footer().
     * Results are fetched client-side via REST, never persisted into any
     * post_content - see the class docblock for why (page-cache safety).
     */
    private function render_overview_page(): void {
        status_header( 200 );

        $url      = WC_PLZ_FILTER_URL;
        $rest_url = rest_url( self::REST_NAMESPACE . '/product-overview' );
        $nonce    = wp_create_nonce( self::NONCE_QUERY );
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Produktübersicht</title>
<link rel="stylesheet" href="<?php echo esc_url( $url . 'assets/css/product-overview.css' ); ?>?ver=<?php echo esc_attr( WC_PLZ_Filter::VERSION ); ?>" />
</head>
<body>
<div id="woohoo-po" class="woohoo-po">
    <div class="woohoo-po-card">
        <h2 class="woohoo-po-card__header">Produktübersicht</h2>
        <div class="woohoo-po-card__body">
            <div class="woohoo-po-field" id="woohoo-po-date-field">
                <label for="woohoo-po-date">Lieferdatum</label>
                <input type="date" id="woohoo-po-date" />
            </div>
            <div class="woohoo-po-field">
                <label for="woohoo-po-plz">PLZ ausschließen</label>
                <input type="text" id="woohoo-po-plz" placeholder="z. B. 63679, 37170" />
                <p class="woohoo-po-hint">Mehrere PLZ kommagetrennt eingeben</p>
            </div>
            <div class="woohoo-po-field">
                <label>Versandart</label>
                <div class="woohoo-po-radios">
                    <label><input type="radio" name="woohoo-po-mode" value="local" checked /> Lokal</label>
                    <label><input type="radio" name="woohoo-po-mode" value="post" /> Postversand</label>
                </div>
            </div>
            <button type="button" id="woohoo-po-submit">Übersicht abrufen</button>
            <div id="woohoo-po-status" class="woohoo-po-status" aria-live="polite"></div>
        </div>
    </div>
    <div id="woohoo-po-results" class="woohoo-po-results"></div>
</div>
<script>
    var woohooPO = <?php echo wp_json_encode( [ 'restUrl' => $rest_url, 'nonce' => $nonce ] ); ?>;
</script>
<script src="<?php echo esc_url( $url . 'assets/js/product-overview.js' ); ?>?ver=<?php echo esc_attr( WC_PLZ_Filter::VERSION ); ?>" defer></script>
</body>
</html>
        <?php
    }

    /* ── REST endpoint ───────────────────────────── */

    public function register_rest_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/product-overview', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'rest_permission' ],
            'callback'            => [ $this, 'rest_handle' ],
        ] );
    }

    public function rest_permission( \WP_REST_Request $request ) {
        if ( current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return true;
        }
        if ( ! $this->is_enabled() ) {
            return new \WP_Error( 'woohoo_po_disabled', 'Nicht verfügbar.', [ 'status' => 404 ] );
        }
        if ( ! $this->has_valid_auth_cookie() ) {
            return new \WP_Error( 'woohoo_po_unauthorized', 'Nicht autorisiert.', [ 'status' => 401 ] );
        }
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_QUERY ) ) {
            return new \WP_Error( 'woohoo_po_bad_nonce', 'Ungültiges Token.', [ 'status' => 403 ] );
        }
        return true;
    }

    public function rest_handle( \WP_REST_Request $request ) {
        $mode = sanitize_key( (string) $request->get_param( 'mode' ) );
        if ( ! in_array( $mode, [ 'local', 'post' ], true ) ) {
            return new \WP_Error( 'woohoo_po_bad_mode', 'Ungültiger Modus.', [ 'status' => 400 ] );
        }

        $exclude_raw = (string) $request->get_param( 'exclude_postcodes' );
        $exclude     = array_values( array_filter( array_map(
            static fn( string $p ): string => preg_replace( '/\D/', '', trim( $p ) ),
            explode( ',', $exclude_raw )
        ) ) );

        try {
            if ( $mode === 'local' ) {
                $date = (string) $request->get_param( 'date' );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                    return new \WP_Error( 'woohoo_po_bad_date', 'Bitte ein gültiges Datum angeben.', [ 'status' => 400 ] );
                }
                $result = Woohoo_PO_Aggregator::get_local_summary( $date, $exclude );
            } else {
                $result = Woohoo_PO_Aggregator::get_post_summary( $exclude );
            }
        } catch ( \Throwable $e ) {
            // Logged server-side (may contain internal detail - query
            // fragments, file paths); the client only ever sees a generic
            // message, since this endpoint's audience includes staff without
            // a WP account, not just admins.
            error_log( 'Woohoo Produktübersicht query failed: ' . $e->getMessage() );
            return new \WP_Error( 'woohoo_po_query_failed', 'Fehler beim Abrufen der Übersicht. Bitte später erneut versuchen.', [ 'status' => 500 ] );
        }

        $response = new \WP_REST_Response( $result );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }
}
