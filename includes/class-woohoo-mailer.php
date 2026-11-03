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

    /**
     * Retention for log entries. The log holds personal data (customer
     * e-mail addresses, subjects with order numbers), so entries are not kept
     * longer than needed to diagnose delivery problems - on top of MAX_LOG.
     */
    const MAX_AGE_DAYS = 90;

    /** Error message captured from wp_mail_failed during our own send(). */
    private string $last_error = '';

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
        $entry = [
            'ts'        => current_time( 'mysql' ),
            'to'        => $to,
            'subject'   => $subject,
            'source'    => $args['source'] ?? '',
            'reference' => $args['reference'] ?? '',
        ];

        if ( ! self::is_valid_recipient( $to ) ) {
            $this->append_log( $entry + [
                'status' => 'invalid_recipient',
                'error'  => $to === '' ? 'Keine Empfängeradresse.' : 'Ungültige Empfängeradresse.',
            ] );
            return false;
        }

        $headers = $args['headers'] ?? [];
        if ( ! empty( $args['reply_to'] ) ) {
            $headers[] = 'Reply-To: ' . $args['reply_to'];
        }

        // Capture the failure reason of exactly this wp_mail() call. Attached
        // only around our own send, so other plugins' mail failures are never
        // attributed to us.
        $this->last_error = '';
        add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );
        try {
            $success = (bool) wp_mail( $to, $subject, $body, $headers );
        } finally {
            remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );
        }

        $this->append_log( $entry + [
            'status' => $success ? 'success' : 'error',
            'error'  => $success ? '' : $this->last_error,
        ] );

        return $success;
    }

    /** @internal Hooked on wp_mail_failed only for the duration of send(). */
    public function capture_mail_error( $error ): void {
        if ( is_wp_error( $error ) ) {
            $this->last_error = (string) $error->get_error_message();
        }
    }

    /** Non-empty, and every comma-separated address passes is_email(). */
    private static function is_valid_recipient( string $to ): bool {
        $addresses = array_map( 'trim', explode( ',', $to ) );
        foreach ( $addresses as $address ) {
            if ( $address === '' || ! is_email( $address ) ) {
                return false;
            }
        }
        return true;
    }

    /* ── Mail-Log ────────────────────────────────── */

    private function append_log( array $entry ): void {
        $log = $this->get_log();
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, self::MAX_LOG );
        update_option( self::OPT_LOG, $log, false );
    }

    /**
     * Log entries, newest first, without entries older than MAX_AGE_DAYS.
     * Expired entries are also removed from the stored option here, so the
     * retention applies even when no new mail is sent for a while (any
     * send or view of the Mail-Log tab purges them).
     */
    public function get_log(): array {
        $log = get_option( self::OPT_LOG, [] );
        if ( ! is_array( $log ) ) {
            return [];
        }

        // Same clock and format as the 'ts' field (current_time( 'mysql' )),
        // so a plain string comparison orders them correctly.
        $cutoff = wp_date( 'Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS );
        $kept   = array_values( array_filter( $log, function ( $entry ) use ( $cutoff ): bool {
            $ts = is_array( $entry ) ? ( $entry['ts'] ?? '' ) : '';
            // Entries without a usable timestamp are left to the MAX_LOG cap.
            return ! is_string( $ts ) || $ts === '' || $ts >= $cutoff;
        } ) );

        if ( count( $kept ) !== count( $log ) ) {
            update_option( self::OPT_LOG, $kept, false );
        }

        return $kept;
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
            finden sich im jeweiligen Modul-Tab. Einträge werden nach
            <?php echo (int) self::MAX_AGE_DAYS; ?> Tagen automatisch gelöscht.
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
                        $status = $entry['status'] ?? '';
                        if ( $status === 'success' ) {
                            $status_label = '<span style="color:#00a32a;font-weight:600;">Erfolgreich</span>';
                        } elseif ( $status === 'invalid_recipient' ) {
                            $status_label = '<span style="color:#d63638;font-weight:600;">Ungültiger Empfänger</span>';
                        } else {
                            $status_label = '<span style="color:#d63638;font-weight:600;">Fehler</span>';
                        }
                        // 'error' exists only on entries written since 2.13.0.
                        $error = isset( $entry['error'] ) && is_string( $entry['error'] ) ? $entry['error'] : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $entry['ts'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['to'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['subject'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['source'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $entry['reference'] ?? '—' ); ?></td>
                            <td>
                                <?php echo wp_kses_post( $status_label ); ?>
                                <?php if ( $error !== '' ) : ?>
                                    <br /><small><?php echo esc_html( $error ); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
