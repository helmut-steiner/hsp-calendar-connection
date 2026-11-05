<?php
/**
 * Durable, payload-free outbox for native Amelia appointment projections.
 *
 * Enqueue never reads an appointment or performs HTTP. A later WP-Cron request
 * reads current native state. Dirty tokens and worker acknowledgements live in
 * separate options so an enqueue racing a worker cannot be acknowledged away.
 * All writes use the WordPress Options/Cron APIs. Only this class's own option
 * names are enumerated with a read-only query; event/customer data is not stored.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinate durable, bounded and recoverable calendar writeback.
 */
final class HSPCC_Sync_Queue {
	private const DIRTY         = 'hspcc_queue_dirty_';
	private const STATE         = 'hspcc_queue_state_';
	private const LOCK          = 'hspcc_queue_lock_';
	private const SITE          = 'hsp_calendar_site_uuid';
	private const HEALTH        = 'hspcc_queue_worker_health';
	private const CURSOR        = 'hspcc_queue_scan_cursor';
	private const CRON          = 'hspcc_queue_retry';
	private const KICK          = 'hspcc_queue_kick';
	private const INTERVAL      = 'hspcc_five_minutes';
	private const LEASE_SECONDS = 300; // Transport must finish well inside this bound.
	private const MAX_SCAN      = 100;
	private const MAX_WRITES    = 5;
	private const RUN_SECONDS   = 20;
	/**
	 * Whether the worker hooks have been registered.
	 *
	 * @var bool
	 */
	private static $booted = false;
	/**
	 * Whether this request is currently processing a worker batch.
	 *
	 * @var bool
	 */
	private static $running = false;
	/**
	 * Whether this request has enqueued durable work.
	 *
	 * @var bool
	 */
	private static $enqueued = false;
	/**
	 * Whether the last owned-option scan failed.
	 *
	 * @var bool
	 */
	private static $scan_failed = false;

	/**
	 * Register the durable queue's scheduling and worker hooks once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return; }
		self::$booted = true;
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Durable calendar writeback retries every five minutes with a bounded worker; fifteen minutes would delay appointment reconciliation.
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'process_pending' ) );
		add_action( self::KICK, array( __CLASS__, 'process_pending' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		// The originating Amelia request only schedules a later worker. It
		// must not snapshot data while an Amelia transaction may still be open.
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), 999 );
	}

	/**
	 * Register the bounded retry schedule used by the queue.
	 *
	 * @param array $schedules Existing WordPress cron schedules.
	 * @return array
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 300,
			'display'  => __( 'HSP calendar retry (five minutes)', 'hsp-calendar-connection' ),
		);
		return $schedules;
	}

	/**
	 * Ensure that the recurring queue worker is scheduled.
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, self::INTERVAL, self::CRON );
		}
	}

	/**
	 * Remove worker schedules while retaining durable recovery state.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::KICK );
	}

	/**
	 * Schedule a near-term worker without processing writes in the current request.
	 *
	 * @return void
	 */
	private static function kick(): void {
		self::ensure_schedule();
		if ( ! wp_next_scheduled( self::KICK ) ) {
			wp_schedule_single_event( time() + 10, self::KICK ); }
	}

	/**
	 * Schedule deferred processing when this request enqueued a job.
	 *
	 * @return void
	 */
	public static function shutdown(): void {
		if ( self::$enqueued ) {
			self::kick(); }
	}

	/**
	 * Persist a technical dirty marker without reading appointment data.
	 *
	 * @param int  $appointment_id Positive canonical appointment identifier.
	 * @param bool $deleted Deletion hint; committed native state remains authoritative.
	 * @param int  $employee_id Positive canonical employee identifier.
	 * @return bool
	 */
	public static function enqueue( int $appointment_id, bool $deleted = false, int $employee_id = 1 ): bool {
		if ( $appointment_id < 1 || $employee_id < 1 ) {
			return false; }
		// The deletion hint intentionally does not become the desired state:
		// current native state, including a later restoration, always wins.
		// An omitted employee selects employee 1, never the administrator view.
		return HSP_Calendar_Connection::with_employee(
			$employee_id,
			static function () use ( $appointment_id, $employee_id ) {
				$marker = array(
					'token'      => wp_generate_uuid4(),
					'queuedUtc'  => time(),
					'employeeId' => $employee_id,
				);
				HSP_Calendar_Connection::set_private_option( self::DIRTY . $appointment_id, $marker );
				self::$enqueued = true;
				self::kick();
				return self::fresh( self::DIRTY . $appointment_id ) === $marker;
			}
		);
	}

