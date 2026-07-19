<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single WooCommerce submenu entry ("DT Woohoo") that hosts every module
 * behind a tab. Modules register themselves; this class only knows the
 * Woohoo_Module_Interface contract, never a concrete module class.
 */
final class Woohoo_Admin_Page {

    use WC_PLZ_Singleton;

    const PAGE_SLUG = 'woohoo';

    /** @var array<string, Woohoo_Module_Interface> */
    private array $modules = [];

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_module( Woohoo_Module_Interface $module ): void {
        $this->modules[ $module->get_tab_slug() ] = $module;
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            'DT Woohoo',
            'DT Woohoo',
            WC_PLZ_Filter::MANAGE_CAP,
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Builds an admin.php?page=woohoo&tab=... URL. Used by module redirect
     * handlers (settings saved, action performed, ...) so they land back on
     * their own tab instead of a stale standalone page.
     */
    public static function tab_url( string $tab, array $extra_args = [] ): string {
        return add_query_arg(
            array_merge( [ 'page' => self::PAGE_SLUG, 'tab' => $tab ], $extra_args ),
            admin_url( 'admin.php' )
        );
    }

    private function get_active_tab(): string {
        $requested = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
        if ( isset( $this->modules[ $requested ] ) ) {
            return $requested;
        }
        return (string) array_key_first( $this->modules );
    }

    public function render_page(): void {
        if ( ! current_user_can( WC_PLZ_Filter::MANAGE_CAP ) ) {
            return;
        }

        $active = $this->get_active_tab();
        ?>
        <div class="wrap">
            <h1>DT Woohoo</h1>
            <?php if ( count( $this->modules ) > 1 ) : ?>
            <h2 class="nav-tab-wrapper">
                <?php foreach ( $this->modules as $slug => $module ) : ?>
                    <a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
                       class="nav-tab <?php echo esc_attr( $slug === $active ? 'nav-tab-active' : '' ); ?>">
                        <?php echo esc_html( $module->get_tab_label() ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            <?php endif; ?>
            <div class="woohoo-tab-content" style="margin-top:16px;">
                <?php if ( isset( $this->modules[ $active ] ) ) : ?>
                    <?php $this->modules[ $active ]->render_tab(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
