<?php
/**
 * Read-only Synology busy-time checks for Amelia 9.9.1's documented hooks.
 * Native Amelia still owns services, working hours, bookings and notifications.
 * This adapter only removes choices or refuses a save; it never creates slots.
 * Validated scope: free fixed appointments/extras and fixed, capacity-one
 * on-site paid frontend appointments for configured employees.
 * Unknown providers/dates fail closed; explicit other providers stay native.
 * Customer-panel moves require one capacity-one native booking; paid moves
 * additionally prove its canonical fixed-price, on-site payment relationship.
 * Other single-booking reassignment and recurring/package/cart are refused.
 * Paid callbacks lack a common native pre-write hook and are outside this bridge.
 *
 * @package HSP_Calendar_Connection
 */

defined( 'ABSPATH' ) || exit;

/** Enforce calendar availability for supported native appointment writes. */
final class HSPCC_Amelia {
	/**
	 * Maximum accepted availability window in seconds.
	 *
	 * @var int
	 */
	private const MAX_WINDOW = 2678400;
	/**
	 * Whether native hooks are already registered.
	 *
	 * @var bool
	 */
	private static $booted = false;
	/**
	 * Cached native dependency container.
	 *
	 * @var object|null
	 */
	private static $container = null;
	/**
	 * Validated services keyed by employee and service identifiers.
	 *
	 * @var array
	 */
	private static $services = array();