	/**
	 * Process a bounded batch of pending work across enabled employees.
	 *
	 * @return array
	 */
	public static function process_pending(): array {
		$result  = array(
			'checked'   => 0,
			'synced'    => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'locked'    => 0,
		);
		$writers = self::writers();
		if ( self::$running || ! $writers ) {
			return $result; }
		self::$running = true;
		$started       = microtime( true );
		$writes        = 0;
		try {
			$cursor = get_option( self::CURSOR, '' );
			if ( ! is_string( $cursor ) || ! str_starts_with( $cursor, self::DIRTY ) || strlen( $cursor ) > 191 ) {
				$cursor = ''; }
			$names = self::names( self::MAX_SCAN, $cursor );
			if ( ! $names && '' !== $cursor ) {
				$names = self::names( self::MAX_SCAN ); }
			foreach ( $names as $name ) {
				if ( $writes >= self::MAX_WRITES || microtime( true ) - $started >= self::RUN_SECONDS ) {
					break; }
				// Advance past malformed names too; one damaged owned option
				// must not prevent later valid tracked IDs being reconciled.
				update_option( self::CURSOR, $name, false );
				$identity = self::identity_from_name( $name );
				if ( null === $identity || ! in_array( $identity['employeeId'], $writers, true ) ) {
					continue; }
				$outcome = HSP_Calendar_Connection::with_employee(
					$identity['employeeId'],
					static function () use ( $identity ) {
						return self::configured() ? self::process_one( $identity['appointmentId'] ) : null;
					}
				);
				if ( null === $outcome ) {
					continue; }
				++$result['checked'];
				if ( 'synced' === $outcome ) {
					++$writes;
					++$result['synced']; } elseif ( 'failed' === $outcome ) {
					++$writes;
					++$result['failed']; } elseif ( 'locked' === $outcome ) {
						++$result['locked']; } else {
						++$result['unchanged']; }
			}
		} catch ( Throwable $error ) {
			// No exception text, native payload, URL or HTTP data is persisted.
			++$result['failed'];
		} finally {
			self::$running = false; }
		return $result;
	}

	/**
	 * Check whether the current employee can perform calendar writes.
	 *
	 * @return bool
	 */
	private static function configured(): bool {
		try {
			return class_exists( 'HSPCC_Calendar_Storage' ) && class_exists( 'HSPCC_Amelia_Sync' ) &&
				HSP_Calendar_Connection::writing_enabled( HSP_Calendar_Connection::employee_id() ) &&
				HSPCC_Calendar_Storage::configured() && self::key( HSPCC_Calendar_Storage::target_key() );
		} catch ( Throwable $error ) {
			return false; }
	}

	/**
	 * List employee connections currently enabled for writeback.
	 *
	 * @return array
	 */
	private static function writers(): array {
		try {
			return array_values(
				array_unique(
					array_filter(
						HSP_Calendar_Connection::configured_employees( 'writing' ),
						static function ( $id ) {
							return is_int( $id ) && $id > 0;
						}
					)
				)
			);
		} catch ( Throwable $error ) {
			return array(); }
	}

	/**
	 * Validate a durable dirty marker.
	 *
	 * @param mixed $marker Stored dirty marker to validate.
	 * @return bool
	 */
	private static function marker_valid( $marker ): bool {
		if ( ! is_array( $marker ) || ! self::uuid( $marker['token'] ?? null ) ) {
			return false; }
		$employee_id = HSP_Calendar_Connection::employee_id();
		return ( $marker['employeeId'] ?? null ) === $employee_id;
	}

