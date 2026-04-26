<?php
/**
 * WC PLZ-Filter – PLZ-Statistik
 *
 * Vollständig von der Business-Logik getrennte Statistik-Klasse.
 * Verantwortlich für: DB-Tabelle, Event-Logging, REST-API, Admin-Abschnitt, Cleanup.
 *
 * DSGVO-Hinweis: Es werden ausschließlich PLZ (geografisches Gebiet), Modus und
 * Zeitstempel gespeichert. Keinerlei personenbezogene Daten (keine IP, keine
 * Session-ID, keine User-ID). Die Daten sind anonym und unterliegen nicht der DSGVO.
 *
 * @copyright Metzgerei Fischer. All rights reserved.
 */

defined( 'ABSPATH' ) || exit;

final class WC_PLZ_Stats {

    const DB_VERSION  = '1';
    const TABLE       = 'wc_plz_events';
    const CACHE_TTL   = 300; // 5 Minuten
    const OPT_CLEANUP = 'wc_plz_stats_cleanup';
    const CRON_HOOK   = 'wc_plz_stats_cleanup';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function activate(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plz     VARCHAR(10)     NOT NULL DEFAULT '',
            mode    VARCHAR(20)     NOT NULL DEFAULT '',
            created DATETIME        NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_created  (created),
            INDEX idx_mode_plz (mode, plz)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'wc_plz_db_version', self::DB_VERSION );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    private function __construct() {
        add_action( 'admin_init', [ $this, 'handle_stats_reset' ] );
        add_action( 'admin_init', [ $this, 'handle_cleanup_settings_save' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_cleanup' ] );
    }

    /* ── DB-Tabelle ──────────────────────────────── */

    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public function maybe_create_table(): void {
        if ( get_option( 'wc_plz_db_version' ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table   = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plz     VARCHAR(10)     NOT NULL DEFAULT '',
            mode    VARCHAR(20)     NOT NULL DEFAULT '',
            created DATETIME        NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_created  (created),
            INDEX idx_mode_plz (mode, plz)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'wc_plz_db_version', self::DB_VERSION );
    }

    /* ── Event loggen ────────────────────────────── */

    public function log_event( string $plz, string $mode ): void {
        if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;
        $wpdb->insert(
            $this->table_name(),
            [
                'plz'     => $plz,
                'mode'    => $mode,
                'created' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s' ]
        );
        $this->bump_cache();
    }

    /* ── Cache-Invalidierung via Epoch ───────────── */

    private function cache_epoch(): int {
        return (int) get_option( 'wc_plz_stats_epoch', 0 );
    }

    private function bump_cache(): void {
        update_option( 'wc_plz_stats_epoch', $this->cache_epoch() + 1 );
    }

    /* ── Aggregierte Stats abrufen ───────────────── */

    public function get_aggregated( string $from = '', string $to = '' ): array {
        $cache_key = 'wplzs_' . $this->cache_epoch() . '_' . md5( $from . '|' . $to );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $table = $this->table_name();

        $where  = '1=1';
        $params = [];

        if ( $from ) {
            $where   .= ' AND created >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ( $to ) {
            $where   .= ' AND created <= %s';
            $params[] = $to . ' 23:59:59';
        }

        $sql = "SELECT plz, mode, COUNT(*) AS count, MAX(created) AS last_seen
                FROM `{$table}`
                WHERE {$where}
                GROUP BY plz, mode
                ORDER BY count DESC";

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        $result = [
            'rows'   => $rows ?: [],
            'total'  => $total,
            'period' => [ 'from' => $from, 'to' => $to ],
        ];

        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    /* ── Cleanup-Einstellungen ───────────────────── */

    private function get_cleanup_settings(): array {
        return wp_parse_args( get_option( self::OPT_CLEANUP, [] ), [
            'ttl_days' => 180,
            'max_rows' => 100000,
        ] );
    }

    /* ── Cron-Cleanup ────────────────────────────── */

    public function run_cleanup(): void {
        global $wpdb;
        $table    = $this->table_name();
        $settings = $this->get_cleanup_settings();
        $ttl      = max( 1, (int) $settings['ttl_days'] );
        $max      = max( 100, (int) $settings['max_rows'] );

        // TTL: Einträge älter als X Tage löschen
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE created < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $ttl
        ) );

        // Zeilenlimit: älteste Einträge bei Überschreitung entfernen
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > $max ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` ORDER BY created ASC LIMIT %d",
                $count - $max
            ) );
        }

        $this->bump_cache();
    }

    /* ── REST API ────────────────────────────────── */

    public function register_rest_routes(): void {
        register_rest_route( 'wc-plz/v1', '/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_stats' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_woocommerce' );
            },
            'args' => [
                'from' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) || $v === '',
                    'default'           => '',
                ],
                'to' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) || $v === '',
                    'default'           => '',
                ],
            ],
        ] );
    }

    public function rest_get_stats( WP_REST_Request $request ): WP_REST_Response {
        $from = $request->get_param( 'from' );
        $to   = $request->get_param( 'to' );
        $data = $this->get_aggregated( $from, $to );

        return new WP_REST_Response( [
            'period'       => $data['period'],
            'total_events' => $data['total'],
            'data'         => array_map( fn( $r ) => [
                'plz'       => $r->plz,
                'mode'      => $r->mode,
                'count'     => (int) $r->count,
                'last_seen' => $r->last_seen,
            ], $data['rows'] ),
        ], 200 );
    }

    /* ── Admin-Abschnitt ─────────────────────────── */

    public function render_admin_section(): void {
        $reset_done   = isset( $_GET['wc_plz_stats_reset_done'] );
        $settings_saved = isset( $_GET['wc_plz_cleanup_saved'] );
        $settings     = $this->get_cleanup_settings();
        $next_cron    = wp_next_scheduled( self::CRON_HOOK );

        // Zeitfilter aus GET
        $from = sanitize_text_field( $_GET['stats_from'] ?? '' );
        $to   = sanitize_text_field( $_GET['stats_to'] ?? '' );
        if ( $from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = '';
        if ( $to   && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = '';

        $data         = $this->get_aggregated( $from, $to );
        $rows         = $data['rows'];
        $total        = $data['total'];
        $admin_url    = admin_url( 'admin.php?page=wc-plz-filter' );
        ?>
        <hr />
        <h2>PLZ-Statistik</h2>

        <?php if ( $reset_done ) : ?>
            <div class="notice notice-success is-dismissible"><p>Alle Statistiken wurden gelöscht.</p></div>
        <?php endif; ?>
        <?php if ( $settings_saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Cleanup-Einstellungen gespeichert.</p></div>
        <?php endif; ?>

        <h3 style="margin-top:16px;">Aufbewahrung</h3>
        <form method="post" action="">
            <?php wp_nonce_field( 'wc_plz_cleanup_settings' ); ?>
            <input type="hidden" name="wc_plz_cleanup_settings" value="1" />
            <table class="form-table" style="max-width:600px;">
                <tr>
                    <th>Aufbewahrungsdauer</th>
                    <td>
                        <input type="number" name="ttl_days" value="<?php echo esc_attr( $settings['ttl_days'] ); ?>" min="1" max="3650" class="small-text" /> Tage
                        <p class="description">Einträge älter als diese Anzahl Tage werden automatisch gelöscht (täglich).</p>
                    </td>
                </tr>
                <tr>
                    <th>Maximale Einträge</th>
                    <td>
                        <input type="number" name="max_rows" value="<?php echo esc_attr( $settings['max_rows'] ); ?>" min="100" max="10000000" class="small-text" style="width:100px;" />
                        <p class="description">Absoluter Deckel. Älteste Einträge werden gelöscht, wenn dieser Wert überschritten wird.</p>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( 'Einstellungen speichern', 'secondary', 'submit', false ); ?>
                <span style="margin-left:16px;color:#888;font-size:0.85em;">
                    Aktuelle Einträge: <strong><?php echo number_format_i18n( $total ); ?></strong>
                    &nbsp;|&nbsp;
                    Nächster Cleanup:
                    <strong><?php echo $next_cron ? esc_html( date_i18n( get_option( 'date_format' ), $next_cron ) ) : 'Nicht geplant'; ?></strong>
                </span>
            </p>
        </form>

        <h3>Auswertung</h3>
        <form method="get" action="<?php echo esc_url( $admin_url ); ?>">
            <input type="hidden" name="page" value="wc-plz-filter" />
            <label>
                Von: <input type="date" name="stats_from" value="<?php echo esc_attr( $from ); ?>" />
            </label>
            &nbsp;
            <label>
                Bis: <input type="date" name="stats_to" value="<?php echo esc_attr( $to ); ?>" />
            </label>
            &nbsp;
            <?php submit_button( 'Filtern', 'secondary', 'submit', false ); ?>
            <?php if ( $from || $to ) : ?>
                &nbsp;<a href="<?php echo esc_url( $admin_url ); ?>">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <?php if ( empty( $rows ) ) : ?>
            <p><em>Noch keine Statistikdaten vorhanden<?php echo ( $from || $to ) ? ' für diesen Zeitraum' : ''; ?>.</em></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:700px;margin-top:12px;">
                <thead>
                    <tr>
                        <th>PLZ</th>
                        <th>Zone / Modus</th>
                        <th>Auswahlen</th>
                        <th>Zuletzt gesehen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) :
                        $is_plz  = in_array( $row->mode, [ 'local', 'post' ], true );
                        $plz_fmt = $is_plz ? esc_html( $row->plz ) : '<em style="color:#999;">–</em>';
                        switch ( $row->mode ) {
                            case 'local':    $zone = '🚚 Lokale Lieferung'; break;
                            case 'post':     $zone = '📦 Postversand'; break;
                            case 'abholung': $zone = '🏪 Abholung'; break;
                            case 'skipped':  $zone = '⏭ Übersprungen'; break;
                            default:         $zone = esc_html( $row->mode );
                        }
                    ?>
                        <tr>
                            <td><?php echo $plz_fmt; ?></td>
                            <td><?php echo esc_html( $zone ); ?></td>
                            <td><strong><?php echo number_format_i18n( (int) $row->count ); ?></strong></td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->last_seen ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:16px;">
            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Alle Statistiken unwiderruflich löschen?');">
                <?php wp_nonce_field( 'wc_plz_stats_reset' ); ?>
                <input type="hidden" name="wc_plz_stats_reset" value="1" />
                <?php submit_button( 'Alle Statistiken löschen', 'delete', 'submit', false ); ?>
            </form>
            &nbsp;
            <span style="font-size:0.82em;color:#888;">
                REST API: <code><?php echo esc_url( rest_url( 'wc-plz/v1/stats' ) ); ?></code>
                (WC API-Key erforderlich – WooCommerce → Einstellungen → Erweitert → REST-API)
            </span>
        </p>
        <?php
    }

    /* ── Admin: Stats-Reset ──────────────────────── */

    public function handle_stats_reset(): void {
        if ( ! isset( $_POST['wc_plz_stats_reset'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        check_admin_referer( 'wc_plz_stats_reset' );

        global $wpdb;
        $wpdb->query( "DELETE FROM `" . $this->table_name() . "`" );
        $this->bump_cache();

        wp_safe_redirect( add_query_arg( 'wc_plz_stats_reset_done', '1', admin_url( 'admin.php?page=wc-plz-filter' ) ) );
        exit;
    }

    /* ── Admin: Cleanup-Einstellungen speichern ──── */

    public function handle_cleanup_settings_save(): void {
        if ( ! isset( $_POST['wc_plz_cleanup_settings'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        check_admin_referer( 'wc_plz_cleanup_settings' );

        update_option( self::OPT_CLEANUP, [
            'ttl_days' => max( 1, (int) ( $_POST['ttl_days'] ?? 180 ) ),
            'max_rows' => max( 100, (int) ( $_POST['max_rows'] ?? 100000 ) ),
        ] );

        wp_safe_redirect( add_query_arg( 'wc_plz_cleanup_saved', '1', admin_url( 'admin.php?page=wc-plz-filter' ) ) );
        exit;
    }
}
