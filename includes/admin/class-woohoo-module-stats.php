<?php
defined( 'ABSPATH' ) || exit;

final class Woohoo_Module_Stats implements Woohoo_Module_Interface {

    private WC_PLZ_Stats $stats;

    public function __construct( WC_PLZ_Stats $stats ) {
        $this->stats = $stats;
    }

    public function get_tab_slug(): string {
        return 'stats';
    }

    public function get_tab_label(): string {
        return 'Statistik';
    }

    public function is_visible(): bool {
        return true;
    }

    public function render_tab(): void {
        $this->stats->render_admin_section();
    }
}