	/**
	 * Reconcile and apply one appointment's current canonical state.
	 *
	 * @param int $id Positive canonical appointment identifier.
	 * @return string
	 */
	private static function process_one( int $id ): string {
		$lease = self::acquire( $id );
		if ( '' === $lease ) {
			return 'locked'; }
		$state = null;
		try {
			$marker = self::fresh( self::DIRTY . $id );
			if ( ! self::marker_valid( $marker ) ) {
				return 'failed'; }
			$token = $marker['token'];
			$state = self::state( $id );
			if ( $state instanceof WP_Error ) {
				return 'failed'; }
			$native = HSPCC_Amelia_Sync::snapshot( $id );
			if ( $native instanceof WP_Error ) {
				if ( $state['nextRetryUtc'] > time() && $state['attemptToken'] === $token ) {
					return 'unchanged'; }
				return self::failed( $id, $lease, $state, $token, 'snapshot_failed' );
			}
			$site   = self::site_uuid();
			$target = HSPCC_Calendar_Storage::target_key();
			$job    = self::job( $id, $native, $site, $target );
			if ( $job instanceof WP_Error ) {
				if ( $state['nextRetryUtc'] > time() && $state['attemptToken'] === $token ) {
					return 'unchanged'; }
				return self::failed( $id, $lease, $state, $token, 'snapshot_failed' );
			}
			$hash = hash( 'sha256', wp_json_encode( $job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			if ( 'upsert' === $job['action'] && ! self::transfer_clear( $id ) ) {
				if ( 'owner_transfer_pending' === $state['errorCode'] && $state['attemptToken'] === $token &&
					$state['nextRetryUtc'] > time() ) {
					return 'unchanged'; }
				return self::failed( $id, $lease, $state, $token, 'owner_transfer_pending' );
			}
			if ( 'owner_transfer_pending' === $state['errorCode'] ) {
				// Old-owner cleanup unlocks a new projection immediately; an
				// unrelated retry timer must not prolong a completed transfer.
				$state['errorCode']    = '';
				$state['nextRetryUtc'] = 0;
				$state['attempts']     = 0;
			}
			// Read current native state even during backoff. A new native hash
			// or dirty token bypasses old backoff; missed hooks also reconcile.
			if ( $state['nextRetryUtc'] > time() && $state['attemptToken'] === $token && $state['attemptHash'] === $hash ) {
				return 'unchanged';
			}
			$state['attemptHash'] = $hash;
			if ( null !== $state['intent'] ) {
				if ( $state['intent']['calendarKey'] !== $target ) {
					return self::failed( $id, $lease, $state, $token, 'target_changed' );
				}
				// An earlier HTTP result may have been lost. Resolve that
				// fingerprint BEFORE changing the desired version or treating
				// an unmapped native tombstone as a no-op.
				if ( ! self::owns( $id, $lease ) ) {
					return 'failed'; }
				$recovered = HSPCC_Calendar_Storage::recover( $state['intent'] );
				if ( $recovered instanceof WP_Error ) {
					return self::failed( $id, $lease, $state, $token, self::error_kind( $recovered ) );
				}
				if ( self::absent( $recovered, $site, $id, $target ) ) {
					$state['mapping'] = null; } else {
					$mapping = self::mapping( $recovered, $site, $id, $target );
					if ( $mapping instanceof WP_Error || ! in_array(
						$mapping['sourceHash'],
						array( $state['intent']['expectedSourceHash'], $state['intent']['priorSourceHash'] ?? '' ),
						true
					) ) {
						return self::failed( $id, $lease, $state, $token, 'invalid_receipt' );
					}
					$state['mapping'] = $mapping;
					}
					$state['intent']       = null;
					$state['errorCode']    = '';
					$state['nextRetryUtc'] = 0;
					// A new version may be sent only after recovered ownership is
					// durable. Otherwise a subsequent cancellation could orphan it.
					if ( ! self::save( $id, $lease, $state ) ) {
						return 'failed'; }
			}
			if ( $state['successHash'] === $hash && $state['desiredHash'] === $hash && '' === $state['errorCode'] &&
				( 'delete' === $job['action'] || null !== $state['mapping'] ) ) {
				$state['ackToken']     = $token;
				$state['attemptToken'] = $token;
				$state['attempts']     = 0;
				$state['nextRetryUtc'] = 0;
				return self::save( $id, $lease, $state ) ? 'unchanged' : 'failed';
			}
			if ( $state['desiredHash'] !== $hash ) {
				if ( $state['sequence'] >= 2147483647 ) {
					return self::failed( $id, $lease, $state, $token, 'state_invalid' );
				}
				$state['desiredHash'] = $hash;
				++$state['sequence'];
				$state['dtstampUtc'] = max( time(), $state['dtstampUtc'] + 1 );
				$state['attempts']   = 0;
			}
			if ( null !== $state['mapping'] && $state['mapping']['calendarKey'] !== $target ) {
				return self::failed( $id, $lease, $state, $token, 'target_changed' );
			}
			$state['attemptToken'] = $token;
			// Persist the version BEFORE HTTP. Retrying an uncertain write uses
			// precisely the same sequence/DTSTAMP, and the storage UID is stable.
			if ( ! self::save( $id, $lease, $state ) ) {
				return 'failed'; }
			$job['sequence']   = $state['sequence'];
			$job['dtstampUtc'] = $state['dtstampUtc'];
			$job['mapping']    = $state['mapping'];
			if ( ! self::owns( $id, $lease ) || ! self::configured() || HSPCC_Calendar_Storage::target_key() !== $target ) {
				return 'failed';
			}
			if ( 'delete' === $job['action'] && null === $state['mapping'] ) {
				$receipt = array(); // An unknown/unmapped ID owns no remote resource.
			} else {
				$intent = HSPCC_Calendar_Storage::intent( $job );
				$intent = self::intent( $intent, $site, $id, $target );
				if ( $intent instanceof WP_Error || ! self::prior_matches( $intent, $state['mapping'] ) ) {
					return self::failed( $id, $lease, $state, $token, 'invalid_receipt' );
				}
				$state['intent'] = $intent;
				// No booking fields are kept: only resource identity and the
				// expected/prior projection hashes needed after an uncertain PUT.
				if ( ! self::save( $id, $lease, $state ) ) {
					return 'failed'; }
				if ( ! self::owns( $id, $lease ) ) {
					return 'failed'; }
				$receipt = HSPCC_Calendar_Storage::sync( $job );
			}
			if ( $receipt instanceof WP_Error ) {
				return self::failed( $id, $lease, $state, $token, self::error_kind( $receipt ) );
			}
			if ( 'upsert' === $job['action'] ) {
				$mapping = self::mapping( $receipt, $site, $id, $target );
				if ( $mapping instanceof WP_Error ) {
					return self::failed( $id, $lease, $state, $token, 'invalid_receipt' ); }
				$state['mapping'] = $mapping;
			} elseif ( ! is_array( $receipt ) || ( null !== $state['mapping'] &&
				( ( $receipt['calendarKey'] ?? null ) !== $target ||
				( $receipt['pathSuffix'] ?? null ) !== $state['mapping']['pathSuffix'] ||
				( $receipt['uid'] ?? null ) !== $state['mapping']['uid'] || ( $receipt['etag'] ?? null ) !== '' ) ) ) {
				return self::failed( $id, $lease, $state, $token, 'invalid_receipt' );
			} else {
				$state['mapping'] = null; }
			$state['successHash']    = $hash;
			$state['intent']         = null;
			$state['ackToken']       = $token; // Never writes/deletes the dirty marker.
			$state['attemptToken']   = $token;
			$state['attempts']       = 0;
			$state['nextRetryUtc']   = 0;
			$state['errorCode']      = '';
			$state['lastSuccessUtc'] = time();
			return self::save( $id, $lease, $state ) ? 'synced' : 'failed';
		} catch ( Throwable $error ) {
			return is_array( $state ) ? self::failed( $id, $lease, $state, $token ?? '', 'sync_failed' ) : 'failed';
		} finally {
			self::release( $id, $lease ); }
	}

	/**
	 * Construct a write job from committed native state and its durable mapping.
	 *
	 * @param int   $id Positive canonical appointment identifier.
	 * @param mixed $native Current committed native appointment snapshot.
	 * @param mixed $site Stable managed-event namespace.
	 * @param mixed $target Verified calendar destination key.
	 * @return array|WP_Error
	 */
	private static function job( int $id, $native, $site, $target ): array|WP_Error {
		$provider = HSP_Calendar_Connection::provider_id();
		if ( ! is_array( $native ) || ! self::uuid( $site ) || ! self::key( $target ) ||
			! is_int( $provider ) || $provider < 1 ||
			( $native['appointmentId'] ?? null ) !== $id || ! in_array( $native['action'] ?? null, array( 'upsert', 'delete' ), true ) ) {
			return self::invalid();
		}
		$job = array(
			'action'        => $native['action'],
			'siteUuid'      => $site,
			'appointmentId' => $id,
			'calendarKey'   => $target,
		);
		if ( 'delete' === $job['action'] ) {
			return $job; }
		$start  = $native['startUtc'] ?? null;
		$end    = $native['endUtc'] ?? null;
		$label  = $native['serviceLabel'] ?? null;
		$status = $native['status'] ?? null;
		if ( ( $native['providerId'] ?? null ) !== $provider || ! is_int( $start ) || ! is_int( $end ) || $start < 0 || $end <= $start ||
			$end > 253402300799 || ! is_string( $label ) || trim( $label ) === '' || strlen( $label ) > 512 ||
			preg_match( '//u', $label ) !== 1 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label ) ||
			! in_array( $status, array( 'confirmed', 'tentative' ), true ) ) {
			return self::invalid(); }
		return $job + array(
			'startUtc'     => $start,
			'endUtc'       => $end,
			'serviceLabel' => str_replace( array( "\r\n", "\r" ), "\n", $label ),
			'status'       => $status,
		);
	}

	/**
	 * Persist a sanitized failure while retaining the current intent for recovery.
	 *
	 * @param int    $id Positive canonical appointment identifier.
	 * @param string $lease Current worker lease owner.
	 * @param array  $state Validated durable queue state.
	 * @param string $token Dirty marker token currently being processed.
	 * @param string $kind Allowlisted failure category.
	 * @return string
	 */
	private static function failed( int $id, string $lease, array $state, string $token, string $kind ): string {
		$state['attemptToken'] = $token;
		$state['attempts']     = min( 1000, $state['attempts'] + 1 );
		$state['errorCode']    = $kind;
		$state['nextRetryUtc'] = time() + min( 21600, 300 * ( 2 ** min( 7, $state['attempts'] - 1 ) ) );
		self::save( $id, $lease, $state );
		return 'failed';
	}

	/**
	 * Build a new durable state record.
	 *
	 * @return array
	 */
	private static function defaults(): array {
		return array(
			'version'        => 2,
			'employeeId'     => HSP_Calendar_Connection::employee_id(),
			'ackToken'       => '',
			'attemptToken'   => '',
			'attemptHash'    => '',
			'successHash'    => '',
			'desiredHash'    => '',
			'sequence'       => -1,
			'dtstampUtc'     => 0,
			'mapping'        => null,
			'intent'         => null,
			'attempts'       => 0,
			'nextRetryUtc'   => 0,
			'errorCode'      => '',
			'lastSuccessUtc' => 0,
		);
	}

	/**
	 * Read and validate a stored queue state record.
	 *
	 * @param int $id Positive canonical appointment identifier.
	 * @return array|WP_Error
	 */
	private static function state( int $id ): array|WP_Error {
		$value = self::fresh( self::STATE . $id );
		if ( false === $value ) {
			return self::defaults(); }
		if ( ! is_array( $value ) || array_keys( $value ) !== array_keys( self::defaults() ) || 2 !== $value['version'] ||
			HSP_Calendar_Connection::employee_id() !== $value['employeeId'] ) {
			return self::invalid(); }
		foreach ( array( 'ackToken', 'attemptToken' ) as $key ) {
			if ( '' !== $value[ $key ] && ! self::uuid( $value[ $key ] ) ) {
				return self::invalid(); }
		}
		foreach ( array( 'attemptHash', 'successHash', 'desiredHash' ) as $key ) {
			if ( '' !== $value[ $key ] && ! self::key( $value[ $key ] ) ) {
				return self::invalid(); }
		}
		foreach ( array( 'sequence', 'dtstampUtc', 'attempts', 'nextRetryUtc', 'lastSuccessUtc' ) as $key ) {
			if ( ! is_int( $value[ $key ] ) || $value[ $key ] < ( 'sequence' === $key ? -1 : 0 ) ) {
				return self::invalid(); }
		}
		if ( $value['sequence'] > 2147483647 || ! in_array(
			$value['errorCode'],
			array(
				'',
				'snapshot_failed',
				'sync_failed',
				'configuration',
				'conflict',
				'target_changed',
				'owner_transfer_pending',
				'invalid_receipt',
				'state_invalid',
			),
			true
		) ) {
			return self::invalid(); }
		if ( null !== $value['mapping'] ) {
			$map  = $value['mapping'];
			$site = HSP_Calendar_Connection::private_option( self::SITE, '' );
			if ( ! is_array( $map ) || self::mapping( $map, $site, $id, $map['calendarKey'] ?? '' ) instanceof WP_Error ) {
				return self::invalid(); }
		}
		if ( null !== $value['intent'] ) {
			$intent = $value['intent'];
			if ( ! is_array( $intent ) || self::intent(
				$intent,
				HSP_Calendar_Connection::private_option( self::SITE, '' ),
				$id,
				$intent['calendarKey'] ?? ''
			) instanceof WP_Error || ! self::prior_matches( $intent, $value['mapping'] ) ) {
				return self::invalid();
			}
		}
		return $value;
	}

	/**
	 * Reduce a storage failure to an allowlisted queue outcome.
	 *
	 * @param WP_Error $error Sanitized storage failure.
	 * @return string
	 */
	private static function error_kind( WP_Error $error ): string {
		$code = $error->get_error_code();
		return in_array(
			$code,
			array(
				'hspcc_storage_conflict',
				'hspcc_storage_ownership_mismatch',
				'hspcc_storage_invalid_mapping',
			),
			true
		) ? 'conflict' :
			( 'hspcc_storage_disabled' === $code ? 'configuration' : 'sync_failed' );
	}

	/**
	 * Validate a receipt proving that an owned resource is absent.
	 *
	 * @param mixed  $receipt Untrusted acknowledgement to validate.
	 * @param string $site Stable managed-event namespace.
	 * @param int    $id Positive canonical appointment identifier.
	 * @param string $target Verified calendar destination key.
	 * @return bool
	 */
	private static function absent( $receipt, string $site, int $id, string $target ): bool {
		return is_array( $receipt ) && ( $receipt['result'] ?? null ) === 'absent' && ( $receipt['etag'] ?? null ) === '' &&
			( $receipt['calendarKey'] ?? null ) === $target &&
			( $receipt['pathSuffix'] ?? null ) === 'hsp-amelia-' . $site . '-' . $id . '.ics' &&
			( $receipt['uid'] ?? null ) === 'hsp-amelia-' . $site . '-' . $id . '@hsp-calendar-connection.local';
	}

	/**
	 * Validate the durable description of a pending remote write.
	 *
	 * @param mixed $intent Durable description of an uncertain write.
	 * @param mixed $site Stable managed-event namespace.
	 * @param int   $id Positive canonical appointment identifier.
	 * @param mixed $target Verified calendar destination key.
	 * @return array|WP_Error
	 */
	private static function intent( $intent, $site, int $id, $target ): array|WP_Error {
		$keys = array(
			'siteUuid',
			'appointmentId',
			'calendarKey',
			'pathSuffix',
			'uid',
			'expectedSourceHash',
			'priorSourceHash',
			'priorETag',
		);
		if ( ! is_array( $intent ) || array_diff( array_keys( $intent ), $keys ) || ! self::uuid( $site ) || ! self::key( $target ) ||
			( $intent['siteUuid'] ?? null ) !== $site || ( $intent['appointmentId'] ?? null ) !== $id ||
			( $intent['calendarKey'] ?? null ) !== $target ||
			( $intent['pathSuffix'] ?? null ) !== 'hsp-amelia-' . $site . '-' . $id . '.ics' ||
			( $intent['uid'] ?? null ) !== 'hsp-amelia-' . $site . '-' . $id . '@hsp-calendar-connection.local' ||
			! self::key( $intent['expectedSourceHash'] ?? null ) ) {
			return self::invalid(); }
		if ( array_key_exists( 'priorSourceHash', $intent ) || array_key_exists( 'priorETag', $intent ) ) {
			if ( ! self::key( $intent['priorSourceHash'] ?? null ) || ! is_string( $intent['priorETag'] ?? null ) ||
				preg_match( '/^"[\x21\x23-\x7E]{1,200}"$/D', $intent['priorETag'] ) !== 1 ) {
				return self::invalid(); }
		}
		return array_intersect_key( $intent, array_flip( $keys ) );
	}

	/**
	 * Compare an intent's previous acknowledgement with the current mapping.
	 *
	 * @param array      $intent Durable description of an uncertain write.
	 * @param array|null $mapping Previous acknowledged owned resource.
	 * @return bool
	 */
	private static function prior_matches( array $intent, ?array $mapping ): bool {
		return null === $mapping
			? ! array_key_exists( 'priorSourceHash', $intent ) && ! array_key_exists( 'priorETag', $intent )
			: ( $intent['priorSourceHash'] ?? null ) === $mapping['sourceHash'] &&
				( $intent['priorETag'] ?? null ) === $mapping['etag'];
	}

	/**
	 * Validate an acknowledged owned calendar resource.
	 *
	 * @param mixed $receipt Untrusted acknowledgement to validate.
	 * @param mixed $site Stable managed-event namespace.
	 * @param int   $id Positive canonical appointment identifier.
	 * @param mixed $target Verified calendar destination key.
	 * @return array|WP_Error
	 */
	private static function mapping( $receipt, $site, int $id, $target ): array|WP_Error {
		if ( ! is_array( $receipt ) || ! self::uuid( $site ) || ! self::key( $target ) ||
			( $receipt['calendarKey'] ?? null ) !== $target ||
			( $receipt['pathSuffix'] ?? null ) !== 'hsp-amelia-' . $site . '-' . $id . '.ics' ||
			( $receipt['uid'] ?? null ) !== 'hsp-amelia-' . $site . '-' . $id . '@hsp-calendar-connection.local' ||
			! is_string( $receipt['etag'] ?? null ) || preg_match( '/^"[\x21\x23-\x7E]{1,200}"$/D', $receipt['etag'] ) !== 1 ||
			! self::key( $receipt['sourceHash'] ?? null ) ) {
			return self::invalid(); }
		return array_intersect_key( $receipt, array_flip( array( 'calendarKey', 'pathSuffix', 'uid', 'etag', 'sourceHash' ) ) );
	}

	/**
	 * Persist state only while the worker retains its appointment lease.
	 *
	 * @param int    $id Positive canonical appointment identifier.
	 * @param string $lease Current worker lease owner.
	 * @param array  $state Validated durable queue state.
	 * @return bool
	 */
	private static function save( int $id, string $lease, array $state ): bool {
		if ( ! self::owns( $id, $lease ) ) {
			return false; }
		HSP_Calendar_Connection::set_private_option( self::STATE . $id, $state );
		return self::fresh( self::STATE . $id ) === $state;
	}

	/**
	 * Read or create the stable employee-scoped event namespace.
	 *
	 * @return string
	 */
	public static function site_uuid(): string {
		$site = HSP_Calendar_Connection::private_option( self::SITE, '' );
		if ( '' !== $site ) {
			return self::uuid( $site ) ? $site : ''; }
		// WordPress add_option uses an upsert under the hood, so a separate
		// advisory lock also serializes the first creation of site identity.
		$name = self::advisory( 'site_identity' );
		if ( '' === $name ) {
			return ''; }
		try {
			$site = self::fresh( self::SITE );
			if ( false === $site ) {
				add_option( HSP_Calendar_Connection::option_name( self::SITE ), wp_generate_uuid4(), '', false );
				$site = self::fresh( self::SITE );
			}
		} finally {
			self::release_advisory( $name ); }
		return self::uuid( $site ) ? $site : '';
	}

	/**
	 * Acquire an appointment advisory lock and fresh worker lease.
	 *
	 * @param int $id Positive canonical appointment identifier.
	 * @return string
	 */
	private static function acquire( int $id ): string {
		$name = self::advisory( 'appointment:' . $id );
		if ( '' === $name ) {
			return ''; }
		$ready = false;
		try {
			$owner = wp_generate_uuid4();
			$lease = array(
				'owner'      => $owner,
				'expiresUtc' => time() + self::LEASE_SECONDS,
			);
			HSP_Calendar_Connection::set_private_option( self::LOCK . $id, $lease );
			$ready = self::fresh( self::LOCK . $id ) === $lease;
			return $ready ? $owner : '';
		} finally {
			if ( ! $ready ) {
				self::release_advisory( $name ); }
		}
	}

	/**
	 * Check that the worker still owns its appointment lease and connection lock.
	 *
	 * @param int    $id Positive canonical appointment identifier.
	 * @param string $owner Worker lease owner.
	 * @return bool
	 */
	private static function owns( int $id, string $owner ): bool {
		$lock = self::fresh( self::LOCK . $id );
		return is_array( $lock ) && ( $lock['owner'] ?? null ) === $owner &&
			is_int( $lock['expiresUtc'] ?? null ) && $lock['expiresUtc'] > time() &&
			self::connection_owns( self::lock_name( 'appointment:' . $id ) );
	}

	/**
	 * Release only the current worker's appointment lease and advisory lock.
	 *
	 * @param int    $id Positive canonical appointment identifier.
	 * @param string $owner Worker lease owner.
	 * @return void
	 */
	private static function release( int $id, string $owner ): void {
		$name = self::lock_name( 'appointment:' . $id );
		if ( ! self::connection_owns( $name ) ) {
			return; }
		try {
			$lease = self::fresh( self::LOCK . $id );
			if ( is_array( $lease ) && ( $lease['owner'] ?? null ) === $owner ) {
				HSP_Calendar_Connection::delete_private_option( self::LOCK . $id ); }
		} finally {
			self::release_advisory( $name ); }
	}

	/**
	 * Build a bounded site-specific advisory lock name.
	 *
	 * @param string $scope Logical advisory lock scope.
	 * @return string
	 */
	private static function lock_name( string $scope ): string {
		global $wpdb;
		return 'hspcc:' . substr(
			hash(
				'sha256',
				( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' .
				( $wpdb->options ?? '' ) . '|' . $scope
			),
			0,
			56
		);
	}

	/**
	 * Acquire a connection-scoped database advisory lock without waiting.
	 *
	 * @param string $scope Logical advisory lock scope.
	 * @return string
	 */
	private static function advisory( string $scope ): string {
		$name   = self::lock_name( $scope );
		$result = self::scalar( 'SELECT GET_LOCK(%s, 0)', $name );
		if ( in_array( $result, array( 0, '0', 1, '1' ), true ) ) {
			try {
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Health is advisory only; failed health reporting must not discard a held lock or unresolved job.
				update_option( self::HEALTH, '', false ); } catch ( Throwable $error ) {
				/* Health is advisory only. */ }
				return in_array( $result, array( 1, '1' ), true ) ? $name : '';
		}
		try {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Health is advisory only; failed health reporting must not discard a held lock or unresolved job.
			update_option( self::HEALTH, 'lock_unavailable', false ); } catch ( Throwable $error ) {
			/* Keep jobs untouched. */ }
			return '';
	}

	/**
	 * Verify that the current database connection owns a named lock.
	 *
	 * @param string $name Validated option, lock or timezone name.
	 * @return bool
	 */
	private static function connection_owns( string $name ): bool {
		return in_array( self::scalar( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ), array( 1, '1' ), true );
	}

	/**
	 * Release an advisory lock only when this connection owns it.
	 *
	 * @param string $name Validated option, lock or timezone name.
	 * @return void
	 */
	private static function release_advisory( string $name ): void {
		if ( self::connection_owns( $name ) ) {
			self::scalar( 'SELECT RELEASE_LOCK(%s)', $name ); }
	}

	/**
	 * Execute a private constant lock query with a prepared name parameter.
	 *
	 * @param string $sql Private constant SQL template containing a name placeholder.
	 * @param string $name Validated option, lock or timezone name.
	 * @return mixed
	 */
	private static function scalar( string $sql, string $name ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null; }
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fresh owned-option scans and advisory-lock checks are required for concurrency safety and have no equivalent Options API. Concurrency decisions require current database state; caching a lock or scan can lose work. Only private constant SQL templates reach this helper; the lock name is bound with wpdb::prepare. The private helper prepares the fixed query and variable lock name.
			return $wpdb->get_var( $wpdb->prepare( $sql, $name ) ); } catch ( Throwable $error ) {
			return null; } finally {
				$wpdb->suppress_errors( $suppressed ); }
	}