	/**
	 * Register native appointment hooks once per request.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return; }
		self::$booted = true;
		add_filter( 'amelia_get_timeslots_filter', array( __CLASS__, 'filter_slots' ), 1000, 2 );
		add_filter( 'amelia_before_booking_added_filter', array( __CLASS__, 'before_frontend' ), 1000, 1 );
		add_filter( 'amelia_before_appointment_added_filter', array( __CLASS__, 'before_create' ), 1000, 3 );
		add_filter( 'amelia_before_appointment_updated_filter', array( __CLASS__, 'before_update' ), 1000, 3 );
		add_filter( 'amelia_before_appointment_booking_saved_filter', array( __CLASS__, 'before_booking_saved' ), 1000, 3 );
		add_action( 'amelia_before_booking_rescheduled', array( __CLASS__, 'before_reschedule' ), 1, 3 );
		add_action( 'amelia_before_booking_approved', array( __CLASS__, 'before_approved' ), 1, 1 );
		add_action( 'amelia_before_booking_pending', array( __CLASS__, 'before_pending' ), 1, 1 );
		add_action( 'amelia_before_booking_approved_link', array( __CLASS__, 'before_approved' ), 1, 1 );
		add_action( 'amelia_before_appointment_status_updated', array( __CLASS__, 'before_appointment_status' ), 1, 2 );
	}

	/**
	 * Refuse a booking using a translated public error.
	 *
	 * @param mixed $busy Whether a verified conflict caused refusal.
	 * @param mixed $unsupported Whether the requested flow is outside the supported scope.
	 * @return void
	 * @throws \AmeliaBooking\Domain\Common\Exceptions\CustomException When the requested operation cannot be safely validated.
	 */
	private static function unavailable( $busy = false, $unsupported = false ) {
		$message = $unsupported
			? __( 'Please contact us to arrange this change or a recurring appointment.', 'hsp-calendar-connection' )
			: ( $busy ? __( 'That time is no longer available. Please choose another time.', 'hsp-calendar-connection' )
				: __( 'Calendar availability could not be verified. Please contact us to arrange your appointment.', 'hsp-calendar-connection' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated text is serialized by the native JSON response, not emitted as HTML.
		throw new \AmeliaBooking\Domain\Common\Exceptions\CustomException( $message );
	}

	/**
	 * Determine whether a native price is exactly zero.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function zero_price( $value ) {
		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) &&
			is_numeric( $value ) && is_finite( (float) $value ) && 0.0 === (float) $value;
	}

	/**
	 * Refuse a paid or custom flow outside the reviewed contract.
	 *
	 * @return void
	 * @throws \AmeliaBooking\Domain\Common\Exceptions\CustomException When the requested operation cannot be safely validated.
	 */
	private static function unsupported_service() {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated text is serialized by the native JSON response, not emitted as HTML.
		throw new \AmeliaBooking\Domain\Common\Exceptions\CustomException( __( 'Please contact us to arrange this paid or custom session.', 'hsp-calendar-connection' ) );
	}

	/**
	 * Validate fixed service pricing and identify a paid service.
	 *
	 * @param array $service Native service data.
	 * @return bool
	 */
	private static function fixed_service( array $service ) {
		$paid = ! self::zero_price( $service['price'] ?? null );
		if ( $paid && ( ! defined( 'AMELIA_VERSION' ) || AMELIA_VERSION !== '9.9.1' ||
			! is_numeric( $service['price'] ?? null ) || ! is_finite( (float) $service['price'] ) ||
			(float) $service['price'] <= 0 || 1 !== self::positive_id( $service['minCapacity'] ?? null ) ||
			1 !== self::positive_id( $service['maxCapacity'] ?? null ) ) ) {
			self::unsupported_service(); }
		$pricing = $service['customPricing'] ?? null;
		if ( null !== $pricing && '' !== $pricing ) {
			if ( is_string( $pricing ) ) {
				$pricing = json_decode( $pricing, true ); }
			if ( ! is_array( $pricing ) || ! array_key_exists( 'enabled', $pricing ) ||
				! in_array( $pricing['enabled'], array( false, 0, '0' ), true ) ) {
				self::unsupported_service(); }
		}
		return $paid;
	}

	/**
	 * Validate one capacity-one booking for the on-site payment flow.
	 *
	 * @param array $service Native service data.
	 * @param mixed $bookings Native booking records.
	 * @return array
	 */
	private static function paid_booking( array $service, $bookings ) {
		if ( ! is_array( $bookings ) || 1 !== count( $bookings ) ) {
			self::unsupported_service(); }
		$booking = reset( $bookings );
		if ( ! is_array( $booking ) || 1 !== self::positive_id( $booking['persons'] ?? null ) ) {
			self::unsupported_service(); }
		if ( isset( $booking['status'] ) && ! in_array( $booking['status'], array( 'approved', 'pending' ), true ) ) {
			self::unsupported_service(); }
		foreach ( array( 'extras', 'coupon', 'couponId', 'deposit', 'packageCustomerService' ) as $key ) {
			if ( ! empty( $booking[ $key ] ) ) {
				self::unsupported_service(); }
		}
		if ( ! empty( $booking['duration'] ) && self::seconds( $booking['duration'] ) !== self::seconds( $service['duration'] ) ) {
			self::unsupported_service();
		}
		return $booking;
	}

	/**
	 * Validate payment context without changing native financial values.
	 *
	 * @param array $data Native hook payload.
	 * @param array $service Native service data.
	 * @param mixed $context Reviewed native booking flow identifier.
	 * @return void
	 */
	private static function paid_context( array $data, array $service, $context ) {
		self::paid_booking( $service, $data['bookings'] ?? null );
		foreach ( array( 'coupon', 'couponId', 'couponCode', 'packageId', 'packageBookingFromBackend' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				self::unsupported_service(); }
		}
		if ( ! empty( $data['serviceDuration'] ) && self::seconds( $data['serviceDuration'] ) !== self::seconds( $service['duration'] ) ) {
			self::unsupported_service();
		}
		if ( 'onsite-frontend' === $context ) {
			// Public AddBookingCommand validates payment before this hook;
			// licensed API requests can skip that step. Accept only the native
			// normalized shape, never API/backend-supplied financial state.
			$payment = $data['payment'] ?? null;
			if ( ! is_array( $payment ) || 'onSite' !== ( $payment['gateway'] ?? null ) ||
				array_diff( array_keys( $payment ), array( 'gateway', 'currency', 'data' ) ) ||
				! is_string( $payment['currency'] ?? null ) || '' === $payment['currency'] ||
				self::container()->get( 'domain.settings.service' )->getSetting( 'payments', 'currency' ) !== $payment['currency'] ||
				( isset( $payment['data'] ) && ! is_array( $payment['data'] ) ) ) {
				self::unsupported_service();
			}
		} elseif ( 'onsite-customer-move' !== $context ) {
			// Other paid creation/update/status paths need their own contracts.
			self::unsupported_service();
		}
	}

	/**
	 * Verify the canonical payment belonging to a customer booking.
	 *
	 * @param array $service Native service data.
	 * @param array $booking Native booking record.
	 * @return void
	 */
	private static function paid_customer_payment( array $service, array $booking ) {
		self::paid_booking( $service, array( $booking ) );
		if ( ! is_numeric( $booking['price'] ?? null ) || ! is_finite( (float) $booking['price'] ) ||
			(float) $booking['price'] !== (float) $service['price'] ) {
			self::unsupported_service(); }
		$booking_id = self::positive_id( $booking['id'] ?? null );
		$payments   = self::container()->get( 'domain.payment.repository' )->getByEntityId( $booking_id, 'customerBookingId' );
		if ( ! is_object( $payments ) || ! method_exists( $payments, 'getItems' ) ) {
			self::unsupported_service(); }
		$payments = $payments->getItems();
		if ( ! is_array( $payments ) || 1 !== count( $payments ) ) {
			self::unsupported_service(); }
		$payment = reset( $payments );
		$payment = is_object( $payment ) && method_exists( $payment, 'toArray' ) ? $payment->toArray() : null;
		if ( ! is_array( $payment ) || self::positive_id( $payment['id'] ?? null ) < 1 ||
			self::positive_id( $payment['customerBookingId'] ?? null ) !== $booking_id ||
			'onSite' !== ( $payment['gateway'] ?? null ) ||
			! in_array( $payment['status'] ?? null, array( 'pending', 'paid', 'partiallyPaid' ), true ) ||
			! is_numeric( $payment['amount'] ?? null ) || ! is_finite( (float) $payment['amount'] ) || (float) $payment['amount'] < 0 ) {
			self::unsupported_service();
		}
		foreach ( array( 'packageCustomerId', 'parentId', 'wcOrderId', 'wcOrderItemId', 'transactionId', 'paymentLinks' ) as $key ) {
			if ( ! empty( $payment[ $key ] ) ) {
				self::unsupported_service(); }
		}
		// Native amount is money already collected (normally zero on site),
		// not the amount owed. Native Amelia alone owns taxes, totals and dates.
	}

	/**
	 * Validate an identifier without accepting coerced or missing values.
	 *
	 * @param mixed $value Value to validate.
	 * @return int
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function positive_id( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
			throw new RuntimeException( 'Unsupported identifier.' );
		}
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			throw new RuntimeException( 'Unsupported identifier.' ); }
		return $id;
	}

	/**
	 * Read the current connection owner from explicit employee context.
	 *
	 * @return int
	 * @throws RuntimeException When the required native context is unavailable or invalid.
	 */
	private static function provider() {
		return self::positive_id( HSP_Calendar_Connection::provider_id() );
	}

	/**
	 * Validate a bounded duration measured in seconds.
	 *
	 * @param mixed $value Value to validate.
	 * @param mixed $allow_zero Whether zero is a valid duration.
	 * @return int
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function seconds( $value, $allow_zero = false ) {
		if ( null === $value && $allow_zero ) {
			return 0; }
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[0-9]+$/D', (string) $value ) ) {
			throw new RuntimeException( 'Unsupported duration.' );
		}
		$duration = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => $allow_zero ? 0 : 1,
					'max_range' => self::MAX_WINDOW,
				),
			)
		);
		if ( false === $duration ) {
			throw new RuntimeException( 'Unsupported duration.' ); }
		return $duration;
	}

	/**
	 * Normalize native slot duration to whole seconds.
	 *
	 * @param mixed $value Value to validate.
	 * @return int
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function minutes( $value ) {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			throw new RuntimeException( 'Unsupported duration.' ); }
		$seconds = (float) $value * 60;
		if ( $seconds < 1 || $seconds > self::MAX_WINDOW || abs( $seconds - round( $seconds ) ) > 0.000001 ) {
			throw new RuntimeException( 'Unsupported duration.' );
		}
		return (int) round( $seconds );
	}

	/**
	 * Resolve the installed native dependency container once per request.
	 *
	 * @return object
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function container() {
		if ( null !== self::$container ) {
			return self::$container; }
		if ( ! defined( 'AMELIA_PATH' ) ) {
			throw new RuntimeException( 'Amelia unavailable.' ); }
		// Amelia uses this same lazy container entry point in its own runtime.
		$container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
		if ( ! is_object( $container ) || ! method_exists( $container, 'get' ) ) {
			throw new RuntimeException( 'Amelia unavailable.' ); }
		self::$container = $container;
		return $container;
	}

	/**
	 * Resolve and validate a native service for the scoped employee.
	 *
	 * @param mixed $id Native record identifier.
	 * @param mixed $provided Optional native service already resolved by the caller.
	 * @return array
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function service( $id, $provided = null ) {
		$id        = self::positive_id( $id );
		$provider  = self::provider();
		$cache_key = $provider . ':' . $id;
		if ( is_array( $provided ) && self::positive_id( $provided['id'] ?? null ) === $id ) {
			$service = $provided;
		} elseif ( isset( self::$services[ $cache_key ] ) ) {
			return self::$services[ $cache_key ];
		} else {
			$service = self::container()->get( 'application.bookable.service' )
				->getAppointmentService( $id, $provider )->toArray();
		}
		if ( ! is_array( $service ) || self::positive_id( $service['id'] ?? null ) !== $id ) {
			throw new RuntimeException( 'Service unavailable.' );
		}
		self::seconds( $service['duration'] ?? null );
		self::seconds( $service['timeBefore'] ?? null, true );
		self::seconds( $service['timeAfter'] ?? null, true );
		if ( ! isset( $service['extras'] ) ) {
			$service['extras'] = array(); }
		if ( ! is_array( $service['extras'] ) ) {
			throw new RuntimeException( 'Extras unavailable.' ); }
		self::$services[ $cache_key ] = $service;
		return $service;
	}

	/**
	 * Resolve the native booking timezone.
	 *
	 * @return DateTimeZone
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function site_zone() {
		$class = '\AmeliaBooking\Domain\Services\DateTime\DateTimeService';
		if ( class_exists( $class ) && method_exists( $class, 'getTimeZone' ) ) {
			$zone = $class::getTimeZone();
		} elseif ( function_exists( 'wp_timezone' ) ) {
			$zone = wp_timezone();
		} else {
			throw new RuntimeException( 'Timezone unavailable.' );
		}
		if ( ! $zone instanceof DateTimeZone ) {
			throw new RuntimeException( 'Timezone unavailable.' ); }
		return $zone;
	}

	/**
	 * Validate an explicit timezone identifier.
	 *
	 * @param mixed $name Timezone identifier.
	 * @return DateTimeZone
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function zone( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			throw new RuntimeException( 'Timezone unavailable.' ); }
		return new DateTimeZone( $name );
	}

	/**
	 * Parse a native wall-clock timestamp and reject ambiguous dates.
	 *
	 * @param mixed        $value Value to validate.
	 * @param DateTimeZone $zone Timezone used to interpret the wall-clock value.
	 * @return int
	 * @throws RuntimeException When the required native context is unavailable or invalid.
	 */
	private static function timestamp( $value, DateTimeZone $zone ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value ) ) {
			throw new RuntimeException( 'Unsupported date.' );
		}
		$wall   = 16 === strlen( $value ) ? $value . ':00' : $value;
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $wall, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $wall ) {
			throw new RuntimeException( 'Unsupported date.' );
		}
		// A wall-clock time in a DST fold has two possible instants. Refuse to
		// guess which one was meant; an explicitly UTC slot remains unambiguous.
		$transitions = $zone->getTransitions( $date->getTimestamp() - 86400, $date->getTimestamp() + 86400 );
		if ( is_array( $transitions ) ) {
			$old_offset = null;
			foreach ( $transitions as $transition ) {
				$offset = $transition['offset'];
				if ( null !== $old_offset && $offset < $old_offset ) {
					$start = gmdate( 'Y-m-d H:i:s', $transition['ts'] + $offset );
					$end   = gmdate( 'Y-m-d H:i:s', $transition['ts'] + $old_offset );
					if ( $wall >= $start && $wall < $end ) {
						throw new RuntimeException( 'Ambiguous date.' ); }
				}
				$old_offset = $offset;
			}
		}
		return $date->getTimestamp();
	}

	/**
	 * Calculate the duration of supported free selected extras.
	 *
	 * @param array $service Native service data.
	 * @param mixed $selected Selected native extra records.
	 * @return int
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function extra_duration( array $service, $selected ) {
		if ( null === $selected ) {
			return 0; }
		if ( ! is_array( $selected ) ) {
			throw new RuntimeException( 'Unsupported extras.' ); }
		$known = array();
		foreach ( $service['extras'] as $extra ) {
			if ( ! is_array( $extra ) ) {
				throw new RuntimeException( 'Unsupported extras.' ); }
			$known[ self::positive_id( $extra['id'] ?? null ) ] = array(
				'duration' => self::seconds( $extra['duration'] ?? null, true ),
				'price'    => $extra['price'] ?? null,
			);
		}
		$total = 0;
		foreach ( $selected as $extra ) {
			if ( ! is_array( $extra ) ) {
				throw new RuntimeException( 'Unsupported extras.' ); }
			$id = self::positive_id( $extra['extraId'] ?? $extra['id'] ?? null );
			if ( ! array_key_exists( $id, $known ) ) {
				throw new RuntimeException( 'Unsupported extras.' ); }
			$raw_quantity = $extra['quantity'] ?? 1;
			if ( ( ! is_int( $raw_quantity ) && ! is_string( $raw_quantity ) ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $raw_quantity ) ) {
				throw new RuntimeException( 'Unsupported extra quantity.' );
			}
			$quantity = filter_var( $raw_quantity, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false === $quantity ) {
				throw new RuntimeException( 'Unsupported extra quantity.' ); }
			if ( $quantity > 0 && ! self::zero_price( $known[ $id ]['price'] ) ) {
				self::unsupported_service(); }
			$total += $known[ $id ]['duration'] * $quantity;
			if ( $total > self::MAX_WINDOW ) {
				throw new RuntimeException( 'Unsupported duration.' ); }
		}
		return $total;
	}

	/**
	 * Calculate the longest active booking duration from native rows.
	 *
	 * @param array $service Native service data.
	 * @param mixed $bookings Native booking records.
	 * @param mixed $allow_cancellation Whether closed bookings may release capacity.
	 * @return int
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function body_duration( array $service, $bookings, $allow_cancellation = false ) {
		if ( ! is_array( $bookings ) || ! $bookings ) {
			throw new RuntimeException( 'Booking unavailable.' ); }
		$duration = 0;
		foreach ( $bookings as $booking ) {
			if ( ! is_array( $booking ) ) {
				throw new RuntimeException( 'Booking unavailable.' ); }
			if ( $allow_cancellation && isset( $booking['status'] ) && in_array( $booking['status'], array( 'canceled', 'cancelled', 'rejected' ), true ) ) {
				continue; }
			$base = self::seconds( $service['duration'] ) + self::extra_duration( $service, $booking['extras'] ?? array() );
			if ( isset( $booking['duration'] ) && null !== $booking['duration'] && 0 !== $booking['duration'] && '0' !== $booking['duration'] ) {
				// Native booking duration can already include selected extras.
				// Compare complete durations rather than adding those extras twice.
				// Never shorten below the canonical fixed service plus extras.
				$base = max( $base, self::seconds( $booking['duration'] ) );
			}
			$duration = max( $duration, $base );
		}
		return $duration;
	}

	/**
	 * Include the native service buffers around a booking interval.
	 *
	 * @param mixed $start Requested start time.
	 * @param mixed $body Native booking duration in seconds.
	 * @param array $service Native service data.
	 * @return array
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function interval( $start, $body, array $service ) {
		$before = self::seconds( $service['timeBefore'] ?? null, true );
		$after  = self::seconds( $service['timeAfter'] ?? null, true );
		$end    = $start + $body + $after;
		$start -= $before;
		if ( $body < 1 || $end <= $start || $end - $start > self::MAX_WINDOW ) {
			throw new RuntimeException( 'Unsupported interval.' ); }
		return array( $start, $end );
	}

	/**
	 * Read verified external busy intervals in the current employee scope.
	 *
	 * @param mixed $start Requested start time.
	 * @param mixed $end Requested end time.
	 * @param mixed $fresh Whether to bypass the display cache.
	 * @return array
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function busy( $start, $end, $fresh ) {
		if ( ! class_exists( 'HSPCC_Busy_Time' ) ) {
			throw new RuntimeException( 'Calendar unavailable.' ); }
		$busy = HSPCC_Busy_Time::intervals( $start, $end, $fresh );
		if ( is_wp_error( $busy ) || ! is_array( $busy ) ) {
			throw new RuntimeException( 'Calendar unavailable.' ); }
		foreach ( $busy as $period ) {
			if ( ! is_array( $period ) || 2 !== count( $period ) || ! isset( $period[0], $period[1] ) ||
				! is_int( $period[0] ) || ! is_int( $period[1] ) || $period[1] <= $period[0] ) {
				throw new RuntimeException( 'Calendar unavailable.' );
			}
		}
		return $busy;
	}

	/**
	 * Determine whether a requested interval intersects external busy time.
	 *
	 * @param mixed $start Requested start time.
	 * @param mixed $end Requested end time.
	 * @param array $busy Whether a verified conflict caused refusal.
	 * @return bool
	 */
	private static function overlaps( $start, $end, array $busy ) {
		foreach ( $busy as $period ) {
			if ( $start < $period[1] && $end > $period[0] ) {
				return true; }
		}
		return false;
	}

	/**
	 * Remove conflicting native timeslots without creating availability.
	 *
	 * @param mixed $data Native hook payload.
	 * @param mixed $props Native timeslot query properties.
	 * @return array
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	public static function filter_slots( $data, $props ) {
		if ( ! is_array( $data ) || ! is_array( $props ) || ! is_array( $data['slots'] ?? null ) ) {
			if ( is_array( $data ) ) {
				$data['slots'] = array(); }
			return $data;
		}
		$tasks    = array();
		$affected = false;
		foreach ( $data['slots'] as $day => $times ) {
			if ( ! is_array( $times ) ) {
				unset( $data['slots'][ $day ] );
				continue; }
			foreach ( $times as $time => $rows ) {
				if ( ! is_array( $rows ) ) {
					unset( $data['slots'][ $day ][ $time ] );
					continue; }
				foreach ( $rows as $index => $row ) {
					try {
						if ( ! is_array( $row ) ) {
							throw new RuntimeException( 'Unsupported slot.' ); }
						$provider = self::positive_id( $row['e'] ?? $row[0] ?? null );
						if ( ! HSP_Calendar_Connection::blocking_enabled( $provider ) ) {
							continue; }
						$affected = true;
						$tasks[]  = HSP_Calendar_Connection::with_employee(
							$provider,
							static function () use ( $row, $props, $data, $day, $time, $index, $provider ) {
								$service_id = $row['s'] ?? $row[3] ?? $props['serviceId'] ?? null;
								$service    = self::service( $service_id );
								if ( self::fixed_service( $service ) ) {
									if ( ! empty( $props['extras'] ) || ( ! empty( $props['serviceDuration'] ) &&
										self::seconds( $props['serviceDuration'] ) !== self::seconds( $service['duration'] ) ) ) {
										self::unsupported_service();
									}
								}
								if ( ! empty( $props['timeZone'] ) ) {
									$zone = self::zone( $props['timeZone'] ); } else {
									$show_client = self::container()->get( 'domain.settings.service' )->getSetting( 'general', 'showClientTimeZone' );
									$zone        = $show_client && ! empty( $props['isFrontEndBooking'] ) ? new DateTimeZone( 'UTC' ) : self::site_zone();
									}
									$start = self::timestamp( (string) $day . ' ' . (string) $time, $zone );
									$body  = self::seconds( $service['duration'] ) + self::extra_duration( $service, $props['extras'] ?? array() );
									if ( ! empty( $props['serviceDuration'] ) ) {
										$body = max( $body, self::seconds( $props['serviceDuration'] ) + self::extra_duration( $service, $props['extras'] ?? array() ) );
									}
									$body     = max(
										$body,
										self::minutes( $data['duration'] ?? null )
										- self::seconds( $service['timeBefore'] ?? null, true ) - self::seconds( $service['timeAfter'] ?? null, true )
									);
								$row_duration = $row['d'] ?? $row[4] ?? null;
								if ( null !== $row_duration ) {
									$body = max( $body, self::minutes( $row_duration ) ); }
								[$from, $until] = self::interval( $start, $body, $service );
								return array(
									'provider' => $provider,
									'day'      => $day,
									'time'     => $time,
									'index'    => $index,
									'start'    => $from,
									'end'      => $until,
								);
							}
						);
					} catch ( Throwable $e ) {
						$affected = true;
						unset( $data['slots'][ $day ][ $time ][ $index ] ); }
				}
			}
		}
		usort(
			$tasks,
			function ( $a, $b ) {
				$provider_order = $a['provider'] <=> $b['provider'];
				return 0 !== $provider_order ? $provider_order : ( $a['start'] <=> $b['start'] );
			}
		);
		$groups = array();
		foreach ( $tasks as $task ) {
			$last = count( $groups ) - 1;
			if ( $last < 0 || $groups[ $last ]['provider'] !== $task['provider'] ||
				max( $groups[ $last ]['end'], $task['end'] ) - $groups[ $last ]['start'] > self::MAX_WINDOW ) {
				$groups[] = array(
					'provider' => $task['provider'],
					'start'    => $task['start'],
					'end'      => $task['end'],
					'tasks'    => array( $task ),
				);
			} else {
				$groups[ $last ]['end']     = max( $groups[ $last ]['end'], $task['end'] );
				$groups[ $last ]['tasks'][] = $task;
			}
		}
		foreach ( $groups as $group ) {
			try {
				$busy = HSP_Calendar_Connection::with_employee(
					$group['provider'],
					static function () use ( $group ) {
						return self::busy( $group['start'], $group['end'], false );
					}
				);
			} catch ( Throwable $e ) {
				$busy = null; }
			foreach ( $group['tasks'] as $task ) {
				if ( null === $busy || self::overlaps( $task['start'], $task['end'], $busy ) ) {
					unset( $data['slots'][ $task['day'] ][ $task['time'] ][ $task['index'] ] );
				}
			}
		}
		foreach ( $data['slots'] as $day => $times ) {
			foreach ( $times as $time => $rows ) {
				if ( ! $rows ) {
					unset( $data['slots'][ $day ][ $time ] ); } else {
					$data['slots'][ $day ][ $time ] = array_values( $rows ); }
			}
			if ( ! $data['slots'][ $day ] ) {
				unset( $data['slots'][ $day ] ); }
		}
		// Native busyness percentages predate our removal; omit these optional
		// hints rather than display stale availability claims. Occupied data stays native.
		if ( $affected ) {
			$data['busyness'] = array(); }
		return $data;
	}

	/**
	 * Validate supported writes against fresh calendar availability.
	 *
	 * @param mixed $data Native hook payload.
	 * @param mixed $provided_service Optional native service supplied by the hook.
	 * @param mixed $raw_backend_time Whether native backend time normalization is still pending.
	 * @param mixed $allow_cancellation Whether closed bookings may release capacity.
	 * @param mixed $paid_context Reviewed native payment flow identifier.
	 * @return void
	 * @throws RuntimeException When the requested operation cannot be safely validated.
	 * @throws \AmeliaBooking\Domain\Common\Exceptions\CustomException When the requested operation cannot be safely validated.
	 */
	private static function guard( $data, $provided_service = null, $raw_backend_time = false, $allow_cancellation = false, $paid_context = null ) {
		try {
			if ( ! is_array( $data ) ) {
				throw new RuntimeException( 'Unsupported appointment.' ); }
			$provider = self::positive_id( $data['providerId'] ?? null );
			if ( ! HSP_Calendar_Connection::blocking_enabled( $provider ) ) {
				return; }
			HSP_Calendar_Connection::with_employee(
				$provider,
				static function () use ( $data, $provided_service, $raw_backend_time, $allow_cancellation, $paid_context ) {
					// Only genuine closed child bookings release capacity on update.
					// Amelia rebuilds the top-level status from those children, so a
					// submitted top-level cancellation alone must not bypass checks.
					if ( ! empty( $data['recurring'] ) || ! empty( $data['package'] ) || ! empty( $data['isCart'] ) ) {
						self::unavailable( false, true ); }
					$service = self::service( $data['serviceId'] ?? null, $provided_service );
					$body    = self::body_duration( $service, $data['bookings'] ?? null, $allow_cancellation );
					if ( 0 === $body ) {
						return; } // Allow explicit cancellation/rejection without an availability dependency.
					if ( self::fixed_service( $service ) ) {
						self::paid_context( $data, $service, $paid_context ); }
					$zone = self::site_zone();
					if ( $raw_backend_time ) {
						if ( ! empty( $data['utc'] ) ) {
							$zone = new DateTimeZone( 'UTC' ); } elseif ( ! empty( $data['timeZone'] ) ) {
												$zone = self::zone( $data['timeZone'] ); }
					}
					$start          = self::timestamp( $data['bookingStart'] ?? null, $zone );
					[$from, $until] = self::interval( $start, $body, $service );
					if ( self::overlaps( $from, $until, self::busy( $from, $until, true ) ) ) {
						self::unavailable( true ); }
				}
			);
		} catch ( \AmeliaBooking\Domain\Common\Exceptions\CustomException $e ) {
			throw $e; } catch ( Throwable $e ) {
			self::unavailable(); }
	}

	/**
	 * Guard native frontend appointment data and preserve event flows.
	 *
	 * @param mixed $data Native hook payload.
	 * @return mixed
	 */
	public static function before_frontend( $data ) {
		// BookingApplicationService::getAppointmentData has already normalized
		// frontend UTC/client-time input to Amelia's site timezone before this hook.
		// This shared frontend hook also serves native event reservations: its
		// reservation service was selected from the same command type before
		// the filter. Appointment-only hooks must never trust this metadata.
		if ( is_array( $data ) && 'event' === ( $data['type'] ?? null ) ) {
			return $data; }
		self::guard( $data, null, false, false, 'onsite-frontend' );
		return $data;
	}

	/**
	 * Guard backend creation before native time normalization.
	 *
	 * @param mixed $data Native hook payload.
	 * @param mixed $service Native service data.
	 * @param mixed $payment Native payment payload retained in the hook signature.
	 * @return mixed
	 */
	public static function before_create( $data, $service = null, $payment = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the native hook contract; financial processing remains native.
		// Backend creation hook precedes AppointmentApplicationService::convertTime.
		self::guard( $data, $service, true );
		return $data;
	}

	/**
	 * Guard whole-appointment updates after native time normalization.
	 *
	 * @param mixed $data Native hook payload.
	 * @param mixed $removed_bookings Removed booking rows retained in the native hook signature.
	 * @param mixed $service Native service data.
	 * @return mixed
	 */
	public static function before_update( $data, $removed_bookings = null, $service = null ) {
		// Backend update has already called convertTime; retained flags must not
		// cause a second UTC/client-time conversion.
		self::guard( $data, $service, false, true );
		return $data;
	}

	/**
	 * Guard a booking added to an existing native appointment.
	 *
	 * @param mixed $booking Native booking record.
	 * @param mixed $service Native service data.
	 * @param mixed $appointment Native appointment record.
	 * @return mixed
	 */
	public static function before_booking_saved( $booking, $service, $appointment ) {
		if ( ! is_array( $appointment ) || ! is_array( $booking ) ) {
			self::unavailable(); }
		$bookings = $appointment['bookings'] ?? array();
		if ( ! is_array( $bookings ) ) {
			self::unavailable(); }
		$bookings[]              = $booking;
		$appointment['bookings'] = $bookings;
		self::guard( $appointment, $service );
		return $booking;
	}

	/**
	 * Parse the immutable native request through its installed middleware.
	 *
	 * @return object
	 * @throws RuntimeException When the required native context is unavailable or invalid.
	 */
	private static function reschedule_request() {
		$request = self::container()->get( 'request' );
		// The container request precedes Slim middleware. Use the native parser.
		if ( empty( $request->getParsedBody() ) ) {
			$capture = new class() implements \AmeliaVendor\Psr\Http\Server\RequestHandlerInterface {
				/**
				 * Parsed native request.
				 *
				 * @var object|null
				 */
				public $request = null;

				/**
				 * Capture the immutable request returned by native body parsing.
				 *
				 * @param \AmeliaVendor\Psr\Http\Message\ServerRequestInterface $request Native request.
				 * @return \AmeliaVendor\Psr\Http\Message\ResponseInterface
				 */
				public function handle( \AmeliaVendor\Psr\Http\Message\ServerRequestInterface $request ): \AmeliaVendor\Psr\Http\Message\ResponseInterface {
					$this->request = $request;
					return ( new \Slim\Psr7\Factory\ResponseFactory() )->createResponse();
				}
			};
			( new \Slim\Middleware\BodyParsingMiddleware() )->process( $request, $capture );
			$request = $capture->request;
		}
		if ( ! is_object( $request ) || ! is_array( $request->getParsedBody() ) ) {
			throw new RuntimeException( 'Native request parsing unavailable.' );
		}
		return $request;
	}

	/**
	 * Resolve canonical ownership and protect configured reschedule targets.
	 *
	 * @param mixed $old_appointment Native appointment before the change.
	 * @param mixed $booking Native booking record.
	 * @return bool
	 * @throws RuntimeException When the required native context is unavailable or invalid.
	 */
	private static function reschedule_is_scoped( $old_appointment, $booking ) {
		if ( ! is_array( $old_appointment ) || ! is_array( $booking ) ) {
			throw new RuntimeException( 'Native reschedule scope unavailable.' );
		}
		$booking_id         = self::positive_id( $booking['id'] ?? null );
		$appointment_id     = self::positive_id( $old_appointment['id'] ?? null );
		$container          = self::container();
		$native_booking     = $container->get( 'domain.booking.customerBooking.repository' )->getById( $booking_id );
		$native_appointment = $container->get( 'domain.booking.appointment.repository' )->getById( $appointment_id );
		if ( ! is_object( $native_booking ) || ! method_exists( $native_booking, 'toArray' ) ||
			! is_object( $native_appointment ) || ! method_exists( $native_appointment, 'toArray' ) ) {
			throw new RuntimeException( 'Native reschedule scope unavailable.' );
		}
		$native_booking     = $native_booking->toArray();
		$native_appointment = $native_appointment->toArray();
		if ( ! is_array( $native_booking ) || ! is_array( $native_appointment ) ||
			self::positive_id( $native_booking['id'] ?? null ) !== $booking_id ||
			self::positive_id( $native_booking['appointmentId'] ?? null ) !== $appointment_id ||
			self::positive_id( $native_appointment['id'] ?? null ) !== $appointment_id ||
			self::positive_id( $native_appointment['providerId'] ?? null ) !== self::positive_id( $old_appointment['providerId'] ?? null ) ) {
			throw new RuntimeException( 'Native reschedule membership unavailable.' );
		}
		$provider = self::positive_id( $native_appointment['providerId'] );
		if ( HSP_Calendar_Connection::blocking_enabled( $provider ) ) {
			return true;
		}
		// The native hook supplies the old appointment, not the requested target.
		// A move from an unconfigured employee into a configured one stays guarded.
		$body   = self::reschedule_request()->getParsedBody();
		$target = empty( $body['providerId'] ) ? $provider : self::positive_id( $body['providerId'] );
		return HSP_Calendar_Connection::blocking_enabled( $target );
	}

	/**
	 * Prove customer ownership and validate a supported time-only move.
	 *
	 * @param mixed $old_appointment Native appointment before the change.
	 * @param mixed $booking Native booking record.
	 * @param mixed $new_start Requested native booking start time.
	 * @return void
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	private static function customer_reschedule( $old_appointment, $booking, $new_start ) {
		// This proof relies on 9.9.1's controller and customer target-change
		// prohibition before its incomplete reschedule hook. Re-audit upgrades.
		if ( ! defined( 'AMELIA_VERSION' ) || AMELIA_VERSION !== '9.9.1' ||
			! is_array( $old_appointment ) || ! is_array( $booking ) ) {
			throw new RuntimeException( 'Unsupported reschedule context.' );
		}
		$booking_id     = self::positive_id( $booking['id'] ?? null );
		$appointment_id = self::positive_id( $old_appointment['id'] ?? null );
		$container      = self::container();
		$request        = self::reschedule_request();
		$query          = $request->getQueryParams();
		if ( 'POST' !== $request->getMethod() || 'cabinet-customer' !== ( $query['source'] ?? null ) ||
			! preg_match( '~(?:^|/)bookings/reassign/([1-9][0-9]*)/?$~D', $request->getUri()->getPath(), $route ) ||
			self::positive_id( $route[1] ) !== $booking_id ) {
			throw new RuntimeException( 'Unsupported reschedule route.' );
		}
		$body = $request->getParsedBody();
		if ( ! is_array( $body ) || ! is_string( $body['bookingStart'] ?? null ) ||
			! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $body['bookingStart'] ) ||
			array_diff( array_keys( $body ), array( 'bookingStart', 'timeZone', 'utcOffset' ) ) ) {
			throw new RuntimeException( 'Target-changing reschedule is unsupported.' );
		}
		if ( ( isset( $body['timeZone'] ) && ! is_string( $body['timeZone'] ) ) ||
			( isset( $body['utcOffset'] ) && ( ( ! is_int( $body['utcOffset'] ) && ! is_float( $body['utcOffset'] ) ) ||
				! is_finite( (float) $body['utcOffset'] ) || abs( $body['utcOffset'] ) > 86400 ) ) ) {
			throw new RuntimeException( 'Unsupported request timezone.' );
		}
		$date_class = '\\AmeliaBooking\\Domain\\Services\\DateTime\\DateTimeService';
		$normalized = $body['bookingStart'] . ':00';
		$input_zone = ! empty( $body['timeZone'] ) ? self::zone( $body['timeZone'] ) : self::site_zone();
		self::timestamp( $normalized, $input_zone );
		if ( 'UTC' === ( $body['timeZone'] ?? null ) ) {
			$normalized = $date_class::getCustomDateTimeFromUtc( $normalized );
		} elseif ( ! empty( $body['timeZone'] ) ) {
			$normalized = $date_class::getDateTimeObjectInTimeZone( $normalized, $body['timeZone'] )
				->setTimezone( $date_class::getTimeZone() )->format( 'Y-m-d H:i:s' );
		} elseif ( isset( $body['utcOffset'] ) && $container->get( 'domain.settings.service' )->getSetting( 'general', 'showClientTimeZone' ) ) {
			$normalized = $date_class::getCustomDateTimeFromUtc( $normalized );
		}
		if ( $normalized !== $new_start ) {
			throw new RuntimeException( 'Native target time does not match the request.' ); }
		$command = new \AmeliaBooking\Application\Commands\Booking\Appointment\ReassignBookingCommand( array( 'id' => $booking_id ) );
		$command->setPermissionService( $container->getPermissionsService() );
		$command->setUserApplicationService( $container->getUserApplicationService() );
		$command->setToken( $request );
		$command->setPage( $query['source'] );
		$actor = $command->authorize(); // Native JWT/cookie/WP actor and password policy.
		if ( 'customer' !== $actor->getType() || ! $actor->getId() ) {
			throw new RuntimeException( 'Customer authentication required.' );
		}
		$native_booking     = $container->get( 'domain.booking.customerBooking.repository' )->getById( $booking_id );
		$native_appointment = $container->get( 'domain.booking.appointment.repository' )->getById( $appointment_id );
		if ( ! is_object( $native_booking ) || ! is_object( $native_appointment ) ) {
			throw new RuntimeException( 'Native booking unavailable.' );
		}
		$native_booking     = $native_booking->toArray();
		$native_appointment = $native_appointment->toArray();
		if ( ! is_array( $native_booking ) || ! is_array( $native_appointment ) ||
			self::positive_id( $native_booking['id'] ?? null ) !== $booking_id ||
			self::positive_id( $native_booking['appointmentId'] ?? null ) !== $appointment_id ||
			self::positive_id( $native_booking['customerId'] ?? null ) !== self::positive_id( $actor->getId()->getValue() ) ||
			self::positive_id( $native_appointment['id'] ?? null ) !== $appointment_id ||
			! is_array( $native_appointment['bookings'] ?? null ) || 1 !== count( $native_appointment['bookings'] ) ) {
			throw new RuntimeException( 'Booking ownership or membership unavailable.' );
		}
		$child = reset( $native_appointment['bookings'] );
		if ( ! is_array( $child ) || self::positive_id( $child['id'] ?? null ) !== $booking_id ||
			self::positive_id( $child['customerId'] ?? null ) !== self::positive_id( $native_booking['customerId'] ) ||
			! in_array( $native_booking['status'] ?? null, array( 'approved', 'pending' ), true ) ||
			( $child['status'] ?? null ) !== $native_booking['status'] ||
			! in_array( $native_appointment['status'] ?? null, array( 'approved', 'pending' ), true ) ||
			1 !== self::positive_id( $native_booking['persons'] ?? null ) || 1 !== self::positive_id( $child['persons'] ?? null ) ) {
			throw new RuntimeException( 'Group, waiting or closed booking is unsupported.' );
		}
		foreach ( array( 'providerId', 'serviceId', 'locationId' ) as $key ) {
			if ( ( $native_appointment[ $key ] ?? null ) !== ( $old_appointment[ $key ] ?? null ) ) {
				throw new RuntimeException( 'Native appointment target changed.' );
			}
		}
		$provider   = self::positive_id( $native_appointment['providerId'] ?? null );
		$service_id = self::positive_id( $native_appointment['serviceId'] ?? null );
		self::timestamp( $new_start, self::site_zone() );
		$existing = $container->get( 'domain.booking.appointment.repository' )->getFiltered(
			array(
				'dates'         => array( $new_start, $new_start ),
				'services'      => array( $service_id ),
				'providers'     => array( $provider ),
				'skipServices'  => true,
				'skipProviders' => true,
				'skipCustomers' => true,
			)
		);
		if ( ! is_object( $existing ) || ! method_exists( $existing, 'getItems' ) ) {
			throw new RuntimeException( 'Target appointment lookup unavailable.' );
		}
		foreach ( $existing->getItems() as $target ) {
			$target = is_object( $target ) && method_exists( $target, 'toArray' ) ? $target->toArray() : null;
			if ( ! is_array( $target ) || self::positive_id( $target['id'] ?? null ) !== $appointment_id ) {
				throw new RuntimeException( 'Merging into another appointment is unsupported.' );
			}
		}
		HSP_Calendar_Connection::with_employee(
			$provider,
			static function () use ( $native_appointment, $native_booking, $service_id, $new_start ) {
				$service = self::service( $service_id );
				if ( self::fixed_service( $service ) ) {
					self::paid_customer_payment( $service, $native_booking ); }
				if ( 1 !== self::positive_id( $service['maxCapacity'] ?? null ) ||
				1 !== self::positive_id( $service['minCapacity'] ?? null ) ) {
					throw new RuntimeException( 'Capacity-one service required.' );
				}
				$native_appointment['bookingStart'] = $new_start;
				self::guard( $native_appointment, $service, false, false, 'onsite-customer-move' ); // Fresh CalDAV, duration, extras and buffers.
			}
		);
	}

	/**
	 * Guard covered rescheduling while preserving unrelated native employees.
	 *
	 * @param mixed $old_appointment Native appointment before the change.
	 * @param mixed $booking Native booking record.
	 * @param mixed $new_start Requested native booking start time.
	 * @return void
	 * @throws \AmeliaBooking\Domain\Common\Exceptions\CustomException When the requested operation cannot be safely validated.
	 */
	public static function before_reschedule( $old_appointment, $booking, $new_start ) {
		if ( null !== $booking ) {
			try {
				if ( ! self::reschedule_is_scoped( $old_appointment, $booking ) ) {
					return;
				}
				self::customer_reschedule( $old_appointment, $booking, $new_start ); } catch ( \AmeliaBooking\Domain\Common\Exceptions\CustomException $e ) {
				throw $e; } catch ( Throwable $e ) {
					self::unavailable( false, true ); }
				return;
		}
		if ( ! is_array( $old_appointment ) ) {
			self::unavailable(); }
		$old_appointment['bookingStart'] = $new_start;
		self::guard( $old_appointment );
	}

	/**
	 * Resolve canonical booking membership before activating a reservation.
	 *
	 * @param mixed $booking Native booking record.
	 * @param mixed $requested_status Native target reservation status.
	 * @return void
	 * @throws RuntimeException When the requested operation cannot be safely validated.
	 * @throws \AmeliaBooking\Domain\Common\Exceptions\CustomException When the requested operation cannot be safely validated.
	 */
	private static function before_booking_status( $booking, $requested_status ) {
		try {
			if ( ! is_array( $booking ) ) {
				throw new RuntimeException( 'Booking unavailable.' ); }
			$booking_id = self::positive_id( $booking['id'] ?? null );
			// These actions also serve event reservations. Determine scope from
			// persisted native data, never from a caller-supplied type flag.
			$native_booking = self::container()->get( 'domain.booking.customerBooking.repository' )->getById( $booking_id );
			if ( ! is_object( $native_booking ) || ! method_exists( $native_booking, 'toArray' ) ) {
				throw new RuntimeException( 'Booking unavailable.' ); }
			$native_booking = $native_booking->toArray();
			if ( ! is_array( $native_booking ) || self::positive_id( $native_booking['id'] ?? null ) !== $booking_id ||
				! array_key_exists( 'appointmentId', $native_booking ) ) {
				throw new RuntimeException( 'Booking unavailable.' ); }
			if ( null === $native_booking['appointmentId'] ) {
				return; } // Canonical event booking.
			$appointment_id     = self::positive_id( $native_booking['appointmentId'] );
			$native_appointment = self::container()->get( 'domain.booking.appointment.repository' )->getById( $appointment_id );
			if ( ! is_object( $native_appointment ) || ! method_exists( $native_appointment, 'toArray' ) ) {
				throw new RuntimeException( 'Appointment unavailable.' ); }
			$appointment = $native_appointment->toArray();
			if ( ! is_array( $appointment ) || self::positive_id( $appointment['id'] ?? null ) !== $appointment_id ||
				! is_array( $appointment['bookings'] ?? null ) ) {
				throw new RuntimeException( 'Appointment unavailable.' ); }
			$provider = self::positive_id( $appointment['providerId'] ?? null );
			if ( ! HSP_Calendar_Connection::blocking_enabled( $provider ) ) {
				return; }
			$found = false;
			foreach ( $appointment['bookings'] as $index => $child ) {
				if ( ! is_array( $child ) ) {
					throw new RuntimeException( 'Booking unavailable.' ); }
				if ( self::positive_id( $child['id'] ?? null ) === $booking_id ) {
					$appointment['bookings'][ $index ] = array_merge( $child, $native_booking, array( 'status' => $requested_status ) );
					$found                             = true;
				}
			}
			if ( ! $found ) {
				throw new RuntimeException( 'Booking unavailable.' ); }
			$appointment['status'] = $requested_status;
			self::guard( $appointment );
		} catch ( \AmeliaBooking\Domain\Common\Exceptions\CustomException $e ) {
			throw $e; } catch ( Throwable $e ) {
			self::unavailable(); }
	}

	/**
	 * Guard approval of a native customer booking.
	 *
	 * @param mixed $booking Native booking record.
	 * @return void
	 */
	public static function before_approved( $booking ) {
		self::before_booking_status( $booking, 'approved' );
	}

	/**
	 * Guard transition of a native customer booking to pending.
	 *
	 * @param mixed $booking Native booking record.
	 * @return void
	 */
	public static function before_pending( $booking ) {
		self::before_booking_status( $booking, 'pending' );
	}

	/**
	 * Guard native whole-appointment status activation.
	 *
	 * @param mixed $appointment Native appointment record.
	 * @param mixed $requested_status Native target reservation status.
	 * @return void
	 */
	public static function before_appointment_status( $appointment, $requested_status ) {
		if ( ! in_array( $requested_status, array( 'approved', 'pending' ), true ) ) {
			return; }
		// Native whole-appointment status handling has updated child statuses
		// in memory, but has not written them when this action is fired.
		self::guard( $appointment );
	}
}
