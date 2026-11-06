<?php
/**
 * Plugin Name: HSP Calendar Connection
 * Plugin URI: https://github.com/helmut-steiner/hsp-calendar-connection
 * Description: Private CalDAV busy-time checks and Amelia appointment writeback.
 * Version: 0.5.7
 * Requires at least: 7.1.4
 * Requires PHP: 8.4
 * Author: Helmut Steiner
 * License: HSP Personal Noncommercial Contribution License 1.0
 * License URI: https://github.com/helmut-steiner/hsp-calendar-connection/blob/main/LICENSE
 * Update URI: https://github.com/helmut-steiner/hsp-calendar-connection
 * Text Domain: hsp-calendar-connection
 * Domain Path: /languages
 *
 * @package HSP_Calendar_Connection
 */

defined( 'ABSPATH' ) || exit;

define( 'HSPCC_VERSION', '0.5.7' );
require_once __DIR__ . '/includes/class-hspcc-updater.php';
HSPCC_Updater::init( __FILE__ );

/** Owns private per-employee CalDAV settings, transport and administration. */
final class HSP_Calendar_Connection {
	const OPTION       = 'hsp_calendar_connection_credentials';
	const DISCOVERY    = 'hsp_calendar_connection_discovery';
	const GROUP        = 'hsp_calendar_connection';
	const PAGE         = 'hsp-calendar-connection';
	const HEALTH       = 'hsp_calendar_connection_health';
	const BLOCKING     = 'hsp_calendar_blocking_enabled';
	const WRITING      = 'hsp_calendar_writing_enabled';
	const TIMEZONE     = 'hsp_calendar_fallback_timezone';
	const PROBE        = 'hsp_calendar_write_probe';
	const WRITE_HEALTH = 'hsp_calendar_write_health';
	/** Server-rendered settings sections; unrelated to employee execution scope. */
	private const TABS = array( 'connection', 'calendars', 'runtime', 'status' );
	/**
	 * Last sanitized value for the Settings API's repeated callback.
	 *
	 * @var array|null
	 */
	private static $sanitized = null;
	/**
	 * Resource temporarily allowed during a bounded write probe.
	 *
	 * @var string
	 */
	private static $probe_resource = '';

	/**
	 * Register native settings, transport and lifecycle callbacks.
	 *
	 * @return void No return value.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		if ( class_exists( 'HSPCC_Calendar_Storage' ) ) {
			HSPCC_Calendar_Storage::set_configuration(
				static function () {
					return self::write_configuration();
				}
			);
			HSPCC_Calendar_Storage::set_transport(
				static function ( $key, $method, $suffix, $headers, $body ) {
					return self::calendar_request( $key, $method, $suffix, $headers, $body, true );
				}
			);
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_hspcc_discover', array( __CLASS__, 'discover' ) );
		add_action( 'admin_post_hspcc_select', array( __CLASS__, 'select' ) );
		add_action( 'admin_post_hspcc_check', array( __CLASS__, 'check' ) );
		add_action( 'admin_post_hspcc_runtime', array( __CLASS__, 'save_runtime' ) );
		add_action( 'admin_post_hspcc_probe', array( __CLASS__, 'run_probe' ) );
		add_action( 'admin_post_hspcc_cleanup', array( __CLASS__, 'clean_probe' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'boot_blocking' ) );
	}

	/**
	 * Start native booking guards and queued writes for configured employees.
	 *
	 * @return void No return value.
	 */
	public static function boot_blocking() {
		if ( self::configured_employees( 'blocking' ) &&
			class_exists( 'AmeliaBooking\\Domain\\Common\\Exceptions\\CustomException' ) ) {
			HSPCC_Amelia::boot(); }
		if ( self::configured_employees( 'writing' ) && class_exists( 'HSPCC_Sync_Queue' ) ) {
			HSPCC_Sync_Queue::boot();
			HSPCC_Amelia_Sync::boot();
		}
	}

