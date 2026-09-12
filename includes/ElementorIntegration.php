<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ElementorIntegration {

    private static $instance = null;

    private const MATH_DISABLE_SETTING = 'smrt_shield_disable_math_challenge';
    private const MATH_ANSWER_FIELD    = 'smrt_shield_math_answer';
    private const MATH_TOKEN_FIELD     = 'smrt_shield_math_token';
    private const MATH_HASH_FIELD      = 'smrt_shield_math_hash';
    private const MATH_PROOF_FIELD     = 'smrt_shield_math_proof';
    private const TIME_PROOF_FIELD     = 'smrt_shield_timestamp_proof';
    private const MIN_FILL_SECONDS     = 3;
    private const PROOF_LIFETIME       = 7200;
    private const MAX_FORM_FIELDS      = 100;
    private const MAX_FIELD_BYTES      = 20000;
    private const MAX_TOTAL_BYTES      = 100000;

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

        // Refresh cached challenges locally on each browser page load.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_script' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_filter( 'script_loader_tag', [ $this, 'mark_frontend_script_as_critical' ], 10, 3 );

        $this->maybe_clear_elementor_cache_after_update();
    }

    /**
     * Loads the tiny challenge refresher on public pages. The script only acts
     * when it finds a Smrt-Shield challenge in the DOM.
     */
    public function enqueue_frontend_script() {
        wp_enqueue_script(
            'smrt-shield-math-challenge',
            SMRT_SHIELD_PLUGIN_URL . 'assets/js/math-challenge.js',
            [],
            SMRT_SHIELD_VERSION,
            true
        );
    }

    /**
     * Prevents optimization plugins from delaying the time-critical refresher.
     * WP Rocket recognizes data-nowprocket; data-no-optimize is understood by
     * several other WordPress optimization plugins.
     *
     * @param string $tag    Script HTML.
     * @param string $handle Registered script handle.
     * @param string $src    Script URL.
     * @return string
     */
    public function mark_frontend_script_as_critical( $tag, $handle, $src ) {
        if ( 'smrt-shield-math-challenge' !== $handle || false !== strpos( $tag, 'data-nowprocket' ) ) {
            return $tag;
        }

        return str_replace( '<script ', '<script data-nowprocket data-no-optimize="1" ', $tag );
    }

    /**
     * Registers the public, same-origin endpoint for fresh signed challenges.
     */
    public function register_rest_routes() {
        register_rest_route(
            'smrt-shield/v1',
            '/challenge',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_rest_math_challenge' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Returns a fresh challenge. This endpoint intentionally accepts anonymous
     * requests because protected forms are public as well.
     *
     * @param \WP_REST_Request $request Request data.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_rest_math_challenge( $request ) {
        $form_id = sanitize_key( (string) $request->get_param( 'form_id' ) );

        if ( '' === $form_id || strlen( $form_id ) > 80 ) {
            return new \WP_Error( 'smrt_shield_invalid_form', __( 'Ungültiges Formular.', 'smrt-shield' ), [ 'status' => 400 ] );
        }

        $response = new \WP_REST_Response( $this->create_math_challenge_data( $form_id ), 200 );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );

        return $response;
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

        $widget->add_control(
            self::MATH_DISABLE_SETTING,
            [
                'label'        => esc_html__( 'Rechenaufgabe deaktivieren', 'smrt-shield' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => esc_html__( 'Ja', 'smrt-shield' ),
                'label_off'    => esc_html__( 'Nein', 'smrt-shield' ),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => esc_html__( 'Deaktiviert nur die sichtbare Rechenaufgabe. Alle anderen Smrt-Shield-Prüfungen bleiben aktiv.', 'smrt-shield' ),
                'condition'    => [
                    'smrt_shield_enable' => 'yes',
                ],
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

        $instance    = sanitize_key( $widget->get_id() );
        $timestamp   = (string) time();
        $hash        = wp_hash( $timestamp . '|' . $instance, 'nonce' );
        $honeypot_id = 'smrt-organization-url-' . $instance;

        // CSS to hide the fields from human users but keep them accessible for bots
        $hidden_html = '<div style="position:absolute; left:-9999px; opacity:0; z-index:-1;" aria-hidden="true" data-smrt-shield-form data-challenge-url="' . esc_url( rest_url( 'smrt-shield/v1/challenge' ) ) . '" data-form-id="' . esc_attr( $instance ) . '">';
        
        // Honeypot Field - Named deceptively
        $hidden_html .= '<label for="' . esc_attr( $honeypot_id ) . '">' . esc_html__( 'Organization URL', 'smrt-shield' ) . '</label>';
        $hidden_html .= '<input type="text" name="smrt_organization_url" id="' . esc_attr( $honeypot_id ) . '" tabindex="-1" autocomplete="off">';
        
        // Timestamp Fields
        $hidden_html .= '<input type="hidden" name="smrt_timestamp" value="' . esc_attr( $timestamp ) . '" data-smrt-shield-timestamp>';
        $hidden_html .= '<input type="hidden" name="smrt_timestamp_hash" value="' . esc_attr( $hash ) . '" data-smrt-shield-timestamp-hash>';
        $hidden_html .= '<input type="hidden" name="' . esc_attr( self::TIME_PROOF_FIELD ) . '[' . esc_attr( $this->encode_proof( [ $timestamp, $hash, $instance ] ) ) . ']" value="1" data-smrt-shield-timestamp-proof>';
        
        $hidden_html .= '</div>';

        $math_html = '';

        // Missing settings belong to forms created before 2.0 and intentionally
        // mean "enabled". Only an explicit opt-out disables the challenge.
        if ( empty( $settings[ self::MATH_DISABLE_SETTING ] ) || 'yes' !== $settings[ self::MATH_DISABLE_SETTING ] ) {
            $math_html = $this->render_math_challenge( $instance );
        }

        if ( '' !== $math_html ) {
            $content = $this->inject_before_submit_button( $content, $math_html );
        }

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
        $form_instance = sanitize_key( (string) $form_id_setting );

        // 1. Reject unusually large or deeply populated form payloads before
        // applying more expensive content checks. File contents are not part
        // of Elementor's processed field values and are therefore unaffected.
        if ( ! $this->is_submission_size_valid( $record ) ) {
            $this->block_spam( $ajax_handler, $form_id, 'Formularinhalt zu groß', '' );
            return;
        }

        // 2. Honeypot Validation
        $honeypot = $this->get_posted_string( 'smrt_organization_url' );

        if ( ! empty( $honeypot ) ) {
            $this->block_spam( $ajax_handler, $form_id, 'Honeypot ausgelöst', $honeypot );
            return;
        }

        // 3. Timestamp Validation
        $timestamp = $this->get_posted_string( 'smrt_timestamp' );
        $hash      = $this->get_posted_string( 'smrt_timestamp_hash' );

        if ( '' === $timestamp || '' === $hash ) {
            $proof = $this->get_posted_proof( self::TIME_PROOF_FIELD, 3 );

            if ( 3 === count( $proof ) ) {
                [ $timestamp, $hash, $proof_form_id ] = $proof;

                if ( hash_equals( $form_instance, $proof_form_id ) ) {
                    $form_hash_input = $timestamp . '|' . $form_instance;
                } else {
                    $form_hash_input = '';
                }
            }
        }

        if ( ! isset( $form_hash_input ) ) {
            $form_hash_input = $timestamp . '|' . $form_instance;
        }

        if (
            '' === $form_instance ||
            empty( $timestamp ) ||
            empty( $hash ) ||
            ! preg_match( '/^\d{10}$/', $timestamp ) ||
            ! hash_equals( wp_hash( $form_hash_input, 'nonce' ), $hash )
        ) {
            $this->block_spam( $ajax_handler, $form_id, 'Manipulierter/Fehlender Timestamp', '' );
            return;
        }

        $time_diff = time() - (int) $timestamp;

        if ( $time_diff < self::MIN_FILL_SECONDS ) {
            $this->block_spam( $ajax_handler, $form_id, 'Zu schnell ausgefüllt (< 3s)', '' );
            return;
        }

        if ( $time_diff > self::PROOF_LIFETIME ) {
            $this->block_spam( $ajax_handler, $form_id, 'Formular abgelaufen', '' );
            return;
        }

        // 4. Rechenaufgabe. Existing forms have no stored setting, therefore
        // only an explicit opt-out disables this default-on protection.
        $math_disabled = $record->get_form_settings( self::MATH_DISABLE_SETTING );

        if ( 'yes' !== $math_disabled && ! $this->is_math_answer_valid( $form_instance ) ) {
            $this->block_spam( $ajax_handler, $form_id, 'Rechenaufgabe falsch beantwortet', '' );
            return;
        }

        // 5. Kombinierte Text-Validierung (Single Pass)
        $blacklist = [
            'seo optimization', 'crypto', 'bitcoin', 'viagra', 'enlargement',
            'hack your', 'million dollars', 'make money online', 'guest post'
        ];
        $blacklist = apply_filters( 'smrt_shield_blacklist', $blacklist );

        // Regex für verbotene Zeichensätze: Whitelist-Ansatz – blockiere alles,
        // was NICHT lateinisch, gemeinsam (Zahlen, Satzzeichen) oder geerbt ist.
        // Erfasst Bengali, Devanagari, Kyrillisch, Arabisch, Han, Hebräisch, Thai usw. in einem Schritt.
        $forbidden_regex = '/[^\p{Latin}\p{Common}\p{Inherited}]/u';
        
        $total_urls = 0;
        $home_url   = home_url();
        
        // Elementor stores processed fields in the $record object
        $fields = $record->get( 'fields' );

        if ( ! empty( $fields ) && is_array( $fields ) ) {
            foreach ( $fields as $field_id => $field ) {
                $value = isset( $field['value'] ) ? $field['value'] : '';
                
                if ( ! is_string( $value ) || empty( $value ) ) {
                    continue;
                }

                // b) Zeichensatz-Check (Fremdsprachen-Filter)
                if ( preg_match( $forbidden_regex, $value ) ) {
                    $this->block_spam( $ajax_handler, $form_id, 'Nicht-lateinischer Zeichensatz erkannt', $value );
                    return;
                }

                // c) Blacklist-Check
                foreach ( $blacklist as $keyword ) {
                    if ( stripos( $value, $keyword ) !== false ) {
                        $this->block_spam( $ajax_handler, $form_id, 'Blacklist-Keyword: ' . esc_html( $keyword ) . ' gefunden', $value );
                        return;
                    }
                }

                // a) URL-Check
                // Entferne die eigene home_url() und die aktuelle Seiten-URL temporär aus dem Wert
                $current_page_url  = wp_get_referer(); // Die Seite, auf der das Formular eingebettet ist
                $text_without_home = str_ireplace( $home_url, '', $value );
                if ( ! empty( $current_page_url ) ) {
                    $text_without_home = str_ireplace( $current_page_url, '', $text_without_home );
                }

                // Zähle verbliebene (externe) URLs im Text
                $url_pattern = '/\b(?:https?:\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i';
                if ( preg_match_all( $url_pattern, $text_without_home, $matches ) ) {
                    $total_urls += count( $matches[0] );
                }
            }
        }

        // Wenn irgendeine externe URL vorkommt -> blockieren
        if ( $total_urls > 0 ) {
            $this->block_spam( $ajax_handler, $form_id, 'Externe URL im Formular gefunden', '' );
            return;
        }
    }

    /**
     * Creates a signed, visible arithmetic challenge.
     *
     * @param string $instance Unique Elementor widget identifier.
     * @return string
     */
    private function render_math_challenge( $instance ) {
        $challenge = $this->create_math_challenge_data( $instance );
        $field_id  = 'smrt-math-answer-' . $instance;
        $hint_id   = $field_id . '-hint';

        $html  = '<div class="elementor-field-type-number elementor-field-group elementor-column elementor-field-group-smrt_shield_math_answer elementor-col-100" data-smrt-shield-math>';
        $html .= '<label for="' . esc_attr( $field_id ) . '" class="elementor-field-label" data-smrt-shield-question>' . esc_html( $challenge['question'] ) . '</label>';
        $html .= '<input type="number" name="' . esc_attr( self::MATH_ANSWER_FIELD ) . '" id="' . esc_attr( $field_id ) . '" class="elementor-field elementor-size-sm elementor-field-textual" inputmode="numeric" step="1" min="0" autocomplete="off" required aria-required="true" aria-describedby="' . esc_attr( $hint_id ) . '">';
        $html .= '<small id="' . esc_attr( $hint_id ) . '" class="elementor-field-description">' . esc_html__( 'Ergebnis als Zahl eingeben', 'smrt-shield' ) . '</small>';
        $html .= '<input type="hidden" name="' . esc_attr( self::MATH_TOKEN_FIELD ) . '" value="' . esc_attr( $challenge['token'] ) . '" data-smrt-shield-token>';
        $html .= '<input type="hidden" name="' . esc_attr( self::MATH_HASH_FIELD ) . '" value="' . esc_attr( $challenge['hash'] ) . '" data-smrt-shield-hash>';
        $html .= '<input type="hidden" name="' . esc_attr( self::MATH_PROOF_FIELD ) . '[' . esc_attr( $this->encode_proof( [ $challenge['token'], $challenge['hash'] ] ) ) . ']" value="1" data-smrt-shield-math-proof>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Generates the display text and signed machine-readable challenge.
     *
     * @param string $form_id Elementor widget identifier.
     * @return array<string, string>
     */
    private function create_math_challenge_data( $form_id ) {
        $first     = wp_rand( 2, 15 );
        $second    = wp_rand( 1, 15 );
        $operator  = 0 === wp_rand( 0, 1 ) ? 'plus' : 'minus';
        $issued_at = (string) time();

        if ( 'minus' === $operator && $second > $first ) {
            $temporary = $first;
            $first     = $second;
            $second    = $temporary;
        }

        $payload = wp_json_encode(
            [
                'a'     => $first,
                'b'     => $second,
                'op'    => $operator,
                'nonce' => wp_generate_password( 12, false, false ),
                'form'  => $form_id,
                'iat'   => $issued_at,
            ]
        );

        $token          = base64_encode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $signature      = wp_hash( $token, 'nonce' );
        $timestamp      = $issued_at;
        $timestamp_hash = wp_hash( $timestamp . '|' . $form_id, 'nonce' );
        $operator_label = 'plus' === $operator
            ? __( 'plus', 'smrt-shield' )
            : __( 'minus', 'smrt-shield' );
        $question       = sprintf(
            /* translators: 1: first number, 2: plus or minus, 3: second number. */
            __( 'Wie viel ist %1$d %2$s %3$d?', 'smrt-shield' ),
            $first,
            $operator_label,
            $second
        );

        return [
            'question'       => wp_strip_all_tags( $question ),
            'token'          => $token,
            'hash'           => $signature,
            'timestamp'      => $timestamp,
            'timestampHash'  => $timestamp_hash,
            'mathProof'      => $this->encode_proof( [ $token, $signature ] ),
            'timestampProof' => $this->encode_proof( [ $timestamp, $timestamp_hash, $form_id ] ),
        ];
    }

    /**
     * Places the challenge before Elementor's submit field so the visual and
     * keyboard order remains logical. Falls back to the end of the form if
     * Elementor's markup changes.
     *
     * @param string $content   Rendered form widget HTML.
     * @param string $math_html Challenge HTML.
     * @return string
     */
    private function inject_before_submit_button( $content, $math_html ) {
        $pattern = '/<div\b[^>]*class=(?:"[^"]*\belementor-field-type-submit\b[^"]*"|\'[^\']*\belementor-field-type-submit\b[^\']*\')[^>]*>/i';

        if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) && ! empty( $matches[0] ) ) {
            $submit = end( $matches[0] );
            return substr_replace( $content, $math_html, $submit[1], 0 );
        }

        return str_replace( '</form>', $math_html . '</form>', $content );
    }

    /**
     * Verifies the signed challenge and submitted integer answer.
     *
     * @param string $form_id Expected Elementor widget identifier.
     * @return bool
     */
    private function is_math_answer_valid( $form_id ) {
        $answer    = $this->get_posted_string( self::MATH_ANSWER_FIELD );
        $token     = $this->get_posted_string( self::MATH_TOKEN_FIELD );
        $signature = $this->get_posted_string( self::MATH_HASH_FIELD );

        if ( '' === $token || '' === $signature ) {
            $proof = $this->get_posted_proof( self::MATH_PROOF_FIELD, 2 );

            if ( 2 === count( $proof ) ) {
                [ $token, $signature ] = $proof;
            }
        }

        if (
            '' === $answer ||
            '' === $token ||
            '' === $signature ||
            strlen( $answer ) > 10 ||
            strlen( $token ) > 512 ||
            strlen( $signature ) > 255
        ) {
            return false;
        }

        if ( ! hash_equals( wp_hash( $token, 'nonce' ), $signature ) ) {
            return false;
        }

        $payload_json = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $payload      = false !== $payload_json ? json_decode( $payload_json, true ) : null;

        if (
            ! is_array( $payload ) ||
            ! isset( $payload['a'], $payload['b'], $payload['op'], $payload['nonce'], $payload['form'], $payload['iat'] ) ||
            ! is_int( $payload['a'] ) ||
            ! is_int( $payload['b'] ) ||
            ! is_string( $payload['nonce'] ) ||
            ! is_string( $payload['form'] ) ||
            ! is_string( $payload['iat'] ) ||
            $payload['a'] < 1 ||
            $payload['a'] > 15 ||
            $payload['b'] < 1 ||
            $payload['b'] > 15 ||
            '' === $payload['nonce'] ||
            strlen( $payload['nonce'] ) > 64 ||
            ! hash_equals( $form_id, $payload['form'] ) ||
            ! preg_match( '/^\d{10}$/', $payload['iat'] ) ||
            time() - (int) $payload['iat'] < self::MIN_FILL_SECONDS ||
            time() - (int) $payload['iat'] > self::PROOF_LIFETIME ||
            ! in_array( $payload['op'], [ 'plus', 'minus' ], true ) ||
            ! preg_match( '/^\d+$/', $answer )
        ) {
            return false;
        }

        $used_key = 'smrt_shield_used_' . substr( hash( 'sha256', $signature ), 0, 40 );

        if ( false !== get_transient( $used_key ) ) {
            return false;
        }

        // This is a denylist, not an allowlist: if a transient disappears
        // early, the form still works. A normal expiry never causes rejection.
        set_transient( $used_key, 1, self::PROOF_LIFETIME );

        $expected = 'plus' === $payload['op']
            ? $payload['a'] + $payload['b']
            : $payload['a'] - $payload['b'];

        return $expected === (int) $answer;
    }

    /**
     * Applies conservative limits to Elementor's processed form values. This
     * excludes uploaded file contents while still stopping oversized text and
     * pathological nested values.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Form record.
     * @return bool
     */
    private function is_submission_size_valid( $record ) {
        $fields = $record->get( 'fields' );

        if ( ! is_array( $fields ) || count( $fields ) > self::MAX_FORM_FIELDS ) {
            return false;
        }

        $total_bytes = 0;
        $value_count = 0;

        foreach ( $fields as $field ) {
            $value = isset( $field['value'] ) ? $field['value'] : '';

            if ( ! $this->measure_submission_value( $value, $total_bytes, $value_count, 0 ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Measures scalar values with a small nesting limit.
     *
     * @param mixed $value       Field value.
     * @param int   $total_bytes Running total.
     * @param int   $value_count Running scalar count.
     * @param int   $depth       Current nesting depth.
     * @return bool
     */
    private function measure_submission_value( $value, &$total_bytes, &$value_count, $depth ) {
        if ( $depth > 3 ) {
            return false;
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $nested_value ) {
                if ( ! $this->measure_submission_value( $nested_value, $total_bytes, $value_count, $depth + 1 ) ) {
                    return false;
                }
            }

            return true;
        }

        if ( ! is_scalar( $value ) && null !== $value ) {
            return false;
        }

        $bytes = strlen( (string) $value );
        ++$value_count;
        $total_bytes += $bytes;

        return $value_count <= self::MAX_FORM_FIELDS && $bytes <= self::MAX_FIELD_BYTES && $total_bytes <= self::MAX_TOTAL_BYTES;
    }

    /**
     * Returns a scalar POST value as an unslashed string.
     *
     * @param string $key POST field name.
     * @return string
     */
    private function get_posted_string( $key ) {
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return '';
        }

        return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * Encodes signed values into a URL-safe key. Elementor may reset hidden
     * input values in popups, but it preserves field names during submission.
     *
     * @param array<int, string> $parts Values covered by existing signatures.
     * @return string
     */
    private function encode_proof( $parts ) {
        $json = wp_json_encode( array_values( $parts ) );
        $data = base64_encode( $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

        return rtrim( strtr( $data, '+/', '-_' ), '=' );
    }

    /**
     * Reads a reset-resistant proof from the sole nested POST key.
     *
     * @param string $field          POST field name.
     * @param int    $expected_parts Exact number of encoded values.
     * @return array<int, string>
     */
    private function get_posted_proof( $field, $expected_parts ) {
        if ( ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) || 1 !== count( $_POST[ $field ] ) ) {
            return [];
        }

        $encoded = (string) array_key_first( $_POST[ $field ] );

        if ( ! preg_match( '/^[A-Za-z0-9_-]{1,1024}$/', $encoded ) ) {
            return [];
        }

        $padding = strlen( $encoded ) % 4;

        if ( 0 !== $padding ) {
            $encoded .= str_repeat( '=', 4 - $padding );
        }

        $json  = base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $parts = false !== $json ? json_decode( $json, true ) : null;

        if ( ! is_array( $parts ) || $expected_parts !== count( $parts ) ) {
            return [];
        }

        foreach ( $parts as $part ) {
            if ( ! is_string( $part ) ) {
                return [];
            }
        }

        return array_values( $parts );
    }

    /**
     * Elementor caches rendered widgets. Clear that cache once after an update
     * so existing forms immediately receive the new challenge markup.
     */
    private function maybe_clear_elementor_cache_after_update() {
        if ( SMRT_SHIELD_VERSION === get_option( 'smrt_shield_version', '' ) ) {
            return;
        }

        if (
            isset( \Elementor\Plugin::$instance->files_manager ) &&
            is_callable( [ \Elementor\Plugin::$instance->files_manager, 'clear_cache' ] )
        ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        update_option( 'smrt_shield_version', SMRT_SHIELD_VERSION );
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
