<?php
/**
 * GitHub webhook auto-updater for WC PLZ-Filter.
 *
 * @package WC_PLZ_Filter
 */

defined( 'ABSPATH' ) || exit;

final class WC_PLZ_Updater {

    const OPT_REPO   = 'wc_plz_updater_repo';
    const OPT_SECRET = 'wc_plz_updater_secret';
    const OPT_LOG    = 'wc_plz_updater_log';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action( 'rest_api_init',  [ $this, 'register_rest_routes' ] );
        add_action( 'admin_init',     [ $this, 'register_settings' ] );
        add_action( 'admin_post_wc_plz_manual_update',    [ $this, 'handle_manual_update' ] );
        add_action( 'admin_post_wc_plz_regenerate_secret', [ $this, 'handle_regenerate_secret' ] );
        add_action( 'admin_notices',  [ $this, 'show_update_notice' ] );
    }

    // ── Option helpers ────────────────────────────────────────────────────

    private function get_repo(): string {
        return (string) get_option( self::OPT_REPO, '' );
    }

    private function get_secret(): string {
        $secret = (string) get_option( self::OPT_SECRET, '' );
        if ( $secret === '' ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( self::OPT_SECRET, $secret );
        }
        return $secret;
    }

    private function get_log(): array {
        return (array) get_option( self::OPT_LOG, [] );
    }

    private function save_log( array $entry ): void {
        $log = $this->get_log();
        array_unshift( $log, $entry );
        update_option( self::OPT_LOG, array_slice( $log, 0, 10 ) );
    }

    // ── REST endpoint ─────────────────────────────────────────────────────

    public function register_rest_routes(): void {
        register_rest_route( 'wc-plz/v1', '/webhook', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'rest_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function rest_webhook( WP_REST_Request $request ): WP_REST_Response {
        if ( $this->get_repo() === '' || $this->get_secret() === '' ) {
            return new WP_REST_Response( [ 'error' => 'not_configured' ], 501 );
        }

        $sig_header = (string) $request->get_header( 'x_hub_signature_256' );
        $raw_body   = $request->get_body();
        $expected   = 'sha256=' . hash_hmac( 'sha256', $raw_body, $this->get_secret() );

        if ( ! hash_equals( $expected, $sig_header ) ) {
            return new WP_REST_Response( [ 'error' => 'invalid_signature' ], 403 );
        }

        $payload = $request->get_json_params();

        if ( ( $payload['ref'] ?? '' ) !== 'refs/heads/main' ) {
            return new WP_REST_Response( [ 'skipped' => true ], 200 );
        }

        $result = $this->run_upgrade();

        return new WP_REST_Response(
            [ 'updated' => $result['success'], 'message' => $result['message'] ],
            $result['success'] ? 200 : 500
        );
    }

    // ── Upgrade logic ─────────────────────────────────────────────────────

    private function run_upgrade(): array {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $old_version = WC_PLZ_Filter::VERSION;
        $zip_url     = 'https://github.com/' . $this->get_repo() . '/archive/refs/heads/main.zip';

        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $zip_url, [ 'overwrite_package' => true ] );

        remove_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10 );

        $plugin_file = WP_PLUGIN_DIR . '/wc-plz-filter/wc-plz-filter.php';
        $data        = get_plugin_data( $plugin_file, false, false );
        $new_version = $data['Version'] ?? 'unknown';

        $success = ( $result === true );
        $message = $success
            ? sprintf( 'Updated from %s to %s.', $old_version, $new_version )
            : ( is_wp_error( $result ) ? $result->get_error_message() : 'Unknown upgrade error.' );

        $this->save_log( [
            'time'        => current_time( 'mysql' ),
            'old_version' => $old_version,
            'new_version' => $success ? $new_version : $old_version,
            'status'      => $success ? 'success' : 'error',
            'message'     => $message,
        ] );

        return [ 'success' => $success, 'message' => $message ];
    }

    public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
        if ( ! str_ends_with( trailingslashit( $source ), 'wc-plz-filter-main/' ) ) {
            return $source;
        }

        global $wp_filesystem;
        $new_source = trailingslashit( $remote_source ) . 'wc-plz-filter/';

        if ( $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }

        return $source;
    }

    // ── Admin handlers ────────────────────────────────────────────────────

    public function handle_manual_update(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }

        check_admin_referer( 'wc_plz_manual_update' );

        if ( $this->get_repo() === '' ) {
            wp_safe_redirect( add_query_arg( 'plz_update', 'error_not_configured',
                admin_url( 'admin.php?page=wc-plz-filter' ) ) );
            exit;
        }

        $result = $this->run_upgrade();
        $status = $result['success'] ? 'success' : 'error';

        wp_safe_redirect( add_query_arg( 'plz_update', $status,
            admin_url( 'admin.php?page=wc-plz-filter' ) ) );
        exit;
    }

    public function handle_regenerate_secret(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }

        check_admin_referer( 'wc_plz_regenerate_secret' );
        update_option( self::OPT_SECRET, bin2hex( random_bytes( 32 ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=wc-plz-filter' ) );
        exit;
    }

    public function show_update_notice(): void {
        $status = sanitize_key( $_GET['plz_update'] ?? '' );
        if ( $status === '' ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'woocommerce_page_wc-plz-filter' ) {
            return;
        }

        if ( $status === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Plugin erfolgreich aktualisiert.</p></div>';
        } elseif ( $status === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Update fehlgeschlagen. Siehe Update-Log für Details.</p></div>';
        } elseif ( $status === 'error_not_configured' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Update fehlgeschlagen: GitHub-Repo nicht konfiguriert.</p></div>';
        }
    }

    // ── Settings registration ─────────────────────────────────────────────

    public function register_settings(): void {
        register_setting( 'wc_plz_updater_group', self::OPT_REPO, [
            'type'              => 'string',
            'sanitize_callback' => function ( $value ) {
                $v = sanitize_text_field( $value );
                if ( $v !== '' && ! preg_match( '/^[a-zA-Z0-9_.\-]+\/[a-zA-Z0-9_.\-]+$/', $v ) ) {
                    add_settings_error( self::OPT_REPO, 'invalid_repo', 'Ungültiges Format. Bitte "owner/repo" eingeben.' );
                    return get_option( self::OPT_REPO, '' );
                }
                return $v;
            },
        ] );
    }

    // ── Admin section ─────────────────────────────────────────────────────

    public function render_admin_section(): void {
        $repo   = $this->get_repo();
        $secret = $this->get_secret();
        $log    = $this->get_log();
        $last   = $log[0] ?? null;
        ?>
        <hr />
        <h2>Auto-Update</h2>

        <table class="form-table" style="max-width:680px;">
            <tr>
                <th scope="row">Aktuelle Version</th>
                <td><code><?php echo esc_html( WC_PLZ_Filter::VERSION ); ?></code></td>
            </tr>
            <?php if ( $last ) : ?>
            <tr>
                <th scope="row">Letztes Update</th>
                <td>
                    <span class="dashicons <?php echo $last['status'] === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"
                          style="color:<?php echo $last['status'] === 'success' ? '#46b450' : '#dba617'; ?>;vertical-align:middle;"></span>
                    <?php echo esc_html( $last['time'] ); ?> —
                    <code><?php echo esc_html( $last['old_version'] ); ?></code> &rarr;
                    <code><?php echo esc_html( $last['new_version'] ); ?></code>
                    <?php if ( $last['status'] !== 'success' ) : ?>
                        <br><em style="color:#c00;"><?php echo esc_html( $last['message'] ); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields( 'wc_plz_updater_group' ); ?>
            <table class="form-table" style="max-width:680px;">
                <tr>
                    <th scope="row">GitHub Repo</th>
                    <td>
                        <input type="text"
                               name="<?php echo esc_attr( self::OPT_REPO ); ?>"
                               value="<?php echo esc_attr( $repo ); ?>"
                               placeholder="owner/repo"
                               class="regular-text" />
                        <p class="description">Format: <code>owner/repo</code> z. B. <code>kevinfischer/wc-plz-filter</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Webhook Secret</th>
                    <td>
                        <code style="background:#f0f0f1;padding:4px 8px;border-radius:3px;user-select:all;"><?php echo esc_html( $secret ); ?></code>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
                            <?php wp_nonce_field( 'wc_plz_regenerate_secret' ); ?>
                            <input type="hidden" name="action" value="wc_plz_regenerate_secret" />
                            <button type="submit" class="button button-small" onclick="return confirm('Secret wirklich neu generieren? Den neuen Wert musst du dann auch bei GitHub eintragen.');">Regenerieren</button>
                        </form>
                        <p class="description">Dieses Secret bei GitHub unter dem Webhook-Secret-Feld eintragen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Webhook-URL</th>
                    <td>
                        <code id="wc-plz-webhook-url" style="background:#f0f0f1;padding:4px 8px;border-radius:3px;"><?php echo esc_html( rest_url( 'wc-plz/v1/webhook' ) ); ?></code>
                        <button type="button" class="button button-small" style="margin-left:8px;"
                                onclick="navigator.clipboard.writeText(document.getElementById('wc-plz-webhook-url').textContent).then(()=>this.textContent='Kopiert!').catch(()=>{}); return false;">Kopieren</button>
                        <p class="description">GitHub: Settings &rarr; Webhooks &rarr; Add webhook &rarr; Content type: <code>application/json</code> &rarr; Events: Just the push event</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Repo speichern', 'secondary' ); ?>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
            <?php wp_nonce_field( 'wc_plz_manual_update' ); ?>
            <input type="hidden" name="action" value="wc_plz_manual_update" />
            <?php submit_button(
                $repo === '' ? 'Jetzt aktualisieren (Repo nicht konfiguriert)' : 'Jetzt aktualisieren',
                $repo === '' ? 'secondary' : 'primary',
                'submit',
                false,
                $repo === '' ? [ 'disabled' => 'disabled' ] : []
            ); ?>
        </form>

        <?php if ( ! empty( $log ) ) : ?>
            <h3 style="margin-top:20px;">Update-Log</h3>
            <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
                <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>Von</th>
                        <th>Nach</th>
                        <th>Status</th>
                        <th>Meldung</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $log as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry['time'] ); ?></td>
                        <td><code><?php echo esc_html( $entry['old_version'] ); ?></code></td>
                        <td><code><?php echo esc_html( $entry['new_version'] ); ?></code></td>
                        <td><?php echo $entry['status'] === 'success'
                            ? '<span style="color:#46b450;">OK</span>'
                            : '<span style="color:#c00;">Fehler</span>'; ?></td>
                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