	/**
	 * Register the bundled translation directory after WordPress initializes.
	 *
	 * @return void No return value.
	 */
	public static function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- This GitHub-distributed plugin bundles its catalogs; WordPress.org does not provide its language packs.
		load_plugin_textdomain( 'hsp-calendar-connection', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Initialize private employee-one defaults without overwriting saved data.
	 *
	 * @return void No return value.
	 */
	public static function activate() {
		add_option( self::OPTION, array(), '', false );
		add_option( self::DISCOVERY, array(), '', false );
	}

	/**
	 * Register the selected employee settings and their sanitization callback.
	 *
	 * @return void No return value.
	 */
	public static function register() {
		self::select_requested_employee();
		register_setting(
			self::GROUP,
			self::option_name( self::OPTION ),
			array(
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Register the administrator settings page.
	 *
	 * @return void No return value.
	 */
	public static function menu() {
		add_options_page( 'HSP Calendar Connection', 'HSP Calendar Connection', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Prepend the settings link for administrators on the Plugins screen.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Result of the operation.
	 */
	public static function settings_link( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' .
				esc_html__( 'Settings', 'hsp-calendar-connection' ) . '</a>'
			);
		}
		return $links;
	}

	/**
	 * Read the current employee private connection settings.
	 *
	 * @return array Result of the operation.
	 */
	private static function credentials() {
		$value = self::private_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Return the employee selected by the current scoped execution.
	 *
	 * @return int Result of the operation.
	 */
	public static function employee_id() {
		return HSPCC_Employee_Context::id(); }

	/**
	 * Run a callback in an employee context and restore the previous context.
	 *
	 * @param int      $employee Native employee identifier; null uses the active context.
	 * @param callable $callback Operation to execute within the employee context.
	 * @return mixed Result of the operation.
	 */
	public static function with_employee( int $employee, callable $callback ) {
		return HSPCC_Employee_Context::run( $employee, $callback );
	}

	/**
	 * Resolve an employee-scoped option name.
	 *
	 * @param string   $base Base option name or DAV address used for resolution.
	 * @param int|null $employee Native employee identifier; null uses the active context.
	 * @return string Result of the operation.
	 */
	public static function option_name( $base, ?int $employee = null ) {
		return HSPCC_Employee_Context::option_name( $base, $employee );
	}

	/**
	 * Read an employee-scoped WordPress option.
	 *
	 * @param string $base Base option name or DAV address used for resolution.
	 * @param mixed  $fallback Value returned when the option is absent.
	 * @return mixed Result of the operation.
	 */
	public static function private_option( $base, $fallback = false ) {
		return get_option( self::option_name( $base ), $fallback );
	}

	/**
	 * Persist an employee option without autoloading private state.
	 *
	 * @param string $base Base option name or DAV address used for resolution.
	 * @param mixed  $value Value to validate or persist.
	 * @param bool   $autoload Compatibility argument; private options always disable autoload.
	 * @return bool Result of the operation.
	 */
	public static function set_private_option( $base, $value, $autoload = false ) {
		// Credentials and connection state must never be autoloaded.
		unset( $autoload );
		return update_option( self::option_name( $base ), $value, false );
	}

	/**
	 * Remove an employee-scoped option through the native Options API.
	 *
	 * @param string $base Base option name or DAV address used for resolution.
	 * @return bool Result of the operation.
	 */
	public static function delete_private_option( $base ) {
		return delete_option( self::option_name( $base ) );
	}

	/**
	 * Find configured employees, optionally restricted to an enabled feature.
	 *
	 * @param string $feature Optional blocking or writing feature filter.
	 * @return int[] Result of the operation.
	 */
	public static function configured_employees( $feature = '' ) {
		if ( ! in_array( $feature, array( '', 'blocking', 'writing' ), true ) ) {
			return array(); }
		$ids = array();
		foreach ( HSPCC_Employee_Context::ids() as $employee ) {
			$configured = self::with_employee(
				$employee,
				static function () use ( $employee, $feature ) {
					$credentials = self::credentials();
					if ( self::positive_id( $credentials['providerId'] ?? null ) !== $employee || self::endpoint() === '' ||
					empty( $credentials['username'] ) || empty( $credentials['secret'] ) ) {
						return false; }
					return '' === $feature || in_array( self::private_option( 'blocking' === $feature ? self::BLOCKING : self::WRITING, false ), array( true, 1, '1' ), true );
				}
			);
			if ( $configured ) {
				$ids[] = $employee; }
		}
		return $ids;
	}

	/**
	 * Check whether fresh calendar blocking is enabled for an employee.
	 *
	 * @param int $employee Native employee identifier; null uses the active context.
	 * @return bool Result of the operation.
	 */
	public static function blocking_enabled( int $employee ) {
		return in_array( $employee, self::configured_employees( 'blocking' ), true ); }
	/**
	 * Check whether queued calendar writes are enabled for an employee.
	 *
	 * @param int $employee Native employee identifier; null uses the active context.
	 * @return bool Result of the operation.
	 */
	public static function writing_enabled( int $employee ) {
		return in_array( $employee, self::configured_employees( 'writing' ), true ); }

	/**
	 * Select a known employee for the administrator view without changing runtime routing.
	 *
	 * @return void No return value.
	 */
	private static function select_requested_employee() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Selects only the displayed employee; positive_id() validates against known employees below.
		$raw        = isset( $_POST['hspcc_employee'] ) ? wp_unslash( $_POST['hspcc_employee'] ) : ( isset( $_GET['hspcc_employee'] ) ? wp_unslash( $_GET['hspcc_employee'] ) : null );
		$employee   = self::positive_id( $raw );
		$choices    = HSPCC_Employee_Context::choices();
		$configured = self::configured_employees();
		if ( $employee > 0 && ( isset( $choices[ $employee ] ) || in_array( $employee, $configured, true ) ) ) {
			HSPCC_Employee_Context::select( $employee );
		} elseif ( ! isset( $choices[ self::employee_id() ] ) && $choices ) {
			HSPCC_Employee_Context::select( (int) array_key_first( $choices ) );
		}
	}

	/**
	 * Build hidden fields preserving the employee and allowlisted settings section.
	 *
	 * @return string Result of the operation.
	 */
	private static function employee_input() {
		return '<input type="hidden" name="hspcc_employee" value="' . (int) self::employee_id() . '">' .
			'<input type="hidden" name="hspcc_tab" value="' . esc_attr( self::requested_tab() ) . '">';
	}

	/**
	 * Resolve a read-only section independently from the employee connection scope.
	 *
	 * @return string An allowlisted section, defaulting to connection settings.
	 */
	private static function requested_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Navigation only; the unslashed value must exactly match the fixed section allowlist below.
		$tab = isset( $_POST['hspcc_tab'] ) ? wp_unslash( $_POST['hspcc_tab'] ) : ( isset( $_GET['hspcc_tab'] ) ? wp_unslash( $_GET['hspcc_tab'] ) : '' );
		return is_string( $tab ) && in_array( $tab, self::TABS, true ) ? $tab : 'connection';
	}

	/**
	 * Build a clean settings URL for the current employee and a validated section.
	 *
	 * @param string $tab Requested section slug.
	 * @param string $status Optional notice key; successful discovery opens calendar routing.
	 * @return string Same-origin administrator URL with no unrelated query parameters.
	 */
	private static function settings_url( $tab, $status = '' ) {
		$tab  = 'discovered' === $status ? 'calendars' : $tab;
		$args = array(
			'page'           => self::PAGE,
			'hspcc_employee' => self::employee_id(),
			'hspcc_tab'      => in_array( $tab, self::TABS, true ) ? $tab : 'connection',
		);
		if ( is_string( $status ) && '' !== $status ) {
			$args['hspcc_status'] = sanitize_key( $status );
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Enqueue responsive section navigation and native employee selector styles.
	 *
	 * @param string $hook Current administrator screen hook.
	 * @return void No return value.
	 */
	public static function admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'hspcc-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), HSPCC_VERSION );
		}
	}

	/**
	 * Return the normalized HTTPS origin and path for the current connection.
	 *
	 * @return string Result of the operation.
	 */
	public static function endpoint() {
		return HSPCC_Endpoint::normalize( self::credentials()['endpoint'] ?? '' );
	}

	/**
	 * Return the configured native provider identifier for the active employee.
	 *
	 * @return int Result of the operation.
	 */
	public static function provider_id() {
		$provider = self::positive_id( self::credentials()['providerId'] ?? null );
		return self::employee_id() === $provider ? $provider : 0;
	}

	/**
	 * Validate a positive integer identifier without accepting other scalar types.
	 *
	 * @param mixed $value Value to validate or persist.
	 * @return int Result of the operation.
	 */
	private static function positive_id( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0; }
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $id ? 0 : $id;
	}

	/**
	 * Resolve the saved timezone or the WordPress timezone default.
	 *
	 * @return string Result of the operation.
	 */
	private static function fallback_timezone() {
		$fallback = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		$value    = self::private_option( self::TIMEZONE, $fallback );
		try {
			return ( new DateTimeZone( is_string( $value ) ? $value : $fallback ) )->getName(); } catch ( Throwable $error ) {
			return 'UTC'; }
	}

	/**
	 * Check that no owned resources, unresolved jobs or probes block a connection change.
	 *
	 * @return bool Result of the operation.
	 */
	private static function can_reconfigure() {
		if ( self::private_option( self::PROBE, false ) ) {
			return false; }
		if ( ! class_exists( 'HSPCC_Sync_Queue' ) ) {
			return true; }
		try {
			$queue = HSPCC_Sync_Queue::summary( self::employee_id() ); } catch ( Throwable $error ) {
			return false; }
			foreach ( array( 'managed', 'unresolved', 'pending', 'retrying', 'conflicts', 'errors', 'limited', 'workerError' ) as $risk ) {
				if ( ! empty( $queue[ $risk ] ) ) {
					return false; }
			}
			return true;
	}

	/**
	 * Validate private Settings API input and preserve credentials on rejected changes.
	 *
	 * @param mixed $input Submitted private connection settings.
	 * @return array Result of the operation.
	 */
	public static function sanitize( $input ) {
		$old = self::credentials();
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			return $old; }
		// WordPress may sanitize again when adding an option for the first time.
		if ( null !== self::$sanitized && $input === self::$sanitized &&
			self::positive_id( $input['providerId'] ?? null ) === self::employee_id() ) {
			return self::$sanitized; }
		$endpoint = HSPCC_Endpoint::normalize( $input['endpoint'] ?? '' );
		$provider = self::positive_id( $input['providerId'] ?? null );
		if ( '' === $endpoint || $provider < 1 || self::employee_id() !== $provider ) {
			add_settings_error( self::GROUP, 'endpoint', esc_html__( 'Enter a valid HTTPS CalDAV server URL and Amelia employee ID. Connection settings were not changed.', 'hsp-calendar-connection' ) );
			return $old;
		}
		$user     = isset( $input['username'] ) && is_string( $input['username'] ) ? trim( $input['username'], ' ' ) : '';
		$password = isset( $input['password'] ) && is_string( $input['password'] ) ? $input['password'] : '';
		if ( '' === $user || strlen( $user ) > 255 || preg_match( '/[:\\x00-\\x1f\\x7f]/', $user ) || strlen( $password ) > 4096 ) {
			add_settings_error( self::GROUP, 'credentials', esc_html__( 'Enter a valid CalDAV username. Credentials were not changed.', 'hsp-calendar-connection' ) );
			return $old;
		}
		$changed = HSPCC_Endpoint::normalize( $old['endpoint'] ?? '' ) !== $endpoint ||
			self::positive_id( $old['providerId'] ?? null ) !== $provider || ( $old['username'] ?? '' ) !== $user;
		if ( $changed && ! self::can_reconfigure() ) {
			add_settings_error( self::GROUP, 'migration', esc_html__( 'Resolve managed appointments, pending sync jobs and temporary tests before changing the server, account or employee. Connection settings were not changed.', 'hsp-calendar-connection' ) );
			return $old;
		}
		if ( '' === $password && ! empty( $old['secret'] ) && ! HSPCC_Endpoint::allows( $endpoint, $old['endpoint'] ?? '' ) ) {
			add_settings_error( self::GROUP, 'new_origin', esc_html__( 'Enter the password for the new CalDAV server. A saved password is never forwarded automatically to a different server.', 'hsp-calendar-connection' ) );
			return $old;
		}
		if ( '' !== $password && self::private_option( self::PROBE, false ) ) {
			add_settings_error( self::GROUP, 'probe', esc_html__( 'Remove the temporary test event before replacing the saved password.', 'hsp-calendar-connection' ) );
			return $old;
		}
		$secret = isset( $old['secret'] ) && is_string( $old['secret'] ) ? $old['secret'] : '';
		if ( '' !== $password ) {
			try {
				$secret = self::encrypt( $password ); } catch ( Throwable $e ) {
				add_settings_error( self::GROUP, 'encryption', esc_html__( 'Secure credential storage is unavailable. Credentials were not changed.', 'hsp-calendar-connection' ) );
				return $old;
				}
		}
		if ( '' === $secret ) {
			add_settings_error( self::GROUP, 'password', esc_html__( 'Enter the CalDAV password for the initial connection.', 'hsp-calendar-connection' ) );
			return $old;
		}
		// A blank password retains the existing encrypted secret; plaintext is never stored.
		self::$sanitized = array(
			'username'   => $user,
			'secret'     => $secret,
			'providerId' => $provider,
			'endpoint'   => $endpoint,
		);
		if ( $changed ) {
			self::set_private_option( self::BLOCKING, false, false );
			self::set_private_option( self::WRITING, false, false );
			self::delete_private_option( self::DISCOVERY );
			self::delete_private_option( self::HEALTH );
			self::delete_private_option( self::WRITE_HEALTH );
		} elseif ( ( $old['secret'] ?? '' ) !== $secret ) {
			// Password rotation on the same server/account keeps owned routing.
			$discovery = self::discovery();
			if ( $discovery ) {
				$discovery['fingerprint'] = self::fingerprint( self::$sanitized );
				self::set_private_option( self::DISCOVERY, $discovery, false );
			}
			self::delete_private_option( self::HEALTH );
			self::delete_private_option( self::WRITE_HEALTH );
			self::set_private_option( self::WRITING, false, false );
		}
		HSPCC_Employee_Context::remember( $provider );
		return self::$sanitized;
	}

	/**
	 * Derive the credential-encryption key from the WordPress authentication salts.
	 *
	 * @return string Result of the operation.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . '|hsp-calendar-connection:v2', true );
	}

	/**
	 * Encrypt a credential with authenticated encryption and a random nonce.
	 *
	 * @param string $plain Plaintext credential; never logged or rendered.
	 * @return string Result of the operation.
	 * @throws RuntimeException When authenticated encryption or saved credentials are unavailable.
	 */
	private static function encrypt( $plain ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
			return 's2:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
		}
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv     = random_bytes( 12 );
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'hspcc:v2' );
			if ( false !== $cipher ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
				return 'o2:' . base64_encode( $iv . $tag . $cipher ); }
		}
		throw new RuntimeException( 'Encryption unavailable.' );
	}

	/**
	 * Authenticate and decrypt a saved credential envelope.
	 *
	 * @param string $secret Versioned authenticated credential envelope.
	 * @return string Result of the operation.
	 * @throws RuntimeException When authenticated encryption or saved credentials are unavailable.
	 */
	private static function decrypt( $secret ) {
		if ( ! is_string( $secret ) ) {
			throw new RuntimeException( 'Saved credentials unavailable.' );
		}
		$format = substr( $secret, 0, 3 );
		if ( ! in_array( $format, array( 's2:', 'o2:' ), true ) ) {
			throw new RuntimeException( 'Saved credentials unavailable.' );
		}
		$key = self::key();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
		$raw = base64_decode( substr( $secret, 3 ), true );
		if ( false === $raw ) {
			throw new RuntimeException( 'Saved credentials unavailable.' ); }
		$plain = false;
		if ( 's2:' === $format && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > 40 ) {
			$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $key );
		} elseif ( 'o2:' === $format && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'hspcc:v2' );
		}
		if ( ! is_string( $plain ) || '' === $plain ) {
			throw new RuntimeException( 'Saved credentials unavailable.' ); }
		return $plain;
	}

	/**
	 * Bind cached discovery and write proofs to the current employee connection.
	 *
	 * @param array $credentials Connection settings containing endpoint, employee and encrypted secret.
	 * @return string Result of the operation.
	 */
	private static function fingerprint( $credentials ) {
		return hash(
			'sha256',
			implode(
				"\0",
				array(
					HSPCC_Endpoint::normalize( $credentials['endpoint'] ?? '' ),
					(string) self::positive_id( $credentials['providerId'] ?? null ),
					(string) ( $credentials['username'] ?? '' ),
					(string) ( $credentials['secret'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Read discovery state only when its fingerprint matches current credentials.
	 *
	 * @return array Result of the operation.
	 */
	private static function discovery() {
		$value = self::private_option( self::DISCOVERY, array() );
		if ( ! is_array( $value ) || ! isset( $value['fingerprint'] ) ||
			! hash_equals( self::fingerprint( self::credentials() ), (string) $value['fingerprint'] ) ) {
			return array(); }
		return $value;
	}

	/**
	 * Check a URL against the configured HTTPS origin and port.
	 *
	 * @param string $url HTTPS address to validate or request.
	 * @return bool Result of the operation.
	 */
	private static function allowed_url( $url ) {
		return HSPCC_Endpoint::allows( $url, self::endpoint() );
	}

	/**
	 * Return valid selected busy-calendar identifiers in stable order.
	 *
	 * @return string[] Result of the operation.
	 */
	public static function source_keys() {
		$discovery = self::discovery();
		$keys      = $discovery['busySelected'] ?? array();
		if ( ! is_array( $keys ) || count( $keys ) > 256 ) {
			return array(); }
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
				return array(); }
			$calendar = $discovery['calendars'][ $key ] ?? null;
			if ( ! is_string( $key ) || ! $calendar || empty( $calendar['freeBusy'] ) || ! self::allowed_url( $calendar['url'] ?? '' ) ) {
				return array(); }
		}
		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Describe the selected destination and whether writing is enabled.
	 *
	 * @return array Result of the operation.
	 */
	public static function write_configuration() {
		$discovery = self::discovery();
		$key       = $discovery['writeSelected'] ?? '';
		$calendar  = $discovery['calendars'][ $key ] ?? null;
		$selected  = self::provider_id() > 0 && is_string( $key ) && '' !== $key && $calendar && self::allowed_url( $calendar['url'] ?? '' );
		return array(
			'ready'       => $selected && in_array( self::private_option( self::WRITING, false ), array( true, 1, '1' ), true ),
			'selected'    => (bool) $selected,
			'calendarKey' => is_string( $key ) ? $key : '',
		);
	}

	/**
	 * Build an opaque source-cache namespace without exposing credentials or URLs.
	 *
	 * @param string $key Opaque identifier of a discovered calendar.
	 * @param bool   $create_namespace Whether runtime callers may initialize the event namespace.
	 * @return string Result of the operation.
	 */
	public static function source_identity( $key, bool $create_namespace = true ) {
		$credentials = self::credentials();
		$discovery   = self::discovery();
		$calendar    = $discovery['calendars'][ $key ] ?? null;
		if ( ! $calendar || empty( $calendar['freeBusy'] ) || ! self::allowed_url( $calendar['url'] ?? '' ) ||
			self::provider_id() < 1 ) {
			return ''; }
		$overlap   = ( $discovery['writeSelected'] ?? '' ) === $key;
		$namespace = $overlap && class_exists( 'HSPCC_Sync_Queue' ) ? HSPCC_Sync_Queue::site_uuid( $create_namespace ) : '';
		if ( $overlap && ! $create_namespace && '' === $namespace ) {
			return '';
		}
		$zone = $overlap ? self::fallback_timezone() : '';
		return hash_hmac( 'sha256', self::fingerprint( $credentials ) . '|' . $key . '|' . $namespace . '|' . (int) $overlap . '|' . $zone, wp_salt( 'nonce' ) );
	}

	/**
	 * Build a cache identity for the union of selected busy sources.
	 *
	 * @param bool $create_namespace Whether runtime callers may initialize the event namespace.
	 * @return string Result of the operation.
	 */
	public static function connection_identity( bool $create_namespace = true ) {
		$keys = self::source_keys();
		if ( ! $keys ) {
			return ''; }
		$identities = array();
		foreach ( $keys as $key ) {
			$id = self::source_identity( $key, $create_namespace );
			if ( '' === $id ) {
				return '';
			} $identities[] = $id; }
		return hash( 'sha256', implode( '|', $identities ) );
	}

	/**
	 * Read bounded free/busy data from a selected calendar using safe HTTP.
	 *
	 * @param int         $start Inclusive UTC Unix timestamp.
	 * @param int         $end Exclusive UTC Unix timestamp.
	 * @param string|null $calendar_key Selected calendar identifier; null uses the first source.
	 * @return string|WP_Error Result of the operation.
	 */
	public static function report( $start, $end, $calendar_key = null ) {
		if ( ! is_int( $start ) || ! is_int( $end ) || $start <= 0 || $end <= $start || $end - $start > 31 * 86400 ) {
			return new WP_Error( 'hspcc_window', __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) );
		}
		$keys         = self::source_keys();
		$calendar_key = $calendar_key ?? ( count( $keys ) === 1 ? $keys[0] : '' );
		if ( ! in_array( $calendar_key, $keys, true ) || self::source_identity( $calendar_key ) === '' ) {
			return new WP_Error( 'hspcc_selection', __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) );
		}
		$credentials = self::credentials();
		$discovery   = self::discovery();
		$calendar    = $discovery['calendars'][ $calendar_key ];
		if ( ( $discovery['writeSelected'] ?? '' ) === $calendar_key ) {
			return self::overlap_report( $calendar_key, $start, $end ); }
		try {
			$password = self::decrypt( $credentials['secret'] ); } catch ( Throwable $error ) {
			return new WP_Error( 'hspcc_credentials', __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) ); }
			$body     = '<?xml version="1.0" encoding="utf-8"?><c:free-busy-query xmlns:c="urn:ietf:params:xml:ns:caldav"><c:time-range start="' .
			gmdate( 'Ymd\THis\Z', $start ) . '" end="' . gmdate( 'Ymd\THis\Z', $end ) . '"/></c:free-busy-query>';
			$response = HSPCC_Endpoint::request(
				$calendar['url'],
				array(
					'method'              => 'REPORT',
					'timeout'             => 15,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 1048576,
					'headers'             => array(
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
						'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $password ),
						'Content-Type'  => 'application/xml; charset=utf-8',
						'Accept'        => 'text/calendar',
						'Depth'         => '1',
					),
					'body'                => $body,
				),
				self::endpoint()
			);
		unset( $password );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hspcc_transport', __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'hspcc_http_' . (int) $status, __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) ); }
		$type          = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		$calendar_data = wp_remote_retrieve_body( $response );
		if ( 'text/calendar' !== $type || ! is_string( $calendar_data ) || '' === $calendar_data || strlen( $calendar_data ) >= 1048576 ) {
			return new WP_Error( 'hspcc_response', __( 'Calendar availability could not be verified.', 'hsp-calendar-connection' ) );
		}
		return $calendar_data;
	}

	/**
	 * Read and project a write calendar while excluding only owned appointment events.
	 *
	 * @param string $key Opaque identifier of a discovered calendar.
	 * @param int    $start Inclusive UTC Unix timestamp.
	 * @param int    $end Exclusive UTC Unix timestamp.
	 * @return string|WP_Error Result of the operation.
	 */
	private static function overlap_report( $key, $start, $end ) {
		if ( ! class_exists( 'HSPCC_Overlap_Busy' ) || ! class_exists( 'HSPCC_Sync_Queue' ) ) {
			return new WP_Error( 'hspcc_overlap_unavailable' ); }
		$properties = '';
		foreach ( array( 'UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'STATUS', 'TRANSP', 'RECURRENCE-ID', 'RRULE', 'RDATE', 'EXDATE' ) as $name ) {
			$properties .= '<c:prop name="' . $name . '"/>';
		}
		$range    = ' start="' . gmdate( 'Ymd\THis\Z', $start ) . '" end="' . gmdate( 'Ymd\THis\Z', $end ) . '"';
		$body     = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
			'<d:prop><c:calendar-data><c:comp name="VCALENDAR"><c:prop name="VERSION"/><c:prop name="PRODID"/>' .
			'<c:comp name="VTIMEZONE"><c:allprop/><c:allcomp/></c:comp><c:comp name="VEVENT">' . $properties . '</c:comp></c:comp>' .
			'<c:expand' . $range . '/></c:calendar-data></d:prop><c:filter><c:comp-filter name="VCALENDAR">' .
			'<c:comp-filter name="VEVENT"><c:time-range' . $range . '/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
		$response = self::calendar_request(
			$key,
			'REPORT',
			'',
			array(
				'Depth'        => '1',
				'Content-Type' => 'application/xml; charset=utf-8',
			),
			$body,
			false
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hspcc_overlap_transport' ); }
		if ( wp_remote_retrieve_response_code( $response ) !== 207 ) {
			return new WP_Error( 'hspcc_overlap_http_' . (int) wp_remote_retrieve_response_code( $response ) ); }
		$discovery = self::discovery();
		$zone      = ! empty( $discovery['calendars'][ $key ]['timezone'] ) ? $discovery['calendars'][ $key ]['timezone'] : self::fallback_timezone();
		return HSPCC_Overlap_Busy::freebusy(
			wp_remote_retrieve_body( $response ),
			$start,
			$end,
			'hsp-amelia-' . HSPCC_Sync_Queue::site_uuid() . '-',
			$zone
		);
	}

	/**
	 * Send an origin-constrained conditional request to a selected calendar resource.
	 *
	 * @param string $key Opaque identifier of a discovered calendar.
	 * @param string $method Allowed HTTP method.
	 * @param string $suffix Validated owned resource filename.
	 * @param array  $headers Conditional HTTP headers.
	 * @param string $body Request payload.
	 * @param bool   $writing Whether the operation requires enabled and verified writing.
	 * @return array|WP_Error Result of the operation.
	 */
	private static function calendar_request( $key, $method, $suffix, $headers, $body, $writing = true ) {
		$discovery = self::discovery();
		$calendar  = $discovery['calendars'][ $key ] ?? null;
		if ( ! $calendar || ! self::allowed_url( $calendar['url'] ?? '' ) || ! is_array( $headers ) || ! is_string( $body ) || strlen( $body ) > 1048576 ) {
			return new WP_Error( 'hspcc_request' ); }
		if ( $writing ) {
			$config = self::write_configuration();
			if ( ! $config['selected'] || $config['calendarKey'] !== $key || ! in_array( $method, array( 'GET', 'PUT', 'DELETE' ), true ) ||
				! is_string( $suffix ) || ! preg_match( '/^hsp-amelia-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-[1-9][0-9]*\.ics$/D', $suffix ) ) {
				return new WP_Error( 'hspcc_target' ); }
			if ( in_array( $method, array( 'PUT', 'DELETE' ), true ) && ! in_array( self::private_option( self::WRITING, false ), array( true, 1, '1' ), true ) &&
				( '' === $suffix || ! hash_equals( self::$probe_resource, $suffix ) ) ) {
				return new WP_Error( 'hspcc_writing_disabled' ); }
			if ( ( 'PUT' === $method && ! isset( $headers['If-Match'] ) && ! isset( $headers['If-None-Match'] ) ) ||
				( 'DELETE' === $method && ! isset( $headers['If-Match'] ) ) ) {
				return new WP_Error( 'hspcc_condition' ); }
		} elseif ( 'REPORT' !== $method || '' !== $suffix || ! in_array( $key, self::source_keys(), true ) ) {
			return new WP_Error( 'hspcc_request' ); }
		foreach ( $headers as $name => $value ) {
			if ( ! in_array( $name, array( 'If-Match', 'If-None-Match', 'Content-Type', 'Accept', 'Depth' ), true ) || ! is_string( $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				return new WP_Error( 'hspcc_header' ); }
		}
		$credentials = self::credentials();
		try {
			$password = self::decrypt( $credentials['secret'] ?? '' ); } catch ( Throwable $e ) {
			return new WP_Error( 'hspcc_credentials' ); }
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
			$headers['Authorization'] = 'Basic ' . base64_encode( $credentials['username'] . ':' . $password );
			$url                      = '' === $suffix ? $calendar['url'] : trailingslashit( $calendar['url'] ) . rawurlencode( $suffix );
			$response                 = HSPCC_Endpoint::request(
				$url,
				array(
					'method'              => $method,
					'timeout'             => 8,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 1048576,

					'headers'             => $headers,

					'body'                => $body,
				),
				self::endpoint()
			);
		unset( $password );
		return is_wp_error( $response ) ? new WP_Error( 'hspcc_transport' ) : $response;
	}

	/**
	 * Create and update a bounded temporary write test without customer information.
	 *
	 * @return array|WP_Error Result of the operation.
	 */
	public static function probe_write_target() {
		if ( ! current_user_can( 'manage_options' ) || ! self::write_configuration()['selected'] ) {
			return new WP_Error( 'hspcc_probe_access' ); }
		if ( self::private_option( self::PROBE, false ) ) {
			return new WP_Error( 'hspcc_probe_cleanup_pending' ); }
		$config = self::write_configuration();
		$stamp  = time();
		$job    = array(
			'action'        => 'upsert',
			'siteUuid'      => wp_generate_uuid4(),
			'appointmentId' => 2147483647,
			'calendarKey'   => $config['calendarKey'],
			'startUtc'      => (int) ( ceil( ( $stamp + 7 * 86400 ) / 60 ) * 60 ),
			'endUtc'        => (int) ( ceil( ( $stamp + 7 * 86400 ) / 60 ) * 60 ) + 60,
			'serviceLabel'  => 'HSP Calendar Connection test (temporary)',
			'sequence'      => 0,
			'dtstampUtc'    => $stamp,
			'status'        => 'tentative',
		);
		$intent = HSPCC_Calendar_Storage::intent( $job );
		if ( is_wp_error( $intent ) ) {
			return $intent; }
		$state = array(
			'intent'    => $intent,
			'created'   => false,
			'updated'   => false,
			'checkedAt' => $stamp,
		);
		self::set_private_option( self::PROBE, $state, false );
		if ( self::private_option( self::PROBE, array() ) !== $state ) {
			return new WP_Error( 'hspcc_probe_persistence' ); }
		self::$probe_resource = $intent['pathSuffix'];
		HSPCC_Calendar_Storage::set_configuration(
			static function () {
				$c          = self::write_configuration();
				$c['ready'] = $c['selected'];
				return $c;
			}
		);
		try {
			$receipt = HSPCC_Calendar_Storage::sync( $job );
			if ( is_wp_error( $receipt ) ) {
				return $receipt; }
			$state['created']    = true;
			$state['receipt']    = $receipt;
			$job['mapping']      = $receipt;
			$job['sequence']     = 1;
			$job['dtstampUtc']   = $stamp + 1;
			$job['endUtc']      += 60;
			$job['serviceLabel'] = 'HSP Calendar Connection test updated (temporary)';
			$intent              = HSPCC_Calendar_Storage::intent( $job );
			if ( is_wp_error( $intent ) ) {
				self::set_private_option( self::PROBE, $state, false );
				return $intent; }
			$state['intent'] = $intent;
			self::set_private_option( self::PROBE, $state, false );
			if ( self::private_option( self::PROBE, array() ) !== $state ) {
				return new WP_Error( 'hspcc_probe_persistence' ); }
			$receipt = HSPCC_Calendar_Storage::sync( $job );
			if ( is_wp_error( $receipt ) ) {
				return $receipt; }
			$state['updated'] = true;
			$state['receipt'] = $receipt;
			self::set_private_option( self::PROBE, $state, false );
			return array(
				'created'        => true,
				'updated'        => true,
				'cleanupPending' => true,
			);
		} finally {
			self::$probe_resource = '';
			HSPCC_Calendar_Storage::set_configuration(
				static function () {
					return self::write_configuration();
				}
			);
		}
	}

	/**
	 * Remove the tracked temporary event and bind successful cleanup to the connection.
	 *
	 * @return bool|WP_Error Result of the operation.
	 */
	public static function cleanup_write_probe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'hspcc_probe_access' ); }
		$state = self::private_option( self::PROBE, array() );
		if ( ! $state ) {
			return array(
				'cleanupComplete' => true,
				'alreadyAbsent'   => true,
			); }
		$intent = $state['intent'] ?? null;
		if ( ! is_array( $intent ) || empty( $intent['pathSuffix'] ) ) {
			return new WP_Error( 'hspcc_probe_state' ); }
		self::$probe_resource = $intent['pathSuffix'];
		HSPCC_Calendar_Storage::set_configuration(
			static function () {
				$c          = self::write_configuration();
				$c['ready'] = $c['selected'];
				return $c;
			}
		);
		try {
			$mapping = HSPCC_Calendar_Storage::recover( $intent );
			if ( is_wp_error( $mapping ) ) {
				return $mapping; }
			if ( ! empty( $mapping['etag'] ) ) {
				$result = HSPCC_Calendar_Storage::sync(
					array(
						'action'        => 'delete',
						'siteUuid'      => $intent['siteUuid'],
						'appointmentId' => $intent['appointmentId'],

						'calendarKey'   => $intent['calendarKey'],

						'mapping'       => $mapping,
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result; }
			}
			self::delete_private_option( self::PROBE );
			$passed = ! empty( $state['created'] ) && ! empty( $state['updated'] );
			self::set_private_option(
				self::WRITE_HEALTH,
				array(
					'ok'              => $passed,
					'checkedAt'       => time(),
					'cleanupComplete' => true,
					'fingerprint'     => self::fingerprint( self::credentials() ),

					'calendarKey'     => $intent['calendarKey'],
				),
				false
			);
			return array(
				'cleanupComplete'         => true,
				'createAndUpdateVerified' => $passed,
			);
		} finally {
			self::$probe_resource = '';
			HSPCC_Calendar_Storage::set_configuration(
				static function () {
					return self::write_configuration();
				}
			);
		}
	}

	/**
	 * Resolve a DAV address only after validating raw and resolved origins.
	 *
	 * @param DOMXPath     $xpath Parsed DAV response query context.
	 * @param string       $query Internal XPath expression.
	 * @param string       $base Base option name or DAV address used for resolution.
	 * @param DOMNode|null $context Optional DAV response node.
	 * @return string|false Result of the operation.
	 */
	private static function href( $xpath, $query, $base, $context = null ) {
		$nodes = $xpath->query( $query, $context );
		if ( ! $nodes || ! $nodes->length ) {
			return false; }
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
		$raw   = $nodes->item( 0 )->textContent;
		$parts = wp_parse_url( $raw );
		// Validate before resolution: WordPress drops userinfo on scheme-relative URLs.
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			preg_match( '/[\x00-\x20\x7f]/', $raw ) || strpos( $raw, chr( 92 ) ) !== false ) {
			return false; }
		$url = WP_Http::make_absolute_url( $raw, $base );
		return self::allowed_url( $url ) ? $url : false;
	}

	/**
	 * Discover DAV properties through bounded authenticated same-origin requests.
	 *
	 * @param string $url HTTPS address to validate or request.
	 * @param string $properties Internal DAV property XML fragment.
	 * @param int    $depth Requested DAV traversal depth.
	 * @param string $username Current connection account name.
	 * @param string $password Decrypted account password, used only for the request.
	 * @return DOMXPath|WP_Error Result of the operation.
	 */
	private static function propfind( $url, $properties, $depth, $username, $password ) {
		if ( ! self::allowed_url( $url ) ) {
			return new WP_Error( 'unsafe' ); }
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'xml' ); }
		$response = HSPCC_Endpoint::request(
			$url,
			array(
				'method'              => 'PROPFIND',
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 1048576,
				'headers'             => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes authenticated ciphertext or the HTTP Basic authentication field, never executable code.
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Content-Type'  => 'application/xml; charset=utf-8',
					'Depth'         => (string) $depth,
				),
				'body'                => '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $properties . '</d:prop></d:propfind>',
			),
			self::endpoint()
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection' ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'authentication' ); }
		if ( $status >= 300 && $status < 400 ) {
			return new WP_Error( 'redirect' ); }
		if ( 207 !== $status && 200 !== $status ) {
			return new WP_Error( 'connection' ); }
		$xml = wp_remote_retrieve_body( $response );
		if ( ! is_string( $xml ) || strlen( $xml ) >= 1048576 || stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false ) {
			return new WP_Error( 'xml' );
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$document = new DOMDocument();
			$loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || null !== $document->doctype ) {
			return new WP_Error( 'xml' ); }
		$xpath = new DOMXPath( $document );
		$xpath->registerNamespace( 'd', 'DAV:' );
		$xpath->registerNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );
		return $xpath;
	}

	/**
	 * Read a published calendar timezone from a bounded VTIMEZONE document.
	 *
	 * @param mixed $value Value to validate or persist.
	 * @return string Result of the operation.
	 */
	private static function calendar_timezone( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 65536 || ! class_exists( 'AmeliaVendor\\Sabre\\VObject\\Reader' ) ) {
			return ''; }
		try {
			$document = \AmeliaVendor\Sabre\VObject\Reader::read( $value );
			$zones    = $document->select( 'VTIMEZONE' );
			if ( count( $zones ) !== 1 || count( $document->select( 'VEVENT' ) ) > 0 ) {
				return ''; }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
			$id = (string) reset( $zones )->TZID;
			return \AmeliaVendor\Sabre\VObject\TimeZoneUtil::getTimeZone( $id, $document, true )->getName();
		} catch ( Throwable $e ) {
			return ''; }
	}

	/**
	 * Require administrator capability and an action-specific nonce before mutations.
	 *
	 * @param string $action Nonce action identifying the administrator operation.
	 * @return void No return value.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'hsp-calendar-connection' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	/**
	 * Redirect an administrator operation to the selected employee status notice.
	 *
	 * @param string $status Allowlisted notice key for the redirect.
	 * @return never No return value.
	 */
	private static function finish( $status ) {
		wp_safe_redirect( self::settings_url( self::requested_tab(), $status ) );
		exit;
	}

	/**
	 * Handle the guarded calendar-discovery administrator action.
	 *
	 * @return void No return value.
	 */
	public static function discover() {
		self::guard( 'hspcc_discover' );
		$credentials = self::credentials();
		$endpoint    = self::endpoint();
		if ( '' === $endpoint || self::provider_id() < 1 || empty( $credentials['username'] ) || empty( $credentials['secret'] ) ) {
			self::finish( 'credentials' ); }
		try {
			$password   = self::decrypt( $credentials['secret'] );
			$properties = '//d:propstat[contains(d:status, " 200 ")]/d:prop';
			$principal  = self::propfind( $endpoint, '<d:current-user-principal/>', 0, $credentials['username'], $password );
			if ( is_wp_error( $principal ) ) {
				self::finish( $principal->get_error_code() ); }
			$principal_url = self::href( $principal, $properties . '/d:current-user-principal/d:href', $endpoint );
			if ( ! $principal_url ) {
				self::finish( 'unsafe' ); }
			$home = self::propfind( $principal_url, '<c:calendar-home-set/>', 0, $credentials['username'], $password );
			if ( is_wp_error( $home ) ) {
				self::finish( $home->get_error_code() ); }
			$home_url = self::href( $home, $properties . '/c:calendar-home-set/d:href', $principal_url );
			if ( ! $home_url ) {
				self::finish( 'unsafe' ); }
			$collections = self::propfind( $home_url, '<d:resourcetype/><d:displayname/><d:supported-report-set/><d:current-user-privilege-set/><c:calendar-timezone/>', 1, $credentials['username'], $password );
			unset( $password );
			if ( is_wp_error( $collections ) ) {
				self::finish( $collections->get_error_code() ); }
			$calendars = array();
			foreach ( $collections->query( '/d:multistatus/d:response' ) as $response ) {
				$prop = './d:propstat[contains(d:status, " 200 ")]/d:prop';
				if ( ! $collections->query( $prop . '/d:resourcetype/c:calendar', $response )->length ) {
					continue; }
				$url = self::href( $collections, './d:href', $home_url, $response );
				if ( ! $url ) {
					self::finish( 'unsafe' ); }
				$names = $collections->query( $prop . '/d:displayname', $response );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
				$name       = $names->length ? sanitize_text_field( $names->item( 0 )->textContent ) : '';
				$privileges = array();
				foreach ( $collections->query( $prop . '/d:current-user-privilege-set/d:privilege/*', $response ) as $privilege ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
					if ( 'DAV:' === $privilege->namespaceURI ) {
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
						$privileges[] = $privilege->localName; }
				}
				$zones                               = $collections->query( $prop . '/c:calendar-timezone', $response );
				$calendars[ hash( 'sha256', $url ) ] = array(
					'url'               => $url,
					'name'              => '' !== $name ? substr( $name, 0, 200 ) : '',
					'freeBusy'          => (bool) $collections->query( $prop . '/d:supported-report-set/d:supported-report/d:report/c:free-busy-query', $response )->length,
					'privilegesChecked' => (bool) $collections->query( $prop . '/d:current-user-privilege-set', $response )->length,
					'canWrite'          => in_array( 'all', $privileges, true ) || in_array( 'write', $privileges, true ) ||
						( in_array( 'bind', $privileges, true ) && in_array( 'unbind', $privileges, true ) && in_array( 'write-content', $privileges, true ) ),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM or iCalendar property names are fixed by their APIs.
					'timezone'          => $zones->length ? self::calendar_timezone( $zones->item( 0 )->textContent ) : '',
				);
				if ( count( $calendars ) > 256 ) {
					self::finish( 'xml' ); }
			}
			$previous = self::discovery();
			self::set_private_option(
				self::DISCOVERY,
				array(
					'fingerprint'   => self::fingerprint( $credentials ),
					'checkedAt'     => time(),
					'calendars'     => $calendars,
					'busySelected'  => array_values( array_intersect( array_keys( $calendars ), $previous['busySelected'] ?? array() ) ),
					'writeSelected' => isset( $calendars[ $previous['writeSelected'] ?? '' ] ) ? $previous['writeSelected'] : '',
				),
				false
			);
			self::finish( $calendars ? 'discovered' : 'empty' );
		} catch ( Throwable $e ) {
			self::finish( 'credentials' ); }
	}

	/**
	 * Validate and persist calendar routing through the guarded administrator action.
	 *
	 * @return void No return value.
	 */
	public static function select() {
		self::guard( 'hspcc_select' );
		$discovery = self::discovery();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the action nonce; routing keys and timezone are validated before persistence.
		$busy = isset( $_POST['busy_calendars'] ) && is_array( $_POST['busy_calendars'] ) ? wp_unslash( $_POST['busy_calendars'] ) : array();
		if ( ! $busy || count( $busy ) > 10 ) {
			self::finish( 'selection' ); }
		foreach ( $busy as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) || empty( $discovery['calendars'][ $key ]['freeBusy'] ) ) {
				self::finish( 'selection' ); }
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the action nonce; routing keys and timezone are validated before persistence.
		$write = isset( $_POST['write_calendar'] ) && is_string( $_POST['write_calendar'] ) ? wp_unslash( $_POST['write_calendar'] ) : '';
		if ( '' !== $write && ( ! preg_match( '/^[a-f0-9]{64}$/D', $write ) || ! isset( $discovery['calendars'][ $write ] ) ) ) {
			self::finish( 'selection' ); }
		if ( '' !== $write && ! empty( $discovery['calendars'][ $write ]['privilegesChecked'] ) && empty( $discovery['calendars'][ $write ]['canWrite'] ) ) {
			self::finish( 'write_access' ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the action nonce; routing keys and timezone are validated before persistence.
		$zone = isset( $_POST['fallback_timezone'] ) && is_string( $_POST['fallback_timezone'] ) ? wp_unslash( $_POST['fallback_timezone'] ) : self::fallback_timezone();
		try {
			$zone = ( new DateTimeZone( $zone ) )->getName();
		} catch ( Throwable $e ) {
			self::finish( 'timezone' ); }
		// Destination migration is a separate operation once owned appointments exist.
		if ( ( $discovery['writeSelected'] ?? '' ) !== $write && class_exists( 'HSPCC_Sync_Queue' ) ) {
			if ( self::private_option( self::PROBE, false ) ) {
				self::finish( 'migration' ); }
			$queue = HSPCC_Sync_Queue::summary( self::employee_id() );
			foreach ( array( 'managed', 'pending', 'retrying', 'conflicts', 'errors', 'limited', 'workerError' ) as $risk ) {
				if ( ! empty( $queue[ $risk ] ) ) {
					self::finish( 'migration' ); }
			}
		}
		$discovery['busySelected'] = array_values( array_unique( $busy ) );
		if ( ( $discovery['writeSelected'] ?? '' ) !== $write ) {
			self::set_private_option( self::WRITING, false, false );
			self::delete_private_option( self::WRITE_HEALTH );
		}
		$discovery['writeSelected'] = $write;
		self::set_private_option( self::TIMEZONE, $zone, false );
		self::set_private_option( self::DISCOVERY, $discovery, false );
		self::finish( 'selected' );
	}

	/**
	 * Run the guarded seven-day read test and redirect with a status result.
	 *
	 * @return void No return value.
	 */
	public static function check() {
		self::guard( 'hspcc_check' );
		$start  = time();
		$result = HSPCC_Busy_Time::intervals( $start, $start + 7 * 86400, true );
		self::finish( is_wp_error( $result ) ? 'busy_error' : 'busy_ok' );
	}

	/**
	 * Validate that destination write and cleanup proof matches the current connection.
	 *
	 * @return bool Result of the operation.
	 */
	private static function write_verified() {
		$health = self::private_option( self::WRITE_HEALTH, array() );
		$config = self::write_configuration();
		return ! empty( $health['ok'] ) && ! empty( $health['cleanupComplete'] ) && $config['selected'] &&
			is_string( $health['fingerprint'] ?? null ) && hash_equals( self::fingerprint( self::credentials() ), $health['fingerprint'] ) &&
			( $health['calendarKey'] ?? '' ) === $config['calendarKey'] && ! self::private_option( self::PROBE, false );
	}

	/**
	 * Set feature gates, permitting disabling even when the connection is unavailable.
	 *
	 * @param bool $blocking Whether busy-time blocking should be enabled.
	 * @param bool $writing Whether the operation requires enabled and verified writing.
	 * @return bool|WP_Error Result of the operation.
	 */
	public static function configure_runtime( $blocking, $writing ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_bool( $blocking ) || ! is_bool( $writing ) ) {
			return new WP_Error( 'runtime' ); }
		self::set_private_option( self::BLOCKING, false, false );
		self::set_private_option( self::WRITING, false, false );
		if ( ! $blocking && ! $writing ) {
			return true; }
		if ( self::provider_id() < 1 || ! self::source_keys() ) {
			return new WP_Error( 'selection' ); }
		if ( $writing && ! self::write_verified() ) {
			return new WP_Error( 'write_test' ); }
		if ( $blocking ) {
			$start  = time();
			$result = HSPCC_Busy_Time::intervals( $start, $start + 7 * 86400, true );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'busy_error' ); }
		}
		self::set_private_option( self::BLOCKING, $blocking, false );
		self::set_private_option( self::WRITING, $writing, false );
		return true;
	}

	/**
	 * Handle the nonce-protected feature-gate submission.
	 *
	 * @return void No return value.
	 */
	public static function save_runtime() {
		self::guard( 'hspcc_runtime' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the action nonce; routing keys and timezone are validated before persistence.
		$result = self::configure_runtime( isset( $_POST['enable_blocking'] ), isset( $_POST['enable_writing'] ) );
		self::finish( is_wp_error( $result ) ? $result->get_error_code() : 'runtime_saved' );
	}

	/**
	 * Handle the nonce-protected temporary write-test submission.
	 *
	 * @return void No return value.
	 */
	public static function run_probe() {
		self::guard( 'hspcc_probe' );
		$result = self::probe_write_target();
		self::finish( is_wp_error( $result ) ? 'probe_error' : 'probe_created' );
	}

	/**
	 * Handle the nonce-protected temporary-event cleanup submission.
	 *
	 * @return void No return value.
	 */
	public static function clean_probe() {
		self::guard( 'hspcc_cleanup' );
		$result = self::cleanup_write_probe();
		self::finish( is_wp_error( $result ) ? 'cleanup_error' : 'probe_cleaned' );
	}

	/**
	 * Return sanitized feature and connection health for the current employee.
	 *
	 * @return array Result of the operation.
	 */
	public static function status() {
		$identity = self::connection_identity( false );
		$sources  = self::source_keys();
		$health   = self::private_option( self::HEALTH, array() );
		$current  = is_array( $health ) && '' !== $identity && isset( $health['identity'] ) &&
			is_string( $health['identity'] ) && hash_equals( $identity, $health['identity'] );
		return array(
			'enabled'             => in_array( self::private_option( self::BLOCKING, false ), array( true, 1, '1' ), true ),
			'configured'          => self::provider_id() > 0 && ! empty( $sources ),
			'employeeId'          => self::employee_id(),
			'providerId'          => self::provider_id(),
			'lastCheckOk'         => $current ? (bool) ( $health['ok'] ?? false ) : null,
			'checkedAt'           => $current ? (int) ( $health['checkedAt'] ?? 0 ) : 0,
			'periodCount'         => $current && ! empty( $health['ok'] ) ? (int) ( $health['periodCount'] ?? 0 ) : null,
			'displayCacheSeconds' => HSPCC_Busy_Time::DISPLAY_TTL,
			'busyCalendarCount'   => count( $sources ),
			'writeTargetSelected' => self::write_configuration()['selected'],
			'writingEnabled'      => in_array( self::private_option( self::WRITING, false ), array( true, 1, '1' ), true ),
		);
	}

	/**
	 * Render one server-selected settings section for the selected employee.
	 *
	 * @return void No return value.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::select_requested_employee();
		$tab       = self::requested_tab();
		$employees = HSPCC_Employee_Context::choices();
		foreach ( self::configured_employees() as $employee ) {
			if ( ! isset( $employees[ $employee ] ) ) {
				/* translators: %1$d: Employee identifier. */
				$employees[ $employee ] = sprintf( __( 'Employee %1$d (not available in Amelia)', 'hsp-calendar-connection' ), $employee );
			}
		}
		/* translators: %1$d: Employee identifier. */
		$employee_name = $employees[ self::employee_id() ] ?? sprintf( __( 'Employee %1$d', 'hsp-calendar-connection' ), self::employee_id() );
		$connection    = self::status();
		$discovery     = self::discovery();
		$tabs          = array(
			'connection' => __( 'Connection settings', 'hsp-calendar-connection' ),
			'calendars'  => __( 'Calendar routing', 'hsp-calendar-connection' ),
			'runtime'    => __( 'Runtime settings', 'hsp-calendar-connection' ),
			'status'     => __( 'Connection status', 'hsp-calendar-connection' ),
		);
		$messages      = array(
			'credentials'    => __( 'Saved credentials are unavailable. Enter or replace them, then try again.', 'hsp-calendar-connection' ),
			'connection'     => __( 'The calendar server could not be reached or returned an unsupported response.', 'hsp-calendar-connection' ),
			'authentication' => __( 'The server rejected authentication or access. Check the CalDAV credentials.', 'hsp-calendar-connection' ),
			'redirect'       => __( 'The server requested a redirect. Redirects are disabled for this connection.', 'hsp-calendar-connection' ),
			'unsafe'         => __( 'The server did not return a supported same-origin calendar address.', 'hsp-calendar-connection' ),
			'xml'            => __( 'The server returned unsupported discovery data.', 'hsp-calendar-connection' ),
			'empty'          => __( 'Discovery completed, but no calendar collections were found.', 'hsp-calendar-connection' ),
			'discovered'     => __( 'Calendar discovery completed. Choose a calendar below.', 'hsp-calendar-connection' ),
			'selection'      => __( 'Choose a calendar from the current discovery results.', 'hsp-calendar-connection' ),
			'selected'       => __( 'Calendar choices saved. Run Check connection to verify availability access.', 'hsp-calendar-connection' ),
			'write_access'   => __( 'This login does not have write access to the chosen booking calendar.', 'hsp-calendar-connection' ),
			'migration'      => __( 'Existing managed appointments must be migrated before changing the booking destination.', 'hsp-calendar-connection' ),
			'timezone'       => __( 'Enter a valid calendar timezone, such as Europe/Vienna.', 'hsp-calendar-connection' ),
			'busy_ok'        => __( 'The free/busy availability check passed.', 'hsp-calendar-connection' ),
			'busy_error'     => __( 'Availability could not be verified. Affected booking times will stay unavailable while the connection is failing.', 'hsp-calendar-connection' ),
			'runtime'        => __( 'Runtime settings could not be saved.', 'hsp-calendar-connection' ),
			'runtime_saved'  => __( 'Runtime settings saved.', 'hsp-calendar-connection' ),
			'write_test'     => __( 'Test write access and remove the temporary event before enabling appointment storage.', 'hsp-calendar-connection' ),
			'probe_error'    => __( 'The write test could not be completed. If a temporary test is pending, remove it before trying again.', 'hsp-calendar-connection' ),
			'probe_created'  => __( 'The temporary event was created and updated. Remove the test event to complete verification.', 'hsp-calendar-connection' ),
			'cleanup_error'  => __( 'The temporary event could not be removed. Check the connection and retry cleanup.', 'hsp-calendar-connection' ),
			'probe_cleaned'  => __( 'Temporary event cleanup completed. Successful create/update verification now permits appointment storage.', 'hsp-calendar-connection' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized notice key resolved through the fixed message map.
		$status = isset( $_GET['hspcc_status'] ) && is_string( $_GET['hspcc_status'] ) ? sanitize_key( wp_unslash( $_GET['hspcc_status'] ) ) : '';
		echo '<div class="wrap"><h1>HSP Calendar Connection</h1>';
		if ( $employees ) {
			echo '<form class="hspcc-employee-selector" method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '">';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="hspcc_tab" value="' . esc_attr( $tab ) . '">';
			echo '<p><label for="hspcc-employee"><strong>' . esc_html__( 'Amelia employee', 'hsp-calendar-connection' ) . '</strong></label></p>';
			echo '<div class="hspcc-employee-controls"><select id="hspcc-employee" name="hspcc_employee">';
			foreach ( $employees as $employee => $name ) {
				echo '<option value="' . (int) $employee . '"' . selected( self::employee_id(), (int) $employee, false ) . '>' . esc_html( $name . ' (#' . (int) $employee . ')' ) . '</option>';
			}
			echo '</select>';
			submit_button( __( 'Show employee', 'hsp-calendar-connection' ), 'secondary', 'hspcc-show-employee', false );
			echo '</div></form><p class="hspcc-employee-context">';
			/* translators: %1$s: Selected employee name. */
			printf( esc_html__( 'Connection settings, calendar choices and status below belong to %1$s.', 'hsp-calendar-connection' ), esc_html( $employee_name ) );
			echo '</p>';
		}
		settings_errors( self::GROUP );
		if ( isset( $messages[ $status ] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $messages[ $status ] ) . '</p></div>';
		}
		if ( ! $employees ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Create an employee in Amelia before setting up a calendar connection.', 'hsp-calendar-connection' ) . '</p></div></div>';
			return;
		}
		echo '<nav class="nav-tab-wrapper hspcc-settings-tabs" aria-label="' . esc_attr__( 'Settings', 'hsp-calendar-connection' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$is_current = $tab === $key;
			echo '<a class="nav-tab' . ( $is_current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::settings_url( $key ) ) . '"' . ( $is_current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav><section class="hspcc-settings-panel" aria-labelledby="hspcc-section-' . esc_attr( $tab ) . '"><h2 id="hspcc-section-' . esc_attr( $tab ) . '">' . esc_html( $tabs[ $tab ] ) . '</h2>';
		switch ( $tab ) {
			case 'calendars':
				self::render_calendars( $discovery, $connection );
				break;
			case 'runtime':
				self::render_runtime( $connection, ! empty( $discovery['calendars'] ) );
				break;
			case 'status':
				self::render_status( $connection );
				break;
			default:
				self::render_connection( $employee_name, self::credentials() );
		}
		echo '</section></div>';
	}

	/**
	 * Render the native credential form and guarded calendar discovery action.
	 *
	 * @param string $employee_name Selected employee label.
	 * @param array  $credentials Current encrypted connection settings.
	 * @return void No return value.
	 */
	private static function render_connection( $employee_name, array $credentials ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
		echo self::employee_input();
		echo '<table class="form-table"><tr><th><label for="hspcc-endpoint">' . esc_html__( 'CalDAV server URL', 'hsp-calendar-connection' ) . '</label></th><td><input type="url" class="regular-text" id="hspcc-endpoint" name="' . esc_attr( self::option_name( self::OPTION ) ) . '[endpoint]" value="' . esc_attr( self::endpoint() ) . '" placeholder="https://calendar.example.com/caldav/" required><p class="description">' . esc_html__( 'Use the direct HTTPS CalDAV endpoint. Discovery stays on this server and port; redirects and private network addresses are blocked. Use a DNS hostname rather than an IPv6 literal.', 'hsp-calendar-connection' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Employee', 'hsp-calendar-connection' ) . '</th><td>' . esc_html( $employee_name . ' (#' . self::employee_id() . ')' ) . '<input type="hidden" name="' . esc_attr( self::option_name( self::OPTION ) ) . '[providerId]" value="' . (int) self::employee_id() . '"></td></tr>';
		echo '<tr><th><label for="hspcc-username">' . esc_html__( 'CalDAV username', 'hsp-calendar-connection' ) . '</label></th><td><input class="regular-text" id="hspcc-username" name="' . esc_attr( self::option_name( self::OPTION ) ) . '[username]" value="' . esc_attr( $credentials['username'] ?? '' ) . '" autocomplete="username" maxlength="255" required></td></tr>';
		echo '<tr><th><label for="hspcc-password">' . esc_html__( 'CalDAV password', 'hsp-calendar-connection' ) . '</label></th><td><input class="regular-text" type="password" id="hspcc-password" name="' . esc_attr( self::option_name( self::OPTION ) ) . '[password]" value="" autocomplete="new-password" maxlength="4096"><p class="description">';
		echo empty( $credentials['secret'] ) ? esc_html__( 'No password is stored.', 'hsp-calendar-connection' ) : esc_html__( 'A password is stored securely. Leave this field blank to keep it.', 'hsp-calendar-connection' );
		echo '</p></td></tr></table>';
		echo '<p>' . esc_html__( 'Changing this employee’s server or account resets their calendar choices and disables their runtime sync. Their managed bookings and unresolved jobs must be reconciled first. A different server requires password entry.', 'hsp-calendar-connection' ) . '</p>';
		submit_button( __( 'Save connection', 'hsp-calendar-connection' ), 'primary', 'hspcc-save-credentials' );
		echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="hspcc_discover">';
		wp_nonce_field( 'hspcc_discover' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
		echo self::employee_input();
		submit_button( __( 'Discover calendars', 'hsp-calendar-connection' ), 'secondary', 'hspcc-discover-calendars' );
		echo '</form>';
	}

	/**
	 * Render calendar routing, write verification and queued appointment status.
	 *
	 * @param array $discovery Fingerprint-bound calendar discovery result.
	 * @param array $connection Sanitized current connection status.
	 * @return void No return value.
	 */
	private static function render_calendars( array $discovery, array $connection ) {
		if ( empty( $discovery['calendars'] ) ) {
			echo '<p>' . esc_html__( 'Save a connection and discover calendars first.', 'hsp-calendar-connection' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="hspcc_select">';
		wp_nonce_field( 'hspcc_select' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
		echo self::employee_input();
		$busy_keys = self::source_keys();
		echo '<fieldset><legend><strong>' . esc_html__( 'Read busy times from', 'hsp-calendar-connection' ) . '</strong></legend><p>' . esc_html__( 'Choose one or more calendars, up to ten.', 'hsp-calendar-connection' ) . '</p>';
		foreach ( $discovery['calendars'] as $key => $calendar ) {
			echo '<label><input type="checkbox" name="busy_calendars[]" value="' . esc_attr( $key ) . '"' .
				checked( in_array( $key, $busy_keys, true ), true, false ) . ( empty( $calendar['freeBusy'] ) ? ' disabled' : '' ) . '> ' .
				esc_html( $calendar['name'] ? $calendar['name'] : __( 'Unnamed calendar', 'hsp-calendar-connection' ) ) . ( empty( $calendar['freeBusy'] ) ? ' ' . esc_html__( '(free/busy access unavailable)', 'hsp-calendar-connection' ) : '' ) . '</label><br>';
		}
		echo '</fieldset><p><label for="hspcc-write-calendar"><strong>' . esc_html__( 'Store Amelia bookings in', 'hsp-calendar-connection' ) . '</strong></label></p>';
		echo '<select id="hspcc-write-calendar" name="write_calendar"><option value="">' . esc_html__( 'Choose a destination later', 'hsp-calendar-connection' ) . '</option>';
		foreach ( $discovery['calendars'] as $key => $calendar ) {
			$read_only = ! empty( $calendar['privilegesChecked'] ) && empty( $calendar['canWrite'] );
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $discovery['writeSelected'] ?? '', $key, false ) . ( $read_only ? ' disabled' : '' ) . '>' .
				esc_html( $calendar['name'] ? $calendar['name'] : __( 'Unnamed calendar', 'hsp-calendar-connection' ) ) . ( $read_only ? ' ' . esc_html__( '(read only)', 'hsp-calendar-connection' ) : '' ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Use the same CalDAV login for both. Appointment changes and cancellations are managed in Amelia.', 'hsp-calendar-connection' ) . '</p>';
		echo '<p><label for="hspcc-timezone"><strong>' . esc_html__( 'Default calendar timezone', 'hsp-calendar-connection' ) . '</strong></label></p><input id="hspcc-timezone" class="regular-text" name="fallback_timezone" value="' . esc_attr( self::fallback_timezone() ) . '"><p class="description">' . esc_html__( 'Used for floating and all-day entries when the server does not publish a calendar timezone. A new connection starts with the WordPress site timezone.', 'hsp-calendar-connection' ) . '</p>';
		submit_button( __( 'Save calendar choices', 'hsp-calendar-connection' ), 'secondary', 'hspcc-save-calendar' );
		echo '</form>';
		echo '<p>' . ( $connection['writingEnabled'] ? esc_html__( 'Appointment storage is enabled.', 'hsp-calendar-connection' ) : esc_html__( 'Appointment storage is not enabled yet.', 'hsp-calendar-connection' ) ) . '</p>';
		if ( self::write_verified() ) {
			echo '<p>' . esc_html__( 'The selected destination passed create, update and cleanup checks.', 'hsp-calendar-connection' ) . '</p>';
		}
		if ( self::private_option( self::PROBE, false ) ) {
			echo '<p><strong>' . esc_html__( 'A temporary connection test still requires cleanup.', 'hsp-calendar-connection' ) . '</strong></p>'; }
		if ( $connection['writeTargetSelected'] ) {
			$pending = (bool) self::private_option( self::PROBE, false );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . ( $pending ? 'hspcc_cleanup' : 'hspcc_probe' ) . '">';
			wp_nonce_field( $pending ? 'hspcc_cleanup' : 'hspcc_probe' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
			echo self::employee_input();
			echo '<p class="description">' . esc_html__( 'The write test creates and updates a temporary event containing no customer details. Remove it to finish verification.', 'hsp-calendar-connection' ) . '</p>';
			submit_button( $pending ? esc_html__( 'Remove test event', 'hsp-calendar-connection' ) : esc_html__( 'Test write access', 'hsp-calendar-connection' ), 'secondary', 'hspcc-test-writing' );
			echo '</form>';
		}
		if ( $connection['writingEnabled'] ) {
			$queue = HSPCC_Sync_Queue::summary( self::employee_id() );
			echo '<p>';
			/* translators: 1: Pending job count, 2: Retrying job count, 3: Conflict count. */
			printf( esc_html__( 'Appointment sync: %1$d pending, %2$d retrying, %3$d conflicts.', 'hsp-calendar-connection' ), (int) ( $queue['pending'] ?? 0 ), (int) ( $queue['retrying'] ?? 0 ), (int) ( $queue['conflicts'] ?? 0 ) );
			echo '</p>';
			if ( ! empty( $queue['workerError'] ) || ! empty( $queue['errors'] ) ) {
				echo '<p>' . esc_html__( 'Some appointment entries need attention. Saved jobs are retained for recovery.', 'hsp-calendar-connection' ) . '</p>'; }
		}
	}

	/**
	 * Render feature toggles, retaining the ability to disable an unavailable connection.
	 *
	 * @param array $connection Sanitized current connection status.
	 * @param bool  $has_calendars Whether calendar discovery has usable collections.
	 * @return void No return value.
	 */
	private static function render_runtime( array $connection, $has_calendars ) {
		if ( ! $has_calendars ) {
			echo '<p>' . esc_html__( 'Save a connection and discover calendars first.', 'hsp-calendar-connection' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="hspcc_runtime">';
		wp_nonce_field( 'hspcc_runtime' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
		echo self::employee_input();
		echo '<p><label><input type="checkbox" name="enable_blocking" value="1"' . checked( $connection['enabled'], true, false ) . '> ' . esc_html__( 'Block busy times from the selected calendars', 'hsp-calendar-connection' ) . '</label></p>';
			echo '<p><label><input type="checkbox" name="enable_writing" value="1"' . checked( $connection['writingEnabled'], true, false ) . '> ' . esc_html__( 'Store Amelia appointments in the selected destination', 'hsp-calendar-connection' ) . '</label></p>';
		submit_button( __( 'Save runtime settings', 'hsp-calendar-connection' ), 'secondary', 'hspcc-save-runtime' );
		echo '</form>';
	}

	/**
	 * Render current availability health, supported booking scope and connection checking.
	 *
	 * @param array $connection Sanitized current connection status.
	 * @return void No return value.
	 */
	private static function render_status( array $connection ) {
		if ( ! $connection['configured'] ) {
			echo '<p>' . esc_html__( 'Save a connection and discover calendars first.', 'hsp-calendar-connection' ) . '</p>';
		}
		echo '<p><strong>' . ( $connection['enabled'] ? esc_html__( 'Busy-time checks are enabled for this employee.', 'hsp-calendar-connection' ) : esc_html__( 'Busy-time checks are not enabled for this employee.', 'hsp-calendar-connection' ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'The checked calendars block availability. A separate destination receives Amelia appointment entries when storage is enabled. Slot displays may use a validated result for up to 60 seconds; appointments receive a fresh availability check before saving.', 'hsp-calendar-connection' ) . '</p>';
		echo '<p>' . esc_html__( 'If the destination is also checked, this employee’s managed entries are excluded from their external busy periods; Amelia checks those appointments itself. Other employees’ entries remain busy.', 'hsp-calendar-connection' ) . '</p>';
		echo '<p>' . esc_html__( 'Working hours and public booking availability are managed separately in Amelia.', 'hsp-calendar-connection' ) . '</p>';
		echo '<p>' . esc_html__( 'Supported on Amelia 9.9.1: individual fixed-duration free appointments and capacity-one fixed-price appointments with on-site payment. Customer rescheduling requires the same service, employee and location. Online payments, packages, recurring appointments and other reassignment flows are not supported.', 'hsp-calendar-connection' ) . '</p>';
		if ( $connection['checkedAt'] ) {
			echo '<p>';
			/* translators: %s: Date and time of the last availability check. */
			printf( esc_html__( 'Last availability check: %1$s', 'hsp-calendar-connection' ), esc_html( wp_date( 'Y-m-d H:i:s T', $connection['checkedAt'] ) ) );
			echo ' — ' . ( $connection['lastCheckOk'] ? esc_html__( 'Passed.', 'hsp-calendar-connection' ) : esc_html__( 'Failed; please check the connection.', 'hsp-calendar-connection' ) ) . '</p>';
		}
		if ( $connection['configured'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="hspcc_check">';
			wp_nonce_field( 'hspcc_check' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed hidden fields with an integer employee ID and an escaped allowlisted section.
			echo self::employee_input();
			submit_button( __( 'Check connection', 'hsp-calendar-connection' ), 'secondary', 'hspcc-check-connection' );
			echo '</form>';
		}
	}
}
require_once __DIR__ . '/includes/class-hspcc-free-busy.php';
require_once __DIR__ . '/includes/class-hspcc-endpoint.php';
require_once __DIR__ . '/includes/class-hspcc-employee-context.php';
require_once __DIR__ . '/includes/class-hspcc-busy-time.php';
require_once __DIR__ . '/includes/class-hspcc-amelia.php';
require_once __DIR__ . '/includes/class-hspcc-calendar-storage.php';
require_once __DIR__ . '/includes/class-hspcc-sync-queue.php';
require_once __DIR__ . '/includes/class-hspcc-amelia-sync.php';
require_once __DIR__ . '/includes/class-hspcc-overlap-busy.php';
HSP_Calendar_Connection::boot();
register_activation_hook( __FILE__, array( 'HSP_Calendar_Connection', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HSPCC_Sync_Queue', 'deactivate' ) );
