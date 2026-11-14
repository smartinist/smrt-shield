<?php
namespace SmrtShield;

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Switch_Control;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adapts the Elementor 4.4 Atomic Form lifecycle to the shared checks.
 */
final class AtomicFormIntegration {
    private const MATH_DISABLE_SETTING = 'smrt-shield-disable-math';

    private $shield;
    private $buffers = [];

    public function __construct( ElementorIntegration $shield ) {
        $this->shield = $shield;

        add_filter( 'elementor/atomic-widgets/props-schema', [ $this, 'add_setting' ] );
        add_filter( 'elementor/atomic-widgets/controls', [ $this, 'add_control' ], 10, 2 );
        add_action( 'elementor/frontend/before_render', [ $this, 'start_render' ], PHP_INT_MAX );
        add_action( 'elementor/frontend/after_render', [ $this, 'finish_render' ], PHP_INT_MIN );
        add_filter( 'elementor_pro/atomic_forms/spam_check', [ $this, 'check_spam' ], 10, 4 );
    }

    public function add_setting( $schema ) {
        // Elementor's schema filter passes the schema but not the element.
        if ( isset( $schema['form-state'], $schema['actions-after-submit'] ) ) {
            $schema[ self::MATH_DISABLE_SETTING ] = Boolean_Prop_Type::make()->default( false );
        }

        return $schema;
    }

    public function add_control( $controls, $element ) {
        if ( 'e-form' === $element->get_name() ) {
            $controls[] = Section::make()
                ->set_id( 'smrt-shield' )
                ->set_label( 'Smrt-Shield' )
                ->set_items( [
                    Switch_Control::bind_to( self::MATH_DISABLE_SETTING )
                        ->set_label( __( 'Rechenaufgabe deaktivieren', 'smrt-shield' ) ),
                ] );
        }

        return $controls;
    }

    public function start_render( $element ) {
        if ( 'e-form' !== $element->get_name() ) {
            return;
        }

        ob_start();
        $this->buffers[ spl_object_id( $element ) ] = ob_get_level();
    }

    public function finish_render( $element ) {
        $key = spl_object_id( $element );
        if ( ! isset( $this->buffers[ $key ] ) ) {
            return;
        }

        $level = $this->buffers[ $key ];
        unset( $this->buffers[ $key ] );
        if ( $level !== ob_get_level() ) {
            return;
        }

        $html = ob_get_clean();
        $end = strripos( $html, '</form>' );
        if ( false !== $end ) {
            $instance = 'atomic-' . sanitize_key( $element->get_id() );
            $math_disabled = true === $element->get_atomic_setting( self::MATH_DISABLE_SETTING );
            [ $hidden, $math ] = $this->shield->render_protection( $instance, $math_disabled, true );

            if ( '' !== $math && preg_match( '/<(?:button|input)\b[^>]*\btype\s*=\s*["\']submit["\']/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
                $html = substr_replace( $html, $hidden, $end, 0 );
                $html = substr_replace( $html, $math, $match[0][1], 0 );
            } else {
                $html = substr_replace( $html, $math . $hidden, $end, 0 );
            }
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor markup plus escaped plugin markup.
    }

    public function check_spam( $is_spam, $fields, $settings, $post_id ) {
        if ( $is_spam ) {
            return $is_spam;
        }

        // Elementor already resolved the submitted form (including components)
        // in this document. Read the same ID and its server-resolved settings.
        $form_id = isset( $_POST['form_id'] ) && is_string( $_POST['form_id'] )
            ? sanitize_key( wp_unslash( $_POST['form_id'] ) )
            : '';
        $name = $settings['form-name'] ?? '';
        $values = is_array( $fields ) && count( $fields ) <= 100 ? array_column( $fields, 'value' ) : null;
        $failure = $this->shield->check_submission(
            'atomic-' . $form_id,
            true === ( $settings[ self::MATH_DISABLE_SETTING ] ?? false ),
            $values,
            $_POST
        );

        if ( ! $failure ) {
            return false;
        }

        Logger::log( is_string( $name ) && '' !== $name ? $name : $post_id . ':' . $form_id, ...$failure );
        return true;
    }
}
