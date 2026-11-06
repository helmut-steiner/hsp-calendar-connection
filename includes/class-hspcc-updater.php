<?php
/**
 * Public GitHub releases integrated with WordPress' native plugin updater.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/** Supplies verified release metadata to WordPress update screens. */
final class HSPCC_Updater {
	const OWNER             = 'helmut-steiner';
	const REPO              = 'hsp-calendar-connection';
	const SLUG              = 'hsp-calendar-connection';
	const RELEASE_TRANSIENT = 'hspcc_github_release';

	private const REQUIRES_WP  = '7.1.4';
	private const REQUIRES_PHP = '8.4';
	private const TESTED_WP    = '7.1.4';
	/**
	 * Installed plugin basename.
	 *
	 * @var string
	 */
	private static $plugin_file = '';

	/**
	 * Register the native WordPress update filters.
	 *
	 * @param string $plugin_file Absolute plugin entry-point path.
	 * @return void
	 */
	public static function init( $plugin_file ) {
		self::$plugin_file = plugin_basename( $plugin_file );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
	}

	/**
	 * Add or clear this plugin's offer without changing other plugin updates.
	 *
	 * @param mixed $transient WordPress plugin update transient.
	 * @return mixed Updated transient, or the original unsupported value.
	 */
	public static function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || ! is_array( $transient->checked ?? null ) ||
			! isset( $transient->checked[ self::$plugin_file ] ) ||
			! is_string( $transient->checked[ self::$plugin_file ] ) ) {
			return $transient;
		}
		$installed = $transient->checked[ self::$plugin_file ];
		$release   = self::get_latest_release();
		$version   = self::release_version( $release );
		if ( ! is_array( $transient->response ?? null ) ) {
			$transient->response = array(); }
		if ( ! is_array( $transient->no_update ?? null ) ) {
			$transient->no_update = array(); }
		$update = (object) array(
			'id'           => self::repo_url(),
			'slug'         => self::SLUG,
			'plugin'       => self::$plugin_file,
			'new_version'  => $installed,
			'url'          => self::repo_url(),
			'package'      => '',
			'requires'     => self::REQUIRES_WP,
			'requires_php' => self::REQUIRES_PHP,
			'tested'       => self::TESTED_WP,
		);
		if ( '' !== $version && version_compare( $version, $installed, '>' ) ) {
			$update->new_version                       = $version;
			$update->package                           = self::package_url( $release );
			$transient->response[ self::$plugin_file ] = $update;
			unset( $transient->no_update[ self::$plugin_file ] );
		} else {
			// Clear expired offers even when GitHub is temporarily unavailable.
			unset( $transient->response[ self::$plugin_file ] );
			$transient->no_update[ self::$plugin_file ] = $update;
		}
		return $transient;
	}

	/**
	 * Supply a plugin details response for this plugin only.
	 *
	 * @param mixed  $result Existing plugin API result.
	 * @param string $action Requested plugin API operation.
	 * @param object $args   WordPress plugin API arguments.
	 * @return mixed Details object or the original result.
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = self::get_latest_release();
		$version = self::release_version( $release );
		if ( '' === $version ) {
			return $result; }
		$body = is_string( $release['body'] ?? null ) ? $release['body'] : '';
		return (object) array(
			'name'           => 'HSP Calendar Connection',
			'slug'           => self::SLUG,
			'version'        => $version,
			'author'         => 'Helmut Steiner',
			'author_profile' => 'https://github.com/' . self::OWNER,
			'homepage'       => self::repo_url(),
			'requires'       => self::REQUIRES_WP,
			'requires_php'   => self::REQUIRES_PHP,
			'tested'         => self::TESTED_WP,
			'download_link'  => self::package_url( $release ),
			'sections'       => array(
				'description' => esc_html__( 'Private CalDAV busy-time checks and Amelia appointment writeback, with one connection per employee.', 'hsp-calendar-connection' ),
				'changelog'   => wpautop( wp_kses_post( $body ) ),
			),
		);
	}

	/**
	 * Get the fixed public upstream repository URL.
	 *
	 * @return string Repository URL.
	 */
	private static function repo_url() {
		return 'https://github.com/' . self::OWNER . '/' . self::REPO;
	}

	/**
	 * Validate stable release flags and normalize a strict version tag.
	 *
	 * @param mixed $release Decoded GitHub release metadata.
	 * @return string Numeric release version or an empty string.
	 */
	private static function release_version( $release ) {
		if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ||
			false !== ( $release['prerelease'] ?? null ) || ! is_string( $release['tag_name'] ?? null ) ||
			! preg_match( '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $release['tag_name'], $match ) ) {
			return '';
		}
		return $match[1];
	}

	/**
	 * Select the exact runtime archive belonging to this repository and tag.
	 *
	 * @param mixed $release Decoded GitHub release metadata.
	 * @return string Verified asset URL or an empty string.
	 */
	private static function package_url( $release ) {
		$version = self::release_version( $release );
		if ( '' === $version || ! is_array( $release['assets'] ?? null ) ) {
			return ''; }
		$name     = self::SLUG . '-' . $version . '.zip';
		$expected = self::repo_url() . '/releases/download/' . $release['tag_name'] . '/' . $name;
		foreach ( $release['assets'] as $asset ) {
			if ( is_array( $asset ) && ( $asset['name'] ?? '' ) === $name &&
				( $asset['state'] ?? '' ) === 'uploaded' && ( $asset['browser_download_url'] ?? '' ) === $expected ) {
				return $expected;
			}
		}
		// GitHub source archives include development files and are never install packages.
		return '';
	}

	/**
	 * Retrieve bounded public release metadata with positive and negative caching.
	 *
	 * @return array Valid release metadata or an empty array on failure.
	 */
	private static function get_latest_release() {
		$cached = get_site_transient( self::RELEASE_TRANSIENT );
		if ( is_array( $cached ) && is_array( $cached['release'] ?? null ) ) {
			$release = $cached['release'];
			if ( array() === $release || self::package_url( $release ) !== '' ) {
				return $release; }
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 262144,
				'headers'             => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$release  = array();
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && self::package_url( $decoded ) !== '' ) {
				$release = $decoded; }
		}
		set_site_transient(
			self::RELEASE_TRANSIENT,
			array( 'release' => $release ),
			array() === $release ? 10 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS
		);
		return $release;
	}
}
