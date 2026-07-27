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
     * Whether this tab should appear for the current user. Modules with no
     * extra restriction beyond the shared page-level MANAGE_CAP check (see
     * Woohoo_Admin_Page::register_menu()) just return true.
     */
    public function is_visible(): bool;

    /**
     * Render the tab body only - no surrounding <div class="wrap">/<h1>,
     * that chrome is owned by Woohoo_Admin_Page.
     */
    public function render_tab(): void;
}
