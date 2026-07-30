<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Produktübersicht" - a native, toggleable replacement for the two
 * "DT konsolidierte Produktliste" n8n workflows. Auto-provisions a real WP
 * page at an admin-configured path (so it renders inside the shop's own
 * theme like any other page), gates it behind a shared password (staff
 * without a WP account use this; users with WC_PLZ_Filter::MANAGE_CAP skip
 * it), and serves the aggregated data - built by Woohoo_PO_Aggregator - via
 * a REST endpoint fetched client-side, never baked into the page's stored
 * content, so a page-cache plugin (WP Rocket is active site-wide, see
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
        // reflects the latest enabled/path settings.
        foreach ( [ self::OPTION_ENABLED, self::OPTION_SETTINGS ] as $option ) {
            add_action( "add_option_{$option}", [ $this, 'sync_page' ] );
            add_action( "update_option_{$option}", [ $this, 'sync_page' ] );
        }

        add_action( 'template_redirect', [ $this, 'guard_page' ], 0 );
        add_filter( 'the_content', [ $this, 'render_page_content' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_filter( 'wp_robots', [ $this, 'maybe_noindex' ] );
    }

    /* ── Settings ────────────────────────────────── */

    private static function defaults(): array {
        return [
            'path'          => self::DEFAULT_PATH,
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
     * Registered on the same shared 'wc_plz_widgets_group' the Zusatz-Features
     * tab already uses for Merkliste/Cart-Indicator (one form, one Speichern
     * button) - see includes/admin/class-woohoo-module-widgets.php. That group
     * has no option_page_capability_* filter yet, which means options.php
     * would otherwise require manage_options (administrators only) to save
     * it, even though the tab itself is only gated by the plugin's own
     * MANAGE_CAP.
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
        add_filter( 'option_page_capability_wc_plz_widgets_group', function () {
            return current_user_can( 'manage_options' ) ? 'manage_options' : WC_PLZ_Filter::MANAGE_CAP;
        } );

        register_setting( 'wc_plz_widgets_group', self::OPTION_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => fn( $value ) => ! empty( $value ) ? 1 : 0,
            'default'           => 0,
        ] );

        register_setting( 'wc_plz_widgets_group', self::OPTION_SETTINGS, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => self::defaults(),
        ] );
    }

    /** error_log(), gated to WP_DEBUG so this stays silent on production unless someone opted in. */
    private static function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Woohoo Product Overview] ' . $message );
        }
    }

    public function sanitize_settings( $input ): array {
        $this->settings_cache = null;
        $raw_input_keys = is_array( $input ) ? implode( ',', array_keys( $input ) ) : gettype( $input );
        $input   = is_array( $input ) ? $input : [];
        $current = wp_parse_args( get_option( self::OPTION_SETTINGS, [] ), self::defaults() );

        $path = sanitize_title( (string) ( $input['path'] ?? '' ) );
        if ( $path === '' ) {
            $path = self::DEFAULT_PATH;
        }

        // Blank submission keeps the existing password - the field name is
        // deliberately "password" (never "password_hash") so a client can
        // only ever submit a new plaintext password, never inject a hash.
        $password_hash = (string) $current['password_hash'];
        $raw_password  = (string) ( $input['password'] ?? '' );
        if ( $raw_password !== '' ) {
            $password_hash = wp_hash_password( $raw_password );
        }

        $result = [
            'path'          => $path,
            'password_hash' => $password_hash,
            'session_days'  => max( 1, min( 90, (int) ( $input['session_days'] ?? 7 ) ) ),
        ];

        self::debug_log( sprintf(
            'sanitize_settings: received keys=[%s], password submitted=%s, resulting path=%s, has_password=%s, session_days=%d',
            $raw_input_keys,
            $raw_password !== '' ? 'yes' : 'no',
            $result['path'],
            $result['password_hash'] !== '' ? 'yes' : 'no',
            $result['session_days']
        ) );

        return $result;
    }

    /**
     * Reacts to settings changes: creates/renames/(un)publishes the
     * provisioned page. Non-destructive - disabling never deletes the page,
     * it's just set to draft (draft pages 404 on the front-end).
     */
    public function sync_page(): void {
        $this->settings_cache = null;

        $enabled  = $this->is_enabled();
        $settings = $this->get_settings();
        $page_id  = (int) get_option( self::OPTION_PAGE_ID, 0 );
        $page     = $page_id ? get_post( $page_id ) : null;

        if ( ! $enabled ) {
            if ( $page && $page->post_type === 'page' && $page->post_status !== 'trash' && $page->post_status !== 'draft' ) {
                wp_update_post( [ 'ID' => $page_id, 'post_status' => 'draft' ] );
                self::debug_log( "sync_page: disabled, set page {$page_id} to draft" );
            } else {
                self::debug_log( 'sync_page: disabled, nothing to do (page_id=' . $page_id . ')' );
            }
            return;
        }

        if ( ! $page || $page->post_type !== 'page' || $page->post_status === 'trash' ) {
            $new_id = wp_insert_post( [
                'post_title'     => 'Produktübersicht',
                'post_name'      => $settings['path'],
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_content'   => '',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true );

            if ( is_wp_error( $new_id ) ) {
                self::debug_log( 'sync_page: wp_insert_post FAILED: ' . $new_id->get_error_message() );
            } else {
                update_option( self::OPTION_PAGE_ID, $new_id, false );
                self::debug_log( "sync_page: created page {$new_id} at path '{$settings['path']}'" );
            }
            return;
        }

        $update = [ 'ID' => $page_id ];
        if ( $page->post_status !== 'publish' ) {
            $update['post_status'] = 'publish';
        }
        if ( $page->post_name !== $settings['path'] ) {
            $update['post_name'] = $settings['path'];
        }
        if ( count( $update ) > 1 ) {
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) {
                self::debug_log( "sync_page: wp_update_post FAILED for page {$page_id}: " . $result->get_error_message() );
            } else {
                self::debug_log( "sync_page: updated page {$page_id} (" . wp_json_encode( $update ) . ')' );
            }
        } else {
            self::debug_log( "sync_page: page {$page_id} already in sync (status={$page->post_status}, slug={$page->post_name})" );
        }
    }

    /**
     * Snapshot of the persisted state, for the admin status panel
     * (Woohoo_Module_Widgets) - lets an admin verify what actually got
     * saved without needing DB/log access.
     */
    public function get_debug_status(): array {
        $settings = $this->get_settings();
        $page_id  = $this->get_page_id();
        $page     = $page_id ? get_post( $page_id ) : null;

        return [
            'enabled'       => $this->is_enabled(),
            'path'          => $settings['path'],
            'url'           => home_url( '/' . $settings['path'] . '/' ),
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

    public function guard_page(): void {
        $page_id = $this->get_page_id();
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }

        nocache_headers();

        if ( ! $this->is_enabled() ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        if ( current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return; // staff already authenticated via wp-admin
        }

        if ( $this->has_valid_auth_cookie() ) {
            return;
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

    /* ── Page content shell (results are fetched client-side, never
     * persisted into post_content - see the class docblock) ────── */

    public function render_page_content( string $content ): string {
        $page_id = $this->get_page_id();
        if ( ! $page_id || ! is_page( $page_id ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        ob_start();
        ?>
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
        <?php
        return (string) ob_get_clean();
    }

    /* ── Assets ──────────────────────────────────── */

    public function enqueue(): void {
        $page_id = $this->get_page_id();
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }

        $url = WC_PLZ_FILTER_URL;

        wp_enqueue_style( 'woohoo-product-overview', $url . 'assets/css/product-overview.css', [], WC_PLZ_Filter::VERSION );
        wp_enqueue_script( 'woohoo-product-overview', $url . 'assets/js/product-overview.js', [], WC_PLZ_Filter::VERSION, [
            'in_footer' => true,
            'strategy'  => 'defer',
        ] );

        wp_localize_script( 'woohoo-product-overview', 'woohooPO', [
            'restUrl' => rest_url( self::REST_NAMESPACE . '/product-overview' ),
            'nonce'   => wp_create_nonce( self::NONCE_QUERY ),
        ] );
    }

    public function maybe_noindex( array $robots ): array {
        $page_id = $this->get_page_id();
        if ( $page_id && is_page( $page_id ) ) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
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
            // Surfaced as a real REST error message (and, with WP_DEBUG on,
            // logged) instead of an opaque 500 - so a failure here is
            // actually diagnosable from the browser network tab alone.
            self::debug_log( 'rest_handle: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return new \WP_Error( 'woohoo_po_query_failed', 'Fehler beim Abrufen der Übersicht: ' . $e->getMessage(), [ 'status' => 500 ] );
        }

        $response = new \WP_REST_Response( $result );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }
}
