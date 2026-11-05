<?php
/**
 * Amelia 9.9.1 lifecycle observer and canonical, customer-free calendar snapshot.
 * Hooks collect integer identifiers only. Shutdown enqueues; it never drains the
 * outbox or makes an HTTP request. A later cron request must read snapshot().
 * Some native hooks run before COMMIT, so hook payloads are never event content.
 *
 * @package HSP_Calendar_Connection
 */

defined( 'ABSPATH' ) || exit;

/** Collect lifecycle identifiers and project committed native appointments. */
final class HSPCC_Amelia_Sync {
	/**
	 * Maximum accepted appointment duration in seconds.
	 *
	 * @var int
	 */
	private const MAX_DURATION = 2678400;
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
	 * Collected appointment identifiers and observed native owners.
	 *
	 * @var array
	 */
	private static $appointments = array();
	/**
	 * Collected booking identifiers awaiting canonical lookup.
	 *
	 * @var array
	 */
	private static $bookings = array();
	/**
	 * Collected WooCommerce order identifiers awaiting payment lookup.
	 *
	 * @var array
	 */
	private static $orders = array();

	/**
	 * Register native appointment hooks once per request.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return; }
		self::$booted = true;
		add_action( 'amelia_after_appointment_added', array( __CLASS__, 'appointment' ), 1000, 1 );
		add_action( 'amelia_after_appointment_updated', array( __CLASS__, 'updated' ), 1000, 2 );
		add_action( 'amelia_after_appointment_deleted', array( __CLASS__, 'deleted' ), 1000, 1 );
		add_action( 'amelia_after_appointment_status_updated', array( __CLASS__, 'appointment' ), 1000, 1 );
		add_action( 'amelia_after_booking_added', array( __CLASS__, 'result' ), 1000, 1 );
		add_action( 'amelia_after_appointment_booking_saved', array( __CLASS__, 'booking_saved' ), 1000, 3 );
		add_action( 'amelia_after_booking_rescheduled', array( __CLASS__, 'rescheduled' ), 1000, 2 );
		add_action( 'amelia_after_package_booking_deleted', array( __CLASS__, 'package_deleted' ), 1000, 2 );
		foreach ( array( 'approved', 'pending', 'canceled', 'rejected', 'no-show', 'waiting' ) as $status ) {
			add_action( 'amelia_after_booking_' . $status, array( __CLASS__, 'result' ), 1000, 1 );
		}
		foreach ( array( 'approved', 'rejected' ) as $status ) {
			add_action( 'amelia_after_booking_' . $status . '_link', array( __CLASS__, 'booking' ), 1000, 1 );
			// Native after_*_link only runs on the configured redirect branch.
			// A memory-only before observer also covers the default response.
			add_action( 'amelia_before_booking_' . $status . '_link', array( __CLASS__, 'booking' ), 1000, 1 );
		}
		// Remote cancellation has the same conditional redirect after action.
		add_action( 'amelia_before_booking_canceled', array( __CLASS__, 'booking' ), 1000, 1 );
		add_action( 'amelia_after_payment_link_callback', array( __CLASS__, 'payment' ), 1000, 1 );
		// Supplemental only: Amelia's webhook actions are feature-gated.
		add_action( 'AmeliaAppointmentBookingStatusUpdated', array( __CLASS__, 'appointment' ), 1000, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order' ), 1000, 1 );
		add_action( 'shutdown', array( __CLASS__, 'enqueue_pending' ), PHP_INT_MAX, 0 );
	}

	/**
	 * Parse an optional native record identifier.
	 *
	 * @param mixed $value Value to validate.
	 * @return int|null
	 */
	private static function id( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
			return null; }
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $id ? null : $id;
	}

	/**
	 * Read the current connection owner from explicit employee context.
	 *
	 * @return int
	 * @throws RuntimeException When the required native context is unavailable or invalid.
	 */
	private static function provider() {
		// Queue calls snapshot inside the durable job owner's explicit scope.
		// That owner survives deletion; administrator selection is not routing.
		$provider = self::id( HSP_Calendar_Connection::employee_id() );
		if ( null === $provider ) {
			throw new RuntimeException( 'Configured employee unavailable.' ); }
		return $provider;
	}

	/**
	 * Normalize an optional lifecycle payload to an array.
	 *
	 * @param mixed $value Value to validate.
	 * @return array
	 */
	private static function array_data( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Collect appointment identity and known owners for later reconciliation.
	 *
	 * @param mixed $id Native record identifier.
	 * @param mixed $deleted Whether a native deletion was observed.
	 * @param mixed $employee_id Known native employee owner, when available.
	 * @return void
	 */
	private static function remember( $id, $deleted = false, $employee_id = null ) {
		$id = self::id( $id );
		if ( null === $id ) {
			return; }
		if ( ! isset( self::$appointments[ $id ] ) ) {
			self::$appointments[ $id ] = array(
				'deleted' => false,
				'owners'  => array(),
				'resolve' => false,
			); }
		self::$appointments[ $id ]['deleted'] = self::$appointments[ $id ]['deleted'] || $deleted;
		$employee_id                          = self::id( $employee_id );
		if ( null === $employee_id ) {
			self::$appointments[ $id ]['resolve'] = true; } else {
			self::$appointments[ $id ]['owners'][ $employee_id ] = true; }
	}

	/**
	 * Collect identity from an appointment-specific native hook.
	 *
	 * @param mixed $data Native hook payload.
	 * @return void
	 */
	public static function appointment( $data ) {
		$data = self::array_data( $data );
		// This callback is registered only on appointment-specific native hooks.
		self::remember( $data['id'] ?? null, false, $data['providerId'] ?? null );
	}

	/**
	 * Collect both prior and current native appointment owners.
	 *
	 * @param mixed $appointment Native appointment record.
	 * @param mixed $old_appointment Native appointment before the change.
	 * @return void
	 */
	public static function updated( $appointment, $old_appointment = null ) {
		self::appointment( $appointment );
		self::appointment( $old_appointment );
	}

	/**
	 * Retain the deleted appointment owner for later calendar cleanup.
	 *
	 * @param mixed $appointment Native appointment record.
	 * @return void
	 */
	public static function deleted( $appointment ) {
		$appointment = self::array_data( $appointment );
		self::remember( $appointment['id'] ?? null, true, $appointment['providerId'] ?? null );
	}

	/**
	 * Collect a booking or its known parent appointment identifier.
	 *
	 * @param mixed $booking Native booking record.
	 * @return void
	 */
	public static function booking( $booking ) {
		$booking = self::array_data( $booking );
		if ( array_key_exists( 'appointmentId', $booking ) ) {
			// An explicit null is an event booking, never an appointment ID.
			self::remember( $booking['appointmentId'] );
			return;
		}
		$id = self::id( $booking['id'] ?? null );
		if ( null !== $id ) {
			self::$bookings[ $id ] = true; }
	}

	/**
	 * Collect identifiers from supported native result structures.
	 *
	 * @param mixed $data Native hook payload.
	 * @return void
	 */
	public static function result( $data ) {
		$data = self::array_data( $data );
		if ( isset( $data['type'] ) && 'event' === $data['type'] ) {
			return; }
		if ( isset( $data['appointment'] ) ) {
			self::appointment( $data['appointment'] ); }
		if ( isset( $data['newAppointment'] ) ) {
			self::appointment( $data['newAppointment'] ); }
		if ( isset( $data['oldAppointment'] ) ) {
			self::appointment( $data['oldAppointment'] ); }
		if ( isset( $data['booking'] ) ) {
			self::booking( $data['booking'] ); }
		if ( array_key_exists( 'appointmentId', $data ) ) {
			self::booking( $data ); }
		// Only known native reservation lists are traversed, never customer data.
		foreach ( array( 'recurring', 'package' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				foreach ( $data[ $key ] as $reservation ) {
					if ( is_array( $reservation ) ) {
						if ( isset( $reservation['appointment'] ) ) {
							self::appointment( $reservation['appointment'] ); }
						if ( isset( $reservation['booking'] ) ) {
							self::booking( $reservation['booking'] ); }
					}
				}
			}
		}
	}

	/**
	 * Collect the saved booking and its parent appointment.
	 *
	 * @param mixed $booking Native booking record.
	 * @param mixed $service Native service data.
	 * @param mixed $appointment Native appointment record.
	 * @return void
	 */
	public static function booking_saved( $booking, $service = null, $appointment = null ) {
		self::appointment( $appointment );
		self::booking( $booking );
	}

	/**
	 * Collect prior appointment and current booking identifiers.
	 *
	 * @param mixed $old_appointment Native appointment before the change.
	 * @param mixed $booking Native booking record.
	 * @return void
	 */
	public static function rescheduled( $old_appointment, $booking = null ) {
		self::appointment( $old_appointment );
		self::booking( $booking );
	}

	/**
	 * Collect package deletion without assuming the parent disappeared.
	 *
	 * @param mixed $appointment Native appointment record.
	 * @param mixed $booking Native booking record.
	 * @return void
	 */
	public static function package_deleted( $appointment, $booking = null ) {
		// A group parent may remain; this is not an unconditional tombstone.
		self::appointment( $appointment );
		self::booking( $booking );
	}

	/**
	 * Collect appointment booking identity from a native payment hook.
	 *
	 * @param mixed $payment Native payment payload retained in the hook signature.
	 * @return void
	 */
	public static function payment( $payment ) {
		$payment = self::array_data( $payment );
		if ( 'appointment' !== ( $payment['entity'] ?? null ) ) {
			return; }
		$id = self::id( $payment['customerBookingId'] ?? null );
		if ( null !== $id ) {
			self::$bookings[ $id ] = true; }
	}

	/**
	 * Collect a WooCommerce order identifier for later native lookup.
	 *
	 * @param mixed $order_id Native WooCommerce order identifier.
	 * @return void
	 */
	public static function order( $order_id ) {
		$id = self::id( $order_id );
		if ( null !== $id ) {
			self::$orders[ $id ] = true; }
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
		$container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
		if ( ! is_object( $container ) || ! method_exists( $container, 'get' ) ) {
			throw new RuntimeException( 'Amelia unavailable.' ); }
		self::$container = $container;
		return $container;
	}

	/**
	 * Return a sanitized synchronization failure without native private details.
	 *
	 * @param mixed $code Stable public error identifier.
	 * @return WP_Error
	 */
	private static function error( $code ) {
		// Native DB exceptions can contain SQL or private fields. Never expose it.
		return new WP_Error( $code, __( 'The appointment could not be prepared for calendar synchronization.', 'hsp-calendar-connection' ) );
	}

	/**
	 * Queue collected identifiers after the native lifecycle completes.
	 *
	 * Queue identifiers only. The queue must schedule a later request, not drain.
	 *
	 * @return void
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	public static function enqueue_pending() {
		if ( ! class_exists( 'HSPCC_Sync_Queue' ) || ! is_callable( array( 'HSPCC_Sync_Queue', 'enqueue' ) ) ) {
			return; }
		foreach ( array_keys( self::$orders ) as $order_id ) {
			try {
				$payments = self::container()->get( 'domain.payment.repository' )->getByEntityId( $order_id, 'wcOrderId' );
				if ( ! is_object( $payments ) || ! method_exists( $payments, 'getItems' ) ) {
					throw new RuntimeException( 'Payment lookup unavailable.' ); }
				foreach ( $payments->getItems() as $payment ) {
					if ( is_object( $payment ) && method_exists( $payment, 'toArray' ) ) {
						self::payment( $payment->toArray() ); }
				}
				unset( self::$orders[ $order_id ] );
			} catch ( Throwable $e ) {
				do_action( 'hspcc_sync_collection_error', self::error( 'hspcc_sync_payment_lookup_failed' ) );
			}
		}
		foreach ( array_keys( self::$bookings ) as $booking_id ) {
			try {
				$booking = self::container()->get( 'domain.booking.customerBooking.repository' )->getById( $booking_id );
				if ( ! is_object( $booking ) || ! method_exists( $booking, 'toArray' ) ) {
					throw new RuntimeException( 'Booking lookup unavailable.' ); }
				$booking = $booking->toArray();
				if ( ! is_array( $booking ) || self::id( $booking['id'] ?? null ) !== $booking_id || ! array_key_exists( 'appointmentId', $booking ) ) {
					throw new RuntimeException( 'Booking lookup unavailable.' );
				}
				self::remember( $booking['appointmentId'] );
				unset( self::$bookings[ $booking_id ] );
			} catch ( Throwable $e ) {
				do_action( 'hspcc_sync_collection_error', self::error( 'hspcc_sync_booking_lookup_failed' ) );
			}
		}
		foreach ( self::$appointments as $id => $pending ) {
			try {
				if ( $pending['resolve'] && ! $pending['owners'] ) {
					// A booking callback may supply only its parent ID. Resolve
					// the persisted owner after the native lifecycle has ended.
					$native = self::container()->get( 'domain.booking.appointment.repository' )->getByIdWithoutBookings( $id );
					if ( null !== $native ) {
						if ( ! is_object( $native ) || ! method_exists( $native, 'toArray' ) ) {
							throw new RuntimeException( 'Appointment lookup unavailable.' ); }
						$native = $native->toArray();
						$owner  = is_array( $native ) && self::id( $native['id'] ?? null ) === $id ? self::id( $native['providerId'] ?? null ) : null;
						if ( null === $owner ) {
							throw new RuntimeException( 'Appointment owner unavailable.' ); }
						$pending['owners'][ $owner ] = true;
					}
					// Missing native rows have no newly inferred owner. A
					// deletion payload's old owner and durable tracked jobs
					// retain ownership; UI selection is never a substitute.
				}
				$failed = false;
				foreach ( array_keys( $pending['owners'] ) as $employee_id ) {
					if ( ! HSP_Calendar_Connection::writing_enabled( $employee_id ) ) {
						continue; }
					$result = HSPCC_Sync_Queue::enqueue( $id, $pending['deleted'], $employee_id );
					if ( is_wp_error( $result ) || false === $result ) {
						$failed = true;
						do_action( 'hspcc_sync_collection_error', self::error( 'hspcc_sync_enqueue_failed' ) );
					}
				}
				if ( ! $failed ) {
					unset( self::$appointments[ $id ] ); }
			} catch ( Throwable $e ) {
				do_action( 'hspcc_sync_collection_error', self::error( 'hspcc_sync_owner_lookup_failed' ) );
			}
		}
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
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new RuntimeException( 'Unsupported appointment date.' );
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new RuntimeException( 'Unsupported appointment date.' );
		}
		// A local wall clock during a DST fold has two possible UTC instants.
		$naive       = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) )->getTimestamp();
		$transitions = $zone->getTransitions( $naive - 172800, $naive + 172800 );
		$matches     = array();
		if ( is_array( $transitions ) ) {
			foreach ( $transitions as $transition ) {
				$candidate = $naive - (int) $transition['offset'];
				if ( ( new DateTimeImmutable( '@' . $candidate ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' ) === $value ) {
					$matches[ $candidate ] = true; }
			}
			if ( 1 !== count( $matches ) ) {
				throw new RuntimeException( 'Ambiguous appointment date.' ); }
		}
		$timestamp = $date->getTimestamp();
		if ( $timestamp <= 0 ) {
			throw new RuntimeException( 'Unsupported appointment date.' ); }
		return $timestamp;
	}

	/**
	 * Project committed native appointment state into customer-free calendar data.
	 *
	 * Re-read in the worker's NEW request. A delete is only permission to remove an existing bridge-owned mapping; an unmapped appointment must be a no-op. DB/shape/date failures are WP_Error, never evidence that an event vanished.
	 *
	 * @param mixed $appointment_id Native appointment identifier.
	 * @return array|WP_Error
	 * @throws RuntimeException When required native context or data cannot be validated.
	 */
	public static function snapshot( $appointment_id ) {
		$id = self::id( $appointment_id );
		if ( null === $id ) {
			return self::error( 'hspcc_sync_invalid_id' ); }
		try {
			$configured_provider = self::provider();
			$appointment         = self::container()->get( 'domain.booking.appointment.repository' )->getByIdWithoutBookings( $id );
			if ( null === $appointment ) {
				return array(
					'action'        => 'delete',
					'appointmentId' => $id,
					'providerId'    => $configured_provider,
				); }
			if ( ! is_object( $appointment ) || ! method_exists( $appointment, 'toArray' ) ) {
				throw new RuntimeException( 'Appointment unavailable.' ); }
			$data = $appointment->toArray();
			if ( ! is_array( $data ) || self::id( $data['id'] ?? null ) !== $id ) {
				throw new RuntimeException( 'Appointment unavailable.' ); }
			$provider = self::id( $data['providerId'] ?? null );
			if ( null === $provider ) {
				throw new RuntimeException( 'Unknown provider.' ); }
			$delete = array(
				'action'        => 'delete',
				'appointmentId' => $id,
				'providerId'    => $provider,
			);
			if ( $provider !== $configured_provider ) {
				return $delete; }
			$status = $data['status'] ?? null;
			if ( in_array( $status, array( 'canceled', 'rejected', 'no-show', 'waiting' ), true ) ) {
				return $delete; }
			if ( ! in_array( $status, array( 'approved', 'pending' ), true ) ) {
				throw new RuntimeException( 'Unsupported appointment status.' ); }
			if ( ! empty( $data['parentId'] ) ) {
				throw new RuntimeException( 'Recurring appointments are unsupported.' ); }
			if ( ! isset( $data['bookings'] ) || ! is_array( $data['bookings'] ) ) {
				throw new RuntimeException( 'Bookings unavailable.' ); }
			$active = false;
			foreach ( $data['bookings'] as $booking ) {
				if ( ! is_array( $booking ) || ! isset( $booking['status'] ) ) {
					throw new RuntimeException( 'Booking status unavailable.' ); }
				if ( in_array( $booking['status'], array( 'approved', 'pending' ), true ) ) {
					$active = true; } elseif ( ! in_array( $booking['status'], array( 'canceled', 'rejected', 'no-show', 'waiting' ), true ) ) {
					throw new RuntimeException( 'Unsupported booking status.' ); }
			}
			if ( ! $active ) {
				return $delete; }
			$zone = \AmeliaBooking\Domain\Services\DateTime\DateTimeService::getTimeZone();
			if ( ! $zone instanceof DateTimeZone ) {
				throw new RuntimeException( 'Amelia timezone unavailable.' ); }
			$start = self::timestamp( $data['bookingStart'] ?? null, $zone );
			$end   = self::timestamp( $data['bookingEnd'] ?? null, $zone );
			if ( $end <= $start || $end - $start > self::MAX_DURATION ) {
				throw new RuntimeException( 'Unsupported appointment bounds.' ); }
			$service_id = self::id( $data['serviceId'] ?? null );
			if ( null === $service_id ) {
				throw new RuntimeException( 'Service unavailable.' ); }
			$service = self::array_data( $data['service'] ?? null );
			if ( self::id( $service['id'] ?? null ) !== $service_id || ! isset( $service['name'] ) ) {
				$service = self::container()->get( 'application.bookable.service' )->getAppointmentService( $service_id, $configured_provider )->toArray();
			}
			if ( ! is_array( $service ) || self::id( $service['id'] ?? null ) !== $service_id || ! is_string( $service['name'] ?? null ) ) {
				throw new RuntimeException( 'Service unavailable.' ); }
			if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $service['name'] ) ) {
				throw new RuntimeException( 'Unsupported service label.' ); }
			$label = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $service['name'] ) ) ?? '' );
			if ( '' === $label || strlen( $label ) > 500 || preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
				throw new RuntimeException( 'Unsupported service label.' ); }
			return array(
				'action'        => 'upsert',
				'appointmentId' => $id,
				'providerId'    => $provider,
				'startUtc'      => $start,
				'endUtc'        => $end,
				'serviceLabel'  => $label,
				'status'        => 'approved' === $status ? 'confirmed' : 'tentative',
			);
		} catch ( Throwable $e ) {
			return self::error( 'hspcc_sync_snapshot_failed' );
		}
	}
}
