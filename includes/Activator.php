<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    /**
     * Runs on plugin activation.
     */
    public static function activate() {
        self::create_logs_table();
        update_option( 'smrt_shield_version', SMRT_SHIELD_VERSION );
    }

    /**
     * Creates the custom database table for logs.
     */
    private static function create_logs_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'smrt_shield_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            form_id varchar(100) NOT NULL,
            block_reason varchar(50) NOT NULL,
            user_agent text NOT NULL,
            honeypot_value text NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}
