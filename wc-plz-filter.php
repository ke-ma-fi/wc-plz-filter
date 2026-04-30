<?php
/**
 * Plugin Name:  WC PLZ-Filter
 * Plugin URI:   https://fischer.digitale-theke.com
 * Description:  PLZ-Popup mit drei Modi (Abholung, Lokale Lieferung, Postversand). Filtert Produkte dynamisch nach WooCommerce-Versandklassen und füllt den Checkout vor.
 * Version:      2.7.9
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

    const VERSION         = '2.7.9';
    const COOKIE          = 'wc_delivery_mode';
    const OPT             = 'wc_plz_filter_v2';
    const CACHE           = 'wc_plz_local_codes';
    const HIDDEN_VERSION  = 'wc_plz_hidden_version';

    private static ?self $instance = null;
    private ?array $settings_cache = null;
    private ?WC_PLZ_Stats   $stats   = null;
    private ?WC_PLZ_Updater $updater = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    private function maybe_bust_rocket_cache(): void {
        if ( get_option( 'wc_plz_rocket_busted', '' ) === self::VERSION ) {
            return;
        }
        update_option( 'wc_plz_rocket_busted', self::VERSION, false );
        if ( function_exists( 'rocket_clean_minify' ) ) {
            rocket_clean_minify();
        }
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
    }

    public function init(): void {
        $this->maybe_bust_rocket_cache();

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>WC PLZ-Filter:</strong> WooCommerce muss aktiv sein.</p></div>';
            });

            return;
        }

        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-plz-stats.php';
        $this->stats = WC_PLZ_Stats::instance();

        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-plz-updater.php';
        $this->updater = WC_PLZ_Updater::instance();

        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_admin_reset' ] );

        add_action( 'woocommerce_after_shipping_zone_object_save', fn() => delete_transient( self::CACHE ) );
        add_action( 'woocommerce_delete_shipping_zone', fn() => delete_transient( self::CACHE ) );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_filter( 'script_loader_tag',  [ $this, 'add_nowprocket_attr' ], 10, 2 );
        add_action( 'wp_footer',          [ $this, 'render_popup' ] );
        add_action( 'rest_api_init',      [ $this, 'register_rest_routes' ] );


        add_action( 'woocommerce_product_query',      [ $this, 'filter_products' ] );
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'prefill_checkout' ], 10, 2 );

        // fgf-Hardening: Server-side enforcement (cookie ist client-controlled)
        add_action( 'woocommerce_cart_loaded_from_session',  [ $this, 'remove_excluded_cart_items' ] );
        add_action( 'woocommerce_check_cart_items',          [ $this, 'validate_cart_items' ] );
        add_action( 'woocommerce_before_checkout_form',      [ $this, 'checkout_min_order_notice' ] );
        add_action( 'template_redirect',                    [ $this, 'redirect_excluded_single' ] );
        add_action( 'wp_footer',                            [ $this, 'maybe_show_blocked_alert' ] );

        // FOUC-Schutz: Inline-Script im <head> versteckt synchron via localStorage-Cache
        add_action( 'wp_head', [ $this, 'print_head_hide_script' ], 1 );

        // Cache-Invalidation für Hidden-IDs (versioniertes Cache-Key-Schema, kein wp_options-LIKE-Scan)
        add_action( 'edited_product_shipping_class',  [ $this, 'invalidate_hidden_ids_cache' ] );
        add_action( 'created_product_shipping_class', [ $this, 'invalidate_hidden_ids_cache' ] );
        add_action( 'delete_product_shipping_class',  [ $this, 'invalidate_hidden_ids_cache' ] );
        add_action( 'set_object_terms',               [ $this, 'maybe_invalidate_hidden_ids' ], 10, 4 );

        foreach ( [ 'wp_ajax_', 'wp_ajax_nopriv_' ] as $p ) {
            add_action( $p . 'wc_plz_check',       [ $this, 'ajax_check' ] );
            add_action( $p . 'wc_plz_save',        [ $this, 'ajax_save' ] );
            add_action( $p . 'wc_plz_hidden_ids',  [ $this, 'ajax_hidden_ids' ] );
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
        if ( $this->settings_cache !== null ) {
            return $this->settings_cache;
        }

        $this->settings_cache = wp_parse_args( get_option( self::OPT, [] ), [
            'excluded_classes'       => [],
            'cookie_days'            => 30,
            'popup_title'            => 'Wie möchten Sie bestellen?',
            'popup_text'             => 'Geben Sie Ihre Postleitzahl ein, um zu prüfen ob wir zu Ihnen liefern, oder wählen Sie Abholung in unserer Filiale.',
            'post_msg'               => 'Für Ihre PLZ ist Postversand verfügbar. Einige Frischeprodukte sind bei Versand nicht erhältlich.',
            'min_order_local'        => 30,
            'min_order_post'         => 30,
            'popup_color'            => '#cc0000',
            'badge_position'         => 'bottom-right',
            'badge_rotate'           => 0,
            'badge_offset_x'         => 0,
            'badge_offset_y'         => 0,
            'badge_tooltip_abholung' => 'Mit dieser Auswahl bestellen Sie zur Abholung in einem unserer Ladengeschäfte. Zum Ändern klicken.',
            'badge_tooltip_local'    => 'Für Ihre PLZ ist lokale Auslieferung verfügbar. Das Team der Metzgerei Fischer beliefert Sie persönlich. Zum Ändern klicken.',
            'badge_tooltip_post'     => 'Für Ihre PLZ ist Postversand verfügbar. Einige Frischeprodukte sind bei Versand nicht erhältlich und werden Ihnen nicht angezeigt. Zum Ändern bitte klicken.',
            'badge_tooltip_skipped'  => 'Noch keine Lieferoption gewählt – klicken Sie hier, um Ihre PLZ einzugeben und die passenden Produkte zu sehen.',
        ] );

        return $this->settings_cache;
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

        set_transient( self::CACHE, $codes, 12 * HOUR_IN_SECONDS );
        return $codes;
    }

    public function is_local( string $plz ): bool {
        $plz = preg_replace( '/\D/', '', $plz );
        if ( strlen( $plz ) !== 5 ) {
            return false;
        }

        $plz_int = (int) $plz;

        foreach ( $this->get_local_postcodes() as $pattern ) {
            $pattern = trim( (string) $pattern );
            if ( $pattern === '' ) {
                continue;
            }

            $separator = str_contains( $pattern, '...' ) ? '...' : ( str_contains( $pattern, '-' ) ? '-' : '' );
            if ( $separator !== '' ) {
                [ $from, $to ] = array_map( 'trim', explode( $separator, $pattern, 2 ) );
                if ( ctype_digit( $from ) && ctype_digit( $to )
                     && strlen( $from ) === 5 && strlen( $to ) === 5
                     && $plz_int >= (int) $from && $plz_int <= (int) $to ) {
                    return true;
                }
                continue;
            }

            if ( str_ends_with( $pattern, '*' ) ) {
                $prefix = rtrim( $pattern, '*' );
                if ( $prefix !== '' && ctype_digit( $prefix ) && str_starts_with( $plz, $prefix ) ) {
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

    /**
     * Liefert die Slugs der ausgeschlossenen Versandklassen.
     * Slugs statt term_ids, weil term_id ≠ term_taxonomy_id je nach DB-Historie.
     */
    private function get_excluded_slugs(): array {
        $settings     = $this->get_settings();
        $excluded_ids = array_filter( array_map( 'intval', (array) $settings['excluded_classes'] ) );
        if ( empty( $excluded_ids ) ) {
            return [];
        }

        $slugs = get_terms( [
            'taxonomy'   => 'product_shipping_class',
            'include'    => $excluded_ids,
            'fields'     => 'slugs',
            'hide_empty' => false,
        ] );

        return ( ! is_wp_error( $slugs ) && is_array( $slugs ) ) ? $slugs : [];
    }

    /**
     * Liefert alle Produkt-IDs deren Versandklasse ausgeschlossen ist.
     * Transient-cached (12h); invalidiert bei Settings-Save oder Term-Zuweisung.
     */
    private function get_hidden_product_slugs(): array {
        return array_values( array_filter( array_map( function( int $id ): ?string {
            $slug = get_post_field( 'post_name', $id );
            return ( is_string( $slug ) && $slug !== '' ) ? $slug : null;
        }, $this->get_hidden_product_ids() ) ) );
    }

    public function get_hidden_product_ids(): array {
        $settings     = $this->get_settings();
        $excluded_ids = array_filter( array_map( 'intval', (array) $settings['excluded_classes'] ) );
        if ( empty( $excluded_ids ) ) {
            return [];
        }

        sort( $excluded_ids );
        $version   = (int) get_option( self::HIDDEN_VERSION, 1 );
        $cache_key = 'wc_plz_hidden_v' . $version . '_' . md5( implode( ',', $excluded_ids ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $excluded_slugs = $this->get_excluded_slugs();
        if ( empty( $excluded_slugs ) ) {
            set_transient( $cache_key, [], 12 * HOUR_IN_SECONDS );
            return [];
        }

        global $wpdb;
        $placeholders = implode( ', ', array_fill( 0, count( $excluded_slugs ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $hidden_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = 'product_shipping_class'
                 AND t.slug IN ($placeholders)",
                ...$excluded_slugs
            )
        );

        $hidden_ids = array_map( 'intval', (array) $hidden_ids );
        set_transient( $cache_key, $hidden_ids, 12 * HOUR_IN_SECONDS );
        return $hidden_ids;
    }

    /**
     * Bump der Hidden-IDs-Version → alle existierenden Transients (mit alter Version
     * im Key) werden ignoriert und verfallen via TTL. Kein wp_options-LIKE-Scan.
     */
    public function invalidate_hidden_ids_cache(): void {
        $current = (int) get_option( self::HIDDEN_VERSION, 1 );
        update_option( self::HIDDEN_VERSION, $current + 1, false );
    }

    /**
     * Hook für set_object_terms — invalidiert bei product_shipping_class Änderungen.
     */
    public function maybe_invalidate_hidden_ids( $object_id, $terms, $tt_ids, $taxonomy ): void {
        if ( $taxonomy === 'product_shipping_class' ) {
            $this->invalidate_hidden_ids_cache();
        }
    }

    public function filter_products( \WP_Query $q ): void {
        if ( is_admin() ) {
            return;
        }

        $post_type = $q->get( 'post_type' );
        $is_product = ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) );
        $is_valid = ( $q->is_main_query() || $q->get( 'wc_query' ) === 'product_query' || $is_product );

        if ( ! $is_valid ) {
            return;
        }

        $state = $this->get_state();
        if ( empty( $state['mode'] ) || $state['mode'] !== 'post' ) {
            return;
        }

        $excluded_slugs = $this->get_excluded_slugs();
        if ( empty( $excluded_slugs ) ) {
            return;
        }

        $tax   = (array) $q->get( 'tax_query' );
        $tax[] = [
            'relation' => 'OR',
            [
                'taxonomy' => 'product_shipping_class',
                'operator' => 'NOT EXISTS',
            ],
            [
                'taxonomy' => 'product_shipping_class',
                'field'    => 'slug',
                'terms'    => $excluded_slugs,
                'operator' => 'NOT IN',
            ],
        ];
        $q->set( 'tax_query', $tax );
    }

    /* --- fgf-Hardening: Server-Side Enforcement --- */

    /**
     * Cart-Validation: Postversand-Modus + Produkt mit ausgeschlossener Klasse → Notice.
     */
    public function remove_excluded_cart_items(): void {
        $state = $this->get_state();
        if ( $state['mode'] !== 'post' ) {
            return;
        }

        $hidden_ids = $this->get_hidden_product_ids();
        if ( empty( $hidden_ids ) ) {
            return;
        }

        $hidden_lookup = array_flip( $hidden_ids );
        $removed       = [];

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( isset( $hidden_lookup[ (int) $item['product_id'] ] ) ) {
                $removed[] = $item['data']->get_name();
                WC()->cart->remove_cart_item( $key );
            }
        }

        if ( ! empty( $removed ) ) {
            wc_add_notice(
                sprintf(
                    'Folgende Produkte sind im Postversand nicht verfügbar und wurden entfernt: %s.',
                    implode( ', ', array_map( 'esc_html', $removed ) )
                ),
                'notice'
            );
        }
    }

    public function validate_cart_items(): void {
        $state = $this->get_state();
        if ( $state['mode'] !== 'post' ) {
            return;
        }

        $hidden_ids = $this->get_hidden_product_ids();
        if ( empty( $hidden_ids ) ) {
            return;
        }

        $hidden_lookup = array_flip( $hidden_ids );

        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = (int) $item['product_id'];
            if ( isset( $hidden_lookup[ $product_id ] ) ) {
                $product = $item['data'];
                wc_add_notice(
                    sprintf(
                        '%s ist im Postversand nicht verfügbar. Bitte aus dem Warenkorb entfernen oder die Lieferart ändern.',
                        esc_html( $product->get_name() )
                    ),
                    'error'
                );
            }
        }

        if ( ! is_checkout() ) {
        $settings = $this->get_settings();
        $min      = (int) ( $state['mode'] === 'local' ? $settings['min_order_local'] : $settings['min_order_post'] );
        if ( $min > 0 ) {
            $subtotal = (float) WC()->cart->get_subtotal();
            if ( $subtotal < $min ) {
                $label = $state['mode'] === 'local' ? 'Lokallieferung' : 'Postversand';
                wc_add_notice(
                    sprintf(
                        'Für %s gilt ein Mindestbestellwert von %s. Es fehlen noch %s.',
                        $label,
                        wc_price( $min ),
                        wc_price( $min - $subtotal )
                    ),
                    'notice'
                );
            }
        }
        } // end ! is_checkout()
    }

    /**
     * Single-Product-Page: blockiert Add-to-Cart wenn Produkt ausgeschlossen ist.
     */
    public function checkout_min_order_notice(): void {
        $state = $this->get_state();
        if ( ! in_array( $state['mode'], [ 'local', 'post' ], true ) ) {
            return;
        }
        $settings = $this->get_settings();
        $min      = (int) ( $state['mode'] === 'local' ? $settings['min_order_local'] : $settings['min_order_post'] );
        if ( $min <= 0 ) {
            return;
        }
        $subtotal = (float) WC()->cart->get_subtotal();
        if ( $subtotal < $min ) {
            $label = $state['mode'] === 'local' ? 'Lokallieferung' : 'Postversand';
            wc_print_notice(
                sprintf(
                    'Für %s gilt ein Mindestbestellwert von %s. Es fehlen noch %s.',
                    $label,
                    wc_price( $min ),
                    wc_price( $min - $subtotal )
                ),
                'notice'
            );
        }
    }

    public function redirect_excluded_single(): void {
        if ( ! is_product() ) {
            return;
        }
        $state = $this->get_state();
        if ( $state['mode'] !== 'post' ) {
            return;
        }

        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $hidden_ids = $this->get_hidden_product_ids();
        if ( ! in_array( (int) $product->get_id(), $hidden_ids, true ) ) {
            return;
        }

        $back = add_query_arg( 'plz_blocked', '1', wp_get_referer() ?: home_url( '/' ) );
        wp_safe_redirect( $back );
        exit;
    }

    public function maybe_show_blocked_alert(): void {
        if ( empty( $_GET['plz_blocked'] ) ) {
            return;
        }
        echo '<script>alert(' . wp_json_encode( __( 'Dieses Produkt ist im Postversand nicht verfügbar. Bitte wählen Sie eine andere Lieferart oder ändern Sie Ihre PLZ.', 'wc-plz-filter' ) ) . ');</script>';
    }

    /* --- Checkout Prefill --- */

    public function prefill_checkout( $value, string $input ) {
        if ( $input !== 'billing_postcode' || ! empty( $value ) ) {
            return $value;
        }
        $state = $this->get_state();
        return ! empty( $state['plz'] ) ? $state['plz'] : $value;
    }

    /* --- AJAX: PLZ prüfen --- */

    public function ajax_check(): void {
        check_ajax_referer( 'wc_plz_nonce', 'nonce' );

        $plz = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) ) );

        if ( strlen( $plz ) !== 5 ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige 5-stellige PLZ eingeben.' ] );
        }

        $local    = $this->is_local( $plz );
        $settings = $this->get_settings();

        $mode     = $local ? 'local' : 'post';

        wp_send_json_success( [
            'plz'        => $plz,
            'is_local'   => $local,
            'mode'       => $mode,
            'message'    => $local
                ? 'Wir liefern in Ihre PLZ ' . $plz . '! Alle Produkte verfügbar.'
                : $settings['post_msg'],
            'hidden_ids' => ( $mode === 'post' ) ? $this->get_hidden_product_ids() : [],
        ] );
    }

    /* --- AJAX: Hidden Product IDs (cache-friendly, browser-cacheable) --- */

    public function ajax_hidden_ids(): void {
        // Kein Nonce-Check: Liste ist nicht sensitiv (gleiche Info via Shop-Source einsehbar),
        // aber Antwort hängt nur von Settings ab → identisch für alle User → Browser-/Edge-Cache OK.
        // WP setzt via send_headers() / nocache_headers() früher Pragma + Expires + Cache-Control.
        // Wir strippen die alle und setzen Cache-Control sauber neu, sonst bleiben konfliktäre Header.
        header_remove( 'Pragma' );
        header_remove( 'Expires' );
        header_remove( 'Cache-Control' );
        header( 'Cache-Control: public, max-age=300' );

        wp_send_json_success( [
            'ids'   => $this->get_hidden_product_ids(),
            'slugs' => $this->get_hidden_product_slugs(),
        ] );
    }

    /* --- REST: Frischer Nonce (Cache-Bypass für WP Rocket & Co.) --- */

    /**
     * Page-Caches (WP Rocket, Cloudflare APO, …) backen den Nonce in HTML mit ein.
     * Nach > 12 h läuft der Nonce-Tick ab und alle AJAX-Calls scheitern mit "-1".
     * Dieser Endpoint liefert immer einen frischen Nonce, no-cache, anonym.
     */
    public function register_rest_routes(): void {
        register_rest_route( 'wc-plz/v1', '/nonce', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                $response = new \WP_REST_Response( [
                    'nonce' => wp_create_nonce( 'wc_plz_nonce' ),
                ] );
                $response->header( 'Cache-Control', 'no-store, max-age=0' );
                return $response;
            },
        ] );
    }

    /* --- AJAX: Modus speichern --- */

    public function ajax_save(): void {
        check_ajax_referer( 'wc_plz_nonce', 'nonce' );

        $mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) );
        $plz  = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) ) );

        if ( ! in_array( $mode, [ 'abholung', 'local', 'post', 'skipped' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Modus.' ] );
        }

        $settings = $this->get_settings();
        $days     = max( 1, (int) $settings['cookie_days'] );

        $this->set_cookie( $mode . ':' . $plz, $days );

        // Sync PLZ into WooCommerce customer session (cart & checkout)
        $wc_plz = in_array( $mode, [ 'abholung', 'skipped' ], true ) ? '' : $plz;
        if ( function_exists( 'WC' ) && WC()->customer ) {
            WC()->customer->set_billing_postcode( $wc_plz );
            WC()->customer->set_shipping_postcode( $wc_plz );
            WC()->customer->save();
        }

        if ( $this->stats ) {
            $this->stats->log_event( $wc_plz, $mode );
        }

        wp_send_json_success( [ 
            'mode' => $mode, 
            'plz'  => $plz,
            'hidden_ids' => ( $mode === 'post' ) ? $this->get_hidden_product_ids() : [],
        ] );
    }

    /* --- Frontend: Scripts & Styles --- */

    public function enqueue(): void {
        if ( is_admin() ) {
            return;
        }

        $url      = plugin_dir_url( __FILE__ );
        $settings = $this->get_settings();

        wp_enqueue_style( 'wc-plz-filter', $url . 'assets/css/plz-popup.css', [], self::VERSION );
        wp_enqueue_script( 'wc-plz-filter', $url . 'assets/js/plz-popup.js', [], self::VERSION, [
            'in_footer' => true,
            'strategy'  => 'defer',
        ] );

        // Cache-friendly: KEINE user-spezifischen Daten (kein state, kein PLZ).
        // JS liest Cookie selbst und holt hidden_ids per AJAX (browser-cacheable).
        wp_localize_script( 'wc-plz-filter', 'wcPlz', [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( 'wc_plz_nonce' ),
            'nonceUrl'             => rest_url( 'wc-plz/v1/nonce' ),
            'version'              => self::VERSION,
            'cookieName'           => self::COOKIE,
            'cookieDays'           => (int) $settings['cookie_days'],
            'isCheckout'           => is_checkout() ? 1 : 0,
            'badgePosition'        => $settings['badge_position'],
            'badgeTooltipAbholung' => $settings['badge_tooltip_abholung'],
            'badgeTooltipLocal'    => $settings['badge_tooltip_local'],
            'badgeTooltipPost'     => $settings['badge_tooltip_post'],
            'badgeTooltipSkipped'  => $settings['badge_tooltip_skipped'],
        ] );
    }

    public function add_nowprocket_attr( string $tag, string $handle ): string {
        if ( 'wc-plz-filter' !== $handle ) {
            return $tag;
        }
        return str_replace( '<script ', '<script data-nowprocket ', $tag );
    }

    /**
     * Inline-Script im <head>: liest Cookie + localStorage synchron und injiziert
     * vor Body-Parse einen <style>-Block der ausgeschlossene Produkte versteckt.
     * Cache-friendly weil rein client-seitig (statisches HTML für alle User).
     * Verhindert FOUC für returning visitors.
     */
    public function print_head_hide_script(): void {
        if ( is_admin() ) {
            return;
        }
        ?>
        <script id="wc-plz-head-hide" data-no-optimize="1" data-no-minify="1" data-no-defer="1" data-nowprocket data-cfasync="false">
        (function(){
            try {
                var m = document.cookie.match(/(?:^|; )<?php echo esc_js( self::COOKIE ); ?>=([^;]*)/);
                if (!m) return;
                var raw = decodeURIComponent(m[1]);
                if (raw.indexOf('post:') !== 0) return;
                var ids = JSON.parse(localStorage.getItem('wc_plz_hidden_ids') || '[]');
                var slugs = JSON.parse(localStorage.getItem('wc_plz_hidden_slugs') || '[]');
                if (!ids.length && !slugs.length) return;
                // .pdb{ID} = fgf-Custom-Grid; .products .post-{ID} = WC-Standard-Loops
                // (Cross-Sells / Up-Sells / Related / Shop). Niemals body.post-{ID} oder
                // article.post-{ID} matchen, sonst verschwindet die Single-Product-Page.
                var sel = ids.map(function(id){ return '.pdb' + id + ', .products .post-' + id; }).join(',');
                if (slugs.length) {
                    var cSel = slugs.map(function(s){ return '.jet-woo-products__inner-box:has(a[href*="/' + s + '/"])'; }).join(',');
                    sel = sel ? sel + ',' + cSel : cSel;
                }
                if (!sel) return;
                var s = document.createElement('style');
                s.id = 'wc-plz-hide-style';
                s.textContent = sel + '{display:none!important}';
                (document.head || document.documentElement).appendChild(s);
            } catch (e) {}
        })();
        </script>
        <?php
    }

    /* --- Frontend: Popup HTML --- */

    public function render_popup(): void {
        if ( is_admin() ) {
            return;
        }

        $s = $this->get_settings();
        $color     = esc_attr( $s['popup_color'] );
        $badge_pos = esc_attr( $s['badge_position'] );
        $rotate    = ! empty( $s['badge_rotate'] );
        $offset_x  = (int) $s['badge_offset_x'];
        $offset_y  = (int) $s['badge_offset_y'];

        // Build badge CSS classes
        $badge_classes = 'wc-plz-badge wc-plz-badge--' . $badge_pos;
        if ( $rotate && in_array( $badge_pos, [ 'left-center', 'right-center' ], true ) ) {
            $badge_classes .= ' wc-plz-badge--rotated';
        }

        // Build inline style for offsets
        $badge_style = 'display:none;';
        if ( $offset_x !== 0 ) {
            $badge_style .= '--wc-plz-offset-x:' . $offset_x . 'px;';
        }
        if ( $offset_y !== 0 ) {
            $badge_style .= '--wc-plz-offset-y:' . $offset_y . 'px;';
        }
        ?>
        <style>:root{--wc-plz-color:<?php echo esc_html( $color ); ?>;}</style>
        <div id="wc-plz-overlay" class="wc-plz-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wc-plz-title">
            <div class="wc-plz-modal">
                <div class="wc-plz-modal__header">
                    <h2 class="wc-plz-modal__title" id="wc-plz-title"><?php echo esc_html( $s['popup_title'] ); ?></h2>
                </div>
                <div class="wc-plz-modal__body">
                    <p class="wc-plz-modal__text"><?php echo esc_html( $s['popup_text'] ); ?></p>
                    <div class="wc-plz-section">
                        <label class="wc-plz-label" for="wc-plz-input">Postleitzahl für Lieferung / Versand</label>
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
        <button id="wc-plz-badge" class="<?php echo esc_attr( $badge_classes ); ?>" style="<?php echo esc_attr( $badge_style ); ?>" aria-label="Bestellmodus ändern">
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

    public function handle_admin_reset(): void {
        if ( ! isset( $_POST['wc_plz_reset'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        check_admin_referer( 'wc_plz_reset' );

        setcookie( self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ] );


        wp_safe_redirect( add_query_arg( 'wc_plz_reset_done', '1', admin_url( 'admin.php?page=wc-plz-filter' ) ) );
        exit;
    }

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
            'badge_rotate'           => ! empty( $input['badge_rotate'] ) ? 1 : 0,
            'badge_offset_x'         => (int) ( $input['badge_offset_x'] ?? 0 ),
            'badge_offset_y'         => (int) ( $input['badge_offset_y'] ?? 0 ),
            'badge_tooltip_abholung' => sanitize_textarea_field( $input['badge_tooltip_abholung'] ?? '' ),
            'badge_tooltip_local'    => sanitize_textarea_field( $input['badge_tooltip_local'] ?? '' ),
            'badge_tooltip_post'     => sanitize_textarea_field( $input['badge_tooltip_post'] ?? '' ),
            'badge_tooltip_skipped'  => sanitize_textarea_field( $input['badge_tooltip_skipped'] ?? '' ),
            'min_order_local'        => max( 0, (int) ( $input['min_order_local'] ?? 30 ) ),
            'min_order_post'         => max( 0, (int) ( $input['min_order_post'] ?? 30 ) ),
        ];
    }

    public function render_admin(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings    = $this->get_settings();
        $classes     = WC()->shipping()->get_shipping_classes();
        $local_codes = $this->get_local_postcodes();

        $reset_done = isset( $_GET['wc_plz_reset_done'] );

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
            <?php if ( $reset_done ) : ?>
                <div class="notice notice-success is-dismissible"><p>Cookie &amp; Session wurden zurückgesetzt.</p></div>
            <?php endif; ?>
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
                        <th>Mindestbestellwert</th>
                        <td>
                            <label style="display:block;margin-bottom:6px;">
                                Lokallieferung:
                                <input type="number" name="<?php echo esc_attr( $opt ); ?>[min_order_local]" value="<?php echo esc_attr( $settings['min_order_local'] ); ?>" min="0" step="1" class="small-text" /> €
                            </label>
                            <label style="display:block;">
                                Postversand:
                                <input type="number" name="<?php echo esc_attr( $opt ); ?>[min_order_post]" value="<?php echo esc_attr( $settings['min_order_post'] ); ?>" min="0" step="1" class="small-text" /> €
                            </label>
                            <p class="description">0 = kein Mindestbestellwert. Wird im Warenkorb und beim Checkout geprüft.</p>
                        </td>
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
                    <tr>
                        <th>Badge vertikal drehen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[badge_rotate]" value="1" <?php checked( $settings['badge_rotate'], 1 ); ?> />
                                Text vertikal anzeigen (nur bei <em>Links mittig</em> / <em>Rechts mittig</em>)
                            </label>
                            <p class="description">Wenn aktiviert, wird die Badge bei seitlicher Platzierung um 90° gedreht.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Badge-Offset</th>
                        <td>
                            <label style="margin-right:16px;">
                                Horizontal: <input type="number" name="<?php echo esc_attr( $opt ); ?>[badge_offset_x]" value="<?php echo esc_attr( $settings['badge_offset_x'] ); ?>" class="small-text" style="width:70px;" /> px
                            </label>
                            <label>
                                Vertikal: <input type="number" name="<?php echo esc_attr( $opt ); ?>[badge_offset_y]" value="<?php echo esc_attr( $settings['badge_offset_y'] ); ?>" class="small-text" style="width:70px;" /> px
                            </label>
                            <p class="description">Feinkorrektur der Badge-Position. Positiv = nach rechts/unten, Negativ = nach links/oben.</p>
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
                    <tr>
                        <th>Tooltip: Kein Filter</th>
                        <td>
                            <textarea name="<?php echo esc_attr( $opt ); ?>[badge_tooltip_skipped]" rows="2" class="large-text"><?php echo esc_textarea( $settings['badge_tooltip_skipped'] ); ?></textarea>
                            <p class="description">Wird angezeigt, wenn der Kunde das Popup übersprungen hat.</p>
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
                    <?php submit_button( 'Prüfen', 'secondary', 'submit', false ); ?>
                </p>
            </form>
            <hr />
            <h2>Entwickler-Reset</h2>
            <p>Setzt den gespeicherten Auswahlstatus (Cookie &amp; WooCommerce-Session) für Ihren Browser zurück, sodass das Popup beim nächsten Seitenaufruf wieder erscheint. Nützlich für Tests.</p>
            <form method="post">
                <?php wp_nonce_field( 'wc_plz_reset' ); ?>
                <input type="hidden" name="wc_plz_reset" value="1" />
                <?php submit_button( 'Cookie &amp; Session zurücksetzen', 'delete', 'submit', false ); ?>
            </form>
            <?php if ( $this->stats ) : ?>
                <?php $this->stats->render_admin_section(); ?>
            <?php endif; ?>
            <?php if ( $this->updater ) : ?>
                <?php $this->updater->render_admin_section(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

WC_PLZ_Filter::instance();

// Lifecycle hooks.
register_activation_hook( __FILE__, function () {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-plz-stats.php';
    WC_PLZ_Stats::activate();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wc_plz_stats_cleanup' );
} );
