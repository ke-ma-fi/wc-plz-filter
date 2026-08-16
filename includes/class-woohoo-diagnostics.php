<?php
/**
 * DT Woohoo – Datenbank-Diagnose
 *
 * Misst, wie viel Platz das Plugin in wp_options belegt, und trennt dabei
 * sauber zwischen "unsere Transients", "fremde Transients" und "echte
 * Optionen". Hintergrund: die Hidden-IDs werden unter einem versionierten
 * Cache-Key abgelegt (siehe WC_PLZ_Filter::get_hidden_product_ids()). Ein
 * Bump der Version legt einen neuen Eintrag an, der alte bleibt bis zum
 * Ablauf der TTL liegen. Läuft WP-Cron nicht, räumt niemand auf und die
 * Reste sammeln sich unbegrenzt.
 *
 * Alle Abfragen sind links-verankerte LIKE-Prefixe, damit der UNIQUE-Index
 * auf option_name greift - ein voller Table-Scan auf einer mehrere GB
 * grossen wp_options wäre sonst nicht bedienbar. Die beiden unvermeidbar
 * teuren Auswertungen (Autoload-Summe, grösste Einträge) hängen deshalb an
 * eigenen Buttons und laufen nie beim blossen Öffnen des Tabs.
 *
 * @copyright Metzgerei Fischer. All rights reserved.
 */

defined( 'ABSPATH' ) || exit;

final class Woohoo_Diagnostics {

    use WC_PLZ_Singleton;

    /** Zwischenspeicher für das Scan-Ergebnis (ein einzelner Eintrag, feste Key). */
    const SCAN_CACHE     = 'wc_plz_diag_scan';
    const SCAN_CACHE_TTL = 300;

    /** Referenzpunkt für die Churn-Messung (wie schnell zählt die Version hoch?). */
    const OPT_BASELINE = 'wc_plz_diag_baseline';

    /** Löschen läuft gestückelt, damit eine grosse Tabelle kein Timeout auslöst. */
    const PURGE_BATCH       = 2000;
    const PURGE_TIME_BUDGET = 20.0; // Sekunden

    const LARGEST_LIMIT = 20;

    /**
     * Key-Prefixe der plugin-eigenen Transients. Wert- und Timeout-Zeile
     * sind getrennte wp_options-Rows, beide zählen zum Verbrauch.
     */
    const OWN_PREFIXES = [
        'hidden' => [ '_transient_wc_plz_hidden_v', '_transient_timeout_wc_plz_hidden_v' ],
        'stats'  => [ '_transient_wplzs_',          '_transient_timeout_wplzs_' ],
    ];

    private function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /* ── Umgebung ────────────────────────────────── */

    /**
     * Die drei Bedingungen, unter denen das versionierte Key-Schema
     * überhaupt gefährlich wird: kein persistenter Object-Cache (sonst
     * landen Transients nie in der DB), kein laufender Cron (sonst räumt
     * delete_expired_transients() täglich ab) und eine schnell steigende
     * Version.
     */
    public function get_environment(): array {
        $baseline = $this->get_baseline();
        $version  = (int) get_option( WC_PLZ_Filter::HIDDEN_VERSION, 1 );
        $epoch    = (int) get_option( 'wc_plz_stats_epoch', 0 );

        $elapsed = max( 1, time() - (int) $baseline['ts'] );
        $hours   = $elapsed / HOUR_IN_SECONDS;

        return [
            'object_cache'    => wp_using_ext_object_cache(),
            'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'cron_next'       => wp_next_scheduled( 'wp_scheduled_delete' ),
            'hidden_version'  => $version,
            'stats_epoch'     => $epoch,
            'baseline'        => $baseline,
            'elapsed_seconds' => $elapsed,
            // Erst ab einer Viertelstunde aussagekräftig - davor macht eine
            // Hochrechnung auf die Stunde aus zwei Messpunkten Unsinn.
            'bumps_per_hour'  => $elapsed >= 900
                ? ( $version - (int) $baseline['hidden_version'] ) / $hours
                : null,
        ];
    }

    private function get_baseline(): array {
        $stored = get_option( self::OPT_BASELINE, [] );
        if ( ! empty( $stored['ts'] ) ) {
            return $stored;
        }
        return $this->reset_baseline();
    }

