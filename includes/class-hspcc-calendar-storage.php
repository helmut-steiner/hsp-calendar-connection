<?php
/**
 * Stateless, conditional CalDAV storage for native appointment projections.
 *
 * The queue owns site UUID, canonical reads, versions, mappings and retries.
 * Main supplies configuration/transport closures; this class reads no options,
 * credentials or appointments and never follows a remote resource URL.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maintain conditional, independently verifiable owned calendar events.
 */
final class HSPCC_Calendar_Storage {
	/**
	 * Injected constrained calendar request callback.
	 *
	 * @var mixed
	 */
	private static $transport = null;
	/**
	 * Injected current employee configuration provider.
	 *
	 * @var mixed
	 */
	private static $configuration = null;
	private const MAX_BODY_BYTES  = 65536;

	/**
	 * Inject the constrained calendar request callback.
	 *
	 * @param callable $transport Constrained calendar transport callback.
	 * @return void
	 */
	public static function set_transport( callable $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * Inject the current employee's write-configuration provider.
	 *
	 * @param callable $configuration Current employee configuration callback.
	 * @return void
	 */
	public static function set_configuration( callable $configuration ): void {
		self::$configuration = $configuration;
	}

	/**
	 * Determine whether a verified write destination is available.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return self::target_key() !== '';
	}

	/**
	 * Read the current verified calendar destination key.
	 *
	 * @return string
	 */
	public static function target_key(): string {
		if ( ! is_callable( self::$configuration ) ) {
			return ''; }
		try {
			$config = call_user_func( self::$configuration );
			return is_array( $config ) && ( $config['ready'] ?? false ) === true &&
				self::calendar_key( $config['calendarKey'] ?? null ) ? $config['calendarKey'] : '';
		} catch ( Throwable $error ) {
			return ''; }
	}

	/**
	 * Apply a canonical appointment job using conditional remote writes.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	public static function sync( array $job ): array|WP_Error {
		$canonical = self::canonical( $job );
		if ( $canonical instanceof WP_Error ) {
			return $canonical; }
		if ( 'delete' === $canonical['action'] && null === $canonical['mapping'] ) {
			return self::receipt( $canonical, 'absent', '' );
		}
		if ( self::target_key() === '' || self::target_key() !== $canonical['calendarKey'] ) {
			return self::error( 'disabled' );
		}
		if ( ! is_callable( self::$transport ) ) {
			return self::error( 'transport_unavailable', true ); }
		return 'delete' === $canonical['action'] ? self::remove( $canonical ) : self::upsert( $canonical );
	}

	/**
	 * Serialize a canonical upsert job without performing I/O.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return string|WP_Error
	 */
	public static function serialize( array $job ): string|WP_Error {
		$canonical = self::canonical( $job );
		if ( $canonical instanceof WP_Error ) {
			return $canonical; }
		if ( 'upsert' !== $canonical['action'] ) {
			return self::error( 'invalid_input' ); }
		return self::calendar_body( $canonical );
	}

	/**
	 * Create a durable, payload-free description of an intended write.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	public static function intent( array $job ): array|WP_Error {
		$canonical = self::canonical( $job );
		if ( $canonical instanceof WP_Error ) {
			return $canonical; }
		$previous = $canonical['mapping'];
		if ( 'delete' === $canonical['action'] && ( null === $previous || ! self::source_hash( $previous['sourceHash'] ?? null ) ) ) {
			return self::error( 'invalid_mapping' ); // An unmapped tombstone needs no remote intent.
		}
		$intent = array(
			'siteUuid'           => $canonical['siteUuid'],
			'appointmentId'      => $canonical['appointmentId'],
			'calendarKey'        => $canonical['calendarKey'],
			'pathSuffix'         => $canonical['pathSuffix'],
			'uid'                => $canonical['uid'],
			'expectedSourceHash' => $canonical['sourceHash'] ?? $previous['sourceHash'],
		);
		if ( null !== $previous ) {
			if ( ! self::source_hash( $previous['sourceHash'] ?? null ) ) {
				return self::error( 'invalid_mapping' ); }
			$intent['priorSourceHash'] = $previous['sourceHash'];
			$intent['priorETag']       = $previous['etag'];
		}
		return $intent;
	}

	/**
	 * Reconcile an uncertain write by reading and validating its owned resource.
	 *
	 * @param array $intent Durable description of an uncertain write.
	 * @return array|WP_Error
	 */
	public static function recover( array $intent ): array|WP_Error {
		$allowed = array(
			'siteUuid',
			'appointmentId',
			'calendarKey',
			'pathSuffix',
			'uid',
			'expectedSourceHash',
			'priorSourceHash',
			'priorETag',
		);
		if ( array_diff( array_keys( $intent ), $allowed ) || ! self::source_hash( $intent['expectedSourceHash'] ?? null ) ||
			( isset( $intent['priorSourceHash'] ) && ! self::source_hash( $intent['priorSourceHash'] ) ) ||
			( isset( $intent['priorETag'] ) && self::etag( $intent['priorETag'] ) === '' ) ) {
			return self::error( 'invalid_intent' ); }
		$job = self::canonical(
			array(
				'action'        => 'delete',
				'siteUuid'      => $intent['siteUuid'] ?? null,
				'appointmentId' => $intent['appointmentId'] ?? null,
				'calendarKey'   => $intent['calendarKey'] ?? null,
			)
		);
		if ( $job instanceof WP_Error || ( $intent['pathSuffix'] ?? null ) !== $job['pathSuffix'] ||
			( $intent['uid'] ?? null ) !== $job['uid'] ) {
			return self::error( 'invalid_intent' ); }
		if ( self::target_key() === '' || self::target_key() !== $job['calendarKey'] ) {
			return self::error( 'disabled' ); }
		if ( ! is_callable( self::$transport ) ) {
			return self::error( 'transport_unavailable', true ); }
		$existing = self::get_owned( $job );
		if ( $existing instanceof WP_Error ) {
			return in_array( $existing->get_error_code(), array( 'hspcc_storage_response_invalid', 'hspcc_storage_ownership_mismatch' ), true )
				? self::error( 'conflict' ) : $existing;
		}
		$job['action']     = 'recover';
		$job['sourceHash'] = $intent['expectedSourceHash'];
		if ( $existing['absent'] ) {
			return self::receipt( $job, 'absent', '' ); }
		$actual = self::projection( $existing['fields'], $job );
		if ( $actual instanceof WP_Error ) {
			return self::error( 'conflict' ); }
		$accepted = array( $intent['expectedSourceHash'] );
		if ( isset( $intent['priorSourceHash'] ) ) {
			$accepted[] = $intent['priorSourceHash']; }
		if ( ! in_array( $actual['sourceHash'], $accepted, true ) ) {
			return self::error( 'conflict' ); }
		$job['sourceHash'] = $actual['sourceHash'];
		return self::receipt( $job, 'recovered', $existing['etag'] );
	}

	/**
	 * Reconstruct a canonical event projection for recovery verification.
	 *
	 * @param array $fields Validated event projection fields.
	 * @param array $identity Verified connection or event identity.
	 * @return array|WP_Error
	 */
	private static function projection( array $fields, array $identity ): array|WP_Error {
		foreach ( array( 'DTSTART', 'DTEND', 'DTSTAMP', 'SEQUENCE', 'SUMMARY', 'STATUS', 'X-HSP-SOURCEHASH' ) as $name ) {
			if ( ! isset( $fields[ $name ] ) || ! is_string( $fields[ $name ] ) ) {
				return self::error( 'conflict' ); }
		}
		if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', $fields['SEQUENCE'] ) || (int) $fields['SEQUENCE'] > 2147483647 ||
			! in_array( $fields['STATUS'], array( 'CONFIRMED', 'TENTATIVE' ), true ) || ! self::source_hash( $fields['X-HSP-SOURCEHASH'] ) ) {
			return self::error( 'conflict' );
		}
		$start = self::utc_value( $fields['DTSTART'] );
		$end   = self::utc_value( $fields['DTEND'] );
		$stamp = self::utc_value( $fields['DTSTAMP'] );
		if ( null === $start || null === $end || null === $stamp ) {
			return self::error( 'conflict' ); }
		$canonical = self::canonical(
			array(
				'action'        => 'upsert',
				'siteUuid'      => $identity['siteUuid'],
				'appointmentId' => $identity['appointmentId'],
				'calendarKey'   => $identity['calendarKey'],
				'startUtc'      => $start,
				'endUtc'        => $end,
				'dtstampUtc'    => $stamp,
				'sequence'      => (int) $fields['SEQUENCE'],
				'serviceLabel'  => $fields['SUMMARY'],
				'status'        => strtolower( $fields['STATUS'] ),
			)
		);
		if ( $canonical instanceof WP_Error || ! hash_equals( $canonical['sourceHash'], $fields['X-HSP-SOURCEHASH'] ) ||
			! self::same_fields( $canonical, $fields ) ) {
			return self::error( 'conflict' ); }
		return $canonical;
	}

