<?php
/**
 * Convert an expanded, privacy-projected calendar-query into busy intervals,
 * excluding this site's managed appointment UIDs only. No I/O or persistence.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

use AmeliaVendor\Sabre\VObject\Component\VCalendar;
use AmeliaVendor\Sabre\VObject\DateTimeParser;
use AmeliaVendor\Sabre\VObject\FreeBusyGenerator;
use AmeliaVendor\Sabre\VObject\Property\ICalendar\DateTime as CalendarDateTime;
use AmeliaVendor\Sabre\VObject\Reader;
use AmeliaVendor\Sabre\VObject\TimeZoneUtil;

/**
 * Exclude owned events while retaining overlapping external busy periods.
 */
final class HSPCC_Overlap_Busy {
	private const MAX_BYTES  = 1048576;
	private const MAX_EVENTS = 5000;

	/**
	 * Project expanded calendar events into privacy-preserving busy intervals.
	 *
	 * @param string $xml207 Bounded CalDAV multistatus XML.
	 * @param int    $start_unix Inclusive UTC query timestamp.
	 * @param int    $end_unix Exclusive UTC query timestamp.
	 * @param string $managed_uid_prefix Employee-specific managed event UID prefix.
	 * @param string $default_timezone_name Configured fallback timezone name.
	 * @return string|WP_Error
	 */
	public static function freebusy(
		string $xml207,
		int $start_unix,
		int $end_unix,
		string $managed_uid_prefix,
		string $default_timezone_name = ''
	): string|WP_Error {
		if ( ! class_exists( Reader::class ) || ! class_exists( FreeBusyGenerator::class ) || ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'hspcc_overlap_unavailable', __( 'Calendar busy-time response could not be validated.', 'hsp-calendar-connection' ) );
		}
		try {
			$phase = 'input';
			if ( $start_unix < 0 || $end_unix <= $start_unix || $end_unix > 253402300799 ||
				! preg_match( '/^hsp-amelia-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-$/D', $managed_uid_prefix ) ) {
				self::invalid();
			}
			$utc            = new DateTimeZone( 'UTC' );
			$start          = ( new DateTimeImmutable( '@' . $start_unix ) )->setTimezone( $utc );
			$end            = ( new DateTimeImmutable( '@' . $end_unix ) )->setTimezone( $utc );
			$default_zone   = self::default_zone( $default_timezone_name );
			$aggregate      = new VCalendar();
			$aggregate_busy = $aggregate->createComponent( 'VFREEBUSY', array(), false );
			$aggregate->add( $aggregate_busy );
			$event_count = 0;
			foreach ( self::calendar_data( $xml207 ) as $data ) {
				$phase = 'envelope';
				self::envelope( $data );
				$phase    = 'reader';
				$calendar = Reader::read( $data, 0, 'UTF-8' );
				$phase    = 'calendar_properties';
				if ( ! $calendar instanceof VCalendar || count( $calendar->select( 'VERSION' ) ) !== 1 ||
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					'2.0' !== (string) $calendar->VERSION || count( $calendar->select( 'PRODID' ) ) !== 1 ||
					count( $calendar->select( 'METHOD' ) ) !== 0 || count( $calendar->select( 'VEVENT' ) ) === 0 ) {
					self::invalid(); }
				$zones = array();
				$phase = 'components';
				foreach ( $calendar->getComponents() as $component ) {
					if ( ! in_array( $component->name, array( 'VTIMEZONE', 'VEVENT' ), true ) ) {
						self::invalid(); }
					if ( 'VTIMEZONE' === $component->name ) {
						if ( count( $component->select( 'TZID' ) ) !== 1 || count( $zones ) >= 64 ) {
							self::invalid(); }
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
						$id = (string) $component->TZID;
						if ( '' === $id || isset( $zones[ $id ] ) ) {
							self::invalid(); }
						$zones[ $id ] = $component;
					}
				}
				foreach ( $calendar->select( 'VEVENT' ) as $event ) {
					$phase = 'event';
					if ( ++$event_count > self::MAX_EVENTS ) {
						self::invalid(); }
					$bounds = self::event( $event, $calendar, $default_zone );
					if ( $bounds[1] <= $bounds[0] || $bounds[0] >= $end_unix || $bounds[1] <= $start_unix ) {
						continue; }
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					$uid = (string) $event->UID;
					// Only the reserved native appointment UID format is managed.
					if ( str_starts_with( $uid, $managed_uid_prefix ) &&
						preg_match( '/^[1-9][0-9]*@hsp-calendar-connection\.local$/D', substr( $uid, strlen( $managed_uid_prefix ) ) ) ) {
						continue;
					}
					// Expanded siblings may have one UID and only RECURRENCE-ID.
					// Isolating each event avoids masterless-instance suppression.
					$isolated = new VCalendar();
					foreach ( $zones as $zone ) {
						$isolated->add( clone $zone ); }
					$copy = clone $event;
					$copy->remove( 'RECURRENCE-ID' );
					foreach ( $copy->children() as $property ) {
						if ( ! in_array( $property->name, array( 'UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'STATUS', 'TRANSP' ), true ) ) {
							$copy->remove( $property );
						}
					}
					$isolated->add( $copy );
					$phase     = 'event_generator';
					$generator = new FreeBusyGenerator( $start, $end, array( $isolated ), $default_zone ?? $utc );
					$result    = $generator->getResult();
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					foreach ( $result->VFREEBUSY->select( 'FREEBUSY' ) as $period ) {
						$aggregate_busy->add( clone $period ); }
					// Bound retained data independently of the number of source calendars.
					if ( count( $aggregate_busy->select( 'FREEBUSY' ) ) > self::MAX_EVENTS ) {
						self::invalid(); }
					$result->destroy();
					$isolated->destroy();
					unset( $generator, $copy, $result, $isolated );
				}
				$calendar->destroy();
			}
			$objects   = count( $aggregate_busy->select( 'FREEBUSY' ) ) === 0 ? array() : array( $aggregate );
			$phase     = 'aggregate_generator';
			$generator = new FreeBusyGenerator( $start, $end, $objects, $default_zone ?? $utc );
			$generated = $generator->getResult();
			$body      = $generated->serialize();
			$generated->destroy();
			$aggregate->destroy();
			if ( strlen( $body ) > self::MAX_BYTES ) {
				self::invalid(); }
			return $body;
		} catch ( Throwable $error ) {
			return new WP_Error(
				'hspcc_overlap_invalid',
				__( 'Calendar busy-time response could not be validated.', 'hsp-calendar-connection' ),
				array(
					'phase'          => $phase ?? 'input',
					'exceptionClass' => get_class( $error ),
				)
			);
		}
	}

	/**
	 * Extract bounded event data from a validated CalDAV multistatus response.
	 *
	 * @param string $xml Bounded CalDAV multistatus XML.
	 * @return array
	 */
	private static function calendar_data( string $xml ): array {
		if ( '' === $xml || strlen( $xml ) > self::MAX_BYTES || preg_match( '//u', $xml ) !== 1 ||
			strpos( $xml, "\0" ) !== false || preg_match( '/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml ) ) {
			self::invalid(); }
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		try {
			$document = new DOMDocument();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			$document->resolveExternals = false;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			$document->substituteEntities = false;
			if ( ! $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT ) ||
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				$document->doctype || ! $document->documentElement ||
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				'DAV:' !== $document->documentElement->namespaceURI || 'multistatus' !== $document->documentElement->localName ) {
				self::invalid();
			}
			foreach ( libxml_get_errors() as $error ) {
				if ( $error->level >= LIBXML_ERR_ERROR ) {
					self::invalid(); }
			}
			$xpath = new DOMXPath( $document );
			$xpath->registerNamespace( 'd', 'DAV:' );
			$xpath->registerNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );
			if ( 0 !== $xpath->query( '//d:error' )->length ) {
				self::invalid(); }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			foreach ( $document->documentElement->childNodes as $child ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				if ( XML_ELEMENT_NODE === $child->nodeType && ( 'DAV:' !== $child->namespaceURI ||
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					! in_array( $child->localName, array( 'response', 'responsedescription' ), true ) ) ) {
					self::invalid(); }
			}
			$responses = $xpath->query( '/d:multistatus/d:response' );
			if ( $responses->length > self::MAX_EVENTS || $responses->length !== $xpath->query( '//d:response' )->length ) {
				self::invalid(); }
			$data = array();
			foreach ( $responses as $response ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				foreach ( $response->childNodes as $child ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					if ( XML_ELEMENT_NODE === $child->nodeType && ( 'DAV:' !== $child->namespaceURI ||
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
						! in_array( $child->localName, array( 'href', 'status', 'propstat', 'responsedescription' ), true ) ) ) {
						self::invalid(); }
				}
				$top_status = $xpath->query( './d:status', $response );
				if ( $top_status->length > 1 || ( 1 === $top_status->length && ! self::ok_status( $top_status->item( 0 )->textContent ) ) ) {
					self::invalid(); }
				$propstats = $xpath->query( './d:propstat', $response );
				if ( 0 === $propstats->length ) {
					self::invalid(); }
				foreach ( $propstats as $propstat ) {
					$statuses = $xpath->query( './d:status', $propstat );
					if ( 1 !== $statuses->length || ! self::ok_status( $statuses->item( 0 )->textContent ) ) {
						self::invalid(); }
				}
				$calendars = $xpath->query( './d:propstat/d:prop/c:calendar-data', $response );
				if ( 1 !== $calendars->length ) {
					self::invalid(); }
				$node = $calendars->item( 0 );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				foreach ( $node->childNodes as $child ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
					if ( ! in_array( $child->nodeType, array( XML_TEXT_NODE, XML_CDATA_SECTION_NODE ), true ) ) {
						self::invalid(); }
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				if ( '' === $node->textContent ) {
					self::invalid(); }
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				$data[] = $node->textContent;
			}
			return $data;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Validate a successful HTTP status line within a DAV response.
	 *
	 * @param string $status Protocol status line.
	 * @return bool
	 */
	private static function ok_status( string $status ): bool {
		return preg_match( '/^HTTP\/\d(?:\.\d)?[ \t]+200(?:[ \t]+[^\r\n]*)?$/D', trim( $status ) ) === 1;
	}

	/**
	 * Reject unsupported or excessive iCalendar structure before parsing.
	 *
	 * @param string $data Bounded calendar data.
	 * @return void
	 */
	private static function envelope( string $data ): void {
		if ( '' === $data || preg_match( '//u', $data ) !== 1 ||
			preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $data ) || preg_match( '/\r(?!\n)/', $data ) ) {
			self::invalid(); }
		$data  = preg_replace( '/\r?\n[ \t]/', '', $data );
		$lines = explode( "\n", trim( str_replace( "\r\n", "\n", $data ), "\n" ) );
		if ( strtoupper( $lines[0] ?? '' ) !== 'BEGIN:VCALENDAR' || strtoupper( end( $lines ) ) !== 'END:VCALENDAR' ) {
			self::invalid(); }
		$stack = array();
		$roots = 0;
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue; }
			if ( strlen( $line ) > 16384 ) {
				self::invalid(); }
			if ( ! preg_match( '/^(BEGIN|END):([A-Za-z0-9-]+)$/Di', $line, $match ) ) {
				continue; }
			$name = strtoupper( $match[2] );
			if ( strtoupper( $match[1] ) === 'BEGIN' ) {
				$parent = end( $stack );
				if ( ( 'VCALENDAR' === $name && ( array() !== $stack || 1 !== ++$roots ) ) ||
					( 'VCALENDAR' !== $name && ! in_array( $name, array( 'VEVENT', 'VTIMEZONE', 'STANDARD', 'DAYLIGHT', 'VALARM' ), true ) ) ||
					( in_array( $name, array( 'VEVENT', 'VTIMEZONE' ), true ) && 'VCALENDAR' !== $parent ) ||
					( in_array( $name, array( 'STANDARD', 'DAYLIGHT' ), true ) && 'VTIMEZONE' !== $parent ) ||
					( 'VALARM' === $name && 'VEVENT' !== $parent ) ) {
					self::invalid(); }
				$stack[] = $name;
			} elseif ( array_pop( $stack ) !== $name ) {
				self::invalid(); }
		}
		if ( array() !== $stack || 1 !== $roots ) {
			self::invalid(); }
	}

