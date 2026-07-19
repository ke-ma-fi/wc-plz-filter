<?php
defined( 'ABSPATH' ) || exit;

final class Woohoo_Module_Delivery implements Woohoo_Module_Interface {

    private WC_PLZ_Filter $filter;

    public function __construct( WC_PLZ_Filter $filter ) {
        $this->filter = $filter;
    }

    public function get_tab_slug(): string {
        return 'delivery';
    }

    public function get_tab_label(): string {
        return 'Liefermodus';
    }

    public function render_tab(): void {
        $this->filter->render_delivery_tab();
    }
}
