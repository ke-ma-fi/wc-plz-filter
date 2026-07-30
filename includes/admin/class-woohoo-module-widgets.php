<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Zusatz-Features" tab: on/off toggles for Merkliste and Cart-Indicator.
 * Renders their settings only - it has no enqueue or markup logic of its
 * own, that stays owned by WC_PLZ_Merkliste / WC_PLZ_Cart_Indicator.
 */
final class Woohoo_Module_Widgets implements Woohoo_Module_Interface {

    public function get_tab_slug(): string {
        return 'widgets';
    }

    public function get_tab_label(): string {
        return 'Zusatz-Features';
    }

    public function is_visible(): bool {
        return true;
    }

    public function render_tab(): void {
        if ( ! current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return;
        }
        ?>
        <p>Beim Deaktivieren der Merkliste bleiben vorhandene Browser-Daten der Kunden unberührt.</p>

        <?php if ( function_exists( 'rocket_clean_domain' ) ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
            <?php wp_nonce_field( 'wc_plz_bust_rocket_cache' ); ?>
            <input type="hidden" name="action" value="wc_plz_bust_rocket_cache" />
            <?php submit_button( 'WP Rocket-Cache leeren', 'secondary', 'submit', false ); ?>
            <p class="description">Nach dem Umschalten von Merkliste oder Cart-Indicator hier klicken, sonst kann eine zwischengespeicherte Seite noch den alten Zustand zeigen.</p>
        </form>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'wc_plz_widgets_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>Merkliste</th>
                    <td>
                        <label>
                            <?php // Hidden fallback: options.php only touches a registered option when its
                            // POST key is present, and an unchecked checkbox sends no key at all. The
                            // hidden "0" guarantees the key exists so unchecking actually persists as off. ?>
                            <input type="hidden" name="<?php echo esc_attr( WC_PLZ_Merkliste::OPTION ); ?>" value="0" />
                            <input type="checkbox" name="<?php echo esc_attr( WC_PLZ_Merkliste::OPTION ); ?>" value="1" <?php checked( get_option( WC_PLZ_Merkliste::OPTION, 1 ), 1 ); ?> />
                            Merkliste-Widget, Notizblock-Icon auf Kacheln und Popover-Liste aktivieren
                        </label>
                        <p class="description">Speicherung rein im Browser (LocalStorage) – keine Account-Bindung, kein Server-Sync. Getrennt von „Meine Lieblingsprodukte".</p>
                    </td>
                </tr>
                <tr>
                    <th>Cart-Indicator</th>
                    <td>
                        <label>
                            <input type="hidden" name="<?php echo esc_attr( WC_PLZ_Cart_Indicator::OPTION ); ?>" value="0" />
                            <input type="checkbox" name="<?php echo esc_attr( WC_PLZ_Cart_Indicator::OPTION ); ?>" value="1" <?php checked( get_option( WC_PLZ_Cart_Indicator::OPTION, 1 ), 1 ); ?> />
                            Farbige Linie unten am Produkt-Kachel-Button anzeigen, wenn Produkt im Warenkorb liegt
                        </label>
                        <p class="description">Ja/Nein-Anzeige auf Produkt-Ebene – mehrere Varianten im Cart zählen als eine Linie, keine Stückzahl. Farbe folgt der Plugin-Akzentfarbe.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Speichern' ); ?>
        </form>

        <hr />

        <h2 class="title">Produktübersicht</h2>
        <p>Konsolidierte, nach Produkt gruppierte Packliste offener Bestellungen (Lokal/Postversand) auf einer eigenen, passwortgeschützten Shop-Seite.</p>
        <form method="post" action="options.php">
            <?php settings_fields( Woohoo_Product_Overview::SETTINGS_GROUP ); ?>
            <?php $this->render_product_overview_fields(); ?>
            <?php submit_button( 'Speichern' ); ?>
        </form>

        <?php $this->render_product_overview_status(); ?>
        <?php
    }

    /**
     * Reads back what's actually persisted right now (independent of the
     * form above, which never re-displays the password) so it's possible to
     * confirm a save actually took effect without digging into the database.
     */
    private function render_product_overview_status(): void {
        $status = Woohoo_Product_Overview::instance()->get_status_summary();
        ?>
        <h3>Produktübersicht – aktueller Status</h3>
        <table class="widefat striped" style="max-width:640px;">
            <tbody>
                <tr>
                    <td><strong>Aktiviert</strong></td>
                    <td><?php echo $status['enabled'] ? 'Ja' : 'Nein'; ?></td>
                </tr>
                <tr>
                    <td><strong>Passwort gesetzt</strong></td>
                    <td>
                        <?php if ( $status['has_password'] ) : ?>
                            <span style="color:#00a32a;font-weight:600;">Ja</span>
                        <?php else : ?>
                            <span style="color:#d63638;font-weight:600;">Nein</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>URL</strong></td>
                    <td><code><?php echo esc_html( $status['url'] ); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Sitzungsdauer</strong></td>
                    <td><?php echo (int) $status['session_days']; ?> Tage</td>
                </tr>
                <tr>
                    <td><strong>Seite</strong></td>
                    <td>
                        <?php if ( $status['page_id'] ) : ?>
                            ID <?php echo (int) $status['page_id']; ?>,
                            Status: <code><?php echo esc_html( (string) $status['page_status'] ); ?></code>,
                            Slug: <code><?php echo esc_html( (string) $status['page_slug'] ); ?></code>
                            <?php if ( $status['page_status'] === null ) : ?>
                                <span style="color:#d63638;">(Seite existiert nicht mehr – bitte einmal speichern, um sie neu anzulegen)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color:#646970;">Noch nicht angelegt (wird beim nächsten Speichern mit aktivierter Checkbox erstellt).</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private function render_product_overview_fields(): void {
        $settings = get_option( Woohoo_Product_Overview::OPTION_SETTINGS, [] );
        $session_days = ! empty( $settings['session_days'] ) ? (int) $settings['session_days'] : 7;
        $has_password = ! empty( $settings['password_hash'] );
        $enabled      = get_option( Woohoo_Product_Overview::OPTION_ENABLED, 0 );
        $opt          = Woohoo_Product_Overview::OPTION_SETTINGS;
        ?>
        <table class="form-table">
            <tr>
                <th>Produktübersicht</th>
                <td>
                    <label>
                        <input type="hidden" name="<?php echo esc_attr( Woohoo_Product_Overview::OPTION_ENABLED ); ?>" value="0" />
                        <input type="checkbox" name="<?php echo esc_attr( Woohoo_Product_Overview::OPTION_ENABLED ); ?>" value="1" <?php checked( $enabled, 1 ); ?> />
                        Seite aktivieren
                    </label>
                    <p class="description">
                        Erreichbar unter:
                        <code><?php echo esc_html( home_url( '/' . Woohoo_Product_Overview::DEFAULT_PATH . '/' ) ); ?></code>
                    </p>
                    <?php if ( ! $has_password ) : ?>
                        <p class="description" style="color:#d63638;">Bitte unten ein Passwort vergeben, sonst bleibt die Seite für alle ohne Woohoo-Berechtigung unzugänglich.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Zugangs-Passwort</th>
                <td>
                    <input type="password" name="<?php echo esc_attr( $opt ); ?>[password]" value="" class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php echo $has_password ? 'Leer lassen, um das aktuelle Passwort beizubehalten.' : 'Noch kein Passwort vergeben.'; ?>
                        Personen mit Woohoo-Berechtigung (angemeldet im wp-admin) benötigen dieses Passwort nicht.
                    </p>
                </td>
            </tr>
            <tr>
                <th>Sitzungsdauer (Tage)</th>
                <td>
                    <input type="number" name="<?php echo esc_attr( $opt ); ?>[session_days]" value="<?php echo esc_attr( $session_days ); ?>" min="1" max="90" class="small-text" />
                    <p class="description">Wie lange ein Browser nach erfolgreicher Passworteingabe entsperrt bleibt.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}
