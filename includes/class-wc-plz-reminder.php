<?php
/**
 * WC PLZ-Filter – Zahlungs-Erinnerung
 *
 * Überwacht WooCommerce-Bestellungen mit Status "pending" und schickt dem
 * Kunden automatisch eine Erinnerungsmail mit Zahlungs-Link, wenn die
 * Bestellung zu lange auf Zahlung wartet.
 *
 * Dev-Modus (Standard: aktiv): kein automatischer Cron, kein Versand an
 * echte Kunden. Manuelle Testläufe über den Admin-Tab möglich.
 *
 * @copyright Metzgerei Fischer. All rights reserved.
 */

defined( 'ABSPATH' ) || exit;

final class WC_PLZ_Reminder {

    const OPT       = 'wc_plz_reminder';
    const OPT_LOG   = 'wc_plz_reminder_log';
    const CRON_HOOK = 'wc_plz_reminder_scan';
    const CRON_SCH  = 'wc_plz_reminder_interval';
    const META_FLAG = 'reminded_pending_payment';
    const LOCK_KEY  = 'wc_plz_reminder_running';
    const MAX_LOG   = 50;

    private static ?self $instance = null;
    private ?array $settings_cache = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action( 'admin_menu',  [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_init',  [ $this, 'handle_admin_actions' ] );
        add_filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_scan' ] );
        add_action( 'update_option_' . self::OPT, [ $this, 'maybe_reschedule_cron' ], 10, 2 );
    }

    /* ── Lifecycle ───────────────────────────────── */

    public static function activate(): void {
        $settings = wp_parse_args( get_option( self::OPT, [] ), self::defaults() );
        if ( empty( $settings['dev_mode'] ) ) {
            // Instanz existiert noch nicht beim Aktivierungsaufruf → direkt schedulen
            self::schedule_cron_static( (int) $settings['cron_interval'] );
        }
    }

    public static function deactivate(): void {
        self::unschedule_cron_static();
    }

    /* ── Defaults ────────────────────────────────── */

    private static function defaults(): array {
        return [
            'dev_mode'          => 1,
            'test_email'        => get_option( 'admin_email', '' ),
            'cron_interval'     => 5,
            'pending_threshold' => 5,
            'mail_subject'      => 'Ihre Bestellung #{order_number} wartet auf Zahlung',
            'mail_body'         => "Guten Tag {customer_first_name},\n\nvielen Dank für Ihre Bestellung #{order_number} vom {order_date} in Höhe von {order_total}.\n\nIhre Zahlung steht noch aus. Bitte schließen Sie die Zahlung über den folgenden Link ab:\n\n{payment_url}\n\nFalls Sie Fragen zu Ihrer Bestellung haben, stehen wir Ihnen gerne zur Verfügung.\n\nMit freundlichen Grüßen\nIhr Shop-Team",
        ];
    }

    /* ── Settings ────────────────────────────────── */

    private function get_settings(): array {
        if ( $this->settings_cache !== null ) {
            return $this->settings_cache;
        }
        $this->settings_cache = wp_parse_args( get_option( self::OPT, [] ), self::defaults() );
        return $this->settings_cache;
    }

    public function register_settings(): void {
        register_setting(
            'wc_plz_reminder_group',
            self::OPT,
            [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
        );
    }

    public function sanitize_settings( array $input ): array {
        $this->settings_cache = null; // Cache invalidieren

        return [
            'dev_mode'          => ! empty( $input['dev_mode'] ) ? 1 : 0,
            'test_email'        => sanitize_email( $input['test_email'] ?? '' ),
            'cron_interval'     => max( 1, (int) ( $input['cron_interval'] ?? 5 ) ),
            'pending_threshold' => max( 0, (int) ( $input['pending_threshold'] ?? 5 ) ),
            'mail_subject'      => sanitize_text_field( $input['mail_subject'] ?? '' ),
            'mail_body'         => sanitize_textarea_field( $input['mail_body'] ?? '' ),
        ];
    }

    /* ── Cron-Reschedule bei Settings-Änderung ───── */

    public function maybe_reschedule_cron( array $old, array $new ): void {
        $dev_old      = (int) ( $old['dev_mode'] ?? 1 );
        $dev_new      = (int) ( $new['dev_mode'] ?? 1 );
        $interval_old = (int) ( $old['cron_interval'] ?? 5 );
        $interval_new = (int) ( $new['cron_interval'] ?? 5 );

        $dev_changed      = $dev_old !== $dev_new;
        $interval_changed = $interval_old !== $interval_new;

        if ( ! $dev_changed && ! $interval_changed ) {
            return;
        }

        self::unschedule_cron_static();

        if ( $dev_new === 0 ) {
            self::schedule_cron_static( $interval_new );
        }
    }

    /* ── Cron-Schedule (Filter) ──────────────────── */

    public function register_cron_schedule( array $schedules ): array {
        $minutes = (int) $this->get_settings()['cron_interval'];
        $schedules[ self::CRON_SCH ] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf( 'Alle %d Minuten (Zahlungs-Erinnerung)', $minutes ),
        ];
        return $schedules;
    }

    private function schedule_cron(): void {
        self::schedule_cron_static( (int) $this->get_settings()['cron_interval'] );
    }

    private static function schedule_cron_static( int $minutes ): void {
        // Custom Schedule manuell eintragen, da der Filter ggf. noch nicht aktiv war
        $interval = $minutes * MINUTE_IN_SECONDS;
        add_filter( 'cron_schedules', function( array $schedules ) use ( $interval, $minutes ): array {
            $schedules[ self::CRON_SCH ] = [
                'interval' => $interval,
                'display'  => sprintf( 'Alle %d Minuten (Zahlungs-Erinnerung)', $minutes ),
            ];
            return $schedules;
        } );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_SCH, self::CRON_HOOK );
        }
    }

    private static function unschedule_cron_static(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
        // Alle verbleibenden Events für diesen Hook entfernen
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ── Hauptlauf ───────────────────────────────── */

    /**
     * Wird direkt vom Cron (ohne Argumente) oder manuell mit $is_dev_run=true aufgerufen.
     * Im Cron-Aufruf ist $is_dev_run immer false (Dev-Modus hat keinen Cron).
     */
    public function run_scan( bool $is_dev_run = false ): array {
        if ( ! $is_dev_run ) {
            if ( get_transient( self::LOCK_KEY ) ) {
                return [ 'skipped' => true, 'reason' => 'locked' ];
            }
            set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );
        }

        $results = [
            'processed' => 0,
            'sent'      => 0,
            'errors'    => 0,
            'skipped'   => 0,
        ];

        try {
            $orders = $this->get_pending_orders();
            foreach ( $orders as $order ) {
                $results['processed']++;
                $sent = $this->send_for_order( $order, $is_dev_run );
                if ( $sent === true ) {
                    $results['sent']++;
                } elseif ( $sent === false ) {
                    $results['errors']++;
                } else {
                    $results['skipped']++;
                }
            }
        } finally {
            if ( ! $is_dev_run ) {
                delete_transient( self::LOCK_KEY );
            }
        }

        update_option( 'wc_plz_reminder_last_run', current_time( 'mysql' ), false );
        return $results;
    }

    /* ── Pending Orders abfragen ─────────────────── */

    private function get_pending_orders(): array {
        $threshold_secs = (int) $this->get_settings()['pending_threshold'] * MINUTE_IN_SECONDS;
        $cutoff         = gmdate( 'Y-m-d H:i:s', time() - $threshold_secs );

        /*
         * Datumsfilter-Syntax: WC 7.0+ (HPOS-kompatibel) unterstützt '<DATETIME>'.
         * Falls die WC-Version diese Syntax nicht kennt, hier auf PHP-seitige
         * Filterung (array_filter nach get_date_created()->getTimestamp()) umsteigen.
         */
        $orders = wc_get_orders( [
            'status'       => 'wc-pending',
            'date_created' => '<' . $cutoff,
            'limit'        => 200,
            'return'       => 'objects',
        ] );

        return is_array( $orders ) ? $orders : [];
    }

    public function get_pending_orders_count(): int {
        $orders = $this->get_pending_orders();
        return count( $orders );
    }

    /* ── Platzhalter-Ersetzung ───────────────────── */

    private function replace_placeholders( string $tpl, WC_Order $order ): string {
        $date = $order->get_date_created();
        $map  = [
            '{order_number}'        => $order->get_order_number(),
            '{order_date}'          => $date ? wp_date( get_option( 'date_format' ), $date->getTimestamp() ) : '',
            '{customer_first_name}' => $order->get_billing_first_name(),
            '{customer_last_name}'  => $order->get_billing_last_name(),
            '{customer_full_name}'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            '{order_total}'         => strip_tags( $order->get_formatted_order_total() ),
            '{payment_url}'         => $order->get_checkout_payment_url(),
            '{shop_name}'           => get_bloginfo( 'name' ),
        ];
        return str_replace( array_keys( $map ), array_values( $map ), $tpl );
    }

    /* ── Mail zusammensetzen ─────────────────────── */

    private function build_subject( WC_Order $order, bool $is_dev ): string {
        $s       = $this->get_settings();
        $subject = $this->replace_placeholders( $s['mail_subject'], $order );
        return $is_dev ? '[TEST] ' . $subject : $subject;
    }

    private function build_body( WC_Order $order, bool $is_dev ): string {
        $s    = $this->get_settings();
        $body = $this->replace_placeholders( $s['mail_body'], $order );

        $date   = $order->get_date_created();
        $footer = "\n\n---\n"
                . 'Bestellnummer: ' . $order->get_order_number() . "\n"
                . 'Bestelldatum: '  . ( $date ? wp_date( get_option( 'date_format' ), $date->getTimestamp() ) : '–' );

        $body .= $footer;

        if ( $is_dev ) {
            $body = '[TEST-Mail – echte Order-ID: #' . $order->get_id() . "]\n\n" . $body;
        }

        return $body;
    }

    /* ── Versand + Flag + Log ────────────────────── */

    /**
     * @return true  Mail erfolgreich versendet
     * @return false Mail-Versand fehlgeschlagen
     * @return null  Bestellung übersprungen (Flag bereits gesetzt oder Status geändert)
     */
    private function send_for_order( WC_Order $order, bool $is_dev ): ?bool {
        // Status-Check kurz vor Versand
        if ( $order->get_status() !== 'pending' ) {
            return null;
        }

        // Im Live-Modus: Flag-Check (bereits erinnert?)
        if ( ! $is_dev && $order->get_meta( self::META_FLAG ) === 'true' ) {
            return null;
        }

        $s       = $this->get_settings();
        $to      = $is_dev ? $s['test_email'] : $order->get_billing_email();
        $subject = $this->build_subject( $order, $is_dev );
        $body    = $this->build_body( $order, $is_dev );
        $success = wp_mail( $to, $subject, $body );

        // Flag nur im Live-Modus und nur bei Erfolg setzen
        if ( $success && ! $is_dev ) {
            $order->update_meta_data( self::META_FLAG, 'true' );
            $order->save();
        }

        $this->append_log( [
            'id'       => uniqid( 'r_', true ),
            'ts'       => current_time( 'mysql' ),
            'to'       => $to,
            'order_id' => $order->get_id(),
            'mode'     => $is_dev ? 'dev' : 'live',
            'status'   => $success ? 'success' : 'error',
        ] );

        return $success;
    }

    /**
     * Wie send_for_order, aber überspringt den Meta-Flag-Check.
     * Für den "Erneut senden"-Button gedacht.
     */
    private function resend_for_order( WC_Order $order ): ?bool {
        $is_dev  = (bool) $this->get_settings()['dev_mode'];
        $s       = $this->get_settings();
        $to      = $is_dev ? $s['test_email'] : $order->get_billing_email();
        $subject = $this->build_subject( $order, $is_dev );
        $body    = $this->build_body( $order, $is_dev );
        $success = wp_mail( $to, $subject, $body );

        // Bei Resend eines fehlgeschlagenen Live-Versands: Flag bei Erfolg setzen
        if ( $success && ! $is_dev && $order->get_meta( self::META_FLAG ) !== 'true' ) {
            $order->update_meta_data( self::META_FLAG, 'true' );
            $order->save();
        }

        $this->append_log( [
            'id'       => uniqid( 'r_', true ),
            'ts'       => current_time( 'mysql' ),
            'to'       => $to,
            'order_id' => $order->get_id(),
            'mode'     => $is_dev ? 'dev' : 'live',
            'status'   => $success ? 'success' : 'error',
        ] );

        return $success;
    }

    /* ── Mail-Log ────────────────────────────────── */

    private function append_log( array $entry ): void {
        $log   = $this->get_log();
        $log[] = $entry;
        // Neueste zuerst, FIFO-Begrenzung auf MAX_LOG
        $log   = array_slice( array_reverse( $log ), 0, self::MAX_LOG );
        update_option( self::OPT_LOG, $log, false );
    }

    public function get_log(): array {
        $log = get_option( self::OPT_LOG, [] );
        return is_array( $log ) ? $log : [];
    }

    /* ── Admin-Menü ──────────────────────────────── */

    public function register_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Zahlungs-Erinnerung',
            'Zahlungs-Erinnerung',
            'manage_woocommerce',
            'wc-plz-reminder',
            [ $this, 'render_admin' ]
        );
    }

    /* ── Admin-Actions ───────────────────────────── */

    public function handle_admin_actions(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Manueller Testlauf
        if ( isset( $_POST['wc_plz_reminder_testrun'] ) ) {
            check_admin_referer( 'wc_plz_reminder_testrun' );
            $results = $this->run_scan( true );
            $msg     = sprintf(
                'Testlauf abgeschlossen: %d Order(s) gefunden, %d Mail(s) versendet, %d Fehler.',
                $results['processed'],
                $results['sent'],
                $results['errors']
            );
            set_transient( 'wc_plz_reminder_notice', $msg, 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=wc-plz-reminder' ) );
            exit;
        }

        // Erneut senden
        if ( isset( $_POST['wc_plz_reminder_resend'] ) ) {
            check_admin_referer( 'wc_plz_reminder_resend' );
            $order_id = absint( $_POST['wc_plz_reminder_order_id'] ?? 0 );
            $order    = $order_id ? wc_get_order( $order_id ) : null;
            if ( $order ) {
                $ok  = $this->resend_for_order( $order );
                $msg = $ok
                    ? sprintf( 'Mail für Bestellung #%d erfolgreich erneut versendet.', $order_id )
                    : sprintf( 'Fehler beim Versand für Bestellung #%d.', $order_id );
            } else {
                $msg = 'Bestellung nicht gefunden.';
            }
            set_transient( 'wc_plz_reminder_notice', $msg, 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=wc-plz-reminder' ) );
            exit;
        }

        // Auf Standardtext zurücksetzen
        if ( isset( $_POST['wc_plz_reminder_reset_text'] ) ) {
            check_admin_referer( 'wc_plz_reminder_reset_text' );
            $current = $this->get_settings();
            $defs    = self::defaults();
            $current['mail_subject'] = $defs['mail_subject'];
            $current['mail_body']    = $defs['mail_body'];
            update_option( self::OPT, $current );
            $this->settings_cache = null;
            wp_safe_redirect( admin_url( 'admin.php?page=wc-plz-reminder&text_reset=1' ) );
            exit;
        }
    }

    /* ── Admin-Tab HTML ──────────────────────────── */

    public function render_admin(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $s            = $this->get_settings();
        $is_dev       = (bool) $s['dev_mode'];
        $cron_ts      = wp_next_scheduled( self::CRON_HOOK );
        $cron_active  = $cron_ts !== false;
        $last_run     = get_option( 'wc_plz_reminder_last_run', '' );
        $log          = $this->get_log();
        $notice       = get_transient( 'wc_plz_reminder_notice' );
        $text_reset   = isset( $_GET['text_reset'] );

        if ( $notice ) {
            delete_transient( 'wc_plz_reminder_notice' );
        }

        $pending_count = $this->get_pending_orders_count();
        ?>
        <div class="wrap">
            <h1>Zahlungs-Erinnerung</h1>

            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $text_reset ) : ?>
                <div class="notice notice-success is-dismissible"><p>Mailtexte auf Standardwerte zurückgesetzt.</p></div>
            <?php endif; ?>

            <!-- Statusanzeige -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:20px;">
                <h2 style="margin-top:0;">Status</h2>
                <table class="widefat" style="max-width:600px;">
                    <tbody>
                        <tr>
                            <td><strong>Modus</strong></td>
                            <td>
                                <?php if ( $is_dev ) : ?>
                                    <span style="color:#d63638;font-weight:600;">Dev-Modus aktiv</span> — kein automatischer Versand
                                <?php else : ?>
                                    <span style="color:#00a32a;font-weight:600;">Live-Modus</span> — Cron aktiv
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cron registriert</strong></td>
                            <td>
                                <?php if ( $cron_active ) : ?>
                                    Ja — nächster Lauf: <?php echo esc_html( wp_date( 'd.m.Y H:i:s', $cron_ts ) ); ?>
                                <?php else : ?>
                                    Nein
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Letzter Lauf</strong></td>
                            <td><?php echo $last_run ? esc_html( $last_run ) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Offene pending Orders (älter als <?php echo (int) $s['pending_threshold']; ?> Min.)</strong></td>
                            <td><?php echo (int) $pending_count; ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ( $is_dev ) : ?>
                    <p style="margin-top:16px;">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'wc_plz_reminder_testrun' ); ?>
                            <input type="hidden" name="wc_plz_reminder_testrun" value="1" />
                            <input type="submit" class="button button-primary" value="Jetzt testen (Dev-Modus)" />
                        </form>
                        <span style="margin-left:12px;color:#646970;font-size:13px;">
                            Führt exakt denselben Scan wie der reguläre Cron aus — jedoch an Test-E-Mail-Adresse, ohne Meta-Flag zu setzen.
                        </span>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Settings-Formular -->
            <form method="post" action="options.php">
                <?php settings_fields( 'wc_plz_reminder_group' ); ?>

                <h2>Einstellungen</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Dev-Modus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[dev_mode]" value="1" <?php checked( $s['dev_mode'], 1 ); ?> />
                                Dev-Modus aktiv (kein automatischer Cron, kein Versand an echte Kunden)
                            </label>
                            <p class="description">Standard: aktiviert. Erst deaktivieren, wenn der Live-Betrieb gewünscht ist.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test-E-Mail-Adresse</th>
                        <td>
                            <input type="email" name="<?php echo esc_attr( self::OPT ); ?>[test_email]" value="<?php echo esc_attr( $s['test_email'] ); ?>" class="regular-text" />
                            <p class="description">Im Dev-Modus werden alle Mails an diese Adresse geschickt (nicht an den Kunden).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cron-Intervall (Minuten)</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( self::OPT ); ?>[cron_interval]" value="<?php echo esc_attr( $s['cron_interval'] ); ?>" min="1" max="1440" class="small-text" />
                            <p class="description">Wie oft der Cron läuft. Änderung wird sofort wirksam (alter Cron wird entfernt, neuer registriert).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pending-Schwellenwert (Minuten)</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( self::OPT ); ?>[pending_threshold]" value="<?php echo esc_attr( $s['pending_threshold'] ); ?>" min="0" max="10080" class="small-text" />
                            <p class="description">Bestellungen, die länger als X Minuten im Status "Zahlung ausstehend" sind, werden angeschrieben.</p>
                        </td>
                    </tr>
                </table>

                <h2>Mailtext-Konfiguration</h2>
                <p>
                    Verfügbare Platzhalter:
                </p>
                <table class="widefat" style="max-width:600px;margin-bottom:16px;">
                    <thead><tr><th>Platzhalter</th><th>Wird ersetzt durch</th></tr></thead>
                    <tbody>
                        <tr><td><code>{order_number}</code></td><td>Bestellnummer</td></tr>
                        <tr><td><code>{order_date}</code></td><td>Bestelldatum (WP-Datumsformat)</td></tr>
                        <tr><td><code>{customer_first_name}</code></td><td>Vorname (Billing)</td></tr>
                        <tr><td><code>{customer_last_name}</code></td><td>Nachname (Billing)</td></tr>
                        <tr><td><code>{customer_full_name}</code></td><td>Vor- und Nachname kombiniert</td></tr>
                        <tr><td><code>{order_total}</code></td><td>Bestellsumme inkl. Währung</td></tr>
                        <tr><td><code>{payment_url}</code></td><td>Direkter Zahlungs-Link</td></tr>
                        <tr><td><code>{shop_name}</code></td><td>Name des Shops</td></tr>
                    </tbody>
                </table>
                <p class="description">
                    Bestellnummer und Bestelldatum werden immer am Ende der Mail eingefügt, auch wenn die Platzhalter aus dem Text entfernt wurden.
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">E-Mail-Betreff</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( self::OPT ); ?>[mail_subject]" value="<?php echo esc_attr( $s['mail_subject'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Mailtext</th>
                        <td>
                            <textarea name="<?php echo esc_attr( self::OPT ); ?>[mail_body]" rows="12" class="large-text"><?php echo esc_textarea( $s['mail_body'] ); ?></textarea>
                            <p class="description">Platziere <code>{payment_url}</code> an der Stelle, an der der Zahlungs-Link erscheinen soll.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Einstellungen speichern' ); ?>
            </form>

            <!-- Standardtext zurücksetzen -->
            <form method="post" style="margin-top:-8px;margin-bottom:24px;">
                <?php wp_nonce_field( 'wc_plz_reminder_reset_text' ); ?>
                <input type="hidden" name="wc_plz_reminder_reset_text" value="1" />
                <input type="submit" class="button button-secondary" value="Mailtexte auf Standardwerte zurücksetzen"
                    onclick="return confirm('Wirklich alle drei Mailtext-Felder (Betreff, Vor-Link-Text, Nach-Link-Text) auf die Standardwerte zurücksetzen?');" />
            </form>

            <hr />

            <!-- Mail-Log -->
            <h2>Mail-Log (letzte <?php echo (int) self::MAX_LOG; ?> Einträge)</h2>
            <?php if ( empty( $log ) ) : ?>
                <p><em>Noch keine Mails versendet.</em></p>
            <?php else : ?>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th>Zeitstempel</th>
                            <th>Empfänger</th>
                            <th>Order-ID</th>
                            <th>Modus</th>
                            <th>Status</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                            <?php
                            $status_label = $entry['status'] === 'success'
                                ? '<span style="color:#00a32a;font-weight:600;">Erfolgreich</span>'
                                : '<span style="color:#d63638;font-weight:600;">Fehler</span>';
                            $mode_label   = $entry['mode'] === 'dev'
                                ? '<span style="color:#646970;">Dev</span>'
                                : 'Live';
                            $order_link   = $entry['order_id']
                                ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $entry['order_id'] . '&action=edit' ) ) . '">#' . (int) $entry['order_id'] . '</a>'
                                : '—';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $entry['ts'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $entry['to'] ?? '—' ); ?></td>
                                <td><?php echo wp_kses_post( $order_link ); ?></td>
                                <td><?php echo wp_kses_post( $mode_label ); ?></td>
                                <td><?php echo wp_kses_post( $status_label ); ?></td>
                                <td>
                                    <?php if ( ! empty( $entry['order_id'] ) ) : ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( 'wc_plz_reminder_resend' ); ?>
                                            <input type="hidden" name="wc_plz_reminder_resend" value="1" />
                                            <input type="hidden" name="wc_plz_reminder_order_id" value="<?php echo (int) $entry['order_id']; ?>" />
                                            <input type="submit" class="button button-small" value="Erneut senden" />
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
