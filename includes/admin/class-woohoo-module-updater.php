<?php
defined( 'ABSPATH' ) || exit;

final class Woohoo_Module_Updater implements Woohoo_Module_Interface {

    private WC_PLZ_Updater $updater;

    public function __construct( WC_PLZ_Updater $updater ) {
        $this->updater = $updater;
    }

    public function get_tab_slug(): string {
        return 'updater';
    }

    public function get_tab_label(): string {
        return 'Updates';
    }

    public function is_visible(): bool {
        return current_user_can( WC_PLZ_Updater::MANAGE_UPDATE_CAP );
    }

    public function render_tab(): void {
        $this->updater->render_admin_section();
    }
}
