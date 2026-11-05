<?php
/** Private connection context; runtime callers scope by canonical employee ID.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage canonical per-employee execution and option scope.
 */
final class HSPCC_Employee_Context {
	private const REGISTRY = 'hsp_calendar_employee_connections';
	private const MARKER   = 'hsp_calendar_employee_connection_';
	/**
	 * Current canonical employee scope.
	 *
	 * @var int
	 */
	private static $employee = 1;
	/**
	 * Cached native employee labels for the current request.
	 *
	 * @var mixed
	 */
	private static $choices = null;

	/**
	 * Read the employee selected for the current execution scope.
	 *
	 * @return int
	 */
	public static function id(): int {
		return self::$employee; }

	/**
	 * Select a validated employee for the current execution scope.
	 *
	 * @param int $employee Positive canonical employee identifier.
	 * @return void
	 * @throws InvalidArgumentException If the supplied input fails validation.
	 */
	public static function select( int $employee ): void {
		if ( $employee < 1 ) {
			throw new InvalidArgumentException( 'A positive employee ID is required.' ); }
		self::$employee = $employee;
	}

	/**
	 * Run a callback for one employee and restore the previous scope.
	 *
	 * @param int      $employee Positive canonical employee identifier.
	 * @param callable $callback Operation to execute in the temporary employee scope.
	 * @return mixed
	 */
	public static function run( int $employee, callable $callback ) {
		$previous = self::$employee;
		self::select( $employee );
		try {
			return $callback(); } finally {
			self::$employee = $previous; }
	}

	/**
	 * Resolve an option name using the current employee-one storage namespace.
	 *
	 * @param string   $base Unscoped private option name.
	 * @param int|null $employee Positive canonical employee identifier.
	 * @return string
	 * @throws InvalidArgumentException If the supplied input fails validation.
	 */
	public static function option_name( string $base, ?int $employee = null ): string {
		$employee = $employee ?? self::$employee;
		if ( $employee < 1 ) {
			throw new InvalidArgumentException( 'A positive employee ID is required.' ); }
		// Preserve the installed connection and managed records for employee 1.
		return 1 === $employee ? $base : $base . '_employee_' . $employee;
	}

	/**
	 * Record an employee connection using independent durable markers.
	 *
	 * @param int $employee Positive canonical employee identifier.
	 * @return void
	 */
	public static function remember( int $employee ): void {
		if ( $employee < 1 ) {
			return; }
		add_option( self::MARKER . $employee, true, '', false );
		$ids   = get_option( self::REGISTRY, array() );
		$ids   = is_array( $ids ) ? $ids : array();
		$ids[] = $employee;
		$ids   = array_values( array_unique( array_filter( $ids, static fn( $id ) => is_int( $id ) && $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		update_option( self::REGISTRY, $ids, false );
	}

	/**
	 * Read configured employee identifiers, including markers missed by a racing save.
	 *
	 * @return array
	 */
	public static function ids(): array {
		$ids   = get_option( self::REGISTRY, array() );
		$ids   = is_array( $ids ) ? $ids : array();
		$ids[] = 1;
		// Per-employee markers are authoritative if simultaneous saves raced
		// while updating the registry cache. Read owned option names only.
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'esc_like' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh owned markers repair registry races; the Options API cannot enumerate option names.
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::MARKER ) . '%' ) );
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( is_string( $name ) && preg_match( '/^' . self::MARKER . '([1-9][0-9]*)$/D', $name, $match ) ) {
					$id = filter_var( $match[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
					if ( false !== $id ) {
						$ids[] = $id; }
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => is_int( $id ) && $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Read named employee choices from Amelia's authoritative provider repository.
	 *
	 * @return array
	 */
	public static function choices(): array {
		if ( null !== self::$choices ) {
			return self::$choices; }
		self::$choices = array();
		if ( ! defined( 'AMELIA_PATH' ) ) {
			return self::$choices; }
		try {
			$container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
			$employees = $container->get( 'domain.users.providers.repository' )->getAll()->toArray();
			foreach ( $employees as $employee ) {
				if ( ! is_array( $employee ) ) {
					continue; }
				$id = filter_var( $employee['id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id ) {
					continue; }
				$name = trim( (string) ( $employee['firstName'] ?? '' ) . ' ' . (string) ( $employee['lastName'] ?? '' ) );
				/* translators: %1$d: Amelia employee identifier. */
				self::$choices[ $id ] = '' === $name ? sprintf( __( 'Employee %1$d', 'hsp-calendar-connection' ), $id ) : $name;
			}
		} catch ( Throwable $error ) {
			self::$choices = array(); }
		return self::$choices;
	}
}
