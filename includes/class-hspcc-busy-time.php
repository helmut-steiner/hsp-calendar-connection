<?php
/**
 * Employee-scoped calendar busy-time services.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read and merge employee-scoped calendar busy intervals.
 */
final class HSPCC_Busy_Time {
	const DISPLAY_TTL  = 60;
	const MAX_RANGE    = 367 * 86400;
	const REPORT_RANGE = 31 * 86400;
	/**
	 * Validated busy-time results reused within the current request.
	 *
	 * @var array
	 */
	private static $memory = array();

	/**
	 * Read, cache and merge the selected calendars' busy intervals.
	 *
	 * @param mixed $start Inclusive UTC start timestamp.
	 * @param mixed $end Exclusive UTC end timestamp.
	 * @param mixed $force_fresh Whether a save guard requires fresh remote data.
	 * @return array|WP_Error
	 */
	public static function intervals( $start, $end, $force_fresh = false ) {
		if ( ! is_int( $start ) || ! is_int( $end ) || $start <= 0 || $end <= $start || $end - $start > self::MAX_RANGE ) {
			return self::failure( 'hspcc_window' );
		}
		$provider = HSP_Calendar_Connection::provider_id();
		if ( ! is_int( $provider ) || $provider < 1 ) {
			return self::failure( 'hspcc_provider' ); }
		$identity = HSP_Calendar_Connection::connection_identity();
		if ( '' === $identity ) {
			return self::failure( 'hspcc_selection' ); }
		// Display requests share UTC-day-bounded cache entries; saving uses the exact interval.
		$query_start  = $force_fresh ? $start : intdiv( $start, 86400 ) * 86400;
		$query_end    = $force_fresh ? $end : ( intdiv( $end - 1, 86400 ) + 1 ) * 86400;
		$all          = array();
		$oldest_check = time();
		foreach ( HSP_Calendar_Connection::source_keys() as $source_key ) {
			$source_identity = HSP_Calendar_Connection::source_identity( $source_key );
			if ( '' === $source_identity ) {
				return self::failure( 'hspcc_selection', $identity ); }
			for ( $from = $query_start; $from < $query_end; $from = $to ) {
				$to     = min( $from + self::REPORT_RANGE, $query_end );
				$key    = 'hspcc_busy_' . substr( hash( 'sha256', 'v2|' . $source_identity . '|' . $provider . '|' . $from . '|' . $to ), 0, 40 );
				$cached = null;
				if ( ! $force_fresh ) {
					$cached = self::$memory[ $key ] ?? get_transient( $key );
					if ( ! self::valid_cache( $cached, $from, $to ) ) {
						$cached = null; }
				}
				if ( null !== $cached ) {
					$periods      = $cached['periods'];
					$oldest_check = min( $oldest_check, $cached['checkedAt'] );
				} else {
					$response = HSP_Calendar_Connection::report( $from, $to, $source_key );
					if ( is_wp_error( $response ) ) {
						return self::failure( $response->get_error_code(), $identity ); }
					$periods = HSPCC_Free_Busy::parse( $response, $from, $to );
					unset( $response );
					if ( is_wp_error( $periods ) ) {
						return self::failure( $periods->get_error_code(), $identity ); }
					if ( ! $force_fresh ) {
						$entry                = array(
							'checkedAt' => time(),
							'start'     => $from,
							'end'       => $to,
							'periods'   => $periods,
						);
						self::$memory[ $key ] = $entry;
						set_transient( $key, $entry, self::DISPLAY_TTL );
					}
				}
				foreach ( $periods as $period ) {
					$left  = max( $start, $period[0] );
					$right = min( $end, $period[1] );
					if ( $right > $left ) {
						$all[] = array( $left, $right ); }
				}
			}
		}
		usort(
			$all,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$merged = array();
		foreach ( $all as $period ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $period[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $period[1] );
			} else {
				$merged[] = $period; }
		}
		HSP_Calendar_Connection::set_private_option(
			HSP_Calendar_Connection::HEALTH,
			array(
				'ok'            => true,
				'checkedAt'     => $oldest_check,
				'periodCount'   => count( $merged ),
				'windowSeconds' => $end - $start,
				'freshForSave'  => (bool) $force_fresh,
				'identity'      => $identity,
			)
		);
		return $merged;
	}

	/**
	 * Validate the identity, time window and bounds of a cached result.
	 *
	 * @param mixed $value Value to validate.
	 * @param mixed $start Inclusive UTC start timestamp.
	 * @param mixed $end Exclusive UTC end timestamp.
	 * @return mixed
	 */
	private static function valid_cache( $value, $start, $end ) {
		if ( ! is_array( $value ) || ! is_int( $value['checkedAt'] ?? null ) ||
			$value['checkedAt'] > time() || time() - $value['checkedAt'] >= self::DISPLAY_TTL ||
			( $value['start'] ?? null ) !== $start || ( $value['end'] ?? null ) !== $end || ! is_array( $value['periods'] ?? null ) ) {
			return false; }
		foreach ( $value['periods'] as $period ) {
			if ( ! is_array( $period ) || count( $period ) !== 2 || ! is_int( $period[0] ?? null ) || ! is_int( $period[1] ?? null ) ||
				$period[0] < $start || $period[1] > $end || $period[1] <= $period[0] ) {
				return false; }
		}
		return true;
	}

	/**
	 * Record a sanitized availability failure without exposing calendar data.
	 *
	 * @param mixed $code Sanitized error or HTTP status code.
	 * @param mixed $identity Verified connection or event identity.
	 * @return mixed
	 */
	private static function failure( $code, $identity = '' ) {
		HSP_Calendar_Connection::set_private_option(
			HSP_Calendar_Connection::HEALTH,
			array(
				'ok'        => false,
				'checkedAt' => time(),
				'code'      => sanitize_key( $code ),
				'identity'  => $identity,
			)
		);
		return new WP_Error( 'hspcc_unavailable', __( 'Calendar availability could not be verified. Please try again or send an enquiry.', 'hsp-calendar-connection' ) );
	}
}
