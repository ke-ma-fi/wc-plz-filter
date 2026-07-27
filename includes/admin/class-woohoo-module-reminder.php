<?php
defined( 'ABSPATH' ) || exit;

final class Woohoo_Module_Reminder implements Woohoo_Module_Interface {

    private WC_PLZ_Reminder $reminder;

    public function __construct( WC_PLZ_Reminder $reminder ) {
        $this->reminder = $reminder;
    }

    public function get_tab_slug(): string {
        return 'reminder';
    }

    public function get_tab_label(): string {
        return 'Zahlungs-Erinnerung';
    }

    public function is_visible(): bool {
        return true;
    }

    public function render_tab(): void {
        $this->reminder->render_tab();
    }
}
