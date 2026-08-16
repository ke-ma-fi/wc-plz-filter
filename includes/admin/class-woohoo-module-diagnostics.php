<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Diagnose" tab: zeigt den Platzverbrauch des Plugins in wp_options und
 * die Umgebungsbedingungen, unter denen das versionierte Transient-Schema
 * aus dem Ruder läuft. Rechnet nichts Teures beim Öffnen - alle vollen
 * Table-Scans hängen an einem eigenen Button (siehe Woohoo_Diagnostics).
 */
final class Woohoo_Module_Diagnostics implements Woohoo_Module_Interface {

    private Woohoo_Diagnostics $diagnostics;

    public function __construct( Woohoo_Diagnostics $diagnostics ) {
        $this->diagnostics = $diagnostics;
    }

    public function get_tab_slug(): string {
        return 'diagnostics';
    }

    public function get_tab_label(): string {
        return 'Diagnose';
    }

    public function is_visible(): bool {
        return Woohoo_Diagnostics::current_user_may();
    }

    public function render_tab(): void {
        if ( ! Woohoo_Diagnostics::current_user_may() ) {
            return;
        }

        $this->render_notices();
        $this->render_verdict();
        $this->render_table_size();
        $this->render_scan();
        $this->render_deep_analysis();
        $this->render_actions();
    }

    /* ── Rückmeldungen nach einer Aktion ─────────── */

    private function render_notices(): void {
        if ( isset( $_GET['wc_plz_diag_purged'] ) ) {
            $deleted = (int) $_GET['wc_plz_diag_purged'];
            $more    = ! empty( $_GET['wc_plz_diag_more'] );
            printf(
                '<div class="notice notice-success"><p>%s Zeilen gelöscht.%s</p></div>',
                number_format_i18n( $deleted ),
                $more
                    ? ' <strong>Zeitbudget erreicht – es sind noch Reste da. Bitte erneut klicken.</strong>'
                    : ''
            );
        }

        if ( isset( $_GET['wc_plz_diag_expired_purged'] ) ) {
            echo '<div class="notice notice-success"><p>Abgelaufene Transients aller Plugins wurden aufgeräumt.</p></div>';
        }

        if ( isset( $_GET['wc_plz_diag_baseline_reset'] ) ) {
            echo '<div class="notice notice-success"><p>Referenzpunkt für die Churn-Messung neu gesetzt.</p></div>';
        }
    }

    /* ── Ampel: greifen die Voraussetzungen? ─────── */