	/**
	 * Read uncached option state needed for concurrency decisions.
	 *
	 * @param string $name Validated option, lock or timezone name.
	 * @return mixed
	 */
	private static function fresh( string $name ) {
		$name = HSP_Calendar_Connection::option_name( $name );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, false );
	}

	/**
	 * Read a bounded ordered page of this plugin's durable option names.
	 *
	 * @param int    $limit Maximum number of option names to return.
	 * @param string $after Exclusive pagination cursor.
	 * @param string $prefix Owned option-name prefix.
	 * @return array
	 */
	private static function names( int $limit, string $after = '', string $prefix = self::DIRTY ): array {
		global $wpdb;
		self::$scan_failed = false;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			self::$scan_failed = true;
			return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh owned-option scans and advisory-lock checks are required for concurrency safety and have no equivalent Options API. Concurrency decisions require current database state; caching a lock or scan can lose work.
		$names             = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
				$wpdb->esc_like( $prefix ) . '%',
				$after,
				$limit
			)
		);
		self::$scan_failed = ! is_array( $names ) || ! empty( $wpdb->last_error );
		return is_array( $names ) ? $names : array();
	}

	/**
	 * Parse the appointment and employee identity from a dirty option name.
	 *
	 * @param mixed $name Validated option, lock or timezone name.
	 * @return array|null
	 */
	private static function identity_from_name( $name ): ?array {
		if ( ! is_string( $name ) || preg_match( '/^' . self::DIRTY . '([1-9][0-9]*)(?:_employee_([1-9][0-9]*))?$/D', $name, $match ) !== 1 ) {
			return null; }
		$id          = filter_var( $match[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$employee_id = isset( $match[2] ) ? filter_var( $match[2], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 2 ) ) ) : 1;
		if ( false === $id || false === $employee_id ) {
			return null; }
		return array(
			'appointmentId' => $id,
			'employeeId'    => $employee_id,
		);
	}

	/**
	 * Reconcile any old employee's owned resource before transferring an appointment.
	 *
	 * @param int $appointment_id Positive canonical appointment identifier.
	 * @return bool
	 */
	private static function transfer_clear( int $appointment_id ): bool {
		global $wpdb;
		// Query this appointment's suffixed owners only. A large history of
		// unrelated appointments must not stop normal write processing.
		$names = self::names( 1001, '', self::STATE . $appointment_id . '_employee_' );
		if ( self::$scan_failed || count( $names ) > 1000 ) {
			return false; }
		$employee_one_name = self::STATE . $appointment_id;
		if ( get_option( $employee_one_name, false ) !== false ) {
			array_unshift( $names, $employee_one_name ); }
		if ( ! empty( $wpdb->last_error ) ) {
			return false; }
		$owner = HSP_Calendar_Connection::employee_id();
		foreach ( $names as $name ) {
			$identity = self::identity_from_name( self::DIRTY . substr( $name, strlen( self::STATE ) ) );
			if ( null === $identity ) {
				return false; }
			if ( $identity['appointmentId'] !== $appointment_id || $identity['employeeId'] === $owner ) {
				continue; }
			try {
				$state = HSP_Calendar_Connection::with_employee(
					$identity['employeeId'],
					static function () use ( $appointment_id ) {
						return self::state( $appointment_id );
					}
				);
			} catch ( Throwable $error ) {
				return false; }
			if ( $state instanceof WP_Error || null !== $state['mapping'] || null !== $state['intent'] ) {
				return false; }
		}
		return true;
	}

	/**
	 * Summarize recovery state without exposing event or customer data.
	 *
	 * @param int|null $employee_id Positive canonical employee identifier.
	 * @return array
	 */
	public static function summary( ?int $employee_id = null ): array {
		$names       = self::names( 1001 );
		$scan_failed = self::$scan_failed;
		$states      = self::names( 1001, '', self::STATE );
		$scan_failed = $scan_failed || self::$scan_failed;
		try {
			$configured = false;
			foreach ( null === $employee_id ? self::writers() : array( $employee_id ) as $candidate ) {
				if ( $candidate > 0 && HSP_Calendar_Connection::with_employee(
					$candidate,
					static function () {
						return self::configured(); }
				) ) {
					$configured = true;
					break;
				}
			}
		} catch ( Throwable $error ) {
			$configured = false; }
		$summary = array(
			'configured'   => $configured,
			'tracked'      => 0,
			'managed'      => 0,
			'unresolved'   => 0,
			'pending'      => 0,
			'retrying'     => 0,
			'conflicts'    => 0,
			'errors'       => 0,
			'nextRetryUtc' => 0,
			'limited'      => count( $names ) > 1000 || count( $states ) > 1000,
			'ambiguous'    => $scan_failed ? 1 : 0,
			'workerError'  => $scan_failed ? 'scan_unavailable' :
					( get_option( self::HEALTH, '' ) === '' ? '' : 'lock_unavailable' ),
		);
		if ( $scan_failed ) {
			++$summary['pending'];
			++$summary['errors']; }
		foreach ( array_slice( $names, 0, 1000 ) as $name ) {
			$identity = self::identity_from_name( $name );
			if ( null === $identity ) {
				// A damaged/unassigned owned name cannot be safely attributed:
				// expose it to every connection's migration guard.
				++$summary['ambiguous'];
				++$summary['pending'];
				++$summary['errors'];
				continue;
			}
			if ( null === $employee_id || $identity['employeeId'] === $employee_id ) {
				++$summary['tracked']; }
			try {
				[$marker_valid, $marker, $state] = HSP_Calendar_Connection::with_employee(
					$identity['employeeId'],
					static function () use ( $identity ) {
						$marker = self::fresh( self::DIRTY . $identity['appointmentId'] );
						return array( self::marker_valid( $marker ), $marker, self::state( $identity['appointmentId'] ) );
					}
				);
			} catch ( Throwable $error ) {
				$marker_valid = false;
				$state        = self::invalid(); }
			if ( ! $marker_valid || $state instanceof WP_Error ) {
				// Corrupt owner/state cannot prove that another connection
				// is uninvolved; make every migration guard conservative.
				++$summary['ambiguous'];
				++$summary['pending'];
				++$summary['errors'];
				continue;
			}
			if ( null !== $employee_id && $identity['employeeId'] !== $employee_id ) {
				continue; }
			if ( null !== $state['intent'] || $marker['token'] !== $state['ackToken'] || $state['desiredHash'] !== $state['successHash'] ||
				'' !== $state['errorCode'] ) {
				++$summary['pending']; }
			if ( null !== $state['mapping'] ) {
				++$summary['managed']; }
			if ( null !== $state['intent'] ) {
				++$summary['unresolved']; }
			if ( '' !== $state['errorCode'] ) {
				++$summary['errors']; }
			if ( in_array( $state['errorCode'], array( 'conflict', 'target_changed' ), true ) ) {
				++$summary['conflicts']; }
			if ( $state['attempts'] > 0 && $state['nextRetryUtc'] > time() ) {
				++$summary['retrying']; }
			if ( $state['nextRetryUtc'] > 0 && ( 0 === $summary['nextRetryUtc'] || $state['nextRetryUtc'] < $summary['nextRetryUtc'] ) ) {
				$summary['nextRetryUtc'] = $state['nextRetryUtc'];
			}
		}
		foreach ( array_slice( $states, 0, 1000 ) as $name ) {
			$identity    = self::identity_from_name( self::DIRTY . substr( $name, strlen( self::STATE ) ) );
			$marker_name = null === $identity ? '' :
				HSP_Calendar_Connection::option_name( self::DIRTY . $identity['appointmentId'], $identity['employeeId'] );
			if ( '' !== $marker_name && get_option( $marker_name, false ) !== false ) {
				continue; }
			// Missing dirty metadata cannot erase evidence of an owned remote
			// mapping. Surface orphan/corrupt state to every migration guard.
			++$summary['ambiguous'];
			++$summary['pending'];
			++$summary['errors'];
		}
		return $summary;
	}

	/**
	 * Validate a managed-event namespace UUID.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function uuid( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value ) === 1;
	}

	/**
	 * Validate a stored calendar destination key.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	private static function key( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1;
	}

	/**
	 * Create a sanitized invalid-queue-state error.
	 *
	 * @return WP_Error
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'hspcc_queue_invalid', __( 'Calendar queue state could not be validated.', 'hsp-calendar-connection' ) );
	}
}
