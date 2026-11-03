<?php
/**
 * GitHub webhook auto-updater for WC PLZ-Filter.
 *
 * @package WC_PLZ_Filter
 */

defined( 'ABSPATH' ) || exit;

final class WC_PLZ_Updater {

    use WC_PLZ_Singleton;

    const OPT_REPO   = 'wc_plz_updater_repo';
    const OPT_BRANCH = 'wc_plz_updater_branch';
    const OPT_SECRET = 'wc_plz_updater_secret';
    const OPT_LOG    = 'wc_plz_updater_log';
    const OPT_CAP_VERSION = 'wc_plz_updater_cap_version';
    const OPT_LOCK       = 'wc_plz_updater_lock';
    const OPT_DELIVERIES = 'wc_plz_updater_deliveries';

    const MANAGE_UPDATE_CAP = 'manage_woohoo_updates';

    // Sperre gilt als verwaist (abgestürzter Lauf), wenn älter als 10 Minuten.
    const LOCK_TTL = 600;
    // Wie viele X-GitHub-Delivery-IDs gegen Doppel-Zustellung gemerkt werden.
    const MAX_DELIVERIES = 20;
    // Einmaliger WP-Cron-Nachlauf, wenn ein Webhook auf eine laufende Sperre trifft.
    const RETRY_HOOK  = 'wc_plz_updater_retry';
    const RETRY_DELAY = 60;

    private string $upgrade_branch = 'main';

    private function __construct() {
        $this->maybe_grant_update_cap_to_admins();

        add_action( 'rest_api_init',  [ $this, 'register_rest_routes' ] );
        add_action( 'admin_init',     [ $this, 'register_settings' ] );
        add_action( 'admin_post_wc_plz_manual_update',    [ $this, 'handle_manual_update' ] );
        add_action( 'admin_post_wc_plz_regenerate_secret', [ $this, 'handle_regenerate_secret' ] );
        add_action( 'admin_post_wc_plz_grant_update_cap', [ $this, 'handle_grant_update_cap' ] );
        add_action( 'admin_notices',  [ $this, 'show_update_notice' ] );
        add_action( self::RETRY_HOOK, [ $this, 'run_retry' ] );
    }

    /**
     * Auto-updates (webhook or manual "Jetzt aktualisieren") overwrite plugin
     * files in place and never re-fire register_activation_hook, so an
     * already-installed site would otherwise never pick up a newly
     * introduced capability. Mirrors WC_PLZ_Filter::maybe_bust_rocket_cache()'s
     * once-per-version pattern to close that gap.
     */
    private function maybe_grant_update_cap_to_admins(): void {
        if ( get_option( self::OPT_CAP_VERSION, '' ) === WC_PLZ_Filter::VERSION ) {
            return;
        }

        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( self::MANAGE_UPDATE_CAP );
        }

