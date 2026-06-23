<?php
defined( 'ABSPATH' ) || exit;

trait WC_PLZ_Singleton {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }
}
