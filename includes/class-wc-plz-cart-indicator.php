<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cart-Indicator: green outline around product tiles when that product is
 * already in the cart. Reads the WC Store API cart client-side; no server state.
 *
 * Self-contained: owns its own option and its own enqueue/page-scoping.
 * WC_PLZ_Filter never references this class directly.
 */
final class WC_PLZ_Cart_Indicator {

    use WC_PLZ_Singleton;

    const OPTION = 'wc_plz_cart_indicator_enabled';

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_setting' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_filter( 'wc_plz_nowprocket_handles', [ $this, 'add_nowprocket_handle' ] );
    }

    public function is_enabled(): bool {
        return (int) get_option( self::OPTION, 1 ) === 1;
    }

    public function register_setting(): void {
        register_setting( 'wc_plz_widgets_group', self::OPTION, [
            'type'              => 'boolean',
            'sanitize_callback' => fn( $value ) => ! empty( $value ) ? 1 : 0,
            'default'           => 1,
        ] );
    }

    public function enqueue(): void {
        if ( is_admin() || ! $this->is_enabled() || ! $this->is_product_listing_page() ) {
            return;
        }

        $url = WC_PLZ_FILTER_URL;

        wp_enqueue_style( 'wc-plz-cart-indicator', $url . 'assets/css/cart-indicator.css', [ 'wc-plz-filter' ], WC_PLZ_Filter::VERSION );
        wp_enqueue_script( 'wc-plz-cart-indicator', $url . 'assets/js/cart-indicator.js', [ 'wc-plz-filter' ], WC_PLZ_Filter::VERSION, [
            'in_footer' => true,
            'strategy'  => 'defer',
        ] );
        wp_localize_script( 'wc-plz-cart-indicator', 'wcPlzCartIndicator', [
            'storeApiUrl' => rest_url( 'wc/store/v1' ),
        ] );
    }

    private function is_product_listing_page(): bool {
        return is_shop() || is_product_category() || is_product_tag() || is_product();
    }

    /** @param string[] $handles */
    public function add_nowprocket_handle( array $handles ): array {
        $handles[] = 'wc-plz-cart-indicator';
        return $handles;
    }
}