    public function reset_baseline(): array {
        $baseline = [
            'ts'             => time(),
            'hidden_version' => (int) get_option( WC_PLZ_Filter::HIDDEN_VERSION, 1 ),
            'stats_epoch'    => (int) get_option( 'wc_plz_stats_epoch', 0 ),
        ];
        update_option( self::OPT_BASELINE, $baseline, false );
        return $baseline;
    }

    /* ── Physische Tabellengrösse ────────────────── */

    /**
     * Aus information_schema statt via SUM(LENGTH(...)) - das ist sofort da
     * und zeigt zusätzlich data_free, also den nach einem DELETE weiterhin
     * belegten, aber wiederverwendbaren Platz. Manche Managed-Hoster sperren
     * information_schema, dann gibt es hier null und die UI sagt das auch.
     */
    public function get_table_size(): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT data_length, index_length, data_free, table_rows
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE() AND table_name = %s",
            $wpdb->options
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        return [
            'data'      => (int) $row['data_length'],
            'index'     => (int) $row['index_length'],
            'free'      => (int) $row['data_free'],
            'total'     => (int) $row['data_length'] + (int) $row['index_length'],
            // InnoDB schätzt table_rows nur - als exakte Zahl taugt das nicht.
            'rows_est'  => (int) $row['table_rows'],
        ];
    }

    /* ── Scan ────────────────────────────────────── */

    public function get_cached_scan(): ?array {
        $scan = get_transient( self::SCAN_CACHE );
        return is_array( $scan ) ? $scan : null;
    }

    /**
     * Zählt Zeilen und Bytes je Kategorie. Jede Abfrage ist ein
     * links-verankerter Prefix und nutzt damit den option_name-Index.
     */
    public function run_scan(): array {
        global $wpdb;

        $hidden = $this->measure_prefixes( self::OWN_PREFIXES['hidden'] );
        $stats  = $this->measure_prefixes( self::OWN_PREFIXES['stats'] );

        $all_transients = $this->measure_prefixes( [ '_transient_', '_site_transient_' ] );

        $scan = [
            'ts'             => time(),
            'hidden'         => $hidden,
            'stats'          => $stats,
            'own_total'      => [
                'rows'  => $hidden['rows'] + $stats['rows'],
                'bytes' => $hidden['bytes'] + $stats['bytes'],
            ],
            'other_transients' => [
                'rows'  => max( 0, $all_transients['rows'] - $hidden['rows'] - $stats['rows'] ),
                'bytes' => max( 0, $all_transients['bytes'] - $hidden['bytes'] - $stats['bytes'] ),
            ],
            'total_rows'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
            'expired'        => $this->count_expired_transients(),
        ];

        set_transient( self::SCAN_CACHE, $scan, self::SCAN_CACHE_TTL );
        return $scan;
    }

    /** @param string[] $prefixes */
    private function measure_prefixes( array $prefixes ): array {
        global $wpdb;

        $rows  = 0;
        $bytes = 0;

        foreach ( $prefixes as $prefix ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            ), ARRAY_A );

            $rows  += (int) ( $row['n'] ?? 0 );
            $bytes += (int) ( $row['bytes'] ?? 0 );
        }

        return [ 'rows' => $rows, 'bytes' => $bytes ];
    }

    /**
     * Abgelaufene, aber noch vorhandene Transients - das ist der Müll, den
     * delete_expired_transients() abräumen würde, wenn Cron liefe. Gezählt
     * wird über die Timeout-Zeilen; die zugehörigen Wert-Zeilen kommen in
     * gleicher Zahl dazu.
     */
    private function count_expired_transients(): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_value + 0 < %d",
            $wpdb->esc_like( '_transient_timeout_' ) . '%',
            time()
        ) );
    }

    /* ── Teure Zusatz-Auswertungen ───────────────── */

    /**
     * Voller Table-Scan mit Filesort. Auf einer stark gewachsenen Tabelle
     * dauert das entsprechend - deshalb nur auf ausdrücklichen Klick und
     * nie automatisch. Beantwortet die eigentliche Frage: sind es wirklich
     * wir, oder bläht ein anderes Plugin die Tabelle auf?
     */
    public function get_largest_options(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS bytes, autoload
             FROM {$wpdb->options}
             ORDER BY LENGTH(option_value) DESC
             LIMIT %d",
            self::LARGEST_LIMIT
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /** Ebenfalls voller Scan: was wird bei jedem Seitenaufruf mitgeladen? */
    public function get_autoload_summary(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT autoload, COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes
             FROM {$wpdb->options}
             GROUP BY autoload",
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /* ── Aufräumen ───────────────────────────────── */

    /**
     * Löscht sämtliche plugin-eigenen Transients - auch den aktuell gültigen.
     * Das ist Absicht und ungefährlich: der Cache baut sich beim nächsten
     * Frontend-Request aus der Datenbank neu auf.
     *
     * Gestückelt mit Zeitbudget. Wird das Budget aufgebraucht, meldet die
     * Funktion done=false und der Aufruf lässt sich einfach wiederholen,
     * statt in ein PHP-Timeout mitten in einer Riesen-Transaktion zu laufen.
     */
    public function purge_own_transients(): array {
        global $wpdb;

        $deadline = microtime( true ) + self::PURGE_TIME_BUDGET;
        $deleted  = 0;

        foreach ( self::OWN_PREFIXES as $prefixes ) {
            foreach ( $prefixes as $prefix ) {
                $like = $wpdb->esc_like( $prefix ) . '%';

                do {
                    if ( microtime( true ) > $deadline ) {
                        delete_transient( self::SCAN_CACHE );
                        return [ 'deleted' => $deleted, 'done' => false ];
                    }

                    $n = $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
                        $like,
                        self::PURGE_BATCH
                    ) );

                    $n = is_numeric( $n ) ? (int) $n : 0;
                    $deleted += $n;
                } while ( $n >= self::PURGE_BATCH );
            }
        }

        delete_transient( self::SCAN_CACHE );
        return [ 'deleted' => $deleted, 'done' => true ];
    }

    /**
     * Core-Aufräumroutine für abgelaufene Transients aller Plugins - genau
     * das, was der tägliche wp_scheduled_delete-Cron tut. force=true, damit
     * sie auch bei externem Object-Cache durchläuft.
     */
    public function purge_expired_transients(): void {
        delete_expired_transients( true );
        delete_transient( self::SCAN_CACHE );
    }

    /* ── Admin-Actions ───────────────────────────── */

    public function handle_actions(): void {
        if ( ! self::current_user_may() ) {
            return;
        }

        if ( isset( $_POST['wc_plz_diag_scan'] ) ) {
            check_admin_referer( 'wc_plz_diag_scan' );
            $this->run_scan();
            $this->redirect( [ 'wc_plz_diag_scanned' => '1' ] );
        }

        if ( isset( $_POST['wc_plz_diag_purge_own'] ) ) {
            check_admin_referer( 'wc_plz_diag_purge_own' );
            $result = $this->purge_own_transients();
            $this->redirect( [
                'wc_plz_diag_purged' => (string) $result['deleted'],
                'wc_plz_diag_more'   => $result['done'] ? '0' : '1',
            ] );
        }

        if ( isset( $_POST['wc_plz_diag_purge_expired'] ) ) {
            check_admin_referer( 'wc_plz_diag_purge_expired' );
            $this->purge_expired_transients();
            $this->redirect( [ 'wc_plz_diag_expired_purged' => '1' ] );
        }

        if ( isset( $_POST['wc_plz_diag_reset_baseline'] ) ) {
            check_admin_referer( 'wc_plz_diag_reset_baseline' );
            $this->reset_baseline();
            $this->redirect( [ 'wc_plz_diag_baseline_reset' => '1' ] );
        }
    }

    private function redirect( array $args ): void {
        wp_safe_redirect( Woohoo_Admin_Page::tab_url( 'diagnostics', $args ) );
        exit;
    }

    /**
     * Diagnose zeigt Datenbank-Interna und bietet Löschaktionen - deshalb
     * dieselbe engere Schranke wie die Update-Verwaltung (nur Administrator,
     * kein shop_manager), nicht die allgemeine MANAGE_CAP.
     */
    public static function current_user_may(): bool {
        return current_user_can( WC_PLZ_Updater::MANAGE_UPDATE_CAP );
    }
}
