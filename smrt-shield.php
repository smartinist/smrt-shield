<?php
/**
 * Plugin Name: Smrt-Shield
 * Description: Unsichtbarer, hochgradig effektiver Spam-Schutz für Elementor Pro Formulare.
 * Version: 1.0.0
 * Author: SMARTini
 * Text Domain: smrt-shield
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'SMRT_SHIELD_VERSION', '1.0.0' );
define( 'SMRT_SHIELD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMRT_SHIELD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for the SmrtShield namespace
spl_autoload_register( function ( $class_name ) {
    if ( strpos( $class_name, 'SmrtShield\\' ) !== 0 ) {
        return;
    }

    $class_path = str_replace( 'SmrtShield\\', '', $class_name );
    $class_path = str_replace( '\\', DIRECTORY_SEPARATOR, $class_path );
    $file = SMRT_SHIELD_PLUGIN_DIR . 'includes/' . $class_path . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Activation hook
 */
register_activation_hook( __FILE__, function() {
    \SmrtShield\Activator::activate();
} );

/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', function() {
    // Wait for Elementor to initialize before hooking into it.
    add_action( 'elementor/init', function() {
        \SmrtShield\ElementorIntegration::get_instance();
    } );

    // Initialize the Admin Page for logs if in the backend
    if ( is_admin() ) {
        new \SmrtShield\AdminPage();
    }
} );
