<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminPage {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
    }

    /**
     * Adds the admin menu item.
     */
    public function add_plugin_page() {
        add_menu_page(
            __( 'Smrt-Shield Logs', 'smrt-shield' ), // Page title
            __( 'Smrt-Shield', 'smrt-shield' ),      // Menu title
            'manage_options',                        // Capability required
            'smrt-shield-logs',                      // Menu slug
            [ $this, 'create_admin_page' ],          // Output function
            'dashicons-shield',                      // Icon (using Dashicons shield)
            80                                       // Position in menu
        );
    }

    /**
     * Renders the admin page with the WP_List_Table.
     */
    public function create_admin_page() {
        // Initialize the table class
        $list_table = new LogsListTable();
        $list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Smrt-Shield: Blockierte Spam-Versuche', 'smrt-shield' ); ?></h1>
            <hr class="wp-header-end">
            
            <p><?php esc_html_e( 'Hier siehst du alle blockierten Spam-Versuche deiner Elementor Formulare. Es werden keine IP-Adressen oder persönliche Daten der Ausfüller gespeichert.', 'smrt-shield' ); ?></p>
            
            <form id="smrt-shield-logs-filter" method="get">
                <!-- Keep the page parameter when submitting bulk actions -->
                <input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }
}
