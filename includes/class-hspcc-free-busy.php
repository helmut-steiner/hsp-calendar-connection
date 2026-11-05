<?php
/**
 * Bounded RFC 4791 VFREEBUSY reader. No transport, storage, logging or VEVENT fallback.
 * Sources: RFC 5545 §§3.1, 3.3.6, 3.3.9, 3.6.4, 3.8.2.6; RFC 4791 §7.10.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse bounded and strict VFREEBUSY responses.
 */
final class HSPCC_Free_Busy {
	const MAX_BYTES     = 1048576;
	const MAX_LINES     = 20000;
	const MAX_PERIODS   = 10000;
	const MAX_TIMESTAMP = 253402300799; // 9999-12-31 23:59:59 UTC.
	const MAX_DURATION  = 315537897599; // Bounded before integer multiplication.

	/**
	 * Parse a bounded VFREEBUSY response into merged UTC intervals.
	 *
	 * @param string $body Bounded protocol response or serialized event body.
	 * @param int    $window_start Inclusive UTC query start.
	 * @param int    $window_end Exclusive UTC query end.
	 * @return array|WP_Error
	 */
	public static function parse( string $body, int $window_start, int $window_end ): array|WP_Error {
		try {
			if ( PHP_INT_SIZE < 8 || $window_end <= $window_start || '' === $body ||
				strlen( $body ) > self::MAX_BYTES ||
				preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body ) ||
				preg_match( '/\r(?!\n)/', $body ) ) {
				self::invalid(); }
			$lines = preg_split( '/\r\n|\n/', rtrim( $body, "\r\n" ) );
			if ( ! $lines || count( $lines ) > self::MAX_LINES ) {
				self::invalid(); }
			$unfolded = array();
			foreach ( $lines as $line ) {
				if ( '' === $line ) {
					self::invalid(); }
				if ( ' ' === $line[0] || "\t" === $line[0] ) {
					if ( ! $unfolded ) {
						self::invalid(); }
					$unfolded[ count( $unfolded ) - 1 ] .= substr( $line, 1 );
				} else {
					$unfolded[] = $line; }
			}
			// RFC folding may split a UTF-8 sequence; validate text after unfolding.
			if ( preg_match( '//u', implode( "\n", $unfolded ) ) !== 1 ) {
				self::invalid(); }
			$state        = 0;
			$calendar     = array();
			$single       = array();
			$range        = array();
			$intervals    = array();
			$period_count = 0;
			foreach ( $unfolded as $line ) {
				[$name, $params, $value] = self::content_line( $line );
				if ( 'BEGIN' === $name || 'END' === $name ) {
					if ( $params ) {
						self::invalid(); }
					$component = strtoupper( $value );
					if ( 'BEGIN' === $name && 'VCALENDAR' === $component && 0 === $state ) {
						$state = 1; } elseif ( 'BEGIN' === $name && 'VFREEBUSY' === $component && 1 === $state ) {
						$state = 2; } elseif ( 'END' === $name && 'VFREEBUSY' === $component && 2 === $state ) {
							$state = 3; } elseif ( 'END' === $name && 'VCALENDAR' === $component && 3 === $state ) {
							$state = 4; } else {
											self::invalid(); }
							continue;
				}
				if ( 1 === $state ) {
					if ( str_starts_with( $name, 'X-' ) ) {
						continue; }
					if ( ! in_array( $name, array( 'VERSION', 'PRODID', 'CALSCALE', 'METHOD' ), true ) ||
						isset( $calendar[ $name ] ) || $params || '' === $value ) {
						self::invalid(); }
					if ( ( 'VERSION' === $name && '2.0' !== $value ) ||
						( 'CALSCALE' === $name && strtoupper( $value ) !== 'GREGORIAN' ) ||
						( 'METHOD' === $name && ! in_array( strtoupper( $value ), array( 'REPLY', 'PUBLISH' ), true ) ) ) {
						self::invalid(); }
					$calendar[ $name ] = true;
					continue;
				}
				if ( 2 !== $state ) {
					self::invalid(); }
				if ( 'FREEBUSY' === $name ) {
					foreach ( $params as $key => $unused ) {
						if ( ! in_array( $key, array( 'FBTYPE', 'VALUE' ), true ) && ! str_starts_with( $key, 'X-' ) ) {
							self::invalid(); }
					}
					if ( isset( $params['VALUE'] ) && strtoupper( self::token( $params['VALUE'] ) ) !== 'PERIOD' ) {
						self::invalid(); }
					$type = isset( $params['FBTYPE'] ) ? strtoupper( self::token( $params['FBTYPE'] ) ) : 'BUSY';
					// RFC 5545 §3.2.9: unrecognized FBTYPE is treated as BUSY, never free.
					foreach ( explode( ',', $value ) as $period ) {
						if ( ++$period_count > self::MAX_PERIODS ) {
							self::invalid(); }
						[$start, $end] = self::period( $period );
						if ( 'FREE' === $type ) {
							continue; } // FREE never subtracts an overlapping busy period.
						$start = max( $start, $window_start );
						$end   = min( $end, $window_end );
						if ( $start < $end ) {
							$intervals[] = array( $start, $end ); }
					}
					continue;
				}
				if ( in_array( $name, array( 'DTSTAMP', 'DTSTART', 'DTEND', 'UID', 'CONTACT', 'ORGANIZER', 'URL' ), true ) ) {
					if ( isset( $single[ $name ] ) || '' === $value ) {
						self::invalid(); }
					$single[ $name ] = true;
					if ( in_array( $name, array( 'DTSTAMP', 'DTSTART', 'DTEND' ), true ) ) {
						foreach ( $params as $key => $unused ) {
							if ( 'VALUE' !== $key && ! str_starts_with( $key, 'X-' ) ) {
								self::invalid(); }
						}
						if ( isset( $params['VALUE'] ) && strtoupper( self::token( $params['VALUE'] ) ) !== 'DATE-TIME' ) {
							self::invalid(); }
						$range[ $name ] = self::utc( $value );
					}
					continue;
				}
				if ( 'REQUEST-STATUS' === $name ) {
					if ( ! preg_match( '/^2\.[0-9]+(?:\.[0-9]+)?;.+$/sD', $value ) ) {
						self::invalid(); }
					continue;
				}
				if ( in_array( $name, array( 'ATTENDEE', 'COMMENT' ), true ) || str_starts_with( $name, 'X-' ) ) {
					continue; }
				// RRULE/RDATE/EXDATE, other components and unsupported semantic properties fail closed.
				self::invalid();
			}
			// UID is optional here: RFC 4791's REPORT response example and Synology omit it.
			if ( 4 !== $state || ! isset( $calendar['VERSION'], $calendar['PRODID'], $single['DTSTAMP'] ) ||
				( isset( $range['DTSTART'], $range['DTEND'] ) && $range['DTEND'] <= $range['DTSTART'] ) ||
				( isset( $range['DTSTART'] ) && $range['DTSTART'] > $window_start ) ||
				( isset( $range['DTEND'] ) && $range['DTEND'] < $window_end ) ) {
				self::invalid(); }
			usort(
				$intervals,
				static function ( $a, $b ) {
					$comparison = $a[0] <=> $b[0];
					return 0 !== $comparison ? $comparison : ( $a[1] <=> $b[1] );
				}
			);
			$merged = array();
			foreach ( $intervals as [$start, $end] ) {
				$last = count( $merged ) - 1;
				if ( $last >= 0 && $start <= $merged[ $last ][1] ) {
					$merged[ $last ][1] = max( $end, $merged[ $last ][1] ); } else {
					$merged[] = array( $start, $end ); }
			}
			return $merged;
		} catch ( Throwable $e ) {
			return new WP_Error( 'hspcc_free_busy_invalid', __( 'Calendar busy-time response could not be validated.', 'hsp-calendar-connection' ) );
		}
	}

	/**
	 * Split a content line while preserving quoted parameter values.
	 *
	 * @param string $text Protocol text to escape or split.
	 * @param string $delimiter Content-line separator.
	 * @param bool   $first Whether only the first separator is significant.
	 * @return array
	 */
	private static function split_outside( string $text, string $delimiter, bool $first = false ): array {
		$quoted = false;
		$parts  = array();
		$offset = 0;
		for ( $i = 0, $length = strlen( $text ); $i < $length; $i++ ) {
			if ( '"' === $text[ $i ] ) {
				$quoted = ! $quoted; } elseif ( $text[ $i ] === $delimiter && ! $quoted ) {
				$parts[] = substr( $text, $offset, $i - $offset );
				$offset  = $i + 1;
				if ( $first ) {
					$parts[] = substr( $text, $offset );
					return $parts; }
				}
		}
		if ( $quoted ) {
			self::invalid(); }
		$parts[] = substr( $text, $offset );
		return $parts;
	}

	/**
	 * Validate and split one iCalendar content line.
	 *
	 * @param string $line One iCalendar content line.
	 * @return array
	 */
	private static function content_line( string $line ): array {
		$parts = self::split_outside( $line, ':', true );
		if ( count( $parts ) !== 2 ) {
			self::invalid(); }
		[$head, $value] = $parts;
		$head           = self::split_outside( $head, ';' );
		$name           = strtoupper( array_shift( $head ) );
		if ( ! preg_match( '/^[A-Z0-9-]+$/D', $name ) ) {
			self::invalid(); }
		$params = array();
		foreach ( $head as $parameter ) {
			if ( ! preg_match( '/^([A-Za-z0-9-]+)=(.+)$/sD', $parameter, $match ) ) {
				self::invalid(); }
			$key = strtoupper( $match[1] );
			if ( isset( $params[ $key ] ) || ! preg_match( '/^(?:"[^"]*"|[^":;,]+)(?:,(?:"[^"]*"|[^":;,]+))*$/D', $match[2] ) ) {
				self::invalid(); }
			$params[ $key ] = $match[2];
		}
		return array( $name, $params, $value );
	}

	/**
	 * Validate an iCalendar property or parameter token.
	 *
	 * @param string $value Value to validate.
	 * @return string
	 */
	private static function token( string $value ): string {
		if ( strlen( $value ) > 1 && '"' === $value[0] && substr( $value, -1 ) === '"' ) {
			$value = substr( $value, 1, -1 ); }
		if ( ! preg_match( '/^[A-Za-z0-9-]+$/D', $value ) ) {
			self::invalid(); }
		return $value;
	}

	/**
	 * Parse a strict UTC iCalendar date-time.
	 *
	 * @param string $value Value to validate.
	 * @return int
	 */
	private static function utc( string $value ): int {
		if ( ! preg_match( '/^[0-9]{8}T[0-9]{6}Z$/D', $value ) ) {
			self::invalid(); }
		$date   = DateTimeImmutable::createFromFormat( '!Ymd\THis\Z', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ||
			$date->format( 'Ymd\THis\Z' ) !== $value || (int) substr( $value, 0, 4 ) < 1 ) {
			self::invalid(); }
		return $date->getTimestamp();
	}

	/**
	 * Parse one free-busy period into start and end timestamps.
	 *
	 * @param string $value Value to validate.
	 * @return array
	 */
	private static function period( string $value ): array {
		$parts = explode( '/', $value );
		if ( count( $parts ) !== 2 || '' === $parts[0] || '' === $parts[1] ) {
			self::invalid(); }
		$start = self::utc( $parts[0] );
		if ( preg_match( '/^\+?P/', $parts[1] ) ) {
			$duration = self::duration( $parts[1] );
			if ( $duration > self::MAX_TIMESTAMP - $start ) {
				self::invalid(); }
			$end = $start + $duration;
		} else {
			$end = self::utc( $parts[1] ); }
		if ( $end <= $start ) {
			self::invalid(); }
		return array( $start, $end );
	}

	/**
	 * Parse a bounded RFC 5545 duration in seconds.
	 *
	 * @param string $value Value to validate.
	 * @return int
	 */
	private static function duration( string $value ): int {
		if ( ! preg_match( '/^\+?P(?:(?<week>[0-9]+)W|(?:(?<day>[0-9]+)D)?(?:T(?:(?<hour>[0-9]+)H)?(?:(?<minute>[0-9]+)M)?(?:(?<second>[0-9]+)S)?)?)$/D', $value, $parts ) ||
			str_ends_with( $value, 'T' ) ) {
			self::invalid(); }
		// RFC 5545's dur-hour may contain seconds only through a dur-minute.
		if ( ( $parts['hour'] ?? '' ) !== '' && ( $parts['second'] ?? '' ) !== '' && ( $parts['minute'] ?? '' ) === '' ) {
			self::invalid(); }
		$total = 0;
		foreach ( array(
			'week'   => 604800,
			'day'    => 86400,
			'hour'   => 3600,
			'minute' => 60,
			'second' => 1,
		) as $key => $unit ) {
			$digits = ltrim( $parts[ $key ] ?? '', '0' );
			if ( strlen( $digits ) > 12 ) {
				self::invalid(); }
			$amount = '' === $digits ? 0 : (int) $digits;
			if ( $amount > intdiv( self::MAX_DURATION - $total, $unit ) ) {
				self::invalid(); }
			$total += $amount * $unit;
		}
		if ( $total <= 0 ) {
			self::invalid(); }
		return $total;
	}

	/**
	 * Reject malformed or unsupported free-busy input.
	 *
	 * @return void
	 * @throws UnexpectedValueException If the supplied input fails validation.
	 */
	private static function invalid(): void {
		throw new UnexpectedValueException( 'Unsupported free/busy response.' );
	}
}
