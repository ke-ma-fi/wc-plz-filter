<?php
/**
 * WC PLZ-Filter (DT Woohoo) – Shared Mail-Service
 *
 * Zentraler Versandpunkt für alle Module, die Kunden-Mails verschicken.
 * Kennt keine Modul-Details (keine Order-IDs, keine Templates) – führt nur
 * den eigentlichen Versand aus und pflegt ein eigenes, modul-unabhängiges
 * Mail-Log. Modul-spezifische Logs (z. B. das Resend-fähige Log der
 * Zahlungs-Erinnerung) bleiben davon getrennt und unberührt.
 *
 * @copyright Metzgerei Fischer. All rights reserved.
 */

defined( 'ABSPATH' ) || exit;

final class Woohoo_Mailer {

    use WC_PLZ_Singleton;

    const OPT_LOG = 'woohoo_mail_log';
    const MAX_LOG = 100;

    private function __construct() {}

    /* ── Versand ─────────────────────────────────── */

    /**
     * @param array $args {
     *     Optional.
     *     @type string   $reply_to  Reply-To-Adresse.
     *     @type string[] $headers   Zusätzliche wp_mail()-Header.
     *     @type string   $source    Aufrufendes Modul, fürs Mail-Log (z. B. "Zahlungs-Erinnerung").
     *     @type string   $reference Anzeige-Referenz fürs Mail-Log (z. B. "Bestellung #123").
     *                               Rein informativ – wird nicht ausgewertet.
     * }
     */
    public function send( string $to, string $subject, string $body, array $args = [] ): bool {
        $headers = $args['headers'] ?? [];
        if ( ! empty( $args['reply_to'] ) ) {
            $headers[] = 'Reply-To: ' . $args['reply_to'];
        }

        $success = wp_mail( $to, $subject, $body, $headers );

        $this->append_log( [
            'ts'        => current_time( 'mysql' ),
            'to'        => $to,
            'subject'   => $subject,
            'source'    => $args['source'] ?? '',
            'reference' => $args['reference'] ?? '',
            'status'    => $success ? 'success' : 'error',
        ] );

        return $success;
    }

    /* ── Mail-Log ────────────────────────────────── */

    private function append_log( array $entry ): void {
        $log = $this->get_log();
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, self::MAX_LOG );
        update_option( self::OPT_LOG, $log, false );
    }

    public function get_log(): array {
        $log = get_option( self::OPT_LOG, [] );
        return is_array( $log ) ? $log : [];
    }

    /* ── Admin-Tab (Woohoo_Module_Mailer) ────────── */

    /**
     * Tab-Inhalt für Woohoo_Module_Mailer. Rendert nur den Inhalt (kein
     * <div class="wrap">/<h1> - das übernimmt Woohoo_Admin_Page).
     */
    public function render_tab(): void {
        if ( ! current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return;
        }

        $log = $this->get_log();
        ?>
        <h2>Mail-Log (letzte <?php echo (int) self::MAX_LOG; ?> Einträge)</h2>
        <p class="description">
            Zeigt jede über den zentralen Mail-Service versendete E-Mail, unabhängig davon
            welches Modul sie ausgelöst hat. Modul-spezifische Aktionen wie "Erneut senden"
            finden sich im jeweiligen Modul-Tab.
        </p>
        <?php if ( empty( $log ) ) : ?>
            <p><em>Noch keine Mails versendet.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>Zeitstempel</th>
                        <th>Empfänger</th>
                        <th>Betreff</th>
                        <th>Modul</th>
                        <th>Referenz</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $log as $entry ) : ?>
                        <?php
                        $status_label = ( $entry['status'] ?? '' ) === 'success'
                            ? '<span style="color:#00a32a;font-weight:600;">Erfolgreich</span>'
                            : '<span style="color:#d63638;font-weight:600;">Fehler</span>';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $entry['ts'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['to'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['subject'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['source'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['reference'] ?? '—' ); ?></td>
                            <td><?php echo wp_kses_post( $status_label ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
