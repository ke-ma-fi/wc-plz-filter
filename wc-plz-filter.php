<?php
/**
 * Plugin Name:  WC PLZ-Filter
 * Plugin URI:   https://fischer.digitale-theke.com
 * Description:  PLZ-Popup mit drei Modi (Abholung, Lokale Lieferung, Postversand). Filtert Produkte dynamisch nach WooCommerce-Versandklassen und füllt den Checkout vor.
 * Version:      2.3.0
 * Author:       Metzgerei Fischer
 * License:      Proprietary
 * License URI:  https://fischer.digitale-theke.com
 * Text Domain:  wc-plz-filter
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 *
 * Copyright (c) 2024-2026 Metzgerei Fischer. All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * modification, distribution, or use of this software, in whole or in part,
 * is strictly prohibited without prior written permission from the copyright
 * holder.
 */

defined( 'ABSPATH' ) || exit;

final class WC_PLZ_Filter {

    const VERSION = '2.3.0';
    const COOKIE  = 'wc_delivery_mode';
    const OPT     = 'wc_plz_filter_v2';
    const CACHE   = 'wc_plz_local_codes';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>WC PLZ-Filter:</strong> WooCommerce muss aktiv sein.</p></div>';
            });

            return;
        }

        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        add_action( 'woocommerce_after_shipping_zone_object_save', fn() => delete_transient( self::CACHE ) );
        add_action( 'woocommerce_delete_shipping_zone', fn() => delete_transient( self::CACHE ) );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_footer',          [ $this, 'render_popup' ] );

        add_action( 'woocommerce_product_query',      [ $this, 'filter_products' ] );
        add_filter( 'woocommerce_checkout_get_value',  [ $this, 'prefill_checkout' ], 10, 2 );

        foreach ( [ 'wp_ajax_', 'wp_ajax_nopriv_' ] as $p ) {
            add_action( $p . 'wc_plz_check', [ $this, 'ajax_check' ] );
            add_action( $p . 'wc_plz_save',  [ $this, 'ajax_save' ] );
        }
    }

    /* --- Cookie / State --- */

    private function get_state(): array {
        $raw = isset( $_COOKIE[ self::COOKIE ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) )
            : '';
        if ( empty( $raw ) ) {
            return [ 'mode' => '', 'plz' => '' ];
        }
        $parts = explode( ':', $raw, 2 );
        return [
            'mode' => $parts[0] ?? '',
            'plz'  => preg_replace( '/\D/', '', $parts[1] ?? '' ),
        ];
    }

    private function set_cookie( string $value, int $days ): void {
        setcookie( self::COOKIE, $value, [
            'expires'  => time() + ( $days * DAY_IN_SECONDS ),
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly'  => false,
            'samesite' => 'Lax',
        ] );
    }

    /* --- Settings --- */

    private function get_settings(): array {
        return wp_parse_args( get_option( self::OPT, [] ), [
            'excluded_classes'       => [],
            'cookie_days'            => 30,
            'popup_title'            => 'Wie moechten Sie bestellen?',
            'popup_text'             => 'Geben Sie Ihre Postleitzahl ein, um zu pruefen ob wir zu Ihnen liefern, oder waehlen Sie Abholung in unserer Filiale.',
            'post_msg'               => 'Fuer Ihre PLZ ist Postversand verfuegbar. Einige Frischeprodukte sind bei Versand nicht erhaeltlich.',
            'popup_color'            => '#cc0000',
            'badge_position'         => 'bottom-right',
            'badge_tooltip_abholung' => 'Mit dieser Auswahl bestellen Sie zur Abholung in einem unserer Ladengeschäfte. Zum Ändern klicken.',
            'badge_tooltip_local'    => 'Mit dieser Auswahl bestellen Sie Ihre Ware zur lokalen Auslieferung. Diese wird vom Team der Metzgerei Fischer durchgeführt. Zum Ändern klicken.',
            'badge_tooltip_post'     => 'Mit der ausgewählten PLZ ist nur ein Postversand möglich. Das Sortiment ist möglicherweise eingeschränkt. Zum Ändern bitte klicken.',
        ] );
    }

    /* --- Dynamische Zonen-Erkennung --- */

    public function get_local_postcodes(): array {
        $cached = get_transient( self::CACHE );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $codes = [];
        foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
            $zone = new WC_Shipping_Zone( $zone_data['id'] );
            foreach ( $zone->get_zone_locations() as $loc ) {
                if ( $loc->type === 'postcode' ) {
                    $codes[] = trim( (string) $loc->code );
                }
            }
        }

        set_transient( self::CACHE, $codes, HOUR_IN_SECONDS );
        return $codes;
    }

    public function is_local( string $plz ): bool {
        $plz = preg_replace( '/\D/', '', $plz );
        if ( strlen( $plz ) !== 5 ) {
            return false;
        }

        foreach ( $this->get_local_postcodes() as $pattern ) {
            $pattern = (string) $pattern;
            
            if ( empty( $pattern ) && $pattern !== '0' ) {
                continue;
            }

            if ( str_contains( $pattern, '...' ) ) {
                [ $from, $to ] = explode( '...', $pattern, 2 );
                if ( $plz >= trim( $from ) && $plz <= trim( $to ) ) {
                    return true;
                }
                continue;
            }

            if ( str_contains( $pattern, '-' ) ) {
                [ $from, $to ] = explode( '-', $pattern, 2 );
                if ( $plz >= trim( $from ) && $plz <= trim( $to ) ) {
                    return true;
                }
                continue;
            }

            if ( str_ends_with( $pattern, '*' ) ) {
                if ( str_starts_with( $plz, rtrim( $pattern, '*' ) ) ) {
                    return true;
                }
                continue;
            }

            if ( $pattern === $plz ) {
                return true;
            }
        }

        return false;
    }

    /* --- Produktfilterung --- */

    public function filter_products( \WP_Query $q ): void {
        if ( is_admin() || ! $q->is_main_query() ) {
            return;
        }

        $state = $this->get_state();

        if ( empty( $state['mode'] ) || $state['mode'] !== 'post' ) {
            return;
        }

        $settings = $this->get_settings();
        $excluded = array_filter( array_map( 'intval', (array) $settings['excluded_classes'] ) );

        if ( empty( $excluded ) ) {
            return;
        }

        $tax = (array) $q->get( 'tax_query' );
        $tax[] = [
            'relation' => 'OR',
            [
                'taxonomy' => 'product_shipping_class',
                'operator' => 'NOT EXISTS',
            ],
            [
                'taxonomy' => 'product_shipping_class',
                'field'    => 'term_id',
                'terms'    => $excluded,
                'operator' => 'NOT IN',
            ],
        ];
        $q->set( 'tax_query', $tax );
    }

    /* --- Checkout Prefill --- */

    public function prefill_checkout( $value, string $input ) {
        if ( $input !== 'billing_postcode' || ! empty( $value ) ) {
            return $value;
        }
        $state = $this->get_state();
        return ! empty( $state['plz'] ) ? $state['plz'] : $value;
    }

    /* --- AJAX: PLZ pruefen --- */

    public function ajax_check(): void {
        check_ajax_referer( 'wc_plz_nonce', 'nonce' );

        $plz = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) ) );

        if ( strlen( $plz ) !== 5 ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gueltige 5-stellige PLZ eingeben.' ] );
        }

        $local    = $this->is_local( $plz );
        $settings = $this->get_settings();

        wp_send_json_success( [
            'plz'      => $plz,
            'is_local' => $local,
            'mode'     => $local ? 'local' : 'post',
            'message'  => $local
                ? 'Wir liefern in Ihre PLZ ' . $plz . '! Alle Produkte verfügbar.'
                : $settings['post_msg'],
        ] );
    }

    /* --- AJAX: Modus speichern --- */

    public function ajax_save(): void {
        check_ajax_referer( 'wc_plz_nonce', 'nonce' );

        $mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) );
        $plz  = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) ) );

        if ( ! in_array( $mode, [ 'abholung', 'local', 'post' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltiger Modus.' ] );
        }

        $settings = $this->get_settings();
        $days     = max( 1, (int) $settings['cookie_days'] );

        $this->set_cookie( $mode . ':' . $plz, $days );

        wp_send_json_success( [ 'mode' => $mode, 'plz' => $plz ] );
    }

    /* --- Frontend: Scripts & Styles --- */

    public function enqueue(): void {
        if ( is_admin() ) {
            return;
        }

        $url      = plugin_dir_url( __FILE__ );
        $state    = $this->get_state();
        $settings = $this->get_settings();

        wp_enqueue_style( 'wc-plz-filter', $url . 'assets/css/plz-popup.css', [], self::VERSION );
        wp_enqueue_script( 'wc-plz-filter', $url . 'assets/js/plz-popup.js', [ 'jquery' ], self::VERSION, true );

        wp_localize_script( 'wc-plz-filter', 'wcPlz', [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( 'wc_plz_nonce' ),
            'cookieName'           => self::COOKIE,
            'cookieDays'           => (int) $settings['cookie_days'],
            'state'                => $state,
            'isCheckout'           => is_checkout() ? 1 : 0,
            'badgePosition'        => $settings['badge_position'],
            'badgeTooltipAbholung' => $settings['badge_tooltip_abholung'],
            'badgeTooltipLocal'    => $settings['badge_tooltip_local'],
            'badgeTooltipPost'     => $settings['badge_tooltip_post'],
        ] );
    }

    /* --- Frontend: Popup HTML --- */

    public function render_popup(): void {
        if ( is_admin() ) {
            return;
        }

        $s = $this->get_settings();
        $color = esc_attr( $s['popup_color'] );
        $badge_pos = esc_attr( $s['badge_position'] );
        ?>
        <style>:root{--wc-plz-color:<?php echo $color; ?>;}</style>
        <div id="wc-plz-overlay" class="wc-plz-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wc-plz-title">
            <div class="wc-plz-modal">
                <div class="wc-plz-modal__header">
                    <h2 class="wc-plz-modal__title" id="wc-plz-title"><?php echo esc_html( $s['popup_title'] ); ?></h2>
                </div>
                <div class="wc-plz-modal__body">
                    <p class="wc-plz-modal__text"><?php echo esc_html( $s['popup_text'] ); ?></p>
                    <div class="wc-plz-section">
                        <label class="wc-plz-label" for="wc-plz-input">Postleitzahl fuer Lieferung / Versand</label>
                        <div class="wc-plz-input-row">
                            <input type="text" id="wc-plz-input" class="wc-plz-input" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" placeholder="z. B. 63667" autocomplete="postal-code" />
                            <button id="wc-plz-submit" class="wc-plz-btn wc-plz-btn--primary">Prüfen</button>
                        </div>
                        <div id="wc-plz-feedback" class="wc-plz-feedback" aria-live="polite"></div>
                    </div>
                    <div class="wc-plz-divider"><span>oder</span></div>
                    <button id="wc-plz-pickup" class="wc-plz-btn wc-plz-btn--pickup">Ich möchte abholen</button>
                </div>
                <div class="wc-plz-modal__footer">
                    <button id="wc-plz-skip" class="wc-plz-btn wc-plz-btn--ghost">Überspringen</button>
                </div>
            </div>
        </div>
        <button id="wc-plz-badge" class="wc-plz-badge wc-plz-badge--<?php echo $badge_pos; ?>" style="display:none;" aria-label="Bestellmodus ändern">
            <span class="wc-plz-badge__pill">
                <span class="wc-plz-badge__dot"></span>
                <span id="wc-plz-badge-icon" class="wc-plz-badge__icon"></span>
                <span id="wc-plz-badge-info" class="wc-plz-badge__info"></span>
                <span class="wc-plz-badge__edit">ändern</span>
            </span>
            <span id="wc-plz-badge-tooltip" class="wc-plz-badge__tooltip" role="tooltip"></span>
        </button>
        <?php
    }

    /* --- Admin --- */

    public function admin_menu(): void {
        add_submenu_page( 'woocommerce', 'PLZ-Filter', 'PLZ-Filter', 'manage_woocommerce', 'wc-plz-filter', [ $this, 'render_admin' ] );
    }

    public function register_settings(): void {
        register_setting( 'wc_plz_filter_group', self::OPT, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ] );
    }

    public function sanitize_settings( array $input ): array {
        delete_transient( self::CACHE );

        $valid_pos = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'bottom-center', 'left-center', 'right-center' ];
        $pos = $input['badge_position'] ?? 'bottom-right';

        return [
            'excluded_classes'       => array_map( 'intval', (array) ( $input['excluded_classes'] ?? [] ) ),
            'cookie_days'            => max( 1, (int) ( $input['cookie_days'] ?? 30 ) ),
            'popup_title'            => sanitize_text_field( $input['popup_title'] ?? '' ),
            'popup_text'             => sanitize_textarea_field( $input['popup_text'] ?? '' ),
            'post_msg'               => sanitize_textarea_field( $input['post_msg'] ?? '' ),
            'popup_color'            => sanitize_hex_color( $input['popup_color'] ?? '#cc0000' ) ?: '#cc0000',
            'badge_position'         => in_array( $pos, $valid_pos, true ) ? $pos : 'bottom-right',
            'badge_tooltip_abholung' => sanitize_textarea_field( $input['badge_tooltip_abholung'] ?? '' ),
            'badge_tooltip_local'    => sanitize_textarea_field( $input['badge_tooltip_local'] ?? '' ),
            'badge_tooltip_post'     => sanitize_textarea_field( $input['badge_tooltip_post'] ?? '' ),
        ];
    }

    public function render_admin(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings    = $this->get_settings();
        $classes     = WC()->shipping()->get_shipping_classes();
        $local_codes = $this->get_local_postcodes();

        $test_html = '';
        if ( isset( $_POST['test_plz'] ) && check_admin_referer( 'wc_plz_test' ) ) {
            $plz = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['test_plz'] ) ) );
            $ok  = $this->is_local( $plz );
            $test_html = '<div class="notice notice-' . ( $ok ? 'success' : 'info' ) . ' inline" style="margin:12px 0"><p>PLZ <strong>' . esc_html( $plz ) . '</strong>: ' . ( $ok ? 'Im lokalen Liefergebiet' : 'Postversand (nicht im lokalen Gebiet)' ) . '</p></div>';
        }

        $opt = self::OPT;
        ?>
        <div class="wrap">
            <h1>PLZ-Filter</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wc_plz_filter_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Vom Postversand ausgeschlossen</th>
                        <td>
                            <?php if ( empty( $classes ) ) : ?>
                                <p class="description">Keine Versandklassen in WooCommerce angelegt.</p>
                            <?php else : ?>
                                <fieldset>
                                <?php foreach ( $classes as $cls ) : ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[excluded_classes][]" value="<?php echo esc_attr( $cls->term_id ); ?>" <?php checked( in_array( $cls->term_id, $settings['excluded_classes'], true ) ); ?> />
                                        <?php echo esc_html( $cls->name ); ?>
                                        <span style="color:#888;">(<?php echo (int) $cls->count; ?> Produkte)</span>
                                    </label>
                                <?php endforeach; ?>
                                </fieldset>
                                <p class="description">Produkte mit diesen Versandklassen werden im Postversand-Modus ausgeblendet.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Cookie-Laufzeit (Tage)</th>
                        <td><input type="number" name="<?php echo esc_attr( $opt ); ?>[cookie_days]" value="<?php echo esc_attr( $settings['cookie_days'] ); ?>" min="1" max="365" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th>Popup-Titel</th>
                        <td><input type="text" name="<?php echo esc_attr( $opt ); ?>[popup_title]" value="<?php echo esc_attr( $settings['popup_title'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Popup-Text</th>
                        <td><textarea name="<?php echo esc_attr( $opt ); ?>[popup_text]" rows="3" class="large-text"><?php echo esc_textarea( $settings['popup_text'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Hinweis bei Postversand</th>
                        <td>
                            <textarea name="<?php echo esc_attr( $opt ); ?>[post_msg]" rows="3" class="large-text"><?php echo esc_textarea( $settings['post_msg'] ); ?></textarea>
                            <p class="description">Wird im Popup angezeigt, wenn die PLZ nicht im lokalen Liefergebiet liegt.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Popup-Farbe</th>
                        <td>
                            <input type="color" name="<?php echo esc_attr( $opt ); ?>[popup_color]" value="<?php echo esc_attr( $settings['popup_color'] ); ?>" />
                            <code style="margin-left:8px;vertical-align:middle;"><?php echo esc_html( $settings['popup_color'] ); ?></code>
                            <p class="description">Farbe des Popup-Headers, der Buttons und der Badge.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Badge-Position</th>
                        <td>
                            <select name="<?php echo esc_attr( $opt ); ?>[badge_position]">
                            <?php
                            $positions = [ 'bottom-right' => 'Unten rechts', 'bottom-left' => 'Unten links', 'top-right' => 'Oben rechts', 'top-left' => 'Oben links', 'bottom-center' => 'Unten mittig', 'left-center' => 'Links mittig', 'right-center' => 'Rechts mittig' ];
                            foreach ( $positions as $val => $label ) :
                            ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['badge_position'], $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                            </select>
                            <p class="description">Position der Status-Bubble im Shop.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Badge-Tooltip Texte</h2>
                <p>Diese Texte werden als Info-Sprechblase beim Hover über die Badge angezeigt.</p>
                <table class="form-table">
                    <tr>
                        <th>Tooltip: Abholung</th>
                        <td>
                            <textarea name="<?php echo esc_attr( $opt ); ?>[badge_tooltip_abholung]" rows="2" class="large-text"><?php echo esc_textarea( $settings['badge_tooltip_abholung'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>Tooltip: Lokale Lieferung</th>
                        <td>
                            <textarea name="<?php echo esc_attr( $opt ); ?>[badge_tooltip_local]" rows="2" class="large-text"><?php echo esc_textarea( $settings['badge_tooltip_local'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>Tooltip: Postversand</th>
                        <td>
                            <textarea name="<?php echo esc_attr( $opt ); ?>[badge_tooltip_post]" rows="2" class="large-text"><?php echo esc_textarea( $settings['badge_tooltip_post'] ); ?></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Speichern' ); ?>
            </form>
            <hr />
            <h2>Erkannte lokale PLZ-Gebiete</h2>
            <?php if ( empty( $local_codes ) ) : ?>
                <p><em>Keine Postleitzahlen in den WooCommerce-Versandzonen gefunden.</em></p>
            <?php else : ?>
                <p>Aus euren Versandzonen erkannt: <code><?php echo esc_html( implode( ', ', $local_codes ) ); ?></code></p>
            <?php endif; ?>
            <hr />
            <h2>PLZ testen</h2>
            <?php echo wp_kses_post( $test_html ); ?>
            <form method="post">
                <?php wp_nonce_field( 'wc_plz_test' ); ?>
                <p>
                    <input type="text" name="test_plz" maxlength="5" placeholder="z. B. 63667" style="width:120px;" />
                    <?php submit_button( 'Pruefen', 'secondary', 'submit', false ); ?>
                </p>
            </form>
        </div>
        <?php
    }
}

WC_PLZ_Filter::instance();
