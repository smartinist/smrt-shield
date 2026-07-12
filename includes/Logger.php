<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Logger {

    /**
     * Logs a blocked spam attempt to the database.
     *
     * @param string $form_id        The ID or name of the form.
     * @param string $block_reason   The reason for blocking (e.g., Honeypot, Timestamp).
     * @param string $honeypot_value The value entered into the honeypot field (if any).
     */
    public static function log( $form_id, $block_reason, $honeypot_value = '' ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'smrt_shield_logs';
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown';

        // Check if table exists to avoid errors on some installations if dbDelta failed
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            return;
        }

        $wpdb->insert(
            $table_name,
            [
                'time'           => current_time( 'mysql' ),
                'form_id'        => sanitize_text_field( $form_id ),
                'block_reason'   => sanitize_text_field( $block_reason ),
                'user_agent'     => $user_agent,
                'honeypot_value' => sanitize_text_field( $honeypot_value ),
            ],
            [
                '%s', // time
                '%s', // form_id
                '%s', // block_reason
                '%s', // user_agent
                '%s', // honeypot_value
            ]
        );
    }
}