    private function render_verdict(): void {
        $env = $this->diagnostics->get_environment();

        // Mit persistentem Object-Cache landen Transients gar nicht erst in
        // der Datenbank - dann kann dieses Plugin die Tabelle nicht aufblähen.
        if ( $env['object_cache'] ) {
            echo '<div class="notice notice-success inline" style="margin:0 0 16px;"><p>'
               . '<strong>Persistenter Object-Cache aktiv.</strong> Transients werden nicht in <code>wp_options</code> gespeichert. '
               . 'Ein Wachstum der Tabelle hat dann eine andere Ursache als dieses Plugin.</p></div>';
        }

        $cron_broken = $env['cron_disabled'] || empty( $env['cron_next'] );
        ?>
        <h2>Voraussetzungen</h2>
        <p class="description" style="margin-bottom:12px;">
            Die Hidden-IDs liegen unter einem versionierten Cache-Key. Zählt die Version hoch,
            bleibt der alte Eintrag bis zum Ablauf der TTL (12 h) liegen. Aufgeräumt wird er nur
            vom täglichen WordPress-Cron. Fällt der aus, sammeln sich die Reste unbegrenzt an.
        </p>
        <table class="widefat striped" style="max-width:860px;">
            <tbody>
                <tr>
                    <td style="width:280px;"><strong>Persistenter Object-Cache</strong></td>
                    <td><?php echo $env['object_cache'] ? 'Ja (Redis/Memcached)' : 'Nein'; ?></td>
                    <td><?php echo $this->flag( ! $env['object_cache'], 'Transients gehen in die Datenbank' ); ?></td>
                </tr>
                <tr>
                    <td><strong>WP-Cron</strong></td>
                    <td>
                        <?php if ( $env['cron_disabled'] ) : ?>
                            <code>DISABLE_WP_CRON</code> ist gesetzt
                        <?php elseif ( empty( $env['cron_next'] ) ) : ?>
                            <code>wp_scheduled_delete</code> ist nicht eingeplant
                        <?php else : ?>
                            Nächster Aufräumlauf:
                            <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $env['cron_next'] ) ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->flag( $cron_broken, 'Niemand räumt abgelaufene Transients ab' ); ?></td>
                </tr>
                <tr>
                    <td><strong>Hidden-IDs-Version</strong></td>
                    <td>
                        <?php echo number_format_i18n( (int) $env['hidden_version'] ); ?>
                        <?php if ( $env['bumps_per_hour'] !== null ) : ?>
                            <br /><span class="description">
                                ca. <?php echo number_format_i18n( $env['bumps_per_hour'], 1 ); ?> Bumps/Stunde
                                (gemessen seit <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $env['baseline']['ts'] ) ); ?>)
                            </span>
                        <?php else : ?>
                            <br /><span class="description">
                                Churn-Messung läuft seit
                                <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $env['baseline']['ts'] ) ); ?> –
                                aussagekräftig ab ca. 15 Minuten Laufzeit.
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        // Jeder Bump erzeugt bis zu zwei neue Zeilen, die 12 h liegen bleiben.
                        // Ab etwa 100/h reden wir über fünfstellige Zeilenzahlen pro Tag.
                        echo $this->flag(
                            $env['bumps_per_hour'] !== null && $env['bumps_per_hour'] >= 100,
                            'Sehr hohe Änderungsrate – typisch für Importe oder ERP-Sync'
                        );
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Statistik-Epoch</strong></td>
                    <td><?php echo number_format_i18n( (int) $env['stats_epoch'] ); ?></td>
                    <td><span style="color:#646970;">nur Admin-Aufrufe betroffen</span></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private function flag( bool $is_risk, string $message ): string {
        if ( ! $is_risk ) {
            return '<span style="color:#00a32a;font-weight:600;">unkritisch</span>';
        }
        return '<span style="color:#d63638;font-weight:600;">Risiko</span> <span class="description">'
             . esc_html( $message ) . '</span>';
    }

    /* ── Physische Tabellengrösse ────────────────── */

    private function render_table_size(): void {
        $size = $this->diagnostics->get_table_size();
        ?>
        <h2 style="margin-top:28px;">Tabellengrösse <code>wp_options</code></h2>
        <?php if ( $size === null ) : ?>
            <p class="description">
                <code>information_schema</code> ist auf diesem Server nicht lesbar –
                die physische Tabellengrösse lässt sich hier nicht ermitteln.
            </p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:860px;">
                <tbody>
                    <tr>
                        <td style="width:280px;"><strong>Daten + Index</strong></td>
                        <td><strong><?php echo esc_html( size_format( $size['total'], 1 ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong>davon Index</strong></td>
                        <td><?php echo esc_html( size_format( $size['index'], 1 ) ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Belegt, aber wiederverwendbar</strong></td>
                        <td>
                            <?php echo esc_html( size_format( $size['free'], 1 ) ); ?>
                            <p class="description" style="margin:4px 0 0;">
                                Nach einem Löschlauf gibt InnoDB den Platz nicht ans Dateisystem zurück.
                                Erst <code>OPTIMIZE TABLE <?php echo esc_html( $GLOBALS['wpdb']->options ); ?>;</code>
                                schrumpft die Datei – das sperrt die Tabelle und gehört in ein Wartungsfenster,
                                deshalb bewusst kein Button an dieser Stelle.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Zeilen (Schätzung)</strong></td>
                        <td>
                            <?php echo esc_html( number_format_i18n( $size['rows_est'] ) ); ?>
                            <span class="description">InnoDB-Schätzwert, exakte Zahl siehe Scan</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php endif;
    }

    /* ── Scan-Ergebnis ───────────────────────────── */

    private function render_scan(): void {
        $scan = $this->diagnostics->get_cached_scan();
        ?>
        <h2 style="margin-top:28px;">Aufteilung nach Verursacher</h2>
        <form method="post" style="margin-bottom:12px;">
            <?php wp_nonce_field( 'wc_plz_diag_scan' ); ?>
            <?php submit_button( $scan ? 'Scan wiederholen' : 'Scan starten', 'primary', 'wc_plz_diag_scan', false ); ?>
            <span class="description" style="margin-left:8px;">
                Nutzt den Index auf <code>option_name</code>, läuft daher auch auf grossen Tabellen zügig.
            </span>
        </form>

        <?php if ( ! $scan ) : ?>
            <p class="description">Noch kein Scan durchgeführt.</p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:860px;">
            <thead>
                <tr>
                    <th>Kategorie</th>
                    <th style="width:140px;">Zeilen</th>
                    <th style="width:140px;">Grösse</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $this->scan_row( 'Hidden-IDs-Transients (dieses Plugin)', $scan['hidden'], true );
                $this->scan_row( 'Statistik-Transients (dieses Plugin)', $scan['stats'], true );
                $this->scan_row( 'Transients anderer Plugins', $scan['other_transients'], false );
                ?>
                <tr>
                    <td><strong>Zeilen insgesamt in <code>wp_options</code></strong></td>
                    <td colspan="2"><strong><?php echo esc_html( number_format_i18n( (int) $scan['total_rows'] ) ); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>Abgelaufen, aber noch vorhanden</strong></td>
                    <td colspan="2">
                        <?php echo esc_html( number_format_i18n( (int) $scan['expired'] ) ); ?> Timeout-Zeilen
                        <span class="description">
                            (plus je eine zugehörige Wert-Zeile) – das wäre die Beute eines Cron-Laufs
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="description">
            Stand: <?php echo esc_html( wp_date( 'd.m.Y H:i:s', (int) $scan['ts'] ) ); ?>.
            Überwiegen die beiden oberen Zeilen, liegt es an diesem Plugin. Überwiegt die dritte,
            ist ein anderes Plugin die Ursache.
        </p>
        <?php
    }

    private function scan_row( string $label, array $bucket, bool $is_own ): void {
        ?>
        <tr>
            <td><?php echo $is_own ? '<strong>' . esc_html( $label ) . '</strong>' : esc_html( $label ); ?></td>
            <td><?php echo esc_html( number_format_i18n( (int) $bucket['rows'] ) ); ?></td>
            <td><?php echo esc_html( size_format( (int) $bucket['bytes'], 1 ) ); ?></td>
        </tr>
        <?php
    }

    /* ── Teure Auswertungen ──────────────────────── */

    private function render_deep_analysis(): void {
        $show = isset( $_GET['wc_plz_diag_deep'] );
        ?>
        <h2 style="margin-top:28px;">Detailanalyse</h2>
        <p class="description" style="margin-bottom:12px;">
            Voller Table-Scan über <code>wp_options</code>. Auf einer stark gewachsenen Tabelle
            dauert das spürbar – deshalb nur auf ausdrücklichen Aufruf.
        </p>

        <?php if ( ! $show ) : ?>
            <a class="button" href="<?php echo esc_url( Woohoo_Admin_Page::tab_url( 'diagnostics', [ 'wc_plz_diag_deep' => '1' ] ) ); ?>">
                Detailanalyse ausführen
            </a>
            <?php return; ?>
        <?php endif; ?>

        <h3>Autoload</h3>
        <table class="widefat striped" style="max-width:860px;margin-bottom:20px;">
            <thead>
                <tr><th>autoload</th><th style="width:140px;">Zeilen</th><th style="width:140px;">Grösse</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $this->diagnostics->get_autoload_summary() as $row ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $row['autoload'] ); ?></code></td>
                        <td><?php echo esc_html( number_format_i18n( (int) $row['n'] ) ); ?></td>
                        <td><?php echo esc_html( size_format( (int) $row['bytes'], 1 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:-12px;margin-bottom:20px;">
            Autoload-Einträge werden bei <em>jedem</em> Seitenaufruf geladen. Alles über ein paar
            hundert Kilobyte ist hier ein Performance-Problem, unabhängig von der Gesamtgrösse.
        </p>

        <h3>Grösste Einträge</h3>
        <table class="widefat striped" style="max-width:860px;">
            <thead>
                <tr><th>option_name</th><th style="width:120px;">Grösse</th><th style="width:100px;">autoload</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $this->diagnostics->get_largest_options() as $row ) : ?>
                    <tr>
                        <td><code style="word-break:break-all;"><?php echo esc_html( (string) $row['option_name'] ); ?></code></td>
                        <td><?php echo esc_html( size_format( (int) $row['bytes'], 1 ) ); ?></td>
                        <td><code><?php echo esc_html( (string) $row['autoload'] ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ── Aktionen ────────────────────────────────── */

    private function render_actions(): void {
        ?>
        <h2 style="margin-top:28px;">Aufräumen</h2>

        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'wc_plz_diag_purge_own' ); ?>
            <?php submit_button( 'Transients dieses Plugins löschen', 'secondary', 'wc_plz_diag_purge_own', false ); ?>
            <p class="description" style="margin-top:6px;max-width:860px;">
                Löscht alle <code>wc_plz_hidden_*</code>- und <code>wplzs_*</code>-Transients, auch den
                aktuell gültigen. Ungefährlich: der Cache baut sich beim nächsten Shop-Aufruf neu auf.
                Läuft in Blöcken mit Zeitbudget – bei sehr vielen Zeilen meldet die Seite, dass ein
                weiterer Klick nötig ist.
            </p>
        </form>

        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'wc_plz_diag_purge_expired' ); ?>
            <?php submit_button( 'Abgelaufene Transients aller Plugins löschen', 'secondary', 'wc_plz_diag_purge_expired', false ); ?>
            <p class="description" style="margin-top:6px;max-width:860px;">
                Entspricht genau dem, was der tägliche WordPress-Cron täte
                (<code>delete_expired_transients()</code>). Sinnvoll, wenn oben steht, dass Cron nicht läuft.
            </p>
        </form>

        <form method="post">
            <?php wp_nonce_field( 'wc_plz_diag_reset_baseline' ); ?>
            <?php submit_button( 'Churn-Messung zurücksetzen', 'secondary', 'wc_plz_diag_reset_baseline', false ); ?>
            <p class="description" style="margin-top:6px;max-width:860px;">
                Setzt den Referenzpunkt für „Bumps/Stunde“ auf jetzt. Nützlich, um die Rate gezielt
                während eines Imports zu messen.
            </p>
        </form>
        <?php
    }
}