	/**
	 * Parse a strict UTC event value into a timestamp.
	 *
	 * @param string $value Value to validate.
	 * @return int|null
	 */
	private static function utc_value( string $value ): ?int {
		if ( ! preg_match( '/^[0-9]{8}T[0-9]{6}Z$/D', $value ) ) {
			return null; }
		$date   = DateTimeImmutable::createFromFormat( '!Ymd\THis\Z', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		return $date && ( false === $errors || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) &&
			$date->format( 'Ymd\THis\Z' ) === $value ? $date->getTimestamp() : null;
	}

	/**
	 * Validate a projection hash.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function source_hash( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1;
	}

	/**
	 * Validate job identity, bounds, projection and previous mapping.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	private static function canonical( array $job ): array|WP_Error {
		$action = $job['action'] ?? null;
		$uuid   = $job['siteUuid'] ?? null;
		$id     = $job['appointmentId'] ?? null;
		if ( ! in_array( $action, array( 'upsert', 'delete' ), true ) || ! is_string( $uuid ) ||
			! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid ) ||
			! is_int( $id ) || $id < 1 ) {
			return self::error( 'invalid_input' ); }
		$key             = $job['calendarKey'] ?? '';
		$missing_mapping = ! array_key_exists( 'mapping', $job ) || null === $job['mapping'];
		if ( ! self::calendar_key( $key ) && ! ( 'delete' === $action && $missing_mapping && '' === $key ) ) {
			return self::error( 'invalid_input' );
		}
		$uid     = 'hsp-amelia-' . $uuid . '-' . $id . '@hsp-calendar-connection.local';
		$path    = 'hsp-amelia-' . $uuid . '-' . $id . '.ics';
		$mapping = null;
		if ( ! $missing_mapping ) {
			$map = $job['mapping'];
			if ( ! is_array( $map ) || ( $map['calendarKey'] ?? null ) !== $key ||
				( $map['pathSuffix'] ?? null ) !== $path || ( $map['uid'] ?? null ) !== $uid ||
				self::etag( $map['etag'] ?? null ) === '' ) {
				return self::error( 'invalid_mapping' ); }
			$mapping = array(
				'calendarKey' => $key,
				'pathSuffix'  => $path,
				'uid'         => $uid,
				'etag'        => self::etag( $map['etag'] ),
			);
			if ( isset( $map['sourceHash'] ) ) {
				if ( ! is_string( $map['sourceHash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $map['sourceHash'] ) ) {
					return self::error( 'invalid_mapping' );
				}
				$mapping['sourceHash'] = $map['sourceHash'];
			}
		}
		$result = array(
			'action'        => $action,
			'siteUuid'      => $uuid,
			'appointmentId' => $id,
			'calendarKey'   => $key,
			'uid'           => $uid,
			'pathSuffix'    => $path,
			'mapping'       => $mapping,
		);
		if ( 'delete' === $action ) {
			return $result; }
		$start    = $job['startUtc'] ?? null;
		$end      = $job['endUtc'] ?? null;
		$stamp    = $job['dtstampUtc'] ?? null;
		$sequence = $job['sequence'] ?? null;
		$label    = $job['serviceLabel'] ?? null;
		$status   = $job['status'] ?? 'confirmed';
		if ( ! self::timestamp( $start ) || ! self::timestamp( $end ) || $end <= $start ||
			! self::timestamp( $stamp ) || ! is_int( $sequence ) || $sequence < 0 || $sequence > 2147483647 ||
			! is_string( $label ) || trim( $label ) === '' || strlen( $label ) > 512 ||
			preg_match( '//u', $label ) !== 1 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label ) ||
			! in_array( $status, array( 'confirmed', 'tentative' ), true ) ) {
			return self::error( 'invalid_input' ); }
		$result              += array(
			'startUtc'     => $start,
			'endUtc'       => $end,
			'dtstampUtc'   => $stamp,
			'sequence'     => $sequence,
			'serviceLabel' => str_replace( array( "\r\n", "\r" ), "\n", $label ),
			'status'       => strtoupper( $status ),
		);
		$result['sourceHash'] = hash(
			'sha256',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve the established byte-exact projection hash used to recover uncertain writes.
			json_encode(
				array(
					$uuid,
					$id,
					$start,
					$end,
					$stamp,
					$sequence,
					$result['serviceLabel'],
					$result['status'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
		return $result;
	}

	/**
	 * Validate a hashed calendar destination key.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function calendar_key( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1;
	}

	/**
	 * Validate the supported Unix timestamp range.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function timestamp( $value ): bool {
		return is_int( $value ) && $value >= 0 && $value <= 253402300799;
	}

	/**
	 * Build the stable fields used for event identity and projection hashing.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array
	 */
	private static function fields( array $job ): array {
		return array(
			'UID'                    => $job['uid'],
			'DTSTAMP'                => gmdate( 'Ymd\THis\Z', $job['dtstampUtc'] ),
			'DTSTART'                => gmdate( 'Ymd\THis\Z', $job['startUtc'] ),
			'DTEND'                  => gmdate( 'Ymd\THis\Z', $job['endUtc'] ),
			'SEQUENCE'               => (string) $job['sequence'],
			'STATUS'                 => $job['status'],
			'TRANSP'                 => 'OPAQUE',
			'CLASS'                  => 'PRIVATE',
			'SUMMARY'                => $job['serviceLabel'],
			'DESCRIPTION'            => 'Appointment reference: ' . $job['appointmentId'],
			'X-HSPCC-SITE-UUID'      => $job['siteUuid'],
			'X-HSPCC-APPOINTMENT-ID' => (string) $job['appointmentId'],
			'X-HSP-SOURCEHASH'       => $job['sourceHash'],
		);
	}

	/**
	 * Serialize the canonical job as a privacy-preserving iCalendar event.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return string
	 */
	private static function calendar_body( array $job ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//HSP Calendar Connection//Appointment Bridge//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
		);
		foreach ( self::fields( $job ) as $name => $value ) {
			if ( in_array( $name, array( 'SUMMARY', 'DESCRIPTION' ), true ) ) {
				$value = self::escape_text( $value ); }
			$lines[] = self::fold( $name . ':' . $value );
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Escape an iCalendar TEXT property without changing its meaning.
	 *
	 * @param string $text Protocol text to escape or split.
	 * @return string
	 */
	private static function escape_text( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', "\\n" ), $text );
	}

	/**
	 * Fold a UTF-8 content line at the iCalendar octet limit.
	 *
	 * @param string $line One iCalendar content line.
	 * @return string
	 */
	private static function fold( string $line ): string {
		$parts  = array();
		$limit  = 75;
		$length = strlen( $line );
		while ( $length > $limit ) {
			$cut = $limit;
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut; }
			$parts[] = substr( $line, 0, $cut );
			$line    = substr( $line, $cut );
			$length -= $cut;
			$limit   = 74; // The continuation whitespace is part of the next line.
		}
		$parts[] = $line;
		return implode( "\r\n ", $parts );
	}

	/**
	 * Create or update an owned event using conditional ETags.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	private static function upsert( array $job ): array|WP_Error {
		$body    = self::calendar_body( $job );
		$mapped  = null !== $job['mapping'];
		$headers = array(
			'Content-Type' => 'text/calendar; charset=utf-8',
			'Accept'       => 'text/calendar',
		);
		$headers[ $mapped ? 'If-Match' : 'If-None-Match' ] = $mapped ? $job['mapping']['etag'] : '*';
		$response = self::request( $job, 'PUT', $headers, $body );
		if ( $response instanceof WP_Error ) {
			return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( 412 === $code ) {
			$existing = self::get_owned( $job );
			if ( $existing instanceof WP_Error ) {
				return $existing; }
			if ( $existing['absent'] ) {
				return self::error( 'conflict' ); }
			if ( self::same_fields( $job, $existing['fields'] ) ) {
				return self::receipt( $job, 'unchanged', $existing['etag'] );
			}
			return self::error( 'conflict' ); // A matching projection is acknowledged, never overwritten.
		}
		if ( ! in_array( $code, array( 200, 201, 204 ), true ) ) {
			return self::http_error( $code ); }
		$etag = self::etag( wp_remote_retrieve_header( $response, 'etag' ) );
		if ( '' === $etag ) {
			$existing = self::get_owned( $job );
			if ( $existing instanceof WP_Error || $existing['absent'] || ! self::same_fields( $job, $existing['fields'] ) ) {
				return self::error( 'write_uncertain', true );
			}
			$etag = $existing['etag'];
		}
		return self::receipt( $job, $mapped ? 'updated' : 'created', $etag );
	}

	/**
	 * Delete an acknowledged owned event with its current ETag.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	private static function remove( array $job ): array|WP_Error {
		$existing = self::get_owned( $job );
		if ( $existing instanceof WP_Error ) {
			return $existing; }
		if ( $existing['absent'] ) {
			return self::receipt( $job, 'absent', '' ); }
		if ( $existing['etag'] !== $job['mapping']['etag'] ) {
			return self::error( 'conflict' ); }
		$response = self::request( $job, 'DELETE', array( 'If-Match' => $job['mapping']['etag'] ), '' );
		if ( $response instanceof WP_Error ) {
			return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 200, 204, 404 ), true ) ) {
			return self::receipt( $job, 'deleted', '' ); }
		if ( 412 === $code ) {
			return self::error( 'conflict' ); }
		return self::http_error( $code );
	}

	/**
	 * Read and validate a resource before treating it as plugin-owned.
	 *
	 * @param array $job Canonical appointment write job.
	 * @return array|WP_Error
	 */
	private static function get_owned( array $job ): array|WP_Error {
		$response = self::request( $job, 'GET', array( 'Accept' => 'text/calendar' ), '' );
		if ( $response instanceof WP_Error ) {
			return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( 'absent' => true ); }
		if ( 200 !== $code ) {
			return self::http_error( $code ); }
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! is_string( $content_type ) || strtolower( trim( explode( ';', $content_type, 2 )[0] ) ) !== 'text/calendar' ) {
			return self::error( 'response_invalid' );
		}
		$etag   = self::etag( wp_remote_retrieve_header( $response, 'etag' ) );
		$fields = self::event_fields( wp_remote_retrieve_body( $response ) );
		if ( '' === $etag || $fields instanceof WP_Error ) {
			return self::error( 'response_invalid' ); }
		if ( ( $fields['UID'] ?? null ) !== $job['uid'] || ( $fields['X-HSPCC-SITE-UUID'] ?? null ) !== $job['siteUuid'] ||
			( $fields['X-HSPCC-APPOINTMENT-ID'] ?? null ) !== (string) $job['appointmentId'] ) {
			return self::error( 'ownership_mismatch' );
		}
		return array(
			'absent' => false,
			'etag'   => $etag,
			'fields' => $fields,
		);
	}

