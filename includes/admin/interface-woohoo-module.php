<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contract for a module shown as one tab on the shared "DT Woohoo" admin page.
 * New modules (mailing, commissioning, delivery-area marketing, ...) implement
 * this and register themselves via Woohoo_Admin_Page::register_module() -
 * nothing else in the codebase needs to know about them.
 */
interface Woohoo_Module_Interface {

    public function get_tab_slug(): string;

    public function get_tab_label(): string;

    /**
     * Render the tab body only - no surrounding <div class="wrap">/<h1>,
     * that chrome is owned by Woohoo_Admin_Page.
     */
    public function render_tab(): void;
}