        update_option( self::OPT_CAP_VERSION, WC_PLZ_Filter::VERSION );
    }

    // ── Option helpers ────────────────────────────────────────────────────

    private function get_repo(): string {
        return (string) get_option( self::OPT_REPO, 'ke-ma-fi/wc-plz-filter' );
    }

    private function get_branch(): string {
        return (string) get_option( self::OPT_BRANCH, 'main' );
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

    // ── Upgrade lock ──────────────────────────────────────────────────────
    //
    // Direkt per SQL statt add_option(): add_option() prüft erst und schreibt
    // dann (INSERT … ON DUPLICATE KEY UPDATE), zwei parallele Requests
    // könnten so beide „gewinnen“. INSERT IGNORE ist atomar (gleiches Muster
    // wie WP_Upgrader::create_lock()) und umgeht den Options-Cache.

    /**
     * Returns the lock token (unix time) on success, 0 if another run holds it.
     */
    private function acquire_lock(): int {
        global $wpdb;

        $now = time();
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
            self::OPT_LOCK,
            (string) $now
        ) );
        if ( $inserted ) {
            return $now;
        }

        $since = $this->get_lock_time();
        if ( $since === 0 || $since > $now - self::LOCK_TTL ) {
            return 0;
        }

        // Verwaiste Sperre: nur genau diesen alten Wert löschen, damit zwei
        // gleichzeitige Übernahmen sich nicht gegenseitig die Sperre klauen.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::OPT_LOCK,
            (string) $since
        ) );
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
            self::OPT_LOCK,
            (string) $now
        ) );

        return $inserted ? $now : 0;
    }

    private function release_lock( int $token ): void {
        global $wpdb;

        // Nur die eigene Sperre freigeben (falls sie nach Ablauf übernommen wurde).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::OPT_LOCK,
            (string) $token
        ) );
    }

    private function get_lock_time(): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPT_LOCK
        ) );
    }

    // ── Webhook delivery IDs (Schutz vor Doppel-Zustellung) ───────────────

    private function get_deliveries(): array {
        return (array) get_option( self::OPT_DELIVERIES, [] );
    }

    private function remember_delivery( string $delivery ): void {
        if ( $delivery === '' ) {
            return;
        }
        $ids = $this->get_deliveries();
        array_unshift( $ids, $delivery );
        update_option( self::OPT_DELIVERIES, array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_DELIVERIES ), false );
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

        // Erst nach geprüfter Signatur: dieselbe Zustellung nicht zweimal ausführen.
        $delivery = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $request->get_header( 'x_github_delivery' ) ), 0, 64 );
        if ( $delivery !== '' && in_array( $delivery, $this->get_deliveries(), true ) ) {
            return new WP_REST_Response( [ 'skipped' => 'duplicate' ], 200 );
        }

        $payload = $request->get_json_params();

        if ( ( $payload['ref'] ?? '' ) !== 'refs/heads/' . $this->get_branch() ) {
            $this->remember_delivery( $delivery );
            return new WP_REST_Response( [ 'skipped' => true ], 200 );
        }

        $result = $this->run_upgrade();

        if ( ! empty( $result['locked'] ) ) {
            // Der laufende Update-Lauf hat das Branch-Zip evtl. schon vor diesem
            // Push geladen - ohne Nachlauf bliebe der Shop auf dem älteren Stand.
            $this->schedule_retry();
            return new WP_REST_Response( [ 'error' => 'locked', 'retry' => 'scheduled' ], 409 );
        }

        // Nur erfolgreiche Läufe merken, damit „Redeliver“ auf GitHub
        // (gleiche Delivery-ID) einen fehlgeschlagenen Lauf wiederholen kann.
        if ( $result['success'] ) {
            $this->remember_delivery( $delivery );
        }

        return new WP_REST_Response(
            [ 'updated' => $result['success'], 'message' => $result['message'] ],
            $result['success'] ? 200 : 500
        );
    }

    // ── Nachlauf nach 409 ─────────────────────────────────────────────────

    private function schedule_retry(): void {
        if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
            wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK );
        }
    }

    /**
     * WP-Cron: holt das Update nach, das wegen einer laufenden Sperre mit 409
     * abgewiesen wurde. Ist die Sperre noch aktiv, erneut einplanen - spätestens
     * nach LOCK_TTL gilt sie als verwaist und wird übernommen.
     */
    public function run_retry(): void {
        // Noch gesperrt → ohne Log-Eintrag neu einplanen (das Update-Log hält
        // nur 10 Einträge, „Gesperrt“-Zeilen würden echte Läufe verdrängen).
        $since = $this->get_lock_time();
        if ( $since !== 0 && $since > time() - self::LOCK_TTL ) {
            $this->schedule_retry();
            return;
        }

        $result = $this->run_upgrade();
        if ( ! empty( $result['locked'] ) ) {
            $this->schedule_retry();
        }
    }

    // ── Upgrade logic ─────────────────────────────────────────────────────

    private function run_upgrade(): array {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $old_version = WC_PLZ_Filter::VERSION;

        // Nie zwei Updates gleichzeitig (Webhook + „Jetzt aktualisieren“,
        // doppelte Zustellung): zwei Plugin_Upgrader im selben Ordner = kaputtes Plugin.
        $lock = $this->acquire_lock();
        if ( $lock === 0 ) {
            $message = sprintf(
                'Skipped: another update is already running (locked since %s).',
                wp_date( 'Y-m-d H:i:s', $this->get_lock_time() ?: time() )
            );
            $this->save_log( [
                'time'        => current_time( 'mysql' ),
                'old_version' => $old_version,
                'new_version' => $old_version,
                'status'      => 'locked',
                'message'     => $message,
            ] );
            return [ 'success' => false, 'locked' => true, 'message' => $message ];
        }

        try {
            // Abbruch durch Client (GitHub-Timeout nach 10 s, Browser geschlossen)
            // darf das Update nicht mittendrin stoppen.
            if ( function_exists( 'ignore_user_abort' ) ) {
                ignore_user_abort( true );
            }
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }

            WP_Filesystem();

            $this->upgrade_branch = $this->get_branch();
            $zip_url              = 'https://github.com/' . $this->get_repo() . '/archive/refs/heads/' . $this->upgrade_branch . '.zip';

            add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );

            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $zip_url, [ 'overwrite_package' => true ] );

            remove_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10 );

            $success = ( $result === true );

            $plugin_file = WC_PLZ_FILTER_DIR . 'wc-plz-filter.php';
            $data        = get_plugin_data( $plugin_file, false, false );
            $new_version = $data['Version'] ?? 'unknown';
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

            return [ 'success' => $success, 'locked' => false, 'message' => $message ];
        } finally {
            $this->release_lock( $lock );
        }
    }

    public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
        // This filter is only attached for the duration of our own install() call
        // (see run_upgrade()), so $source is always the single folder WordPress
        // already extracted our archive into — no need to match it by name.
        // Rename to the currently active plugin folder name (not necessarily
        // "wc-plz-filter" — a manual zip upload can install under a different
        // slug, e.g. "wc-plz-filter-dev"), so this update overwrites it in place.
        $new_source = trailingslashit( $remote_source ) . basename( WC_PLZ_FILTER_DIR ) . '/';

        if ( trailingslashit( $source ) === $new_source ) {
            return $source;
        }

        global $wp_filesystem;
        if ( $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }

        return $source;
    }

    // ── Admin handlers ────────────────────────────────────────────────────

    public function handle_manual_update(): void {
        if ( ! current_user_can( self::MANAGE_UPDATE_CAP ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }

        check_admin_referer( 'wc_plz_manual_update' );

        if ( $this->get_repo() === '' ) {
            wp_safe_redirect( add_query_arg( 'plz_update', 'error_not_configured',
                Woohoo_Admin_Page::tab_url( 'updater' ) ) );
            exit;
        }

        $result = $this->run_upgrade();
        $status = $result['success'] ? 'success' : ( ! empty( $result['locked'] ) ? 'error_locked' : 'error' );

        wp_safe_redirect( add_query_arg( 'plz_update', $status,
            Woohoo_Admin_Page::tab_url( 'updater' ) ) );
        exit;
    }

    public function handle_regenerate_secret(): void {
        if ( ! current_user_can( self::MANAGE_UPDATE_CAP ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }

        check_admin_referer( 'wc_plz_regenerate_secret' );
        update_option( self::OPT_SECRET, bin2hex( random_bytes( 32 ) ) );

        wp_safe_redirect( Woohoo_Admin_Page::tab_url( 'updater' ) );
        exit;
    }

    public function handle_grant_update_cap(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }

        check_admin_referer( 'wc_plz_grant_update_cap' );

        $user_id = (int) ( $_POST['wc_plz_grant_user'] ?? 0 );
        $user    = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            wp_safe_redirect( add_query_arg( 'plz_grant', 'error_no_user',
                Woohoo_Admin_Page::tab_url( 'updater' ) ) );
            exit;
        }

        $user->add_cap( self::MANAGE_UPDATE_CAP );

        wp_safe_redirect( add_query_arg( 'plz_grant', 'success',
            Woohoo_Admin_Page::tab_url( 'updater' ) ) );
        exit;
    }

    public function show_update_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'woocommerce_page_woohoo' ) {
            return;
        }

        $status = sanitize_key( $_GET['plz_update'] ?? '' );
        if ( $status === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Plugin erfolgreich aktualisiert.</p></div>';
        } elseif ( $status === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Update fehlgeschlagen. Siehe Update-Log für Details.</p></div>';
        } elseif ( $status === 'error_not_configured' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Update fehlgeschlagen: GitHub-Repo nicht konfiguriert.</p></div>';
        } elseif ( $status === 'error_locked' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Update übersprungen: Es läuft bereits ein Update. Bitte in ein paar Minuten erneut versuchen (eine hängende Sperre verfällt nach 10 Minuten).</p></div>';
        }

        $grant_status = sanitize_key( $_GET['plz_grant'] ?? '' );
        if ( $grant_status === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Update-Recht erfolgreich vergeben.</p></div>';
        } elseif ( $grant_status === 'error_no_user' ) {
            echo '<div class="notice notice-error is-dismissible"><p>Update-Recht konnte nicht vergeben werden: Benutzer nicht gefunden.</p></div>';
        }
    }

    // ── Settings registration ─────────────────────────────────────────────

    public function register_settings(): void {
        // Save capability for this group (strict MANAGE_UPDATE_CAP): see
        // WC_PLZ_Filter::settings_group_caps().
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

        register_setting( 'wc_plz_updater_group', self::OPT_BRANCH, [
            'type'              => 'string',
            'default'           => 'main',
            'sanitize_callback' => function ( $value ) {
                $v = sanitize_text_field( $value );
                if ( $v === '' || ! preg_match( '/^[a-zA-Z0-9_.\/\-]+$/', $v ) ) {
                    add_settings_error( self::OPT_BRANCH, 'invalid_branch', 'Ungültiger Branch-Name.' );
                    return get_option( self::OPT_BRANCH, 'main' );
                }
                return $v;
            },
        ] );
    }

    // ── Admin section ─────────────────────────────────────────────────────

    public function render_admin_section(): void {
        if ( ! current_user_can( self::MANAGE_UPDATE_CAP ) ) {
            return;
        }

        $repo   = $this->get_repo();
        $branch = $this->get_branch();
        $secret = $this->get_secret();
        $log    = $this->get_log();
        $last   = $log[0] ?? null;
        ?>
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

        <table class="form-table" style="max-width:680px;">
            <tr>
                <th scope="row">Webhook Secret</th>
                <td>
                    <code style="background:#f0f0f1;padding:4px 8px;border-radius:3px;user-select:all;"><?php echo esc_html( $secret ); ?></code>
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

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
            <?php wp_nonce_field( 'wc_plz_regenerate_secret' ); ?>
            <input type="hidden" name="action" value="wc_plz_regenerate_secret" />
            <button type="submit" class="button button-small" onclick="return confirm('Secret wirklich neu generieren? Den neuen Wert musst du dann auch bei GitHub eintragen.');">Secret regenerieren</button>
        </form>

        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <h3 style="margin-top:20px;">Update-Rechte vergeben</h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
            <?php wp_nonce_field( 'wc_plz_grant_update_cap' ); ?>
            <input type="hidden" name="action" value="wc_plz_grant_update_cap" />
            <?php wp_dropdown_users( [ 'name' => 'wc_plz_grant_user', 'role__not_in' => [ 'customer' ] ] ); ?>
            <?php submit_button(
                'Update-Recht vergeben',
                'secondary',
                'submit',
                false,
                [ 'onclick' => "return confirm('Achtung: Diese Person kann danach das GitHub-Repo/Branch ändern und Updates auslösen — praktisch Code-Ausführungsrechte auf dieser Seite. Wirklich vergeben?');" ]
            ); ?>
            <p class="description">Vergibt das Recht, Auto-Update-Einstellungen zu ändern und Updates auszulösen, an den ausgewählten Benutzer.</p>
        </form>
        <?php endif; ?>

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
                    <th scope="row">Branch</th>
                    <td>
                        <input type="text"
                               name="<?php echo esc_attr( self::OPT_BRANCH ); ?>"
                               value="<?php echo esc_attr( $branch ); ?>"
                               placeholder="main"
                               class="regular-text" />
                        <p class="description">Von welchem Branch aktualisiert werden soll, z. B. <code>main</code> oder <code>dev</code>. Auf Dev-Instanzen hier <code>dev</code> eintragen.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Einstellungen speichern', 'secondary' ); ?>
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
                        <td><?php if ( $entry['status'] === 'success' ) {
                            echo '<span style="color:#46b450;">OK</span>';
                        } elseif ( $entry['status'] === 'locked' ) {
                            echo '<span style="color:#dba617;">Gesperrt</span>';
                        } else {
                            echo '<span style="color:#c00;">Fehler</span>';
                        } ?></td>
                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