	/**
	 * Parse the bounded fields required to verify managed-event ownership.
	 *
	 * @param mixed $body Bounded protocol response or serialized event body.
	 * @return array|WP_Error
	 */
	private static function event_fields( $body ): array|WP_Error {
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES || preg_match( '//u', $body ) !== 1 ||
			preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body ) || preg_match( '/\r(?!\n)/', $body ) ) {
			return self::error( 'response_invalid' );
		}
		$unfolded = preg_replace( '/\r?\n[ \t]/', '', $body );
		$state    = 0;
		$fields   = array();
		$version  = false;
		foreach ( explode( "\n", str_replace( "\r\n", "\n", $unfolded ) ) as $line ) {
			if ( '' === $line ) {
				continue; }
			if ( strlen( $line ) > 8192 || ! preg_match( '/^([A-Za-z0-9-]+)(;[^:]*)?:(.*)$/D', $line, $part ) ) {
				return self::error( 'response_invalid' );
			}
			$name   = strtoupper( $part[1] );
			$params = $part[2] ?? '';
			$value  = $part[3];
			if ( 'BEGIN' === $name || 'END' === $name ) {
				$component = strtoupper( $value );
				if ( 'BEGIN' === $name && 'VCALENDAR' === $component && 0 === $state ) {
					$state = 1; } elseif ( 'BEGIN' === $name && 'VEVENT' === $component && 1 === $state ) {
					$state = 2; } elseif ( 'END' === $name && 'VEVENT' === $component && 2 === $state ) {
						$state = 3; } elseif ( 'END' === $name && 'VCALENDAR' === $component && 3 === $state ) {
						$state = 4; } else {
									return self::error( 'response_invalid' ); }
						continue;
			}
			if ( 1 === $state ) {
				if ( 'METHOD' === $name ) {
					return self::error( 'response_invalid' ); }
				if ( 'VERSION' === $name ) {
					if ( $version || '' !== $params || '2.0' !== $value ) {
						return self::error( 'response_invalid' ); }
					$version = true;
				}
				continue;
			}
			if ( 2 !== $state || in_array( $name, array( 'ATTENDEE', 'ORGANIZER', 'RRULE', 'RDATE', 'EXDATE' ), true ) ) {
				return self::error( 'response_invalid' );
			}
			if ( in_array(
				$name,
				array(
					'UID',
					'DTSTAMP',
					'DTSTART',
					'DTEND',
					'SEQUENCE',
					'STATUS',
					'TRANSP',
					'CLASS',
					'SUMMARY',
					'DESCRIPTION',
					'X-HSPCC-SITE-UUID',
					'X-HSPCC-APPOINTMENT-ID',
					'X-HSP-SOURCEHASH',
				),
				true
			) ) {
				if ( isset( $fields[ $name ] ) || '' !== $params ) {
					return self::error( 'response_invalid' ); }
				if ( in_array( $name, array( 'SUMMARY', 'DESCRIPTION' ), true ) ) {
					$value = self::unescape_text( $value );
					if ( $value instanceof WP_Error ) {
						return $value; }
				}
				$fields[ $name ] = $value;
			}
		}
		return 4 === $state && $version && isset( $fields['UID'] ) ? $fields : self::error( 'response_invalid' );
	}

	/**
	 * Decode supported iCalendar TEXT escapes.
	 *
	 * @param string $value Value to validate.
	 * @return string|WP_Error
	 */
	private static function unescape_text( string $value ): string|WP_Error {
		$result = '';
		for ( $i = 0, $size = strlen( $value ); $i < $size; ++$i ) {
			if ( '\\' !== $value[ $i ] ) {
				$result .= $value[ $i ];
				continue; }
			if ( ++$i >= $size ) {
				return self::error( 'response_invalid' ); }
			$char = $value[ $i ];
			if ( 'n' === $char || 'N' === $char ) {
				$result .= "\n"; } elseif ( in_array( $char, array( '\\', ',', ';' ), true ) ) {
				$result .= $char; } else {
					return self::error( 'response_invalid' ); }
		}
		return $result;
	}

	/**
	 * Compare a stored event with the intended canonical projection.
	 *
	 * @param array $job Canonical appointment write job.
	 * @param array $actual Validated fields read from the stored event.
	 * @return bool
	 */
	private static function same_fields( array $job, array $actual ): bool {
		foreach ( self::fields( $job ) as $name => $expected ) {
			if ( ! isset( $actual[ $name ] ) || $actual[ $name ] !== $expected ) {
				return false; }
		}
		return true;
	}

	/**
	 * Validate an HTTP entity tag for conditional requests.
	 *
	 * @param mixed $value Value to validate.
	 * @return string
	 */
	private static function etag( $value ): string {
		if ( ! is_string( $value ) ) {
			return ''; }
		$value = trim( $value, " \t" );
		return preg_match( '/^"[\x21\x23-\x7E]{1,200}"$/D', $value ) === 1 ? $value : '';
	}

	/**
	 * Invoke the constrained transport with canonical destination and resource.
	 *
	 * @param array  $job Canonical appointment write job.
	 * @param string $method Permitted HTTP method.
	 * @param array  $headers Constrained request headers.
	 * @param string $body Bounded protocol response or serialized event body.
	 * @return array|WP_Error
	 */
	private static function request( array $job, string $method, array $headers, string $body ): array|WP_Error {
		if ( self::target_key() !== $job['calendarKey'] ) {
			return self::error( 'disabled' ); }
		try {
			$response = call_user_func( self::$transport, $job['calendarKey'], $method, $job['pathSuffix'], $headers, $body );
			if ( $response instanceof WP_Error || ! is_array( $response ) ) {
				return self::error( 'transport', true ); }
			return $response;
		} catch ( Throwable $error ) {
			return self::error( 'transport', true ); }
	}

	/**
	 * Build a payload-free acknowledgement of a completed operation.
	 *
	 * @param array  $job Canonical appointment write job.
	 * @param string $result Sanitized operation outcome.
	 * @param string $etag Validated entity tag.
	 * @return array
	 */
	private static function receipt( array $job, string $result, string $etag ): array {
		return array(
			'action'        => $job['action'],
			'result'        => $result,
			'appointmentId' => $job['appointmentId'],
			'calendarKey'   => $job['calendarKey'],
			'pathSuffix'    => $job['pathSuffix'],
			'uid'           => $job['uid'],
			'etag'          => $etag,
			'sourceHash'    => $job['sourceHash'] ?? ( $job['mapping']['sourceHash'] ?? '' ),
		);
	}

	/**
	 * Classify a failed HTTP status without exposing remote response data.
	 *
	 * @param mixed $code Sanitized error or HTTP status code.
	 * @return WP_Error
	 */
	private static function http_error( $code ): WP_Error {
		return self::error( 'http', is_int( $code ) && ( 408 === $code || 429 === $code || $code >= 500 ) );
	}

	/**
	 * Create a sanitized storage error with retryability metadata.
	 *
	 * @param string $kind Allowlisted failure category.
	 * @param bool   $retryable Whether a later worker may retry the operation.
	 * @return WP_Error
	 */
	private static function error( string $kind, bool $retryable = false ): WP_Error {
		return new WP_Error(
			'hspcc_storage_' . $kind,
			__( 'Calendar appointment could not be synchronized.', 'hsp-calendar-connection' ),
			array( 'retryable' => $retryable )
		);
	}
}
