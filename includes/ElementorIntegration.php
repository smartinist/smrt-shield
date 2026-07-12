<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ElementorIntegration {

    private static $instance = null;

    /**
     * Gets the singleton instance.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Register controls for the form widget under "Additional Options" (section_form_options)
        add_action( 'elementor/element/form/section_form_options/before_section_end', [ $this, 'add_form_controls' ], 10, 2 );

        // Hook into form rendering to inject hidden fields
        add_filter( 'elementor/widget/render_content', [ $this, 'render_hidden_fields' ], 10, 2 );

        // Hook into form validation to check honeypot and timestamp
        add_action( 'elementor_pro/forms/validation', [ $this, 'validate_form' ], 10, 2 );
    }

    /**
     * Adds the Smrt-Shield toggle to Elementor Form options.
     *
     * @param \Elementor\Widget_Base $widget The form widget instance.
     * @param array                  $args   Arguments.
     */
    public function add_form_controls( $widget, $args ) {
        $widget->add_control(
            'smrt_shield_enable',
            [
                'label'        => esc_html__( 'Smrt-Shield aktivieren', 'smrt-shield' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => esc_html__( 'Ja', 'smrt-shield' ),
                'label_off'    => esc_html__( 'Nein', 'smrt-shield' ),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => esc_html__( 'Aktiviert den unsichtbaren Spam-Schutz (Honeypot & Zeitmessung) für dieses Formular.', 'smrt-shield' ),
                'separator'    => 'before',
            ]
        );
    }

    /**
     * Injects the honeypot and timestamp fields into the form.
     *
     * @param string                 $content
     * @param \Elementor\Widget_Base $widget
     * @return string
     */
    public function render_hidden_fields( $content, $widget ) {
        if ( 'form' !== $widget->get_name() ) {
            return $content;
        }

        $settings = $widget->get_active_settings();

        if ( empty( $settings['smrt_shield_enable'] ) || 'yes' !== $settings['smrt_shield_enable'] ) {
            return $content;
        }

        $timestamp = time();
        $hash      = wp_hash( $timestamp, 'nonce' );

        // CSS to hide the fields from human users but keep them accessible for bots
        $hidden_html = '<div style="position:absolute; left:-9999px; opacity:0; z-index:-1;" aria-hidden="true">';
        
        // Honeypot Field - Named deceptively
        $hidden_html .= '<label for="smrt_organization_url">' . esc_html__( 'Organization URL', 'smrt-shield' ) . '</label>';
        $hidden_html .= '<input type="text" name="smrt_organization_url" id="smrt_organization_url" tabindex="-1" autocomplete="off">';
        
        // Timestamp Fields
        $hidden_html .= '<input type="hidden" name="smrt_timestamp" value="' . esc_attr( $timestamp ) . '">';
        $hidden_html .= '<input type="hidden" name="smrt_timestamp_hash" value="' . esc_attr( $hash ) . '">';
        
        $hidden_html .= '</div>';

        return str_replace( '</form>', $hidden_html . '</form>', $content );
    }

    /**
     * Validates the form submission against spam rules.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
     */
    public function validate_form( $record, $ajax_handler ) {
        $smrt_shield_enable = $record->get_form_settings( 'smrt_shield_enable' );

        if ( empty( $smrt_shield_enable ) || 'yes' !== $smrt_shield_enable ) {
            return; // Shield is disabled for this form
        }

        $form_name = $record->get_form_settings( 'form_name' );
        $form_id_setting = $record->get_form_settings( 'id' );
        $form_id = ! empty( $form_name ) ? $form_name : $form_id_setting;

        // 1. Honeypot Validation
        $honeypot = isset( $_POST['smrt_organization_url'] ) ? sanitize_text_field( wp_unslash( $_POST['smrt_organization_url'] ) ) : '';

        if ( ! empty( $honeypot ) ) {
            $this->block_spam( $ajax_handler, $form_id, 'Honeypot ausgelöst', $honeypot );
            return;
        }

        // 2. Timestamp Validation
        $timestamp = isset( $_POST['smrt_timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['smrt_timestamp'] ) ) : '';
        $hash      = isset( $_POST['smrt_timestamp_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['smrt_timestamp_hash'] ) ) : '';

        if ( empty( $timestamp ) || empty( $hash ) || ! hash_equals( wp_hash( $timestamp, 'nonce' ), $hash ) ) {
            $this->block_spam( $ajax_handler, $form_id, 'Manipulierter/Fehlender Timestamp', '' );
            return;
        }

        $time_diff = time() - (int) $timestamp;

        if ( $time_diff < 3 ) {
            $this->block_spam( $ajax_handler, $form_id, 'Zu schnell ausgefüllt (< 3s)', '' );
            return;
        }
    }

    /**
     * Blocks the submission, logs the attempt, and returns a generic error.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
     * @param string $form_id
     * @param string $reason
     * @param string $honeypot_value
     */
    private function block_spam( $ajax_handler, $form_id, $reason, $honeypot_value ) {
        // Log to database
        Logger::log( $form_id, $reason, $honeypot_value );

        // Add a generic error message to block Elementor submission
        $error_message = esc_html__( 'Ein Fehler ist aufgetreten. Bitte laden Sie die Seite neu und versuchen Sie es erneut.', 'smrt-shield' );
        
        // This adds an error to the internal errors array, preventing success actions (like emails)
        $ajax_handler->add_error( 'smrt_shield_error', $error_message );
        
        // Display generic message
        $ajax_handler->add_error_message( $error_message );
    }
}
