<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merkliste (wishlist) widget: LocalStorage-based product list, a toggle
 * icon on product tiles, and a floating widget button + popover.
 *
 * Self-contained: owns its own option, its own enqueue, and its own tab
 * markup. WC_PLZ_Filter::init() bootstraps it (require_once + instance()),
 * but beyond that one wiring point it integrates purely through the
 * wc_plz_widget_group_extra action and wc_plz_nowprocket_handles filter, so
 * the core class's own logic never needs to know Merkliste exists.
 */
final class WC_PLZ_Merkliste {

    use WC_PLZ_Singleton;

    const OPTION = 'wc_plz_merkliste_enabled';

    /**
     * Rough usage counters, "for reference" only - not a full events table
     * like WC_PLZ_Stats (no per-day history, no per-product breakdown, just
     * running totals). Deliberately a single small option rather than that
     * table's dbDelta/cron-cleanup machinery: Merkliste itself never talks
     * to the server otherwise (LocalStorage-only, see the class docblock),
     * so this is the smallest addition that still answers "is anyone using
     * this". No size cap on the option needed - the key set is fixed
     * (STATS_EVENTS) and only the counter values grow.
     */
    const STATS_OPTION = 'wc_plz_merkliste_stats';
    const STATS_EVENTS  = [ 'init', 'add', 'remove', 'popover_open' ];

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_setting' ] );
        add_action( 'admin_init', [ $this, 'handle_stats_reset' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wc_plz_widget_group_extra', [ $this, 'render_button' ] );
        add_filter( 'wc_plz_nowprocket_handles', [ $this, 'add_nowprocket_handle' ] );
        add_action( 'rest_api_init', [ $this, 'register_stats_route' ] );
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
            'statsUrl'    => rest_url( 'wc-plz/v1/merkliste-stats' ),
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

    /* ── Nutzungs-Statistik (Zähler) ─────────────── */

    public function register_stats_route(): void {
        register_rest_route( 'wc-plz/v1', '/merkliste-stats', [
            'methods'             => WP_REST_Server::CREATABLE,
            // Public and un-throttled on purpose: these are rough reference
            // counters, not sensitive data, and merkliste.js runs for
            // anonymous visitors who have no nonce to send.
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'rest_bump_stat' ],
            'args'                => [
                'event' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => fn( $value ) => in_array( $value, self::STATS_EVENTS, true ),
                ],
            ],
        ] );
    }

    public function rest_bump_stat( WP_REST_Request $request ): WP_REST_Response {
        $this->bump_stat( (string) $request->get_param( 'event' ) );
        return new WP_REST_Response( null, 204 );
    }

    /**
     * Skips staff the same way WC_PLZ_Stats::log_event() does - browsing
     * the front-end while logged into wp-admin shouldn't inflate what's
     * meant to be a read on real visitor usage.
     */
    private function bump_stat( string $event ): void {
        if ( ! in_array( $event, self::STATS_EVENTS, true ) ) {
            return;
        }
        if ( is_user_logged_in() && current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return;
        }

        $stats           = get_option( self::STATS_OPTION, [] );
        $stats[ $event ] = (int) ( $stats[ $event ] ?? 0 ) + 1;
        update_option( self::STATS_OPTION, $stats, false );
    }

    /** @return array<string,int> All STATS_EVENTS keys, zero-filled if never counted. */
    public function get_stats(): array {
        $stats = get_option( self::STATS_OPTION, [] );
        return array_merge(
            array_fill_keys( self::STATS_EVENTS, 0 ),
            array_intersect_key( $stats, array_flip( self::STATS_EVENTS ) )
        );
    }

    public function handle_stats_reset(): void {
        if ( ! isset( $_POST['wc_plz_merkliste_stats_reset'] ) ) {
            return;
        }
        if ( ! current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return;
        }
        check_admin_referer( 'wc_plz_merkliste_stats_reset' );

        delete_option( self::STATS_OPTION );

        wp_safe_redirect( add_query_arg( 'wc_plz_merkliste_stats_reset_done', '1', Woohoo_Admin_Page::tab_url( 'stats' ) ) );
        exit;
    }

    /**
     * Rendered by Woohoo_Module_Stats::render_tab() above the PLZ-Statistik
     * section - Merkliste stays the source of truth for its own numbers
     * (own option, own reset handler) the same way it owns its enqueue and
     * settings, WC_PLZ_Stats just hosts the tab both appear on.
     */
    public function render_stats_block(): void {
        $stats      = $this->get_stats();
        $reset_done = isset( $_GET['wc_plz_merkliste_stats_reset_done'] );
        // "init" is a reach baseline (fires on every page view where the
        // widget script loads, regardless of wishlist content) so the other
        // rows can be read as rates against it, not a count of visitors
        // who already have items saved.
        $labels     = [
            'init'         => 'Seitenaufrufe (Merkliste-Widget aktiv)',
            'add'          => 'Produkte hinzugefügt',
            'remove'       => 'Produkte entfernt',
            'popover_open' => 'Popover geöffnet',
        ];
        ?>
        <h2>Merkliste-Nutzung</h2>
        <?php if ( $reset_done ) : ?>
            <div class="notice notice-success is-dismissible"><p>Merkliste-Zähler wurden zurückgesetzt.</p></div>
        <?php endif; ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:500px;margin-top:8px;">
            <tbody>
                <?php foreach ( $labels as $key => $label ) : ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td><strong><?php echo number_format_i18n( $stats[ $key ] ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;">
            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Merkliste-Zähler unwiderruflich zurücksetzen?');">
                <?php wp_nonce_field( 'wc_plz_merkliste_stats_reset' ); ?>
                <input type="hidden" name="wc_plz_merkliste_stats_reset" value="1" />
                <?php submit_button( 'Zähler zurücksetzen', 'delete', 'submit', false ); ?>
            </form>
        </p>
        <hr style="margin:24px 0;" />
        <?php
    }
}
