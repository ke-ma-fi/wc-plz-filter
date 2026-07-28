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
                            Grünes Kreis-Icon auf Produktkacheln anzeigen, wenn Produkt im Warenkorb liegt
                        </label>
                        <p class="description">Ja/Nein-Anzeige auf Produkt-Ebene – mehrere Varianten im Cart zählen als ein Icon, keine Stückzahl.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Speichern' ); ?>
        </form>
        <?php
    }
}
