<?php
namespace SmrtShield;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Makes public GitHub releases available through WordPress' native updater.
 */
class Updater {

    private const REPOSITORY     = 'smartinist/smrt-shield';
    private const UPDATE_URI     = 'https://github.com/smartinist/smrt-shield';
    private const RELEASE_ASSET  = 'smrt-shield.zip';
    private const CACHE_KEY      = 'smrt_shield_github_release';
    private const CACHE_LIFETIME = 6 * HOUR_IN_SECONDS;

    private static $instance = null;

    /**
     * Gets the singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Registers updater hooks.
     */
    private function __construct() {
        add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 4 );
        add_filter( 'plugins_api', [ $this, 'plugin_information' ], 20, 3 );
    }

    /**
     * Supplies update data for Smrt-Shield to WordPress.
     *
     * @param array|false $update      Existing update data.
     * @param array       $plugin_data Plugin header data.
     * @param string      $plugin_file Plugin basename.
     * @param string[]    $locales     Requested locales.
     * @return array|false
     */
    public function check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
        unset( $plugin_data, $locales );

        if ( SMRT_SHIELD_PLUGIN_BASENAME !== $plugin_file ) {
            return $update;
        }

        $release = $this->get_latest_release();

        if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
            return false;
        }

        if ( version_compare( SMRT_SHIELD_VERSION, $release['version'], '>=' ) ) {
            return false;
        }

        return [
            'id'           => self::UPDATE_URI,
            'slug'         => 'smrt-shield',
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '5.8',
            'tested'       => $release['tested'],
            'requires_php' => $release['requires_php'],
            'autoupdate'   => false,
        ];
    }

    /**
     * Adds details to WordPress' plugin information modal.
     *
     * @param false|object|array $result Existing result.
     * @param string             $action API action.
     * @param object             $args   Request arguments.
     * @return false|object|array
     */
    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'smrt-shield' !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( empty( $release['version'] ) ) {
            return $result;
        }

        return (object) [
            'name'          => 'Smrt-Shield',
            'slug'          => 'smrt-shield',
            'version'       => $release['version'],
            'author'        => '<a href="https://smartini.de">SMARTini</a>',
            'homepage'      => self::UPDATE_URI,
            'download_link' => $release['package'],
            'requires'      => '5.8',
            'requires_php'  => $release['requires_php'],
            'tested'        => $release['tested'],
            'sections'      => [
                'description' => __( 'Unsichtbarer Spam-Schutz und Rechenaufgabe für Elementor Pro Formulare.', 'smrt-shield' ),
                'changelog'   => $release['changelog'],
            ],
        ];
    }

    /**
     * Fetches and normalizes the latest public GitHub release.
     *
     * Only an explicitly uploaded smrt-shield.zip is accepted. GitHub's
     * automatically generated source archives use a changing root directory
     * and are therefore not safe plugin update packages.
     *
     * @return array<string, string>|false
     */
    private function get_latest_release() {
        $cached = get_site_transient( self::CACHE_KEY );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            [
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Smrt-Shield/' . SMRT_SHIELD_VERSION . ' (+https://github.com/smartinist/smrt-shield)',
                ],
                'limit_response_size' => 1048576,
                'timeout'             => 10,
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache failures briefly to avoid repeated requests during outages.
            set_site_transient( self::CACHE_KEY, [], HOUR_IN_SECONDS );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
            set_site_transient( self::CACHE_KEY, [], HOUR_IN_SECONDS );
            return false;
        }

        $package = '';

        foreach ( $data['assets'] as $asset ) {
            if (
                isset( $asset['name'], $asset['browser_download_url'] ) &&
                self::RELEASE_ASSET === $asset['name']
            ) {
                $candidate = esc_url_raw( $asset['browser_download_url'] );
                $scheme    = wp_parse_url( $candidate, PHP_URL_SCHEME );
                $host      = wp_parse_url( $candidate, PHP_URL_HOST );

                if ( 'https' === $scheme && 'github.com' === $host ) {
                    $package = $candidate;
                    break;
                }
            }
        }

        if ( empty( $package ) ) {
            set_site_transient( self::CACHE_KEY, [], HOUR_IN_SECONDS );
            return false;
        }

        $version = ltrim( sanitize_text_field( $data['tag_name'] ), 'vV' );

        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
            set_site_transient( self::CACHE_KEY, [], HOUR_IN_SECONDS );
            return false;
        }

        $release = [
            'version'      => $version,
            'url'          => isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : self::UPDATE_URI,
            'package'      => $package,
            'tested'       => '7.0',
            'requires_php' => '7.4',
            'changelog'    => ! empty( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
        ];

        set_site_transient( self::CACHE_KEY, $release, self::CACHE_LIFETIME );

        return $release;
    }
}
