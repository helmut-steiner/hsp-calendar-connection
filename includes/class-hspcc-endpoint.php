<?php
/**
 * One administrator-selected HTTPS CalDAV origin. No DNS, credentials, options
 * or HTTP access occurs in normalize()/allows(). The request wrapper retains
 * WordPress safe HTTP DNS checks and grants only this origin's configured port.
 * IPv6 literals are refused because WordPress safe HTTP rejects them; use DNS.
 *
 * @package HSPCalendarConnection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enforce configured HTTPS origin and safe HTTP transport policy.
 */
final class HSPCC_Endpoint {
	/**
	 * Normalize a permitted HTTPS CalDAV endpoint.
	 *
	 * @param mixed $input Untrusted endpoint input.
	 * @return string
	 */
	public static function normalize( $input ): string {
		$parts = self::parts( $input );
		if ( null === $parts ) {
			return ''; }
		return $parts['origin'] . rtrim( $parts['path'], '/' ) . '/';
	}

	/**
	 * Check whether a candidate URL remains on the configured HTTPS origin.
	 *
	 * @param mixed $candidate Candidate resource URL.
	 * @param mixed $configured Configured HTTPS endpoint.
	 * @return bool
	 */
	public static function allows( $candidate, $configured ): bool {
		$endpoint = self::parts( $configured );
		$target   = self::parts( $candidate );
		return null !== $endpoint && null !== $target && $endpoint['origin'] === $target['origin'];
	}

	/**
	 * Make a same-origin safe HTTP request with enforced TLS and redirect policy.
	 *
	 * @param mixed $url Same-origin resource URL.
	 * @param array $args HTTP arguments to constrain before requesting.
	 * @param mixed $endpoint Administrator-configured HTTPS endpoint.
	 * @return array|WP_Error
	 */
	public static function request( $url, array $args, $endpoint ): array|WP_Error {
		$configured = self::normalize( $endpoint );
		if ( '' === $configured || ! self::allows( $url, $configured ) ) {
			return self::error( 'origin' ); }
		$target                     = self::parts( $url );
		$origin                     = self::parts( $configured );
		$filter                     = static function ( $ports, $host, $request_url ) use ( $configured, $origin ) {
			// WordPress supplies host + complete URL. Both must match; another
			// request made while this callback exists gains no extra privilege.
			if ( is_array( $ports ) && is_string( $host ) && strtolower( $host ) === $origin['host'] &&
				HSPCC_Endpoint::allows( $request_url, $configured ) ) {
				if ( ! in_array( $origin['port'], $ports, true ) ) {
					$ports[] = $origin['port']; }
				return array_values( $ports );
			}
			return $ports;
		};
		$args['sslverify']          = true;
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;
		$args['blocking']           = true;
		$args['stream']             = false;
		unset( $args['filename'] );
		add_filter( 'http_allowed_safe_ports', $filter, 10, 3 );
		try {
			// Never add http_request_host_is_external or another private-host
			// exception. Safe HTTP still decides DNS/address reachability.
			$response = wp_safe_remote_request( $target['origin'] . $target['path'], $args );
			return is_array( $response ) ? $response : self::error( 'transport' );
		} catch ( Throwable $error ) {
			return self::error( 'transport' ); } finally {
			remove_filter( 'http_allowed_safe_ports', $filter, 10 ); }
	}

	/**
	 * Parse and validate a bounded HTTPS URL without resolving DNS.
	 *
	 * @param mixed $input Untrusted endpoint input.
	 * @return array|null
	 */
	private static function parts( $input ): ?array {
		if ( ! is_string( $input ) || '' === $input || strlen( $input ) > 2048 || preg_match( '//u', $input ) !== 1 ||
			preg_match( '/[\p{C}\p{Z}]/u', $input ) || strpos( $input, chr( 92 ) ) !== false ||
			preg_match( '/%(?![a-f0-9]{2})/i', $input ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $input ) ) {
			return null; }
		try {
			$url = wp_parse_url( $input ); } catch ( Throwable $error ) {
			return null; }
			if ( ! is_array( $url ) || strtolower( $url['scheme'] ?? '' ) !== 'https' || ! isset( $url['host'] ) ||
			isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
				return null; }
			// An explicit authority grammar rejects encoded host/userinfo, empty
			// ports, IPv6 brackets, Unicode hostnames and alternative numeric IPs.
			if ( preg_match( '~^https://([a-z0-9.-]+)(?::([0-9]+))?(?:/[^?#]*)?$~iD', $input, $authority ) !== 1 ) {
				return null; }
			$host = strtolower( $url['host'] );
			if ( strtolower( $authority[1] ) !== $host || ! self::host( $host ) ) {
				return null; }
			$port = $url['port'] ?? 443;
			if ( ! is_int( $port ) || $port < 1 || $port > 65535 ) {
				return null; }
			$path = $url['path'] ?? '/';
			if ( '' === $path ) {
				$path = '/'; }
			if ( '/' !== $path[0] ) {
				return null; }
			return array(
				'host'   => $host,
				'port'   => $port,
				'origin' => 'https://' . $host . ( 443 === $port ? '' : ':' . $port ),
				'path'   => $path,
			);
	}

	/**
	 * Validate a DNS hostname or publicly routable IPv4 literal.
	 *
	 * @param string $host Normalized DNS hostname or IPv4 literal.
	 * @return bool
	 */
	private static function host( string $host ): bool {
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false ) {
			return self::public_ipv4( $host ); }
		if ( strlen( $host ) > 253 || strpos( $host, '.' ) === false || substr( $host, -1 ) === '.' ) {
			return false; }
		$labels = explode( '.', $host );
		foreach ( $labels as $label ) {
			if ( strlen( $label ) > 63 || preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label ) !== 1 ) {
				return false; }
		}
		$last = end( $labels );
		if ( preg_match( '/[a-z]/', $last ) !== 1 || in_array(
			$last,
			array( 'localhost', 'local', 'localdomain', 'internal', 'lan', 'home', 'invalid', 'onion' ),
			true
		) ) {
			return false; }
		// Qualified DNS names are resolved/validated only by safe HTTP at use.
		return true;
	}

	/**
	 * Reject private, reserved and special-purpose IPv4 ranges.
	 *
	 * @param string $host Normalized DNS hostname or IPv4 literal.
	 * @return bool
	 */
	private static function public_ipv4( string $host ): bool {
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return false; }
		[$a, $b, $c] = array_map( 'intval', explode( '.', $host ) );
		// Match WordPress's special-purpose exclusions, including shared,
		// documentation, benchmark, multicast and reserved address space.
		return ! ( 0 === $a || 10 === $a || 127 === $a || $a >= 224 ||
			( 100 === $a && $b >= 64 && $b <= 127 ) || ( 169 === $a && 254 === $b ) ||
			( 172 === $a && $b >= 16 && $b <= 31 ) || ( 192 === $a && 168 === $b ) ||
			( 192 === $a && 0 === $b && in_array( $c, array( 0, 2 ), true ) ) ||
			( 192 === $a && 88 === $b && 99 === $c ) || ( 198 === $a && in_array( $b, array( 18, 19 ), true ) ) ||
			( 198 === $a && 51 === $b && 100 === $c ) || ( 203 === $a && 0 === $b && 113 === $c ) );
	}

	/**
	 * Create a sanitized endpoint-policy failure.
	 *
	 * @param string $kind Allowlisted failure category.
	 * @return WP_Error
	 */
	private static function error( string $kind ): WP_Error {
		return new WP_Error( 'hspcc_endpoint_' . $kind, __( 'The calendar server request could not be completed safely.', 'hsp-calendar-connection' ) );
	}
}
