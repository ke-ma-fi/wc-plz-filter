<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merkliste (wishlist) widget: LocalStorage-based product list, a toggle
 * icon on product tiles, and a floating widget button + popover.
 *
 * Self-contained: owns its own option, its own enqueue, and its own tab
 * markup. It talks to WC_PLZ_Filter only through the wc_plz_widget_group_extra
 * action and wc_plz_nowprocket_handles filter, so the core class never needs
 * to know Merkliste exists.
 */
final class WC_PLZ_Merkliste {

    use WC_PLZ_Singleton;

    const OPTION = 'wc_plz_merkliste_enabled';

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_setting' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wc_plz_widget_group_extra', [ $this, 'render_button' ] );
        add_filter( 'wc_plz_nowprocket_handles', [ $this, 'add_nowprocket_handle' ] );
    }

    public function is_enabled(): bool {
        return (int) get_option( self::OPTION, 1 ) === 1;
    }

    /**
     * shop_manager holds MANAGE_CAP but not manage_options, so this group
     * needs its own option_page_capability_* filter or options.php falls
     * back to requiring manage_options (administrators only) to save it -
     * even though the tab itself is already gated by MANAGE_CAP. OR'd rather
     * than hard-set to MANAGE_CAP so admins lacking MANAGE_CAP (e.g. a stale
     * activation hook) aren't locked out either. Cart-Indicator adds the
     * same filter on the same hook; harmless since both just resolve to the
     * same value regardless of call count.
     */
    public function register_setting(): void {
        add_filter( 'option_page_capability_wc_plz_widgets_group', function () {
            return current_user_can( 'manage_options' ) ? 'manage_options' : WC_PLZ_Filter::MANAGE_CAP;
        } );

        register_setting( 'wc_plz_widgets_group', self::OPTION, [
            'type'              => 'boolean',
            'sanitize_callback' => fn( $value ) => ! empty( $value ) ? 1 : 0,
            'default'           => 1,
        ] );
    }

    public function enqueue(): void {
        if ( is_admin() || ! $this->is_enabled() ) {
            return;
        }

        $url = WC_PLZ_FILTER_URL;

        wp_enqueue_style( 'wc-plz-merkliste', $url . 'assets/css/merkliste.css', [ 'wc-plz-filter' ], WC_PLZ_Filter::VERSION );
        wp_enqueue_script( 'wc-plz-merkliste', $url . 'assets/js/merkliste.js', [ 'wc-plz-filter' ], WC_PLZ_Filter::VERSION, [
            'in_footer' => true,
            'strategy'  => 'defer',
        ] );
        wp_localize_script( 'wc-plz-merkliste', 'wcPlzMerkliste', [
            'storeApiUrl' => rest_url( 'wc/store/v1' ),
        ] );
    }

    /**
     * Fired inside the badge's fixed-position widget-group container
     * (see WC_PLZ_Filter::render_popup()).
     */
    public function render_button(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        ?>
        <button id="wc-plz-merkliste-btn"
                class="wc-plz-merkliste-btn"
                aria-label="Merkliste öffnen"
                type="button">
            <span class="wc-plz-merkliste-btn__icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor" width="16" height="18" aria-hidden="true"><path d="M225.8 468.2l-2.5-2.3L48.1 303.2C17.4 274.7 0 234.7 0 192.8l0-3.3c0-70.4 50-130.9 119.2-144.3c46.2-9 93.7 7.7 123.9 43.7l12.9 15.4 12.9-15.4c30.2-36 77.7-52.7 123.9-43.7C462 58.6 512 119.1 512 189.5l0 3.3c0 41.9-17.4 81.9-48.1 110.4L288.7 465.9l-2.5 2.3c-8.2 7.6-19 11.9-30.2 11.9s-22-4.3-30.2-11.9z"/></svg>
            </span>
            <span id="wc-plz-merkliste-count" class="wc-plz-merkliste-btn__count" aria-hidden="true"></span>
        </button>
        <?php
    }

    /** @param string[] $handles */
    public function add_nowprocket_handle( array $handles ): array {
        $handles[] = 'wc-plz-merkliste';
        return $handles;
    }
}