	/**
	 * Validate one event's semantics and determine its UTC bounds.
	 *
	 * @param mixed             $event Parsed native iCalendar event.
	 * @param VCalendar         $calendar Parsed native iCalendar container.
	 * @param DateTimeZone|null $default_zone Fallback timezone for floating or all-day dates.
	 * @return array
	 */
	private static function event( $event, VCalendar $calendar, ?DateTimeZone $default_zone ): array {
		if ( count( $event->select( 'UID' ) ) !== 1 || count( $event->select( 'DTSTART' ) ) !== 1 ||
			count( $event->select( 'DTEND' ) ) > 1 || count( $event->select( 'DURATION' ) ) > 1 ||
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			( isset( $event->DTEND ) && isset( $event->DURATION ) ) || count( $event->select( 'RECURRENCE-ID' ) ) > 1 ||
			count( $event->select( 'STATUS' ) ) > 1 || count( $event->select( 'TRANSP' ) ) > 1 ||
			count( $event->select( 'RRULE' ) ) || count( $event->select( 'RDATE' ) ) || count( $event->select( 'EXDATE' ) ) ) {
			self::invalid(); }
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		$uid = (string) $event->UID;
		if ( '' === $uid || strlen( $uid ) > 1024 ) {
			self::invalid(); }
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		$start = self::date( $event->DTSTART, $calendar, $default_zone );
		$end   = $start;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		if ( isset( $event->DTEND ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			$end = self::date( $event->DTEND, $calendar, $default_zone );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			if ( $event->DTSTART->hasTime() !== $event->DTEND->hasTime() || $end <= $start ) {
				self::invalid(); }
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		} elseif ( isset( $event->DURATION ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
			$value = (string) $event->DURATION;
			if ( strlen( $value ) > 64 || ! preg_match( '/^\+?P(?=\d|T\d)(?:\d+W|(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+S)?)?)$/D', $value ) ) {
				self::invalid(); }
			$interval = DateTimeParser::parseDuration( $value );
			if ( $interval->invert || $start->add( $interval ) < $start ||
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
				( ! $event->DTSTART->hasTime() && strpos( $value, 'T' ) !== false ) ) {
				self::invalid(); }
			$end = $start->add( $interval );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		} elseif ( ! $event->DTSTART->hasTime() ) {
			$end = $start->modify( '+1 day' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		if ( isset( $event->TRANSP ) && ! in_array( strtoupper( (string) $event->TRANSP ), array( 'OPAQUE', 'TRANSPARENT' ), true ) ) {
			self::invalid(); }
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM and Sabre expose these fixed native property names.
		if ( isset( $event->STATUS ) && ! preg_match( '/^[A-Za-z0-9-]{1,100}$/D', (string) $event->STATUS ) ) {
			self::invalid(); }
		return array( $start->getTimestamp(), $end->getTimestamp() );
	}

	/**
	 * Resolve a validated event date using its declared or configured timezone.
	 *
	 * @param mixed             $property Parsed native date property.
	 * @param VCalendar         $calendar Parsed native iCalendar container.
	 * @param DateTimeZone|null $default_zone Fallback timezone for floating or all-day dates.
	 * @return DateTimeImmutable
	 */
	private static function date( $property, VCalendar $calendar, ?DateTimeZone $default_zone ): DateTimeImmutable {
		if ( ! $property instanceof CalendarDateTime || count( $property->getParts() ) !== 1 ) {
			self::invalid(); }
		$raw      = (string) $property;
		$has_time = $property->hasTime();
		$pattern  = $has_time ? '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/D' : '/^(\d{4})(\d{2})(\d{2})$/D';
		if ( ! preg_match( $pattern, $raw, $part ) || ! checkdate( (int) $part[2], (int) $part[3], (int) $part[1] ) ||
			( $has_time && ( (int) $part[4] > 23 || (int) $part[5] > 59 || (int) $part[6] > 59 ) ) ) {
			self::invalid(); }
		if ( isset( $property['TZID'] ) ) {
			$tzid = (string) $property['TZID'];
			if ( ! $has_time || ( $part[7] ?? '' ) === 'Z' || '' === $tzid || strlen( $tzid ) > 200 ) {
				self::invalid(); }
			TimeZoneUtil::getTimeZone( $tzid, $calendar, true ); // Never permit Sabre's PHP-default fallback.
		} elseif ( $property->isFloating() && null === $default_zone ) {
			self::invalid(); }
		$date = $property->getDateTime( $default_zone ?? new DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof DateTimeImmutable || $date->format( $has_time ? 'Ymd\THis' : 'Ymd' ) !== rtrim( $raw, 'Z' ) ) {
			self::invalid(); }
		return $date;
	}

	/**
	 * Resolve the configured fallback timezone when present.
	 *
	 * @param string $name Validated option, lock or timezone name.
	 * @return DateTimeZone|null
	 */
	private static function default_zone( string $name ): ?DateTimeZone {
		if ( '' === $name || strlen( $name ) > 200 ) {
			return null; }
		try {
			return new DateTimeZone( $name );
		} catch ( Throwable $error ) {
			return null; }
	}

	/**
	 * Reject malformed or unsupported calendar event data.
	 *
	 * @return void
	 * @throws RuntimeException If the supplied input fails validation.
	 */
	private static function invalid(): void {
		throw new RuntimeException( 'Invalid calendar projection.' );
	}
}
