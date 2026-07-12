<?php
namespace SmrtShield;

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LogsListTable extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Log', 'smrt-shield' ),
            'plural'   => __( 'Logs', 'smrt-shield' ),
            'ajax'     => false
        ] );
    }

    public function get_columns() {
        return [
            'cb'             => '<input type="checkbox" />',
            'time'           => __( 'Zeitpunkt', 'smrt-shield' ),
            'form_id'        => __( 'Formular ID / Name', 'smrt-shield' ),
            'block_reason'   => __( 'Grund', 'smrt-shield' ),
            'honeypot_value' => __( 'Honeypot-Wert', 'smrt-shield' ),
            'user_agent'     => __( 'User-Agent', 'smrt-shield' ),
        ];
    }

    public function get_sortable_columns() {
        return [
            'time' => [ 'time', false ],
        ];
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'time':
                return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['time'] ) ) );
            case 'form_id':
            case 'block_reason':
            case 'honeypot_value':
            case 'user_agent':
                return esc_html( $item[ $column_name ] );
            default:
                return print_r( $item, true );
        }
    }

    protected function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="log[]" value="%s" />',
            esc_attr( $item['id'] )
        );
    }

    public function get_bulk_actions() {
        return [
            'delete' => __( 'Löschen', 'smrt-shield' ),
        ];
    }

    public function process_bulk_action() {
        if ( 'delete' === $this->current_action() ) {
            $log_ids = isset( $_REQUEST['log'] ) ? wp_unslash( $_REQUEST['log'] ) : [];
            
            if ( ! empty( $log_ids ) && is_array( $log_ids ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'smrt_shield_logs';
                
                // Sanitize IDs
                $log_ids = array_map( 'intval', $log_ids );
                $ids     = implode( ',', $log_ids );
                
                // Delete query
                $wpdb->query( "DELETE FROM $table_name WHERE id IN($ids)" );
            }
        }
    }

    public function prepare_items() {
        global $wpdb;

        // Handle bulk actions (like delete) before querying data
        $this->process_bulk_action();

        $table_name = $wpdb->prefix . 'smrt_shield_logs';

        // Check if table exists to prevent crash if not activated properly
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            $this->items = [];
            return;
        }

        $per_page = 20;
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [ $columns, $hidden, $sortable ];

        // Secure ordering
        $valid_order_columns = ['time'];
        $orderby = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $valid_order_columns, true ) ? $_REQUEST['orderby'] : 'time';
        $order   = isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'asc' ? 'ASC' : 'DESC';

        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );
        
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }
}
