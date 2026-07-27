<?php
defined( 'ABSPATH' ) || exit;

final class Woohoo_Module_Mailer implements Woohoo_Module_Interface {

    private Woohoo_Mailer $mailer;

    public function __construct( Woohoo_Mailer $mailer ) {
        $this->mailer = $mailer;
    }

    public function get_tab_slug(): string {
        return 'mail-log';
    }

    public function get_tab_label(): string {
        return 'Mail-Log';
    }

    public function render_tab(): void {
        $this->mailer->render_tab();
    }
}
